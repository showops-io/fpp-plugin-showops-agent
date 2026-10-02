#!/usr/bin/env bash
# Stamp the current ShowOps agent release into AGENT_VERSION.
#
# FPP 9 Plugin Manager shows Update only after git fetch sees new commits.
# pluginInfo.json must stay schema-valid, so the version lives in AGENT_VERSION
# rather than an unknown JSON key. checksums.txt in this tree must already
# contain the SHA-256 lines for that release.
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

echo "Stamped agent ${VER} into AGENT_VERSION"
echo "checksums.txt must already contain the SHA-256 lines for ${VER}"
