# Installation

Roll out on a non-production node/server first. Install both the native Panel plugin and matching patched Wings; stock nodes cannot activate bindings.

## Requirements

Target exact Panel/Wings v1.0.0-rc.1 commits in upstream.lock.json. Development uses PHP 8.4.26, Go 1.26.0, Node 22 and pinned Composer dependencies. Only Linux amd64 is built and exercised. Require Linux 5.6+ with working openat2, rootful local Unix Docker and identity UID/GID mapping. Remote/rootless Docker and guessed remaps fail closed.

Provision a root-owned 0700 tmpfs outside server volumes. Rendered files use the game UID/GID, mode 0400 and read-only binds. Size it for all games; one plan can render up to 64 MiB before trusted lower limits. Example host configuration:

```fstab
tmpfs /run/better-pelican-secrets tmpfs rw,noexec,nosuid,nodev,mode=0700,size=256m 0 0
```

Create/verify the mountpoint as root and make wings.service depend on it with RequiresMountsFor=/run/better-pelican-secrets. The plugin never mounts host filesystems.

Set system.secrets_runtime_directory in Wings YAML for a different private tmpfs; Panel JSON cannot change it. Wings and Docker must see the same host path. Containerized Wings must preserve that path; a whole-root bind conservatively retains all attempts while its container owns the mount.

## Install the pair

Back up Panel data and APP_KEY together. Preserve previous keys; lost keys mean lost values. Verify SHA256SUMS and provenance.json from a qualified release. Replace the node binary with wings-bps-0.1.0-linux-amd64 through normal host maintenance, retain the old binary, restart Wings and check its version/tmpfs.

Import better-pelican-secrets-0.1.0.zip through the native plugin importer and install/enable it. Folder/manifest IDs match and no install-state meta is shipped. Native installation runs migrations and builds assets.

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
