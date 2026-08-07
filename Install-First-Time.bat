@echo off
setlocal
cd /d "%~dp0"
title AI WordPress Manager - Setup and Recovery Tool

where powershell.exe >nul 2>&1
if errorlevel 1 (
    echo.
    echo [ERROR] Windows PowerShell was not found.
    echo Install PowerShell or run Setup-Tool.ps1 manually using PowerShell 7.
    echo.
    pause
    exit /b 1
)

if not exist "%~dp0Setup-Tool.ps1" (
    echo.
    echo [ERROR] Setup-Tool.ps1 was not found next to this BAT file.
    echo Expected: %~dp0Setup-Tool.ps1
    echo.
    pause
    exit /b 1
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Setup-Tool.ps1" %*
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo.
    echo [ERROR] Setup operation stopped with exit code %EXIT_CODE%.
)

exit /b %EXIT_CODE%
