<?php
declare(strict_types=1);

require __DIR__ . '/../app/store.php';
$data = load_data();
$errors = [];
$students = [];
foreach ($data['students'] ?? [] as $student) $students[$student['id']] = $student;
$groups = [];
foreach ($data['groups'] ?? [] as $group) $groups[$group['id']] = $group;

foreach ($data['groups'] ?? [] as $group) {
    $members = array_values(array_unique($group['member_ids'] ?? []));
    if (count($members) > 5) $errors[] = "{$group['id']}: more than five members";
    if (!in_array($group['leader_id'] ?? '', $members, true)) $errors[] = "{$group['id']}: leader is not a member";
    foreach ($members as $memberId) {
        if (!isset($students[$memberId])) $errors[] = "{$group['id']}: missing student {$memberId}";
        elseif (($students[$memberId]['faculty'] ?? '') !== ($group['faculty'] ?? '')) $errors[] = "{$group['id']}: faculty mismatch {$memberId}";
    }
    $roles = array_filter($group['advisor_roles'] ?? []);
    if (count($roles) !== count(array_unique($roles))) $errors[] = "{$group['id']}: duplicate committee member";
}

foreach ($data['students'] ?? [] as $student) {
    if (!in_array($student['faculty'] ?? '', app_faculties(), true)) {
        $errors[] = "{$student['id']}: invalid faculty";
    }
    if (!in_array($student['major'] ?? '', app_majors(), true)) {
        $errors[] = "{$student['id']}: invalid major";
    }
}

foreach ($data['advisors'] ?? [] as $advisor) {
    if (!in_array($advisor['faculty'] ?? '', app_faculties(), true)) {
        $errors[] = "{$advisor['id']}: invalid faculty";
    }
    if (!in_array($advisor['department'] ?? '', app_majors(), true)) {
        $errors[] = "{$advisor['id']}: invalid department";
    }
}

foreach ($data['documents'] ?? [] as $document) {
    if (!in_array($document['type'] ?? '', ['proposal', 'draft', 'complete'], true)) {
        $errors[] = "{$document['id']}: invalid document stage";
    }
}

foreach ($data['notifications'] ?? [] as $notification) {
    $hasScope = !empty($notification['group_id']) || !empty($notification['student_id'])
        || !empty($notification['advisor_id']) || in_array($notification['scope'] ?? '', ['system', 'legacy'], true);
    if (!$hasScope) $errors[] = "{$notification['id']}: notification has no audience scope";
    if (!empty($notification['group_id']) && !isset($groups[$notification['group_id']])) {
        $errors[] = "{$notification['id']}: notification references a missing group";
    }
}

foreach ($data['calendar'] ?? [] as $event) {
    if (empty($event['group_id']) || !isset($groups[$event['group_id']])) {
        $errors[] = "{$event['id']}: calendar event references a missing group";
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo "INVARIANTS_OK\n";
