[CmdletBinding()]
param(
    [ValidateSet("Interactive", "InstallOrUpdate", "Pull", "Push", "Diagnose", "Build", "Test", "Run")]
    [string]$Mode = "Interactive",
    [string]$InstallPath = "",
    [string]$RepositoryUrl = "https://github.com/walidatiyaai2025-gif/AIMWWeb.git",
    [string]$Branch = "main",
    [ValidateSet("Debug", "Release")]
    [string]$Configuration = "Release",
    [ValidateSet("Ask", "Stash", "Abort", "Discard")]
    [string]$LocalChangesPolicy = "Ask",
    [string]$ServerUrl = "",
    [switch]$SkipStart,
    [switch]$SkipClean,
    [switch]$NonInteractive
)

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$script:OriginalLocation = Get-Location
$script:LocationWasPushed = $false
$script:LogDirectory = Join-Path ([System.IO.Path]::GetTempPath()) "AIWordPressManager-Setup"
$script:LogPath = Join-Path $script:LogDirectory ("setup-{0}.log" -f (Get-Date -Format "yyyyMMdd-HHmmss"))
$script:LastCommand = ""
$script:LastExitCode = 0
$script:LastOutput = ""
$script:LastErrorOutput = ""

New-Item -ItemType Directory -Path $script:LogDirectory -Force | Out-Null

function Write-Log {
    param(
        [Parameter(Mandatory)][string]$Message,
        [ValidateSet("INFO", "SUCCESS", "WARNING", "ERROR", "DEBUG")]
        [string]$Level = "INFO"
    )

    $line = "{0} [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
    Add-Content -LiteralPath $script:LogPath -Value $line -Encoding UTF8
}

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "[INFO] $Message" -ForegroundColor Cyan
    Write-Log $Message "INFO"
}

function Write-Success([string]$Message) {
    Write-Host "[SUCCESS] $Message" -ForegroundColor Green
    Write-Log $Message "SUCCESS"
}

function Write-WarningMessage([string]$Message) {
    Write-Host "[WARNING] $Message" -ForegroundColor Yellow
    Write-Log $Message "WARNING"
}

function Write-ErrorMessage([string]$Message) {
    Write-Host "[ERROR] $Message" -ForegroundColor Red
    Write-Log $Message "ERROR"
}

function Stop-WithError([string]$Message) {
    Write-Host ""
    Write-ErrorMessage $Message
    Write-Host ""
    Write-Host "Diagnostic log: $script:LogPath" -ForegroundColor Yellow

    if (-not [string]::IsNullOrWhiteSpace($script:LastCommand)) {
        Write-Host "Last command   : $script:LastCommand" -ForegroundColor DarkGray
        Write-Host "Exit code      : $script:LastExitCode" -ForegroundColor DarkGray
    }

    if ([Environment]::UserInteractive -and -not $NonInteractive) {
        Read-Host "Press Enter to close"
    }

    exit 1
}

function Read-RequiredValue {
    param(
        [Parameter(Mandatory)][string]$Prompt,
        [string]$DefaultValue = ""
    )

    if ($NonInteractive) {
        if ([string]::IsNullOrWhiteSpace($DefaultValue)) {
            throw "A required value was not supplied in non-interactive mode: $Prompt"
        }
        return $DefaultValue
    }

    while ($true) {
        $displayPrompt = if ([string]::IsNullOrWhiteSpace($DefaultValue)) { $Prompt } else { "$Prompt [$DefaultValue]" }
        $entered = Read-Host $displayPrompt
        $value = if ([string]::IsNullOrWhiteSpace($entered)) { $DefaultValue } else { $entered }

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

    if ($NonInteractive) {
        return $DefaultYes
    }

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
        [switch]$CaptureOutput,
        [switch]$AllowFailure,
        [switch]$Quiet
    )

    $script:LastCommand = "$FilePath $($Arguments -join ' ')"
    Write-Log "COMMAND: $script:LastCommand" "DEBUG"

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"

    try {
        $output = @(& $FilePath @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    $script:LastExitCode = $exitCode
    $script:LastOutput = ($output | Out-String).Trim()
    $script:LastErrorOutput = if ($exitCode -ne 0) { $script:LastOutput } else { "" }

    if (-not [string]::IsNullOrWhiteSpace($script:LastOutput)) {
        Add-Content -LiteralPath $script:LogPath -Value $script:LastOutput -Encoding UTF8
    }

    if ($exitCode -ne 0 -and -not $AllowFailure) {
        $details = $script:LastOutput
        if ([string]::IsNullOrWhiteSpace($details)) {
            throw "$FailureMessage Exit code: $exitCode"
        }
        throw "$FailureMessage`n$details`nExit code: $exitCode"
    }

    if (-not $Quiet -and -not $CaptureOutput -and -not [string]::IsNullOrWhiteSpace($script:LastOutput)) {
        $output | ForEach-Object {
            $text = $_.ToString()
            if (-not [string]::IsNullOrWhiteSpace($text)) { Write-Host $text }
        }
    }

    if ($CaptureOutput) { return $output }
    return $exitCode
}

function Test-NativeSuccess {
    param(
        [Parameter(Mandatory)][string]$FilePath,
        [Parameter(Mandatory)][string[]]$Arguments
    )

    $null = Invoke-Native $FilePath $Arguments "Command failed." -AllowFailure -Quiet
    return ($script:LastExitCode -eq 0)
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
    return ($Url.Trim().TrimEnd("/") -replace '\.git$', '').ToLowerInvariant()
}

function Assert-ValidBranch([string]$BranchName) {
    if (-not (Test-NativeSuccess "git.exe" @("check-ref-format", "--branch", $BranchName))) {
        throw "The Git branch name is invalid: $BranchName"
    }
}

function Get-CurrentBranch {
    return ((Invoke-Native "git.exe" @("rev-parse", "--abbrev-ref", "HEAD") "Could not read the current Git branch." -CaptureOutput) | Out-String).Trim()
}

function Get-TrackedFiles([string]$Pattern) {
    $items = @(Invoke-Native "git.exe" @("ls-files", "--", $Pattern) "Could not read tracked '$Pattern' files." -CaptureOutput)
    return @($items | ForEach-Object { $_.ToString().Trim() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Find-SolutionFile([string]$RootPath) {
    $tracked = @(Get-TrackedFiles "*.sln")
    if ($tracked.Count -eq 0) { throw "No tracked solution file (*.sln) exists in branch '$Branch'." }

    $candidates = @($tracked | ForEach-Object {
        $relative = $_
        $fullPath = [System.IO.Path]::GetFullPath((Join-Path $RootPath $relative))
        if (Test-Path -LiteralPath $fullPath) {
            [pscustomobject]@{
                Relative = $relative
                FullName = $fullPath
                Depth = (($relative -split '[\\/]').Count - 1)
            }
        }
    } | Where-Object { $_ })

    if ($candidates.Count -eq 0) { throw "Git lists solution files, but none exist on disk after synchronization." }

    $selected = $candidates | Sort-Object Depth, @{ Expression = { $_.Relative.Length } }, Relative | Select-Object -First 1
    if ($candidates.Count -gt 1) {
        Write-WarningMessage "Multiple tracked solutions exist. Selected the solution closest to the repository root: $($selected.Relative)"
    }
    return $selected.FullName
}

function Get-TrackedProjects([string]$RootPath) {
    return @(Get-TrackedFiles "*.csproj" | ForEach-Object {
        $relative = $_
        $fullPath = [System.IO.Path]::GetFullPath((Join-Path $RootPath $relative))
        if (Test-Path -LiteralPath $fullPath) {
            [pscustomobject]@{
                Relative = $relative
                FullName = $fullPath
                Directory = Split-Path $fullPath -Parent
                Depth = (($relative -split '[\\/]').Count - 1)
            }
        }
    } | Where-Object { $_ })
}

function Find-WebProject([string]$RootPath) {
    $projects = @(Get-TrackedProjects $RootPath | Where-Object {
        $_.Relative -notmatch '(^|[\\/])(tests?|TestResults)([\\/]|$)' -and
        (Get-Content -LiteralPath $_.FullName -Raw -ErrorAction SilentlyContinue) -match 'Microsoft\.NET\.Sdk\.Web'
    })

    if ($projects.Count -eq 0) { throw "No tracked ASP.NET Core Web project was found." }

    $selected = $projects | Sort-Object Depth, @{ Expression = { $_.Relative.Length } }, Relative | Select-Object -First 1
    if ($projects.Count -gt 1) {
        Write-WarningMessage "Multiple Web projects exist. Selected: $($selected.Relative)"
    }
    return $selected.FullName
}

function Enable-GitSafeDirectoryIfRequired([string]$RepositoryPath) {
    $status = Invoke-Native "git.exe" @("-C", $RepositoryPath, "status", "--porcelain") "Git could not inspect the repository." -AllowFailure -CaptureOutput
    if ($script:LastExitCode -eq 0) { return }

    $text = ($status | Out-String)
    if ($text -notmatch "dubious ownership") {
        throw "Git could not access the repository.`n$text"
    }

    Write-WarningMessage "Git reports dubious ownership for: $RepositoryPath"
    if (-not (Read-YesNo "Add this repository to Git safe.directory for the current user?" $true)) {
        throw "Repository trust was declined. Git cannot continue."
    }

    $safePath = $RepositoryPath.Replace("\", "/")
    $existing = @(Invoke-Native "git.exe" @("config", "--global", "--get-all", "safe.directory") "Could not read safe.directory." -AllowFailure -CaptureOutput)
    if (@($existing | ForEach-Object { $_.ToString().Trim() }) -notcontains $safePath) {
        Invoke-Native "git.exe" @("config", "--global", "--add", "safe.directory", $safePath) "Could not add safe.directory." | Out-Null
        Write-Success "Repository trust was added for the current Windows user."
    }
}

function Ensure-Prerequisites {
    Write-Step "Checking Git..."
    if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) {
        throw "Git is not installed or git.exe is not available in PATH. Install Git for Windows and run the setup tool again."
    }
    $gitVersion = ((Invoke-Native "git.exe" @("--version") "Could not read Git version." -CaptureOutput) | Out-String).Trim()
    Write-Success $gitVersion

    Write-Step "Checking .NET 8 SDK..."
    if (-not (Get-Command dotnet.exe -ErrorAction SilentlyContinue)) {
        throw ".NET SDK is not installed or dotnet.exe is not available in PATH. Install the .NET 8 SDK and run the setup tool again."
    }

    $sdks = @(Invoke-Native "dotnet.exe" @("--list-sdks") "Could not read installed .NET SDKs." -CaptureOutput)
    $sdk8 = @($sdks | Where-Object { $_.ToString() -match '^\s*8\.' })
    if ($sdk8.Count -eq 0) {
        throw ".NET 8 SDK was not found. A runtime-only installation is not enough for restore/build operations."
    }
    Write-Success (".NET 8 SDK found: " + ($sdk8[0].ToString().Trim()))
}

function Resolve-InstallPath {
    if ([string]::IsNullOrWhiteSpace($script:ResolvedInstallPath)) {
        if ([string]::IsNullOrWhiteSpace($InstallPath)) {
            $script:ResolvedInstallPath = Resolve-FullPath (Read-RequiredValue "Enter the full installation/repository directory")
        }
        else {
            $script:ResolvedInstallPath = Resolve-FullPath $InstallPath
        }
    }
    return $script:ResolvedInstallPath
}

function Ensure-Repository {
    param([switch]$AllowClone)

    $path = Resolve-InstallPath

    if (-not (Test-Path -LiteralPath $path)) {
        if (-not $AllowClone) { throw "Repository directory does not exist: $path" }

        $parent = Split-Path $path -Parent
        if (-not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }

        Write-Step "Checking remote branch '$Branch'..."
        $remote = Invoke-Native "git.exe" @("ls-remote", "--heads", $RepositoryUrl, $Branch) "Could not query the remote repository." -CaptureOutput
        if ([string]::IsNullOrWhiteSpace(($remote | Out-String).Trim())) { throw "Remote branch '$Branch' does not exist at $RepositoryUrl" }

        Write-Step "First installation detected. Cloning branch '$Branch'..."
        Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $path) "Git clone failed." | Out-Null
    }
    elseif (-not (Test-Path -LiteralPath (Join-Path $path ".git"))) {
        $entries = @(Get-ChildItem -LiteralPath $path -Force -ErrorAction SilentlyContinue)
        if ($entries.Count -eq 0 -and $AllowClone) {
            Write-Step "Empty installation directory detected. Cloning branch '$Branch'..."
            Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $path) "Git clone failed." | Out-Null
        }
        else {
            throw "The selected directory exists but is not a Git repository: $path`nChoose the existing repository folder or an empty folder for first installation."
        }
    }

    Enable-GitSafeDirectoryIfRequired $path

    if (-not $script:LocationWasPushed) {
        Push-Location $path
        $script:LocationWasPushed = $true
    }

    Assert-ValidBranch $Branch
    Ensure-Origin
}

function Ensure-Origin {
    $currentOrigin = ((Invoke-Native "git.exe" @("remote", "get-url", "origin") "Could not read Git origin." -AllowFailure -CaptureOutput) | Out-String).Trim()

    if ($script:LastExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($currentOrigin)) {
        if (Read-YesNo "No valid 'origin' remote was found. Add the configured repository URL as origin?" $true) {
            Invoke-Native "git.exe" @("remote", "add", "origin", $RepositoryUrl) "Could not add Git origin." | Out-Null
            Write-Success "Git origin added."
            return
        }
        throw "A Git origin remote is required."
    }

    if ((Normalize-GitRemote $currentOrigin) -ne (Normalize-GitRemote $RepositoryUrl)) {
        Write-WarningMessage "Origin does not match the configured repository."
        Write-Host "Current   : $currentOrigin"
        Write-Host "Configured: $RepositoryUrl"
        if (Read-YesNo "Replace origin with the configured repository URL?" $false) {
            Invoke-Native "git.exe" @("remote", "set-url", "origin", $RepositoryUrl) "Could not update Git origin." | Out-Null
            Write-Success "Git origin updated."
        }
        else {
            throw "Repository synchronization cancelled because origin does not match."
        }
    }
}

function Get-WorkingTreeChanges {
    return @((Invoke-Native "git.exe" @("status", "--porcelain") "Could not inspect local changes." -CaptureOutput) | ForEach-Object { $_.ToString() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Protect-LocalChanges {
    $changes = @(Get-WorkingTreeChanges)
    if ($changes.Count -eq 0) { return }

    Write-WarningMessage "Local uncommitted changes were detected. They will NOT be deleted automatically."
    $changes | Select-Object -First 20 | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
    if ($changes.Count -gt 20) { Write-Host "  ... and $($changes.Count - 20) more" -ForegroundColor Yellow }

    $policy = $LocalChangesPolicy
    if ($policy -eq "Ask") {
        if ($NonInteractive) { $policy = "Abort" }
        else {
            Write-Host ""
            Write-Host "1. Stash changes safely and continue" -ForegroundColor White
            Write-Host "2. Abort and leave everything untouched" -ForegroundColor White
            Write-Host "3. DISCARD local changes and continue" -ForegroundColor Red
            $choice = Read-Host "Select [1-3]"
            $policy = switch ($choice) { "1" { "Stash" } "3" { "Discard" } default { "Abort" } }
        }
    }

    switch ($policy) {
        "Stash" {
            $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
            Invoke-Native "git.exe" @("stash", "push", "--include-untracked", "-m", "AIWM Setup Tool backup $stamp") "Could not stash local changes." | Out-Null
            Write-Success "Local changes were saved in Git stash. They were not deleted."
            Write-WarningMessage "The setup tool will not automatically pop the stash, to avoid merge conflicts."
        }
        "Discard" {
            if (-not $NonInteractive -and -not (Read-YesNo "This permanently discards uncommitted changes. Continue?" $false)) {
                throw "Discard operation cancelled."
            }
            Invoke-Native "git.exe" @("reset", "--hard", "HEAD") "Could not discard tracked changes." | Out-Null
            Invoke-Native "git.exe" @("clean", "-fd") "Could not discard untracked files." | Out-Null
            Write-WarningMessage "Local uncommitted changes were discarded by explicit request."
        }
        default { throw "Update cancelled because the repository contains local uncommitted changes." }
    }
}

function Fetch-RemoteBranch {
    Write-Step "Fetching the latest origin/$Branch state..."
    $refSpec = "+refs/heads/${Branch}:refs/remotes/origin/${Branch}"
    Invoke-Native "git.exe" @("fetch", "origin", $refSpec, "--prune", "--tags") "Git fetch failed. Check network access, GitHub authentication, repository URL, and firewall/proxy settings." | Out-Null

    if (-not (Test-NativeSuccess "git.exe" @("show-ref", "--verify", "--quiet", "refs/remotes/origin/$Branch"))) {
        throw "Remote branch origin/$Branch was not found after fetch."
    }
}

function Switch-ToConfiguredBranch {
    $current = Get-CurrentBranch
    if ($current -eq $Branch) {
        Write-Success "Already using branch '$Branch'."
        return
    }

    if (Test-NativeSuccess "git.exe" @("show-ref", "--verify", "--quiet", "refs/heads/$Branch")) {
        Write-Step "Switching from '$current' to '$Branch'..."
        Invoke-Native "git.exe" @("switch", "--quiet", $Branch) "Could not switch to branch '$Branch'." | Out-Null
    }
    else {
        Write-Step "Creating local branch '$Branch' from origin/$Branch..."
        Invoke-Native "git.exe" @("switch", "--quiet", "--create", $Branch, "--track", "origin/$Branch") "Could not create local branch '$Branch'." | Out-Null
    }
}

function Sync-FromGitHub {
    Protect-LocalChanges
    Fetch-RemoteBranch
    Switch-ToConfiguredBranch

    Write-Step "Applying the latest origin/$Branch commit..."
    Invoke-Native "git.exe" @("reset", "--hard", "origin/$Branch") "Could not update the local repository to origin/$Branch." | Out-Null

    $local = ((Invoke-Native "git.exe" @("rev-parse", "HEAD") "Could not read local commit." -CaptureOutput) | Out-String).Trim()
    $remote = ((Invoke-Native "git.exe" @("rev-parse", "origin/$Branch") "Could not read remote commit." -CaptureOutput) | Out-String).Trim()
    if ($local -ne $remote) { throw "Local HEAD does not match origin/$Branch after synchronization." }

    $commit = ((Invoke-Native "git.exe" @("log", "-1", "--pretty=format:%h %cd %s", "--date=iso") "Could not read latest commit." -CaptureOutput) | Out-String).Trim()
    Write-Success "Repository synchronized: $commit"
}

function Stop-DotNetProcessesWithConsent {
    $processes = @(Get-Process dotnet -ErrorAction SilentlyContinue)
    if ($processes.Count -eq 0) { return $false }

    Write-WarningMessage "dotnet processes are running and may be locking build output."
    $processes | ForEach-Object { Write-Host ("  PID {0}  {1}" -f $_.Id, $_.ProcessName) -ForegroundColor Yellow }

    if (-not (Read-YesNo "Stop ALL listed dotnet processes and retry cleanup? This may stop other .NET applications." $false)) {
        return $false
    }

    $processes | Stop-Process -Force -ErrorAction Stop
    Start-Sleep -Milliseconds 500
    Write-Success "dotnet processes stopped by user request."
    return $true
}

function Remove-BuildOutput([string]$RootPath) {
    if ($SkipClean) {
        Write-WarningMessage "Build output cleanup skipped by parameter."
        return
    }

    $projectDirectories = @(Get-TrackedProjects $RootPath | Select-Object -ExpandProperty Directory -Unique)
    $failed = @()

    foreach ($projectDirectory in $projectDirectories) {
        foreach ($folderName in @("bin", "obj")) {
            $folder = Join-Path $projectDirectory $folderName
            if (Test-Path -LiteralPath $folder) {
                try { Remove-Item -LiteralPath $folder -Recurse -Force -ErrorAction Stop }
                catch { $failed += $folder }
            }
        }
    }

    if ($failed.Count -gt 0) {
        Write-WarningMessage "Some build folders are locked:"
        $failed | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
        if (Stop-DotNetProcessesWithConsent) {
            foreach ($folder in $failed) {
                if (Test-Path -LiteralPath $folder) { Remove-Item -LiteralPath $folder -Recurse -Force -ErrorAction Stop }
            }
        }
        else {
            throw "Build output is locked. Stop the application/process using these folders and run the setup tool again."
        }
    }
}

function Restore-Packages([string]$SolutionPath) {
    Write-Step "Restoring NuGet packages..."
    $null = Invoke-Native "dotnet.exe" @("restore", $SolutionPath) "NuGet restore failed." -AllowFailure
    if ($script:LastExitCode -eq 0) { Write-Success "NuGet restore completed."; return }

    Write-WarningMessage "NuGet restore failed."
    if (Read-YesNo "Clear all local NuGet caches and retry restore?" $true) {
        Invoke-Native "dotnet.exe" @("nuget", "locals", "all", "--clear") "Could not clear NuGet caches." | Out-Null
        Invoke-Native "dotnet.exe" @("restore", $SolutionPath) "NuGet restore failed again after clearing caches." | Out-Null
        Write-Success "NuGet restore completed after cache repair."
        return
    }

    throw "NuGet restore failed. See the diagnostic log for the package/source error."
}

function Build-Solution([string]$RootPath) {
    $solution = Find-SolutionFile $RootPath
    Write-Success "Selected solution: $solution"

    Write-Step "Cleaning tracked project build output..."
    Remove-BuildOutput $RootPath
    Restore-Packages $solution

    Write-Step "Building solution in $Configuration mode..."
    Invoke-Native "dotnet.exe" @("build", $solution, "--configuration", $Configuration, "--no-restore") "Build failed. See the compiler errors above and in the diagnostic log." | Out-Null
    Write-Success "Build completed successfully."
    return $solution
}

function Test-Solution([string]$RootPath) {
    $solution = Find-SolutionFile $RootPath
    Write-Step "Running tests in $Configuration mode..."
    Invoke-Native "dotnet.exe" @("test", $solution, "--configuration", $Configuration, "--no-build") "Tests failed. See the failing test names and stack traces in the diagnostic log." | Out-Null
    Write-Success "All executed tests passed."
}

function Test-ServerUrl([string]$Url) {
    $uri = $null
    if (-not [Uri]::TryCreate($Url, [UriKind]::Absolute, [ref]$uri)) { return $false }
    return $uri.Scheme -in @("http", "https")
}

function Assert-PortAvailable([string]$Url) {
    $uri = [Uri]$Url
    $port = $uri.Port
    if ($port -le 0) { return }

    if (Get-Command Get-NetTCPConnection -ErrorAction SilentlyContinue) {
        $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
        if ($listeners.Count -gt 0) {
            $pids = @($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
            throw "Port $port is already in use by PID(s): $($pids -join ', '). Stop the existing service or choose another server URL."
        }
    }
}

function Run-WebApplication([string]$RootPath) {
    $project = Find-WebProject $RootPath
    Write-Success "Selected Web project: $project"

    $url = $ServerUrl
    if ([string]::IsNullOrWhiteSpace($url)) {
        $url = Read-RequiredValue "Enter the server URL (example: http://0.0.0.0:7148)"
    }
    while (-not (Test-ServerUrl $url)) {
        if ($NonInteractive) { throw "Invalid ServerUrl: $url" }
        Write-WarningMessage "Enter a valid absolute HTTP or HTTPS URL."
        $url = Read-RequiredValue "Enter the server URL"
    }

    Assert-PortAvailable $url
    $env:ASPNETCORE_ENVIRONMENT = if ($Configuration -eq "Release") { "Production" } else { "Development" }

    Write-Host ""
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host " Starting AI WordPress Manager" -ForegroundColor White
    Write-Host " URL: $url" -ForegroundColor Green
    Write-Host " Log: $script:LogPath" -ForegroundColor DarkGray
    Write-Host " Press Ctrl+C to stop the application." -ForegroundColor Yellow
    Write-Host "====================================================" -ForegroundColor DarkCyan

    Invoke-Native "dotnet.exe" @("run", "--project", $project, "--configuration", $Configuration, "--no-build", "--no-launch-profile", "--urls", $url) "The web application stopped with an error." | Out-Null
}

function Push-ToGitHub {
    Fetch-RemoteBranch
    $current = Get-CurrentBranch
    if ($current -ne $Branch) { throw "Current branch is '$current'. Switch to '$Branch' before pushing through the setup tool." }

    $changes = @(Get-WorkingTreeChanges)
    if ($changes.Count -gt 0) {
        Write-WarningMessage "Uncommitted files exist. Git push will NOT upload them because they are not committed."
    }

    $aheadText = ((Invoke-Native "git.exe" @("rev-list", "--count", "origin/$Branch..HEAD") "Could not calculate commits ahead of origin." -CaptureOutput) | Out-String).Trim()
    $ahead = 0
    [void][int]::TryParse($aheadText, [ref]$ahead)

    if ($ahead -eq 0) {
        Write-Success "Nothing to push. Local committed history is not ahead of origin/$Branch."
        return
    }

    Write-Host "Local branch contains $ahead committed change(s) not on origin/$Branch." -ForegroundColor Yellow
    if (-not (Read-YesNo "Push these committed changes to origin/$Branch?" $false)) {
        throw "Push cancelled by user."
    }

    Invoke-Native "git.exe" @("push", "origin", $Branch) "Git push failed. Check authentication, branch protection, and network access." | Out-Null
    Write-Success "Committed changes pushed to origin/$Branch."
}

function Diagnose-Environment {
    Write-Host ""
    Write-Host "================ DIAGNOSTIC REPORT ================" -ForegroundColor DarkCyan
    Write-Host "Log file: $script:LogPath" -ForegroundColor DarkGray

    $issues = New-Object System.Collections.Generic.List[string]

    if (Get-Command git.exe -ErrorAction SilentlyContinue) {
        $git = ((Invoke-Native "git.exe" @("--version") "Git version check failed." -AllowFailure -CaptureOutput) | Out-String).Trim()
        Write-Host "Git          : $git"
    }
    else { $issues.Add("Git is not installed or not in PATH."); Write-Host "Git          : MISSING" -ForegroundColor Red }

    if (Get-Command dotnet.exe -ErrorAction SilentlyContinue) {
        $sdks = @(Invoke-Native "dotnet.exe" @("--list-sdks") ".NET SDK check failed." -AllowFailure -CaptureOutput)
        $has8 = @($sdks | Where-Object { $_.ToString() -match '^\s*8\.' }).Count -gt 0
        Write-Host "NET 8 SDK    : $(if ($has8) { 'FOUND' } else { 'MISSING' })" -ForegroundColor $(if ($has8) { 'Green' } else { 'Red' })
        if (-not $has8) { $issues.Add(".NET 8 SDK is missing.") }
    }
    else { $issues.Add("dotnet.exe is not installed or not in PATH."); Write-Host "dotnet       : MISSING" -ForegroundColor Red }

    if (-not [string]::IsNullOrWhiteSpace($InstallPath)) {
        $path = Resolve-FullPath $InstallPath
        Write-Host "Repository   : $path"
        if (-not (Test-Path -LiteralPath $path)) { $issues.Add("Repository directory does not exist.") }
        elseif (-not (Test-Path -LiteralPath (Join-Path $path ".git"))) { $issues.Add("Selected directory is not a Git repository.") }
        elseif (Get-Command git.exe -ErrorAction SilentlyContinue) {
            Enable-GitSafeDirectoryIfRequired $path
            Push-Location $path
            $pushedHere = $true
            try {
                $origin = ((Invoke-Native "git.exe" @("remote", "get-url", "origin") "Origin check failed." -AllowFailure -CaptureOutput) | Out-String).Trim()
                Write-Host "Origin       : $origin"
                $current = Get-CurrentBranch
                Write-Host "Branch       : $current"
                $dirty = @(Get-WorkingTreeChanges)
                Write-Host "Local changes: $($dirty.Count)"

                $solutions = @(Get-TrackedFiles "*.sln")
                Write-Host "Solutions    : $($solutions.Count) tracked"
                $projects = @(Get-TrackedFiles "*.csproj")
                Write-Host "Projects     : $($projects.Count) tracked"
            }
            catch { $issues.Add($_.Exception.Message) }
            finally { if ($pushedHere) { Pop-Location } }
        }
    }
    else {
        Write-Host "Repository   : not supplied (use -InstallPath for repository checks)" -ForegroundColor Yellow
    }

    if ($issues.Count -eq 0) {
        Write-Success "No blocking setup problems were detected."
    }
    else {
        Write-Host ""
        Write-Host "Detected problems:" -ForegroundColor Red
        $issues | ForEach-Object { Write-Host " - $_" -ForegroundColor Red }
        Write-Log ("Diagnostic issues: " + ($issues -join " | ")) "ERROR"
    }

    Write-Host "====================================================" -ForegroundColor DarkCyan
}

function Show-Menu {
    Write-Host ""
    Write-Host "Choose an operation:" -ForegroundColor White
    Write-Host "  1. Install first time OR update existing installation" -ForegroundColor White
    Write-Host "  2. Pull latest GitHub branch only" -ForegroundColor White
    Write-Host "  3. Build application only" -ForegroundColor White
    Write-Host "  4. Run tests only" -ForegroundColor White
    Write-Host "  5. Run application only" -ForegroundColor White
    Write-Host "  6. Diagnose environment/repository" -ForegroundColor White
    Write-Host "  7. Push already committed local changes to GitHub" -ForegroundColor White
    Write-Host "  0. Exit" -ForegroundColor DarkGray
    $choice = Read-Host "Select [0-7]"
    return switch ($choice) {
        "1" { "InstallOrUpdate" }
        "2" { "Pull" }
        "3" { "Build" }
        "4" { "Test" }
        "5" { "Run" }
        "6" { "Diagnose" }
        "7" { "Push" }
        default { "Exit" }
    }
}

try {
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host " AI WordPress Manager - Setup & Recovery Tool" -ForegroundColor White
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host "Diagnostic log: $script:LogPath" -ForegroundColor DarkGray
    Write-Log "Setup tool started. Mode=$Mode Branch=$Branch Configuration=$Configuration" "INFO"

    if ($Mode -eq "Interactive") {
        if ($NonInteractive) { throw "Interactive mode cannot be used with -NonInteractive." }
        $Mode = Show-Menu
        if ($Mode -eq "Exit") { Write-Success "No changes were made."; exit 0 }
    }

    if ($Mode -eq "Diagnose") {
        Diagnose-Environment
        exit 0
    }

    Ensure-Prerequisites

    $allowClone = ($Mode -eq "InstallOrUpdate")
    Ensure-Repository -AllowClone:$allowClone
    $root = Resolve-InstallPath

    switch ($Mode) {
        "InstallOrUpdate" {
            Sync-FromGitHub
            $null = Build-Solution $root
            if (-not $SkipStart -and (Read-YesNo "Build succeeded. Start AI WordPress Manager now?" $true)) {
                Run-WebApplication $root
            }
        }
        "Pull" {
            Sync-FromGitHub
            Write-Success "Pull/update completed. Build was not run."
        }
        "Push" { Push-ToGitHub }
        "Build" { $null = Build-Solution $root }
        "Test" { Test-Solution $root }
        "Run" { Run-WebApplication $root }
    }

    Write-Host ""
    Write-Success "Setup operation completed successfully."
    Write-Host "Diagnostic log: $script:LogPath" -ForegroundColor DarkGray
}
catch {
    Write-Log $_.Exception.ToString() "ERROR"
    Stop-WithError $_.Exception.Message
}
finally {
    if ($script:LocationWasPushed) {
        Pop-Location -ErrorAction SilentlyContinue
    }
    else {
        Set-Location $script:OriginalLocation -ErrorAction SilentlyContinue
    }
}
