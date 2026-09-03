<?php page_header('นักศึกษาของฉัน', 'แสดงเฉพาะนักศึกษาที่อยู่ในความดูแล พร้อมสถานะเอกสารแต่ละขั้นตอน'); ?>
<section class="card mb-4">
    <div class="card-header clean-header"><h2>กลุ่มโครงงานของฉัน</h2></div>
    <div class="table-responsive">
        <table class="table align-middle" id="advisorGroupsTable">
            <thead><tr><th>ชื่อกลุ่ม</th><th>โครงงาน</th><th>ตำแหน่ง</th><th>สมาชิก</th><th>รายชื่อสมาชิก</th><th>สถานะ</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
<section class="card">
    <div class="card-header clean-header">
        <h2>รายชื่อนักศึกษา</h2>
        <select class="form-select form-select-sm table-filter" id="advisorStudentStatusFilter">
            <option value="">ทุกสถานะ</option><option value="Pending">รอดำเนินการ</option><option value="Review">รอแก้ไข</option><option value="Approved">อนุมัติแล้ว</option><option value="Rejected">ไม่อนุมัติ</option>
        </select>
    </div>
    <div class="table-responsive">
        <table class="table align-middle datatable students-table-wide" id="advisorStudentsTable">
            <thead><tr><th>รหัสนักศึกษา</th><th>ชื่อ-นามสกุล</th><th>สาขา</th><th>ชื่อโครงงาน</th><th>Proposal</th><th>Draft</th><th>Complete</th><th>สถานะ</th><th>จัดการ</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
