<?php

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function app_base_url(): string
{
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $projectRoot = realpath(__DIR__ . '/..');
    if ($documentRoot && $projectRoot && str_starts_with(strtolower($projectRoot), strtolower($documentRoot))) {
        $relative = str_replace('\\', '/', substr($projectRoot, strlen($documentRoot)));
        $relative = trim($relative, '/');
        return $relative === '' ? '/' : '/' . $relative . '/';
    }
    return '/';
}

function route_url(string $page, array $params = []): string
{
    $routes = [
        'login' => 'login.php',
        'dashboard' => 'admin/dashboard.php', 'students' => 'admin/students/index.php',
        'student-add' => 'admin/students/add.php', 'student-detail' => 'admin/students/detail.php',
        'student-edit' => 'admin/students/edit.php', 'advisors' => 'admin/advisors/index.php',
        'reports' => 'admin/reports/index.php', 'settings' => 'admin/settings/index.php',
        'advisor-login' => 'login.php', 'advisor-dashboard' => 'advisor/dashboard.php',
        'advisor-students' => 'advisor/students.php', 'advisor-student-detail' => 'advisor/student-detail.php',
        'advisor-proposal' => 'advisor/review.php?stage=proposal', 'advisor-draft' => 'advisor/review.php?stage=draft',
        'advisor-complete' => 'advisor/review.php?stage=complete', 'advisor-messages' => 'advisor/messages.php',
        'advisor-notifications' => 'advisor/notifications.php', 'advisor-calendar' => 'advisor/calendar.php',
        'advisor-profile' => 'advisor/profile.php', 'advisor-reports' => 'advisor/reports.php',
        'advisor-logout' => 'advisor/logout.php',
        'portal-dashboard' => 'student/dashboard.php', 'portal-project' => 'student/project.php',
        'portal-proposal' => 'student/proposal.php', 'portal-draft' => 'student/draft.php',
        'portal-complete' => 'student/complete.php', 'portal-timeline' => 'student/timeline.php',
        'portal-messages' => 'student/messages.php', 'portal-notifications' => 'student/notifications.php',
        'portal-profile' => 'student/profile.php', 'portal-documents' => 'student/documents.php',
        'portal-status' => 'student/status.php', 'portal-barcode' => 'student/barcode.php',
        'portal-change-password' => 'student/change-password.php',
        'portal-forgot-password' => 'student/forgot-password.php',
        'projects' => 'admin/page.php?view=projects', 'documents' => 'admin/page.php?view=documents',
        'notifications' => 'admin/page.php?view=notifications', 'profile' => 'admin/page.php?view=profile',
        'barcode' => 'admin/page.php?view=barcode', 'timeline' => 'admin/page.php?view=timeline',
        'proposal' => 'admin/page.php?view=proposal', 'draft' => 'admin/page.php?view=draft',
        'complete' => 'admin/page.php?view=complete', 'import-excel' => 'admin/page.php?view=import-excel',
        'export-excel' => 'admin/page.php?view=export-excel',
    ];
    $target = $routes[$page] ?? '404.php';
    if ($params) $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($params);
    return app_base_url() . $target;
}

function asset_url(string $path): string
{
    return app_base_url() . 'assets/' . ltrim($path, '/');
}

function versioned_asset_url(string $path): string
{
    $relativePath = ltrim($path, '/');
    $file = __DIR__ . '/../assets/' . $relativePath;
    $version = is_file($file) ? substr((string) sha1_file($file), 0, 12) : '1';
    return asset_url($relativePath) . '?v=' . rawurlencode($version);
}

function page_uses_datatables(string $page): bool
{
    return in_array($page, [
        'dashboard', 'students', 'student-detail', 'advisors', 'projects',
        'documents', 'proposal', 'draft', 'complete', 'reports', 'notifications',
        'import-excel', 'portal-documents', 'portal-notifications',
        'advisor-dashboard', 'advisor-students', 'advisor-proposal',
        'advisor-draft', 'advisor-complete', 'advisor-notifications',
        'advisor-reports',
    ], true);
}

function page_uses_charts(string $page): bool
{
    return in_array($page, [
        'dashboard', 'reports', 'portal-project',
        'advisor-dashboard', 'advisor-reports',
    ], true);
}

function page_uses_blob_upload(string $page): bool
{
    return in_array($page, [
        'portal-profile', 'portal-proposal', 'portal-draft', 'portal-complete',
    ], true);
}

function app_pages(): array
{
    return [
        '401' => ['title' => 'Unauthorized', 'file' => '401.php', 'icon' => 'fa-lock'],
        '422' => ['title' => 'Unprocessable request', 'file' => '422.php', 'icon' => 'fa-triangle-exclamation'],
        'login' => ['title' => 'เข้าสู่ระบบ', 'file' => 'login.php', 'icon' => 'fa-right-to-bracket'],
        'dashboard' => ['title' => 'แดชบอร์ด', 'file' => 'dashboard.php', 'icon' => 'fa-gauge-high'],
        'students' => ['title' => 'รายชื่อนักศึกษา', 'file' => 'students.php', 'icon' => 'fa-user-graduate'],
        'student-detail' => ['title' => 'รายละเอียดนักศึกษา', 'file' => 'student-detail.php', 'icon' => 'fa-id-card'],
        'student-add' => ['title' => 'เพิ่มนักศึกษา', 'file' => 'student-add.php', 'icon' => 'fa-user-plus'],
        'student-edit' => ['title' => 'แก้ไขนักศึกษา', 'file' => 'student-edit.php', 'icon' => 'fa-user-pen'],
        'advisors' => ['title' => 'รายชื่ออาจารย์ที่ปรึกษา', 'file' => 'advisors.php', 'icon' => 'fa-chalkboard-user'],
        'projects' => ['title' => 'โครงงานสหกิจ/ปริญญานิพนธ์', 'file' => 'projects.php', 'icon' => 'fa-diagram-project'],
        'documents' => ['title' => 'เอกสาร', 'file' => 'documents.php', 'icon' => 'fa-folder-open'],
        'proposal' => ['title' => 'ข้อเสนอโครงงาน', 'file' => 'proposal.php', 'icon' => 'fa-file-signature'],
        'draft' => ['title' => 'ฉบับร่าง', 'file' => 'draft.php', 'icon' => 'fa-file-lines'],
        'complete' => ['title' => 'ฉบับสมบูรณ์', 'file' => 'complete.php', 'icon' => 'fa-circle-check'],
        'barcode' => ['title' => 'Barcode', 'file' => 'barcode.php', 'icon' => 'fa-barcode'],
        'timeline' => ['title' => 'ไทม์ไลน์', 'file' => 'timeline.php', 'icon' => 'fa-timeline'],
        'reports' => ['title' => 'รายงาน', 'file' => 'reports.php', 'icon' => 'fa-chart-column'],
        'import-excel' => ['title' => 'นำเข้า Excel', 'file' => 'import-excel.php', 'icon' => 'fa-file-import'],
        'export-excel' => ['title' => 'ส่งออก Excel', 'file' => 'export-excel.php', 'icon' => 'fa-file-export'],
        'notifications' => ['title' => 'การแจ้งเตือน', 'file' => 'notifications.php', 'icon' => 'fa-bell'],
        'profile' => ['title' => 'โปรไฟล์', 'file' => 'profile.php', 'icon' => 'fa-user'],
        'settings' => ['title' => 'ตั้งค่า', 'file' => 'settings.php', 'icon' => 'fa-gear'],
        '404' => ['title' => '404 ไม่พบหน้า', 'file' => '404.php', 'icon' => 'fa-triangle-exclamation'],
        '403' => ['title' => '403 ไม่มีสิทธิ์เข้าถึง', 'file' => '403.php', 'icon' => 'fa-lock'],
        '500' => ['title' => '500 ระบบขัดข้อง', 'file' => '500.php', 'icon' => 'fa-bug'],
    ];
}

function sidebar_items(): array
{
    return [
        'dashboard' => ['label' => 'แดชบอร์ด', 'icon' => 'fa-gauge-high'],
        'students' => ['label' => 'นักศึกษา', 'icon' => 'fa-user-graduate'],
        'advisors' => ['label' => 'อาจารย์ที่ปรึกษา', 'icon' => 'fa-chalkboard-user'],
        'projects' => ['label' => 'โครงงาน', 'icon' => 'fa-diagram-project'],
        'documents' => ['label' => 'เอกสาร', 'icon' => 'fa-folder-open'],
        'reports' => ['label' => 'รายงาน', 'icon' => 'fa-chart-column'],
        'notifications' => ['label' => 'การแจ้งเตือน', 'icon' => 'fa-bell'],
        'settings' => ['label' => 'ตั้งค่า', 'icon' => 'fa-gear'],
    ];
}

function page_file(array $meta): string
{
    return __DIR__ . '/../views/pages/' . $meta['file'];
}

function page_header(string $title, string $subtitle, array $actions = []): void
{
    ?>
    <div class="page-heading">
        <div>
            <p class="eyebrow mb-1">ระบบจัดการโครงงาน RMUTP</p>
            <h1><?= e($title) ?></h1>
            <p class="text-muted mb-0"><?= e($subtitle) ?></p>
        </div>
        <?php if ($actions): ?>
            <div class="page-actions">
                <?php foreach ($actions as $action): ?>
                    <a class="btn <?= e($action['class'] ?? 'btn-primary') ?>" href="<?= e($action['href'] ?? '#') ?>" <?= isset($action['attr']) ? $action['attr'] : '' ?>>
                        <i class="fa-solid <?= e($action['icon'] ?? 'fa-arrow-right') ?>"></i>
                        <span><?= e($action['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function render_status_badge(string $status): string
{
    $labels = [
        'Approved' => 'อนุมัติแล้ว',
        'Completed' => 'เสร็จสมบูรณ์',
        'Active' => 'ใช้งานอยู่',
        'Pending' => 'รอดำเนินการ',
        'Review' => 'รอตรวจสอบ',
        'Draft' => 'ฉบับร่าง',
        'Rejected' => 'ไม่อนุมัติ',
        'Inactive' => 'ปิดใช้งาน',
    ];
    $classes = [
        'Approved' => 'success',
        'Completed' => 'success',
        'Active' => 'success',
        'Pending' => 'warning',
        'Review' => 'info',
        'Draft' => 'secondary',
        'Rejected' => 'danger',
        'Inactive' => 'danger',
    ];
    $class = $classes[$status] ?? 'primary';
    return '<span class="badge rounded-pill text-bg-' . $class . '">' . e($labels[$status] ?? $status) . '</span>';
}
