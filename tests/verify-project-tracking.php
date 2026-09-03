<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/store.php';

$pdo = database_connection(); ensure_project_tracking_schema($pdo);
$data = load_data();
$prefixes = ['TRKADV', 'TRKSTU', 'TRKPRJ', 'TRKGRP', 'TRKDOC'];
$isTestId = static function(string $id) use ($prefixes): bool { foreach ($prefixes as $prefix) if (str_starts_with($id, $prefix)) return true; return false; };
foreach (['documents', 'groups', 'projects', 'students', 'advisors'] as $collection) {
    $data[$collection] = array_values(array_filter($data[$collection] ?? [], static fn(array $row): bool => !$isTestId((string) ($row['id'] ?? ''))));
}
save_data($data);

$data['advisors'][] = ['id'=>'TRKADV1','name'=>'Tracking Advisor','email'=>'tracking-advisor@example.test','phone'=>'0999900001','faculty'=>'Test','department'=>'Test','students'=>1,'status'=>'Active'];
$data['students'][] = ['id'=>'TRKSTU1','code'=>'079999999991-1','first_name'=>'Tracking','last_name'=>'Student','email'=>'tracking-student@example.test','phone'=>'0999900002','faculty'=>'Test','major'=>'Test','year_level'=>4,'advisor_id'=>'TRKADV1','advisor_roles'=>['chair'=>'TRKADV1'],'project_id'=>'TRKPRJ1','status'=>'Active'];
$data['projects'][] = ['id'=>'TRKPRJ1','code'=>'TRK-001','title'=>'Tracking verification project','student_id'=>'TRKSTU1','advisor_id'=>'TRKADV1','status'=>'Pending','progress'=>0,'updated_at'=>date('Y-m-d H:i:s')];
$data['groups'][] = ['id'=>'TRKGRP1','name'=>'Tracking QA','leader_id'=>'TRKSTU1','project_id'=>'TRKPRJ1','faculty'=>'Test','created_at'=>date('Y-m-d H:i:s'),'member_ids'=>['TRKSTU1'],'advisor_roles'=>['chair'=>'TRKADV1']];
save_data($data);

$errors=[]; $observed=[calculated_project_progress([])]; $counter=0;
$saveDoc = static function(string $type, ?int $chapter, string $status) use (&$data, &$counter, &$observed): string {
    $counter++; $id='TRKDOC'.str_pad((string)$counter,2,'0',STR_PAD_LEFT);
    $data['documents'][]=['id'=>$id,'project_id'=>'TRKPRJ1','student_id'=>'TRKSTU1','group_id'=>'TRKGRP1','type'=>$type,'chapter'=>$chapter,'title'=>$type,'filename'=>$id.'.pdf','size'=>'1 MB','status'=>$status,'uploaded_at'=>date('Y-m-d H:i:s', time()+$counter)];
    save_data($data); $observed[]=calculated_project_progress(tracking_documents_for_project($data['documents'],'TRKPRJ1')); return $id;
};
$setStatus = static function(string $id,string $status) use (&$data,&$observed): void {
    foreach($data['documents'] as &$row) if(($row['id']??'')===$id){$row['status']=$status;$row['approved_at']=date('Y-m-d H:i:s');break;} unset($row);
    save_data($data); $observed[]=calculated_project_progress(tracking_documents_for_project($data['documents'],'TRKPRJ1'));
};

$proposal=$saveDoc('proposal',null,'Pending');
$setStatus($proposal,'NeedsRevision');
foreach($data['documents'] as &$row) if($row['id']===$proposal){$row['status']='Resubmitted';$row['uploaded_at']=date('Y-m-d H:i:s',time()+20);} unset($row); save_data($data);
$setStatus($proposal,'Approved');
for($chapter=1;$chapter<=5;$chapter++){ $id=$saveDoc('draft',$chapter,'Pending'); $setStatus($id,'Approved'); }
$complete=$saveDoc('complete',null,'Pending'); $setStatus($complete,'Approved');
// Remove duplicate status-only observation from revision; focus on the canonical progression checkpoints.
$checkpoints=[0,15,30,38,46,54,62,70,85,100];
$actual=array_values(array_unique($observed));
if($actual!==$checkpoints)$errors[]='progress sequence '.json_encode($actual);
$before=count(project_tracking_history('TRKPRJ1')); save_data($data); $after=count(project_tracking_history('TRKPRJ1'));
if($before!==$after)$errors[]='retry created duplicate history';
foreach($data['documents'] as $index=>$row) if($row['id']===$complete){unset($data['documents'][$index]);break;} $data['documents']=array_values($data['documents']); save_data($data);
$history=project_tracking_history('TRKPRJ1');
foreach(['submitted','resubmitted','revision_requested','approved','document_deleted'] as $type) if(!array_filter($history,fn($row)=>$row['event_type']===$type))$errors[]='missing '.$type;
$tracking=derive_project_tracking(tracking_documents_for_project($data['documents'],'TRKPRJ1'),$history);
if(count($tracking['milestones'])!==7)$errors[]='milestone count';

foreach(['documents','groups','projects','students','advisors'] as $collection)$data[$collection]=array_values(array_filter($data[$collection]??[],static fn(array $row):bool=>!$isTestId((string)($row['id']??''))));
save_data($data);
$pdo->exec("DELETE FROM project_groups WHERE id LIKE 'TRK%'");
$pdo->exec("DELETE FROM projects WHERE id LIKE 'TRK%'");
$pdo->exec("DELETE FROM students WHERE id LIKE 'TRK%'");
$pdo->exec("DELETE FROM advisors WHERE id LIKE 'TRK%'");
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);} echo 'PROJECT_TRACKING_OK history='.$before.PHP_EOL;
