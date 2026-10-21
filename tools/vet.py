"""Reject new vet diagnostics while exposing the exact pinned upstream baseline."""
from collections import Counter
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import subprocess
import tarfile
import tempfile

import dev

ROOT = dev.ROOT


def diagnostics(checkout):
    cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
    mounts = []
    for target in ("go/pkg/mod", "root/.cache/go-build"):
        volume = "bps-dev-" + cache_id + "-" + target.replace("/", "-").replace(".", "")
        dev.run([dev.DOCKER, "volume", "create", "--label", "better-pelican-secrets.dev=true", volume], stdout=subprocess.DEVNULL)
        mounts += ["--mount", "type=volume,source=" + volume + ",target=/" + target + ",volume-nocopy"]
    image = json.loads((ROOT / "dev/images.lock.json").read_text(encoding="utf-8"))["go"]
    result = subprocess.run([dev.DOCKER, "run", "--rm", "--label", "better-pelican-secrets.dev=true",
        "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace,readonly", *mounts,
        "--workdir", "/workspace/" + checkout.relative_to(ROOT).as_posix(),
        "--env", "GOTOOLCHAIN=local", image, "go", "vet", "./..."], capture_output=True, text=True)
    found = []
    for line in (result.stdout + result.stderr).splitlines():
        match = re.fullmatch(r"(.+\.go):[0-9]+:[0-9]+: (.+)", line)
        if match:
            if PurePosixPath(match[1]).is_absolute():
                raise RuntimeError("Vet dependency/tool failure: " + line)
            found.append(match[1] + ": " + match[2])
        elif re.fullmatch(r"go: downloading [^\s]+ v[0-9][^\s]*", line):
            print(line)
        elif line.strip() and not line.startswith("# "):
            raise RuntimeError("Unrecognized vet output: " + line)
    if result.returncode not in (0, 1) or (result.returncode and not found):
        raise RuntimeError("Vet did not produce usable diagnostics")
    return sorted(found)


def compare():
    with tempfile.TemporaryDirectory(prefix="vet-pinned-", dir=ROOT / ".work") as temporary:
        clean = Path(temporary).resolve()
        if not clean.is_relative_to((ROOT / ".work").resolve()):
            raise RuntimeError("Vet checkout escaped its workspace")
        tree = subprocess.check_output(["git", "-C", str(ROOT / ".work/wings"), "archive", "--format=tar", "HEAD"])
        with tarfile.open(fileobj=io.BytesIO(tree)) as archive:
            archive.extractall(clean, filter="data")
        baseline = diagnostics(clean)
    current = diagnostics(ROOT / ".work/wings")
    introduced = sorted((Counter(current) - Counter(baseline)).elements())
    for issue in current:
        print("Pinned upstream vet: " + issue)
    if introduced:
        raise RuntimeError("New vet diagnostics:\n" + "\n".join(introduced))
    print("Vet: no diagnostics introduced over the pristine pinned source.")
    return {"pinned_baseline": baseline, "current": current, "introduced": introduced}
