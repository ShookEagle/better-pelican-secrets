"""Snapshot only disposable dependency volumes; never databases or test evidence."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess

import dev

ROOT = dev.ROOT
SUFFIXES = {"php": ["vendor", "node"], "go": ["go-pkg-mod", "root-cache-go-build"],
            "upstream": ["vendor", "node"], "full": ["vendor", "node", "go-pkg-mod", "root-cache-go-build"]}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("operation", choices=["restore", "save"])
    parser.add_argument("suite", choices=SUFFIXES)
    args = parser.parse_args()
    dev.require_docker()
    cache = ROOT / ".work/ci-cache"
    cache.mkdir(parents=True, exist_ok=True)
    image = json.loads((ROOT / "dev/images.lock.json").read_text())["fixture"]
    dev.ensure_image(image)
    prefix = "bps-dev-" + hashlib.sha256(str(ROOT).encode()).hexdigest()[:16] + "-"
    for suffix in SUFFIXES[args.suite]:
        volume = prefix + suffix
        if args.operation == "restore":
            if not (cache / (suffix + ".tgz")).is_file():
                continue
            dev.run([dev.DOCKER, "volume", "create", "--label", "better-pelican-secrets.dev=true", volume], stdout=subprocess.DEVNULL)
            script = 'tar -xzf "/cache/$1.tgz" -C /data'
        else:
            present = subprocess.run([dev.DOCKER, "volume", "inspect", volume], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            if present.returncode:
                continue
            script = 'set -eu; set -o pipefail; tar -C /data -cf - . | gzip -1 > "/cache/$1.tgz"'
        label = subprocess.check_output([dev.DOCKER, "volume", "inspect", "--format", '{{index .Labels "better-pelican-secrets.dev"}}', volume], text=True).strip()
        if label != "true":
            raise RuntimeError("Refusing an unlabelled dependency volume: " + volume)
        dev.run([dev.DOCKER, "run", "--rm", "--label", "better-pelican-secrets.dev=true",
                 "--mount", "type=volume,source=" + volume + ",target=/data" + (",readonly" if args.operation == "save" else ""),
                 "--mount", "type=bind,source=" + str(cache) + ",target=/cache" + (",readonly" if args.operation == "restore" else ""),
                 image, "sh", "-c", script, "bps-cache", suffix])


if __name__ == "__main__":
    main()
