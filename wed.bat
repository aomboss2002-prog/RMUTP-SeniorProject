@echo off
setlocal EnableExtensions EnableDelayedExpansion

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
echo ====================================================
echo RMUTP Senior Project - Website Publisher
echo ====================================================
echo.
echo Project : %VERCEL_PROJECT%
echo Target  : Vercel Production
echo Website : %PRODUCTION_URL%
echo.

echo [1/5] Checking Vercel CLI...
set "VERCEL_CMD="
if exist "%ROOT_DIR%node_modules\.bin\vercel.cmd" (
    set "VERCEL_CMD=%ROOT_DIR%node_modules\.bin\vercel.cmd"
) else (
    where vercel.cmd >nul 2>nul
    if not errorlevel 1 set "VERCEL_CMD=vercel.cmd"
)

if not defined VERCEL_CMD (
    echo [ERROR] Vercel CLI was not found.
    echo Run this command first: npm install --save-dev vercel
    goto fail
)

for /f "delims=" %%V in ('call "!VERCEL_CMD!" --version 2^>nul') do set "VERCEL_VERSION=%%V"
if defined VERCEL_VERSION (
    echo [OK] !VERCEL_VERSION!
) else (
    echo [OK] Vercel CLI found.
)

echo [2/5] Checking project connection...
if not exist ".vercel\project.json" (
    echo [ERROR] This folder is not linked to a Vercel project.
    echo Run: vercel link --scope %VERCEL_SCOPE%
    goto fail
)

findstr /i /c:"%VERCEL_PROJECT%" ".vercel\project.json" >nul
if errorlevel 1 (
    echo [ERROR] The linked Vercel project is not %VERCEL_PROJECT%.
    echo Review .vercel\project.json before publishing.
    goto fail
)
echo [OK] Connected to %VERCEL_SCOPE%/%VERCEL_PROJECT%.

echo [3/5] Checking protected local files...
if not exist ".vercelignore" (
    echo [ERROR] .vercelignore was not found.
    echo Deployment stopped to prevent uploading local secrets or storage files.
    goto fail
)

powershell -NoProfile -Command "$lines = Get-Content -LiteralPath '.vercelignore'; if ($lines -contains '.env') { exit 0 } else { exit 1 }"
if errorlevel 1 (
    echo [ERROR] .env is not excluded by .vercelignore.
    echo Add .env to .vercelignore before publishing.
    goto fail
)
echo [OK] Local secrets and runtime files are excluded.

if "%CHECK_ONLY%"=="1" (
    echo.
    echo [OK] Website publisher check completed. No deployment was started.
    if "%NO_PAUSE%"=="0" pause
    exit /b 0
)

echo [4/5] Uploading the website to Vercel...
echo [INFO] Uploading only files allowed by .vercelignore.
echo [INFO] Vercel will submit the Production deployment without waiting indefinitely.
echo [INFO] The Vercel build normally finishes in about 12-15 seconds.
echo.
for /f %%T in ('powershell -NoProfile -Command "[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()"') do set "DEPLOY_STARTED=%%T"
call "!VERCEL_CMD!" deploy --prod --yes --non-interactive --no-color --no-wait --scope "%VERCEL_SCOPE%"
if errorlevel 1 (
    echo.
    echo [ERROR] Vercel deployment failed.
    goto fail
)
for /f %%T in ('powershell -NoProfile -Command "[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()"') do set "DEPLOY_FINISHED=%%T"
set /a "DEPLOY_SECONDS=DEPLOY_FINISHED-DEPLOY_STARTED"
echo [OK] Deployment was submitted in !DEPLOY_SECONDS! seconds.

echo.
echo [5/5] Checking the production website...
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
    "$ErrorActionPreference='Stop'; $response=Invoke-WebRequest -Uri '%PRODUCTION_URL%/login.php' -UseBasicParsing -TimeoutSec 30; if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 400) { exit 1 }"
if errorlevel 1 (
    echo [WARNING] Deployment succeeded, but the website health check did not respond yet.
    echo [INFO] Vercel may still be assigning the Production domain.
) else (
    echo [OK] Production website is online.
)

echo.
echo ====================================================
echo WEBSITE PUBLISH SUCCESS
echo ====================================================
echo Website : %PRODUCTION_URL%
echo Dashboard: https://vercel.com/%VERCEL_SCOPE%/%VERCEL_PROJECT%
echo.

if "%OPEN_BROWSER%"=="1" start "" "%PRODUCTION_URL%"
if "%NO_PAUSE%"=="0" pause
exit /b 0

:fail
echo.
echo ====================================================
echo WEBSITE PUBLISH FAILED
echo ====================================================
echo Read the error above, correct it, and run wed.bat again.
echo.
if "%NO_PAUSE%"=="0" pause
exit /b 1
