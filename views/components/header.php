<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $pageCsrfToken = (($page ?? '') === 'login') ? bin2hex(random_bytes(32)) : csrf_token(); ?>
    <meta name="csrf-token" content="<?= e($pageCsrfToken) ?>">
    <meta name="app-base-url" content="<?= e(app_base_url()) ?>">
    <meta name="storage-driver" content="<?= e(function_exists('storage_driver') ? storage_driver() : 'local') ?>">
    <meta name="blob-path-prefix" content="<?= e(function_exists('storage_blob_prefix') ? storage_blob_prefix() : 'rmutp') ?>">
    <title><?= e($meta['title'] ?? 'ระบบจัดการโครงงาน RMUTP') ?> | ระบบจัดการโครงงาน RMUTP</title>
    <link rel="icon" type="image/png" sizes="any" href="<?= e(asset_url('img/rmutp-logo.png')) ?>">
    <link href="<?= e(versioned_asset_url('vendor/bootstrap/bootstrap.min.css')) ?>" rel="stylesheet">
    <?php if (page_uses_datatables((string) ($page ?? ''))): ?>
        <link href="<?= e(versioned_asset_url('vendor/datatables/dataTables.bootstrap5.min.css')) ?>" rel="stylesheet">
        <link href="<?= e(versioned_asset_url('vendor/datatables/responsive.bootstrap5.min.css')) ?>" rel="stylesheet">
        <link href="<?= e(versioned_asset_url('vendor/datatables/buttons.bootstrap5.min.css')) ?>" rel="stylesheet">
    <?php endif; ?>
    <link href="<?= e(versioned_asset_url('vendor/fontawesome/css/all.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(versioned_asset_url('css/theme.css')) ?>" rel="stylesheet">
    <link href="<?= e(versioned_asset_url('css/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(versioned_asset_url('css/responsive.css')) ?>" rel="stylesheet">
    <?php if (($page ?? '') === 'login'): ?>
        <link href="<?= e(versioned_asset_url('css/public-catalog.css')) ?>" rel="stylesheet">
    <?php endif; ?>
</head>
