[CmdletBinding()]
param(
    [string]$InstallPath = "",
    [string]$RepositoryUrl = "https://github.com/walidatiyaai2025-gif/AIMWWeb.git",
    [string]$Branch = "main",
    [ValidateSet("Debug", "Release")]
    [string]$Configuration = "Release",
    [switch]$SkipStart
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$OriginalLocation = Get-Location
$LocationWasPushed = $false

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "[INFO] $Message" -ForegroundColor Cyan
}

function Write-Success([string]$Message) {
    Write-Host "[SUCCESS] $Message" -ForegroundColor Green
}

function Write-WarningMessage([string]$Message) {
    Write-Host "[WARNING] $Message" -ForegroundColor Yellow
}

function Stop-WithError([string]$Message) {
    Write-Host ""
    Write-Host "[ERROR] $Message" -ForegroundColor Red
    if ([Environment]::UserInteractive) {
        Read-Host "Press Enter to close"
    }
    exit 1
}

function Read-RequiredValue {
    param(
        [Parameter(Mandatory)][string]$Prompt,
        [string]$DefaultValue = ""
    )

    while ($true) {
        $value = if ([string]::IsNullOrWhiteSpace($DefaultValue)) {
            Read-Host $Prompt
        }
        else {
            $entered = Read-Host "$Prompt [$DefaultValue]"
            if ([string]::IsNullOrWhiteSpace($entered)) { $DefaultValue } else { $entered }
        }

        if (-not [string]::IsNullOrWhiteSpace($value)) {
            return $value.Trim()
        }

        Write-WarningMessage "A value is required."
    }
}

function Read-YesNo {
    param(
        [Parameter(Mandatory)][string]$Prompt,
        [bool]$DefaultYes = $true
    )

    $suffix = if ($DefaultYes) { "[Y/n]" } else { "[y/N]" }

    while ($true) {
        $answer = Read-Host "$Prompt $suffix"
        if ([string]::IsNullOrWhiteSpace($answer)) { return $DefaultYes }

        switch ($answer.Trim().ToLowerInvariant()) {
            "y"   { return $true }
            "yes" { return $true }
            "n"   { return $false }
            "no"  { return $false }
            default { Write-WarningMessage "Please answer Y or N." }
        }
    }
}

function Invoke-Native {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$Arguments,
        [Parameter(Mandatory)][string]$FailureMessage,
        [switch]$CaptureOutput
    )

    if ($CaptureOutput) {
        $output = & $FilePath @Arguments 2>&1
        $exitCode = $LASTEXITCODE
        if ($exitCode -ne 0) {
            $details = ($output | Out-String).Trim()
            throw "$FailureMessage`n$details`nExit code: $exitCode"
        }
        return $output
    }

    & $FilePath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "$FailureMessage Exit code: $LASTEXITCODE"
    }
}

function Resolve-FullPath([string]$Path) {
    $expanded = [Environment]::ExpandEnvironmentVariables($Path.Trim())
    if ($expanded.StartsWith("~")) {
        $expanded = Join-Path $HOME $expanded.Substring(1).TrimStart("\", "/")
    }
    return [System.IO.Path]::GetFullPath($expanded)
}

function Normalize-GitRemote([string]$Url) {
    if ([string]::IsNullOrWhiteSpace($Url)) { return "" }
    return $Url.Trim().TrimEnd("/").ToLowerInvariant() -replace '\.git$', ''
}

function Enable-GitSafeDirectoryIfRequired([string]$RepositoryPath) {
    $output = & git.exe -C $RepositoryPath status --porcelain 2>&1
    if ($LASTEXITCODE -eq 0) { return }

    $text = ($output | Out-String)
    if ($text -notmatch "dubious ownership") {
        throw "Git could not access the repository.`n$text"
    }

    Write-WarningMessage "Git detected that this repository is owned by another Windows account."
    if (-not (Read-YesNo -Prompt "Trust this repository for the current user?" -DefaultYes $true)) {
        throw "Git access was cancelled because the repository is not trusted."
    }

    $safePath = $RepositoryPath.Replace("\", "/")
    Invoke-Native "git.exe" @("config", "--global", "--add", "safe.directory", $safePath) "Could not add safe.directory."
}

function Get-RelativeDepth {
    param(
        [Parameter(Mandatory)][string]$RootPath,
        [Parameter(Mandatory)][string]$FilePath
    )

    $root = [System.IO.Path]::GetFullPath($RootPath).TrimEnd("\", "/")
    $directory = [System.IO.Path]::GetDirectoryName([System.IO.Path]::GetFullPath($FilePath))
    if ($directory.Equals($root, [System.StringComparison]::OrdinalIgnoreCase)) { return 0 }

    $relative = $directory.Substring($root.Length).TrimStart("\", "/")
    if ([string]::IsNullOrWhiteSpace($relative)) { return 0 }
    return ($relative -split '[\/]').Count
}

function Find-SolutionFile([string]$RootPath) {
    $rootSolutions = @(Get-ChildItem -LiteralPath $RootPath -Filter "*.sln" -File -ErrorAction SilentlyContinue)
    if ($rootSolutions.Count -gt 0) {
        return ($rootSolutions | Sort-Object Name | Select-Object -First 1).FullName
    }

    $solutions = @(
        Get-ChildItem -Path $RootPath -Filter "*.sln" -File -Recurse -ErrorAction SilentlyContinue |
        Where-Object {
            $_.FullName -notmatch '[\/](bin|obj|\.git|tests|TestResults)[\/]' -and
            -not (Test-Path -LiteralPath (Join-Path $_.Directory.FullName ".git"))
        } |
        Sort-Object `
            @{ Expression = { Get-RelativeDepth -RootPath $RootPath -FilePath $_.FullName }; Ascending = $true },
            @{ Expression = { $_.FullName.Length }; Ascending = $true },
            @{ Expression = { $_.Name }; Ascending = $true }
    )

    if ($solutions.Count -eq 0) {
        throw "No solution file (*.sln) was found in the current branch under: $RootPath"
    }

    if ($solutions.Count -gt 1) {
        Write-WarningMessage "Multiple solution files exist. The closest one to the repository root was selected automatically."
    }

    return $solutions[0].FullName
}

function Find-WebProject {
    param(
        [Parameter(Mandatory)][string]$RootPath,
        [Parameter(Mandatory)][string]$SolutionPath
    )

    $solutionDirectory = Split-Path $SolutionPath -Parent
    $projects = @(
        Get-ChildItem -Path $solutionDirectory -Filter "*.csproj" -File -Recurse -ErrorAction SilentlyContinue |
        Where-Object {
            $_.FullName -notmatch '[\/](bin|obj|tests|TestResults|\.git)[\/]' -and
            (Get-Content -LiteralPath $_.FullName -Raw -ErrorAction SilentlyContinue) -match 'Microsoft\.NET\.Sdk\.Web'
        } |
        Sort-Object `
            @{ Expression = { Get-RelativeDepth -RootPath $solutionDirectory -FilePath $_.FullName }; Ascending = $true },
            @{ Expression = { $_.FullName.Length }; Ascending = $true }
    )

    if ($projects.Count -eq 0) {
        throw "No ASP.NET Core Web project was found for solution: $SolutionPath"
    }

    return $projects[0].FullName
}

function Test-ServerUrl([string]$Url) {
    $parsed = $null
    if (-not [Uri]::TryCreate($Url, [UriKind]::Absolute, [ref]$parsed)) { return $false }
    return $parsed.Scheme -in @("http", "https")
}

try {
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host " AI WordPress Manager - Install / Update Utility" -ForegroundColor White
    Write-Host "====================================================" -ForegroundColor DarkCyan

    Write-Step "Checking Git installation..."
    if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) {
        throw "Git is not installed or git.exe is not available in PATH."
    }

    Write-Step "Checking .NET 8 SDK..."
    if (-not (Get-Command dotnet.exe -ErrorAction SilentlyContinue)) {
        throw ".NET SDK is not installed or dotnet.exe is not available in PATH."
    }

    $sdks = @(& dotnet.exe --list-sdks)
    if (-not ($sdks | Where-Object { $_ -match '^\s*8\.' })) {
        throw ".NET 8 SDK was not found. A runtime-only installation is not enough for building."
    }

    if ([string]::IsNullOrWhiteSpace($InstallPath)) {
        $InstallPath = Read-RequiredValue -Prompt "Enter the full installation directory"
    }
    $InstallPath = Resolve-FullPath $InstallPath

    if ([string]::IsNullOrWhiteSpace($Branch)) {
        $Branch = Read-RequiredValue -Prompt "Enter the Git branch" -DefaultValue "main"
    }

    Write-Host ""
    Write-Host "Installation directory : $InstallPath"
    Write-Host "Repository URL         : $RepositoryUrl"
    Write-Host "Branch                 : $Branch"
    Write-Host "Build configuration    : $Configuration"

    if (Test-Path -LiteralPath $InstallPath) {
        if (-not (Test-Path -LiteralPath (Join-Path $InstallPath ".git"))) {
            throw "The selected directory exists but is not a Git repository: $InstallPath"
        }
        Enable-GitSafeDirectoryIfRequired $InstallPath
    }
    else {
        $parent = Split-Path $InstallPath -Parent
        if (-not (Test-Path -LiteralPath $parent)) {
            New-Item -ItemType Directory -Path $parent -Force | Out-Null
        }

        Write-Step "Checking branch '$Branch'..."
        $remoteBranch = & git.exe ls-remote --heads $RepositoryUrl $Branch 2>&1
        if ($LASTEXITCODE -ne 0 -or -not $remoteBranch) {
            throw "The branch '$Branch' could not be found in the remote repository."
        }

        Write-Step "Cloning the latest branch state..."
        Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $InstallPath) "Git clone failed."
        Enable-GitSafeDirectoryIfRequired $InstallPath
    }

    Push-Location $InstallPath
    $LocationWasPushed = $true

    Write-Step "Checking origin remote..."
    $currentOrigin = ((Invoke-Native "git.exe" @("remote", "get-url", "origin") "Could not read origin." -CaptureOutput) | Out-String).Trim()
    if ((Normalize-GitRemote $currentOrigin) -ne (Normalize-GitRemote $RepositoryUrl)) {
        Write-WarningMessage "The existing origin points to a different repository."
        Write-Host "Current origin  : $currentOrigin"
        Write-Host "Requested origin: $RepositoryUrl"
        if (-not (Read-YesNo -Prompt "Replace the current origin URL?" -DefaultYes $false)) {
            throw "Repository update was cancelled."
        }
        Invoke-Native "git.exe" @("remote", "set-url", "origin", $RepositoryUrl) "Could not update origin URL."
    }

    Write-Step "Fetching the latest commit from origin/$Branch..."
    Invoke-Native "git.exe" @("fetch", "origin", $Branch, "--prune", "--tags") "Git fetch failed."

    & git.exe show-ref --verify --quiet "refs/remotes/origin/$Branch"
    if ($LASTEXITCODE -ne 0) {
        throw "Remote branch origin/$Branch was not found after fetch."
    }

    & git.exe show-ref --verify --quiet "refs/heads/$Branch"
    if ($LASTEXITCODE -eq 0) {
        Invoke-Native "git.exe" @("switch", $Branch) "Could not switch to branch '$Branch'."
    }
    else {
        Invoke-Native "git.exe" @("switch", "--create", $Branch, "--track", "origin/$Branch") "Could not create local branch '$Branch'."
    }

    Write-Step "Applying the latest origin/$Branch commit..."
    Invoke-Native "git.exe" @("reset", "--hard", "origin/$Branch") "Git reset failed."

    $commit = ((Invoke-Native "git.exe" @("log", "-1", "--pretty=format:%h %cd %s", "--date=iso") "Could not read the latest commit." -CaptureOutput) | Out-String).Trim()
    Write-Success "Latest branch commit: $commit"

    Write-Step "Removing untracked build files..."
    Invoke-Native "git.exe" @("clean", "-fd", "-e", "appsettings.Production.json") "Git clean failed."

    Write-Step "Detecting the solution automatically..."
    $solutionFile = Find-SolutionFile $InstallPath
    Write-Success "Selected solution: $solutionFile"

    Write-Step "Cleaning old build output..."
    Get-ChildItem -Path (Split-Path $solutionFile -Parent) -Directory -Recurse -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -in @("bin", "obj") -and $_.FullName -notmatch '[\/]\.git[\/]' } |
        ForEach-Object { Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction SilentlyContinue }

    Write-Step "Restoring NuGet packages..."
    Invoke-Native "dotnet.exe" @("restore", $solutionFile) "NuGet restore failed."

    Write-Step "Building the application in $Configuration mode..."
    Invoke-Native "dotnet.exe" @("build", $solutionFile, "--configuration", $Configuration, "--no-restore") "Application build failed."
    Write-Success "Application build completed successfully."

    if ($SkipStart) {
        Write-Success "Installation/update completed. Application startup was skipped."
        exit 0
    }

    if (-not (Read-YesNo -Prompt "Start AI WordPress Manager now?" -DefaultYes $true)) {
        Write-Success "Installation/update completed without starting the application."
        exit 0
    }

    $webProject = Find-WebProject -RootPath $InstallPath -SolutionPath $solutionFile
    Write-Success "Selected Web project: $webProject"

    $serverUrl = Read-RequiredValue -Prompt "Enter the server URL, for example http://0.0.0.0:7148"
    while (-not (Test-ServerUrl $serverUrl)) {
        Write-WarningMessage "Enter a valid absolute HTTP or HTTPS URL."
        $serverUrl = Read-RequiredValue -Prompt "Enter the server URL"
    }

    $env:ASPNETCORE_ENVIRONMENT = if ($Configuration -eq "Release") { "Production" } else { "Development" }

    Write-Host ""
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host " Starting AI WordPress Manager" -ForegroundColor White
    Write-Host " URL: $serverUrl" -ForegroundColor Green
    Write-Host " Press Ctrl+C to stop the application." -ForegroundColor Yellow
    Write-Host "====================================================" -ForegroundColor DarkCyan

    Invoke-Native "dotnet.exe" @(
        "run",
        "--project", $webProject,
        "--configuration", $Configuration,
        "--no-build",
        "--no-launch-profile",
        "--urls", $serverUrl
    ) "The application stopped with an error."
}
catch {
    Stop-WithError $_.Exception.Message
}
finally {
    if ($LocationWasPushed) {
        Pop-Location -ErrorAction SilentlyContinue
    }
    else {
        Set-Location $OriginalLocation -ErrorAction SilentlyContinue
    }
}
