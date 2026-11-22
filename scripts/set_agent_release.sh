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

# Fetch and check checksums first; write nothing until both files are ready, so a
# failed fetch never leaves a new AGENT_VERSION next to old checksums.
tmp_sums="$(mktemp "$ROOT/.checksums.XXXXXX")"
tmp_ver="$(mktemp "$ROOT/.agent_version.XXXXXX")"
trap 'rm -f "$tmp_sums" "$tmp_ver"' EXIT
curl -fsSL --connect-timeout 15 --max-time 180 "${BASE}/v1/agent/releases/${VER}/checksums.txt" -o "$tmp_sums"
for arch in armv7 arm64 amd64; do
  if ! grep -Eq "^[0-9a-f]{64}  fpp-monitor-agent-linux-${arch}$" "$tmp_sums"; then
    echo "checksums for ${VER} are missing fpp-monitor-agent-linux-${arch}" >&2
    exit 1
  fi
done
printf '%s\n' "$VER" > "$tmp_ver"
chmod 0644 "$tmp_sums" "$tmp_ver"
mv "$tmp_sums" "$ROOT/checksums.txt"
mv "$tmp_ver" "$ROOT/AGENT_VERSION"
trap - EXIT

echo "Stamped agent ${VER} into AGENT_VERSION and checksums.txt"
