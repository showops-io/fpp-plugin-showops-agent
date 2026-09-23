#!/usr/bin/env bash
# Stamp the current ShowOps agent release into files FPP 9 uses for Update.
#
# FPP 9 Plugin Manager shows Update only after git fetch sees new commits.
# pluginInfo.json must stay schema-valid, so the version lives in AGENT_VERSION
# rather than an unknown JSON key.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-}"
TAG="${TAG#v}"
if [[ ! "$TAG" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "usage: $0 <agent-semver>   e.g. 1.2.48 or v1.2.48" >&2
  exit 1
fi
VER="v${TAG}"

printf '%s\n' "$VER" > "$ROOT/AGENT_VERSION"

python3 - "$ROOT/scripts/fpp_install.sh" "$VER" <<'PY'
from pathlib import Path
import re, sys
path, ver = Path(sys.argv[1]), sys.argv[2]
text = path.read_text(encoding="utf-8")
new, n = re.subn(
    r'DEFAULT_RELEASE_VERSION="v[0-9]+\.[0-9]+\.[0-9]+"',
    f'DEFAULT_RELEASE_VERSION="{ver}"',
    text,
    count=1,
)
if n != 1:
    raise SystemExit(f"failed to patch {path} (matches={n})")
path.write_text(new, encoding="utf-8")
PY

echo "Stamped agent ${VER} into AGENT_VERSION and scripts/fpp_install.sh"
