package secrets

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"strings"
	"unicode/utf8"

	"gopkg.in/yaml.v3"
)

type compiledPart struct {
	start    int
	length   int
	literal  string
	secretID string
	mode     string
}

type CompiledTemplate struct {
	format string
	parts  []compiledPart
	limits Limits
}

var tokenPrefix = regexp.MustCompile(`\{\{!?secret`)
var tokenPattern = regexp.MustCompile(`^\{\{secret:([A-Z][A-Z0-9_]{0,63})(?:\|(raw|json-string|yaml-string|dotenv))?\}\}$`)
var literalPattern = regexp.MustCompile(`^\{\{!secret:([A-Z][A-Z0-9_]{0,63})\}\}$`)

func boundedLimits(l Limits) bool {
	max := DefaultLimits()
	actual := []int64{l.SecretBytes, l.TemplateBytes, l.ReferencesPerFile, l.FilesPerServer, l.BundleBytes, l.RenderedBytes}
	ceilings := []int64{max.SecretBytes, max.TemplateBytes, max.ReferencesPerFile, max.FilesPerServer, max.BundleBytes, max.RenderedBytes}
	for i, n := range actual {
		if n < 1 || n > ceilings[i] {
			return false
		}
	}
	return true
}

func Compile(source []byte, file FilePlan, limits Limits) (CompiledTemplate, error) {
	if !boundedLimits(limits) || int64(len(source)) > limits.TemplateBytes {
		return CompiledTemplate{}, errors.New("limit_exceeded")
	}
	if !utf8.Valid(source) || bytes.IndexByte(source, 0) >= 0 || !allowedFormat(file.Format) || !allowedMode(file.DefaultMode) ||
		(file.Format != "text" && bytes.HasPrefix(source, []byte{0xef, 0xbb, 0xbf})) {
		return CompiledTemplate{}, errors.New("template_invalid")
	}
	approved := map[string]ManifestEntry{}
	identityModes := map[string]bool{}
	names, identityNames := map[string]string{}, map[string]string{}
	var total int64
	for _, entry := range file.Manifest {
		if !uuidPattern.MatchString(entry.SecretID) || !namePattern.MatchString(entry.Name) || !allowedMode(entry.Mode) || entry.Count < 1 {
			return CompiledTemplate{}, errors.New("manifest_mismatch")
		}
		if entry.Count > limits.ReferencesPerFile-total {
			return CompiledTemplate{}, errors.New("limit_exceeded")
		}
		total += entry.Count
		key, identity := entry.Name+":"+entry.Mode, entry.SecretID+":"+entry.Mode
		if _, found := approved[key]; found || identityModes[identity] || (names[entry.Name] != "" && names[entry.Name] != entry.SecretID) ||
			(identityNames[entry.SecretID] != "" && identityNames[entry.SecretID] != entry.Name) {
			return CompiledTemplate{}, errors.New("manifest_mismatch")
		}
		approved[key], identityModes[identity], names[entry.Name], identityNames[entry.SecretID] = entry, true, entry.SecretID, entry.Name
	}
	// Conversion owns an immutable snapshot even if the caller reuses its byte buffer.
	text := string(source)
	parts := make([]compiledPart, 0)
	observed := map[string]int64{}
	cursor, references := 0, int64(0)
	var literal strings.Builder
	literalStart, literalLength := 0, 0
	appendLiteral := func(start, length int, value string) {
		if literal.Len() == 0 {
			literalStart = start
		}
		literal.WriteString(value)
		literalLength += length
	}
	flushLiteral := func() {
		if literalLength != 0 {
			parts = append(parts, compiledPart{start: literalStart, length: literalLength, literal: literal.String()})
			literal.Reset()
			literalLength = 0
		}
	}
	for cursor < len(text) {
		found := tokenPrefix.FindStringIndex(text[cursor:])
		if found == nil {
			break
		}
		start := cursor + found[0]
		if start > cursor {
			appendLiteral(cursor, start-cursor, text[cursor:start])
		}
		relativeEnd := strings.Index(text[start:], "}}")
		if relativeEnd < 0 || relativeEnd > 128 {
			return CompiledTemplate{}, errors.New("template_invalid")
		}
		end := start + relativeEnd + 2
		token := text[start:end]
		if escaped := literalPattern.FindStringSubmatch(token); escaped != nil {
			appendLiteral(start, end-start, "{{secret:"+escaped[1]+"}}")
		} else {
			match := tokenPattern.FindStringSubmatch(token)
			if match == nil {
				return CompiledTemplate{}, errors.New("template_invalid")
			}
			references++
			if references > limits.ReferencesPerFile {
				return CompiledTemplate{}, errors.New("limit_exceeded")
			}
			mode := match[2]
			if mode == "" {
				mode = file.DefaultMode
			}
			key := match[1] + ":" + mode
			entry, exists := approved[key]
			if !exists {
				return CompiledTemplate{}, errors.New("manifest_mismatch")
			}
			observed[key]++
			flushLiteral()
			parts = append(parts, compiledPart{start: start, length: end - start, secretID: entry.SecretID, mode: mode})
		}
		cursor = end
	}
	if cursor < len(text) {
		appendLiteral(cursor, len(text)-cursor, text[cursor:])
	}
	flushLiteral()
	for key, entry := range approved {
		if observed[key] != entry.Count {
			return CompiledTemplate{}, errors.New("manifest_mismatch")
		}
	}
	return CompiledTemplate{format: file.Format, parts: parts, limits: limits}, nil
}

func Render(template CompiledTemplate, values map[string]string, remainingBytes int64) ([]byte, error) {
	budget := min(remainingBytes, template.limits.RenderedBytes)
	if budget < 0 {
		return nil, errors.New("limit_exceeded")
	}
	var output bytes.Buffer
	for _, part := range template.parts {
		chunk := part.literal
		if part.secretID != "" {
			value, found := values[part.secretID]
			if !found {
				return nil, errors.New("secret_missing")
			}
			if !utf8.ValidString(value) || strings.IndexByte(value, 0) >= 0 {
				return nil, errors.New("value_invalid")
			}
			if int64(len(value)) > template.limits.SecretBytes {
				return nil, errors.New("limit_exceeded")
			}
			var err error
			chunk, err = encodeValue(value, part.mode)
			if err != nil {
				return nil, err
			}
		}
		if int64(len(chunk)) > budget-int64(output.Len()) {
			return nil, errors.New("limit_exceeded")
		}
		output.WriteString(chunk)
	}
	if err := validateRendered(output.Bytes(), template.format); err != nil {
		return nil, err
	}
	return output.Bytes(), nil
}

func encodeValue(value, mode string) (string, error) {
	if mode == "raw" {
		return value, nil
	}
	if mode == "dotenv" {
		return encodeDotenv(value)
	}
	if mode != "json-string" && mode != "yaml-string" {
		return "", errors.New("template_invalid")
	}
	var buffer bytes.Buffer
	encoder := json.NewEncoder(&buffer)
	encoder.SetEscapeHTML(false)
	if err := encoder.Encode(value); err != nil {
		return "", errors.New("value_invalid")
	}
	encoded := strings.TrimSuffix(buffer.String(), "\n")
	if mode == "yaml-string" {
		var escaped strings.Builder
		for _, r := range encoded {
			if (r >= 0x7f && r <= 0x9f) || r == 0xfffe || r == 0xffff {
				fmt.Fprintf(&escaped, `\u%04x`, r)
			} else {
				escaped.WriteRune(r)
			}
		}
		encoded = escaped.String()
	}
	return encoded, nil
}

func validateRendered(output []byte, format string) error {
	switch format {
	case "text":
		return nil
	case "dotenv":
		return validateDotenv(output)
	case "json":
		if !json.Valid(output) || !validUnicodeEscapes(output) || !validJSONDepth(output) {
			return errors.New("template_invalid")
		}
	case "yaml":
		var node yaml.Node
		decoder := yaml.NewDecoder(bytes.NewReader(output))
		if err := decoder.Decode(&node); err == io.EOF {
			return nil
		} else if err != nil || !validYAMLNode(&node, 0) {
			return errors.New("template_invalid")
		}
		var extra yaml.Node
		if err := decoder.Decode(&extra); err != io.EOF {
			return errors.New("template_invalid")
		}
		var decoded any
		if err := node.Decode(&decoded); err != nil {
			return errors.New("template_invalid")
		}
	default:
		return errors.New("template_invalid")
	}
	return nil
}

func validJSONDepth(data []byte) bool {
	depth, quoted, escaped := 0, false, false
	for _, c := range data {
		if quoted {
			if escaped {
				escaped = false
			} else if c == '\\' {
				escaped = true
			} else if c == '"' {
				quoted = false
			}
			continue
		}
		if c == '"' {
			quoted = true
		} else if c == '[' || c == '{' {
			depth++
			if depth >= 64 {
				return false
			}
		} else if c == ']' || c == '}' {
			depth--
		}
	}
	return true
}

func validYAMLNode(node *yaml.Node, depth int) bool {
	if node.Kind == yaml.AliasNode {
		return false
	}
	if node.Kind == yaml.SequenceNode || node.Kind == yaml.MappingNode {
		depth++
	}
	if depth > 64 {
		return false
	}
	switch node.ShortTag() {
	case "", "!!str", "!!map", "!!seq", "!!int", "!!bool", "!!float", "!!null", "!!timestamp", "!!binary", "!!merge":
	default:
		return false
	}
	for _, child := range node.Content {
		if !validYAMLNode(child, depth) {
			return false
		}
	}
	return true
}
