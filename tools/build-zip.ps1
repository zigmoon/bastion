<#
.SYNOPSIS
	Builds dist/Bastion-<version>.zip from the Bastion/ folder.

.DESCRIPTION
	The archive contains a single top-level folder, Bastion/, which is what
	WordPress expects when the ZIP is uploaded from "Add New → Upload Plugin".
	Development files (tests, tools, docs, assets) are deliberately excluded.

	The script then re-opens the archive and compares every file with the source
	with a SHA-256 hash, so a stale archive can never ship.

.EXAMPLE
	pwsh -File tools/build-zip.ps1
#>

[CmdletBinding()]
param(
	[string] $Root = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'

$pluginDir = Join-Path $Root 'Bastion'
$mainFile  = Join-Path $pluginDir 'bastion.php'

if (-not (Test-Path $mainFile)) {
	throw "Plugin entry point not found: $mainFile"
}

$header = (Get-Content $mainFile -TotalCount 30) -join "`n"
$match  = [regex]::Match($header, '(?m)^\s*\*\s*Version:\s*(\S+)')

if (-not $match.Success) {
	throw 'Could not read the Version header from bastion.php.'
}

$version = $match.Groups[1].Value
$distDir = Join-Path $Root 'dist'
$zipPath = Join-Path $distDir "Bastion-$version.zip"

New-Item -ItemType Directory -Force -Path $distDir | Out-Null

if (Test-Path $zipPath) {
	Remove-Item $zipPath -Force
}

Compress-Archive -Path $pluginDir -DestinationPath $zipPath -CompressionLevel Optimal

# ── Verification: every source file must be present and identical ──────────
Add-Type -AssemblyName System.IO.Compression.FileSystem

$staging = Join-Path ([System.IO.Path]::GetTempPath()) ("Bastion-verify-" + [guid]::NewGuid().ToString('n'))
[System.IO.Compression.ZipFile]::ExtractToDirectory($zipPath, $staging)

try {
	$extracted = Join-Path $staging 'Bastion'
	$source    = (Resolve-Path $pluginDir).Path
	$problems  = @()

	Get-ChildItem -Recurse -File $source | ForEach-Object {
		$relative = $_.FullName.Substring($source.Length).TrimStart('\', '/')
		$other    = Join-Path $extracted $relative

		if (-not (Test-Path $other)) {
			$problems += "missing from archive: $relative"
			return
		}

		if ((Get-FileHash $_.FullName).Hash -ne (Get-FileHash $other).Hash) {
			$problems += "content differs: $relative"
		}
	}

	$sourceCount   = (Get-ChildItem -Recurse -File $source).Count
	$archivedCount = (Get-ChildItem -Recurse -File $extracted).Count

	if ($sourceCount -ne $archivedCount) {
		$problems += "file count differs: source=$sourceCount archive=$archivedCount"
	}

	if ($problems.Count -gt 0) {
		$problems | ForEach-Object { Write-Error $_ }
		throw 'Archive verification failed.'
	}

	$size = [math]::Round((Get-Item $zipPath).Length / 1KB, 1)
	$hash = (Get-FileHash $zipPath -Algorithm SHA256).Hash

	Write-Host ''
	Write-Host "Built: dist/Bastion-$version.zip"
	Write-Host "  files : $archivedCount"
	Write-Host "  size  : $size KB"
	Write-Host "  sha256: $hash"
}
finally {
	Remove-Item -Recurse -Force $staging -ErrorAction SilentlyContinue
}
