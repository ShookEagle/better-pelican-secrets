# Node protocol v1

Panel and patched Wings target the exact rc.1 commits in upstream.lock.json. Both decoders consume the shared protocol fixtures; neither accepts a higher protocol or unknown wire fields. Every nested object's field names are case-sensitive. The Go decoder also rejects duplicate keys, invalid UTF-8, unpaired UTF-16 escapes, and null-to-empty value coercion.

## Plans and bundles

A plan contains protocol, server_uuid, revision, versions, files, startup, and limits. Revisions are canonical decimal strings from "0" through "18446744073709551615"; no layer converts them through a float or JavaScript number. Immutable version numbers are positive integers through 2147483647.

Each versions entry is {secret_id, version}. The referenced IDs must match the union of file manifests and startup assignments exactly. A file has a UUID file_id, relative source, absolute in-container destination, output format, default mode, and manifest entries {secret_id, name, mode, count}. Startup assignments are {env, secret_id}. Duplicate file identities, sources, destinations, startup names, version identities, and manifest ID/mode pairs are invalid. Files with no placeholders have an empty manifest.

Names use [A-Z][A-Z0-9_]{0,63}. Output formats are json, yaml, dotenv, and text. Modes are json-string, yaml-string, dotenv, and raw. Protocol path validation rejects empty segments, traversal, control characters, and backslashes; Linux filesystem/mount validation imposes the stronger destination/root/symlink checks during preparation.

A runtime bundle adds attempt_id and a values object keyed by exactly the required secret IDs. Each value is a valid UTF-8 string without NUL, including an explicitly empty string. Missing, extra, null, and non-string values fail. Normal bundle logging, debug formatting, serialization, and JSON encoding omit values. PHP RuntimeBundle::toWire() is an explicit internal export reserved for the authenticated node controller response; it must never be logged, queued, or cached. Go values remain private to the secrets package.

## Reports and status

Reports carry protocol, server_uuid, kind, attempt_id, revision, source_digests, versions, and error_code. kind is applied, failed, or source-dirty. Digests map file UUIDs to lowercase SHA-256 strings. A failed report requires a documented error code; other reports require null. Arbitrary error messages and rendered text are rejected. A source-dirty report cannot advance the applied revision. It additionally requires observation_sequence, a canonical positive uint64 decimal string; applied and failed reports forbid this field. The counter increases for every fresh observation within that start attempt. Retries retain the original counter; older observations cannot replace a newer head, and reusing a counter with different content is invalid.

Error codes are protocol_invalid, protocol_unsupported, limit_exceeded, secret_missing, template_invalid, manifest_mismatch, path_unsafe, node_incompatible, panel_unavailable, revision_conflict, mount_failed, start_failed, decryption_failed, internal_error, value_invalid, and dotenv_unsupported.

ApplicationStatus presents desiredRevision, appliedRevision, pending, dirty, lastAttemptId, nullable lastErrorCode, and observationStale. An applied observation refers only to its exact start snapshot.

## Bounds

| Budget | Maximum |
| --- | ---: |
| UTF-8 bytes per secret | 65,536 |
| Source bytes per template | 4 MiB |
| References per file | 1,000 |
| Managed files per server | 256 |
| Serialized bundle bytes | 16 MiB |
| Aggregate rendered output per start | 64 MiB |

Limits may be lowered by trusted configuration, never raised past protocol maxima. Bundle HTTP readers must cap the raw transport before JSON allocation; PHP decoding receives already-parsed arrays, so its controller must perform this body-size gate too. The Go decoder enforces the raw bound directly. Renderers account for expanded output separately, including repeated values. Startup assignments are capped at 4,096; unique version references at 256,000.

Schemas under protocol/v1 describe field structure and bounds. Both decoders additionally enforce byte budgets, identity uniqueness, canonical integers, and complete reference coverage, which JSON Schema cannot fully express. Object maps (values and source_digests) serialize as objects even when empty. All checked-in examples use synthetic fixture values.

## Reproduce

Run ./tools/dev task4-check for both languages. The harness requires BPS_PROTOCOL_FIXTURES in tests, runs Go 1.26 from a digest-pinned image with GOTOOLCHAIN=local, and caches compilation/modules in worktree-specific Docker volumes. New source remains authoritative in node/overlay; materialization copies it to the pinned Wings module and applies ordered patches. ./tools/dev gofmt copies formatting changes back to overlay source.

The PHP and Go renderers consume the same contract corpus. Exact 65,536-byte limits, UTF-8 validation, transport size, per-file multiset coverage, and aggregate expansion are enforced in code as well as the structural schemas.

## Authenticated transport and activation

The Panel exposes POST /api/remote/servers/{uuid}/secrets/runtime-bundle and POST /api/remote/servers/{uuid}/secrets/application-report. Both require HTTPS and the native node bearer identity, and bind the route's server to that node under a database lock. Browser sessions cannot substitute for node authentication. Requests reject duplicate and unknown fields. Bundle requests carry protocol, expected_revision, and attempt_id; a changed desired revision returns 409. Responses include Cache-Control: no-store (the framework also adds private). Request bodies, returned values, and raw error bodies must never be recorded by reverse-proxy or request-inspection middleware.

Runtime delivery resolves only the immutable references in the current plan. Encoded metadata counts toward the bundle budget; each value's encoded size is checked before retaining it or reading the next value. Issued-attempt records contain only reference metadata. Applied reports must match a previously issued attempt, revision, version set, and complete file digest set. Repeating an identical report is idempotent; contradictory terminal reports return 409. Older applied observations cannot clear a newer desired revision.

Before activation, start/restart, node reassignment, transfer, or websocket start permission, the Panel checks the target node's authenticated HTTPS GET /api/system?secrets=1 response for protocol 1, the pinned Wings base commit, and Panel rc.1. Positive proofs are cached for 60 seconds against node ID, address, and token identity. Unprotected servers keep native behavior. Root users cannot bypass compatibility. The capability is a declaration by a trusted patched node; host operators remain trusted and must deploy the qualified patch set.

Wings uses a dedicated bounded transport for these two endpoints, verifies TLS certificates (including development), refuses redirects and insecure TLS settings, caps requests at 15 seconds, and emits only fixed protocol error codes. Private CA installations must configure a trusted CA in the node's system trust store. Generic remote error-body handling is deliberately bypassed.

## Trusted operator budgets and patch development

Publish the plugin configuration with php artisan vendor:publish --tag=better-pelican-secrets-config. BPS_BUNDLE_BYTES and BPS_RENDERED_BYTES may lower the 16 MiB transport and 64 MiB aggregate output ceilings; other ceilings can be lowered in config/better-pelican-secrets.php. Invalid or increased limits fail closed. Limits enter desired reference snapshots, so changes become pending for the next normal start. Bindings and previews use the same configured ceilings.

Existing upstream edits live in ordered node/patches/*.patch; new files live in node/overlay. After deliberately editing an existing file in .work/wings, run ./tools/dev refresh-patches with the latest numbered patch basename before materialization or testing. Materialization verifies the complete patch chain against a temporary index and refuses to overwrite unrecorded tracked edits. Run ./tools/dev gofmt, refresh the latest patch if tracked files changed, and run ./tools/dev task8-check for the endpoint, TLS, protocol, and integration-interface gate.


## Linux runtime preparation

The node must run as root on Linux with working openat2 (kernel 5.6 or newer); it fails with node_incompatible when the syscall or required flags are unavailable. Sources are opened relative to an owned os.Root descriptor with RESOLVE_BENEATH, NO_SYMLINKS, NO_MAGICLINKS, and NO_XDEV. Managed inputs and existing mapped targets must be regular files without executable permission bits. Reads are bounded and hashed; inode metadata changes during a read cause a retry. Digests and custom destinations are rechecked before allocating an attempt and immediately before handing mounts to the caller.

Exactly two preparation attempts are permitted for changing source content. Repeated changes fail with template_invalid. Snapshot buffers are processed one file at a time, and accumulated output is bounded by rendered_bytes. Failure after allocation removes that attempt; a duplicate attempt never overwrites or deletes an existing snapshot.

The default runtime base is /run/better-pelican-secrets. It must be a real tmpfs, outside the server data tree, with a root-owned 0700 directory and root-owned non-writable ancestors. The service/package must arrange this mount; persistent disk and permissive directories are rejected. UUID-only server/attempt/file names stay in this private area. Each rendered file is 0400, owned by the trusted effective host UID/GID corresponding to the game's container identity. Unsupported ownership/namespace mappings must fail; caller adapters must derive IDs from trusted Docker/node configuration. The preparer never broadens file modes to make a mapping work.

PreparedStart exposes copied mount metadata, an explicitly exported ephemeral environment for Docker creation, and a metadata-only report. Formatting and JSON of either its value or pointer exclude values. Report() describes the prepared snapshot; callers may publish an applied report only after Docker start succeeds. Close() releases environment references and removes only that attempt. The lifecycle layer must retain it while the container needs its bind sources and close it after safe teardown.

Run ./tools/dev task9-check to test preparation and the separate non-root Docker consumer. The live fixture mounts the production preparer's returned file read-only into /game/csgo/config.json, checks owner/modes and a different UID, and archives the original volume to verify it still contains the marker. It qualifies the normal UID mapping; full daemon user-namespace qualification belongs to the final lifecycle matrix.


## Start lifecycle and controlled output

Normal starts and restarts prepare inside the native power lock after configuration sync and native file preflight. The node fetches a new bundle each time, retries one revision conflict after refreshing references, and fails closed on preparation or Docker errors. Missing reference metadata cannot silently disable a previously required plan. Docker consumes each prepared attempt once and recreates the stopped container using ephemeral environment/mount copies. Ordinary environment settings and cached server JSON remain reference-only. Bound startup keys follow native uppercase environment naming; case-fold collisions are invalid. The native STARTUP invocation receives an ephemeral substitution using the same bound values.

The initial Docker adapter qualifies local Unix sockets and rootful identity-mapped namespaces, including explicit host user namespaces. Rootless daemons, remapped namespaces, remote Docker endpoints, and unresolved ownership fail with node_incompatible; it never guesses host IDs from writable source ownership. A trusted alias such as /game can be selected only from native data mounts pointing at the actual server root. Other mounts overlapping a managed destination are rejected.

Literal redaction runs on the raw attach stream before native line scanning, server logging, feature matching, websocket sinks, and history. It handles split writes, multiline literals, and leftmost-longest overlapping patterns; empty values are not patterns. It remains best effort for transformed or application-authored output. Protected containers disable Docker's raw persistent log driver; history is bounded to 1,000 sanitized lines and 1 MiB in memory, and resets with a new snapshot. Reattaching after daemon restart lacks its previous inventory and emits an explicit warning; it does not claim restored redaction until the next protected restart.

Metadata reports describe successful Docker delivery, not application acceptance. A failed attempt reports a fixed code and never publishes applied first; an unavailable Panel may also prevent report delivery, leaving status pending rather than inventing success. Runtime directories remain private while the active environment owns them, and replacement releases the stopped attempt. Final lifecycle cleanup covers deletion and node restart separately.

Run ./tools/dev task10-check for native power/locking, Docker creation, redaction, shared protocol, and a live Docker lifecycle test. The live test uses actual Wings HandlePowerAction and Docker Environment methods with a synthetic metadata/bundle service, a digest-based fixture image, a real game UID, and independently inspected/exec-read Docker containers. Full real Panel-to-Wings qualification follows in Task 15.


## Source editing and observation ordering

TemplateSourceService reads only the registered source path through the native daemon file repository, with ordinary file.read-content or file.update rights and server membership. It never resolves a secret or reads a rendered mount. Saves validate the fixed approved manifest and dummy-rendered structure, re-read the current source digest to reject stale editor sessions, and write only the source. The native repository does not offer an atomic compare-and-swap shared with arbitrary SFTP writes, so an external writer can still race the short read/write interval. The watcher and next-start digest checks reconcile external changes.

Each active managed-file snapshot owns a cancellable descriptor-rooted watcher. It polls every five seconds and captures that start's copied reference plan, rather than new cached bindings. It reports only digests, version identities, and validity. Missing, unsafe, or invalid sources omit their digest in a source-dirty report; an incomplete map marks invalid/pending without claiming a new applied revision. A pending binding change can also invalidate a template against the running snapshot's old manifest; the next start validates the current desired manifest.

Applied attempt ordering comes from bundle issuance, so a delayed old success cannot replace a newer successful snapshot. Source observations are ordered separately by their per-attempt counter, without timestamps or floating-point conversion. An A-to-B-to-A restoration is distinct from a stale replay of A. Stable observation content reuses an immutable content record; the mutable head stores the latest accepted counter and record reference, keeping unchanged polling history bounded. Panel-authorized source saves update the current source head without inventing a node counter; a subsequent fresh node observation reconciles it.

Polling is serialized and reasserts the metadata-only applied receipt, so a temporary report-delivery failure can recover. Watchers stop on normal stop/restart, kill, server cleanup, and natural exit. A fresh protected start always validates current source digests even when no watcher observation arrived. Restoring an old attempt's watcher after daemon restart requires restoring its observation counter or waiting for a new start. Panel marks managed-file observation stale and pending after 15 seconds without a fresh accepted node counter (or initial applied receipt). Receipt retries and Panel source saves cannot refresh this deadline. The separate node_observed_at receipt time controls freshness only; per-attempt counters still control ordering. The UI refreshes every five seconds and describes the current source state as unknown while retaining the last delivery revision.

Run ./tools/dev task11-check. The live native-path fixture uses the production preparer, a real read-only game bind, authenticated Wings file routes, the native SSH/SFTP credential callback and handler, signed downloads, native file-manager compression, and local backup generation. All ordinary paths read the marker. Inserting an unapproved token through SFTP leaves the running snapshot unchanged and makes the next preparation fail. Archive qualification uses the normal file-manager directory-and-basename request shape.


## Management and setup boundaries

Server and admin pages use the same explicitly authorized action layer as the client secret API. Ordinary lists, receipts, audit events, and Livewire snapshots contain metadata only. Reveal is a separate audited operation with a no-store response; its browser-only dialog clears on close, navigation, an interaction outside the reveal dialog, or after 30 seconds. Existing value inputs are never prefilled. A blank replacement preserves the version; Generate and Set empty value are distinct writes. Direct Livewire calls and API requests recheck raw permissions and scope.

A matching explicit global admin secret operation allows its cross-server admin action without server membership. Server pages and the client API retain normal server membership. File and startup setup additionally require their ordinary destination permissions; a global secret grant alone does not grant filesystem access. Access management shows direct, broader, native, and root rights, and checks every delegated operation.

File setup suggests a format and escaping mode but requires confirmation. The picker offers only secrets the actor can list and bind. Tokens inserted through it always record the selected explicit mode. JSON string insertion replaces the whole quoted value and rejects key selection. The browser converts UTF-16 selection positions to UTF-8 byte offsets. JSON/YAML/dotenv modes emit complete quoted scalar values, so their marker belongs outside additional quotes. Other text and deliberate mixed raw tokens remain available. Dummy previews never read a secret version.

A source may be an existing file or a new file under an existing directory. An absence receipt is distinct from the SHA-256 of an empty existing file. Setup validates references, paths, limits, permissions, and node capability before writing; it rechecks the live source receipt before the native file write, then commits its approved binding plan. A write or final plan commit can fail independently. A metadata-only setup intent commits before the remote write. Its unique attempt identity is verified while holding the server lock through the write and final plan transaction, so a competing attempt cannot clear its guard. Plan registration and intent removal commit together; process termination or later database failure leaves the prior durable intent. Ambient database transactions are rejected before setup begins. Metadata-only setup-failure rows keep the UI invalid/pending and identify the source for review/retry; the system does not restore an old plaintext copy or claim a cross-node atomic transaction. An ordinary SFTP writer can still race the short read/write window, as described above.

Startup setup offers existing egg variables only. Explicit migration rechecks a digest, clears the ordinary per-server value in a transaction, and binds the selected secret. Native bound fields display the binding name without a value input, and the model/service guard rejects direct writes. Migration does not rewrite old defaults, copies, logs, or backups; credentials previously exposed through them may require rotation. Saving a value, file, or binding never automatically restarts the game.

Run ./tools/dev task13-check. Its service and Livewire tests cover four formats, explicit confirmation, scoped pickers, whole JSON string insertion, stale/absent source receipts, interrupted registration, and native bound startup fields. The live setup fixture uses real Panel setup services and its authenticated bundle controller, in-memory synthetic node IPC, the real Wings preparer, and a separate non-root Docker game reading all four read-only overlays and its ephemeral environment. This supplements the real native power-path test from Task 10; full real Panel-to-Wings HTTPS/daemon qualification is a separate final release gate.

For local visual work, ./tools/dev ui-start creates a localhost-only disposable Panel with synthetic accounts, a separate database, a labelled source volume, and an explicitly guarded synthetic node transport in its fixture router. It exercises real native plugin discovery, authentication, page rendering, and setup actions; it does not qualify TLS or a real Wings daemon. Stop that labelled container with ./tools/dev ui-stop. These fixture scripts are not production plugin hooks.


## Retirement, host maintenance and cleanup

Removing bindings creates a new empty desired plan. A successful authenticated empty-plan start must replace the old container before Wings marks that revision retired and sends its applied receipt. Removing bindings alone does not release old runtime references or authorize disable/uninstall. Applied and unresolved issued references are retained per node; a supported successful native transfer fences its already-stopped source. Direct host reassignment retains old-node references conservatively.

Root host commands accept actor/server IDs for attribution and print fixed summaries: secrets:purge-versions and secrets:reencrypt. Re-encryption uses the current Laravel APP_KEY with configured previous keys and preserves logical version numbers; keys never belong in command arguments. The host scheduler runs secrets:prune-audit daily with configurable BPS_AUDIT_RETENTION_DAYS (default 90). Audit metadata survives plugin migration rollback and reinstall. After uninstall, the plugin scheduler is absent: the operator remains responsible for retained audit metadata.

An abandoned unregistered setup can be retired with secrets:retire-setup ACTOR SERVER FAILURE_UUID SHA256 --confirm, using the reviewed current ordinary-source digest, or absent for an actually missing source. A changed source, unavailable node, non-root actor, or registered managed file fails. This command does not edit source bytes or retire applied bindings. Retry normal setup to repair a registered failure.

The trusted Wings YAML setting system.secrets_runtime_directory selects the private tmpfs and is never accepted from Panel JSON. Startup reconciliation inspects all live, starting, paused, restarting and created Docker containers before deleting an orphan attempt; any failed inspection aborts the pass. Labels supplement inspected mount sources. A bind of the entire private root conservatively keeps all attempts, including a containerized development driver. Unsupported or missing tmpfs blocks protected preparation; ordinary servers can boot while the operator repairs it.

Protected containers use Docker restart policy no. Wings crash recovery must run the normal fresh preparation path. Normal stop, restore, transfer and successful container deletion release stopped runtime attempts. Naturally exited attempts can remain allocated until the next start, explicit cleanup or daemon restart; cleanup never guesses that a live consumer has stopped. Existing running games can survive a Wings restart, but an old attempt's source watcher waits for a fresh protected start rather than resetting its sequence.

Single-file bind mounts depend on the underlying destination directory entry. Renaming or deleting an ordinary template can make that pathname unavailable inside a running container even though its process and already-open descriptors continue. Editing/deleting configuration can disrupt application behavior; the next protected start rejects a missing or unapproved source. Source edits are not a mechanism for rotating the running runtime snapshot.
