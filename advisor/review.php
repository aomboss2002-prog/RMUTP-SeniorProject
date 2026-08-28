<?php
$stage = strtolower((string) ($_GET['stage'] ?? 'proposal'));
$entryPage = in_array($stage, ['proposal', 'draft', 'complete'], true) ? 'advisor-' . $stage : 'advisor-proposal';
require dirname(__DIR__) . '/app/page-entry.php';
