<?php page_header('ศูนย์การแจ้งเตือน', 'ความคิดเห็นใหม่ การอนุมัติ การตีกลับ และประกาศจากระบบ', [
    ['label' => 'ทำเครื่องหมายว่าอ่านแล้ว', 'href' => '#', 'icon' => 'fa-check-double', 'attr' => 'data-action="student-mark-read"'],
]); ?>
<section class="card">
    <div class="card-header clean-header"><h2>การแจ้งเตือน</h2></div>
    <div class="table-responsive">
        <table class="table align-middle datatable" id="studentNotificationsTable">
            <thead><tr><th>กลุ่ม</th><th>หัวข้อ</th><th>ข้อความ</th><th>ประเภท</th><th>สถานะ</th><th>วันที่</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
