#!/usr/bin/env bash
set -e

echo "=========================================="
echo "  Agent Host Docker Build & Start"
echo "=========================================="

echo "[1/3] Stopping any host process running on port 3116..."
pkill -f "python3.*server.py" || true
sleep 1

echo "[2/3] Building lightweight Docker image (~45-60s)..."
cd "$(dirname "$0")"
if command -v docker-compose &>/dev/null; then
    docker-compose build
    docker-compose up -d
else
    docker compose build
    docker compose up -d
fi

echo ""
echo "[3/3] Verifying container status..."
docker ps --filter "name=agent-host"

echo ""
echo "=========================================="
echo "✅ Agent Host successfully running in Docker!"
echo "   URL:    http://localhost:3116"
echo "   Start:  docker start agent-host"
echo "   Stop:   docker stop agent-host"
echo "   Logs:   docker logs -f agent-host"
echo "=========================================="
