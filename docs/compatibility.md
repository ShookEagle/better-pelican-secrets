# Compatibility

| Component | Target |
| --- | --- |
| Panel | v1.0.0-rc.1 / 46fc1cf0ebce3f43a5dd38bb2c4a89ad0f668e00 |
| Wings base | v1.0.0-rc.1 / 7b489da7b8196d0b4baef3e2db062c06d743e2f9 |
| Node protocol | 1, checked-in schema/fixtures |
| Architecture | Linux amd64 |
| Development PHP / Go | 8.4.26 / 1.26.0 |
| Development engine | Linux Docker 29.9.1 in Docker Desktop/WSL2 |
| Host profile | rootful local Unix Docker, identity mapping, private root-owned tmpfs, openat2 |

Future Pelican versions are not implicitly qualified. Capability probes require matching identities/verified HTTPS; stock/mixed nodes cannot activate bindings. No Panel-only fallback exists.

Candidate builds do not establish all ten live cases; the provenance qualification status is authoritative. The harness must record successful current-source evidence before tools/dev package reproduces qualified artifacts.

Docker Desktop runs the Linux tests in its VM; it does not establish a Windows Wings daemon. Remote daemons, rootless/remapped identities, unsupported mounts, binary/NUL templates, guessed schemas and untested CPU architectures are excluded.

The native acceptance stack uses SQLite. No MariaDB/PostgreSQL qualification is claimed by these results. Test deployments should use the exact documented pair and run their own non-production database/host validation before rollout.
