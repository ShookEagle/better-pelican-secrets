package secrets

import (
	"bytes"
	"errors"
	"regexp"
	"strings"
	"unicode"
	"unicode/utf8"
)

var dotenvAssignment = regexp.MustCompile(`^(?:export[ \t]+)?[A-Za-z0-9_.]+[ \t]*=[ \t]*(.*)$`)

func encodeDotenv(value string) (string, error) {
	if !utf8.ValidString(value) {
		return "", errors.New("dotenv_unsupported")
	}
	var output strings.Builder
	output.WriteByte('"')
	for _, c := range value {
		switch c {
		case '\\':
			output.WriteString(`\\`)
		case '"':
			output.WriteString(`\"`)
		case '$':
			output.WriteString(`\$`)
		case '\r':
			output.WriteString(`\r`)
		case '\n':
			output.WriteString(`\n`)
		case '\t':
			output.WriteString(`\t`)
		case '\f':
			output.WriteString(`\f`)
		case '\v':
			output.WriteString(`\v`)
		default:
			if c < 9 || (c >= 14 && c < 32) || c == 127 {
				return "", errors.New("dotenv_unsupported")
			}
			output.WriteRune(c)
		}
	}
	output.WriteByte('"')
	return output.String(), nil
}

func validateDotenv(output []byte) error {
	normalized := bytes.ReplaceAll(output, []byte("\r\n"), []byte("\n"))
	if !utf8.Valid(normalized) {
		return errors.New("dotenv_unsupported")
	}
	for _, c := range normalized {
		if c < 9 || c == 11 || c == 12 || c == 13 || (c >= 14 && c < 32) || c == 127 {
			return errors.New("dotenv_unsupported")
		}
	}
	for _, line := range strings.Split(string(normalized), "\n") {
		line = strings.TrimLeft(line, " \t")
		if line == "" || line[0] == '#' {
			continue
		}
		match := dotenvAssignment.FindStringSubmatch(line)
		if match == nil {
			return errors.New("dotenv_unsupported")
		}
		value := match[1]
		if value == "" || value[0] == '#' {
			continue
		}
		quote := value[0]
		if quote == '"' || quote == '\'' {
			closed := false
			for i := 1; i < len(value); i++ {
				if quote == '"' && value[i] == '\\' {
					i++
					if i >= len(value) || !strings.ContainsRune("\"\\$nrtfv", rune(value[i])) {
						return errors.New("dotenv_unsupported")
					}
				} else if value[i] == quote {
					tail := strings.TrimLeft(value[i+1:], " \t")
					if tail != "" && tail[0] != '#' {
						return errors.New("dotenv_unsupported")
					}
					closed = true
					break
				}
			}
			if !closed {
				return errors.New("dotenv_unsupported")
			}
		} else {
			for i, c := range value {
				if c == '\'' || c == '"' || c == '\\' {
					return errors.New("dotenv_unsupported")
				}
				if unicode.IsSpace(c) || c == '#' {
					tail := strings.TrimLeft(value[i:], " \t")
					if tail != "" && tail[0] != '#' {
						return errors.New("dotenv_unsupported")
					}
					break
				}
			}
		}
	}
	return nil
}
