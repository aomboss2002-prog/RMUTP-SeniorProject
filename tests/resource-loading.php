<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';

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

echo 'RESOURCE_LOADING_OK' . PHP_EOL;
