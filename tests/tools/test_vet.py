"""Cold-run Docker progress must never enter the strict analyzer output parser."""
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
import vet


class VetDockerTests(unittest.TestCase):
    def invoke(self, output="", pull_fails=False):
        ready = False

        def docker(args, **kwargs):
            nonlocal ready
            if args[1:3] == ["image", "inspect"]:
                return subprocess.CompletedProcess(args, 0 if ready else 1)
            if args[1] == "pull":
                if pull_fails:
                    raise subprocess.CalledProcessError(1, args)
                ready = True
                return subprocess.CompletedProcess(args, 0)
            if args[1] == "run":
                stderr = output if ready else "Unable to find image 'golang:exact-pin' locally\n"
                return subprocess.CompletedProcess(args, 1 if output else 0, "", stderr)
            return subprocess.CompletedProcess(args, 0)

        with patch.object(vet.dev, "DOCKER", "docker"), patch.object(vet.dev, "run", side_effect=docker), patch.object(vet.subprocess, "run", side_effect=docker):
            return vet.diagnostics(vet.ROOT / ".work/wings")

    def test_cold_image_is_pulled_before_analyzer_capture(self):
        self.assertEqual([], self.invoke())

    def test_real_diagnostics_remain_visible(self):
        self.assertEqual(["server/server.go: unreachable code"], self.invoke("server/server.go:10:2: unreachable code\n"))

    def test_unknown_tool_output_still_fails(self):
        with self.assertRaisesRegex(RuntimeError, "Unrecognized vet output: fatal tool error"):
            self.invoke("fatal tool error\n")

    def test_failed_image_pull_blocks_checks(self):
        with self.assertRaises(subprocess.CalledProcessError):
            self.invoke(pull_fails=True)


if __name__ == "__main__":
    unittest.main()
