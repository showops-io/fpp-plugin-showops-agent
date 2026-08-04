#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$ROOT_DIR/.." && pwd)"
. "$ROOT_DIR/install_common.sh"

log_install_session_start "uninstall"

PLUGIN_DIR="$REPO_ROOT"
# Prior install directory names (repoName migrations).
LEGACY_PLUGIN_DIRS=(
  "${MEDIADIR}/plugins/showops-agent"
  "${MEDIADIR}/plugins/fpp-monitor-agent"
)
BIN_LINK="/usr/local/bin/fpp-monitor-agent"
INSTALL_DIR="/opt/fpp-monitor-agent"
DATA_DIR="/var/lib/fpp-monitor-agent"
BIN_PATH_PLUGIN="$PLUGIN_DIR/bin/fpp-monitor-agent"
FALLBACK_SCRIPT="$PLUGIN_DIR/system/fpp-monitor-agent.sh"
CONFIG_PATH="${MEDIADIR}/config/fpp-monitor-agent.json"

if is_systemd; then
  if can_privileged; then
    run_privileged systemctl disable --now fpp-monitor-agent.service || true
    run_privileged rm -f /etc/systemd/system/fpp-monitor-agent.service
    run_privileged systemctl daemon-reload || true
  else
    log "Systemd present but cannot privilege-escalate; cannot fully remove service"
  fi
else
  run_cmd pkill -f "fpp-monitor-agent" || true
fi

if can_privileged; then
  run_privileged rm -f "$BIN_LINK"
  # Remove the full install prefix so cloudflared, VERSION, and future bundle
  # files do not linger after uninstall (stale paths confuse remote support).
  run_privileged rm -rf "$INSTALL_DIR"
  run_privileged rm -rf "$DATA_DIR"
else
  log "Cannot remove $INSTALL_DIR, $DATA_DIR, or $BIN_LINK without root"
  run_cmd rm -rf "$INSTALL_DIR" "$DATA_DIR" || true
fi

run_cmd rm -f "$BIN_PATH_PLUGIN" || true
run_cmd rm -f "$FALLBACK_SCRIPT" || true
run_cmd rm -rf "$PLUGIN_DIR/bin" "$PLUGIN_DIR/system" || true

for legacy in "${LEGACY_PLUGIN_DIRS[@]}"; do
  run_cmd rm -f "$legacy/bin/fpp-monitor-agent" || true
  run_cmd rm -f "$legacy/system/fpp-monitor-agent.sh" || true
  run_cmd rm -rf "$legacy/bin" "$legacy/system" || true
done

if have_command crontab; then
  if is_dry_run; then
    log "DRY_RUN: would remove crontab entry for fpp-monitor-agent.sh"
  else
    crontab -l 2>/dev/null | grep -v "fpp-monitor-agent.sh" | crontab - || true
  fi
fi

# PLUGIN_GUIDELINES.md §2.1: undo everything outside the plugin directory,
# including the agent config (tokens live here). Safe to re-run (rm -f).
if is_dry_run; then
  log "DRY_RUN: would remove config $CONFIG_PATH"
else
  run_cmd rm -f "$CONFIG_PATH" || true
  if [[ -f "$CONFIG_PATH" ]]; then
    log "Warning: could not remove $CONFIG_PATH (check permissions)"
  else
    log "Removed config $CONFIG_PATH"
  fi
fi

log "Uninstall complete"
