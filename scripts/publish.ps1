[CmdletBinding()]
param(
    [switch]$NoPause,
    [switch]$Open,
    [switch]$Check
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# Windows PowerShell 5.1 inherits the legacy Thai/OEM code page from cmd.exe.
# Force UTF-8 so box-drawing and progress characters render consistently.
$Utf8Encoding = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = $Utf8Encoding
$OutputEncoding = $Utf8Encoding
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$ProjectName = 'rmutp-senior-project'
$Scope = 'boss-ec12'
$ProductionUrl = 'https://rmutp-senior-project.vercel.app'
$StartedAt = [DateTime]::UtcNow
$Interactive = -not [Console]::IsOutputRedirected
$LogPath = $null
$VercelPrefix = ''

# Keep the frame ASCII for broad compatibility. The progress cells are created
# from Unicode code points at runtime after the console is switched to UTF-8.
$Glyph = @{
    TopLeft = '+'; TopRight = '+'
    BottomLeft = '+'; BottomRight = '+'
    Horizontal = '-'; Vertical = '|'
    Full = [char]0x2588; Empty = [char]0x2591
    Check = 'OK'; Cross = 'X'
}
$script:UiTop = 0
$script:PreviousLines = @()
$script:CursorHidden = $false
$script:FallbackShown = $false

function Get-TerminalWidth {
    if (-not $Interactive) { return 72 }
    try { return [Math]::Max(46, [Math]::Min(92, [Console]::WindowWidth - 1)) } catch { return 72 }
}

function Fit-Text([string]$Text, [int]$Length) {
    if ($Length -le 0) { return '' }
    if ($null -eq $Text) { $Text = '' }
    $clean = $Text -replace "[\r\n\t]", ' '
    if ($clean.Length -gt $Length) {
        if ($Length -le 3) { return $clean.Substring(0, $Length) }
        return $clean.Substring(0, $Length - 3) + '...'
    }
    return $clean.PadRight($Length)
}

function Format-Bytes([double]$Bytes) {
    if ($Bytes -ge 1GB) { return ('{0:N1} GB' -f ($Bytes / 1GB)) }
    if ($Bytes -ge 1MB) { return ('{0:N1} MB' -f ($Bytes / 1MB)) }
    if ($Bytes -ge 1KB) { return ('{0:N1} KB' -f ($Bytes / 1KB)) }
    return ('{0:N0} B' -f $Bytes)
}

function Convert-SizeToBytes([string]$Value) {
    if ($Value -notmatch '^\s*(?<number>[\d.,]+)\s*(?<unit>KiB|MiB|GiB|KB|MB|GB|B)\s*$') { return $null }
    $number = [double]::Parse(($Matches.number -replace ',', ''), [Globalization.CultureInfo]::InvariantCulture)
    switch ($Matches.unit.ToUpperInvariant()) {
        'B' { return $number }; 'KB' { return $number * 1KB }; 'KIB' { return $number * 1KB }
        'MB' { return $number * 1MB }; 'MIB' { return $number * 1MB }
        'GB' { return $number * 1GB }; 'GIB' { return $number * 1GB }
    }
}

function New-UiLines {
    param(
        [string]$Title, [string]$Status, [int]$Percent, [string]$Details,
        [ValidateSet('active', 'success', 'error')][string]$State = 'active', [int]$Pulse = 0
    )
    $width = Get-TerminalWidth
    $inside = $width - 2
    $indeterminate = $Percent -lt 0
    $percent = [Math]::Max(0, [Math]::Min(100, $Percent))
    $suffix = (' {0,3}%' -f $percent)
    if ($State -eq 'success') { $suffix += " $($Glyph.Check)" }
    if ($State -eq 'error') { $suffix += " $($Glyph.Cross)" }
    $barWidth = [Math]::Max(10, $inside - $suffix.Length - 3)

    if ($indeterminate) {
        $barChars = New-Object char[] $barWidth
        for ($i = 0; $i -lt $barWidth; $i++) { $barChars[$i] = $Glyph.Empty }
        $pulseWidth = [Math]::Max(3, [Math]::Min(8, [int]($barWidth / 4)))
        $start = $Pulse % [Math]::Max(1, ($barWidth - $pulseWidth + 1))
        for ($i = $start; $i -lt ($start + $pulseWidth); $i++) { $barChars[$i] = $Glyph.Full }
        $bar = -join $barChars
        $suffix = ' LIVE'
    } else {
        $filled = [Math]::Min($barWidth, [int][Math]::Floor($barWidth * $percent / 100))
        $bar = ([string]$Glyph.Full * $filled) + ([string]$Glyph.Empty * ($barWidth - $filled))
    }

    $border = [string]$Glyph.Horizontal * $inside
    return @(
        "$($Glyph.TopLeft)$border$($Glyph.TopRight)",
        "$($Glyph.Vertical)$(Fit-Text "  $Title" $inside)$($Glyph.Vertical)",
        "$($Glyph.Vertical)$(Fit-Text "  $Status" $inside)$($Glyph.Vertical)",
        "$($Glyph.Vertical)$(Fit-Text "  $bar$suffix" $inside)$($Glyph.Vertical)",
        "$($Glyph.Vertical)$(Fit-Text "  $Details" $inside)$($Glyph.Vertical)",
        "$($Glyph.BottomLeft)$border$($Glyph.BottomRight)"
    )
}

function Show-ProgressUi {
    param(
        [string]$Title, [string]$Status, [int]$Percent, [string]$Details,
        [ValidateSet('active', 'success', 'error')][string]$State = 'active', [int]$Pulse = 0,
        [switch]$ForceFallback
    )
    if (-not $Interactive) {
        if ($ForceFallback -or -not $script:FallbackShown) {
            Write-Output ("[{0}] {1} - {2}" -f $Percent, $Title, $Status)
            $script:FallbackShown = $true
        }
        return
    }

    $lines = New-UiLines -Title $Title -Status $Status -Percent $Percent -Details $Details -State $State -Pulse $Pulse
    if (-not $script:CursorHidden) {
        try { [Console]::CursorVisible = $false } catch {}
        $script:CursorHidden = $true
        Clear-Host
        $script:UiTop = [Console]::CursorTop
    }
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($script:PreviousLines.Count -le $i -or $script:PreviousLines[$i] -ne $lines[$i]) {
            try {
                [Console]::SetCursorPosition(0, $script:UiTop + $i)
                $originalColor = [Console]::ForegroundColor
                if ($State -eq 'success') { [Console]::ForegroundColor = [ConsoleColor]::Green }
                elseif ($State -eq 'error') { [Console]::ForegroundColor = [ConsoleColor]::Red }
                elseif ($i -eq 0 -or $i -eq 5) { [Console]::ForegroundColor = [ConsoleColor]::DarkCyan }
                elseif ($i -eq 3) { [Console]::ForegroundColor = [ConsoleColor]::Cyan }
                else { [Console]::ForegroundColor = [ConsoleColor]::White }
                [Console]::Write($lines[$i])
                [Console]::ForegroundColor = $originalColor
            } catch { Write-Output $lines[$i] }
        }
    }
    $script:PreviousLines = $lines
    try { [Console]::SetCursorPosition(0, $script:UiTop + $lines.Count) } catch {}
}

function Restore-Terminal {
    if ($script:CursorHidden) {
        try {
            [Console]::SetCursorPosition(0, $script:UiTop + 6)
            [Console]::CursorVisible = $true
            [Console]::WriteLine()
        } catch {}
        $script:CursorHidden = $false
    }
}

function Wait-ForKey {
    if (-not $NoPause -and $Interactive) {
        Write-Host 'Press any key to continue . . .' -NoNewline
        try { $null = [Console]::ReadKey($true) } catch {}
        Write-Host
    }
}

function Stop-WithFailure([string]$Message, [int]$Percent = 0) {
    $elapsed = [Math]::Max(0, [int]([DateTime]::UtcNow - $StartedAt).TotalSeconds)
    Show-ProgressUi -Title 'Publish Failed' -Status $Message -Percent $Percent -Details "Elapsed ${elapsed}s" -State error -ForceFallback
    Restore-Terminal
    Wait-ForKey
    exit 1
}

function Get-MeaningfulError([string]$Content) {
    $lines = @($Content -split "\r?\n" | ForEach-Object { $_.Trim() } | Where-Object {
        $_ -and $_ -notmatch '^[{}\[\],]+$' -and $_ -notmatch '^Vercel CLI '
    })
    $candidate = $lines | Where-Object { $_ -match '(?i)error|failed|denied|invalid|timed out' } | Select-Object -Last 1
    if (-not $candidate) { $candidate = $lines | Select-Object -Last 1 }
    if (-not $candidate) { return 'Vercel deployment failed' }
    return ($candidate -replace '^Error:\s*', '')
}

try {
    Set-Location -LiteralPath $ProjectRoot
    Show-ProgressUi -Title 'Preparing Production Publish' -Status 'Checking Vercel CLI' -Percent 5 -Details "$Scope/$ProjectName"

    $localVercel = Join-Path $ProjectRoot 'node_modules\.bin\vercel.cmd'
    if (Test-Path -LiteralPath $localVercel) { $VercelCommand = $localVercel }
    else {
        $resolved = Get-Command vercel.cmd -ErrorAction SilentlyContinue
        if ($resolved) { $VercelCommand = $resolved.Source }
    }
    if (-not $VercelCommand) {
        $resolvedNpx = Get-Command npx.cmd -ErrorAction SilentlyContinue
        if ($resolvedNpx) {
            # Use the PATH-resolved command name. A fully qualified npm path is
            # commonly under "Program Files" and is fragile inside cmd /s /c.
            $VercelCommand = 'npx.cmd'
            $VercelPrefix = '--yes vercel@59.9.1'
        }
    }
    if (-not $VercelCommand) { Stop-WithFailure 'Vercel CLI and npx were not found' 5 }

    $versionArgs = @()
    if ($VercelPrefix) { $versionArgs += $VercelPrefix.Split(' ') }
    $versionArgs += '--version'
    $previousErrorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $versionOutput = @(& $VercelCommand @versionArgs 2>&1)
        $versionExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorPreference
    }
    if ($versionExitCode -ne 0) { Stop-WithFailure 'Unable to start Vercel CLI through npx' 5 }
    $version = $versionOutput | ForEach-Object { $_.ToString().Trim() } | Where-Object { $_ } | Select-Object -Last 1
    if (-not $version) { $version = 'Vercel CLI ready' }
    Show-ProgressUi -Title 'Preparing Production Publish' -Status 'Checking project connection' -Percent 12 -Details $version

    $projectFile = Join-Path $ProjectRoot '.vercel\project.json'
    if (-not (Test-Path -LiteralPath $projectFile)) { Stop-WithFailure 'Project is not linked to Vercel' 12 }
    if ((Get-Content -LiteralPath $projectFile -Raw) -notmatch [regex]::Escape($ProjectName)) {
        Stop-WithFailure "Linked project is not $ProjectName" 12
    }

    Show-ProgressUi -Title 'Preparing Production Publish' -Status 'Protecting local secrets' -Percent 20 -Details 'Checking .vercelignore'
    $ignoreFile = Join-Path $ProjectRoot '.vercelignore'
    if (-not (Test-Path -LiteralPath $ignoreFile)) { Stop-WithFailure '.vercelignore was not found' 20 }
    $ignoreLines = @(Get-Content -LiteralPath $ignoreFile | ForEach-Object { $_.Trim() })
    if ($ignoreLines -notcontains '.env') { Stop-WithFailure '.env is not excluded from deployment' 20 }

    if ($Check) {
        Show-ProgressUi -Title 'Publisher Check Complete' -Status 'Vercel publisher is ready' -Percent 100 -Details "$Scope/$ProjectName" -State success -ForceFallback
        Restore-Terminal; Wait-ForKey; exit 0
    }

    $LogPath = [IO.Path]::GetTempFileName()
    $commandToken = if ($VercelCommand -match '\s') { '"' + $VercelCommand + '"' } else { $VercelCommand }
    $commandLine = '{0} {1} deploy --prod --yes --non-interactive --no-color --scope "{2}" > "{3}" 2>&1' -f $commandToken, $VercelPrefix, $Scope, $LogPath
    $processInfo = New-Object Diagnostics.ProcessStartInfo
    $processInfo.FileName = $env:ComSpec
    $processInfo.Arguments = "/d /c $commandLine"
    $processInfo.WorkingDirectory = $ProjectRoot
    $processInfo.UseShellExecute = $false
    $processInfo.CreateNoWindow = $true
    $process = [Diagnostics.Process]::Start($processInfo)
    if (-not $process) { Stop-WithFailure 'Could not start Vercel CLI' 20 }

    $deployStarted = [DateTime]::UtcNow
    $phase = 'Uploading project files'
    $pulse = 0
    $lastUploaded = 0.0
    $lastSampleAt = $deployStarted
    $speed = 0.0
    $lastContent = ''
    $lastPercent = 20

    while (-not $process.HasExited) {
        $elapsed = [Math]::Max(0, [int]([DateTime]::UtcNow - $deployStarted).TotalSeconds)
        try { $content = Get-Content -LiteralPath $LogPath -Raw -ErrorAction SilentlyContinue } catch { $content = '' }
        if ($null -eq $content) { $content = '' }
        if ($content) { $lastContent = $content }
        $details = "Elapsed ${elapsed}s  Waiting for Vercel"
        $displayPercent = -1

        if ($content -match '(?i)Uploading\s+\[[^\]]*\]\s*\((?<done>[\d.,]+\s*(?:KiB|MiB|GiB|KB|MB|GB|B))\s*/\s*(?<total>[\d.,]+\s*(?:KiB|MiB|GiB|KB|MB|GB|B))\)') {
            $done = Convert-SizeToBytes $Matches.done
            $total = Convert-SizeToBytes $Matches.total
            if ($total -gt 0) {
                $now = [DateTime]::UtcNow
                $sampleSeconds = ($now - $lastSampleAt).TotalSeconds
                if ($sampleSeconds -gt 0.15 -and $done -ge $lastUploaded) {
                    $instantSpeed = ($done - $lastUploaded) / $sampleSeconds
                    if ($instantSpeed -gt 0) {
                        $speed = if ($speed -gt 0) { ($speed * 0.65) + ($instantSpeed * 0.35) } else { $instantSpeed }
                    }
                    $lastUploaded = $done; $lastSampleAt = $now
                }
                $displayPercent = [Math]::Min(65, [Math]::Max(1, [int][Math]::Floor(($done / $total) * 65)))
                $lastPercent = $displayPercent
                $eta = if ($speed -gt 0) { [Math]::Max(0, [int][Math]::Ceiling(($total - $done) / $speed)) } else { 0 }
                $speedText = if ($speed -gt 0) { "$(Format-Bytes $speed)/s" } else { '--/s' }
                $etaText = if ($eta -gt 0) { "ETA ${eta}s" } else { 'ETA --' }
                $details = "$(Format-Bytes $done) / $(Format-Bytes $total)  $speedText  $etaText"
            }
        }
        if ($content -match '(?i)Building|Running build|vercel build') {
            $phase = 'Building Production deployment'; $displayPercent = 72; $lastPercent = 72
            $details = "Build running  Elapsed ${elapsed}s"
        }
        if ($content -match '(?i)Deploying outputs|Completing') {
            $phase = 'Deploying build outputs'; $displayPercent = 88; $lastPercent = 88
            $details = "Production deployment  Elapsed ${elapsed}s"
        }
        if ($content -match '(?i)Aliased|readyState[^\r\n]*READY|Production:\s+https?://') {
            $phase = 'Assigning Production domain'; $displayPercent = 96; $lastPercent = 96
            $details = "$ProductionUrl  Elapsed ${elapsed}s"
        }
        Show-ProgressUi -Title 'Publishing to Vercel' -Status $phase -Percent $displayPercent -Details $details -Pulse $pulse
        $pulse++
        Start-Sleep -Milliseconds 120
        try { $process.Refresh() } catch {}
    }
    $process.WaitForExit()
    try { $lastContent = Get-Content -LiteralPath $LogPath -Raw -ErrorAction SilentlyContinue } catch {}
    if ($process.ExitCode -ne 0) { Stop-WithFailure (Get-MeaningfulError $lastContent) $lastPercent }

    $deploySeconds = [Math]::Max(1, [int][Math]::Ceiling(([DateTime]::UtcNow - $deployStarted).TotalSeconds))
    Show-ProgressUi -Title 'Verifying Production Website' -Status 'Checking the Production URL' -Percent 98 -Details "$ProductionUrl/login.php"
    $healthMessage = 'Production website is online'
    try {
        $response = Invoke-WebRequest -Uri "$ProductionUrl/login.php" -UseBasicParsing -TimeoutSec 30
        if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 400) { throw "HTTP $($response.StatusCode)" }
    } catch { $healthMessage = 'Deployed; domain health check is still warming up' }

    Show-ProgressUi -Title 'Publish Complete' -Status $healthMessage -Percent 100 -Details "Production READY in ${deploySeconds}s" -State success -ForceFallback
    Restore-Terminal
    if ($Open) { Start-Process $ProductionUrl }
    Wait-ForKey
    exit 0
} catch {
    Stop-WithFailure $_.Exception.Message 0
} finally {
    Restore-Terminal
    if ($LogPath -and (Test-Path -LiteralPath $LogPath)) {
        Remove-Item -LiteralPath $LogPath -Force -ErrorAction SilentlyContinue
    }
}
