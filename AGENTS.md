# AGENTS.md – fpp-plugin-monitor-agent

## Role & Purpose
This repository contains the Falcon Player plugin and install/uninstall/configuration wrapper for the ShowOps FPP agent.

It owns:
- plugin UI shown inside FPP (`showops.php` at plugin root)
- install and uninstall scripts
- default config creation and config handoff
- plugin-side pairing and integration behavior

## Architecture Rules
- this repo wraps the agent; it does not replace the agent runtime
- preserve FPP compatibility and plugin-manager expectations
- keep install/uninstall scripts safe and idempotent
- do not invent backend contracts; consume approved contracts from specs and impact plans
- config shape must stay aligned with the agent contract

## Expected Inputs for Codex
Before changing code, read:
- `.repo-manifest.yaml`
- `docs/PLUGIN_API_CONTRACT.md` + `docs/contract-fingerprints.json`
- vault: `FPP Plugin Lifecycle` + `2026-08-04-fpp-plugin-install-pairing-field-fixes` in `showops-vault`

## Likely Areas
- `showops.php` (plugin-root UI; `www/showops.php` is a shim only)
- `menu.inc`
- `scripts/` (`fpp_install.sh`, `install_common.sh`, update check)
- `system/` (systemd unit template + `fpp-monitor-agent.sh` wrapper)
- `docs/PLUGIN_API_CONTRACT.md`

## Field traps (do not regress)
- When sourcing `/opt/fpp/scripts/common`, use `set +eu` then restore `set -euo pipefail` (unset `LD_LIBRARY_PATH` aborts under nounset — silent install / dead systemd wrapper)
- Checksums: match `checksums.txt` by **exact** filename (`$2 == name`), never substring (slim binary name is a prefix of `.tar.gz`)
- FPP loads `page=showops.php` from the **plugin root**, not `www/`
- Pairing claim needs the agent **running** to poll; a visible code alone is not enough

## Do / Don't
✅ keep FPP install behavior predictable and scriptable
✅ update contract docs + fingerprints when plugin-agent boundaries change
✅ call out any config-schema changes explicitly
❌ do not move agent runtime logic into the plugin layer
❌ do not break dry-run or uninstall safety guarantees
❌ do not reintroduce fetch/AJAX form hacks for pairing create — PHP posts to the API
