<header class="top-navbar">
    <button class="icon-btn" id="sidebarToggle" aria-label="ย่อหรือขยายเมนู" title="ย่อหรือขยายเมนู">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="navbar-title">
        <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP Logo">
        <div>
            <strong>ผู้ดูแล</strong>
            <span><?= e($meta['title'] ?? 'แดชบอร์ด') ?></span>
        </div>
    </div>
    <div class="navbar-actions">
        <button class="icon-btn position-relative" id="notificationBell" aria-label="การแจ้งเตือน">
            <i class="fa-solid fa-bell"></i>
            <span class="notification-counter" id="notificationCounter">0</span>
        </button>
        <a class="profile-chip" href="<?= e(route_url('profile')) ?>">
            <span id="adminNavbarName">ผู้ดูแล</span>
        </a>
        <button class="btn btn-outline-primary btn-sm" id="logoutBtn">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>ออกจากระบบ</span>
        </button>
    </div>
</header>
