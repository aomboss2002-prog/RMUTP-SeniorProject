<?php page_header('เพิ่มนักศึกษา', 'บันทึกข้อมูลส่วนตัว ข้อมูลการศึกษา และรูปประจำตัวนักศึกษา', [
    ['label' => 'รายชื่อนักศึกษา', 'href' => route_url('students'), 'icon' => 'fa-list', 'class' => 'btn btn-outline-primary'],
]); ?>
<?php $mode = 'add'; require __DIR__ . '/_student-form.php'; ?>
