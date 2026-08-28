<?php
declare(strict_types=1);

final class PageController
{
    public function render(string $page): never
    {
        $_GET['page'] = $page;
        if (!defined('APP_ROUTED_ENTRY')) define('APP_ROUTED_ENTRY', true);
        require dirname(__DIR__) . '/index.php';
        exit;
    }
}
