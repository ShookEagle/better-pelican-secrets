package remote

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"

	"github.com/pelican/wings/secrets"
)

const fixtureServerUUID = "11111111-1111-4111-8111-111111111111"
const fixtureAttemptID = "44444444-4444-4444-8444-444444444444"
const fixtureRevision = "9007199254740993"

func secretWireFixtures(t *testing.T) (json.RawMessage, json.RawMessage) {
	t.Helper()
	root := os.Getenv("BPS_PROTOCOL_FIXTURES")
	if root == "" {
		t.Fatal("BPS_PROTOCOL_FIXTURES is required")
	}
	data, err := os.ReadFile(filepath.Join(root, "protocol.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fixtures struct {
		Bundle json.RawMessage
		Report json.RawMessage
	}
	if err := json.Unmarshal(data, &fixtures); err != nil {
		t.Fatal(err)
	}
	return fixtures.Bundle, fixtures.Report
}

func tlsSecretRemote(t *testing.T, handler http.HandlerFunc) (Client, *httptest.Server) {
	t.Helper()
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Cache-Control", "no-store")
		if r.Header.Get("Authorization") != "Bearer fixture-id.fixture-token" {
			t.Error("wrong node identity header")
		}
		handler(w, r)
	}))
	server.Config.ErrorLog = log.New(io.Discard, "", 0)
	server.StartTLS()
	t.Cleanup(server.Close)
	return New(server.URL, WithCredentials("fixture-id", "fixture-token"), WithHttpClient(server.Client())), server
}

func TestSecretRemoteRejectsOversizeAndIdentityMismatch(t *testing.T) {
	bundle, _ := secretWireFixtures(t)
	for _, testcase := range []struct {
		name   string
		body   string
		status int
	}{
		{"oversize", strings.Repeat(" ", 16777217), 200},
		{"identity", strings.Replace(string(bundle), fixtureServerUUID, "55555555-5555-4555-8555-555555555555", 1), 200},
		{"error-body", `{"errors":[{"code":"Internal","detail":"fixture-secret"}]}`, 500},
	} {
		t.Run(testcase.name, func(t *testing.T) {
			client, _ := tlsSecretRemote(t, func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(testcase.status)
				io.WriteString(w, testcase.body)
			})
			_, err := client.GetSecretBundle(context.Background(), fixtureServerUUID, fixtureRevision, fixtureAttemptID)
			if err == nil {
				t.Fatal("invalid remote response accepted")
			}
			if strings.Contains(fmt.Sprintf("%+v", err), "fixture-secret") {
				t.Fatal("remote error leaked response value")
			}
		})
	}
}

func TestSecretRemoteUsesVerifiedTLSAndRefusesRedirects(t *testing.T) {
	bundle, _ := secretWireFixtures(t)
	var calls atomic.Int32
	client, server := tlsSecretRemote(t, func(w http.ResponseWriter, r *http.Request) { calls.Add(1); w.Write(bundle) })
	if _, err := client.GetSecretBundle(context.Background(), fixtureServerUUID, fixtureRevision, fixtureAttemptID); err != nil {
		t.Fatal(err)
	}
	calls.Store(0)
	insecure := New(server.URL, WithHttpClient(&http.Client{Transport: &http.Transport{TLSClientConfig: &tls.Config{InsecureSkipVerify: true}}}))
	if _, err := insecure.GetSecretBundle(context.Background(), fixtureServerUUID, fixtureRevision, fixtureAttemptID); err == nil || calls.Load() != 0 {
		t.Fatal("insecure TLS transport accepted")
	}
	untrusted := New(server.URL)
	if _, err := untrusted.GetSecretBundle(context.Background(), fixtureServerUUID, fixtureRevision, fixtureAttemptID); err == nil {
		t.Fatal("untrusted TLS certificate accepted")
	}
	plain := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { calls.Add(1); w.Write(bundle) }))
	defer plain.Close()
	if _, err := New(plain.URL).GetSecretBundle(context.Background(), fixtureServerUUID, fixtureRevision, fixtureAttemptID); err == nil || calls.Load() != 0 {
		t.Fatal("cleartext secret request accepted")
	}
	redirect, _ := tlsSecretRemote(t, func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, plain.URL, http.StatusFound) })
	if _, err := redirect.GetSecretBundle(context.Background(), fixtureServerUUID, fixtureRevision, fixtureAttemptID); err == nil || calls.Load() != 0 {
		t.Fatal("secret request followed a redirect")
	}
}

func TestSecretRemoteReportAndBundleAreMetadataSafe(t *testing.T) {
	bundle, reportWire := secretWireFixtures(t)
	report, err := secrets.DecodeReport(reportWire)
	if err != nil {
		t.Fatal(err)
	}
	client, _ := tlsSecretRemote(t, func(w http.ResponseWriter, r *http.Request) {
		requestBody, _ := io.ReadAll(io.LimitReader(r.Body, 16777217))
		if strings.Contains(string(requestBody), "fixture-secret") {
			t.Error("request contained a runtime value")
		}
		if strings.HasSuffix(r.URL.Path, "application-report") {
			w.WriteHeader(http.StatusNoContent)
			return
		}
		w.Write(bundle)
	})
	decoded, err := client.GetSecretBundle(context.Background(), fixtureServerUUID, fixtureRevision, fixtureAttemptID)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(fmt.Sprintf("%#v", decoded), "fixture-secret") {
		t.Fatal("bundle debug output leaked a value")
	}
	if err := client.ReportSecretApplication(context.Background(), fixtureServerUUID, report); err != nil {
		t.Fatal(err)
	}
	bad := "fixture-secret"
	report.Kind, report.ErrorCode = "failed", &bad
	if err := client.ReportSecretApplication(context.Background(), fixtureServerUUID, report); err == nil || strings.Contains(err.Error(), bad) {
		t.Fatal("unsafe report error accepted or disclosed")
	}
}

func TestSecretRemoteConfigurationKeepsReferencesAndRejectsValues(t *testing.T) {
	bundle, _ := secretWireFixtures(t)
	var base map[string]json.RawMessage
	if err := json.Unmarshal(bundle, &base); err != nil {
		t.Fatal(err)
	}
	delete(base, "values")
	delete(base, "attempt_id")
	for _, invalid := range []bool{false, true} {
		t.Run(fmt.Sprint(invalid), func(t *testing.T) {
			wire, err := json.Marshal(base)
			if err != nil {
				t.Fatal(err)
			}
			if invalid {
				var bad map[string]json.RawMessage
				json.Unmarshal(wire, &bad)
				bad["values"] = json.RawMessage("{}")
				wire, _ = json.Marshal(bad)
			}
			client, _ := tlsSecretRemote(t, func(w http.ResponseWriter, r *http.Request) {
				settings := map[string]any{"uuid": fixtureServerUUID, "secrets": json.RawMessage(wire)}
				response := map[string]any{"settings": settings}
				if r.URL.Path == "/api/remote/servers" {
					response = map[string]any{"data": []any{map[string]any{"uuid": fixtureServerUUID, "settings": settings}}, "meta": map[string]int{"last_page": 1}}
				}
				json.NewEncoder(w).Encode(response)
			})
			config, configErr := client.GetServerConfiguration(context.Background(), fixtureServerUUID)
			servers, serversErr := client.GetServers(context.Background(), 50)
			if invalid {
				if configErr == nil || serversErr == nil || config.SecretPlan != nil || len(servers) != 0 {
					t.Fatal("value-bearing configuration accepted")
				}
				return
			}
			if configErr != nil || serversErr != nil {
				t.Fatalf("valid configuration failed: %v / %v", configErr, serversErr)
			}
			if config.SecretPlan == nil || config.SecretPlan.Revision != fixtureRevision || len(servers) != 1 || servers[0].SecretPlan == nil {
				t.Fatal("reference plan lost from single or paged configuration")
			}
		})
	}
}
