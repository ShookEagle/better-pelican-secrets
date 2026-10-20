# Development environment

Implementation follows [the approved plan](superpowers/plans/2026-10-20-better-pelican-secrets.md). The initial feasibility gate exercises the native plugin installer/loader and Linux Docker mounts. Later tasks qualify the complete Panel/Wings integration.

## Pinned inputs

`upstream.lock.json` records exact Panel and Wings rc.1 commits. `tools/dev materialize` verifies checkout identities under ignored `.work/panel` and `.work/wings`. It stamps Panel's release version using the pinned upstream `.github/actions/stamp-version/action.yml` semantics: a raw release-tag checkout still identifies itself as canary. This is a disposable build step, not a distributed Panel patch.

`dev/images.lock.json` contains immutable official image digests. PHP 8.4, Composer, and Node 22/Yarn support the native installer. Dependencies are installed from upstream's Composer lock. Worktree-specific Docker volumes cache vendor/node_modules to avoid Windows shared-filesystem extraction overhead. Download archives remain under ignored `.work/composer-cache`.

## Run the harness

Use Git Bash on Windows or Bash on Linux, Python 3, Git, and a Linux Docker engine. Docker Desktop with WSL2 has been verified on this PC. Set `BPS_PYTHON` and `BPS_DOCKER` to executable paths if they are absent from PATH; Windows Store Python aliases are insufficient.

Run from the worktree root:

```sh
./tools/dev prepare
./tools/dev install-fixture
./tools/dev php tests/php/Compatibility/IntegrationSeamsTest.php
./tools/dev acceptance
```

`install-fixture` migrates only the disposable SQLite database and runs Pelican's native plugin installation, including its asset build. Installation metadata is retained only in the ignored installed copy. The distributable manifest has no installation-state `meta`.

`./tools/dev task1-check` runs the four PHP compatibility checks and five acceptance checks together. `./tools/dev pint --test` checks PHP style. The portable equivalent of the wrapper is `python tools/dev.py <command>`.

## What the initial gate proves

The real-application probe runs outside APP_ENV=testing, asserts the exact release version, and finds the enabled plugin/provider on both admin and server panels. Separate PHP tests prove provider registration and native service replacement for lifecycle vetoes and server configuration decoration.

Live Docker checks use synthetic values and random, labelled resources, removing only their own containers and volumes. They verify a non-root game UID/GID, /game mapping, read-only runtime bind overlays, unchanged source-template bytes and archive inputs, and a recreated container reading a changed runtime value. A keeper container holds the private tmpfs throughout each experiment.

These mount checks characterize the underlying source-volume contract; later tasks test Wings SFTP/backup behavior and the full start lifecycle. Missing Docker or another prerequisite is an error, never a skipped successful check.

The ignored execution ledger is under `.superpowers/sdd/2026-10-20-better-pelican-secrets/`. Resume from its first unfinished task.
