"""Isolated real Panel + two native Wings processes behind a private test CA."""
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile
import io
import socket
import time
import uuid

import test_mount_contract as contract

ROOT = contract.ROOT


class NativeStack:
    run_docker = classmethod(contract.MountContractTests.run_docker.__func__)

    def __init__(self):
        self.prefix = "bps-native-" + uuid.uuid4().hex
        self.server = str(uuid.uuid4())
        self.cleanup = []
        contract.MountContractTests.setUpClass.__func__(type(self))
        self.go_image = json.loads((ROOT / "dev/images.lock.json").read_text())["go"]
        self.php_image = "better-pelican-secrets-dev-php:local"

    def close(self):
        if hasattr(self, "wings"):
            for host, name in self.wings.items():
                logs = self.run_docker("logs", "--tail", "100", name, check=False)
                (ROOT / ".work" / ("native-" + host + ".log")).write_bytes(logs.stdout + logs.stderr)
        for callback in reversed(self.cleanup):
            callback()

    def volume(self, suffix, tmpfs=False):
        name = self.prefix + "-" + suffix
        args = ["volume", "create", "--label", "better-pelican-secrets.dev=true"]
        if tmpfs:
            args += ["--opt", "type=tmpfs", "--opt", "device=tmpfs", "--opt", "o=mode=0700"]
        self.run_docker(*args, name)
        self.cleanup.append(lambda: self.run_docker("volume", "rm", name, check=False))
        point = self.run_docker("volume", "inspect", "--format", "{{.Mountpoint}}", name).stdout.decode().strip()
        if not point.startswith("/var/lib/docker/volumes/" + name + "/"):
            raise RuntimeError("Unexpected native fixture volume")
        return name, point

    def start_container(self, suffix, args):
        name = self.prefix + "-" + suffix
        self.run_docker("run", "--detach", "--name", name, "--label", "better-pelican-secrets.dev=true", *args)
        def remove():
            label = self.run_docker("inspect", "--format", '{{index .Config.Labels "better-pelican-secrets.dev"}}', name, check=False)
            if label.returncode == 0 and label.stdout.strip() == b"true":
                self.run_docker("rm", "-f", name, check=False)
        self.cleanup.append(remove)
        return name

    def exec(self, name, *args, data=None, check=True, timeout=120):
        result = subprocess.run([self.docker, "exec", "-i", name, *args], input=data,
                                capture_output=True, timeout=timeout)
        if check and result.returncode:
            raise RuntimeError("Native fixture exec failed: " + result.stderr.decode(errors="replace")[-3000:])
        return result

    def panel(self, action):
        result = self.exec(self.panelapp, "php", "-d", "openssl.cafile=/fixture/tls/ca.crt",
                           "-d", "curl.cainfo=/fixture/tls/ca.crt", "/workspace/tools/fixtures/native-panel.php", action)
        return json.loads(result.stdout)

    def request(self, path, method="GET", data=None, host="wings", token=None, check=True):
        return self.exec(self.proxy, "/workspace/.work/native-tls", "-mode", "request",
                         "-target", "https://" + host + ":8443" + path, "-method", method,
                         "-token", "/fixture/" + (token or ("root" if host=="panel" else host)) + ".token", check=check,
                         data=json.dumps(data).encode() if data is not None else b"")

    def wait(self, predicate, seconds=30):
        deadline = time.monotonic() + seconds
        while time.monotonic() < deadline:
            if predicate():
                return
            time.sleep(0.2)
        raise AssertionError("Native stack condition did not become true")

    def game(self):
        result = self.run_docker("inspect", self.server, check=False)
        return json.loads(result.stdout)[0] if result.returncode == 0 else None

    def power(self, action, host="wings"):
        previous = self.game()
        old_id = previous["Id"] if previous else None
        self.request("/api/servers/" + self.server + "/power", "POST", {"action": action, "wait_seconds": 30}, host)
        if action == "stop":
            self.wait(lambda: (info := self.game()) is None or not info["State"]["Running"])
        else:
            try:
                self.wait(lambda: (info := self.game()) is not None and info["State"]["Running"] and (action != "restart" or info["Id"] != old_id))
            except AssertionError:
                info = self.game()
                if info:
                    raise AssertionError("Native power failed; Docker state: " + json.dumps({k: info["State"].get(k) for k in ["Status", "Running", "Error"]}))
                raise

    def choose_port(self, excluded=()):
        # Ask the OS for an available loopback port and exercise both Docker
        # bindings: Windows can reserve parts of the nominal ephemeral range.
        for _ in range(10):
            with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as tcp:
                tcp.bind(("127.0.0.2", 0))
                port = tcp.getsockname()[1]
                if port in excluded:
                    continue
                try:
                    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as udp:
                        udp.bind(("127.0.0.2", port))
                except OSError:
                    continue
            name = self.prefix + "-port-" + uuid.uuid4().hex[:6]
            try:
                result = self.run_docker("run", "--rm", "--name", name, "--label", "better-pelican-secrets.dev=true",
                    "--network", "none", "--publish", "127.0.0.2:" + str(port) + ":8080/tcp",
                    "--publish", "127.0.0.2:" + str(port) + ":8080/udp", self.image, "true", check=False)
                if result.returncode == 0:
                    return port
            finally:
                label = self.run_docker("inspect", "--format", '{{index .Config.Labels "better-pelican-secrets.dev"}}', name, check=False)
                if label.returncode == 0 and label.stdout.strip() == b"true":
                    self.run_docker("rm", "-f", name, check=False)
        raise RuntimeError("No qualified disposable game port was available")

    def start(self):
        self.network = self.prefix + "-network"
        self.run_docker("network", "create", "--label", "better-pelican-secrets.dev=true", self.network)
        self.cleanup.append(lambda: self.run_docker("network", "rm", self.network, check=False))
        self.fixture, _ = self.volume("fixture")
        self.data1, self.point1 = self.volume("data1")
        self.runtime1, self.runtime_point1 = self.volume("runtime1", True)
        self.data2, self.point2 = self.volume("data2")
        self.runtime2, self.runtime_point2 = self.volume("runtime2", True)
        workspace = ["--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace"]
        fixture = ["--mount", "type=volume,source=" + self.fixture + ",target=/fixture"]
        stock = ROOT / ".work/stock-wings"
        if not stock.exists():
            stock.mkdir()
            archive = subprocess.check_output(["git", "-c", "core.autocrlf=false", "-C", str(ROOT / ".work/wings"), "archive", "--format=tar", "HEAD"])
            with tarfile.open(fileobj=io.BytesIO(archive)) as source:
                source.extractall(stock, filter="data")
        cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
        build_cache = []
        for target in ("go/pkg/mod", "root/.cache/go-build"):
            build_cache += ["--mount", "type=volume,source=bps-dev-" + cache_id + "-" + target.replace("/", "-").replace(".", "") + ",target=/" + target]
        self.run_docker("run", "--rm", "--label", "better-pelican-secrets.dev=true", *workspace, *build_cache,
            "--workdir", "/workspace/.work/stock-wings", "--env", "CGO_ENABLED=0", "--env", "GOTOOLCHAIN=local",
            self.go_image, "go", "build", "-trimpath", "-buildvcs=false", "-o", "/workspace/.work/stock-wings.bin", ".", timeout=600)
        self.run_docker("run", "--rm", "--label", "better-pelican-secrets.dev=true", *workspace,
                       "--workdir", "/workspace/.work/wings", "--env", "CGO_ENABLED=0",
                       self.go_image, "go", "build", "-o", "/workspace/.work/native-tls", "/workspace/node/fixtures/native-stack/tls.go", timeout=600)
        self.run_docker("run", "--rm", "--label", "better-pelican-secrets.dev=true", *workspace, *fixture,
                       self.go_image, "/workspace/.work/native-tls", "-mode", "init")
        cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
        dependencies = []
        for directory, suffix in [("vendor", "vendor"), ("node_modules", "node")]:
            dependencies += ["--mount", "type=volume,source=bps-dev-" + cache_id + "-" + suffix
                             + ",target=/workspace/.work/panel/" + directory + ",volume-nocopy"]
        mounts = [*workspace, *fixture, *dependencies,
                  "--mount", "type=volume,source=" + self.data1 + ",target=" + self.point1,
                  "--mount", "type=volume,source=" + self.data2 + ",target=" + self.point2]
        self.run_docker("run", "--rm", "--label", "better-pelican-secrets.dev=true", *mounts, self.php_image,
                       "sh", "-c", "mkdir -p /fixture/panel; tar -C /workspace/.work/panel --exclude=./.git --exclude=./vendor --exclude=./node_modules --exclude=./plugins --exclude=./storage --exclude=./.env --exclude=./database/*.sqlite --exclude=./bootstrap/cache/*.php -cf - . | tar -C /fixture/panel -xf -; ln -s /workspace/.work/panel/vendor /fixture/panel/vendor; ln -s /workspace/.work/panel/node_modules /fixture/panel/node_modules; mkdir -p /fixture/panel/storage/framework/cache/data /fixture/panel/storage/framework/views /fixture/panel/storage/framework/sessions /fixture/panel/storage/logs /fixture/panel/storage/app /fixture/panel/plugins; touch /fixture/panel/database/acceptance.sqlite",
                       timeout=180)
        game_directory = ROOT / ".work" / self.prefix
        game_directory.mkdir()
        (game_directory / "Dockerfile").write_text("FROM " + self.image + "\nLABEL better-pelican-secrets.dev=true\n"
            'ENTRYPOINT ["/bin/sh","-c","eval \\"$STARTUP\\""]\n', encoding="utf-8")
        self.game_image = self.prefix + ":local"
        self.run_docker("build", "-t", self.game_image, str(game_directory), timeout=120)
        self.run_docker("tag", self.game_image, self.game_image + "-alias")
        self.cleanup.append(lambda: self.run_docker("image", "rm", self.game_image, self.game_image + "-alias", check=False))
        self.port1 = self.choose_port()
        self.port2 = self.choose_port([self.port1])
        variables = {"APP_ENV": "local", "APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
                     "APP_URL": "https://panel:8443", "APP_INSTALLED": "true", "APP_DEBUG": "false",
                     "DB_CONNECTION": "sqlite", "DB_DATABASE": "/fixture/panel/database/acceptance.sqlite",
                     "CACHE_STORE": "array", "SESSION_DRIVER": "file", "QUEUE_CONNECTION": "sync",
                     "TRUSTED_PROXIES": "*", "BPS_NATIVE_NETWORK": self.network, "BPS_NATIVE_SERVER": self.server,
                     "BPS_NATIVE_DATA1": self.point1, "BPS_NATIVE_DATA2": self.point2,
                     "BPS_NATIVE_RUNTIME1": self.runtime_point1, "BPS_NATIVE_RUNTIME2": self.runtime_point2,
                     "BPS_NATIVE_IMAGE": self.game_image, "BPS_NATIVE_PORT1": str(self.port1), "BPS_NATIVE_PORT2": str(self.port2)}
        environment = [value for key, item in variables.items() for value in ["--env", key + "=" + item]]
        self.panelapp = self.start_container("panel", [*mounts, *environment, "--network", self.network,
            "--network-alias", "panelapp", "--workdir", "/fixture/panel", self.php_image,
            "php", "-d", "openssl.cafile=/fixture/tls/ca.crt", "-d", "curl.cainfo=/fixture/tls/ca.crt",
            "-S", "0.0.0.0:8080", "-t", "public"])
        self.exec(self.panelapp, "php", "artisan", "migrate", "--force", "--no-interaction", timeout=120)
        self.panel("import")
        self.exec(self.panelapp, "php", "artisan", "p:plugin:install", "better-pelican-secrets", "--no-interaction", timeout=600)
        self.panel("probe")
        self.panel("seed")
        self.proxy = self.start_container("tls", [*workspace, *fixture, "--network", self.network,
            "--network-alias", "panel", self.go_image, "/workspace/.work/native-tls", "-mode", "proxy"])
        self.wings = {}
        for host, data_volume, data_point, runtime_volume, runtime_point in [
            ("wings", self.data1, self.point1, self.runtime1, self.runtime_point1),
            ("wings2", self.data2, self.point2, self.runtime2, self.runtime_point2),
            ("stock", self.data2, self.point2, self.runtime2, self.runtime_point2)]:
            args = [*workspace, *fixture, "--network", self.network, "--network-alias", host,
                    "--mount", "type=bind,source=/var/run/docker.sock,target=/var/run/docker.sock",
                    "--mount", "type=volume,source=" + data_volume + ",target=" + data_point,
                    "--mount", "type=volume,source=" + runtime_volume + ",target=" + runtime_point,
                    "--env", "SSL_CERT_FILE=/fixture/tls/ca.crt", self.go_image,
                    ("/workspace/.work/stock-wings.bin" if host=="stock" else "/workspace/dist/wings-bps-0.1.1-linux-amd64"), "--config", "/fixture/" + host + ".json"]
            self.wings[host] = self.start_container(host, args)
        def ready():
            result = self.exec(self.proxy, "/workspace/.work/native-tls", "-mode", "request",
                "-target", "https://wings:8443/api/system?secrets=1", "-token", "/fixture/wings.token", check=False)
            return result.returncode == 0
        self.wait(ready, 60)
        self.exec(self.wings["wings"], "/workspace/.work/native-tls", "-mode", "host-public",
                  "-hostkey", self.point1 + "/.sftp/id_ed25519")
        self.panel("setup")
        def remove_game():
            info = self.game()
            if info and info["Config"]["Labels"].get("better-pelican-secrets.dev") == "true":
                self.run_docker("rm", "-f", self.server, check=False)
        self.cleanup.append(remove_game)
