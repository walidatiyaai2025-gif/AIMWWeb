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
$script:ResolvedInstallPath = ""
$script:LogDirectory = Join-Path ([System.IO.Path]::GetTempPath()) "AIWordPressManager-Setup"
$script:LogPath = Join-Path $script:LogDirectory ("setup-{0}.log" -f (Get-Date -Format "yyyyMMdd-HHmmss"))
$script:LastCommand = ""
$script:LastExitCode = 0
$script:LastOutput = ""

New-Item -ItemType Directory -Path $script:LogDirectory -Force | Out-Null

function Write-Log {
    param([string]$Message, [string]$Level = "INFO")
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

function Stop-WithError([string]$Message) {
    Write-Host ""
    Write-Host "[ERROR] $Message" -ForegroundColor Red
    Write-Log $Message "ERROR"
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
    param([string]$Prompt, [string]$DefaultValue = "")

    if ($NonInteractive) {
        if ([string]::IsNullOrWhiteSpace($DefaultValue)) {
            throw "Required value missing in non-interactive mode: $Prompt"
        }
        return $DefaultValue
    }

    while ($true) {
        $display = if ([string]::IsNullOrWhiteSpace($DefaultValue)) { $Prompt } else { "$Prompt [$DefaultValue]" }
        $entered = Read-Host $display
        $value = if ([string]::IsNullOrWhiteSpace($entered)) { $DefaultValue } else { $entered }
        if (-not [string]::IsNullOrWhiteSpace($value)) { return $value.Trim() }
        Write-WarningMessage "A value is required."
    }
}

function Read-YesNo {
    param([string]$Prompt, [bool]$DefaultYes = $true)

    if ($NonInteractive) { return $DefaultYes }
    $suffix = if ($DefaultYes) { "[Y/n]" } else { "[y/N]" }

    while ($true) {
        $answer = Read-Host "$Prompt $suffix"
        if ([string]::IsNullOrWhiteSpace($answer)) { return $DefaultYes }
        switch ($answer.Trim().ToLowerInvariant()) {
            "y" { return $true }
            "yes" { return $true }
            "n" { return $false }
            "no" { return $false }
            default { Write-WarningMessage "Please answer Y or N." }
        }
    }
}

function Invoke-Native {
    param(
        [string]$FilePath,
        [string[]]$Arguments,
        [string]$FailureMessage,
        [switch]$CaptureOutput,
        [switch]$AllowFailure,
        [switch]$Quiet
    )

    $script:LastCommand = "$FilePath $($Arguments -join ' ')"
    Write-Log "COMMAND: $script:LastCommand" "DEBUG"

    $oldPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    try {
        $output = @(& $FilePath @Arguments 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $oldPreference
    }

    $script:LastExitCode = $exitCode
    $script:LastOutput = ($output | Out-String).Trim()
    if (-not [string]::IsNullOrWhiteSpace($script:LastOutput)) {
        Add-Content -LiteralPath $script:LogPath -Value $script:LastOutput -Encoding UTF8
    }

    if ($exitCode -ne 0 -and -not $AllowFailure) {
        if ([string]::IsNullOrWhiteSpace($script:LastOutput)) {
            throw "$FailureMessage Exit code: $exitCode"
        }
        throw "$FailureMessage`n$script:LastOutput`nExit code: $exitCode"
    }

    if (-not $Quiet -and -not $CaptureOutput -and -not [string]::IsNullOrWhiteSpace($script:LastOutput)) {
        foreach ($line in $output) {
            $text = $line.ToString()
            if (-not [string]::IsNullOrWhiteSpace($text)) { Write-Host $text }
        }
    }

    if ($CaptureOutput) { return $output }
}

function Test-NativeSuccess {
    param([string]$FilePath, [string[]]$Arguments)
    Invoke-Native $FilePath $Arguments "Command failed." -AllowFailure -Quiet
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

function Ensure-Prerequisites {
    Write-Step "Checking Git..."
    if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) {
        throw "Git is missing. Install Git for Windows and run this tool again."
    }
    $gitVersion = ((Invoke-Native "git.exe" @("--version") "Could not read Git version." -CaptureOutput) | Out-String).Trim()
    Write-Success $gitVersion

    Write-Step "Checking .NET 8 SDK..."
    if (-not (Get-Command dotnet.exe -ErrorAction SilentlyContinue)) {
        throw ".NET SDK is missing. Install the .NET 8 SDK and run this tool again."
    }
    $sdks = @(Invoke-Native "dotnet.exe" @("--list-sdks") "Could not list .NET SDKs." -CaptureOutput)
    $sdk8 = @($sdks | Where-Object { $_.ToString() -match '^\s*8\.' })
    if ($sdk8.Count -eq 0) {
        throw ".NET 8 SDK was not found. Installing only the runtime is not enough for build operations."
    }
    Write-Success (".NET 8 SDK found: " + $sdk8[0].ToString().Trim())
}

function Resolve-InstallPath {
    if ([string]::IsNullOrWhiteSpace($script:ResolvedInstallPath)) {
        $value = $InstallPath
        if ([string]::IsNullOrWhiteSpace($value)) {
            $value = Read-RequiredValue "Enter the full installation/repository directory"
        }
        $script:ResolvedInstallPath = Resolve-FullPath $value
    }
    return $script:ResolvedInstallPath
}

function Assert-ValidBranch {
    if (-not (Test-NativeSuccess "git.exe" @("check-ref-format", "--branch", $Branch))) {
        throw "Invalid Git branch name: $Branch"
    }
}

function Enable-GitSafeDirectoryIfRequired([string]$Path) {
    $result = Invoke-Native "git.exe" @("-C", $Path, "status", "--porcelain") "Could not inspect repository." -AllowFailure -CaptureOutput
    if ($script:LastExitCode -eq 0) { return }

    $text = ($result | Out-String)
    if ($text -notmatch "dubious ownership") {
        throw "Git cannot access the repository.`n$text"
    }

    Write-WarningMessage "Git detected dubious ownership for this repository."
    if (-not (Read-YesNo "Trust this repository for the current Windows user?" $true)) {
        throw "Repository trust was declined."
    }

    $safePath = $Path.Replace("\", "/")
    $existing = @(Invoke-Native "git.exe" @("config", "--global", "--get-all", "safe.directory") "Could not read safe.directory." -AllowFailure -CaptureOutput)
    $existingText = @($existing | ForEach-Object { $_.ToString().Trim() })
    if ($existingText -notcontains $safePath) {
        Invoke-Native "git.exe" @("config", "--global", "--add", "safe.directory", $safePath) "Could not add safe.directory."
        Write-Success "Repository added to Git safe.directory."
    }
}

function Ensure-Origin {
    $originResult = Invoke-Native "git.exe" @("remote", "get-url", "origin") "Could not read origin." -AllowFailure -CaptureOutput
    $origin = ($originResult | Out-String).Trim()

    if ($script:LastExitCode -ne 0 -or [string]::IsNullOrWhiteSpace($origin)) {
        if (-not (Read-YesNo "No origin remote exists. Add the configured GitHub repository as origin?" $true)) {
            throw "A Git origin remote is required."
        }
        Invoke-Native "git.exe" @("remote", "add", "origin", $RepositoryUrl) "Could not add origin."
        Write-Success "Origin remote added."
        return
    }

    if ((Normalize-GitRemote $origin) -ne (Normalize-GitRemote $RepositoryUrl)) {
        Write-WarningMessage "The current origin points to a different repository."
        Write-Host "Current   : $origin"
        Write-Host "Configured: $RepositoryUrl"
        if (-not (Read-YesNo "Replace origin with the configured repository?" $false)) {
            throw "Repository synchronization cancelled because origin does not match."
        }
        Invoke-Native "git.exe" @("remote", "set-url", "origin", $RepositoryUrl) "Could not update origin."
        Write-Success "Origin remote updated."
    }
}

function Ensure-Repository([bool]$AllowClone) {
    $path = Resolve-InstallPath

    if (-not (Test-Path -LiteralPath $path)) {
        if (-not $AllowClone) { throw "Repository directory does not exist: $path" }
        $parent = Split-Path $path -Parent
        if (-not (Test-Path -LiteralPath $parent)) { New-Item -ItemType Directory -Path $parent -Force | Out-Null }
        Write-Step "First installation detected. Cloning '$Branch'..."
        Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $path) "Git clone failed. Check network access, repository URL, credentials, and branch name."
    }
    elseif (-not (Test-Path -LiteralPath (Join-Path $path ".git"))) {
        $entries = @(Get-ChildItem -LiteralPath $path -Force -ErrorAction SilentlyContinue)
        if ($AllowClone -and $entries.Count -eq 0) {
            Write-Step "Empty directory detected. Cloning '$Branch'..."
            Invoke-Native "git.exe" @("clone", "--branch", $Branch, "--single-branch", $RepositoryUrl, $path) "Git clone failed."
        }
        else {
            throw "The selected directory exists but is not a Git repository: $path`nChoose the existing repository directory or an empty directory for first installation."
        }
    }

    Enable-GitSafeDirectoryIfRequired $path
    if (-not $script:LocationWasPushed) {
        Push-Location $path
        $script:LocationWasPushed = $true
    }
    Assert-ValidBranch
    Ensure-Origin
}

function Get-TrackedFiles([string]$Pattern) {
    $items = @(Invoke-Native "git.exe" @("ls-files", "--", $Pattern) "Could not list tracked $Pattern files." -CaptureOutput)
    return @($items | ForEach-Object { $_.ToString().Trim() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Find-SolutionFile([string]$Root) {
    $files = @(Get-TrackedFiles "*.sln")
    if ($files.Count -eq 0) { throw "No tracked solution (*.sln) exists in branch '$Branch'." }

    $items = @()
    foreach ($relative in $files) {
        $full = [System.IO.Path]::GetFullPath((Join-Path $Root $relative))
        if (Test-Path -LiteralPath $full) {
            $items += [pscustomobject]@{ Relative = $relative; FullName = $full; Depth = (($relative -split '[\\/]').Count - 1) }
        }
    }
    if ($items.Count -eq 0) { throw "Solution files are tracked by Git but are missing from disk." }

    $selected = $items | Sort-Object Depth, @{Expression={$_.Relative.Length}}, Relative | Select-Object -First 1
    if ($items.Count -gt 1) { Write-WarningMessage "Multiple solutions found. Automatically selected: $($selected.Relative)" }
    return $selected.FullName
}

function Get-TrackedProjects([string]$Root) {
    $items = @()
    foreach ($relative in @(Get-TrackedFiles "*.csproj")) {
        $full = [System.IO.Path]::GetFullPath((Join-Path $Root $relative))
        if (Test-Path -LiteralPath $full) {
            $items += [pscustomobject]@{ Relative = $relative; FullName = $full; Directory = (Split-Path $full -Parent); Depth = (($relative -split '[\\/]').Count - 1) }
        }
    }
    return $items
}

function Find-WebProject([string]$Root) {
    $items = @()
    foreach ($project in @(Get-TrackedProjects $Root)) {
        if ($project.Relative -match '(^|[\\/])(tests?|TestResults)([\\/]|$)') { continue }
        $content = Get-Content -LiteralPath $project.FullName -Raw -ErrorAction SilentlyContinue
        if ($content -match 'Microsoft\.NET\.Sdk\.Web') { $items += $project }
    }
    if ($items.Count -eq 0) { throw "No tracked ASP.NET Core Web project was found." }
    $selected = $items | Sort-Object Depth, @{Expression={$_.Relative.Length}}, Relative | Select-Object -First 1
    if ($items.Count -gt 1) { Write-WarningMessage "Multiple Web projects found. Automatically selected: $($selected.Relative)" }
    return $selected.FullName
}

function Get-WorkingTreeChanges {
    $output = @(Invoke-Native "git.exe" @("status", "--porcelain") "Could not inspect local changes." -CaptureOutput)
    return @($output | ForEach-Object { $_.ToString() } | Where-Object { -not [string]::IsNullOrWhiteSpace($_) })
}

function Protect-LocalChanges {
    $changes = @(Get-WorkingTreeChanges)
    if ($changes.Count -eq 0) { return }

    Write-WarningMessage "Uncommitted local changes were detected. They will not be deleted automatically."
    $changes | Select-Object -First 15 | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }

    $policy = $LocalChangesPolicy
    if ($policy -eq "Ask") {
        if ($NonInteractive) { $policy = "Abort" }
        else {
            Write-Host ""
            Write-Host "1. Save changes in Git stash and continue"
            Write-Host "2. Abort and leave files untouched"
            Write-Host "3. DISCARD uncommitted changes" -ForegroundColor Red
            $answer = Read-Host "Select [1-3]"
            if ($answer -eq "1") { $policy = "Stash" }
            elseif ($answer -eq "3") { $policy = "Discard" }
            else { $policy = "Abort" }
        }
    }

    if ($policy -eq "Stash") {
        $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
        Invoke-Native "git.exe" @("stash", "push", "--include-untracked", "-m", "AIWM Setup Tool backup $stamp") "Could not stash local changes."
        Write-Success "Local changes saved safely in Git stash."
        Write-WarningMessage "The stash is not restored automatically to avoid merge conflicts."
        return
    }

    if ($policy -eq "Discard") {
        if (-not $NonInteractive -and -not (Read-YesNo "Permanently discard ALL uncommitted changes?" $false)) {
            throw "Discard cancelled."
        }
        Invoke-Native "git.exe" @("reset", "--hard", "HEAD") "Could not discard tracked changes."
        Invoke-Native "git.exe" @("clean", "-fd") "Could not discard untracked files."
        Write-WarningMessage "Uncommitted changes were discarded by explicit request."
        return
    }

    throw "Operation cancelled because local uncommitted changes exist."
}

function Fetch-RemoteBranch {
    Write-Step "Fetching latest origin/$Branch..."
    $refSpec = "+refs/heads/${Branch}:refs/remotes/origin/${Branch}"
    Invoke-Native "git.exe" @("fetch", "origin", $refSpec, "--prune", "--tags") "Git fetch failed. Check network, GitHub authentication, proxy/firewall, repository URL, and branch name."
    if (-not (Test-NativeSuccess "git.exe" @("show-ref", "--verify", "--quiet", "refs/remotes/origin/$Branch"))) {
        throw "Remote branch origin/$Branch was not found after fetch."
    }
}

function Get-CurrentBranch {
    return ((Invoke-Native "git.exe" @("rev-parse", "--abbrev-ref", "HEAD") "Could not read current branch." -CaptureOutput) | Out-String).Trim()
}

function Switch-ConfiguredBranch {
    $current = Get-CurrentBranch
    if ($current -eq $Branch) {
        Write-Success "Already using branch '$Branch'."
        return
    }

    if (Test-NativeSuccess "git.exe" @("show-ref", "--verify", "--quiet", "refs/heads/$Branch")) {
        Write-Step "Switching from '$current' to '$Branch'..."
        Invoke-Native "git.exe" @("switch", "--quiet", $Branch) "Could not switch to branch '$Branch'."
    }
    else {
        Write-Step "Creating local '$Branch' from origin/$Branch..."
        Invoke-Native "git.exe" @("switch", "--quiet", "--create", $Branch, "--track", "origin/$Branch") "Could not create local branch '$Branch'."
    }
}

function Sync-FromGitHub {
    Protect-LocalChanges
    Fetch-RemoteBranch
    Switch-ConfiguredBranch
    Write-Step "Applying latest origin/$Branch commit..."
    Invoke-Native "git.exe" @("reset", "--hard", "origin/$Branch") "Could not reset repository to origin/$Branch."

    $local = ((Invoke-Native "git.exe" @("rev-parse", "HEAD") "Could not read local commit." -CaptureOutput) | Out-String).Trim()
    $remote = ((Invoke-Native "git.exe" @("rev-parse", "origin/$Branch") "Could not read remote commit." -CaptureOutput) | Out-String).Trim()
    if ($local -ne $remote) { throw "Local HEAD does not match origin/$Branch after update." }

    $summary = ((Invoke-Native "git.exe" @("log", "-1", "--pretty=format:%h %cd %s", "--date=iso") "Could not read latest commit." -CaptureOutput) | Out-String).Trim()
    Write-Success "Repository synchronized: $summary"
}

function Stop-DotNetProcessesWithConsent {
    $processes = @(Get-Process dotnet -ErrorAction SilentlyContinue)
    if ($processes.Count -eq 0) { return $false }
    Write-WarningMessage "dotnet processes may be locking build folders."
    foreach ($process in $processes) { Write-Host ("  PID {0} - {1}" -f $process.Id, $process.ProcessName) -ForegroundColor Yellow }
    if (-not (Read-YesNo "Stop ALL listed dotnet processes and retry cleanup? This may stop other .NET applications." $false)) { return $false }
    $processes | Stop-Process -Force -ErrorAction Stop
    Start-Sleep -Milliseconds 500
    return $true
}

function Clean-BuildOutput([string]$Root) {
    if ($SkipClean) { Write-WarningMessage "Build cleanup skipped."; return }

    $failed = @()
    foreach ($project in @(Get-TrackedProjects $Root)) {
        foreach ($name in @("bin", "obj")) {
            $folder = Join-Path $project.Directory $name
            if (Test-Path -LiteralPath $folder) {
                try { Remove-Item -LiteralPath $folder -Recurse -Force -ErrorAction Stop }
                catch { $failed += $folder }
            }
        }
    }

    if ($failed.Count -eq 0) { return }
    Write-WarningMessage "Some build folders are locked."
    $failed | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
    if (-not (Stop-DotNetProcessesWithConsent)) {
        throw "Build files are locked. Stop the application using these folders and run the setup tool again."
    }
    foreach ($folder in $failed) {
        if (Test-Path -LiteralPath $folder) { Remove-Item -LiteralPath $folder -Recurse -Force -ErrorAction Stop }
    }
}

function Restore-Packages([string]$Solution) {
    Write-Step "Restoring NuGet packages..."
    Invoke-Native "dotnet.exe" @("restore", $Solution) "NuGet restore failed." -AllowFailure
    if ($script:LastExitCode -eq 0) { Write-Success "NuGet restore completed."; return }

    Write-WarningMessage "NuGet restore failed."
    if (-not (Read-YesNo "Clear NuGet caches and retry?" $true)) {
        throw "NuGet restore failed. Review the diagnostic log."
    }
    Invoke-Native "dotnet.exe" @("nuget", "locals", "all", "--clear") "Could not clear NuGet caches."
    Invoke-Native "dotnet.exe" @("restore", $Solution) "NuGet restore failed again after cache cleanup."
    Write-Success "NuGet restore completed after cache cleanup."
}

function Build-Application([string]$Root) {
    $solution = Find-SolutionFile $Root
    Write-Success "Selected solution: $solution"
    Write-Step "Cleaning tracked build output..."
    Clean-BuildOutput $Root
    Restore-Packages $solution
    Write-Step "Building in $Configuration mode..."
    Invoke-Native "dotnet.exe" @("build", $solution, "--configuration", $Configuration, "--no-restore") "Build failed. Review compiler errors and the diagnostic log."
    Write-Success "Build completed successfully."
}

function Test-Application([string]$Root) {
    $solution = Find-SolutionFile $Root
    Write-Step "Running tests..."
    Invoke-Native "dotnet.exe" @("test", $solution, "--configuration", $Configuration, "--no-build") "Tests failed. Review failing tests in the diagnostic log."
    Write-Success "Tests completed successfully."
}

function Test-ServerUrl([string]$Url) {
    $uri = $null
    if (-not [Uri]::TryCreate($Url, [UriKind]::Absolute, [ref]$uri)) { return $false }
    return ($uri.Scheme -eq "http" -or $uri.Scheme -eq "https")
}

function Assert-PortAvailable([string]$Url) {
    $uri = [Uri]$Url
    if (-not (Get-Command Get-NetTCPConnection -ErrorAction SilentlyContinue)) { return }
    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $uri.Port -ErrorAction SilentlyContinue)
    if ($listeners.Count -gt 0) {
        $pids = @($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
        throw "Port $($uri.Port) is already in use by PID(s): $($pids -join ', '). Stop the existing service or choose another URL."
    }
}

function Run-Application([string]$Root) {
    $project = Find-WebProject $Root
    Write-Success "Selected Web project: $project"
    $url = $ServerUrl
    if ([string]::IsNullOrWhiteSpace($url)) { $url = Read-RequiredValue "Enter server URL (example: http://0.0.0.0:7148)" }
    while (-not (Test-ServerUrl $url)) {
        if ($NonInteractive) { throw "Invalid server URL: $url" }
        Write-WarningMessage "Enter a valid HTTP or HTTPS URL."
        $url = Read-RequiredValue "Enter server URL"
    }
    Assert-PortAvailable $url

    $env:ASPNETCORE_ENVIRONMENT = if ($Configuration -eq "Release") { "Production" } else { "Development" }
    Write-Host ""
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host " Starting AI WordPress Manager" -ForegroundColor White
    Write-Host " URL: $url" -ForegroundColor Green
    Write-Host " Log: $script:LogPath" -ForegroundColor DarkGray
    Write-Host " Press Ctrl+C to stop." -ForegroundColor Yellow
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Invoke-Native "dotnet.exe" @("run", "--project", $project, "--configuration", $Configuration, "--no-build", "--no-launch-profile", "--urls", $url) "Application stopped with an error."
}

function Push-ToGitHub {
    Fetch-RemoteBranch
    $current = Get-CurrentBranch
    if ($current -ne $Branch) { throw "Current branch is '$current'. The setup tool only pushes the configured '$Branch' branch." }

    $changes = @(Get-WorkingTreeChanges)
    if ($changes.Count -gt 0) { Write-WarningMessage "Uncommitted files exist. They cannot be pushed until they are committed." }

    $aheadText = ((Invoke-Native "git.exe" @("rev-list", "--count", "origin/$Branch..HEAD") "Could not calculate commits ahead." -CaptureOutput) | Out-String).Trim()
    $ahead = 0
    [void][int]::TryParse($aheadText, [ref]$ahead)
    if ($ahead -eq 0) { Write-Success "Nothing to push."; return }

    Write-Host "$ahead committed change(s) are ready to push." -ForegroundColor Yellow
    if (-not (Read-YesNo "Push committed changes to origin/$Branch?" $false)) { throw "Push cancelled." }
    Invoke-Native "git.exe" @("push", "origin", $Branch) "Git push failed. Check authentication, branch protection, and network access."
    Write-Success "Push completed successfully."
}

function Diagnose {
    Write-Host ""
    Write-Host "================ DIAGNOSTIC REPORT ================" -ForegroundColor DarkCyan
    $issues = New-Object System.Collections.Generic.List[string]

    if (Get-Command git.exe -ErrorAction SilentlyContinue) {
        $text = ((Invoke-Native "git.exe" @("--version") "Git check failed." -AllowFailure -CaptureOutput) | Out-String).Trim()
        Write-Host "Git          : $text"
    }
    else { Write-Host "Git          : MISSING" -ForegroundColor Red; $issues.Add("Git is missing.") }

    if (Get-Command dotnet.exe -ErrorAction SilentlyContinue) {
        $sdks = @(Invoke-Native "dotnet.exe" @("--list-sdks") ".NET check failed." -AllowFailure -CaptureOutput)
        $has8 = @($sdks | Where-Object { $_.ToString() -match '^\s*8\.' }).Count -gt 0
        if ($has8) { Write-Host "NET 8 SDK    : FOUND" -ForegroundColor Green }
        else { Write-Host "NET 8 SDK    : MISSING" -ForegroundColor Red; $issues.Add(".NET 8 SDK is missing.") }
    }
    else { Write-Host "dotnet       : MISSING" -ForegroundColor Red; $issues.Add("dotnet.exe is missing.") }

    if (-not [string]::IsNullOrWhiteSpace($InstallPath)) {
        $path = Resolve-FullPath $InstallPath
        Write-Host "Repository   : $path"
        if (-not (Test-Path -LiteralPath $path)) { $issues.Add("Repository directory does not exist.") }
        elseif (-not (Test-Path -LiteralPath (Join-Path $path ".git"))) { $issues.Add("Selected directory is not a Git repository.") }
        elseif (Get-Command git.exe -ErrorAction SilentlyContinue) {
            try {
                Enable-GitSafeDirectoryIfRequired $path
                Push-Location $path
                try {
                    $origin = ((Invoke-Native "git.exe" @("remote", "get-url", "origin") "Origin check failed." -AllowFailure -CaptureOutput) | Out-String).Trim()
                    Write-Host "Origin       : $origin"
                    Write-Host "Branch       : $(Get-CurrentBranch)"
                    Write-Host "Local changes: $(@(Get-WorkingTreeChanges).Count)"
                    Write-Host "Solutions    : $(@(Get-TrackedFiles '*.sln').Count) tracked"
                    Write-Host "Projects     : $(@(Get-TrackedFiles '*.csproj').Count) tracked"
                }
                finally { Pop-Location }
            }
            catch { $issues.Add($_.Exception.Message) }
        }
    }
    else { Write-Host "Repository   : not supplied; pass -InstallPath for repository checks" -ForegroundColor Yellow }

    if ($issues.Count -eq 0) { Write-Success "No blocking setup problems detected." }
    else {
        Write-Host ""
        Write-Host "Detected problems:" -ForegroundColor Red
        foreach ($issue in $issues) { Write-Host " - $issue" -ForegroundColor Red }
        Write-Log ("Diagnostic issues: " + ($issues -join " | ")) "ERROR"
    }
    Write-Host "Log file     : $script:LogPath" -ForegroundColor DarkGray
    Write-Host "====================================================" -ForegroundColor DarkCyan
}

function Show-Menu {
    Write-Host ""
    Write-Host "Choose an operation:" -ForegroundColor White
    Write-Host "  1. Install first time OR update existing installation"
    Write-Host "  2. Pull latest GitHub branch only"
    Write-Host "  3. Build application only"
    Write-Host "  4. Run tests only"
    Write-Host "  5. Run application only"
    Write-Host "  6. Diagnose environment/repository"
    Write-Host "  7. Push already committed local changes to GitHub"
    Write-Host "  0. Exit" -ForegroundColor DarkGray
    $choice = Read-Host "Select [0-7]"
    switch ($choice) {
        "1" { return "InstallOrUpdate" }
        "2" { return "Pull" }
        "3" { return "Build" }
        "4" { return "Test" }
        "5" { return "Run" }
        "6" { return "Diagnose" }
        "7" { return "Push" }
        default { return "Exit" }
    }
}

try {
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host " AI WordPress Manager - Setup & Recovery Tool" -ForegroundColor White
    Write-Host "====================================================" -ForegroundColor DarkCyan
    Write-Host "Diagnostic log: $script:LogPath" -ForegroundColor DarkGray
    Write-Log "Setup started. Mode=$Mode Branch=$Branch Configuration=$Configuration" "INFO"

    if ($Mode -eq "Interactive") {
        if ($NonInteractive) { throw "Interactive mode cannot be used with -NonInteractive." }
        $Mode = Show-Menu
        if ($Mode -eq "Exit") { Write-Success "No changes were made."; exit 0 }
    }

    if ($Mode -eq "Diagnose") { Diagnose; exit 0 }

    Ensure-Prerequisites
    $canClone = ($Mode -eq "InstallOrUpdate")
    Ensure-Repository $canClone
    $root = Resolve-InstallPath

    if ($Mode -eq "InstallOrUpdate") {
        Sync-FromGitHub
        Build-Application $root
        if (-not $SkipStart -and (Read-YesNo "Build succeeded. Start AI WordPress Manager now?" $true)) { Run-Application $root }
    }
    elseif ($Mode -eq "Pull") {
        Sync-FromGitHub
        Write-Success "Repository update completed. Build was not run."
    }
    elseif ($Mode -eq "Push") { Push-ToGitHub }
    elseif ($Mode -eq "Build") { Build-Application $root }
    elseif ($Mode -eq "Test") { Test-Application $root }
    elseif ($Mode -eq "Run") { Run-Application $root }

    Write-Host ""
    Write-Success "Setup operation completed successfully."
    Write-Host "Diagnostic log: $script:LogPath" -ForegroundColor DarkGray
}
catch {
    Write-Log $_.Exception.ToString() "ERROR"
    Stop-WithError $_.Exception.Message
}
finally {
    if ($script:LocationWasPushed) { Pop-Location -ErrorAction SilentlyContinue }
    else { Set-Location $script:OriginalLocation -ErrorAction SilentlyContinue }
}
