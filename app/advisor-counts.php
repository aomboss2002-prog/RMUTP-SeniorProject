<?php
declare(strict_types=1);

/** Count current assignments, not invitation records or the cached advisors.students field. */
function admin_advisors_with_student_counts(array $data): array
{
    $assigned = [];
    $existingStudents = [];
    foreach ($data['students'] ?? [] as $student) {
        if (!empty($student['id'])) $existingStudents[(string) $student['id']] = true;
    }
    $advisorIds = static function (array $owner): array {
        // Roles are assigned when an invitation is accepted. An explicit empty
        // roles map is authoritative; do not revive a stale primary advisor.
        return array_key_exists('advisor_roles', $owner)
            ? array_values($owner['advisor_roles'] ?? [])
            : [(string) ($owner['advisor_id'] ?? '')];
    };
    $add = static function (string $studentId, array $ids) use (&$assigned, $existingStudents): void {
        if (!isset($existingStudents[$studentId])) return;
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($id !== '') $assigned[$id][$studentId] = true;
        }
    };
    $groupMembers = [];
    foreach ($data['groups'] ?? [] as $group) {
        foreach ($group['member_ids'] ?? [] as $studentId) {
            $studentId = (string) $studentId;
            $groupMembers[$studentId] = true;
            $add($studentId, $advisorIds($group));
        }
    }
    foreach ($data['students'] ?? [] as $student) {
        $id = (string) ($student['id'] ?? '');
        // Group membership is authoritative over old student-level assignments.
        if (!isset($groupMembers[$id])) $add($id, $advisorIds($student));
    }
    return array_values(array_map(static function (array $advisor) use ($assigned): array {
        $advisor['students'] = count($assigned[(string) ($advisor['id'] ?? '')] ?? []);
        unset($advisor['password_hash']);
        return $advisor;
    }, $data['advisors'] ?? []));
}
