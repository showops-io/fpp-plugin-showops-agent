#!/usr/bin/env bash
set -euo pipefail

LOG_FILE="${FPP_MONITOR_AGENT_LOG_FILE:-/home/fpp/media/logs/fpp-monitor-agent-install.log}"

log() {
  local message="[fpp-monitor-agent] $*"
  if [[ -n "$LOG_FILE" ]] && ! is_dry_run; then
    ensure_dir "$(dirname "$LOG_FILE")"
    if have_command tee; then
      echo "$message" | tee -a "$LOG_FILE"
      return
    fi
    echo "$message" >>"$LOG_FILE" || true
    return
  fi
  echo "$message"
}

have_command() {
  command -v "$1" >/dev/null 2>&1
}

download_file() {
  local url="$1"
  local dest="$2"
  local status=""
  local attempt=1
  local max_attempts=6

  if have_command curl; then
    log "Copy/paste to debug download: curl -fSL -o \"$dest\" \"$url\""
    while [[ "$attempt" -le "$max_attempts" ]]; do
      status="$(curl -sSL -L -w "%{http_code}" -o "$dest" "$url" || true)"
      if [[ "$status" == "200" ]]; then
        return 0
      fi
      # 404/000: release assets may still be uploading after latest.json advances.
      if [[ "$status" != "404" && "$status" != "000" ]]; then
        log "Download failed (HTTP $status): $url"
        return 1
      fi
      log "Download not ready (HTTP $status), retry ${attempt}/${max_attempts}: $url"
      sleep $((attempt * 3))
      attempt=$((attempt + 1))
    done
    log "Download failed (HTTP $status): $url"
    return 1
  elif have_command wget; then
    log "Copy/paste to debug download: wget -O \"$dest\" \"$url\""
    while [[ "$attempt" -le "$max_attempts" ]]; do
      status="$(wget --server-response -O "$dest" "$url" 2>&1 | awk '/^  HTTP/{code=$2} END{print code}' || true)"
      if [[ "$status" == "200" ]]; then
        return 0
      fi
      if [[ "$status" != "404" ]]; then
        log "Download failed (HTTP $status): $url"
        return 1
      fi
      log "Download not ready (HTTP $status), retry ${attempt}/${max_attempts}: $url"
      sleep $((attempt * 3))
      attempt=$((attempt + 1))
    done
    log "Download failed (HTTP $status): $url"
    return 1
  else
    log "Neither curl nor wget found."
    return 1
  fi
}

sha256_file() {
  local file="$1"
  if have_command sha256sum; then
    sha256sum "$file" | awk '{print $1}'
  elif have_command shasum; then
    shasum -a 256 "$file" | awk '{print $1}'
  else
    log "No sha256 tool available."
    return 1
  fi
}

is_systemd() {
  [[ -d /run/systemd/system ]] && have_command systemctl
}

ensure_dir() {
  local dir="$1"
  if [[ ! -d "$dir" ]]; then
    mkdir -p "$dir"
  fi
}

can_sudo() {
  have_command sudo && sudo -n true >/dev/null 2>&1
}

is_dry_run() {
  [[ "${DRY_RUN:-0}" == "1" ]]
}

generate_install_run_id() {
  if [[ -r /proc/sys/kernel/random/uuid ]]; then
    cat /proc/sys/kernel/random/uuid
    return 0
  fi
  if have_command uuidgen; then
    uuidgen
    return 0
  fi
  if have_command openssl; then
    printf 'inst-%s-%s\n' "$(date -u +%Y%m%dT%H%M%SZ)" "$(openssl rand -hex 8)"
    return 0
  fi
  printf 'inst-%s-%s\n' "$(date -u +%Y%m%dT%H%M%SZ)" "$$"
}

# One ID per install/uninstall invocation for install logs and field support (correlate with support tickets).
log_install_session_start() {
  local action="$1"
  if [[ -z "${FPP_MONITOR_INSTALL_RUN_ID:-}" ]]; then
    local rid
    rid="$(generate_install_run_id)"
    export FPP_MONITOR_INSTALL_RUN_ID="${rid}"
  fi
  log "${action} begin install_run_id=${FPP_MONITOR_INSTALL_RUN_ID}"
}

run_cmd() {
  if is_dry_run; then
    log "DRY_RUN: $*"
    return 0
  fi
  "$@"
}

run_cmd_capture() {
  if is_dry_run; then
    log "DRY_RUN: $*"
    return 0
  fi
  "$@" 2>&1
}

run_cmd_sudo() {
  if can_sudo; then
    run_cmd sudo "$@"
  else
    run_cmd "$@"
  fi
}
