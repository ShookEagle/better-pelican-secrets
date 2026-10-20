# Retain developer access and support generic configuration files

Supersedes ADR-0001. The user rejected restricting developers to approved configuration fields and console actions, accepting review of executable changes instead; the design must retain normal developer file/code access rather than enforce the earlier restriction on executable deployment. Secret listing, revealing, creating, updating, deleting, binding, and access management still require explicit grants for ordinary server owners and non-root administrators, with root administrators trusted.

The protection goal is to control direct secret access and avoid ordinary exposure through editable configuration and management interfaces. It cannot guarantee confidentiality from someone who can execute code in the secret-consuming server, redirect secret-bearing requests, or cause the application to disclose a value; reviewing changes provides detection rather than prevention.

File support is generic across text configuration formats, including JSON, YAML, and .env files, with no game-specific schema or initial JSON-only limit. Binding and escaping details remain to be designed. Panel/Wings v1.0.0-rc.1, server-owned secrets first, possible future shared secrets, and application on the next start without automatic restart remain the agreed scope.
