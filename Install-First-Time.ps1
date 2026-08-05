[CmdletBinding()]
param(
    [string]$InstallPath = "C:\AIWordpressSite",
    [string]$RepositoryUrl = "https://github.com/walidatiyaai2025-gif/AIMWWeb.git",
    [string]$Branch = "feature/system-health"
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

function Write-Step([string]$Message) {
    Write-Host "`n[INFO] $Message" -ForegroundColor Cyan
}

function Stop-WithError([string]$Message) {
    Write-Host "`n[ERROR] $Message" -ForegroundColor Red
    Read-Host "Press Enter to close"
    exit 1
}

function Invoke-Native([string]$FilePath, [string[]]$Arguments, [string]$FailureMessage) {
    & $FilePath @Arguments
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "$FailureMessage Exit code: $exitCode"
    }
}

try {
    Write-Host "============================================" -ForegroundColor DarkCyan
    Write-Host " AI WordPress Manager - First Installation" -ForegroundColor White
    Write-Host "============================================" -ForegroundColor DarkCyan

    Write-Step "Checking Git..."
    if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) {
        Stop-WithError "Git is not installed. Install Git for Windows, then run this file again."
    }

    Write-Step "Checking .NET 8 SDK..."
    if (-not (Get-Command dotnet.exe -ErrorAction SilentlyContinue)) {
        Stop-WithError ".NET 8 SDK is not installed. Install the .NET 8 SDK, then run this file again."
    }

    $sdkList = & dotnet.exe --list-sdks
    if (-not ($sdkList | Where-Object { $_ -match '^8\.' })) {
        Stop-WithError ".NET 8 SDK was not found. A runtime-only installation is not enough."
    }

    if (Test-Path $InstallPath) {
        $gitFolder = Join-Path $InstallPath ".git"
        if (-not (Test-Path $gitFolder)) {
            Stop-WithError "The installation folder already exists but is not a Git repository: $InstallPath"
        }

        Write-Step "Existing repository found. Updating it instead of cloning again..."
        Set-Location $InstallPath
    }
    else {
        $parent = Split-Path $InstallPath -Parent
        if (-not (Test-Path $parent)) {
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
        }

        Write-Step "Cloning project from GitHub..."
        Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $InstallPath) "Git clone failed."
        Set-Location $InstallPath
    }

    Write-Step "Downloading the latest branch state..."
    Invoke-Native "git.exe" @("fetch", "origin", "--prune") "Git fetch failed."
    Invoke-Native "git.exe" @("checkout", $Branch) "Git checkout failed."
    Invoke-Native "git.exe" @("reset", "--hard", "origin/$Branch") "Git reset failed."

    Write-Step "Cleaning old build output..."
    Get-ChildItem -Path (Join-Path $InstallPath "src") -Directory -Recurse -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -in @("bin", "obj") } |
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

    $solution = Join-Path $InstallPath "AIWordPressManager.Web.sln"
    if (-not (Test-Path $solution)) {
        Stop-WithError "Solution file was not found: $solution"
    }

    Write-Step "Restoring NuGet packages..."
    Invoke-Native "dotnet.exe" @("restore", $solution) "NuGet restore failed."

    Write-Step "Building the application..."
    Invoke-Native "dotnet.exe" @("build", $solution, "-c", "Debug", "--no-restore") "Build failed."

    $runner = Join-Path $InstallPath "Build\Run-Web.ps1"
    if (-not (Test-Path $runner)) {
        Stop-WithError "Run-Web.ps1 was not found: $runner"
    }

    Write-Step "Starting AI WordPress Manager..."
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runner
    exit $LASTEXITCODE
}
catch {
    Stop-WithError $_.Exception.Message
}
