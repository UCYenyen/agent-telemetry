#!/usr/bin/env bash
# CI runs: docker/deploy.sh deploy <branch> <github token>
# Works with a plain SSH key, or as the forced command of a key restricted to this script
# (then the requested command arrives in SSH_ORIGINAL_COMMAND instead of the arguments).
set -euo pipefail

if [[ -n "${SSH_ORIGINAL_COMMAND:-}" ]]; then
    read -r -a requested <<< "$SSH_ORIGINAL_COMMAND"
    [[ "${requested[0]:-}" == */deploy.sh ]] && requested=("${requested[@]:1}")
    set -- "${requested[@]}"
fi

action="${1:-}" branch="${2:-}" token="${3:-}"

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
