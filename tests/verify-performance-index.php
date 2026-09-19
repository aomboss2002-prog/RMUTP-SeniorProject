<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/advisor-document-index.php';

// Synthetic fixtures only: no database connection and no writes.
$data = ['groups' => [], 'documents' => []];
for ($i = 0; $i < 100; $i++) {
    $data['groups'][] = ['id' => 'G' . $i, 'member_ids' => ['S' . ($i * 2), 'S' . ($i * 2 + 1)]];
}
for ($i = 0; $i < 3000; $i++) {
    $data['documents'][] = ['id' => 'D' . $i, 'student_id' => 'S' . ($i % 240),
        'group_id' => $i % 3 ? 'G' . ($i % 100) : ''];
}
// Repeated membership must retain the first matching group.
$data['groups'][] = ['id' => 'OTHER', 'member_ids' => ['S0']];
$index = advisor_document_index($data);
for ($i = 0; $i < 250; $i++) {
    $studentId = 'S' . $i;
    $groupId = '';
    foreach ($data['groups'] as $group) {
        if (in_array($studentId, $group['member_ids'], true)) {
            $groupId = $group['id'];
            break;
        }
    }
    $expected = array_values(array_filter($data['documents'], static fn(array $document): bool =>
        $document['student_id'] === $studentId || (!empty($document['group_id']) && $document['group_id'] === $groupId)));
    if ($expected !== advisor_indexed_documents($index, $studentId)) {
        throw new RuntimeException('Document scope/order mismatch for ' . $studentId);
    }
}
if (advisor_indexed_documents(advisor_document_index([]), 'missing') !== []) {
    throw new RuntimeException('Empty input regression');
}
echo "PERFORMANCE_INDEX_OK: 250 students / 3000 documents, owner/group deduplication and order preserved\n";
