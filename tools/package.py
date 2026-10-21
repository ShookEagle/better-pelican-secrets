"""Deterministic candidate artifacts; qualification is a separate, enforced gate."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import zipfile
import io
import tarfile
import tempfile
import shutil

import dev

ROOT = dev.ROOT
VERSION = "0.1.1"
DIST = ROOT / "dist"


def source_files():
    prefixes = ["plugins", "node", "protocol", "tools", "tests", "dev", "docs", ".github"]
    files = []
    for prefix in prefixes:
        directory = ROOT / prefix
        if directory.exists():
            files.extend(p for p in directory.rglob("*") if p.is_file()
                         and "__pycache__" not in p.parts and not p.name.endswith(".pyc")
                         and not p.relative_to(ROOT).as_posix().startswith(("docs/agents/", "docs/superpowers/plans/")))
    files.extend(ROOT / p for p in ["upstream.lock.json", "README.md", "GLOSSARY.md"] if (ROOT / p).is_file())
    return sorted(set(files))


def source_digest():
    result = hashlib.sha256()
    for path in source_files():
        result.update(path.relative_to(ROOT).as_posix().encode() + b"\0")
        result.update(path.read_bytes().replace(b"\r\n", b"\n") + b"\0")
    return result.hexdigest()


def archive(path, entries):
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
        for name, source in sorted(entries):
            if ".." in Path(name).parts or Path(name).is_absolute():
                raise RuntimeError("Invalid artifact path")
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = (0o100755 if name.endswith(".sh") else 0o100644) << 16
            output.writestr(info, source.read_bytes().replace(b"\r\n", b"\n"))


def candidate():
    dev.require_docker()
    dev.materialize()
    DIST.mkdir(exist_ok=True)
    plugin = ROOT / "plugins/better-pelican-secrets"
    manifest = json.loads((plugin / "plugin.json").read_text(encoding="utf-8"))
    if manifest["id"] != plugin.name or "meta" in manifest:
        raise RuntimeError("Distributable manifest has installation state or mismatched identity")
    archive(DIST / ("better-pelican-secrets-" + VERSION + ".zip"),
            [("better-pelican-secrets/" + p.relative_to(plugin).as_posix(), p)
             for p in plugin.rglob("*") if p.is_file()])
    node_entries = []
    for p in (ROOT / "node").rglob("*"):
        if p.is_file() and not p.name.endswith("_test.go") and "/fixtures/" not in p.as_posix():
            node_entries.append(("node/" + p.relative_to(ROOT / "node").as_posix(), p))
    node_entries.append(("upstream.lock.json", ROOT / "upstream.lock.json"))
    archive(DIST / ("wings-source-" + VERSION + ".zip"), node_entries)
    cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
    mounts = []
    for target in ("go/pkg/mod", "root/.cache/go-build"):
        volume = "bps-dev-" + cache_id + "-" + target.replace("/", "-").replace(".", "")
        dev.run([dev.DOCKER, "volume", "create", "--label", "better-pelican-secrets.dev=true", volume], stdout=subprocess.DEVNULL)
        mounts += ["--mount", "type=volume,source=" + volume + ",target=/" + target + ",volume-nocopy"]
    image = json.loads((ROOT / "dev/images.lock.json").read_text(encoding="utf-8"))["go"]
    binary = "wings-bps-" + VERSION + "-linux-amd64"
    with tempfile.TemporaryDirectory(prefix="release-wings-", dir=ROOT / ".work") as temporary:
        clean = Path(temporary).resolve()
        if not clean.is_relative_to((ROOT / ".work").resolve()):
            raise RuntimeError("Release checkout escaped the intended workspace")
        tree = subprocess.check_output(["git", "-c", "core.autocrlf=false", "-C", str(ROOT / ".work/wings"), "archive", "--format=tar", "HEAD"])
        with tarfile.open(fileobj=io.BytesIO(tree)) as source:
            source.extractall(clean, filter="data")
        dev.run(["git", "init", "--quiet", str(clean)])
        for patch in sorted((ROOT / "node/patches").glob("*.patch")):
            dev.run(["git", "-c", "core.autocrlf=false", "-C", str(clean), "apply", "--check", str(patch)])
            dev.run(["git", "-c", "core.autocrlf=false", "-C", str(clean), "apply", str(patch)])
        shutil.copytree(ROOT / "node/overlay", clean, dirs_exist_ok=True, ignore=shutil.ignore_patterns("*_test.go"))
        dev.run([dev.DOCKER, "run", "--rm", "--label", "better-pelican-secrets.dev=true",
                 "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace", *mounts,
                 "--workdir", "/workspace/" + clean.relative_to(ROOT).as_posix(), "--env", "GOTOOLCHAIN=local",
                 "--env", "CGO_ENABLED=0", "--env", "GOOS=linux", "--env", "GOARCH=amd64",
                 image, "go", "build", "-trimpath", "-buildvcs=false", "-ldflags",
                 "-s -w -X github.com/pelican/wings/system.Version=1.0.0-rc.1+bps.0.1.1",
                 "-o", "/workspace/dist/" + binary, "."])
    # Executing the candidate proves the declared architecture runs in the qualifying VM.
    dev.run([dev.DOCKER, "run", "--rm", "--label", "better-pelican-secrets.dev=true",
             "--mount", "type=bind,source=" + str(DIST) + ",target=/dist,readonly",
             image, "/dist/" + binary, "version"])
    artifacts = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(DIST.iterdir())
                 if p.name in {binary, "better-pelican-secrets-" + VERSION + ".zip", "wings-source-" + VERSION + ".zip"}}
    provenance = {"version": VERSION, "panel_version": "1.0.0-rc.1", "architectures": ["linux/amd64"],
                  "source_digest": source_digest(), "qualification": {"status": "candidate"}, "upstream": json.loads((ROOT / "upstream.lock.json").read_text()),
                  "images": json.loads((ROOT / "dev/images.lock.json").read_text()), "artifacts": artifacts}
    (DIST / "provenance.json").write_text(json.dumps(provenance, indent=2, sort_keys=True) + "\n", encoding="utf-8", newline="\n")
    sums = {**artifacts, "provenance.json": hashlib.sha256((DIST / "provenance.json").read_bytes()).hexdigest()}
    (DIST / "SHA256SUMS").write_text("".join(digest + "  " + name + "\n" for name, digest in sorted(sums.items())), encoding="utf-8", newline="\n")
    print("Candidate artifacts built; qualification is required before release.")


def package():
    expected = source_digest()
    for path in [ROOT / ".work/check-results.json", ROOT / ".work/acceptance-results.json"]:
        report = json.loads(path.read_text(encoding="utf-8"))
        if report["source_digest"] != expected or report.get("failures") or report.get("skipped"):
            raise RuntimeError("Current source has not passed qualification")
    acceptance = json.loads((ROOT / ".work/acceptance-results.json").read_text())
    if acceptance.get("release_matrix") != "passed" or set(acceptance.get("spec_cases", {})) != set(map(str, range(1, 11))):
        raise RuntimeError("Complete release matrix has not passed")
    if any(not names or not set(names).issubset(set(acceptance.get("passed", []))) for names in acceptance["spec_cases"].values()):
        raise RuntimeError("Missing executed live case")
    manifest = json.loads((DIST / "provenance.json").read_text())
    if manifest["source_digest"] != expected:
        raise RuntimeError("Candidate source differs from qualification")
    previous = manifest["artifacts"]
    candidate()
    current = json.loads((DIST / "provenance.json").read_text())["artifacts"]
    if previous != current:
        raise RuntimeError("Rebuilt artifacts differ from the qualified candidate")
    provenance = json.loads((DIST / "provenance.json").read_text())
    provenance["qualification"] = {"status": "qualified", "checks_sha256": hashlib.sha256((ROOT / ".work/check-results.json").read_bytes()).hexdigest(),
        "acceptance_sha256": hashlib.sha256((ROOT / ".work/acceptance-results.json").read_bytes()).hexdigest(), "spec_cases": list(range(1, 11))}
    (DIST / "provenance.json").write_text(json.dumps(provenance, indent=2, sort_keys=True) + "\n", encoding="utf-8", newline="\n")
    sums = {**current, "provenance.json": hashlib.sha256((DIST / "provenance.json").read_bytes()).hexdigest()}
    (DIST / "SHA256SUMS").write_text("".join(digest + "  " + name + "\n" for name, digest in sorted(sums.items())), encoding="utf-8", newline="\n")
    print("Qualified artifacts reproduced.")


if __name__ == "__main__":
    try:
        {"candidate": candidate, "package": package}[sys.argv[1] if len(sys.argv) > 1 else "package"]()
    except (KeyError, OSError, RuntimeError, subprocess.CalledProcessError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
