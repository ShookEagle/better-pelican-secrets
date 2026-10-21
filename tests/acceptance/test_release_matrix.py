"""Release artifacts and recorded live qualification are mandatory, never skipped."""
import hashlib
import json
import io
import os
import shutil
import subprocess
import tarfile
import tempfile
from pathlib import Path
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]


class ReleaseMatrixTests(unittest.TestCase):
    def test_release_acceptance_matrix(self):
        artifact = ROOT / "dist/better-pelican-secrets-0.1.1.zip"
        self.assertTrue(artifact.is_file(), "Missing candidate release artifact")
        provenance = json.loads((ROOT / "dist/provenance.json").read_text(encoding="utf-8"))
        self.assertEqual("1.0.0-rc.1", provenance["panel_version"])
        self.assertEqual(["linux/amd64"], provenance["architectures"])
        matrix = json.loads((ROOT / ".work/acceptance-results.json").read_text(encoding="utf-8"))
        self.assertEqual(set(range(1, 11)), {int(n) for n in matrix["spec_cases"]})
        self.assertEqual([], matrix["skipped"])
        self.assertEqual([], matrix["failures"])
        for case, tests in matrix["spec_cases"].items():
            self.assertTrue(tests, "No live test for case " + case)
            self.assertTrue(set(tests).issubset(set(matrix["passed"])))
        for required in [
            "test_native_stack.NativeStackTests.test_08a_native_stock_node_activation_rejection",
            "test_native_stack.NativeStackTests.test_08b_native_zip_upgrade_reinstall",
        ]:
            self.assertIn(required, matrix["passed"])
        self.assertEqual(provenance["source_digest"], matrix["source_digest"])
        for name, expected in provenance["artifacts"].items():
            self.assertEqual(expected, hashlib.sha256((ROOT / "dist" / name).read_bytes()).hexdigest())
        with zipfile.ZipFile(artifact) as archive:
            names = archive.namelist()
            self.assertTrue(names)
            self.assertTrue(all(n.startswith("better-pelican-secrets/") for n in names))
            manifest = json.loads(archive.read("better-pelican-secrets/plugin.json"))
            self.assertEqual("better-pelican-secrets", manifest["id"])
            self.assertNotIn("meta", manifest)
            for name in names:
                self.assertNotIn("..", Path(name).parts)
                self.assertFalse(any(p.startswith(".env") for p in Path(name).parts))
                self.assertFalse(name.endswith((".sqlite", ".key", ".pem")))
                self.assertNotIn(b"fixture-secret", archive.read(name))
                self.assertNotIn(b"visual-fixture-value", archive.read(name))

class NodeArtifactTests(unittest.TestCase):
    def test_source_zip_patches_apply_to_pristine_upstream(self):
        source = ROOT / "dist/wings-source-0.1.1.zip"
        tree_bytes = subprocess.check_output(["git", "-c", "core.autocrlf=false", "-C", str(ROOT / ".work/wings"), "archive", "--format=tar", "HEAD"])
        with tempfile.TemporaryDirectory(prefix="source-zip-probe-", dir=ROOT / ".work") as temporary:
            folder = Path(temporary).resolve()
            self.assertTrue(folder.is_relative_to((ROOT / ".work").resolve()))
            tree = folder / "tree"
            tree.mkdir()
            with tarfile.open(fileobj=io.BytesIO(tree_bytes)) as archive:
                archive.extractall(tree, filter="data")
            command = ["git", "-c", "core.autocrlf=false", "-C", str(tree)]
            subprocess.run([*command, "init", "--quiet"], check=True)
            subprocess.run([*command, "add", "--all"], check=True)
            with zipfile.ZipFile(source) as archive:
                names = sorted(n for n in archive.namelist() if n.startswith("node/patches/") and n.endswith(".patch"))
                self.assertTrue(names)
                for name in names:
                    patch = folder / Path(name).name
                    patch.write_bytes(archive.read(name))
                    for options in [["--cached", "--check"], ["--check"], ["--index"]]:
                        result = subprocess.run([*command, "apply", *options, str(patch)], capture_output=True, text=True)
                        self.assertEqual(0, result.returncode, name + " " + repr(options) + ": " + result.stderr)
            changed = subprocess.check_output(["git", "-c", "core.autocrlf=false", "-C", str(ROOT / ".work/wings"),
                                               "diff", "--name-only", "HEAD"], text=True).splitlines()
            for path in changed:
                self.assertEqual((ROOT / ".work/wings" / path).read_bytes(), (tree / path).read_bytes(), path)

    def test_source_zip_build_matches_candidate(self):
        docker = os.environ.get("BPS_DOCKER") or shutil.which("docker")
        self.assertTrue(docker, "Linux Docker is required for source build qualification")
        image = json.loads((ROOT / "dev/images.lock.json").read_text())["go"]
        cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
        mounts = []
        for target in ("go/pkg/mod", "root/.cache/go-build"):
            volume = "bps-dev-" + cache_id + "-" + target.replace("/", "-").replace(".", "")
            mounts += ["--mount", "type=volume,source=" + volume + ",target=/" + target + ",volume-nocopy"]
        with tempfile.TemporaryDirectory(prefix="source-zip-build-", dir=ROOT / ".work") as temporary:
            folder = Path(temporary).resolve()
            self.assertTrue(folder.is_relative_to((ROOT / ".work").resolve()))
            with zipfile.ZipFile(ROOT / "dist/wings-source-0.1.1.zip") as archive:
                archive.extractall(folder)
            relative = "/workspace/" + folder.relative_to(ROOT).as_posix()
            subprocess.run([docker, "run", "--rm", "--label", "better-pelican-secrets.dev=true",
                "--mount", "type=bind,source=" + str(ROOT) + ",target=/workspace", *mounts,
                "--workdir", relative, "--env", "GOTOOLCHAIN=local",
                image, "bash", "node/build.sh", relative + "/checkout", relative + "/rebuilt-wings"], check=True, timeout=300)
            expected = hashlib.sha256((ROOT / "dist/wings-bps-0.1.1-linux-amd64").read_bytes()).hexdigest()
            self.assertEqual(expected, hashlib.sha256((folder / "rebuilt-wings").read_bytes()).hexdigest())

    def test_node_artifact_policy(self):
        source = ROOT / "dist/wings-source-0.1.1.zip"
        with zipfile.ZipFile(source) as archive:
            names = archive.namelist()
            self.assertIn("node/build.sh", names)
            self.assertIn("upstream.lock.json", names)
            self.assertTrue(any(n.startswith("node/patches/") for n in names))
            self.assertTrue(any(n.startswith("node/overlay/") for n in names))
            for name in names:
                self.assertNotIn("..", Path(name).parts)
                self.assertNotIn("fixtures", Path(name).parts)
                self.assertFalse(name.endswith(("_test.go", ".sqlite", ".key", ".pem")))
                self.assertFalse(any(p.startswith(".env") for p in Path(name).parts))
                self.assertNotIn(b"fixture-secret", archive.read(name))
                self.assertNotIn(b"visual-fixture-value", archive.read(name))
        binary = (ROOT / "dist/wings-bps-0.1.1-linux-amd64").read_bytes()
        self.assertEqual(b"\x7fELF", binary[:4])
        self.assertNotIn(b"fixture-secret", binary)
        self.assertNotIn(b"visual-fixture-value", binary)


if __name__ == "__main__":
    unittest.main()
