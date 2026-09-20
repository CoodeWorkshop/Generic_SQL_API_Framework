#!/usr/bin/env bash

set -euo pipefail

BACKEND_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="$(command -v php || true)"
PHP_INI_PATH="$BACKEND_ROOT/runtime/linux/php/php.ini"
OPCACHE_PATH="$BACKEND_ROOT/runtime/linux/php/opcache"
LOG_PATH="$BACKEND_ROOT/logs"
API_PATH="$BACKEND_ROOT/api"

echo "========================================"
echo "       Generic SQL API Framework"
echo "========================================"
echo

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
    echo "[FAILED] PHP CLI is not installed or executable."
    exit 1
fi
if [ ! -f "$PHP_INI_PATH" ]; then
    echo "[FAILED] PHP configuration not found: $PHP_INI_PATH"
    exit 1
fi
if [ ! -f "$API_PATH/router.php" ]; then
    echo "[FAILED] API router not found: $API_PATH/router.php"
    exit 1
fi

mkdir -p "$OPCACHE_PATH" "$LOG_PATH"

for extension in odbc openssl json session; do
    if ! "$PHP_BIN" -c "$PHP_INI_PATH" -m | grep -i "^${extension}$" >/dev/null; then
        echo "[FAILED] Required PHP extension is unavailable: $extension"
        exit 1
    fi
done

"$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/bootstrap-runtime-configuration.php"

GENERIC_SQL_API_ENCRYPTION_KEY="$($PHP_BIN -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/prepare-local-encryption-key.php")"
if [ -z "$GENERIC_SQL_API_ENCRYPTION_KEY" ]; then
    echo "[FAILED] Unable to prepare the database encryption key."
    exit 1
fi
export GENERIC_SQL_API_ENCRYPTION_KEY

PORT="$($PHP_BIN -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/find-available-port.php")"
export GENERIC_ADMIN_ENABLED=1
ADMIN_URL="http://127.0.0.1:$PORT/admin"

echo "[OK] PHP runtime and required extensions"
echo "[OK] Runtime configuration"
echo "[OK] Local database encryption key"
echo "[OK] Loopback port $PORT"
echo
echo "API:   http://127.0.0.1:$PORT/index.php"
echo "Admin: $ADMIN_URL"
echo
echo "The server is bound to this computer only. Press Ctrl+C to stop it."
echo "The PHP built-in server is for local setup and development, not production."
echo

(
    sleep 1
    if command -v xdg-open >/dev/null 2>&1; then
        xdg-open "$ADMIN_URL" >/dev/null 2>&1 || true
    elif command -v cmd.exe >/dev/null 2>&1; then
        cmd.exe /C start "" "$ADMIN_URL" >/dev/null 2>&1 || true
    fi
) &

exec "$PHP_BIN" -c "$PHP_INI_PATH" \
    -d "opcache.file_cache=$OPCACHE_PATH" \
    -d "error_log=$LOG_PATH/php_errors.log" \
    -S "127.0.0.1:$PORT" \
    -t "$API_PATH" \
    "$API_PATH/router.php"
