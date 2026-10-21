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

## Complete release gates

Run tools/dev check, tools/dev candidate, tools/dev acceptance, then tools/dev package sequentially. check runs plugin PHP tests, Pint, the pinned native Panel suite in an isolated Linux tmpfs copy with file-backed SQLite, Go vet/tests/race checks and project Go formatting. Candidate reconstruction starts from a fresh immutable Wings Git archive, applies the ordered patches and production overlay, and builds Linux amd64 with trimpath/buildvcs=false.

The acceptance runner records actual named passes, failures and skips under ignored .work/acceptance-results.json and maps all ten design cases to executed native tests. A focused -k run never creates full qualification evidence. Release verification checks the candidate ZIP, provenance and current-source matrix. package requires matching successful check/acceptance reports, reconstructs artifacts again, compares their hashes with the qualified candidate and records evidence digests in provenance.

The real native stack uses an isolated native Panel copy and database, actual ZIP import/install/reinstall, real patched and stock Wings processes, a private test CA, verified HTTPS in both directions, non-root Docker games, SSH/SFTP with its pinned generated host public key, local backups and a second node. Shared-engine fixtures wait for source container deletion before reusing a UUID on the destination. No fake node transport is installed into that stack.

All live resources use fresh names and the project label; cleanup removes only those identities. Missing infrastructure and skipped live cases fail qualification. The qualifying local VM reports Linux 6.18.33.2-microsoft-standard-WSL2, x86_64 and Docker 29.9.1. The supported minimum openat2 API is not a claim that every kernel/configuration has been tested.

Native Docker Desktop fixture allocations use 127.0.0.2: Wings rewrites literal 127.0.0.1 to its bridge interface, and Desktop rejected that custom bridge IP for published ports. The explicit loopback alias was characterized with the pinned fixture image. This changes fixture allocation metadata, not the production adapter.

The pinned upstream tests have three methods whose data-provider arity PHPUnit 12 rejects. tools/upstream.py copies those test files into the disposable runner and adds only unused parameters to their signatures, preserving every assertion and running the otherwise rejected data sets. It does not alter the pinned checkout or shipped Panel code, suppress warnings or skip executable cases. In-memory SQLite is unsuitable for this suite because its reconnect/truncation lifecycle loses tables.

The upstream PluginFixtureTest::test_index is an intentionally skipped empty placeholder (it contains no request or assertion). JUnit verification accepts only that exact preexisting placeholder and records it separately; any other skip, warning, risky/incomplete case or failure blocks checks. Live acceptance permits no skips. The upstream fixture APP_URL retains its expected trailing slash rather than rewriting snapshots.

Go vet compares the complete patched tree against a fresh immutable archive of the pinned Wings commit. Every baseline diagnostic remains visible and is recorded in check evidence; new diagnostics or unrecognized output fail. This keeps existing upstream copy-lock/unreachable findings visible without disabling analyzers for project code.

Routine CI runs the plugin PHP/Pint, Go vet/tests/race/formatting, and complete native Panel suites in three independent runners. It caches the pinned PHP image layers and dependency-only Docker volumes, cancels superseded branch/PR runs, and avoids duplicate branch/PR and documentation-only runs. `tools/dev check --suite php|go|upstream` selects one suite without producing release qualification evidence. Go images are explicitly available before capturing vet output, keeping Docker download progress outside the analyzer parser.

Version tags and **Run workflow** execute the complete sequential release gate, including native installation, every live acceptance case and reproducible package verification. Routine checks do not qualify a release. Dependency caches contain vendor, node_modules and Go download/build caches only; databases, secrets, source checkouts, acceptance reports and release artifacts are never cached.

Release source portability is checked independently of a developer checkout: release tests apply the source ZIP's patches to a pristine pinned tree and Git index, then run the ZIP's actual node/build.sh in pinned Linux tooling and compare the resulting binary byte for byte with the candidate. Native archives and patch application explicitly disable core.autocrlf. The original upstream router backup test uses CRLF, so its first change is represented as a Git binary delta that survives repository/ZIP LF normalization.
