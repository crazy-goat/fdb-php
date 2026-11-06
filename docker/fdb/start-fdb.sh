#!/bin/bash
#
# Start an fdbserver for the Docker Compose stack.
#
# Replaces /var/fdb/scripts/fdb.bash from the upstream image so the compose
# stack can give the blob manager a real, shared blob store. The knob name is
# lowercase: --knob-bg_url works, --knob-BG_URL is silently ignored and the
# blob manager crash-loops with Error="backup_invalid_url".
set -euo pipefail

cluster_file="${FDB_CLUSTER_FILE:-/etc/foundationdb/fdb.cluster}"
mkdir -p "$(dirname "$cluster_file")"
printf '%s\n' "${FDB_CLUSTER_FILE_CONTENTS:?FDB_CLUSTER_FILE_CONTENTS is required}" > "$cluster_file"

public_ip=$(hostname -i | awk '{print $1}')

# Start from empty trace logs so the healthcheck cannot see a crash loop from
# a previous run of the same volume.
mkdir -p /var/fdb/logs /var/fdb/blobs
rm -rf /var/fdb/logs/*

echo "Starting FDB server on ${public_ip}:${FDB_PORT}"
exec fdbserver \
    --listen-address "0.0.0.0:${FDB_PORT}" \
    --public-address "${public_ip}:${FDB_PORT}" \
    --datadir /var/fdb/data \
    --logdir /var/fdb/logs \
    --locality-zoneid="$(hostname)" \
    --locality-machineid="$(hostname)" \
    --class "${FDB_PROCESS_CLASS:-unset}" \
    --knob-bg_url="${FDB_BG_URL:-file:///var/fdb/blobs/}"
