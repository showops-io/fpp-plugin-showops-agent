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

# Fallback version used only when the ShowOps manifest and GitHub API are both unreachable.
# Update this whenever a new stable release ships.
DEFAULT_RELEASE_VERSION="v1.2.63"
RELEASE_VERSION="${RELEASE_VERSION:-}"
AGENT_REPO_OWNER="${AGENT_REPO_OWNER:-showops-io}"
AGENT_REPO_NAME="${AGENT_REPO_NAME:-fpp-agent-monitor}"
SHOWOPS_API_BASE="${SHOWOPS_API_BASE:-https://api.showops.io}"

resolve_latest_tag() {
  local manifest_url="${SHOWOPS_API_BASE}/v1/agent/releases/latest"
  local github_manifest_url="https://raw.githubusercontent.com/${AGENT_REPO_OWNER}/${AGENT_REPO_NAME}/main/latest.json"
  local api_url="https://api.github.com/repos/${AGENT_REPO_OWNER}/${AGENT_REPO_NAME}/releases/latest"
  local body=""
  local tmp=""

  tmp="$(mktemp)"
  if download_file "$manifest_url" "$tmp" 1>&2; then
    body="$(cat "$tmp")"
    rm -f "$tmp"
    version="$(echo "$body" | sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
    if [[ -n "$version" ]]; then
      echo "$version"
      return 0
    fi
  else
    rm -f "$tmp"
    log "Failed to resolve latest tag from $manifest_url" >&2
  fi

  # Dual-publish fallback (remove after ShowOps channel is sole source of truth).
  tmp="$(mktemp)"
  if download_file "$github_manifest_url" "$tmp" 1>&2; then
    body="$(cat "$tmp")"
    rm -f "$tmp"
    version="$(echo "$body" | sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
    if [[ -n "$version" ]]; then
      echo "$version"
      return 0
    fi
  fi
  rm -f "$tmp"
  log "Failed to resolve latest tag from $github_manifest_url" >&2

  tmp="$(mktemp)"
  if ! download_file "$api_url" "$tmp" 1>&2; then
    rm -f "$tmp"
    log "Failed to resolve latest tag from $api_url" >&2
    return 1
  fi
  body="$(cat "$tmp")"
  rm -f "$tmp"

  tag="$(echo "$body" | sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -n 1)"
  if [[ -n "$tag" ]]; then
    echo "$tag"
    return 0
  fi
  return 1
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

RESOLVED_TAG="$RELEASE_VERSION"
if [[ -z "$RESOLVED_TAG" ]]; then
  RESOLVED_TAG="$(resolve_latest_tag || true)"
  if [[ -z "$RESOLVED_TAG" ]]; then
    log "WARNING: Could not resolve latest release tag from manifest or GitHub API."
    log "WARNING: Falling back to hardcoded default version: $DEFAULT_RELEASE_VERSION"
    log "WARNING: This may not be the latest release. Check network connectivity"
    log "WARNING: or set RELEASE_VERSION explicitly to suppress this warning."
    RESOLVED_TAG="$DEFAULT_RELEASE_VERSION"
  else
    log "Resolved latest tag: $RESOLVED_TAG"
  fi
fi

RELEASE_BASE="${RELEASE_BASE:-${SHOWOPS_API_BASE}/v1/agent/releases/${RESOLVED_TAG}}"
GITHUB_RELEASE_BASE="https://github.com/${AGENT_REPO_OWNER}/${AGENT_REPO_NAME}/releases/download/${RESOLVED_TAG}"

platform_arch="$($ROOT_DIR/detect_platform.sh)"
asset_tar="fpp-monitor-agent-linux-${platform_arch}.tar.gz"
asset_bin="fpp-monitor-agent-linux-${platform_arch}"
checksums_name="checksums.txt"

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
tmp_checksums="$tmp_dir/$checksums_name"
install_mode=""

log "Downloading release assets from $RELEASE_BASE"
log "Resolved asset URLs: $RELEASE_BASE/$asset_tar or $RELEASE_BASE/$asset_bin and $RELEASE_BASE/$checksums_name"
if is_dry_run; then
  log "DRY_RUN: would download $RELEASE_BASE/$asset_tar"
  log "DRY_RUN: would download $RELEASE_BASE/$asset_bin if tar missing"
  log "DRY_RUN: would download $RELEASE_BASE/$checksums_name"
  log "DRY_RUN: would verify checksum and install $BIN_PATH"
  log "DRY_RUN: would install cloudflared to $INSTALL_DIR/cloudflared"
  log "DRY_RUN: would write version file to $INSTALL_DIR/VERSION"
  rm -rf "$tmp_dir"
else
  download_release_asset() {
    local name="$1"
    local dest="$2"
    if download_file "$RELEASE_BASE/$name" "$dest"; then
      return 0
    fi
    # Dual-publish fallback while the ShowOps channel is still being seeded.
    log "ShowOps download failed for $name; trying GitHub Releases"
    download_file "$GITHUB_RELEASE_BASE/$name" "$dest"
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
  if ! download_release_asset "$checksums_name" "$tmp_checksums"; then
    log "Failed to download $checksums_name"
    rm -rf "$tmp_dir"
    exit 1
  fi

  if [[ "$install_mode" == "tar" ]]; then
    expected_sha="$(awk -v name="$asset_tar" '$2 == name { print $1; exit }' "$tmp_checksums")"
    asset_name="$asset_tar"
    asset_path="$tmp_tar"
  else
    expected_sha="$(awk -v name="$asset_bin" '$2 == name { print $1; exit }' "$tmp_checksums")"
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

# Self-update runs as User=fpp; keep plugin bin + downloads writable.
if ! is_dry_run && [[ -d "$INSTALL_DIR" ]]; then
  DOWNLOADS_DIR="$INSTALL_DIR/downloads"
  ensure_dir "$DOWNLOADS_DIR"
  if can_privileged; then
    run_privileged chown -R fpp:fpp "$INSTALL_DIR" || true
  fi
fi

# Migrate legacy config into plugindata (FPP-preferred location).
ensure_dir "$PLUGINDATA_DIR"
if [[ ! -f "$CONFIG_PATH" && -f "$LEGACY_CONFIG_PATH" ]]; then
  log "Migrating config from $LEGACY_CONFIG_PATH to $CONFIG_PATH"
  if is_dry_run; then
    log "DRY_RUN: would migrate legacy config"
  else
    run_cmd cp -a "$LEGACY_CONFIG_PATH" "$CONFIG_PATH"
    run_cmd rm -f "$LEGACY_CONFIG_PATH" || true
  fi
fi

restore_enrollment_config "$CONFIG_PATH"

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
  "restart_fpp_command": ""
}
JSON
  fi
else
  log "Config exists; leaving $CONFIG_PATH unchanged"
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

start_fallback_runner() {
  if is_dry_run; then
    log "DRY_RUN: would start fallback runner $FALLBACK_SCRIPT"
    return 0
  fi
  if [[ ! -x "$FALLBACK_SCRIPT" && -f "$FALLBACK_SCRIPT" ]]; then
    chmod 0755 "$FALLBACK_SCRIPT" || true
  fi
  if [[ -x "$BIN_PATH" ]]; then
    nohup "$BIN_PATH" --config "$CONFIG_PATH" >>"$LOG_FILE" 2>&1 &
    return 0
  fi
  if [[ -x "$FALLBACK_SCRIPT" ]]; then
    nohup "$FALLBACK_SCRIPT" >/dev/null 2>&1 &
  fi
}

# From here on, the binary is installed. Do not fail the FPP plugin install for
# systemd/crontab issues — ShowOps UI can still start the agent.
set +e

if is_systemd; then
  log "Installing systemd service"
  if can_privileged; then
    if is_dry_run; then
      log "DRY_RUN: would install $GENERATED_UNIT to /etc/systemd/system/fpp-monitor-agent.service"
    else
      run_privileged install -m 0644 "$GENERATED_UNIT" /etc/systemd/system/fpp-monitor-agent.service
    fi
    run_privileged systemctl daemon-reload || log "WARNING: systemctl daemon-reload failed"
    run_privileged systemctl enable fpp-monitor-agent.service || log "WARNING: systemctl enable failed"
    restart_output=""
    restart_code=0
    if is_dry_run; then
      log "DRY_RUN: systemctl restart fpp-monitor-agent.service"
    else
      restart_output="$(run_privileged systemctl restart fpp-monitor-agent.service 2>&1)"
      restart_code=$?
    fi
    if [[ $restart_code -eq 0 ]] || is_dry_run; then
      run_privileged systemctl --no-pager --full status fpp-monitor-agent.service || true
    else
      if [[ -n "$restart_output" ]]; then
        log "Systemd restart failed: $restart_output"
      else
        log "Systemd restart failed with exit code $restart_code"
      fi
      log "Falling back to direct agent start"
      start_fallback_runner
    fi
  else
    log "Systemd present but cannot write /etc/systemd (not root). Using fallback runner."
    log "To enable systemd later: install -m 0644 $GENERATED_UNIT /etc/systemd/system/fpp-monitor-agent.service && systemctl daemon-reload && systemctl enable --now fpp-monitor-agent.service"
    start_fallback_runner
  fi
else
  log "Systemd not detected; installing fallback runner"
  start_fallback_runner
  if have_command crontab; then
    log "Registering fallback runner at boot via crontab"
    if is_dry_run; then
      log "DRY_RUN: would add @reboot $FALLBACK_SCRIPT to crontab"
    else
      (crontab -l 2>/dev/null | grep -v "fpp-monitor-agent.sh" ; echo "@reboot $FALLBACK_SCRIPT") | crontab - || log "WARNING: crontab update failed"
    fi
  else
    log "crontab not available; fallback runner will not be auto-started"
  fi
fi

if [[ -x "$BIN_PATH" ]] || is_dry_run; then
  log "Install complete"
  exit 0
fi

log "Install finished but agent binary missing at $BIN_PATH"
exit 1
