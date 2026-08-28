[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $projectRoot '.env'
$schemaFile = Join-Path $projectRoot 'database\database.sql'
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Step([string]$Text) { Write-Host "[STEP] $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text) { Write-Host "[OK] $Text" -ForegroundColor Green }
function Write-Info([string]$Text) { Write-Host "[INFO] $Text" -ForegroundColor Yellow }

function Find-Executable {
    param([string]$Name, [string[]]$Candidates)
    $command = Get-Command $Name -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($command) { return $command.Source }
    foreach ($candidate in $Candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate -PathType Leaf)) {
            return (Resolve-Path -LiteralPath $candidate).Path
        }
    }
    return $null
}

function Get-XamppRoots {
    $roots = New-Object System.Collections.Generic.List[string]
    foreach ($value in @($env:XAMPP_HOME, $env:XAMPP_DIR, (Split-Path -Parent (Split-Path -Parent $projectRoot)))) {
        if ($value) { $roots.Add($value) }
    }
    foreach ($drive in Get-PSDrive -PSProvider FileSystem -ErrorAction SilentlyContinue) {
        $roots.Add((Join-Path $drive.Root 'xampp'))
        $roots.Add((Join-Path $drive.Root 'XAMPP'))
    }
    return $roots | Select-Object -Unique
}

function Read-EnvFile {
    $values = @{}
    if (-not (Test-Path -LiteralPath $envFile)) { return $values }
    foreach ($line in [IO.File]::ReadAllLines($envFile)) {
        $trimmed = $line.Trim()
        if (-not $trimmed -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) { continue }
        $parts = $trimmed.Split('=', 2)
        $values[$parts[0].Trim()] = $parts[1].Trim().Trim('"').Trim("'")
    }
    return $values
}

function Set-EnvValue {
    param([string]$Key, [string]$Value)
    $lines = [System.Collections.Generic.List[string]]::new()
    if (Test-Path -LiteralPath $envFile) {
        $lines.AddRange([string[]][IO.File]::ReadAllLines($envFile))
    }
    $found = $false
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index] -match ('^' + [regex]::Escape($Key) + '=')) {
            $lines[$index] = "$Key=$Value"
            $found = $true
        }
    }
    if (-not $found) { $lines.Add("$Key=$Value") }
    [IO.File]::WriteAllLines($envFile, $lines, $utf8NoBom)
}

function Invoke-Native {
    param(
        [string]$FilePath,
        [string[]]$Arguments,
        [switch]$Quiet,
        [switch]$Capture
    )
    if ($Capture) {
        $result = & $FilePath @Arguments 2>$null
    } elseif ($Quiet) {
        & $FilePath @Arguments *> $null
        $result = $null
    } else {
        & $FilePath @Arguments
        $result = $null
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed (exit $LASTEXITCODE): $FilePath"
    }
    return $result
}

try {
    Set-Location $projectRoot
    Write-Host "Project: $projectRoot"

    $xamppRoots = @(Get-XamppRoots)
    $phpCandidates = foreach ($root in $xamppRoots) { Join-Path $root 'php\php.exe' }
    $phpCandidates += @(
        (Join-Path $env:ProgramFiles 'PHP\php.exe'),
        $(if (${env:ProgramFiles(x86)}) { Join-Path ${env:ProgramFiles(x86)} 'PHP\php.exe' })
    )
    $phpCandidates += Get-ChildItem 'C:\wamp64\bin\php\php*\php.exe', 'D:\wamp64\bin\php\php*\php.exe', 'E:\wamp64\bin\php\php*\php.exe' -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName
    $php = Find-Executable -Name 'php.exe' -Candidates $phpCandidates
    if (-not $php) { throw 'PHP was not found. Install XAMPP/PHP 8.x, add php.exe to PATH, or set XAMPP_HOME.' }
    Write-Ok "PHP found: $php"

    $phpVersion = (& $php -r 'echo PHP_VERSION;').Trim()
    $phpMajor = [int]($phpVersion.Split('.')[0])
    if ($phpMajor -lt 8) { throw "PHP 8.0 or newer is required. Found PHP $phpVersion." }
    foreach ($extension in @('pdo_mysql', 'fileinfo', 'mbstring', 'openssl')) {
        & $php -r "exit(extension_loaded('$extension') ? 0 : 1);"
        if ($LASTEXITCODE -ne 0) { throw "Required PHP extension is disabled: $extension" }
    }
    Write-Ok "PHP $phpVersion and required extensions are ready."

    $mysqlCandidates = foreach ($root in $xamppRoots) { Join-Path $root 'mysql\bin\mysql.exe' }
    $mysqlCandidates += Get-ChildItem `
        'C:\wamp64\bin\mysql\mysql*\bin\mysql.exe', 'D:\wamp64\bin\mysql\mysql*\bin\mysql.exe', `
        "$env:ProgramFiles\MySQL\MySQL Server *\bin\mysql.exe", "$env:ProgramFiles\MariaDB *\bin\mysql.exe" `
        -ErrorAction SilentlyContinue | Select-Object -ExpandProperty FullName
    $mysql = Find-Executable -Name 'mysql.exe' -Candidates $mysqlCandidates
    if (-not $mysql) { throw 'MySQL/MariaDB client was not found. Install XAMPP/WAMP/MySQL or add mysql.exe to PATH.' }
    Write-Ok "MySQL client: $mysql"

    $createdEnv = $false
    if (-not (Test-Path -LiteralPath $envFile)) {
        $example = Join-Path $projectRoot '.env.example'
        if (Test-Path -LiteralPath $example) {
            Copy-Item -LiteralPath $example -Destination $envFile
        } else {
            [IO.File]::WriteAllLines($envFile, @(
                'APP_NAME=RMUTP-SeniorProject', 'APP_ENV=local', 'APP_DEBUG=true',
                'APP_TIMEZONE=Asia/Bangkok', 'APP_URL=', 'DB_HOST=localhost',
                'DB_PORT=3306', 'DB_DATABASE=rmutp_senior_project', 'DB_USERNAME=root',
                'DB_PASSWORD=', 'JWT_SECRET=', 'UPLOAD_PATH=uploads'
            ), $utf8NoBom)
        }
        $createdEnv = $true
        $folder = [uri]::EscapeDataString((Split-Path -Leaf $projectRoot))
        Set-EnvValue -Key 'APP_URL' -Value "http://localhost/$folder"
        Write-Ok 'Created .env for this project folder.'
    } else {
        Write-Ok 'Existing .env will be preserved.'
    }

    $config = Read-EnvFile
    $dbHost = if ($config.DB_HOST) { $config.DB_HOST } else { 'localhost' }
    $dbPort = if ($config.DB_PORT) { [int]$config.DB_PORT } else { 3306 }
    $dbName = if ($config.DB_DATABASE) { $config.DB_DATABASE } else { 'rmutp_senior_project' }
    $dbUser = if ($config.DB_USERNAME) { $config.DB_USERNAME } else { 'root' }
    $dbPassword = if ($null -ne $config.DB_PASSWORD) { $config.DB_PASSWORD } else { '' }
    $appUrl = if ($config.APP_URL) { $config.APP_URL.TrimEnd('/') } else { 'http://localhost/RMUTP-SeniorProject' }
    if ($dbPort -lt 1 -or $dbPort -gt 65535) { throw 'DB_PORT must be between 1 and 65535.' }
    if ($dbName -notmatch '^[A-Za-z0-9_]+$') { throw 'DB_DATABASE may contain only letters, numbers, and underscores.' }
    if (-not $config.DB_PORT) {
        Set-EnvValue -Key 'DB_PORT' -Value '3306'
        $dbPort = 3306
    }
    if (-not $config.JWT_SECRET) {
        $bytes = New-Object byte[] 32
        [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
        Set-EnvValue -Key 'JWT_SECRET' -Value ([Convert]::ToBase64String($bytes))
        Write-Ok 'Generated JWT_SECRET.'
    } else {
        Write-Ok 'JWT_SECRET already exists.'
    }

    $env:MYSQL_PWD = $dbPassword
    # Force the client encoding because Windows console defaults differ between
    # machines. Without this option Thai seed values can be imported as mojibake
    # and subsequently fail the application faculty/major validation.
    $mysqlBase = @('--protocol=tcp', '--default-character-set=utf8mb4', "--host=$dbHost", "--port=$dbPort", "--user=$dbUser", '--connect-timeout=5')
    Write-Step 'Testing database connection...'
    Invoke-Native $mysql ($mysqlBase + @('-e', 'SELECT 1;')) -Quiet
    Write-Ok 'Database connection succeeded.'

    Write-Step 'Creating database...'
    Invoke-Native $mysql ($mysqlBase + @('-e', "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")) -Quiet
    Write-Ok "Database is ready: $dbName at ${dbHost}:$dbPort"

    $existingTableRows = @(Invoke-Native $mysql ($mysqlBase + @(
        '--batch', '--skip-column-names', '-e',
        "SELECT table_name FROM information_schema.tables WHERE table_schema='$dbName';"
    )) -Capture)
    $existingTables = @($existingTableRows | ForEach-Object { $_.ToString().Trim() } | Where-Object { $_ })
    $dataDirectoryRow = @(Invoke-Native $mysql ($mysqlBase + @(
        '--batch', '--skip-column-names', '-e', 'SELECT @@datadir;'
    )) -Capture | Select-Object -First 1)
    $dataDirectory = if ($dataDirectoryRow.Count) { $dataDirectoryRow[0].ToString().Trim() } else { '' }
    $databaseDirectory = if ($dataDirectory) { Join-Path $dataDirectory $dbName } else { '' }
    $orphanedTableFiles = @()
    if ($existingTables.Count -eq 0 -and $databaseDirectory -and (Test-Path -LiteralPath $databaseDirectory)) {
        $orphanedTableFiles = @(Get-ChildItem -LiteralPath $databaseDirectory -File -ErrorAction SilentlyContinue |
            Where-Object { $_.Extension -in @('.ibd', '.frm', '.MYD', '.MYI', '.cfg') })
    }
    $brokenTables = New-Object System.Collections.Generic.List[string]
    foreach ($table in $existingTables) {
        & $mysql @mysqlBase $dbName -e "SELECT 1 FROM ``$table`` LIMIT 0;" *> $null
        if ($LASTEXITCODE -ne 0) { $brokenTables.Add($table) }
    }

    if ($brokenTables.Count -gt 0 -or $orphanedTableFiles.Count -gt 0) {
        $originalDatabase = $dbName
        $suffix = Get-Date -Format 'yyyyMMdd_HHmmss'
        $dbName = "${originalDatabase}_recovered_$suffix"
        if ($brokenTables.Count -gt 0) {
            Write-Info "Corrupted MySQL tables detected: $($brokenTables -join ', ')"
        }
        if ($orphanedTableFiles.Count -gt 0) {
            Write-Info "Orphaned MySQL table files detected in: $databaseDirectory"
        }
        Write-Info "The damaged database '$originalDatabase' will be preserved."
        Write-Step "Creating clean recovery database: $dbName"
        Invoke-Native $mysql ($mysqlBase + @(
            '-e', "CREATE DATABASE ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        )) -Quiet
        Set-EnvValue -Key 'DB_DATABASE' -Value $dbName
        $config.DB_DATABASE = $dbName
        $existingTables = @()
        Write-Ok "Updated .env to use recovery database: $dbName"
    }

    $requiredTables = @('advisors', 'students', 'projects', 'documents')
    $schemaInstalled = @($requiredTables | Where-Object { $existingTables -contains $_ }).Count -eq $requiredTables.Count
    if ($schemaInstalled) {
        Write-Info 'Existing database tables detected. Seed import skipped to preserve user data.'
    } else {
        if (-not (Test-Path -LiteralPath $schemaFile)) { throw 'database\database.sql is missing.' }
        Write-Step 'Importing database schema...'
        $temporarySchema = Join-Path ([IO.Path]::GetTempPath()) ("rmutp-schema-" + [guid]::NewGuid().ToString('N') + '.sql')
        try {
            $schema = [IO.File]::ReadAllText($schemaFile).Replace('rmutp_senior_project', $dbName)
            [IO.File]::WriteAllText($temporarySchema, $schema, $utf8NoBom)
            $process = Start-Process -FilePath $mysql -ArgumentList ($mysqlBase + @($dbName)) -NoNewWindow -Wait -PassThru -RedirectStandardInput $temporarySchema
            if ($process.ExitCode -ne 0) {
                $originalDatabase = $dbName
                $suffix = Get-Date -Format 'yyyyMMdd_HHmmss'
                $dbName = "${originalDatabase}_recovered_$suffix"
                Write-Info "The database '$originalDatabase' contains orphaned or damaged table files."
                Write-Info 'The damaged database will be preserved and a clean recovery database will be used.'
                Write-Step "Creating recovery database: $dbName"
                Invoke-Native $mysql ($mysqlBase + @(
                    '-e', "CREATE DATABASE ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
                )) -Quiet
                Set-EnvValue -Key 'DB_DATABASE' -Value $dbName
                $config.DB_DATABASE = $dbName
                $schema = [IO.File]::ReadAllText($schemaFile).Replace('rmutp_senior_project', $dbName)
                [IO.File]::WriteAllText($temporarySchema, $schema, $utf8NoBom)
                $recoveryProcess = Start-Process -FilePath $mysql -ArgumentList ($mysqlBase + @($dbName)) -NoNewWindow -Wait -PassThru -RedirectStandardInput $temporarySchema
                if ($recoveryProcess.ExitCode -ne 0) {
                    throw "Database recovery import failed (exit $($recoveryProcess.ExitCode))."
                }
                Write-Ok "Recovery database created and selected: $dbName"
            }
        } finally {
            if (Test-Path -LiteralPath $temporarySchema) { Remove-Item -LiteralPath $temporarySchema -Force }
        }
        Write-Ok 'Database schema imported.'
    }

    foreach ($relative in @('uploads', 'uploads\student', 'uploads\proposal', 'uploads\draft', 'uploads\complete')) {
        $folder = Join-Path $projectRoot $relative
        if (-not (Test-Path -LiteralPath $folder)) { New-Item -ItemType Directory -Path $folder | Out-Null }
    }
    Write-Ok 'Upload folders are ready.'

    $composerJson = Join-Path $projectRoot 'composer.json'
    if (Test-Path -LiteralPath $composerJson) {
        if (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'vendor\autoload.php'))) {
            $composer = Get-Command composer.bat -ErrorAction SilentlyContinue
            if (-not $composer) { throw 'composer.json exists but Composer was not found in PATH.' }
            Write-Step 'Installing Composer dependencies...'
            Invoke-Native $composer.Source @('install', '--no-interaction', '--prefer-dist')
        }
        Write-Ok 'Composer dependencies are ready.'
    } else {
        Write-Ok 'Composer is not required by this project.'
    }

    Write-Step 'Verifying PHP and database integration...'
    Invoke-Native $php @('-r', "require 'app/store.php'; database_connection();") -Quiet
    $invariants = Join-Path $projectRoot 'tests\invariants.php'
    if (Test-Path -LiteralPath $invariants) { Invoke-Native $php @($invariants) }
    Write-Ok 'Application verification passed.'

    Write-Host ''
    Write-Host "PHP   : $php"
    Write-Host "MySQL : $mysql"
    Write-Host "URL   : $appUrl/login.php"
    exit 0
} catch {
    Write-Host ''
    Write-Host "[ERROR] $($_.Exception.Message)" -ForegroundColor Red
    exit 1
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}
