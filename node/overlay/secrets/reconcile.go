//go:build linux

package secrets

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"

	"golang.org/x/sys/unix"
)

const DefaultRuntimeDirectory = "/run/better-pelican-secrets"

// The caller supplies identities from Docker inspection of all live/starting
// containers. Labels supplement mount inspection; they never replace it.
type ContainerIdentity struct {
	ServerUUID   string
	AttemptID    string
	ContainerID  string
	MountSources []string
}

var runtimeReconcileMutex sync.Mutex
var activeRuntimeAttempts = map[string]bool{}

// Reconcile deletes only unowned UUID-shaped attempts beneath a verified private
// tmpfs. In-process preparation and Docker-inspected bind users both retain them.
func Reconcile(ctx context.Context, runtimeRoot string, liveContainers []ContainerIdentity) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	runtimeReconcileMutex.Lock()
	defer runtimeReconcileMutex.Unlock()
	file, err := openPrivateBase(runtimeRoot)
	if err != nil {
		return errors.New("path_unsafe")
	}
	defer file.Close()
	var stat unix.Statfs_t
	if err := unix.Fstatfs(int(file.Fd()), &stat); err != nil || stat.Type != unix.TMPFS_MAGIC {
		return errors.New("node_incompatible")
	}
	// Retain the validated inode even if a trusted host operator renames its path.
	root, err := os.OpenRoot(fmt.Sprintf("/proc/self/fd/%d", file.Fd()))
	if err != nil {
		return errors.New("path_unsafe")
	}
	defer root.Close()
	keep := map[string]bool{}
	keepServers := map[string]bool{}
	keepAll := false
	for _, identity := range liveContainers {
		if uuidPattern.MatchString(identity.ServerUUID) && uuidPattern.MatchString(identity.AttemptID) {
			keep[filepath.Join(identity.ServerUUID, identity.AttemptID)] = true
		}
		for _, source := range identity.MountSources {
			if !filepath.IsAbs(source) {
				return errors.New("path_unsafe")
			}
			relative, err := filepath.Rel(runtimeRoot, filepath.Clean(source))
			if err != nil || relative == ".." || strings.HasPrefix(relative, ".."+string(filepath.Separator)) {
				continue
			}
			if relative == "." {
				keepAll = true
				continue
			}
			parts := strings.Split(relative, string(filepath.Separator))
			if len(parts) == 1 {
				keepServers[parts[0]] = true
			} else {
				keep[filepath.Join(parts[0], parts[1])] = true
			}
		}
	}
	if keepAll {
		return nil
	}
	entries, err := root.Open(".")
	if err != nil {
		return errors.New("path_unsafe")
	}
	servers, err := entries.ReadDir(-1)
	entries.Close()
	if err != nil {
		return errors.New("path_unsafe")
	}
	for _, server := range servers {
		if err := ctx.Err(); err != nil {
			return err
		}
		if !uuidPattern.MatchString(server.Name()) {
			continue
		}
		if err := privateExistingDirectory(root, server.Name()); err != nil {
			return err
		}
		if keepServers[server.Name()] {
			continue
		}
		folder, err := root.OpenRoot(server.Name())
		if err != nil {
			return errors.New("path_unsafe")
		}
		dir, err := folder.Open(".")
		if err != nil {
			folder.Close()
			return errors.New("path_unsafe")
		}
		attempts, err := dir.ReadDir(-1)
		dir.Close()
		if err != nil {
			folder.Close()
			return errors.New("path_unsafe")
		}
		for _, attempt := range attempts {
			if err := ctx.Err(); err != nil {
				folder.Close()
				return err
			}
			if !uuidPattern.MatchString(attempt.Name()) {
				continue
			}
			if err := privateExistingDirectory(folder, attempt.Name()); err != nil {
				folder.Close()
				return err
			}
			key := filepath.Join(server.Name(), attempt.Name())
			if keep[key] || activeRuntimeAttempts[filepath.Join(runtimeRoot, key)] {
				continue
			}
			if err := folder.RemoveAll(attempt.Name()); err != nil {
				folder.Close()
				return errors.New("mount_failed")
			}
		}
		folder.Close()
		// An empty private server directory carries no values; keep it rather than
		// risk racing a future per-server allocation outside this cleanup interval.
	}
	return nil
}

func privateExistingDirectory(root *os.Root, name string) error {
	info, err := root.Lstat(name)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm() != 0700 {
		return errors.New("path_unsafe")
	}
	file, err := root.Open(name)
	if err != nil {
		return errors.New("path_unsafe")
	}
	defer file.Close()
	var stat unix.Stat_t
	if err := unix.Fstat(int(file.Fd()), &stat); err != nil || stat.Uid != 0 {
		return errors.New("path_unsafe")
	}
	return nil
}
