//go:build linux

package secrets

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func reconciliationFixture(t *testing.T) string {
	t.Helper()
	base, err := os.MkdirTemp("/run/better-pelican-secrets", "reconcile-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(base) })
	return base
}
func reconciliationArtifact(t *testing.T, base, server, attempt string) string {
	t.Helper()
	dir := filepath.Join(base, server, attempt)
	if err := os.MkdirAll(dir, 0700); err != nil {
		t.Fatal(err)
	}
	file := filepath.Join(dir, "33333333-3333-4333-8333-333333333333")
	if err := os.WriteFile(file, []byte("synthetic-runtime"), 0400); err != nil {
		t.Fatal(err)
	}
	return file
}
func TestReconcileNeverDeletesLiveMounts(t *testing.T) {
	base := reconciliationFixture(t)
	server := "11111111-1111-4111-8111-111111111111"
	liveAttempt := "44444444-4444-4444-8444-444444444444"
	orphanAttempt := "55555555-5555-4555-8555-555555555555"
	live := reconciliationArtifact(t, base, server, liveAttempt)
	orphan := reconciliationArtifact(t, base, server, orphanAttempt)
	// Mount inspection is authoritative even when labels are absent/mismatched.
	identity := ContainerIdentity{ContainerID: "inspected-live-container", ServerUUID: "66666666-6666-4666-8666-666666666666",
		MountSources: []string{live}}
	if err := Reconcile(context.Background(), base, []ContainerIdentity{identity}); err != nil {
		t.Fatal(err)
	}
	if bytes, err := os.ReadFile(live); err != nil || string(bytes) != "synthetic-runtime" {
		t.Fatal("live bind source was removed")
	}
	if _, err := os.Stat(orphan); !os.IsNotExist(err) {
		t.Fatal("orphan runtime remained")
	}
}
func TestReconcilePreservesInflightPreparation(t *testing.T) {
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id)
	if err != nil {
		t.Fatal(err)
	}
	defer prepared.Close()
	file := prepared.Mounts()[0].Source
	if err := Reconcile(context.Background(), p.RuntimeBase, nil); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(file); err != nil {
		t.Fatal("in-flight attempt was collected before Docker creation")
	}
}
func TestReconcileRejectsSymlinkDirectories(t *testing.T) {
	base := reconciliationFixture(t)
	outside := t.TempDir()
	sentinel := filepath.Join(outside, "sentinel")
	os.WriteFile(sentinel, []byte("keep"), 0600)
	if err := os.Symlink(outside, filepath.Join(base, "11111111-1111-4111-8111-111111111111")); err != nil {
		t.Fatal(err)
	}
	if err := Reconcile(context.Background(), base, nil); err == nil {
		t.Fatal("unsafe directory was accepted")
	}
	if bytes, err := os.ReadFile(sentinel); err != nil || string(bytes) != "keep" {
		t.Fatal("cleanup escaped its private root")
	}
}
func TestReconcileCancellationDoesNotDelete(t *testing.T) {
	base := reconciliationFixture(t)
	file := reconciliationArtifact(t, base, "11111111-1111-4111-8111-111111111111", "44444444-4444-4444-8444-444444444444")
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := Reconcile(ctx, base, nil); !errors.Is(err, context.Canceled) {
		t.Fatalf("expected cancellation: %v", err)
	}
	if _, err := os.Stat(file); err != nil {
		t.Fatal("cancelled cleanup removed an artifact")
	}
}
