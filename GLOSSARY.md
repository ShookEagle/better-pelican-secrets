# Pelican Server Secrets

Confidential values used by game servers, with explicit control over who can manage and reveal them.

## Language

**Secret**:
A named confidential value, such as an RCON password or an API key, used by a game server.
_Avoid_: Ordinary startup variable, public configuration value

**Server-owned secret**:
A secret belonging to one server and available for use by that server.
_Avoid_: Global secret

**Shared secret**:
A secret intended for use by multiple explicitly selected servers. Shared secrets are a future capability beyond the initial server-owned scope.
_Avoid_: Server-owned secret

**Secret permission**:
An explicitly granted right to perform a particular operation on secrets within a server or on an individual secret. Operations distinguish listing names, revealing values, creating, updating, deleting, assigning uses, and managing access.
_Avoid_: General developer access, automatic access

**Reveal**:
Access to the actual confidential value of a secret, distinct from seeing its name or assigning it for use by a server.
_Avoid_: List, use

**Secret binding**:
An assignment of a secret to a server startup variable or a placeholder in a server configuration file, regardless of its text format.
_Avoid_: Secret value

**Protected field**:
A configuration value supplied by a secret binding and represented by a placeholder in the editable file. It is not limited to a JSON field or a game-specific configuration schema.
_Avoid_: Public configuration value

**Secret access grant**:
An explicit assignment of secret permissions to a user. Server ownership and ordinary administrator status do not themselves confer secret permissions; root administrators are the trusted exception.
_Avoid_: Server ownership, developer role

**File template**:
An editable text configuration file containing secret references in place of confidential values. It can use JSON, YAML, dotenv, or another text format.
_Avoid_: Rendered file

**Rendered file**:
A runtime copy of a file template with its approved secret references replaced by actual values for the game to consume.
_Avoid_: File template, source file

**Desired revision**:
The saved secret versions and bindings intended for a server's next start. It can differ from the configuration currently used by the running game.
_Avoid_: Applied revision

**Applied revision**:
The saved configuration revision delivered successfully during a particular server start. It records delivery, rather than guaranteeing that the game accepted every setting.
_Avoid_: Desired revision
