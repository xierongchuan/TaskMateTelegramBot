#!/bin/bash
set -e
export PYTHONPATH="/app/.packages:$PYTHONPATH"

if [[ "${1:-}" == -* ]]; then
    exec python "$@"
else
    exec "$@"
fi
