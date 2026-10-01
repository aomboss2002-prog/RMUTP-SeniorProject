<?php
declare(strict_types=1);
putenv('VERCEL=1');
putenv('AI_TITLE_ENGINE=auto');
require dirname(__DIR__) . '/app/store.php';
require dirname(__DIR__) . '/app/ai-title-check.php';

$base = 'ระบบติดตามโครงงานนักศึกษาด้วยปัญญาประดิษฐ์';
$cases = [
    'exact' => $base,
    'similar' => $base . 'ออนไลน์',
    'different' => 'เครื่องรดน้ำต้นไม้อัตโนมัติพลังงานแสงอาทิตย์',
];
foreach ($cases as $name => $title) {
    $result = title_similarity_results($title, [['id' => 'TEST_ONLY', 'title' => $base]]);
    echo $name . ' score=' . round($result['maxScore'] * 100, 2) . '% risk=' . $result['risk'] . ' engine=' . $result['engine'] . PHP_EOL;
    if ($result['engine'] !== 'local-ngram-v1') throw new RuntimeException('Unexpected hosted engine');
    if ($name === 'exact' && ($result['maxScore'] < 0.99 || $result['risk'] !== 'high')) throw new RuntimeException('Exact duplicate not detected');
    if ($name === 'similar' && $result['maxScore'] < 0.70) throw new RuntimeException('Similar title not detected');
    if ($name === 'different' && $result['risk'] !== 'clear') throw new RuntimeException('Unrelated title flagged');
}
echo "TITLE_SIMILARITY_OK (no database writes; simulated Vercel environment)\n";
