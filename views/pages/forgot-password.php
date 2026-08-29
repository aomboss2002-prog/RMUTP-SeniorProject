<?php require __DIR__ . '/../components/header.php'; ?>
<body data-page="forgot-password" class="public-catalog-body password-recovery-body">
<main class="password-recovery-shell">
    <section class="password-recovery-card" aria-labelledby="forgotPasswordHeading">
        <a class="password-recovery-brand" href="<?= e(route_url('login')) ?>">
            <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP">
            <span>RMUTP Senior Project</span>
        </a>
        <span class="password-recovery-icon"><i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i></span>
        <p class="section-kicker">PASSWORD RECOVERY</p>
        <h1 id="forgotPasswordHeading">ลืมรหัสผ่าน</h1>
        <p class="password-recovery-copy">กรอกอีเมลของบัญชีนักศึกษาหรืออาจารย์ ระบบจะส่งลิงก์ตั้งรหัสผ่านใหม่ที่ใช้งานได้ 15 นาที</p>

        <form id="forgotPasswordForm" class="password-recovery-form">
            <input type="hidden" name="action" value="request">
            <label for="resetEmail">อีเมล</label>
            <div class="login-input">
                <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                <input id="resetEmail" name="email" type="email" autocomplete="email" required placeholder="name@rmutp.ac.th">
            </div>
            <button class="public-login-submit" type="submit">
                <span>ส่งลิงก์ตั้งรหัสผ่านใหม่</span><i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
            </button>
        </form>
        <div id="passwordResetMessage" class="password-recovery-message" role="status" aria-live="polite" hidden></div>
        <a class="password-recovery-back" href="<?= e(route_url('login')) ?>"><i class="fa-solid fa-arrow-left"></i> กลับไปหน้าเข้าสู่ระบบ</a>
    </section>
</main>
<script defer src="<?= e(versioned_asset_url('js/password-reset.js')) ?>"></script>
</body>
</html>
