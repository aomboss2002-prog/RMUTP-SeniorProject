<?php
$studentId = $_GET['id'] ?? 'STU001';
page_header('รายละเอียดนักศึกษา', 'ตรวจสอบประวัติ ความคืบหน้า เอกสาร ความคิดเห็น และประวัติการอนุมัติ', [
    ['label' => 'แก้ไขนักศึกษา', 'href' => route_url('student-edit', ['id' => $studentId]), 'icon' => 'fa-user-pen'],
    ['label' => 'ย้อนกลับ', 'href' => route_url('students'), 'icon' => 'fa-arrow-left', 'class' => 'btn btn-outline-primary'],
]);
?>
<input type="hidden" id="studentDetailId" value="<?= e($studentId) ?>">

<section class="row g-4">
    <div class="col-xl-4">
        <div class="card profile-card">
            <img id="studentPhoto" src="<?= e(asset_url('img/profile-student.svg')) ?>" alt="รูปนักศึกษา">
            <h2 id="studentFullName">นักศึกษา</h2>
            <p id="studentCode" class="text-muted"></p>
            <div id="studentStatus"></div>
        </div>
        <div class="card mt-4">
            <div class="card-header clean-header"><h2>อาจารย์ที่ปรึกษา</h2></div>
            <div class="card-body" id="studentAdvisor"></div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header clean-header"><h2>ข้อมูลนักศึกษา</h2></div>
            <div class="card-body detail-grid" id="studentInfoGrid"></div>
        </div>
        <div class="card mt-4">
            <div class="card-header clean-header"><h2>ไทม์ไลน์</h2></div>
            <div class="card-body timeline" id="studentTimeline"></div>
        </div>
    </div>
</section>

<section class="card mt-4 tracking-panel" aria-labelledby="adminProjectPulseTitle">
    <div class="card-header clean-header"><div><h2 id="adminProjectPulseTitle">Project Pulse</h2><small class="text-muted">มุมมองตรวจสอบประวัติแบบอ่านอย่างเดียว</small></div><strong id="adminTrackingProgress">0%</strong></div>
    <div class="card-body"><div class="tracking-action-callout" id="adminTrackingSummary" role="status" aria-live="polite"></div><ol class="milestone-rail mt-4" id="adminMilestones" aria-label="ลำดับความก้าวหน้าโครงงาน"></ol><div class="tracking-chart-wrap mt-4"><canvas id="adminTrackingChart" aria-label="กราฟความก้าวหน้าตามเวลา"></canvas></div></div>
</section>
<section class="row g-4 mt-1">
    <div class="col-xl-7"><div class="card h-100"><div class="card-header clean-header"><h2>ประวัติความก้าวหน้า</h2></div><div class="card-body tracking-history" id="adminTrackingHistory"></div></div></div>
    <div class="col-xl-5"><div class="card h-100"><div class="card-header clean-header"><h2>บันทึกการติดตามจากอาจารย์</h2></div><div class="card-body followup-list" id="adminTrackingFollowups"></div></div></div>
</section>

<section class="row g-4 mt-1">
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header clean-header"><h2>ไฟล์ที่อัปโหลด</h2></div>
            <div class="table-responsive">
                <table class="table align-middle datatable" id="studentFilesTable">
                    <thead><tr><th>ชื่อเอกสาร</th><th>ประเภท</th><th>สถานะ</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card">
            <div class="card-header clean-header">
                <h2>ความคิดเห็น</h2>
                <button class="btn btn-sm btn-primary" data-action="add-comment"><i class="fa-solid fa-comment"></i><span>เพิ่ม</span></button>
            </div>
            <div class="list-group list-group-flush" id="studentComments"></div>
        </div>
    </div>
</section>

<section class="card mt-4">
    <div class="card-header clean-header"><h2>ประวัติการอนุมัติ</h2></div>
    <div class="table-responsive">
        <table class="table align-middle datatable" id="approvalHistoryTable">
            <thead><tr><th>ขั้นตอน</th><th>ผู้ตรวจ</th><th>สถานะ</th><th>วันที่</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
