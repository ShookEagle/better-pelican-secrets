"""Actual Panel setup services -> real Wings preparation -> non-root Docker game."""
import hashlib
import json
import os
import subprocess
import unittest
import uuid

import test_mount_contract as mount_contract

ROOT = mount_contract.ROOT


class SetupFlowTests(unittest.TestCase):
    run_docker = classmethod(mount_contract.MountContractTests.run_docker.__func__)

    @classmethod
    def setUpClass(cls):
        mount_contract.MountContractTests.setUpClass.__func__(cls)
        cls.go_image = json.loads((ROOT / "dev/images.lock.json").read_text(encoding="utf-8"))["go"]
        cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
        cls.vendor = "bps-dev-" + cache_id + "-vendor"
        caches = []
        for target in ("go/pkg/mod", "root/.cache/go-build"):
            volume = "bps-dev-" + cache_id + "-" + target.replace("/", "-").replace(".", "")
            cls.run_docker("volume", "create", "--label", "better-pelican-secrets.dev=true", volume)
            caches += ["--mount", "type=volume,source=" + volume + ",target=/" + target + ",volume-nocopy"]
        cls.run_docker("run", "--rm", "--label", "better-pelican-secrets.dev=true", *caches,
                       "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
                       "--workdir", "/workspace/.work/wings", "--env", "GOTOOLCHAIN=local", "--env", "CGO_ENABLED=0",
                       cls.go_image, "go", "build", "-o", "/workspace/.work/setup-flow-probe",
                       "/workspace/node/fixtures/setup-flow/main.go", timeout=600)

    def test_four_formats_and_startup_setup_flow(self):
        prefix = "bps-setup-" + uuid.uuid4().hex
        volumes, points = [], []
        for suffix in ("data", "runtime"):
            name = prefix + "-" + suffix
            args = ["volume", "create", "--label", "better-pelican-secrets.dev=true"]
            if suffix == "runtime":
                args += ["--opt", "type=tmpfs", "--opt", "device=tmpfs", "--opt", "o=mode=0700"]
            self.run_docker(*args, name)
            self.addCleanup(lambda name=name: self.run_docker("volume", "rm", name, check=False))
            point = self.run_docker("volume", "inspect", "--format", "{{.Mountpoint}}", name).stdout.decode().strip()
            if not point.startswith("/var/lib/docker/volumes/" + name + "/"):
                raise RuntimeError("Unexpected disposable volume path")
            volumes.append(name)
            points.append(point)
        php = self.run_docker("run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
            "--mount", "type=volume,source=" + self.vendor + ",target=/workspace/.work/panel/vendor,volume-nocopy",
            "--mount", "type=volume,source=" + volumes[0] + ",target=/fixture-data",
            "--workdir", "/workspace/.work/panel", "--env", "APP_ENV=testing",
            "--env", "APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
            "--env", "DB_CONNECTION=sqlite", "--env", "DB_DATABASE=:memory:", "--env", "CACHE_STORE=array",
            "--env", "SESSION_DRIVER=array", "--env", "QUEUE_CONNECTION=sync",
            "better-pelican-secrets-dev-php:local", "php", "-d", "memory_limit=512M",
            "/workspace/tools/fixtures/setup-flow.php", timeout=120)
        # Deliberate synthetic runtime IPC stays in memory, outside the ordinary data volume.
        corpus = json.loads(php.stdout)
        self.assertEqual(len(corpus["plan"]["files"]), 4)
        self.assertEqual(len(corpus["plan"]["startup"]), 1)
        self.assertNotIn("fixture-secret", json.dumps(corpus["plan"]))
        args = [self.docker, "run", "--rm", "-i", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
            "--mount", "type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock",
            "--mount", "type=volume,source=" + volumes[0] + ",target=" + points[0],
            "--mount", "type=volume,source=" + volumes[1] + ",target=" + points[1],
            self.go_image, "/workspace/.work/setup-flow-probe", "-data", points[0], "-runtime", points[1], "-image", self.image]
        result = subprocess.run(args, input=php.stdout, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            timeout=120, env={**os.environ, "MSYS_NO_PATHCONV": "1"})
        self.assertEqual(result.returncode, 0, result.stderr.decode()[-2000:])
        self.assertEqual(json.loads(result.stdout), {"files": 4, "startup": True, "game": True})
        ordinary = self.run_docker("run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=volume,source=" + volumes[0] + ",target=/source,readonly", self.image,
            "sh", "-c", "cat /source/config.json /source/config.yaml /source/.env /source/config.txt").stdout
        self.assertNotIn(b"fixture-secret", ordinary)
        self.assertNotIn(b"legacy-value", ordinary)
        self.assertEqual(ordinary.count(b"{{secret:API_KEY"), 4)
