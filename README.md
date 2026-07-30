# ShowOps Agent Plugin

Lightweight outbound-only ShowOps monitoring agent for Falcon Player (FPP). The plugin installs a small Go binary (`fpp-monitor-agent`) and runs it under systemd when available, otherwise falls back to a background runner.

---

## Table of Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Pairing a Device](#pairing-a-device)
- [Remote Sessions](#remote-sessions)
- [Uninstallation](#uninstallation)
- [Troubleshooting](#troubleshooting)
- [Development](#development)
- [Plugin API contract](#plugin-api-contract)

---

## Overview

The plugin provides:

- **Heartbeat reporting** — device sends periodic status updates to the ShowOps API
- **Command polling** — device polls for and executes allowlisted management commands
- **Device pairing** — one-time enrollment flow to associate the device with a ShowOps account
- **Remote sessions** — optional cloudflared-based tunnel for secure remote access

**Integration contract (engineers):** [docs/PLUGIN_API_CONTRACT.md](docs/PLUGIN_API_CONTRACT.md) — paths, config merge rules, install correlation ID, verifier fingerprints, and agent boundary.

Architecture:

```
FPP Dashboard
    ↓
menu.inc → www/showops.php  (Plugin web UI)
    ↓
/home/fpp/media/config/fpp-monitor-agent.json  (Config file)
    ↓
fpp-monitor-agent (Go binary, managed by systemd or fallback runner)
    ↓
https://api.showops.io  (ShowOps cloud API)
    ↓
cloudflared tunnel (optional — for remote sessions)
```

The Go binary itself lives in the [fpp-agent-monitor](https://github.com/jlwright325/fpp-agent-monitor) repository. This plugin repo handles FPP integration, installation, and the web UI.

---

## Requirements

- Falcon Player (FPP) v9.0 or later
- Linux (ARMv7, ARM64, or x86_64)
- `curl` or `wget` for downloading the agent binary
- `sha256sum` or `shasum` for checksum verification
- `systemd` recommended; fallback to `crontab`/`nohup` if unavailable

---

## Installation

FPP installs the plugin automatically when added via the FPP Plugin Manager. The plugin manager clones this repository into `/home/fpp/media/plugins/showops-agent` and runs `scripts/fpp_install.sh`.

### Manual installation

```bash
cd /home/fpp/media/plugins/showops-agent
bash scripts/fpp_install.sh
```

The installer will:

1. Detect the system architecture (armv7, arm64, x86_64)
2. Download the latest `fpp-monitor-agent` binary from GitHub releases
3. Verify the SHA-256 checksum
4. Install the binary to `/opt/fpp-monitor-agent/fpp-monitor-agent` (or `./bin/` if no sudo)
5. Write a default config to `/home/fpp/media/config/fpp-monitor-agent.json` (skipped if config already exists)
6. Install and start the `fpp-monitor-agent` systemd service (or start via fallback runner)

### Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `RELEASE_VERSION` | _(latest)_ | Pin to a specific release tag (e.g. `v0.1.27`) |
| `AGENT_REPO_OWNER` | `jlwright325` | GitHub owner for agent binary releases |
| `AGENT_REPO_NAME` | `fpp-agent-monitor` | GitHub repo name for agent binary releases |
| `RELEASE_BASE` | _(derived)_ | Override full release asset base URL |
| `DRY_RUN` | `0` | Set to `1` to print actions without executing them |

**`DRY_RUN` contract:** When `DRY_RUN=1`, the installer must not create directories under `/home/fpp`, write install logs to FPP media paths, or mutate the live system. CI runs `DRY_RUN=1 bash scripts/fpp_install.sh` on every PR; any new filesystem side effects must be guarded with `is_dry_run` (see `scripts/install_common.sh`).

---

## Configuration

The config file is located at `/home/fpp/media/config/fpp-monitor-agent.json`. It is created with defaults on first install and never overwritten by reinstalls.

```json
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
  "last_heartbeat_ts": "",
  "cloudflared_token": "",
  "cloudflared_hostname": "",
  "heartbeat_interval_sec": 60,
  "command_poll_interval_sec": 30,
  "reboot_enabled": false,
  "restart_fpp_command": "systemctl restart fpp"
}
```

| Field | Description |
|-------|-------------|
| `api_base_url` | ShowOps API endpoint. Do not change unless self-hosting. |
| `enrollment_token` | Token used during initial device enrollment. Set automatically during pairing. |
| `device_id` / `device_token` | Set automatically after successful enrollment. |
| `device_fingerprint` | Hardware fingerprint used to identify the device. Set automatically. |
| `pairing_requested` | Set to `true` to initiate a pairing request on next agent cycle. |
| `pairing_code` | Short code displayed during pairing. Read-only — set by agent. |
| `pairing_expires_at` | ISO timestamp when the pairing code expires. Read-only. |
| `pairing_status` | Current pairing state (`pending`, `approved`, `denied`). Read-only. |
| `last_heartbeat_ts` | Last successful heartbeat timestamp (agent-written; shown in FPP plugin UI). |
| `cloudflared_token` | Tunnel token for remote session support. Set by agent after enrollment. |
| `cloudflared_hostname` | Tunnel hostname. Set by agent. |
| `heartbeat_interval_sec` | How often (seconds) the agent sends a heartbeat to the API. |
| `command_poll_interval_sec` | How often (seconds) the agent polls for queued commands. |
| `reboot_enabled` | Allow ShowOps to trigger a system reboot remotely. Defaults to `false`. |
| `restart_fpp_command` | Command used to restart FPP when requested remotely. |

---

## Pairing a Device

1. In FPP, navigate to **Plugins → ShowOps Configuration**.
2. Click **Generate Pairing Code**.
3. A 6-character pairing code will appear. Enter it in the ShowOps dashboard under **Devices → Add Device**.
4. The page will update automatically once the pairing is approved.
5. The agent will begin sending heartbeats within one polling cycle.

If pairing fails or expires, click **Generate Pairing Code** again.

---

## Remote Sessions

Remote sessions use [cloudflared](https://github.com/cloudflare/cloudflared) to establish an outbound-only tunnel to the ShowOps cloud. No inbound ports need to be opened.

`cloudflared` is bundled in release tarballs starting from `v0.1.27`. If the installed release does not include it, remote sessions will be unavailable until cloudflared is installed manually:

```bash
# ARM64 example
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-arm64 \
  -o /opt/fpp-monitor-agent/cloudflared
chmod +x /opt/fpp-monitor-agent/cloudflared
```

---

## Uninstallation

```bash
bash /home/fpp/media/plugins/showops-agent/scripts/fpp_uninstall.sh
```

When **sudo** is available, the script removes the entire install directory (`/opt/fpp-monitor-agent`), including the agent binary, bundled `cloudflared`, and the `VERSION` file, so nothing stale is left for support to misread after an uninstall.

To preserve the config file (device pairing state, tokens) during uninstall:

```bash
KEEP_CONFIG=1 bash scripts/fpp_uninstall.sh
```

---

## Troubleshooting

### Check service status

```bash
systemctl status fpp-monitor-agent.service
```

### View agent logs

```bash
# systemd journal
journalctl -u fpp-monitor-agent.service -n 100 --no-pager

# Install log (includes install_run_id per run — share with support)
cat /home/fpp/media/logs/fpp-monitor-agent-install.log
```

### Agent not starting

1. Check that the binary exists: `ls -la /opt/fpp-monitor-agent/fpp-monitor-agent`
2. Check for config errors: `cat /home/fpp/media/config/fpp-monitor-agent.json`
3. Try running manually: `/opt/fpp-monitor-agent/fpp-monitor-agent`

### Pairing code not appearing

- Ensure the device has outbound internet access to `https://api.showops.io`
- Check agent logs for API errors
- Verify `pairing_requested` is `true` in the config file

### Re-install / update

Re-running `fpp_install.sh` will download and install the latest agent binary without overwriting your existing config:

```bash
bash /home/fpp/media/plugins/showops-agent/scripts/fpp_install.sh
```

To install a specific version:

```bash
RELEASE_VERSION=v0.1.27 bash scripts/fpp_install.sh
```

### FPP does not show an update for the plugin

FPP's Plugins page flags an update when the plugin directory has commits to pull, or when `scripts/fpp_update_check.sh` reports one. Because the agent binary ships as a release asset rather than a commit in this repo, that script is what surfaces a new agent. Check what it sees:

```bash
bash /home/fpp/media/plugins/showops-agent/scripts/fpp_update_check.sh
```

`1` means an update is available, `0` means none. Run stderr through as well (drop `2>/dev/null`) for the installed and latest versions it compared. The answer is cached for 15 minutes at `/home/fpp/media/tmp/showops-agent-update-check`; delete that file to force a fresh check.

---

## Development

### Running a dry-run install

```bash
DRY_RUN=1 bash scripts/fpp_install.sh
```

### Plugin API contract check

```bash
bash scripts/verify_plugin_contract.sh
```

### Linting shell scripts

```bash
shellcheck scripts/*.sh system/*.sh
```

### CI

GitHub Actions runs on every push and PR:

- **ShellCheck** — lints all shell scripts in `scripts/` and `system/`
- **Plugin API contract** — asserts frozen paths and FPP UI POST actions match `docs/contract-fingerprints.json`
- **Dry-run install** — validates the installer runs without error in dry-run mode
- **Update check hook** — asserts `scripts/fpp_update_check.sh` reports `1` only when the installed binary is behind, and `0` when it cannot resolve a release
- **Dry-run uninstall** — validates the uninstaller runs without error in dry-run mode
- **JSON validation** — validates `pluginInfo.json`
- **PHP syntax** — `php -l` on `www/showops.php` (FPP plugin UI; catches syntax errors before deploy)

Workflows use **pinned third-party Actions (commit SHA)** for supply-chain stability. See [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

---

## Plugin API contract

Versioned integration boundary between this FPP plugin and the Go agent ([fpp-agent-monitor](https://github.com/jlwright325/fpp-agent-monitor)): paths, config keys used by `www/showops.php`, POST actions, correlation header guidance for outbound HTTP, and informal SLO notes.

- **Human-readable:** [`docs/PLUGIN_API_CONTRACT.md`](docs/PLUGIN_API_CONTRACT.md)
- **CI fingerprints:** [`docs/contract-fingerprints.json`](docs/contract-fingerprints.json) 
