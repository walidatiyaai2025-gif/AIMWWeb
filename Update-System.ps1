[CmdletBinding()]
param(
    [string]$ProjectPath = "C:\AIWordpressSite",
    [string]$Branch = "feature/system-health",
    [switch]$Clean
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
    Write-Host "=======================================" -ForegroundColor DarkCyan
    Write-Host " AI WordPress Manager - Update & Run" -ForegroundColor White
    Write-Host "=======================================" -ForegroundColor DarkCyan

    if (-not (Test-Path $ProjectPath)) {
        Stop-WithError "Project folder was not found: $ProjectPath. Run Install-First-Time.ps1 first."
    }

    if (-not (Test-Path (Join-Path $ProjectPath ".git"))) {
        Stop-WithError "The project folder is not a Git repository: $ProjectPath"
    }

    if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) {
        Stop-WithError "Git is not installed or is not available in PATH."
    }

    if (-not (Get-Command dotnet.exe -ErrorAction SilentlyContinue)) {
        Stop-WithError ".NET SDK is not installed or is not available in PATH."
    }

    Set-Location $ProjectPath

    Write-Step "Stopping previous application instances..."
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            ($_.Name -in @("dotnet.exe", "AIWordPressManager.Web.exe")) -and
            ($_.CommandLine -like "*$ProjectPath*")
        } |
        ForEach-Object {
            Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
        }

    Write-Step "Downloading the latest GitHub changes..."
    Invoke-Native "git.exe" @("fetch", "origin", "--prune") "Git fetch failed."
    Invoke-Native "git.exe" @("checkout", $Branch) "Git checkout failed."
    Invoke-Native "git.exe" @("reset", "--hard", "origin/$Branch") "Git reset failed."

    if ($Clean) {
        Write-Step "Cleaning all bin and obj folders..."
        Get-ChildItem -Path (Join-Path $ProjectPath "src") -Directory -Recurse -Force -ErrorAction SilentlyContinue |
            Where-Object { $_.Name -in @("bin", "obj") } |
            Remove-Item -Recurse -Force -ErrorAction SilentlyContinue
    }

    $solution = Join-Path $ProjectPath "AIWordPressManager.Web.sln"
    Write-Step "Restoring NuGet packages..."
    Invoke-Native "dotnet.exe" @("restore", $solution) "NuGet restore failed."

    Write-Step "Building the application..."
    Invoke-Native "dotnet.exe" @("build", $solution, "-c", "Debug", "--no-restore") "Build failed."

    $runner = Join-Path $ProjectPath "Build\Run-Web.ps1"
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
