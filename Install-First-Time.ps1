# Compatibility wrapper. The full installer/updater now lives in Setup-Tool.ps1.
$setupTool = Join-Path $PSScriptRoot "Setup-Tool.ps1"

if (-not (Test-Path -LiteralPath $setupTool)) {
    Write-Host "[ERROR] Setup-Tool.ps1 was not found next to this file." -ForegroundColor Red
    Write-Host "Expected: $setupTool" -ForegroundColor Yellow
    exit 1
}

& powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File $setupTool @args
exit $LASTEXITCODE
