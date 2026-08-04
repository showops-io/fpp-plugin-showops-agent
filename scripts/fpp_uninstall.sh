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
BIN_PATH_PLUGIN="$PLUGIN_DIR/bin/fpp-monitor-agent"
FALLBACK_SCRIPT="$PLUGIN_DIR/system/fpp-monitor-agent.sh"
CONFIG_PATH="${MEDIADIR}/config/fpp-monitor-agent.json"
KEEP_CONFIG="${KEEP_CONFIG:-0}"

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
else
  log "Cannot remove $INSTALL_DIR or $BIN_LINK without root"
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

if [[ "${PURGE:-0}" == "1" ]]; then
  log "PURGE=1 set; removing config"
  run_cmd rm -f "$CONFIG_PATH"
  log "Uninstall complete (config removed)"
elif [[ "$KEEP_CONFIG" == "1" ]]; then
  log "KEEP_CONFIG=1 set; retaining config at $CONFIG_PATH"
  log "Uninstall complete (config retained)"
else
  log "Clearing pairing fields in $CONFIG_PATH (preserving api_base_url, intervals, and other tunables)"
  if [[ -f "$CONFIG_PATH" ]]; then
    if is_dry_run; then
      log "DRY_RUN: would merge-clear pairing/enrollment fields via JSON merge"
    elif ! have_command python3; then
      log "python3 not found; cannot safely update $CONFIG_PATH. Pairing fields not cleared."
    elif ! CONFIG_PATH="$CONFIG_PATH" python3 <<'PY'
import json
import os
import sys

path = os.environ["CONFIG_PATH"]
keys = {
    "enrollment_token": "",
    "device_id": "",
    "device_token": "",
    "device_fingerprint": "",
    "pairing_requested": False,
    "pairing_request_id": "",
    "pairing_code": "",
    "pairing_expires_at": "",
    "pairing_status": "",
    "unpair_requested": False,
}

with open(path, "r", encoding="utf-8") as handle:
    data = json.load(handle)
if not isinstance(data, dict):
    sys.exit("config root must be an object")
for key, value in keys.items():
    data[key] = value
tmp = path + ".tmp"
with open(tmp, "w", encoding="utf-8") as handle:
    json.dump(data, handle, indent=2)
    handle.write("\n")
os.replace(tmp, path)
PY
    then
      log "Failed to merge-clear pairing fields in $CONFIG_PATH (invalid JSON or permission); file unchanged"
    fi
  else
    log "Config not found; nothing to clear"
  fi
  log "Uninstall complete (pairing cleared)"
fi
