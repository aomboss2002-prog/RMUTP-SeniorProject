@echo off
setlocal EnableExtensions DisableDelayedExpansion

rem This file is also discoverable as the `git` command on Windows because it
rem lives in the current directory. Forward real Git subcommands so tools such
rem as Vercel CLI do not accidentally launch the GitHub publisher.
if "%~1"=="" goto publisher_start
if /i "%~1"=="--check" goto publisher_start
if /i "%~1"=="--no-pause" goto publisher_start
if /i "%~1"=="--message" goto publisher_start

set "REAL_GIT_EXE="
for /f "delims=" %%G in ('where git.exe 2^>nul') do if not defined REAL_GIT_EXE set "REAL_GIT_EXE=%%G"
if not defined REAL_GIT_EXE (
    echo [ERROR] Git for Windows was not found.
    exit /b 1
)
"%REAL_GIT_EXE%" %*
exit /b %errorlevel%

:publisher_start

set "ROOT_DIR=%~dp0"
set "REMOTE_URL=https://github.com/aomboss2002-prog/RMUTP-SeniorProject.git"
set "BRANCH=main"
set "COMMIT_MESSAGE="
set "CHECK_ONLY=0"
set "NO_PAUSE=0"
if /i "%~1"=="--message" set "COMMIT_MESSAGE=%~2"
if /i "%~1"=="--check" (
    set "CHECK_ONLY=1"
    set "COMMIT_MESSAGE="
)
if /i "%~1"=="--no-pause" set "NO_PAUSE=1"
if /i "%~2"=="--no-pause" set "NO_PAUSE=1"

if /i "%RMUTP_LIVE_CORE%"=="1" goto publisher_core
set "RMUTP_GIT_MESSAGE=%COMMIT_MESSAGE%"
set "RMUTP_GIT_CHECK=%CHECK_ONLY%"
set "LIVE_PAUSE="
if "%NO_PAUSE%"=="1" set "LIVE_PAUSE=-NoPause"
chcp 65001 >nul
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\live-bat.ps1" -ScriptPath "%~f0" -Title "GitHub Publisher" -TotalSteps 4 %LIVE_PAUSE%
set "LIVE_EXIT=%ERRORLEVEL%"
set "RMUTP_GIT_MESSAGE="
set "RMUTP_GIT_CHECK="
exit /b %LIVE_EXIT%

:publisher_core
if defined RMUTP_GIT_MESSAGE set "COMMIT_MESSAGE=%RMUTP_GIT_MESSAGE%"
if "%RMUTP_GIT_CHECK%"=="1" set "CHECK_ONLY=1"
cd /d "%ROOT_DIR%"

title RMUTP Senior Project ^| Git Publisher
cls
echo.
echo ====================================================
echo RMUTP Senior Project - GitHub Publisher
echo ====================================================
echo.
echo Repository: %REMOTE_URL%
echo Branch    : %BRANCH%
echo.

echo [STEP] Checking Git for Windows...
where git.exe >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Git was not found.
    echo Install Git for Windows from https://git-scm.com/download/win
    goto fail
)

for /f "delims=" %%V in ('git.exe --version') do echo [OK] %%V

if not exist ".git\HEAD" (
    if "%CHECK_ONLY%"=="1" (
        echo [ERROR] Git repository has not been initialized.
        goto fail
    )
    echo [STEP] Initializing Git repository...
    git.exe init
    if errorlevel 1 goto fail
) else (
    echo [OK] Existing Git repository detected.
)

echo [STEP] Checking branch and remote...
set "CURRENT_BRANCH="
for /f "delims=" %%B in ('git.exe branch --show-current 2^>nul') do set "CURRENT_BRANCH=%%B"
if /i "%CURRENT_BRANCH%"=="%BRANCH%" goto branch_ready
if "%CHECK_ONLY%"=="1" (
    echo [ERROR] Current branch is "%CURRENT_BRANCH%", expected "%BRANCH%".
    goto fail
)
git.exe branch -M "%BRANCH%"
if errorlevel 1 goto fail

:branch_ready
echo [OK] Branch = %BRANCH%

set "CURRENT_REMOTE="
for /f "delims=" %%R in ('git.exe remote get-url origin 2^>nul') do set "CURRENT_REMOTE=%%R"
if not defined CURRENT_REMOTE goto add_remote
if /i "%CURRENT_REMOTE%"=="%REMOTE_URL%" goto remote_ready
if "%CHECK_ONLY%"=="1" (
    echo [ERROR] origin does not match the configured GitHub repository.
    echo Current : %CURRENT_REMOTE%
    echo Expected: %REMOTE_URL%
    goto fail
)
git.exe remote set-url origin "%REMOTE_URL%"
if errorlevel 1 goto fail
goto remote_ready

:add_remote
if "%CHECK_ONLY%"=="1" (
    echo [ERROR] Git remote "origin" is not configured.
    goto fail
)
git.exe remote add origin "%REMOTE_URL%"
if errorlevel 1 goto fail

:remote_ready
echo [OK] origin = %REMOTE_URL%

for /f "delims=" %%N in ('git.exe config user.name 2^>nul') do set "GIT_USER_NAME=%%N"
for /f "delims=" %%E in ('git.exe config user.email 2^>nul') do set "GIT_USER_EMAIL=%%E"
if not defined GIT_USER_NAME (
    echo.
    echo [ERROR] Git user.name is not configured.
    echo Run: git config --global user.name "Your Name"
    goto fail
)
if not defined GIT_USER_EMAIL (
    echo.
    echo [ERROR] Git user.email is not configured.
    echo Run: git config --global user.email "you@example.com"
    goto fail
)
echo [OK] Commit author: %GIT_USER_NAME% ^<%GIT_USER_EMAIL%^>

if "%CHECK_ONLY%"=="1" (
    echo.
    echo [OK] GitHub publisher check completed. No files were staged or pushed.
    if not defined RMUTP_LIVE_CORE if "%NO_PAUSE%"=="0" pause
    exit /b 0
)

echo [STEP] Staging project files...
git.exe add -A
if errorlevel 1 goto fail

git.exe diff --cached --quiet
if errorlevel 1 goto create_commit

echo [INFO] No new file changes to commit.
goto push_changes

:create_commit
if defined COMMIT_MESSAGE goto commit_ready
for /f "delims=" %%T in ('powershell -NoProfile -Command "Get-Date -Format 'yyyy-MM-dd HH:mm'"') do set "NOW=%%T"
set "COMMIT_MESSAGE=Update RMUTP Senior Project %NOW%"

:commit_ready
echo [STEP] Creating commit...
echo [INFO] %COMMIT_MESSAGE%
git.exe commit -m "%COMMIT_MESSAGE%"
if errorlevel 1 goto fail
echo [OK] Commit created.

:push_changes
echo [STEP] Pushing to GitHub...
echo [INFO] Git Credential Manager may open a browser for GitHub sign-in.
git.exe push -u origin "%BRANCH%"
if errorlevel 1 (
    echo.
    echo [ERROR] GitHub push failed.
    echo If the remote already contains files, review them before combining histories.
    echo Remote: %REMOTE_URL%
    goto fail
)

echo.
echo ====================================================
echo GITHUB PUBLISH SUCCESS
echo ====================================================
echo Repository: %REMOTE_URL%
echo Branch    : %BRANCH%
echo.
if not defined RMUTP_LIVE_CORE if "%NO_PAUSE%"=="0" pause
exit /b 0

:fail
echo.
echo ====================================================
echo GITHUB PUBLISH FAILED
echo ====================================================
echo Read the error above and try again.
echo.
if not defined RMUTP_LIVE_CORE if "%NO_PAUSE%"=="0" pause
exit /b 1
