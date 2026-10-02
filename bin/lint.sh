#!/usr/bin/env bash
# Run all static analysis, linters and formatter checks. --fix applies fixes first.
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

FIX=0
[ "${1:-}" = "--fix" ] && FIX=1
failed=()

step() {
    local name="$1"; shift
    echo "==> $name"
    "$@" || failed+=("$name")
}

if [ "$FIX" = 1 ]; then
    vendor/bin/rector process --no-progress-bar
    vendor/bin/phpcbf --standard=phpcs.xml.dist || true # exits 1 after fixing
fi

step "phpcs" vendor/bin/phpcs --standard=phpcs.xml.dist
step "rector" vendor/bin/rector process --dry-run --no-progress-bar
step "phpstan" vendor/bin/phpstan analyse --memory-limit=512M --no-progress
step "shellcheck" bash -c 'git ls-files -z "*.sh" | xargs -0 -r shellcheck'
step "hadolint" bash -c 'git ls-files -z "*Dockerfile*" | xargs -0 -r hadolint'

if [ "${#failed[@]}" -gt 0 ]; then
    echo "Failed: ${failed[*]}" >&2
    exit 1
fi
echo "All checks passed."
