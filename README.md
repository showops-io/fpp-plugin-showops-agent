# ShowOps Agent Plugin

Outbound-only ShowOps monitoring agent for Falcon Player (FPP).

For operator install steps, use **ShowOps → Getting Started**. This README is a short reference for the plugin repo.

## Install (FPP 7+)

ShowOps is not in FPP’s built-in catalog yet. Paste this **raw** URL into **Content Setup → Plugin Manager** (Available):

```text
https://raw.githubusercontent.com/showops-io/fpp-plugin-showops-agent/main/pluginInfo.json
```

Then install **ShowOps Agent**, open **Content Setup → ShowOps Configuration**, generate a pairing code (`FPP-XXXX-XXXX`), and claim it in ShowOps Devices.

On FPP 10, set UI Level to **Developer** so paste-install can load the card. On FPP 7–9, Advanced is usually enough.

Do **not** paste the GitHub repo homepage or a `.git` URL.

## Pair / unpair

- Pair: **Generate Pairing Code** in the plugin UI, then claim in ShowOps.
- Unpair / reinstall: use the plugin UI or re-run `scripts/fpp_install.sh` (keeps existing config unless you remove it).

## Useful paths

| Path | Purpose |
|------|---------|
| `/home/fpp/media/plugins/fpp-plugin-showops-agent` | Plugin (matches `repoName`) |
| `/home/fpp/media/plugindata/fpp-plugin-showops-agent/fpp-monitor-agent.json` | Agent config (not overwritten on reinstall) |
| `/home/fpp/media/plugins/fpp-plugin-showops-agent/bin/fpp-monitor-agent` | Agent binary |
| `/home/fpp/media/logs/plugin-fpp-plugin-showops-agent.log` | Plugin install + agent log (FPP-rotated) |

```bash
systemctl status fpp-monitor-agent.service
tail -n 100 /home/fpp/media/logs/plugin-fpp-plugin-showops-agent.log
```

## Uninstall

```bash
bash /home/fpp/media/plugins/fpp-plugin-showops-agent/scripts/fpp_uninstall.sh
```

Removes the agent binary, systemd unit, plugindata config, and legacy `/opt` paths. Enrollment is copied to `/home/fpp/media/config/showops-agent-enrollment.json` first so FPP **Reinstall All** after an OS upgrade can restore pairing. Explicit Unpair in the plugin UI deletes that stash. Reinstall restores the stash when present; otherwise it writes a fresh config.
## Engineers

- Agent binary repo: [fpp-agent-monitor](https://github.com/showops-io/fpp-agent-monitor)
- Integration contract: [docs/PLUGIN_API_CONTRACT.md](docs/PLUGIN_API_CONTRACT.md)
- Local checks: `DRY_RUN=1 bash scripts/fpp_install.sh`, `bash scripts/verify_plugin_contract.sh`
- Follow [FPP plugin guidelines](https://github.com/FalconChristmas/fpp-plugin-Template/blob/master/PLUGIN_GUIDELINES.md) before changing install/UI behavior.
