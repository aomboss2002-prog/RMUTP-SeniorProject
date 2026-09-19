<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';

function csrf_token(): string { return 'test-token'; }
function storage_driver(): string { return 'vercel_blob'; }
function storage_blob_prefix(): string { return 'rmutp'; }

function rendered_page_head(string $page): string
{
    $meta = ['title' => 'Resource test'];
    ob_start();
    require __DIR__ . '/../views/components/header.php';
    return (string) ob_get_clean();
}

function rendered_page_scripts(string $page): string
{
    ob_start();
    require __DIR__ . '/../views/components/footer.php';
    return (string) ob_get_clean();
}

function assert_assets(string $page, array $required, array $forbidden): void
{
    $html = rendered_page_scripts($page);
    foreach ($required as $asset) {
        if (!str_contains($html, $asset)) {
            fwrite(STDERR, "$page: missing required asset $asset" . PHP_EOL);
            exit(1);
        }
    }
    foreach ($forbidden as $asset) {
        if (str_contains($html, $asset)) {
            fwrite(STDERR, "$page: loaded unused asset $asset" . PHP_EOL);
            exit(1);
        }
    }
}

assert_assets(
    'dashboard',
    ['chart.umd.js', 'dashboard.js', 'notification.js'],
    ['bootstrap.bundle.min.js', 'jquery.dataTables.min.js', 'student.js']
);
assert_assets(
    'settings',
    ['student.js', 'notification.js'],
    ['bootstrap.bundle.min.js', 'jquery.dataTables.min.js', 'chart.umd.js']
);
assert_assets(
    'portal-documents',
    ['bootstrap.bundle.min.js', 'jquery.dataTables.min.js', 'portal.js'],
    ['student.js', 'advisor.js', 'chart.umd.js']
);
assert_assets(
    'advisor-dashboard',
    ['chart.umd.js', 'advisor.js'],
    ['bootstrap.bundle.min.js', 'jquery.dataTables.min.js', 'student.js']
);
assert_assets(
    'portal-proposal',
    ['portal.js'],
    ['vercel-blob-upload.js', 'chart.umd.js']
);

if (!str_contains(rendered_page_head('portal-proposal'), 'name="blob-upload-script"')
    || str_contains(rendered_page_head('portal-documents'), 'name="blob-upload-script"')) {
    fwrite(STDERR, 'Blob upload module must be discoverable only on upload pages.' . PHP_EOL);
    exit(1);
}

echo 'RESOURCE_LOADING_OK' . PHP_EOL;
