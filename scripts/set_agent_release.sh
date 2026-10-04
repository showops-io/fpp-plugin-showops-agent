#!/usr/bin/env bash
# Stamp the current ShowOps agent release into AGENT_VERSION.
#
# FPP 9 Plugin Manager shows Update only after git fetch sees new commits.
# pluginInfo.json must stay schema-valid, so the version lives in AGENT_VERSION
# rather than an unknown JSON key. checksums.txt is downloaded from that release.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TAG="${1:-}"
TAG="${TAG#v}"
if [[ ! "$TAG" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "usage: $0 <agent-semver>   e.g. 1.2.48 or v1.2.48" >&2
  exit 1
fi
VER="v${TAG}"
BASE="${SHOWOPS_API_BASE:-https://api.showops.io}"

printf '%s\n' "$VER" > "$ROOT/AGENT_VERSION"

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
curl -fsSL --connect-timeout 15 --max-time 180 "${BASE}/v1/agent/releases/${VER}/checksums.txt" -o "$tmp"
if ! grep -q 'fpp-monitor-agent-linux-arm64' "$tmp" || ! grep -q 'fpp-monitor-agent-linux-armv7' "$tmp"; then
  echo "checksums for ${VER} are missing an agent asset" >&2
  exit 1
fi
mv "$tmp" "$ROOT/checksums.txt"
trap - EXIT

echo "Stamped agent ${VER} into AGENT_VERSION and checksums.txt"
