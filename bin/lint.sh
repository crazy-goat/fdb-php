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

# Versions and asset checksums live in .lint-tools.env so local lint and CI cannot drift.
# shellcheck source=.lint-tools.env
# shellcheck disable=SC1091
. ./.lint-tools.env

SHELLCHECK_INSTALL="set -euo pipefail
tmp=\$(mktemp)
trap 'rm -f \"\$tmp\"' EXIT
curl -sSfL -o \"\$tmp\" https://github.com/koalaman/shellcheck/releases/download/v${LINT_SHELLCHECK_VERSION}/shellcheck-v${LINT_SHELLCHECK_VERSION}.linux.x86_64.tar.xz
printf '%s  %s\\n' '${LINT_SHELLCHECK_SHA256}' \"\$tmp\" | sha256sum -c -
mkdir -p \"\$HOME/.local/bin\"
tar -xJ --strip-components=1 -C \"\$HOME/.local/bin\" -f \"\$tmp\" shellcheck-v${LINT_SHELLCHECK_VERSION}/shellcheck"

HADOLINT_INSTALL="set -euo pipefail
tmp=\$(mktemp)
trap 'rm -f \"\$tmp\"' EXIT
curl -sSfL -o \"\$tmp\" https://github.com/hadolint/hadolint/releases/download/v${LINT_HADOLINT_VERSION}/hadolint-Linux-x86_64
printf '%s  %s\\n' '${LINT_HADOLINT_SHA256}' \"\$tmp\" | sha256sum -c -
mkdir -p \"\$HOME/.local/bin\"
install -m 0755 \"\$tmp\" \"\$HOME/.local/bin/hadolint\""

ACTIONLINT_INSTALL="set -euo pipefail
tmp=\$(mktemp)
trap 'rm -f \"\$tmp\"' EXIT
curl -sSfL -o \"\$tmp\" https://github.com/rhysd/actionlint/releases/download/v${LINT_ACTIONLINT_VERSION}/actionlint_${LINT_ACTIONLINT_VERSION}_linux_amd64.tar.gz
printf '%s  %s\\n' '${LINT_ACTIONLINT_SHA256}' \"\$tmp\" | sha256sum -c -
mkdir -p \"\$HOME/.local/bin\"
tar -xz -C \"\$HOME/.local/bin\" -f \"\$tmp\" actionlint"

tool_version() {
    "$1" --version 2>/dev/null | grep -oE '[0-9]+(\.[0-9]+)+' | head -n 1 | cut -d. -f1-3
}

version_older_than() {
    local installed_major installed_minor installed_patch
    local expected_major expected_minor expected_patch
    IFS=. read -r installed_major installed_minor installed_patch <<< "$1"
    IFS=. read -r expected_major expected_minor expected_patch <<< "$2"
    installed_minor=${installed_minor:-0}
    installed_patch=${installed_patch:-0}
    expected_minor=${expected_minor:-0}
    expected_patch=${expected_patch:-0}
    if [ "$installed_major" -ne "$expected_major" ]; then
        [ "$installed_major" -lt "$expected_major" ]
        return
    fi
    if [ "$installed_minor" -ne "$expected_minor" ]; then
        [ "$installed_minor" -lt "$expected_minor" ]
        return
    fi
    [ "$installed_patch" -lt "$expected_patch" ]
}

require_tool() {
    local tool="$1" expected="$2" release="$3" install_command="$4"
    local installed

    if ! command -v "$tool" >/dev/null 2>&1; then
        echo "error: $tool is not installed; CI uses $tool $expected." >&2
        echo "  Install it from $release" >&2
        echo "  Linux x86_64 (as in CI): $install_command" >&2
        echo "  Make sure \"\$HOME/.local/bin\" is on PATH." >&2
        exit 1
    fi

    installed=$(tool_version "$tool")
    if [ -z "$installed" ]; then
        echo "warning: cannot determine the $tool version; CI uses $expected." >&2
        return
    fi
    if version_older_than "$installed" "$expected"; then
        echo "error: $tool $installed is older than the CI version $expected." >&2
        echo "  Install $expected from $release" >&2
        echo "  Linux x86_64 (as in CI): $install_command" >&2
        echo "  Make sure \"\$HOME/.local/bin\" is on PATH." >&2
        exit 1
    fi
    if [ "$installed" != "$expected" ]; then
        echo "warning: $tool $installed is installed; CI uses $expected." >&2
        echo "  Lint results may differ; install the CI version from $release." >&2
    fi
}

require_tool shellcheck "$LINT_SHELLCHECK_VERSION" \
    "https://github.com/koalaman/shellcheck/releases/tag/v$LINT_SHELLCHECK_VERSION" \
    "$SHELLCHECK_INSTALL"
require_tool hadolint "$LINT_HADOLINT_VERSION" \
    "https://github.com/hadolint/hadolint/releases/tag/v$LINT_HADOLINT_VERSION" \
    "$HADOLINT_INSTALL"
require_tool actionlint "$LINT_ACTIONLINT_VERSION" \
    "https://github.com/rhysd/actionlint/releases/tag/v$LINT_ACTIONLINT_VERSION" \
    "$ACTIONLINT_INSTALL"

if [ "$FIX" = 1 ]; then
    vendor/bin/rector process --no-progress-bar
    vendor/bin/php-cs-fixer fix --show-progress=none
fi

step "php-cs-fixer" vendor/bin/php-cs-fixer fix --dry-run --diff --show-progress=none
step "rector" vendor/bin/rector process --dry-run --no-progress-bar
step "phpstan" vendor/bin/phpstan analyse --memory-limit=512M --no-progress
step "shellcheck" bash -c 'git ls-files -z "*.sh" | xargs -0 -r shellcheck'
step "hadolint" bash -c 'git ls-files -z "*Dockerfile*" | xargs -0 -r hadolint'
step "actionlint" actionlint

if [ "${#failed[@]}" -gt 0 ]; then
    echo "Failed: ${failed[*]}" >&2
    exit 1
fi
echo "All checks passed."
