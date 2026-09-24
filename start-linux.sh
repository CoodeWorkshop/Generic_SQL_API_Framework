#!/usr/bin/env bash

set -euo pipefail

BACKEND_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SYSTEM_PHP_BIN="$(command -v php || true)"
BUNDLED_PHP_BIN="$BACKEND_ROOT/runtime/linux/php/php"
PHP_BIN="$BUNDLED_PHP_BIN"
if [ ! -x "$PHP_BIN" ]; then PHP_BIN="$SYSTEM_PHP_BIN"; fi
PHP_INI_PATH="$BACKEND_ROOT/runtime/linux/php/php.ini"
OPCACHE_PATH="$BACKEND_ROOT/runtime/linux/php/opcache"
LOG_PATH="$BACKEND_ROOT/logs"
ADMIN_PATH="$BACKEND_ROOT/admin"
export GENERIC_RUNTIME_CONFIG_DIR="$BACKEND_ROOT/config"

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
if [ ! -f "$ADMIN_PATH/router.php" ]; then
    echo "[FAILED] Admin router not found: $ADMIN_PATH/router.php"
    exit 1
fi
if ! "$PHP_BIN" -n -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);'; then
    echo "[FAILED] PHP 8.2 or newer is required."
    exit 1
fi

mkdir -p "$OPCACHE_PATH" "$LOG_PATH"

for extension in odbc openssl json session; do
    if ! "$PHP_BIN" -c "$PHP_INI_PATH" -r "exit(extension_loaded('$extension') ? 0 : 1);"; then
        echo "[FAILED] Required PHP extension is unavailable: $extension"
        exit 1
    fi
done

"$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/bootstrap-runtime-configuration.php"
"$PHP_BIN" -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/database-runtime-control.php" disconnect >/dev/null

GENERIC_SQL_API_ENCRYPTION_KEY="$($PHP_BIN -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/prepare-local-encryption-key.php")"
if [ -z "$GENERIC_SQL_API_ENCRYPTION_KEY" ]; then
    echo "[FAILED] Unable to prepare the database encryption key."
    exit 1
fi
export GENERIC_SQL_API_ENCRYPTION_KEY

ADMIN_PORT="$($PHP_BIN -c "$PHP_INI_PATH" "$BACKEND_ROOT/scripts/find-available-port.php" admin)"
export GENERIC_ADMIN_ENABLED=1
export GENERIC_ADMIN_STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ADMIN_URL="http://127.0.0.1:$ADMIN_PORT/admin"

echo "[OK] PHP runtime and required extensions"
echo "[OK] Runtime configuration"
echo "[OK] Database runtime disconnected"
echo "[OK] Local database encryption key"
echo "[OK] Admin Console port $ADMIN_PORT"
echo
echo "API:   stopped until started from System Health"
echo "Parser: stopped until started from System Health"
echo "Admin: $ADMIN_URL"
echo
echo "The Admin Console is bound to this computer only. Press Ctrl+C to stop it."
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
    -S "127.0.0.1:$ADMIN_PORT" \
    -t "$ADMIN_PATH" \
    "$ADMIN_PATH/router.php"
