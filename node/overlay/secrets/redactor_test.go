package secrets

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"strings"
	"testing"
)

func TestRedactorHandlesChunksAndOverlappingValues(t *testing.T) {
	for _, size := range []int{1, 2, 3, 7, 64, 65536} {
		var output bytes.Buffer
		writer := NewRedactor(&output, []string{"fixture-secret", "secret", "abcd", "abc", "", "multi\nline", "prefix" + strings.Repeat("x", 65530)})
		input := "safe fixture-secret and abcd / abc and multi\nline end"
		for start := 0; start < len(input); start += size {
			n, err := writer.Write([]byte(input[start:min(len(input), start+size)]))
			if err != nil || n != min(size, len(input)-start) {
				t.Fatalf("write failed: %v", err)
			}
		}
		if err := writer.Close(); err != nil {
			t.Fatal(err)
		}
		if output.String() != "safe [REDACTED] and [REDACTED] / [REDACTED] and [REDACTED] end" {
			t.Fatalf("wrong redacted bytes for chunk size %d", size)
		}
		if _, err := writer.Write([]byte("more")); err == nil {
			t.Fatal("write after close accepted")
		}
	}
}

func TestRedactorFlushesPartialPrefixesAndDoesNotFormatValues(t *testing.T) {
	var output bytes.Buffer
	writer := NewRedactor(&output, []string{"abc", "abcd", "fixture-secret"})
	writer.Write([]byte("ab"))
	if output.Len() != 0 {
		t.Fatal("partial match escaped before next chunk")
	}
	if err := writer.Close(); err != nil {
		t.Fatal(err)
	}
	if output.String() != "ab" {
		t.Fatal("non-secret suffix lost")
	}
	encoded, err := json.Marshal(writer)
	if err != nil {
		t.Fatal(err)
	}
	for _, printed := range []string{string(encoded), fmt.Sprintf("%#v", writer), fmt.Sprintf("%+v", writer)} {
		if strings.Contains(printed, "fixture-secret") {
			t.Fatal("redactor formatted a value")
		}
	}
}

func TestRedactorPreservesOrdinaryOutputAndWriterErrors(t *testing.T) {
	var out bytes.Buffer
	writer := NewRedactor(&out, []string{""})
	input := []byte("ordinary output\n")
	n, err := writer.Write(input)
	if err != nil || n != len(input) {
		t.Fatal("ordinary output write failed")
	}
	writer.Close()
	if !bytes.Equal(out.Bytes(), input) {
		t.Fatal("ordinary output changed")
	}
	failed := NewRedactor(failingRedactionWriter{}, []string{"fixture-secret"})
	if _, err := failed.Write([]byte("normal output")); err == nil {
		t.Fatal("destination error lost")
	}
}

type failingRedactionWriter struct{}

func (failingRedactionWriter) Write([]byte) (int, error) { return 0, io.ErrClosedPipe }
