"""Existing Ubuntu/Debian systemd nodes only. Never unmount live secret storage."""
import argparse
from contextlib import contextmanager
import ctypes
from dataclasses import dataclass
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import platform
import re
import shlex
import signal
import ssl
import stat
import subprocess
import tempfile
import time
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, HTTPSHandler, Request, build_opener
import uuid

VERSION = "0.1.1"
BINARY = "wings-bps-0.1.1-linux-amd64"
RELEASE_URL = "https://github.com/ShookEagle/better-pelican-secrets/releases/download/v0.1.1/"
BACKUPS = "/var/backups/better-pelican-secrets"
MARKER = "# Managed by Better Pelican Secrets node installer\n"


class InstallError(Exception):
    pass


@dataclass(frozen=True)
class Release:
    binary_sha: str = "661072d6877720f9dcfd02f1cae5dfb8981bbeef7f5acf39b02a5cd4bac6edc5"
    provenance_sha: str = "7b59454a895da3a3e50b1179dd73a7a932e7ed1b2919c3838a79d127e1dc8b7d"


@dataclass
class Options:
    service: str = "wings.service"
    config: str | None = None
    tmpfs_size: str | None = None
    dry_run: bool = False
    panel_ca_file: str | None = None
    health_timeout: int = 45


@dataclass
class Node:
    service: str
    binary: str
    config: str
    runtime: str
    mount_unit: str
    server_count: int
    mounted: bool
    persistent_mount: bool


def sha(data):
    return hashlib.sha256(data).hexdigest()


def clean_path(value):
    if not isinstance(value, str) or not re.fullmatch(r"/[A-Za-z0-9_.\-/]+", value):
        raise InstallError("Unsupported path; use an absolute path without spaces or unit specifiers.")
    if value == "/" or str(PurePosixPath(value)) != value or ".." in PurePosixPath(value).parts:
        raise InstallError("Unsafe or non-canonical path.")
    return value


def validate_runtime_path(value, data, protected=()):
    clean_path(value)
    clean_path(data)
    runtime = PurePosixPath(value)
    resolved_runtime = Path(value).resolve()
    for name in (data, BACKUPS, *protected):
        clean_path(name)
        # Ordinary storage may contain a root-owned symlink. Compare actual paths
        # so a private mount cannot cover server data, recovery files or Wings.
        ordinary = Path(name).resolve()
        if resolved_runtime == ordinary or resolved_runtime.is_relative_to(ordinary) or ordinary.is_relative_to(resolved_runtime):
            raise InstallError("Private tmpfs overlaps ordinary storage or installation/recovery files.")
    if len(runtime.parts) < 3 or runtime.parts[1] not in ("run", "opt", "var"):
        raise InstallError("Private tmpfs must be a dedicated directory under /run, /opt or /var.")
    return value


def service_paths(value):
    matches = re.findall(r"(?:\{|\s)path=([^;]+?)\s*;", value)
    argv = re.findall(r"argv\[\]=(.*?)\s*;", value)
    if len(matches) != 1 or len(argv) != 1:
        raise InstallError("Wings must have one direct ExecStart, without a shell wrapper.")
    binary = clean_path(matches[0].strip())
    arguments = shlex.split(argv[0])
    if not arguments or arguments[0] != binary:
        raise InstallError("Unsupported Wings service command.")
    config = "/etc/pelican/config.yml"
    for index, argument in enumerate(arguments[1:], 1):
        if argument.startswith("--ignore-certificate-errors") and argument != "--ignore-certificate-errors=false":
            raise InstallError("Wings disables TLS verification; correct its service before installing.")
        if argument == "--config":
            if index + 1 >= len(arguments):
                raise InstallError("Missing Wings configuration path.")
            config = clean_path(arguments[index + 1])
        elif argument.startswith("--config="):
            config = clean_path(argument.split("=", 1)[1])
    return binary, config


def size_bytes(value):
    if not re.fullmatch(r"[1-9][0-9]*[KMG]", value):
        raise InstallError("Use a tmpfs size such as 256M or 1G.")
    result = int(value[:-1]) * {"K": 1024, "M": 1024**2, "G": 1024**3}[value[-1]]
    if result < 64 * 1024**2:
        raise InstallError("Use at least 64M for the private tmpfs.")
    return result


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise InstallError("Panel redirected the authenticated request; use its canonical HTTPS URL.")


class SecureRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        if urlsplit(newurl).scheme != "https":
            raise InstallError("Release download attempted an insecure redirect.")
        return super().redirect_request(request, fp, code, message, headers, newurl)


class Host:
    def path(self, name):
        return Path(name)

    def command(self, args, check=True):
        result = subprocess.run(args, capture_output=True, text=True, timeout=90)
        if check and result.returncode:
            # Never echo daemon output or the contents of its configuration.
            raise InstallError(args[0] + " operation failed; inspect the service locally.")
        return result.stdout.strip()

    def guard(self, name, directory=False):
        target = self.path(name)
        for candidate in [target, *target.parents]:
            if candidate.is_symlink():
                raise InstallError("Refusing a symbolic link in an installation path.")
            if not candidate.exists():
                continue
            info = candidate.stat()
            if info.st_uid != 0 or info.st_mode & 0o022:
                raise InstallError("Installation paths must be root-owned and not writable by other users.")
        if target.exists() and (not target.is_dir() if directory else not target.is_file()):
            raise InstallError("Unexpected installation path type.")

    def properties(self, service, names):
        raw = self.command(["systemctl", "show", service, *["--property=" + n for n in names]])
        return dict(line.split("=", 1) for line in raw.splitlines() if "=" in line)

    def inspect(self, options):
        if os.geteuid() != 0 or platform.system() != "Linux" or platform.machine() != "x86_64":
            raise InstallError("Run as root on an Ubuntu/Debian Linux amd64 node.")
        if not Path("/run/systemd/system").is_dir():
            raise InstallError("Wings must run directly under systemd, outside a container.")
        if not re.fullmatch(r"[A-Za-z0-9_.@-]+\.service", options.service):
            raise InstallError("Invalid systemd service name.")
        properties = self.properties(options.service, ["LoadState", "ExecStart", "User", "RootDirectory", "RootImage", "PrivateMounts", "Environment", "EnvironmentFiles"])
        if properties.get("LoadState") != "loaded" or properties.get("User", "") not in ("", "root", "0"):
            raise InstallError("An existing root-run Wings service is required.")
        if properties.get("RootDirectory") or properties.get("RootImage") or properties.get("PrivateMounts") == "yes":
            raise InstallError("Wings must share the host mount namespace with local Docker.")
        if properties.get("EnvironmentFiles"):
            raise InstallError("EnvironmentFile-based services require manual setup; no changes made.")
        environment = dict(item.split("=", 1) for item in shlex.split(properties.get("Environment", "")) if "=" in item)
        binary, config = service_paths(properties.get("ExecStart", ""))
        if options.config and clean_path(options.config) != config:
            raise InstallError("--config must match the configuration already selected by the Wings service.")
        self.guard(binary)
        self.guard(config)
        if not self.path(binary).is_file() or not self.path(config).is_file():
            raise InstallError("Existing Wings binary and configuration are required.")
        version = self.command([binary, "version"]).splitlines()[0]
        if version not in ("wings v1.0.0-rc.1", "wings v1.0.0-rc.1+bps.0.1.0", "wings v1.0.0-rc.1+bps.0.1.1"):
            raise InstallError("Existing Wings is not the supported 1.0.0-rc.1 base; refusing to downgrade it.")
        import yaml
        settings = yaml.safe_load(self.path(config).read_text())
        if not isinstance(settings, dict) or not isinstance(settings.get("system", {}), dict):
            raise InstallError("Invalid Wings configuration.")
        system = settings.get("system", {})
        if system.get("secrets_integration_required", True) is not True:
            raise InstallError("Secret integration is explicitly disabled in Wings configuration.")
        runtime = validate_runtime_path(system.get("secrets_runtime_directory", "/run/better-pelican-secrets"), system.get("data", "/var/lib/pelican/volumes"), (binary, config))
        self.guard(runtime, directory=True)
        self.guard(BACKUPS, directory=True)
        # Probe the actual required syscall, including kernels carrying backports.
        how = (ctypes.c_ulonglong * 3)(os.O_RDONLY | os.O_DIRECTORY | os.O_CLOEXEC, 0, 0)
        libc = ctypes.CDLL(None, use_errno=True)
        fd = libc.syscall(437, -100, b"/", ctypes.byref(how), ctypes.sizeof(how))
        if fd < 0:
            raise InstallError("The kernel must provide openat2 for private runtime paths.")
        os.close(fd)
        endpoint = environment.get("DOCKER_HOST", "unix:///var/run/docker.sock")
        if not endpoint.startswith("unix:///"):
            raise InstallError("Local rootful Unix Docker is required.")
        info_raw = self.command(["docker", "--host", endpoint, "info", "--format", "{{json .}}"])
        info = json.loads(info_raw)
        security = info.get("SecurityOptions", [])
        mode = settings.get("docker", {}).get("userns_mode", "")
        if info.get("OSType") != "linux" or any("rootless" in entry for entry in security) or (any("userns" in entry for entry in security) and mode != "host"):
            raise InstallError("Rootless/remapped Docker is outside the supported node configuration.")
        docker_root = info.get("DockerRootDir", "/var/lib/docker")
        validate_runtime_path(runtime, docker_root)
        if "/var/snap/docker" in docker_root:
            raise InstallError("Docker Snap is not supported by Wings.")
        count = self.panel_inventory(settings, environment, options.panel_ca_file)
        unit = self.command(["systemd-escape", "--path", "--suffix=mount", runtime])
        if not unit.endswith(".mount") or "/" in unit or "\n" in unit:
            raise InstallError("Unable to derive the private mount unit.")
        mounted = self.mount_record(runtime) is not None
        persistent = self.properties(unit, ["LoadState", "What", "Where", "Type", "Options", "FragmentPath"])
        manual = persistent.get("LoadState") == "loaded" and bool(persistent.get("FragmentPath"))
        if manual:
            if persistent.get("What") != "tmpfs" or persistent.get("Where") != runtime or persistent.get("Type") != "tmpfs":
                raise InstallError("An existing mount unit conflicts with private tmpfs setup.")
            self.safe_mount_options(persistent.get("Options", ""))
        node = Node(options.service, binary, config, runtime, unit, count, mounted, manual)
        if mounted:
            self.check_mount(node)
        elif self.path(runtime).exists() and any(self.path(runtime).iterdir()):
            raise InstallError("Refusing to mount over a nonempty directory.")
        return node

    def panel_inventory(self, settings, environment, ca_file):
        remote = settings.get("remote", "")
        parsed = urlsplit(remote)
        if parsed.scheme != "https" or not parsed.netloc or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise InstallError("The configured Panel must use a canonical verified HTTPS URL.")
        identifier = environment.get("WINGS_TOKEN_ID", settings.get("token_id", ""))
        token = environment.get("WINGS_TOKEN", settings.get("token", ""))
        if not all(isinstance(value, str) and value and not re.search(r"[\s$]", value) and not value.startswith("env:") for value in [identifier, token]):
            raise InstallError("Wings must have usable node credentials; no credentials are printed.")
        headers = dict(settings.get("remote_query", {}).get("custom_headers", {}))
        headers.update({"Authorization": "Bearer " + identifier + "." + token, "Accept": "application/json"})
        context = ssl.create_default_context(cafile=ca_file or environment.get("SSL_CERT_FILE"))
        opener = build_opener(NoRedirect(), HTTPSHandler(context=context))
        seen, page = set(), 1
        while True:
            url = remote.rstrip("/") + "/api/remote/servers?" + urlencode({"page": page, "per_page": 50})
            try:
                with opener.open(Request(url, headers=headers), timeout=15) as response:
                    raw = response.read(64 * 1024**2 + 1)
                if len(raw) > 64 * 1024**2:
                    raise InstallError("Panel server inventory exceeded the response limit.")
                payload = json.loads(raw)
                for server in payload["data"]:
                    plan = server["settings"].get("secrets", {})
                    if plan.get("protocol") != 1 or plan.get("server_uuid") != server["uuid"]:
                        raise InstallError("Install and enable the matching Panel plugin before updating this node.")
                    if server["uuid"] in seen:
                        raise InstallError("Panel inventory changed during discovery; retry the installer.")
                    seen.add(server["uuid"])
                last = int(payload["meta"]["last_page"])
                if not 1 <= last <= 1000:
                    raise InstallError("Unexpected Panel pagination.")
                if page >= last:
                    return len(seen)
                page += 1
            except InstallError:
                raise
            except Exception:
                raise InstallError("Could not verify Panel server inventory over HTTPS; no node changes made.") from None

    def fetch(self, name, maximum):
        opener = build_opener(SecureRedirect(), HTTPSHandler(context=ssl.create_default_context()))
        try:
            with opener.open(RELEASE_URL + name, timeout=60) as response:
                data = response.read(maximum + 1)
        except Exception:
            raise InstallError("Release download failed; no node changes made.") from None
        if len(data) > maximum:
            raise InstallError("Release download exceeded its size limit.")
        return data

    def mount_record(self, runtime):
        output = self.command(["findmnt", "--json", "--mountpoint", runtime, "--output", "TARGET,FSTYPE,OPTIONS"], check=False)
        if not output:
            return None
        records = json.loads(output).get("filesystems", [])
        return records[0] if len(records) == 1 else None

    def safe_mount_options(self, options):
        flags = set(options.split(","))
        if not {"rw", "noexec", "nosuid", "nodev"}.issubset(flags):
            raise InstallError("Existing tmpfs must include noexec,nosuid,nodev; it will not be remounted.")
        for flag in flags:
            if flag.startswith(("uid=", "gid=")) and flag.split("=", 1)[1] != "0":
                raise InstallError("Private tmpfs must belong to root.")
        if not any(flag in flags for flag in ["mode=700", "mode=0700"]):
            raise InstallError("Persistent private tmpfs must specify mode=0700.")

    def check_mount(self, node):
        record = self.mount_record(node.runtime)
        info = self.path(node.runtime).stat()
        if not record or record["fstype"] != "tmpfs" or record["target"] != node.runtime or info.st_uid != 0 or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != 0o700:
            raise InstallError("The private root must be a root-owned 0700 tmpfs.")
        flags = set(record["options"].split(","))
        if not {"rw", "noexec", "nosuid", "nodev"}.issubset(flags):
            raise InstallError("Private tmpfs mount flags are unsafe; refusing to remount it.")

    def mount_size(self, node):
        data = os.statvfs(self.path(node.runtime))
        return data.f_frsize * data.f_blocks

    def is_current(self, node, expected):
        properties = self.properties(node.service, ["ActiveState", "MainPID"])
        if properties.get("ActiveState") != "active":
            return False
        try:
            pid = int(properties["MainPID"])
            return pid > 0 and sha(Path("/proc", str(pid), "exe").read_bytes()) == expected
        except (KeyError, ValueError, OSError):
            return False

    def wait_healthy(self, node, expected, timeout):
        until, stable = time.monotonic() + timeout, 0
        while time.monotonic() < until:
            stable = stable + 1 if self.is_current(node, expected) else 0
            if stable >= 5:
                return
            time.sleep(1)
        raise InstallError("The new Wings process did not remain active.")

    @contextmanager
    def lock(self):
        import fcntl
        name = "/run/better-pelican-secrets-installer.lock"
        self.guard(name)
        fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        try:
            try:
                fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                raise InstallError("Another node installer is already running.") from None
            yield
        finally:
            os.close(fd)


class Installer:
    def __init__(self, host=None, release=None, output=print):
        self.host, self.release, self.output = host or Host(), release or Release(), output

    def write(self, name, data, mode):
        self.host.guard(name)
        target = self.host.path(name)
        target.parent.mkdir(parents=True, exist_ok=True)
        fd, temporary = tempfile.mkstemp(prefix=".bps-", dir=target.parent)
        try:
            with os.fdopen(fd, "wb") as stream:
                stream.write(data)
                stream.flush()
                os.fsync(stream.fileno())
            os.chmod(temporary, mode)
            os.replace(temporary, target)
            if os.name == "posix":
                directory_fd = os.open(target.parent, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

    def verified_payload(self):
        provenance = self.host.fetch("provenance.json", 1024**2)
        binary = self.host.fetch(BINARY, 128 * 1024**2)
        if sha(provenance) != self.release.provenance_sha or sha(binary) != self.release.binary_sha:
            raise InstallError("Release checksum verification failed; no node files changed.")
        try:
            record = json.loads(provenance)
            valid = record["version"] == VERSION and record["qualification"]["status"] == "qualified" and record["architectures"] == ["linux/amd64"] and record["artifacts"][BINARY] == self.release.binary_sha
        except (KeyError, ValueError, TypeError):
            valid = False
        if not valid or not binary.startswith(b"\x7fELF"):
            raise InstallError("The node binary is not a qualified matching release.")
        return binary

    def run(self, options):
        node = self.host.inspect(options)
        binary = self.verified_payload()
        size = size_bytes(options.tmpfs_size or "256M")
        if node.mounted:
            current_size = self.host.mount_size(node)
            if options.tmpfs_size and size != current_size:
                raise InstallError("Existing live tmpfs size differs; refusing to resize or unmount it.")
            size = current_size
        dropin = "/etc/systemd/system/" + node.service + ".d/90-better-pelican-secrets.conf"
        mount_path = "/etc/systemd/system/" + node.mount_unit
        files = {dropin: (MARKER + "[Unit]\nRequiresMountsFor=" + node.runtime + "\n").encode()}
        if not node.persistent_mount:
            files[mount_path] = (MARKER + "[Unit]\nDescription=Private runtime files for Better Pelican Secrets\n\n[Mount]\nWhat=tmpfs\nWhere=" + node.runtime + "\nType=tmpfs\nOptions=rw,noexec,nosuid,nodev,mode=0700,uid=0,gid=0,size=" + str(size) + "\n\n[Install]\nWantedBy=local-fs.target\n").encode()
        changes = {}
        for name, data in files.items():
            self.host.guard(name)
            target = self.host.path(name)
            if target.exists() and target.read_bytes() != data:
                raise InstallError("An existing installer unit/drop-in has different contents; no changes made.")
            if not target.exists():
                changes[name] = data
        original_binary = self.host.path(node.binary).read_bytes()
        original_config = self.host.path(node.config).read_bytes()
        self.output("Assigned servers discovered from Panel: " + str(node.server_count) + ".")
        self.output("Target: qualified Wings " + VERSION + "; private tmpfs: " + node.runtime + ".")
        if node.server_count == 0:
            self.output("No servers assigned yet; keep the Panel plugin enabled before adding servers.")
        if options.dry_run:
            self.output("Dry run passed. No node files, mounts or services changed.")
            return
        with self.host.lock():
            if self.host.path(node.binary).read_bytes() != original_binary or self.host.path(node.config).read_bytes() != original_config:
                raise InstallError("Wings changed during preflight; retry without concurrent maintenance.")
            if not changes and node.mounted and sha(original_binary) == self.release.binary_sha and self.host.is_current(node, self.release.binary_sha):
                self.output("Already installed and active; no restart needed.")
                return
            backup = BACKUPS + "/" + time.strftime("%Y%m%dT%H%M%SZ", time.gmtime()) + "-" + uuid.uuid4().hex[:12]
            self.host.guard(backup, directory=True)
            self.host.path(backup).mkdir(parents=True, mode=0o700)
            os.chmod(self.host.path(backup), 0o700)
            original_mode = stat.S_IMODE(self.host.path(node.binary).stat().st_mode)
            config_mode = stat.S_IMODE(self.host.path(node.config).stat().st_mode)
            self.write(backup + "/wings", original_binary, 0o600)
            self.write(backup + "/config.yml", original_config, 0o600)
            self.write(backup + "/manifest.json", json.dumps({"binary": node.binary, "config": node.config, "binary_sha256": sha(original_binary), "config_sha256": sha(original_config), "service": node.service, "runtime": node.runtime}, indent=2).encode(), 0o600)
            restart_attempted = False
            try:
                for name, data in changes.items():
                    self.write(name, data, 0o644)
                self.host.command(["systemctl", "daemon-reload"])
                if not node.persistent_mount:
                    self.host.command(["systemctl", "enable", node.mount_unit])
                if not node.mounted:
                    self.host.path(node.runtime).mkdir(parents=True, mode=0o700, exist_ok=True)
                    os.chmod(self.host.path(node.runtime), 0o700)
                    self.host.command(["systemctl", "start", node.mount_unit])
                self.host.check_mount(node)
                if sha(original_binary) != self.release.binary_sha:
                    self.write(node.binary, binary, 0o755)
                restart_attempted = True
                self.host.command(["systemctl", "restart", node.service])
                self.host.wait_healthy(node, self.release.binary_sha, options.health_timeout)
            except BaseException:
                stopped = False
                if restart_attempted:
                    try:
                        self.host.command(["systemctl", "stop", node.service])
                        stopped = True
                    except BaseException:
                        pass
                try:
                    self.write(node.binary, original_binary, original_mode)
                    self.write(node.config, original_config, config_mode)
                except BaseException:
                    raise InstallError("Installation failed and file recovery could not complete. Manual recovery required. Backup: " + backup) from None
                state = "Wings left stopped" if stopped else ("verify service state before recovery" if restart_attempted else "service was not restarted")
                raise InstallError("Installation failed; previous binary/config restored; " + state + ". Private tmpfs and its dependency are retained. Backup: " + backup) from None
            self.output("Installed and active. Backup: " + backup + ".")
            self.output("All node assignments reload through Wings. Create bindings and explicit grants in Panel.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--service", default="wings.service")
    parser.add_argument("--config")
    parser.add_argument("--tmpfs-size")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--panel-ca-file")
    parser.add_argument("--health-timeout", type=int, default=45)
    args = parser.parse_args()
    if not 5 <= args.health_timeout <= 300:
        parser.error("health timeout must be between 5 and 300 seconds")
    def interrupted(signum, frame):
        raise InstallError("Installer interrupted.")
    signal.signal(signal.SIGTERM, interrupted)
    try:
        Installer().run(Options(**vars(args)))
    except (Exception, KeyboardInterrupt) as error:
        print("Node setup failed: " + (str(error) if isinstance(error, InstallError) else "preflight or filesystem operation failed; no credentials printed"), file=__import__("sys").stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
