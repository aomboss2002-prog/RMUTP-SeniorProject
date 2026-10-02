<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/store.php';

// Pure workflow test; does not connect to the database.
$data = ['projects' => [['id' => 'P', 'status' => 'Pending']], 'documents' => []];
$data['documents'][] = ['project_id' => 'P', 'type' => 'proposal', 'status' => 'Approved'];
for ($chapter = 1; $chapter <= 5; $chapter++) {
    $data['documents'][] = ['project_id' => 'P', 'type' => 'draft', 'chapter' => $chapter, 'status' => 'Approved'];
}
$data['documents'][] = ['project_id' => 'P', 'type' => 'complete', 'status' => 'Approved'];
function completion_expect(array $data, int $progress, string $status): array {
    $result = apply_calculated_project_progress($data);
    if ($result['projects'][0]['progress'] !== $progress || $result['projects'][0]['status'] !== $status) {
        throw new RuntimeException('Completion status does not match workflow');
    }
    return $result;
}
$data = completion_expect($data, 100, 'Completed');
completion_expect($data, 100, 'Completed');
$data['documents'][6]['status'] = 'Review';
completion_expect($data, 85, 'Pending');
array_pop($data['documents']);
completion_expect($data, 70, 'Pending');
$data['documents'][5]['status'] = 'NeedsRevision';
completion_expect($data, 62, 'Pending');
$data['documents'][] = ['project_id' => 'P', 'type' => 'complete', 'status' => 'Approved'];
completion_expect($data, 62, 'Pending');
echo "PROJECT_COMPLETION_OK\n";
