//go:build linux

package server

import (
	"context"
	"errors"
	"os"
	"time"

	"github.com/pelican/wings/environment"
	"github.com/pelican/wings/secrets"
)

func (s *Server) startSecretWatcher(prepared *secrets.PreparedStart) {
	plan, applied := prepared.ReferencePlan(), prepared.Report()
	if len(plan.Files) == 0 || s.Environment.State() == environment.ProcessOfflineState {
		return
	}
	root, err := os.OpenRoot(s.Filesystem().Path())
	if err != nil {
		return
	}
	ctx, cancel := context.WithCancel(s.Context())
	done := make(chan struct{})
	s.secretWatchMutex.Lock()
	// Serialize with an offline event: it either observes this owner and
	// cancels it, or this check observes the already-offline environment.
	if s.Environment.State() == environment.ProcessOfflineState {
		s.secretWatchMutex.Unlock()
		cancel()
		root.Close()
		return
	}
	s.secretWatchCancel = cancel
	s.secretWatchDone = done
	s.secretWatchMutex.Unlock()
	watcher := &secrets.Watcher{Root: root, Report: func(ctx context.Context, report secrets.AppliedReport) error {
		return s.client.ReportSecretApplication(ctx, plan.ServerUUID, report)
	}}
	go func() { defer close(done); defer root.Close(); watcher.Run(ctx, plan, applied) }()
}
func (s *Server) stopSecretWatcher() error {
	s.secretWatchMutex.Lock()
	defer s.secretWatchMutex.Unlock()
	if s.secretWatchCancel == nil {
		return nil
	}
	s.secretWatchCancel()
	select {
	case <-s.secretWatchDone:
		s.secretWatchCancel = nil
		s.secretWatchDone = nil
		return nil
	case <-time.After(5 * time.Second):
		return errors.New("start_failed")
	}
}
