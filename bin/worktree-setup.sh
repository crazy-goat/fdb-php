#!/usr/bin/env bash
# Install Composer dependencies in a fresh worktree.
# Called by bin/worktree.sh after a new worktree is created.
# The FoundationDB stack is not started here: unit tests need no cluster, and
# integration tests run in the php container (`docker compose up -d`, see AGENTS.md).
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

composer install --no-interaction --prefer-dist
