<?php require __DIR__ . '/components/header.php'; ?>
<body data-page="<?= e($page) ?>" class="app-body">
<?php require __DIR__ . '/components/loader.php'; ?>
<div class="app-shell">
    <?php
    $isPortal = substr($page, 0, 7) === 'portal-';
    $isAdvisor = substr($page, 0, 8) === 'advisor-';
    require $isAdvisor ? __DIR__ . '/components/advisor-sidebar.php' : ($isPortal ? __DIR__ . '/components/portal-sidebar.php' : __DIR__ . '/components/sidebar.php');
    ?>
    <div class="app-main">
        <?php require $isAdvisor ? __DIR__ . '/components/advisor-navbar.php' : ($isPortal ? __DIR__ . '/components/portal-navbar.php' : __DIR__ . '/components/navbar.php'); ?>
        <main class="content-area">
            <?php
            if (!is_array($meta ?? null)) { $meta = ['file' => '500.php', 'title' => '500 ระบบขัดข้อง']; }
            $file = page_file($meta);
            if (file_exists($file)) {
                require $file;
            } else {
                require __DIR__ . '/pages/500.php';
            }
            ?>
        </main>
        <?php require __DIR__ . '/components/footer.php'; ?>
    </div>
</div>
<?php require __DIR__ . '/components/modal.php'; ?>
</body>
</html>

