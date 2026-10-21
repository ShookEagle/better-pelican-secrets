"""Destructive host integration: run only on a fresh GitHub-hosted Ubuntu VM.

This uses real systemd, tmpfs, rootful Docker and the pinned published Wings
binary. The HTTPS Panel is an authenticated zero-server protocol fixture.
"""
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import ipaddress
import json
import os
from pathlib import Path
import platform
import shutil
import socket
import ssl
import stat
import subprocess
import sys
import threading
import time
import unittest
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import urlopen
import uuid

import yaml

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "tools"))
import node_installer as installer


def require_disposable_runner():
    """Fail closed before creating any host resources, including under unittest."""
    if (platform.system() != "Linux" or not hasattr(os, "geteuid") or os.geteuid() != 0
            or os.environ.get("GITHUB_ACTIONS") != "true"
            or os.environ.get("RUNNER_ENVIRONMENT") != "github-hosted"):
        raise RuntimeError("Host integration requires root on a disposable GitHub-hosted runner.")
    release = dict(line.split("=", 1) for line in Path("/etc/os-release").read_text().splitlines() if "=" in line)
    if release.get("ID", "").strip('"') != "ubuntu" or release.get("VERSION_ID", "").strip('"') != "24.04":
        raise RuntimeError("Host integration requires a fresh Ubuntu 24.04 runner.")
    if Path("/proc/1/comm").read_text().strip() != "systemd":
        raise RuntimeError("Host integration requires host systemd as PID 1.")


def command(*args, check=True, timeout=180):
    result = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
    if check and result.returncode:
        raise AssertionError(f"{args[0]} failed ({result.returncode}): {result.stderr[-2000:]}")
    return result


def digest(path):
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def port():
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        return sock.getsockname()[1]


class SystemdInstallerTest(unittest.TestCase):
    def setUp(self):
        require_disposable_runner()
        self.tag = "bps-installer-ci-" + uuid.uuid4().hex[:12]
        self.directory = Path("/opt") / self.tag
        self.runtime = Path("/run") / self.tag
        self.service = self.tag + ".service"
        self.unit = Path("/etc/systemd/system") / self.service
        self.dropins = Path(str(self.unit) + ".d")
        self.mount = command("systemd-escape", "--path", "--suffix=mount", str(self.runtime)).stdout.strip()
        self.mount_file = Path("/etc/systemd/system") / self.mount
        self.network = self.tag
        self.network_id = None
        self.server = None
        self.server_thread = None
        self.token_id, self.token = uuid.uuid4().hex[:16], uuid.uuid4().hex * 2
        self.binary = self.directory / "wings"
        self.config = self.directory / "config.yml"
        self.ca = self.directory / "ca.crt"
        self.baseline = b'#!/bin/sh\nif [ "$1" = version ]; then echo "wings v1.0.0-rc.1"; else exec /usr/bin/sleep infinity; fi\n'
        self.backups_before = set(Path(installer.BACKUPS).glob("*"))
        # Stage ownership before mutations so partial setup is also cleaned up.
        for path in (self.directory, self.runtime, self.unit, self.dropins, self.mount_file):
            self.assertFalse(path.exists(), f"Fixture path already exists: {path}")
        self.assertNotEqual(command("docker", "network", "inspect", self.network, check=False).returncode, 0)
        self.addCleanup(self.cleanup_fixture)
        self.directory.mkdir(mode=0o700)
        self.binary.write_bytes(self.baseline)
        self.binary.chmod(0o755)
        self.create_certificates()
        self.start_panel()
        api_port, sftp_port = port(), port()
        while sftp_port == api_port:
            sftp_port = port()
        self.api_port = api_port
        subnet = self.free_subnet()
        gateway = str(subnet.network_address + 1)
        settings = {
            "uuid": str(uuid.uuid4()), "token_id": self.token_id, "token": self.token,
            "remote": f"https://localhost:{self.server.server_port}",
            "api": {"host": "127.0.0.1", "port": api_port, "ssl": {
                "enabled": True, "cert": str(self.directory / "tls.crt"), "key": str(self.directory / "tls.key")}},
            "system": {
                "root_directory": str(self.directory / "data"), "data": str(self.directory / "data/volumes"),
                "log_directory": str(self.directory / "logs"), "tmp_directory": str(self.directory / "tmp"),
                "archive_directory": str(self.directory / "archives"), "backup_directory": str(self.directory / "backups"),
                "secrets_runtime_directory": str(self.runtime), "secrets_integration_required": True,
                "username": "root", "timezone": "UTC", "enable_log_rotate": False,
                "user": {"uid": 0, "gid": 0, "passwd": {"directory": str(self.directory / "passwd")}},
                "machine_id": {"directory": str(self.directory / "machine-id")},
                "sftp": {"bind_address": "127.0.0.1", "bind_port": sftp_port}},
            "docker": {"network": {"name": self.network, "network_mode": self.network,
                "interface": gateway, "IPv6": False, "interfaces": {"v4": {"subnet": str(subnet), "gateway": gateway}}}}}
        self.original_settings = settings
        self.config.write_text(yaml.safe_dump(settings))
        self.config.chmod(0o600)
        self.unit.write_text("[Unit]\nDescription=Disposable node installer CI fixture\n[Service]\n"
                             f"Type=simple\nUser=root\nExecStart={self.binary} --config {self.config}\n"
                             f"Environment=SSL_CERT_FILE={self.ca}\nRestart=no\nTimeoutStopSec=10\n")
        self.unit.chmod(0o644)
        command("systemctl", "daemon-reload")
        command("systemctl", "start", self.service)
        self.assertGreater(self.pid(), 0)

    def create_certificates(self):
        command("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                "-subj", "/CN=BPS disposable CI CA", "-keyout", str(self.directory / "ca.key"), "-out", str(self.ca))
        command("openssl", "req", "-newkey", "rsa:2048", "-nodes", "-subj", "/CN=localhost",
                "-keyout", str(self.directory / "tls.key"), "-out", str(self.directory / "tls.csr"))
        extension = self.directory / "extensions"
        extension.write_text("subjectAltName=DNS:localhost,IP:127.0.0.1\nextendedKeyUsage=serverAuth\n")
        command("openssl", "x509", "-req", "-days", "1", "-in", str(self.directory / "tls.csr"),
                "-CA", str(self.ca), "-CAkey", str(self.directory / "ca.key"), "-CAcreateserial",
                "-extfile", str(extension), "-out", str(self.directory / "tls.crt"))
        for key in self.directory.glob("*.key"):
            key.chmod(0o600)

    def start_panel(self):
        authorization = "Bearer " + self.token_id + "." + self.token
        self.requests = []
        requests = self.requests

        class Panel(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def dispatch(self):
                path = urlsplit(self.path).path
                if self.headers.get("Authorization") != authorization:
                    self.send_error(401)
                    return
                requests.append((self.command, path))
                if self.command == "GET" and path == "/api/remote/servers":
                    data = json.dumps({"data": [], "meta": {"current_page": 1, "last_page": 1,
                        "per_page": 50, "total": 0, "from": 0, "to": 0}}).encode()
                    self.send_response(200)
                    self.send_header("Content-Type", "application/json")
                    self.send_header("Content-Length", str(len(data)))
                    self.end_headers()
                    self.wfile.write(data)
                elif self.command == "POST" and path == "/api/remote/servers/reset":
                    self.send_response(204)
                    self.end_headers()
                else:
                    self.send_error(404)

            do_GET = dispatch
            do_POST = dispatch

        self.server = ThreadingHTTPServer(("127.0.0.1", 0), Panel)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(self.directory / "tls.crt", self.directory / "tls.key")
        self.server.socket = context.wrap_socket(self.server.socket, server_side=True)
        self.server_thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.server_thread.start()

    def free_subnet(self):
        identifiers = command("docker", "network", "ls", "--quiet").stdout.split()
        occupied = []
        if identifiers:
            for network in json.loads(command("docker", "network", "inspect", *identifiers).stdout):
                for config in (network.get("IPAM") or {}).get("Config") or []:
                    if config.get("Subnet"):
                        occupied.append(ipaddress.ip_network(config["Subnet"]))
        for route in json.loads(command("ip", "-json", "route", "show").stdout):
            if route.get("dst", "default") != "default":
                occupied.append(ipaddress.ip_network(route["dst"], strict=False))
        for index in range(256):
            candidate = ipaddress.ip_network(f"10.233.{index}.0/24")
            if not any(other.version == 4 and candidate.overlaps(other) for other in occupied):
                return candidate
        self.fail("No unused fixture Docker subnet")

    def pid(self):
        return int(command("systemctl", "show", self.service, "--property=MainPID", "--value").stdout.strip())

    def run_installer(self, *arguments, success=True):
        result = command("bash", str(ROOT / "install-node.sh"), "--service", self.service,
                         "--config", str(self.config), "--panel-ca-file", str(self.ca),
                         "--health-timeout", "15", *arguments, check=False, timeout=300)
        combined = result.stdout + result.stderr
        self.assertNotIn(self.token, combined, "Installer printed node credentials")
        print(combined.strip(), flush=True)
        self.assertEqual(result.returncode == 0, success, "Unexpected installer result")
        return combined

    def assert_mount(self):
        record = json.loads(command("findmnt", "--json", "--mountpoint", str(self.runtime),
                                    "--output", "TARGET,FSTYPE,OPTIONS").stdout)["filesystems"][0]
        self.assertEqual(record["target"], str(self.runtime))
        self.assertEqual(record["fstype"], "tmpfs")
        self.assertTrue({"rw", "noexec", "nosuid", "nodev"}.issubset(record["options"].split(",")))
        info = self.runtime.stat()
        self.assertEqual((info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)), (0, 0, 0o700))

    def assert_api(self):
        context = ssl.create_default_context(cafile=str(self.ca))
        deadline = time.monotonic() + 30
        while time.monotonic() < deadline:
            try:
                with urlopen(f"https://localhost:{self.api_port}/api/system", context=context, timeout=2):
                    self.fail("Unauthenticated Wings API request unexpectedly succeeded")
            except HTTPError as error:
                self.assertIn(error.code, (401, 403))
                return
            except (URLError, TimeoutError):
                time.sleep(0.5)
        self.fail("Actual Wings HTTPS API did not become ready")

    def assert_backups(self):
        created = set(Path(installer.BACKUPS).glob("*")) - self.backups_before
        self.assertGreaterEqual(len(created), 2)
        for backup in created:
            manifest = json.loads((backup / "manifest.json").read_text())
            self.assertEqual(manifest["service"], self.service)
            self.assertEqual(manifest["binary"], str(self.binary))
            self.assertEqual(manifest["config"], str(self.config))
            self.assertEqual(backup.stat().st_uid, 0)
            self.assertEqual(stat.S_IMODE(backup.stat().st_mode), 0o700)
            for path in backup.iterdir():
                self.assertEqual(path.stat().st_uid, 0)
                self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o600)
                self.assertFalse(path.is_symlink())

    def test_install_idempotence_and_failed_restart_recovery(self):
        print("Real host: dry run preserves files, mount state and service PID", flush=True)
        original_pid = self.pid()
        original_config = self.config.read_bytes()
        unit_contents = self.unit.read_bytes()
        self.run_installer("--dry-run")
        self.assertEqual(self.pid(), original_pid)
        self.assertEqual(self.binary.read_bytes(), self.baseline)
        self.assertEqual(self.config.read_bytes(), original_config)
        self.assertEqual(self.unit.read_bytes(), unit_contents)
        self.assertFalse(self.dropins.exists())
        self.assertFalse(self.mount_file.exists())
        self.assertNotEqual(command("findmnt", "--mountpoint", str(self.runtime), check=False).returncode, 0)
        self.assertEqual(set(Path(installer.BACKUPS).glob("*")), self.backups_before)

        print("Real host: install published binary and verify HTTPS readiness", flush=True)
        self.run_installer()
        self.assertEqual(digest(self.binary), installer.Release().binary_sha)
        pid = self.pid()
        self.assertGreater(pid, 0)
        self.assertEqual(digest(Path("/proc") / str(pid) / "exe"), installer.Release().binary_sha)
        self.assert_mount()
        self.assertEqual(command("systemctl", "is-enabled", self.mount).stdout.strip(), "enabled")
        self.assertIn(str(self.runtime), command("systemctl", "show", self.service, "--property=RequiresMountsFor").stdout)
        self.assertIn("RequiresMountsFor=" + str(self.runtime),
                      (self.dropins / "90-better-pelican-secrets.conf").read_text())
        self.assert_api()
        self.assertIn(("POST", "/api/remote/servers/reset"), self.requests)
        current = yaml.safe_load(self.config.read_text())
        for field in ("uuid", "token_id", "token", "remote"):
            self.assertEqual(current[field], self.original_settings[field])
        self.assertEqual(current["system"]["secrets_runtime_directory"], str(self.runtime))
        self.assertEqual(current["docker"]["network"]["name"], self.network)
        network = json.loads(command("docker", "network", "inspect", self.network).stdout)[0]
        self.network_id = network["Id"]
        self.assertEqual(network["Name"], self.network)

        print("Real host: repeat install keeps process and private tmpfs contents", flush=True)
        marker = self.runtime / "private-marker"
        marker.write_bytes(os.urandom(32))
        marker.chmod(0o600)
        marker_contents = marker.read_bytes()
        current_config = self.config.read_bytes()
        self.assertIn("no restart needed", self.run_installer())
        self.assertEqual(self.pid(), pid)
        self.assertEqual(self.config.read_bytes(), current_config)
        self.assertEqual(marker.read_bytes(), marker_contents)
        self.assert_mount()

        print("Real host: failed restart restores files and retains mounted private storage", flush=True)
        failure = self.dropins / "50-ci-failure.conf"
        failure.write_text("[Service]\nExecStartPre=/usr/bin/false\n")
        failure.chmod(0o644)
        command("systemctl", "daemon-reload")
        # Atomic replacement leaves the running real Wings inode untouched.
        replacement = self.directory / "original-wings"
        replacement.write_bytes(self.baseline)
        replacement.chmod(0o755)
        replacement.replace(self.binary)
        self.assertEqual(self.pid(), pid)
        rollback_config = self.config.read_bytes()
        output = self.run_installer(success=False)
        self.assertIn("previous binary/config restored", output)
        self.assertEqual(self.binary.read_bytes(), self.baseline)
        self.assertEqual(self.config.read_bytes(), rollback_config)
        self.assertEqual(stat.S_IMODE(self.binary.stat().st_mode), 0o755)
        self.assertEqual(stat.S_IMODE(self.config.stat().st_mode), 0o600)
        self.assertEqual(self.pid(), 0)
        state = command("systemctl", "show", self.service, "--property=ActiveState", "--value").stdout.strip()
        self.assertIn(state, ("inactive", "failed"))
        self.assert_mount()
        self.assertEqual(marker.read_bytes(), marker_contents)
        self.assertTrue(self.mount_file.is_file())
        self.assert_backups()
        print("Real host: all installer lifecycle assertions passed", flush=True)

    def cleanup_fixture(self):
        # Every resource is checked against its staged exact fixture identity.
        self.assertTrue(self.tag.startswith("bps-installer-ci-"))
        self.assertEqual(self.directory, Path("/opt") / self.tag)
        self.assertEqual(self.runtime, Path("/run") / self.tag)
        self.assertEqual(self.unit, Path("/etc/systemd/system") / (self.tag + ".service"))
        if self.unit.exists():
            diagnostics = command("journalctl", "--unit", self.service, "--no-pager", "-n", "35", check=False).stdout
            print(diagnostics.replace(self.token, "[redacted]").replace(self.token_id, "[redacted]"), flush=True)
            command("systemctl", "stop", self.service)
            self.assertEqual(self.pid(), 0, "Refusing to unmount while the fixture service runs")
        try:
            if self.mount_file.exists():
                self.assertIn("Where=" + str(self.runtime), self.mount_file.read_text())
                command("systemctl", "disable", "--now", self.mount)
                self.mount_file.unlink()
            # Never recurse into a live mount, even if systemd cleanup failed.
            self.assertNotEqual(command("findmnt", "--mountpoint", str(self.runtime), check=False).returncode, 0)
            if self.runtime.exists():
                self.assertFalse(self.runtime.is_symlink())
                shutil.rmtree(self.runtime)
            if self.dropins.exists():
                self.assertFalse(self.dropins.is_symlink())
                shutil.rmtree(self.dropins)
            self.unit.unlink(missing_ok=True)
            command("systemctl", "daemon-reload")
            command("systemctl", "reset-failed", self.service, check=False)
            network_result = command("docker", "network", "inspect", self.network, check=False)
            if network_result.returncode == 0:
                network = json.loads(network_result.stdout)[0]
                self.assertEqual(network["Name"], self.tag)
                if self.network_id is not None:
                    self.assertEqual(network["Id"], self.network_id)
                command("docker", "network", "rm", network["Id"])
            # Only delete new backups whose manifest proves fixture ownership.
            for backup in set(Path(installer.BACKUPS).glob("*")) - self.backups_before:
                self.assertFalse(backup.is_symlink())
                self.assertEqual(backup.parent, Path(installer.BACKUPS))
                manifest = json.loads((backup / "manifest.json").read_text())
                self.assertEqual((manifest["service"], manifest["binary"], manifest["config"]),
                                 (self.service, str(self.binary), str(self.config)))
                shutil.rmtree(backup)
        finally:
            if self.server is not None:
                self.server.shutdown()
                self.server.server_close()
            if self.server_thread is not None:
                self.server_thread.join(timeout=5)
            if self.directory.exists():
                self.assertFalse(self.directory.is_symlink())
                shutil.rmtree(self.directory)


if __name__ == "__main__":
    require_disposable_runner()
    unittest.main(verbosity=2)
