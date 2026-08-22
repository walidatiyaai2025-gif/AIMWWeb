#requires -RunAsAdministrator
[CmdletBinding()]
param(
    [string]$SiteName = "AIMWWeb",
    [string]$AppPoolName = "AIMWWeb",
    [string]$PhysicalPath = "C:\inetpub\AIMWWeb",
    [int]$Port = 8088,
    [string]$RepoOwner = "walidatiyaai2025-gif",
    [string]$RepoName = "AIMWWeb",
    [string]$RepoRef = "__SOURCE_COMMIT__",
    [switch]$NoOpenBrowser
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$programDataRoot = "C:\ProgramData\AIMWWeb"
$logDir = Join-Path $programDataRoot "Logs"
$backupDir = Join-Path $programDataRoot ("Backups\GitHubPatch-" + $timestamp)
$tempRoot = Join-Path $env:TEMP ("AIMWWeb-GitHubPatch-" + $timestamp)
$sourceZip = Join-Path $tempRoot "source.zip"
$sourceExtract = Join-Path $tempRoot "source"
$publishDir = Join-Path $tempRoot "publish"
$logFile = Join-Path $logDir ("github-patch-" + $timestamp + ".log")
$previousIisState = $null
$backupCreated = $false
$deploymentStarted = $false

$persistentDirs = @("Data", "Logs", "Screenshots", "Backups", "Exports", "Temp")
$persistentFiles = @("appsettings.Production.json", "appsettings.Local.json")

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "=== $Message ===" -ForegroundColor Cyan
}

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Invoke-External([string]$FilePath, [string[]]$Arguments) {
    Write-Host "> $FilePath $($Arguments -join ' ')" -ForegroundColor DarkGray
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code ${LASTEXITCODE}: $FilePath $($Arguments -join ' ')"
    }
}

function Invoke-Robocopy([string]$Source, [string]$Destination, [string[]]$ExtraArgs = @()) {
    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    $args = @($Source, $Destination, "/E", "/R:2", "/W:1", "/COPY:DAT", "/DCOPY:DAT", "/NFL", "/NDL", "/NP", "/NJH", "/NJS") + $ExtraArgs
    & robocopy.exe @args
    $code = $LASTEXITCODE
    if ($code -ge 8) {
        throw "Robocopy failed with exit code $code while copying '$Source' to '$Destination'."
    }
}

function Stop-AIMWWeb {
    Import-Module WebAdministration -ErrorAction Stop
    $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
    $poolExists = Test-Path "IIS:\AppPools\$AppPoolName"
    $state = [pscustomobject]@{
        SiteWasStarted = ($null -ne $site -and $site.State -eq "Started")
        AppPoolWasStarted = ($poolExists -and (Get-WebAppPoolState -Name $AppPoolName).Value -eq "Started")
    }

    if ($null -ne $site -and $site.State -ne "Stopped") { Stop-Website -Name $SiteName -ErrorAction SilentlyContinue }
    if ($poolExists -and (Get-WebAppPoolState -Name $AppPoolName).Value -ne "Stopped") { Stop-WebAppPool -Name $AppPoolName -ErrorAction SilentlyContinue }

    $deadline = (Get-Date).AddSeconds(30)
    do {
        $siteStopped = $true
        $siteNow = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
        if ($null -ne $siteNow) { $siteStopped = ($siteNow.State -eq "Stopped") }
        $poolStopped = $true
        if (Test-Path "IIS:\AppPools\$AppPoolName") { $poolStopped = ((Get-WebAppPoolState -Name $AppPoolName).Value -eq "Stopped") }
        if ($siteStopped -and $poolStopped) { break }
        Start-Sleep -Milliseconds 400
    } while ((Get-Date) -lt $deadline)

    if (-not $siteStopped -or -not $poolStopped) { throw "IIS site/app pool did not stop cleanly within 30 seconds." }
    Start-Sleep -Seconds 1
    return $state
}

function Start-AIMWWeb {
    Import-Module WebAdministration -ErrorAction Stop
    if (Test-Path "IIS:\AppPools\$AppPoolName") {
        if ((Get-WebAppPoolState -Name $AppPoolName).Value -ne "Started") { Start-WebAppPool -Name $AppPoolName }
    }
    $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
    if ($null -ne $site -and $site.State -ne "Started") { Start-Website -Name $SiteName }
}

function Restore-PreviousIisState($State) {
    if ($null -eq $State) { return }
    Import-Module WebAdministration -ErrorAction SilentlyContinue
    try {
        if ($State.AppPoolWasStarted -and (Test-Path "IIS:\AppPools\$AppPoolName")) {
            if ((Get-WebAppPoolState -Name $AppPoolName).Value -ne "Started") { Start-WebAppPool -Name $AppPoolName -ErrorAction SilentlyContinue }
        }
        if ($State.SiteWasStarted) {
            $site = Get-Website -Name $SiteName -ErrorAction SilentlyContinue
            if ($null -ne $site -and $site.State -ne "Started") { Start-Website -Name $SiteName -ErrorAction SilentlyContinue }
        }
    }
    catch { Write-Warning "Could not fully restore the previous IIS running state: $($_.Exception.Message)" }
}

function Wait-HttpOk([string]$Url, [int]$Attempts = 20) {
    $last = $null
    for ($i = 1; $i -le $Attempts; $i++) {
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5 -MaximumRedirection 0 -ErrorAction Stop
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) { return $response }
        }
        catch {
            if ($_.Exception.Response -and $_.Exception.Response.StatusCode.value__ -in 301,302,307,308) { return $_.Exception.Response }
            $last = $_.Exception.Message
        }
        Start-Sleep -Seconds 2
    }
    throw "HTTP verification failed for '$Url'. Last result: $last"
}

if (-not (Test-Administrator)) { throw "Run Patch.cmd or this PowerShell script as Administrator." }

New-Item -ItemType Directory -Path $logDir -Force | Out-Null
New-Item -ItemType Directory -Path $tempRoot -Force | Out-Null
Start-Transcript -Path $logFile -Append | Out-Null

try {
    Write-Host "AIMWWeb GitHub Patch" -ForegroundColor Yellow
    Write-Host "Version       : __VERSION__"
    Write-Host "Pinned commit : $RepoRef"
    Write-Host "Target        : $PhysicalPath"
    Write-Host "IIS           : $SiteName / $AppPoolName"

    Write-Step "Preflight"
    if (-not (Test-Path -LiteralPath $PhysicalPath)) { throw "AIMWWeb physical path was not found: $PhysicalPath" }
    if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) { throw ".NET 8 SDK is required to build this patch." }
    $sdkInfo = & dotnet --list-sdks
    if (-not ($sdkInfo -match '(?m)^8\.')) { throw ".NET 8 SDK is required to build this patch." }
    Import-Module WebAdministration -ErrorAction Stop
    if (-not (Get-Website -Name $SiteName -ErrorAction SilentlyContinue)) { throw "IIS site '$SiteName' was not found." }

    Write-Step "Downloading exact source from GitHub"
    $archiveUrl = "https://github.com/$RepoOwner/$RepoName/archive/$RepoRef.zip"
    Write-Host $archiveUrl -ForegroundColor DarkGray
    Invoke-WebRequest -Uri $archiveUrl -OutFile $sourceZip -UseBasicParsing -TimeoutSec 180
    if ((Get-Item $sourceZip).Length -lt 10000) { throw "Downloaded source archive is unexpectedly small." }

    New-Item -ItemType Directory -Path $sourceExtract -Force | Out-Null
    Expand-Archive -LiteralPath $sourceZip -DestinationPath $sourceExtract -Force
    $solution = Get-ChildItem -LiteralPath $sourceExtract -Filter "AIWordPressManager.Web.sln" -File -Recurse | Select-Object -First 1
    if ($null -eq $solution) { throw "AIMWWeb solution was not found in the downloaded archive." }
    $repoRoot = $solution.Directory.FullName

    Write-Step "Building and publishing before IIS downtime"
    Push-Location $repoRoot
    try {
        Invoke-External "dotnet" @("restore", ".\AIWordPressManager.Web.sln")
        Invoke-External "dotnet" @("publish", ".\src\AIWordPressManager.Web\AIWordPressManager.Web.csproj", "-c", "Release", "--no-restore", "-o", $publishDir, "/p:GitBranch=main")
    }
    finally { Pop-Location }

    $publishedDll = Join-Path $publishDir "AIWordPressManager.Web.dll"
    $publishedWebConfig = Join-Path $publishDir "web.config"
    if (-not (Test-Path -LiteralPath $publishedDll)) { throw "Published AIWordPressManager.Web.dll was not found. Deployment stopped before IIS downtime." }
    if (-not (Test-Path -LiteralPath $publishedWebConfig)) { throw "Published web.config was not found. Deployment stopped before IIS downtime." }

    Write-Step "Stopping IIS"
    $previousIisState = Stop-AIMWWeb

    Write-Step "Backing up current application files"
    $backupArgs = @("/XD") + $persistentDirs
    Invoke-Robocopy $PhysicalPath $backupDir $backupArgs
    $backupCreated = $true
    Write-Host "Backup: $backupDir" -ForegroundColor Green

    Write-Step "Applying patch"
    $deploymentStarted = $true
    $deployArgs = @("/XD") + $persistentDirs + @("/XF") + $persistentFiles
    Invoke-Robocopy $publishDir $PhysicalPath $deployArgs

    $installedDll = Join-Path $PhysicalPath "AIWordPressManager.Web.dll"
    if (-not (Test-Path -LiteralPath $installedDll)) { throw "AIWordPressManager.Web.dll was not installed." }

    $marker = [ordered]@{
        package = "AIMWWeb GitHub Patch"
        version = "__VERSION__"
        commit = $RepoRef
        appliedAtUtc = (Get-Date).ToUniversalTime().ToString("o")
        backup = $backupDir
    } | ConvertTo-Json -Depth 4
    Set-Content -LiteralPath (Join-Path $PhysicalPath ".aimw-patch.json") -Value $marker -Encoding UTF8

    Write-Step "Starting IIS"
    Start-AIMWWeb

    Write-Step "Verifying application"
    $healthUrl = "http://127.0.0.1:$Port/health/live"
    $welcomeUrl = "http://127.0.0.1:$Port/welcome"
    $null = Wait-HttpOk $healthUrl
    $null = Wait-HttpOk $welcomeUrl

    Write-Host ""
    Write-Host "PATCH APPLIED SUCCESSFULLY" -ForegroundColor Green
    Write-Host "Health : $healthUrl" -ForegroundColor Green
    Write-Host "Welcome: $welcomeUrl" -ForegroundColor Green
    Write-Host "Backup : $backupDir"
    Write-Host "Log    : $logFile"
    Write-Host "Commit : $RepoRef"

    if (-not $NoOpenBrowser) { try { Start-Process "http://localhost:$Port/welcome" } catch { } }
}
catch {
    Write-Host ""
    Write-Host "PATCH FAILED" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red

    if ($deploymentStarted -and $backupCreated) {
        Write-Warning "Attempting automatic rollback from $backupDir ..."
        try {
            $null = Stop-AIMWWeb
            $rollbackArgs = @("/MIR", "/XD") + $persistentDirs + @("/XF") + $persistentFiles
            Invoke-Robocopy $backupDir $PhysicalPath $rollbackArgs
            Start-AIMWWeb
            Write-Host "Rollback completed." -ForegroundColor Yellow
        }
        catch { Write-Warning "Automatic rollback failed: $($_.Exception.Message)" }
    }
    else { Restore-PreviousIisState $previousIisState }

    Write-Host "Log: $logFile" -ForegroundColor Yellow
    throw
}
finally {
    try { Stop-Transcript | Out-Null } catch { }
    if (Test-Path -LiteralPath $tempRoot) { Remove-Item -LiteralPath $tempRoot -Recurse -Force -ErrorAction SilentlyContinue }
}
