$ErrorActionPreference = 'Stop'
$base = 'http://localhost/RMUTP-SeniorProject'

function Assert-HealthyResponse {
    param($Response, [string]$Path)
    if ($Response.StatusCode -ne 200) { throw "$Path returned $($Response.StatusCode)" }
    if ($Response.Content -match 'Fatal error|Uncaught (Error|Exception)|Warning:.*\.php on line') {
        throw "$Path contains a PHP runtime error"
    }
}

$publicPages = @('/login.php', '/advisor/login.php')
foreach ($path in $publicPages) {
    $response = Invoke-WebRequest -Uri ($base + $path) -UseBasicParsing
    Assert-HealthyResponse $response $path
}

$adminPages = @(
    '/admin/dashboard.php', '/admin/students/index.php', '/admin/students/add.php',
    '/admin/advisors/index.php', '/admin/page.php?view=projects',
    '/admin/page.php?view=documents', '/admin/reports/index.php', '/admin/settings/index.php'
)
$studentPages = @(
    '/student/dashboard.php', '/student/project.php', '/student/proposal.php', '/student/draft.php',
    '/student/complete.php', '/student/barcode.php', '/student/timeline.php', '/student/documents.php',
    '/student/messages.php', '/student/notifications.php', '/student/status.php', '/student/profile.php'
)
$advisorPages = @(
    '/advisor/dashboard.php', '/advisor/students.php', '/advisor/review.php?stage=proposal',
    '/advisor/review.php?stage=draft', '/advisor/review.php?stage=complete', '/advisor/messages.php',
    '/advisor/notifications.php', '/advisor/calendar.php', '/advisor/reports.php', '/advisor/profile.php'
)

foreach ($path in ($adminPages + $studentPages + $advisorPages)) {
    $response = Invoke-WebRequest -Uri ($base + $path) -UseBasicParsing -MaximumRedirection 5
    if ($response.StatusCode -ne 200 -or $response.BaseResponse.ResponseUri.AbsolutePath -notmatch 'login.php$') {
        throw "$path did not redirect to a login page"
    }
    Assert-HealthyResponse $response $path
}

try {
    Invoke-WebRequest -Uri ($base + '/index.php?page=dashboard') -UseBasicParsing | Out-Null
    throw 'Legacy route was not rejected'
} catch {
    if ($_.Exception.Response -and [int]$_.Exception.Response.StatusCode -ne 404) { throw }
}

$envValues = @{}
foreach ($line in [IO.File]::ReadAllLines((Join-Path $PSScriptRoot '..\.env'))) {
    if ($line -match '^([^#=]+)=(.*)$') { $envValues[$matches[1].Trim()] = $matches[2].Trim().Trim('"').Trim("'") }
}
$adminEmail = if ($envValues.ADMIN_EMAIL) { $envValues.ADMIN_EMAIL } else { 'admin@rmutp.ac.th' }
$adminPassword = if ($envValues.ADMIN_PASSWORD) { $envValues.ADMIN_PASSWORD } else { 'admin123' }
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginPage = Invoke-WebRequest -Uri ($base + '/login.php') -WebSession $session -UseBasicParsing
$loginCacheControl = [string]$loginPage.Headers['Cache-Control']
if ($loginCacheControl -notmatch 'public' -or $loginCacheControl -notmatch 's-maxage=60') {
    throw 'Login page is missing the expected public edge cache policy'
}
if ([string]$loginPage.Headers['Set-Cookie'] -match 'PHPSESSID') {
    throw 'Public login page unexpectedly started a PHP database session'
}
if ($loginPage.Content -match 'vendor/datatables/(dataTables|responsive|buttons)') {
    throw 'Public login page unexpectedly loads DataTables assets'
}
$csrf = [regex]::Match($loginPage.Content, 'name="csrf-token" content="([^"]+)"').Groups[1].Value
if ($csrf.Length -ne 64) { throw 'Login page did not provide a valid CSRF token' }
$loginBody = @{ email = $adminEmail; password = $adminPassword } | ConvertTo-Json
$loginResponse = Invoke-RestMethod -Uri ($base + '/api/index.php?resource=auth&action=login') `
    -Method Post -WebSession $session -Headers @{ 'X-CSRF-Token' = $csrf } `
    -ContentType 'application/json' -Body $loginBody
if (-not $loginResponse.success -or $loginResponse.data.role -ne 'admin') { throw 'Admin login failed' }

foreach ($path in $adminPages) {
    $response = Invoke-WebRequest -Uri ($base + $path) -WebSession $session -UseBasicParsing
    Assert-HealthyResponse $response $path
}
foreach ($resource in @('dashboard', 'students', 'advisors', 'projects', 'documents', 'notifications', 'settings')) {
    $response = Invoke-RestMethod -Uri ($base + "/api/index.php?resource=$resource") -WebSession $session
    if (-not $response.success) { throw "Admin API failed: $resource" }
}

Write-Output 'HTTP_SMOKE_OK'
