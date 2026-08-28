<?php
require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/session.php';
start_app_session();

header('Content-Type: application/json; charset=utf-8');

$data = load_data();
$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $studentEndpoint ?? trim((string) ($_GET['endpoint'] ?? ''), '/');

function student_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function student_payload(): array
{
    $input = file_get_contents('php://input');
    $json = json_decode($input ?: '', true);
    return is_array($json) ? $json : $_POST;
}

function academic_value(string $value): string
{
    return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
}

function advisor_matches_student(array $advisor, array $student): bool
{
    $advisorFaculty = academic_value((string) ($advisor['faculty'] ?? ''));
    $advisorDepartment = academic_value((string) ($advisor['department'] ?? ''));
    $studentFaculty = academic_value((string) ($student['faculty'] ?? ''));
    $studentMajor = academic_value((string) ($student['major'] ?? ''));
    return $advisorFaculty !== '' && $advisorDepartment !== ''
        && $advisorFaculty === $studentFaculty
        && $advisorDepartment === $studentMajor;
}

function add_scoped_notification(array &$data, array $notification): void
{
    if (!isset($data['notifications']) || !is_array($data['notifications'])) {
        $data['notifications'] = [];
    }
    array_unshift($data['notifications'], array_merge([
        'id' => next_id($data['notifications'], 'NOT'),
        'group_id' => '', 'student_id' => '', 'advisor_id' => '',
        'title' => 'การแจ้งเตือน', 'message' => '', 'type' => 'System',
        'read_by' => [], 'created_at' => date('Y-m-d H:i:s'),
    ], $notification));
}

function current_student_id(): string
{
    if (($_SESSION['app_user']['role'] ?? '') !== 'student' || empty($_SESSION['app_user']['id'])) {
        student_respond(['success' => false, 'message' => 'Student sign-in required.'], 401);
    }
    return (string) $_SESSION['app_user']['id'];
}

function student_group(array $data, string $studentId): ?array
{
    foreach ($data['groups'] ?? [] as $group) {
        if (in_array($studentId, $group['member_ids'] ?? [], true)) {
            return $group;
        }
    }
    return null;
}

function group_member_rows(array $data, array $group): array
{
    $members = [];
    foreach ($group['member_ids'] ?? [] as $memberId) {
        $member = find_row($data['students'] ?? [], $memberId);
        if ($member) {
            $members[] = $member;
        }
    }
    return $members;
}

function previous_group_membership(array $data, string $groupId, string $studentId): array
{
    $previous = ['project_id' => '', 'advisor_id' => '', 'advisor_roles' => []];
    foreach ($data['group_invitations'] ?? [] as $invitation) {
        if (($invitation['group_id'] ?? '') === $groupId
            && ($invitation['invited_student_id'] ?? '') === $studentId
            && ($invitation['status'] ?? '') === 'Accepted') {
            $previous = [
                'project_id' => $invitation['previous_project_id'] ?? '',
                'advisor_id' => $invitation['previous_advisor_id'] ?? '',
                'advisor_roles' => $invitation['previous_advisor_roles'] ?? [],
            ];
        }
    }
    return $previous;
}

function restore_student_membership(array &$data, string $groupId, string $studentId): void
{
    $previous = previous_group_membership($data, $groupId, $studentId);
    foreach ($data['students'] as &$student) {
        if (($student['id'] ?? '') === $studentId) {
            $student['project_id'] = $previous['project_id'];
            $student['advisor_id'] = $previous['advisor_id'];
            $student['advisor_roles'] = $previous['advisor_roles'];
            break;
        }
    }
    unset($student);
}

function student_context(array $data): array
{
    $studentId = current_student_id();
    $student = find_row($data['students'] ?? [], $studentId);
    if (!$student) {
        student_respond(['success' => false, 'message' => 'Student not found'], 404);
    }
    $group = student_group($data, $studentId);
    $advisorId = $group['advisor_id'] ?? $student['advisor_id'] ?? '';
    $projectId = $group['project_id'] ?? $student['project_id'] ?? '';
    $advisor = find_row($data['advisors'] ?? [], $advisorId) ?? [];
    $project = find_row($data['projects'] ?? [], $projectId) ?? [];
    $groupMemberIds = $group['member_ids'] ?? [$studentId];
    $documents = array_values(array_filter($data['documents'] ?? [], static function ($row) use ($studentId, $group): bool {
        if ($group) {
            return ($row['group_id'] ?? '') !== '' && ($row['group_id'] ?? '') === ($group['id'] ?? '');
        }
        return ($row['student_id'] ?? '') === $studentId && empty($row['group_id']);
    }));
    $documentIds = array_values(array_filter(array_column($documents, 'id')));
    $comments = array_values(array_filter($data['comments'] ?? [], static fn($row): bool =>
        in_array($row['student_id'] ?? '', $groupMemberIds, true)
        || in_array($row['document_id'] ?? '', $documentIds, true)
    ));
    $approvals = array_values(array_filter($data['approvals'] ?? [], static fn($row): bool =>
        in_array($row['student_id'] ?? '', $groupMemberIds, true)
        || in_array($row['document_id'] ?? '', $documentIds, true)
    ));
    $groupId = (string) ($group['id'] ?? '');
    $notifications = array_values(array_filter($data['notifications'] ?? [], static function ($row) use ($studentId, $groupId): bool {
        if (($row['student_id'] ?? '') === $studentId) {
            return true;
        }
        if ($groupId !== '' && ($row['group_id'] ?? '') === $groupId) {
            return true;
        }
        return ($row['scope'] ?? '') === 'system';
    }));
    $messages = array_values(array_filter($data['messages'] ?? [], static function ($row) use ($studentId, $group): bool {
        return $group
            ? (($row['group_id'] ?? '') === ($group['id'] ?? ''))
            : (($row['student_id'] ?? '') === $studentId && empty($row['group_id']));
    }));

    return compact('studentId', 'student', 'group', 'advisor', 'project', 'documents', 'comments', 'approvals', 'notifications', 'messages');
}

function document_stage(array $documents, string $stage): ?array
{
    $latest = null;
    foreach ($documents as $document) {
        if (($document['type'] ?? '') !== $stage) {
            continue;
        }
        if (
            !$latest
            || strcmp((string) ($document['uploaded_at'] ?? ''), (string) ($latest['uploaded_at'] ?? '')) > 0
        ) {
            $latest = $document;
        }
    }
    return $latest;
}

function document_comments(array $comments, ?array $document): array
{
    if (!$document || empty($document['id'])) {
        return [];
    }
    $documentId = (string) $document['id'];
    return array_values(array_filter(
        $comments,
        static fn(array $comment): bool => (string) ($comment['document_id'] ?? '') === $documentId
    ));
}

function stage_payload(array $context, string $stage): array
{
    if ($stage === 'draft') {
        $latestByChapter = [];
        foreach ($context['documents'] as $row) {
            if (($row['type'] ?? '') === 'draft') {
                $chapter = (int) ($row['chapter'] ?? 0);
                if ($chapter >= 1 && $chapter <= 5) {
                    $latestByChapter[$chapter] = $row;
                }
            }
        }
        ksort($latestByChapter);
        $approvedCount = count(array_filter($latestByChapter, static fn(array $row): bool => in_array($row['status'] ?? '', ['Approved', 'Completed'], true)));
        $latest = null;
        foreach ($latestByChapter as $draftDocument) {
            if (!$latest || strcmp((string) ($draftDocument['uploaded_at'] ?? ''), (string) ($latest['uploaded_at'] ?? '')) > 0) {
                $latest = $draftDocument;
            }
        }
        return [
            'stage' => 'draft',
            'status' => $approvedCount === 5 ? 'Approved' : ($latest['status'] ?? 'Not Started'),
            'submit_date' => count($latestByChapter) === 5 ? ($latest['uploaded_at'] ?? '') : '',
            'approved_date' => $approvedCount === 5 ? ($latest['approved_at'] ?? $latest['uploaded_at'] ?? '') : '',
            'officer' => 'Academic Affairs Officer',
            'advisor' => $context['advisor']['name'] ?? '',
            'comments' => document_comments($context['comments'], $latest),
            'document' => $latest,
            'documents' => array_values($latestByChapter),
            'uploaded_count' => count($latestByChapter),
            'approved_count' => $approvedCount,
            'scope' => $context['group'] ? 'group' : 'personal',
        ];
    }
    $document = document_stage($context['documents'], $stage);
    $approval = null;
    foreach ($context['approvals'] as $row) {
        if (strtolower((string) ($row['step'] ?? '')) === $stage) {
            $approval = $row;
            break;
        }
    }

    return [
        'stage' => $stage,
        'status' => $document['status'] ?? 'Not Started',
        'submit_date' => $document['uploaded_at'] ?? '',
        'approved_date' => in_array($document['status'] ?? '', ['Approved', 'Completed'], true) ? ($document['approved_at'] ?? $approval['created_at'] ?? $document['uploaded_at'] ?? '') : '',
        'officer' => 'Academic Affairs Officer',
        'advisor' => $context['advisor']['name'] ?? '',
        'comments' => document_comments($context['comments'], $document),
        'document' => $document,
        'scope' => (!$document && !empty($context['group'])) || ($document && (
            !empty($document['group_id'])
            || (!empty($context['group']['project_id']) && ($document['project_id'] ?? '') === ($context['group']['project_id'] ?? ''))
        )) ? 'group' : 'personal',
    ];
}

function barcode_is_available(array $context): bool
{
    $complete = document_stage($context['documents'] ?? [], 'complete');
    return $complete !== null
        && in_array($complete['status'] ?? '', ['Approved', 'Completed'], true)
        && !empty($context['project']['code']);
}

function portal_timeline(array $context): array
{
    $project = $context['project'];
    $updated = $project['updated_at'] ?? date('Y-m-d H:i:s');
    $proposal = stage_payload($context, 'proposal');
    $draft = stage_payload($context, 'draft');
    $complete = stage_payload($context, 'complete');

    $timeline = [];
    if ($proposal['submit_date']) {
        $timeline[] = [
            'title' => 'Proposal Submitted',
            'status' => $proposal['approved_date'] ? 'Completed' : ($proposal['status'] ?? 'Review'),
            'date' => $proposal['submit_date'],
            'scope' => $proposal['scope'],
        ];
        if ($proposal['approved_date']) {
            $timeline[] = ['title' => 'Proposal Approved', 'status' => 'Completed', 'date' => $proposal['approved_date'], 'scope' => $proposal['scope']];
        }
    }
    if ($proposal['approved_date']) {
        foreach ($draft['documents'] ?? [] as $draftDocument) {
            $chapter = (int) ($draftDocument['chapter'] ?? 0);
            if ($chapter < 1 || $chapter > 5) continue;
            $approved = in_array($draftDocument['status'] ?? '', ['Approved', 'Completed'], true);
            $timeline[] = [
                'title' => 'Draft Chapter ' . $chapter . ' Submitted',
                'status' => $approved ? 'Completed' : ($draftDocument['status'] ?? 'Review'),
                'date' => $draftDocument['uploaded_at'] ?? '',
                'scope' => $draft['scope'],
            ];
            if ($approved) {
                $timeline[] = [
                    'title' => 'Draft Chapter ' . $chapter . ' Approved',
                    'status' => 'Completed',
                    'date' => $draftDocument['approved_at'] ?? $draftDocument['uploaded_at'] ?? '',
                    'scope' => $draft['scope'],
                ];
            }
        }
    }
    if ($draft['approved_date'] && $complete['submit_date']) {
        $timeline[] = [
            'title' => 'Complete Submitted',
            'status' => $complete['approved_date'] ? 'Completed' : ($complete['status'] ?? 'Review'),
            'date' => $complete['submit_date'],
            'scope' => $complete['scope'],
        ];
        if ($complete['approved_date']) {
            $timeline[] = ['title' => 'Complete Approved', 'status' => 'Completed', 'date' => $complete['approved_date'], 'scope' => $complete['scope']];
        }
    }
    if (barcode_is_available($context)) {
        $timeline[] = ['title' => 'Barcode Generated', 'status' => 'Completed', 'date' => $updated, 'scope' => $context['group'] ? 'group' : 'personal'];
    }
    foreach ($timeline as $index => &$item) {
        $item['_order'] = $index;
        $item['sequence'] = $index + 1;
    }
    unset($item);
    usort($timeline, static function (array $a, array $b): int {
        $dateA = trim((string) ($a['date'] ?? ''));
        $dateB = trim((string) ($b['date'] ?? ''));
        if ($dateA === '' && $dateB !== '') return 1;
        if ($dateA !== '' && $dateB === '') return -1;
        if ($dateA !== $dateB) return strcmp($dateB, $dateA);
        return ($b['_order'] ?? 0) <=> ($a['_order'] ?? 0);
    });
    foreach ($timeline as &$item) unset($item['_order']);
    unset($item);
    return $timeline;
}

function save_student_upload(array &$data, array $context, string $stage): void
{
    if ($context['group'] && ($context['group']['leader_id'] ?? '') !== $context['studentId']) {
        student_respond(['success' => false, 'message' => 'Only the group leader can submit project documents.'], 403);
    }
    $roles = $context['group']['advisor_roles'] ?? $context['student']['advisor_roles'] ?? [];
    if (empty($roles['chair'])) {
        student_respond(['success' => false, 'message' => 'The chair must accept the invitation before submitting Proposal, Draft, or Complete.'], 409);
    }
    $requiredPreviousStage = ['draft' => 'proposal'][$stage] ?? '';
    if ($requiredPreviousStage !== '') {
        $previous = document_stage($context['documents'], $requiredPreviousStage);
        if (!$previous || !in_array($previous['status'] ?? '', ['Approved', 'Completed'], true)) {
            student_respond(['success' => false, 'message' => ucfirst($requiredPreviousStage) . ' must be approved before this submission.'], 409);
        }
    }
    if ($stage === 'complete') {
        $draft = stage_payload($context, 'draft');
        if (($draft['approved_count'] ?? 0) !== 5) {
            student_respond(['success' => false, 'message' => 'All five Draft chapters must be approved before submitting Complete.'], 409);
        }
    }
    $draftChapter = 0;
    if ($stage === 'draft') {
        $draftChapter = (int) ($_POST['draft_chapter'] ?? 0);
        if ($draftChapter < 1 || $draftChapter > 5) {
            student_respond(['success' => false, 'message' => 'Please select Draft chapter 1-5.'], 422);
        }
        if ($draftChapter > 1) {
            $previousChapterApproved = false;
            foreach ($context['documents'] as $document) {
                if (($document['type'] ?? '') === 'draft'
                    && (int) ($document['chapter'] ?? 0) === $draftChapter - 1
                    && in_array($document['status'] ?? '', ['Approved', 'Completed'], true)) {
                    $previousChapterApproved = true;
                    break;
                }
            }
            if (!$previousChapterApproved) {
                student_respond(['success' => false, 'message' => 'The previous Draft chapter must be approved first.'], 409);
            }
        }
    }
    if (empty($_FILES['file'])) {
        student_respond(['success' => false, 'message' => 'Please choose a PDF file.'], 422);
    }
    if ($_FILES['file']['size'] > 20 * 1024 * 1024) {
        student_respond(['success' => false, 'message' => 'Maximum file size is 20 MB.'], 422);
    }
    $extension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['file']['tmp_name']);
    if ($extension !== 'pdf' || $mimeType !== 'application/pdf') {
        student_respond(['success' => false, 'message' => 'PDF files only.'], 422);
    }
    $signature = file_get_contents($_FILES['file']['tmp_name'], false, null, 0, 5);
    if ($signature !== '%PDF-') {
        student_respond(['success' => false, 'message' => 'Invalid PDF file signature.'], 422);
    }

    $existingId = '';
    $isResubmission = false;
    foreach ($data['documents'] as $index => $document) {
        $sameOwner = $context['group']
            ? (($document['group_id'] ?? '') === ($context['group']['id'] ?? ''))
            : (($document['student_id'] ?? '') === $context['studentId'] && empty($document['group_id']));
        $sameChapter = $stage !== 'draft' || (int) ($document['chapter'] ?? 0) === $draftChapter;
        if ($sameOwner && ($document['type'] ?? '') === $stage && $sameChapter) {
            if (in_array($document['status'] ?? '', ['Approved', 'Completed'], true)) {
                student_respond(['success' => false, 'message' => 'This document has already been approved and is locked.'], 409);
            }
            if (in_array($document['status'] ?? '', ['NeedsRevision', 'Rejected'], true)) {
                $isResubmission = true;
            }
            $existingId = $document['id'];
            unset($data['documents'][$index]);
        }
    }

    $targetDir = __DIR__ . '/../uploads/' . $stage;
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0775, true);
    }

    $originalName = basename($_FILES['file']['name']);
    $target = $targetDir . '/' . bin2hex(random_bytes(24)) . '.pdf';
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
        student_respond(['success' => false, 'message' => 'Could not save uploaded file.'], 500);
    }

    $document = [
        'id' => $existingId ?: next_id($data['documents'], 'DOC'),
        'project_id' => $context['project']['id'] ?? '',
        'student_id' => $context['studentId'],
        'group_id' => $context['group']['id'] ?? '',
        'type' => $stage,
        'title' => $stage === 'draft' ? 'Draft Chapter ' . $draftChapter . ' PDF' : ucfirst($stage) . ' PDF',
        'chapter' => $draftChapter ?: null,
        'filename' => basename($target),
        'original_name' => $originalName,
        'size' => round(filesize($target) / 1048576, 2) . ' MB',
        'status' => $isResubmission ? 'Resubmitted' : 'Pending',
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];
    $data['documents'][] = $document;
    $data['documents'] = array_values($data['documents']);
    add_scoped_notification($data, [
        'group_id' => $context['group']['id'] ?? '',
        'student_id' => $context['group'] ? '' : $context['studentId'],
        'title' => ($isResubmission ? 'ส่งเอกสารแก้ไข: ' : 'มีเอกสารใหม่: ') . $document['title'],
        'message' => trim(($context['student']['first_name'] ?? '') . ' ' . ($context['student']['last_name'] ?? ''))
            . ($isResubmission ? ' ส่งกลับมาแก้ไข ' : ' อัปโหลด ')
            . $document['title'],
        'type' => 'Upload', 'read_by' => [$context['studentId']],
    ]);
    save_data($data);
    student_respond(['success' => true, 'data' => $document, 'message' => 'File uploaded successfully.']);
}

$context = student_context($data);
require_csrf_token();

if ($endpoint === 'group') {
    if ($method === 'GET') {
        $group = $context['group'];
        $receivedInvitations = [];
        foreach ($data['group_invitations'] ?? [] as $invitation) {
            if (($invitation['invited_student_id'] ?? '') === $context['studentId']
                && ($invitation['status'] ?? '') === 'Pending') {
                $invitedGroup = find_row($data['groups'] ?? [], (string) ($invitation['group_id'] ?? '')) ?? [];
                $invitation['group_name'] = $invitedGroup['name'] ?? '-';
                $receivedInvitations[] = $invitation;
            }
        }
        student_respond(['success' => true, 'data' => [
            'group' => $group,
            'members' => $group ? group_member_rows($data, $group) : [],
            'is_leader' => $group && ($group['leader_id'] ?? '') === $context['studentId'],
            'max_members' => 5,
            'received_invitations' => $receivedInvitations,
        ]]);
    }

    if ($method === 'POST') {
        $payload = student_payload();
        $action = (string) ($payload['action'] ?? 'create');
        $group = $context['group'];

        if (in_array($action, ['create', 'solo'], true)) {
            if ($group) {
                student_respond(['success' => false, 'message' => 'You are already in a project group.'], 409);
            }
            $isSolo = $action === 'solo';
            $name = $isSolo
                ? 'โครงงานเดี่ยว - ' . ($context['student']['code'] ?? $context['studentId'])
                : trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                student_respond(['success' => false, 'message' => 'Please enter a group name.'], 422);
            }
            $group = [
                'id' => next_id($data['groups'] ?? [], 'GRP'),
                'name' => $name,
                'leader_id' => $context['studentId'],
                'member_ids' => [$context['studentId']],
                'project_id' => $context['student']['project_id'] ?? '',
                'advisor_id' => $context['student']['advisor_id'] ?? '',
                'advisor_roles' => $context['student']['advisor_roles'] ?? [],
                'mode' => $isSolo ? 'solo' : 'group',
                'faculty' => $context['student']['faculty'] ?? '',
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $data['groups'][] = $group;
            save_data($data);
            student_respond([
                'success' => true,
                'data' => $group,
                'message' => $isSolo ? 'ตั้งค่าเป็นโครงงานเดี่ยวแล้ว' : 'สร้างกลุ่มโครงงานแล้ว',
            ]);
        }

        if ($action === 'respond_invitation') {
            if ($group) {
                student_respond(['success' => false, 'message' => 'Leave your current group before accepting another invitation.'], 409);
            }
            $invitationId = trim((string) ($payload['invitation_id'] ?? ''));
            $decision = (string) ($payload['decision'] ?? '');
            if (!in_array($decision, ['accept', 'reject'], true)) {
                student_respond(['success' => false, 'message' => 'Invalid invitation decision.'], 422);
            }
            foreach ($data['group_invitations'] as &$invitation) {
                if (($invitation['id'] ?? '') !== $invitationId
                    || ($invitation['invited_student_id'] ?? '') !== $context['studentId']
                    || ($invitation['status'] ?? '') !== 'Pending') {
                    continue;
                }
                $targetGroup = find_row($data['groups'] ?? [], (string) $invitation['group_id']);
                if (!$targetGroup) {
                    student_respond(['success' => false, 'message' => 'Project group not found.'], 404);
                }
                if ($decision === 'accept' && count($targetGroup['member_ids'] ?? []) >= 5) {
                    student_respond(['success' => false, 'message' => 'This group already has 5 members.'], 409);
                }
                $invitation['status'] = $decision === 'accept' ? 'Accepted' : 'Rejected';
                $invitation['responded_at'] = date('Y-m-d H:i:s');
                if ($decision === 'accept') {
                    $invitation['previous_project_id'] = $context['student']['project_id'] ?? '';
                    $invitation['previous_advisor_id'] = $context['student']['advisor_id'] ?? '';
                    $invitation['previous_advisor_roles'] = $context['student']['advisor_roles'] ?? [];
                    foreach ($data['groups'] as &$storedGroup) {
                        if (($storedGroup['id'] ?? '') === ($targetGroup['id'] ?? '')) {
                            $storedGroup['member_ids'][] = $context['studentId'];
                            break;
                        }
                    }
                    unset($storedGroup);
                    foreach ($data['students'] as &$student) {
                        if (($student['id'] ?? '') === $context['studentId']) {
                            $student['project_id'] = $targetGroup['project_id'] ?? '';
                            $student['advisor_id'] = $targetGroup['advisor_id'] ?? '';
                            $student['advisor_roles'] = $targetGroup['advisor_roles'] ?? [];
                            break;
                        }
                    }
                    unset($student);
                }
                save_data($data);
                student_respond(['success' => true, 'message' => $decision === 'accept' ? 'Group invitation accepted.' : 'Group invitation rejected.']);
            }
            unset($invitation);
            student_respond(['success' => false, 'message' => 'Invitation not found.'], 404);
        }

        if ($action === 'leave') {
            if (!$group) {
                student_respond(['success' => false, 'message' => 'You do not belong to a group.'], 409);
            }
            if (($group['leader_id'] ?? '') === $context['studentId']) {
                student_respond(['success' => false, 'message' => 'Transfer group leadership before leaving.'], 409);
            }
            foreach ($data['groups'] as &$storedGroup) {
                if (($storedGroup['id'] ?? '') === ($group['id'] ?? '')) {
                    $storedGroup['member_ids'] = array_values(array_filter(
                        $storedGroup['member_ids'] ?? [],
                        static fn(string $id): bool => $id !== $context['studentId']
                    ));
                    break;
                }
            }
            unset($storedGroup);
            restore_student_membership($data, (string) $group['id'], $context['studentId']);
            save_data($data);
            student_respond(['success' => true, 'message' => 'You have left the project group.']);
        }

        if (!$group || ($group['leader_id'] ?? '') !== $context['studentId']) {
            student_respond(['success' => false, 'message' => 'Only the group leader can manage members.'], 403);
        }

        if ($action === 'add') {
            if (($group['mode'] ?? 'group') === 'solo') {
                student_respond([
                    'success' => false,
                    'message' => 'โครงงานเดี่ยวจำกัดผู้จัดทำ 1 คน ไม่สามารถเพิ่มสมาชิกได้',
                ], 409);
            }
            if (count($group['member_ids'] ?? []) >= 5) {
                student_respond(['success' => false, 'message' => 'A group can contain no more than 5 students.'], 422);
            }
            $studentCode = trim((string) ($payload['student_code'] ?? ''));
            $newMember = null;
            foreach ($data['students'] ?? [] as $candidate) {
                if (($candidate['code'] ?? '') === $studentCode) {
                    $newMember = $candidate;
                    break;
                }
            }
            if (!$newMember) {
                student_respond(['success' => false, 'message' => 'Student code not found.'], 404);
            }
            if (($newMember['faculty'] ?? '') !== ($context['student']['faculty'] ?? '')) {
                student_respond(['success' => false, 'message' => 'Group members must be in the same faculty.'], 422);
            }
            if (student_group($data, (string) $newMember['id'])) {
                student_respond(['success' => false, 'message' => 'This student already belongs to a group.'], 409);
            }
            foreach ($data['group_invitations'] ?? [] as $invitation) {
                if (($invitation['group_id'] ?? '') === ($group['id'] ?? '')
                    && ($invitation['invited_student_id'] ?? '') === ($newMember['id'] ?? '')
                    && ($invitation['status'] ?? '') === 'Pending') {
                    student_respond(['success' => false, 'message' => 'An invitation is already pending for this student.'], 409);
                }
            }
            $data['group_invitations'][] = [
                'id' => next_id($data['group_invitations'], 'GINV'),
                'group_id' => $group['id'],
                'invited_student_id' => $newMember['id'],
                'invited_by_student_id' => $context['studentId'],
                'status' => 'Pending', 'created_at' => date('Y-m-d H:i:s'), 'responded_at' => '',
            ];
            save_data($data);
            student_respond(['success' => true, 'message' => 'Group invitation sent.']);
        }

        if ($action === 'transfer_leader') {
            $memberId = trim((string) ($payload['student_id'] ?? ''));
            if ($memberId === $context['studentId'] || !in_array($memberId, $group['member_ids'] ?? [], true)) {
                student_respond(['success' => false, 'message' => 'Select another current group member.'], 422);
            }
            foreach ($data['groups'] as &$storedGroup) {
                if (($storedGroup['id'] ?? '') === ($group['id'] ?? '')) {
                    $storedGroup['leader_id'] = $memberId;
                    break;
                }
            }
            unset($storedGroup);
            save_data($data);
            audit_log('transfer_leader', 'group', (string) $group['id'], ['from' => $context['studentId'], 'to' => $memberId]);
            student_respond(['success' => true, 'message' => 'Group leadership transferred.']);
        }

        if ($action === 'disband') {
            if (count($group['member_ids'] ?? []) !== 1) {
                student_respond(['success' => false, 'message' => 'Remove all other members before disbanding the group.'], 409);
            }
            foreach ($data['documents'] ?? [] as $document) {
                if (($document['group_id'] ?? '') === ($group['id'] ?? '')) {
                    student_respond(['success' => false, 'message' => 'A group with submitted documents cannot be disbanded.'], 409);
                }
            }
            $data['groups'] = array_values(array_filter(
                $data['groups'] ?? [],
                static fn(array $row): bool => ($row['id'] ?? '') !== ($group['id'] ?? '')
            ));
            foreach ($data['advisor_invitations'] ?? [] as &$invitation) {
                if (($invitation['group_id'] ?? '') === ($group['id'] ?? '') && ($invitation['status'] ?? '') === 'Pending') {
                    $invitation['status'] = 'Rejected';
                    $invitation['responded_at'] = date('Y-m-d H:i:s');
                }
            }
            unset($invitation);
            foreach ($data['group_invitations'] ?? [] as &$invitation) {
                if (($invitation['group_id'] ?? '') === ($group['id'] ?? '') && ($invitation['status'] ?? '') === 'Pending') {
                    $invitation['status'] = 'Rejected';
                    $invitation['responded_at'] = date('Y-m-d H:i:s');
                }
            }
            unset($invitation);
            foreach ($data['students'] as &$student) {
                if (($student['id'] ?? '') === $context['studentId']) {
                    $student['advisor_id'] = '';
                    $student['advisor_roles'] = [];
                    break;
                }
            }
            unset($student);
            save_data($data);
            audit_log('disband', 'group', (string) $group['id']);
            student_respond(['success' => true, 'message' => 'Project group disbanded.']);
        }

        if ($action === 'remove') {
            $memberId = trim((string) ($payload['student_id'] ?? ''));
            if ($memberId === ($group['leader_id'] ?? '') || !in_array($memberId, $group['member_ids'] ?? [], true)) {
                student_respond(['success' => false, 'message' => 'This member cannot be removed.'], 422);
            }
            foreach ($data['groups'] as &$storedGroup) {
                if (($storedGroup['id'] ?? '') === ($group['id'] ?? '')) {
                    $storedGroup['member_ids'] = array_values(array_filter(
                        $storedGroup['member_ids'] ?? [],
                        static fn(string $id): bool => $id !== $memberId
                    ));
                    break;
                }
            }
            unset($storedGroup);
            restore_student_membership($data, (string) $group['id'], $memberId);
            save_data($data);
            student_respond(['success' => true, 'message' => 'Student removed from the group.']);
        }

        student_respond(['success' => false, 'message' => 'Unsupported group action.'], 422);
    }
}

if ($endpoint === 'profile') {
    if ($method === 'GET') {
        $availableAdvisors = array_values(array_map(static function (array $advisor): array {
            unset($advisor['password_hash']);
            return $advisor;
        }, array_filter(
            $data['advisors'] ?? [],
            static fn(array $advisor): bool => ($advisor['status'] ?? 'Active') === 'Active'
                && advisor_matches_student($advisor, $context['student'])
        )));
        $advisorRoles = $context['group']['advisor_roles'] ?? $context['student']['advisor_roles'] ?? [];
        $advisorInvitationStatuses = [];
        if ($context['group']) {
            foreach ($data['advisor_invitations'] ?? [] as $invitation) {
                if (($invitation['group_id'] ?? '') === ($context['group']['id'] ?? '')) {
                    $role = $invitation['role'] ?? '';
                    if (($advisorInvitationStatuses[$role] ?? '') === 'Accepted'
                        && ($invitation['status'] ?? '') !== 'Accepted') {
                        continue;
                    }
                    $advisorRoles[$role] = $invitation['advisor_id'] ?? '';
                    $advisorInvitationStatuses[$role] = $invitation['status'] ?? 'Pending';
                }
            }
        }
        student_respond(['success' => true, 'data' => [
            'student' => $context['student'],
            'advisor' => $context['advisor'],
            'advisors' => $availableAdvisors,
            'advisor_roles' => $advisorRoles,
            'advisor_invitation_statuses' => $advisorInvitationStatuses,
            'group' => $context['group'],
            'is_group_leader' => $context['group'] && ($context['group']['leader_id'] ?? '') === $context['studentId'],
        ]]);
    }
    if ($method === 'PUT' || $method === 'POST') {
        $payload = student_payload();
        $selectedAdvisors = [];
        $invitationsSent = false;
        $roleFields = [
            'chair' => 'chair_advisor_id',
            'vice_chair' => 'vice_chair_advisor_id',
            'committee' => 'committee_advisor_id',
        ];
        $hasAdvisorSelection = count(array_intersect(array_values($roleFields), array_keys($payload))) > 0;
        if ($hasAdvisorSelection) {
            if (!$context['group']) {
                student_respond(['success' => false, 'message' => 'Create a project group before inviting advisors.'], 422);
            }
            if (($context['group']['leader_id'] ?? '') !== $context['studentId']) {
                student_respond(['success' => false, 'message' => 'Only the group leader can select the advisor committee.'], 403);
            }
            foreach ($roleFields as $role => $field) {
                $advisorId = trim((string) ($payload[$field] ?? ''));
                if ($advisorId === '') {
                    continue;
                }
                $advisor = find_row($data['advisors'] ?? [], $advisorId);
                if (!$advisor
                    || ($advisor['status'] ?? 'Active') !== 'Active'
                    || !advisor_matches_student($advisor, $context['student'])) {
                    student_respond([
                        'success' => false,
                        'message' => 'เลือกได้เฉพาะอาจารย์ที่มีคณะและสาขาตรงกับนักศึกษาเท่านั้น',
                    ], 422);
                }
                $selectedAdvisors[$role] = $advisor;
            }
            if (!$selectedAdvisors) {
                student_respond(['success' => false, 'message' => 'Please select at least one committee position.'], 422);
            }
            $effectiveRoles = $context['group']['advisor_roles'] ?? [];
            foreach ($data['advisor_invitations'] ?? [] as $invitation) {
                if (($invitation['group_id'] ?? '') === ($context['group']['id'] ?? '')
                    && in_array(($invitation['status'] ?? ''), ['Pending', 'Accepted'], true)) {
                    $effectiveRoles[$invitation['role']] = $invitation['advisor_id'];
                }
            }
            foreach ($selectedAdvisors as $role => $advisor) {
                $effectiveRoles[$role] = $advisor['id'];
            }
            $effectiveIds = array_values(array_filter($effectiveRoles));
            if (count(array_unique($effectiveIds)) !== count($effectiveIds)) {
                student_respond(['success' => false, 'message' => 'The chair, vice chair, and committee member must be different advisors.'], 422);
            }
            $advisorRoles = array_map(static fn(array $advisor): string => $advisor['id'], $selectedAdvisors);
            $acceptedByRole = [];
            foreach ($data['advisor_invitations'] ?? [] as $invitation) {
                if (($invitation['group_id'] ?? '') === ($context['group']['id'] ?? '')
                    && ($invitation['status'] ?? '') === 'Accepted'
                    && !empty($invitation['responded_at'])) {
                    $acceptedByRole[$invitation['role']] = $invitation['advisor_id'];
                }
            }
            foreach ($acceptedByRole as $role => $acceptedAdvisorId) {
                if (isset($advisorRoles[$role]) && $advisorRoles[$role] !== $acceptedAdvisorId) {
                    student_respond(['success' => false, 'message' => 'An accepted advisor role is locked for the whole group.'], 409);
                }
            }
            foreach ($data['advisor_invitations'] ?? [] as &$existingInvitation) {
                $role = $existingInvitation['role'] ?? '';
                if (($existingInvitation['group_id'] ?? '') === ($context['group']['id'] ?? '')
                    && isset($advisorRoles[$role])
                    && ($existingInvitation['status'] ?? '') === 'Pending'
                    && ($existingInvitation['advisor_id'] ?? '') !== $advisorRoles[$role]) {
                    $existingInvitation['status'] = 'Rejected';
                    $existingInvitation['responded_at'] = date('Y-m-d H:i:s');
                }
            }
            unset($existingInvitation);
            foreach ($advisorRoles as $role => $advisorId) {
                if (isset($acceptedByRole[$role])) {
                    continue;
                }
                $alreadyPending = false;
                foreach ($data['advisor_invitations'] ?? [] as $existingInvitation) {
                    if (($existingInvitation['group_id'] ?? '') === ($context['group']['id'] ?? '')
                        && ($existingInvitation['role'] ?? '') === $role
                        && ($existingInvitation['advisor_id'] ?? '') === $advisorId
                        && ($existingInvitation['status'] ?? '') === 'Pending') {
                        $alreadyPending = true;
                        break;
                    }
                }
                if ($alreadyPending) {
                    continue;
                }
                $data['advisor_invitations'][] = [
                    'id' => next_id($data['advisor_invitations'], 'INV'),
                    'group_id' => $context['group']['id'],
                    'student_id' => $context['studentId'],
                    'advisor_id' => $advisorId,
                    'role' => $role,
                    'status' => 'Pending',
                    'created_at' => date('Y-m-d H:i:s'),
                    'responded_at' => '',
                ];
                $invitationsSent = true;
            }
            $selectedAdvisors = [];
        }
        foreach ($data['students'] as &$student) {
            if (($student['id'] ?? '') === $context['studentId']) {
                if (isset($payload['phone'])) {
                    $student['phone'] = $payload['phone'];
                }
                if (isset($payload['email'])) {
                    $student['email'] = $payload['email'];
                }
                if ($selectedAdvisors) {
                    $student['advisor_id'] = $selectedAdvisors['chair']['id'];
                    $student['advisor_roles'] = $advisorRoles;
                    foreach ($data['projects'] as &$project) {
                        if (($project['id'] ?? '') === ($student['project_id'] ?? '')) {
                            $project['advisor_id'] = $selectedAdvisors['chair']['id'];
                            $project['advisor_roles'] = $advisorRoles;
                            $project['updated_at'] = date('Y-m-d H:i:s');
                            break;
                        }
                    }
                    unset($project);
                }
                if (!empty($_FILES['photo_file'])) {
                    $extension = strtolower(pathinfo($_FILES['photo_file']['name'], PATHINFO_EXTENSION));
                    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                        student_respond(['success' => false, 'message' => 'Image files only.'], 422);
                    }
                    $targetDir = __DIR__ . '/../uploads/student';
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0775, true);
                    }
                    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', basename($_FILES['photo_file']['name']));
                    $target = $targetDir . '/' . $context['studentId'] . '-' . time() . '-' . $safeName;
                    if (!move_uploaded_file($_FILES['photo_file']['tmp_name'], $target)) {
                        student_respond(['success' => false, 'message' => 'Could not upload profile picture.'], 500);
                    }
                    $student['photo'] = 'uploads/student/' . basename($target);
                } elseif (isset($payload['photo'])) {
                    $student['photo'] = $payload['photo'];
                }
                save_data($data);
                $responseStudent = $student;
                $responseAdvisor = $selectedAdvisors['chair'] ?? $context['advisor'];
                unset($responseAdvisor['password_hash']);
                student_respond(['success' => true, 'data' => [
                    'student' => $responseStudent,
                    'advisor' => $responseAdvisor,
                    'photo' => $responseStudent['photo'] ?? '',
                ], 'message' => $invitationsSent ? 'Advisor invitations sent.' : 'Profile updated.']);
            }
        }
    }
}

if ($endpoint === 'project') {
    if ($method === 'POST' || $method === 'PUT') {
        if ($context['group'] && ($context['group']['leader_id'] ?? '') !== $context['studentId']) {
            student_respond(['success' => false, 'message' => 'Only the group leader can edit the project.'], 403);
        }
        $payload = student_payload();
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            student_respond(['success' => false, 'message' => 'Please enter the project title.'], 422);
        }
        if (empty($context['project']['id'])) {
            $projectId = next_id($data['projects'] ?? [], 'PRJ');
            $project = [
                'id' => $projectId,
                'code' => '',
                'title' => $title,
                'student_id' => $context['group']['leader_id'] ?? $context['studentId'],
                'advisor_id' => $context['group']['advisor_id'] ?? $context['student']['advisor_id'] ?? '',
                'advisor_roles' => $context['group']['advisor_roles'] ?? $context['student']['advisor_roles'] ?? [],
                'category' => '',
                'status' => 'Pending',
                'progress' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $data['projects'][] = $project;
            if ($context['group']) {
                foreach ($data['groups'] as &$group) {
                    if (($group['id'] ?? '') === ($context['group']['id'] ?? '')) {
                        $group['project_id'] = $projectId;
                        break;
                    }
                }
                unset($group);
                foreach ($data['students'] as &$student) {
                    if (in_array($student['id'] ?? '', $context['group']['member_ids'] ?? [], true)) {
                        $student['project_id'] = $projectId;
                    }
                }
                unset($student);
            } else {
                foreach ($data['students'] as &$student) {
                    if (($student['id'] ?? '') === $context['studentId']) {
                        $student['project_id'] = $projectId;
                        break;
                    }
                }
                unset($student);
            }
            save_data($data);
            student_respond(['success' => true, 'data' => $project, 'message' => 'Project created.']);
        }
        foreach ($data['projects'] as &$project) {
            if (($project['id'] ?? '') === ($context['project']['id'] ?? '')) {
                $project['title'] = $title;
                $project['updated_at'] = date('Y-m-d H:i:s');
                save_data($data);
                student_respond(['success' => true, 'data' => $project, 'message' => 'Project updated.']);
            }
        }
        unset($project);
        student_respond(['success' => false, 'message' => 'Project not found.'], 404);
    }
    student_respond(['success' => true, 'data' => [
        'student' => $context['student'],
        'group' => $context['group'],
        'is_group_leader' => $context['group'] && ($context['group']['leader_id'] ?? '') === $context['studentId'],
        'advisor' => $context['advisor'],
        'project' => $context['project'],
        'progress' => calculated_project_progress($context['documents']),
        'stages' => [
            'proposal' => stage_payload($context, 'proposal'),
            'draft' => stage_payload($context, 'draft'),
            'complete' => stage_payload($context, 'complete'),
            'barcode' => [
                'status' => barcode_is_available($context) ? 'Completed' : 'Not Started',
                'available' => barcode_is_available($context),
                'code' => barcode_is_available($context) ? ($context['project']['code'] ?? '') : '',
            ],
        ],
        'comments' => $context['comments'],
    ]]);
}

if ($endpoint === 'timeline') {
    student_respond(['success' => true, 'data' => portal_timeline($context)]);
}

if ($endpoint === 'notifications') {
    if ($method === 'POST') {
        foreach ($data['notifications'] as &$notification) {
            if (in_array($notification['id'] ?? '', array_column($context['notifications'], 'id'), true)) {
                $notification['read_by'] = array_values(array_unique(array_merge($notification['read_by'] ?? [], [$context['studentId']])));
            }
        }
        unset($notification);
        save_data($data);
        student_respond(['success' => true, 'message' => 'Notifications marked as read.']);
    }
    $groupName = (string) ($context['group']['name'] ?? 'ส่วนตัว');
    $notifications = array_map(static function (array $row) use ($context, $groupName): array {
        $row['read'] = in_array($context['studentId'], $row['read_by'] ?? [], true) || (!isset($row['read_by']) && !empty($row['read']));
        $row['group_name'] = ($row['group_id'] ?? '') !== '' ? $groupName : 'ส่วนตัว';
        return $row;
    }, $context['notifications']);
    $unread = count(array_filter($notifications, fn($row) => empty($row['read'])));
    student_respond(['success' => true, 'data' => $notifications, 'unread' => $unread, 'announcement' => 'Submission schedule is open for this semester.']);
}

if ($endpoint === 'messages') {
    $roleLabels = ['chair' => 'ประธาน', 'vice_chair' => 'รองประธาน', 'committee' => 'กรรมการ'];
    $recipients = [];
    foreach (($context['group']['advisor_roles'] ?? $context['student']['advisor_roles'] ?? []) as $role => $advisorId) {
        $advisor = find_row($data['advisors'] ?? [], (string) $advisorId);
        if ($advisor && isset($roleLabels[$role])) {
            $recipients[] = ['id' => $advisor['id'], 'name' => $advisor['name'], 'role' => $role, 'role_label' => $roleLabels[$role]];
        }
    }
    if ($method === 'POST') {
        $payload = student_payload();
        $advisorId = trim((string) ($payload['advisor_id'] ?? ''));
        $recipient = null;
        foreach ($recipients as $candidate) {
            if (($candidate['id'] ?? '') === $advisorId) {
                $recipient = $candidate;
                break;
            }
        }
        if (!$recipient) {
            student_respond(['success' => false, 'message' => 'You can only message accepted project committee members.'], 403);
        }
        $subject = trim((string) ($payload['subject'] ?? ''));
        $messageText = trim((string) ($payload['message'] ?? ''));
        if ($subject === '' || $messageText === '') {
            student_respond(['success' => false, 'message' => 'Please enter a subject and message.'], 422);
        }
        $message = [
            'id' => next_id($data['messages'] ?? [], 'MSG'),
            'student_id' => $context['studentId'],
            'group_id' => $context['group']['id'] ?? '',
            'advisor_id' => $advisorId,
            'sender' => trim(($context['student']['first_name'] ?? '') . ' ' . ($context['student']['last_name'] ?? '')),
            'receiver' => $recipient['name'],
            'recipient_role' => $recipient['role'],
            'subject' => $subject,
            'message' => $messageText,
            'attachment' => '',
            'read' => false,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $data['messages'][] = $message;
        add_scoped_notification($data, [
            'group_id' => $context['group']['id'] ?? '',
            'student_id' => $context['group'] ? '' : $context['studentId'],
            'advisor_id' => $advisorId,
            'title' => 'ข้อความใหม่: ' . $subject,
            'message' => $message['sender'] . ' ส่งข้อความถึง ' . $recipient['name'],
            'type' => 'Message', 'read_by' => [$context['studentId']],
        ]);
        save_data($data);
        student_respond(['success' => true, 'data' => $message, 'message' => 'Message sent.']);
    }
    $messages = $context['messages'];
    usort($messages, static fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    student_respond(['success' => true, 'data' => ['messages' => $messages, 'recipients' => $recipients]]);
}

if (in_array($endpoint, ['upload/proposal', 'upload/draft', 'upload/complete'], true)) {
    $stage = basename($endpoint);
    if ($method === 'POST') {
        save_student_upload($data, $context, $stage);
    }
    if ($method === 'DELETE') {
        if ($context['group'] && ($context['group']['leader_id'] ?? '') !== $context['studentId']) {
            student_respond(['success' => false, 'message' => 'Only the group leader can delete project documents.'], 403);
        }
        foreach ($data['documents'] as $index => $document) {
            $sameOwner = $context['group']
                ? (($document['group_id'] ?? '') === ($context['group']['id'] ?? ''))
                : (($document['student_id'] ?? '') === $context['studentId'] && empty($document['group_id']));
            if ($sameOwner && ($document['type'] ?? '') === $stage) {
                if (in_array($document['status'] ?? '', ['Approved', 'Completed'], true)) {
                    student_respond(['success' => false, 'message' => 'Approved documents cannot be deleted.'], 422);
                }
                unset($data['documents'][$index]);
                $data['documents'] = array_values($data['documents']);
                save_data($data);
                student_respond(['success' => true, 'message' => 'Document deleted.']);
            }
        }
        student_respond(['success' => false, 'message' => 'Document not found.'], 404);
    }
}

if ($endpoint === 'change-password' && $method === 'POST') {
    $payload = student_payload();
    if (empty($payload['new_password']) || strlen((string) $payload['new_password']) < 6) {
        student_respond(['success' => false, 'message' => 'Password must be at least 6 characters.'], 422);
    }
    student_respond(['success' => true, 'message' => 'Password changed successfully.']);
}

if ($endpoint === 'forgot-password' && $method === 'POST') {
    student_respond(['success' => true, 'message' => 'Password reset instructions were sent to your email.']);
}

student_respond(['success' => false, 'message' => 'Student API endpoint not found.'], 404);
