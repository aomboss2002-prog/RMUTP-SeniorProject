<aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(route_url('dashboard')) ?>">
        <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP Logo">
        <span>RMUTP</span>
    </a>
    <nav class="sidebar-nav">
        <?php foreach (sidebar_items() as $key => $item): ?>
            <?php if ($key === 'dashboard'): ?><span class="sidebar-section-title">ภาพรวม</span><?php endif; ?>
            <?php if ($key === 'students'): ?><span class="sidebar-section-title">จัดการข้อมูล</span><?php endif; ?>
            <?php if ($key === 'notifications'): ?><span class="sidebar-section-title">ระบบ</span><?php endif; ?>
            <a class="sidebar-link <?= $page === $key ? 'active' : '' ?>" href="<?= e(route_url($key)) ?>">
                <i class="fa-solid <?= e($item['icon']) ?>"></i>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <span class="status-dot"></span>
        <span>Online</span>
    </div>
</aside>
