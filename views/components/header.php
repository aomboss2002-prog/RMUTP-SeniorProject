<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="app-base-url" content="<?= e(app_base_url()) ?>">
    <title><?= e($meta['title'] ?? 'ระบบจัดการโครงงาน RMUTP') ?> | ระบบจัดการโครงงาน RMUTP</title>
    <link rel="icon" type="image/png" sizes="any" href="<?= e(asset_url('img/rmutp-logo.png')) ?>">
    <link href="<?= e(asset_url('vendor/bootstrap/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('vendor/datatables/dataTables.bootstrap5.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('vendor/datatables/responsive.bootstrap5.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('vendor/datatables/buttons.bootstrap5.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('vendor/fontawesome/css/all.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(versioned_asset_url('css/theme.css')) ?>" rel="stylesheet">
    <link href="<?= e(versioned_asset_url('css/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(versioned_asset_url('css/responsive.css')) ?>" rel="stylesheet">
    <?php if (($page ?? '') === 'login'): ?>
        <link href="<?= e(versioned_asset_url('css/public-catalog.css')) ?>" rel="stylesheet">
    <?php endif; ?>
</head>
