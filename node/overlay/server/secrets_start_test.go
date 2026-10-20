package server

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/pelican/wings/config"
	"github.com/pelican/wings/environment"
	"github.com/pelican/wings/remote"
	"github.com/pelican/wings/secrets"
	"github.com/pelican/wings/server/filesystem"
)

type secretPowerRemote struct {
	settingsOverrides map[string]any
	remote.Client
	mu       sync.Mutex
	plan     secrets.Plan
	value    string
	failure  bool
	requests int
	reports  []secrets.AppliedReport
	entered  chan struct{}
	release  chan struct{}
}

func (c *secretPowerRemote) GetServerConfiguration(context.Context, string) (remote.ServerConfigurationResponse, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	settings := map[string]any{"uuid": c.plan.ServerUUID, "secrets": c.plan, "environment": map[string]string{}, "invocation": "echo {{RCON_PASSWORD}}", "allocations": map[string]any{"default": map[string]any{"ip": "127.0.0.1", "port": 0}}}
	for key, value := range c.settingsOverrides {
		settings[key] = value
	}
	wire, err := json.Marshal(settings)
	return remote.ServerConfigurationResponse{Settings: wire, SecretPlan: &c.plan, ProcessConfiguration: &remote.ProcessConfiguration{}}, err
}
func (c *secretPowerRemote) GetSecretBundle(ctx context.Context, server, revision, attempt string) (secrets.Bundle, error) {
	c.mu.Lock()
	c.requests++
	fail := c.failure
	plan := c.plan
	value := c.value
	entered, release := c.entered, c.release
	c.mu.Unlock()
	if entered != nil {
		select {
		case entered <- struct{}{}:
		default:
		}
		select {
		case <-release:
		case <-ctx.Done():
			return secrets.Bundle{}, errors.New("panel_unavailable")
		}
	}
	if fail {
		return secrets.Bundle{}, errors.New("panel_unavailable")
	}
	if server != plan.ServerUUID || revision != plan.Revision {
		return secrets.Bundle{}, errors.New("revision_conflict")
	}
	raw, _ := json.Marshal(plan)
	var wire map[string]any
	json.Unmarshal(raw, &wire)
	wire["attempt_id"] = attempt
	wire["values"] = map[string]string{plan.Versions[0].SecretID: value}
	raw, _ = json.Marshal(wire)
	return secrets.DecodeBundle(raw)
}
func (c *secretPowerRemote) ReportSecretApplication(_ context.Context, _ string, report secrets.AppliedReport) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.reports = append(c.reports, report)
	return nil
}

type secretPowerEnvironment struct {
	environment.ProcessEnvironment
	mu            sync.Mutex
	configuration *environment.Configuration
	root          string
	state         string
	prepared      *secrets.PreparedStart
	starts        int
	snapshots     []map[string]string
	failStart     bool
}

func (e *secretPowerEnvironment) Config() *environment.Configuration { return e.configuration }
func (e *secretPowerEnvironment) State() string                      { e.mu.Lock(); defer e.mu.Unlock(); return e.state }
func (e *secretPowerEnvironment) InSituUpdate() error                { return nil }
func (e *secretPowerEnvironment) WaitForStop(context.Context, time.Duration, bool) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	e.state = environment.ProcessOfflineState
	return nil
}
func (e *secretPowerEnvironment) SetSecretRuntime(prepared *secrets.PreparedStart) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	if e.prepared != nil {
		e.prepared.Close()
	}
	e.prepared = prepared
	return nil
}
func (e *secretPowerEnvironment) SecretRuntimeConfiguration(context.Context) (secrets.DataMapping, secrets.GameIdentity, error) {
	return secrets.DataMapping{HostRoot: e.root, ContainerRoot: "/home/container"}, secrets.GameIdentity{ContainerUID: 1000, ContainerGID: 1000, HostUID: 1000, HostGID: 1000}, nil
}
func (e *secretPowerEnvironment) Start(context.Context) error {
	e.mu.Lock()
	defer e.mu.Unlock()
	e.starts++
	if e.failStart {
		return errors.New("start_failed")
	}
	if e.prepared == nil {
		return errors.New("start_failed")
	}
	e.snapshots = append(e.snapshots, e.prepared.Environment())
	e.state = environment.ProcessRunningState
	return nil
}
func secretPowerFixture(t *testing.T) (*Server, *secretPowerRemote, *secretPowerEnvironment) {
	t.Helper()
	config.Set(&config.Configuration{AuthenticationToken: "test-token"})
	dir := t.TempDir()
	bytes, err := os.ReadFile(filepath.Join(os.Getenv("BPS_PROTOCOL_FIXTURES"), "protocol.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fixtures struct{ Plan json.RawMessage }
	if err := json.Unmarshal(bytes, &fixtures); err != nil {
		t.Fatal(err)
	}
	plan, err := secrets.DecodePlan(fixtures.Plan)
	if err != nil {
		t.Fatal(err)
	}
	plan.Files[0].Destination = "/home/container/csgo/config.json"
	client := &secretPowerRemote{plan: plan, value: "fixture-secret"}
	server, err := New(client)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(server.CtxCancel)
	if err := os.MkdirAll(filepath.Join(dir, "csgo"), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "csgo/config.json"), []byte("{\"key\":{{secret:KEY}}}"), 0644); err != nil {
		t.Fatal(err)
	}
	server.fs, err = filesystem.New(dir, 0, nil)
	if err != nil {
		t.Fatal(err)
	}
	server.cfg.Uuid = plan.ServerUUID
	server.cfg.Secrets = &plan
	env := &secretPowerEnvironment{configuration: environment.NewConfiguration(environment.Settings{}, nil), root: dir, state: environment.ProcessOfflineState}
	server.Environment = env
	t.Cleanup(func() { env.SetSecretRuntime(nil) })
	return server, client, env
}

func TestStartAndRestartAlwaysPrepare(t *testing.T) {
	s, client, env := secretPowerFixture(t)
	if err := s.HandlePowerAction(PowerActionStart); err != nil {
		t.Fatal(err)
	}
	client.value = "second-value"
	client.plan.Revision = "9007199254740994"
	client.plan.Versions[0].Version = 2
	if env.starts != 1 {
		t.Fatal("save restarted game")
	}
	if err := s.HandlePowerAction(PowerActionRestart); err != nil {
		t.Fatal(err)
	}
	if client.requests != 2 || env.starts != 2 {
		t.Fatal("start/restart failed to fetch fresh bundles")
	}
	if env.snapshots[0]["RCON_PASSWORD"] != "fixture-secret" || env.snapshots[1]["RCON_PASSWORD"] != "second-value" {
		t.Fatal("stale startup value")
	}
	if !strings.Contains(env.snapshots[1]["STARTUP"], "second-value") {
		t.Fatal("native invocation did not use ephemeral bound variable")
	}
	if len(client.reports) != 2 || client.reports[1].Kind != "applied" {
		t.Fatal("missing successful reports")
	}
	raw, _ := json.Marshal(s.Config())
	if strings.Contains(string(raw), "fixture-secret") || strings.Contains(string(raw), "second-value") {
		t.Fatal("configuration cached plaintext")
	}
}

func TestFailureCannotStartWithStaleSnapshot(t *testing.T) {
	s, client, env := secretPowerFixture(t)
	if err := s.HandlePowerAction(PowerActionStart); err != nil {
		t.Fatal(err)
	}
	client.failure = true
	if err := s.HandlePowerAction(PowerActionRestart); err == nil {
		t.Fatal("bundle failure accepted")
	}
	if env.starts != 1 || env.prepared != nil {
		t.Fatal("failed fetch retained stale start material")
	}
	if len(client.reports) != 2 || client.reports[1].Kind != "failed" {
		t.Fatal("failure report missing")
	}
	env.failStart = true
	client.failure = false
	if err := s.HandlePowerAction(PowerActionStart); err == nil {
		t.Fatal("Docker failure accepted")
	}
	if env.prepared != nil || client.reports[len(client.reports)-1].Kind != "failed" {
		t.Fatal("failed Docker start marked applied or retained values")
	}
}

func TestConcurrentStartsSharePowerLock(t *testing.T) {
	s, client, env := secretPowerFixture(t)
	client.entered = make(chan struct{}, 1)
	client.release = make(chan struct{})
	result := make(chan error, 1)
	go func() { result <- s.HandlePowerAction(PowerActionStart) }()
	select {
	case <-client.entered:
	case <-time.After(5 * time.Second):
		t.Fatal("first start did not reach bundle fetch")
	}
	if err := s.HandlePowerAction(PowerActionStart); err == nil {
		t.Fatal("concurrent start ignored power lock")
	}
	close(client.release)
	if err := <-result; err != nil {
		t.Fatal(err)
	}
	if env.starts != 1 || client.requests != 1 {
		t.Fatal("concurrent protected starts overlapped")
	}
}

func TestSecretUnsupportedEnvironmentCannotPrepare(t *testing.T) {
	s, _, _ := secretPowerFixture(t)
	s.Environment = &unsupportedSecretEnvironment{configuration: environment.NewConfiguration(environment.Settings{}, nil)}
	if prepared, err := s.prepareSecretStart(context.Background()); err == nil {
		prepared.Close()
		t.Fatal("unsupported environment accepted")
	}
}

type unsupportedSecretEnvironment struct {
	environment.ProcessEnvironment
	configuration *environment.Configuration
}

func (e *unsupportedSecretEnvironment) Config() *environment.Configuration { return e.configuration }

func TestSecretConfigurationCannotDropRequiredPlan(t *testing.T) {
	s, _, _ := secretPowerFixture(t)
	err := s.SyncWithConfiguration(remote.ServerConfigurationResponse{Settings: json.RawMessage("{\"uuid\":\"11111111-1111-4111-8111-111111111111\"}"), ProcessConfiguration: &remote.ProcessConfiguration{}})
	if err == nil {
		t.Fatal("protected server accepted missing reference plan")
	}
}
