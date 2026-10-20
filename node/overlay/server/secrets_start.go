//go:build linux

package server

import (
	"context"
	"errors"
	"os"
	"strings"
	"time"

	"github.com/google/uuid"
	"github.com/pelican/wings/environment"
	"github.com/pelican/wings/secrets"
)

func (s *Server) secretPlan() *secrets.Plan {
	s.cfg.mu.RLock()
	defer s.cfg.mu.RUnlock()
	return s.cfg.Secrets
}
func (s *Server) hasSecretPlan() bool {
	plan := s.secretPlan()
	return plan != nil && (plan.Revision != "0" || len(plan.Files) > 0 || len(plan.Startup) > 0)
}
func (s *Server) reportSecretResult(report secrets.AppliedReport) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := s.client.ReportSecretApplication(ctx, s.ID(), report); err != nil {
		s.Log().Warn("unable to deliver metadata-only secret application report")
	}
}
func (s *Server) failSecretAttempt(plan *secrets.Plan, attempt string, err error) {
	if plan == nil {
		return
	}
	code := "start_failed"
	if err != nil && secrets.IsErrorCode(err.Error()) {
		code = err.Error()
	}
	s.reportSecretResult(secrets.AppliedReport{Protocol: 1, ServerUUID: plan.ServerUUID, Kind: "failed", AttemptID: attempt, Revision: plan.Revision,
		SourceDigests: map[string]string{}, Versions: append([]secrets.VersionRef{}, plan.Versions...), ErrorCode: &code})
}
func (s *Server) prepareSecretStart(ctx context.Context) (*secrets.PreparedStart, error) {
	plan := s.secretPlan()
	if !s.hasSecretPlan() {
		if consumer, ok := s.Environment.(secrets.RuntimeConsumer); ok {
			if err := consumer.SetSecretRuntime(nil); err != nil {
				return nil, errors.New("start_failed")
			}
		}
		return nil, nil
	}
	attempt := uuid.NewString()
	failed := func(err error) (*secrets.PreparedStart, error) {
		s.failSecretAttempt(plan, attempt, err)
		return nil, errors.New(secretCode(err))
	}
	consumer, ok := s.Environment.(secrets.RuntimeConsumer)
	if !ok {
		return failed(errors.New("node_incompatible"))
	}
	provider, ok := s.Environment.(secrets.RuntimeContextProvider)
	if !ok {
		return failed(errors.New("node_incompatible"))
	}
	if err := consumer.SetSecretRuntime(nil); err != nil {
		return failed(err)
	}
	for retry := 0; retry < 2; retry++ {
		bundle, err := s.client.GetSecretBundle(ctx, s.ID(), plan.Revision, attempt)
		if err != nil {
			if retry == 0 && err.Error() == "revision_conflict" {
				if err := s.Sync(); err != nil {
					return failed(errors.New("panel_unavailable"))
				}
				plan = s.secretPlan()
				if plan == nil {
					return failed(errors.New("protocol_invalid"))
				}
				attempt = uuid.NewString()
				continue
			}
			return failed(err)
		}
		mapping, identity, err := provider.SecretRuntimeConfiguration(ctx)
		if err != nil {
			return failed(err)
		}
		mapping, err = selectSecretMapping(s.Environment.Config().Mounts(), plan, s.Filesystem().Path(), mapping)
		if err != nil {
			return failed(err)
		}
		root, err := os.OpenRoot(s.Filesystem().Path())
		if err != nil {
			return failed(errors.New("path_unsafe"))
		}
		preparer := s.secretPreparer
		if preparer == nil {
			preparer = &secrets.Preparer{}
		}
		prepared, err := preparer.Prepare(ctx, *plan, bundle, root, mapping, identity)
		root.Close()
		if err != nil {
			return failed(err)
		}
		variables := environment.Variables{}
		for name, value := range s.Config().EnvVars {
			variables[name] = value
		}
		runtimeEnvironment := prepared.Environment()
		for name, value := range runtimeEnvironment {
			variables[name] = value
		}
		for _, binding := range plan.Startup {
			variables[binding.Env] = runtimeEnvironment[strings.ToUpper(binding.Env)]
		}
		invocation := parseInvocation(s.Config().Invocation, variables, s.MemoryLimit(), s.Config().Allocations.DefaultMapping.Port, s.Config().Allocations.DefaultMapping.Ip)
		if err := prepared.SetStartupInvocation(invocation); err != nil {
			prepared.Close()
			return failed(err)
		}
		if err := consumer.SetSecretRuntime(prepared); err != nil {
			prepared.Close()
			return failed(err)
		}
		return prepared, nil
	}
	return failed(errors.New("revision_conflict"))
}
func secretCode(err error) string {
	if err != nil && secrets.IsErrorCode(err.Error()) {
		return err.Error()
	}
	return "start_failed"
}
func (s *Server) startWithSecretRuntime() error {
	prepared, err := s.prepareSecretStart(s.Context())
	if err != nil {
		return err
	}
	if err := s.Environment.Start(s.Context()); err != nil {
		if prepared != nil {
			report := prepared.Report()
			report.Kind = "failed"
			code := secretCode(err)
			report.ErrorCode = &code
			if consumer, ok := s.Environment.(secrets.RuntimeConsumer); ok {
				consumer.SetSecretRuntime(nil)
			}
			prepared.Close()
			s.reportSecretResult(report)
			return errors.New(code)
		}
		return err
	}
	if prepared != nil {
		s.reportSecretResult(prepared.Report())
	}
	return nil
}
func selectSecretMapping(mounts []environment.Mount, plan *secrets.Plan, root string, fallback secrets.DataMapping) (secrets.DataMapping, error) {
	selected := fallback
	if selected.HostRoot != root {
		return selected, errors.New("path_unsafe")
	}
	found := false
	for _, mount := range mounts {
		if mount.Source != root {
			continue
		}
		matches := true
		for _, file := range plan.Files {
			if !strings.HasPrefix(file.Destination, mount.Target+"/") {
				matches = false
				break
			}
		}
		if matches && (!found || len(mount.Target) > len(selected.ContainerRoot)) {
			selected = secrets.DataMapping{HostRoot: root, ContainerRoot: mount.Target}
			found = true
		}
	}
	if !found {
		return selected, errors.New("path_unsafe")
	}
	for _, file := range plan.Files {
		for _, mount := range mounts {
			overlaps := file.Destination == mount.Target || strings.HasPrefix(file.Destination, mount.Target+"/") || strings.HasPrefix(mount.Target, file.Destination+"/")
			if overlaps && mount.Source != root {
				return selected, errors.New("path_unsafe")
			}
		}
	}
	return selected, nil
}

func (s *Server) secretPreflightFailure(err error) error {
	if !s.hasSecretPlan() {
		return err
	}
	s.failSecretAttempt(s.secretPlan(), uuid.NewString(), errors.New("start_failed"))
	return errors.New("start_failed")
}
