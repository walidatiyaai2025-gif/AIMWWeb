@echo off
setlocal EnableExtensions
chcp 65001 >nul

title AI WordPress Manager - Update and Run
color 0A

set "APP_DIR=C:\AIWordpressSite"
set "BRANCH=feature/system-health"
set "RUN_SCRIPT=Build\Run-Web.ps1"

echo ============================================================
echo   AI WordPress Manager - Update and Run
echo ============================================================
echo.

if not exist "%APP_DIR%\.git" (
    echo [ERROR] Project repository was not found:
    echo         %APP_DIR%
    echo.
    echo Clone the project first, then run this file again.
    goto :FAIL
)

where git >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Git is not installed or is not available in PATH.
    goto :FAIL
)

where powershell.exe >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Windows PowerShell is not available.
    goto :FAIL
)

where dotnet >nul 2>&1
if errorlevel 1 (
    echo [ERROR] .NET SDK is not installed or is not available in PATH.
    goto :FAIL
)

cd /d "%APP_DIR%"
if errorlevel 1 (
    echo [ERROR] Could not open project folder.
    goto :FAIL
)

echo [1/4] Fetching latest changes...
git fetch origin --prune
if errorlevel 1 goto :GIT_FAIL

echo [2/4] Switching to %BRANCH%...
git checkout "%BRANCH%"
if errorlevel 1 goto :GIT_FAIL

echo [3/4] Updating local files from GitHub...
git reset --hard "origin/%BRANCH%"
if errorlevel 1 goto :GIT_FAIL

if not exist "%APP_DIR%\%RUN_SCRIPT%" (
    echo [ERROR] Run script was not found:
    echo         %APP_DIR%\%RUN_SCRIPT%
    goto :FAIL
)

echo [4/4] Restoring, building and starting the website...
echo.
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%APP_DIR%\%RUN_SCRIPT%"
set "APP_EXIT=%ERRORLEVEL%"

if "%APP_EXIT%"=="0" goto :SUCCESS

echo.
echo [ERROR] Application stopped with exit code %APP_EXIT%.
goto :FAIL

:GIT_FAIL
echo.
echo [ERROR] Git update failed. Check internet access and GitHub permissions.
goto :FAIL

:SUCCESS
echo.
echo ============================================================
echo   Application stopped normally.
echo ============================================================
endlocal
exit /b 0

:FAIL
echo.
echo ============================================================
echo   Operation failed. Review the messages above.
echo ============================================================
echo.
pause
endlocal
exit /b 1
