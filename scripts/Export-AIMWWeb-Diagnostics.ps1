[CmdletBinding()]
param(
    [string]$LogDirectory = 'C:\ProgramData\AIMWWeb\Logs',
    [int]$Days = 3,
    [string]$OutputDirectory = "$env:USERPROFILE\Desktop"
)

$ErrorActionPreference = 'Stop'

if ($Days -lt 1) { throw 'Days must be at least 1.' }

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

New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$work = Join-Path $env:TEMP "AIMWWeb-Diagnostics-$stamp"
$zip = Join-Path $OutputDirectory "AIMWWeb-Diagnostics-$stamp.zip"
New-Item -ItemType Directory -Path $work -Force | Out-Null

try {
    $cutoff = (Get-Date).AddDays(-$Days)
    $files = Get-ChildItem -LiteralPath $resolvedLogDirectory -Filter '*.log' -File |
        Where-Object { $_.LastWriteTime -ge $cutoff }

    if (-not $files) {
        throw "No AIMWWeb .log files were found in '$resolvedLogDirectory' for the last $Days day(s)."
    }

    foreach ($file in $files) {
        Copy-Item -LiteralPath $file.FullName -Destination $work -Force
    }

    $metadata = [ordered]@{
        ExportedAtLocal = (Get-Date).ToString('o')
        ExportedAtUtc = (Get-Date).ToUniversalTime().ToString('o')
        ComputerName = $env:COMPUTERNAME
        LogDirectory = $resolvedLogDirectory
        DaysIncluded = $Days
        Files = @($files.Name)
    }
    $metadata | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $work 'diagnostics-metadata.json') -Encoding UTF8

    Compress-Archive -Path (Join-Path $work '*') -DestinationPath $zip -Force
    Write-Host "Diagnostics package created:" -ForegroundColor Green
    Write-Host $zip -ForegroundColor Cyan
}
finally {
    if (Test-Path -LiteralPath $work) {
        Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
    }
}
