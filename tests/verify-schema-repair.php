<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/schema-repair.php';
class SchemaTestStatement extends PDOStatement {
    public function __construct(private bool $exists) {}
    public function fetchColumn(int $column = 0): mixed { return $this->exists ? 1 : 0; }
}
class SchemaTestPDO extends PDO {
    public bool $exists = true;
    public function __construct() {}
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (!str_contains($query, "TABLE_NAME='app_state'")) throw new RuntimeException('Unexpected query');
        return new SchemaTestStatement($this->exists);
    }
    public function exec(string $statement): int|false { throw new RuntimeException('No DDL allowed'); }
}
function check_schema(bool $ok): void { if (!$ok) throw new RuntimeException('Assertion failed'); }
$db=new SchemaTestPDO();
check_schema(repair_missing_ai_tables($db) === ['created'=>[], 'unchanged'=>['app_state']]);
$db->exists=false;
try { repair_missing_ai_tables($db); throw new LogicException('Should fail'); }
catch (RuntimeException $e) { check_schema($e->getMessage()==='BASE_SCHEMA_MISSING'); }
$api = file_get_contents(dirname(__DIR__) . '/api/index.php');
$action = strpos($api, "if ($" . "action === 'repair-ai-schema')");
check_schema(strpos($api, 'require_csrf_token();') < $action);
check_schema(strpos($api, "!== 'admin'") < $action);
check_schema(str_contains($api, "(request_json()['confirm'] ?? false) !== true"));
echo "SCHEMA_REPAIR_OK: runtime store check, no DDL, admin/CSRF guards\n";
