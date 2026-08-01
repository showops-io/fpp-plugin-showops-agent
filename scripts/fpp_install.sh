#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$ROOT_DIR/.." && pwd)"
. "$ROOT_DIR/install_common.sh"

log_install_session_start "install"

PLUGIN_DIR="/home/fpp/media/plugins/showops-agent"
CONFIG_PATH="/home/fpp/media/config/fpp-monitor-agent.json"
INSTALL_DIR="/opt/fpp-monitor-agent"
BIN_LINK="/usr/local/bin/fpp-monitor-agent"
FALLBACK_SCRIPT="$PLUGIN_DIR/system/fpp-monitor-agent.sh"
TMP_FALLBACK_DIR="/home/fpp/media/tmp"

if ! can_sudo; then
  INSTALL_DIR="$PLUGIN_DIR/bin"
  log "No sudo; using $INSTALL_DIR for binary install"
fi

BIN_PATH="$INSTALL_DIR/fpp-monitor-agent"

# Fallback version used only when the manifest and GitHub API are both unreachable.
# Update this whenever a new stable release ships.
DEFAULT_RELEASE_VERSION="v0.1.27"
RELEASE_VERSION="${RELEASE_VERSION:-}"
AGENT_REPO_OWNER="${AGENT_REPO_OWNER:-showops-io}"
AGENT_REPO_NAME="${AGENT_REPO_NAME:-fpp-agent-monitor}"

resolve_latest_tag() {
  local manifest_url="https://raw.githubusercontent.com/${AGENT_REPO_OWNER}/${AGENT_REPO_NAME}/main/latest.json"
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
  fi
  rm -f "$tmp"
  log "Failed to resolve latest tag from $manifest_url" >&2

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

RELEASE_BASE="${RELEASE_BASE:-https://github.com/${AGENT_REPO_OWNER}/${AGENT_REPO_NAME}/releases/download/${RESOLVED_TAG}}"

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
  if download_file "$RELEASE_BASE/$asset_tar" "$tmp_tar"; then
    install_mode="tar"
  else
    log "Tarball not found; falling back to binary download"
    if download_file "$RELEASE_BASE/$asset_bin" "$tmp_bin"; then
      install_mode="bin"
    else
      log "Failed to download $asset_tar or $asset_bin"
      rm -rf "$tmp_dir"
      exit 1
    fi
  fi
  if ! download_file "$RELEASE_BASE/$checksums_name" "$tmp_checksums"; then
    log "Failed to download $checksums_name"
    rm -rf "$tmp_dir"
    exit 1
  fi

  if [[ "$install_mode" == "tar" ]]; then
    expected_sha="$(awk "/$asset_tar/ {print \$1}" "$tmp_checksums")"
    asset_name="$asset_tar"
    asset_path="$tmp_tar"
  else
    expected_sha="$(awk "/$asset_bin/ {print \$1}" "$tmp_checksums")"
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
    run_cmd_sudo install -m 0755 "$extract_dir/fpp-monitor-agent" "$BIN_PATH"
    if [[ -f "$extract_dir/cloudflared" ]]; then
      run_cmd_sudo install -m 0755 "$extract_dir/cloudflared" "$INSTALL_DIR/cloudflared"
    else
      log "cloudflared not found in bundle; remote sessions will not work until installed"
    fi
  else
    log "Installing binary to $BIN_PATH"
    run_cmd_sudo install -m 0755 "$tmp_bin" "$BIN_PATH"
    log "cloudflared not bundled in this release; remote sessions will not work until installed"
  fi
  run_cmd_sudo sh -c "echo \"$RESOLVED_TAG\" > \"$INSTALL_DIR/VERSION\""

  if can_sudo; then
    run_cmd sudo ln -sf "$BIN_PATH" "$BIN_LINK"
  else
    log "No sudo; skipping symlink to $BIN_LINK"
  fi

  rm -rf "$tmp_dir"
fi

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
  "restart_fpp_command": "systemctl restart fpp"
}
JSON
  fi
else
  log "Config exists; leaving $CONFIG_PATH unchanged"
fi

if is_dry_run; then
  log "DRY_RUN: would ensure $CONFIG_PATH is writable by fpp"
else
  if can_sudo; then
    run_cmd_sudo chown fpp:fpp "$CONFIG_PATH" || true
    run_cmd_sudo chmod 664 "$CONFIG_PATH" || true
  else
    run_cmd chmod 664 "$CONFIG_PATH" || true
  fi
fi

if is_systemd; then
  log "Installing systemd service"
  if can_sudo; then
    run_cmd_sudo install -m 0644 "$REPO_ROOT/system/fpp-monitor-agent.service" /etc/systemd/system/fpp-monitor-agent.service
    run_cmd_sudo systemctl daemon-reload
    run_cmd_sudo systemctl enable fpp-monitor-agent.service
    restart_output="$(run_cmd_capture sudo systemctl restart fpp-monitor-agent.service)"
    restart_code=$?
    if [[ $restart_code -eq 0 ]]; then
      run_cmd_sudo systemctl --no-pager --full status fpp-monitor-agent.service || true
    else
      if [[ -n "$restart_output" ]]; then
        log "Systemd restart failed: $restart_output"
      else
        log "Systemd restart failed with exit code $restart_code"
      fi
      log "Falling back to runner"
      run_cmd mkdir -p "$PLUGIN_DIR/system"
      run_cmd install -m 0755 "$REPO_ROOT/system/fpp-monitor-agent.sh" "$FALLBACK_SCRIPT"
      run_cmd nohup "$FALLBACK_SCRIPT" >/dev/null 2>&1 &
    fi
  else
    log "Systemd present but no sudo; using fallback runner"
    run_cmd mkdir -p "$PLUGIN_DIR/system"
    run_cmd install -m 0755 "$REPO_ROOT/system/fpp-monitor-agent.sh" "$FALLBACK_SCRIPT"
    run_cmd nohup "$FALLBACK_SCRIPT" >/dev/null 2>&1 &
  fi
else
  log "Systemd not detected; installing fallback runner"
  run_cmd mkdir -p "$PLUGIN_DIR/system"
  run_cmd install -m 0755 "$REPO_ROOT/system/fpp-monitor-agent.sh" "$FALLBACK_SCRIPT"
  run_cmd nohup "$FALLBACK_SCRIPT" >/dev/null 2>&1 &
  if have_command crontab; then
    log "Registering fallback runner at boot via crontab"
    if is_dry_run; then
      log "DRY_RUN: would add @reboot $FALLBACK_SCRIPT to crontab"
    else
      (crontab -l 2>/dev/null | grep -v "fpp-monitor-agent.sh" ; echo "@reboot $FALLBACK_SCRIPT") | crontab -
    fi
  else
    log "crontab not available; fallback runner will not be auto-started"
  fi
fi

log "Install complete"
