<?php
declare(strict_types=1);

/** Request-local indexes; preserve source order and owner OR group semantics. */
function advisor_document_index(array $data): array
{
    $index = ['students' => [], 'groups' => [], 'membership' => []];
    foreach ($data['groups'] ?? [] as $group) {
        foreach ($group['member_ids'] ?? [] as $studentId) {
            if (is_string($studentId) && !array_key_exists($studentId, $index['membership'])) {
                $index['membership'][$studentId] = (string) ($group['id'] ?? '');
            }
        }
    }
    foreach (array_values($data['documents'] ?? []) as $position => $document) {
        $index['students'][(string) ($document['student_id'] ?? '')][$position] = $document;
        if (!empty($document['group_id'])) {
            $index['groups'][(string) $document['group_id']][$position] = $document;
        }
    }
    return $index;
}

function advisor_indexed_documents(array $index, string $studentId): array
{
    $groupId = $index['membership'][$studentId] ?? '';
    $documents = ($index['students'][$studentId] ?? []) + ($index['groups'][$groupId] ?? []);
    ksort($documents, SORT_NUMERIC);
    return array_values($documents);
}
