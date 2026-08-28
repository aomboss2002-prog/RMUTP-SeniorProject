<?php page_header('การแจ้งเตือน', 'ตรวจสอบข้อความระบบและล้างรายการที่ยังไม่ได้อ่าน', [
    ['label' => 'ทำเครื่องหมายว่าอ่านแล้ว', 'href' => '#', 'icon' => 'fa-check-double', 'attr' => 'data-action="mark-all-read"'],
]); ?>
<div class="card">
    <div class="card-header clean-header"><h2>การแจ้งเตือน</h2></div>
    <div class="table-responsive">
        <table class="table align-middle datatable" id="notificationsTable">
            <thead><tr><th>หัวข้อ</th><th>ข้อความ</th><th>ประเภท</th><th>สถานะ</th><th>วันที่</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
