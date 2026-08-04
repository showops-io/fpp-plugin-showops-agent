# FPP ShowOps monitor plugin — integration contract

**Version:** 1.5.0  
**Slice:** #2 — follows monitor-agent plugin slice #1; scaling and deferral notes live in company issue **SHO-253**.  
**Status:** Frozen interfaces below are covered by CI (`scripts/verify_plugin_contract.sh`).

This document is the **versioned boundary** between:

- This repo (FPP plugin: install shell, systemd glue, `www/showops.php` UI), and
- The Go agent binary ([fpp-agent-monitor](https://github.com/showops-io/fpp-agent-monitor)), which reads/writes the shared JSON config and calls ShowOps HTTP APIs.

Breaking changes to paths, config semantics, or plugin UI actions require **bumping `contractVersion`** in `docs/contract-fingerprints.json` and updating this file.

---

## 1. Frozen filesystem paths

| Symbol | Path | Owner |
|--------|------|--------|
| User config | `/home/fpp/media/plugindata/fpp-plugin-showops-agent/fpp-monitor-agent.json` | Installer creates plugindata dir; agent + UI read/write (FPP guidelines §5) |
| Plugin root | `/home/fpp/media/plugins/fpp-plugin-showops-agent` | FPP plugin manager (`repoName`) + `scripts/fpp_install.sh` |
| Agent binary | `{plugin root}/bin/fpp-monitor-agent` | Installer (in-tree; no `/opt`) |
| Fallback wrapper | `{plugin root}/system/fpp-monitor-agent.sh` | Invoked by systemd unit or manual fallback; appends to plugin log |
| Systemd unit | `fpp-monitor-agent.service` (file under `/etc/systemd/system/` or `/lib/systemd/system/`) | Installer (paths substituted at install) |
| Release tag file | `{plugin root}/bin/VERSION` | Installer |
| Plugin log | `/home/fpp/media/logs/plugin-fpp-plugin-showops-agent.log` | Installer / wrapper / uninstaller (append; FPP rotates) |

**Legacy (migrated or cleaned on install/uninstall):** `/home/fpp/media/config/fpp-monitor-agent.json`, `/opt/fpp-monitor-agent/`, `/var/lib/fpp-monitor-agent/`, and old plugin dirs `showops-agent` / `fpp-monitor-agent`.

---

## 2. Config file (`fpp-monitor-agent.json`)

The **authoritative field list** for operators is in the root [README](../README.md#configuration). The agent binary may persist additional keys; the plugin UI depends on at least:

| Key | Plugin UI usage |
|-----|------------------|
| `device_id` | Enrollment / paired state |
| `last_heartbeat_ts` | Status card (“Last Heartbeat”) |
| `pairing_requested`, `pairing_request_id`, `pairing_code`, `pairing_expires_at`, `pairing_status` | Pairing flow |
| `unpair_requested` | Unpair flow |
| `enrollment_token` | Cleared/updated during pair/unpair transitions |
| `api_base_url` | Cleared during pair/unpair (agent re-resolves) |

**Encoding:** UTF-8 JSON object. Pretty-print is optional.

**Install / reinstall:** The installer writes the **full** default schema on first install and must **never** overwrite an existing file on reinstall.

### Uninstall (full cleanup)

Uninstall **deletes** plugindata (`fpp-monitor-agent.json`), the systemd unit, `{plugin root}/bin/`, legacy `/opt` + `/var/lib` + `media/config` paths, and related symlinks/crontab entries. Tracked plugin sources (wrapper, PHP) remain until FPP removes the plugin directory. Reinstall creates a fresh default config.

Default `restart_fpp_command` is **empty**. Prefer FPP’s `restartFlag` over shelling `systemctl restart fpp` (PLUGIN_GUIDELINES.md §3.6 / §4.1).

### Install / uninstall session correlation

Each `fpp_install.sh` and `fpp_uninstall.sh` invocation logs a line early in the run:

`[fpp-plugin-showops-agent] <install|uninstall> begin install_run_id=<id>`

Environment variable **`FPP_MONITOR_INSTALL_RUN_ID`** is exported for the script lifetime (tests may preset it). Operators can grep `install_run_id` in `/home/fpp/media/logs/plugin-fpp-plugin-showops-agent.log` when opening support tickets. Outbound HTTP correlation for the Go agent remains separate (see §4).

---

## 3. FPP plugin web surface (`www/showops.php`)

**Entry:** FPP loads the page via `menu.inc` → `showops.php` (path under plugin root).

**POST `action` values** (form field `action`):

| Value | Behavior |
|-------|----------|
| `pair` | Set pairing flags in config; restart agent |
| `unpair` | Request unpair; restart agent |
| `restart` | Restart agent service / fallback runner |
| `tail` | Refresh log snippet in UI |

CI asserts these four actions exist in `www/showops.php`. New actions require a contract bump and a fingerprint update.

---

## 4. Correlation and observability (agent HTTP)

The Go agent should attach a stable correlation identifier on **outbound HTTPS** to ShowOps so support can tie plugin restarts, heartbeats, and command polls to one device/session:

- **Header:** `ShowOps-Correlation-Id`
- **Value:** Prefer existing `device_id` once enrolled; before enroll, use a one-time UUID generated at process start and rotate after successful enrollment if needed.

This repo does not emit the header (PHP UI is local-only); **fpp-agent-monitor** implements it. Plugin contract version bumps do not require agent releases unless paths or config semantics change.

---

## 5. Security boundary (show network)

- **Leaves the LAN:** TLS to ShowOps API (`api_base_url`), GitHub release URLs for binary updates, and optional cloudflared endpoints for remote support — as configured by the agent.
- **Stays on the Pi:** FPP plugin UI (HTTP to local FPP), config file on disk, local logs under `/home/fpp/media/logs/` when the installer writes them.
- **Secrets:** `device_token`, `enrollment_token`, `cloudflared_token` must not be logged by the plugin UI (UI does not print raw tokens).

---

## 6. SLO targets (informative, not CI-enforced)

Aligned with architect checkpoint **SHO-253** — targets for product/ops, not automated gates yet:

| Signal | Target (v1 field ops) |
|--------|-------------------------|
| Heartbeat success → API `2xx` | ≥ 99% over 24h per device (excluding operator maintenance windows) |
| Config write (pair/unpair) → visible in UI on refresh | \< 5 s |
| False “offline” flip (dashboard) | \< 1 per show-night per device under normal WAN |

---

## 7. Agent release tarball (binary contract)

The plugin downloads release assets from [fpp-agent-monitor](https://github.com/jlwright325/fpp-agent-monitor). The tarball must contain:

- `fpp-monitor-agent` (executable)
- Optional `cloudflared` (executable) for remote sessions

Checksum verification uses `checksums.txt` from the same release. **Do not** change asset naming without updating `scripts/fpp_install.sh` and this document.

---

## 8. Update detection for FPP's Plugins page

FPP's `PluginHasUpdates()` decides whether to show "update available" by looking for unpulled commits in the plugin directory, then falling back to `scripts/fpp_update_check.sh`. This plugin needs the fallback: the agent binary ships as a release asset in [fpp-agent-monitor](https://github.com/jlwright325/fpp-agent-monitor), so a plugin repo with no new commits says nothing about how far behind the installed binary is.

`scripts/fpp_update_check.sh` must therefore keep FPP's contract:

- exit `0`, and print `1` as the **final stdout line** when the installed release tag is older than the newest one
- print `0` for every other outcome, including no binary installed and no network — FPP treats a non-zero exit or any other final line as "no update"
- keep diagnostics on **stderr**; FPP reads only the last stdout line

Latest-version resolution matches `api.php`: the `latest.json` manifest first, then the GitHub releases API. Results are cached for 15 minutes at `/home/fpp/media/tmp/showops-agent-update-check` (keyed by installed version, so an upgrade invalidates it) because FPP runs the check on every Plugins page render.

Applying the update needs nothing extra here — FPP's `upgrade_plugin` runs `scripts/fpp_install.sh` after the no-op `git pull`, and that installs the newest release without overwriting an existing config.

---

## 9. PHP plugin UI and config merges

The UI reads the same config path and expects JSON objects as produced by the installer. Pairing/unpair actions update only the pairing-related keys and must not wipe unrelated operator fields in the live config. Uninstall removes the config file entirely.
---

## 10. CI contract verification

`scripts/verify_plugin_contract.sh` reads `docs/contract-fingerprints.json` and fails if frozen paths or POST actions drift. Run locally:

```bash
bash scripts/verify_plugin_contract.sh
```

---

## References

- Operator-facing install notes: [README.md](../README.md)
- Next-slice risk context: company issue **SHO-253**
