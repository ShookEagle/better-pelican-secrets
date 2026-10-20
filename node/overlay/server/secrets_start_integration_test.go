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

func TestSecretStartDockerLifecycleLive(t *testing.T) {
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
