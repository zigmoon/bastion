<#
.SYNOPSIS
	Bumps the version, rebuilds everything, commits, tags and publishes the release.

.DESCRIPTION
	A release touches six things that must agree: the plugin header, the
	`BASTION_VERSION` constant, the `Stable tag` of readme.txt, the README
	badge, the CHANGELOG and the ZIP. Forgetting one is how a site ends up
	offering an update that installs the same version again, so this script does
	them together and refuses to publish when they disagree.

	Steps, in order:
	  1. read the version from the plugin header;
	  2. bump it (optional) in the header, the constant, readme.txt, README and
	     CHANGELOG;
	  3. rebuild the translation catalogues, which embed the version;
	  4. lint every PHP file and run both test suites;
	  5. build the ZIP and copy it to the project root;
	  6. commit, tag, push, and create the GitHub release with the ZIP attached.

	Nothing is published with -DryRun: the version bump and the build still run,
	so the result can be inspected.

.PARAMETER Bump
	patch, minor, major, or none to release the version already in the header.

.PARAMETER Notes
	Release body. Markdown. Defaults to the top CHANGELOG section.

.PARAMETER DryRun
	Stop after the ZIP is built: no commit, no tag, no push, no release.

.EXAMPLE
	pwsh -File tools/release.ps1 -Bump patch

.EXAMPLE
	pwsh -File tools/release.ps1 -Bump minor -DryRun
#>

[CmdletBinding()]
param(
	[ValidateSet( 'none', 'patch', 'minor', 'major' )]
	[string] $Bump = 'none',

	[string] $Notes = '',

	[string] $Summary = '',

	[switch] $DryRun,

	[string] $Root = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'

# ── Identity ────────────────────────────────────────────────────────────────

$plugin   = 'Bastion'
$slug     = 'bastion'
$repo     = 'zigmoon/bastion'
$mainFile = Join-Path $Root "$plugin/$slug.php"
$readme   = Join-Path $Root "$plugin/readme.txt"
$docReadme = Join-Path $Root 'README.md'
$changelog = Join-Path $Root 'CHANGELOG.md'

foreach ( $file in $mainFile, $readme, $docReadme, $changelog ) {
	if ( -not ( Test-Path $file ) ) {
		throw "Missing file: $file"
	}
}

function Write-Step( [string] $text ) {
	Write-Host ''
	Write-Host "▸ $text" -ForegroundColor Cyan
}

function Get-UtilToken {
	if ( $env:GITHUB_TOKEN ) { return $env:GITHUB_TOKEN }

	# The credential manager only answers when the username is part of the query.
	$query = "protocol=https`nhost=github.com`nusername=zigmoon`n`n"
	$answer = $query | git credential fill 2>$null

	foreach ( $line in $answer ) {
		if ( "$line" -like 'password=*' ) {
			return ( "$line" -replace '^password=', '' ).Trim()
		}
	}

	return ''
}

function Replace-First( [string] $text, [string] $pattern, [string] $insertion ) {
	# PowerShell's -replace has no 'first match only' form, and the .NET one
	# interprets $ in the replacement: splicing the string avoids both.
	$match = [regex]::Match( $text, $pattern )

	if ( -not $match.Success ) {
		return $text
	}

	return $text.Substring( 0, $match.Index ) + $insertion + $text.Substring( $match.Index + $match.Length )
}

# ── 1. Current version ──────────────────────────────────────────────────────

$header   = ( Get-Content $mainFile -TotalCount 30 ) -join "`n"
$match    = [regex]::Match( $header, '(?m)^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)' )
$current  = if ( $match.Success ) { $match.Groups[1].Value } else { throw "No Version header in $mainFile" }

Write-Step "Current version: $current"

# ── 2. New version ──────────────────────────────────────────────────────────

$parts = $current.Split( '.' ) | ForEach-Object { [int] $_ }

$next = switch ( $Bump ) {
	'major' { "$( $parts[0] + 1 ).0.0" }
	'minor' { "$( $parts[0] ).$( $parts[1] + 1 ).0" }
	'patch' { "$( $parts[0] ).$( $parts[1] ).$( $parts[2] + 1 )" }
	default { $current }
}

if ( $next -ne $current ) {
	Write-Step "Bumping to $next"

	$text = Get-Content $mainFile -Raw
	$text = $text -replace "(?m)^(\s*\*\s*Version:[ \t]*)$([regex]::Escape( $current ))[ \t]*\r?`$", "`${1}$next"
	$text = $text -replace "define\( '$($slug.ToUpper())_VERSION', '$([regex]::Escape( $current ))' \);", "define( '$($slug.ToUpper())_VERSION', '$next' );"
	Set-Content $mainFile -Value $text -NoNewline

	$text = Get-Content $readme -Raw
	$text = $text -replace "(?m)^Stable tag:\s*$([regex]::Escape( $current ))\s*$", "Stable tag: $next"
	Set-Content $readme -Value $text -NoNewline

	$text = Get-Content $docReadme -Raw
	$text = $text -replace "version-$([regex]::Escape( $current ))-0a66ff", "version-$next-0a66ff"
	$text = $text -replace "version $([regex]::Escape( $current ))", "version $next"
	$text = $text -replace "$plugin-$([regex]::Escape( $current ))\.zip", "$plugin-$next.zip"
	Set-Content $docReadme -Value $text -NoNewline

	$text = Get-Content $changelog -Raw
	if ( -not $text.Contains( "## [$next]" ) ) {
		$today = Get-Date -Format 'yyyy-MM-dd'
		$heading = "## [$next] — $today"
		$bullet = if ( $Summary ) { $Summary } else { 'Maintenance release.' }
		$text = Replace-First $text '(?m)^## \[' "$heading`n`n### Changed`n`n- $bullet`n`n## ["
		$text = Replace-First $text '(?m)^\[1\.' "[$next]: https://github.com/$repo/releases/tag/v$next`n[1."
		Set-Content $changelog -Value $text -NoNewline
	}
}
else {
	Write-Step 'Releasing the version already in the header'
}

# ── 3. The four places must now agree ───────────────────────────────────────

Write-Step 'Checking version coherence'

$checks = [ordered]@{
	'plugin header' = [regex]::Match( ( Get-Content $mainFile -TotalCount 30 ) -join "`n", '(?m)^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)' ).Groups[1].Value
	'constant'      = [regex]::Match( ( Get-Content $mainFile -Raw ), "define\( '$($slug.ToUpper())_VERSION', '([\d.]+)' \)" ).Groups[1].Value
	'readme.txt'    = [regex]::Match( ( Get-Content $readme -Raw ), '(?m)^Stable tag:\s*([\d.]+)' ).Groups[1].Value
	'README badge'  = [regex]::Match( ( Get-Content $docReadme -Raw ), 'badge/version-([\d.]+)-' ).Groups[1].Value
}

foreach ( $entry in $checks.GetEnumerator() ) {
	if ( $entry.Value -ne $next ) {
		throw "Version mismatch: $($entry.Key) says '$($entry.Value)', expected '$next'."
	}
	Write-Host "  $($entry.Key): $($entry.Value)"
}

# ── 4. Catalogue, lint, tests ───────────────────────────────────────────────

Write-Step 'Rebuilding the translation catalogues'
& php ( Join-Path $Root 'tools/build-translations.php' )
if ( $LASTEXITCODE -ne 0 ) { throw 'The translation build failed: a string is missing from fr_FR or es_ES.' }

Write-Step 'Linting every PHP file'
$broken = 0
Get-ChildItem ( Join-Path $Root $plugin ) -Recurse -Filter *.php | ForEach-Object {
	$result = & php -l $_.FullName 2>&1
	if ( "$result" -notmatch 'No syntax errors' ) {
		Write-Host "  ✗ $($_.Name): $result" -ForegroundColor Red
		$broken++
	}
}
if ( $broken -gt 0 ) { throw "$broken PHP file(s) do not parse." }
Write-Host '  all files parse'

foreach ( $suite in 'run', 'smoke' ) {
	Write-Step "php tests/$suite.php"
	& php ( Join-Path $Root "tests/$suite.php" )
	if ( $LASTEXITCODE -ne 0 ) { throw "tests/$suite.php failed: nothing was published." }
}

# ── 5. ZIP ──────────────────────────────────────────────────────────────────

Write-Step 'Building the archive'
& pwsh -NoProfile -File ( Join-Path $Root 'tools/build-zip.ps1' )
if ( $LASTEXITCODE -ne 0 ) { throw 'The ZIP build failed.' }

$zip     = Join-Path $Root "dist/$plugin-$next.zip"
$zipRoot = Join-Path $Root "$plugin-$next.zip"

if ( -not ( Test-Path $zip ) ) { throw "Expected archive not found: $zip" }

Copy-Item $zip $zipRoot -Force
$hash = ( Get-FileHash $zip -Algorithm SHA256 ).Hash

Write-Host ''
Write-Host "  $plugin-$next.zip" -ForegroundColor Green
Write-Host "  sha256: $hash"

if ( $DryRun ) {
	Write-Step 'Dry run: nothing was committed, tagged or published.'
	return
}

# ── 6. Git and GitHub ───────────────────────────────────────────────────────

Write-Step 'Committing'
Push-Location $Root
try {
	git add -A
	git commit -m "$plugin $next" -m "Co-authored-by: Copilot <223556219+Copilot@users.noreply.github.com>" | Out-Null
	if ( $LASTEXITCODE -ne 0 ) { throw 'git commit failed.' }

	git tag -a "v$next" -m "$plugin $next"
	if ( $LASTEXITCODE -ne 0 ) { throw "git tag v$next failed: does it already exist?" }

	$token = Get-UtilToken
	if ( '' -eq $token ) { throw 'No GitHub token: set GITHUB_TOKEN, or sign in with git so the credential manager can answer.' }

	# The token never touches .git/config: it is passed as a one-shot header.
	$basic = [Convert]::ToBase64String( [Text.Encoding]::ASCII.GetBytes( "x-access-token:$token" ) )

	Write-Step 'Pushing'
	git -c "http.extraheader=Authorization: Basic $basic" push origin HEAD --quiet
	if ( $LASTEXITCODE -ne 0 ) { throw 'git push failed.' }
	git -c "http.extraheader=Authorization: Basic $basic" push origin "v$next" --quiet
	if ( $LASTEXITCODE -ne 0 ) { throw 'git push of the tag failed.' }
}
finally {
	Pop-Location
}

Write-Step 'Publishing the release'

$headers = @{
	Authorization          = "token $token"
	Accept                 = 'application/vnd.github+json'
	'User-Agent'           = 'zigmoon'
	'X-GitHub-Api-Version' = '2022-11-28'
}

if ( '' -eq $Notes ) {
	$raw   = Get-Content $changelog -Raw
	$block = [regex]::Match( $raw, "(?s)## \[$([regex]::Escape( $next ))\][^\n]*\n(.*?)(?=\n## \[|\z)" ).Groups[1].Value
	$Notes = $block.Trim()
}

$body = @{
	tag_name         = "v$next"
	target_commitish = 'main'
	name             = "$plugin $next"
	draft            = $false
	prerelease       = $false
	body             = $Notes
} | ConvertTo-Json

$release = Invoke-RestMethod -Uri "https://api.github.com/repos/$repo/releases" -Headers $headers -Method Post -Body $body -ContentType 'application/json'
Write-Host "  release: $($release.html_url)"

$upload = ( $release.upload_url -replace '\{.*\}', '' ) + "?name=$plugin-$next.zip"
$assetHeaders = @{
	Authorization = "token $token"
	Accept        = 'application/vnd.github+json'
	'User-Agent'  = 'zigmoon'
	'Content-Type' = 'application/zip'
}

$asset = Invoke-RestMethod -Uri $upload -Headers $assetHeaders -Method Post -InFile $zip
Write-Host "  asset: $($asset.name) ($([math]::Round( $asset.size / 1KB, 1 )) KB)"

Write-Host ''
Write-Host "Published $plugin $next." -ForegroundColor Green
