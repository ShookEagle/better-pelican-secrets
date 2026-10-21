"""Actual native install, TLS transport, file operations and game lifecycle."""
import io
import json
import tarfile
import time
import unittest
from urllib.parse import quote, urlsplit

from native_stack import NativeStack


class NativeStackTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.stack = NativeStack()
        try:
            cls.stack.start()
            cls.meta = cls.stack.panel("metadata")
        except BaseException:
            cls.stack.close()
            raise

    @classmethod
    def tearDownClass(cls):
        cls.stack.close()

    def running(self):
        info = self.stack.game()
        return info is not None and info["State"]["Running"]

    def applied(self):
        self.stack.wait(lambda: not self.stack.panel("status")["pending"])

    def test_01_native_https_four_formats_and_first_start(self):
        s = self.stack
        s.power("start")
        self.applied()
        info = s.game()
        self.assertIn("BPS_TOKEN=fixture-secret", info["Config"]["Env"])
        self.assertEqual("none", info["HostConfig"]["LogConfig"]["Type"])
        self.assertEqual("no", info["HostConfig"]["RestartPolicy"]["Name"])
        self.assertEqual(4, sum(m["RW"] is False and m["Source"].startswith(s.runtime_point1 + "/") for m in info["Mounts"]))
        expected = {"config.json": b'{"plugin":{"key":"fixture-secret"}}', "config.yaml": b'key: "fixture-secret"',
                    ".env": b'KEY="fixture-secret"', "config.txt": b"key=fixture-secret"}
        for name, value in expected.items():
            actual = s.run_docker("exec", s.server, "cat", "/home/container/" + name).stdout
            self.assertEqual(value, actual)

    def test_02_native_editor_sftp_archive_backup_download_restore(self):
        s = self.stack
        path = "/api/client/servers/" + s.server + "/files"
        source = s.request(path + "/contents?file=config.json", host="panel", token="developer").stdout
        self.assertEqual(b'{"plugin":{"key":{{secret:API_KEY}}}}', source)
        sftp = s.exec(s.proxy, "/workspace/.work/native-tls", "-mode", "sftp", "-target", "wings:2022",
                      "-user", "native-developer." + self.meta["short"], "-token", "/fixture/sftp.password",
                      "-path", "config.json")
        self.assertEqual(source, sftp.stdout)
        download = json.loads(s.request(path + "/download?file=config.json", host="panel", token="developer").stdout)
        url = urlsplit(download["attributes"]["url"])
        self.assertEqual(source, s.request(url.path + "?" + url.query).stdout)
        compressed = json.loads(s.request("/api/servers/" + s.server + "/files/compress", "POST",
            {"root": "/", "files": ["config.json", "config.yaml", ".env", "config.txt"], "name": "native-templates", "extension": "tar.gz"}).stdout)
        archive = s.request("/api/servers/" + s.server + "/files/contents?file=" + quote(compressed["name"])).stdout
        self.assert_templates_archive(archive)
        s.panel("backup")
        s.wait(lambda: s.panel("backup-status")["complete"], 60)
        self.assertTrue(s.panel("backup-status")["success"])
        url = urlsplit(s.panel("backup-url")["url"])
        self.assert_templates_archive(s.request(url.path + "?" + url.query).stdout)
        s.power("stop")
        s.panel("restore")
        s.wait(lambda: s.panel("restore-status")["complete"], 60)
        self.assertTrue(s.panel("restore-status")["deleted_absent"])
        s.power("start")
        self.applied()

    def assert_templates_archive(self, data):
        with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as archive:
            contents = [archive.extractfile(m).read() for m in archive.getmembers() if m.isfile()]
            self.assertTrue(any(b"{{secret:API_KEY}}" in c for c in contents))
            self.assertFalse(any(b"fixture-secret" in c or b"rotated-fixture" in c for c in contents))
            self.assertFalse(any("better-pelican-secrets" in m.name for m in archive.getmembers()))

    def test_03_native_rotate_pending_then_consistent_restart(self):
        s = self.stack
        old = s.game()["Id"]
        s.panel("rotate")
        self.assertTrue(s.panel("status")["pending"])
        self.assertEqual(old, s.game()["Id"])
        self.assertIn("BPS_TOKEN=fixture-secret", s.game()["Config"]["Env"])
        s.power("restart")
        s.wait(lambda: s.game()["Id"] != old)
        self.applied()
        self.assertIn("BPS_TOKEN=rotated-fixture", s.game()["Config"]["Env"])

    def test_04_native_schedule_and_wings_restart_paths(self):
        s = self.stack
        old = s.game()["Id"]
        s.run_docker("restart", s.wings["wings"])
        s.wait(lambda: s.request("/api/system?secrets=1", check=False).returncode == 0, 60)
        self.assertEqual(old, s.game()["Id"])
        self.assertTrue(self.running())
        s.power("stop")
        s.panel("schedule-start")
        s.wait(self.running)
        s.wait(lambda: s.game()["Id"] != old)
        self.applied()
        self.assertIn("BPS_TOKEN=rotated-fixture", s.game()["Config"]["Env"])

    def test_05_native_revoke_reveal_during_session(self):
        s = self.stack
        s.panel("grant-reveal")
        path = "/api/client/servers/" + s.server + "/secrets/" + self.meta["secret"] + "/reveal"
        first = s.request(path, "POST", {}, host="panel", token="developer")
        self.assertEqual("rotated-fixture", json.loads(first.stdout)["value"])
        s.panel("revoke-reveal")
        denied = s.request(path, "POST", {}, host="panel", token="developer", check=False)
        self.assertNotEqual(0, denied.returncode)
        self.assertIn(b"status 403", denied.stderr)
        self.assertTrue(self.running())
        self.assertIn("BPS_TOKEN=rotated-fixture", s.game()["Config"]["Env"])

    def test_06_native_panel_outage_survives_but_restart_fails(self):
        s = self.stack
        old = s.game()["Id"]
        s.run_docker("stop", s.panelapp)
        self.assertTrue(self.running())
        self.assertEqual(old, s.game()["Id"])
        s.request("/api/servers/" + s.server + "/power", "POST", {"action": "restart", "wait_seconds": 30})
        s.wait(lambda: not self.running(), 30)
        time.sleep(2)
        self.assertFalse(self.running())
        s.run_docker("start", s.panelapp)
        s.wait(lambda: s.request("/api/client/servers/" + s.server + "/secrets", host="panel", check=False).returncode == 0, 30)
        s.power("start")
        self.applied()

    def test_07_native_source_rename_image_change_and_interruption(self):
        s = self.stack
        root = s.point1 + "/" + s.server
        s.exec(s.wings["wings"], "mv", root + "/config.json", root + "/config.moved")
        s.request("/api/servers/" + s.server + "/power", "POST", {"action": "restart", "wait_seconds": 30})
        s.wait(lambda: not self.running())
        s.wait(lambda: s.panel("status")["lastErrorCode"] is not None)
        self.assertTrue(s.panel("status")["pending"])
        s.exec(s.wings["wings"], "mv", root + "/config.moved", root + "/config.json")
        self.assertTrue(s.panel("invalidate-image")["changed"])
        s.power("start")
        self.applied()
        s.panel("invalidate-egg")
        s.request("/api/servers/" + s.server + "/power", "POST", {"action": "restart", "wait_seconds": 30})
        s.wait(lambda: not self.running())
        time.sleep(1)
        self.assertFalse(self.running())
        s.panel("restore-egg")
        s.power("start")
        self.applied()
        before = s.game()["Id"]
        s.run_docker("stop", s.wings["wings"])
        self.assertTrue(self.running())
        s.run_docker("start", s.wings["wings"])
        s.wait(lambda: s.request("/api/system?secrets=1", check=False).returncode == 0, 60)
        self.assertEqual(before, s.game()["Id"])

    def test_07b_native_missing_plugin_after_daemon_restart_fails_closed(self):
        s = self.stack
        manifest = "/fixture/panel/plugins/better-pelican-secrets/plugin.json"
        old = s.game()["Id"]
        s.exec(s.panelapp, "mv", manifest, manifest + ".missing")
        try:
            s.run_docker("restart", s.wings["wings"])
            s.wait(lambda: s.request("/api/system?secrets=1", check=False).returncode == 0, 60)
            self.assertTrue(self.running())
            self.assertEqual(old, s.game()["Id"])
            s.run_docker("stop", s.server)
            denied = s.request("/api/servers/" + s.server + "/power", "POST", {"action": "start", "wait_seconds": 30}, check=False)
            self.assertNotEqual(0, denied.returncode)
            time.sleep(1)
            self.assertFalse(self.running())
        finally:
            s.exec(s.panelapp, "mv", manifest + ".missing", manifest)
        s.run_docker("restart", s.wings["wings"])
        s.wait(lambda: s.request("/api/servers/" + s.server, check=False).returncode == 0, 60)
        if not self.running():
            s.power("start")
        self.applied()

    def test_08_native_developer_can_edit_code_and_read_runtime(self):
        s = self.stack
        path = "/api/client/servers/" + s.server + "/files/write?file=developer-script.sh"
        # Ordinary developer code can access the runtime: this is the documented limit.
        script = "cat /home/container/config.json"
        result = s.exec(s.proxy, "/workspace/.work/native-tls", "-mode", "request",
            "-target", "https://panel:8443" + path, "-method", "POST", "-content-type", "text/plain", "-token", "/fixture/developer.token",
            data=script.encode(), check=False)
        self.assertEqual(0, result.returncode, result.stderr.decode())
        observed = s.run_docker("exec", s.server, "sh", "/home/container/developer-script.sh").stdout
        self.assertEqual(b'{"plugin":{"key":"rotated-fixture"}}', observed)
        denied = s.request("/api/client/servers/" + s.server + "/secrets/" + self.meta["secret"] + "/reveal",
            "POST", {}, host="panel", token="developer", check=False)
        self.assertIn(b"status 403", denied.stderr)

    def test_09_native_transfer_then_post_transfer_start_and_delete(self):
        s = self.stack
        s.panel("transfer")
        s.wait(lambda: s.panel("transfer-status")["success"], 60)
        self.assertTrue(s.panel("transfer-status")["node"])
        root = s.point2 + "/" + s.server
        source = s.exec(s.wings["wings2"], "cat", root + "/config.json").stdout
        self.assertEqual(b'{"plugin":{"key":{{secret:API_KEY}}}}', source)
        self.assertNotIn(b"rotated-fixture", source)
        # Both fixture nodes use one Docker engine; wait for the source's
        # asynchronous deletion before reusing its global container UUID.
        s.wait(lambda: s.game() is None, 30)
        s.power("start", "wings2")
        self.applied()
        self.assertIn("BPS_TOKEN=rotated-fixture", s.game()["Config"]["Env"])
        s.power("stop", "wings2")
        s.panel("delete")
        s.wait(lambda: s.game() is None)

    def test_10_native_logging_and_documented_limits(self):
        for host in ["wings", "wings2"]:
            logs = self.stack.run_docker("logs", self.stack.wings[host], check=False)
            self.assertNotIn(b"fixture-secret", logs.stdout + logs.stderr)
            self.assertNotIn(b"rotated-fixture", logs.stdout + logs.stderr)
        from pathlib import Path
        operations = (Path(__file__).resolve().parents[2] / "docs/operations.md").read_text()
        self.assertIn("defense in depth", operations)
        self.assertIn("Changed code can read a runtime value", operations)

    def test_08a_native_stock_node_activation_rejection(self):
        s = self.stack
        s.wait(lambda: s.request("/api/system", host="stock", check=False).returncode == 0, 60)
        self.assertTrue(s.panel("stock-check")["stock_rejected"])

    def test_08b_native_zip_upgrade_reinstall(self):
        s = self.stack
        s.panel("import")
        s.exec(s.panelapp, "php", "artisan", "p:plugin:install", "better-pelican-secrets", "--no-interaction", timeout=600)
        self.assertTrue(s.panel("probe")["native_provider"])
