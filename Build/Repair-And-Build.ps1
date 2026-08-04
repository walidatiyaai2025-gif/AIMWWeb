$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

Write-Host 'Cleaning previous build outputs...' -ForegroundColor Cyan
Get-ChildItem -Path . -Directory -Recurse -Force | Where-Object { $_.Name -in @('bin','obj','.vs') } | Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

dotnet --info
dotnet restore .\AIWordPressManager.Web.sln
dotnet build .\AIWordPressManager.Web.sln -c Debug --no-restore

Write-Host 'Build completed.' -ForegroundColor Green
