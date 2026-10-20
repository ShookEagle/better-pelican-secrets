package secrets

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type renderingVector struct {
	Name         string            `json:"name"`
	Format       string            `json:"format"`
	DefaultMode  string            `json:"default_mode"`
	Source       string            `json:"source"`
	SourceBase64 *string           `json:"source_base64"`
	Manifest     []ManifestEntry   `json:"manifest"`
	Values       map[string]string `json:"values"`
	ValuesBase64 map[string]string `json:"values_base64"`
	Expected     string            `json:"expected_output"`
	ErrorCode    string            `json:"error_code"`
	Limits       map[string]int64  `json:"limits"`
}

func loadRenderingVectors(t *testing.T) []renderingVector {
	t.Helper()
	root := os.Getenv("BPS_PROTOCOL_FIXTURES")
	if root == "" {
		t.Fatal("BPS_PROTOCOL_FIXTURES is required")
	}
	data, err := os.ReadFile(filepath.Join(root, "rendering.json"))
	if err != nil {
		t.Fatal(err)
	}
	var corpus struct{ Vectors []renderingVector }
	if err := json.Unmarshal(data, &corpus); err != nil {
		t.Fatal(err)
	}
	return corpus.Vectors
}

func renderVector(t *testing.T, vector renderingVector) {
	t.Helper()
	limits := DefaultLimits()
	if n, ok := vector.Limits["rendered_bytes"]; ok {
		limits.RenderedBytes = n
	}
	source := []byte(vector.Source)
	if vector.SourceBase64 != nil {
		var err error
		source, err = base64.StdEncoding.DecodeString(*vector.SourceBase64)
		if err != nil {
			t.Fatal(err)
		}
	}
	for id, encoded := range vector.ValuesBase64 {
		value, err := base64.StdEncoding.DecodeString(encoded)
		if err != nil {
			t.Fatal(err)
		}
		vector.Values[id] = string(value)
	}
	file := FilePlan{Format: vector.Format, DefaultMode: vector.DefaultMode, Manifest: vector.Manifest}
	template, err := Compile(source, file, limits)
	var result []byte
	if err == nil {
		result, err = Render(template, vector.Values, limits.RenderedBytes)
	}
	if vector.ErrorCode != "" {
		if err == nil || err.Error() != vector.ErrorCode {
			t.Fatalf("expected %s, got %v", vector.ErrorCode, err)
		}
		if strings.Contains(err.Error(), "fixture-secret") {
			t.Fatal("diagnostic leaked a value")
		}
		return
	}
	if err != nil {
		t.Fatal(err)
	}
	if string(result) != vector.Expected {
		t.Fatalf("rendered bytes differ in vector %s", vector.Name)
	}
	if int64(len(result)) > limits.RenderedBytes {
		t.Fatal("output exceeded byte budget")
	}
}

func TestSharedRenderingVectors(t *testing.T) {
	for _, vector := range loadRenderingVectors(t) {
		t.Run(vector.Name, func(t *testing.T) { renderVector(t, vector) })
	}
}

func fixtureManifest(mode string, count int64) []ManifestEntry {
	return []ManifestEntry{{SecretID: "22222222-2222-4222-8222-222222222222", Name: "KEY", Mode: mode, Count: count}}
}

func TestCRLFBOMAndInvalidUTF8(t *testing.T) {
	for _, vector := range loadRenderingVectors(t) {
		if strings.Contains(vector.Name, "crlf") || strings.Contains(vector.Name, "bom") || strings.Contains(vector.Name, "utf8") {
			renderVector(t, vector)
		}
	}
	source := []byte("{{secret:KEY}}")
	template, err := Compile(source, FilePlan{Format: "text", DefaultMode: "raw", Manifest: fixtureManifest("raw", 1)}, DefaultLimits())
	if err != nil {
		t.Fatal(err)
	}
	source[0] = 'x'
	result, err := Render(template, map[string]string{"22222222-2222-4222-8222-222222222222": "fixture-secret"}, 100)
	if err != nil || string(result) != "fixture-secret" {
		t.Fatal("compiled source was mutable")
	}
}

func TestExpansionBudget(t *testing.T) {
	limits := DefaultLimits()
	limits.RenderedBytes = 1024
	file := FilePlan{Format: "text", DefaultMode: "raw", Manifest: fixtureManifest("raw", 1000)}
	template, err := Compile([]byte(strings.Repeat("{{secret:KEY}}", 1000)), file, limits)
	if err != nil {
		t.Fatal(err)
	}
	result, err := Render(template, map[string]string{"22222222-2222-4222-8222-222222222222": strings.Repeat("x", 65536)}, limits.RenderedBytes)
	if err == nil || err.Error() != "limit_exceeded" || len(result) > int(limits.RenderedBytes) {
		t.Fatal("expanded output was not bounded")
	}
	result, err = Render(template, map[string]string{"22222222-2222-4222-8222-222222222222": "x"}, limits.RenderedBytes)
	if err != nil || len(result) != 1000 {
		t.Fatal("exact reference boundary failed")
	}
	file.Manifest = fixtureManifest("raw", 1001)
	if _, err := Compile([]byte(strings.Repeat("{{secret:KEY}}", 1001)), file, limits); err == nil || err.Error() != "limit_exceeded" {
		t.Fatal("1001st reference accepted")
	}
	plain := FilePlan{Format: "text", DefaultMode: "raw", Manifest: []ManifestEntry{}}
	boundary := bytes.Repeat([]byte("a"), 4194304)
	if _, err := Compile(boundary, plain, limits); err != nil {
		t.Fatal(err)
	}
	if _, err := Compile(append(boundary, 'a'), plain, limits); err == nil {
		t.Fatal("oversize source accepted")
	}
}
