$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$solution = Join-Path $root 'AIWordPressManager.Web.sln'
$project = Join-Path $root 'src\AIWordPressManager.Web\AIWordPressManager.Web.csproj'
$webProjectDir = Split-Path -Parent $project
$webOutput = Join-Path $webProjectDir 'bin\Debug\net8.0'
$webDll = Join-Path $webOutput 'AIWordPressManager.Web.dll'
$port = 7148

function Invoke-DotNetProcess {
    param(
        [Parameter(Mandatory = $true)][string[]]$Arguments,
        [Parameter(Mandatory = $true)][string]$FailureMessage
    )

    $argumentText = ($Arguments | ForEach-Object {
        if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ }
    }) -join ' '

    Write-Host "dotnet $argumentText" -ForegroundColor DarkGray

    $process = Start-Process `
        -FilePath 'dotnet.exe' `
        -ArgumentList $argumentText `
        -WorkingDirectory $root `
        -NoNewWindow `
        -Wait `
        -PassThru

    if ($null -eq $process) {
        throw "$FailureMessage dotnet process could not be started."
    }

    if ($process.ExitCode -ne 0) {
        throw "$FailureMessage Exit code: $($process.ExitCode)"
    }
}

function Invoke-RestoreWithRetry {
    try {
        Invoke-DotNetProcess -Arguments @('restore', $solution, '--disable-parallel') -FailureMessage 'NuGet restore failed.'
    }
    catch {
        Write-Host 'First restore attempt failed. Clearing NuGet temporary caches and retrying...' -ForegroundColor Yellow

        $httpCache = Join-Path $env:LOCALAPPDATA 'NuGet\v3-cache'
        $tempCache = Join-Path $env:TEMP 'NuGetScratchroot'
        foreach ($cache in @($httpCache, $tempCache)) {
            if ($cache -and (Test-Path $cache)) {
                Remove-Item $cache -Recurse -Force -ErrorAction SilentlyContinue
            }
        }

        Invoke-DotNetProcess -Arguments @('nuget', 'locals', 'http-cache', '--clear') -FailureMessage 'Could not clear NuGet HTTP cache.'
        Invoke-DotNetProcess -Arguments @('restore', $solution, '--disable-parallel', '--force') -FailureMessage 'NuGet restore failed after retry.'
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
            ($_.Name -eq 'dotnet.exe' -and $_.CommandLine -and $_.CommandLine -like '*AIWordPressManager.Web.dll*')
        } |
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
    $remaining = Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.ProcessName -like 'AIWordPressManager.Web*' }

    if ($remaining) {
        throw "Unable to stop previous process(es): $($remaining.Id -join ', '). Run PowerShell as Administrator."
    }
}

Stop-AIWordPressManagerProcesses

Write-Host 'Cleaning web build output...' -ForegroundColor Cyan
if (Test-Path $webOutput) {
    Remove-Item $webOutput -Recurse -Force -ErrorAction Stop
}

Write-Host 'Restoring packages...' -ForegroundColor Cyan
Invoke-RestoreWithRetry

Write-Host 'Building project...' -ForegroundColor Cyan
Invoke-DotNetProcess -Arguments @('build', $solution, '-c', 'Debug', '--no-restore') -FailureMessage 'Build failed.'

if (-not (Test-Path $webDll)) {
    throw "Build succeeded but the web DLL was not created: $webDll"
}

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
finally {
    Pop-Location
}
