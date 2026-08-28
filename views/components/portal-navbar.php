<header class="top-navbar">
    <button class="icon-btn" id="sidebarToggle" aria-label="ย่อหรือขยายเมนู" title="ย่อหรือขยายเมนู">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="navbar-title">
        <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP Logo">
        <div>
            <strong>นักศึกษา</strong>
            <span><?= e($meta['title'] ?? 'แดชบอร์ดนักศึกษา') ?></span>
        </div>
    </div>
    <div class="navbar-actions">
        <button class="icon-btn position-relative" id="portalNotificationBell" aria-label="การแจ้งเตือน">
            <i class="fa-solid fa-bell"></i>
            <span class="notification-counter" id="portalNotificationCounter">0</span>
        </button>
        <a class="profile-chip" href="<?= e(route_url('portal-profile')) ?>">
            <span id="portalNavbarName">นักศึกษา</span>
        </a>
        <button class="btn btn-outline-primary btn-sm" id="logoutBtn">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>ออกจากระบบ</span>
        </button>
    </div>
</header>
