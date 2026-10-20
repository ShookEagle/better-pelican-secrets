"""Reproducible patches against the pinned, disposable Wings checkout."""
from contextlib import contextmanager
import os
from pathlib import Path
import re
import shutil
import subprocess
import uuid

ROOT = Path(__file__).resolve().parents[1]
WINGS = ROOT / ".work/wings"
PATCHES = ROOT / "node/patches"
OVERLAY = ROOT / "node/overlay"


def git(*args, env=None, capture=False, check=True):
    return subprocess.run(["git", "-c", "core.autocrlf=false", "-c", "core.safecrlf=false", "-C", str(WINGS), *args], check=check, env=env,
                          stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                          stderr=subprocess.PIPE if capture else None)


@contextmanager
def baseline(patches):
    path = ROOT / ".work" / ("patch-index-" + uuid.uuid4().hex)
    env = {**os.environ, "GIT_INDEX_FILE": path.as_posix()}
    try:
        git("read-tree", "HEAD", env=env)
        for patch in patches:
            git("apply", "--cached", "--whitespace=nowarn", str(patch), env=env)
        yield env
    finally:
        path.unlink(missing_ok=True)
        path.with_name(path.name + ".lock").unlink(missing_ok=True)


def sync_node():
    patches = sorted(PATCHES.glob("*.patch")) if PATCHES.exists() else []
    with baseline(patches) as env:
        matches = git("diff", "--quiet", env=env, check=False).returncode == 0
    if not matches:
        if git("diff", "--quiet", "HEAD", check=False).returncode != 0:
            raise RuntimeError("Unrecorded Wings edits; run tools/dev refresh-patches before materializing/testing.")
        for patch in patches:
            git("apply", "--check", str(patch))
            git("apply", str(patch))
    tracked = set(git("ls-tree", "-r", "--name-only", "HEAD", capture=True).stdout.decode().splitlines())
    if OVERLAY.exists():
        for source in OVERLAY.rglob("*"):
            if source.is_file() and source.relative_to(OVERLAY).as_posix() in tracked:
                raise RuntimeError("Existing upstream files belong in patches, not overlays.")
        shutil.copytree(OVERLAY, WINGS, dirs_exist_ok=True)


def refresh(name=None):
    PATCHES.mkdir(parents=True, exist_ok=True)
    existing = sorted(PATCHES.glob("*.patch"))
    name = name or (existing[-1].name if existing else "0001-secrets-protocol.patch")
    if not re.fullmatch(r"[0-9]{4}-[a-z0-9-]+\.patch", name):
        raise RuntimeError("Use a numbered patch basename.")
    target = PATCHES / name
    if any(p.name > name for p in existing):
        raise RuntimeError("Refresh the latest patch; do not absorb later changes into an earlier patch.")
    with baseline([p for p in existing if p.name < name]) as env:
        changed = git("diff", "--name-only", env=env, capture=True).stdout.decode().splitlines()
        tracked = set(git("ls-tree", "-r", "--name-only", "HEAD", capture=True).stdout.decode().splitlines())
        if any(path not in tracked for path in changed):
            raise RuntimeError("New files must remain authoritative in node/overlay.")
        diff = git("diff", "--binary", "--no-ext-diff", "--src-prefix=a/", "--dst-prefix=b/",
                   env=env, capture=True).stdout
    if not diff:
        raise RuntimeError("No existing upstream edits to record.")
    target.write_bytes(diff)
    print("Refreshed " + str(target.relative_to(ROOT)))


def format_paths():
    paths = [source.relative_to(OVERLAY).as_posix() for source in OVERLAY.rglob("*.go")] if OVERLAY.exists() else []
    paths += [path for path in git("diff", "--name-only", "HEAD", capture=True).stdout.decode().splitlines() if path.endswith(".go")]
    paths += ["/workspace/" + source.relative_to(ROOT).as_posix() for source in (ROOT / "node/fixtures").rglob("*.go")]
    return sorted(set(paths))


def copy_formatted_overlay():
    if OVERLAY.exists():
        for source in OVERLAY.rglob("*.go"):
            shutil.copyfile(WINGS / source.relative_to(OVERLAY), source)
