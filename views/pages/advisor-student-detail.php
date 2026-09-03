<?php page_header('รายละเอียดนักศึกษา', 'ข้อมูลนักศึกษา โครงงาน ไทม์ไลน์ เอกสาร ประวัติการอนุมัติ และความคิดเห็น'); ?>
<input type="hidden" id="advisorStudentId" value="<?= e($_GET['id'] ?? 'STU001') ?>">
<section class="row g-4">
    <div class="col-xl-4"><div class="card profile-card"><img id="detailStudentPhoto" src="<?= e(asset_url('img/profile-student.svg')) ?>" alt="นักศึกษา"><h2 id="detailStudentName">นักศึกษา</h2><p id="detailStudentCode" class="text-muted"></p></div></div>
    <div class="col-xl-8"><div class="card"><div class="card-header clean-header"><h2>ข้อมูลนักศึกษาและโครงงาน</h2></div><div class="card-body"><div class="detail-grid" id="advisorStudentDetailGrid"></div><div id="advisorProjectRiskScore" class="mt-3 d-none" role="status" aria-live="polite"></div></div></div></div>
</section>
<section class="card mt-4 tracking-panel" aria-labelledby="advisorProjectPulseTitle">
    <div class="card-header clean-header"><div><h2 id="advisorProjectPulseTitle">Project Pulse</h2><small class="text-muted">ขั้นตอนปัจจุบัน ผู้รับผิดชอบ และแนวโน้มความก้าวหน้า</small></div><strong id="advisorTrackingProgress">0%</strong></div>
    <div class="card-body"><div class="tracking-action-callout" id="advisorTrackingSummary" role="status" aria-live="polite"></div><ol class="milestone-rail mt-4" id="advisorMilestones" aria-label="ลำดับความก้าวหน้าโครงงาน"></ol><div class="tracking-chart-wrap mt-4"><canvas id="advisorTrackingChart" aria-label="กราฟความก้าวหน้าตามเวลา"></canvas></div></div>
</section>
<section class="row g-4 mt-1">
    <div class="col-xl-5"><div class="card"><div class="card-header clean-header"><h2>ไทม์ไลน์</h2></div><div class="card-body timeline" id="advisorStudentTimeline"></div></div></div>
    <div class="col-xl-7"><div class="card"><div class="card-header clean-header"><h2>เอกสารที่อัปโหลด</h2></div><div class="table-responsive"><table class="table align-middle" id="advisorStudentDocsTable"><thead><tr><th>เอกสาร</th><th>สถานะ</th><th>วันที่ส่ง</th><th>จัดการ</th></tr></thead><tbody></tbody></table></div></div></div>
</section>
<section class="row g-4 mt-1">
    <div class="col-xl-7"><div class="card h-100"><div class="card-header clean-header"><h2>ประวัติความก้าวหน้า</h2></div><div class="card-body tracking-history" id="advisorTrackingHistory" aria-live="polite"></div></div></div>
    <div class="col-xl-5"><div class="card h-100 followup-panel"><div class="card-header clean-header"><h2>บันทึกการติดตาม</h2></div><div class="card-body">
        <form id="advisorFollowupForm" novalidate><input type="hidden" id="advisorFollowupId">
            <div class="mb-3"><label class="form-label" for="advisorFollowupNote">สรุปการติดตาม <span class="text-danger">*</span></label><textarea class="form-control" id="advisorFollowupNote" maxlength="1000" rows="3" required></textarea></div>
            <div class="mb-3"><label class="form-label" for="advisorFollowupIssue">ปัญหาที่พบ</label><textarea class="form-control" id="advisorFollowupIssue" maxlength="1000" rows="2"></textarea></div>
            <div class="mb-3"><label class="form-label" for="advisorFollowupNextAction">สิ่งที่ต้องทำต่อ</label><textarea class="form-control" id="advisorFollowupNextAction" maxlength="1000" rows="2"></textarea></div>
            <div class="mb-3"><label class="form-label" for="advisorFollowupDate">วันที่ติดตามครั้งถัดไป (ไม่บังคับ)</label><input class="form-control" type="date" id="advisorFollowupDate"></div>
            <div class="followup-form-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> บันทึกการติดตาม</button><button class="btn btn-outline-secondary d-none" type="button" id="advisorFollowupCancel">ยกเลิกแก้ไข</button></div>
        </form><div class="followup-list mt-4" id="advisorFollowupList" aria-live="polite"></div>
    </div></div></div>
</section>
<section class="card mt-4"><div class="card-header clean-header"><h2>ความคิดเห็นและประวัติการอนุมัติ</h2></div><div class="list-group list-group-flush" id="advisorStudentComments"></div></section>
