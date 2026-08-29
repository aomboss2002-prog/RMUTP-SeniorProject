<?php
$resetToken = strtolower(trim((string) ($_GET['token'] ?? '')));
$validResetToken = preg_match('/^[a-f0-9]{64}$/', $resetToken) === 1;
require __DIR__ . '/../components/header.php';
?>
<body data-page="reset-password" class="public-catalog-body password-recovery-body">
<main class="password-recovery-shell">
    <section class="password-recovery-card" aria-labelledby="resetPasswordHeading">
        <a class="password-recovery-brand" href="<?= e(route_url('login')) ?>">
            <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP">
            <span>RMUTP Senior Project</span>
        </a>
        <span class="password-recovery-icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span>
        <p class="section-kicker">NEW PASSWORD</p>
        <h1 id="resetPasswordHeading">ตั้งรหัสผ่านใหม่</h1>
        <p class="password-recovery-copy">ลิงก์นี้ใช้ได้ครั้งเดียวและหมดอายุภายใน 15 นาทีหลังจากขอ</p>

        <?php if ($validResetToken): ?>
        <form id="resetPasswordForm" class="password-recovery-form">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="token" value="<?= e($resetToken) ?>">
            <label for="newPassword">รหัสผ่านใหม่</label>
            <div class="login-input">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                <input id="newPassword" name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="อย่างน้อย 8 ตัวอักษร">
            </div>
            <label for="confirmPassword">ยืนยันรหัสผ่านใหม่</label>
            <div class="login-input">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <input id="confirmPassword" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required placeholder="กรอกรหัสผ่านอีกครั้ง">
            </div>
            <button class="public-login-submit" type="submit">
                <span>บันทึกรหัสผ่านใหม่</span><i class="fa-solid fa-check" aria-hidden="true"></i>
            </button>
        </form>
        <?php else: ?>
        <div class="password-recovery-message is-error">ลิงก์ตั้งรหัสผ่านไม่ถูกต้อง กรุณาขอลิงก์ใหม่อีกครั้ง</div>
        <?php endif; ?>
        <div id="passwordResetMessage" class="password-recovery-message" role="status" aria-live="polite" hidden></div>
        <a class="password-recovery-back" href="<?= e(route_url($validResetToken ? 'login' : 'forgot-password')) ?>"><i class="fa-solid fa-arrow-left"></i> <?= $validResetToken ? 'กลับไปหน้าเข้าสู่ระบบ' : 'ขอลิงก์ใหม่' ?></a>
    </section>
</main>
<script defer src="<?= e(versioned_asset_url('js/password-reset.js')) ?>"></script>
</body>
</html>
