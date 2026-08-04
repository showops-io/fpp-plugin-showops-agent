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

mkdir -p "$(dirname "$CONFIG_PATH")" "$(dirname "$LOG_FILE")"

export FPP_MONITOR_AGENT_CONFIG="$CONFIG_PATH"
export SHOWOPS_CONFIG_PATH="$CONFIG_PATH"

# Append to the FPP-managed plugin log (PLUGIN_GUIDELINES.md §1).
exec >>"$LOG_FILE" 2>&1
exec "$BIN_PATH" --config "$CONFIG_PATH"
