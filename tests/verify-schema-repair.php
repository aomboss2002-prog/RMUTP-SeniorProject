<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/schema-repair.php';
class SchemaTestStatement extends PDOStatement {
    private string $table = '';
    public function __construct(private SchemaTestPDO $db) {}
    public function execute(?array $params = null): bool { $this->table = $params[0]; return true; }
    public function fetchColumn(int $column = 0): mixed { return isset($this->db->tables[$this->table]) ? 1 : 0; }
}
class SchemaTestPDO extends PDO {
    public array $tables = ['projects' => true];
    public array $writes = [];
    public bool $failRisk = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new SchemaTestStatement($this); }
    public function exec(string $statement): int|false {
        if (!preg_match('/^CREATE TABLE IF NOT EXISTS (project_title_checks|project_risk_scores)\s*\(/', $statement, $m)) throw new RuntimeException('Unexpected DDL');
        if (preg_match('/\b(DROP|DELETE|ALTER|TRUNCATE)\s+(TABLE|FROM)\b/i', $statement)) throw new RuntimeException('Destructive SQL');
        if ($this->failRisk && $m[1] === 'project_risk_scores') throw new RuntimeException('simulated failure');
        $this->writes[] = $m[1]; $this->tables[$m[1]] = true; return 0;
    }
}
function check_schema(bool $ok): void { if (!$ok) throw new RuntimeException('Assertion failed'); }
$db = new SchemaTestPDO();
check_schema(count(repair_missing_ai_tables($db)['created']) === 2);
check_schema(repair_missing_ai_tables($db)['created'] === [] && count($db->writes) === 2);
$db = new SchemaTestPDO(); $db->tables['project_title_checks'] = true;
check_schema(repair_missing_ai_tables($db)['created'] === ['project_risk_scores']);
$db = new SchemaTestPDO(); $db->tables = [];
try { repair_missing_ai_tables($db); throw new LogicException('Should fail'); } catch (RuntimeException $e) { check_schema($e->getMessage() === 'BASE_SCHEMA_MISSING' && $db->writes === []); }
$db = new SchemaTestPDO(); $db->failRisk = true;
try { repair_missing_ai_tables($db); throw new LogicException('Should fail'); } catch (RuntimeException $e) { check_schema($e->getMessage() === 'simulated failure'); }
$db->failRisk = false;
check_schema(repair_missing_ai_tables($db)['created'] === ['project_risk_scores']);
$api = file_get_contents(dirname(__DIR__) . '/api/index.php');
$action = strpos($api, "if ($" . "action === 'repair-ai-schema')");
check_schema(strpos($api, 'require_csrf_token();') < $action);
check_schema(strpos($api, "!== 'admin'") < $action);
check_schema(str_contains($api, "(request_json()['confirm'] ?? false) !== true"));
echo "SCHEMA_REPAIR_OK: missing/existing tables, base guard, partial failure retry, admin/CSRF guards (mock PDO)\n";
