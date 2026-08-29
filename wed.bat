@echo off
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul

set "ROOT_DIR=%~dp0"
set "PUBLISH_ARGS="

:parse_args
if "%~1"=="" goto run_publisher
if /i "%~1"=="--no-pause" set "PUBLISH_ARGS=!PUBLISH_ARGS! -NoPause"
if /i "%~1"=="--open" set "PUBLISH_ARGS=!PUBLISH_ARGS! -Open"
if /i "%~1"=="--check" set "PUBLISH_ARGS=!PUBLISH_ARGS! -Check"
shift
goto parse_args

:run_publisher
title RMUTP Senior Project ^| Vercel Production Deploy
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%ROOT_DIR%scripts\publish.ps1" !PUBLISH_ARGS!
set "PUBLISH_EXIT=%ERRORLEVEL%"
exit /b %PUBLISH_EXIT%
