<?php page_header('รายงาน', 'สร้างรายงานความคืบหน้านักศึกษา รายงานการอนุมัติ รายงานสาขา และส่งออก Excel/PDF', [
    ['label' => 'Export Excel', 'href' => '#', 'icon' => 'fa-file-excel', 'attr' => 'data-action="advisor-export-excel"'],
    ['label' => 'Export PDF', 'href' => '#', 'icon' => 'fa-file-pdf', 'class' => 'btn btn-outline-primary', 'attr' => 'data-action="advisor-export-pdf"'],
]); ?>
<section class="row g-4">
    <div class="col-xl-4"><div class="card report-card"><i class="fa-solid fa-bars-progress"></i><h2>รายงานความคืบหน้า</h2><p>สรุป % ความคืบหน้าของนักศึกษา</p></div></div>
    <div class="col-xl-4"><div class="card report-card"><i class="fa-solid fa-stamp"></i><h2>รายงานการอนุมัติ</h2><p>Proposal, Draft, Complete</p></div></div>
    <div class="col-xl-4"><div class="card report-card"><i class="fa-solid fa-building-columns"></i><h2>รายงานสาขา</h2><p>ภาพรวมแยกตามสาขา/ภาควิชา</p></div></div>
</section>
<section class="card mt-4"><div class="card-header clean-header"><h2>ข้อมูลรายงาน</h2></div><div class="table-responsive"><table class="table align-middle datatable" id="advisorReportsTable"><thead><tr><th>นักศึกษา</th><th>สาขา</th><th>โครงงาน</th><th>ความคืบหน้า</th><th>สถานะ</th></tr></thead><tbody></tbody></table></div></section>
