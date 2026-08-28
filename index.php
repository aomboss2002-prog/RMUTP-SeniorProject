<?php
require_once __DIR__ . '/app/store.php';
require_once __DIR__ . '/app/session.php';
require_once __DIR__ . '/app/storage.php';
require_once __DIR__ . '/app/helpers.php';

$requestedPage = $_GET['page'] ?? 'dashboard';
$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isPublicLoginRequest = in_array($requestedPage, ['login', 'advisor-login'], true)
    && in_array($requestMethod, ['GET', 'HEAD'], true);
if (!$isPublicLoginRequest) {
    start_app_session();
}

if (!defined('APP_ROUTED_ENTRY')) {
    if (isset($_GET['page'])) {
        http_response_code(404);
        exit('Legacy page routes are no longer available.');
    }
    header('Location: ' . route_url('login'));
    exit;
}

if ($requestedPage === 'advisor-logout') {
    unset(
        $_SESSION['app_user'],
        $_SESSION['advisor_user'],
        $_SESSION['remember_me'],
        $_SESSION['csrf_token']
    );
    session_regenerate_id(true);
    set_remembered_session(false);
    header('Location: ' . route_url('login'));
    exit;
}
if ($requestedPage === 'advisor-login') {
    header('Location: ' . route_url('login'));
    exit;
}

$pages = app_pages();
$portalPages = [
    'portal-dashboard' => ['title' => 'แดชบอร์ดนักศึกษา', 'file' => 'portal-dashboard.php', 'icon' => 'fa-gauge-high'],
    'portal-profile' => ['title' => 'โปรไฟล์นักศึกษา', 'file' => 'portal-profile.php', 'icon' => 'fa-user'],
    'portal-project' => ['title' => 'โครงงานของฉัน', 'file' => 'portal-project.php', 'icon' => 'fa-diagram-project'],
    'portal-proposal' => ['title' => 'ข้อเสนอโครงงาน', 'file' => 'portal-stage.php', 'icon' => 'fa-file-signature', 'stage' => 'proposal'],
    'portal-draft' => ['title' => 'ฉบับร่าง', 'file' => 'portal-stage.php', 'icon' => 'fa-file-lines', 'stage' => 'draft'],
    'portal-complete' => ['title' => 'ฉบับสมบูรณ์', 'file' => 'portal-stage.php', 'icon' => 'fa-circle-check', 'stage' => 'complete'],
    'portal-barcode' => ['title' => 'บาร์โค้ด', 'file' => 'portal-barcode.php', 'icon' => 'fa-barcode'],
    'portal-timeline' => ['title' => 'ไทม์ไลน์', 'file' => 'portal-timeline.php', 'icon' => 'fa-timeline'],
    'portal-notifications' => ['title' => 'การแจ้งเตือน', 'file' => 'portal-notifications.php', 'icon' => 'fa-bell'],
    'portal-documents' => ['title' => 'เอกสาร', 'file' => 'portal-documents.php', 'icon' => 'fa-folder-open'],
    'portal-messages' => ['title' => 'ข้อความ', 'file' => 'portal-messages.php', 'icon' => 'fa-envelope'],
    'portal-status' => ['title' => 'สถานะ', 'file' => 'portal-status.php', 'icon' => 'fa-list-check'],
    'portal-change-password' => ['title' => 'เปลี่ยนรหัสผ่าน', 'file' => 'portal-change-password.php', 'icon' => 'fa-key'],
    'portal-forgot-password' => ['title' => 'ลืมรหัสผ่าน', 'file' => 'portal-forgot-password.php', 'icon' => 'fa-unlock-keyhole'],
];
$advisorPages = [
    'advisor-login' => ['title' => 'เข้าสู่ระบบอาจารย์', 'file' => 'advisor-login.php', 'icon' => 'fa-right-to-bracket'],
    'advisor-dashboard' => ['title' => 'แดชบอร์ดอาจารย์', 'file' => 'advisor-dashboard.php', 'icon' => 'fa-gauge-high'],
    'advisor-students' => ['title' => 'นักศึกษาของฉัน', 'file' => 'advisor-students.php', 'icon' => 'fa-user-graduate'],
    'advisor-student-detail' => ['title' => 'รายละเอียดนักศึกษา', 'file' => 'advisor-student-detail.php', 'icon' => 'fa-id-card'],
    'advisor-proposal' => ['title' => 'พิจารณาข้อเสนอโครงงาน', 'file' => 'advisor-stage.php', 'icon' => 'fa-file-signature', 'stage' => 'proposal'],
    'advisor-draft' => ['title' => 'พิจารณาฉบับร่าง', 'file' => 'advisor-stage.php', 'icon' => 'fa-file-lines', 'stage' => 'draft'],
    'advisor-complete' => ['title' => 'พิจารณาฉบับสมบูรณ์', 'file' => 'advisor-stage.php', 'icon' => 'fa-circle-check', 'stage' => 'complete'],
    'advisor-messages' => ['title' => 'ข้อความ', 'file' => 'advisor-messages.php', 'icon' => 'fa-envelope'],
    'advisor-notifications' => ['title' => 'การแจ้งเตือน', 'file' => 'advisor-notifications.php', 'icon' => 'fa-bell'],
    'advisor-calendar' => ['title' => 'ปฏิทิน', 'file' => 'advisor-calendar.php', 'icon' => 'fa-calendar-days'],
    'advisor-profile' => ['title' => 'โปรไฟล์อาจารย์', 'file' => 'advisor-profile.php', 'icon' => 'fa-user-gear'],
    'advisor-reports' => ['title' => 'รายงานอาจารย์', 'file' => 'advisor-reports.php', 'icon' => 'fa-chart-pie'],
];
$pages = array_merge($pages, $portalPages, $advisorPages);
$page = array_key_exists($requestedPage, $pages) ? $requestedPage : '404';
$meta = $pages[$page];

if ($page === '404') {
    http_response_code(404);
}

if ($page === '403') {
    http_response_code(403);
}

if ($page === '401') {
    http_response_code(401);
}

if ($page === '422') {
    http_response_code(422);
}

if ($page === '500') {
    http_response_code(500);
}

if ($page === 'advisor-login') {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $appData = load_data();
        $advisorAccount = null;
        foreach ($appData['advisors'] ?? [] as $advisor) {
            if (strtolower((string) ($advisor['email'] ?? '')) === strtolower($email)
                && !empty($advisor['password_hash'])
                && secure_password_verify($password, (string) $advisor['password_hash'])) {
                $advisorAccount = $advisor;
                break;
            }
        }
        if ($advisorAccount) {
            session_regenerate_id(true);
            $_SESSION['advisor_user'] = [
                'id' => $advisorAccount['id'],
                'name' => $advisorAccount['name'],
                'email' => $advisorAccount['email'],
            ];
            header('Location: ' . route_url('advisor-dashboard'));
            exit;
        }
        $loginError = 'บัญชีอาจารย์หรือรหัสผ่านไม่ถูกต้อง';
    }
    require __DIR__ . '/views/pages/advisor-login.php';
    exit;
}
if ($page === 'login') {
    require __DIR__ . '/views/pages/login.php';
    exit;
}

if (str_starts_with($page, 'portal-') && ($_SESSION['app_user']['role'] ?? '') !== 'student') {
    header('Location: ' . route_url('login'));
    exit;
}

$isAdminPage = !str_starts_with($page, 'portal-')
    && !str_starts_with($page, 'advisor-')
    && !in_array($page, ['login', '404', '403', '500'], true);
if ($isAdminPage && ($_SESSION['app_user']['role'] ?? '') !== 'admin') {
    header('Location: ' . route_url('login'));
    exit;
}

if (str_starts_with($page, 'advisor-') && $page !== 'advisor-login' && empty($_SESSION['advisor_user'])) {
    header('Location: ' . route_url('login'));
    exit;
}

require __DIR__ . '/views/layout.php';
