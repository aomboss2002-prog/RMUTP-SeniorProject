<?php page_header('เอกสาร', 'จัดการเอกสารข้อเสนอ ฉบับร่าง และฉบับสมบูรณ์', [
    ['label' => 'ข้อเสนอ', 'href' => route_url('proposal'), 'icon' => 'fa-file-signature'],
    ['label' => 'ฉบับร่าง', 'href' => route_url('draft'), 'icon' => 'fa-file-lines', 'class' => 'btn btn-outline-primary'],
    ['label' => 'ฉบับสมบูรณ์', 'href' => route_url('complete'), 'icon' => 'fa-circle-check', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="summary-grid compact">
    <a class="summary-card link-card" href="<?= e(route_url('proposal')) ?>"><span>ข้อเสนอ</span><strong data-doc-count="proposal">0</strong><i class="fa-solid fa-file-signature"></i></a>
    <a class="summary-card link-card" href="<?= e(route_url('draft')) ?>"><span>ฉบับร่าง</span><strong data-doc-count="draft">0</strong><i class="fa-solid fa-file-lines"></i></a>
    <a class="summary-card link-card" href="<?= e(route_url('complete')) ?>"><span>ฉบับสมบูรณ์</span><strong data-doc-count="complete">0</strong><i class="fa-solid fa-circle-check"></i></a>
</section>
<div class="card">
    <div class="card-header clean-header"><h2>เอกสารทั้งหมด</h2></div>
    <div class="table-responsive">
        <table class="table align-middle datatable" id="documentsTable">
            <thead><tr><th>ชื่อเอกสาร</th><th>ประเภท</th><th>โครงงาน</th><th>ขนาด</th><th>สถานะ</th><th>วันที่อัปโหลด</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
