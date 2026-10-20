"""Exercise actual Wings power actions against real Linux Docker containers."""
import json
import hashlib
import unittest
import uuid

import test_mount_contract as mount_contract

ROOT = mount_contract.ROOT


class SecretStartTests(unittest.TestCase):
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
        cls.run_docker(
            "run", "--rm", "--label", "better-pelican-secrets.dev=true", *caches,
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
            "--workdir", "/workspace/.work/wings", "--env", "GOTOOLCHAIN=local",
            "--env", "CGO_ENABLED=0", cls.go_image,
            "go", "test", "-c", "-tags", "bps_integration",
            "-o", "/workspace/.work/secret-start.test", "./server", timeout=600,
        )

    def test_rotate_without_restart_then_apply_secret_start(self):
        prefix = "bps-secret-start-" + uuid.uuid4().hex
        server = str(uuid.uuid4())
        volumes = []
        points = []
        for suffix in ("data", "runtime"):
            volume = prefix + "-" + suffix
            args = ["volume", "create", "--label", "better-pelican-secrets.dev=true"]
            if suffix == "runtime":
                args += ["--opt", "type=tmpfs", "--opt", "device=tmpfs", "--opt", "o=mode=0700"]
            self.run_docker(*args, volume)
            volumes.append(volume)
            self.addCleanup(lambda volume=volume: self.run_docker("volume", "rm", volume, check=False))
            point = self.run_docker("volume", "inspect", "--format", "{{.Mountpoint}}", volume).stdout.decode().strip()
            if not point.startswith("/var/lib/docker/volumes/" + volume + "/"):
                raise RuntimeError("Unexpected fixture volume path")
            points.append(point)
        game_image = prefix + ":local"
        self.addCleanup(lambda: self.run_docker("image", "rm", game_image, check=False))
        # Build from the digest-pinned fixture base; only this labelled image is removed.
        directory = ROOT / ".work" / prefix
        directory.mkdir()
        (directory / "Dockerfile").write_text(
            "FROM " + self.image + "\nLABEL better-pelican-secrets.dev=true\n"
            'ENTRYPOINT ["/bin/sh","-c","eval \\"$STARTUP\\""]\n', encoding="utf-8")
        self.run_docker("build", "-t", game_image, str(directory), timeout=120)
        # A fresh random server ID is used by the live test and all containers
        # it creates are labelled. Cleanup verifies the label before removal.
        def remove_game():
            result = self.run_docker("inspect", "--format", '{{index .Config.Labels "better-pelican-secrets.dev"}}', server, check=False)
            if result.returncode == 0 and result.stdout.strip() == b"true":
                self.run_docker("rm", "-f", server, check=False)
        self.addCleanup(remove_game)
        args = [
            "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
            "--mount", "type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock",
            "--mount", "type=volume,source=" + volumes[0] + ",target=" + points[0],
            "--mount", "type=volume,source=" + volumes[1] + ",target=" + points[1],
            "--env", "BPS_LIVE_DATA=" + points[0], "--env", "BPS_LIVE_RUNTIME=" + points[1],
            "--env", "BPS_LIVE_IMAGE=" + game_image, "--env", "BPS_LIVE_SERVER=" + server,
            "--env", "BPS_PROTOCOL_FIXTURES=/workspace/protocol/fixtures",
            self.go_image, "/workspace/.work/secret-start.test",
            "-test.run", "^TestSecretStartDockerLifecycleLive$", "-test.v", "-test.timeout", "90s",
        ]
        result = self.run_docker(*args, timeout=120)
        self.assertIn(b"--- PASS: TestSecretStartDockerLifecycleLive", result.stdout)
