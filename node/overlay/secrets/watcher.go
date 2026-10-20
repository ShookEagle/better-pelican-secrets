//go:build linux

package secrets

import (
	"context"
	"errors"
	"os"
	"strconv"
	"time"
)

// Watcher owns no values. Its caller owns Root until Run finishes. Reports are
// serialized; each poll reasserts current metadata, including restored digests.
type Watcher struct {
	sequence uint64
	Root     *os.Root
	Report   func(context.Context, AppliedReport) error
	interval time.Duration
}

func (w *Watcher) Observe(ctx context.Context, plan Plan, applied AppliedReport) error {
	if ctx.Err() != nil {
		return ctx.Err()
	}
	if w.Root == nil || w.Report == nil || applied.Kind != "applied" || applied.ServerUUID != plan.ServerUUID || applied.Revision != plan.Revision {
		return invalid()
	}
	if err := w.Report(ctx, applied); err != nil {
		return err
	}
	report := applied
	report.Kind = "source-dirty"
	report.ErrorCode = nil
	report.SourceDigests = map[string]string{}
	remaining := plan.Limits.RenderedBytes
	for _, file := range plan.Files {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		snapshot, err := readSource(w.Root, file.Source, plan.Limits.TemplateBytes)
		if err != nil {
			continue
		}
		compiled, err := Compile(snapshot.data, file, plan.Limits)
		if err != nil {
			continue
		}
		dummy := map[string]string{}
		for _, entry := range file.Manifest {
			dummy[entry.SecretID] = "DUMMY_VALUE"
		}
		output, err := Render(compiled, dummy, remaining)
		if err != nil {
			continue
		}
		remaining -= int64(len(output))
		report.SourceDigests[file.FileID] = snapshot.digest
	}
	if w.sequence == ^uint64(0) {
		return errors.New("limit_exceeded")
	}
	w.sequence++
	report.ObservationSequence = strconv.FormatUint(w.sequence, 10)
	return w.Report(ctx, report)
}

func (w *Watcher) Run(ctx context.Context, plan Plan, applied AppliedReport) {
	interval := w.interval
	if interval <= 0 {
		interval = 5 * time.Second
	}
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
		// Errors leave the previous observation intact; a later poll reconciles.
		if err := w.Observe(ctx, plan, applied); err != nil && errors.Is(err, context.Canceled) {
			return
		}
	}
}
