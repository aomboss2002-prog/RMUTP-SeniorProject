<?php require __DIR__ . '/../components/header.php'; ?>
<body data-page="login" class="public-catalog-body">
<header class="archive-masthead">
    <div class="archive-grid-pattern" aria-hidden="true"></div>
    <div class="archive-masthead__inner">
        <div class="archive-brand">
            <span class="archive-brand__mark">
                <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="ตราสัญลักษณ์มหาวิทยาลัยเทคโนโลยีราชมงคลพระนคร">
            </span>
            <div>
                <p class="archive-brand__eyebrow">RMUTP DIGITAL ARCHIVE</p>
                <p class="archive-brand__name">ระบบบริหารจัดการวิทยานิพนธ์และงานวิจัย</p>
                <p class="archive-brand__university">มหาวิทยาลัยเทคโนโลยีราชมงคลพระนคร</p>
            </div>
        </div>
        <div class="archive-trust">
            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
            <span>คลังผลงานวิชาการที่ผ่านการตรวจสอบแล้ว</span>
        </div>
    </div>

    <div class="archive-intro">
        <div class="archive-intro__copy">
            <span class="archive-intro__label"><i class="fa-solid fa-book-open" aria-hidden="true"></i> คลังความรู้สาธารณะ</span>
            <h1>วิทยานิพนธ์และงานวิจัย</h1>
            <p>สืบค้นและดาวน์โหลดผลงานโครงงานของนักศึกษา RMUTP เพื่อการศึกษา อ้างอิง และต่อยอดองค์ความรู้</p>
        </div>
        <div class="archive-stat" aria-label="จำนวนผลงานฉบับสมบูรณ์">
            <span class="archive-stat__number" id="publicCompletedCount">—</span>
            <span class="archive-stat__label">ผลงานฉบับสมบูรณ์</span>
            <span class="archive-stat__caption">Completed archive</span>
        </div>
    </div>
</header>

<main class="archive-shell">
    <div class="archive-layout">
        <section class="catalog-column" aria-labelledby="catalogHeading">
            <div class="catalog-heading">
                <div>
                    <p class="section-kicker">EXPLORE THE ARCHIVE</p>
                    <h2 id="catalogHeading">ค้นหาผลงานวิชาการ</h2>
                </div>
                <p class="catalog-heading__hint">ค้นหาจากชื่อเรื่อง ผู้จัดทำ หรือรหัสโครงการ</p>
            </div>

            <form class="catalog-filters" id="publicCatalogFilters" role="search">
                <div class="catalog-search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <label class="visually-hidden" for="catalogSearch">ค้นหาผลงาน</label>
                    <input id="catalogSearch" name="q" type="search" autocomplete="off"
                           placeholder="ค้นหาชื่อเรื่อง / ผู้จัดทำ / รหัสโครงการ...">
                    <button type="button" class="catalog-search__clear" id="clearCatalogSearch" aria-label="ล้างคำค้นหา" hidden>
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="catalog-filter-grid">
                    <div class="filter-field">
                        <label for="catalogYear">ปีการศึกษา</label>
                        <div class="filter-select">
                            <select id="catalogYear" name="year"><option value="">ทุกปีการศึกษา</option></select>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="filter-field">
                        <label for="catalogFaculty">คณะ</label>
                        <div class="filter-select">
                            <select id="catalogFaculty" name="faculty"><option value="">ทุกคณะ</option></select>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="filter-field">
                        <label for="catalogMajor">สาขาวิชา</label>
                        <div class="filter-select">
                            <select id="catalogMajor" name="major"><option value="">ทุกสาขาวิชา</option></select>
                            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                        </div>
                    </div>
                </div>
            </form>

            <div class="catalog-results-bar">
                <p id="catalogResultSummary" aria-live="polite">กำลังโหลดรายการผลงาน...</p>
                <button type="button" class="catalog-reset" id="resetCatalogFilters">
                    <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> ล้างตัวกรอง
                </button>
            </div>

            <div class="thesis-list" id="publicCatalogResults" aria-live="polite" aria-busy="true"></div>
            <nav class="catalog-pagination" id="publicCatalogPagination" aria-label="หน้ารายการผลงาน"></nav>
        </section>

        <aside class="login-column" aria-labelledby="loginHeading">
            <div class="public-login-card">
                <div class="public-login-card__head">
                    <span class="public-login-card__icon"><i class="fa-solid fa-user-lock" aria-hidden="true"></i></span>
                    <div>
                        <p class="section-kicker">MEMBER ACCESS</p>
                        <h2 id="loginHeading">เข้าสู่ระบบ</h2>
                    </div>
                </div>
                <p class="public-login-card__intro">สำหรับนักศึกษา อาจารย์ และผู้ดูแลระบบ</p>

                <form id="loginForm" class="public-login-form" autocomplete="on">
                    <div class="login-field">
                        <label for="loginEmail">อีเมลหรือชื่อผู้ใช้</label>
                        <div class="login-input">
                            <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                            <input id="loginEmail" name="email" type="text" autocomplete="username" required
                                   placeholder="name@rmutp.ac.th">
                        </div>
                    </div>
                    <div class="login-field">
                        <label for="loginPassword">รหัสผ่าน</label>
                        <div class="login-input">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i>
                            <input id="loginPassword" name="password" type="password" autocomplete="current-password" required
                                   placeholder="กรอกรหัสผ่าน">
                            <button type="button" class="password-toggle" id="toggleLoginPassword" aria-label="แสดงรหัสผ่าน" aria-pressed="false">
                                <i class="fa-regular fa-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="login-options">
                        <label class="remember-option" for="loginRemember">
                            <input id="loginRemember" name="remember" type="checkbox" value="1">
                            <span>จดจำการเข้าสู่ระบบ</span>
                        </label>
                    </div>
                    <button class="public-login-submit" type="submit">
                        <span>เข้าสู่ระบบ</span>
                        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <div class="advisor-entry" hidden>
                    <span>สำหรับอาจารย์ที่ปรึกษา</span>
                    <a href="<?= e(route_url('advisor-login')) ?>">
                        เข้าสู่พอร์ทัลอาจารย์ <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                    </a>
                </div>
                <p class="login-security"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> การเข้าสู่ระบบได้รับการปกป้องด้วยมาตรการความปลอดภัย</p>
            </div>
        </aside>
    </div>
</main>

<footer class="public-footer">
    <div>
        <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="" aria-hidden="true">
        <span>มหาวิทยาลัยเทคโนโลยีราชมงคลพระนคร</span>
    </div>
    <p>คลังโครงงานฉบับสมบูรณ์ • เพื่อการศึกษาและการอ้างอิง</p>
</footer>

<div class="app-loader" id="appLoader" aria-hidden="true"><div class="spinner-border text-light" role="status"><span class="visually-hidden">กำลังเข้าสู่ระบบ</span></div></div>
<script defer src="<?= e(asset_url('vendor/jquery/jquery.min.js')) ?>"></script>
<script defer src="<?= e(asset_url('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script defer src="<?= e(versioned_asset_url('js/app.js')) ?>"></script>
<script defer src="<?= e(versioned_asset_url('js/public-catalog.js')) ?>"></script>
</body>
</html>
