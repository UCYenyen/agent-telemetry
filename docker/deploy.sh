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

# GitHub and GHCR connections from this VPS drop now and then
retry() {
    for attempt in 1 2 3 4 5; do
        "$@" && return 0
        echo "attempt $attempt failed: $*" >&2
        sleep 15
    done
    return 1
}

# Download first, then switch: a failed fetch must never leave the folder on a stale branch
retry git fetch origin "$branch"
git checkout "$branch"
git merge --ff-only "origin/$branch"

# Pull with the job's short-lived token in a throwaway config; the host's own logins stay untouched
export DOCKER_CONFIG
DOCKER_CONFIG="$(mktemp -d)"
trap 'rm -rf "$DOCKER_CONFIG"' EXIT
# A function, so each retry feeds the token again (stdin is used up by the first attempt)
ghcr_login() { docker login ghcr.io -u UCYenyen --password-stdin <<< "$token" >/dev/null; }
retry ghcr_login
retry docker compose pull
docker compose up -d --remove-orphans
docker image prune -f
