$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$solution = Join-Path $root 'AIWordPressManager.Web.sln'
$project = Join-Path $root 'src\AIWordPressManager.Web\AIWordPressManager.Web.csproj'

Write-Host 'Stopping previous website instance...' -ForegroundColor Yellow
Get-Process -Name 'AIWordPressManager.Web' -ErrorAction SilentlyContinue |
    Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

Write-Host 'Restoring packages...' -ForegroundColor Cyan
dotnet restore $solution

Write-Host 'Building project...' -ForegroundColor Cyan
dotnet build $solution -c Debug --no-restore

Write-Host 'Opening browser shortly...' -ForegroundColor Cyan
Start-Process powershell -ArgumentList '-NoProfile','-WindowStyle','Hidden','-Command',"Start-Sleep -Seconds 5; Start-Process 'https://localhost:7148'"

Write-Host 'Starting Blazor Server...' -ForegroundColor Green
dotnet run --project $project --launch-profile https --no-build
