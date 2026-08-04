# ShowOps Agent Plugin

Outbound-only ShowOps monitoring agent for Falcon Player (FPP).

For operator install steps, use **ShowOps → Getting Started**. This README is a short reference for the plugin repo.

## Install (FPP 7+)

ShowOps is not in FPP’s built-in catalog. Paste this **raw** URL into **Content Setup → Plugin Manager** (Available):

```text
https://raw.githubusercontent.com/showops-io/fpp-plugin-showops-agent/main/pluginInfo.json
```

Then install **ShowOps Agent**, open **Plugins → ShowOps Configuration**, generate a pairing code (`FPP-XXXX-XXXX`), and claim it in ShowOps Devices.

If the card does not appear: set UI Level to Advanced/Developer, clear the box, paste again, and confirm FPP 7+.

Do **not** paste the GitHub repo homepage or a `.git` URL.

## Pair / unpair

- Pair: **Generate Pairing Code** in the plugin UI, then claim in ShowOps.
- Unpair / reinstall: use the plugin UI or re-run `scripts/fpp_install.sh` (keeps existing config unless you remove it).

## Useful paths

| Path | Purpose |
|------|---------|
| `/home/fpp/media/plugins/showops-agent` | Plugin |
| `/home/fpp/media/config/fpp-monitor-agent.json` | Agent config (not overwritten on reinstall) |
| `/opt/fpp-monitor-agent/fpp-monitor-agent` | Agent binary |
| `/home/fpp/media/logs/fpp-monitor-agent-install.log` | Install log |

```bash
systemctl status fpp-monitor-agent.service
journalctl -u fpp-monitor-agent.service -n 100 --no-pager
```

## Uninstall

```bash
bash /home/fpp/media/plugins/showops-agent/scripts/fpp_uninstall.sh
# KEEP_CONFIG=1 to preserve pairing/config
```

## Engineers

- Agent binary repo: [fpp-agent-monitor](https://github.com/showops-io/fpp-agent-monitor)
- Integration contract: [docs/PLUGIN_API_CONTRACT.md](docs/PLUGIN_API_CONTRACT.md)
- Local checks: `DRY_RUN=1 bash scripts/fpp_install.sh`, `bash scripts/verify_plugin_contract.sh`
