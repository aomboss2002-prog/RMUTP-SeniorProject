@echo off
setlocal EnableExtensions DisableDelayedExpansion

set "ROOT_DIR=%~dp0"
set "REMOTE_URL=https://github.com/aomboss2002-prog/RMUTP-SeniorProject.git"
set "BRANCH=main"
set "COMMIT_MESSAGE=%*"
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

where git >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Git was not found.
    echo Install Git for Windows from https://git-scm.com/download/win
    goto fail
)

for /f "delims=" %%V in ('git --version') do echo [OK] %%V

if not exist ".git\HEAD" (
    echo [STEP] Initializing Git repository...
    git init
    if errorlevel 1 goto fail
) else (
    echo [OK] Existing Git repository detected.
)

echo [STEP] Configuring branch and remote...
git branch -M "%BRANCH%"
if errorlevel 1 goto fail

git remote get-url origin >nul 2>nul
if errorlevel 1 (
    git remote add origin "%REMOTE_URL%"
) else (
    git remote set-url origin "%REMOTE_URL%"
)
if errorlevel 1 goto fail
echo [OK] origin = %REMOTE_URL%

for /f "delims=" %%N in ('git config user.name 2^>nul') do set "GIT_USER_NAME=%%N"
for /f "delims=" %%E in ('git config user.email 2^>nul') do set "GIT_USER_EMAIL=%%E"
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

echo [STEP] Staging project files...
git add -A
if errorlevel 1 goto fail

git diff --cached --quiet
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
git commit -m "%COMMIT_MESSAGE%"
if errorlevel 1 goto fail
echo [OK] Commit created.

:push_changes
echo [STEP] Pushing to GitHub...
echo [INFO] Git Credential Manager may open a browser for GitHub sign-in.
git push -u origin "%BRANCH%"
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
pause
exit /b 0

:fail
echo.
echo ====================================================
echo GITHUB PUBLISH FAILED
echo ====================================================
echo Read the error above and try again.
echo.
pause
exit /b 1
