<header class="top-navbar">
    <button class="icon-btn" id="sidebarToggle" type="button" aria-label="ย่อหรือขยายเมนู" title="ย่อหรือขยายเมนู">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="navbar-title">
        <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP">
        <div>
            <strong>อาจารย์</strong>
            <span><?= e($meta['title'] ?? 'แดชบอร์ดอาจารย์') ?></span>
        </div>
    </div>
    <div class="navbar-actions">
        <button class="icon-btn position-relative" id="advisorNotificationBell" type="button" aria-label="การแจ้งเตือน">
            <i class="fa-solid fa-bell"></i>
            <span class="notification-counter" id="advisorNotificationCounter">0</span>
        </button>
        <a class="profile-chip" href="<?= e(route_url('advisor-profile')) ?>">
            <span id="advisorNavbarName">อาจารย์</span>
        </a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e(route_url('advisor-logout')) ?>">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>ออกจากระบบ</span>
        </a>
    </div>
</header>
