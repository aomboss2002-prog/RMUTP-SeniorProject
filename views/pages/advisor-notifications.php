<?php page_header('การแจ้งเตือน', 'แจ้งเตือนแบบ real-time เมื่อมีอัปโหลดใหม่ ข้อความ ความคิดเห็น และประกาศระบบ', [
    ['label' => 'ทำเครื่องหมายว่าอ่านแล้ว', 'href' => '#', 'icon' => 'fa-check-double', 'attr' => 'data-action="advisor-mark-read"'],
]); ?>
<section class="card mb-4">
    <div class="card-header clean-header"><h2>คำเชิญจากกลุ่มนักศึกษา</h2></div>
    <div class="table-responsive">
        <table class="table align-middle" id="advisorInvitationsTable">
            <thead><tr><th>กลุ่ม</th><th>หัวหน้ากลุ่ม</th><th>ตำแหน่ง</th><th>สถานะ</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
<section class="card"><div class="card-header clean-header"><h2>รายการแจ้งเตือน</h2></div><div class="table-responsive"><table class="table align-middle datatable" id="advisorNotificationsTable"><thead><tr><th>กลุ่ม</th><th>หัวข้อ</th><th>ข้อความ</th><th>ประเภท</th><th>สถานะ</th><th>เวลา</th></tr></thead><tbody></tbody></table></div></section>
