<?php page_header('โครงงาน', 'ตรวจสอบความคืบหน้า สถานะอนุมัติ อาจารย์ที่ปรึกษา และลิงก์บาร์โค้ด/ไทม์ไลน์', [
    ['label' => 'บาร์โค้ด', 'href' => route_url('barcode'), 'icon' => 'fa-barcode', 'class' => 'btn btn-outline-primary'],
]); ?>
<div class="card">
    <div class="card-header clean-header">
        <h2>โครงงาน</h2>
        <select class="form-select form-select-sm table-filter" id="projectStatusFilter">
            <option value="">ทุกสถานะ</option><option value="Pending">รอดำเนินการ</option><option value="Draft">ฉบับร่าง</option><option value="Review">รอตรวจสอบ</option><option value="Approved">อนุมัติแล้ว</option><option value="Completed">เสร็จสมบูรณ์</option>
        </select>
    </div>
    <div class="table-responsive">
        <table class="table align-middle datatable" id="projectsTable">
            <thead><tr><th>รหัส</th><th>ชื่อโครงงาน</th><th>นักศึกษา</th><th>อาจารย์ที่ปรึกษา</th><th>ความคืบหน้า</th><th>สถานะ</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
