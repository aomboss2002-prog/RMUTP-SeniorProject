<?php
$menu = [
    ['advisor-dashboard', 'แดชบอร์ด', 'fa-gauge-high'],
    ['advisor-students', 'นักศึกษาของฉัน', 'fa-user-graduate'],
    ['advisor-proposal', 'ข้อเสนอโครงงาน', 'fa-file-signature'],
    ['advisor-draft', 'ฉบับร่าง', 'fa-file-lines'],
    ['advisor-complete', 'ฉบับสมบูรณ์', 'fa-circle-check'],
    ['advisor-messages', 'ข้อความ', 'fa-envelope'],
    ['advisor-notifications', 'การแจ้งเตือน', 'fa-bell'],
    ['advisor-calendar', 'ปฏิทิน', 'fa-calendar-days'],
    ['advisor-reports', 'รายงาน', 'fa-chart-pie'],
    ['advisor-profile', 'โปรไฟล์', 'fa-user-gear'],
];
?>
<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(route_url('advisor-dashboard')) ?>">
        <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP">
        <span>พอร์ทัลอาจารย์</span>
    </a>
    <nav class="sidebar-nav">
        <?php foreach ($menu as [$route, $label, $icon]): ?>
            <?php if ($route === 'advisor-dashboard'): ?><span class="sidebar-section-title">ภาพรวม</span><?php endif; ?>
            <?php if ($route === 'advisor-students'): ?><span class="sidebar-section-title">โครงงานและนักศึกษา</span><?php endif; ?>
            <?php if ($route === 'advisor-messages'): ?><span class="sidebar-section-title">การสื่อสาร</span><?php endif; ?>
            <?php if ($route === 'advisor-profile'): ?><span class="sidebar-section-title">บัญชีผู้ใช้</span><?php endif; ?>
            <a class="sidebar-link <?= $page === $route ? 'active' : '' ?>" href="<?= e(route_url($route)) ?>">
                <i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"><span class="status-dot"></span><span>พร้อมใช้งาน</span></div>
</aside>
