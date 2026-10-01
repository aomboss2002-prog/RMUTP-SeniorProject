<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/store.php';
$major = 'บธ.บ. สาขาวิชาระบบสารสนเทศและนวัตกรรมดิจิทัล';
if (app_majors() !== [$major]) throw new RuntimeException('Only the selected major should be available');
$data = normalize_faculty_data([
    'students' => [['major' => 'สาขาเดิม'], ['major' => '']],
    'advisors' => [['department' => 'สาขาเดิม'], ['department' => '']],
]);
if ($data['students'][0]['major'] !== 'สาขาเดิม' || $data['advisors'][0]['department'] !== 'สาขาเดิม') throw new RuntimeException('Existing majors must not be silently rewritten');
if ($data['students'][1]['major'] !== $major || $data['advisors'][1]['department'] !== $major) throw new RuntimeException('Missing majors should use the selected default');
echo "MAJOR_OPTIONS_OK: one choice, default values, existing data preserved (no database writes)\n";
