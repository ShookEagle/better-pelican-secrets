//go:build linux

package secrets

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func watcherFixture(t *testing.T) (*Watcher, Plan, AppliedReport, *os.Root, *[]AppliedReport) {
	t.Helper()
	plan, err := DecodePlan(loadProtocolFixtures(t).Plan)
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	name := filepath.Join(dir, plan.Files[0].Source)
	if err := os.MkdirAll(filepath.Dir(name), 0755); err != nil {
		t.Fatal(err)
	}
	source := []byte("{\"key\":{{secret:KEY}}}")
	if err := os.WriteFile(name, source, 0644); err != nil {
		t.Fatal(err)
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { root.Close() })
	hash := sha256.Sum256(source)
	applied := AppliedReport{Protocol: 1, ServerUUID: plan.ServerUUID, Kind: "applied", AttemptID: "44444444-4444-4444-8444-444444444444",
		Revision: plan.Revision, SourceDigests: map[string]string{plan.Files[0].FileID: hex.EncodeToString(hash[:])}, Versions: plan.Versions}
	reports := []AppliedReport{}
	watcher := &Watcher{Root: root, Report: func(_ context.Context, report AppliedReport) error { reports = append(reports, report); return nil }}
	return watcher, plan, applied, root, &reports
}
func TestWatcherObservesChangedSourceWithoutResolvingValues(t *testing.T) {
	watcher, plan, applied, root, reports := watcherFixture(t)
	if err := watcher.Observe(context.Background(), plan, applied); err != nil {
		t.Fatal(err)
	}
	source := []byte("{\"key\":{{secret:KEY}},\"ordinary\":1}")
	if err := root.WriteFile(plan.Files[0].Source, source, 0644); err != nil {
		t.Fatal(err)
	}
	if err := watcher.Observe(context.Background(), plan, applied); err != nil {
		t.Fatal(err)
	}
	last := (*reports)[len(*reports)-1]
	if last.Kind != "source-dirty" || last.Revision != applied.Revision || last.AttemptID != applied.AttemptID ||
		last.SourceDigests[plan.Files[0].FileID] == applied.SourceDigests[plan.Files[0].FileID] {
		t.Fatal("changed source not observed")
	}
	encoded, _ := json.Marshal(reports)
	if strings.Contains(string(encoded), "fixture-secret") || strings.Contains(string(encoded), "ordinary") {
		t.Fatal("watcher reported source/value text")
	}
	current, _ := root.ReadFile(plan.Files[0].Source)
	if string(current) != string(source) {
		t.Fatal("watcher rewrote source")
	}
}
func TestWatcherInvalidOrMissingSourceHasNoSuccessfulDigest(t *testing.T) {
	for _, kind := range []string{"unknown-token", "json-invalid", "symlink", "missing"} {
		t.Run(kind, func(t *testing.T) {
			watcher, plan, applied, root, reports := watcherFixture(t)
			switch kind {
			case "unknown-token":
				root.WriteFile(plan.Files[0].Source, []byte("{\"key\":{{secret:OTHER}}}"), 0644)
			case "json-invalid":
				root.WriteFile(plan.Files[0].Source, []byte("{\"key\":{{secret:KEY}}"), 0644)
			case "symlink":
				root.Remove(plan.Files[0].Source)
				root.Symlink("/etc/passwd", plan.Files[0].Source)
			case "missing":
				root.Remove(plan.Files[0].Source)
			}
			if err := watcher.Observe(context.Background(), plan, applied); err != nil {
				t.Fatal(err)
			}
			last := (*reports)[len(*reports)-1]
			if len(last.SourceDigests) != 0 || last.Kind != "source-dirty" || last.ErrorCode != nil {
				t.Fatal("invalid source reported a successful digest")
			}
		})
	}
}
func TestWatcherPollsSeriallyAndCancels(t *testing.T) {
	watcher, plan, applied, _, _ := watcherFixture(t)
	watcher.interval = 5 * time.Millisecond
	calls := 0
	watcher.Report = func(ctx context.Context, report AppliedReport) error {
		calls++
		if calls == 3 {
			<-ctx.Done()
			return ctx.Err()
		}
		return nil
	}
	ctx, cancel := context.WithCancel(context.Background())
	finished := make(chan struct{})
	go func() { watcher.Run(ctx, plan, applied); close(finished) }()
	time.Sleep(40 * time.Millisecond)
	cancel()
	select {
	case <-finished:
	case <-time.After(time.Second):
		t.Fatal("watcher did not cancel")
	}
	if calls < 3 {
		t.Fatal("watcher did not poll")
	}
}
func TestWatcherReassertsRestoredDigestsAndAppliedReport(t *testing.T) {
	watcher, plan, applied, root, reports := watcherFixture(t)
	original, _ := root.ReadFile(plan.Files[0].Source)
	watcher.Observe(context.Background(), plan, applied)
	root.WriteFile(plan.Files[0].Source, []byte("{\"key\":{{secret:KEY}},\"changed\":true}"), 0644)
	watcher.Observe(context.Background(), plan, applied)
	root.WriteFile(plan.Files[0].Source, original, 0644)
	watcher.Observe(context.Background(), plan, applied)
	last := (*reports)[len(*reports)-1]
	if last.SourceDigests[plan.Files[0].FileID] != applied.SourceDigests[plan.Files[0].FileID] {
		t.Fatal("restored source not reasserted")
	}
	appliedCount := 0
	for _, report := range *reports {
		if report.Kind == "applied" {
			appliedCount++
		}
	}
	if appliedCount < 1 {
		t.Fatal("applied report cannot recover from delivery failure")
	}
}

func TestWatcherObservationCountersAdvanceForEveryFreshPoll(t *testing.T) {
	watcher, plan, applied, _, reports := watcherFixture(t)
	for index := 0; index < 3; index++ {
		if err := watcher.Observe(context.Background(), plan, applied); err != nil {
			t.Fatal(err)
		}
	}
	wanted := []string{"1", "2", "3"}
	index := 0
	for _, report := range *reports {
		if report.Kind == "source-dirty" {
			if report.ObservationSequence != wanted[index] {
				t.Fatal("fresh poll reused a stale observation counter")
			}
			if _, err := EncodeReport(report); err != nil {
				t.Fatal(err)
			}
			index++
		}
	}
	if index != 3 {
		t.Fatal("missing fresh source observations")
	}
}
