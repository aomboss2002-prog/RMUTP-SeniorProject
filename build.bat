@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT_DIR=%~dp0"
set "NO_PAUSE=%~1"
set "CURRENT_PROGRESS=0"
cd /d "%ROOT_DIR%"

title RMUTP Senior Project ^| Build Engine
for /f "delims=" %%T in ('powershell -NoProfile -Command "[DateTime]::UtcNow.Ticks"') do set "BUILD_STARTED_TICKS=%%T"

for /f "delims=" %%E in ('echo prompt $E^| cmd') do set "ESC=%%E"
set "C_RESET=!ESC![0m"
set "C_BLUE=!ESC![94m"
set "C_CYAN=!ESC![96m"
set "C_GREEN=!ESC![92m"
set "C_YELLOW=!ESC![93m"
set "C_RED=!ESC![91m"
set "C_DIM=!ESC![90m"
set "B_BLUE=!ESC![48;5;25;97m"
set "B_GREEN=!ESC![48;5;35;97m"
set "B_RED=!ESC![48;5;160;97m"

cls
echo.
echo   !B_BLUE!  BUILD  !C_RESET!  !C_BLUE!RMUTP Senior Project!C_RESET!
echo           !C_DIM!Project readiness check!C_RESET!
echo.
echo   !C_DIM!Workspace  %ROOT_DIR%!C_RESET!
echo   !C_DIM!Started    %DATE%  %TIME:~0,8%!C_RESET!
echo.
call :boot_animation
echo.
set "CURRENT_PROGRESS=0"
echo   !C_DIM!Progress!C_RESET!  !C_CYAN![------------------------------]   0%%!C_RESET!
echo.

echo   !C_DIM!01!C_RESET!  PHP runtime
call :require_php
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=9"
echo       !C_CYAN![##----------------------------]   9%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!02!C_RESET!  Temporary cache
call :clean_cache
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=18"
echo       !C_CYAN![#####-------------------------]  18%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!03!C_RESET!  Project structure
call :verify_project_structure
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=27"
echo       !C_CYAN![########----------------------]  27%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!04!C_RESET!  Environment file
call :ensure_env
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=36"
echo       !C_CYAN![##########--------------------]  36%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!05!C_RESET!  Environment variables
call :load_env
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=45"
echo       !C_CYAN![#############-----------------]  45%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!06!C_RESET!  Application config
call :verify_config
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=55"
echo       !C_CYAN![################--------------]  55%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!07!C_RESET!  MySQL client
call :find_mysql
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=64"
echo       !C_CYAN![###################-----------]  64%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!08!C_RESET!  Database connection
call :check_database_connection
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=73"
echo       !C_CYAN![#####################---------]  73%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!09!C_RESET!  Security secret
call :ensure_jwt_secret
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=82"
echo       !C_CYAN![########################------]  82%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!10!C_RESET!  Upload storage
call :create_upload_folders
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=91"
echo       !C_CYAN![###########################---]  91%%!C_RESET!  !B_GREEN! DONE !C_RESET!
echo   !C_DIM!11!C_RESET!  Optimization and syntax
call :optimize_project
if errorlevel 1 goto fail
set "CURRENT_PROGRESS=100"
echo       !C_GREEN![##############################] 100%%!C_RESET!  !B_GREEN! DONE !C_RESET!

for /f "delims=" %%T in ('powershell -NoProfile -Command "$start=[long]'!BUILD_STARTED_TICKS!'; [Math]::Round(([DateTime]::UtcNow.Ticks-$start)/10000000.0,2)"') do set "BUILD_SECONDS=%%T"

echo.
echo   !B_GREEN!  READY  !C_RESET!  !C_GREEN!All 11 checks passed in !BUILD_SECONDS!s!C_RESET!
echo.
echo   !C_DIM!Run!C_RESET!       !C_CYAN!.\run.bat!C_RESET!
echo   !C_DIM!Open!C_RESET!      !C_CYAN!http://localhost/RMUTP-SeniorProject/login.php!C_RESET!
echo.
if /I not "%NO_PAUSE%"=="--no-pause" pause
exit /b 0

:fail
echo.
echo   !B_RED!  FAILED  !C_RESET!  !C_RED!Build stopped at !CURRENT_PROGRESS!%%!C_RESET!
echo   !C_DIM!Review the error above, fix the issue, and run again.!C_RESET!
echo.
if /I not "%NO_PAUSE%"=="--no-pause" pause
exit /b 1

:boot_animation
powershell -NoProfile -ExecutionPolicy Bypass -Command "$frames=@('   ',' . ',' ..','...'); foreach($i in 0..11){ Write-Host (([char]13)+'  Preparing workspace'+$frames[$i -band 3]) -NoNewline -ForegroundColor Cyan; Start-Sleep -Milliseconds 45 }; Write-Host (([char]13)+'  Workspace ready.          ') -ForegroundColor Green"
exit /b 0

:require_php
set "PHP_CMD="
for /f "delims=" %%I in ('where php 2^>nul') do (
    if not defined PHP_CMD set "PHP_CMD=%%I"
)
if defined PHP_CMD goto php_found
if exist "C:\xampp\php\php.exe" set "PHP_CMD=C:\xampp\php\php.exe"
if defined PHP_CMD goto php_found
if exist "C:\php\php.exe" set "PHP_CMD=C:\php\php.exe"
if defined PHP_CMD goto php_found
if exist "C:\Program Files\PHP\php.exe" set "PHP_CMD=C:\Program Files\PHP\php.exe"
if defined PHP_CMD goto php_found
for /d %%D in ("C:\wamp64\bin\php\php*") do (
    if exist "%%~D\php.exe" if not defined PHP_CMD set "PHP_CMD=%%~D\php.exe"
)
if defined PHP_CMD goto php_found
echo [Missing] PHP was not found.
echo Install PHP 8.x or XAMPP, then add php.exe to your Windows PATH.
echo XAMPP default path: C:\xampp\php
exit /b 1

:php_found
echo !C_DIM!      PHP  %PHP_CMD%!C_RESET!
exit /b 0

:clean_cache
for %%D in ("cache" "app\cache" "storage\cache" "bootstrap\cache") do (
    if exist "%%~D" (
        del /f /q "%%~D\*" >nul 2>nul
        for /d %%C in ("%%~D\*") do rmdir /s /q "%%~C" >nul 2>nul
    )
)
if not exist "cache" mkdir "cache"
echo !C_DIM!      Cache cleared!C_RESET!
exit /b 0

:verify_project_structure
set "STRUCTURE_OK=1"
for %%D in ("api" "app" "assets" "config" "controllers" "database" "middleware" "models" "routes" "uploads" "views") do (
    if not exist "%%~D" (
        echo [Missing] Folder %%~D
        set "STRUCTURE_OK=0"
    )
)
for %%F in ("index.php" ".env.example" "database\database.sql" "api\index.php" "api\student-api.php" "app\helpers.php" "app\store.php" "views\layout.php" "views\components\header.php" "views\components\sidebar.php" "views\components\navbar.php" "views\components\footer.php" "views\components\portal-sidebar.php" "views\components\portal-navbar.php" "assets\css\theme.css" "assets\css\style.css" "assets\css\responsive.css" "assets\js\app.js" "assets\js\dashboard.js" "assets\js\student.js" "assets\js\notification.js" "assets\js\portal.js") do (
    if not exist "%%~F" (
        echo [Missing] File %%~F
        set "STRUCTURE_OK=0"
    )
)
if "%STRUCTURE_OK%"=="0" exit /b 1
echo !C_DIM!      Required files and folders found!C_RESET!
exit /b 0

:ensure_env
if exist ".env" (
    echo !C_DIM!      Using existing .env!C_RESET!
    exit /b 0
)
if exist ".env.example" (
    copy /Y ".env.example" ".env" >nul
    echo !C_DIM!      Created .env from .env.example!C_RESET!
    exit /b 0
)
echo [Error] .env and .env.example are missing.
exit /b 1

:load_env
for /f "usebackq eol=# tokens=1,* delims==" %%A in (".env") do (
    if not "%%~A"=="" set "%%~A=%%~B"
)
exit /b 0

:verify_config
set "CONFIG_OK=1"
if not defined DB_HOST (
    echo [Missing] DB_HOST in .env
    set "CONFIG_OK=0"
)
if not defined DB_DATABASE (
    echo [Missing] DB_DATABASE in .env
    set "CONFIG_OK=0"
)
if not defined DB_USERNAME (
    echo [Missing] DB_USERNAME in .env
    set "CONFIG_OK=0"
)
if "%CONFIG_OK%"=="0" exit /b 1
set "MYSQL_PASSWORD_ARG="
if defined DB_PASSWORD set "MYSQL_PASSWORD_ARG=-p%DB_PASSWORD%"
echo !C_DIM!      Required values are present!C_RESET!
exit /b 0

:find_mysql
set "MYSQL_CMD="
for /f "delims=" %%I in ('where mysql 2^>nul') do (
    if not defined MYSQL_CMD set "MYSQL_CMD=%%I"
)
if defined MYSQL_CMD goto mysql_found
if exist "C:\xampp\mysql\bin\mysql.exe" set "MYSQL_CMD=C:\xampp\mysql\bin\mysql.exe"
if defined MYSQL_CMD goto mysql_found
for /d %%D in ("C:\wamp64\bin\mysql\mysql*") do (
    if exist "%%~D\bin\mysql.exe" if not defined MYSQL_CMD set "MYSQL_CMD=%%~D\bin\mysql.exe"
)
if defined MYSQL_CMD goto mysql_found
for /d %%D in ("C:\Program Files\MySQL\MySQL Server *") do (
    if exist "%%~D\bin\mysql.exe" if not defined MYSQL_CMD set "MYSQL_CMD=%%~D\bin\mysql.exe"
)
if defined MYSQL_CMD goto mysql_found
echo [Missing] MySQL client was not found.
echo Install MySQL, MariaDB, WAMP, or XAMPP and make sure mysql.exe is available.
exit /b 1

:mysql_found
echo !C_DIM!      MySQL  %MYSQL_CMD%!C_RESET!
exit /b 0

:check_database_connection
echo !C_DIM!      Connecting to %DB_DATABASE%...!C_RESET!
call "%MYSQL_CMD%" -h "%DB_HOST%" -u "%DB_USERNAME%" %MYSQL_PASSWORD_ARG% "%DB_DATABASE%" -e "SELECT 1;" >nul
if errorlevel 1 (
    echo [Error] Cannot connect to database %DB_DATABASE%.
    echo Run install.bat first, make sure MySQL is running, and confirm .env credentials.
    exit /b 1
)
echo !C_DIM!      Connection established!C_RESET!
exit /b 0

:ensure_jwt_secret
if defined JWT_SECRET (
    echo !C_DIM!      Existing JWT secret is configured!C_RESET!
    exit /b 0
)
for /f "delims=" %%S in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "$b=New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); [Convert]::ToBase64String($b)"') do set "JWT_SECRET=%%S"
if not defined JWT_SECRET (
    echo [Error] Could not generate JWT_SECRET.
    exit /b 1
)
call :set_env_value JWT_SECRET "%JWT_SECRET%"
if errorlevel 1 exit /b 1
echo !C_DIM!      Generated a new JWT secret!C_RESET!
exit /b 0

:set_env_value
powershell -NoProfile -ExecutionPolicy Bypass -Command "$path='.env'; $key='%~1'; $value='%~2'; if(Test-Path $path){$lines=Get-Content $path}else{$lines=@()}; $pattern='^'+[regex]::Escape($key)+'='; $found=$false; $updated=@(); foreach($line in $lines){ if($line -match $pattern){ $updated += ($key+'='+$value); $found=$true } else { $updated += $line } }; if(-not $found){ $updated += ($key+'='+$value) }; Set-Content -Path $path -Value $updated -Encoding ASCII"
if errorlevel 1 exit /b 1
exit /b 0

:create_upload_folders
for %%D in ("uploads" "uploads\student" "uploads\proposal" "uploads\draft" "uploads\complete") do (
    if not exist "%%~D" mkdir "%%~D"
)
echo !C_DIM!      Upload folders are writable!C_RESET!
exit /b 0

:optimize_project
if exist "composer.json" (
    where composer >nul 2>nul
    if errorlevel 1 (
        echo [Missing] Composer was not found.
        echo Install Composer for Windows, then run build.bat again.
        exit /b 1
    )
    call composer dump-autoload --optimize --no-interaction
    if errorlevel 1 (
        echo [Error] Composer optimization failed.
        exit /b 1
    )
    echo !C_DIM!      Composer autoload optimized!C_RESET!
) else (
    echo !C_DIM!      Composer is not required!C_RESET!
)
for %%P in ("index.php" "api\index.php" "api\student-api.php" "app\helpers.php" "app\store.php") do (
    call "%PHP_CMD%" -l "%%~P" >nul
    if errorlevel 1 (
        echo [Error] PHP syntax check failed for %%~P.
        exit /b 1
    )
)
for /R "views" %%P in (*.php) do (
    call "%PHP_CMD%" -l "%%~fP" >nul
    if errorlevel 1 (
        echo [Error] PHP syntax check failed for %%~fP.
        exit /b 1
    )
)
for /R "api\student" %%P in (*.php) do (
    call "%PHP_CMD%" -l "%%~fP" >nul
    if errorlevel 1 (
        echo [Error] PHP syntax check failed for %%~fP.
        exit /b 1
    )
)
if exist "tests\resource-loading.php" (
    call "%PHP_CMD%" "tests\resource-loading.php" >nul
    if errorlevel 1 (
        echo [Error] Page resource loading rules failed.
        exit /b 1
    )
)
echo !C_DIM!      PHP syntax is valid!C_RESET!
exit /b 0
