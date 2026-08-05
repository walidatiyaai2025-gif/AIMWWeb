@echo off
setlocal
cd /d "%~dp0"
title AI WordPress Manager - Clean Rebuild

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Update-System.ps1" -Clean
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo.
    echo [ERROR] Clean rebuild stopped with exit code %EXIT_CODE%.
    pause
)

exit /b %EXIT_CODE%
