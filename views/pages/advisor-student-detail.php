<?php page_header('รายละเอียดนักศึกษา', 'ข้อมูลนักศึกษา โครงงาน ไทม์ไลน์ เอกสาร ประวัติการอนุมัติ และความคิดเห็น'); ?>
<input type="hidden" id="advisorStudentId" value="<?= e($_GET['id'] ?? 'STU001') ?>">
<section class="row g-4">
    <div class="col-xl-4"><div class="card profile-card"><img id="detailStudentPhoto" src="<?= e(asset_url('img/profile-student.svg')) ?>" alt="นักศึกษา"><h2 id="detailStudentName">นักศึกษา</h2><p id="detailStudentCode" class="text-muted"></p></div></div>
    <div class="col-xl-8"><div class="card"><div class="card-header clean-header"><h2>ข้อมูลนักศึกษาและโครงงาน</h2></div><div class="card-body detail-grid" id="advisorStudentDetailGrid"></div></div></div>
</section>
<section class="row g-4 mt-1">
    <div class="col-xl-5"><div class="card"><div class="card-header clean-header"><h2>ไทม์ไลน์</h2></div><div class="card-body timeline" id="advisorStudentTimeline"></div></div></div>
    <div class="col-xl-7"><div class="card"><div class="card-header clean-header"><h2>เอกสารที่อัปโหลด</h2></div><div class="table-responsive"><table class="table align-middle" id="advisorStudentDocsTable"><thead><tr><th>เอกสาร</th><th>สถานะ</th><th>วันที่ส่ง</th><th>จัดการ</th></tr></thead><tbody></tbody></table></div></div></div>
</section>
<section class="card mt-4"><div class="card-header clean-header"><h2>ความคิดเห็นและประวัติการอนุมัติ</h2></div><div class="list-group list-group-flush" id="advisorStudentComments"></div></section>
