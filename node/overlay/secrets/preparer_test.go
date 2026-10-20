package secrets

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func preparationFixture(t *testing.T) (*Preparer, Plan, Bundle, *os.Root, DataMapping, GameIdentity) {
	t.Helper()
	plan, err := DecodePlan(loadProtocolFixtures(t).Plan)
	if err != nil {
		t.Fatal(err)
	}
	bundle, err := DecodeBundle(loadProtocolFixtures(t).Bundle)
	if err != nil {
		t.Fatal(err)
	}
	dir := t.TempDir()
	for _, file := range plan.Files {
		name := filepath.Join(dir, file.Source)
		if err := os.MkdirAll(filepath.Dir(name), 0755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(name, []byte("{\"key\":{{secret:KEY}}}"), 0644); err != nil {
			t.Fatal(err)
		}
	}
	root, err := os.OpenRoot(dir)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { root.Close() })
	base, err := os.MkdirTemp("/run/better-pelican-secrets", "test-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(base) })
	return &Preparer{RuntimeBase: base}, plan, bundle, root, DataMapping{dir, "/game"}, GameIdentity{1000, 1000, 1000, 1000}
}

func TestSourceChangesRetryOnce(t *testing.T) {
	for _, every := range []bool{false, true} {
		t.Run(fmt.Sprint(every), func(t *testing.T) {
			p, plan, bundle, root, mapping, id := preparationFixture(t)
			calls := 0
			p.afterSnapshot = func(attempt int) {
				calls++
				if every || attempt == 0 {
					root.WriteFile(plan.Files[0].Source, []byte(fmt.Sprintf("{\"change\":%d,\"key\":{{secret:KEY}}}", attempt)), 0644)
				}
			}
			prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id)
			if calls != 2 {
				t.Fatalf("expected exactly two snapshot attempts, got %d", calls)
			}
			if every {
				if err == nil {
					prepared.Close()
					t.Fatal("repeated source changes accepted")
				}
				entries, _ := os.ReadDir(filepath.Join(p.RuntimeBase, plan.ServerUUID))
				if len(entries) != 0 {
					t.Fatal("partial runtime attempt retained")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			defer prepared.Close()
			data, err := os.ReadFile(prepared.Mounts()[0].Source)
			if err != nil || !strings.Contains(string(data), "\"change\":0") {
				t.Fatalf("latest source not rendered: %v", err)
			}
		})
	}
}

func TestAggregateOutputBudget(t *testing.T) {
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	extra := plan.Files[0]
	extra.FileID = "66666666-6666-4666-8666-666666666666"
	extra.Source = "csgo/other.json"
	extra.Destination = "/game/csgo/other.json"
	if err := root.WriteFile(extra.Source, []byte("{\"key\":{{secret:KEY}}}"), 0644); err != nil {
		t.Fatal(err)
	}
	plan.Files = append(plan.Files, extra)
	// Each output is 24 bytes; only their sum exceeds this ceiling.
	plan.Limits.RenderedBytes = 40
	bundle.Plan = plan
	prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id)
	if err == nil {
		prepared.Close()
		t.Fatal("aggregate budget accepted")
	}
	if err.Error() != "limit_exceeded" {
		t.Fatalf("unexpected fixed code: %v", err)
	}
}

func TestRuntimePrivateSnapshotAndSerialization(t *testing.T) {
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id)
	if err != nil {
		t.Fatal(err)
	}
	defer prepared.Close()
	mounts := prepared.Mounts()
	if len(mounts) != 1 || !mounts[0].ReadOnly {
		t.Fatal("missing readonly mount")
	}
	info, err := os.Stat(mounts[0].Source)
	if err != nil || info.Mode().Perm() != 0400 {
		t.Fatalf("unsafe runtime file: %v", err)
	}
	parent, err := os.Stat(filepath.Dir(mounts[0].Source))
	if err != nil || parent.Mode().Perm() != 0700 {
		t.Fatalf("unsafe runtime directory: %v", err)
	}
	data, _ := root.ReadFile(plan.Files[0].Source)
	if strings.Contains(string(data), "fixture-secret") || !strings.Contains(string(data), "{{secret:KEY}}") {
		t.Fatal("ordinary source changed")
	}
	for _, out := range []string{fmt.Sprint(prepared), fmt.Sprintf("%+v", prepared), fmt.Sprintf("%#v", prepared), fmt.Sprintf("%#v", *prepared)} {
		if strings.Contains(out, "fixture-secret") {
			t.Fatal("prepared start formatted plaintext")
		}
	}
	encoded, err := json.Marshal(prepared)
	if err != nil || strings.Contains(string(encoded), "fixture-secret") {
		t.Fatal("prepared start serialized plaintext")
	}
	report := prepared.Report()
	encoded, _ = json.Marshal(report)
	if strings.Contains(string(encoded), "fixture-secret") || len(report.SourceDigests) != 1 {
		t.Fatal("invalid metadata report")
	}
	env := prepared.Environment()
	for _, binding := range plan.Startup {
		if env[binding.Env] != "fixture-secret" {
			t.Fatal("startup value missing")
		}
		env[binding.Env] = "changed"
	}
	for _, binding := range plan.Startup {
		if prepared.Environment()[binding.Env] != "fixture-secret" {
			t.Fatal("mutable environment alias")
		}
	}
	mountPath := mounts[0].Source
	if err := prepared.Close(); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(mountPath); !os.IsNotExist(err) {
		t.Fatal("closed runtime file retained")
	}
	if len(prepared.Environment()) != 0 || len(prepared.Mounts()) != 0 {
		t.Fatal("closed snapshot retained references")
	}
}

func TestRuntimeRejectsBundleMismatchAndCancellation(t *testing.T) {
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	bundle.Revision = "2"
	if start, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id); err == nil {
		start.Close()
		t.Fatal("mixed revision accepted")
	}
	bundle.Plan = plan
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if start, err := p.Prepare(ctx, plan, bundle, root, mapping, id); err == nil {
		start.Close()
		t.Fatal("cancelled prepare accepted")
	}
}

func TestRuntimeLastRecheckRetriesAndCleansPartialAttempt(t *testing.T) {
	for _, always := range []bool{false, true} {
		t.Run(fmt.Sprint(always), func(t *testing.T) {
			p, plan, bundle, root, mapping, id := preparationFixture(t)
			reads := 0
			p.openSource = func(root *os.Root, name string, maximum int64) (sourceSnapshot, error) {
				reads++
				if reads == 3 || always && reads == 6 {
					if err := root.WriteFile(name, []byte(fmt.Sprintf("{\"change\":%d,\"key\":{{secret:KEY}}}", reads)), 0644); err != nil {
						t.Fatal(err)
					}
				}
				return readSource(root, name, maximum)
			}
			prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id)
			if reads != 6 {
				t.Fatalf("expected two complete attempts, got %d reads", reads)
			}
			if always {
				if err == nil {
					prepared.Close()
					t.Fatal("last recheck accepted repeated changes")
				}
				entries, _ := os.ReadDir(filepath.Join(p.RuntimeBase, plan.ServerUUID))
				if len(entries) != 0 {
					t.Fatal("partial runtime attempt retained")
				}
			} else {
				if err != nil {
					t.Fatal(err)
				}
				prepared.Close()
			}
		})
	}
}
