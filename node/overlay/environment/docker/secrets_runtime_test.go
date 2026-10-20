package docker

import (
	"context"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/docker/client"
	"github.com/pelican/wings/config"
	"github.com/pelican/wings/environment"
	"github.com/pelican/wings/secrets"
	"github.com/pelican/wings/system"
)

func dockerSecretSnapshot(t *testing.T, value, attempt string) *secrets.PreparedStart {
	t.Helper()
	data, err := os.ReadFile(filepath.Join(os.Getenv("BPS_PROTOCOL_FIXTURES"), "protocol.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fixtures struct {
		Plan   json.RawMessage
		Bundle map[string]any
	}
	if err := json.Unmarshal(data, &fixtures); err != nil {
		t.Fatal(err)
	}
	plan, err := secrets.DecodePlan(fixtures.Plan)
	if err != nil {
		t.Fatal(err)
	}
	fixtures.Bundle["attempt_id"] = attempt
	fixtures.Bundle["values"] = map[string]string{plan.Versions[0].SecretID: value}
	wire, _ := json.Marshal(fixtures.Bundle)
	bundle, err := secrets.DecodeBundle(wire)
	if err != nil {
		t.Fatal(err)
	}
	rootPath := t.TempDir()
	os.MkdirAll(filepath.Join(rootPath, "csgo"), 0755)
	os.WriteFile(filepath.Join(rootPath, "csgo/config.json"), []byte("{\"key\":{{secret:KEY}}}"), 0644)
	root, err := os.OpenRoot(rootPath)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	base, err := os.MkdirTemp("/run/better-pelican-secrets", "docker-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(base) })
	p := secrets.Preparer{RuntimeBase: base}
	prepared, err := p.Prepare(context.Background(), plan, bundle, root, secrets.DataMapping{HostRoot: rootPath, ContainerRoot: "/game"}, secrets.GameIdentity{ContainerUID: 1000, ContainerGID: 1000, HostUID: 1000, HostGID: 1000})
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { prepared.Close() })
	return prepared
}
func secretDockerEnvironment(t *testing.T) *Environment {
	t.Helper()
	config.Set(&config.Configuration{AuthenticationToken: "test-token"})
	api := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(404)
		w.Write([]byte("{\"message\":\"No such container\"}"))
	}))
	t.Cleanup(api.Close)
	cli, err := client.NewClientWithOpts(client.WithHost(api.URL), client.WithVersion("1.51"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { cli.Close() })
	return &Environment{Id: "11111111-1111-4111-8111-111111111111", client: cli, Configuration: environment.NewConfiguration(environment.Settings{}, []string{"RCON_PASSWORD=old-ordinary", "OTHER=keep"}), st: system.NewAtomicString(environment.ProcessOfflineState)}
}
func TestStoppedContainerGetsNewEnvAndMounts(t *testing.T) {
	env := secretDockerEnvironment(t)
	first := dockerSecretSnapshot(t, "fixture-secret", "44444444-4444-4444-8444-444444444444")
	if err := env.SetSecretRuntime(first); err != nil {
		t.Fatal(err)
	}
	config := &container.Config{Env: append([]string{}, env.Configuration.EnvironmentVariables()...)}
	host := &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeBind, Source: first.DataMapping().HostRoot, Target: first.DataMapping().ContainerRoot}}}
	if err := env.applySecretRuntime(config, host); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(strings.Join(config.Env, "\n"), "RCON_PASSWORD=fixture-secret") || len(host.Mounts) != 2 || !host.Mounts[1].ReadOnly {
		t.Fatal("ephemeral overrides missing")
	}
	if host.LogConfig.Type != "none" {
		t.Fatal("protected container retained raw Docker log storage")
	}
	if strings.Contains(strings.Join(env.Configuration.EnvironmentVariables(), "\n"), "fixture-secret") {
		t.Fatal("ordinary configuration mutated")
	}
	second := dockerSecretSnapshot(t, "second-value", "55555555-5555-4555-8555-555555555555")
	if err := env.SetSecretRuntime(second); err != nil {
		t.Fatal(err)
	}
	config = &container.Config{Env: append([]string{}, env.Configuration.EnvironmentVariables()...)}
	host = &container.HostConfig{Mounts: []mount.Mount{{Type: mount.TypeBind, Source: second.DataMapping().HostRoot, Target: second.DataMapping().ContainerRoot}}}
	if err := env.applySecretRuntime(config, host); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(strings.Join(config.Env, "\n"), "fixture-secret") || !strings.Contains(strings.Join(config.Env, "\n"), "second-value") {
		t.Fatal("stale environment reused")
	}
	if len(first.Mounts()) != 0 {
		t.Fatal("old runtime attempt retained")
	}
}
func TestSecretDockerRequiresFreshMaterial(t *testing.T) {
	env := secretDockerEnvironment(t)
	env.RequireSecretRuntime(true)
	if err := env.applySecretRuntime(&container.Config{}, &container.HostConfig{}); err == nil {
		t.Fatal("protected create accepted absent snapshot")
	}
	prepared := dockerSecretSnapshot(t, "fixture-secret", "44444444-4444-4444-8444-444444444444")
	if err := env.SetSecretRuntime(prepared); err != nil {
		t.Fatal(err)
	}
	if err := env.beginSecretStart(); err != nil {
		t.Fatal(err)
	}
	if err := env.beginSecretStart(); err == nil {
		t.Fatal("stale attempt started twice")
	}
	prepared.Close()
	if err := env.applySecretRuntime(&container.Config{}, &container.HostConfig{}); err == nil {
		t.Fatal("closed snapshot reused")
	}
}

func TestSecretOutputAndHistoryReceiveSanitizedBytes(t *testing.T) {
	env := secretDockerEnvironment(t)
	prepared := dockerSecretSnapshot(t, "multi\nline", "44444444-4444-4444-8444-444444444444")
	if err := env.SetSecretRuntime(prepared); err != nil {
		t.Fatal(err)
	}
	var lines []string
	if err := env.scanSecretOutput(strings.NewReader("safe multi\nline end\n"), func(line []byte) { lines = append(lines, string(line)) }); err != nil {
		t.Fatal(err)
	}
	history, err := env.Readlog(20)
	if err != nil || strings.Join(lines, "|") != "safe [REDACTED] end" || strings.Join(history, "|") != strings.Join(lines, "|") {
		t.Fatal("controlled delivery/history did not use streaming sanitizer")
	}
}

func TestSecretDockerRejectsRemoteDaemon(t *testing.T) {
	config.Set(&config.Configuration{AuthenticationToken: "test-token"})
	api := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte("{}"))
	}))
	defer api.Close()
	cli, err := client.NewClientWithOpts(client.WithHost(api.URL), client.WithVersion("1.51"))
	if err != nil {
		t.Fatal(err)
	}
	defer cli.Close()
	env := &Environment{client: cli, Configuration: environment.NewConfiguration(environment.Settings{Mounts: []environment.Mount{{Default: true, Source: t.TempDir(), Target: "/game"}}}, nil)}
	if _, _, err := env.SecretRuntimeConfiguration(context.Background()); err == nil {
		t.Fatal("unqualified remote daemon mapping accepted")
	}
}

func TestSecretDockerIdentityNamespaces(t *testing.T) {
	for _, testcase := range []struct {
		name, security, mode string
		rootless, valid      bool
	}{
		{"identity", "", "", false, true},
		{"remapped", "name=userns", "", false, false},
		{"host-identity", "name=userns", "host", false, true},
		{"rootless-daemon", "name=rootless", "", false, false},
		{"rootless-setting", "", "", true, false},
	} {
		t.Run(testcase.name, func(t *testing.T) {
			cfg := &config.Configuration{AuthenticationToken: "test-token"}
			cfg.System.User.Uid = 1000
			cfg.System.User.Gid = 1001
			cfg.System.User.Rootless.Enabled = testcase.rootless
			cfg.Docker.UsernsMode = testcase.mode
			config.Set(cfg)
			dir, err := os.MkdirTemp("/tmp", "bps-socket-")
			if err != nil {
				t.Fatal(err)
			}
			defer os.RemoveAll(dir)
			socket := filepath.Join(dir, "docker.sock")
			listener, err := net.Listen("unix", socket)
			if err != nil {
				t.Fatal(err)
			}
			api := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.Header().Set("Content-Type", "application/json")
				options := []string{}
				if testcase.security != "" {
					options = append(options, testcase.security)
				}
				json.NewEncoder(w).Encode(map[string]any{"SecurityOptions": options})
			})}
			go api.Serve(listener)
			defer api.Close()
			cli, err := client.NewClientWithOpts(client.WithHost("unix://"+socket), client.WithVersion("1.51"))
			if err != nil {
				t.Fatal(err)
			}
			defer cli.Close()
			env := &Environment{client: cli, Configuration: environment.NewConfiguration(environment.Settings{Mounts: []environment.Mount{{Default: true, Source: t.TempDir(), Target: "/game"}}}, nil)}
			_, identity, err := env.SecretRuntimeConfiguration(context.Background())
			if (err == nil) != testcase.valid {
				t.Fatalf("wrong namespace result: %v", err)
			}
			if testcase.valid && (identity.HostUID != 1000 || identity.HostGID != 1001 || identity.ContainerUID != 1000 || identity.ContainerGID != 1001) {
				t.Fatal("incorrect game identity")
			}
		})
	}
}

func TestSecretOrdinaryAttachDoesNotRequireStoppedContainer(t *testing.T) {
	config.Set(&config.Configuration{AuthenticationToken: "test-token"})
	calls := 0
	api := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"State": map[string]bool{"Running": true}})
	}))
	defer api.Close()
	cli, err := client.NewClientWithOpts(client.WithHost(api.URL), client.WithVersion("1.51"))
	if err != nil {
		t.Fatal(err)
	}
	defer cli.Close()
	env := &Environment{client: cli}
	if err := env.SetSecretRuntime(nil); err != nil {
		t.Fatal("ordinary attach acquired a secret lifecycle requirement")
	}
	if calls != 0 {
		t.Fatal("ordinary attach probed secret runtime ownership")
	}
}
