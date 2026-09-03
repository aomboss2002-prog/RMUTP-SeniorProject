<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/store.php';
$pdo=database_connection(); ensure_project_tracking_schema($pdo); $data=load_data();
$data['advisors'][]=['id'=>'FUPADV1','name'=>'Follow-up Advisor','email'=>'followup-advisor@example.test','phone'=>'0999800001','faculty'=>'Test','department'=>'Test','students'=>1,'status'=>'Active'];
$data['students'][]=['id'=>'FUPSTU1','code'=>'079999999992-9','first_name'=>'Followup','last_name'=>'Student','email'=>'followup-student@example.test','phone'=>'0999800002','faculty'=>'Test','major'=>'Test','year_level'=>4,'advisor_id'=>'FUPADV1','advisor_roles'=>['chair'=>'FUPADV1'],'project_id'=>'FUPPRJ1','status'=>'Active'];
$data['projects'][]=['id'=>'FUPPRJ1','code'=>'FUP-001','title'=>'Followup verification','student_id'=>'FUPSTU1','advisor_id'=>'FUPADV1','status'=>'Pending','progress'=>0];
$data['groups'][]=['id'=>'FUPGRP1','name'=>'Follow-up QA','leader_id'=>'FUPSTU1','project_id'=>'FUPPRJ1','faculty'=>'Test','created_at'=>date('Y-m-d H:i:s'),'member_ids'=>['FUPSTU1'],'advisor_roles'=>['chair'=>'FUPADV1']]; save_data($data);
$errors=[];
foreach([['note'=>''],['note'=>str_repeat('x',1001)],['note'=>'ok','followup_at'=>'2026-02-30']] as $invalid){try{validate_advisor_followup_values($invalid);$errors[]='invalid payload accepted';}catch(InvalidArgumentException $expected){}}
$valid=validate_advisor_followup_values(['note'=>' valid ','followup_at'=>'']);if($valid['note']!=='valid'||$valid['followup_at']!==null)$errors[]='payload normalization';
if(!advisor_is_assigned_to_project($data,'FUPADV1','FUPPRJ1'))$errors[]='assigned advisor denied';
if(advisor_is_assigned_to_project($data,'UNASSIGNED','FUPPRJ1'))$errors[]='unassigned advisor allowed';
$insert=$pdo->prepare('INSERT INTO advisor_followups(project_id,advisor_id,note,issue,next_action,followup_at) VALUES(?,?,?,?,?,?)');
$insert->execute(['FUPPRJ1','FUPADV1','Initial note','','Next action',null]); $id=(int)$pdo->lastInsertId();
$row=project_followups('FUPPRJ1')[0]??[]; if(!array_key_exists('followup_at',$row)||$row['followup_at']!==null)$errors[]='nullable date';
$pdo->prepare('UPDATE advisor_followups SET note=?,followup_at=? WHERE id=? AND advisor_id=?')->execute(['Updated note','2026-09-15',$id,'FUPADV1']);
$row=project_followups('FUPPRJ1')[0]??[]; if(($row['note']??'')!=='Updated note')$errors[]='update failed';
$wrong=$pdo->prepare('DELETE FROM advisor_followups WHERE id=? AND advisor_id=?');$wrong->execute([$id,'UNASSIGNED']);if($wrong->rowCount())$errors[]='unassigned delete';
$pdo->prepare('DELETE FROM advisor_followups WHERE id=? AND advisor_id=?')->execute([$id,'FUPADV1']);if(project_followups('FUPPRJ1'))$errors[]='delete failed';
foreach(['groups','projects','students','advisors'] as $collection)$data[$collection]=array_values(array_filter($data[$collection]??[],static fn(array $row):bool=>!str_starts_with((string)($row['id']??''),'FUP'))); save_data($data);
$pdo->exec("DELETE FROM project_groups WHERE id LIKE 'FUP%'"); $pdo->exec("DELETE FROM projects WHERE id LIKE 'FUP%'"); $pdo->exec("DELETE FROM students WHERE id LIKE 'FUP%'"); $pdo->exec("DELETE FROM advisors WHERE id LIKE 'FUP%'");
if($errors){fwrite(STDERR,implode(PHP_EOL,$errors).PHP_EOL);exit(1);} echo "ADVISOR_FOLLOWUPS_OK\n";
