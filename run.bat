@echo off
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

if /I not "%~1"=="--no-pause" timeout /t 2 /nobreak >nul
exit /b 0

:fail
echo.
echo Could not open the project. Please read the message above.
if /I not "%~1"=="--no-pause" pause
exit /b 1
