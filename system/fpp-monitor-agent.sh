#!/usr/bin/env bash
set -euo pipefail

# Resolve paths relative to this script so the unit does not hard-code /opt.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$ROOT_DIR/.." && pwd)"

: "${FPPDIR:=/opt/fpp}"
if [[ -f "${FPPDIR}/scripts/common" ]]; then
  set +e
  # shellcheck disable=SC1090,SC1091
  . "${FPPDIR}/scripts/common" >/dev/null 2>&1
  set -e
fi
: "${MEDIADIR:=/home/fpp/media}"
: "${LOGDIR:=${MEDIADIR}/logs}"

PLUGIN_REPO_NAME="${SHOWOPS_PLUGIN_REPO_NAME:-fpp-plugin-showops-agent}"
CONFIG_PATH="${FPP_MONITOR_AGENT_CONFIG:-${MEDIADIR}/plugindata/${PLUGIN_REPO_NAME}/fpp-monitor-agent.json}"
BIN_PATH="${FPP_MONITOR_AGENT_BIN:-${PLUGIN_DIR}/bin/fpp-monitor-agent}"
LOG_FILE="${FPP_MONITOR_AGENT_LOG:-${LOGDIR}/plugin-${PLUGIN_REPO_NAME}.log}"

if [[ ! -x "$BIN_PATH" ]]; then
  echo "fpp-monitor-agent binary not found at $BIN_PATH" >&2
  exit 1
fi

# Append to the FPP-managed plugin log (PLUGIN_GUIDELINES.md §1).
# Do not abort if the preferred log is not writable (common for web-user starts).
set +e
mkdir -p "$(dirname "$CONFIG_PATH")" "$(dirname "$LOG_FILE")" 2>/dev/null
if ! touch "$LOG_FILE" 2>/dev/null; then
  LOG_FILE="${PLUGIN_DIR}/bin/agent-runtime.log"
  mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null
  touch "$LOG_FILE" 2>/dev/null || true
fi
set -e

exec >>"$LOG_FILE" 2>&1
exec "$BIN_PATH" --config "$CONFIG_PATH"
