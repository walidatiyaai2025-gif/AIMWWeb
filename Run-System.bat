@echo off
setlocal
cd /d "%~dp0"
title AI WordPress Manager - Run

if not exist "%~dp0Build\Run-Web.ps1" (
    echo [ERROR] Build\Run-Web.ps1 was not found.
    pause
    exit /b 1
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Build\Run-Web.ps1"
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo.
    echo [ERROR] Application stopped with exit code %EXIT_CODE%.
    pause
)

exit /b %EXIT_CODE%
