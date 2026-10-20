//go:build linux

package docker

import (
	"context"
	"errors"
	"strings"

	"github.com/docker/docker/api/types/container"
	"github.com/docker/docker/api/types/mount"
	"github.com/docker/docker/client"
	"github.com/pelican/wings/secrets"
)

// ReconcileSecretRuntime treats Docker-inspected mount sources as authoritative.
// Inspection failure aborts the entire pass; a partial inventory cannot authorize deletion.
func ReconcileSecretRuntime(ctx context.Context, root string) error {
	cli, err := client.NewClientWithOpts(client.FromEnv, client.WithAPIVersionNegotiation())
	if err != nil {
		return errors.New("node_incompatible")
	}
	defer cli.Close()
	if !strings.HasPrefix(cli.DaemonHost(), "unix://") {
		return errors.New("node_incompatible")
	}
	all, err := cli.ContainerList(ctx, container.ListOptions{All: true})
	if err != nil {
		return errors.New("start_failed")
	}
	identities := make([]secrets.ContainerIdentity, 0, len(all))
	for _, listed := range all {
		inspected, err := cli.ContainerInspect(ctx, listed.ID)
		if client.IsErrNotFound(err) {
			continue
		}
		if err != nil || inspected.State == nil || inspected.Config == nil {
			return errors.New("start_failed")
		}
		if !inspected.State.Running && !inspected.State.Restarting && !inspected.State.Paused && inspected.State.Status != "created" {
			continue
		}
		identity := secrets.ContainerIdentity{ContainerID: inspected.ID, ServerUUID: inspected.Config.Labels["io.better-pelican-secrets.server"], AttemptID: inspected.Config.Labels["io.better-pelican-secrets.attempt"]}
		for _, m := range inspected.Mounts {
			if m.Type == mount.TypeBind || m.Type == mount.TypeVolume { // Docker Desktop can retain a foreign Windows host spelling for
				// unrelated workspace mounts. It cannot name this Linux private tmpfs.
				if len(m.Source) >= 3 && ((m.Source[0] >= 'A' && m.Source[0] <= 'Z') || (m.Source[0] >= 'a' && m.Source[0] <= 'z')) && m.Source[1] == ':' && (m.Source[2] == '/' || m.Source[2] == 92) {
					continue
				}
				identity.MountSources = append(identity.MountSources, m.Source)
			}
		}
		identities = append(identities, identity)
	}
	return secrets.Reconcile(ctx, root, identities)
}
