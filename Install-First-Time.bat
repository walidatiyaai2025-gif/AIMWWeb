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

set "SETUP_TOOL=%~dp0Setup-Tool.ps1"
set "BOOTSTRAP_TOOL="
set "BOOTSTRAP_BRANCH=main"

rem If this folder is already a Git repository, prefer the newest Setup-Tool.ps1
rem from the current remote branch. This prevents an outdated local setup script
rem from failing before it has a chance to update the repository.
if exist "%~dp0.git" (
    where git.exe >nul 2>&1
    if not errorlevel 1 (
        for /f "usebackq delims=" %%B in (`git.exe -C "%~dp0" rev-parse --abbrev-ref HEAD 2^>nul`) do set "BOOTSTRAP_BRANCH=%%B"
        if /I "%BOOTSTRAP_BRANCH%"=="HEAD" set "BOOTSTRAP_BRANCH=main"

        echo [INFO] Checking for the latest Setup Tool on origin/%BOOTSTRAP_BRANCH%...
        git.exe -C "%~dp0" fetch origin "%BOOTSTRAP_BRANCH%" --quiet 2>nul
        if not errorlevel 1 (
            set "BOOTSTRAP_TOOL=%TEMP%\AIWM-Setup-Tool-%RANDOM%-%RANDOM%.ps1"
            git.exe -C "%~dp0" show "origin/%BOOTSTRAP_BRANCH%:Setup-Tool.ps1" > "%BOOTSTRAP_TOOL%" 2>nul
            if errorlevel 1 (
                del /q "%BOOTSTRAP_TOOL%" >nul 2>&1
                set "BOOTSTRAP_TOOL="
            ) else (
                echo [SUCCESS] Latest Setup Tool loaded from origin/%BOOTSTRAP_BRANCH%.
                set "SETUP_TOOL=%BOOTSTRAP_TOOL%"
            )
        ) else (
            echo [WARNING] Could not refresh the Setup Tool from GitHub. Using the local copy.
        )
    )
)

if not exist "%SETUP_TOOL%" (
    echo.
    echo [ERROR] Setup-Tool.ps1 was not found locally and could not be loaded from GitHub.
    echo Expected local file: %~dp0Setup-Tool.ps1
    echo.
    pause
    exit /b 1
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%SETUP_TOOL%" %*
set "EXIT_CODE=%ERRORLEVEL%"

if defined BOOTSTRAP_TOOL del /q "%BOOTSTRAP_TOOL%" >nul 2>&1

if not "%EXIT_CODE%"=="0" (
    echo.
    echo [ERROR] Setup operation stopped with exit code %EXIT_CODE%.
)

exit /b %EXIT_CODE%
