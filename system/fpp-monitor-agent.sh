#!/usr/bin/env bash
set -euo pipefail

# Resolve paths relative to this script so the unit does not hard-code /opt.
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$ROOT_DIR/.." && pwd)"

: "${FPPDIR:=/opt/fpp}"
if [[ -f "${FPPDIR}/scripts/common" ]]; then
  # Same trap as install_common.sh: FPP common expands unset LD_LIBRARY_PATH.
  set +eu
  # shellcheck disable=SC1090,SC1091
  . "${FPPDIR}/scripts/common" >/dev/null 2>&1 || true
  set -euo pipefail
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

export FPP_MONITOR_AGENT_CONFIG="$CONFIG_PATH"
export SHOWOPS_CONFIG_PATH="$CONFIG_PATH"

# One FPP-managed log. The agent reopens it on SIGHUP and when the inode changes.
mkdir -p "$(dirname "$CONFIG_PATH")" "$(dirname "$LOG_FILE")"
if ! touch "$LOG_FILE"; then
  echo "fpp-monitor-agent cannot write $LOG_FILE" >&2
  exit 1
fi

export FPP_MONITOR_AGENT_LOG="$LOG_FILE"
exec "$BIN_PATH" --config "$CONFIG_PATH"
