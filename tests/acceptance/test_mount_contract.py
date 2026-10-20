"""Linux Docker characterization; missing infrastructure is an error, never a skip.

Breaks caught: rendering into the ordinary volume, a writable runtime mount,
incorrect effective game ownership, and reuse of a previous container snapshot.
Only randomly named, labelled resources created by this suite are removed.
"""
import json
import os
from pathlib import Path, PurePosixPath
import shutil
import subprocess
import unittest
import uuid


ROOT = Path(__file__).resolve().parents[2]


class MountContractTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.docker = os.environ.get("BPS_DOCKER") or shutil.which("docker")
        if not cls.docker:
            raise RuntimeError("Linux Docker is required; install/start Docker Desktop or select the approved test host.")
        info = cls.run_docker("info", "--format", "{{.OSType}}")
        if info.stdout.strip() != b"linux":
            raise RuntimeError("The selected Docker engine must run Linux containers.")
        cls.image = json.loads((ROOT / "dev/images.lock.json").read_text(encoding="utf-8"))["fixture"]
        if "@sha256:" not in cls.image:
            raise RuntimeError("The fixture image must be pinned by digest.")
        cls.run_docker("pull", cls.image, timeout=600)

    @classmethod
    def run_docker(cls, *args, check=True, timeout=90):
        result = subprocess.run(
            [cls.docker, *args], stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=timeout, env={**os.environ, "MSYS_NO_PATHCONV": "1"},
        )
        if check and result.returncode:
            raise RuntimeError(
                "Docker test command failed: " + " ".join(args[:3]) + "\n"
                + result.stderr.decode("utf-8", errors="replace")[-3000:]
            )
        return result

    def setUp(self):
        self.prefix = "bps-mount-" + uuid.uuid4().hex
        self.data = self.prefix + "-data"
        self.runtime = self.prefix + "-runtime"
        self.new_volume(self.data)
        self.new_volume(self.runtime, "--opt", "type=tmpfs", "--opt", "device=tmpfs", "--opt", "o=mode=0700")
        self.seed(self.data, "{{secret:KEY}}", "0644")
        self.seed(self.runtime, "fixture-value", "0400")
        mountpoint = self.run_docker("volume", "inspect", "--format", "{{.Mountpoint}}", self.runtime).stdout.decode().strip()
        if not mountpoint.startswith("/"):
            raise RuntimeError("Expected a Linux runtime-volume mountpoint.")
        self.runtime_file = str(PurePosixPath(mountpoint) / "config.json")

    def new_volume(self, name, *options):
        self.run_docker("volume", "create", "--label", "better-pelican-secrets.dev=true", *options, name)
        self.addCleanup(lambda: self.run_docker("volume", "rm", name, check=False))

    def seed(self, volume, value, mode):
        self.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=volume,source=" + volume + ",target=/data",
            self.image, "sh", "-c",
            'printf %s "$1" > /data/config.json && chown 1000:1000 /data/config.json && chmod "$2" /data/config.json',
            "seed", value, mode,
        )

    def source(self, archive=False):
        command = "tar -cf /tmp/source.tar -C /game config.json && tar -xOf /tmp/source.tar config.json" if archive else "cat /game/config.json"
        return self.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--user", "1000:1000", "--mount",
            "type=volume,source=" + self.data + ",target=/game,readonly",
            self.image, "sh", "-c", command,
        ).stdout

    def container(self, command="cat /game/config.json"):
        name = self.prefix + "-" + uuid.uuid4().hex[:10]
        result = self.run_docker(
            "create", "--name", name, "--label", "better-pelican-secrets.dev=true",
            "--user", "1000:1000",
            "--mount", "type=volume,source=" + self.data + ",target=/game",
            "--mount", "type=bind,source=" + self.runtime_file + ",target=/game/config.json,readonly",
            self.image, "sh", "-c", command,
        )
        self.addCleanup(lambda: self.run_docker("rm", "-f", name, check=False))
        return name, result.stdout.strip()

    def test_runtime_mount_preserves_source(self):
        name, _ = self.container()
        self.assertEqual(self.run_docker("start", "--attach", name).stdout, b"fixture-value")
        self.assertEqual(self.source(), b"{{secret:KEY}}")
        self.assertEqual(self.source(archive=True), b"{{secret:KEY}}")

    def test_game_uid_can_read_under_game_mapping(self):
        name, _ = self.container("id -u; id -g; cat /game/config.json")
        self.assertEqual(self.run_docker("start", "--attach", name).stdout, b"1000\n1000\nfixture-value")

    def test_runtime_mount_is_readonly(self):
        name, _ = self.container("printf changed > /game/config.json")
        self.run_docker("start", "--attach", name, check=False)
        exit_code = self.run_docker("inspect", "--format", "{{.State.ExitCode}}", name).stdout.strip()
        self.assertNotEqual(exit_code, b"0")
        self.assertEqual(self.source(), b"{{secret:KEY}}")

    def test_recreated_container_sees_new_runtime(self):
        first, first_id = self.container()
        self.assertEqual(self.run_docker("start", "--attach", first).stdout, b"fixture-value")
        self.run_docker("rm", first)
        self.seed(self.runtime, "second-value", "0400")
        second, second_id = self.container()
        self.assertNotEqual(first_id, second_id)
        self.assertEqual(self.run_docker("start", "--attach", second).stdout, b"second-value")
        self.assertEqual(self.source(), b"{{secret:KEY}}")


if __name__ == "__main__":
    unittest.main()
