[CmdletBinding()]
param(
    [Parameter(Position = 0)][string]$Version,
    [switch]$LocalOnly,
    [switch]$SyncOnly,
    [switch]$UseBundled,
    [switch]$GitHubRelease,
    [string]$CommonPath
)

$ErrorActionPreference = 'Stop'
$moduleRoot = $PSScriptRoot
$sourceRoot = if ($CommonPath) { $CommonPath } else { Join-Path $moduleRoot '../zabbix-module-common' }
$engine = Join-Path $moduleRoot 'tools/release.py'
if (-not $UseBundled -and (Test-Path -LiteralPath (Join-Path $sourceRoot 'tools/release.py'))) {
    $engine = Join-Path $sourceRoot 'tools/release.py'
}

$releaseArgs = @($engine, '--module-root', $moduleRoot)
if ($Version) { $releaseArgs += $Version }
if ($LocalOnly) { $releaseArgs += '--local-only' }
if ($SyncOnly) { $releaseArgs += '--sync-only' }
if ($UseBundled) { $releaseArgs += '--bundled' }
if ($GitHubRelease) { $releaseArgs += '--github-release' }
if ($CommonPath) { $releaseArgs += @('--common-path', $CommonPath) }

if (Get-Command python -ErrorAction SilentlyContinue) {
    & python @releaseArgs
}
elseif (Get-Command py -ErrorAction SilentlyContinue) {
    & py -3 @releaseArgs
}
else {
    throw 'Python 3.10 or newer is required on the release machine.'
}
if ($LASTEXITCODE -ne 0) { throw "Release failed (exit code $LASTEXITCODE)." }
