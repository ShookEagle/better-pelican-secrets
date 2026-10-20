"""The application must load the plugin without PHPUnit's explicit provider registration."""
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PluginLoadingTests(unittest.TestCase):
    def test_plugin_loads_in_real_application(self):
        result = subprocess.run(
            [sys.executable, str(ROOT / "tools/dev.py"), "probe"],
            cwd=ROOT, capture_output=True, text=True, timeout=90,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        payload = json.loads(result.stdout.strip().splitlines()[-1])
        self.assertNotEqual(payload["environment"], "testing")
        self.assertTrue(payload["provider_loaded"])
        self.assertEqual(payload["panel_version"], "1.0.0-rc.1")
        self.assertEqual(payload["panels"], {
            "admin": "better-pelican-secrets", "server": "better-pelican-secrets",
        })


if __name__ == "__main__":
    unittest.main()
