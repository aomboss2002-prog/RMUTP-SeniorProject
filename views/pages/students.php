<?php page_header('รายชื่อนักศึกษา', 'จัดการข้อมูลนักศึกษาพร้อมค้นหา กรองข้อมูล แบ่งหน้า และส่งออกไฟล์', [
    ['label' => 'เพิ่มนักศึกษา', 'href' => route_url('student-add'), 'icon' => 'fa-user-plus'],
    ['label' => 'ส่งออก', 'href' => '#', 'icon' => 'fa-file-export', 'class' => 'btn btn-outline-primary', 'attr' => 'data-action="download-resource" data-resource="students"'],
]); ?>

<div class="card">
    <div class="card-header clean-header">
        <h2>นักศึกษา</h2>
        <select class="form-select form-select-sm table-filter" id="studentStatusFilter">
            <option value="">ทุกสถานะนักศึกษา</option>
            <option value="Active">กำลังศึกษา</option>
            <option value="Completed">สำเร็จการศึกษา</option>
            <option value="Inactive">ไม่ใช้งาน</option>
        </select>
    </div>
    <div class="table-responsive">
        <table class="table align-middle datatable students-table-wide" id="studentsTable">
            <thead><tr><th>รหัส</th><th>ชื่อ-นามสกุล</th><th>สาขา</th><th>อาจารย์ที่ปรึกษา</th><th>สถานะนักศึกษา</th><th class="text-end">จัดการ</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
