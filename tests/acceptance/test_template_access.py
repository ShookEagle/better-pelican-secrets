"""Native Wings editor/SFTP/download/archive/backup isolation with a live game."""
import hashlib
import json
from pathlib import PurePosixPath
import unittest
import uuid

import test_mount_contract as mount_contract

ROOT = mount_contract.ROOT
SERVER = "11111111-1111-4111-8111-111111111111"


class TemplateAccessTests(unittest.TestCase):
    run_docker = classmethod(mount_contract.MountContractTests.run_docker.__func__)

    @classmethod
    def setUpClass(cls):
        mount_contract.MountContractTests.setUpClass.__func__(cls)
        cls.go_image = json.loads((ROOT / "dev/images.lock.json").read_text())["go"]
        caches = []
        cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
        for target in ("go/pkg/mod", "root/.cache/go-build"):
            volume = "bps-dev-" + cache_id + "-" + target.replace("/", "-").replace(".", "")
            cls.run_docker("volume", "create", "--label", "better-pelican-secrets.dev=true", volume)
            caches += ["--mount", "type=volume,source=" + volume + ",target=/" + target + ",volume-nocopy"]
        common = [
            "run", "--rm", "--label", "better-pelican-secrets.dev=true", *caches,
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
            "--workdir", "/workspace/.work/wings", "--env", "GOTOOLCHAIN=local",
            "--env", "CGO_ENABLED=0", cls.go_image,
        ]
        cls.run_docker(*common, "go", "build", "-o", "/workspace/.work/runtime-files-probe",
                       "/workspace/node/fixtures/runtime-files/main.go", timeout=600)
        cls.run_docker(*common, "go", "test", "-c", "-tags", "bps_integration",
                       "-o", "/workspace/.work/template-access.test", "./sftp", timeout=600)

    def test_editor_sftp_download_archive_and_backup_see_template_template_access(self):
        prefix = "bps-template-access-" + uuid.uuid4().hex
        volumes, points = [], []
        for suffix in ("data", "runtime"):
            volume = prefix + "-" + suffix
            args = ["volume", "create", "--label", "better-pelican-secrets.dev=true"]
            if suffix == "runtime":
                args += ["--opt", "type=tmpfs", "--opt", "device=tmpfs", "--opt", "o=mode=0700"]
            self.run_docker(*args, volume)
            self.addCleanup(lambda volume=volume: self.run_docker("volume", "rm", volume, check=False))
            point = self.run_docker("volume", "inspect", "--format", "{{.Mountpoint}}", volume).stdout.decode().strip()
            if not point.startswith("/var/lib/docker/volumes/" + volume + "/"):
                raise RuntimeError("Unexpected fixture volume mountpoint")
            volumes.append(volume)
            points.append(point)
        root = points[0] + "/" + SERVER
        self.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=volume,source=" + volumes[0] + ",target=/seed",
            self.image, "sh", "-c",
            'mkdir -p "/seed/$1/csgo" && printf %s \'{"key":{{secret:KEY}}}\' > "/seed/$1/csgo/config.json"',
            "seed", SERVER,
        )
        probe = prefix + "-probe"
        self.addCleanup(lambda: self.run_docker("rm", "-f", probe, check=False))
        shared = [
            "--mount", "type=volume,source=" + volumes[0] + ",target=" + points[0],
            "--mount", "type=volume,source=" + volumes[1] + ",target=" + points[1],
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
        ]
        self.run_docker(
            "run", "-d", "--name", probe, "--label", "better-pelican-secrets.dev=true", *shared,
            self.image, "/workspace/.work/runtime-files-probe",
            "-data", root, "-runtime", points[1], "-fixtures", "/workspace/protocol/fixtures/protocol.json",
        )
        expected = points[1] + "/" + SERVER + "/44444444-4444-4444-8444-444444444444/33333333-3333-4333-8333-333333333333"
        self.run_docker("exec", probe, "sh", "-c",
                        'i=0; while [ ! -f "$1" ]; do i=$((i+1)); [ $i -lt 100 ] || exit 1; sleep 0.1; done', "wait", expected)
        metadata = json.loads(self.run_docker("logs", probe).stdout)
        runtime_file = metadata["Mounts"][0]["Source"]
        PurePosixPath(runtime_file).relative_to(points[1])
        self.assertNotIn("fixture-secret", json.dumps(metadata))
        game = prefix + "-game"
        def remove_game():
            result = self.run_docker("inspect", "--format", '{{index .Config.Labels "better-pelican-secrets.dev"}}', game, check=False)
            if result.returncode == 0 and result.stdout.strip() == b"true":
                self.run_docker("rm", "-f", game, check=False)
        self.addCleanup(remove_game)
        result = self.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true", *shared,
            "--mount", "type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock",
            "--env", "BPS_LIVE_DATA_BASE=" + points[0],
            "--env", "BPS_LIVE_RUNTIME_FILE=" + runtime_file,
            "--env", "BPS_LIVE_IMAGE=" + self.image, "--env", "BPS_LIVE_GAME=" + game,
            "--env", "BPS_PROTOCOL_FIXTURES=/workspace/protocol/fixtures",
            self.go_image, "/workspace/.work/template-access.test",
            "-test.run", "^TestTemplateAccessNativePathsLive$", "-test.v", "-test.timeout", "90s",
            timeout=120,
        )
        self.assertIn(b"--- PASS: TestTemplateAccessNativePathsLive", result.stdout)
