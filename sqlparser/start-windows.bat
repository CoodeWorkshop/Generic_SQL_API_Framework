@echo off
setlocal
set "ROOT=%~dp0..\"
set "PHP=%ROOT%runtime\windows\php\php.exe"

if not exist "%PHP%" (
    echo [FAILED] Bundled PHP runtime not found: %PHP%
    pause
    exit /b 1
)

echo SQL to API JSON Generator
echo http://127.0.0.1:8005/
echo This process is independent from api/index.php.
"%PHP%" -S 127.0.0.1:8005 -t "%~dp0"
