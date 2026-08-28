<?php
$allowed = ['projects', 'documents', 'notifications', 'profile', 'barcode', 'timeline', 'proposal', 'draft', 'complete', 'import-excel', 'export-excel'];
$view = (string) ($_GET['view'] ?? 'dashboard');
$entryPage = in_array($view, $allowed, true) ? $view : '404';
require dirname(__DIR__) . '/app/page-entry.php';
