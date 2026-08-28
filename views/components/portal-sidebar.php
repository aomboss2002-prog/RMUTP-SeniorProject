<aside class="sidebar portal-sidebar" id="sidebar">
    <a class="brand" href="<?= e(route_url('portal-dashboard')) ?>">
        <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP Logo">
        <span>แถบเมนู</span>
    </a>
    <nav class="sidebar-nav">
        <?php
        $items = [
            'portal-dashboard' => ['label' => 'แดชบอร์ด', 'icon' => 'fa-gauge-high'],
            'portal-project' => ['label' => 'โครงงานของฉัน', 'icon' => 'fa-diagram-project'],
            'portal-proposal' => ['label' => 'ข้อเสนอโครงงาน', 'icon' => 'fa-file-signature'],
            'portal-draft' => ['label' => 'ฉบับร่าง', 'icon' => 'fa-file-lines'],
            'portal-complete' => ['label' => 'ฉบับสมบูรณ์', 'icon' => 'fa-circle-check'],
            'portal-barcode' => ['label' => 'บาร์โค้ด', 'icon' => 'fa-barcode'],
            'portal-timeline' => ['label' => 'ไทม์ไลน์', 'icon' => 'fa-timeline'],
            'portal-documents' => ['label' => 'เอกสาร', 'icon' => 'fa-folder-open'],
            'portal-messages' => ['label' => 'ข้อความ', 'icon' => 'fa-envelope'],
            'portal-notifications' => ['label' => 'การแจ้งเตือน', 'icon' => 'fa-bell'],
            'portal-status' => ['label' => 'สถานะ', 'icon' => 'fa-list-check'],
            'portal-profile' => ['label' => 'โปรไฟล์', 'icon' => 'fa-user'],
        ];
        foreach ($items as $key => $item):
        ?>
            <?php if ($key === 'portal-dashboard'): ?><span class="sidebar-section-title">ภาพรวม</span><?php endif; ?>
            <?php if ($key === 'portal-project'): ?><span class="sidebar-section-title">โครงงาน</span><?php endif; ?>
            <?php if ($key === 'portal-messages'): ?><span class="sidebar-section-title">การสื่อสาร</span><?php endif; ?>
            <?php if ($key === 'portal-profile'): ?><span class="sidebar-section-title">บัญชีผู้ใช้</span><?php endif; ?>
            <a class="sidebar-link <?= $page === $key ? 'active' : '' ?>" href="<?= e(route_url($key)) ?>">
                <i class="fa-solid <?= e($item['icon']) ?>"></i>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <span class="status-dot"></span>
        <span>สำหรับนักศึกษา</span>
    </div>
</aside>
