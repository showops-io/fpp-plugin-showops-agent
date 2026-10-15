#!/usr/bin/env bash
set -euo pipefail

# Supported ShowOps agent targets: Raspberry Pi / BeagleBone ARM, and x86-64
# (FPP on a PC, VM or container). ARMv6 (Pi Zero W / Pi 1) and 32-bit x86
# are explicitly unsupported.

arch_raw="$(uname -m)"

case "$arch_raw" in
  armv7l|armv7*)
    echo "armv7"
    ;;
  aarch64|arm64)
    echo "arm64"
    ;;
  armv6l|armv6*)
    echo "showops-agent: unsupported platform '${arch_raw}' (ARMv6 / Pi Zero W / Pi 1 not supported)" >&2
    exit 1
    ;;
  x86_64|amd64)
    echo "amd64"
    ;;
  i386|i686)
    echo "showops-agent: unsupported platform '${arch_raw}' (32-bit x86 not supported)" >&2
    exit 1
    ;;
  *)
    echo "showops-agent: unsupported platform '${arch_raw}'" >&2
    exit 1
    ;;
esac
