$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$root = Split-Path -Parent $PSScriptRoot
$source = Join-Path $root 'assets\img\rmutp-logo.png'
$destination = Join-Path $root 'assets\img\rmutp-logo-web.png'
$original = [Drawing.Image]::FromFile($source)
$bitmap = $null
$graphics = $null
try {
    # A 192px-high transparent PNG covers the small header/sidebar logos at 2x/3x.
    $height = [Math]::Min(192, $original.Height)
    $width = [int][Math]::Round($original.Width * $height / $original.Height)
    $bitmap = New-Object Drawing.Bitmap($width, $height)
    $graphics = [Drawing.Graphics]::FromImage($bitmap)
    $graphics.Clear([Drawing.Color]::Transparent)
    $graphics.CompositingQuality = [Drawing.Drawing2D.CompositingQuality]::HighQuality
    $graphics.InterpolationMode = [Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $graphics.PixelOffsetMode = [Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $graphics.DrawImage($original, 0, 0, $width, $height)
    $bitmap.Save($destination, [Drawing.Imaging.ImageFormat]::Png)
    Get-Item -LiteralPath $source, $destination | Select-Object Name, Length
} finally {
    if ($graphics) { $graphics.Dispose() }
    if ($bitmap) { $bitmap.Dispose() }
    $original.Dispose()
}
