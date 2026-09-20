#!/usr/bin/env bash

set -euo pipefail

PARSER_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "$PARSER_ROOT/.." && pwd)"
PHP_BIN="$BACKEND_ROOT/runtime/linux/php/php"
if [ ! -x "$PHP_BIN" ]; then PHP_BIN="$(command -v php || true)"; fi
if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
    echo "[FAILED] PHP CLI is not installed or executable."
    exit 1
fi

echo "SQL to API JSON Generator"
echo "http://127.0.0.1:8005/"
echo "This process is independent from the Admin Console, API, and database."
exec "$PHP_BIN" -S 127.0.0.1:8005 -t "$PARSER_ROOT"
