@echo off
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
if /I not "%~1"=="--no-pause" pause
exit /b 0

:fail
echo.
echo ====================================================
echo INSTALL FAILED
echo ====================================================
echo Read the error above, correct it, and run install.bat again.
echo.
if /I not "%~1"=="--no-pause" pause
exit /b 1
