<?php page_header('รายงาน', 'วิเคราะห์สถานะโครงงาน เอกสารอัปโหลด ประวัติอนุมัติ และส่งออกข้อมูล', [
    ['label' => 'ส่งออก Excel', 'href' => route_url('export-excel'), 'icon' => 'fa-file-export'],
]); ?>
<section class="card form-card mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-md-4"><label class="form-label">จากวันที่</label><input class="form-control" type="date" id="reportFrom"></div>
        <div class="col-md-4"><label class="form-label">ถึงวันที่</label><input class="form-control" type="date" id="reportTo"></div>
        <div class="col-md-4"><button class="btn btn-primary w-100" data-action="refresh-reports"><i class="fa-solid fa-filter"></i><span>กรองข้อมูล</span></button></div>
    </div>
</section>
<section class="row g-4">
    <div class="col-xl-6"><div class="card dashboard-card"><div class="card-header clean-header"><h2>รายงานสถานะ</h2></div><div class="card-body chart-box"><canvas id="reportStatusChart"></canvas></div></div></div>
    <div class="col-xl-6"><div class="card dashboard-card"><div class="card-header clean-header"><h2>รายงานเอกสาร</h2></div><div class="card-body chart-box"><canvas id="reportDocumentChart"></canvas></div></div></div>
</section>
<div class="card mt-4">
    <div class="card-header clean-header"><h2>ข้อมูลรายงาน</h2></div>
    <div class="table-responsive">
        <table class="table align-middle datatable" id="reportsTable">
            <thead><tr><th>รหัส</th><th>โครงงาน</th><th>นักศึกษา</th><th>อาจารย์ที่ปรึกษา</th><th>สถานะ</th><th>ความคืบหน้า</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
