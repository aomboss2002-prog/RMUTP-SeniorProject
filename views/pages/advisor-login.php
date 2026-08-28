<?php require __DIR__ . '/../components/header.php'; ?>
<body data-page="advisor-login" class="login-body">
<main class="login-screen">
    <section class="login-panel">
        <div class="login-brand">
            <img src="<?= e(asset_url('img/rmutp-logo.png')) ?>" alt="RMUTP">
            <div><p class="eyebrow mb-1">Advisor Portal</p><h1>เข้าสู่ระบบอาจารย์ที่ปรึกษา</h1></div>
        </div>
        <form id="advisorLoginForm" class="login-card" method="post" action="<?= e(route_url('advisor-login')) ?>">
            <?php if (!empty($loginError)): ?>
                <div class="alert alert-danger"><?= e($loginError) ?></div>
            <?php endif; ?>
            <div class="mb-3"><label class="form-label">บัญชีมหาวิทยาลัย</label><input class="form-control" name="email" type="email" value="advisor@rmutp.ac.th" required></div>
            <div class="mb-3"><label class="form-label">รหัสผ่าน</label><input class="form-control" name="password" type="password" autocomplete="current-password" required></div>
            <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-right-to-bracket"></i><span>เข้าสู่ระบบ</span></button>
        </form>
    </section>
</main>
<script src="<?= e(asset_url('vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(asset_url('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset_url('js/app.js')) ?>"></script>
<script src="<?= e(asset_url('js/advisor.js')) ?>"></script>
</body>
</html>
