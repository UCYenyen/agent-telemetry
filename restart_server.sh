#!/usr/bin/env bash
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

PYTHON_BIN="$(which python3 || echo /usr/bin/python3)"

echo "Stopping any existing server instances..."
pkill -9 -f "server.py" 2>/dev/null || true
pkill -9 -f "agy --disable-slash-commands" 2>/dev/null || true
sleep 1

echo "Starting server in background (detached with nohup)..."
nohup "$PYTHON_BIN" server.py > "$DIR/server.log" 2>&1 < /dev/null &

SERVER_PID=$!
echo "Server started with PID $SERVER_PID — logs at $DIR/server.log"
sleep 2

if [ -f "$DIR/server.log" ]; then
    cat "$DIR/server.log"
fi
