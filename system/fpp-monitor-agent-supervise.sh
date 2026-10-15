#!/usr/bin/env bash
# Keeps the agent running where there is no systemd (FPP in Docker), the way
# fpp-monitor-agent.service does elsewhere: run the launcher as fpp and start
# it again 5 seconds after it exits (Restart=always, RestartSec=5).
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
while true; do
  "$ROOT_DIR/fpp-monitor-agent.sh"
  sleep 5
done
