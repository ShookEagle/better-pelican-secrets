# Installation

Roll out on a non-production node/server first. Install both the native Panel plugin and matching patched Wings; stock nodes cannot activate bindings.

## Import the plugin by URL

1. Open **Admin → Plugins** in your Panel.
2. Choose **Import from URL** and paste this versioned ZIP link:

   ```text
   https://github.com/ShookEagle/better-pelican-secrets/releases/download/v0.1.1/better-pelican-secrets-0.1.1.zip
   ```

3. Import it, then choose **Install** and enable **Better Pelican Secrets**. Native installation runs migrations and builds assets.
4. Complete the node setup below before creating bindings. Importing the plugin does not replace Wings or provision the node's tmpfs.

If your Panel host cannot download from GitHub, [download the ZIP](https://github.com/ShookEagle/better-pelican-secrets/releases/download/v0.1.1/better-pelican-secrets-0.1.1.zip) in your browser and use **Import from file** instead.

Download the [matching Linux amd64 Wings binary](https://github.com/ShookEagle/better-pelican-secrets/releases/download/v0.1.1/wings-bps-0.1.1-linux-amd64), [SHA256SUMS](https://github.com/ShookEagle/better-pelican-secrets/releases/download/v0.1.1/SHA256SUMS), and [provenance.json](https://github.com/ShookEagle/better-pelican-secrets/releases/download/v0.1.1/provenance.json) from the same release. The [release page](https://github.com/ShookEagle/better-pelican-secrets/releases/tag/v0.1.1) also includes the source patches.

## One-command node setup

After installing and enabling the Panel plugin, run this once on each existing Ubuntu 22.04+/Debian 12+ **amd64 systemd node**:

```sh
curl -fsSL https://github.com/ShookEagle/better-pelican-secrets/releases/download/node-installer-v0.1.0/install-node.sh | sudo bash
```

This installer is versioned separately and installs the qualified Wings **v0.1.1** above. It verifies pinned SHA-256 hashes for both the binary and qualified provenance, reuses your existing service/configuration/node credentials, checks the Panel inventory over verified HTTPS, provisions a root-owned 0700 tmpfs, and adds the Wings service's mount dependency. It restarts Wings and verifies the running process. It does not reboot the host or send game-server restart commands; normal Wings recovery still applies.

Wings automatically loads every server already assigned to this node in Pelican. New servers follow normal Pelican provisioning. The installer does not scan arbitrary directories, import plaintext keys, create bindings or grant secret access. Configure those explicitly on the server's Secrets page.

Inspect the script or preview the operation without installing packages or changing node files, mounts or services:

```sh
curl -fsSL https://github.com/ShookEagle/better-pelican-secrets/releases/download/node-installer-v0.1.0/install-node.sh | sudo bash -s -- --dry-run
```

Dry runs require Python 3.10+ and `python3-yaml` already installed. A normal run installs those prerequisites through apt if needed. Repeating a completed installation leaves a healthy Wings process and existing private tmpfs in place.

The new tmpfs defaults to a **256 MiB maximum**, shared by the node's servers. Set its initial capacity with `--tmpfs-size 1G`; use `--service custom.service` for a different existing service, or `--panel-ca-file /path/to/ca.pem` for your existing private Panel CA. The configuration path is discovered from the service's `--config` argument. Existing live tmpfs capacity is preserved; an explicit size mismatch is rejected instead of remounting it.

Backups of the previous binary and configuration are root-private under `/var/backups/better-pelican-secrets/`. If replacement fails, the installer restores those files. After a restart attempt it leaves Wings stopped for operator review; automatically starting an older daemon could bypass newly configured secret bindings. Private tmpfs and its service dependency remain in place, preserving runtime files held by running containers. The installer never unmounts or deletes that storage.

Containerized/rootless/remapped deployments, unsupported Wings versions, disabled integration, unsafe existing mounts and conflicting operator-owned unit/drop-in files are rejected before replacement. Services using `EnvironmentFile` or private mount namespaces need the manual setup below. On an empty node, plugin availability cannot be inferred from server metadata; keep the Panel plugin enabled before provisioning its first server.

## Requirements

Target exact Panel/Wings v1.0.0-rc.1 commits in upstream.lock.json. Development uses PHP 8.4.26, Go 1.26.0, Node 22 and pinned Composer dependencies. Only Linux amd64 is built and exercised. Require Linux 5.6+ with working openat2, rootful local Unix Docker and identity UID/GID mapping. Remote/rootless Docker and guessed remaps fail closed.

Provision a root-owned 0700 tmpfs outside server volumes. Rendered files use the game UID/GID, mode 0400 and read-only binds. Size it for all games; one plan can render up to 64 MiB before trusted lower limits. Example host configuration:

```fstab
tmpfs /run/better-pelican-secrets tmpfs rw,noexec,nosuid,nodev,mode=0700,size=256m 0 0
```

Create/verify the mountpoint as root and make wings.service depend on it with RequiresMountsFor=/run/better-pelican-secrets. The plugin never mounts host filesystems.

Set system.secrets_runtime_directory in Wings YAML for a different private tmpfs; Panel JSON cannot change it. Wings and Docker must see the same host path. Containerized Wings must preserve that path; a whole-root bind conservatively retains all attempts while its container owns the mount.

## Install the pair

Back up Panel data and APP_KEY together. Preserve previous keys; lost keys mean lost values. Verify SHA256SUMS and provenance.json from the same qualified release.

Import, install and enable the Panel plugin first, using the URL above or the ZIP file. Folder/manifest IDs match and no install-state meta is shipped.

After preparing tmpfs and the service dependency, replace the node binary with wings-bps-0.1.1-linux-amd64 through normal host maintenance. Retain the old binary, restart Wings and check its version/tmpfs. Use the binary and plugin from the same release.

Use verified HTTPS and trusted certificate chains in both directions. Do not use ignore-certificate-errors. Capability checks reject incompatible nodes; preparation rechecks runtime/identity constraints.

Create a secret on the server Secrets page, grant operations explicitly, and bind an existing egg variable or generic file template. Confirm format, mode, references and read-only behavior. Previews use dummy values; normal start applies the snapshot.

For /game destinations, configure a native mount of the same server data root to /game. Sources remain relative to the ordinary root; unrelated mounts cannot overlap destinations.

## Build from source

The node-source ZIP contains pins, ordered patches, production overlay and node/build.sh. On Linux amd64 with Go 1.26.0, Python 3 and Git:

```sh
./node/build.sh /absolute/new-checkout /absolute/wings-bps
```

The checkout must not exist. Builds never modify a running installation.

Patched Wings defaults system.secrets_integration_required to true. Install/enable the plugin before loading server configurations. A missing plugin on daemon boot blocks loading/start rather than enabling a fallback. After restoring a plugin that was missing at boot, restart Wings to reload those server configurations. See operations for deliberate retirement and host opt-out.
