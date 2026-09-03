<?php
declare(strict_types=1);

/**
 * Queue and process project-title similarity checks.
 *
 * Ollama is optional and never needs an API token. When it is unavailable the
 * worker falls back to a deterministic UTF-8 n-gram comparison so queued jobs
 * still complete instead of blocking the project workflow.
 */

function ai_title_config(string $key, string $default = ''): string
{
    $config = env_config();
    return trim((string) ($config[$key] ?? $default));
}

function queue_project_title_check(string $projectId, string $title): ?array
{
    $projectId = trim($projectId);
    $title = trim($title);
    if ($projectId === '' || $title === '') return null;

    $pdo = database_connection();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $cancel = $pdo->prepare(
            "UPDATE project_title_checks
             SET status = 'cancelled', completed_at = NOW(), error_message = 'Superseded by a newer title'
             WHERE project_id = :project_id AND status IN ('queued', 'processing')"
        );
        $cancel->execute(['project_id' => $projectId]);
        $insert = $pdo->prepare(
            "INSERT INTO project_title_checks (project_id, title, status)
             VALUES (:project_id, :title, 'queued')"
        );
        $insert->execute(['project_id' => $projectId, 'title' => $title]);
        $id = (int) $pdo->lastInsertId();
        if ($ownsTransaction) $pdo->commit();
        $queued = latest_project_title_check($projectId, $id);
        if ($queued && ai_web_processing_enabled()) {
            return process_project_title_check_inline($queued);
        }
        return $queued;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

/** Claim and finish one known job inside the current web request. */
function process_project_title_check_inline(array $queued): array
{
    $pdo = database_connection();
    $jobId = (int) ($queued['id'] ?? 0);
    if ($jobId <= 0) return $queued;

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            "SELECT id, project_id, title, attempts
             FROM project_title_checks
             WHERE id = :id AND status = 'queued'
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute(['id' => $jobId]);
        $job = $statement->fetch();
        if (!is_array($job)) {
            $pdo->commit();
            return latest_project_title_check((string) ($queued['project_id'] ?? ''), $jobId) ?? $queued;
        }
        $update = $pdo->prepare(
            "UPDATE project_title_checks
             SET status = 'processing', attempts = attempts + 1, started_at = NOW(),
                 completed_at = NULL, error_message = NULL
             WHERE id = :id"
        );
        $update->execute(['id' => $jobId]);
        $pdo->commit();
        $job['id'] = $jobId;
        $job['attempts'] = (int) ($job['attempts'] ?? 0) + 1;
        return process_project_title_check_job($job);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $job = $job ?? $queued;
        $job['attempts'] = (int) ($job['attempts'] ?? 1);
        try {
            fail_project_title_check_job($job, $error);
        } catch (Throwable $storeError) {
            error_log('[AI WEB WORKER] Unable to store title-check failure: ' . $storeError->getMessage());
        }
        error_log('[AI WEB WORKER] ' . $error->getMessage());
        return latest_project_title_check((string) ($queued['project_id'] ?? ''), $jobId) ?? $queued;
    }
}

function latest_project_title_check(string $projectId, ?int $jobId = null): ?array
{
    if ($projectId === '') return null;
    $sql = 'SELECT id, project_id, title, status, engine, model, max_similarity, risk_level,
                   matches_json, error_message, attempts, created_at, started_at, completed_at
            FROM project_title_checks
            WHERE project_id = :project_id';
    $params = ['project_id' => $projectId];
    if ($jobId !== null) {
        $sql .= ' AND id = :id';
        $params['id'] = $jobId;
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    $statement = database_connection()->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();
    if (!is_array($row)) return null;
    $matches = json_decode((string) ($row['matches_json'] ?? ''), true);
    $row['matches'] = is_array($matches) ? $matches : [];
    unset($row['matches_json']);
    $row['id'] = (int) $row['id'];
    $row['attempts'] = (int) $row['attempts'];
    $row['max_similarity'] = $row['max_similarity'] === null ? null : (float) $row['max_similarity'];
    return $row;
}

function claim_project_title_check_job(): ?array
{
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        $job = $pdo->query(
            "SELECT id, project_id, title, attempts
             FROM project_title_checks
             WHERE status = 'queued'
                OR (status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
             ORDER BY id ASC LIMIT 1 FOR UPDATE"
        )->fetch();
        if (!is_array($job)) {
            $pdo->commit();
            return null;
        }
        $statement = $pdo->prepare(
            "UPDATE project_title_checks
             SET status = 'processing', attempts = attempts + 1, started_at = NOW(),
                 completed_at = NULL, error_message = NULL
             WHERE id = :id"
        );
        $statement->execute(['id' => $job['id']]);
        $pdo->commit();
        $job['id'] = (int) $job['id'];
        $job['attempts'] = (int) $job['attempts'] + 1;
        return $job;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function normalize_ai_title(string $title): string
{
    $title = mb_strtolower(trim($title), 'UTF-8');
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    $title = preg_replace('/\s+/u', ' ', $title) ?? $title;
    return trim($title);
}

function ai_title_ngrams(string $title, int $size = 3): array
{
    $normalized = str_replace(' ', '', normalize_ai_title($title));
    $characters = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($characters) <= $size) return $normalized === '' ? [] : [$normalized => true];
    $grams = [];
    for ($index = 0; $index <= count($characters) - $size; $index++) {
        $grams[implode('', array_slice($characters, $index, $size))] = true;
    }
    return $grams;
}

function ai_title_tokens(string $title): array
{
    $stopWords = ['ระบบ', 'โครงงาน', 'การ', 'และ', 'สำหรับ', 'ด้วย', 'ของ', 'the', 'a', 'an', 'for', 'and', 'system'];
    $tokens = preg_split('/\s+/u', normalize_ai_title($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_fill_keys(array_values(array_diff($tokens, $stopWords)), true);
}

function ai_set_similarity(array $left, array $right, bool $dice = false): float
{
    if (!$left || !$right) return 0.0;
    $intersection = count(array_intersect_key($left, $right));
    if ($dice) return (2.0 * $intersection) / (count($left) + count($right));
    $union = count($left) + count($right) - $intersection;
    return $union > 0 ? $intersection / $union : 0.0;
}

function local_title_similarity(string $left, string $right): float
{
    $normalizedLeft = normalize_ai_title($left);
    $normalizedRight = normalize_ai_title($right);
    if ($normalizedLeft !== '' && $normalizedLeft === $normalizedRight) return 1.0;
    $ngramScore = ai_set_similarity(ai_title_ngrams($left), ai_title_ngrams($right), true);
    $tokenScore = ai_set_similarity(ai_title_tokens($left), ai_title_tokens($right));
    return min(1.0, (0.78 * $ngramScore) + (0.22 * $tokenScore));
}

function vector_cosine_similarity(array $left, array $right): float
{
    if (!$left || count($left) !== count($right)) return 0.0;
    $dot = 0.0;
    $leftNorm = 0.0;
    $rightNorm = 0.0;
    foreach ($left as $index => $value) {
        $a = (float) $value;
        $b = (float) ($right[$index] ?? 0.0);
        $dot += $a * $b;
        $leftNorm += $a * $a;
        $rightNorm += $b * $b;
    }
    if ($leftNorm <= 0.0 || $rightNorm <= 0.0) return 0.0;
    return max(0.0, min(1.0, $dot / (sqrt($leftNorm) * sqrt($rightNorm))));
}

function ollama_embed_titles(array $titles): array
{
    $url = rtrim(ai_title_config('AI_OLLAMA_URL', 'http://127.0.0.1:11434'), '/') . '/api/embed';
    $model = ai_title_config('AI_OLLAMA_MODEL', 'bge-m3');
    $payload = json_encode(['model' => $model, 'input' => array_values($titles)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => $payload,
        'timeout' => max(2, (int) ai_title_config('AI_OLLAMA_TIMEOUT', '20')),
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($url, false, $context);
    if (!is_string($response) || $response === '') throw new RuntimeException('Ollama is not reachable.');
    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !is_array($decoded['embeddings'] ?? null)) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Ollama returned an invalid embedding response.'));
    }
    return $decoded['embeddings'];
}

function title_similarity_results(string $title, array $candidates): array
{
    $requestedEngine = strtolower(ai_title_config('AI_TITLE_ENGINE', 'auto'));
    // localhost Ollama is unreachable from Vercel. Auto mode therefore uses
    // the built-in no-token engine immediately, without a network timeout.
    if ($requestedEngine === 'auto' && getenv('VERCEL') !== false) {
        $requestedEngine = 'local';
    }
    $engine = 'local-ngram-v1';
    $model = '';
    $scores = [];

    if (in_array($requestedEngine, ['auto', 'ollama'], true) && $candidates) {
        try {
            $target = ollama_embed_titles([$title])[0] ?? [];
            foreach (array_chunk($candidates, 48) as $chunk) {
                $vectors = ollama_embed_titles(array_column($chunk, 'title'));
                foreach ($chunk as $index => $candidate) {
                    $scores[(string) $candidate['id']] = vector_cosine_similarity($target, $vectors[$index] ?? []);
                }
            }
            $engine = 'ollama-embedding';
            $model = ai_title_config('AI_OLLAMA_MODEL', 'bge-m3');
        } catch (Throwable $error) {
            if ($requestedEngine === 'ollama') throw $error;
        }
    }

    if (!$scores) {
        foreach ($candidates as $candidate) {
            $scores[(string) $candidate['id']] = local_title_similarity($title, (string) $candidate['title']);
        }
    }

    $matches = [];
    foreach ($candidates as $candidate) {
        $score = round((float) ($scores[(string) $candidate['id']] ?? 0.0), 5);
        if ($score < 0.30) continue;
        $matches[] = ['project_id' => $candidate['id'], 'code' => $candidate['code'] ?? '', 'title' => $candidate['title'], 'score' => $score];
    }
    usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
    $matches = array_slice($matches, 0, 5);
    $maxScore = $matches ? (float) $matches[0]['score'] : 0.0;
    $high = (float) ai_title_config('AI_TITLE_HIGH_THRESHOLD', '0.85');
    $review = (float) ai_title_config('AI_TITLE_REVIEW_THRESHOLD', '0.70');
    $risk = $maxScore >= $high ? 'high' : ($maxScore >= $review ? 'review' : 'clear');
    return compact('engine', 'model', 'matches', 'maxScore', 'risk');
}

function process_project_title_check_job(array $job): array
{
    $pdo = database_connection();
    $statement = $pdo->prepare('SELECT id, code, title FROM projects WHERE id <> :project_id AND TRIM(title) <> \'\' ORDER BY id');
    $statement->execute(['project_id' => $job['project_id']]);
    $result = title_similarity_results((string) $job['title'], $statement->fetchAll());
    $update = $pdo->prepare(
        "UPDATE project_title_checks
         SET status = 'completed', engine = :engine, model = :model,
             max_similarity = :max_similarity, risk_level = :risk_level,
             matches_json = :matches_json, error_message = NULL, completed_at = NOW()
         WHERE id = :id AND status = 'processing'"
    );
    $update->execute([
        'engine' => $result['engine'], 'model' => $result['model'] ?: null,
        'max_similarity' => $result['maxScore'], 'risk_level' => $result['risk'],
        'matches_json' => json_encode($result['matches'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'id' => $job['id'],
    ]);
    return latest_project_title_check((string) $job['project_id'], (int) $job['id']) ?? [];
}

function fail_project_title_check_job(array $job, Throwable $error): void
{
    $retry = (int) ($job['attempts'] ?? 1) < 3;
    $statement = database_connection()->prepare(
        "UPDATE project_title_checks
         SET status = :status, error_message = :message,
             completed_at = CASE WHEN :status_copy = 'failed' THEN NOW() ELSE NULL END
         WHERE id = :id"
    );
    $statement->execute([
        'status' => $retry ? 'queued' : 'failed',
        'status_copy' => $retry ? 'queued' : 'failed',
        'message' => mb_substr($error->getMessage(), 0, 1000, 'UTF-8'),
        'id' => $job['id'],
    ]);
}
