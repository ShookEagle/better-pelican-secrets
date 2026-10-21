# Better Pelican Secrets

Native Pelican Panel secrets management with matching patched Wings. Store server-owned encrypted secrets, grant operations explicitly, and use a secret in an existing startup variable or generic JSON, YAML, dotenv and text files.

Ordinary files keep markers such as {{secret:PLUGIN_API_KEY|json-string}}. On normal start, Wings fetches an authenticated snapshot, renders private tmpfs files and mounts them read-only. Saves remain pending until the next start. Normal editing, SFTP, downloads and backups use templates.

Only the exact Panel/Wings v1.0.0-rc.1 commits in [upstream.lock.json](upstream.lock.json) are targeted. Use only artifacts whose provenance records completed qualification; candidate builds do not establish a release.

## Install v0.1.1

In Panel, open **Admin → Plugins → Import from URL**, paste the following link, then install and enable the imported plugin:

```text
https://github.com/ShookEagle/better-pelican-secrets/releases/download/v0.1.1/better-pelican-secrets-0.1.1.zip
```

Then run the node installer once on each existing Ubuntu/Debian amd64 systemd node:

```sh
curl -fsSL https://github.com/ShookEagle/better-pelican-secrets/releases/download/node-installer-v0.1.0/install-node.sh | sudo bash
```

It installs the **matching patched Wings**, configures private tmpfs and its service dependency, and restarts Wings using the node's existing configuration. Wings automatically loads the servers assigned to that node in Pelican. Bindings and access grants remain explicit. See the [installation guide](docs/installation.md#one-command-node-setup) for dry runs, prerequisites, capacity and recovery.

- [Installation](docs/installation.md)
- [Operations and security limits](docs/operations.md)
- [Compatibility](docs/compatibility.md)
- [Development](docs/development.md)
- [Protocol](docs/protocol-v1.md)

Executable game code can deliberately read runtime secrets. Host, Docker, Wings, Panel and game operators are trusted. Reveal permissions protect management access; they do not isolate a credential from the process using it.
