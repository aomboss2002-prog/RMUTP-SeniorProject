<?php
declare(strict_types=1);
require_once __DIR__ . '/runtime-records.php';

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
    if ($projectId === '' || $title === '' || !system_setting_enabled('ai_title_enabled')) return null;
    $pdo = database_connection();
    $owns = !$pdo->inTransaction();
    if ($owns) $pdo->beginTransaction();
    try {
        $id = runtime_sequence($pdo, 'title');
        $parent = $pdo->prepare('SELECT id FROM projects WHERE id=? FOR UPDATE');
        $parent->execute([$projectId]);
        if (!$parent->fetchColumn()) throw new RuntimeException('Project no longer exists');
        $query = $pdo->prepare("SELECT id FROM " . runtime_records_sql('title') . " t WHERE project_id=? AND status IN ('queued','processing')");
        $query->execute([$projectId]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
            $row = runtime_record_get($pdo, 'title', $oldId);
            runtime_record_put($pdo, 'title', $oldId, array_replace($row, ['status' => 'cancelled',
                'completed_at' => date('Y-m-d H:i:s'), 'error_message' => 'Superseded by a newer title']));
        }
        runtime_record_put($pdo, 'title', $id, ['id' => $id, 'project_id' => $projectId, 'title' => $title,
            'status' => 'queued', 'engine' => '', 'model' => null, 'max_similarity' => null, 'risk_level' => '',
            'matches_json' => null, 'error_message' => null, 'attempts' => 0, 'created_at' => date('Y-m-d H:i:s'),
            'started_at' => null, 'completed_at' => null]);
        if ($owns) $pdo->commit();
        $queued = latest_project_title_check($projectId, $id);
        return $owns && ai_web_processing_enabled() ? process_project_title_check_inline($queued) : $queued;
    } catch (Throwable $error) { if ($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function process_project_title_check_inline(array $queued): array
{
    $job = claim_runtime_title((int) $queued['id']);
    if (!$job) return latest_project_title_check((string) $queued['project_id'], (int) $queued['id']) ?? $queued;
    try { return process_project_title_check_job($job); }
    catch (Throwable $error) {
        fail_project_title_check_job($job, $error);
        error_log('[AI WEB WORKER] ' . $error->getMessage());
        return latest_project_title_check((string) $job['project_id'], (int) $job['id']) ?? $queued;
    }
}

function latest_project_title_check(string $projectId, ?int $jobId = null): ?array
{
    if ($projectId === '') return null;
    $pdo = database_connection();
    if ($jobId === null) {
        $query = $pdo->prepare("SELECT state_json FROM app_state WHERE state_key LIKE 'ai-title:%' AND JSON_UNQUOTE(JSON_EXTRACT(state_json, '$.project_id'))=? ORDER BY state_key DESC LIMIT 1");
        $query->execute([$projectId]);
        $json = $query->fetchColumn();
        $row = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } else $row = runtime_record_get($pdo, 'title', $jobId);
    if (!$row || $row['project_id'] !== $projectId) return null;
    $row['matches'] = json_decode((string) ($row['matches_json'] ?? ''), true) ?: [];
    unset($row['matches_json']);
    $row['id'] = (int) $row['id'];
    $row['attempts'] = (int) $row['attempts'];
    $row['max_similarity'] = $row['max_similarity'] === null ? null : (float) $row['max_similarity'];
    return $row;
}

function claim_runtime_title(?int $id = null): ?array
{
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        runtime_sequence($pdo, 'title', false);
        if ($id === null) {
            $id = (int) $pdo->query("SELECT id FROM " . runtime_records_sql('title') . " t
                WHERE status='queued' OR (status='processing' AND started_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
                ORDER BY id LIMIT 1")->fetchColumn();
        }
        $job = runtime_record_get($pdo, 'title', $id, true);
        if (!$job || !($job['status'] === 'queued' || ($job['status'] === 'processing' && strtotime($job['started_at']) < time() - 600))) {
            $pdo->commit();
            return null;
        }
        $job = array_replace($job, ['status' => 'processing', 'attempts' => (int) $job['attempts'] + 1,
            'started_at' => date('Y-m-d H:i:s'), 'completed_at' => null, 'error_message' => null]);
        runtime_record_put($pdo, 'title', $id, $job);
        $pdo->commit();
        return $job;
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function claim_project_title_check_job(): ?array
{
    return system_setting_enabled('ai_title_enabled') ? claim_runtime_title() : null;
}

/** A cancelled/reclaimed job must never overwrite the newer result. */
function update_runtime_title_job(array $job, array $values): void
{
    $pdo = database_connection();
    $pdo->beginTransaction();
    try {
        runtime_sequence($pdo, 'title', false);
        $current = runtime_record_get($pdo, 'title', (int) $job['id'], true);
        if ($current && $current['status'] === 'processing' && (int) $current['attempts'] === (int) $job['attempts']) {
            runtime_record_put($pdo, 'title', (int) $job['id'], array_replace($current, $values));
        }
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
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
    update_runtime_title_job($job, [
        'status' => 'completed', 'engine' => $result['engine'], 'model' => $result['model'] ?: null,
        'max_similarity' => $result['maxScore'], 'risk_level' => $result['risk'],
        'matches_json' => json_encode($result['matches'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'error_message' => null, 'completed_at' => date('Y-m-d H:i:s'),
    ]);
    return latest_project_title_check((string) $job['project_id'], (int) $job['id']) ?? [];
}

function fail_project_title_check_job(array $job, Throwable $error): void
{
    $retry = (int) ($job['attempts'] ?? 1) < 3;
    update_runtime_title_job($job, ['status' => $retry ? 'queued' : 'failed',
        'error_message' => mb_substr($error->getMessage(), 0, 1000, 'UTF-8'),
        'completed_at' => $retry ? null : date('Y-m-d H:i:s')]);
}
