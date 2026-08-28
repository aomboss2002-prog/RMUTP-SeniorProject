@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul

rem ANSI palette for modern Windows Terminal / PowerShell terminals.
for /f "delims=" %%E in ('echo prompt $E^| cmd') do set "ESC=%%E"
set "RESET=!ESC![0m"
set "BOLD=!ESC![1m"
set "BLUE=!ESC![38;2;37;99;235m"
set "CYAN=!ESC![38;2;34;211;238m"
set "GREEN=!ESC![38;2;34;197;94m"
set "YELLOW=!ESC![38;2;250;204;21m"
set "RED=!ESC![38;2;248;113;113m"
set "MUTED=!ESC![38;2;148;163;184m"
set "WHITE=!ESC![38;2;241;245;249m"

set "ROOT_DIR=%~dp0"
set "VERCEL_PROJECT=rmutp-senior-project"
set "VERCEL_SCOPE=boss-ec12"
set "PRODUCTION_URL=https://rmutp-senior-project.vercel.app"
set "NO_PAUSE=0"
set "OPEN_BROWSER=0"
set "CHECK_ONLY=0"

if /i "%~1"=="--no-pause" set "NO_PAUSE=1"
if /i "%~1"=="--open" set "OPEN_BROWSER=1"
if /i "%~1"=="--check" set "CHECK_ONLY=1"
if /i "%~2"=="--no-pause" set "NO_PAUSE=1"
if /i "%~2"=="--open" set "OPEN_BROWSER=1"
if /i "%~2"=="--check" set "CHECK_ONLY=1"

cd /d "%ROOT_DIR%"
title RMUTP Senior Project ^| Vercel Production Deploy
cls

echo.
echo !BLUE!  +--------------------------------------------------------------+!RESET!
echo !BLUE!  ^|!RESET! !BOLD!!WHITE! RMUTP SENIOR PROJECT!RESET! !MUTED!// PRODUCTION DEPLOY CONSOLE!RESET!       !BLUE!^|!RESET!
echo !BLUE!  +--------------------------------------------------------------+!RESET!
echo.
echo   !MUTED!PROJECT!RESET!  !WHITE!%VERCEL_SCOPE%/%VERCEL_PROJECT%!RESET!
echo   !MUTED!TARGET !RESET!  !YELLOW![PRODUCTION]!RESET!
echo   !MUTED!WEBSITE!RESET!  !CYAN!%PRODUCTION_URL%!RESET!
echo.

echo !BLUE!  [####----------------]  20%%!RESET!  !BOLD!Checking Vercel CLI!RESET!
set "VERCEL_CMD="
if exist "%ROOT_DIR%node_modules\.bin\vercel.cmd" (
    set "VERCEL_CMD=%ROOT_DIR%node_modules\.bin\vercel.cmd"
) else (
    where vercel.cmd >nul 2>nul
    if not errorlevel 1 set "VERCEL_CMD=vercel.cmd"
)

if not defined VERCEL_CMD (
    echo !RED!  [ERROR]!RESET! Vercel CLI was not found.
    echo !MUTED!          Run: npm install --save-dev vercel!RESET!
    goto fail
)

for /f "delims=" %%V in ('call "!VERCEL_CMD!" --version 2^>nul') do set "VERCEL_VERSION=%%V"
if defined VERCEL_VERSION (
    echo !GREEN!          [OK] !VERCEL_VERSION!!RESET!
) else (
    echo !GREEN!          [OK] Vercel CLI found!RESET!
)

echo !BLUE!  [########------------]  40%%!RESET!  !BOLD!Checking project connection!RESET!
if not exist ".vercel\project.json" (
    echo !RED!  [ERROR]!RESET! This folder is not linked to a Vercel project.
    echo !MUTED!          Run: vercel link --scope %VERCEL_SCOPE%!RESET!
    goto fail
)

findstr /i /c:"%VERCEL_PROJECT%" ".vercel\project.json" >nul
if errorlevel 1 (
    echo !RED!  [ERROR]!RESET! The linked Vercel project is not %VERCEL_PROJECT%.
    echo !MUTED!          Review .vercel\project.json before publishing.!RESET!
    goto fail
)
echo !GREEN!          [OK] Connected to %VERCEL_SCOPE%/%VERCEL_PROJECT%!RESET!

echo !BLUE!  [############--------]  60%%!RESET!  !BOLD!Protecting local secrets!RESET!
if not exist ".vercelignore" (
    echo !RED!  [ERROR]!RESET! .vercelignore was not found.
    echo !MUTED!          Deployment stopped to protect local secrets.!RESET!
    goto fail
)

powershell -NoProfile -Command "$lines = Get-Content -LiteralPath '.vercelignore'; if ($lines -contains '.env') { exit 0 } else { exit 1 }"
if errorlevel 1 (
    echo !RED!  [ERROR]!RESET! .env is not excluded by .vercelignore.
    echo !MUTED!          Add .env to .vercelignore before publishing.!RESET!
    goto fail
)
echo !GREEN!          [OK] Secrets and runtime files are excluded!RESET!

if "%CHECK_ONLY%"=="1" (
    echo.
    echo !GREEN!  [OK] Publisher check completed!RESET! !MUTED!No deployment was started.!RESET!
    if "%NO_PAUSE%"=="0" pause
    exit /b 0
)

echo !BLUE!  [################----]  80%%!RESET!  !BOLD!Uploading and building Production!RESET!
echo !MUTED!          Only files allowed by .vercelignore will be uploaded.!RESET!
echo !MUTED!          Waiting for the new deployment to reach READY.!RESET!
echo !MUTED!          Typical build time: 12-20 seconds.!RESET!
echo.
for /f %%T in ('powershell -NoProfile -Command "[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()"') do set "DEPLOY_STARTED=%%T"
call "!VERCEL_CMD!" deploy --prod --yes --non-interactive --no-color --scope "%VERCEL_SCOPE%"
if errorlevel 1 (
    echo.
    echo !RED!  [ERROR]!RESET! Vercel deployment failed.
    goto fail
)
for /f %%T in ('powershell -NoProfile -Command "[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()"') do set "DEPLOY_FINISHED=%%T"
set /a "DEPLOY_SECONDS=DEPLOY_FINISHED-DEPLOY_STARTED"
echo !GREEN!          [OK] Production READY in !DEPLOY_SECONDS! seconds!RESET!

echo.
echo !BLUE!  [####################] 100%%!RESET!  !BOLD!Verifying the production website!RESET!
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ErrorActionPreference='Stop'; $response=Invoke-WebRequest -Uri '%PRODUCTION_URL%/login.php' -UseBasicParsing -TimeoutSec 30; if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 400) { exit 1 }"
if errorlevel 1 (
    echo !YELLOW!  [WARNING]!RESET! Deployment succeeded, but the health check is not ready.
    echo !MUTED!            Vercel may still be assigning the Production domain.!RESET!
) else (
    echo !GREEN!          [OK] Production website is online!RESET!
)

echo.
echo !GREEN!  +--------------------------------------------------------------+!RESET!
echo !GREEN!  ^|!RESET! !BOLD!!WHITE! DEPLOYMENT COMPLETE!RESET! !GREEN![ONLINE]!RESET!                           !GREEN!^|!RESET!
echo !GREEN!  +--------------------------------------------------------------+!RESET!
echo   !MUTED!Website  !RESET! !CYAN!%PRODUCTION_URL%!RESET!
echo   !MUTED!Dashboard!RESET! !CYAN!https://vercel.com/%VERCEL_SCOPE%/%VERCEL_PROJECT%!RESET!
echo   !MUTED!Duration !RESET! !WHITE!!DEPLOY_SECONDS! seconds!RESET!
echo.

if "%OPEN_BROWSER%"=="1" start "" "%PRODUCTION_URL%"
if "%NO_PAUSE%"=="0" pause
exit /b 0

:fail
echo.
echo !RED!  +--------------------------------------------------------------+!RESET!
echo !RED!  ^|!RESET! !BOLD!!WHITE! DEPLOYMENT FAILED!RESET!                                          !RED!^|!RESET!
echo !RED!  +--------------------------------------------------------------+!RESET!
echo !MUTED!  Read the error above, correct it, and run wed.bat again.!RESET!
echo.
if "%NO_PAUSE%"=="0" pause
exit /b 1
