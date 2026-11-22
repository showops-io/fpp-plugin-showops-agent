# ShowOps Agent Plugin

Connects a Falcon Player (FPP) to your ShowOps account for monitoring and remote management. `pluginInfo.json` (`description` and `privacy`) lists what the agent sends and what ShowOps can ask it to do.

For operator install steps, use **ShowOps → Getting Started**. This README is a short reference for the plugin repo.

## Install (FPP 7+)

ShowOps is not in FPP’s built-in catalog yet. Paste this **raw** URL into **Content Setup → Plugin Manager** (Available):

```text
https://raw.githubusercontent.com/showops-io/fpp-plugin-showops-agent/main/pluginInfo.json
```

Then install **ShowOps Agent**, open **Content Setup → ShowOps**, click **Sign in to ShowOps**, and sign in on the showops.io tab that opens. The player is added to your account automatically (or scan the QR code on a phone).

On FPP 10, set UI Level to **Developer** so paste-install can load the card. On FPP 7–9, Advanced is usually enough.

Do **not** paste the GitHub repo homepage or a `.git` URL.

## Pair / unpair

- Connect: **Sign in to ShowOps** in the plugin UI. Typing the code at showops.io/connect still works.
- Unpair / reinstall: use the plugin UI or re-run `scripts/fpp_install.sh` (keeps existing config unless you remove it).

## Useful paths

| Path | Purpose |
|------|---------|
| `/home/fpp/media/plugins/fpp-plugin-showops-agent` | Plugin (matches `repoName`) |
| `/home/fpp/media/plugindata/fpp-plugin-showops-agent/fpp-monitor-agent.json` | Agent config (kept across Reinstall on FPP 10.4+, removed on uninstall) |
| `/home/fpp/media/plugins/fpp-plugin-showops-agent/bin/fpp-monitor-agent` | Agent binary |
| `/home/fpp/media/logs/plugin-fpp-plugin-showops-agent.log` | Plugin install + agent log (FPP-rotated) |

```bash
systemctl status fpp-monitor-agent.service
tail -n 100 /home/fpp/media/logs/plugin-fpp-plugin-showops-agent.log
```

Without systemd (FPP in Docker), `scripts/postStart.sh` runs the agent as the `fpp` user under `system/fpp-monitor-agent-supervise.sh`, which restarts it 5 seconds after it exits. It is not stopped with fppd, on purpose: the agent is what reports that fppd stopped. Uninstall stops it.

## Uninstall

```bash
bash /home/fpp/media/plugins/fpp-plugin-showops-agent/scripts/fpp_uninstall.sh
```

Removes the agent binary, systemd unit, the agent's `media/tmp` files, and legacy `/opt` and `/var/lib` paths. It also removes the `0600` config in `plugindata/`, except during a **Reinstall** on FPP 10.4 or later (`FPP_PLUGIN_REINSTALL=1`), so pairing survives Reinstall All after an OS upgrade. **Disconnect from ShowOps** in the plugin UI also clears it. Nothing is copied into `media/config/`, and a `showops-agent-enrollment.json` left there by older versions is deleted.

## Engineers

- Agent binary repo: [fpp-agent-monitor](https://github.com/showops-io/fpp-agent-monitor)
- Integration contract: [docs/PLUGIN_API_CONTRACT.md](docs/PLUGIN_API_CONTRACT.md)
- Local checks: `DRY_RUN=1 bash scripts/fpp_install.sh`, `bash scripts/verify_plugin_contract.sh`
- Follow [FPP plugin guidelines](https://github.com/FalconChristmas/fpp-plugin-Template/blob/master/PLUGIN_GUIDELINES.md) before changing install/UI behavior.
