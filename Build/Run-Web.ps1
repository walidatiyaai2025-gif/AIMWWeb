$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$solution = Join-Path $root 'AIWordPressManager.Web.sln'
$project = Join-Path $root 'src\AIWordPressManager.Web\AIWordPressManager.Web.csproj'
$webProjectDir = Split-Path -Parent $project
$webOutput = Join-Path $webProjectDir 'bin\Debug\net8.0'
$webDll = Join-Path $webOutput 'AIWordPressManager.Web.dll'
$port = 7148

function Invoke-DotNetChecked {
    param([string[]]$Arguments,[string]$FailureMessage)
    & dotnet @Arguments
    if ($LASTEXITCODE -ne 0) { throw "$FailureMessage Exit code: $LASTEXITCODE" }
}

function Stop-AIWordPressManagerProcesses {
    Write-Host 'Stopping previous website instances...' -ForegroundColor Yellow
    $processIds = New-Object System.Collections.Generic.HashSet[int]

    Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.ProcessName -like 'AIWordPressManager.Web*' } |
        ForEach-Object { [void]$processIds.Add($_.Id) }

    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like 'AIWordPressManager.Web*' -or ($_.CommandLine -and $_.CommandLine -like '*AIWordPressManager.Web*') } |
        ForEach-Object { [void]$processIds.Add([int]$_.ProcessId) }

    netstat -ano -p tcp 2>$null |
        Select-String ":$port\s+.*LISTENING\s+(\d+)$" |
        ForEach-Object {
            if ($_.Matches.Count -gt 0) { [void]$processIds.Add([int]$_.Matches[0].Groups[1].Value) }
        }

    foreach ($processId in $processIds) {
        if ($processId -eq $PID) { continue }
        $existing = Get-Process -Id $processId -ErrorAction SilentlyContinue
        if ($existing) {
            Write-Host "Stopping process PID $processId..." -ForegroundColor DarkYellow
            Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
        }
    }

    Start-Sleep -Seconds 2
    $remaining = Get-Process -ErrorAction SilentlyContinue | Where-Object { $_.ProcessName -like 'AIWordPressManager.Web*' }
    if ($remaining) { throw "Unable to stop previous process(es): $($remaining.Id -join ', '). Run PowerShell as Administrator." }
}

Stop-AIWordPressManagerProcesses

Write-Host 'Cleaning web build output...' -ForegroundColor Cyan
if (Test-Path $webOutput) { Remove-Item $webOutput -Recurse -Force }

Write-Host 'Restoring packages...' -ForegroundColor Cyan
Invoke-DotNetChecked -Arguments @('restore', $solution) -FailureMessage 'NuGet restore failed.'

Write-Host 'Building project...' -ForegroundColor Cyan
Invoke-DotNetChecked -Arguments @('build', $solution, '-c', 'Debug', '--no-restore') -FailureMessage 'Build failed.'

if (-not (Test-Path $webDll)) { throw "Build succeeded but the web DLL was not created: $webDll" }

Write-Host "Compiled web application: $webDll" -ForegroundColor DarkGreen
Start-Process powershell -ArgumentList '-NoProfile','-WindowStyle','Hidden','-Command',"Start-Sleep -Seconds 5; Start-Process 'https://localhost:$port'"
Write-Host 'Starting Blazor Server...' -ForegroundColor Green
Write-Host 'Press Ctrl+C to stop the website.' -ForegroundColor DarkGray

Push-Location $webProjectDir
try {
    $env:ASPNETCORE_ENVIRONMENT = 'Development'
    $env:ASPNETCORE_URLS = "https://localhost:$port;http://localhost:5148"
    & dotnet $webDll
    $exitCode = $LASTEXITCODE

    if ($exitCode -eq 0 -or $exitCode -eq -1) {
        Write-Host 'Website stopped normally.' -ForegroundColor Yellow
        exit 0
    }

    throw "Website stopped unexpectedly with exit code $exitCode"
}
finally { Pop-Location }
