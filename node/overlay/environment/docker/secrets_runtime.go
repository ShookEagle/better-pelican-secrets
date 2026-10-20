//go:build linux

package docker

import (
	"context"
	"errors"
	"io"
	"os"
	"sort"
	"strings"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/docker/client"
	"github.com/pelican/wings/config"
	"github.com/pelican/wings/secrets"
	"github.com/pelican/wings/system"
)

var _ secrets.RuntimeConsumer = (*Environment)(nil)
var _ secrets.RuntimeContextProvider = (*Environment)(nil)

func (e *Environment) RequireSecretRuntime(required bool) {
	e.mu.Lock()
	defer e.mu.Unlock()
	e.secretRequired = required
}
func (e *Environment) SetSecretRuntime(prepared *secrets.PreparedStart) error {
	e.mu.Lock()
	if prepared == nil && !e.secretRequired && e.secretRuntime == nil {
		e.secretReady = false
		e.mu.Unlock()
		return nil
	}
	e.mu.Unlock()
	running, err := e.IsRunning(context.Background())
	if err != nil && !client.IsErrNotFound(err) {
		return errors.New("start_failed")
	}
	if running {
		return errors.New("start_failed")
	}
	if prepared != nil && (!prepared.IsOpen() || prepared.Report().ServerUUID != e.Id) {
		return errors.New("protocol_invalid")
	}
	e.mu.Lock()
	defer e.mu.Unlock()
	if e.secretRuntime != nil && e.secretRuntime != prepared {
		if err := e.secretRuntime.Close(); err != nil {
			return err
		}
	}
	e.secretRuntime = prepared
	e.secretReady = prepared != nil
	if prepared != nil {
		e.secretRequired = true
	}
	e.secretHistory = nil
	e.secretHistoryBytes = 0
	return nil
}
func (e *Environment) beginSecretStart() error {
	e.mu.Lock()
	defer e.mu.Unlock()
	if !e.secretRequired {
		return nil
	}
	if !e.secretReady || e.secretRuntime == nil || !e.secretRuntime.IsOpen() {
		return errors.New("start_failed")
	}
	e.secretReady = false
	return nil
}
func (e *Environment) applySecretRuntime(conf *container.Config, host *container.HostConfig) error {
	e.mu.RLock()
	prepared, required := e.secretRuntime, e.secretRequired
	e.mu.RUnlock()
	if !required {
		return nil
	}
	if prepared == nil || !prepared.IsOpen() {
		return errors.New("start_failed")
	}
	mapping := prepared.DataMapping()
	mapped := false
	for _, native := range host.Mounts {
		if native.Source == mapping.HostRoot && native.Target == mapping.ContainerRoot {
			mapped = true
		}
	}
	if !mapped {
		return errors.New("path_unsafe")
	}
	overrides := prepared.Environment()
	merged := make([]string, 0, len(conf.Env)+len(overrides))
	for _, entry := range conf.Env {
		name, _, _ := strings.Cut(entry, "=")
		if _, bound := overrides[name]; !bound {
			merged = append(merged, entry)
		}
	}
	names := make([]string, 0, len(overrides))
	for name := range overrides {
		names = append(names, name)
	}
	sort.Strings(names)
	for _, name := range names {
		merged = append(merged, name+"="+overrides[name])
	}
	conf.Env = merged
	for _, runtime := range prepared.Mounts() {
		for _, native := range host.Mounts {
			overlaps := runtime.Destination == native.Target || strings.HasPrefix(runtime.Destination, native.Target+"/") || strings.HasPrefix(native.Target, runtime.Destination+"/")
			if overlaps && native.Source != mapping.HostRoot {
				return errors.New("path_unsafe")
			}
		}
		host.Mounts = append(host.Mounts, mount.Mount{Type: mount.TypeBind, Source: runtime.Source, Target: runtime.Destination, ReadOnly: true})
	}
	// Docker's raw persistent logs bypass Wings' sanitizer. Protected games use
	// attach for live output and a bounded sanitized in-memory history instead.
	host.LogConfig = container.LogConfig{Type: "none"}
	if conf.Labels == nil {
		conf.Labels = map[string]string{}
	}
	conf.Labels["io.better-pelican-secrets.protocol"] = "1"
	return nil
}
func (e *Environment) SecretRuntimeConfiguration(ctx context.Context) (secrets.DataMapping, secrets.GameIdentity, error) {
	cfg := config.Get()
	incompatible := func() (secrets.DataMapping, secrets.GameIdentity, error) {
		return secrets.DataMapping{}, secrets.GameIdentity{}, errors.New("node_incompatible")
	}
	if cfg.System.User.Rootless.Enabled || cfg.System.User.Uid < 0 || cfg.System.User.Gid < 0 ||
		uint64(cfg.System.User.Uid) >= uint64(^uint32(0)) || uint64(cfg.System.User.Gid) >= uint64(^uint32(0)) {
		return incompatible()
	}
	if cfg.Docker.UsernsMode != "" && cfg.Docker.UsernsMode != "host" {
		return incompatible()
	}
	if !strings.HasPrefix(e.client.DaemonHost(), "unix://") {
		return incompatible()
	}
	info, err := e.client.Info(ctx)
	if err != nil {
		return incompatible()
	}
	for _, option := range info.SecurityOptions {
		if strings.HasPrefix(option, "name=rootless") || strings.HasPrefix(option, "name=userns") && cfg.Docker.UsernsMode != "host" {
			return incompatible()
		}
	}
	// Do not guess a Docker daemon remap from source-file ownership. The first
	// qualified adapter accepts the identity map, and fails closed for remapping.
	for _, name := range []string{"/proc/self/uid_map", "/proc/self/gid_map"} {
		data, err := os.ReadFile(name)
		if err != nil {
			return incompatible()
		}
		fields := strings.Fields(string(data))
		if len(fields) != 3 || fields[0] != "0" || fields[1] != "0" || fields[2] != "4294967295" {
			return incompatible()
		}
	}
	uid, gid := uint32(cfg.System.User.Uid), uint32(cfg.System.User.Gid)
	for _, native := range e.Configuration.Mounts() {
		if native.Default {
			return secrets.DataMapping{HostRoot: native.Source, ContainerRoot: native.Target},
				secrets.GameIdentity{ContainerUID: uid, ContainerGID: gid, HostUID: uid, HostGID: gid}, nil
		}
	}
	return incompatible()
}
func (e *Environment) secretLogHistory(lines int) ([]string, bool) {
	e.mu.RLock()
	defer e.mu.RUnlock()
	if !e.secretRequired {
		return nil, false
	}
	if lines <= 0 {
		return []string{}, true
	}
	start := max(0, len(e.secretHistory)-lines)
	return append([]string{}, e.secretHistory[start:]...), true
}
func (e *Environment) scanSecretOutput(reader io.Reader, callback func([]byte)) error {
	e.mu.RLock()
	prepared, required := e.secretRuntime, e.secretRequired
	e.mu.RUnlock()
	if !required {
		return system.ScanReader(reader, callback)
	}
	deliver := func(line []byte) {
		text := string(line)
		e.mu.Lock()
		e.secretHistory = append(e.secretHistory, text)
		e.secretHistoryBytes += len(text)
		for len(e.secretHistory) > 1000 || e.secretHistoryBytes > 1024*1024 {
			e.secretHistoryBytes -= len(e.secretHistory[0])
			e.secretHistory = e.secretHistory[1:]
		}
		e.mu.Unlock()
		callback(line)
	}
	if prepared == nil || !prepared.IsOpen() {
		deliver([]byte("[Secrets] Literal output redaction inventory is unavailable until the next protected restart."))
		return system.ScanReader(reader, deliver)
	}
	pipeReader, pipeWriter := io.Pipe()
	defer pipeReader.Close()
	redactor, err := prepared.Redactor(pipeWriter)
	if err != nil {
		return errors.New("start_failed")
	}
	go func() {
		_, err := io.Copy(redactor, reader)
		closeErr := redactor.Close()
		if err == nil {
			err = closeErr
		}
		pipeWriter.CloseWithError(err)
	}()
	return system.ScanReader(pipeReader, deliver)
}
