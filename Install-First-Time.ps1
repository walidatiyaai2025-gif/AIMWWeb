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

function Write-Step {
    param([Parameter(Mandatory)][string]$Message)
    Write-Host ""
    Write-Host "[INFO] $Message" -ForegroundColor Cyan
}

function Write-Success {
    param([Parameter(Mandatory)][string]$Message)
    Write-Host "[SUCCESS] $Message" -ForegroundColor Green
}

function Write-WarningMessage {
    param([Parameter(Mandatory)][string]$Message)
    Write-Host "[WARNING] $Message" -ForegroundColor Yellow
}

function Stop-WithError {
    param([Parameter(Mandatory)][string]$Message)
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
            $inputValue = Read-Host "$Prompt [$DefaultValue]"
            if ([string]::IsNullOrWhiteSpace($inputValue)) { $DefaultValue } else { $inputValue }
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
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw "$FailureMessage Exit code: $exitCode"
    }
}

function Resolve-FullPath {
    param([Parameter(Mandatory)][string]$Path)

    $expandedPath = [Environment]::ExpandEnvironmentVariables($Path.Trim())
    if ($expandedPath.StartsWith("~")) {
        $expandedPath = Join-Path $HOME $expandedPath.Substring(1).TrimStart("\", "/")
    }

    return [System.IO.Path]::GetFullPath($expandedPath)
}

function Enable-GitSafeDirectoryIfRequired {
    param([Parameter(Mandatory)][string]$RepositoryPath)

    $statusOutput = & git.exe -C $RepositoryPath status --porcelain 2>&1
    $statusExitCode = $LASTEXITCODE
    $statusText = ($statusOutput | Out-String)

    if ($statusExitCode -eq 0) { return }

    if ($statusText -notmatch "dubious ownership") {
        throw "Git could not access the repository.`n$statusText"
    }

    Write-WarningMessage "Git detected that this repository is owned by another Windows account."
    Write-Host "Repository: $RepositoryPath" -ForegroundColor Gray

    if (-not (Read-YesNo -Prompt "Add this repository to Git safe.directory for the current user?" -DefaultYes $true)) {
        throw "Git access was cancelled because the repository is not trusted."
    }

    $gitSafePath = $RepositoryPath.Replace("\", "/")
    Invoke-Native "git.exe" @("config", "--global", "--add", "safe.directory", $gitSafePath) "Could not add safe.directory."
    Write-Success "Repository added to Git safe.directory."
}

function Find-SolutionFile {
    param([Parameter(Mandatory)][string]$RootPath)

    $solutions = @(
        Get-ChildItem -Path $RootPath -Filter "*.sln" -File -Recurse -ErrorAction SilentlyContinue |
        Where-Object { $_.FullName -notmatch '[\\/](bin|obj|\.git)[\\/]' }
    )

    if ($solutions.Count -eq 0) {
        throw "No solution file (*.sln) was found under: $RootPath"
    }

    if ($solutions.Count -eq 1) {
        return $solutions[0].FullName
    }

    Write-Host ""
    Write-Host "Multiple solution files were found:" -ForegroundColor Yellow
    for ($i = 0; $i -lt $solutions.Count; $i++) {
        Write-Host "[$($i + 1)] $($solutions[$i].FullName)"
    }

    while ($true) {
        $selection = Read-Host "Select a solution number"
        $number = 0
        if ([int]::TryParse($selection, [ref]$number) -and $number -ge 1 -and $number -le $solutions.Count) {
            return $solutions[$number - 1].FullName
        }
        Write-WarningMessage "Invalid selection."
    }
}

function Find-WebProject {
    param([Parameter(Mandatory)][string]$RootPath)

    $projects = @(
        Get-ChildItem -Path $RootPath -Filter "*.csproj" -File -Recurse -ErrorAction SilentlyContinue |
        Where-Object {
            $_.FullName -notmatch '[\\/](bin|obj|tests|\.git)[\\/]' -and
            (Get-Content $_.FullName -Raw -ErrorAction SilentlyContinue) -match 'Microsoft\.NET\.Sdk\.Web'
        }
    )

    if ($projects.Count -eq 0) {
        throw "No ASP.NET Core Web project was found under: $RootPath"
    }

    if ($projects.Count -eq 1) {
        return $projects[0].FullName
    }

    Write-Host ""
    Write-Host "Multiple ASP.NET Core Web projects were found:" -ForegroundColor Yellow
    for ($i = 0; $i -lt $projects.Count; $i++) {
        Write-Host "[$($i + 1)] $($projects[$i].FullName)"
    }

    while ($true) {
        $selection = Read-Host "Select a web project number"
        $number = 0
        if ([int]::TryParse($selection, [ref]$number) -and $number -ge 1 -and $number -le $projects.Count) {
            return $projects[$number - 1].FullName
        }
        Write-WarningMessage "Invalid selection."
    }
}

function Test-ServerUrl {
    param([Parameter(Mandatory)][string]$Url)

    $parsedUri = $null
    if (-not [Uri]::TryCreate($Url, [UriKind]::Absolute, [ref]$parsedUri)) { return $false }
    return $parsedUri.Scheme -in @("http", "https")
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

    $sdkList = @(& dotnet.exe --list-sdks)
    if (-not ($sdkList | Where-Object { $_ -match '^\s*8\.' })) {
        throw ".NET 8 SDK was not found. A runtime-only installation is not enough for building."
    }

    if ([string]::IsNullOrWhiteSpace($InstallPath)) {
        $InstallPath = Read-RequiredValue -Prompt "Enter the full installation directory"
    }
    $InstallPath = Resolve-FullPath -Path $InstallPath

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
        Enable-GitSafeDirectoryIfRequired -RepositoryPath $InstallPath
    }
    else {
        $parentDirectory = Split-Path $InstallPath -Parent
        if ([string]::IsNullOrWhiteSpace($parentDirectory)) {
            throw "Could not determine the parent directory for: $InstallPath"
        }

        if (-not (Test-Path -LiteralPath $parentDirectory)) {
            New-Item -ItemType Directory -Path $parentDirectory -Force | Out-Null
        }

        Write-Step "Checking that branch '$Branch' exists..."
        $remoteBranch = & git.exe ls-remote --heads $RepositoryUrl $Branch 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "Could not query the Git repository.`n$($remoteBranch | Out-String)"
        }
        if (-not $remoteBranch) {
            throw "The branch '$Branch' does not exist in the remote repository."
        }

        Write-Step "Cloning the repository..."
        Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $InstallPath) "Git clone failed."
        Enable-GitSafeDirectoryIfRequired -RepositoryPath $InstallPath
    }

    Push-Location $InstallPath
    $LocationWasPushed = $true

    Write-Step "Checking origin remote..."
    $existingOrigin = ((Invoke-Native "git.exe" @("remote", "get-url", "origin") "Could not read origin." -CaptureOutput) | Out-String).Trim()
    if ($existingOrigin -ne $RepositoryUrl) {
        Write-WarningMessage "The existing origin does not match the requested repository."
        Write-Host "Current origin  : $existingOrigin"
        Write-Host "Requested origin: $RepositoryUrl"

        if (-not (Read-YesNo -Prompt "Replace the current origin URL?" -DefaultYes $false)) {
            throw "Repository update cancelled because the origin URL does not match."
        }

        Invoke-Native "git.exe" @("remote", "set-url", "origin", $RepositoryUrl) "Could not update origin URL."
    }

    Write-Step "Downloading the latest repository state..."
    Invoke-Native "git.exe" @("fetch", "origin", "--prune") "Git fetch failed."

    & git.exe show-ref --verify --quiet "refs/remotes/origin/$Branch"
    if ($LASTEXITCODE -ne 0) {
        throw "The remote branch origin/$Branch was not found after fetching."
    }

    & git.exe show-ref --verify --quiet "refs/heads/$Branch"
    if ($LASTEXITCODE -eq 0) {
        Invoke-Native "git.exe" @("switch", $Branch) "Could not switch to branch '$Branch'."
    }
    else {
        Invoke-Native "git.exe" @("switch", "--create", $Branch, "--track", "origin/$Branch") "Could not create local branch '$Branch'."
    }

    Write-Step "Resetting repository to origin/$Branch..."
    Invoke-Native "git.exe" @("reset", "--hard", "origin/$Branch") "Git reset failed."

    Write-Step "Removing untracked build files..."
    Invoke-Native "git.exe" @("clean", "-fd", "-e", "appsettings.Production.json") "Git clean failed."

    $solutionFile = Find-SolutionFile -RootPath $InstallPath
    Write-Host "Solution: $solutionFile" -ForegroundColor Gray

    Write-Step "Cleaning old build output..."
    Get-ChildItem -Path $InstallPath -Directory -Recurse -Force -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -in @("bin", "obj") -and $_.FullName -notmatch '[\\/]\.git[\\/]' } |
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

    $webProject = Find-WebProject -RootPath $InstallPath
    Write-Host "Web project: $webProject" -ForegroundColor Gray

    $serverUrl = Read-RequiredValue -Prompt "Enter the server URL to listen on, for example http://0.0.0.0:7148"
    while (-not (Test-ServerUrl -Url $serverUrl)) {
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
    Stop-WithError -Message $_.Exception.Message
}
finally {
    if ($LocationWasPushed) {
        Pop-Location -ErrorAction SilentlyContinue
    }
    else {
        Set-Location $OriginalLocation -ErrorAction SilentlyContinue
    }
}
