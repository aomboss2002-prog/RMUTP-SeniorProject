<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/settings.php';
require dirname(__DIR__) . '/app/ai-title-check.php';
require dirname(__DIR__) . '/app/ai-risk.php';
class SettingsGateStatement extends PDOStatement {
    public function fetchColumn(int $column = 0): mixed { return '{"ai_title_enabled":false,"ai_risk_enabled":false}'; }
}
class SettingsGatePDO extends PDO {
    public int $reads = 0;
    public function __construct() {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (!str_contains($query, "JSON_EXTRACT(state_json, '$.settings')")) throw new RuntimeException('Unexpected query');
        $this->reads++;
        return new SettingsGateStatement();
    }
}
function database_connection(): PDO { static $pdo; return $pdo ??= new SettingsGatePDO(); }
if (queue_project_title_check('TEST', 'test title') !== null) throw new RuntimeException('Disabled title queued');
if (claim_project_title_check_job() !== null) throw new RuntimeException('Disabled title claimed');
refresh_project_risk_score_if_stale('TEST');
$result = process_project_risk_scores();
if (!$result['disabled'] || $result['processed'] !== 0 || database_connection()->reads !== 1) throw new RuntimeException('Disabled risk processed or settings not cached');
echo "SETTINGS_AI_GATES_OK: no queue/claim/risk writes when disabled; one settings read\n";
