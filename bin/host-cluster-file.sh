#!/usr/bin/env bash
# Print the host-side FoundationDB cluster file for the compose stack.
#
# A host-side client must dial the mapped host ports, which default to the
# container ports from .env. This script reads the same values
# docker-compose.yml uses, so changing a port in .env updates the compose
# services and this output together. CI's e2e job writes the same file inline;
# calling this script there is the follow-up.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    echo "error: .env is missing; it holds the FDB_*_CONTAINER_PORT defaults." >&2
    exit 1
fi

# Load the defaults without overriding exported values; this matches Docker
# Compose's precedence (the shell environment wins over .env).
set -a
# shellcheck source=/dev/null
. ./.env
set +a

printf 'docker:docker@127.0.0.1:%s,127.0.0.1:%s,127.0.0.1:%s\n' \
    "${FDB_COORD_1_PORT:-$FDB_COORD_1_CONTAINER_PORT}" \
    "${FDB_COORD_2_PORT:-$FDB_COORD_2_CONTAINER_PORT}" \
    "${FDB_COORD_3_PORT:-$FDB_COORD_3_CONTAINER_PORT}"
