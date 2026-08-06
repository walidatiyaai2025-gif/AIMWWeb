[CmdletBinding()]
param(
    [string]$InstallPath = "C:\Apps\AIWM",
    [string]$RepositoryUrl = "https://github.com/walidatiyaai2025-gif/AIMWWeb.git",
    [string]$Branch = "feature/stable-ui-framework",
    [switch]$NoRun
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

function Write-Step([string]$Message) {
    Write-Host "`n[INFO] $Message" -ForegroundColor Cyan
}

function Write-Success([string]$Message) {
    Write-Host "[OK] $Message" -ForegroundColor Green
}

function Stop-WithError([string]$Message) {
    Write-Host "`n[ERROR] $Message" -ForegroundColor Red
    Read-Host "Press Enter to close"
    exit 1
}

function Invoke-Native([string]$FilePath, [string[]]$Arguments, [string]$FailureMessage) {
    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$FailureMessage Exit code: $LASTEXITCODE"
    }
}

function Stop-ApplicationProcesses {
    Write-Step "Stopping running AI WordPress Manager processes..."

    $processes = Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            $_.Name -in @("dotnet.exe", "AIWordPressManager.Web.exe") -and
            $_.CommandLine -match "AIWordPressManager.Web"
        }

    foreach ($process in $processes) {
        Write-Host "Stopping PID $($process.ProcessId): $($process.Name)" -ForegroundColor Yellow
        Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
    }

    Start-Sleep -Seconds 2
}

function Remove-BuildFolders([string]$RootPath) {
    Write-Step "Cleaning bin and obj folders..."

    Get-ChildItem -Path $RootPath -Recurse -Directory -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -in @("bin", "obj") } |
        ForEach-Object {
            Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
        }
}

try {
    Write-Host "==============================================" -ForegroundColor DarkCyan
    Write-Host " AI WordPress Manager - First-Time Setup" -ForegroundColor Cyan
    Write-Host " Install path: $InstallPath" -ForegroundColor Gray
    Write-Host " Branch: $Branch" -ForegroundColor Gray
    Write-Host "==============================================" -ForegroundColor DarkCyan

    Write-Step "Checking Git..."
    $git = Get-Command git.exe -ErrorAction SilentlyContinue
    if (-not $git) {
        throw "Git is not installed or is not available in PATH. Install Git for Windows, then run this setup again."
    }
    Write-Success "Git detected."

    Write-Step "Checking .NET SDK..."
    $dotnet = Get-Command dotnet.exe -ErrorAction SilentlyContinue
    if (-not $dotnet) {
        throw ".NET SDK is not installed or is not available in PATH. Install .NET 8 SDK, then run this setup again."
    }

    $sdks = & dotnet --list-sdks
    if (-not ($sdks -match '^8\.')) {
        throw ".NET 8 SDK is required but was not found."
    }
    Write-Success ".NET 8 SDK detected."

    Stop-ApplicationProcesses

    $parentPath = Split-Path -Path $InstallPath -Parent
    if (-not (Test-Path $parentPath)) {
        Write-Step "Creating parent folder: $parentPath"
        New-Item -ItemType Directory -Path $parentPath -Force | Out-Null
    }

    $gitFolder = Join-Path $InstallPath ".git"
    if (Test-Path $gitFolder) {
        Write-Step "Existing repository detected. Updating source code..."
        Set-Location $InstallPath

        Invoke-Native "git.exe" @("remote", "set-url", "origin", $RepositoryUrl) "Could not set Git remote."
        Invoke-Native "git.exe" @("fetch", "origin", "--prune") "Git fetch failed."
        Invoke-Native "git.exe" @("checkout", $Branch) "Could not checkout branch $Branch."
        Invoke-Native "git.exe" @("reset", "--hard", "origin/$Branch") "Could not reset to origin/$Branch."
        Invoke-Native "git.exe" @("clean", "-fd") "Could not clean untracked files."
    }
    else {
        if (Test-Path $InstallPath) {
            $existingItems = Get-ChildItem -Path $InstallPath -Force -ErrorAction SilentlyContinue
            if ($existingItems) {
                Write-Step "Removing existing non-repository folder: $InstallPath"
                Remove-Item -LiteralPath $InstallPath -Recurse -Force
            }
        }

        Write-Step "Cloning project into $InstallPath..."
        Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $InstallPath) "Git clone failed."
        Set-Location $InstallPath
    }

    $solutionPath = Join-Path $InstallPath "AIWordPressManager.Web.sln"
    $runScriptPath = Join-Path $InstallPath "Build\Run-Web.ps1"

    if (-not (Test-Path $solutionPath)) {
        throw "Solution file was not found: $solutionPath"
    }

    if (-not (Test-Path $runScriptPath)) {
        throw "Run script was not found: $runScriptPath"
    }

    Remove-BuildFolders $InstallPath

    Write-Step "Restoring NuGet packages..."
    Invoke-Native "dotnet.exe" @("restore", $solutionPath) "Restore failed."

    Write-Step "Building project..."
    Invoke-Native "dotnet.exe" @("build", $solutionPath, "--configuration", "Debug", "--no-restore") "Build failed."
    Write-Success "Build completed successfully."

    $webDll = Join-Path $InstallPath "src\AIWordPressManager.Web\bin\Debug\net8.0\AIWordPressManager.Web.dll"
    if (Test-Path $webDll) {
        $versionInfo = [System.Diagnostics.FileVersionInfo]::GetVersionInfo($webDll)
        Write-Host "Built version: $($versionInfo.ProductVersion)" -ForegroundColor Green
    }

    if (-not $NoRun) {
        Write-Step "Starting website..."
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runScriptPath
        if ($LASTEXITCODE -ne 0) {
            throw "Application stopped with exit code $LASTEXITCODE."
        }
    }
    else {
        Write-Success "Setup completed. Application start was skipped by -NoRun."
    }
}
catch {
    Stop-WithError $_.Exception.Message
}
