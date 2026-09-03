<?php
$stage = $meta['stage'] ?? 'proposal';
$stageName = ['proposal' => 'ข้อเสนอโครงงาน', 'draft' => 'ฉบับร่าง', 'complete' => 'ฉบับสมบูรณ์'][$stage] ?? $stage;
page_header($meta['title'] ?? $stageName, 'เปิดดู PDF ดาวน์โหลด อนุมัติ ไม่อนุมัติ และขอแก้ไข', [
    ['label' => 'รีเฟรชรายการ', 'href' => '#', 'icon' => 'fa-rotate', 'attr' => 'data-action="advisor-refresh-stage"'],
]);
?>
<input type="hidden" id="advisorStage" value="<?= e($stage) ?>">
<section class="card">
    <div class="card-header clean-header"><h2>รายการรอพิจารณา: <?= e($stageName) ?></h2></div>
    <div class="table-responsive">
        <table class="table align-middle datatable documents-review-table" id="advisorStageTable">
            <thead><tr><th>นักศึกษา</th><th>โครงงาน</th><th>ไฟล์</th><th>สถานะ</th><th>วันที่ส่ง</th><th>จัดการ</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
