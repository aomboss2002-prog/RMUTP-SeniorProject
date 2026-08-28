<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/store.php';

const TEST_PROJECT_COUNT = 20;

function find_test_pdf(): string
{
    $files = glob(__DIR__ . '/../uploads/complete/*.pdf') ?: [];
    usort($files, static fn(string $left, string $right): int => filesize($right) <=> filesize($left));

    foreach ($files as $file) {
        $handle = fopen($file, 'rb');
        $signature = $handle ? fread($handle, 5) : '';
        if ($handle) fclose($handle);
        if ($signature === '%PDF-') return basename($file);
    }

    throw new RuntimeException('No valid PDF exists in uploads/complete for the test records.');
}

$pdo = database_connection();
$advisor = $pdo->query("SELECT id, name, faculty, department FROM advisors WHERE status = 'Active' ORDER BY id LIMIT 1")->fetch();
if (!$advisor) throw new RuntimeException('An active advisor is required before creating test projects.');

$pdfFilename = find_test_pdf();
$faculty = (string) ($advisor['faculty'] ?: 'คณะบริหารธุรกิจ');
$major = (string) ($advisor['department'] ?: 'บธ.บ. สาขาวิชาการจัดการ');
$baseTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));

foreach (['proposal', 'draft'] as $stageDirectory) {
    $directory = __DIR__ . '/../uploads/' . $stageDirectory;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create upload directory: ' . $stageDirectory);
    }
    $stageFile = $directory . '/' . $pdfFilename;
    if (!is_file($stageFile) && !copy(__DIR__ . '/../uploads/complete/' . $pdfFilename, $stageFile)) {
        throw new RuntimeException('Could not create the test PDF copy for ' . $stageDirectory);
    }
}

$runtimeStudents = [];
$runtimeProjects = [];
$runtimeGroups = [];
$runtimeDocuments = [];
$runtimeApprovals = [];

$studentSql = $pdo->prepare(
    'INSERT INTO students
        (id, code, first_name, last_name, email, phone, faculty, major, year_level, advisor_id, project_id, status)
     VALUES
        (:id, :code, :first_name, :last_name, :email, :phone, :faculty, :major, 4, :advisor_id, :project_id, :status)
     ON DUPLICATE KEY UPDATE
        first_name = VALUES(first_name), last_name = VALUES(last_name), email = VALUES(email),
        faculty = VALUES(faculty), major = VALUES(major), advisor_id = VALUES(advisor_id),
        project_id = VALUES(project_id), status = VALUES(status)'
);
$projectSql = $pdo->prepare(
    'INSERT INTO projects
        (id, code, title, student_id, advisor_id, category, status, progress, updated_at)
     VALUES
        (:id, :code, :title, :student_id, :advisor_id, :category, :status, 100, :updated_at)
     ON DUPLICATE KEY UPDATE
        code = VALUES(code), title = VALUES(title), student_id = VALUES(student_id),
        advisor_id = VALUES(advisor_id), category = VALUES(category), status = VALUES(status),
        progress = 100, updated_at = VALUES(updated_at)'
);
$studentAdvisorSql = $pdo->prepare(
    "INSERT INTO student_advisors (student_id, advisor_id, advisor_role)
     VALUES (:student_id, :advisor_id, 'chair')
     ON DUPLICATE KEY UPDATE advisor_id = VALUES(advisor_id)"
);
$documentSql = $pdo->prepare(
    'INSERT INTO documents
        (id, project_id, student_id, type, chapter, title, filename, size, status, uploaded_at, approved_at)
     VALUES
        (:id, :project_id, :student_id, :type, :chapter, :title, :filename, :size, :status, :uploaded_at, :approved_at)
     ON DUPLICATE KEY UPDATE
        project_id = VALUES(project_id), student_id = VALUES(student_id), type = VALUES(type),
        chapter = VALUES(chapter), title = VALUES(title), filename = VALUES(filename),
        size = VALUES(size), status = VALUES(status), uploaded_at = VALUES(uploaded_at),
        approved_at = VALUES(approved_at)'
);
$approvalSql = $pdo->prepare(
    'INSERT INTO approvals
        (id, student_id, document_id, reviewer_id, step, reviewer, status, message, created_at, approved_at)
     VALUES
        (:id, :student_id, :document_id, :reviewer_id, :step, :reviewer, :status, :message, :created_at, :approved_at)
     ON DUPLICATE KEY UPDATE
        student_id = VALUES(student_id), document_id = VALUES(document_id), reviewer_id = VALUES(reviewer_id),
        step = VALUES(step), reviewer = VALUES(reviewer), status = VALUES(status),
        message = VALUES(message), created_at = VALUES(created_at), approved_at = VALUES(approved_at)'
);

$pdo->beginTransaction();
try {
    for ($number = 1; $number <= TEST_PROJECT_COUNT; $number++) {
        $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        $studentId = 'TESTSTU' . $suffix;
        $projectId = 'TESTPRJ' . $suffix;
        $approvedAt = $baseTime->modify('-' . ($number - 1) . ' minutes')->format('Y-m-d H:i:s');

        $studentSql->execute([
            'id' => $studentId,
            'code' => '9966' . str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'first_name' => 'นักศึกษาทดสอบ',
            'last_name' => 'สมบูรณ์ ' . $suffix,
            'email' => 'completed.' . $suffix . '@example.test',
            'phone' => '080-900-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'faculty' => $faculty,
            'major' => $major,
            'advisor_id' => $advisor['id'],
            'project_id' => $projectId,
            'status' => 'Completed',
        ]);
        $runtimeStudents[] = [
            'id' => $studentId,
            'code' => '9966' . str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'first_name' => 'นักศึกษาทดสอบ',
            'last_name' => 'สมบูรณ์ ' . $suffix,
            'email' => 'completed.' . $suffix . '@example.test',
            'phone' => '080-900-' . str_pad((string) $number, 4, '0', STR_PAD_LEFT),
            'faculty' => $faculty,
            'major' => $major,
            'year_level' => 4,
            'advisor_id' => $advisor['id'],
            'advisor_roles' => ['chair' => $advisor['id'], 'vice_chair' => '', 'committee' => ''],
            'project_id' => $projectId,
            'status' => 'Completed',
            'photo' => 'assets/img/profile-student.svg',
        ];
        $projectSql->execute([
            'id' => $projectId,
            'code' => 'TEST-2026-' . $suffix,
            'title' => 'โครงงานสมบูรณ์สำหรับทดสอบระบบ ' . $suffix,
            'student_id' => $studentId,
            'advisor_id' => $advisor['id'],
            'category' => 'System Test',
            'status' => 'Completed',
            'updated_at' => $approvedAt,
        ]);
        $runtimeProjects[] = [
            'id' => $projectId,
            'code' => 'TEST-2026-' . $suffix,
            'title' => 'โครงงานสมบูรณ์สำหรับทดสอบระบบ ' . $suffix,
            'student_id' => $studentId,
            'advisor_id' => $advisor['id'],
            'category' => 'System Test',
            'status' => 'Completed',
            'progress' => 100,
            'updated_at' => $approvedAt,
        ];
        $runtimeGroups[] = [
            'id' => 'TESTGRP' . $suffix,
            'name' => 'กลุ่มทดสอบสมบูรณ์ ' . $suffix,
            'leader_id' => $studentId,
            'project_id' => $projectId,
            'faculty' => $faculty,
            'member_ids' => [$studentId],
            'advisor_roles' => ['chair' => $advisor['id'], 'vice_chair' => '', 'committee' => ''],
            'created_at' => $approvedAt,
        ];
        $studentAdvisorSql->execute(['student_id' => $studentId, 'advisor_id' => $advisor['id']]);

        $stages = [['P', 'proposal', null, 'ข้อเสนอโครงงาน', 'Proposal']];
        for ($chapter = 1; $chapter <= 5; $chapter++) {
            $stages[] = ['D' . $chapter, 'draft', $chapter, 'ฉบับร่าง บทที่ ' . $chapter, 'Draft Chapter ' . $chapter];
        }
        $stages[] = ['C', 'complete', null, 'ฉบับสมบูรณ์', 'Complete'];

        foreach ($stages as $stageIndex => [$stageCode, $type, $chapter, $title, $step]) {
            $documentId = 'TDC' . $suffix . $stageCode;
            $stageTime = (new DateTimeImmutable($approvedAt))->modify('-' . (7 - $stageIndex) . ' days')->format('Y-m-d H:i:s');
            $documentSql->execute([
                'id' => $documentId,
                'project_id' => $projectId,
                'student_id' => $studentId,
                'type' => $type,
                'chapter' => $chapter,
                'title' => $title,
                'filename' => $pdfFilename,
                'size' => number_format(filesize(__DIR__ . '/../uploads/complete/' . $pdfFilename) / 1048576, 2) . ' MB',
                'status' => $type === 'complete' ? 'Completed' : 'Approved',
                'uploaded_at' => $stageTime,
                'approved_at' => $type === 'complete' ? $approvedAt : $stageTime,
            ]);
            $runtimeDocuments[] = [
                'id' => $documentId,
                'project_id' => $projectId,
                'student_id' => $studentId,
                'group_id' => 'TESTGRP' . $suffix,
                'type' => $type,
                'chapter' => $chapter,
                'title' => $title,
                'filename' => $pdfFilename,
                'original_name' => strtolower(str_replace(' ', '-', $step)) . '-' . $suffix . '.pdf',
                'size' => number_format(filesize(__DIR__ . '/../uploads/complete/' . $pdfFilename) / 1048576, 2) . ' MB',
                'status' => $type === 'complete' ? 'Completed' : 'Approved',
                'uploaded_at' => $stageTime,
                'approved_at' => $type === 'complete' ? $approvedAt : $stageTime,
            ];
            $approvalSql->execute([
                'id' => 'TAP' . $suffix . $stageCode,
                'student_id' => $studentId,
                'document_id' => $documentId,
                'reviewer_id' => $advisor['id'],
                'step' => $step,
                'reviewer' => $advisor['name'],
                'status' => $type === 'complete' ? 'Completed' : 'Approved',
                'message' => 'ข้อมูลทดสอบ: อนุมัติแล้ว',
                'created_at' => $stageTime,
                'approved_at' => $type === 'complete' ? $approvedAt : $stageTime,
            ]);
            $runtimeApprovals[] = [
                'id' => 'TAP' . $suffix . $stageCode,
                'student_id' => $studentId,
                'document_id' => $documentId,
                'group_id' => 'TESTGRP' . $suffix,
                'reviewer_id' => $advisor['id'],
                'step' => $step,
                'reviewer' => $advisor['name'],
                'status' => $type === 'complete' ? 'Completed' : 'Approved',
                'message' => 'ข้อมูลทดสอบ: อนุมัติแล้ว',
                'created_at' => $stageTime,
                'approved_at' => $type === 'complete' ? $approvedAt : $stageTime,
            ];
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
}

$mergeById = static function (array $currentRows, array $newRows): array {
    $rowsById = [];
    foreach ($currentRows as $row) {
        if (!empty($row['id'])) $rowsById[(string) $row['id']] = $row;
    }
    foreach ($newRows as $row) {
        $id = (string) ($row['id'] ?? '');
        if ($id !== '') $rowsById[$id] = array_merge($rowsById[$id] ?? [], $row);
    }
    return array_values($rowsById);
};

// The application keeps workflow collections in app_state while primary
// students/projects are also mirrored to relational tables. Seed both layers
// through save_data() so the web UI sees exactly the same records as MySQL.
$runtimeData = load_data();
$runtimeData['students'] = $mergeById($runtimeData['students'] ?? [], $runtimeStudents);
$runtimeData['projects'] = $mergeById($runtimeData['projects'] ?? [], $runtimeProjects);
$runtimeData['groups'] = $mergeById($runtimeData['groups'] ?? [], $runtimeGroups);
$runtimeData['documents'] = $mergeById($runtimeData['documents'] ?? [], $runtimeDocuments);
$runtimeData['approvals'] = $mergeById($runtimeData['approvals'] ?? [], $runtimeApprovals);
save_data($runtimeData);

// Groups now exist in the relational layer, so attach the workflow rows to
// their matching group without temporarily violating foreign keys.
$documentGroupSql = $pdo->prepare('UPDATE documents SET group_id = :group_id WHERE id = :id');
$approvalGroupSql = $pdo->prepare('UPDATE approvals SET group_id = :group_id WHERE id = :id');
foreach ($runtimeDocuments as $document) {
    $documentGroupSql->execute(['group_id' => $document['group_id'], 'id' => $document['id']]);
}
foreach ($runtimeApprovals as $approval) {
    $approvalGroupSql->execute(['group_id' => $approval['group_id'], 'id' => $approval['id']]);
}

$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM students WHERE id LIKE 'TESTSTU%') AS students,
        (SELECT COUNT(*) FROM projects WHERE id LIKE 'TESTPRJ%') AS projects,
        (SELECT COUNT(*) FROM documents WHERE id LIKE 'TDC%') AS documents,
        (SELECT COUNT(*) FROM approvals WHERE id LIKE 'TAP%') AS approvals"
)->fetch();

echo 'TEST_COMPLETED_PROJECTS_OK ' . json_encode([
    'records' => $counts,
    'complete_pdf' => $pdfFilename,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
