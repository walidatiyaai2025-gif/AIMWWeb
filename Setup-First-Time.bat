@echo off
setlocal

cd /d "%~dp0"

echo ==============================================
echo  AI WordPress Manager - First-Time Setup
echo  Target: C:\Apps\AIWM
echo ==============================================
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Setup-First-Time.ps1"
set "EXIT_CODE=%ERRORLEVEL%"

if not "%EXIT_CODE%"=="0" (
    echo.
    echo [ERROR] Setup failed with exit code %EXIT_CODE%.
    pause
    exit /b %EXIT_CODE%
)

echo.
echo [OK] Setup completed successfully.
pause
endlocal
