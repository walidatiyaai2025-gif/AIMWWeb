[CmdletBinding()]
param(
    [Parameter(Mandatory = $false)]
    [string]$TargetPath = "C:\inetpub\wwwroot\AIMWWeb",

    [Parameter(Mandatory = $false)]
    [string]$IisSiteName = "",

    [Parameter(Mandatory = $false)]
    [string]$BackupRoot = "$env:ProgramData\AIMWWeb\UpdateBackups",

    [Parameter(Mandatory = $false)]
    [switch]$SkipIisControl
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$PackageRoot = $PSScriptRoot
$AppSource = Join-Path $PackageRoot "app"
$VersionFile = Join-Path $PackageRoot "VERSION.txt"
$PreserveNames = @(
    "Data",
    "Logs",
    "Screenshots",
    "Backups",
    "Exports",
    "Temp",
    "appsettings.Production.json",
    "appsettings.Local.json"
)

function Write-Step([string]$Message) {
    Write-Host "[AIMW UPDATE] $Message" -ForegroundColor Cyan
}

function Invoke-RobocopySafe {
    param(
        [Parameter(Mandatory = $true)][string]$Source,
        [Parameter(Mandatory = $true)][string]$Destination,
        [string[]]$ExtraArgs = @()
    )

    New-Item -ItemType Directory -Path $Destination -Force | Out-Null
    $args = @($Source, $Destination, "/E", "/COPY:DAT", "/DCOPY:DAT", "/R:2", "/W:1", "/XJ", "/NP", "/NFL", "/NDL") + $ExtraArgs
    & robocopy @args | Out-Null
    $code = $LASTEXITCODE
    if ($code -ge 8) {
        throw "Robocopy failed with exit code $code while copying '$Source' to '$Destination'."
    }
}

function Resolve-IisSiteByPath([string]$Path) {
    if ($SkipIisControl) { return $null }
    try {
        Import-Module WebAdministration -ErrorAction Stop
    }
    catch {
        return $null
    }

    if (-not [string]::IsNullOrWhiteSpace($IisSiteName)) {
        $site = Get-Website -Name $IisSiteName -ErrorAction SilentlyContinue
        if (-not $site) { throw "IIS site '$IisSiteName' was not found." }
        return $site
    }

    $resolvedTarget = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    foreach ($site in Get-Website) {
        $physicalPath = [Environment]::ExpandEnvironmentVariables([string]$site.physicalPath)
        if ([string]::IsNullOrWhiteSpace($physicalPath)) { continue }
        try {
            $resolvedPhysical = [IO.Path]::GetFullPath($physicalPath).TrimEnd('\')
            if ($resolvedPhysical.Equals($resolvedTarget, [StringComparison]::OrdinalIgnoreCase)) {
                return $site
            }
        }
        catch { }
    }

    return $null
}

function Stop-IisTarget($Site) {
    if (-not $Site) { return }
    $siteName = [string]$Site.Name
    $poolName = [string]$Site.applicationPool
    Write-Step "Stopping IIS site '$siteName'..."
    Stop-Website -Name $siteName -ErrorAction SilentlyContinue
    if (-not [string]::IsNullOrWhiteSpace($poolName)) {
        Write-Step "Stopping IIS app pool '$poolName'..."
        Stop-WebAppPool -Name $poolName -ErrorAction SilentlyContinue
    }
}

function Start-IisTarget($Site) {
    if (-not $Site) { return }
    $siteName = [string]$Site.Name
    $poolName = [string]$Site.applicationPool
    if (-not [string]::IsNullOrWhiteSpace($poolName)) {
        Write-Step "Starting IIS app pool '$poolName'..."
        Start-WebAppPool -Name $poolName -ErrorAction SilentlyContinue
    }
    Write-Step "Starting IIS site '$siteName'..."
    Start-Website -Name $siteName -ErrorAction SilentlyContinue
}

if (-not (Test-Path $AppSource -PathType Container)) {
    throw "Package is incomplete: '$AppSource' was not found. Extract the full update ZIP before running this script."
}

$webDll = Join-Path $AppSource "AIWordPressManager.Web.dll"
if (-not (Test-Path $webDll -PathType Leaf)) {
    throw "Package is incomplete: AIWordPressManager.Web.dll was not found in the app payload."
}

$TargetPath = [IO.Path]::GetFullPath($TargetPath)
$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupPath = Join-Path $BackupRoot $timestamp
$preservePath = Join-Path ([IO.Path]::GetTempPath()) "AIMWWeb-update-preserve-$([Guid]::NewGuid().ToString('N'))"
$site = $null
$stopped = $false
$backupCreated = $false

$versionText = if (Test-Path $VersionFile) { (Get-Content $VersionFile -Raw).Trim() } else { "unknown" }
Write-Step "Installing package: $versionText"
Write-Step "Target: $TargetPath"

try {
    New-Item -ItemType Directory -Path $TargetPath -Force | Out-Null
    New-Item -ItemType Directory -Path $BackupRoot -Force | Out-Null
    New-Item -ItemType Directory -Path $preservePath -Force | Out-Null

    $site = Resolve-IisSiteByPath $TargetPath
    if ($site) {
        Stop-IisTarget $site
        $stopped = $true
    }
    elseif (-not $SkipIisControl) {
        Write-Warning "No matching IIS site was detected. Ensure the application process is stopped before replacing files."
    }

    if (Test-Path $TargetPath -PathType Container) {
        $existingItems = @(Get-ChildItem -LiteralPath $TargetPath -Force -ErrorAction SilentlyContinue)
        if ($existingItems.Count -gt 0) {
            Write-Step "Creating full rollback backup at '$backupPath'..."
            Invoke-RobocopySafe -Source $TargetPath -Destination $backupPath
            $backupCreated = $true
        }
    }

    Write-Step "Preserving portable runtime data and local configuration..."
    foreach ($name in $PreserveNames) {
        $source = Join-Path $TargetPath $name
        if (-not (Test-Path $source)) { continue }
        $destination = Join-Path $preservePath $name
        if (Test-Path $source -PathType Container) {
            Invoke-RobocopySafe -Source $source -Destination $destination
        }
        else {
            $parent = Split-Path $destination -Parent
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
            Copy-Item -LiteralPath $source -Destination $destination -Force
        }
    }

    Write-Step "Replacing application binaries with the packaged build..."
    Get-ChildItem -LiteralPath $TargetPath -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
    Invoke-RobocopySafe -Source $AppSource -Destination $TargetPath

    Write-Step "Restoring preserved runtime data and local configuration..."
    foreach ($name in $PreserveNames) {
        $source = Join-Path $preservePath $name
        if (-not (Test-Path $source)) { continue }
        $destination = Join-Path $TargetPath $name
        if (Test-Path $source -PathType Container) {
            Invoke-RobocopySafe -Source $source -Destination $destination
        }
        else {
            Copy-Item -LiteralPath $source -Destination $destination -Force
        }
    }

    $installedDll = Join-Path $TargetPath "AIWordPressManager.Web.dll"
    if (-not (Test-Path $installedDll -PathType Leaf)) {
        throw "Post-install verification failed: AIWordPressManager.Web.dll is missing from the target."
    }

    $marker = [ordered]@{
        Package = $versionText
        InstalledAtUtc = (Get-Date).ToUniversalTime().ToString("o")
        TargetPath = $TargetPath
        RollbackBackup = if ($backupCreated) { $backupPath } else { $null }
    } | ConvertTo-Json -Depth 4
    Set-Content -LiteralPath (Join-Path $TargetPath ".aimw-update.json") -Value $marker -Encoding UTF8

    if ($stopped) {
        Start-IisTarget $site
        $stopped = $false
    }

    Write-Host ""
    Write-Host "AIMWWeb update installed successfully." -ForegroundColor Green
    Write-Host "Installed package: $versionText" -ForegroundColor Green
    if ($backupCreated) {
        Write-Host "Rollback backup: $backupPath" -ForegroundColor Yellow
    }
}
catch {
    Write-Host "Update failed: $($_.Exception.Message)" -ForegroundColor Red

    if ($backupCreated -and (Test-Path $backupPath -PathType Container)) {
        try {
            Write-Step "Rolling back the previous application files..."
            Get-ChildItem -LiteralPath $TargetPath -Force -ErrorAction SilentlyContinue | Remove-Item -Recurse -Force
            Invoke-RobocopySafe -Source $backupPath -Destination $TargetPath
            Write-Host "Rollback completed." -ForegroundColor Yellow
        }
        catch {
            Write-Host "Automatic rollback also failed: $($_.Exception.Message)" -ForegroundColor Red
        }
    }

    if ($stopped) {
        try { Start-IisTarget $site } catch { }
    }

    throw
}
finally {
    if (Test-Path $preservePath) {
        Remove-Item -LiteralPath $preservePath -Recurse -Force -ErrorAction SilentlyContinue
    }
}
