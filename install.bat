@echo off
if /i "%RMUTP_LIVE_CORE%"=="1" goto live_core
set "LIVE_PAUSE="
if /i "%~1"=="--no-pause" set "LIVE_PAUSE=-NoPause"
chcp 65001 >nul
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\live-bat.ps1" -ScriptPath "%~f0" -Title "System Installation" -TotalSteps 8 %LIVE_PAUSE%
exit /b %ERRORLEVEL%

:live_core
setlocal EnableExtensions
cd /d "%~dp0"

echo ====================================================
echo RMUTP Senior Project - Portable Windows Installer
echo ====================================================
echo.

where powershell.exe >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Windows PowerShell was not found.
    echo Install Windows PowerShell 5.1 or newer and try again.
    goto fail
)

powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\install.ps1"
if errorlevel 1 goto fail

echo.
echo ====================================================
echo INSTALL SUCCESS
echo ====================================================
echo.
if not defined RMUTP_LIVE_CORE if /I not "%~1"=="--no-pause" pause
exit /b 0

:fail
echo.
echo ====================================================
echo INSTALL FAILED
echo ====================================================
echo Read the error above, correct it, and run install.bat again.
echo.
if not defined RMUTP_LIVE_CORE if /I not "%~1"=="--no-pause" pause
exit /b 1
