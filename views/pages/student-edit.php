<?php
$studentId = $_GET['id'] ?? 'STU001';
page_header('แก้ไขนักศึกษา', 'ปรับปรุงข้อมูลส่วนตัว ข้อมูลการศึกษา และรูปประจำตัว', [
    ['label' => 'รายละเอียดนักศึกษา', 'href' => route_url('student-detail', ['id' => $studentId]), 'icon' => 'fa-id-card', 'class' => 'btn btn-outline-primary'],
]);
$mode = 'edit';
require __DIR__ . '/_student-form.php';
?>
