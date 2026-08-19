[CmdletBinding()]
param(
    [string]$LogDirectory = 'C:\ProgramData\AIMWWeb\Logs',
    [ValidateRange(1, 30)]
    [int]$Days = 3,
    [string]$OutputDirectory = 'C:\ProgramData\AIMWWeb\Diagnostics',
    [string]$SiteName = 'AIMWWeb'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

function Protect-DiagnosticText {
    param([AllowNull()][string]$Text)

    if ([string]::IsNullOrEmpty($Text)) { return $Text }

    $value = $Text
    $value = [regex]::Replace(
        $value,
        '(?i)(password|pwd|passphrase|secret|client[_-]?secret|api[_-]?key|access[_-]?token|refresh[_-]?token|authorization|cookie|connection[_-]?string)\s*[:=]\s*([^;\s\r\n]+)',
        '$1=[REDACTED]')
    $value = [regex]::Replace($value, '(?i)Bearer\s+[A-Za-z0-9._~+\-/]+=*', 'Bearer [REDACTED]')
    $value = [regex]::Replace($value, '(?i)(User\s+Id|UID)\s*=\s*[^;\r\n]+', '$1=[REDACTED]')
    return $value
}

function Write-SafeText {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [AllowNull()][string]$Text
    )

    Protect-DiagnosticText $Text | Set-Content -LiteralPath $Path -Encoding UTF8
}

function Add-WarningNote {
    param(
        [Parameter(Mandatory = $true)][System.Collections.Generic.List[string]]$Warnings,
        [Parameter(Mandatory = $true)][string]$Message
    )

    $Warnings.Add($Message) | Out-Null
}

function Get-IisSnapshot {
    param(
        [Parameter(Mandatory = $true)][string]$RequestedSiteName,
        [Parameter(Mandatory = $true)][System.Collections.Generic.List[string]]$Warnings
    )

    try {
        Import-Module WebAdministration -ErrorAction Stop
        $site = Get-Website -Name $RequestedSiteName -ErrorAction Stop
        $appPoolName = $site.ApplicationPool
        $pool = Get-Item ("IIS:\AppPools\{0}" -f $appPoolName) -ErrorAction Stop

        $bindings = @()
        foreach ($binding in @($site.Bindings.Collection)) {
            $bindings += [ordered]@{
                Protocol = $binding.Protocol
                BindingInformation = $binding.BindingInformation
                SslFlags = [string]$binding.SslFlags
            }
        }

        return [ordered]@{
            Site = [ordered]@{
                Name = $site.Name
                State = [string]$site.State
                PhysicalPath = $site.PhysicalPath
                ApplicationPool = $appPoolName
                Bindings = $bindings
            }
            AppPool = [ordered]@{
                Name = $appPoolName
                State = [string]$pool.State
                ManagedRuntimeVersion = $pool.managedRuntimeVersion
                ManagedPipelineMode = [string]$pool.managedPipelineMode
                StartMode = [string]$pool.startMode
                Enable32BitAppOnWin64 = [bool]$pool.enable32BitAppOnWin64
                IdentityType = [string]$pool.processModel.identityType
            }
        }
    }
    catch {
        Add-WarningNote $Warnings ("IIS snapshot unavailable: {0}" -f $_.Exception.Message)
        return $null
    }
}

function Test-LocalHealth {
    param(
        $IisSnapshot,
        [Parameter(Mandatory = $true)][System.Collections.Generic.List[string]]$Warnings
    )

    if ($null -eq $IisSnapshot) { return $null }

    $binding = @($IisSnapshot.Site.Bindings) |
        Where-Object { $_.Protocol -eq 'http' } |
        Select-Object -First 1
    if ($null -eq $binding) {
        Add-WarningNote $Warnings 'No HTTP IIS binding was available for a local health probe.'
        return $null
    }

    $parts = [string]$binding.BindingInformation -split ':'
    if ($parts.Count -lt 3) {
        Add-WarningNote $Warnings 'The IIS binding format could not be used for a local health probe.'
        return $null
    }

    $port = $parts[1]
    $hostHeader = ($parts[2..($parts.Count - 1)] -join ':').Trim()
    if ([string]::IsNullOrWhiteSpace($port)) { $port = '80' }
    $uri = "http://127.0.0.1:$port/health/live"

    try {
        $headers = @{}
        if (-not [string]::IsNullOrWhiteSpace($hostHeader)) { $headers['Host'] = $hostHeader }
        $started = Get-Date
        $response = Invoke-WebRequest -Uri $uri -Headers $headers -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
        return [ordered]@{
            Uri = $uri
            HostHeader = $hostHeader
            StatusCode = [int]$response.StatusCode
            ElapsedMs = [int]((Get-Date) - $started).TotalMilliseconds
            CheckedAtUtc = (Get-Date).ToUniversalTime().ToString('o')
        }
    }
    catch {
        Add-WarningNote $Warnings ("Local health probe failed: {0}" -f $_.Exception.Message)
        return [ordered]@{
            Uri = $uri
            HostHeader = $hostHeader
            StatusCode = $null
            Error = Protect-DiagnosticText $_.Exception.Message
            CheckedAtUtc = (Get-Date).ToUniversalTime().ToString('o')
        }
    }
}

$resolvedLogDirectory = [Environment]::ExpandEnvironmentVariables($LogDirectory)
if (-not (Test-Path -LiteralPath $resolvedLogDirectory)) {
    $fallback = 'C:\inetpub\AIMWWeb\Logs'
    if (Test-Path -LiteralPath $fallback) {
        $resolvedLogDirectory = $fallback
    }
    else {
        throw "AIMWWeb log directory was not found. Checked '$resolvedLogDirectory' and '$fallback'."
    }
}

$resolvedOutputDirectory = [Environment]::ExpandEnvironmentVariables($OutputDirectory)
New-Item -ItemType Directory -Path $resolvedOutputDirectory -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$work = Join-Path $env:TEMP "AIMWWeb-Diagnostics-$stamp"
$zip = Join-Path $resolvedOutputDirectory "AIMWWeb-Diagnostics-$stamp.zip"
$warnings = New-Object 'System.Collections.Generic.List[string]'
New-Item -ItemType Directory -Path $work -Force | Out-Null

try {
    $logsWork = Join-Path $work 'runtime-logs'
    New-Item -ItemType Directory -Path $logsWork -Force | Out-Null
    $cutoff = (Get-Date).AddDays(-$Days)
    $files = @(Get-ChildItem -LiteralPath $resolvedLogDirectory -Filter '*.log' -File -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -ge $cutoff } |
        Sort-Object LastWriteTime)

    foreach ($file in $files) {
        $content = Get-Content -LiteralPath $file.FullName -Raw -ErrorAction SilentlyContinue
        Write-SafeText -Path (Join-Path $logsWork $file.Name) -Text $content
    }
    if ($files.Count -eq 0) {
        Add-WarningNote $warnings "No AIMWWeb .log files were found in '$resolvedLogDirectory' for the last $Days day(s)."
    }

    try {
        $dotnetInfo = (& dotnet --info 2>&1 | Out-String)
        Write-SafeText -Path (Join-Path $work 'dotnet-info.txt') -Text $dotnetInfo
        $runtimes = (& dotnet --list-runtimes 2>&1 | Out-String)
        Write-SafeText -Path (Join-Path $work 'dotnet-runtimes.txt') -Text $runtimes
        $sdks = (& dotnet --list-sdks 2>&1 | Out-String)
        Write-SafeText -Path (Join-Path $work 'dotnet-sdks.txt') -Text $sdks
    }
    catch {
        Add-WarningNote $warnings (".NET inventory unavailable: {0}" -f $_.Exception.Message)
    }

    $iisSnapshot = Get-IisSnapshot -RequestedSiteName $SiteName -Warnings $warnings
    if ($null -ne $iisSnapshot) {
        $iisSnapshot | ConvertTo-Json -Depth 8 |
            ForEach-Object { Protect-DiagnosticText $_ } |
            Set-Content -LiteralPath (Join-Path $work 'iis-snapshot.json') -Encoding UTF8
    }

    $health = Test-LocalHealth -IisSnapshot $iisSnapshot -Warnings $warnings
    if ($null -ne $health) {
        $health | ConvertTo-Json -Depth 5 |
            Set-Content -LiteralPath (Join-Path $work 'health-live.json') -Encoding UTF8
    }

    try {
        $providers = @('.NET Runtime', 'Application Error', 'IIS AspNetCore Module V2')
        $events = Get-WinEvent -FilterHashtable @{ LogName = 'Application'; StartTime = $cutoff } -ErrorAction Stop |
            Where-Object { $providers -contains $_.ProviderName -or $_.Message -match '(?i)AIMWWeb|AIWordPressManager|aspnetcorev2' } |
            Select-Object -First 300 |
            ForEach-Object {
                [ordered]@{
                    TimeCreated = $_.TimeCreated.ToUniversalTime().ToString('o')
                    ProviderName = $_.ProviderName
                    Id = $_.Id
                    Level = $_.LevelDisplayName
                    Message = Protect-DiagnosticText $_.Message
                }
            }
        @($events) | ConvertTo-Json -Depth 5 |
            Set-Content -LiteralPath (Join-Path $work 'windows-application-events.json') -Encoding UTF8
    }
    catch {
        Add-WarningNote $warnings ("Windows Application event log unavailable: {0}" -f $_.Exception.Message)
    }

    $metadata = [ordered]@{
        SchemaVersion = 2
        ExportedAtLocal = (Get-Date).ToString('o')
        ExportedAtUtc = (Get-Date).ToUniversalTime().ToString('o')
        ComputerName = $env:COMPUTERNAME
        SiteName = $SiteName
        LogDirectory = $resolvedLogDirectory
        DaysIncluded = $Days
        RuntimeLogFiles = @($files.Name)
        WarningCount = $warnings.Count
        Redaction = 'Sensitive assignment values, bearer tokens, authorization/cookie fields, connection strings and common secrets are scrubbed from text evidence.'
    }
    $metadata | ConvertTo-Json -Depth 5 |
        Set-Content -LiteralPath (Join-Path $work 'diagnostics-metadata.json') -Encoding UTF8

    if ($warnings.Count -gt 0) {
        Write-SafeText -Path (Join-Path $work 'collection-warnings.txt') -Text ($warnings -join [Environment]::NewLine)
    }

    if (Test-Path -LiteralPath $zip) { Remove-Item -LiteralPath $zip -Force }
    Compress-Archive -Path (Join-Path $work '*') -DestinationPath $zip -Force

    Write-Host ''
    Write-Host 'AIMWWeb diagnostics package created:' -ForegroundColor Green
    Write-Host $zip -ForegroundColor Cyan
    if ($warnings.Count -gt 0) {
        Write-Host ("Completed with {0} non-fatal collection warning(s). See collection-warnings.txt inside the ZIP." -f $warnings.Count) -ForegroundColor Yellow
    }
    Write-Host 'Send this ZIP with the Error ID / Correlation ID shown in the application when available.' -ForegroundColor Gray
    Write-Output $zip
}
finally {
    if (Test-Path -LiteralPath $work) {
        Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
    }
}