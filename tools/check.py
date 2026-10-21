"""Independent CI suites; full qualification remains sequential in one checkout."""
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys

import dev
import package
import upstream
import vet

ROOT = dev.ROOT


def php_checks():
    for command in [["php"], ["pint", "--test"]]:
        dev.run([sys.executable, str(ROOT / "tools/dev.py"), *command])


def go_checks():
    vet_result = vet.compare()
    dev.go_run(["test", "-count=1", "./..."])
    dev.go_run(["test", "-count=1", "-race", "./secrets", "./server", "./environment/docker"])
    image = json.loads((ROOT / "dev/images.lock.json").read_text())["go"]
    paths = ["/workspace/" + p.relative_to(ROOT).as_posix() for folder in ["node/overlay", "node/fixtures"] for p in (ROOT / folder).rglob("*.go")]
    formatted = subprocess.check_output([dev.DOCKER, "run", "--rm", "--label", "better-pelican-secrets.dev=true",
        "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace,readonly", image, "gofmt", "-l", *paths], text=True)
    if formatted.strip():
        raise RuntimeError("Unformatted project Go sources:\n" + formatted)
    return vet_result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--suite", choices=["full", "php", "go", "upstream"], default="full")
    args = parser.parse_args()
    dev.require_docker()
    dev.materialize()
    before = package.source_digest()
    if args.suite in ("full", "php"):
        php_checks()
    if args.suite in ("full", "upstream"):
        upstream_result = upstream.panel()
    if args.suite in ("full", "go"):
        vet_result = go_checks()
    # Syntax validation has no credential-bearing runtime inputs.
    dev.run([sys.executable, "-m", "compileall", "-q", str(ROOT / "tools"), str(ROOT / "tests")])
    dev.run([sys.executable, "-m", "unittest", "discover", "-s", str(ROOT / "tests/tools"), "-v"])
    for file in ["README.md", "docs/installation.md", "docs/operations.md", "docs/compatibility.md"]:
        if not (ROOT / file).is_file():
            raise RuntimeError("Missing release documentation")
    if package.source_digest() != before:
        raise RuntimeError("Source changed during checks")
    if args.suite == "full":
        (ROOT / ".work/check-results.json").write_text(json.dumps({"source_digest": before, "failures": [], "skipped": [], "upstream": upstream_result, "vet": vet_result}, indent=2) + "\n", encoding="utf-8")
    print(args.suite + " current-source checks passed.")


if __name__ == "__main__":
    try:
        main()
    except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
