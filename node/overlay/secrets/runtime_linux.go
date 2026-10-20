//go:build linux

package secrets

import (
	"errors"
	"golang.org/x/sys/unix"
	"os"
	"path/filepath"
	"strings"
)

type runtimeArea struct {
	parent                *os.Root
	directory             *os.Root
	base, server, attempt string
}

func openPrivateBase(base string) (*os.File, error) {
	if !filepath.IsAbs(base) || filepath.Clean(base) != base || base == "/" {
		return nil, errors.New("path_unsafe")
	}
	fd, err := unix.Open("/", unix.O_RDONLY|unix.O_DIRECTORY|unix.O_CLOEXEC, 0)
	if err != nil {
		return nil, err
	}
	parts := strings.Split(strings.TrimPrefix(base, "/"), "/")
	for index, part := range parts {
		next, err := unix.Openat2(fd, part, &unix.OpenHow{Flags: unix.O_RDONLY | unix.O_DIRECTORY | unix.O_CLOEXEC,
			Resolve: unix.RESOLVE_BENEATH | unix.RESOLVE_NO_SYMLINKS | unix.RESOLVE_NO_MAGICLINKS})
		if errors.Is(err, unix.ENOENT) && index == len(parts)-1 {
			if err = unix.Mkdirat(fd, part, 0700); err == nil {
				next, err = unix.Openat2(fd, part, &unix.OpenHow{Flags: unix.O_RDONLY | unix.O_DIRECTORY | unix.O_CLOEXEC,
					Resolve: unix.RESOLVE_BENEATH | unix.RESOLVE_NO_SYMLINKS | unix.RESOLVE_NO_MAGICLINKS})
			}
		}
		unix.Close(fd)
		if err != nil {
			return nil, err
		}
		fd = next
		var stat unix.Stat_t
		if err := unix.Fstat(fd, &stat); err != nil {
			unix.Close(fd)
			return nil, err
		}
		if stat.Uid != 0 || stat.Mode&0022 != 0 || index == len(parts)-1 && stat.Mode&0777 != 0700 {
			unix.Close(fd)
			return nil, errors.New("path_unsafe")
		}
	}
	return os.NewFile(uintptr(fd), base), nil
}
func validateRuntimeBase(base string, mapping DataMapping, id GameIdentity) error {
	if os.Geteuid() != 0 || id.HostUID == ^uint32(0) || id.HostGID == ^uint32(0) ||
		id.ContainerUID == ^uint32(0) || id.ContainerGID == ^uint32(0) {
		return errors.New("node_incompatible")
	}
	if runtimeInsideData(base, mapping) {
		return errors.New("path_unsafe")
	}
	file, err := openPrivateBase(base)
	if err != nil {
		return sourceError(err)
	}
	defer file.Close()
	var stat unix.Statfs_t
	if err := unix.Fstatfs(int(file.Fd()), &stat); err != nil || stat.Type != unix.TMPFS_MAGIC {
		return errors.New("node_incompatible")
	}
	return nil
}
func privateDirectory(parent *os.Root, name string) error {
	if err := parent.Mkdir(name, 0700); err != nil && !os.IsExist(err) {
		return err
	}
	info, err := parent.Lstat(name)
	if err != nil {
		return err
	}
	if !info.IsDir() || info.Mode().Perm() != 0700 || info.Mode()&os.ModeSymlink != 0 {
		return errors.New("path_unsafe")
	}
	file, err := parent.Open(name)
	if err != nil {
		return err
	}
	defer file.Close()
	var actual unix.Stat_t
	if err := unix.Fstat(int(file.Fd()), &actual); err != nil {
		return err
	}
	if actual.Uid != 0 {
		return errors.New("path_unsafe")
	}
	return nil
}
func createRuntimeArea(base, server, attempt string) (*runtimeArea, error) {
	runtimeReconcileMutex.Lock()
	defer runtimeReconcileMutex.Unlock()
	if !uuidPattern.MatchString(server) || !uuidPattern.MatchString(attempt) {
		return nil, invalid()
	}
	checked, err := openPrivateBase(base)
	if err != nil {
		return nil, sourceError(err)
	}
	var stat unix.Statfs_t
	err = unix.Fstatfs(int(checked.Fd()), &stat)
	checked.Close()
	if err != nil || stat.Type != unix.TMPFS_MAGIC {
		return nil, errors.New("node_incompatible")
	}
	root, err := os.OpenRoot(base)
	if err != nil {
		return nil, errors.New("mount_failed")
	}
	defer root.Close()
	if err := privateDirectory(root, server); err != nil {
		return nil, errors.New("path_unsafe")
	}
	parent, err := root.OpenRoot(server)
	if err != nil {
		return nil, errors.New("mount_failed")
	}
	if err := parent.Mkdir(attempt, 0700); err != nil {
		parent.Close()
		return nil, errors.New("mount_failed")
	}
	directory, err := parent.OpenRoot(attempt)
	if err != nil {
		parent.RemoveAll(attempt)
		parent.Close()
		return nil, errors.New("mount_failed")
	}
	activeRuntimeAttempts[filepath.Join(base, server, attempt)] = true
	return &runtimeArea{parent, directory, base, server, attempt}, nil
}
func (a *runtimeArea) write(id string, data []byte, identity GameIdentity) (string, error) {
	if !uuidPattern.MatchString(id) {
		return "", invalid()
	}
	file, err := a.directory.OpenFile(id, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		return "", errors.New("mount_failed")
	}
	defer file.Close()
	if _, err := file.Write(data); err != nil {
		return "", errors.New("mount_failed")
	}
	if err := file.Chown(int(identity.HostUID), int(identity.HostGID)); err != nil {
		return "", errors.New("node_incompatible")
	}
	if err := file.Chmod(0400); err != nil {
		return "", errors.New("mount_failed")
	}
	var stat unix.Stat_t
	if err := unix.Fstat(int(file.Fd()), &stat); err != nil || stat.Uid != identity.HostUID || stat.Gid != identity.HostGID || stat.Mode&0777 != 0400 {
		return "", errors.New("node_incompatible")
	}
	return runtimeFilePath(a.base, a.server, a.attempt, id), nil
}
func (a *runtimeArea) close() error {
	runtimeReconcileMutex.Lock()
	defer runtimeReconcileMutex.Unlock()
	if a.directory != nil {
		a.directory.Close()
		a.directory = nil
	}
	if a.parent == nil {
		return nil
	}
	if err := a.parent.RemoveAll(a.attempt); err != nil {
		return err
	}
	delete(activeRuntimeAttempts, filepath.Join(a.base, a.server, a.attempt))
	err := a.parent.Close()
	a.parent = nil
	return err
}
