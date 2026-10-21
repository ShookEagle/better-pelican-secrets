"""Release artifacts and recorded live qualification are mandatory, never skipped."""
import hashlib
import json
from pathlib import Path
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[2]


class ReleaseMatrixTests(unittest.TestCase):
    def test_release_acceptance_matrix(self):
        artifact = ROOT / "dist/better-pelican-secrets-0.1.0.zip"
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
    def test_node_artifact_policy(self):
        source = ROOT / "dist/wings-source-0.1.0.zip"
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
        binary = (ROOT / "dist/wings-bps-0.1.0-linux-amd64").read_bytes()
        self.assertEqual(b"\x7fELF", binary[:4])
        self.assertNotIn(b"fixture-secret", binary)
        self.assertNotIn(b"visual-fixture-value", binary)


if __name__ == "__main__":
    unittest.main()
