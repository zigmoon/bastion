<#
.SYNOPSIS
	Draws every raster icon from the geometry of assets/icon.svg.

.DESCRIPTION
	The mark is three primitives — a rounded card, a battlemented wall with its
	ground line, and a filled gate — so it is drawn here with GDI+ instead of
	being downscaled from a browser screenshot: the result is deterministic at
	every size, with no device-pixel-ratio surprises.

	assets/icon-master.png is a 1536 px render (3× the largest icon). Each size
	below is drawn at its exact pixel size, never resampled, so hairlines stay
	crisp.

	Keep the numbers in sync with assets/icon.svg — they are the same drawing.

.EXAMPLE
	pwsh -File tools/build-icon-master.ps1
#>

[CmdletBinding()]
param(
	[string] $Root = (Split-Path -Parent $PSScriptRoot)
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$card = [System.Drawing.Color]::FromArgb( 255, 0xF7, 0xF8, 0xFA )
$ink  = [System.Drawing.Color]::FromArgb( 255, 0x0B, 0x0D, 0x10 )

function New-Icon( [int] $size ) {
	$s = $size / 512.0

	$bmp = New-Object System.Drawing.Bitmap( $size, $size, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb )
	$g   = [System.Drawing.Graphics]::FromImage( $bmp )

	$g.SmoothingMode      = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
	$g.PixelOffsetMode    = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
	$g.CompositingQuality = [System.Drawing.Drawing2D.CompositingQuality]::HighQuality
	$g.Clear( [System.Drawing.Color]::Transparent )

	# Rounded card, radius 76/512.
	$radius = 76.0 * $s
	$card_path = New-Object System.Drawing.Drawing2D.GraphicsPath
	$d = $radius * 2
	$card_path.AddArc( 0, 0, $d, $d, 180, 90 )
	$card_path.AddArc( $size - $d, 0, $d, $d, 270, 90 )
	$card_path.AddArc( $size - $d, $size - $d, $d, $d, 0, 90 )
	$card_path.AddArc( 0, $size - $d, $d, $d, 90, 90 )
	$card_path.CloseFigure()
	$card_brush = New-Object System.Drawing.SolidBrush( $card )
	$g.FillPath( $card_brush, $card_path )

	$pen = New-Object System.Drawing.Pen( $ink, ( 32.0 * $s ) )
	$pen.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Miter
	$pen.StartCap = [System.Drawing.Drawing2D.LineCap]::Square
	$pen.EndCap   = [System.Drawing.Drawing2D.LineCap]::Square

	# The battlemented wall: three merlons, two embrasures.
	$wall = New-Object System.Drawing.Drawing2D.GraphicsPath
	$wall.AddLine( 138 * $s, 400 * $s, 138 * $s, 200 * $s )
	$wall.AddLine( 138 * $s, 200 * $s, 188 * $s, 200 * $s )
	$wall.AddLine( 188 * $s, 200 * $s, 188 * $s, 244 * $s )
	$wall.AddLine( 188 * $s, 244 * $s, 230 * $s, 244 * $s )
	$wall.AddLine( 230 * $s, 244 * $s, 230 * $s, 200 * $s )
	$wall.AddLine( 230 * $s, 200 * $s, 282 * $s, 200 * $s )
	$wall.AddLine( 282 * $s, 200 * $s, 282 * $s, 244 * $s )
	$wall.AddLine( 282 * $s, 244 * $s, 324 * $s, 244 * $s )
	$wall.AddLine( 324 * $s, 244 * $s, 324 * $s, 200 * $s )
	$wall.AddLine( 324 * $s, 200 * $s, 374 * $s, 200 * $s )
	$wall.AddLine( 374 * $s, 200 * $s, 374 * $s, 400 * $s )
	$g.DrawPath( $pen, $wall )

	# The ground line.
	$g.DrawLine( $pen, 112 * $s, 400 * $s, 400 * $s, 400 * $s )

	# The gate: an arch, filled.
	$gate = New-Object System.Drawing.Drawing2D.GraphicsPath
	$gate.AddLine( 228 * $s, 400 * $s, 228 * $s, 342 * $s )
	$gate.AddArc( 228 * $s, 314 * $s, 56 * $s, 56 * $s, 180, 180 )
	$gate.AddLine( 284 * $s, 342 * $s, 284 * $s, 400 * $s )
	$gate.CloseFigure()
	$ink_brush = New-Object System.Drawing.SolidBrush( $ink )
	$g.FillPath( $ink_brush, $gate )

	$ink_brush.Dispose()
	$gate.Dispose()
	$pen.Dispose()
	$wall.Dispose()
	$card_brush.Dispose()
	$card_path.Dispose()
	$g.Dispose()

	return $bmp
}

$master = New-Icon 1536
$master.Save( (Join-Path $Root 'assets/icon-master.png'), [System.Drawing.Imaging.ImageFormat]::Png )
$master.Dispose()
Write-Host '  assets/icon-master.png (1536)'

foreach ( $size in 512, 256, 128, 64 ) {
	$bitmap = New-Icon $size
	$bitmap.Save( (Join-Path $Root "assets/icon-$size.png"), [System.Drawing.Imaging.ImageFormat]::Png )
	$bitmap.Dispose()
	Write-Host "  assets/icon-$size.png"
}

Write-Host 'Done.'
