#!/usr/bin/env bash
# Stamp the current ShowOps agent release into plugin files FPP 9 uses.
#
# FPP 9 Plugin Manager shows Update only after git fetch sees new commits on
# origin (PluginHasUpdates is git-log, not agent-binary compare). A trailing
# space in pluginInfo.json is easy to miss and does not bump fpp_install.sh,
# so a later Plugin Update can still install the stale DEFAULT_RELEASE_VERSION.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-}"
TAG="${TAG#v}"
if [[ ! "$TAG" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "usage: $0 <agent-semver>   e.g. 1.2.48 or v1.2.48" >&2
  exit 1
fi
VER="v${TAG}"

python3 - "$ROOT/pluginInfo.json" "$VER" <<'PY'
import json, sys
path, ver = sys.argv[1], sys.argv[2]
with open(path, encoding="utf-8") as f:
    data = json.load(f)
data["agentVersion"] = ver
base = (
    "Outbound-only ShowOps monitoring agent for FPP "
    "(Raspberry Pi, BeagleBone, Armbian, and Debian ARM boards)."
)
data["description"] = f"{base} Ships agent {ver}."
with open(path, "w", encoding="utf-8") as f:
    json.dump(data, f, indent=2)
    f.write("\n")
PY

python3 - "$ROOT" "$VER" <<'PY'
from pathlib import Path
import re, sys
root, ver = Path(sys.argv[1]), sys.argv[2]
for rel, pattern in (
    ("scripts/fpp_install.sh", r'DEFAULT_RELEASE_VERSION="v[0-9]+\.[0-9]+\.[0-9]+"'),
    ("showops.php", r"return 'v[0-9]+\.[0-9]+\.[0-9]+';"),
):
    path = root / rel
    text = path.read_text(encoding="utf-8")
    if rel.endswith(".sh"):
        repl = f'DEFAULT_RELEASE_VERSION="{ver}"'
    else:
        repl = f"return '{ver}';"
    new, n = re.subn(pattern, repl, text, count=1)
    if n != 1:
        raise SystemExit(f"failed to patch {rel} (matches={n})")
    path.write_text(new, encoding="utf-8")
PY

echo "Stamped agent ${VER} into pluginInfo.json, scripts/fpp_install.sh, showops.php"
