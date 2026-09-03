<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/ai-title-check.php';
require_once dirname(__DIR__) . '/app/ai-risk.php';
require_once dirname(__DIR__) . '/app/session.php';

const DEMO_STUDENT_COUNT = 80;
const DEMO_ADVISOR_COUNT = 20;
const DEMO_PASSWORD = 'Demo@2026';
const DEMO_PDF_FILENAME = 'workflow-demo-placeholder.pdf';

function remove_workflow_demo_data(PDO $pdo): void
{
    $pdo->exec("DELETE FROM activities WHERE id LIKE 'DMACT%'");
    $pdo->exec("DELETE FROM group_messages WHERE id LIKE 'DMMSG%'");
    $pdo->exec("DELETE FROM notifications WHERE id LIKE 'DMNOT%'");
    $pdo->exec("DELETE FROM approvals WHERE id LIKE 'DMAPR%'");
    $pdo->exec("DELETE FROM documents WHERE id LIKE 'DMDOC%'");
    $pdo->exec("DELETE FROM project_group_members WHERE group_id LIKE 'DEMOGRP%'");
    $pdo->exec("DELETE FROM project_groups WHERE id LIKE 'DEMOGRP%'");
    $pdo->exec("UPDATE students SET project_id = NULL WHERE id LIKE 'DEMOSTU%'");
    $pdo->exec("DELETE FROM projects WHERE id LIKE 'DEMOPRJ%'");
    $pdo->exec("DELETE FROM students WHERE id LIKE 'DEMOSTU%'");
    $pdo->exec("DELETE FROM advisors WHERE id LIKE 'DEMOADV%'");
}

function remove_workflow_demo_files(): void
{
    foreach (['proposal', 'draft', 'complete'] as $stage) {
        $fixture = dirname(__DIR__) . '/uploads/' . $stage . '/' . DEMO_PDF_FILENAME;
        if (is_file($fixture)) @unlink($fixture);
    }
}

function filter_workflow_demo_runtime(array $data): array
{
    $prefixes = [
        'advisors' => 'DEMOADV', 'students' => 'DEMOSTU', 'projects' => 'DEMOPRJ',
        'documents' => 'DMDOC', 'approvals' => 'DMAPR', 'notifications' => 'DMNOT',
        'activities' => 'DMACT', 'messages' => 'DMMSG',
        'groups' => 'DEMOGRP',
    ];
    foreach ($prefixes as $collection => $prefix) {
        $data[$collection] = array_values(array_filter(
            is_array($data[$collection] ?? null) ? $data[$collection] : [],
            static fn(array $row): bool => !str_starts_with((string) ($row['id'] ?? ''), $prefix)
        ));
    }
    return $data;
}

function synchronize_workflow_demo_runtime(PDO $pdo, bool $includeDemoRows): void
{
    $data = filter_workflow_demo_runtime(load_data());
    if (!$includeDemoRows) {
        save_data($data);
        return;
    }

    $advisors = $pdo->query(
        "SELECT id, name, email, phone, faculty, department, students, status, password_hash, photo, created_at
         FROM advisors WHERE id LIKE 'DEMOADV%' ORDER BY id"
    )->fetchAll();
    $students = $pdo->query(
        "SELECT id, code, first_name, last_name, email, phone, faculty, major, year_level,
                advisor_id, project_id, status, photo, created_at
         FROM students WHERE id LIKE 'DEMOSTU%' ORDER BY id"
    )->fetchAll();
    foreach ($students as &$student) {
        $student['year_level'] = (int) ($student['year_level'] ?? 4);
        $student['advisor_roles'] = ['chair' => (string) ($student['advisor_id'] ?? '')];
    }
    unset($student);
    $projects = $pdo->query(
        "SELECT id, code, title, student_id, advisor_id, category, status, progress, updated_at
         FROM projects WHERE id LIKE 'DEMOPRJ%' ORDER BY id"
    )->fetchAll();
    $groups = $pdo->query(
        "SELECT id, name, leader_id, project_id, faculty, created_at
         FROM project_groups WHERE id LIKE 'DEMOGRP%' ORDER BY id"
    )->fetchAll();
    $memberStatement = $pdo->prepare('SELECT student_id FROM project_group_members WHERE group_id=:group_id ORDER BY student_id');
    foreach ($groups as &$group) {
        $memberStatement->execute(['group_id' => $group['id']]);
        $group['member_ids'] = $memberStatement->fetchAll(PDO::FETCH_COLUMN);
        $project = current(array_filter($projects, static fn(array $row): bool => ($row['id'] ?? '') === ($group['project_id'] ?? ''))) ?: [];
        $group['advisor_id'] = (string) ($project['advisor_id'] ?? '');
        $group['advisor_roles'] = ['chair' => (string) ($project['advisor_id'] ?? '')];
    }
    unset($group);
    $documents = $pdo->query(
        "SELECT id, project_id, student_id, group_id, type, chapter, title, filename, size,
                status, uploaded_at, approved_at
         FROM documents WHERE id LIKE 'DMDOC%' ORDER BY id"
    )->fetchAll();
    $approvals = $pdo->query(
        "SELECT id, student_id, document_id, group_id, reviewer_id, step, reviewer, status,
                message, created_at, approved_at
         FROM approvals WHERE id LIKE 'DMAPR%' ORDER BY id"
    )->fetchAll();
    $notifications = $pdo->query(
        "SELECT id, group_id, student_id, advisor_id, scope, title, message, type,
                read_status, read_by, created_at
         FROM notifications WHERE id LIKE 'DMNOT%' ORDER BY id"
    )->fetchAll();
    foreach ($notifications as &$notification) {
        $notification['read'] = (bool) ($notification['read_status'] ?? false);
        $notification['read_by'] = [];
    }
    unset($notification);
    $activities = $pdo->query(
        "SELECT id, title, actor, created_at FROM activities WHERE id LIKE 'DMACT%' ORDER BY id"
    )->fetchAll();
    $messages = $pdo->query(
        "SELECT id, group_id, student_id, advisor_id, sender, receiver, subject, message,
                attachment, read_status, created_at
         FROM group_messages WHERE id LIKE 'DMMSG%' ORDER BY id"
    )->fetchAll();
    foreach ($messages as &$message) $message['read'] = (bool) ($message['read_status'] ?? false);
    unset($message);

    foreach (compact('advisors', 'students', 'projects', 'groups', 'documents', 'approvals', 'notifications', 'activities', 'messages') as $collection => $rows) {
        $data[$collection] = array_merge($data[$collection] ?? [], $rows);
    }
    save_data($data);
}

function workflow_test_pdf(): string
{
    $files = glob(dirname(__DIR__) . '/uploads/complete/*.pdf') ?: [];
    foreach ($files as $file) {
        $handle = @fopen($file, 'rb');
        $signature = $handle ? fread($handle, 5) : '';
        if ($handle) fclose($handle);
        if ($signature === '%PDF-') return basename($file);
    }
    $directory = dirname(__DIR__) . '/uploads/complete';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create uploads/complete for the workflow PDF fixture.');
    }
    $fixture = $directory . '/' . DEMO_PDF_FILENAME;
    $pdf = "%PDF-1.4\n% Workflow demo fixture\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    if (file_put_contents($fixture, $pdf) === false) {
        throw new RuntimeException('Could not create the workflow PDF fixture.');
    }
    return DEMO_PDF_FILENAME;
}

function ensure_workflow_pdf(string $filename): void
{
    $source = dirname(__DIR__) . '/uploads/complete/' . $filename;
    foreach (['proposal', 'draft'] as $stage) {
        $directory = dirname(__DIR__) . '/uploads/' . $stage;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create uploads/{$stage}.");
        }
        $target = $directory . '/' . $filename;
        if (!is_file($target) && !copy($source, $target)) {
            throw new RuntimeException("Could not prepare the {$stage} test PDF.");
        }
    }
}

function demo_stage(int $number): string
{
    return match (true) {
        $number <= 12 => 'not_submitted',
        $number <= 24 => 'proposal_review',
        $number <= 36 => 'proposal_revision',
        $number <= 48 => 'draft_active',
        $number <= 60 => 'draft_revision',
        $number <= 70 => 'complete_review',
        default => 'completed',
    };
}

$pdo = database_connection();
$cleanOnly = in_array('--clean', $argv, true);
$pdfFilename = $cleanOnly ? '' : workflow_test_pdf();
if (!$cleanOnly) ensure_workflow_pdf($pdfFilename);

$pdo->beginTransaction();
try {
    remove_workflow_demo_data($pdo);
    if ($cleanOnly) {
        remove_workflow_demo_files();
        $pdo->commit();
        synchronize_workflow_demo_runtime($pdo, false);
        echo "WORKFLOW_DEMO_DATA_CLEANED\n";
        exit(0);
    }

    $advisorInsert = $pdo->prepare(
        'INSERT INTO advisors
         (id, name, email, phone, faculty, department, students, status, password_hash)
         VALUES (:id, :name, :email, :phone, :faculty, :department, :students, :status, :password_hash)'
    );
    $advisorNames = [
        'อ.ดร.กิตติพงษ์ ทดสอบระบบ', 'อ.ดร.วราภรณ์ ข้อมูลตัวอย่าง',
        'อ.ธนกร วิเคราะห์งาน', 'อ.ปรียานุช ตรวจสอบโครงงาน',
        'อ.ดร.ชยพล ปัญญาประดิษฐ์', 'อ.สุภาวดี วิจัยและพัฒนา',
    ];
    while (count($advisorNames) < DEMO_ADVISOR_COUNT) {
        $advisorNames[] = 'Demo Advisor ' . str_pad((string) (count($advisorNames) + 1), 2, '0', STR_PAD_LEFT);
    }
    $demoFaculty = app_faculties()[0];
    $demoMajors = app_majors();
    for ($number = 1; $number <= DEMO_ADVISOR_COUNT; $number++) {
        $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
        $advisorInsert->execute([
            'id' => 'DEMOADV' . $suffix,
            'name' => $advisorNames[$number - 1],
            'email' => 'demo.advisor' . $suffix . '@rmutp.ac.th',
            'phone' => '08270000' . $suffix,
            'faculty' => $demoFaculty,
            'department' => $demoMajors[($number - 1) % count($demoMajors)],
            'students' => (int) ceil(DEMO_STUDENT_COUNT / DEMO_ADVISOR_COUNT),
            'status' => 'Active',
            'password_hash' => secure_password_hash(DEMO_PASSWORD),
        ]);
    }

    $studentInsert = $pdo->prepare(
        'INSERT INTO students
         (id, code, first_name, last_name, email, phone, faculty, major, year_level,
          advisor_id, project_id, status)
         VALUES
         (:id, :code, :first_name, :last_name, :email, :phone, :faculty, :major, :year_level,
          :advisor_id, NULL, :status)'
    );
    $projectInsert = $pdo->prepare(
        'INSERT INTO projects
         (id, code, title, student_id, advisor_id, category, status, progress, updated_at)
         VALUES
         (:id, :code, :title, :student_id, :advisor_id, :category, :status, :progress, :updated_at)'
    );
    $studentProjectUpdate = $pdo->prepare('UPDATE students SET project_id=:project_id WHERE id=:student_id');
    $studentAdvisorInsert = $pdo->prepare(
        "INSERT INTO student_advisors (student_id, advisor_id, advisor_role)
         VALUES (:student_id, :advisor_id, 'chair')"
    );
    $groupInsert = $pdo->prepare(
        'INSERT INTO project_groups (id, name, leader_id, project_id, faculty, created_at)
         VALUES (:id, :name, :leader_id, :project_id, :faculty, :created_at)'
    );
    $groupMemberInsert = $pdo->prepare(
        'INSERT INTO project_group_members (group_id, student_id) VALUES (:group_id, :student_id)'
    );
    $documentInsert = $pdo->prepare(
        'INSERT INTO documents
         (id, project_id, student_id, group_id, type, chapter, title, filename, size, status, uploaded_at, approved_at)
         VALUES
         (:id, :project_id, :student_id, :group_id, :type, :chapter, :title, :filename, :size, :status, :uploaded_at, :approved_at)'
    );
    $approvalInsert = $pdo->prepare(
        'INSERT INTO approvals
         (id, student_id, document_id, group_id, reviewer_id, step, reviewer, status, message, created_at, approved_at)
         VALUES
         (:id, :student_id, :document_id, :group_id, :reviewer_id, :step, :reviewer, :status, :message, :created_at, :approved_at)'
    );
    $notificationInsert = $pdo->prepare(
        'INSERT INTO notifications
         (id, group_id, student_id, advisor_id, scope, title, message, type, read_status, created_at)
         VALUES
         (:id, :group_id, :student_id, :advisor_id, :scope, :title, :message, :type, :read_status, :created_at)'
    );
    $messageInsert = $pdo->prepare(
        'INSERT INTO group_messages
         (id, group_id, student_id, advisor_id, sender, receiver, subject, message, attachment, read_status, created_at)
         VALUES
         (:id, :group_id, :student_id, :advisor_id, :sender, :receiver, :subject, :message, :attachment, :read_status, :created_at)'
    );

    $firstNames = ['กิตติ', 'ชนากานต์', 'ณัฐพงษ์', 'ปิยะดา', 'ภัทรพล', 'วรัญญา', 'ศุภกิจ', 'อรทัย', 'ธนภัทร'];
    $lastNames = ['ทดสอบ', 'ใจดี', 'พัฒนาระบบ', 'วิเคราะห์ข้อมูล', 'สร้างสรรค์', 'ก้าวหน้า'];
    $projectTopics = [
        'ระบบติดตามโครงงานนักศึกษาด้วยปัญญาประดิษฐ์',
        'ระบบจัดการห้องเรียนอัจฉริยะ',
        'แพลตฟอร์มอนุมัติเอกสารออนไลน์',
        'ระบบค้นหาคลังงานวิจัย',
        'ระบบนัดหมายอาจารย์ที่ปรึกษา',
        'ระบบยืมคืนอุปกรณ์ภายในมหาวิทยาลัย',
        'แดชบอร์ดติดตามการฝึกงานของนักศึกษา',
        'ระบบแนะนำหนังสือในห้องสมุดดิจิทัล',
        'ระบบลงทะเบียนกิจกรรมมหาวิทยาลัย',
    ];
    $stageLabels = [
        'not_submitted' => ['ยังไม่ส่งเอกสาร', 'ยังไม่มีเอกสารในระบบ'],
        'proposal_review' => ['ส่งข้อเสนอแล้ว', 'ข้อเสนอกำลังรออาจารย์ตรวจ'],
        'proposal_revision' => ['ข้อเสนอต้องแก้ไข', 'อาจารย์ส่งข้อเสนอกลับให้แก้ไข'],
        'draft_active' => ['กำลังจัดทำฉบับร่าง', 'ส่งฉบับร่างบางบทแล้ว'],
        'draft_revision' => ['ฉบับร่างต้องแก้ไข', 'มีบทที่ถูกส่งกลับให้แก้ไข'],
        'complete_review' => ['ส่งฉบับสมบูรณ์แล้ว', 'กำลังรออนุมัติฉบับสมบูรณ์'],
        'completed' => ['โครงงานเสร็จสมบูรณ์', 'ฉบับสมบูรณ์ได้รับการอนุมัติแล้ว'],
    ];
    $advisorById = [];
    foreach ($pdo->query("SELECT id, name FROM advisors WHERE id LIKE 'DEMOADV%'")->fetchAll() as $advisor) {
        $advisorById[(string) $advisor['id']] = (string) $advisor['name'];
    }
    $pdfSize = number_format(filesize(dirname(__DIR__) . '/uploads/complete/' . $pdfFilename) / 1048576, 2) . ' MB';

    $addDocument = static function (
        string $documentId, string $projectId, string $studentId, string $groupId, string $advisorId,
        string $advisorName, string $type, ?int $chapter, string $status,
        string $uploadedAt, string $filename, string $size
    ) use ($documentInsert, $approvalInsert): void {
        $title = $type === 'proposal' ? 'ข้อเสนอโครงงาน'
            : ($type === 'complete' ? 'วิทยานิพนธ์ฉบับสมบูรณ์' : 'ฉบับร่าง บทที่ ' . $chapter);
        $approvedAt = in_array($status, ['Approved', 'Completed'], true) ? $uploadedAt : null;
        $documentInsert->execute([
            'id' => $documentId, 'project_id' => $projectId, 'student_id' => $studentId, 'group_id' => $groupId,
            'type' => $type, 'chapter' => $chapter, 'title' => $title,
            'filename' => $filename, 'size' => $size, 'status' => $status,
            'uploaded_at' => $uploadedAt, 'approved_at' => $approvedAt,
        ]);
        $approvalInsert->execute([
            'id' => 'DMAPR' . substr($documentId, 5),
            'student_id' => $studentId, 'document_id' => $documentId,
            'group_id' => $groupId,
            'reviewer_id' => $advisorId, 'step' => $title, 'reviewer' => $advisorName,
            'status' => $status, 'message' => match ($status) {
                'Approved', 'Completed' => 'ตรวจสอบแล้ว เอกสารถูกต้อง',
                'NeedsRevision' => 'กรุณาปรับแก้รายละเอียดตามข้อเสนอแนะ',
                default => 'รอตรวจสอบเอกสาร',
            },
            'created_at' => $uploadedAt, 'approved_at' => $approvedAt,
        ]);
    };

    for ($number = 1; $number <= DEMO_STUDENT_COUNT; $number++) {
        $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        $studentId = 'DEMOSTU' . $suffix;
        $projectId = 'DEMOPRJ' . $suffix;
        $groupId = 'DEMOGRP' . $suffix;
        $advisorNumber = (($number - 1) % DEMO_ADVISOR_COUNT) + 1;
        $advisorId = 'DEMOADV' . str_pad((string) $advisorNumber, 2, '0', STR_PAD_LEFT);
        $advisorName = $advisorById[$advisorId];
        $stage = demo_stage($number);
        $daysAgo = 1 + (($number * 7) % 56);
        $updatedAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        $status = match ($stage) {
            'completed' => 'Completed',
            'draft_active', 'draft_revision', 'complete_review' => 'Draft',
            default => 'Pending',
        };
        $progress = match ($stage) {
            'not_submitted' => ($number * 3) % 11,
            'proposal_review' => 15 + ($number % 6),
            'proposal_revision' => 20 + ($number % 8),
            'draft_active' => 35 + ($number % 21),
            'draft_revision' => 50 + ($number % 21),
            'complete_review' => 90 + ($number % 6),
            default => 100,
        };
        $codeBase = '0799903' . str_pad((string) $number, 5, '0', STR_PAD_LEFT);
        $code = $codeBase . '-' . ($number % 10);

        $studentInsert->execute([
            'id' => $studentId, 'code' => $code,
            'first_name' => $firstNames[($number - 1) % count($firstNames)],
            'last_name' => $lastNames[($number - 1) % count($lastNames)] . ' ' . $suffix,
            'email' => str_replace('-', '', $code) . '@rmutp.com',
            'phone' => $number % 4 === 0 ? null : '08970' . str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'faculty' => $demoFaculty,
            'major' => $demoMajors[($advisorNumber - 1) % count($demoMajors)],
            'year_level' => 3 + ($number % 2), 'advisor_id' => $advisorId,
            'status' => $stage === 'completed' ? 'Completed' : 'Active',
        ]);
        $title = $projectTopics[($number - 1) % count($projectTopics)] . ' รุ่นทดสอบ ' . $suffix;
        if ($number >= 53) $title = 'ระบบติดตามความก้าวหน้าโครงงานด้วยปัญญาประดิษฐ์';
        $projectInsert->execute([
            'id' => $projectId, 'code' => 'DEMO-2026-' . $suffix, 'title' => $title,
            'student_id' => $studentId, 'advisor_id' => $advisorId,
            'category' => 'Workflow Test', 'status' => $status,
            'progress' => $progress, 'updated_at' => $updatedAt,
        ]);
        $studentProjectUpdate->execute(['project_id' => $projectId, 'student_id' => $studentId]);
        $studentAdvisorInsert->execute(['student_id' => $studentId, 'advisor_id' => $advisorId]);
        $groupInsert->execute([
            'id' => $groupId, 'name' => 'กลุ่มทดสอบโครงงาน ' . $suffix,
            'leader_id' => $studentId, 'project_id' => $projectId,
            'faculty' => $demoFaculty, 'created_at' => $updatedAt,
        ]);
        $groupMemberInsert->execute(['group_id' => $groupId, 'student_id' => $studentId]);

        $proposalId = 'DMDOC' . $suffix . 'P';
        if ($stage !== 'not_submitted') {
            $proposalStatus = match ($stage) {
                'proposal_review' => 'Review',
                'proposal_revision' => 'NeedsRevision',
                default => 'Approved',
            };
            $addDocument($proposalId, $projectId, $studentId, $groupId, $advisorId, $advisorName,
                'proposal', null, $proposalStatus, $updatedAt, $pdfFilename, $pdfSize);
        }
        if (in_array($stage, ['draft_active', 'draft_revision', 'complete_review', 'completed'], true)) {
            $lastChapter = $stage === 'draft_active' ? 3 : ($stage === 'draft_revision' ? 4 : 5);
            for ($chapter = 1; $chapter <= $lastChapter; $chapter++) {
                $draftStatus = 'Approved';
                if ($stage === 'draft_active' && $chapter === $lastChapter) $draftStatus = 'Review';
                if ($stage === 'draft_revision' && $chapter === $lastChapter) $draftStatus = 'NeedsRevision';
                $draftTime = date('Y-m-d H:i:s', strtotime($updatedAt . ' -' . ($lastChapter - $chapter) . ' days'));
                $addDocument('DMDOC' . $suffix . 'D' . $chapter, $projectId, $studentId, $groupId, $advisorId,
                    $advisorName, 'draft', $chapter, $draftStatus, $draftTime, $pdfFilename, $pdfSize);
            }
        }
        if (in_array($stage, ['complete_review', 'completed'], true)) {
            $completeStatus = $stage === 'completed' ? 'Completed' : 'Review';
            $addDocument('DMDOC' . $suffix . 'C', $projectId, $studentId, $groupId, $advisorId,
                $advisorName, 'complete', null, $completeStatus, $updatedAt, $pdfFilename, $pdfSize);
        }

        [$noticeTitle, $noticeMessage] = $stageLabels[$stage];
        $notificationInsert->execute([
            'id' => 'DMNOT' . $suffix, 'group_id' => $groupId, 'student_id' => $studentId, 'advisor_id' => $advisorId,
            'scope' => 'student', 'title' => $noticeTitle, 'message' => $noticeMessage,
            'type' => 'Workflow Test', 'read_status' => $number % 3 === 0 ? 1 : 0,
            'created_at' => $updatedAt,
        ]);
        if ($number % 3 === 0) {
            $messageInsert->execute([
                'id' => 'DMMSG' . $suffix, 'group_id' => $groupId, 'student_id' => $studentId, 'advisor_id' => $advisorId,
                'sender' => $advisorName, 'receiver' => $firstNames[($number - 1) % count($firstNames)],
                'subject' => 'ติดตามความคืบหน้าโครงงาน',
                'message' => 'กรุณาอัปเดตความคืบหน้าและตรวจสอบข้อเสนอแนะล่าสุด',
                'attachment' => '', 'read_status' => $number % 2,
                'created_at' => $updatedAt,
            ]);
        }
    }

    for ($number = 1; $number <= 12; $number++) {
        $pdo->prepare('INSERT INTO activities (id, title, actor, created_at) VALUES (:id, :title, :actor, :created_at)')
            ->execute([
                'id' => 'DMACT' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
                'title' => 'กิจกรรมทดสอบ Workflow ลำดับที่ ' . $number,
                'actor' => $advisorNames[($number - 1) % count($advisorNames)],
                'created_at' => date('Y-m-d H:i:s', strtotime("-{$number} hours")),
            ]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

synchronize_workflow_demo_runtime($pdo, true);

$riskSummary = process_project_risk_scores(500);
$duplicateProjectId = 'DEMOPRJ054';
$duplicateTitle = 'ระบบติดตามความก้าวหน้าโครงงานด้วยปัญญาประดิษฐ์';
$job = queue_project_title_check($duplicateProjectId, $duplicateTitle);
if (!$job) throw new RuntimeException('Could not queue the workflow title test.');

$claim = $pdo->prepare(
    "UPDATE project_title_checks
     SET status='processing', attempts=attempts+1, started_at=NOW(), error_message=NULL
     WHERE id=:id AND status='queued'"
);
$claim->execute(['id' => $job['id']]);
if ($claim->rowCount() === 1) {
    $titleResult = process_project_title_check_job([
        'id' => (int) $job['id'], 'project_id' => $duplicateProjectId,
        'title' => $duplicateTitle, 'attempts' => 1,
    ]);
} else {
    $deadline = microtime(true) + 60;
    do {
        usleep(250000);
        $titleResult = latest_project_title_check($duplicateProjectId, (int) $job['id']) ?? [];
    } while (microtime(true) < $deadline && !in_array($titleResult['status'] ?? '', ['completed', 'failed'], true));
}

$stageCounts = $pdo->query(
    "SELECT
        SUM(CASE WHEN document_total=0 THEN 1 ELSE 0 END) AS not_submitted,
        SUM(CASE WHEN review_total>0 THEN 1 ELSE 0 END) AS awaiting_review,
        SUM(CASE WHEN revision_total>0 THEN 1 ELSE 0 END) AS needs_revision,
        SUM(CASE WHEN complete_total>0 THEN 1 ELSE 0 END) AS complete_submitted
     FROM (
        SELECT projects.id,
          COUNT(documents.id) AS document_total,
          SUM(documents.status='Review') AS review_total,
          SUM(documents.status='NeedsRevision') AS revision_total,
          SUM(documents.type='complete') AS complete_total
        FROM projects LEFT JOIN documents ON documents.project_id=projects.id
        WHERE projects.id LIKE 'DEMOPRJ%'
        GROUP BY projects.id
     ) workflow"
)->fetch();
$counts = [
    'advisors' => (int) $pdo->query("SELECT COUNT(*) FROM advisors WHERE id LIKE 'DEMOADV%'")->fetchColumn(),
    'students' => (int) $pdo->query("SELECT COUNT(*) FROM students WHERE id LIKE 'DEMOSTU%'")->fetchColumn(),
    'projects' => (int) $pdo->query("SELECT COUNT(*) FROM projects WHERE id LIKE 'DEMOPRJ%'")->fetchColumn(),
    'documents' => (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE id LIKE 'DMDOC%'")->fetchColumn(),
    'approvals' => (int) $pdo->query("SELECT COUNT(*) FROM approvals WHERE id LIKE 'DMAPR%'")->fetchColumn(),
    'notifications' => (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE id LIKE 'DMNOT%'")->fetchColumn(),
    'messages' => (int) $pdo->query("SELECT COUNT(*) FROM group_messages WHERE id LIKE 'DMMSG%'")->fetchColumn(),
];
if ($counts['advisors'] !== DEMO_ADVISOR_COUNT || $counts['students'] !== DEMO_STUDENT_COUNT || $counts['projects'] !== DEMO_STUDENT_COUNT) {
    throw new RuntimeException('Workflow account totals are incorrect.');
}
if (($titleResult['status'] ?? '') !== 'completed' || (float) ($titleResult['max_similarity'] ?? 0) < 0.99) {
    throw new RuntimeException('Duplicate-title verification failed.');
}
if (!secure_password_verify(DEMO_PASSWORD, (string) $pdo->query("SELECT password_hash FROM advisors WHERE id='DEMOADV01'")->fetchColumn())) {
    throw new RuntimeException('Demo advisor password verification failed.');
}

echo 'WORKFLOW_DEMO_READY accounts=' . ($counts['advisors'] + $counts['students'])
    . ' students=' . $counts['students'] . ' advisors=' . $counts['advisors']
    . ' projects=' . $counts['projects'] . PHP_EOL;
echo 'WORKFLOW_MIX not_submitted=' . (int) $stageCounts['not_submitted']
    . ' awaiting_review=' . (int) $stageCounts['awaiting_review']
    . ' needs_revision=' . (int) $stageCounts['needs_revision']
    . ' complete_submitted=' . (int) $stageCounts['complete_submitted'] . PHP_EOL;
echo 'RELATED_DATA documents=' . $counts['documents'] . ' approvals=' . $counts['approvals']
    . ' notifications=' . $counts['notifications'] . ' messages=' . $counts['messages'] . PHP_EOL;
echo 'AI_CHECK score=' . number_format((float) $titleResult['max_similarity'] * 100, 1)
    . '% engine=' . ($titleResult['engine'] ?? '')
    . ' risk_processed=' . (int) ($riskSummary['processed'] ?? 0) . PHP_EOL;
echo 'ADVISOR_LOGIN demo.advisor01@rmutp.ac.th / ' . DEMO_PASSWORD . PHP_EOL;
echo 'STUDENT_LOGIN 079990300001-1 / 079990300001-1' . PHP_EOL;
echo "Clean later with: php tests/seed-workflow-data.php --clean\n";
