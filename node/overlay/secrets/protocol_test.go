package secrets

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type protocolFixtures struct {
	Plan    json.RawMessage `json:"plan"`
	Bundle  json.RawMessage `json:"bundle"`
	Report  json.RawMessage `json:"report"`
	Invalid []struct {
		Name      string
		Input     json.RawMessage
		ErrorCode string `json:"error_code"`
	} `json:"invalid_plans"`
}

func loadProtocolFixtures(t *testing.T) protocolFixtures {
	t.Helper()
	root := os.Getenv("BPS_PROTOCOL_FIXTURES")
	if root == "" {
		t.Fatal("BPS_PROTOCOL_FIXTURES is required")
	}
	data, err := os.ReadFile(filepath.Join(root, "protocol.json"))
	if err != nil {
		t.Fatal(err)
	}
	var f protocolFixtures
	if err := json.Unmarshal(data, &f); err != nil {
		t.Fatal(err)
	}
	return f
}

func TestProtocolRevisionRoundtripAboveJSSafeInteger(t *testing.T) {
	f := loadProtocolFixtures(t)
	p, err := DecodePlan(f.Plan)
	if err != nil {
		t.Fatal(err)
	}
	if p.Revision != "9007199254740993" {
		t.Fatalf("revision changed: %s", p.Revision)
	}
	data, err := json.Marshal(p)
	if err != nil {
		t.Fatal(err)
	}
	var before, after any
	json.Unmarshal(f.Plan, &before)
	json.Unmarshal(data, &after)
	if fmt.Sprint(before) != fmt.Sprint(after) {
		t.Fatal("plan roundtrip changed fields")
	}
}

func TestProtocolRuntimeValuesNeverEnterPlanOrReport(t *testing.T) {
	f := loadProtocolFixtures(t)
	b, err := DecodeBundle(f.Bundle)
	if err != nil {
		t.Fatal(err)
	}
	p, err := DecodePlan(f.Plan)
	if err != nil {
		t.Fatal(err)
	}
	r, err := DecodeReport(f.Report)
	if err != nil {
		t.Fatal(err)
	}
	encoded, err := json.Marshal(b)
	if err != nil {
		t.Fatal(err)
	}
	for _, output := range []string{fmt.Sprintf("%v", b), fmt.Sprintf("%+v", b), fmt.Sprintf("%#v", b), string(encoded), fmt.Sprint(p), fmt.Sprint(r)} {
		if strings.Contains(output, "fixture-secret") {
			t.Fatal("runtime value escaped metadata boundary")
		}
	}
	if b.values["22222222-2222-4222-8222-222222222222"] != "fixture-secret" {
		t.Fatal("decoder lost required runtime value")
	}
}

func TestProtocolStrictInvalidVectors(t *testing.T) {
	for _, f := range loadProtocolFixtures(t).Invalid {
		t.Run(f.Name, func(t *testing.T) {
			_, err := DecodePlan(f.Input)
			if err == nil || err.Error() != f.ErrorCode {
				t.Fatalf("expected %s, got %v", f.ErrorCode, err)
			}
		})
	}
}

func TestProtocolRejectsDuplicateKeysAndOversize(t *testing.T) {
	f := loadProtocolFixtures(t)
	duplicate := strings.Replace(string(f.Plan), `"protocol": 1`, `"protocol": 1, "protocol": 1`, 1)
	if _, err := DecodePlan([]byte(duplicate)); err == nil {
		t.Fatal("duplicate key accepted")
	}
	if _, err := DecodeBundle([]byte(strings.Repeat(" ", 16777217))); err == nil {
		t.Fatal("oversize bundle accepted")
	}
	var bundle map[string]any
	json.Unmarshal(f.Bundle, &bundle)
	bundle["values"].(map[string]any)["55555555-5555-4555-8555-555555555555"] = "fixture-secret"
	raw, _ := json.Marshal(bundle)
	if _, err := DecodeBundle(raw); err == nil {
		t.Fatal("extra secret accepted")
	}
}

func TestProtocolBundleRejectsNonStringValues(t *testing.T) {
	f := loadProtocolFixtures(t)
	for _, replacement := range []string{"null", "true", "1", "[]", "{}"} {
		raw := strings.Replace(string(f.Bundle), "\"fixture-secret\"", replacement, 1)
		if _, err := DecodeBundle([]byte(raw)); err == nil {
			t.Fatalf("non-string runtime value accepted: %s", replacement)
		}
	}
	raw := strings.Replace(string(f.Bundle), "\"fixture-secret\"", "\"\\ud800\"", 1)
	if _, err := DecodeBundle([]byte(raw)); err == nil {
		t.Fatal("unpaired UTF-16 surrogate accepted")
	}
}

func TestProtocolValidUnicodeIsPreserved(t *testing.T) {
	f := loadProtocolFixtures(t)
	cases := map[string]string{
		"\"\\ud83d\\ude00\"": "😀",
		"\"\\ufffd\"":        "�",
		"\"\\\\ud800\"":      "\\ud800",
		"\"\"":               "",
	}
	for wire, expected := range cases {
		raw := strings.Replace(string(f.Bundle), "\"fixture-secret\"", wire, 1)
		bundle, err := DecodeBundle([]byte(raw))
		if err != nil {
			t.Fatalf("valid Unicode rejected: %v", err)
		}
		if bundle.values["22222222-2222-4222-8222-222222222222"] != expected {
			t.Fatal("Unicode bytes changed")
		}
	}
}

func TestProtocolSourceObservationSequence(t *testing.T) {
	root := os.Getenv("BPS_PROTOCOL_FIXTURES")
	data, err := os.ReadFile(filepath.Join(root, "protocol.json"))
	if err != nil {
		t.Fatal(err)
	}
	var corpus struct {
		Source  json.RawMessage `json:"source_dirty_report"`
		Invalid []struct {
			Name      string
			Input     json.RawMessage
			ErrorCode string `json:"error_code"`
		} `json:"invalid_reports"`
	}
	if err := json.Unmarshal(data, &corpus); err != nil {
		t.Fatal(err)
	}
	report, err := DecodeReport(corpus.Source)
	if err != nil {
		t.Fatal(err)
	}
	if report.ObservationSequence != "9007199254740993" {
		t.Fatal("source sequence lost precision")
	}
	for _, vector := range corpus.Invalid {
		if _, err := DecodeReport(vector.Input); err == nil || err.Error() != vector.ErrorCode {
			t.Fatalf("accepted invalid report %s", vector.Name)
		}
	}
}
