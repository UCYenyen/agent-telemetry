#!/usr/bin/env bash
# Startup script for Agent Host
export PATH=/snap/bin:/snap/antigravity-cli/current/bin:/root/.local/bin:/root/.nvm/versions/node/v24.15.0/bin:/usr/local/bin:/usr/bin:/bin
cd "$(dirname "$0")" || exit 1
exec python3 server.py

