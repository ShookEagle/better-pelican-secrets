#!/usr/bin/env bash
set -euo pipefail
source_root="$(cd "$(dirname "$0")/.." && pwd)"
if [ "$#" -ne 2 ]; then echo "Usage: node/build.sh NEW_CHECKOUT ABSOLUTE_OUTPUT" >&2; exit 1; fi
checkout="$1"
output="$2"
case "$(go env GOOS)/$(go env GOARCH)" in linux/amd64) ;; *) echo "Only Linux amd64 is qualified." >&2; exit 1;; esac
python_bin="$(printenv BPS_PYTHON || printf python3)"
commit="$("$python_bin" -c 'import json,sys; print(json.load(open(sys.argv[1]))["wings"]["commit"])' "$source_root/upstream.lock.json")"
if [ -e "$checkout" ]; then echo "Use a new checkout path." >&2; exit 1; fi
git clone --no-checkout https://github.com/pelican/wings.git "$checkout"
git -C "$checkout" checkout --detach "$commit"
for patch in "$source_root"/node/patches/*.patch; do git -C "$checkout" apply --check "$patch"; git -C "$checkout" apply "$patch"; done
cp -R "$source_root/node/overlay/." "$checkout/"
(cd "$checkout" && GOTOOLCHAIN=local CGO_ENABLED=0 go build -trimpath -buildvcs=false -ldflags "-s -w -X github.com/pelican/wings/system.Version=1.0.0-rc.1+bps.0.1.0" -o "$output" .)
