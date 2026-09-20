#!/bin/bash

set -e

# ============================================================
# Generic SQL API Framework
# Database Configuration Encryption Setup
# Linux / Kali WSL2
# ============================================================

echo
echo "============================================================"
echo "  Generic SQL API - Database Configuration Encryption Setup"
echo "============================================================"
echo

# ============================================================
# Paths
# ============================================================

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PHP="$(command -v php)"
PHP_INI="$ROOT/runtime/linux/php/php.ini"

GENERATE_KEY="$ROOT/scripts/generate-encryption-key.php"
SETUP_ENCRYPTION="$ROOT/scripts/setup-database-encryption.php"
DB_CHECK="$ROOT/scripts/check-database.php"

DB_CONFIG="$ROOT/database/config/database.json"

TEMP_DIR="${TMPDIR:-/tmp}/generic-sql-api-encryption"
KEY_OUTPUT="$TEMP_DIR/key.txt"

# ============================================================
# Check PHP
# ============================================================

echo "[INFO] Checking Linux PHP..."

if [ -z "$PHP" ]; then
    echo "[FAILED] PHP was not found."
    echo
    echo "Install PHP CLI and try again."
    exit 1
fi

if [ ! -x "$PHP" ]; then
    echo "[FAILED] PHP runtime is not executable."
    echo "Expected: $PHP"
    exit 1
fi

if [ ! -f "$PHP_INI" ]; then
    echo "[FAILED] php.ini was not found."
    echo "Expected: $PHP_INI"
    exit 1
fi

echo "[OK] PHP found."
echo "     $PHP"

echo "[OK] php.ini found."
echo "     $PHP_INI"
echo

# ============================================================
# PHP Version
# ============================================================

echo "[INFO] PHP Version:"
"$PHP" -c "$PHP_INI" -v
echo

# ============================================================
# Check Database Configuration
# ============================================================

echo "[INFO] Checking database configuration..."

if [ ! -f "$DB_CONFIG" ]; then
    echo "[FAILED] database.json was not found:"
    echo "         $DB_CONFIG"
    exit 1
fi

echo "[OK] database.json found."
echo

# ============================================================
# Check Setup Scripts
# ============================================================

echo "[INFO] Checking encryption scripts..."

if [ ! -f "$GENERATE_KEY" ]; then
    echo "[FAILED] Missing:"
    echo "         $GENERATE_KEY"
    exit 1
fi

if [ ! -f "$SETUP_ENCRYPTION" ]; then
    echo "[FAILED] Missing:"
    echo "         $SETUP_ENCRYPTION"
    exit 1
fi

echo "[OK] Encryption scripts found."
echo

# ============================================================
# Check OpenSSL
# ============================================================

echo "[INFO] Checking PHP OpenSSL extension..."

if ! "$PHP" -c "$PHP_INI" -m | grep -qi "^openssl$"; then
    echo "[FAILED] PHP OpenSSL extension is not enabled."
    echo
    echo "OpenSSL is required for AES-256-GCM encryption."
    exit 1
fi

echo "[OK] OpenSSL extension is enabled."
echo

# ============================================================
# Check PDO_ODBC
# ============================================================

echo "[INFO] Checking PHP PDO_ODBC extension..."

if ! "$PHP" -c "$PHP_INI" -m | grep -qi "^PDO_ODBC$"; then
    echo "[FAILED] PHP PDO_ODBC extension is not enabled."
    echo
    echo "PDO_ODBC is required for database connectivity."
    exit 1
fi

echo "[OK] PDO_ODBC extension is enabled."
echo

# ============================================================
# Confirm Plaintext State
# ============================================================

echo "[INFO] Checking database configuration encryption state..."
echo

"$PHP" \
    -c "$PHP_INI" \
    "$SETUP_ENCRYPTION" \
    --check-plaintext

PLAINTEXT_STATUS=$?

if [ "$PLAINTEXT_STATUS" -ne 0 ]; then
    echo
    echo "[FAILED] Database configuration is not eligible for encryption."
    echo
    echo "The configuration may already be encrypted or invalid."
    exit 1
fi

echo
echo "[OK] Plaintext database configuration found."
echo

# ============================================================
# Create Temporary Directory
# ============================================================

if [ ! -d "$TEMP_DIR" ]; then
    mkdir -p "$TEMP_DIR"
fi

if [ ! -d "$TEMP_DIR" ]; then
    echo "[FAILED] Unable to create temporary directory."
    exit 1
fi

chmod 700 "$TEMP_DIR"

echo "[OK] Temporary directory created."
echo

# ============================================================
# Generate Encryption Key
# ============================================================

echo "[INFO] Generating encryption key..."
echo

rm -f "$KEY_OUTPUT"

"$PHP" \
    -c "$PHP_INI" \
    "$GENERATE_KEY" > "$KEY_OUTPUT"

if [ $? -ne 0 ]; then
    echo "[FAILED] Unable to generate encryption key."
    exit 1
fi

if [ ! -f "$KEY_OUTPUT" ]; then
    echo "[FAILED] Encryption key was not generated."
    exit 1
fi

KEY_LINE="$(head -n 1 "$KEY_OUTPUT")"

if [ -z "$KEY_LINE" ]; then
    echo "[FAILED] Generated encryption key is empty."
    exit 1
fi

# Expected:
# GENERIC_SQL_API_ENCRYPTION_KEY=xxxxxxxx

KEY_PREFIX="GENERIC_SQL_API_ENCRYPTION_KEY="

if [[ "$KEY_LINE" != "$KEY_PREFIX"* ]]; then
    echo "[FAILED] Invalid encryption key output."
    exit 1
fi

ENCRYPTION_KEY="${KEY_LINE#"$KEY_PREFIX"}"

if [ -z "$ENCRYPTION_KEY" ]; then
    echo "[FAILED] Encryption key value is empty."
    exit 1
fi

echo "[OK] Encryption key generated."
echo

# ============================================================
# Set Key For Current Process
# ============================================================

export GENERIC_SQL_API_ENCRYPTION_KEY="$ENCRYPTION_KEY"

echo "[OK] Encryption key loaded into current process."
echo

# ============================================================
# Persist Key For Future WSL Processes
# ============================================================

echo "[INFO] Saving encryption key to Linux environment..."

PROFILE_FILE="$HOME/.bashrc"

if [ -f "$PROFILE_FILE" ]; then
    # Remove an existing managed entry.
    sed -i '/^export GENERIC_SQL_API_ENCRYPTION_KEY=/d' "$PROFILE_FILE"
fi

printf '\n# Generic SQL API Framework - Database Encryption Key\n' >> "$PROFILE_FILE"
printf 'export GENERIC_SQL_API_ENCRYPTION_KEY=%q\n' "$ENCRYPTION_KEY" >> "$PROFILE_FILE"

echo "[OK] Encryption key saved to:"
echo "     $PROFILE_FILE"
echo

echo "[INFO] The current process already has the key."
echo "[INFO] New terminals will load it automatically."
echo

# ============================================================
# Encrypt Complete Database Configuration
# ============================================================

echo "============================================================"
echo "  Encrypting Database Configuration"
echo "============================================================"
echo

echo "[INFO] Reading database.json..."
echo "[INFO] Encrypting complete configuration using AES-256-GCM..."
echo

"$PHP" \
    -c "$PHP_INI" \
    "$SETUP_ENCRYPTION"

ENCRYPTION_STATUS=$?

if [ "$ENCRYPTION_STATUS" -ne 0 ]; then
    echo
    echo "[FAILED] Unable to encrypt database configuration."
    exit 1
fi

echo
echo "[OK] Complete database configuration encrypted successfully."
echo "[OK] database.json updated successfully."
echo

# ============================================================
# Test Database Connection
# ============================================================

echo "============================================================"
echo "  Testing Database Connection"
echo "============================================================"
echo

if [ ! -f "$DB_CHECK" ]; then
    echo "[FAILED] Database check script was not found:"
    echo "         $DB_CHECK"
    exit 1
fi

"$PHP" \
    -c "$PHP_INI" \
    "$DB_CHECK"

DB_STATUS=$?

if [ "$DB_STATUS" -ne 0 ]; then
    echo
    echo "============================================================"
    echo "  DATABASE CONNECTION TEST FAILED"
    echo "============================================================"
    echo
    echo "The configuration was encrypted, but the database connection"
    echo "test failed."
    echo
    exit 1
fi

echo
echo "============================================================"
echo "  SUCCESS"
echo "============================================================"
echo

echo "Complete database configuration encryption is configured successfully."
echo
echo "Encrypted configuration:"
echo "    $DB_CONFIG"
echo
echo "Encryption key:"
echo "    GENERIC_SQL_API_ENCRYPTION_KEY"
echo
echo "Environment file:"
echo "    $PROFILE_FILE"
echo
echo "Open a new Kali/WSL terminal before starting the API"
echo "to load the saved environment variable automatically."
echo
echo "============================================================"
echo

# ============================================================
# Cleanup
# ============================================================

rm -f "$KEY_OUTPUT"
rmdir "$TEMP_DIR" 2>/dev/null || true

echo "[OK] Temporary files cleaned up."
echo