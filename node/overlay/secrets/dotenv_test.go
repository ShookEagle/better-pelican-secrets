package secrets

import (
	"strings"
	"testing"
)

func TestDotenvParity(t *testing.T) {
	for _, vector := range loadRenderingVectors(t) {
		if vector.Format == "dotenv" {
			t.Run(vector.Name, func(t *testing.T) { renderVector(t, vector) })
		}
	}
}

func TestDotenvEncoderAndDialectBoundaries(t *testing.T) {
	for _, value := range []string{"", "quotes\" slash\\ dollar$", "line\r\nnext\t\f\v雪"} {
		encoded, err := encodeDotenv(value)
		if err != nil {
			t.Fatal(err)
		}
		if err := validateDotenv([]byte("KEY=" + encoded + "\n")); err != nil {
			t.Fatal(err)
		}
	}
	for _, source := range []string{"KEY=\"first\nsecond\"\n", "KEY=\"bad\\q\"\n", "KEY=value\rNEXT=bad", "KEY=two words", "bare_name"} {
		if err := validateDotenv([]byte(source)); err == nil || err.Error() != "dotenv_unsupported" {
			t.Fatal("unsupported dotenv accepted")
		}
	}
	encoded, err := encodeDotenv("$" + "{OTHER}")
	if err != nil || !strings.Contains(encoded, "\\$") {
		t.Fatal("dotenv interpolation was not escaped")
	}
}
