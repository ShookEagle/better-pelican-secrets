//go:build linux

package secrets

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"golang.org/x/sys/unix"
	"io"
	"os"
	"path/filepath"
)

type sourceSnapshot struct {
	data   []byte
	digest string
}

var errSourceChanged = errors.New("source_changed")

func openBeneath(root *os.Root, name string) (*os.File, error) {
	if root == nil || !validPath(name, false) {
		return nil, errors.New("path_unsafe")
	}
	dir, err := root.Open(".")
	if err != nil {
		return nil, err
	}
	defer dir.Close()
	fd, err := unix.Openat2(int(dir.Fd()), name, &unix.OpenHow{
		Flags:   unix.O_RDONLY | unix.O_CLOEXEC | unix.O_NOFOLLOW | unix.O_NONBLOCK,
		Resolve: unix.RESOLVE_BENEATH | unix.RESOLVE_NO_SYMLINKS | unix.RESOLVE_NO_MAGICLINKS | unix.RESOLVE_NO_XDEV,
	})
	if err != nil {
		return nil, err
	}
	file := os.NewFile(uintptr(fd), name)
	var stat unix.Stat_t
	if err := unix.Fstat(fd, &stat); err != nil {
		file.Close()
		return nil, err
	}
	if stat.Mode&unix.S_IFMT != unix.S_IFREG || stat.Mode&0111 != 0 {
		file.Close()
		return nil, errors.New("path_unsafe")
	}
	return file, nil
}
func readSource(root *os.Root, name string, maximum int64) (sourceSnapshot, error) {
	file, err := openBeneath(root, name)
	if err != nil {
		return sourceSnapshot{}, err
	}
	defer file.Close()
	var before, after unix.Stat_t
	if err := unix.Fstat(int(file.Fd()), &before); err != nil {
		return sourceSnapshot{}, err
	}
	if before.Size > maximum {
		return sourceSnapshot{}, errors.New("limit_exceeded")
	}
	data, err := io.ReadAll(io.LimitReader(file, maximum+1))
	if err != nil {
		return sourceSnapshot{}, err
	}
	if int64(len(data)) > maximum {
		return sourceSnapshot{}, errors.New("limit_exceeded")
	}
	if err := unix.Fstat(int(file.Fd()), &after); err != nil {
		return sourceSnapshot{}, err
	}
	if before.Size != after.Size || before.Mtim != after.Mtim || before.Ctim != after.Ctim {
		return sourceSnapshot{}, errSourceChanged
	}
	digest := sha256.Sum256(data)
	return sourceSnapshot{data, hex.EncodeToString(digest[:])}, nil
}
func validateMapping(root *os.Root, mapping DataMapping, files []FilePlan) error {
	if err := validateDestinations(mapping, files); err != nil {
		return err
	}
	if root == nil || !filepath.IsAbs(mapping.HostRoot) || filepath.Clean(mapping.HostRoot) != mapping.HostRoot {
		return errors.New("path_unsafe")
	}
	fd, err := unix.Openat2(unix.AT_FDCWD, mapping.HostRoot, &unix.OpenHow{
		Flags:   unix.O_RDONLY | unix.O_DIRECTORY | unix.O_CLOEXEC,
		Resolve: unix.RESOLVE_NO_SYMLINKS | unix.RESOLVE_NO_MAGICLINKS,
	})
	if err != nil {
		return sourceError(err)
	}
	expected := os.NewFile(uintptr(fd), mapping.HostRoot)
	defer expected.Close()
	actual, err := root.Open(".")
	if err != nil {
		return errors.New("path_unsafe")
	}
	defer actual.Close()
	a, err := actual.Stat()
	if err != nil {
		return errors.New("path_unsafe")
	}
	b, err := expected.Stat()
	if err != nil || !os.SameFile(a, b) {
		return errors.New("path_unsafe")
	}
	for _, file := range files {
		if target := destinationSource(mapping, file.Destination); target != file.Source {
			opened, err := openBeneath(root, target)
			if err != nil {
				return sourceError(err)
			}
			opened.Close()
		}
	}
	return nil
}
func sourceError(err error) error {
	switch {
	case errors.Is(err, unix.ENOSYS), errors.Is(err, unix.EINVAL), errors.Is(err, unix.E2BIG):
		return errors.New("node_incompatible")
	case err != nil && err.Error() == "limit_exceeded":
		return errors.New("limit_exceeded")
	default:
		return errors.New("path_unsafe")
	}
}
