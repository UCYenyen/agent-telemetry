#!/usr/bin/env bash
# Runs on the VPS as the forced command of the CI deploy key, so the key can do nothing else.
# CI sends: deploy <branch> <github token>
set -euo pipefail

read -r action branch token <<< "${SSH_ORIGINAL_COMMAND:-}"

if [[ "$action" != "deploy" || ! "$branch" =~ ^(main|laravel-react-rewrite)$ || -z "$token" ]]; then
    echo "usage: deploy <main|laravel-react-rewrite> <token>" >&2
    exit 1
fi

cd "$(dirname "$0")/.."

git fetch origin "$branch"
git checkout "$branch"
git pull --ff-only origin "$branch"

# Pull with the job's short-lived token in a throwaway config; the host's own logins stay untouched
export DOCKER_CONFIG
DOCKER_CONFIG="$(mktemp -d)"
trap 'rm -rf "$DOCKER_CONFIG"' EXIT
echo "$token" | docker login ghcr.io -u UCYenyen --password-stdin >/dev/null
docker compose pull
docker compose up -d --remove-orphans
docker image prune -f
