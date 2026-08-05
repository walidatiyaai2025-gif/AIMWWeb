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

    $previousErrorAction = $ErrorActionPreference
    $nativePreferenceExists = Get-Variable PSNativeCommandUseErrorActionPreference -ErrorAction SilentlyContinue
    $previousNativePreference = $null

    if ($nativePreferenceExists) {
        $previousNativePreference = $PSNativeCommandUseErrorActionPreference
        $PSNativeCommandUseErrorActionPreference = $false
    }

    try {
        $ErrorActionPreference = 'Continue'
        & dotnet.exe @Arguments 2>&1 | ForEach-Object { Write-Host $_ }
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorAction
        if ($nativePreferenceExists) {
            $PSNativeCommandUseErrorActionPreference = $previousNativePreference
        }
    }

    if ($null -eq $exitCode) { throw "$FailureMessage dotnet did not return an exit code." }
    if ($exitCode -ne 0) { throw "$FailureMessage Exit code: $exitCode" }
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
            if ($cache -and (Test-Path $cache)) { Remove-Item $cache -Recurse -Force -ErrorAction SilentlyContinue }
        }
        Invoke-DotNetProcess -Arguments @('nuget', 'locals', 'http-cache', '--clear') -FailureMessage 'Could not clear NuGet HTTP cache.'
        Invoke-DotNetProcess -Arguments @('restore', $solution, '--disable-parallel', '--force') -FailureMessage 'NuGet restore failed after retry.'
    }
}

function Get-ProjectProcessIds {
    $ids = New-Object System.Collections.Generic.HashSet[int]
    $rootPattern = [Regex]::Escape($root)
    $projectPattern = [Regex]::Escape($project)
    $dllPattern = [Regex]::Escape($webDll)

    Get-Process -ErrorAction SilentlyContinue |
        Where-Object { $_.ProcessName -like 'AIWordPressManager.Web*' } |
        ForEach-Object { [void]$ids.Add([int]$_.Id) }

    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            $cmd = $_.CommandLine
            if (-not $cmd) { return $false }
            $isDotnetHost = $_.Name -in @('dotnet.exe', 'AIWordPressManager.Web.exe')
            $belongsToProject = $cmd -match $dllPattern -or $cmd -match $projectPattern -or
                ($cmd -match $rootPattern -and $cmd -match 'AIWordPressManager\.Web')
            $isDotnetHost -and $belongsToProject
        } |
        ForEach-Object { [void]$ids.Add([int]$_.ProcessId) }

    netstat -ano -p tcp 2>$null |
        Select-String ":($port|5148)\s+.*LISTENING\s+(\d+)$" |
        ForEach-Object {
            if ($_.Matches.Count -gt 0) { [void]$ids.Add([int]$_.Matches[0].Groups[2].Value) }
        }

    return @($ids)
}

function Stop-AIWordPressManagerProcesses {
    Write-Host 'Stopping previous website instances...' -ForegroundColor Yellow
    $processIds = Get-ProjectProcessIds

    foreach ($processId in $processIds) {
        if ($processId -eq $PID) { continue }
        $existing = Get-Process -Id $processId -ErrorAction SilentlyContinue
        if ($existing) {
            Write-Host "Stopping project process PID $processId ($($existing.ProcessName))..." -ForegroundColor DarkYellow
            Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue
        }
    }

    $deadline = (Get-Date).AddSeconds(20)
    do {
        Start-Sleep -Milliseconds 500
        $remainingIds = @(Get-ProjectProcessIds | Where-Object { $_ -ne $PID })
    } while ($remainingIds.Count -gt 0 -and (Get-Date) -lt $deadline)

    if ($remainingIds.Count -gt 0) {
        foreach ($id in $remainingIds) { & taskkill.exe /PID $id /T /F 2>$null | Out-Null }
        Start-Sleep -Seconds 2
    }

    $remainingIds = @(Get-ProjectProcessIds | Where-Object { $_ -ne $PID })
    if ($remainingIds.Count -gt 0) {
        throw "Unable to stop previous website process(es): $($remainingIds -join ', '). Run PowerShell as Administrator."
    }
}

function Wait-ForBuildFilesToUnlock {
    $targets = @(
        (Join-Path $webOutput 'AIWordPressManager.Application.dll'),
        (Join-Path $webOutput 'AIWordPressManager.Domain.dll'),
        (Join-Path $webOutput 'AIWordPressManager.Infrastructure.dll'),
        (Join-Path $webOutput 'AIWordPressManager.Persistence.dll'),
        $webDll
    )

    $deadline = (Get-Date).AddSeconds(20)
    do {
        $locked = @()
        foreach ($file in $targets) {
            if (-not (Test-Path $file)) { continue }
            try {
                $stream = [System.IO.File]::Open($file, 'Open', 'ReadWrite', 'None')
                $stream.Dispose()
            }
            catch { $locked += $file }
        }
        if ($locked.Count -eq 0) { return }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    throw "Build files are still locked after stopping the website: $($locked -join ', ')"
}

function Remove-BuildOutputs {
    Write-Host 'Cleaning project build output...' -ForegroundColor Cyan
    Get-ChildItem (Join-Path $root 'src') -Directory -ErrorAction SilentlyContinue | ForEach-Object {
        foreach ($folderName in @('bin', 'obj')) {
            $folder = Join-Path $_.FullName $folderName
            if (Test-Path $folder) {
                Remove-Item $folder -Recurse -Force -ErrorAction Stop
            }
        }
    }
}

Stop-AIWordPressManagerProcesses
Wait-ForBuildFilesToUnlock
Remove-BuildOutputs

Write-Host 'Restoring packages...' -ForegroundColor Cyan
Invoke-RestoreWithRetry

Write-Host 'Building project...' -ForegroundColor Cyan
Invoke-DotNetProcess -Arguments @('build', $solution, '-c', 'Debug', '--no-restore', '-m:1') -FailureMessage 'Build failed.'

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
