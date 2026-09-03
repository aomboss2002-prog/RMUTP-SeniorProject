@echo off
setlocal EnableExtensions
cd /d "%~dp0"

set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"

if /i "%~1"=="--once" (
    "%PHP_EXE%" "%~dp0scripts\ai-title-worker.php"
) else (
    echo AI Project Title Worker
    echo Press Ctrl+C to stop.
    "%PHP_EXE%" "%~dp0scripts\ai-title-worker.php" --watch
)

exit /b %ERRORLEVEL%
