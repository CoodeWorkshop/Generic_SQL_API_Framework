@echo off
setlocal

set "ROOT=%~dp0"
set "PHP=%ROOT%runtime\windows\php\php.exe"
set "PHP_INI=%ROOT%runtime\windows\php\php.ini"
set "OPCACHE=%ROOT%runtime\windows\php\opcache"
set "LOGS=%ROOT%logs"
set "API=%ROOT%api"

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
if not exist "%API%\router.php" (
    echo [FAILED] API router not found: %API%\router.php
    pause
    exit /b 1
)

if not exist "%OPCACHE%" mkdir "%OPCACHE%"
if not exist "%LOGS%" mkdir "%LOGS%"

for %%E in (odbc openssl json session) do (
    "%PHP%" -c "%PHP_INI%" -m | findstr /i /x "%%E" >nul
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

set "PORT="
for /f "usebackq delims=" %%P in (`"%PHP%" -c "%PHP_INI%" "%ROOT%scripts\find-available-port.php"`) do set "PORT=%%P"
if not defined PORT (
    echo [FAILED] No available local port was found.
    pause
    exit /b 1
)

set "GENERIC_ADMIN_ENABLED=1"
set "ADMIN_URL=http://127.0.0.1:%PORT%/admin"

echo [OK] PHP runtime and required extensions
echo [OK] Runtime configuration
echo [OK] Local database encryption key
echo [OK] Loopback port %PORT%
echo.
echo API:   http://127.0.0.1:%PORT%/index.php
echo Admin: %ADMIN_URL%
echo.
echo The server is bound to this computer only. Press Ctrl+C to stop it.
echo The PHP built-in server is for local setup and development, not production.
echo.

start "" "%ADMIN_URL%"
"%PHP%" -c "%PHP_INI%" -d "opcache.file_cache=%OPCACHE%" -d "error_log=%LOGS%\php_errors.log" -S 127.0.0.1:%PORT% -t "%API%" "%API%\router.php"

echo.
echo API stopped.
pause
