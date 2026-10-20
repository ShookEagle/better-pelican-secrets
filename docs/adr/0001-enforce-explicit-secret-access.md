---
status: superseded by ADR-0002
---

# Enforce explicit secret access across server operations

Secrets must be protected from users without explicit secret permissions, including ordinary server owners and non-root administrators; root administrators are trusted and have automatic authority. Because game code can extract values consumed by its own process, users without the required secret access must be prevented from deploying arbitrary executable game plugins and changing startup commands rather than receiving an impossible confidentiality guarantee while retaining those powers. Developers may edit non-secret fields in a protected JSON file through a view that shows placeholders and preserves protected fields; the implementation must account for alternative file, archive, backup, and runtime access paths before claiming this boundary is enforced.
