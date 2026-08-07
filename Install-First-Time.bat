@echo off
setlocal EnableExtensions
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

if "%EXIT_CODE%"=="0" goto :end

echo.
echo ============================================================
echo [ERROR] Setup operation stopped with exit code %EXIT_CODE%.
echo ============================================================

set "LATEST_LOG="
for /f "delims=" %%F in ('dir /b /a-d /o-d "%TEMP%\AIWordPressManager-Setup\setup-*.log" 2^>nul') do (
    if not defined LATEST_LOG set "LATEST_LOG=%TEMP%\AIWordPressManager-Setup\%%F"
)

if defined LATEST_LOG (
    echo.
    echo Diagnostic log: %LATEST_LOG%
    echo.
    echo ---------------- Last diagnostic lines ----------------
    powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -Command "Get-Content -LiteralPath $env:LATEST_LOG -Tail 80 -ErrorAction SilentlyContinue" 2>nul
    echo ---------------------------------------------------------
) else (
    echo.
    echo [WARNING] No setup diagnostic log was found in:
    echo %TEMP%\AIWordPressManager-Setup
)

echo.
echo The setup window will remain open so the error can be copied.
if /I not "%CI%"=="true" pause

:end
exit /b %EXIT_CODE%
