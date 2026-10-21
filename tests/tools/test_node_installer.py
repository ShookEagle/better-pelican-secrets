"""Installer outcomes: verified payloads, preserved state and repeatable setup."""
import hashlib
from io import BytesIO
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
import node_installer as installer


def digest(data):
    return hashlib.sha256(data).hexdigest()


class FixtureHost(installer.Host):
    def __init__(self, root):
        self.root = root
        self.calls = []
        self.mounted = False
        self.healthy = True
        self.current = False
        self.assigned = 3
        self.config = b"token: must-stay-private\nremote: https://panel.example\n"
        self.payload = b"\x7fELFqualified-wings"
        provenance = {"version": "0.1.1", "qualification": {"status": "qualified"},
                      "architectures": ["linux/amd64"], "artifacts": {installer.BINARY: digest(self.payload)}}
        self.provenance = json.dumps(provenance).encode()
        self.release = installer.Release(digest(self.payload), digest(self.provenance))
        self.path("/usr/local/bin").mkdir(parents=True)
        self.path("/usr/local/bin/wings").write_bytes(b"original-wings")
        self.path("/etc/pelican").mkdir(parents=True)
        self.path("/etc/pelican/config.yml").write_bytes(self.config)

    def path(self, name):
        return self.root / str(name).lstrip("/").replace("\\", "~")

    def inspect(self, options):
        return installer.Node(options.service, "/usr/local/bin/wings", "/etc/pelican/config.yml",
                              "/run/better-pelican-secrets", "run-better\\x2dpelican\\x2dsecrets.mount",
                              self.assigned, self.mounted, False)

    def fetch(self, name, maximum):
        return self.payload if name == installer.BINARY else self.provenance

    def guard(self, name, directory=False):
        pass  # Platform ownership is exercised on the real Linux integration runner.

    def check_mount(self, node):
        if not self.mounted:
            raise installer.InstallError("private tmpfs unavailable")

    def mount_size(self, node):
        return 268435456

    def command(self, args, check=True):
        self.calls.append(args)
        if args[:2] == ["systemctl", "start"] and args[2].endswith(".mount"):
            self.mounted = True
        if args[:2] == ["systemctl", "restart"]:
            self.current = self.healthy
            if not self.healthy:
                self.path("/etc/pelican/config.yml").write_bytes(b"daemon-rewrote-config")
                raise installer.InstallError("service restart failed")
        return ""

    def is_current(self, node, expected):
        return self.current

    def wait_healthy(self, node, expected, timeout):
        if not self.healthy:
            raise installer.InstallError("service unhealthy")

    def lock(self):
        from contextlib import nullcontext
        return nullcontext()


class NodeInstallerTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.host = FixtureHost(Path(self.temporary.name))
        self.messages = []

    def run_install(self, **options):
        return installer.Installer(self.host, self.host.release, self.messages.append).run(installer.Options(**options))

    def test_installs_verified_binary_and_persistent_mount_without_rewriting_credentials(self):
        self.run_install()
        self.assertEqual(self.host.payload, self.host.path("/usr/local/bin/wings").read_bytes())
        self.assertEqual(self.host.config, self.host.path("/etc/pelican/config.yml").read_bytes())
        dropin = self.host.path("/etc/systemd/system/wings.service.d/90-better-pelican-secrets.conf").read_text()
        self.assertIn("RequiresMountsFor=/run/better-pelican-secrets", dropin)
        unit = next(self.host.path("/etc/systemd/system").glob("*.mount")).read_text()
        self.assertIn("mode=0700", unit)
        self.assertIn("noexec,nosuid,nodev", unit)
        self.assertIn(["systemctl", "restart", "wings.service"], self.host.calls)
        self.assertNotIn("must-stay-private", "\n".join(self.messages))
        backups = list(self.host.path(installer.BACKUPS).glob("*/wings"))
        self.assertEqual(1, len(backups))
        self.assertEqual(b"original-wings", backups[0].read_bytes())

    def test_second_install_preserves_live_mount_and_does_not_restart(self):
        self.run_install()
        self.host.calls.clear()
        self.run_install()
        self.assertFalse(any(call[:2] in (["systemctl", "restart"], ["systemctl", "stop"]) for call in self.host.calls))
        self.assertFalse(any("umount" in call for call in self.host.calls))

    def test_bad_checksum_leaves_binary_configuration_and_mount_unchanged(self):
        self.host.payload += b"corrupt"
        with self.assertRaisesRegex(installer.InstallError, "checksum"):
            self.run_install()
        self.assertEqual(b"original-wings", self.host.path("/usr/local/bin/wings").read_bytes())
        self.assertFalse(self.host.mounted)
        self.assertEqual([], self.host.calls)

    def test_unqualified_provenance_is_rejected_even_when_its_hash_is_pinned(self):
        record = json.loads(self.host.provenance)
        record["qualification"]["status"] = "candidate"
        self.host.provenance = json.dumps(record).encode()
        self.host.release = installer.Release(digest(self.host.payload), digest(self.host.provenance))
        with self.assertRaisesRegex(installer.InstallError, "qualified"):
            self.run_install()
        self.assertEqual([], self.host.calls)

    def test_dry_run_does_not_create_backups_units_or_restart(self):
        before = {p.relative_to(self.host.root): p.read_bytes() for p in self.host.root.rglob("*") if p.is_file()}
        self.run_install(dry_run=True)
        after = {p.relative_to(self.host.root): p.read_bytes() for p in self.host.root.rglob("*") if p.is_file()}
        self.assertEqual(before, after)
        self.assertEqual([], self.host.calls)

    def test_failed_restart_restores_binary_and_config_and_leaves_service_stopped(self):
        self.host.healthy = False
        with self.assertRaisesRegex(installer.InstallError, "restored.*stopped"):
            self.run_install()
        self.assertEqual(b"original-wings", self.host.path("/usr/local/bin/wings").read_bytes())
        self.assertEqual(self.host.config, self.host.path("/etc/pelican/config.yml").read_bytes())
        self.assertIn(["systemctl", "stop", "wings.service"], self.host.calls)
        self.assertTrue(self.host.mounted)
        self.assertFalse(any("umount" in call for call in self.host.calls))

    def test_existing_unknown_dropin_is_not_overwritten(self):
        target = self.host.path("/etc/systemd/system/wings.service.d/90-better-pelican-secrets.conf")
        target.parent.mkdir(parents=True)
        target.write_text("operator-owned customization")
        with self.assertRaisesRegex(installer.InstallError, "existing"):
            self.run_install()
        self.assertEqual("operator-owned customization", target.read_text())
        self.assertEqual([], self.host.calls)

    def test_existing_private_mount_is_never_unmounted_or_restarted(self):
        self.host.mounted = True
        self.run_install()
        self.assertFalse(any(call[:2] == ["systemctl", "start"] and call[2].endswith(".mount") for call in self.host.calls))
        self.assertFalse(any("umount" in call for call in self.host.calls))


class InstallerInputTests(unittest.TestCase):
    def test_standalone_download_matches_reviewed_source(self):
        import build_node_installer
        self.assertEqual(build_node_installer.render(), (build_node_installer.ROOT / "install-node.sh").read_text().replace("\r\n", "\n"))

    def test_runtime_paths_reject_roots_traversal_unit_specifiers_and_server_data(self):
        for value in ["/", "/run/../etc", "/run/x%u", "/var/lib/pelican/volumes/private", "/run/x\nWhere=/etc"]:
            with self.subTest(value=value), self.assertRaises(installer.InstallError):
                installer.validate_runtime_path(value, "/var/lib/pelican/volumes")

    def test_service_arguments_preserve_the_existing_binary_and_config_selection(self):
        binary, config = installer.service_paths("{ path=/opt/wings; argv[]=/opt/wings --config /etc/pelican/custom.yml; ignore_errors=no; }")
        self.assertEqual(("/opt/wings", "/etc/pelican/custom.yml"), (binary, config))

    def test_private_mount_cannot_hide_its_recovery_backups(self):
        for value in [installer.BACKUPS, "/var/backups", installer.BACKUPS + "/private"]:
            with self.subTest(value=value), self.assertRaises(installer.InstallError):
                installer.validate_runtime_path(value, "/var/lib/pelican/volumes")

    def test_private_mount_cannot_alias_ordinary_storage_through_a_symlink(self):
        resolved = {"/srv/pelican/volumes": "/var/private"}
        with patch.object(installer.Path, "resolve", autospec=True,
                          side_effect=lambda path: Path(resolved.get(str(path).replace("\\", "/"), str(path)))):
            with self.assertRaises(installer.InstallError):
                installer.validate_runtime_path("/var/private", "/srv/pelican/volumes")

    def test_insecure_tls_flags_are_rejected(self):
        with self.assertRaises(installer.InstallError):
            installer.service_paths("{ path=/opt/wings; argv[]=/opt/wings --ignore-certificate-errors; ignore_errors=no; }")


class PanelDiscoveryTests(unittest.TestCase):
    settings = {"remote": "https://panel.example", "token_id": "node-id", "token": "private-token"}

    def test_discovers_all_assigned_servers_across_panel_pages(self):
        pages = [json.dumps({"data": [{"uuid": identifier, "settings": {"secrets": {
            "protocol": 1, "server_uuid": identifier}}}], "meta": {"last_page": 2}}).encode()
                 for identifier in ("first-server", "second-server")]
        with patch.object(installer, "build_opener") as builder:
            opener = builder.return_value
            opener.open.side_effect = [BytesIO(page) for page in pages]
            self.assertEqual(2, installer.Host().panel_inventory(self.settings, {}, None))
            requests = [call.args[0] for call in opener.open.call_args_list]
            self.assertIn("page=2", requests[1].full_url)
            self.assertTrue(all(request.get_header("Authorization") == "Bearer node-id.private-token" for request in requests))

    def test_missing_panel_plugin_blocks_node_replacement(self):
        page = json.dumps({"data": [{"uuid": "server", "settings": {}}], "meta": {"last_page": 1}}).encode()
        with patch.object(installer, "build_opener") as builder:
            builder.return_value.open.return_value = BytesIO(page)
            with self.assertRaisesRegex(installer.InstallError, "Panel plugin"):
                installer.Host().panel_inventory(self.settings, {}, None)

    def test_authenticated_panel_requests_never_follow_redirects(self):
        with self.assertRaisesRegex(installer.InstallError, "redirected"):
            installer.NoRedirect().redirect_request(None, None, 302, "redirect", {}, "https://other.example")


if __name__ == "__main__":
    unittest.main()
