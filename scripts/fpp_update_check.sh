#!/usr/bin/env bash
#
# Reports whether a newer ShowOps agent is available, for FPP's Plugins page.
#
# FPP's PluginHasUpdates() first looks for unpulled commits in the plugin
# directory and only calls this script when it finds none -- which is our normal
# state, because the plugin code lives in this repo while the agent binary it
# installs ships as a release asset in fpp-agent-monitor. Without this hook FPP
# reports the plugin as current no matter how far behind the installed binary is.
#
# Contract (FPP www/api/controllers/plugin.php):
#   exit 0 and print "1" as the final stdout line to report an update available.
#   Anything else counts as "no update", so failures must still print "0".
#
# Applying the update needs no extra work here: FPP's upgrade_plugin runs
# scripts/fpp_install.sh after the (no-op) git pull, and that installs the
# newest release without touching an existing config.

set -uo pipefail

PLUGIN_DIR="${SHOWOPS_PLUGIN_DIR:-/home/fpp/media/plugins/showops-agent}"
AGENT_REPO_OWNER="${AGENT_REPO_OWNER:-showops-io}"
AGENT_REPO_NAME="${AGENT_REPO_NAME:-fpp-agent-monitor}"
# FPP runs this on every Plugins page render, so answer from cache rather than
# reaching across the WAN each time.
CACHE_FILE="${SHOWOPS_UPDATE_CHECK_CACHE:-/home/fpp/media/tmp/showops-agent-update-check}"
CACHE_TTL_SEC="${SHOWOPS_UPDATE_CHECK_TTL_SEC:-900}"
HTTP_TIMEOUT_SEC="${SHOWOPS_UPDATE_CHECK_TIMEOUT_SEC:-4}"
USER_AGENT="showops-agent-fpp-plugin"

# Diagnostics go to stderr; FPP reads the last line of stdout as the answer.
note() {
  echo "[showops-agent update check] $*" >&2
}

read_installed_version() {
  local path raw
  for path in "/opt/fpp-monitor-agent/VERSION" "${PLUGIN_DIR}/bin/VERSION"; do
    [[ -r "$path" ]] || continue
    raw="$(tr -d '[:space:]' <"$path" 2>/dev/null)" || continue
    if [[ -n "$raw" ]]; then
      printf '%s\n' "$raw"
      return 0
    fi
  done
  return 1
}

fetch_url() {
  local url="$1"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --max-time "$HTTP_TIMEOUT_SEC" -H "User-Agent: ${USER_AGENT}" "$url" 2>/dev/null
    return $?
  fi
  if command -v wget >/dev/null 2>&1; then
    wget -qO- --timeout="$HTTP_TIMEOUT_SEC" --header="User-Agent: ${USER_AGENT}" "$url" 2>/dev/null
    return $?
  fi
  note "neither curl nor wget is available"
  return 1
}

json_string_field() {
  local field="$1" body="$2"
  printf '%s' "$body" |
    sed -n "s/.*\"${field}\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" |
    head -n 1
}

resolve_latest_version() {
  local body version

  # The manifest is a static file, so a device cannot be rate limited out of
  # update discovery the way the GitHub API does under load.
  body="$(fetch_url "https://raw.githubusercontent.com/${AGENT_REPO_OWNER}/${AGENT_REPO_NAME}/main/latest.json")" || body=""
  version="$(json_string_field version "$body")"
  if [[ -n "$version" ]]; then
    printf '%s\n' "$version"
    return 0
  fi

  body="$(fetch_url "https://api.github.com/repos/${AGENT_REPO_OWNER}/${AGENT_REPO_NAME}/releases/latest")" || body=""
  version="$(json_string_field tag_name "$body")"
  if [[ -n "$version" ]]; then
    printf '%s\n' "$version"
    return 0
  fi

  return 1
}

file_age_sec() {
  local file="$1" mtime now
  mtime="$(stat -c %Y "$file" 2>/dev/null || stat -f %m "$file" 2>/dev/null)" || return 1
  [[ -n "$mtime" ]] || return 1
  now="$(date +%s)"
  printf '%s\n' "$((now - mtime))"
}

# Cached against the installed version, so an upgrade invalidates the entry
# instead of leaving the badge lit until the TTL expires.
read_cached_latest() {
  local installed="$1" age cached_installed cached_latest
  [[ -r "$CACHE_FILE" ]] || return 1
  age="$(file_age_sec "$CACHE_FILE")" || return 1
  [[ "$age" -ge 0 && "$age" -lt "$CACHE_TTL_SEC" ]] || return 1
  IFS=' ' read -r cached_installed cached_latest <"$CACHE_FILE" || return 1
  [[ "$cached_installed" == "$installed" && -n "$cached_latest" ]] || return 1
  printf '%s\n' "$cached_latest"
}

write_cache() {
  local installed="$1" latest="$2" dir
  dir="$(dirname "$CACHE_FILE")"
  mkdir -p "$dir" 2>/dev/null || return 0
  printf '%s %s\n' "$installed" "$latest" >"$CACHE_FILE" 2>/dev/null || true
}

# Compares the numeric segments only, so tags like v1.2.26 and 1.2.26-rc.1
# reduce to the same ordering api.php uses.
version_is_older() {
  awk -v a="$1" -v b="$2" '
    function version_parts(value, out,   count, index_in, index_out, segments) {
      sub(/^[vV]/, "", value)
      count = split(value, segments, /[^0-9]+/)
      index_out = 0
      for (index_in = 1; index_in <= count; index_in++) {
        if (segments[index_in] != "") out[++index_out] = segments[index_in] + 0
      }
      return index_out
    }
    BEGIN {
      left_len = version_parts(a, left)
      right_len = version_parts(b, right)
      if (left_len == 0 || right_len == 0) { print "0"; exit }
      len = (left_len > right_len) ? left_len : right_len
      if (len < 3) len = 3
      for (i = 1; i <= len; i++) {
        left_value = (i <= left_len) ? left[i] : 0
        right_value = (i <= right_len) ? right[i] : 0
        if (left_value < right_value) { print "1"; exit }
        if (left_value > right_value) { print "0"; exit }
      }
      print "0"
    }'
}

main() {
  local installed latest

  installed="$(read_installed_version)" || installed=""
  if [[ -z "$installed" ]]; then
    # Nothing installed to compare. An install, not an update badge, is the fix.
    note "no VERSION file found under /opt/fpp-monitor-agent or ${PLUGIN_DIR}/bin"
    echo 0
    return 0
  fi

  latest="$(read_cached_latest "$installed")" || latest=""
  if [[ -z "$latest" ]]; then
    latest="$(resolve_latest_version)" || latest=""
    if [[ -z "$latest" ]]; then
      note "could not resolve the latest release; reporting no update"
      echo 0
      return 0
    fi
    write_cache "$installed" "$latest"
  fi

  if [[ "$(version_is_older "$installed" "$latest")" == "1" ]]; then
    note "installed ${installed} is behind ${latest}"
    echo 1
  else
    echo 0
  fi
  return 0
}

main "$@"
