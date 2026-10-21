"""Record actual live results, then verify their artifact/coverage contract."""
import argparse
import json
from pathlib import Path
import sys
import unittest

import package

ROOT = package.ROOT
NATIVE = "test_native_stack.NativeStackTests."
CASES = {
    "1": [NATIVE + "test_01_native_https_four_formats_and_first_start"],
    "2": [NATIVE + "test_02_native_editor_sftp_archive_backup_download_restore",
          NATIVE + "test_09_native_transfer_then_post_transfer_start_and_delete"],
    "3": [NATIVE + "test_01_native_https_four_formats_and_first_start"],
    "4": [NATIVE + "test_04_native_schedule_and_wings_restart_paths",
          NATIVE + "test_03_native_rotate_pending_then_consistent_restart",
          NATIVE + "test_09_native_transfer_then_post_transfer_start_and_delete"],
    "5": [NATIVE + "test_03_native_rotate_pending_then_consistent_restart"],
    "6": [NATIVE + "test_05_native_revoke_reveal_during_session"],
    "7": [NATIVE + "test_06_native_panel_outage_survives_but_restart_fails",
          NATIVE + "test_07b_native_missing_plugin_after_daemon_restart_fails_closed"],
    "8": [NATIVE + "test_07_native_source_rename_image_change_and_interruption"],
    "9": [NATIVE + "test_08_native_developer_can_edit_code_and_read_runtime"],
    "10": [NATIVE + "test_10_native_logging_and_documented_limits",
           "test_secret_start.SecretStartTests.test_rotate_without_restart_then_apply_secret_start"],
}


def flatten(suite):
    for test in suite:
        if isinstance(test, unittest.TestSuite):
            yield from flatten(test)
        else:
            yield test


class RecordedResult(unittest.TextTestResult):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.passed = []

    def addSuccess(self, test):
        self.passed.append(test.id())
        super().addSuccess(test)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("-k")
    parser.add_argument("-f", "--failfast", action="store_true")
    args = parser.parse_args()
    suite = unittest.defaultTestLoader.discover(str(ROOT / "tests/acceptance"), pattern="test_*.py")
    tests = list(flatten(suite))
    if args.k:
        tests = [test for test in tests if args.k in test.id()]
        if not tests:
            raise RuntimeError("No matching live tests")
        result = unittest.TextTestRunner(verbosity=2, failfast=args.failfast).run(unittest.TestSuite(tests))
        return 0 if result.wasSuccessful() and not result.skipped else 1
    before = package.source_digest()
    verification = [test for test in tests if test.id().startswith("test_release_matrix.")]
    producers = [test for test in tests if test not in verification]
    result = unittest.TextTestRunner(verbosity=2, failfast=args.failfast, resultclass=RecordedResult).run(unittest.TestSuite(producers))
    report = {"source_digest": before, "passed": result.passed, "failures": [t.id() for t, _ in result.failures + result.errors],
              "skipped": [t.id() for t, _ in result.skipped], "spec_cases": CASES}
    if package.source_digest() != before:
        report["failures"].append("source_changed_during_qualification")
    (ROOT / ".work/acceptance-results.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if not result.wasSuccessful() or result.skipped:
        return 1
    result = unittest.TextTestRunner(verbosity=2).run(unittest.TestSuite(verification))
    if not result.wasSuccessful() or result.skipped:
        return 1
    report["release_matrix"] = "passed"
    (ROOT / ".work/acceptance-results.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    sys.exit(main())
