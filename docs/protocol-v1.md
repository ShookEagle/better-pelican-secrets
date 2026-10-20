# Node protocol v1

Panel and patched Wings target the exact rc.1 commits in upstream.lock.json. Both decoders consume the shared protocol fixtures; neither accepts a higher protocol or unknown wire fields. Every nested object's field names are case-sensitive. The Go decoder also rejects duplicate keys, invalid UTF-8, unpaired UTF-16 escapes, and null-to-empty value coercion.

## Plans and bundles

A plan contains protocol, server_uuid, revision, versions, files, startup, and limits. Revisions are canonical decimal strings from "0" through "18446744073709551615"; no layer converts them through a float or JavaScript number. Immutable version numbers are positive integers through 2147483647.

Each versions entry is {secret_id, version}. The referenced IDs must match the union of file manifests and startup assignments exactly. A file has a UUID file_id, relative source, absolute in-container destination, output format, default mode, and manifest entries {secret_id, name, mode, count}. Startup assignments are {env, secret_id}. Duplicate file identities, sources, destinations, startup names, version identities, and manifest ID/mode pairs are invalid. Files with no placeholders have an empty manifest.

Names use [A-Z][A-Z0-9_]{0,63}. Output formats are json, yaml, dotenv, and text. Modes are json-string, yaml-string, dotenv, and raw. Protocol path validation rejects empty segments, traversal, control characters, and backslashes; Linux filesystem/mount validation imposes the stronger destination/root/symlink checks during preparation.

A runtime bundle adds attempt_id and a values object keyed by exactly the required secret IDs. Each value is a valid UTF-8 string without NUL, including an explicitly empty string. Missing, extra, null, and non-string values fail. Normal bundle logging, debug formatting, serialization, and JSON encoding omit values. PHP RuntimeBundle::toWire() is an explicit internal export reserved for the authenticated node controller response; it must never be logged, queued, or cached. Go values remain private to the secrets package.

## Reports and status

Reports carry protocol, server_uuid, kind, attempt_id, revision, source_digests, versions, and error_code. kind is applied, failed, or source-dirty. Digests map file UUIDs to lowercase SHA-256 strings. A failed report requires a documented error code; other reports require null. Arbitrary error messages and rendered text are rejected. A source-dirty report cannot advance the applied revision.

Error codes are protocol_invalid, protocol_unsupported, limit_exceeded, secret_missing, template_invalid, manifest_mismatch, path_unsafe, node_incompatible, panel_unavailable, revision_conflict, mount_failed, start_failed, decryption_failed, internal_error, value_invalid, and dotenv_unsupported.

ApplicationStatus presents desiredRevision, appliedRevision, pending, dirty, lastAttemptId, and nullable lastErrorCode. An applied observation refers only to its exact start snapshot.

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

The contract fixtures are seeds for the PHP and Go renderer tasks; they are not evidence that rendering is implemented. Exact 65,536-byte limits, UTF-8 validation, transport size, per-file multiset coverage, and aggregate expansion are enforced in code as well as the structural schemas.