# Development environment

Implementation follows [the approved plan](superpowers/plans/2026-10-20-better-pelican-secrets.md). Task 1 is in progress; the plugin and runtime integration have not passed their feasibility gate.

## Pinned inputs

`upstream.lock.json` records the exact Panel and Wings rc.1 source commits. Ignored checkouts are materialized under `.work/panel` and `.work/wings`; they are development inputs, not release source.

`dev/images.lock.json` records immutable Docker Hub image digests, resolved from the official registry. The mount-contract suite uses only the pinned BusyBox fixture image.

## Runtime prerequisites

Use a Linux Docker engine. On this Windows PC, WSL 2.7.13 has been installed and Virtual Machine Platform enabled. Windows requires a reboot before that feature becomes active. Docker Desktop 4.96.0 is installed in per-user mode with its WSL2 backend; its CLI and Compose version commands pass. The Linux engine still needs verification after reboot.

Installation status is not evidence that the engine works. After reboot, verify `docker info` reports Linux and run the live tests before proceeding to later plan tasks. No test may silently skip because Docker is unavailable.

## Initial mount-contract checks

Run from the worktree root:

```sh
python3 -m unittest discover -s tests/acceptance -p test_mount_contract.py -v
```

On Windows, use a real Python interpreter; the Microsoft Store command aliases may not be installed. Set `BPS_DOCKER` to the full Docker CLI path if this terminal's PATH predates installation.

The suite uses synthetic values. It creates randomly named, labelled volumes and containers and removes only those resources. It checks source-template preservation, archive inputs, read-only runtime overlays, a non-root game UID/GID, the /game mapping, and recreation with a new runtime value. It is a low-level Docker characterization; real Panel loading, node integration, and SFTP/backup qualification remain required by Task 1 and later tasks.

## Resume point

The task ledger and Task 1 brief live in this plan's ignored `.superpowers/sdd/2026-10-20-better-pelican-secrets/` workspace. Resume Task 1 from that ledger after Windows restarts. Do not mark Task 1 complete or start Task 2 until its live mount and real plugin-loading checks pass.
