#!/usr/bin/env bash
# FPP runs every plugin's scripts/postStart.sh after fppd starts. Players with
# systemd run the agent as fpp-monitor-agent.service, so this does nothing
# there. Without systemd (FPP in Docker) it starts the agent's supervisor as
# the fpp user, once.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "$ROOT_DIR/.." && pwd)"
SUPERVISOR="$PLUGIN_DIR/system/fpp-monitor-agent-supervise.sh"
BIN_PATH="$PLUGIN_DIR/bin/fpp-monitor-agent"
PLUGIN_REPO_NAME="${SHOWOPS_PLUGIN_REPO_NAME:-fpp-plugin-showops-agent}"
LOG_FILE="${MEDIADIR:-/home/fpp/media}/logs/plugin-${PLUGIN_REPO_NAME}.log"

if [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then
  exit 0
fi
if [[ ! -x "$BIN_PATH" || ! -f "$SUPERVISOR" ]]; then
  exit 0
fi
if pgrep -f "$SUPERVISOR" >/dev/null 2>&1; then
  exit 0
fi

if [[ "$(id -u)" -eq 0 ]]; then
  # The install log is written as root; the agent runs as fpp and must append to it.
  if [[ -f "$LOG_FILE" ]]; then
    chown fpp:fpp "$LOG_FILE" || true
  fi
  if command -v runuser >/dev/null 2>&1; then
    setsid runuser -u fpp -- bash "$SUPERVISOR" </dev/null >/dev/null 2>&1 &
  else
    setsid su -s /bin/bash fpp -c "bash '$SUPERVISOR'" </dev/null >/dev/null 2>&1 &
  fi
else
  setsid bash "$SUPERVISOR" </dev/null >/dev/null 2>&1 &
fi
