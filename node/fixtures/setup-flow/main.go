// Disposable qualification of Panel-generated plans against the real preparer
// and a separate non-root Docker game. Runtime IPC is read only from stdin.
package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"os"
	"time"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/docker/client"
	"github.com/pelican/wings/secrets"
)

func main() {
	data := flag.String("data", "", "trusted fixture data")
	runtime := flag.String("runtime", "", "trusted private tmpfs")
	image := flag.String("image", "", "digest-pinned game fixture")
	flag.Parse()
	if err := run(*data, *runtime, *image); err != nil {
		fmt.Fprintln(os.Stderr, "setup qualification failed:", err)
		os.Exit(1)
	}
}

func run(data, runtime, image string) error {
	ctx, cancel := context.WithTimeout(context.Background(), 70*time.Second)
	defer cancel()
	var corpus struct {
		Plan   json.RawMessage
		Bundle json.RawMessage
	}
	if err := json.NewDecoder(io.LimitReader(os.Stdin, 16777217)).Decode(&corpus); err != nil {
		return fmt.Errorf("invalid fixture IPC")
	}
	plan, err := secrets.DecodePlan(corpus.Plan)
	if err != nil {
		return err
	}
	bundle, err := secrets.DecodeBundle(corpus.Bundle)
	if err != nil {
		return err
	}
	root, err := os.OpenRoot(data)
	if err != nil {
		return err
	}
	defer root.Close()
	prepared, err := (&secrets.Preparer{RuntimeBase: runtime}).Prepare(ctx, plan, bundle, root,
		secrets.DataMapping{HostRoot: data, ContainerRoot: "/game"},
		secrets.GameIdentity{ContainerUID: 1000, ContainerGID: 1000, HostUID: 1000, HostGID: 1000})
	if err != nil {
		return err
	}
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		prepared.Close()
		return err
	}
	defer cli.Close()
	id := ""
	defer func() {
		if id == "" {
			prepared.Close()
			return
		}
		clean, stop := context.WithTimeout(context.Background(), 10*time.Second)
		defer stop()
		if err := cli.ContainerRemove(clean, id, container.RemoveOptions{Force: true}); err == nil || client.IsErrNotFound(err) {
			prepared.Close()
		}
	}()
	mounts := []mount.Mount{{Type: mount.TypeBind, Source: data, Target: "/game"}}
	for _, m := range prepared.Mounts() {
		if !m.ReadOnly {
			return fmt.Errorf("writable overlay")
		}
		mounts = append(mounts, mount.Mount{Type: mount.TypeBind, Source: m.Source, Target: m.Destination, ReadOnly: true})
	}
	env := []string{}
	for k, v := range prepared.Environment() {
		env = append(env, k+"="+v)
	}
	// The expected literal is synthetic, compiled fixture data. No actual value is logged.
	command := `set -eu
test "$BPS_TOKEN" = 'fixture-secret'
test "$(cat /game/config.json)" = '{"key":"fixture-secret"}'
test "$(cat /game/config.yaml)" = 'key: "fixture-secret"'
test "$(cat /game/.env)" = 'KEY="fixture-secret"'
test "$(cat /game/config.txt)" = 'key=fixture-secret'
! echo changed > /game/config.json
`
	created, err := cli.ContainerCreate(ctx, &container.Config{Image: image, User: "1000:1000", Env: env,
		Cmd: []string{"sh", "-c", command}, Labels: map[string]string{"better-pelican-secrets.dev": "true"}},
		&container.HostConfig{Mounts: mounts, AutoRemove: true, LogConfig: container.LogConfig{Type: "none"}}, nil, nil, "")
	if err != nil {
		return err
	}
	id = created.ID
	if err := cli.ContainerStart(ctx, id, container.StartOptions{}); err != nil {
		return err
	}
	done, failed := cli.ContainerWait(ctx, id, container.WaitConditionNotRunning)
	select {
	case status := <-done:
		if status.StatusCode != 0 {
			return fmt.Errorf("game rejected setup (%d)", status.StatusCode)
		}
	case err := <-failed:
		return err
	case <-ctx.Done():
		return ctx.Err()
	}
	return json.NewEncoder(os.Stdout).Encode(struct {
		Files   int  `json:"files"`
		Startup bool `json:"startup"`
		Game    bool `json:"game"`
	}{len(prepared.Mounts()), true, true})
}
