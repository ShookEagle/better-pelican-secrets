# Operations

## Grants and values

Owners and ordinary admins have no implicit secret authority. Root is the sole bypass. Grant list, reveal, create, update, delete, bind and manage-access independently at server/secret scope. Global admin actions need matching explicit global rights. Managers cannot delegate rights they lack in that scope.

Lists, previews, audits and ordinary responses contain metadata. Reveal is audited/no-store; its browser dialog clears on close, outside interaction, navigation or after 30 seconds. Blank replacement preserves the version; Set empty and Generate are explicit writes. Revocation applies to the next request and does not scrub a running game.

Values use Laravel APP_KEY encryption with immutable logical versions. Protect keys/database backups together. Configure the current APP_KEY and APP_PREVIOUS_KEYS through normal deployment, then run on the trusted Panel host:

```sh
php artisan secrets:reencrypt ROOT_ACTOR_ID SERVER_ID
php artisan secrets:purge-versions ROOT_ACTOR_ID SERVER_ID
```

Commands print fixed summaries/counts and never accept keys/values in arguments. Re-encryption preserves logical versions. Purge retains current values, desired refs, latest applied refs per non-retired node and unresolved newer issued attempts. Direct node reassignment retains old-node refs conservatively; use native transfer.

## Files and starts

Use UTF-8. JSON/YAML string tokens emit complete quoted scalars outside existing quotes. The picker replaces whole JSON string values and rejects keys. Dotenv accepts the tested quoted, non-interpolating subset; unsupported shell expressions fail. Raw mode inserts exact text without recursive expansion.

Runtime files are read-only. Applications needing in-place writes must write elsewhere or use another mechanism. Editing/SFTP work on templates. Source rename/deletion can make a single-file bind pathname unavailable in the game, while its process/open descriptors continue. Next start rejects missing/unapproved sources.

Saves remain pending. Normal start/restart fetches a fresh bundle and recreates Docker state. Protected containers use Docker restart policy no; Wings crash recovery prepares afresh. Running games can survive Panel/Wings outages. New protected starts fail on unavailable TLS, Panel/plugin, node capability, template or value. No stale, empty or plaintext fallback exists.

Backups/downloads/archives/transfers contain templates, excluding runtime values/grants. Restore cannot restore deleted credentials/permissions. Egg/image/reinstall changes invalidate desired state; install scripts receive no values. After daemon restart an old attempt's watcher waits for a new start instead of resetting its sequence.

Cleanup requires complete Docker inspection. Live mount sources, labels and in-flight preparations protect attempts; whole-root owners retain everything. Unsafe/missing tmpfs blocks protected starts while ordinary servers may remain available.

## Retirement and recovery

Remove bindings, normally start the new empty plan, and confirm its applied receipt before disable/uninstall/rollback. Unbinding alone cannot prove the old game stopped. Native integration management enforces this; manually removing host files is a trusted-operator override.

Interrupted setup blocks delivery. Retry setup to reconcile it. Root host recovery can retire an abandoned unregistered failure after reviewing its current ordinary source:

```sh
php artisan secrets:retire-setup ROOT_ACTOR_ID SERVER_ID FAILURE_UUID CURRENT_SOURCE_SHA256 --confirm
```

Use absent only for a missing source. Changed receipts/registered files fail. It does not edit bytes or retire applied bindings.

Audit metadata survives server deletion/migration rollback. BPS_AUDIT_RETENTION_DAYS defaults to 90; enabled plugins schedule secrets:prune-audit daily. After uninstall its scheduler stops; the operator must retain/prune the surviving audit table explicitly.

## Accepted limits

Host/root/Docker/Wings/Panel admins and game processes are trusted. Developers keep normal file, SFTP, console and executable-code permissions. Changed code can read a runtime value and transmit/encode it into logs/files. Code review is an operational detection control, not process isolation.

Raw Docker logging is disabled. Controlled startup/live output gets streaming literal redaction and bounded in-memory history. This is defense in depth: encoded/transformed or application-written logs can expose values. Reattached Wings lacks the previous redaction inventory until the next protected start.

Migration cannot rewrite preexisting plaintext defaults, copies, logs or backups. Review and rotate exposed credentials. Shared multi-server secrets remain deferred.

The paired Wings adapter requires plugin reference metadata even for an empty plan. A fresh daemon therefore fails closed if the plugin disappears, rather than mistaking a server for unprotected. After all empty-plan retirements are confirmed, a trusted host operator can switch back to stock Wings or explicitly set system.secrets_integration_required: false before running without the plugin. This YAML-only opt-out is an operator boundary; do not use it to work around a failed protected start.
