<?php
declare(strict_types=1);
// Dedicated local test server only. Never takes credentials or a schema from .env.
$port = (int) (getenv('TWENTY_TEST_PORT') ?: 33319);
$db = 'test_twenty_' . bin2hex(random_bytes(5));
$control = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$directory = str_replace('\\', '/', (string) $control->query('SELECT @@datadir')->fetchColumn());
if (!str_contains(strtolower($directory), '/temp/twenty-tables-')) throw new RuntimeException('Not an isolated twenty-table test server');
$control->exec("CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
foreach (['DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$port,'DB_DATABASE'=>$db,'DB_USERNAME'=>'root','DB_PASSWORD'=>'','DB_AUTO_MIGRATE'=>'false','DATABASE_URL'=>'','MYSQL_URL'=>'','AI_TITLE_ENGINE'=>'local','AI_WEB_PROCESSING_ENABLED'=>'false'] as $key=>$value) putenv("{$key}={$value}");
require_once __DIR__ . '/../app/store.php';
require_once __DIR__ . '/../app/twenty-table-migration.php';
require_once __DIR__ . '/../app/session.php';
require_once __DIR__ . '/../app/ai-title-check.php';
require_once __DIR__ . '/../app/ai-risk.php';
require_once __DIR__ . '/../app/advisor-followup-store.php';
require_once __DIR__ . '/../app/admin-dashboard.php';
require_once __DIR__ . '/../app/website-monitor.php';
function twenty_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    $pdo = database_connection();
    $old = file_get_contents(__DIR__ . '/fixtures/pre-twenty-schema.sql');
    $pdo->exec($old);
    twenty_assert(count(twenty_table_inventory($pdo)) === 27, 'Legacy inventory');
    $pdo->exec("INSERT INTO projects(id,code,title) VALUES ('P1','P1','ระบบทดสอบชื่อโครงงาน'),('P2','P2','ระบบทดสอบชื่อโครงงาน');
        INSERT INTO advisors(id,name,email,department) VALUES ('A1','Advisor','a@example.test','Test');
        INSERT INTO php_sessions(session_id,session_data,expires_at) VALUES ('legacy-session','csrf|s:4:\"test\";',DATE_ADD(NOW(),INTERVAL 1 HOUR));
        INSERT INTO user_sessions(session_id,user_type,user_id,last_activity_at,expires_at) VALUES ('legacy-session','student','S1',NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR));
        INSERT INTO schema_migrations(version) VALUES ('legacy-version');
        INSERT INTO project_title_checks(project_id,title,status) VALUES ('P1','ระบบทดสอบชื่อโครงงาน','completed');
        INSERT INTO project_risk_scores(project_id,score,factors_json) VALUES ('P1',13,'[]');
        INSERT INTO system_job_runs(job_name,status,started_at) VALUES ('ai-web-worker','success',NOW());
        INSERT INTO project_progress_history(project_id,event_type,stage,previous_progress,current_progress,event_key,occurred_at) VALUES ('P1','approved','proposal',15,30,REPEAT('a',64),NOW());
        INSERT INTO advisor_followups(project_id,advisor_id,note,issue,next_action) VALUES ('P1','A1','Original note','Issue','Next');
        INSERT INTO notifications(id,title,message) VALUES ('N1','Test','Retain');
        INSERT INTO notification_reads(notification_id,reader_type,reader_id) VALUES ('N1','student','S1')");
    $counts = migrate_twenty_tables($pdo);
    twenty_assert(count($counts) === 7 && count(twenty_table_inventory($pdo)) === 27, 'Copy before drop');
    // Conflicting copy must prevent destructive cleanup.
    $pdo->exec("UPDATE comments SET message='conflict' WHERE followup_id=1");
    try { migrate_twenty_tables($pdo, true); throw new LogicException('Conflict accepted'); }
    catch (RuntimeException $e) { twenty_assert(str_contains($e->getMessage(), 'conflict'), 'Expected verification failure'); }
    twenty_assert(count(twenty_table_inventory($pdo)) === 27, 'No DROP after failed verification');
    $pdo->exec("UPDATE comments SET message='Original note' WHERE followup_id=1");
    migrate_twenty_tables($pdo, true);
    migrate_twenty_tables($pdo, true); // Restart safety.
    twenty_assert(count(twenty_table_inventory($pdo)) === 20, 'Final inventory');
    twenty_assert(database_schema_is_current($pdo, 'legacy-version'), 'Migration version preserved');
    $session = new DatabaseSessionHandler();
    twenty_assert($session->read('legacy-session') === 'csrf|s:4:"test";', 'Session bytes preserved');
    $_SESSION = [];
    $session->write('anonymous', 'csrf|s:4:"test";');
    twenty_assert($session->validateId('anonymous'), 'Anonymous session');
    $_SESSION = ['app_user'=>['role'=>'student','id'=>'S1']];
    $session->write('signed-in', 'user');
    twenty_assert($session->read('signed-in') === 'user', 'Signed-in session');
    $pdo->exec("DELETE FROM user_sessions WHERE user_type='student' AND user_id='S1'");
    twenty_assert((new DatabaseSessionHandler())->read('signed-in') === '', 'Reset invalidates session');
    $session->destroy('anonymous');
    foreach (['admin','advisor','student'] as $role) {
        $_SESSION = $role === 'advisor' ? ['advisor_user'=>['id'=>'A1','name'=>'Advisor']] : ['app_user'=>['role'=>$role,'id'=>'S1']];
        $session->write('role-'.$role,'payload-'.$role);
        twenty_assert($session->read('role-'.$role)==='payload-'.$role,'Role session '.$role);
        $query=$pdo->prepare('SELECT user_type FROM user_sessions WHERE session_id=?');$query->execute(['role-'.$role]);
        twenty_assert($query->fetchColumn()===$role,'Session identity '.$role);
        $session->destroy('role-'.$role);
    }
    twenty_assert(project_tracking_history('P1')[0]['current_progress'] == 30, 'Progress migrated');
    $event=['project_id'=>'P1','document_id'=>null,'event_type'=>'approved','stage'=>'draft','chapter'=>1,'previous_progress'=>30,'current_progress'=>38,'actor_type'=>'advisor','actor_id'=>'A1','actor_name'=>'Advisor','event_key'=>str_repeat('b',64),'occurred_at'=>date('Y-m-d H:i:s'),'metadata'=>[]];
    $pdo->beginTransaction(); persist_project_tracking_events($pdo,[$event,$event]); $pdo->commit();
    twenty_assert(count(project_tracking_history('P1')) === 2, 'Progress idempotent');
    twenty_assert(project_followups('P1')[0]['note'] === 'Original note', 'Followup migrated');
    $values=['note'=>'New note','issue'=>'','next_action'=>'','followup_at'=>null];
    $id=save_advisor_followup($pdo,'P1','A1','POST',0,$values);
    twenty_assert($id>1,'Followup sequence preserved');
    try { save_advisor_followup($pdo,'P1','OTHER','DELETE',$id); throw new LogicException('Ownership bypass'); } catch (DomainException) {}
    save_advisor_followup($pdo,'P1','A1','PATCH',$id,array_replace($values,['note'=>'Edited']));
    save_advisor_followup($pdo,'P1','A1','DELETE',$id);
    twenty_assert((int)$pdo->query("SELECT COUNT(*) FROM activities WHERE activity_type LIKE 'followup_%'")->fetchColumn()===4,'Followup history retained');
    twenty_assert(latest_project_risk_score('P1')['score']===13,'Risk migrated');
    $risk=process_project_risk_scores(10);
    twenty_assert($risk['processed']===2 && latest_project_risk_score('P2')!==null,'Risk worker');
    $job=queue_project_title_check('P1','ระบบทดสอบชื่อโครงงาน');
    // Two independent PHP workers compete for one queued job.
    $workers=[];
    $code='require '.var_export(dirname(__DIR__).'/app/store.php',true).'; require '.var_export(dirname(__DIR__).'/app/ai-title-check.php',true).'; echo json_encode(claim_project_title_check_job());';
    for($i=0;$i<2;$i++){
        $process=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Cannot start competing worker');
        $workers[]=[$process,$pipes];
    }
    $claims=[];
    foreach($workers as [$process,$pipes]){
        $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);
        twenty_assert(proc_close($process)===0,'Competing worker failed: '.$error);
        $claim=json_decode($output,true,512,JSON_THROW_ON_ERROR);if($claim)$claims[]=$claim;
    }
    twenty_assert(count($claims)===1,'Concurrent claim exactly once');
    $claimed=$claims[0]; $claimed['id']=(int)$claimed['id'];
    twenty_assert($claimed['id']===$job['id'] && claim_project_title_check_job()===null,'Claim once');
    $result=process_project_title_check_job($claimed);
    twenty_assert($result['status']==='completed' && $result['max_similarity']>0.99,'Duplicate title');
    $stale=queue_project_title_check('P1','Old title'); $stale=claim_project_title_check_job();
    $new=queue_project_title_check('P1','New title');
    fail_project_title_check_job($stale,new RuntimeException('stale worker'));
    twenty_assert(latest_project_title_check('P1',(int)$stale['id'])['status']==='cancelled','Stale worker cannot revive job');
    $run=runtime_job_start($pdo); runtime_job_finish($pdo,$run,'success',15,'{}');
    twenty_assert($run>1 && runtime_record_get($pdo,'job',$run)['status']==='success','Worker log');
    twenty_assert((int)$pdo->query('SELECT COUNT(*) FROM notification_reads')->fetchColumn()===1,'Notification read state retained');
    twenty_assert(admin_dashboard_payload($pdo)['risk_overview']['total']===2,'Risk dashboard');
    twenty_assert(website_monitor_snapshot($pdo)['activity_available'],'Live history');
    // Independent fresh installation: CREATE statements only, no demo data.
    $fresh=$db.'_fresh'; $control->exec("CREATE DATABASE {$fresh} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $control->exec("USE {$fresh}");
        preg_match_all('/CREATE\\s+TABLE\\s+IF\\s+NOT\\s+EXISTS\\s+.*?;/is',file_get_contents(__DIR__.'/../database/database.sql'),$matches);
        foreach($matches[0] as $sql)$control->exec($sql);
        twenty_assert(count(twenty_table_inventory($control))===20,'Fresh install exactly 20');
        ensure_consolidated_columns($control);
        twenty_assert(count(twenty_table_inventory($control))===20,'No recreated legacy tables');
        putenv('DB_DATABASE=' . $fresh);
        putenv('DB_AUTO_MIGRATE=true');
        $child = proc_open([PHP_BINARY, __DIR__ . '/backend-tables.php'], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($child)) throw new RuntimeException('Cannot start fresh bootstrap test');
        $output=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        twenty_assert(proc_close($child)===0 && str_contains($output,'BACKEND_TABLES_OK'), 'Automatic install: '.$errors);
        putenv('DB_DATABASE=' . $db);
        putenv('DB_AUTO_MIGRATE=false');
    } finally { $control->exec("DROP DATABASE {$fresh}"); }
    echo "TWENTY_TABLES_OK migration/conflict/retry/session/progress/followups/title/risk/notifications/jobs/fresh-install\n";
} finally { $control->exec("DROP DATABASE {$db}"); }
