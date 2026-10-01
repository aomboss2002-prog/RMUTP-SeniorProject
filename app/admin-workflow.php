<?php
declare(strict_types=1);

function admin_report_filter(array $rows, string $field, string $from, string $to): array
{
    foreach ([$from, $to] as $date) {
        if ($date === '') continue;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('วันที่ไม่ถูกต้อง');
    }
    if ($from !== '' && $to !== '' && $from > $to) throw new InvalidArgumentException('วันที่เริ่มต้นต้องไม่เกินวันที่สิ้นสุด');
    if ($from === '' && $to === '') return array_values($rows);
    return array_values(array_filter($rows, static function (array $row) use ($field, $from, $to): bool {
        $date = substr((string) ($row[$field] ?? ''), 0, 10);
        return $date !== '' && ($from === '' || $date >= $from) && ($to === '' || $date <= $to);
    }));
}

function admin_timeline_rows(array $history): array
{
    $events = ['submitted' => 'ส่งเอกสาร', 'resubmitted' => 'ส่งเอกสารแก้ไข', 'approved' => 'อนุมัติ',
        'revision_requested' => 'ส่งกลับแก้ไข', 'rejected' => 'ไม่อนุมัติ',
        'document_deleted' => 'ลบเอกสาร', 'progress_changed' => 'ปรับความก้าวหน้า'];
    $statuses = ['submitted' => 'Review', 'resubmitted' => 'Review', 'approved' => 'Approved',
        'revision_requested' => 'NeedsRevision', 'rejected' => 'Rejected'];
    return array_map(static function (array $row) use ($events, $statuses): array {
        $stage = (string) ($row['stage'] ?? '');
        $stageLabel = ['proposal' => 'ข้อเสนอโครงงาน', 'draft' => 'ฉบับร่าง', 'complete' => 'ฉบับสมบูรณ์'][$stage] ?? $stage;
        if ($stage === 'draft' && !empty($row['chapter'])) $stageLabel .= ' บทที่ ' . (int) $row['chapter'];
        $event = (string) ($row['event_type'] ?? '');
        return ['step' => ($events[$event] ?? $event) . ' — ' . $stageLabel,
            'reviewer' => (string) ($row['actor_name'] ?? ''), 'created_at' => (string) ($row['occurred_at'] ?? ''),
            'status' => $statuses[$event] ?? '', 'progress' => (int) ($row['current_progress'] ?? 0)];
    }, $history);
}

function admin_upload_context(array $data, array $payload): array
{
    $type = (string) ($payload['type'] ?? '');
    if (!in_array($type, ['proposal', 'draft', 'complete'], true)) throw new InvalidArgumentException('ประเภทเอกสารไม่ถูกต้อง');
    $studentId = (string) ($payload['student_id'] ?? '');
    $projectId = (string) ($payload['project_id'] ?? '');
    $student = null;
    $project = null;
    foreach ($data['students'] ?? [] as $row) if (($row['id'] ?? '') === $studentId) $student = $row;
    foreach ($data['projects'] ?? [] as $row) if (($row['id'] ?? '') === $projectId) $project = $row;
    if (!$student || !$project) throw new InvalidArgumentException('กรุณาเลือกนักศึกษาและโครงงานที่มีอยู่จริง');
    $groupId = null;
    foreach ($data['groups'] ?? [] as $group) {
        if (($group['project_id'] ?? '') === $projectId && in_array($studentId, $group['member_ids'] ?? [], true)) $groupId = $group['id'];
    }
    if (!$groupId && ($project['student_id'] ?? '') !== $studentId) throw new InvalidArgumentException('นักศึกษาไม่ได้เป็นเจ้าของหรือสมาชิกของโครงงานนี้');
    $chapter = $type === 'draft' ? filter_var($payload['chapter'] ?? null, FILTER_VALIDATE_INT) : null;
    if ($type === 'draft' && ($chapter === false || $chapter < 1 || $chapter > 5)) throw new InvalidArgumentException('กรุณาเลือกบทที่ 1–5');
    return ['type' => $type, 'student_id' => $studentId, 'project_id' => $projectId, 'group_id' => $groupId, 'chapter' => $chapter];
}

function admin_validate_pdf(string $path, string $originalName): int
{
    $size = is_file($path) ? (int) filesize($path) : 0;
    if ($size < 1 || $size > 20 * 1024 * 1024) throw new InvalidArgumentException('ไฟล์ต้องมีขนาดไม่เกิน 20 MB');
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf'
        || (new finfo(FILEINFO_MIME_TYPE))->file($path) !== 'application/pdf'
        || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
        throw new InvalidArgumentException('รองรับไฟล์ PDF ที่ถูกต้องเท่านั้น');
    }
    return $size;
}
