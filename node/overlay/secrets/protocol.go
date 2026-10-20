package secrets

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"
)

const BaseWingsCommit = "7b489da7b8196d0b4baef3e2db062c06d743e2f9"
const PanelBaseVersion = "1.0.0-rc.1"

const MaxBundleBytes int64 = 16 * 1024 * 1024
const MaxRenderedBytes int64 = 64 * 1024 * 1024

var uuidPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`)
var namePattern = regexp.MustCompile(`^[A-Z][A-Z0-9_]{0,63}$`)
var envPattern = regexp.MustCompile(`^[A-Za-z_][A-Za-z0-9_]{0,127}$`)
var revisionPattern = regexp.MustCompile(`^(0|[1-9][0-9]{0,19})$`)
var digestPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

type Limits struct {
	SecretBytes       int64 `json:"secret_bytes"`
	TemplateBytes     int64 `json:"template_bytes"`
	ReferencesPerFile int64 `json:"references_per_file"`
	FilesPerServer    int64 `json:"files_per_server"`
	BundleBytes       int64 `json:"bundle_bytes"`
	RenderedBytes     int64 `json:"rendered_bytes"`
}

func DefaultLimits() Limits {
	return Limits{65536, 4194304, 1000, 256, MaxBundleBytes, MaxRenderedBytes}
}

type VersionRef struct {
	SecretID string `json:"secret_id"`
	Version  int64  `json:"version"`
}

type ManifestEntry struct {
	SecretID string `json:"secret_id"`
	Name     string `json:"name"`
	Mode     string `json:"mode"`
	Count    int64  `json:"count"`
}

type FilePlan struct {
	FileID      string          `json:"file_id"`
	Source      string          `json:"source"`
	Destination string          `json:"destination"`
	Format      string          `json:"format"`
	DefaultMode string          `json:"default_mode"`
	Manifest    []ManifestEntry `json:"manifest"`
}

type StartupPlan struct {
	Env      string `json:"env"`
	SecretID string `json:"secret_id"`
}

type Plan struct {
	Protocol   int           `json:"protocol"`
	ServerUUID string        `json:"server_uuid"`
	Revision   string        `json:"revision"`
	Versions   []VersionRef  `json:"versions"`
	Files      []FilePlan    `json:"files"`
	Startup    []StartupPlan `json:"startup"`
	Limits     Limits        `json:"limits"`
}

type Bundle struct {
	Plan
	AttemptID string `json:"attempt_id"`
	values    map[string]string
}

func (b Bundle) MarshalJSON() ([]byte, error) {
	return json.Marshal(struct {
		Plan
		AttemptID string `json:"attempt_id"`
	}{b.Plan, b.AttemptID})
}
func (b Bundle) String() string                    { data, _ := b.MarshalJSON(); return string(data) }
func (b Bundle) GoString() string                  { return b.String() }
func (b Bundle) Format(state fmt.State, verb rune) { io.WriteString(state, b.String()) }

type AppliedReport struct {
	Protocol      int               `json:"protocol"`
	ServerUUID    string            `json:"server_uuid"`
	Kind          string            `json:"kind"`
	AttemptID     string            `json:"attempt_id"`
	Revision      string            `json:"revision"`
	SourceDigests map[string]string `json:"source_digests"`
	Versions      []VersionRef      `json:"versions"`
	ErrorCode     *string           `json:"error_code"`
}

var planFields = []string{"protocol", "server_uuid", "revision", "versions", "files", "startup", "limits"}

func DecodePlan(data []byte) (Plan, error) {
	var plan Plan
	if err := decodeStrict(data, &plan, planFields, false); err != nil {
		return Plan{}, err
	}
	if err := validatePlan(plan); err != nil {
		return Plan{}, err
	}
	if int64(len(data)) > plan.Limits.BundleBytes {
		return Plan{}, errors.New("limit_exceeded")
	}
	return plan, nil
}

func DecodeBundle(data []byte) (Bundle, error) {
	var wire struct {
		Plan
		AttemptID string            `json:"attempt_id"`
		Values    map[string]string `json:"values"`
	}
	fields := append(append([]string{}, planFields...), "attempt_id", "values")
	if err := decodeStrict(data, &wire, fields, false); err != nil {
		return Bundle{}, err
	}
	if err := validatePlan(wire.Plan); err != nil {
		return Bundle{}, err
	}
	if !uuidPattern.MatchString(wire.AttemptID) || wire.Values == nil || len(wire.Values) != len(wire.Versions) {
		return Bundle{}, invalid()
	}
	if int64(len(data)) > wire.Limits.BundleBytes {
		return Bundle{}, errors.New("limit_exceeded")
	}
	var total int64
	for _, ref := range wire.Versions {
		value, found := wire.Values[ref.SecretID]
		if !found || !utf8.ValidString(value) || strings.ContainsRune(value, 0) {
			return Bundle{}, invalid()
		}
		total += int64(len(value))
		if int64(len(value)) > wire.Limits.SecretBytes || total > wire.Limits.BundleBytes {
			return Bundle{}, errors.New("limit_exceeded")
		}
	}
	return Bundle{Plan: wire.Plan, AttemptID: wire.AttemptID, values: wire.Values}, nil
}

func DecodeReport(data []byte) (AppliedReport, error) {
	var report AppliedReport
	fields := []string{"protocol", "server_uuid", "kind", "attempt_id", "revision", "source_digests", "versions", "error_code"}
	if err := decodeStrict(data, &report, fields, true); err != nil {
		return AppliedReport{}, err
	}
	if err := validateIdentity(report.Protocol, report.ServerUUID, report.Revision); err != nil {
		return AppliedReport{}, err
	}
	if !uuidPattern.MatchString(report.AttemptID) || report.SourceDigests == nil || len(report.SourceDigests) > 256 {
		return AppliedReport{}, invalid()
	}
	if _, err := validateVersions(report.Versions); err != nil {
		return AppliedReport{}, err
	}
	for id, digest := range report.SourceDigests {
		if !uuidPattern.MatchString(id) || !digestPattern.MatchString(digest) {
			return AppliedReport{}, invalid()
		}
	}
	if report.Kind == "failed" {
		if report.ErrorCode == nil || !allowedError(*report.ErrorCode) {
			return AppliedReport{}, invalid()
		}
	} else if (report.Kind != "applied" && report.Kind != "source-dirty") || report.ErrorCode != nil {
		return AppliedReport{}, invalid()
	}
	return report, nil
}

func validateIdentity(protocol int, server, revision string) error {
	if protocol != 1 {
		return errors.New("protocol_unsupported")
	}
	if !uuidPattern.MatchString(server) || !revisionPattern.MatchString(revision) {
		return invalid()
	}
	if _, err := strconv.ParseUint(revision, 10, 64); err != nil {
		return invalid()
	}
	return nil
}

func validateVersions(versions []VersionRef) (map[string]bool, error) {
	if versions == nil || len(versions) > 256000 {
		return nil, invalid()
	}
	ids := make(map[string]bool, len(versions))
	for _, ref := range versions {
		if !uuidPattern.MatchString(ref.SecretID) || ref.Version < 1 || ref.Version > 2147483647 || ids[ref.SecretID] {
			return nil, invalid()
		}
		ids[ref.SecretID] = true
	}
	return ids, nil
}

func validatePlan(p Plan) error {
	if err := validateIdentity(p.Protocol, p.ServerUUID, p.Revision); err != nil {
		return err
	}
	l, max := p.Limits, DefaultLimits()
	actual := []int64{l.SecretBytes, l.TemplateBytes, l.ReferencesPerFile, l.FilesPerServer, l.BundleBytes, l.RenderedBytes}
	ceilings := []int64{max.SecretBytes, max.TemplateBytes, max.ReferencesPerFile, max.FilesPerServer, max.BundleBytes, max.RenderedBytes}
	for i, limit := range actual {
		if limit < 1 || limit > ceilings[i] {
			return invalid()
		}
	}
	ids, err := validateVersions(p.Versions)
	if err != nil {
		return err
	}
	if p.Files == nil || p.Startup == nil || int64(len(p.Files)) > l.FilesPerServer || len(p.Startup) > 4096 {
		return invalid()
	}
	used, fileIDs, sources, destinations := map[string]bool{}, map[string]bool{}, map[string]bool{}, map[string]bool{}
	names := map[string]string{}
	for _, file := range p.Files {
		if !uuidPattern.MatchString(file.FileID) || !validPath(file.Source, false) || !validPath(file.Destination, true) || !allowedFormat(file.Format) || !allowedMode(file.DefaultMode) || file.Manifest == nil {
			return invalid()
		}
		if fileIDs[file.FileID] || sources[file.Source] || destinations[file.Destination] {
			return invalid()
		}
		fileIDs[file.FileID], sources[file.Source], destinations[file.Destination] = true, true, true
		entries := map[string]bool{}
		var references int64
		for _, entry := range file.Manifest {
			if !ids[entry.SecretID] || !namePattern.MatchString(entry.Name) || !allowedMode(entry.Mode) || entry.Count < 1 || entry.Count > l.ReferencesPerFile {
				return invalid()
			}
			key := entry.SecretID + ":" + entry.Mode
			if entries[key] || (names[entry.Name] != "" && names[entry.Name] != entry.SecretID) {
				return invalid()
			}
			entries[key], names[entry.Name], used[entry.SecretID] = true, entry.SecretID, true
			references += entry.Count
			if references > l.ReferencesPerFile {
				return invalid()
			}
		}
	}
	envs := map[string]bool{}
	for _, startup := range p.Startup {
		if !envPattern.MatchString(startup.Env) || envs[strings.ToUpper(startup.Env)] || !ids[startup.SecretID] {
			return invalid()
		}
		envs[strings.ToUpper(startup.Env)], used[startup.SecretID] = true, true
	}
	if len(used) != len(ids) {
		return invalid()
	}
	return nil
}

func validPath(value string, absolute bool) bool {
	if value == "" || len(value) > 4096 || !utf8.ValidString(value) || strings.HasPrefix(value, "/") != absolute {
		return false
	}
	for _, c := range value {
		if c < 32 || c == 127 || c == '\\' {
			return false
		}
	}
	if absolute {
		value = value[1:]
	}
	for _, part := range strings.Split(value, "/") {
		if part == "" || part == "." || part == ".." {
			return false
		}
	}
	return true
}

func allowedMode(mode string) bool {
	return mode == "raw" || mode == "json-string" || mode == "yaml-string" || mode == "dotenv"
}
func allowedFormat(format string) bool {
	return format == "text" || format == "json" || format == "yaml" || format == "dotenv"
}
func allowedError(code string) bool {
	switch code {
	case "protocol_invalid", "protocol_unsupported", "limit_exceeded", "secret_missing", "template_invalid", "manifest_mismatch", "path_unsafe", "node_incompatible", "panel_unavailable", "revision_conflict", "mount_failed", "start_failed", "decryption_failed", "internal_error", "value_invalid", "dotenv_unsupported":
		return true
	}
	return false
}
func invalid() error { return errors.New("protocol_invalid") }

// Bound input before decoding, reject duplicate keys and unknown fields at every typed layer.
func decodeStrict(data []byte, target any, required []string, nullableError bool) error {
	if int64(len(data)) > MaxBundleBytes {
		return errors.New("limit_exceeded")
	}
	if !utf8.Valid(data) || !validUnicodeEscapes(data) {
		return invalid()
	}
	scan := json.NewDecoder(bytes.NewReader(data))
	if err := scanJSON(scan, 0); err != nil {
		return invalid()
	}
	if _, err := scan.Token(); err != io.EOF {
		return invalid()
	}
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(data, &fields); err != nil || len(fields) != len(required) {
		return invalid()
	}
	for _, key := range required {
		raw, ok := fields[key]
		if !ok || (bytes.Equal(bytes.TrimSpace(raw), []byte("null")) && !(nullableError && key == "error_code")) {
			return invalid()
		}
	}
	if err := exactNestedFields(fields); err != nil {
		return err
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(target); err != nil {
		return invalid()
	}
	return nil
}

func scanJSON(decoder *json.Decoder, depth int) error {
	if depth > 32 {
		return invalid()
	}
	token, err := decoder.Token()
	if err != nil {
		return err
	}
	delim, compound := token.(json.Delim)
	if !compound {
		return nil
	}
	switch delim {
	case '{':
		seen := map[string]bool{}
		for decoder.More() {
			key, err := decoder.Token()
			if err != nil {
				return err
			}
			name, ok := key.(string)
			if !ok || seen[name] {
				return invalid()
			}
			seen[name] = true
			if err := scanJSON(decoder, depth+1); err != nil {
				return err
			}
		}
		end, err := decoder.Token()
		if err != nil || end != json.Delim('}') {
			return invalid()
		}
	case '[':
		for decoder.More() {
			if err := scanJSON(decoder, depth+1); err != nil {
				return err
			}
		}
		end, err := decoder.Token()
		if err != nil || end != json.Delim(']') {
			return invalid()
		}
	default:
		return invalid()
	}
	return nil
}
func exactObject(raw []byte, keys []string) (map[string]json.RawMessage, error) {
	var fields map[string]json.RawMessage
	if err := json.Unmarshal(raw, &fields); err != nil || fields == nil || len(fields) != len(keys) {
		return nil, invalid()
	}
	for _, key := range keys {
		value, ok := fields[key]
		if !ok || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return nil, invalid()
		}
	}
	return fields, nil
}

func objectList(raw []byte, keys []string, maximum int) ([]map[string]json.RawMessage, error) {
	var rows []json.RawMessage
	if err := json.Unmarshal(raw, &rows); err != nil || rows == nil || len(rows) > maximum {
		return nil, invalid()
	}
	result := make([]map[string]json.RawMessage, 0, len(rows))
	for _, row := range rows {
		object, err := exactObject(row, keys)
		if err != nil {
			return nil, err
		}
		result = append(result, object)
	}
	return result, nil
}

func exactNestedFields(fields map[string]json.RawMessage) error {
	if raw, ok := fields["values"]; ok {
		var values map[string]json.RawMessage
		if err := json.Unmarshal(raw, &values); err != nil || values == nil {
			return invalid()
		}
		for _, value := range values {
			trimmed := bytes.TrimSpace(value)
			if len(trimmed) < 2 || trimmed[0] != '"' {
				return invalid()
			}
		}
	}
	if raw, ok := fields["versions"]; ok {
		if _, err := objectList(raw, []string{"secret_id", "version"}, 256000); err != nil {
			return err
		}
	}
	if raw, ok := fields["limits"]; ok {
		if _, err := exactObject(raw, []string{"secret_bytes", "template_bytes", "references_per_file", "files_per_server", "bundle_bytes", "rendered_bytes"}); err != nil {
			return err
		}
	}
	if raw, ok := fields["startup"]; ok {
		if _, err := objectList(raw, []string{"env", "secret_id"}, 4096); err != nil {
			return err
		}
	}
	if raw, ok := fields["files"]; ok {
		files, err := objectList(raw, []string{"file_id", "source", "destination", "format", "default_mode", "manifest"}, 256)
		if err != nil {
			return err
		}
		for _, file := range files {
			if _, err := objectList(file["manifest"], []string{"secret_id", "name", "mode", "count"}, 1000); err != nil {
				return err
			}
		}
	}
	return nil
}

func validUnicodeEscapes(data []byte) bool {
	inString := false
	for i := 0; i < len(data); i++ {
		if data[i] == '"' {
			inString = !inString
			continue
		}
		if !inString || data[i] != '\\' {
			continue
		}
		if i+1 >= len(data) {
			return false
		}
		if data[i+1] != 'u' {
			i++
			continue
		}
		if i+5 >= len(data) {
			return false
		}
		code, err := strconv.ParseUint(string(data[i+2:i+6]), 16, 16)
		if err != nil {
			return false
		}
		if code >= 0xDC00 && code <= 0xDFFF {
			return false
		}
		if code >= 0xD800 && code <= 0xDBFF {
			if i+11 >= len(data) || data[i+6] != '\\' || data[i+7] != 'u' {
				return false
			}
			low, err := strconv.ParseUint(string(data[i+8:i+12]), 16, 16)
			if err != nil || low < 0xDC00 || low > 0xDFFF {
				return false
			}
			i += 11
		} else {
			i += 5
		}
	}
	return true
}

func ValidateRequestIdentity(uuid, revision, attemptID string) error {
	if err := validateIdentity(1, uuid, revision); err != nil {
		return err
	}
	if !uuidPattern.MatchString(attemptID) {
		return invalid()
	}
	return nil
}

func IsErrorCode(code string) bool { return allowedError(code) }

func EncodeReport(report AppliedReport) ([]byte, error) {
	if err := validateIdentity(report.Protocol, report.ServerUUID, report.Revision); err != nil {
		return nil, err
	}
	if !uuidPattern.MatchString(report.AttemptID) || report.SourceDigests == nil || len(report.SourceDigests) > 256 {
		return nil, invalid()
	}
	if _, err := validateVersions(report.Versions); err != nil {
		return nil, err
	}
	for id, digest := range report.SourceDigests {
		if !uuidPattern.MatchString(id) || !digestPattern.MatchString(digest) {
			return nil, invalid()
		}
	}
	if report.Kind == "failed" {
		if report.ErrorCode == nil || !allowedError(*report.ErrorCode) {
			return nil, invalid()
		}
	} else if (report.Kind != "applied" && report.Kind != "source-dirty") || report.ErrorCode != nil {
		return nil, invalid()
	}
	data, err := json.Marshal(report)
	if err != nil {
		return nil, invalid()
	}
	if int64(len(data)) > MaxBundleBytes {
		return nil, errors.New("limit_exceeded")
	}
	return data, nil
}
