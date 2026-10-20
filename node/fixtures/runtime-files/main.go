// Linux integration probe: exercises the production preparer and keeps its
// tmpfs attempt alive while an independent Docker game container reads it.
package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"

	"github.com/pelican/wings/secrets"
)

func main() {
	fixtures := flag.String("fixtures", "/fixtures/protocol.json", "synthetic protocol corpus")
	data := flag.String("data", "/game", "trusted server data root")
	base := flag.String("runtime", "/run/better-pelican-secrets", "private tmpfs")
	flag.Parse()
	if err := run(*fixtures, *data, *base); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
func run(fixtures, data, base string) error {
	bytes, err := os.ReadFile(fixtures)
	if err != nil {
		return err
	}
	var corpus struct {
		Plan   json.RawMessage
		Bundle json.RawMessage
	}
	if err := json.Unmarshal(bytes, &corpus); err != nil {
		return err
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
	ctx, cancel := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer cancel()
	preparer := secrets.Preparer{RuntimeBase: base}
	prepared, err := preparer.Prepare(ctx, plan, bundle, root, secrets.DataMapping{HostRoot: data, ContainerRoot: "/game"}, secrets.GameIdentity{ContainerUID: 1000, ContainerGID: 1000, HostUID: 1000, HostGID: 1000})
	if err != nil {
		return err
	}
	defer prepared.Close()
	if err := json.NewEncoder(os.Stdout).Encode(struct {
		Mounts []secrets.RuntimeMount
		Report secrets.AppliedReport
	}{prepared.Mounts(), prepared.Report()}); err != nil {
		return err
	}
	<-ctx.Done()
	return nil
}
