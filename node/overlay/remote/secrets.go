package remote

import (
	"bytes"
	"context"
	"crypto/tls"
	stdjson "encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/pelican/wings/secrets"
)

func (c *client) GetSecretBundle(ctx context.Context, uuid, revision, attemptID string) (secrets.Bundle, error) {
	if err := secrets.ValidateRequestIdentity(uuid, revision, attemptID); err != nil {
		return secrets.Bundle{}, err
	}
	body, _ := stdjson.Marshal(struct {
		Protocol         int    `json:"protocol"`
		ExpectedRevision string `json:"expected_revision"`
		AttemptID        string `json:"attempt_id"`
	}{1, revision, attemptID})
	raw, err := c.secretRequest(ctx, fmt.Sprintf("/servers/%s/secrets/runtime-bundle", uuid), body, true)
	if err != nil {
		return secrets.Bundle{}, err
	}
	bundle, err := secrets.DecodeBundle(raw)
	if err != nil {
		return secrets.Bundle{}, err
	}
	if bundle.ServerUUID != uuid || bundle.Revision != revision || bundle.AttemptID != attemptID {
		return secrets.Bundle{}, errors.New("protocol_invalid")
	}
	return bundle, nil
}

func (c *client) ReportSecretApplication(ctx context.Context, uuid string, report secrets.AppliedReport) error {
	if uuid != report.ServerUUID {
		return errors.New("protocol_invalid")
	}
	body, err := secrets.EncodeReport(report)
	if err != nil {
		return err
	}
	_, err = c.secretRequest(ctx, fmt.Sprintf("/servers/%s/secrets/application-report", uuid), body, false)
	return err
}

func (c *client) secretRequest(ctx context.Context, path string, body []byte, bundle bool) ([]byte, error) {
	endpoint, err := url.Parse(c.baseUrl)
	if err != nil || endpoint.Scheme != "https" || endpoint.Host == "" || endpoint.User != nil || endpoint.RawQuery != "" || endpoint.Fragment != "" {
		return nil, errors.New("panel_unavailable")
	}
	transport := c.httpClient.Transport
	if transport == nil {
		transport = http.DefaultTransport
	}
	standard, ok := transport.(*http.Transport)
	if !ok || (standard.TLSClientConfig != nil && standard.TLSClientConfig.InsecureSkipVerify) {
		return nil, errors.New("panel_unavailable")
	}
	privateTransport := standard.Clone()
	if privateTransport.TLSClientConfig == nil {
		privateTransport.TLSClientConfig = &tls.Config{}
	} else {
		privateTransport.TLSClientConfig = privateTransport.TLSClientConfig.Clone()
	}
	if privateTransport.TLSClientConfig.MinVersion < tls.VersionTLS12 {
		privateTransport.TLSClientConfig.MinVersion = tls.VersionTLS12
	}
	defer privateTransport.CloseIdleConnections()
	privateClient := *c.httpClient
	privateClient.Transport = privateTransport
	privateClient.CheckRedirect = func(req *http.Request, via []*http.Request) error { return http.ErrUseLastResponse }
	if privateClient.Timeout == 0 || privateClient.Timeout > 15*time.Second {
		privateClient.Timeout = 15 * time.Second
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseUrl+path, bytes.NewReader(body))
	if err != nil {
		return nil, errors.New("panel_unavailable")
	}
	for key, value := range c.customHeaders {
		request.Header.Set(key, value)
	}
	request.Header.Set("Authorization", "Bearer "+c.tokenId+"."+c.token)
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Cache-Control", "no-store")
	request.Header.Set("User-Agent", "Pelican Wings/secrets-v1")
	// Do not use generic request/Response.Error: those retain arbitrary error bodies.
	response, err := privateClient.Do(request)
	if err != nil {
		return nil, errors.New("panel_unavailable")
	}
	defer response.Body.Close()
	maximum := int64(4096)
	if response.StatusCode == http.StatusOK && bundle {
		maximum = secrets.MaxBundleBytes
	}
	if response.ContentLength > maximum {
		return nil, errors.New("limit_exceeded")
	}
	raw, err := io.ReadAll(io.LimitReader(response.Body, maximum+1))
	if err != nil {
		return nil, errors.New("panel_unavailable")
	}
	if int64(len(raw)) > maximum {
		return nil, errors.New("limit_exceeded")
	}
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		code := "panel_unavailable"
		if response.StatusCode == http.StatusConflict {
			code = "revision_conflict"
		} else {
			var failure struct {
				Error string `json:"error"`
			}
			if stdjson.Unmarshal(raw, &failure) == nil && secrets.IsErrorCode(failure.Error) {
				code = failure.Error
			}
		}
		return nil, errors.New(code)
	}
	if !noStore(response.Header.Get("Cache-Control")) {
		return nil, errors.New("protocol_invalid")
	}
	if bundle && response.StatusCode != http.StatusOK {
		return nil, errors.New("protocol_invalid")
	}
	return raw, nil
}

func noStore(header string) bool {
	for _, directive := range strings.Split(header, ",") {
		if strings.EqualFold(strings.TrimSpace(directive), "no-store") {
			return true
		}
	}
	return false
}

func decodeSecretReferences(settings []byte, uuid string) (*secrets.Plan, error) {
	var metadata map[string]stdjson.RawMessage
	if stdjson.Unmarshal(settings, &metadata) != nil {
		return nil, errors.New("protocol_invalid")
	}
	raw, found := metadata["secrets"]
	if !found {
		return nil, nil
	}
	plan, err := secrets.DecodePlan(raw)
	if err != nil {
		return nil, err
	}
	if plan.ServerUUID != uuid {
		return nil, errors.New("protocol_invalid")
	}
	return &plan, nil
}
