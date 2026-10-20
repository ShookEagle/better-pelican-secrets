#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
python_bin="$(printenv BPS_PYTHON || printf python3)"
exec "$python_bin" "$root/tools/dev.py" materialize
