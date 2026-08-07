@echo off
setlocal
cd /d "%~dp0"
title AI WordPress Manager - Install or Update

where powershell.exe >nul 2>&1
if errorlevel 1 (
    echo.
    echo [ERROR] Windows PowerShell was not found.
    echo Install PowerShell or run Install-First-Time.ps1 manually using PowerShell 7.
    echo.
    pause
    exit /b 1
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Install-First-Time.ps1" %*
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo.
    echo [ERROR] Install or update stopped with exit code %EXIT_CODE%.
)

exit /b %EXIT_CODE%
