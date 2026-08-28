<?php

date_default_timezone_set('Asia/Bangkok');

final class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /** @var array<string, int> */
    private array $knownExpirations = [];

    private function connection(): PDO
    {
        if (function_exists('database_connection')) return database_connection();
        if (function_exists('shared_database_connection')) return shared_database_connection();
        throw new RuntimeException('A database connection is required for database sessions.');
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        $statement = $this->connection()->prepare(
            'SELECT session_data, UNIX_TIMESTAMP(expires_at) AS expires_at
             FROM php_sessions WHERE session_id = :id AND expires_at > NOW()'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) return '';
        $this->knownExpirations[$id] = (int) ($row['expires_at'] ?? 0);
        return (string) ($row['session_data'] ?? '');
    }

    public function write(string $id, string $data): bool
    {
        $lifetime = max(1800, (int) ini_get('session.gc_maxlifetime'));
        $expiresAt = time() + $lifetime;
        $written = $this->connection()->prepare(
            'INSERT INTO php_sessions (session_id, session_data, expires_at)
             VALUES (:id, :data, :expires_at)
             ON DUPLICATE KEY UPDATE session_data = VALUES(session_data), expires_at = VALUES(expires_at)'
        )->execute([
            'id' => $id,
            'data' => $data,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);
        if ($written) $this->knownExpirations[$id] = $expiresAt;
        return $written;
    }

    public function destroy(string $id): bool
    {
        return $this->connection()->prepare('DELETE FROM php_sessions WHERE session_id = :id')
            ->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $statement = $this->connection()->prepare('DELETE FROM php_sessions WHERE expires_at <= NOW()');
        $statement->execute();
        return $statement->rowCount();
    }

    public function validateId(string $id): bool
    {
        if (($this->knownExpirations[$id] ?? 0) > time()) return true;
        $statement = $this->connection()->prepare(
            'SELECT UNIX_TIMESTAMP(expires_at) FROM php_sessions
             WHERE session_id = :id AND expires_at > NOW()'
        );
        $statement->execute(['id' => $id]);
        $expiresAt = (int) $statement->fetchColumn();
        if ($expiresAt <= time()) return false;
        $this->knownExpirations[$id] = $expiresAt;
        return true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        // Avoid a remote UPDATE on every read-only request. Refreshing when
        // fewer than ten minutes remain still keeps active sessions alive.
        if (($this->knownExpirations[$id] ?? 0) > time() + 600) return true;
        $lifetime = max(1800, (int) ini_get('session.gc_maxlifetime'));
        $expiresAt = time() + $lifetime;
        $updated = $this->connection()->prepare(
            'UPDATE php_sessions SET expires_at = :expires_at WHERE session_id = :id'
        )->execute([
            'id' => $id,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);
        if ($updated) $this->knownExpirations[$id] = $expiresAt;
        return $updated;
    }
}

function configured_session_driver(): string
{
    $driver = getenv('SESSION_DRIVER');
    if ((!is_string($driver) || $driver === '') && function_exists('env_config')) {
        $driver = env_config()['SESSION_DRIVER'] ?? '';
    }
    return strtolower(trim((string) $driver));
}

function secure_password_hash(string $password): string
{
    $iterations = 210000;
    $salt = random_bytes(16);
    $derivedKey = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
    return implode('$', ['pbkdf2-sha256', (string) $iterations, base64_encode($salt), base64_encode($derivedKey)]);
}

function secure_password_verify(string $password, string $storedHash): bool
{
    if (str_starts_with($storedHash, 'pbkdf2-sha256$')) {
        $parts = explode('$', $storedHash);
        if (count($parts) !== 4 || !ctype_digit($parts[1])) return false;
        $iterations = (int) $parts[1];
        $salt = base64_decode($parts[2], true);
        $expected = base64_decode($parts[3], true);
        if ($iterations < 100000 || $salt === false || $expected === false || strlen($expected) !== 32) return false;
        $actual = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);
        return hash_equals($expected, $actual);
    }
    return password_verify($password, $storedHash);
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    if (configured_session_driver() === 'database') {
        session_set_save_handler(new DatabaseSessionHandler(), true);
    } else {
        $sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rmutp-seniorproject-sessions';
        if (!is_dir($sessionPath)) mkdir($sessionPath, 0770, true);
        session_save_path($sessionPath);
    }
    session_set_cookie_params([
        'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity'] ?? $now);
    $idleTimeout = !empty($_SESSION['remember_me']) ? 30 * 86400 : 1800;
    if ($now - $lastActivity > $idleTimeout) {
        unset($_SESSION['app_user'], $_SESSION['advisor_user'], $_SESSION['csrf_token'], $_SESSION['remember_me']);
        session_regenerate_id(true);
    }
    // Updating this timestamp on every request makes database-backed sessions
    // dirty and forces a remote write. One update per minute is sufficient for
    // the 30-minute idle timeout while keeping navigation responsive.
    if ($now - $lastActivity >= 60 || !isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = $now;
    }
}

function set_remembered_session(bool $remember): void
{
    start_app_session();
    if ($remember) {
        $_SESSION['remember_me'] = true;
    } else {
        unset($_SESSION['remember_me']);
    }
    setcookie(session_name(), session_id(), [
        'expires' => $remember ? time() + (30 * 86400) : 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function login_rate_limited(string $identity): bool
{
    start_app_session();
    $key = hash('sha256', strtolower(trim($identity)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    $attempt = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'started_at' => time()];
    if (time() - (int) $attempt['started_at'] > 900) {
        unset($_SESSION['login_attempts'][$key]);
        return false;
    }
    return (int) $attempt['count'] >= 5;
}

function record_login_attempt(string $identity, bool $success): void
{
    start_app_session();
    $key = hash('sha256', strtolower(trim($identity)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    if ($success) {
        unset($_SESSION['login_attempts'][$key]);
        return;
    }
    $attempt = $_SESSION['login_attempts'][$key] ?? ['count' => 0, 'started_at' => time()];
    $attempt['count']++;
    $_SESSION['login_attempts'][$key] = $attempt;
}

function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function require_csrf_token(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Security token expired. Reload the page and try again.']);
        exit;
    }
}
