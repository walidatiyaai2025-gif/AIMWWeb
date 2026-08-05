@echo off
setlocal
cd /d "%~dp0"
title AI WordPress Manager - Update and Run

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Update-System.ps1"
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo.
    echo [ERROR] Update stopped with exit code %EXIT_CODE%.
    pause
)

exit /b %EXIT_CODE%
