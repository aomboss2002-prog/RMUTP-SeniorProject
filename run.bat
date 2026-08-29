@echo off
if /i "%RMUTP_LIVE_CORE%"=="1" goto live_core
set "LIVE_PAUSE="
if /i "%~1"=="--no-pause" set "LIVE_PAUSE=-NoPause"
chcp 65001 >nul
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\live-bat.ps1" -ScriptPath "%~f0" -Title "XAMPP Launcher" -TotalSteps 3 %LIVE_PAUSE%
exit /b %ERRORLEVEL%

:live_core
setlocal EnableExtensions
cd /d "%~dp0"

echo ====================================================
echo RMUTP Senior Project - Portable XAMPP Launcher
echo ====================================================
echo.

where powershell.exe >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Windows PowerShell was not found.
    goto fail
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\run.ps1"
if errorlevel 1 goto fail

if not defined RMUTP_LIVE_CORE if /I not "%~1"=="--no-pause" timeout /t 2 /nobreak >nul
exit /b 0

:fail
echo.
echo Could not open the project. Please read the message above.
if not defined RMUTP_LIVE_CORE if /I not "%~1"=="--no-pause" pause
exit /b 1
