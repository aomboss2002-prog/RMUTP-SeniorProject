<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/store.php';
require_once dirname(__DIR__) . '/app/ai-title-check.php';
require_once dirname(__DIR__) . '/app/ai-risk.php';

const AI_TEST_PROJECT_COUNT = 60;
const AI_TEST_PROJECT_PREFIX = 'AITESTPRJ';
const AI_TEST_STUDENT_PREFIX = 'AITESTSTU';
const AI_TEST_ADVISOR_ID = 'AITESTADV01';

function remove_ai_test_projects(PDO $pdo): void
{
    $pdo->exec("DELETE FROM documents WHERE project_id LIKE 'AITESTPRJ%'");
    $pdo->exec("UPDATE students SET project_id = NULL WHERE id LIKE 'AITESTSTU%'");
    $pdo->exec("DELETE FROM projects WHERE id LIKE 'AITESTPRJ%'");
    $pdo->exec("DELETE FROM students WHERE id LIKE 'AITESTSTU%'");
    $pdo->exec("DELETE FROM advisors WHERE id = 'AITESTADV01'");
}

$pdo = database_connection();
$cleanOnly = in_array('--clean', $argv, true);

$pdo->beginTransaction();
try {
    remove_ai_test_projects($pdo);
    if ($cleanOnly) {
        $pdo->commit();
        echo "AI_TEST_DATA_CLEANED\n";
        exit(0);
    }

    $advisor = $pdo->prepare(
        'INSERT INTO advisors
         (id, name, email, phone, faculty, department, students, status, password_hash)
         VALUES (:id, :name, :email, NULL, :faculty, :department, 45, :status, :password_hash)'
    );
    $advisor->execute([
        'id' => AI_TEST_ADVISOR_ID,
        'name' => 'AI Test Advisor',
        'email' => 'ai-test-advisor@example.test',
        'faculty' => 'Test Faculty',
        'department' => 'Artificial Intelligence Test',
        'status' => 'Active',
        'password_hash' => password_hash('AI-Test-Only-2026!', PASSWORD_DEFAULT),
    ]);

    $studentInsert = $pdo->prepare(
        'INSERT INTO students
         (id, code, first_name, last_name, email, phone, faculty, major, year_level,
          advisor_id, project_id, status)
         VALUES
         (:id, :code, :first_name, :last_name, :email, NULL, :faculty, :major, 4,
          :advisor_id, NULL, :status)'
    );
    $projectInsert = $pdo->prepare(
        'INSERT INTO projects
         (id, code, title, student_id, advisor_id, category, status, progress, updated_at)
         VALUES
         (:id, :code, :title, :student_id, :advisor_id, :category, :status, :progress, :updated_at)'
    );
    $studentProjectUpdate = $pdo->prepare(
        'UPDATE students SET project_id = :project_id WHERE id = :student_id'
    );
    $documentInsert = $pdo->prepare(
        'INSERT INTO documents
         (id, project_id, student_id, type, chapter, title, filename, size, status, uploaded_at)
         VALUES
         (:id, :project_id, :student_id, :type, :chapter, :title, :filename, :size, :status, :uploaded_at)'
    );

    $baseTitles = [
        'Student Project Tracking System with Artificial Intelligence',
        'Smart Classroom Attendance Management',
        'University Document Approval Workflow',
        'Research Archive Search Platform',
        'Academic Advisor Appointment System',
        'Campus Equipment Borrowing System',
        'Student Internship Progress Dashboard',
        'Digital Library Recommendation Platform',
        'University Event Registration System',
        'Senior Project Collaboration Portal',
    ];

    for ($number = 1; $number <= AI_TEST_PROJECT_COUNT; $number++) {
        $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        $studentId = AI_TEST_STUDENT_PREFIX . $suffix;
        $projectId = AI_TEST_PROJECT_PREFIX . $suffix;
        $assignedAdvisor = $number <= 45 ? AI_TEST_ADVISOR_ID : null;

        if ($number <= 10) {
            $status = 'Completed';
            $progress = 100;
            $daysAgo = 5 + $number;
        } elseif ($number <= 15) {
            $status = 'Draft';
            $progress = 85;
            $daysAgo = 2;
        } elseif ($number <= 30) {
            $status = 'Draft';
            $progress = 10;
            $daysAgo = 20;
        } elseif ($number <= 45) {
            $status = 'Pending';
            $progress = 5;
            $daysAgo = 40;
        } else {
            $status = 'Pending';
            $progress = 5;
            $daysAgo = 50;
        }

        $title = $baseTitles[($number - 1) % count($baseTitles)] . ' - Scenario ' . $suffix;
        if ($number === 1 || $number === AI_TEST_PROJECT_COUNT) {
            $title = 'Student Project Tracking System with Artificial Intelligence';
        }
        $updatedAt = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));

        $studentInsert->execute([
            'id' => $studentId,
            'code' => '0799901' . str_pad((string) $number, 5, '0', STR_PAD_LEFT) . '-0',
            'first_name' => 'AI Test',
            'last_name' => 'Student ' . $suffix,
            'email' => 'ai-test-student-' . $suffix . '@example.test',
            'faculty' => 'Test Faculty',
            'major' => 'Artificial Intelligence Test',
            'advisor_id' => $assignedAdvisor,
            'status' => $status === 'Completed' ? 'Completed' : 'Active',
        ]);
        $projectInsert->execute([
            'id' => $projectId,
            'code' => 'AI-TEST-2026-' . $suffix,
            'title' => $title,
            'student_id' => $studentId,
            'advisor_id' => $assignedAdvisor,
            'category' => 'AI Test Dataset',
            'status' => $status,
            'progress' => $progress,
            'updated_at' => $updatedAt,
        ]);
        $studentProjectUpdate->execute(['project_id' => $projectId, 'student_id' => $studentId]);

        // Critical scenarios combine old pending work with repeated revisions.
        if ($number > 45) {
            for ($chapter = 1; $chapter <= 5; $chapter++) {
                $documentInsert->execute([
                    'id' => 'AITD' . $suffix . $chapter,
                    'project_id' => $projectId,
                    'student_id' => $studentId,
                    'type' => 'draft',
                    'chapter' => $chapter,
                    'title' => 'AI risk test chapter ' . $chapter,
                    'filename' => 'ai-risk-test.pdf',
                    'size' => '1 KB',
                    'status' => $chapter === 5 ? 'Review' : 'NeedsRevision',
                    'uploaded_at' => $updatedAt,
                ]);
            }
        }
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}

$riskSummary = process_project_risk_scores(500);
$riskRows = $pdo->query(
    "SELECT risk_level, COUNT(*) AS total
     FROM project_risk_scores
     WHERE project_id LIKE 'AITESTPRJ%'
     GROUP BY risk_level"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$duplicateProjectId = AI_TEST_PROJECT_PREFIX . str_pad((string) AI_TEST_PROJECT_COUNT, 3, '0', STR_PAD_LEFT);
$duplicateTitle = 'Student Project Tracking System with Artificial Intelligence';
$job = queue_project_title_check($duplicateProjectId, $duplicateTitle);
if (!$job) throw new RuntimeException('Could not queue the 60-project title comparison.');

$deadline = microtime(true) + 60;
do {
    usleep(250000);
    $titleResult = latest_project_title_check($duplicateProjectId, (int) $job['id']) ?? [];
} while (microtime(true) < $deadline && !in_array($titleResult['status'] ?? '', ['completed', 'failed'], true));

$expected = ['low' => 15, 'watch' => 15, 'high' => 15, 'critical' => 15];
foreach ($expected as $level => $total) {
    if ((int) ($riskRows[$level] ?? 0) !== $total) {
        throw new RuntimeException("Expected {$total} {$level} projects, found " . (int) ($riskRows[$level] ?? 0) . '.');
    }
}
if (($titleResult['status'] ?? '') !== 'completed') {
    throw new RuntimeException('The title check did not complete within 60 seconds.');
}
if ((float) ($titleResult['max_similarity'] ?? 0) < 0.99 || ($titleResult['risk_level'] ?? '') !== 'high') {
    throw new RuntimeException('The exact duplicate among 60 projects was not detected.');
}

echo 'AI_TEST_DATA_READY projects=' . AI_TEST_PROJECT_COUNT
    . ' low=' . $expected['low'] . ' watch=' . $expected['watch']
    . ' high=' . $expected['high'] . ' critical=' . $expected['critical'] . PHP_EOL;
echo 'AI_TITLE_BATCH_OK candidates=59 score='
    . number_format((float) $titleResult['max_similarity'] * 100, 1)
    . '% engine=' . ($titleResult['engine'] ?? '') . PHP_EOL;
echo "Clean later with: php tests/seed-ai-projects.php --clean\n";
