$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

Write-Host 'Stopping running AIWordPressManager.Web processes...' -ForegroundColor Yellow
Get-Process -Name 'AIWordPressManager.Web' -ErrorAction SilentlyContinue |
    Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

Write-Host 'Cleaning previous build outputs...' -ForegroundColor Cyan
Get-ChildItem -Path . -Directory -Recurse -Force |
    Where-Object { $_.Name -in @('bin','obj','.vs') } |
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

dotnet --info
dotnet restore .\AIWordPressManager.Web.sln
dotnet build .\AIWordPressManager.Web.sln -c Debug --no-restore

Write-Host 'Build completed.' -ForegroundColor Green
