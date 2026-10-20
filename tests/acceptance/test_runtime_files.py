"""Real preparer output is mounted into a separate non-root game container."""
import json
from pathlib import PurePosixPath
import unittest
import uuid

import test_mount_contract as mount_contract

ROOT = mount_contract.ROOT


class RuntimeFilesTests(unittest.TestCase):
    run_docker = classmethod(mount_contract.MountContractTests.run_docker.__func__)

    @classmethod
    def setUpClass(cls):
        mount_contract.MountContractTests.setUpClass.__func__(cls)
        images = json.loads((ROOT / "dev/images.lock.json").read_text())
        cls.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
            "--workdir", "/workspace/.work/wings", "--env", "GOTOOLCHAIN=local",
            "--env", "CGO_ENABLED=0", images["go"],
            "go", "build", "-o", "/workspace/.work/runtime-files-probe",
            "/workspace/node/fixtures/runtime-files/main.go", timeout=600,
        )

    def setUp(self):
        self.prefix = "bps-runtime-files-" + uuid.uuid4().hex
        self.data = self.prefix + "-data"
        self.runtime = self.prefix + "-runtime"
        for name in (self.data, self.runtime):
            args = ["volume", "create", "--label", "better-pelican-secrets.dev=true"]
            if name == self.runtime:
                args += ["--opt", "type=tmpfs", "--opt", "device=tmpfs", "--opt", "o=mode=0700"]
            self.run_docker(*args, name)
            self.addCleanup(lambda name=name: self.run_docker("volume", "rm", name, check=False))
        self.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=volume,source=" + self.data + ",target=/game",
            self.image, "sh", "-c",
            "mkdir -p /game/csgo && printf '%s' '{\"key\":{{secret:KEY}}}' > /game/csgo/config.json && chmod 0644 /game/csgo/config.json",
        )
        self.keeper = self.prefix + "-preparer"
        self.addCleanup(lambda: self.run_docker("rm", "-f", self.keeper, check=False))
        self.run_docker(
            "run", "-d", "--name", self.keeper, "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=volume,source=" + self.data + ",target=/game",
            "--mount", "type=volume,source=" + self.runtime + ",target=/run/better-pelican-secrets",
            "--mount", "type=bind,source=" + str(ROOT / ".work/runtime-files-probe") + ",target=/probe,readonly",
            "--mount", "type=bind,source=" + str(ROOT / "protocol/fixtures") + ",target=/fixtures,readonly",
            self.image, "/probe",
        )
        # Blocking output wait, bounded by timeout; a failed preparer is an error.
        self.run_docker("exec", self.keeper, "sh", "-c",
                        "i=0; while [ ! -f /run/better-pelican-secrets/11111111-1111-4111-8111-111111111111/44444444-4444-4444-8444-444444444444/33333333-3333-4333-8333-333333333333 ]; do i=$((i+1)); [ $i -lt 100 ] || exit 1; sleep 0.1; done")
        metadata = json.loads(self.run_docker("logs", self.keeper).stdout)
        self.assertNotIn("fixture-secret", json.dumps(metadata))
        runtime_mount = metadata["Mounts"][0]
        self.assertTrue(runtime_mount["ReadOnly"])
        source = PurePosixPath(runtime_mount["Source"])
        relative = source.relative_to("/run/better-pelican-secrets")
        host = self.run_docker("volume", "inspect", "--format", "{{.Mountpoint}}", self.runtime).stdout.decode().strip()
        if not host.startswith("/"):
            raise RuntimeError("Expected an absolute Linux tmpfs mountpoint")
        self.host_file = str(PurePosixPath(host) / relative)

    def game(self, user, command):
        return self.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true", "--user", user,
            "--mount", "type=volume,source=" + self.data + ",target=/game",
            "--mount", "type=bind,source=" + self.host_file + ",target=/game/csgo/config.json,readonly",
            self.image, "sh", "-c", command, check=False,
        )

    def test_game_uid_reads_private_tmpfs_mount_runtime_files(self):
        result = self.game("1000:1000", "id -u; id -g; cat /game/csgo/config.json")
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(result.stdout, b'1000\n1000\n{"key":"fixture-secret"}')
        self.assertNotEqual(self.game("1001:1001", "cat /game/csgo/config.json").returncode, 0)
        self.assertNotEqual(self.game("1000:1000", "echo changed > /game/csgo/config.json").returncode, 0)
        source = self.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=volume,source=" + self.data + ",target=/source,readonly",
            self.image, "sh", "-c", "tar -cf /tmp/data.tar -C /source . && tar -xOf /tmp/data.tar ./csgo/config.json",
        ).stdout
        self.assertEqual(source, b'{"key":{{secret:KEY}}}')
        modes = self.run_docker("exec", self.keeper, "sh", "-c",
            "stat -c '%a:%u:%g' /run/better-pelican-secrets; stat -c '%a:%u:%g' /run/better-pelican-secrets/*/*/*").stdout
        self.assertEqual(modes, b"700:0:0\n400:1000:1000\n")
