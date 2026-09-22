@echo off
setlocal

set "ROOT=%~dp0"
set "PHP=%ROOT%runtime\windows\php\php.exe"
set "PHP_INI=%ROOT%runtime\windows\php\php.ini"
set "OPCACHE=%ROOT%runtime\windows\php\opcache"
set "LOGS=%ROOT%logs"
set "ADMIN=%ROOT%admin"
set "GENERIC_RUNTIME_CONFIG_DIR=%ROOT%config"

echo ========================================
echo          Generic SQL API Framework
echo ========================================
echo.

if not exist "%PHP%" (
    echo [FAILED] Bundled PHP runtime not found: %PHP%
    pause
    exit /b 1
)
if not exist "%PHP_INI%" (
    echo [FAILED] PHP configuration not found: %PHP_INI%
    pause
    exit /b 1
)
if not exist "%ADMIN%\router.php" (
    echo [FAILED] Admin router not found: %ADMIN%\router.php
    pause
    exit /b 1
)

if not exist "%OPCACHE%" mkdir "%OPCACHE%"
if not exist "%LOGS%" mkdir "%LOGS%"

for %%E in (odbc openssl json session) do (
    "%PHP%" -c "%PHP_INI%" -r "exit(extension_loaded('%%E') ? 0 : 1);"
    if errorlevel 1 (
        echo [FAILED] Required PHP extension is unavailable: %%E
        pause
        exit /b 1
    )
)

"%PHP%" -c "%PHP_INI%" "%ROOT%scripts\bootstrap-runtime-configuration.php"
if errorlevel 1 (
    echo [FAILED] Unable to initialize runtime configuration.
    pause
    exit /b 1
)

set "PREPARED_ENCRYPTION_KEY="
for /f "usebackq delims=" %%K in (`"%PHP%" -c "%PHP_INI%" "%ROOT%scripts\prepare-local-encryption-key.php"`) do set "PREPARED_ENCRYPTION_KEY=%%K"
if not defined PREPARED_ENCRYPTION_KEY (
    echo [FAILED] Unable to prepare the database encryption key.
    pause
    exit /b 1
)
set "GENERIC_SQL_API_ENCRYPTION_KEY=%PREPARED_ENCRYPTION_KEY%"
set "PREPARED_ENCRYPTION_KEY="

set "ADMIN_PORT="
for /f "usebackq delims=" %%P in (`"%PHP%" -c "%PHP_INI%" "%ROOT%scripts\find-available-port.php" admin`) do set "ADMIN_PORT=%%P"
if not defined ADMIN_PORT (
    echo [FAILED] The configured Admin port is unavailable.
    pause
    exit /b 1
)

set "GENERIC_ADMIN_ENABLED=1"
for /f "usebackq delims=" %%T in (`powershell -NoProfile -Command "[DateTime]::UtcNow.ToString('o')"`) do set "GENERIC_ADMIN_STARTED_AT=%%T"
set "ADMIN_URL=http://127.0.0.1:%ADMIN_PORT%/admin"

echo [OK] PHP runtime and required extensions
echo [OK] Runtime configuration
echo [OK] Local database encryption key
echo [OK] Admin Console port %ADMIN_PORT%
echo.
echo API:   stopped until started from System Health
echo Parser: stopped until started from System Health
echo Admin: %ADMIN_URL%
echo.
echo The Admin Console is bound to this computer only. Press Ctrl+C to stop it.
echo The PHP built-in server is for local setup and development, not production.
echo.

start "" "%ADMIN_URL%"
"%PHP%" -c "%PHP_INI%" -d "opcache.file_cache=%OPCACHE%" -d "error_log=%LOGS%\php_errors.log" -S 127.0.0.1:%ADMIN_PORT% -t "%ADMIN%" "%ADMIN%\router.php"

echo.
echo Admin Console stopped. API and SQL Parser lifecycles remain independently managed.
pause
