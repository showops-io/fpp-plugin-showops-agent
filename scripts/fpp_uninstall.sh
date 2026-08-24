#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$ROOT_DIR/.." && pwd)"
. "$ROOT_DIR/install_common.sh"

log_install_session_start "uninstall"

PLUGIN_DIR="$REPO_ROOT"
PLUGIN_REPO_NAME="${SHOWOPS_PLUGIN_REPO_NAME:-fpp-plugin-showops-agent}"
PLUGINDATA_DIR="${MEDIADIR}/plugindata/${PLUGIN_REPO_NAME}"
CONFIG_PATH="${PLUGINDATA_DIR}/fpp-monitor-agent.json"
LEGACY_CONFIG_PATH="${MEDIADIR}/config/fpp-monitor-agent.json"
LEGACY_PLUGIN_DIRS=(
  "${MEDIADIR}/plugins/showops-agent"
  "${MEDIADIR}/plugins/fpp-monitor-agent"
)
LEGACY_OPT_DIR="/opt/fpp-monitor-agent"
LEGACY_DATA_DIR="/var/lib/fpp-monitor-agent"
LEGACY_BIN_LINK="/usr/local/bin/fpp-monitor-agent"
BIN_PATH_PLUGIN="$PLUGIN_DIR/bin/fpp-monitor-agent"

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

# Legacy out-of-tree paths from older installs.
if can_privileged; then
  run_privileged rm -f "$LEGACY_BIN_LINK" || true
  run_privileged rm -rf "$LEGACY_OPT_DIR" "$LEGACY_DATA_DIR" || true
else
  run_cmd rm -f "$LEGACY_BIN_LINK" || true
  run_cmd rm -rf "$LEGACY_OPT_DIR" "$LEGACY_DATA_DIR" || true
fi

# Remove installed binary under plugin bin/; leave tracked plugin sources (wrapper, PHP) intact.
run_cmd rm -f "$BIN_PATH_PLUGIN" || true
run_cmd rm -rf "$PLUGIN_DIR/bin" || true

for legacy in "${LEGACY_PLUGIN_DIRS[@]}"; do
  run_cmd rm -f "$legacy/bin/fpp-monitor-agent" || true
  run_cmd rm -rf "$legacy/bin" || true
done

if have_command crontab; then
  if is_dry_run; then
    log "DRY_RUN: would remove crontab entry for fpp-monitor-agent.sh"
  else
    crontab -l 2>/dev/null | grep -v "fpp-monitor-agent.sh" | crontab - || true
  fi
fi

# PLUGIN_GUIDELINES.md §2.1: remove plugindata + any legacy config.
# Stash enrollment first so FPP "Reinstall All" after FPPOS can restore pairing.
if [[ -f "$CONFIG_PATH" ]]; then
  stash_enrollment_config "$CONFIG_PATH"
elif [[ -f "$LEGACY_CONFIG_PATH" ]]; then
  stash_enrollment_config "$LEGACY_CONFIG_PATH"
fi
if is_dry_run; then
  log "DRY_RUN: would remove config $CONFIG_PATH and $LEGACY_CONFIG_PATH"
  log "DRY_RUN: would remove plugindata dir $PLUGINDATA_DIR"
else
  run_cmd rm -f "$CONFIG_PATH" "$LEGACY_CONFIG_PATH" || true
  run_cmd rm -rf "$PLUGINDATA_DIR" || true
  log "Removed agent config and plugindata"
fi

log "Uninstall complete"
