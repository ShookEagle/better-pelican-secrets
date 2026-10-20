"""Portable disposable harness; all container operations target this project's fixtures."""
import hashlib
import json
import os
import re
from pathlib import Path
import shutil
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
DOCKER = os.environ.get("BPS_DOCKER") or shutil.which("docker")
if not DOCKER and os.name == "nt":
    candidate = Path(os.environ.get("LOCALAPPDATA", "")) / "Programs/DockerDesktop/resources/bin/docker.exe"
    DOCKER = str(candidate) if candidate.is_file() else None
IMAGE = "better-pelican-secrets-dev-php:local"


def run(args, **kwargs):
    return subprocess.run(args, cwd=ROOT, check=True, **kwargs)


def materialize():
    pins = json.loads((ROOT / "upstream.lock.json").read_text(encoding="utf-8"))
    for name in ("panel", "wings"):
        path = ROOT / ".work" / name
        if not (path / ".git").exists():
            path.mkdir(parents=True, exist_ok=True)
            run(["git", "init", "--quiet", str(path)])
            run(["git", "-C", str(path), "remote", "add", "origin", pins[name]["url"]])
            run(["git", "-C", str(path), "fetch", "--quiet", "--depth", "1", "origin", pins[name]["commit"]])
            run(["git", "-C", str(path), "-c", "core.autocrlf=false", "checkout", "--quiet", "--detach", "FETCH_HEAD"])
        head = subprocess.check_output(["git", "-C", str(path), "rev-parse", "HEAD"], text=True).strip()
        if head != pins[name]["commit"]:
            raise RuntimeError("Pinned upstream checkout mismatch: " + name)
    # Match the pinned upstream release workflow, which stamps a tag checkout.
    version = pins["release"].removeprefix("v")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?", version):
        raise RuntimeError("Invalid pinned release version")
    app_config = ROOT / ".work/panel/config/app.php"
    source = app_config.read_text(encoding="utf-8")
    stamped = "'version' => '" + version + "',"
    source = source.replace("'version' => 'canary',", stamped)
    if stamped not in source:
        raise RuntimeError("Pinned Panel release version stamp failed")
    app_config.write_text(source, encoding="utf-8")
    sync_plugin()


def sync_plugin():
    source = ROOT / "plugins" / "better-pelican-secrets"
    if not source.exists():
        return
    destination = ROOT / ".work/panel/plugins/better-pelican-secrets"
    manifest = destination / "plugin.json"
    meta = json.loads(manifest.read_text(encoding="utf-8")).get("meta") if manifest.exists() else None
    shutil.copytree(source, destination, dirs_exist_ok=True)
    if meta is not None:
        data = json.loads(manifest.read_text(encoding="utf-8"))
        data["meta"] = meta
        manifest.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


def require_docker():
    if not DOCKER:
        raise RuntimeError("Linux Docker is required; set BPS_DOCKER to its CLI path.")
    kind = subprocess.check_output([DOCKER, "info", "--format", "{{.OSType}}"], text=True).strip()
    if kind != "linux":
        raise RuntimeError("The approved harness requires a Linux Docker engine.")


def php_run(arguments, app_env="testing"):
    sync_plugin()
    variables = {
        "APP_ENV": app_env,
        "COMPOSER_CACHE_DIR": "/root/.cache/composer",
        "APP_KEY": "base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
        "APP_URL": "http://localhost",
        "DB_CONNECTION": "sqlite",
        "DB_DATABASE": "/workspace/.work/panel/database/testing.sqlite",
        "CACHE_STORE": "array", "QUEUE_CONNECTION": "sync", "SESSION_DRIVER": "array",
        "BPS_PROTOCOL_FIXTURES": "/workspace/protocol/fixtures",
    }
    cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
    cache_volumes = {"vendor": "bps-dev-" + cache_id + "-vendor", "node_modules": "bps-dev-" + cache_id + "-node"}
    for volume in cache_volumes.values():
        run([DOCKER, "volume", "create", "--label", "better-pelican-secrets.dev=true", volume], stdout=subprocess.DEVNULL)
    args = [DOCKER, "run", "--rm", "--label", "better-pelican-secrets.dev=true",
            "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace",
            "--workdir", "/workspace/.work/panel"]
    for directory, volume in cache_volumes.items():
        args += ["--mount", "type=volume,source=" + volume + ",target=/workspace/.work/panel/" + directory + ",volume-nocopy"]
    cache_path = ROOT / ".work/composer-cache"
    cache_path.mkdir(parents=True, exist_ok=True)
    args += ["--mount", "type=bind,source=" + str(cache_path) + ",target=/root/.cache/composer"]
    for key, value in variables.items():
        args += ["--env", key + "=" + value]
    return run([*args, IMAGE, *arguments])


def main():
    if len(sys.argv) < 2:
        raise RuntimeError("Usage: tools/dev prepare|materialize|php|acceptance|probe")
    command, arguments = sys.argv[1], sys.argv[2:]
    if command == "materialize":
        materialize()
        return
    require_docker()
    if command == "prepare":
        materialize()
        run([DOCKER, "compose", "-f", str(ROOT / "dev/compose.yaml"), "build", "php"])
        (ROOT / ".work/panel/database/testing.sqlite").touch(exist_ok=True)
        php_run(["composer", "install", "--no-interaction", "--prefer-dist"])
    elif command == "php":
        test_arguments = ["/workspace/" + arg if arg.startswith("tests/") else arg for arg in arguments]
        php_run(["php", "-d", "memory_limit=512M", "vendor/bin/phpunit", "-c", "/workspace/tests/phpunit.xml", *test_arguments])
    elif command == "pint":
        php_run(["php", "vendor/bin/pint", "/workspace/plugins", "/workspace/tests/php", *arguments])
    elif command == "task1-check":
        run([sys.executable, str(ROOT / "tools/dev.py"), "php", "tests/php/Compatibility/IntegrationSeamsTest.php"])
        run([sys.executable, str(ROOT / "tools/dev.py"), "acceptance"])
    elif command == "acceptance":
        run([sys.executable, "-m", "unittest", "discover", "-s", "tests/acceptance",
             "-p", "test_*.py", "-v", *arguments], env={**os.environ, "BPS_DOCKER": DOCKER})
    elif command == "install-fixture":
        php_run(["php", "artisan", "migrate", "--force", "--no-interaction"])
        php_run(["php", "artisan", "p:plugin:install", "better-pelican-secrets", "--no-interaction"], app_env="local")
    elif command == "probe":
        php_run(["php", "/workspace/tests/php/compatibility-probe.php"], app_env="local")
    else:
        raise RuntimeError("Command not yet available at this implementation stage: " + command)


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
