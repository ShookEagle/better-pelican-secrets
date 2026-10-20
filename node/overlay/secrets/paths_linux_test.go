//go:build linux

package secrets

import (
	"context"
	"os"
	"path/filepath"
	"testing"

	"golang.org/x/sys/unix"
)

func TestTraversalSymlinkSwapAndSpecialFiles(t *testing.T) {
	for _, kind := range []string{"traversal", "symlink", "directory", "fifo", "socket", "executable", "symlink-swap"} {
		t.Run(kind, func(t *testing.T) {
			p, plan, bundle, root, mapping, id := preparationFixture(t)
			source := plan.Files[0].Source
			target := filepath.Join(mapping.HostRoot, source)
			switch kind {
			case "traversal":
				plan.Files[0].Source = "../outside"
			case "symlink":
				os.Remove(target)
				os.Symlink("/etc/passwd", target)
			case "directory":
				os.Remove(target)
				os.Mkdir(target, 0755)
			case "fifo":
				os.Remove(target)
				if err := unix.Mkfifo(target, 0600); err != nil {
					t.Fatal(err)
				}
			case "socket":
				os.Remove(target)
				fd, err := unix.Socket(unix.AF_UNIX, unix.SOCK_STREAM, 0)
				if err != nil {
					t.Fatal(err)
				}
				defer unix.Close(fd)
				if err := unix.Bind(fd, &unix.SockaddrUnix{Name: target}); err != nil {
					t.Fatal(err)
				}
			case "executable":
				os.Chmod(target, 0755)
			case "symlink-swap":
				p.afterSnapshot = func(int) { os.Remove(target); os.Symlink("/etc/passwd", target) }
			}
			bundle.Plan = plan
			if prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id); err == nil {
				prepared.Close()
				t.Fatal("unsafe source accepted")
			}
		})
	}
}

func TestDuplicateAndOverlappingDestinations(t *testing.T) {
	for _, destination := range []string{"/game", "/game/config/child", "/etc/keys", "/game/../etc/key", "/game/run/control"} {
		t.Run(destination, func(t *testing.T) {
			p, plan, bundle, root, mapping, id := preparationFixture(t)
			plan.Files[0].Destination = destination
			// /game/config/child overlaps a second configured file.
			if destination == "/game/config/child" || destination == "/game/run/control" {
				extra := plan.Files[0]
				extra.FileID = "66666666-6666-4666-8666-666666666666"
				extra.Source = "other"
				extra.Destination = "/game/config"
				if destination == "/game/run/control" {
					mapping.ContainerRoot = "/game/run"
				}
				plan.Files = append(plan.Files, extra)
			}
			bundle.Plan = plan
			if prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id); err == nil {
				prepared.Close()
				t.Fatal("unsafe destination accepted")
			}
		})
	}
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	mapping.HostRoot = t.TempDir()
	if prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id); err == nil {
		prepared.Close()
		t.Fatal("unrelated host mapping accepted")
	}
}

func TestRuntimeRequiresOpenat2(t *testing.T) {
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	p.openSource = func(*os.Root, string, int64) (sourceSnapshot, error) { return sourceSnapshot{}, unix.ENOSYS }
	prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id)
	if err == nil {
		prepared.Close()
		t.Fatal("unsupported kernel accepted")
	}
	if err.Error() != "node_incompatible" {
		t.Fatalf("unexpected fixed code %v", err)
	}
}

func TestRuntimeCustomDestinationSwapFailsClosed(t *testing.T) {
	p, plan, bundle, root, mapping, id := preparationFixture(t)
	if err := root.WriteFile("target.json", []byte("{}"), 0644); err != nil {
		t.Fatal(err)
	}
	plan.Files[0].Destination = "/game/target.json"
	bundle.Plan = plan
	p.afterSnapshot = func(int) {
		if err := root.Remove("target.json"); err != nil {
			t.Fatal(err)
		}
		if err := os.Symlink("/etc/passwd", filepath.Join(mapping.HostRoot, "target.json")); err != nil {
			t.Fatal(err)
		}
	}
	if prepared, err := p.Prepare(context.Background(), plan, bundle, root, mapping, id); err == nil {
		prepared.Close()
		t.Fatal("swapped custom target accepted")
	}
}
