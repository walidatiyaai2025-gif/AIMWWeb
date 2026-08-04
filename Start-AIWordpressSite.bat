@echo off
setlocal EnableExtensions
title AI WordPress Manager - Update and Run

set "ROOT=C:\AIWordpressSite"
set "REPO=https://github.com/walidatiyaai2025-gif/AIMWWeb.git"
set "BRANCH=feature/system-health"

echo ============================================================
echo   AI WordPress Manager - Update, Build and Run
echo ============================================================
echo.

where git >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Git is not installed or is not available in PATH.
    pause
    exit /b 1
)

where dotnet >nul 2>&1
if errorlevel 1 (
    echo [ERROR] .NET SDK is not installed or is not available in PATH.
    pause
    exit /b 1
)

if not exist "%ROOT%" mkdir "%ROOT%"
cd /d "%ROOT%"

if not exist ".git" (
    echo [INFO] Project is not present. Cloning from GitHub...
    git clone --branch "%BRANCH%" "%REPO%" .
    if errorlevel 1 goto :failed
) else (
    echo [INFO] Existing project found. Updating from GitHub...
    git fetch origin --prune
    if errorlevel 1 goto :failed

    git checkout -B "%BRANCH%" "origin/%BRANCH%"
    if errorlevel 1 goto :failed

    git reset --hard "origin/%BRANCH%"
    if errorlevel 1 goto :failed
)

if not exist ".\Build\Run-Web.ps1" (
    echo [ERROR] Build\Run-Web.ps1 was not found after update.
    goto :failed
)

echo.
echo [INFO] Starting application...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ".\Build\Run-Web.ps1"
set "EXITCODE=%ERRORLEVEL%"

if not "%EXITCODE%"=="0" (
    echo.
    echo [ERROR] Application stopped with exit code %EXITCODE%.
    pause
    exit /b %EXITCODE%
)

exit /b 0

:failed
echo.
echo ============================================================
echo [ERROR] Update or startup failed. Review the messages above.
echo ============================================================
pause
exit /b 1
