$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$project = Join-Path $root 'src\AIWordPressManager.Web\AIWordPressManager.Web.csproj'
Write-Host 'Restoring packages...' -ForegroundColor Cyan
dotnet restore $project
Write-Host 'Starting Blazor Server...' -ForegroundColor Green
dotnet run --project $project --launch-profile https
