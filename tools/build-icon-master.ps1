<#
.SYNOPSIS
	Draws every raster icon from the geometry of assets/icon.svg.

.DESCRIPTION
	The mark is two shapes — a rounded card and one filled path — so it is drawn
	here with GDI+ instead of being downscaled from a browser screenshot: the
	result is deterministic at every size, with no device-pixel-ratio surprises.

	assets/icon-master.png is a 1536 px render (3× the largest icon). Each size
	below is drawn at its exact pixel size, never resampled, so the arch stays
	crisp at 64 px.

	Keep the numbers in sync with assets/icon.svg — they are the same drawing.
	On the 512 grid: the gate spans 100..412 on both axes, the two arches share
	their spring line at y 256, and the band between them is 56 px everywhere.

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

	# The gate. Traced in the same order as the SVG path, so the two cannot
	# drift: up the left outer edge, over the crown, down the right, across the
	# base, up the right inner edge, back over the opening, down the left inner
	# edge, and closed along the base.
	#
	# GDI+ measures angles from 3 o'clock, clockwise, with y pointing down, so
	# 180° is the left of the arch and +180 sweeps through its top; the inner
	# arch is the same arc read the other way, hence 0 and -180.
	$gate = New-Object System.Drawing.Drawing2D.GraphicsPath
	$gate.StartFigure()
	$gate.AddLine( 100 * $s, 412 * $s, 100 * $s, 256 * $s )
	$gate.AddArc( 100 * $s, 100 * $s, 312 * $s, 312 * $s, 180, 180 )
	$gate.AddLine( 412 * $s, 256 * $s, 412 * $s, 412 * $s )
	$gate.AddLine( 412 * $s, 412 * $s, 356 * $s, 412 * $s )
	$gate.AddLine( 356 * $s, 412 * $s, 356 * $s, 256 * $s )
	$gate.AddArc( 156 * $s, 156 * $s, 200 * $s, 200 * $s, 0, -180 )
	$gate.AddLine( 156 * $s, 256 * $s, 156 * $s, 412 * $s )
	$gate.CloseFigure()

	$ink_brush = New-Object System.Drawing.SolidBrush( $ink )
	$g.FillPath( $ink_brush, $gate )

	$ink_brush.Dispose()
	$gate.Dispose()
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
