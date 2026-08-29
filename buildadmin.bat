@echo off
if /i "%RMUTP_LIVE_CORE%"=="1" goto live_core
set "LIVE_PAUSE="
set "LIVE_TITLE=Admin Portal Build"
if /i "%~1"=="--no-pause" set "LIVE_PAUSE=-NoPause"
if /i "%~1"=="--check" set "LIVE_TITLE=Admin Portal Check"
if /i "%~2"=="--no-pause" set "LIVE_PAUSE=-NoPause"
chcp 65001 >nul
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\live-bat.ps1" -ScriptPath "%~f0" -Title "%LIVE_TITLE%" -TotalSteps 4 %LIVE_PAUSE%
exit /b %ERRORLEVEL%

:live_core
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT_DIR=%~dp0"
set "NO_PAUSE=%~1"
set /a PHP_COUNT=0
set /a JS_COUNT=0
cd /d "%ROOT_DIR%"

echo ====================================================
echo RMUTP Senior Project - Admin Portal Build
echo ====================================================
echo.

call :find_php
if errorlevel 1 goto fail
call :check_structure
if errorlevel 1 goto fail
call :lint_php
if errorlevel 1 goto fail
call :lint_javascript
if errorlevel 1 goto fail
call :run_invariants
if errorlevel 1 goto fail

echo.
echo ====================================================
echo ADMIN BUILD SUCCESS
echo ====================================================
echo PHP files checked : !PHP_COUNT!
echo JS files checked  : !JS_COUNT!
echo Admin login       : http://localhost/RMUTP-SeniorProject/login.php
echo Email             : admin@rmutp.ac.th
echo Password          : admin123
echo.
if not defined RMUTP_LIVE_CORE if /I not "%NO_PAUSE%"=="--no-pause" pause
exit /b 0

:fail
echo.
echo ====================================================
echo ADMIN BUILD FAILED
echo ====================================================
echo Fix the error shown above, then run buildadmin.bat again.
echo.
if not defined RMUTP_LIVE_CORE if /I not "%NO_PAUSE%"=="--no-pause" pause
exit /b 1

:find_php
set "PHP_CMD="
for /f "delims=" %%I in ('where php 2^>nul') do if not defined PHP_CMD set "PHP_CMD=%%I"
if not defined PHP_CMD if exist "C:\xampp\php\php.exe" set "PHP_CMD=C:\xampp\php\php.exe"
if not defined PHP_CMD if exist "C:\php\php.exe" set "PHP_CMD=C:\php\php.exe"
if not defined PHP_CMD (
    echo [ERROR] PHP was not found. Install XAMPP or add PHP to PATH.
    exit /b 1
)
echo [OK] PHP: %PHP_CMD%
exit /b 0

:check_structure
echo [STEP] Checking Admin portal structure...
set "STRUCTURE_OK=1"
for %%D in ("admin" "api\admin" "app" "assets\css" "assets\js" "controllers" "routes" "views\components" "views\pages") do (
    if not exist "%%~D" (
        echo [MISSING] %%~D
        set "STRUCTURE_OK=0"
    )
)
for %%F in ("login.php" "admin\dashboard.php" "admin\page.php" "api\admin\index.php" "api\index.php" "app\helpers.php" "app\session.php" "app\store.php" "controllers\PageController.php" "routes\web.php" "views\layout.php" "views\components\sidebar.php" "views\components\navbar.php" "assets\css\theme.css" "assets\css\style.css" "assets\css\responsive.css" "assets\js\app.js" "assets\js\dashboard.js" "assets\js\student.js" "assets\js\notification.js") do (
    if not exist "%%~F" (
        echo [MISSING] %%~F
        set "STRUCTURE_OK=0"
    )
)
if "!STRUCTURE_OK!"=="0" exit /b 1
echo [OK] Admin structure is complete.
exit /b 0

:lint_php
echo [STEP] Checking PHP syntax...
for %%F in ("login.php" "index.php" "api\index.php" "api\admin\index.php" "app\helpers.php" "app\session.php" "app\store.php" "controllers\PageController.php" "routes\web.php") do (
    call :lint_one_php "%%~F"
    if errorlevel 1 exit /b 1
)
for /R "admin" %%F in (*.php) do (
    call :lint_one_php "%%~fF"
    if errorlevel 1 exit /b 1
)
for /R "views" %%F in (*.php) do (
    call :lint_one_php "%%~fF"
    if errorlevel 1 exit /b 1
)
echo [OK] PHP syntax is valid.
exit /b 0

:lint_one_php
call "%PHP_CMD%" -l "%~1" >nul
if errorlevel 1 (
    echo [ERROR] PHP syntax failed: %~1
    call "%PHP_CMD%" -l "%~1"
    exit /b 1
)
set /a PHP_COUNT+=1
exit /b 0

:lint_javascript
echo [STEP] Checking Admin JavaScript...
where node >nul 2>nul
if errorlevel 1 (
    echo [WARNING] Node.js was not found. JavaScript syntax check skipped.
    exit /b 0
)
for %%F in ("assets\js\app.js" "assets\js\dashboard.js" "assets\js\student.js" "assets\js\notification.js") do (
    node --check "%%~F"
    if errorlevel 1 (
        echo [ERROR] JavaScript syntax failed: %%~F
        exit /b 1
    )
    set /a JS_COUNT+=1
)
echo [OK] JavaScript syntax is valid.
exit /b 0

:run_invariants
if not exist "tests\invariants.php" (
    echo [WARNING] tests\invariants.php was not found. Runtime checks skipped.
    exit /b 0
)
echo [STEP] Checking database and application invariants...
call "%PHP_CMD%" "tests\invariants.php"
if errorlevel 1 (
    echo [ERROR] Application invariant checks failed.
    exit /b 1
)
echo [OK] Database and application invariants passed.
exit /b 0
