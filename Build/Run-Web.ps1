$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$solution = Join-Path $root 'AIWordPressManager.Web.sln'
$project = Join-Path $root 'src\AIWordPressManager.Web\AIWordPressManager.Web.csproj'
$webProjectDir = Split-Path -Parent $project
$webOutput = Join-Path $webProjectDir 'bin\Debug\net8.0'
$webDll = Join-Path $webOutput 'AIWordPressManager.Web.dll'
$port = 7148

function Invoke-DotNetChecked {
    param(
        [Parameter(Mandatory = $true)]
        [string[]]$Arguments,

        [Parameter(Mandatory = $true)]
        [string]$FailureMessage
    )

    & dotnet @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$FailureMessage Exit code: $LASTEXITCODE"
    }
}

function Stop-AIWordPressManagerProcesses {
    Write-Host 'Stopping previous website instances...' -ForegroundColor Yellow

    $processIds = New-Object System.Collections.Generic.HashSet[int]

    Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.ProcessName -like 'AIWordPressManager.Web*' } |
        ForEach-Object { [void]$processIds.Add($_.Id) }

    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            $_.Name -like 'AIWordPressManager.Web*' -or
            ($_.CommandLine -and $_.CommandLine -like '*AIWordPressManager.Web*')
        } |
        ForEach-Object { [void]$processIds.Add([int]$_.ProcessId) }

    $listeners = netstat -ano -p tcp 2>$null |
        Select-String ":$port\s+.*LISTENING\s+(\d+)$"

    foreach ($listener in $listeners) {
        if ($listener.Matches.Count -gt 0) {
            [void]$processIds.Add([int]$listener.Matches[0].Groups[1].Value)
        }
    }

    foreach ($processId in $processIds) {
        if ($processId -eq $PID) { continue }

        Write-Host "Stopping process PID $processId..." -ForegroundColor DarkYellow
        Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
        & taskkill.exe /PID $processId /T /F 2>$null | Out-Null
    }

    $deadline = (Get-Date).AddSeconds(15)
    do {
        $remaining = Get-Process -ErrorAction SilentlyContinue |
            Where-Object { $_.ProcessName -like 'AIWordPressManager.Web*' }

        if (-not $remaining) { break }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    $remaining = Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.ProcessName -like 'AIWordPressManager.Web*' }

    if ($remaining) {
        $ids = ($remaining.Id -join ', ')
        throw "Unable to stop previous AIWordPressManager.Web process(es): $ids. Run PowerShell as Administrator and retry."
    }

    Start-Sleep -Seconds 2
}

Stop-AIWordPressManagerProcesses

Write-Host 'Cleaning web build output...' -ForegroundColor Cyan
if (Test-Path $webOutput) {
    Remove-Item $webOutput -Recurse -Force -ErrorAction Stop
}

Write-Host 'Restoring packages...' -ForegroundColor Cyan
Invoke-DotNetChecked -Arguments @('restore', $solution) -FailureMessage 'NuGet restore failed.'

Write-Host 'Building project...' -ForegroundColor Cyan
Invoke-DotNetChecked -Arguments @('build', $solution, '-c', 'Debug', '--no-restore') -FailureMessage 'Build failed.'

if (-not (Test-Path $webDll)) {
    throw "Build reported success but the web DLL was not created: $webDll"
}

Write-Host "Compiled web application: $webDll" -ForegroundColor DarkGreen
Write-Host 'Opening browser shortly...' -ForegroundColor Cyan
Start-Process powershell -ArgumentList '-NoProfile','-WindowStyle','Hidden','-Command',"Start-Sleep -Seconds 5; Start-Process 'https://localhost:$port'"

Write-Host 'Starting Blazor Server...' -ForegroundColor Green
Push-Location $webProjectDir
try {
    $env:ASPNETCORE_ENVIRONMENT = 'Development'
    $env:ASPNETCORE_URLS = "https://localhost:$port;http://localhost:5148"
    & dotnet $webDll
    if ($LASTEXITCODE -ne 0) {
        throw "Website stopped with exit code $LASTEXITCODE"
    }
}
finally {
    Pop-Location
}
