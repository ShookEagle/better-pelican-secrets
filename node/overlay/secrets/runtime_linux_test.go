//go:build linux

package secrets

import (
	"context"
	"os"
	"path/filepath"
	"testing"

	"golang.org/x/sys/unix"
)

func TestRuntimeRejectsPersistentOrUnsafeBase(t *testing.T) {
	for _, kind := range []string{"persistent", "symlink", "under-source", "world-readable", "invalid-identity"} {
		t.Run(kind, func(t *testing.T) {
			p, plan, bundle, root, mapping, id := preparationFixture(t)
			switch kind {
			case "persistent":
				base, err := os.MkdirTemp("/run", "bps-persistent-")
				if err != nil {
					t.Fatal(err)
				}
				t.Cleanup(func() { os.RemoveAll(base) })
				p.RuntimeBase = base
			case "symlink":
				link := filepath.Join(p.RuntimeBase, "alias")
				os.Symlink(p.RuntimeBase, link)
				p.RuntimeBase = link
			case "under-source":
				p.RuntimeBase = mapping.HostRoot
			case "world-readable":
				os.Chmod(p.RuntimeBase, 0755)
			case "invalid-identity":
				id.HostUID = ^uint32(0)
			}
			if start, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id); err == nil {
				start.Close()
				t.Fatal("unsafe runtime accepted")
			}
		})
	}
}

func TestRuntimeEffectiveMappedOwnerAndExclusiveAttempt(t *testing.T) {
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	id.HostUID, id.HostGID = 101000, 101001
	start, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id)
	if err != nil {
		t.Fatal(err)
	}
	defer start.Close()
	var stat unix.Stat_t
	if err := unix.Stat(start.Mounts()[0].Source, &stat); err != nil {
		t.Fatal(err)
	}
	if stat.Uid != 101000 || stat.Gid != 101001 || stat.Mode&0777 != 0400 {
		t.Fatal("translated owner or mode lost")
	}
	if duplicate, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id); err == nil {
		duplicate.Close()
		t.Fatal("existing attempt overwritten")
	}
	data, err := os.ReadFile(start.Mounts()[0].Source)
	if err != nil || len(data) == 0 {
		t.Fatal("failed duplicate damaged active attempt")
	}
}
