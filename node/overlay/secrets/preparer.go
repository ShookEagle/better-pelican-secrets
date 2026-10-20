//go:build linux

package secrets

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path"
	"path/filepath"
	"reflect"
	"strings"
	"sync"
	"unicode/utf8"
)

const DefaultRuntimeBase = "/run/better-pelican-secrets"

type RuntimeMount struct {
	Source      string
	Destination string
	ReadOnly    bool
}
type DataMapping struct {
	HostRoot      string
	ContainerRoot string
}

// Host IDs come from the trusted Docker user-namespace mapping, never from
// server-authored settings. Container IDs are retained for qualification.
type GameIdentity struct {
	ContainerUID uint32
	ContainerGID uint32
	HostUID      uint32
	HostGID      uint32
}

type Preparer struct {
	RuntimeBase   string
	afterSnapshot func(int)
	openSource    func(*os.Root, string, int64) (sourceSnapshot, error)
}
type PreparedStart struct{ state *preparedState }
type preparedState struct {
	values      []string
	mapping     DataMapping
	mu          sync.Mutex
	mounts      []RuntimeMount
	environment map[string]string
	report      AppliedReport
	area        *runtimeArea
}

func (p PreparedStart) Mounts() []RuntimeMount {
	if p.state == nil {
		return nil
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	return append([]RuntimeMount(nil), p.state.mounts...)
}

// Environment explicitly exports ephemeral values for Docker creation only.
func (p PreparedStart) Environment() map[string]string {
	if p.state == nil {
		return map[string]string{}
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	values := map[string]string{}
	for name, value := range p.state.environment {
		values[name] = value
	}
	return values
}
func (p PreparedStart) Report() AppliedReport {
	if p.state == nil {
		return AppliedReport{SourceDigests: map[string]string{}, Versions: []VersionRef{}}
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	report := p.state.report
	report.Versions = append([]VersionRef{}, report.Versions...)
	report.SourceDigests = map[string]string{}
	for key, value := range p.state.report.SourceDigests {
		report.SourceDigests[key] = value
	}
	return report
}
func (p PreparedStart) MarshalJSON() ([]byte, error) {
	return json.Marshal(struct{ Report AppliedReport }{p.Report()})
}
func (p PreparedStart) String() string                    { data, _ := p.MarshalJSON(); return string(data) }
func (p PreparedStart) GoString() string                  { return p.String() }
func (p PreparedStart) Format(state fmt.State, verb rune) { io.WriteString(state, p.String()) }
func (p PreparedStart) Close() error {
	if p.state == nil {
		return nil
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	p.state.values = nil
	p.state.environment = nil
	p.state.mounts = nil
	if p.state.area != nil {
		if err := p.state.area.close(); err != nil {
			return errors.New("mount_failed")
		}
		p.state.area = nil
	}
	return nil
}

func (p *Preparer) Prepare(ctx context.Context, plan Plan, bundle Bundle, root *os.Root, mapping DataMapping, identity GameIdentity) (*PreparedStart, error) {
	if ctx.Err() != nil {
		return nil, errors.New("start_failed")
	}
	wire, err := json.Marshal(plan)
	if err != nil {
		return nil, invalid()
	}
	if _, err := DecodePlan(wire); err != nil {
		return nil, err
	}
	if !reflect.DeepEqual(plan, bundle.Plan) || ValidateRequestIdentity(plan.ServerUUID, plan.Revision, bundle.AttemptID) != nil {
		return nil, invalid()
	}
	if len(bundle.values) != len(plan.Versions) {
		return nil, invalid()
	}
	for _, ref := range plan.Versions {
		value, ok := bundle.values[ref.SecretID]
		if !ok {
			return nil, errors.New("secret_missing")
		}
		if !utf8.ValidString(value) || strings.IndexByte(value, 0) >= 0 {
			return nil, errors.New("value_invalid")
		}
		if int64(len(value)) > plan.Limits.SecretBytes {
			return nil, errors.New("limit_exceeded")
		}
	}
	if err := validateMapping(root, mapping, plan.Files); err != nil {
		return nil, err
	}
	base := p.RuntimeBase
	if base == "" {
		base = DefaultRuntimeBase
	}
	if err := validateRuntimeBase(base, mapping, identity); err != nil {
		return nil, err
	}
	read := p.openSource
	if read == nil {
		read = readSource
	}
	for attempt := 0; attempt < 2; attempt++ {
		outputs := make([][]byte, 0, len(plan.Files))
		digests := map[string]string{}
		remaining := plan.Limits.RenderedBytes
		changed := false
		for _, file := range plan.Files {
			if ctx.Err() != nil {
				return nil, errors.New("start_failed")
			}
			snapshot, err := read(root, file.Source, plan.Limits.TemplateBytes)
			if errors.Is(err, errSourceChanged) {
				changed = true
				break
			}
			if err != nil {
				return nil, sourceError(err)
			}
			compiled, err := Compile(snapshot.data, file, plan.Limits)
			if err != nil {
				return nil, err
			}
			output, err := Render(compiled, bundle.values, remaining)
			if err != nil {
				return nil, err
			}
			remaining -= int64(len(output))
			outputs = append(outputs, output)
			digests[file.FileID] = snapshot.digest
		}
		if p.afterSnapshot != nil {
			p.afterSnapshot(attempt)
		}
		if !changed {
			for _, file := range plan.Files {
				if ctx.Err() != nil {
					return nil, errors.New("start_failed")
				}
				snapshot, err := read(root, file.Source, plan.Limits.TemplateBytes)
				if errors.Is(err, errSourceChanged) {
					changed = true
					break
				}
				if err != nil {
					return nil, sourceError(err)
				}
				if snapshot.digest != digests[file.FileID] {
					changed = true
					break
				}
			}
		}
		if changed {
			if attempt == 0 {
				continue
			}
			return nil, errors.New("template_invalid")
		}
		if err := validateMapping(root, mapping, plan.Files); err != nil {
			return nil, err
		}
		area, err := createRuntimeArea(base, plan.ServerUUID, bundle.AttemptID)
		if err != nil {
			return nil, err
		}
		prepared := &PreparedStart{state: &preparedState{mapping: mapping, area: area, environment: map[string]string{}, report: AppliedReport{
			Protocol: 1, ServerUUID: plan.ServerUUID, Kind: "applied", AttemptID: bundle.AttemptID,
			Revision: plan.Revision, SourceDigests: digests, Versions: append([]VersionRef{}, plan.Versions...),
		}}}
		for index, file := range plan.Files {
			if ctx.Err() != nil {
				prepared.Close()
				return nil, errors.New("start_failed")
			}
			source, err := area.write(file.FileID, outputs[index], identity)
			if err != nil {
				prepared.Close()
				return nil, err
			}
			prepared.state.mounts = append(prepared.state.mounts, RuntimeMount{source, file.Destination, true})
			outputs[index] = nil
		}
		// Recheck immediately before handing off mounts, including after writes.
		for _, file := range plan.Files {
			snapshot, err := read(root, file.Source, plan.Limits.TemplateBytes)
			if err != nil {
				prepared.Close()
				if errors.Is(err, errSourceChanged) {
					changed = true
					break
				}
				return nil, sourceError(err)
			}
			if snapshot.digest != digests[file.FileID] {
				prepared.Close()
				changed = true
				break
			}
		}
		if changed {
			if attempt == 0 {
				continue
			}
			return nil, errors.New("template_invalid")
		}
		if err := validateMapping(root, mapping, plan.Files); err != nil {
			prepared.Close()
			return nil, err
		}
		for _, value := range bundle.values {
			prepared.state.values = append(prepared.state.values, value)
		}
		for _, binding := range plan.Startup {
			prepared.state.environment[strings.ToUpper(binding.Env)] = bundle.values[binding.SecretID]
		}
		if ctx.Err() != nil {
			prepared.Close()
			return nil, errors.New("start_failed")
		}
		return prepared, nil
	}
	return nil, errors.New("template_invalid")
}

func validateDestinations(mapping DataMapping, files []FilePlan) error {
	if !validPath(mapping.ContainerRoot, true) || mapping.ContainerRoot == "/" || reservedDestination(mapping.ContainerRoot) {
		return errors.New("path_unsafe")
	}
	for i, file := range files {
		if !validPath(file.Source, false) || !validPath(file.Destination, true) ||
			!strings.HasPrefix(file.Destination, mapping.ContainerRoot+"/") || reservedDestination(file.Destination) {
			return errors.New("path_unsafe")
		}
		for _, previous := range files[:i] {
			if file.Destination == previous.Destination || strings.HasPrefix(file.Destination, previous.Destination+"/") ||
				strings.HasPrefix(previous.Destination, file.Destination+"/") {
				return errors.New("path_unsafe")
			}
		}
	}
	return nil
}
func reservedDestination(destination string) bool {
	for _, reserved := range []string{"/proc", "/sys", "/dev", "/run", "/etc", "/bin", "/sbin", "/usr", "/lib", "/lib64", "/boot"} {
		if destination == reserved || strings.HasPrefix(destination, reserved+"/") {
			return true
		}
	}
	return false
}
func destinationSource(mapping DataMapping, destination string) string {
	return strings.TrimPrefix(destination, mapping.ContainerRoot+"/")
}
func runtimeInsideData(base string, mapping DataMapping) bool {
	relative, err := filepath.Rel(mapping.HostRoot, base)
	return err != nil || relative == "." || relative != ".." && !strings.HasPrefix(relative, ".."+string(os.PathSeparator))
}
func runtimeFilePath(base, server, attempt, file string) string {
	return path.Join(base, server, attempt, file)
}
