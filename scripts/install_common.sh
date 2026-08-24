#!/usr/bin/env bash
set -euo pipefail

# Resolve FPP log directory the supported way (§1). Fall back for dry-run off-box.
: "${FPPDIR:=/opt/fpp}"
if [[ -f "${FPPDIR}/scripts/common" ]]; then
  # FPP's scripts/common expands unset vars (e.g. LD_LIBRARY_PATH). With our
  # `set -u`, that aborts the whole install before any log line is written —
  # FPP Plugin Manager then only shows "Could not properly install plugin".
  # Disable -e/-u only while sourcing; restore nounset + errexit after.
  set +eu
  # shellcheck disable=SC1090,SC1091
  . "${FPPDIR}/scripts/common" >/dev/null 2>&1 || true
  set -euo pipefail
fi
: "${MEDIADIR:=/home/fpp/media}"
: "${LOGDIR:=${MEDIADIR}/logs}"

# PLUGIN_GUIDELINES.md §1: exactly one runtime log at ${LOGDIR}/plugin-<repoName>.log
PLUGIN_REPO_NAME="${SHOWOPS_PLUGIN_REPO_NAME:-fpp-plugin-showops-agent}"
LOG_FILE="${FPP_MONITOR_AGENT_LOG_FILE:-${LOGDIR}/plugin-${PLUGIN_REPO_NAME}.log}"

log() {
  local message="[${PLUGIN_REPO_NAME}] $*"
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
  # Bound hangs so FPP Plugin Manager / install hooks cannot stall forever.
  local connect_timeout_sec=15
  local max_time_sec=180

  if have_command curl; then
    log "Copy/paste to debug download: curl -fSL --connect-timeout ${connect_timeout_sec} --max-time ${max_time_sec} -o \"$dest\" \"$url\""
    while [[ "$attempt" -le "$max_attempts" ]]; do
      status="$(curl -sSL -L --connect-timeout "$connect_timeout_sec" --max-time "$max_time_sec" -w "%{http_code}" -o "$dest" "$url" || true)"
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
    log "Copy/paste to debug download: wget --timeout=${max_time_sec} -O \"$dest\" \"$url\""
    while [[ "$attempt" -le "$max_attempts" ]]; do
      status="$(wget --server-response --timeout="$max_time_sec" --tries=1 -O "$dest" "$url" 2>&1 | awk '/^  HTTP/{code=$2} END{print code}' || true)"
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

is_root() {
  [[ "$(id -u)" -eq 0 ]]
}

# Install scripts already run as root on FPP (PLUGIN_GUIDELINES.md §2.4 — no sudo).
# Keep a non-root path only for local DRY_RUN / developer machines.
can_privileged() {
  is_root || (have_command sudo && sudo -n true >/dev/null 2>&1)
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

# Prefer direct root execution; only escalate when not root (dev hosts).
run_privileged() {
  if is_root; then
    run_cmd "$@"
  elif have_command sudo && sudo -n true >/dev/null 2>&1; then
    run_cmd sudo "$@"
  else
    run_cmd "$@"
  fi
}

# Back-compat aliases used by older script revisions / local wrappers.
can_sudo() { can_privileged; }
run_cmd_sudo() { run_privileged "$@"; }

# FPP Plugin Manager "Reinstall All" (prompted after FPPOS) is uninstall+install.
# Keep enrollment outside plugindata so pairing survives that cycle.
# Deleted only by explicit Unpair in the plugin UI.
enrollment_stash_path() {
  echo "${MEDIADIR}/config/showops-agent-enrollment.json"
}

stash_enrollment_config() {
  local src="$1"
  local dest
  dest="$(enrollment_stash_path)"
  if [[ ! -f "$src" ]]; then
    return 0
  fi
  if is_dry_run; then
    log "DRY_RUN: would stash enrollment from $src to $dest"
    return 0
  fi
  ensure_dir "$(dirname "$dest")"
  run_cmd cp -a "$src" "$dest"
  if can_privileged; then
    run_privileged chmod 600 "$dest" || true
    run_privileged chown fpp:fpp "$dest" || true
  else
    run_cmd chmod 600 "$dest" || true
  fi
  log "Stashed enrollment to $dest (survives plugin reinstall after FPP OS upgrade)"
}

restore_enrollment_config() {
  local dest="$1"
  local src
  src="$(enrollment_stash_path)"
  if [[ -f "$dest" ]]; then
    return 0
  fi
  if [[ ! -f "$src" ]]; then
    return 0
  fi
  if is_dry_run; then
    log "DRY_RUN: would restore enrollment from $src to $dest"
    return 0
  fi
  ensure_dir "$(dirname "$dest")"
  run_cmd cp -a "$src" "$dest"
  log "Restored enrollment from $src"
}
