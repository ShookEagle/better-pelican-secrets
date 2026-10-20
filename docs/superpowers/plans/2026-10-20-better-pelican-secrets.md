# Better Pelican Secrets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver server-owned secrets with explicit permissions, generic file templates, startup bindings, and isolated runtime delivery on the next server start.

**Architecture:** A Pelican plugin owns encrypted versions, grants, interfaces, and reference-only desired plans. A version-pinned Wings extension fetches an authenticated runtime bundle at start, renders files outside the server volume, and supplies read-only mounts and ephemeral environment overrides to a recreated container. PHP and Go share a versioned protocol and golden rendering fixtures.

**Tech Stack:** Pelican Panel/Wings v1.0.0-rc.1; PHP 8.4 with the Panel's locked Laravel 13/Filament 5 dependencies; Go 1.26.0; Linux Docker; the existing Symfony YAML 7.4.20 and phpdotenv 5.7.0 libraries; PHPUnit 12.5.33 from the Panel lockfile; Python 3 standard-library live acceptance tests.

**Spec:** [Approved design](../specs/2026-10-20-better-pelican-secrets-design.md). Read the complete spec, GLOSSARY.md, and ADR-0002/0003 before executing this plan.

## Global Constraints

- Target Pelican Panel and Wings v1.0.0-rc.1 as an explicitly tested pair.
- Server-owned secrets first. Shared secrets across servers are a future extension, not part of this release.
- Generic UTF-8 text templates, including JSON, YAML, .env, and raw text, from the first release.
- Startup bindings assign a secret to a named existing egg environment variable.
- Secret and template changes apply on the next normal start or restart. Never restart a server automatically after a save.
- Server owners and non-root administrators need explicit grants. Root administrators are trusted and have automatic authority.
- File and code editing remain available under existing Pelican permissions. Adding restrictions to executable uploads, safe JSON fields, or general console use is outside this design.
- Initial limits are 64 KiB of UTF-8 per secret, 4 MiB per template, 1,000 references per template, and 256 managed files per server.
- Managed runtime files are read-only inputs. Source templates remain editable. Normal source-file operations must never read the runtime copy.
- No plaintext values in ordinary startup-variable storage, cached Wings configuration, queues, previews, default serialization, audit records, or exception output.
- Runtime extraction by executable game code, malicious destination edits, and application-generated disclosures remain accepted trust limits; do not add developer restrictions to solve them.
- No production installation or service restart is part of executing this plan unless separately requested. Acceptance runs against a disposable Linux environment.

## Review Focus

1. A plugin can appear functional in mocked tests while never loading normally: prove actual plugin discovery/install and lifecycle vetoes outside the upstream test-loader shortcut (Tasks 1 and 15).
2. CRLF, BOMs, invalid UTF-8, and multiline dotenv values must behave identically in PHP and Go; unsupported representations fail without leaking input (Tasks 5 and 6).
3. Repeated references and mixed modes must respect expanded-output budgets, not merely source-file limits (Tasks 4, 5, 6, and 9).
4. A stop/start or daemon crash must not reuse old Docker environment values, mounts, or float-rounded revision identifiers (Tasks 4, 9, 10, and 14).
5. Root-owned Docker mounts still need the actual game UID/GID, user-namespace behavior, and configured data-root mapping to work, including `/game` (Tasks 1, 9, and 15).

Every focus item has an owning test below. These supplement the spec's permission, secrecy, and lifecycle acceptance cases.

## Repository and execution layout

This is one coordinated plan: the Panel and Wings parts share a mandatory wire contract and neither is a releasable substitute for the complete feature. Tasks still produce independently testable components. Task 1 is an integration feasibility gate; Tasks 2-8 build Panel/contracts; Tasks 9-10 build runtime delivery; Tasks 11-14 complete user flows/lifecycle; Task 15 qualifies the release.

Use `superpowers:using-git-worktrees` at execution time, before changing product files. Earlier setup files (`AGENTS.md` and `docs/agents/`) are currently untracked; preserve them and copy their approved instructions into the execution worktree without discarding or silently committing unrelated work. Do not create a new worktree while merely reviewing this plan.

| Tracked location | Responsibility |
| --- | --- |
| `plugins/better-pelican-secrets/` | Installable plugin, ID `better-pelican-secrets`, namespace `ShookEagle\BetterPelicanSecrets`. |
| `plugins/better-pelican-secrets/src/Secrets/` | Storage, explicit authorization, grants, audit. |
| `plugins/better-pelican-secrets/src/Templates/` | Parser, escaping, validation, dummy preview. |
| `plugins/better-pelican-secrets/src/Plans/` | Bindings, immutable snapshots, desired/applied revisions. |
| `plugins/better-pelican-secrets/src/Node/` | Wire DTOs, compatibility, node endpoints. |
| `plugins/better-pelican-secrets/src/Filament/` | Server/admin pages and forms. |
| `node/overlay/` | New Go files, copied into the corresponding pinned upstream Wings paths. |
| `node/patches/` | Ordered patches to existing upstream Wings files; never duplicate the full fork here. |
| `protocol/` | JSON schemas and shared golden fixtures. |
| `tests/php/`, `tests/acceptance/` | Plugin tests using the pinned Panel, and live cross-boundary tests. |
| `dev/`, `tools/`, `.github/workflows/` | Disposable harness, source pins, patch application, packaging, CI. |
| `.work/panel/`, `.work/wings/`, `dist/` | Ignored materialized upstream checkouts/build output; not authoritative source. |

Resolve upstream by commit, not floating tags:

- Panel: `46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00`.
- Wings: `7b489da7b8196d0b4baef3e2db062c06d743e2f9`.

The Linux command wrapper defined in Task 1 is `./tools/dev`: `prepare`, `php <test-path> [--filter <name>]`, `go <packages> [go-test-flags]`, `acceptance [-k <name>]`, `check`, `refresh-patches`, and `package`. It runs PHP tests against the pinned application, Go tests inside the materialized patched Wings module, and acceptance through `python3 -m unittest discover -s tests/acceptance -p 'test_*.py'`. Materialization copies overlays and applies patches in numeric order to the exact upstream base; refresh regenerates patches for existing upstream files only, while new files remain authoritative in `node/overlay/`. Never commit `.work` checkouts. Implement commands when their first owning task needs them; do not leave successful no-op stubs.

All tasks follow red/green verification. A failure must be the intended missing behavior, not an unavailable tool or broken harness. Stage only each task's listed files and patch outputs, check the staged diff, and commit with its specified message. Acceptance tests marked Docker/Linux-dependent must actually run in that environment; a skip is not a pass. Unless qualified, PHP `User`, `Server`, and `Plugin` mean the corresponding `App\Models` classes. Below, paths introduced as plugin-relative are rooted at `plugins/better-pelican-secrets/`; overlay-relative paths are rooted at `node/overlay/`. Assertion excerpts assume the named test has arranged its fixtures; they pin behavior rather than supplying test bodies.

---

### Task 1: Prove installation seams and runtime mount behavior

**Files:** Create `upstream.lock.json`, `.gitignore`, `tools/dev`, `tools/materialize-upstream.sh`, `dev/compose.yaml`, `dev/php.Dockerfile`, `dev/images.lock.json`, `tests/phpunit.xml`, `tests/php/bootstrap.php`, `tests/php/Compatibility/IntegrationSeamsTest.php`, `tests/acceptance/test_mount_contract.py`, `plugins/better-pelican-secrets/plugin.json`, `plugins/better-pelican-secrets/src/BetterPelicanSecretsPlugin.php`, `plugins/better-pelican-secrets/src/Providers/BetterPelicanSecretsProvider.php`, `docs/development.md`.

**Interfaces:** Consumes the pinned upstream commits. Produces the command wrapper above, a real installed plugin with `getId(): string`, `register(Filament\Panel $panel): void`, `boot(Filament\Panel $panel): void`, and the PHP test bootstrap. Manifest: version `0.1.0`, `api_version: 1`, strict `panel_version: 1.0.0-rc.1`, panels `admin` and `server`; omit installation-state `meta` from distributable manifests.

- [x] **Step 1:** Establish the runnable harness/bootstrap, then add `test_plugin_loads_in_real_application` and `test_runtime_mount_preserves_source`. Assert discovered plugin ID, registered provider, source bytes `{{secret:KEY}}`, container bytes `fixture-value`, denied runtime overwrite, unchanged SFTP/backup source bytes, and a recreated container seeing a second fixture value. Add `test_game_uid_can_read_under_game_mapping` for a non-root UID/GID and `/game` data mapping.

  ```python
  self.assertEqual(source_bytes, b"{{secret:KEY}}")
  self.assertEqual(container_bytes, b"fixture-value")
  self.assertNotEqual(write_attempt.returncode, 0)
  ```
- [x] **Step 2:** Run `./tools/dev prepare`, then `./tools/dev php tests/php/Compatibility/IntegrationSeamsTest.php` and `./tools/dev acceptance -k mount_contract`. Establish dependencies first; record the subsequent expected failures as missing registration/mount behavior.
- [x] **Step 3:** Implement the minimal plugin/harness and mount experiment. Materialize exact commits, install Composer from its lock, pin harness images by resolved digest, and expose services only to the disposable test network/loopback. PHPUnit explicitly registers the plugin provider because upstream skips plugin loading in tests; the separate real-application check proves discovery without that shortcut. Verify `PluginService` service replacement can veto `disablePlugin` and `uninstallPlugin` before destructive work, and `ServerConfigurationStructureService` can be decorated. This gate must pass before building on those seams; otherwise report the exact incompatibility for design amendment rather than adding an unapproved Panel-core patch.
- [x] **Step 4:** Repeat both commands. Expected: green checks for real loading, lifecycle seam interception, read-only overlay precedence, UID access, and container replacement. Document results and image digests, not hypothetical compatibility.
- [x] **Step 5:** Commit `test: establish pinned Pelican integration and mount harness`.

### Task 2: Store encrypted versions and metadata-only audit events

**Files:** Create `plugins/better-pelican-secrets/database/migrations/2026_10_20_000001_create_secret_storage.php`; `src/Secrets/SecretStore.php`, `SecretMetadata.php`, `SecretVersionRef.php`, `SecretValue.php`, `AuditRecorder.php`, `Models/Secret.php`, `Models/SecretVersion.php`, `Models/SecretAuditEvent.php` beneath the plugin; `tests/php/Secrets/SecretStoreTest.php`.

**Interfaces:** `SecretStore::create(Server $server, string $name, string $description, SecretValue $value, User $actor): SecretMetadata`; `replace(string $secretId, SecretValue $value, User $actor): SecretVersionRef`; `metadata(string $secretId): SecretMetadata`; `reveal(SecretVersionRef $version): SecretValue`; `delete(string $secretId, User $actor): void`. The store is internal-only; Task 7 adds the binding-aware deletion entry point before public routes exist. `SecretVersionRef` contains `secretId: string`, `version: int`. `SecretValue` wraps a private UTF-8 value and exposes `bytes(): string`; debug/JSON/string representations are redacted. `AuditRecorder::record(User|int $actor, string $operation, array $metadata): void` accepts whitelisted metadata only.

- [x] **Step 1:** Write `test_ciphertext_is_not_value`, `test_versions_are_immutable`, `test_empty_is_distinct_from_unchanged`, and `test_audit_and_serialization_exclude_values`. Assertions include `assertStringNotContainsString('fixture-secret', $databaseRowJson)` and `assertSame(2, $newVersion->version)`. Cover the exact name regex, duplicate server/name, 65,536-byte boundary, invalid UTF-8/NUL, encryption failure, and non-secret description handling.

  ```php
  $this->assertStringNotContainsString('fixture-secret', $databaseRowJson);
  $this->assertSame(2, $newVersion->version);
  ```
- [x] **Step 2:** Run `./tools/dev php tests/php/Secrets/SecretStoreTest.php`; expected missing storage behavior.
- [x] **Step 3:** Implement models/migration and authenticated Laravel encryption with hidden ciphertext fields, transactional version replacement, unique names per server, immutable names, and redacted errors. This task exposes no public route; callers are trusted services until Task 3 supplies authorization. Generated values use 32 random bytes encoded base64url without padding. Never hash a value into audit metadata.
- [x] **Step 4:** Repeat the command; expect all boundary and leakage assertions to pass, including an empty stored value created explicitly.
- [x] **Step 5:** Commit `feat: store encrypted immutable secret versions`.

### Task 3: Enforce explicit grants and prevent delegation escalation

**Files:** Create plugin `database/migrations/2026_10_20_000002_create_secret_grants.php`; `src/Secrets/SecretOperation.php`, `SecretScope.php`, `SecretAuthorizer.php`, `ExplicitNativeGrantReader.php`, `GrantService.php`, `Models/SecretGrant.php`; `tests/php/Secrets/SecretAuthorizerTest.php`, `tests/php/Secrets/GrantServiceTest.php`. Modify the provider to register `secrets.list`, `secrets.reveal`, `secrets.create`, `secrets.update`, `secrets.delete`, `secrets.bind`, `secrets.manage-access`.

**Interfaces:** `SecretOperation` is a backed enum of the seven spec operation strings, with cases `ListSecrets`, `Reveal`, `Create`, `Update`, `Delete`, `Bind`, `ManageAccess`. `SecretScope(int $serverId, ?string $secretId)` is a readonly value. `SecretAuthorizer::authorize(User $actor, SecretOperation $operation, SecretScope $scope): void` throws a metadata-only authorization error. `ExplicitNativeGrantReader::operations(User $actor, int $serverId): array` reads raw role/subuser grants without owner shortcuts. `GrantService::grant(User $actor, User $recipient, SecretScope $scope, array $operations): void`; `revoke(User $actor, User $recipient, SecretScope $scope, array $operations): void`.

- [x] **Step 1:** Write a table-driven `test_explicit_permission_matrix` covering root, ordinary admin, owner, subuser, global role grant, server grant, per-secret grant, and wrong server. Assert an owner without a grant is denied and a root is allowed. `test_manager_cannot_grant_unheld_reveal` and `test_revocation_affects_next_request` cover delegation and cache invalidation. Check broader additive grants remain effective after deleting a narrow grant.

  ```php
  $this->expectException(AuthorizationException::class);
  $authorizer->authorize($ownerWithoutGrant, SecretOperation::Reveal, $scope);
  ```
- [x] **Step 2:** Run `./tools/dev php tests/php/Secrets`; expected failures in the new authorizer/grant tests while Task 2 stays green.
- [x] **Step 3:** Implement root-only unconditional bypass, additive explicit scopes, server-only create, normal server membership, and same-or-narrower delegation. Native role/subuser mutation adapters use these checks before storing custom rights; no native `can()` wildcard is accepted as proof. Memoization is request-local, and grant mutations invalidate native permission caches.
- [x] **Step 4:** Repeat the command. Expected: every operation/actor cell passes, including direct grants to an owner without a subuser row and immediate next-request denial after revoke.
- [x] **Step 5:** Commit `feat: enforce explicit server and secret grants`.

### Task 4: Freeze protocol v1 and cross-language fixture contracts

**Files:** Create `protocol/v1/plan.schema.json`, `bundle.schema.json`, `report.schema.json`, `protocol/fixtures/rendering.json`, `protocol/fixtures/protocol.json`; plugin `src/Node/Protocol.php`, `DesiredPlan.php`, `FilePlan.php`, `StartupPlan.php`, `RuntimeBundle.php`, `AppliedReport.php`, `ApplicationStatus.php`; `node/overlay/secrets/protocol.go`, `protocol_test.go`; `tests/php/Node/ProtocolTest.php`; `docs/protocol-v1.md`.

**Interfaces:** `Protocol::decodePlan(array $input): DesiredPlan`; `RuntimeBundle::toWire(): array` is callable only by the node controller. Go `DecodePlan([]byte) (Plan, error)` and `DecodeBundle([]byte) (Bundle, error)` mirror schema fields. Define `protocol: 1`, `server_uuid`, decimal-string `revision`, `attempt_id`, explicit version references, files, startup assignments, and allowed limits. `FilePlan` specifies `file_id`, `source`, `destination`, `format`, `default_mode`, and manifest entries `{secret_id,name,mode,count}`. `RuntimeBundle` carries the same identity/revision plus `values` keyed by secret ID; report fields contain digests/version references and a whitelisted error code, never values.

Produce Go structs `Plan`, `FilePlan`, `StartupPlan`, `Bundle`, `AppliedReport`, and `Limits` in `protocol.go`; metadata field names are exported PascalCase equivalents of the JSON names. `Plan` contains `Protocol int`, `ServerUUID string`, `Revision string`, `Files []FilePlan`, `Startup []StartupPlan`, and `Limits Limits`. `Bundle` adds `AttemptID string` and unexported `values map[string]string`, populated by a dedicated wire decoder; its formatter/debug/JSON output is metadata-only. `Limits` contains `SecretBytes`, `TemplateBytes`, `ReferencesPerFile`, `FilesPerServer`, `BundleBytes`, and `RenderedBytes` as `int64`. Reports include `Kind` (`applied`, `failed`, or `source-dirty`), `AttemptID`, `Revision`, `SourceDigests map[string]string`, version references, and `ErrorCode`; source-dirty reports never advance applied revision. PHP DTO properties use camelCase equivalents of the wire names. PHP `ApplicationStatus` contains `desiredRevision`, `appliedRevision`, `pending`, `dirty`, `lastAttemptId`, and nullable `lastErrorCode`. The test wrapper exports `BPS_PROTOCOL_FIXTURES` as the absolute `protocol/fixtures` directory for both languages; fixture tests fail, not skip, if it is missing.

- [x] **Step 1:** Write `test_revision_roundtrip_above_js_safe_integer` in both suites using revision `9007199254740993`, and `test_runtime_values_never_enter_plan_or_report`. Fixtures cover all rendering modes, literal escapes, mixed modes, duplicates, line endings, Unicode, malformed references, and budget errors.

  ```php
  $this->assertSame('9007199254740993', $decoded->revision);
  $this->assertStringNotContainsString('fixture-secret', json_encode($plan));
  ```
- [x] **Step 2:** Run `./tools/dev php tests/php/Node/ProtocolTest.php` and `./tools/dev go ./secrets -run TestProtocol`; expect missing contracts.
- [x] **Step 3:** Implement strict schema/type decoding, protocol-version rejection, stable error codes, and reference-only DTO serialization. Reuse spec limits; add documented configurable transport/output bounds of 16 MiB per bundle and 64 MiB aggregate rendered bytes per start, checked before unbounded allocation. No DTO can silently stringify a plaintext bundle for logging.
- [x] **Step 4:** Repeat both commands; expected matching fixtures and lossless decimal-string revisions. Review schema examples for synthetic values only.
- [x] **Step 5:** Commit `feat: define versioned node protocol and shared fixtures`.

### Task 5: Implement the PHP template compiler and safe preview

**Files:** Create plugin `src/Templates/TemplateDocument.php`, `CompiledTemplate.php`, `TemplateCompiler.php`, `TemplateError.php`, `PreviewService.php`; `tests/php/Templates/TemplateCompilerTest.php`, `tests/php/Templates/PreviewServiceTest.php`. Extend `protocol/fixtures/rendering.json` with the cases in Step 1.

**Interfaces:** `TemplateDocument(string $source, string $format, string $defaultMode, array $manifest)`; `TemplateCompiler::compile(TemplateDocument $document): CompiledTemplate`; `render(CompiledTemplate $template, array $valuesBySecretId): string`; `PreviewService::preview(User $actor, int $serverId, TemplateDocument $document): string` always uses deterministic dummy values. Compiled tokens reference immutable secret IDs, mode, and source spans; they never contain live values.

- [ ] **Step 1:** Write `test_shared_rendering_vectors`, `test_preview_never_reads_secret_store`, `test_line_endings_and_encoding`, and `test_repeated_tokens_hit_expanded_budget`. Assert decoded JSON/YAML equals `"quote\" slash\\ dollar$ line\n雪"`, raw inserted token-looking text is not rescanned, CRLF outside tokens is preserved, an initial UTF-8 BOM is preserved only for text and rejected for structured outputs, and invalid UTF-8 is rejected. Dotenv vectors are read back with locked phpdotenv; reject unsupported multiline representations rather than inventing a dialect.

  ```php
  $this->assertSame($vector['expected_output'], $rendered);
  $this->assertSame($originalValue, json_decode($jsonOutput, true)['apiKey']);
  ```
- [ ] **Step 2:** Run `./tools/dev php tests/php/Templates`; expected missing compiler behavior.
- [ ] **Step 3:** Implement a single-pass literal scanner, `{{secret:NAME}}`, explicit `|mode`, and `{{!secret:NAME}}`. Enforce manifest multisets, per-token escaping, whole-document validation by output format, limits, and no recursion/evaluation. Use Symfony YAML and phpdotenv already in the pinned Panel; do not install a general template engine. Reject YAML aliases/merge constructs when the two language validators cannot agree; generic text mode remains available.
- [ ] **Step 4:** Repeat tests and shared vectors. Expected exact outputs/explicit errors with no source values or rendered fragments in diagnostics.
- [ ] **Step 5:** Commit `feat: compile and preview generic secret templates`.

### Task 6: Implement matching Go rendering and validation

**Files:** Create `node/overlay/secrets/renderer.go`, `renderer_test.go`, `dotenv.go`, `dotenv_test.go`. Modify `protocol/fixtures/rendering.json` only for additional agreed cross-language cases.

**Interfaces:** `Compile(source []byte, file FilePlan, limits Limits) (CompiledTemplate, error)` and `Render(template CompiledTemplate, values map[string]string, remainingBytes int64) ([]byte, error)`. Go tokenization, byte counting, quoting, validation errors, and manifest checks must match Task 5.

- [ ] **Step 1:** Write `TestSharedRenderingVectors`, `TestCRLFBOMAndInvalidUTF8`, `TestDotenvParity`, and `TestExpansionBudget`. Assertions load the same checked-in expected bytes/errors as PHP, including exactly 1,000 allowed references, rejected 1,001st reference, and budget termination before producing more than the permitted output.

  ```go
  require.Equal(t, vector.ExpectedOutput, string(rendered))
  require.LessOrEqual(t, int64(len(rendered)), limits.RenderedBytes)
  ```
- [ ] **Step 2:** Run `./tools/dev go ./secrets -run 'TestSharedRendering|TestCRLF|TestDotenv|TestExpansion'`; expected renderer failures.
- [ ] **Step 3:** Implement the scanner/encoder and a deliberately bounded dotenv emitter/validator matching the shared phpdotenv fixtures. Reuse pinned Go YAML/JSON dependencies. Do not substitute a different generic dotenv library and assume dialect parity. Align YAML acceptance with Task 5 through fixtures and reject divergent constructs in structured mode.
- [ ] **Step 4:** Run the Go command and `./tools/dev php tests/php/Templates`; both must pass the same vectors. The cross-language fixture suite is the authority, not independent plausible implementations.
- [ ] **Step 5:** Commit `feat: render node templates with cross-language parity`.

### Task 7: Save bindings and immutable desired plans

**Files:** Create plugin `database/migrations/2026_10_20_000003_create_secret_plans.php`; `src/Plans/BindingService.php`, `PlanService.php`, `ManagedVariableGuard.php`, `Models/ManagedFile.php`, `Models/StartupBinding.php`, `Models/DesiredPlanRecord.php`; `tests/php/Plans/BindingServiceTest.php`, `PlanServiceTest.php`, `ManagedVariableGuardTest.php`. Modify the provider's model/service hooks.

**Interfaces:** `BindingService::saveFile(User $actor, Server $server, FilePlan $file, string $templateSource): DesiredPlan`; `bindStartup(User $actor, Server $server, string $env, string $secretId): DesiredPlan`; `removeFile(User $actor, Server $server, string $fileId): DesiredPlan`; `unbindStartup(User $actor, Server $server, string $env): DesiredPlan`; `deleteSecret(User $actor, Server $server, string $secretId): void`. Deletion authorizes `delete`, locks/checks all bindings, and only then calls the internal store. `PlanService::current(Server $server): DesiredPlan`; `snapshot(Server $server, string $expectedRevision): DesiredPlan`; `markSecretChanged(string $secretId): void`. `ManagedVariableGuard::assertWritable(Server $server, string $env): void` blocks ordinary writes while bound. The caller supplies source text for validation; this service saves the approved plan, not the remote file. The node independently checks the actual source before start.

- [ ] **Step 1:** Add `test_bind_requires_all_secret_and_destination_rights`, `test_bound_variable_never_stores_value`, `test_rotation_pins_one_consistent_revision`, and `test_bound_secret_cannot_be_deleted`. Exercise native Startup Livewire, client/application API, and model-level writes; stale/default `server_variables` values are removed only by explicit migration, never copied silently into a binding.

  ```php
  $this->assertNotSame($before->revision, $after->revision);
  $this->assertStringNotContainsString('fixture-secret', json_encode($after));
  $daemon->shouldNotHaveReceived('power');
  ```
- [ ] **Step 2:** Run `./tools/dev php tests/php/Plans`; expect missing binding/guard behavior.
- [ ] **Step 3:** Implement transactional monotonic desired revisions, locking current version references, startup-name existence checks against the egg, file/manifest validation through Task 5, and normal destination edit permission checks. SecretStore replacement notifies PlanService after commit. Blocking normal writes must work across native forms/APIs and other plugin model writes; own migration paths use a narrow explicit bypass inside the transaction.
- [ ] **Step 4:** Repeat tests. Expected: one complete snapshot, no value in plan/ordinary variables, unbind first before deletion, and no power-action dispatch on save.
- [ ] **Step 5:** Commit `feat: bind secrets to versioned file and startup plans`.

### Task 8: Expose authenticated node bundles and capability gates

**Files:** Create plugin `routes/node.php`; `src/Node/RuntimeBundleController.php`, `ApplicationReportController.php`, `NodeCapabilityService.php`, `ReferenceConfigurationService.php`; `database/migrations/2026_10_20_000004_create_application_records.php`; `src/Plans/Models/ApplicationRecord.php`; `tests/php/Node/RuntimeBundleTest.php`, `NodeCapabilityTest.php`, `ApplicationReportTest.php`. Modify provider service bindings. Create `node/patches/0001-secrets-protocol.patch` targeting `remote/servers.go`, `remote/types.go`, `remote/http.go`, `server/configuration.go`, and `router/router_system.go`; create overlay `remote/secrets.go`, `remote/secrets_test.go`.

**Interfaces:** `POST /api/remote/servers/{uuid}/secrets/runtime-bundle` accepts protocol, expected revision, attempt ID; `POST .../secrets/application-report` accepts AppliedReport. `ReferenceConfigurationService::handle(Server $server, array $override = []): array` preserves upstream configuration and adds a reference-only `secrets` plan. Go remote interface adds `GetSecretBundle(ctx context.Context, uuid string, revision string, attemptID string) (secrets.Bundle,error)` and `ReportSecretApplication(ctx context.Context, uuid string, report secrets.AppliedReport) error`. Capability response advertises protocol `1` and the patched base version.

- [ ] **Step 1:** Add `test_wrong_node_and_browser_cannot_fetch_bundle`, `test_stale_revision_returns_conflict`, `test_bundle_is_no_store_and_values_are_minimal`, `test_stock_node_cannot_enable_bindings`, and `test_old_report_cannot_clear_new_pending_revision`. Go `TestSecretRemoteRejectsOversizeAndIdentityMismatch` checks bounded bodies, TLS validation, and error-body redaction.

  ```php
  $staleResponse->assertStatus(409);
  $bundleResponse->assertHeader('Cache-Control', 'no-store');
  $browserResponse->assertForbidden();
  ```
- [ ] **Step 2:** Run `./tools/dev php tests/php/Node` and `./tools/dev go ./remote -run TestSecretRemote`; expected missing endpoints/capabilities.
- [ ] **Step 3:** Implement node-authenticated scope checks, 409 revision conflicts, minimal pinned-version bundles, no-store headers, and metadata-only idempotent reports. Node values stay out of request/response logging. Reject activation/transfer to nodes without verified capability; gate Panel power routes and websocket power permissions on compatibility. Unmodified stock Wings cannot implement a new veto: activation must be refused by Panel, while patched Wings enforces required plans on every node start. A trusted host operator replacing the binary with stock Wings is outside the guarantee, not a supported fallback. Decorate the resolved configuration service without plaintext environment injection.
- [ ] **Step 4:** Repeat suites and inspect a serialized native server configuration for reference-only fields. Root power actions do not bypass node compatibility.
- [ ] **Step 5:** Commit `feat: deliver scoped runtime bundles to compatible nodes`.

### Task 9: Prepare private runtime files with safe path handling

**Files:** Create overlay `secrets/preparer.go`, `preparer_test.go`, `paths_linux.go`, `paths_linux_test.go`, `runtime_linux.go`, `runtime_linux_test.go`; `tests/acceptance/test_runtime_files.py`.

**Interfaces:** `Preparer.Prepare(ctx context.Context, plan Plan, bundle Bundle, root *os.Root, mapping DataMapping, identity GameIdentity) (*PreparedStart,error)`; `PreparedStart` privately owns overrides, mount sources, attempt/revision, source digests, and cleanup. Expose `Mounts() []RuntimeMount`, `Environment() map[string]string`, `Report() AppliedReport`, `Close() error`; none serialize plaintext. Define `RuntimeMount{Source string, Destination string, ReadOnly bool}`, `DataMapping{HostRoot string, ContainerRoot string}`, and `GameIdentity{ContainerUID uint32, ContainerGID uint32, HostUID uint32, HostGID uint32}` in `preparer.go`; identity includes Docker user-namespace translation.

- [ ] **Step 1:** Add `TestTraversalSymlinkSwapAndSpecialFiles`, `TestDuplicateAndOverlappingDestinations`, `TestSourceChangesRetryOnce`, `TestAggregateOutputBudget`, and live `test_game_uid_reads_private_tmpfs_mount`. Assert traversal/reserved roots fail, `/game/...` maps only through configured roots, root directory mode is 0700, file mode is 0400 for the effective game owner, and the normal server volume never receives the fixture value.

  ```go
  require.True(t, mounts[0].ReadOnly)
  require.Equal(t, os.FileMode(0400), runtimeFileInfo.Mode().Perm())
  require.NotContains(t, string(sourceBytes), "fixture-secret")
  ```
- [ ] **Step 2:** Run `./tools/dev go ./secrets -run 'TestTraversal|TestDuplicate|TestSource|TestAggregate|TestRuntime'` and `./tools/dev acceptance -k runtime_files`; expected missing preparation.
- [ ] **Step 3:** Implement Linux descriptor-relative safe reads using `unix.Openat2` beneath-root/no-symlink checks and `os.Root` ownership, bounded snapshots/digests, Task 6 rendering, and a private `/run/better-pelican-secrets/{server_uuid}/{attempt_id}` tmpfs area outside data volumes. Require working `openat2` (Linux 5.6 or newer) and return a compatibility error instead of weakening path checks on unsupported kernels. Validate tmpfs rather than silently using persistent storage. Resolve effective UID/GID; reject unsupported mappings instead of loosening host file modes. Recheck sources before mounting; exactly one retry means at most two snapshot attempts. Clean partial attempts on all errors and release references without logging buffers.
- [ ] **Step 4:** Repeat tests. Expected: path-swap attack fails safely, second modification aborts start, and non-root game reads succeed while runtime files remain absent from source SFTP/backup traversal.
- [ ] **Step 5:** Commit `feat: prepare private runtime mounts from safe snapshots`.

### Task 10: Apply a fresh start snapshot and redact controlled output

**Files:** Create `node/patches/0002-secret-start-lifecycle.patch` targeting `server/power.go`, `server/server.go`, `environment/docker/container.go`, and `environment/docker/power.go`; overlay `server/secrets_start.go`, `server/secrets_start_test.go`, `environment/docker/secrets_runtime.go`, `environment/docker/secrets_runtime_test.go`, `secrets/runtime.go`, `secrets/redactor.go`, `secrets/redactor_test.go`; `tests/acceptance/test_secret_start.py`.

**Interfaces:** `Server.prepareSecretStart(ctx context.Context) (*secrets.PreparedStart,error)` runs inside the existing power lock before any protected start. `docker.Environment.SetSecretRuntime(prepared *secrets.PreparedStart) error` stores ephemeral overrides separately from persisted configuration and implements `secrets.RuntimeConsumer`, an interface declared in `secrets/runtime.go` with that method. The server uses a checked interface assertion; an unsupported environment rejects a protected start. `secrets.NewRedactor(destination io.Writer, values []string) io.WriteCloser` is a streaming literal-value sanitizer; empty values are excluded from match patterns. Every successful/failed attempt emits Task 8's metadata-only report.

- [ ] **Step 1:** Add `TestStartAndRestartAlwaysPrepare`, `TestStoppedContainerGetsNewEnvAndMounts`, `TestFailureCannotStartWithStaleSnapshot`, `TestConcurrentStartsSharePowerLock`, and `TestRedactorHandlesChunksAndOverlappingValues`. Live `test_rotate_without_restart_then_apply` asserts no restart after save, old value while running, new value after normal restart, reference-only cached configuration, and read-only runtime files.

  ```go
  require.Equal(t, 0, startCallsAfterSave)
  require.Equal(t, "second-value", recreatedContainerEnv["RCON_PASSWORD"])
  require.NotContains(t, controlledOutput.String(), "fixture-secret")
  ```
- [ ] **Step 2:** Run `./tools/dev go ./server ./environment/docker ./secrets -run 'TestSecret|TestStart|TestStopped|TestFailure|TestConcurrent|TestRedactor'` and `./tools/dev acceptance -k secret_start`; expected absent lifecycle integration.
- [ ] **Step 3:** Integrate prepare after latest config sync but before container creation/start on both start/restart paths. Recreate a stopped container whenever protected material changes; never mutate serialized environment/mount config with values. Pass merged copies only to Docker creation. Report applied only after successful Docker start; newer desired revisions stay pending. Literal redaction occurs before Wings-controlled logging/websocket/history delivery for prepared starts, buffers partial matches across chunks, and is explicitly best effort for application output. A missing redaction inventory after reattach must not be advertised as guaranteed redaction. Preserve ordinary developer code/console permissions.
- [ ] **Step 4:** Repeat Go/live tests, then upstream `./tools/dev go ./server ./environment/docker` regressions. Expected preserved ordinary power behavior and no stale snapshot fallback or premature applied state.
- [ ] **Step 5:** Commit `feat: apply fresh runtime secrets on every protected start`.

### Task 11: Expose source-template editing and truthful dirty status

**Files:** Create plugin `src/Templates/TemplateSourceService.php`, `src/Plans/ApplicationStatusService.php`; `tests/php/Templates/TemplateSourceServiceTest.php`, `tests/php/Plans/ApplicationStatusTest.php`; overlay `secrets/watcher.go`, `secrets/watcher_test.go`; `node/patches/0003-template-status.patch` targeting `server/server.go` for watcher lifecycle; `tests/acceptance/test_template_access.py`.

**Interfaces:** `TemplateSourceService::read(User $actor, Server $server, string $fileId): string`; `save(User $actor, Server $server, string $fileId, string $source, string $expectedDigest): void` uses normal file rights and optimistic digests, not secret-store reads. `ApplicationStatusService::forServer(Server $server): ApplicationStatus`. Go `Watcher.Observe(ctx context.Context, plan Plan, applied AppliedReport) error` reports source digests/dirty state, with digest comparison authoritative at start.

- [ ] **Step 1:** Add `test_editor_never_fetches_rendered_file`, `test_stale_save_returns_conflict`, `test_unapproved_reference_does_not_render`, and `test_source_change_remains_pending_after_old_report`. Live `test_editor_sftp_download_archive_and_backup_see_template` reads the same registered file through all normal paths and asserts the marker exists and fixture value does not.

  ```php
  $this->assertStringContainsString('{{secret:API_KEY}}', $source);
  $this->assertStringNotContainsString('fixture-secret', $source);
  $this->assertTrue($status->pending);
  ```
- [ ] **Step 2:** Run `./tools/dev php tests/php/Templates/TemplateSourceServiceTest.php`, `./tools/dev php tests/php/Plans/ApplicationStatusTest.php`, `./tools/dev go ./secrets -run TestWatcher`, and `./tools/dev acceptance -k template_access`.
- [ ] **Step 3:** Implement source-only file operations through the existing daemon repository, digest-conflict responses, and metadata-only dirty reports. Poll managed source metadata/digests every five seconds using a cancellable watcher, avoiding a new filesystem-event dependency; digest comparison at start remains authoritative. Normal SFTP edits may invalidate a manifest; show invalid/pending status and block the next protected start rather than removing SFTP permissions. Ordinary surrounding edits with unchanged manifest remain allowed. Never overwrite the backing template with a runtime read or expose a render endpoint to general file clients.
- [ ] **Step 4:** Repeat suites. Expected native source paths stay unchanged, runtime mount remains a start snapshot, and stale status messages cannot erase a newer dirty/pending state.
- [ ] **Step 5:** Commit `feat: edit source templates and track pending application`.

### Task 12: Deliver server/admin secret and access interfaces

**Files:** Create plugin `src/Filament/Server/Pages/Secrets.php`, `src/Filament/Admin/Pages/Secrets.php`, `src/Filament/Shared/SecretActions.php`, `GrantActions.php`, `src/Http/Controllers/SecretController.php`, `routes/client.php`, `resources/views/filament/server/secrets.blade.php`, `resources/views/filament/admin/secrets.blade.php`, `lang/en/secrets.php`; `tests/php/Filament/SecretsPageTest.php`, `tests/php/Http/SecretControllerTest.php`. Modify main plugin/provider registrations.

**Interfaces:** UI actions and `/api/client/servers/{server}/secrets` CRUD/reveal/grant endpoints call Tasks 2/3/7 services, never model values directly. `SecretController::reveal(Illuminate\Http\Request $request, Server $server, string $secretId): Illuminate\Http\JsonResponse` obtains the actor from `$request->user()` and returns only the explicitly requested authorized value with no-store. Shared actions expose name/description/version/binding count metadata and distinct Replace, Set empty value, Delete, Reveal, Generate, and Manage access actions.

- [ ] **Step 1:** Write `test_owner_page_does_not_bypass_grants`, `test_direct_livewire_action_rechecks_permission`, `test_blank_update_preserves_value`, `test_reveal_is_separate_audited_action`, and `test_admin_list_respects_scope`. Assert ordinary page HTML/Livewire payloads and API list JSON never contain the fixture value, update never prefills it, and granting unheld reveal is denied through native and plugin forms.

  ```php
  $listResponse->assertDontSee('fixture-secret');
  $deniedRevealResponse->assertForbidden();
  $authorizedRevealResponse->assertJsonPath('value', 'fixture-secret');
  ```
- [ ] **Step 2:** Run `./tools/dev php tests/php/Filament/SecretsPageTest.php` and `./tools/dev php tests/php/Http/SecretControllerTest.php`; expected missing interfaces.
- [ ] **Step 3:** Implement three sections on the server page, admin server filters, explicit grant management with visible inherited access, request-by-request authorization, action-specific metadata responses, and sanitized validation messages. Deletion must call `BindingService::deleteSecret`, not the internal store directly. Bind native admin/subuser custom permission editing to the Task 3 authorizer; UI hiding alone is insufficient. Do not persist revealed values in component state across ordinary subsequent requests or include them in general notifications.
- [ ] **Step 4:** Repeat tests and manually view server/admin flows using fixture users in the disposable Panel. Expected correct action availability and explicit reveal only; verify both layouts visually before screenshots/documentation.
- [ ] **Step 5:** Commit `feat: add server and admin secret management pages`.

### Task 13: Deliver generic file/startup setup and explicit migration

**Files:** Create plugin `src/Filament/Shared/FileTemplateActions.php`, `StartupBindingActions.php`, `src/Templates/MigrationService.php`; `resources/views/filament/shared/template-editor.blade.php`; `tests/php/Filament/BindingActionsTest.php`, `tests/php/Templates/MigrationServiceTest.php`, `tests/acceptance/test_setup_flow.py`. Extend the server Secrets page, language file, and native Startup customization registration.

**Interfaces:** File setup uses Task 5 preview, Task 7 binding, and Task 11 source services. `MigrationService::migrate(User $actor, Server $server, string $expectedDigest, string $replacementSource, FilePlan $file, array $confirmedReferences): DesiredPlan` replaces an existing source only after explicit confirmation and a digest recheck. `confirmedReferences` contains approved secret IDs/modes, never old values. Save source optimistically before committing the corresponding approved plan; if the plan save fails, report an invalid/pending source rather than claiming a cross-node database transaction or starting it. Native bound startup inputs show `Bound to secret: NAME`, not the value.

- [ ] **Step 1:** Add `test_picker_offers_only_bindable_secrets`, `test_format_suggestion_requires_confirmation`, `test_json_yaml_dotenv_and_text_setup`, `test_migration_requires_confirmation_and_current_digest`, and `test_bound_startup_form_cannot_write_plain_value`. Verify the JSON picker replaces an entire selected string value, avoiding doubled outer quotes, and previews use dummy data.

  ```php
  $this->assertSame('json-string', $file->defaultMode);
  $this->assertStringContainsString('{{secret:API_KEY}}', $savedSource);
  $this->assertStringNotContainsString('fixture-secret', $preview);
  ```
- [ ] **Step 2:** Run `./tools/dev php tests/php/Filament/BindingActionsTest.php`, `./tools/dev php tests/php/Templates/MigrationServiceTest.php`, and `./tools/dev acceptance -k setup_flow`.
- [ ] **Step 3:** Implement file picker/create, generic token insertion, explicit format/mode controls, mixed-mode support, dummy rendered preview, source/destination mapping display, and pending status. Offer only existing egg variables for startup bindings. The migration confirmation explains old copies/logs/backups are untouched and rotation may be needed; it does not echo detected values. Show the read-only-runtime limitation before registration. No per-game schema or editable-field allowlist is introduced.
- [ ] **Step 4:** Repeat suites and exercise one secret used in all four file formats and startup in the disposable UI. Expected simple setup with markers in originals, no automatic restart, and exact runtime values only after start.
- [ ] **Step 5:** Commit `feat: configure generic templates and startup secret bindings`.

### Task 14: Complete transfer, restore, cleanup, retention, and uninstall behavior

**Files:** Create plugin `src/Plans/ServerLifecycleService.php`, `src/Secrets/VersionRetentionService.php`, `src/Integration/GuardedPluginService.php`, `src/Console/Commands/PurgeSecretVersions.php`, `ReencryptSecretVersions.php`; `tests/php/Lifecycle/ServerLifecycleTest.php`, `PluginLifecycleTest.php`, `VersionRetentionTest.php`; overlay `secrets/reconcile.go`, `reconcile_test.go`; `node/patches/0004-secret-cleanup.patch` targeting `server/server.go`, `server/backup.go`, and `server/transfer/source.go`; `tests/acceptance/test_lifecycle.py`.

**Interfaces:** `ServerLifecycleService::invalidate(Server $server, string $reason): void`; `delete(Server $server): void`; `assertIntegrationCanDisable(): void`. `VersionRetentionService::purgeUnreferenced(User $actor, int $serverId): int` retains versions referenced by desired/applied records; re-encryption is a trusted host CLI operation using Laravel key-rotation support. `GuardedPluginService` subclasses the verified upstream PluginService and overrides `disablePlugin(string|Plugin $plugin): void` and `uninstallPlugin(Plugin $plugin, bool $deleteFiles = false): void` to check active bindings before destructive actions. Go `Reconcile(ctx context.Context, runtimeRoot string, liveContainers []ContainerIdentity) error` removes only orphan artifacts; define `ContainerIdentity{ServerUUID string, AttemptID string, ContainerID string, MountSources []string}` in `reconcile.go` using Docker-inspected running/starting containers, not an untrusted directory listing.

- [ ] **Step 1:** Add `test_restore_does_not_restore_credentials_or_grants`, `test_transfer_requires_compatible_destination`, `test_bound_plugin_cannot_disable_or_uninstall`, `test_purge_keeps_desired_and_applied_versions`, and `TestReconcileNeverDeletesLiveMounts`. Live tests cover source deletion/rename, changed egg/image, Panel outage, interrupted start, Wings restart, restore/transfer, and deletion cleanup. Assert a surviving game continues during Panel outage, while a new protected start fails.

  ```php
  $this->assertTrue($retainedVersions->contains($appliedVersion));
  $this->assertSame($grantsBeforeRestore, $grantsAfterRestore);
  ```
- [ ] **Step 2:** Run `./tools/dev php tests/php/Lifecycle`, `./tools/dev go ./secrets -run TestReconcile`, and `./tools/dev acceptance -k lifecycle`.
- [ ] **Step 3:** Wire lifecycle observers/guards without adding values to serialized jobs. Transfer references/templates only; restore leaves deleted secrets missing; egg/image/reinstall invalidates validation without install-script injection. Node reconciliation must inspect live-container use before deleting directories and recreate containers with lost tmpfs on next protected start. Server deletion removes versions/grants/bindings but preserves audit metadata; purge is explicit and root-only. Retain audit metadata for a configurable default of 90 days. Disabled/missing plugin endpoints must cause node start failure, never plaintext fallback. Hook supported lifecycle operations through the tested provider/service replacement; manual host deletion remains trusted-operator override.
- [ ] **Step 4:** Repeat suites and native upstream lifecycle/power regressions. Expected no orphan values copied into backups/transfers and no cleanup that breaks a live container. Inspect staged patches for unrelated upstream edits.
- [ ] **Step 5:** Commit `feat: secure secret lifecycle and node cleanup`.

### Task 15: Qualify and package the complete release

**Files:** Create `.github/workflows/verify.yml`, `tools/package.sh`, `tests/acceptance/test_release_matrix.py`, `docs/installation.md`, `docs/operations.md`, `docs/compatibility.md`, `README.md`; extend `tools/dev`, `docs/development.md`, and image/source locks with observed acceptance results. Produce ignored `dist/better-pelican-secrets-0.1.0.zip`, patched Wings binaries, SHA-256 sums, and a provenance manifest.

**Interfaces:** `./tools/dev check` runs PHP tests, PHP Pint checks, Go formatting/vet/tests, protocol parity, and document/package checks. `./tools/dev acceptance` runs all live cases without skips on the qualifying Linux runner. `./tools/dev package` rebuilds exact source pins plus checked-in overlays/patches and produces artifacts only after successful verification.

- [ ] **Step 1:** Write `test_release_acceptance_matrix` with the ten numbered live cases in spec Section 13 mapped to named tests, plus real ZIP import/install and a stock-node activation rejection. Add package checks asserting matching folder/manifest IDs, no install-state `meta`, no `.env`, keys, development databases, runtime files, or fixture values in artifacts. Test fresh install and upgrade/reinstall on the exact pinned Panel rather than only the manually booted PHPUnit provider.

  ```python
  self.assertEqual(set(exercised_spec_cases), set(range(1, 11)))
  self.assertEqual(skipped_live_cases, [])
  self.assertNotIn(b"fixture-secret", artifact_bytes)
  ```
- [ ] **Step 2:** Run `./tools/dev acceptance -k release_matrix`. Expected: assertions fail for missing release artifacts or incomplete matrix/provenance, with the existing live harness working; do not count an unavailable tool or skipped live test as the intended failure.
- [ ] **Step 3:** Implement CI/package commands and operations docs. Document required PHP/Go/Docker versions, Linux 5.6+ with working `openat2`, root-owned tmpfs, custom `/game` mapping, supported dotenv/YAML behavior, read-only runtime files, restart/outage trade-offs, grant semantics, APP_KEY backup/rotation, safe retirement/unbind flow, trusted runtime-code limit, literal-log-redaction limits, and the fact that preexisting plaintext copies require migration/rotation. Package code and reproducible node patches, not vendored credential-bearing environments. Record actual supported CPU architectures from successful builds; do not claim untested architectures or versions.
- [ ] **Step 4:** Run `./tools/dev check`, `./tools/dev acceptance`, and `./tools/dev package` on a clean materialization. Expected: every permission/rendering/lifecycle test green, all ten live acceptance cases exercised, upstream regression suites passing, deterministic artifact contents/provenance, and no product claim backed only by a mock. Review server/admin layouts in the running Panel, obtain a whole-change code review, and fix material findings before calling the release ready. Any failed mount/lifecycle acceptance test blocks shipping rather than enabling a Panel-only fallback.
- [ ] **Step 5:** Commit `release: qualify and package Pelican secrets integration`. Do not publish, deploy to production, or automatically merge as part of this task.

## Dependency order and implementation boundaries

Execute Task 1 first and stop on a failed feasibility gate. Then execute Tasks 2 through 15 in order; their Interfaces blocks define contracts consumed later. PHP and Go rendering may be implemented independently after Task 4 if the selected execution method allows it, but both must use the same fixtures and pass parity before Task 9. UI work consumes completed services; it must not implement alternate encryption, authorization, rendering, or revision logic.

Each task requires its focused red/green cycle. Re-run upstream/native regressions when changing their behavior and the complete qualification matrix at Task 15. Report unavailable Linux/Docker infrastructure as a verification blocker; installing tools or writing tests does not establish a passing live release.

## Coverage map for review

| Design requirement | Owning tasks |
| --- | --- |
| Explicit server/admin/per-secret permissions, delegation, root exception | 3, 12 |
| Encrypted immutable values, safe reveal, audit, deletion/retention/rotation | 2, 7, 12, 14 |
| Generic UTF-8 JSON/YAML/dotenv/text templates, escaping, preview | 4, 5, 6, 13 |
| Startup bindings without plaintext ordinary variables | 7, 8, 10, 13 |
| Reference-only configuration and authenticated node bundles | 4, 8 |
| Safe private runtime mounts, game UID and `/game` mapping | 1, 9, 10 |
| Pending/applied status, source changes, save/start races | 4, 7, 8, 10, 11 |
| Original-file SFTP/download/archive/backup behavior | 1, 11, 14, 15 |
| Restore, transfer, reinstall, deletion, daemon restart, uninstall | 14, 15 |
| No auto restart, no stale/empty/plaintext fallback, outage behavior | 7, 8, 9, 10, 14 |
| Native UI/API/plugin-write bypasses and real plugin installation | 1, 3, 7, 12, 13, 15 |
| Logging limits, developer runtime trust, deployment/compatibility docs | 10, 15 |

## Planning verification and handoff

The plan is a set of future implementation/check steps, not a claim that tests or compatibility experiments have passed. Planning inspected the pinned plugin stubs, plugin lifecycle/configuration services, power/container interfaces, source commit IDs, and dependency lockfiles. Every code path, bootstrap adapter, and compatibility claim above still needs its owning task's test.

After plan review, select an execution method. Recommended: **subagent-driven**, because the fifteen deliverables cross encryption, explicit authorization, a PHP/Go wire contract, and privileged container lifecycle behavior; independent review before each dependent task reduces the cost of carrying a mistake into later layers. Native execution remains available if preferred.
