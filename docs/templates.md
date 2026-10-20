# File templates

Register an editable UTF-8 source file, select its output format and default mode, and approve the secret references. Source files contain placeholders. Rendered runtime copies are separate and apply on the next normal start or restart.

With json-string mode:

```json
{"apiKey": {{secret:API_KEY}}}
```

With yaml-string mode:

```yaml
apiKey: {{secret:API_KEY}}
```

With dotenv mode:

```dotenv
API_KEY={{secret:API_KEY}}
```

These modes insert complete quoted values, so the token has no outer quotes. text format with raw mode inserts literal bytes. An individual token can select another mode, for example {{secret:API_KEY|json-string}}. Inserted values are never scanned again.

{{!secret:API_KEY}} emits the literal text {{secret:API_KEY}}. Names are case-sensitive uppercase identifiers. Unknown/malformed tokens, missing values, changed modes, and changed reference counts fail validation. A file's approved manifest pins IDs, names, modes, and occurrence counts. Normal file edits can change surrounding text; reference changes require a new binding approval.

Preview uses the deterministic value DUMMY_VALUE and never reads a stored value. It validates syntax with dummy data, not the actual credential. In particular, raw insertion still needs the real output to pass validation at start.

## Format rules

JSON is parsed as a whole document. YAML uses the pinned Symfony parser with aliases disabled; the Go renderer must match its accepted fixtures. Inline merge data without aliases remains subject to both whole-document validators. JSON/YAML nesting is limited to 64 levels. YAML string emission escapes control code points that cannot be written literally.

The dotenv dialect is a bounded subset of locked Laravel/phpdotenv behavior: one physical line per assignment, ASCII names made of letters, digits, underscore, and dot, optional export, blank lines, and comments. Single-quoted, double-quoted, and ordinary unquoted values are supported. Double-quoted escapes are backslash, quote, dollar sign, n, r, t, f, and v. Secret dollar signs are escaped to prevent interpolation; multiline secret values use escaped newline/carriage-return sequences. Physical multiline quoted values, bare-CR line endings, unsupported controls/escapes, quoted or non-ASCII variable names, and bare names without equals are rejected. Select text format for another dotenv/shell dialect and choose the appropriate explicit token mode.

CRLF outside tokens is preserved. An initial UTF-8 BOM is preserved only in text format and rejected for structured formats. Invalid UTF-8 and NUL are rejected.

The shared fixture file protocol/fixtures/rendering.json specifies exact bytes and error codes for both languages. source_base64 and values_base64 express invalid UTF-8 test inputs without corrupting the JSON fixture itself. Rendering enforces source, reference, value, and expanded-output budgets; escaping and repeated references count toward output size.