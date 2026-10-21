"""Run unchanged pinned application code with narrow PHPUnit provider compatibility."""
import hashlib
import xml.etree.ElementTree as ET
from pathlib import Path

import dev

ROOT = dev.ROOT


def panel(arguments=None):
    compat = ROOT / ".work/upstream-compat"
    compat.mkdir(exist_ok=True)
    replacements = {
        "Integration/Api/Remote/SftpAuthenticationControllerTest.php": [
            ("test_user_is_throttled_if_invalid_credentials_are_provided()", "test_user_is_throttled_if_invalid_credentials_are_provided(string $type)")],
        "Unit/Helpers/ConvertToUtf8Test.php": [
            ("test_output_is_valid_utf8(string $input)", "test_output_is_valid_utf8(string $input, string $expected)"),
            ("test_idempotent(string $input)", "test_idempotent(string $input, string $expected)")],
    }
    copies = []
    for relative, signatures in replacements.items():
        text = (ROOT / ".work/panel/tests" / relative).read_text(encoding="utf-8")
        for before, after in signatures:
            if text.count(before) != 1:
                raise RuntimeError("Pinned PHPUnit compatibility signature changed")
            text = text.replace(before, after)
        target = compat / Path(relative).name
        target.write_text(text, encoding="utf-8", newline="\n")
        copies.append("cp /workspace/.work/upstream-compat/" + target.name + " /bps-upstream/panel/tests/" + relative)
    cache_id = hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]
    script = """set -eu
mkdir -p /bps-upstream/panel
tar -C /workspace/.work/panel --exclude=./.git --exclude=./vendor --exclude=./node_modules --exclude=./storage --exclude=./.env --exclude=./database/*.sqlite --exclude=./bootstrap/cache/*.php -cf - . | tar -C /bps-upstream/panel -xf -
mkdir -p /bps-upstream/panel/storage/framework/cache/data /bps-upstream/panel/storage/framework/views /bps-upstream/panel/storage/framework/sessions /bps-upstream/panel/storage/logs /bps-upstream/panel/storage/app
touch /bps-upstream/panel/database/upstream.sqlite /bps-upstream/panel/.env
""" + "\n".join(copies) + """
cd /bps-upstream/panel
exec php -d memory_limit=512M vendor/bin/pest -c phpunit.xml --fail-on-warning --fail-on-risky --fail-on-incomplete --fail-on-empty-test-suite --log-junit /workspace/.work/upstream-results.xml "$@"
"""
    dev.php_run(["sh", "-c", script, "bps-upstream", *(arguments or [])],
        env_overrides={"DB_DATABASE": "/bps-upstream/panel/database/upstream.sqlite", "APP_URL": "http://localhost/"},
        extra_docker_args=["--tmpfs", "/bps-upstream:rw,mode=0700,size=1g",
            "--mount", "type=volume,source=bps-dev-" + cache_id + "-vendor,target=/bps-upstream/panel/vendor,volume-nocopy"])

    cases = ET.parse(ROOT / ".work/upstream-results.xml").getroot().findall(".//testcase")
    allowed = "App.Tests.Integration.Api.Fixtures.Application.PluginFixtureTest::Index"
    skipped = []
    for case in cases:
        name = case.attrib.get("classname", "").replace("\\", ".") + "::" + case.attrib["name"]
        if case.find("failure") is not None or case.find("error") is not None:
            raise RuntimeError("Upstream test failed: " + name)
        if case.find("skipped") is not None:
            if name != allowed:
                raise RuntimeError("Unexpected upstream skipped test: " + name)
            skipped.append(name)
    if not cases:
        raise RuntimeError("No upstream test evidence")
    return {"passed": len(cases) - len(skipped), "skipped_empty_upstream_placeholder": skipped}
