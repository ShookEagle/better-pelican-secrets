//go:build linux && bps_integration

package server

import (
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/creasty/defaults"
	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/client"
	"github.com/docker/docker/pkg/stdcopy"
	"github.com/pelican/wings/config"
	"github.com/pelican/wings/environment"
	dockerEnvironment "github.com/pelican/wings/environment/docker"
	"github.com/pelican/wings/secrets"
	"github.com/pelican/wings/server/filesystem"
)

func TestSecretStartDockerLifecycleLive(t *testing.T) { runSecretDockerLifecycle(t, false) }
func TestSecretLifecycleRecoveryLive(t *testing.T)    { runSecretDockerLifecycle(t, true) }
func runSecretDockerLifecycle(t *testing.T, lifecycle bool) {
	rootPath, runtimePath, image, serverID := os.Getenv("BPS_LIVE_DATA"), os.Getenv("BPS_LIVE_RUNTIME"), os.Getenv("BPS_LIVE_IMAGE"), os.Getenv("BPS_LIVE_SERVER")
	if rootPath == "" || runtimePath == "" || image == "" || serverID == "" {
		t.Fatal("explicit live Docker fixture parameters required")
	}
	cfg := &config.Configuration{AuthenticationToken: "test-token"}
	if err := defaults.Set(cfg); err != nil {
		t.Fatal(err)
	}
	cfg.System.Quotas.Enabled = false
	cfg.System.CheckPermissionsOnBoot = false
	cfg.System.MachineID.Enable = false
	cfg.System.User.Passwd.Enable = false
	cfg.System.User.Uid = 1000
	cfg.System.User.Gid = 1000
	cfg.System.Timezone = "UTC"
	cfg.Docker.Network.Mode = "none"
	cfg.Docker.TmpfsSize = 16
	cfg.Throttles.Lines = 1000
	cfg.Throttles.Period = 100
	config.Set(cfg)
	data, err := os.ReadFile(filepath.Join(os.Getenv("BPS_PROTOCOL_FIXTURES"), "protocol.json"))
	if err != nil {
		t.Fatal(err)
	}
	var corpus struct{ Plan json.RawMessage }
	if err := json.Unmarshal(data, &corpus); err != nil {
		t.Fatal(err)
	}
	plan, err := secrets.DecodePlan(corpus.Plan)
	if err != nil {
		t.Fatal(err)
	}
	plan.ServerUUID = serverID
	plan.Files[0].Destination = "/home/container/csgo/config.json"
	remote := &secretPowerRemote{plan: plan, value: "fixture-secret"}
	remote.settingsOverrides = map[string]any{
		"container":  map[string]string{"image": "~" + image},
		"labels":     map[string]string{"better-pelican-secrets.dev": "true"},
		"invocation": "echo \"startup={{RCON_PASSWORD}}\"; cat /home/container/csgo/config.json; while true; do sleep 1; done",
	}
	s, err := New(remote)
	if err != nil {
		t.Fatal(err)
	}
	defer s.CtxCancel()
	s.secretPreparer = &secrets.Preparer{RuntimeBase: runtimePath}
	s.fs, err = filesystem.New(rootPath, 0, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Join(rootPath, "csgo"), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(rootPath, "csgo/config.json"), []byte(`{"key":{{secret:KEY}}}`), 0644); err != nil {
		t.Fatal(err)
	}
	s.cfg.Uuid = serverID
	s.cfg.Secrets = &plan
	allocations := environment.Allocations{DefaultMapping: &environment.DefaultAllocationMapping{Ip: "127.0.0.1", Port: 0}}
	conf := environment.NewConfiguration(environment.Settings{Mounts: s.Mounts(), Allocations: allocations}, nil)
	env, err := dockerEnvironment.New(serverID, &dockerEnvironment.Metadata{Image: "~" + image}, conf)
	if err != nil {
		t.Fatal(err)
	}
	s.Environment = env
	env.RequireSecretRuntime(true)
	// The callback receives output only after the production streaming sanitizer.
	env.SetLogCallback(func([]byte) {})
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		t.Fatal(err)
	}
	defer cli.Close()
	cleanup := func() {
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		inspected, err := cli.ContainerInspect(ctx, serverID)
		if err == nil && inspected.Config.Labels["better-pelican-secrets.dev"] == "true" {
			cli.ContainerRemove(ctx, serverID, container.RemoveOptions{Force: true, RemoveVolumes: true})
		}
		env.SetSecretRuntime(nil)
	}
	defer cleanup()
	if err := s.HandlePowerAction(PowerActionStart); err != nil {
		t.Fatal(err)
	}
	first, err := cli.ContainerInspect(context.Background(), serverID)
	if err != nil {
		t.Fatal(err)
	}
	if !first.State.Running || !strings.Contains(strings.Join(first.Config.Env, "\n"), "RCON_PASSWORD=fixture-secret") {
		t.Fatal("initial game did not receive snapshot")
	}
	if first.HostConfig.LogConfig.Type != "none" {
		t.Fatal("raw Docker logging remained enabled")
	}
	if got := liveSecretExec(t, cli, serverID, []string{"cat", "/home/container/csgo/config.json"}); got != `{"key":"fixture-secret"}` {
		t.Fatal("initial game file did not receive snapshot")
	}
	deadline := time.Now().Add(5 * time.Second)
	for {
		history, err := env.Readlog(100)
		if err != nil {
			t.Fatal(err)
		}
		text := strings.Join(history, "\n")
		if strings.Contains(text, "fixture-secret") {
			t.Fatal("controlled history exposed a literal value")
		}
		if strings.Contains(text, "[REDACTED]") {
			break
		}
		if time.Now().After(deadline) {
			t.Fatal("sanitized history never arrived")
		}
		time.Sleep(25 * time.Millisecond)
	}
	remote.mu.Lock()
	remote.value = "second-value"
	remote.plan.Revision = "9007199254740994"
	remote.plan.Versions[0].Version = 2
	remote.mu.Unlock()
	afterSave, err := cli.ContainerInspect(context.Background(), serverID)
	if err != nil {
		t.Fatal(err)
	}
	if afterSave.ID != first.ID || remote.requests != 1 {
		t.Fatal("save caused a restart or bundle fetch")
	}
	if liveSecretExec(t, cli, serverID, []string{"cat", "/home/container/csgo/config.json"}) != `{"key":"fixture-secret"}` {
		t.Fatal("running snapshot changed after save")
	}
	if err := s.HandlePowerAction(PowerActionRestart); err != nil {
		t.Fatal(err)
	}
	second, err := cli.ContainerInspect(context.Background(), serverID)
	if err != nil {
		t.Fatal(err)
	}
	if second.ID == first.ID || !second.State.Running || !strings.Contains(strings.Join(second.Config.Env, "\n"), "RCON_PASSWORD=second-value") {
		t.Fatal("restart did not recreate with new environment")
	}
	if liveSecretExec(t, cli, serverID, []string{"cat", "/home/container/csgo/config.json"}) != `{"key":"second-value"}` {
		t.Fatal("restart did not apply new runtime file")
	}
	// Exercise the actual kernel mount as the game's configured UID.
	code := liveSecretExecCode(t, cli, serverID, []string{"sh", "-c", "echo changed > /home/container/csgo/config.json"})
	if code == 0 {
		t.Fatal("runtime bind was writable")
	}
	source, err := os.ReadFile(filepath.Join(rootPath, "csgo/config.json"))
	if err != nil || string(source) != `{"key":{{secret:KEY}}}` {
		t.Fatal("ordinary volume source changed")
	}
	cache, _ := json.Marshal(s.Config())
	if strings.Contains(string(cache), "fixture-secret") || strings.Contains(string(cache), "second-value") {
		t.Fatal("cached server configuration included values")
	}

	if lifecycle {
		remote.mu.Lock()
		remote.failure = true
		remote.mu.Unlock()
		if got := liveSecretExec(t, cli, serverID, []string{"cat", "/home/container/csgo/config.json"}); got != `{"key":"second-value"}` {
			t.Fatal("outage changed running snapshot")
		}
		remote.mu.Lock()
		remote.failure = false
		remote.mu.Unlock()
		sourcePath := filepath.Join(rootPath, "csgo/config.json")
		if err := os.Rename(sourcePath, sourcePath+".moved"); err != nil {
			t.Fatal(err)
		}
		renamed, err := cli.ContainerInspect(context.Background(), serverID)
		if err != nil || !renamed.State.Running {
			t.Fatal("source rename stopped the running game")
		}
		if err := s.HandlePowerAction(PowerActionRestart); err == nil {
			t.Fatal("missing source started")
		}
		if err := os.Rename(sourcePath+".moved", sourcePath); err != nil {
			t.Fatal(err)
		}
		if err := s.HandlePowerAction(PowerActionStart); err != nil {
			t.Fatal(err)
		}
		orphan := filepath.Join(runtimePath, serverID, "66666666-6666-4666-8666-666666666666")
		if err := os.MkdirAll(orphan, 0700); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(orphan, "fixture"), []byte("orphan-fixture"), 0400); err != nil {
			t.Fatal(err)
		}
		if err := dockerEnvironment.ReconcileSecretRuntime(context.Background(), runtimePath); err != nil {
			t.Fatal(err)
		}
		// The test driver itself binds the whole runtime root, so the adapter
		// correctly retains all of it. Qualify orphan deletion separately with
		// the real game's inspected mounts, without that synthetic root owner.
		if _, err := os.Stat(orphan); err != nil {
			t.Fatal("whole-root mount owner was ignored")
		}
		inspected, err := cli.ContainerInspect(context.Background(), serverID)
		if err != nil {
			t.Fatal(err)
		}
		identity := secrets.ContainerIdentity{ContainerID: inspected.ID}
		for _, m := range inspected.Mounts {
			if m.Source != "" {
				identity.MountSources = append(identity.MountSources, m.Source)
			}
		}
		if err := secrets.Reconcile(context.Background(), runtimePath, []secrets.ContainerIdentity{identity}); err != nil {
			t.Fatal(err)
		}
		if _, err := os.Stat(orphan); !os.IsNotExist(err) {
			t.Fatal("orphan attempt survived game inventory")
		}
		if got := liveSecretExec(t, cli, serverID, []string{"cat", "/home/container/csgo/config.json"}); got != `{"key":"second-value"}` {
			t.Fatal("reconcile damaged running snapshot")
		}
	}
	remote.mu.Lock()
	remote.failure = true
	remote.mu.Unlock()
	if err := s.HandlePowerAction(PowerActionRestart); err == nil {
		t.Fatal("failed bundle fetch started stale snapshot")
	}
	stopped, err := cli.ContainerInspect(context.Background(), serverID)
	if err != nil || stopped.State.Running {
		t.Fatal("failed preparation left game running")
	}
	if err := env.Start(context.Background()); err == nil {
		t.Fatal("direct environment start reused consumed snapshot")
	}
	if lifecycle {
		remote.mu.Lock()
		remote.failure = false
		remote.plan.Revision = "9007199254740995"
		remote.plan.Files = []secrets.FilePlan{}
		remote.plan.Startup = []secrets.StartupPlan{}
		remote.plan.Versions = []secrets.VersionRef{}
		remote.settingsOverrides["invocation"] = "while true; do sleep 1; done"
		remote.mu.Unlock()
		if err := s.HandlePowerAction(PowerActionStart); err != nil {
			t.Fatal(err)
		}
		retired, err := cli.ContainerInspect(context.Background(), serverID)
		if err != nil || !retired.State.Running {
			t.Fatal("empty replacement did not start")
		}
		for _, m := range retired.Mounts {
			if strings.HasPrefix(m.Source, runtimePath+"/") {
				t.Fatal("empty replacement retained secret mount")
			}
		}
		if strings.Contains(strings.Join(retired.Config.Env, "\\n"), "second-value") {
			t.Fatal("empty replacement retained environment value")
		}
		if s.hasSecretPlan() {
			t.Fatal("empty successfully replaced revision not retired locally")
		}
		if err := s.HandlePowerAction(PowerActionStop); err != nil {
			t.Fatal(err)
		}
		s.CleanupForDestroy()
		return
	}
	remote.mu.Lock()
	defer remote.mu.Unlock()
	if len(remote.reports) != 3 || remote.reports[0].Kind != "applied" || remote.reports[1].Kind != "applied" || remote.reports[2].Kind != "failed" {
		t.Fatal("application report ordering invalid")
	}
}

func liveSecretExec(t *testing.T, cli *client.Client, id string, command []string) string {
	t.Helper()
	exec, err := cli.ContainerExecCreate(context.Background(), id, container.ExecOptions{Cmd: command, AttachStdout: true, AttachStderr: true})
	if err != nil {
		t.Fatal(err)
	}
	attached, err := cli.ContainerExecAttach(context.Background(), exec.ID, container.ExecAttachOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer attached.Close()
	var out, stderr bytes.Buffer
	if _, err := stdcopy.StdCopy(&out, &stderr, attached.Reader); err != nil {
		t.Fatal(err)
	}
	status, err := cli.ContainerExecInspect(context.Background(), exec.ID)
	if err != nil || status.ExitCode != 0 {
		t.Fatal("fixture exec failed")
	}
	return out.String()
}
func liveSecretExecCode(t *testing.T, cli *client.Client, id string, command []string) int {
	t.Helper()
	exec, err := cli.ContainerExecCreate(context.Background(), id, container.ExecOptions{Cmd: command, AttachStdout: true, AttachStderr: true})
	if err != nil {
		t.Fatal(err)
	}
	attached, err := cli.ContainerExecAttach(context.Background(), exec.ID, container.ExecAttachOptions{})
	if err != nil {
		t.Fatal(err)
	}
	defer attached.Close()
	var out bytes.Buffer
	stdcopy.StdCopy(&out, &out, attached.Reader)
	status, err := cli.ContainerExecInspect(context.Background(), exec.ID)
	if err != nil {
		t.Fatal(err)
	}
	return status.ExitCode
}
