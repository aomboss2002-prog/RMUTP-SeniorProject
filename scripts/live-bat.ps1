[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][string]$ScriptPath,
    [Parameter(Mandatory = $true)][string]$Title,
    [int]$TotalSteps = 1,
    [switch]$NoPause,
    [Parameter(ValueFromRemainingArguments = $true)][string[]]$CoreArguments
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Utf8 = New-Object Text.UTF8Encoding($false)
[Console]::OutputEncoding = $Utf8
$OutputEncoding = $Utf8
$Interactive = -not [Console]::IsOutputRedirected
$NoPause = $NoPause -or ($CoreArguments -contains '--no-pause')
$StartedAt = [DateTime]::UtcNow
$LogPath = [IO.Path]::GetTempFileName()
$PreviousLines = @()
$UiTop = 0
$CursorHidden = $false
$Full = [char]0x2588
$Empty = [char]0x2591

function Fit([string]$Text, [int]$Length) {
    if ($null -eq $Text) { $Text = '' }
    $Text = ($Text -replace "[\r\n\t]", ' ').TrimEnd()
    if ($Text.Length -gt $Length) {
        if ($Length -lt 4) { return $Text.Substring(0, $Length) }
        return $Text.Substring(0, $Length - 3) + '...'
    }
    return $Text.PadRight($Length)
}

function Clean-Line([string]$Line) {
    return (($Line -replace "\x1B\[[0-9;?]*[ -/]*[@-~]", '') -replace '[\x00-\x08\x0B\x0C\x0E-\x1F]', '').Trim()
}

function Render([string]$Heading, [string]$Status, [int]$Percent, [string]$Detail, [string]$State = 'active', [int]$Pulse = 0) {
    if (-not $Interactive) {
        if ($State -ne 'active' -or $script:PreviousLines.Count -eq 0) { Write-Output "[$Percent%] $Heading - $Status" }
        return
    }
    try { $width = [Math]::Max(46, [Math]::Min(92, [Console]::WindowWidth - 1)) } catch { $width = 72 }
    $inside = $width - 2
    $suffix = if ($Percent -lt 0) { ' LIVE' } elseif ($State -eq 'success') { (' {0,3}% OK' -f $Percent) } elseif ($State -eq 'error') { (' {0,3}% X' -f $Percent) } else { (' {0,3}%' -f $Percent) }
    $barWidth = [Math]::Max(10, $inside - $suffix.Length - 3)
    if ($Percent -lt 0) {
        $cells = New-Object char[] $barWidth
        for ($i = 0; $i -lt $barWidth; $i++) { $cells[$i] = $Empty }
        $pulseWidth = [Math]::Max(3, [Math]::Min(8, [int]($barWidth / 4)))
        $start = $Pulse % [Math]::Max(1, $barWidth - $pulseWidth + 1)
        for ($i = $start; $i -lt $start + $pulseWidth; $i++) { $cells[$i] = $Full }
        $bar = -join $cells
    } else {
        $safePercent = [Math]::Max(0, [Math]::Min(100, $Percent))
        $filled = [Math]::Min($barWidth, [int][Math]::Floor($barWidth * $safePercent / 100))
        $bar = ([string]$Full * $filled) + ([string]$Empty * ($barWidth - $filled))
    }
    $border = '+' + ('-' * $inside) + '+'
    $lines = @(
        $border,
        ('|{0}|' -f (Fit "  $Heading" $inside)),
        ('|{0}|' -f (Fit "  $Status" $inside)),
        ('|{0}|' -f (Fit "  $bar$suffix" $inside)),
        ('|{0}|' -f (Fit "  $Detail" $inside)),
        $border
    )
    if (-not $script:CursorHidden) {
        Clear-Host
        try { [Console]::CursorVisible = $false } catch {}
        $script:CursorHidden = $true
        $script:UiTop = [Console]::CursorTop
    }
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($script:PreviousLines.Count -le $i -or $script:PreviousLines[$i] -ne $lines[$i]) {
            try {
                [Console]::SetCursorPosition(0, $script:UiTop + $i)
                $old = [Console]::ForegroundColor
                if ($State -eq 'success') { [Console]::ForegroundColor = 'Green' }
                elseif ($State -eq 'error') { [Console]::ForegroundColor = 'Red' }
                elseif ($i -eq 0 -or $i -eq 5) { [Console]::ForegroundColor = 'DarkCyan' }
                elseif ($i -eq 3) { [Console]::ForegroundColor = 'Cyan' }
                else { [Console]::ForegroundColor = 'White' }
                [Console]::Write($lines[$i])
                [Console]::ForegroundColor = $old
            } catch { Write-Output $lines[$i] }
        }
    }
    $script:PreviousLines = $lines
    try { [Console]::SetCursorPosition(0, $script:UiTop + 6) } catch {}
}

function Restore-Cursor {
    if ($script:CursorHidden) {
        try { [Console]::SetCursorPosition(0, $script:UiTop + 6); [Console]::CursorVisible = $true; [Console]::WriteLine() } catch {}
        $script:CursorHidden = $false
    }
}

function Quote-CmdArgument([string]$Value) {
    if ($null -eq $Value) { return '""' }
    return '"' + ($Value -replace '"', '""') + '"'
}

try {
    $resolvedScript = (Resolve-Path -LiteralPath $ScriptPath).Path
    $workingDirectory = Split-Path -Parent $resolvedScript
    $argumentText = (($CoreArguments | ForEach-Object { Quote-CmdArgument $_ }) -join ' ')
    $commandLine = '""{0}" {1} > "{2}" 2>&1"' -f $resolvedScript, $argumentText, $LogPath
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = $env:ComSpec
    $info.Arguments = "/d /s /c $commandLine"
    $info.WorkingDirectory = $workingDirectory
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.EnvironmentVariables['RMUTP_LIVE_CORE'] = '1'
    $process = [Diagnostics.Process]::Start($info)
    if (-not $process) { throw 'Unable to start the command.' }

    $pulse = 0
    $shownPercent = 2
    $targetPercent = 2
    $status = 'Starting...'
    while (-not $process.HasExited) {
        try { $content = Get-Content -LiteralPath $LogPath -Raw -ErrorAction SilentlyContinue } catch { $content = '' }
        if ($null -eq $content) { $content = '' }
        $cleanLines = @($content -split "\r?\n" | ForEach-Object { Clean-Line $_ } | Where-Object { $_ -and $_ -notmatch '^=+$' })
        $stepCount = @($cleanLines | Where-Object { $_ -match '^\[STEP\]' -or $_ -match '^\d{2}\s{2,}' }).Count
        if ($stepCount -gt 0) { $targetPercent = [Math]::Max($targetPercent, [Math]::Min(90, 5 + [int](85 * $stepCount / [Math]::Max(1, $TotalSteps)))) }
        foreach ($line in $cleanLines) {
            if ($line -match '(?<percent>\d{1,3})%') { $targetPercent = [Math]::Max($targetPercent, [Math]::Min(95, [int]$Matches.percent)) }
        }
        $candidate = $cleanLines | Where-Object { $_ -notmatch '^\[(OK|INFO|WARNING)\]\s*$' } | Select-Object -Last 1
        if ($candidate) { $status = $candidate -replace '^\[(STEP|OK|INFO|WARNING)\]\s*', '' }
        if ($shownPercent -lt $targetPercent) { $shownPercent = [Math]::Min($targetPercent, $shownPercent + 1) }
        $elapsed = [Math]::Max(0, [int]([DateTime]::UtcNow - $StartedAt).TotalSeconds)
        Render -Heading $Title -Status $status -Percent $shownPercent -Detail "Running  Elapsed ${elapsed}s" -Pulse $pulse
        $pulse++
        Start-Sleep -Milliseconds 80
        try { $process.Refresh() } catch {}
    }
    $process.WaitForExit()
    try { $finalContent = Get-Content -LiteralPath $LogPath -Raw -ErrorAction SilentlyContinue } catch { $finalContent = '' }
    $elapsed = [Math]::Max(0, [int]([DateTime]::UtcNow - $StartedAt).TotalSeconds)
    if ($process.ExitCode -eq 0) {
        Render -Heading "$Title Complete" -Status 'All operations completed successfully' -Percent 100 -Detail "Finished in ${elapsed}s" -State success
    } else {
        $errors = @($finalContent -split "\r?\n" | ForEach-Object { Clean-Line $_ } | Where-Object {
            $_ -match '(?i)error|failed|missing|cannot|not found' -and
            $_ -notmatch '(?i)^Fix the error shown|^Read the error above|BUILD FAILED|Application invariant checks failed'
        })
        $message = $errors | Select-Object -Last 1
        if (-not $message) { $message = 'The operation failed. Review the command output.' }
        Render -Heading "$Title Failed" -Status $message -Percent $shownPercent -Detail "Exit code $($process.ExitCode)  Elapsed ${elapsed}s" -State error
    }
    Restore-Cursor
    if (-not $NoPause -and $Interactive) {
        Write-Host 'Press any key to continue . . .' -NoNewline
        try { $null = [Console]::ReadKey($true) } catch {}
        Write-Host
    }
    exit $process.ExitCode
} catch {
    Render -Heading "$Title Failed" -Status $_.Exception.Message -Percent 0 -Detail 'Unable to start operation' -State error
    Restore-Cursor
    exit 1
} finally {
    Restore-Cursor
    Remove-Item -LiteralPath $LogPath -Force -ErrorAction SilentlyContinue
}
