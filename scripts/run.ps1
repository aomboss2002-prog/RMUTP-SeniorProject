[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $projectRoot '.env'

function Find-XamppRoot {
    $candidates = New-Object System.Collections.Generic.List[string]
    foreach ($configured in @($env:XAMPP_HOME, $env:XAMPP_DIR)) {
        if ($configured) { $candidates.Add($configured) }
    }
    $current = Get-Item -LiteralPath $projectRoot
    while ($current) {
        $candidates.Add($current.FullName)
        $current = $current.Parent
    }
    foreach ($drive in Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue) {
        $candidates.Add((Join-Path $drive.Root 'xampp'))
        $candidates.Add((Join-Path $drive.Root 'XAMPP'))
    }
    foreach ($candidate in $candidates | Select-Object -Unique) {
        if (Test-Path -LiteralPath (Join-Path $candidate 'apache\bin\httpd.exe')) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }
    return $null
}

function Read-AppUrl {
    if (-not (Test-Path -LiteralPath $envFile)) { return $null }
    foreach ($line in [IO.File]::ReadAllLines($envFile)) {
        if ($line -match '^APP_URL=(.+)$') { return $matches[1].Trim().TrimEnd('/') }
    }
    return $null
}

function Set-AppUrl([string]$Url) {
    if (-not (Test-Path -LiteralPath $envFile)) { return }
    $utf8NoBom = New-Object Text.UTF8Encoding($false)
    $lines = [System.Collections.Generic.List[string]]::new()
    $lines.AddRange([string[]][IO.File]::ReadAllLines($envFile))
    $found = $false
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match '^APP_URL=') {
            $lines[$index] = "APP_URL=$Url"
            $found = $true
        }
    }
    if (-not $found) { $lines.Add("APP_URL=$Url") }
    [IO.File]::WriteAllLines($envFile, $lines, $utf8NoBom)
}

function Test-WebPage([string]$Url) {
    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 3 -MaximumRedirection 5
        return $response.StatusCode -ge 200 -and $response.StatusCode -lt 500
    } catch {
        if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -lt 500) { return $true }
        return $false
    }
}

try {
    $xamppRoot = Find-XamppRoot
    if (-not $xamppRoot) {
        throw 'XAMPP Apache was not found. Set XAMPP_HOME or add XAMPP to a local drive.'
    }
    Write-Host "[OK] XAMPP found: $xamppRoot" -ForegroundColor Green

    $htdocs = Join-Path $xamppRoot 'htdocs'
    $resolvedProject = (Resolve-Path -LiteralPath $projectRoot).Path.TrimEnd('\')
    $resolvedHtdocs = (Resolve-Path -LiteralPath $htdocs).Path.TrimEnd('\')
    $appUrl = Read-AppUrl
    if ($resolvedProject.StartsWith($resolvedHtdocs + '\', [StringComparison]::OrdinalIgnoreCase)) {
        $relative = $resolvedProject.Substring($resolvedHtdocs.Length).Trim('\')
        $encodedSegments = $relative.Split('\') | ForEach-Object { [uri]::EscapeDataString($_) }
        $calculatedUrl = 'http://localhost/' + ($encodedSegments -join '/')
        if ($appUrl -ne $calculatedUrl) {
            $appUrl = $calculatedUrl
            Set-AppUrl $appUrl
            Write-Host "[OK] Updated APP_URL for this folder: $appUrl" -ForegroundColor Green
        }
    }
    if (-not $appUrl) { throw 'APP_URL is missing and the project is not located under the XAMPP htdocs folder.' }
    $loginUrl = $appUrl.TrimEnd('/') + '/login.php'

    $mysqlAdmin = Join-Path $xamppRoot 'mysql\bin\mysqladmin.exe'
    $mysqlAlive = $false
    if (Test-Path -LiteralPath $mysqlAdmin) {
        & $mysqlAdmin -u root ping *> $null
        $mysqlAlive = $LASTEXITCODE -eq 0
    }
    if (-not $mysqlAlive) {
        $mysqlStart = Join-Path $xamppRoot 'mysql_start.bat'
        if (Test-Path -LiteralPath $mysqlStart) {
            Write-Host '[INFO] Starting XAMPP MySQL...'
            Start-Process -FilePath $env:ComSpec -ArgumentList @('/c', "`"$mysqlStart`"") -WindowStyle Hidden
        }
    } else {
        Write-Host '[OK] MySQL is already running.' -ForegroundColor Green
    }

    if (-not (Test-WebPage $loginUrl)) {
        $apacheStart = Join-Path $xamppRoot 'apache_start.bat'
        if (-not (Test-Path -LiteralPath $apacheStart)) { throw "Apache launcher was not found: $apacheStart" }
        Write-Host '[INFO] Starting XAMPP Apache...'
        Start-Process -FilePath $env:ComSpec -ArgumentList @('/c', "`"$apacheStart`"") -WindowStyle Hidden
        Write-Host '[INFO] Waiting for the website...'
        $ready = $false
        for ($attempt = 1; $attempt -le 30; $attempt++) {
            Start-Sleep -Seconds 1
            if (Test-WebPage $loginUrl) {
                $ready = $true
                break
            }
        }
        if (-not $ready) {
            throw "Apache is running but the login page did not respond: $loginUrl"
        }
    }

    Write-Host "[OK] Opening: $loginUrl" -ForegroundColor Green
    if ($env:RMUTP_RUN_NO_OPEN -ne '1') {
        Start-Process $loginUrl
    }
    exit 0
} catch {
    Write-Host "[ERROR] $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
