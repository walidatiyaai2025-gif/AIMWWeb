@echo off
setlocal
cd /d "%~dp0"
net session >nul 2>&1
if not "%errorlevel%"=="0" (
  echo.
  echo This patch must be run as Administrator.
  echo Right-click Patch.cmd and choose "Run as administrator".
  echo.
  pause
  exit /b 1
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0Apply-AIMWWeb-Patch.ps1"
set EXITCODE=%errorlevel%
echo.
if not "%EXITCODE%"=="0" (
  echo Patch failed. Review the error above and C:\ProgramData\AIMWWeb\Logs\github-patch-*.log
) else (
  echo Patch completed successfully.
)
pause
exit /b %EXITCODE%
