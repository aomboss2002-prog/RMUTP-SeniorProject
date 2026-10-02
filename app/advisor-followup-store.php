<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-records.php';

/** Current text in comments; immutable create/edit/delete history in activities. */
function save_advisor_followup(PDO $pdo, string $projectId, string $advisorId, string $method, int $id, array $values = []): int
{
    $pdo->beginTransaction();
    try {
        $before = null;
        if ($method !== 'POST') {
            $query = $pdo->prepare("SELECT * FROM comments WHERE followup_id=? AND project_id=? AND comment_type='advisor_followup' FOR UPDATE");
            $query->execute([$id, $projectId]);
            $before = $query->fetch(PDO::FETCH_ASSOC);
            if (!$before || $before['author_id'] !== $advisorId) throw new DomainException('Only the author can modify this follow-up.');
        } else $id = runtime_sequence($pdo, 'followup');
        $name = (string) ($_SESSION['advisor_user']['name'] ?? $advisorId);
        if ($method === 'POST') {
            $pdo->prepare("INSERT INTO comments (id,project_id,author_id,author,message,comment_type,followup_id,issue,next_action,followup_at,updated_at)
                VALUES (:key,:project,:advisor,:author,:note,'advisor_followup',:id,:issue,:next_action,:followup_at,NOW())")
                ->execute($values + ['key' => 'FU' . base_convert((string) $id, 10, 36), 'project' => $projectId, 'advisor' => $advisorId, 'author' => $name, 'id' => $id]);
        } elseif ($method === 'DELETE') {
            $pdo->prepare('DELETE FROM comments WHERE followup_id=?')->execute([$id]);
        } else {
            $pdo->prepare('UPDATE comments SET message=:note,issue=:issue,next_action=:next_action,followup_at=:followup_at,updated_at=NOW() WHERE followup_id=:id')
                ->execute($values + ['id' => $id]);
        }
        $event = $method === 'POST' ? 'followup_created' : ($method === 'DELETE' ? 'followup_deleted' : 'followup_updated');
        $pdo->prepare("INSERT INTO activities (id,title,actor,activity_type,project_id,actor_type,actor_id,metadata_json,occurred_at)
            VALUES (?,?,?, ?,?,'advisor',?,?,NOW())")->execute(['FU' . bin2hex(random_bytes(8)), $event, $name, $event, $projectId, $advisorId,
                json_encode(['followup_id' => $id, 'before' => $before, 'after' => $method === 'DELETE' ? null : $values], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $pdo->commit();
        return $id;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}
