#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$ROOT_DIR/.." && pwd)"
. "$ROOT_DIR/install_common.sh"

log_install_session_start "install"

# Keep artifacts inside the plugin / FPP media tree (PLUGIN_GUIDELINES.md §5).
PLUGIN_DIR="$REPO_ROOT"
PLUGIN_REPO_NAME="${SHOWOPS_PLUGIN_REPO_NAME:-fpp-plugin-showops-agent}"
PLUGINDATA_DIR="${MEDIADIR}/plugindata/${PLUGIN_REPO_NAME}"
CONFIG_PATH="${PLUGINDATA_DIR}/fpp-monitor-agent.json"
LEGACY_CONFIG_PATH="${MEDIADIR}/config/fpp-monitor-agent.json"
INSTALL_DIR="$PLUGIN_DIR/bin"
BIN_PATH="$INSTALL_DIR/fpp-monitor-agent"
FALLBACK_SCRIPT="$PLUGIN_DIR/system/fpp-monitor-agent.sh"
TMP_FALLBACK_DIR="${MEDIADIR}/tmp"
LEGACY_OPT_DIR="/opt/fpp-monitor-agent"
LEGACY_BIN_LINK="/usr/local/bin/fpp-monitor-agent"

SHOWOPS_API_BASE="${SHOWOPS_API_BASE:-https://api.showops.io}"
AGENT_VERSION_FILE="$REPO_ROOT/AGENT_VERSION"
CHECKSUMS_FILE="$REPO_ROOT/checksums.txt"

read_committed_version() {
  if [[ ! -s "$AGENT_VERSION_FILE" ]]; then
    log "AGENT_VERSION is missing"
    return 1
  fi
  if [[ ! -s "$CHECKSUMS_FILE" ]]; then
    log "checksums.txt is missing"
    return 1
  fi
  local version
  version="$(tr -d '[:space:]' < "$AGENT_VERSION_FILE")"
  if [[ -z "$version" ]]; then
    log "AGENT_VERSION is empty"
    return 1
  fi
  RESOLVED_TAG="$version"
}

ensure_tmpdir() {
  local tmp_free=""
  if have_command df; then
    tmp_free="$(df -Pm /tmp 2>/dev/null | awk 'NR==2 {print $4}')"
  fi
  if [[ -n "$tmp_free" && "$tmp_free" -lt 120 ]]; then
    ensure_dir "$TMP_FALLBACK_DIR"
    export TMPDIR="$TMP_FALLBACK_DIR"
    log "Low /tmp space (${tmp_free}MB). Using TMPDIR=$TMPDIR"
  fi
}

ensure_tmpdir

read_committed_version || exit 1
log "Installing agent version $RESOLVED_TAG named in this plugin"

RELEASE_BASE="${RELEASE_BASE:-${SHOWOPS_API_BASE}/v1/agent/releases/${RESOLVED_TAG}}"

platform_arch="$($ROOT_DIR/detect_platform.sh)"
asset_tar="fpp-monitor-agent-linux-${platform_arch}.tar.gz"
asset_bin="fpp-monitor-agent-linux-${platform_arch}"

if ! is_dry_run; then
  ensure_dir "$PLUGIN_DIR"
  ensure_dir "$(dirname "$CONFIG_PATH")"
fi

log "Installing for platform: $platform_arch"

if ! is_dry_run; then
  ensure_dir "$INSTALL_DIR"
fi

tmp_dir="$(mktemp -d)"
tmp_tar="$tmp_dir/$asset_tar"
tmp_bin="$tmp_dir/$asset_bin"
install_mode=""

checksum_for() {
  local name="$1"
  # First exact filename match. Do not use awk's exit: the listing check treats
  # that word as a shell exit, and this function is called inside $(...).
  awk -v name="$name" '$2 == name && !found { print $1; found = 1 }' "$CHECKSUMS_FILE"
}

if [[ -z "$(checksum_for "$asset_bin")" && -z "$(checksum_for "$asset_tar")" ]]; then
  log "Checksum for $asset_bin or $asset_tar not found in checksums.txt"
  rm -rf "$tmp_dir"
  exit 1
fi

log "Downloading release assets from $RELEASE_BASE"
log "Resolved asset URLs: $RELEASE_BASE/$asset_tar or $RELEASE_BASE/$asset_bin"
if is_dry_run; then
  log "DRY_RUN: would download $RELEASE_BASE/$asset_tar"
  log "DRY_RUN: would download $RELEASE_BASE/$asset_bin if tar missing"
  log "DRY_RUN: would verify checksum from $CHECKSUMS_FILE and install $BIN_PATH"
  log "DRY_RUN: would install cloudflared to $INSTALL_DIR/cloudflared"
  log "DRY_RUN: would write version file to $INSTALL_DIR/VERSION"
  rm -rf "$tmp_dir"
else
  download_release_asset() {
    local name="$1"
    local dest="$2"
    download_file "$RELEASE_BASE/$name" "$dest"
  }

  # Prefer the slim binary (~7MB) over the tarball (~20MB). Small FPP boards
  # often fail tar downloads/extracts on /tmp space or timeouts.
  if download_release_asset "$asset_bin" "$tmp_bin"; then
    install_mode="bin"
  else
    log "Binary download failed; falling back to tarball"
    if download_release_asset "$asset_tar" "$tmp_tar"; then
      install_mode="tar"
    else
      log "Failed to download $asset_bin or $asset_tar"
      rm -rf "$tmp_dir"
      exit 1
    fi
  fi
  if [[ "$install_mode" == "tar" ]]; then
    expected_sha="$(checksum_for "$asset_tar")"
    asset_name="$asset_tar"
    asset_path="$tmp_tar"
  else
    expected_sha="$(checksum_for "$asset_bin")"
    asset_name="$asset_bin"
    asset_path="$tmp_bin"
  fi
  if [[ -z "$expected_sha" ]]; then
    log "Checksum for $asset_name not found in checksums.txt"
    rm -rf "$tmp_dir"
    exit 1
  fi

  if ! actual_sha="$(sha256_file "$asset_path")"; then
    log "Failed to compute sha256 for downloaded asset"
    rm -rf "$tmp_dir"
    exit 1
  fi
  if [[ "$expected_sha" != "$actual_sha" ]]; then
    log "Checksum mismatch for downloaded asset"
    rm -rf "$tmp_dir"
    exit 1
  fi

  if [[ "$install_mode" == "tar" ]]; then
    extract_dir="$tmp_dir/extract"
    ensure_dir "$extract_dir"
    tar -xzf "$tmp_tar" -C "$extract_dir"

    if [[ ! -f "$extract_dir/fpp-monitor-agent" ]]; then
      log "Bundle missing fpp-monitor-agent"
      rm -rf "$tmp_dir"
      exit 1
    fi

    log "Installing bundle to $INSTALL_DIR"
    ensure_dir "$INSTALL_DIR"
    run_cmd install -m 0755 "$extract_dir/fpp-monitor-agent" "$BIN_PATH"
    if [[ -f "$extract_dir/cloudflared" ]]; then
      run_cmd install -m 0755 "$extract_dir/cloudflared" "$INSTALL_DIR/cloudflared"
    else
      log "cloudflared not found in bundle; remote sessions will not work until installed"
    fi
  else
    log "Installing binary to $BIN_PATH"
    ensure_dir "$INSTALL_DIR"
    run_cmd install -m 0755 "$tmp_bin" "$BIN_PATH"
    log "cloudflared not bundled in this release; remote sessions will not work until installed"
  fi
  echo "$RESOLVED_TAG" > "$INSTALL_DIR/VERSION"

  # Remove legacy /opt layout left by older plugin installs.
  if can_privileged; then
    run_privileged rm -f "$LEGACY_BIN_LINK" || true
    run_privileged rm -rf "$LEGACY_OPT_DIR" || true
  else
    run_cmd rm -f "$LEGACY_BIN_LINK" || true
    run_cmd rm -rf "$LEGACY_OPT_DIR" || true
  fi

  rm -rf "$tmp_dir"
fi

# Migrate legacy config into plugindata (FPP-preferred location).
if ! is_dry_run; then
  ensure_dir "$PLUGINDATA_DIR"
fi
if [[ ! -f "$CONFIG_PATH" && -f "$LEGACY_CONFIG_PATH" ]]; then
  log "Migrating config from $LEGACY_CONFIG_PATH to $CONFIG_PATH"
  if is_dry_run; then
    log "DRY_RUN: would migrate legacy config"
  else
    run_cmd cp -a "$LEGACY_CONFIG_PATH" "$CONFIG_PATH"
    run_cmd rm -f "$LEGACY_CONFIG_PATH" || true
  fi
fi

migrate_legacy_enrollment_stash "$CONFIG_PATH"

if [[ ! -f "$CONFIG_PATH" ]]; then
  log "Writing default config to $CONFIG_PATH"
  if is_dry_run; then
    log "DRY_RUN: would write config template"
  else
    cat <<'JSON' > "$CONFIG_PATH"
{
  "api_base_url": "https://api.showops.io",
  "enrollment_token": "",
  "device_id": "",
  "device_token": "",
  "device_fingerprint": "",
  "pairing_requested": false,
  "pairing_request_id": "",
  "pairing_code": "",
  "pairing_expires_at": "",
  "pairing_status": "",
  "unpair_requested": false,
  "cloudflared_token": "",
  "cloudflared_hostname": "",
  "heartbeat_interval_sec": 60,
  "command_poll_interval_sec": 30,
  "reboot_enabled": false,
  "backup_enabled": false,
  "location_enabled": false,
  "fpp_collect_enabled": false,
  "update": {"enabled": false, "channel": "stable", "allow_downgrade": false}
}
JSON
  fi
else
  log "Config exists; applying catalog defaults without wiping pairing"
  if ! is_dry_run; then
    php -r '
      $path = $argv[1];
      $data = json_decode(file_get_contents($path), true);
      if (!is_array($data)) { fwrite(STDERR, "config is not a JSON object\n"); exit(1); }
      if (!array_key_exists("fpp_collect_enabled", $data)) { $data["fpp_collect_enabled"] = false; }
      if (!array_key_exists("location_enabled", $data)) { $data["location_enabled"] = false; }
      if (!array_key_exists("backup_enabled", $data)) { $data["backup_enabled"] = false; }
      if (!array_key_exists("reboot_enabled", $data)) { $data["reboot_enabled"] = false; }
      $update = (isset($data["update"]) && is_array($data["update"])) ? $data["update"] : array();
      $update["enabled"] = false;
      if (!isset($update["channel"])) { $update["channel"] = "stable"; }
      if (!isset($update["allow_downgrade"])) { $update["allow_downgrade"] = false; }
      $data["update"] = $update;
      file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    ' "$CONFIG_PATH"
  fi
fi

if is_dry_run; then
  log "DRY_RUN: would ensure $CONFIG_PATH is writable by fpp"
else
  if can_privileged; then
    run_privileged chown -R fpp:fpp "$PLUGINDATA_DIR" || true
    run_privileged chmod 700 "$PLUGINDATA_DIR" || true
    run_privileged chmod 600 "$CONFIG_PATH" || true
  else
    run_cmd chmod 700 "$PLUGINDATA_DIR" || true
    run_cmd chmod 600 "$CONFIG_PATH" || true
  fi
fi

write_unit_file() {
  local dest="$1"
  local unit_src="$REPO_ROOT/system/fpp-monitor-agent.service"
  if is_dry_run; then
    log "DRY_RUN: would write systemd unit to $dest"
    return 0
  fi
  sed \
    -e "s|__PLUGIN_DIR__|${PLUGIN_DIR}|g" \
    -e "s|__CONFIG_PATH__|${CONFIG_PATH}|g" \
    -e "s|__BIN_PATH__|${BIN_PATH}|g" \
    -e "s|__LOG_FILE__|${LOG_FILE}|g" \
    "$unit_src" >"$dest"
}

# Always render a concrete unit into the plugin tree (support / manual enable).
GENERATED_UNIT="$PLUGIN_DIR/system/fpp-monitor-agent.generated.service"
write_unit_file "$GENERATED_UNIT"

# Wrapper already lives in the plugin tree — never `install` a file onto itself
# (GNU install fails with "same file" and aborts the whole FPP plugin install).
if [[ -f "$FALLBACK_SCRIPT" ]]; then
  run_cmd chmod 0755 "$FALLBACK_SCRIPT" || true
elif [[ -f "$REPO_ROOT/system/fpp-monitor-agent.sh" ]]; then
  run_cmd install -m 0755 "$REPO_ROOT/system/fpp-monitor-agent.sh" "$FALLBACK_SCRIPT" || true
fi

if ! is_systemd; then
  log "Systemd is required. FPP images provide it; refusing to start the agent as root."
  exit 1
fi

log "Installing systemd service"
if ! can_privileged; then
  log "Cannot write /etc/systemd (not root). Reinstall the plugin from FPP Plugin Manager."
  exit 1
fi
if is_dry_run; then
  log "DRY_RUN: would install $GENERATED_UNIT to /etc/systemd/system/fpp-monitor-agent.service"
  log "DRY_RUN: systemctl restart fpp-monitor-agent.service"
else
  run_privileged install -m 0644 "$GENERATED_UNIT" /etc/systemd/system/fpp-monitor-agent.service
  run_privileged systemctl daemon-reload
  run_privileged systemctl enable fpp-monitor-agent.service
  # Restart after this script returns. Update Agent in ShowOps calls FPP's
  # plugin upgrade from the agent process; systemctl restart inside that
  # request deadlocks, because systemd waits for the agent and the agent
  # waits for this script.
  restart_unit="showops-agent-restart-$(date +%s)"
  systemctl_bin="$(command -v systemctl)"
  if run_privileged systemd-run --collect --unit="$restart_unit" --on-active=5s --timer-property=AccuracySec=1s "$systemctl_bin" restart fpp-monitor-agent.service; then
    log "Scheduled agent restart in 5s ($restart_unit)"
  else
    log "systemd-run failed; restarting immediately"
    if ! restart_output="$(run_privileged systemctl restart fpp-monitor-agent.service 2>&1)"; then
      log "Systemd restart failed: $restart_output"
      exit 1
    fi
    run_privileged systemctl --no-pager --full status fpp-monitor-agent.service || true
  fi
fi

if [[ -x "$BIN_PATH" ]] || is_dry_run; then
  log "Install complete"
  exit 0
fi

log "Install finished but agent binary missing at $BIN_PATH"
exit 1
