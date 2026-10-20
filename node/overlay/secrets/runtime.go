//go:build linux

package secrets

import (
	"context"
	"errors"
	"io"
)

// RuntimeConsumer keeps prepared values outside persisted environment settings.
type RuntimeConsumer interface{ SetSecretRuntime(*PreparedStart) error }

// RuntimeContextProvider supplies a mapping and ownership proof from trusted
// node/Docker configuration rather than inferring ownership from a source file.
type RuntimeContextProvider interface {
	SecretRuntimeConfiguration(context.Context) (DataMapping, GameIdentity, error)
}

func (p PreparedStart) IsOpen() bool {
	if p.state == nil {
		return false
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	return p.state.area != nil
}
func (p PreparedStart) DataMapping() DataMapping {
	if p.state == nil {
		return DataMapping{}
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	return p.state.mapping
}

// SetStartupInvocation is an explicit internal plaintext export into the
// ephemeral Docker environment; it never changes the reference configuration.
func (p PreparedStart) SetStartupInvocation(invocation string) error {
	if p.state == nil {
		return errors.New("start_failed")
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	if p.state.area == nil {
		return errors.New("start_failed")
	}
	p.state.environment["STARTUP"] = invocation
	return nil
}
func (p PreparedStart) Redactor(destination io.Writer) (io.WriteCloser, error) {
	if p.state == nil {
		return nil, errors.New("start_failed")
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	if p.state.area == nil {
		return nil, errors.New("start_failed")
	}
	return NewRedactor(destination, p.state.values), nil
}

// ReferencePlan returns only copied metadata for observers of this exact start.
func (p PreparedStart) ReferencePlan() Plan {
	if p.state == nil {
		return Plan{}
	}
	p.state.mu.Lock()
	defer p.state.mu.Unlock()
	return clonePlan(p.state.plan)
}
func clonePlan(plan Plan) Plan {
	copy := plan
	copy.Versions = append([]VersionRef{}, plan.Versions...)
	copy.Startup = append([]StartupPlan{}, plan.Startup...)
	copy.Files = append([]FilePlan{}, plan.Files...)
	for index := range copy.Files {
		copy.Files[index].Manifest = append([]ManifestEntry{}, plan.Files[index].Manifest...)
	}
	return copy
}
