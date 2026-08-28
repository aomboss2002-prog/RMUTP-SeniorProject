<?php page_header('แดชบอร์ด', 'ติดตามนักศึกษา การอนุมัติ เอกสารอัปโหลด และความเคลื่อนไหวล่าสุด', [
    ['label' => 'เพิ่มนักศึกษา', 'href' => route_url('student-add'), 'icon' => 'fa-user-plus'],
    ['label' => 'นำเข้า Excel', 'href' => route_url('import-excel'), 'icon' => 'fa-file-import', 'class' => 'btn btn-outline-primary'],
]); ?>

<div class="search-panel">
    <i class="fa-solid fa-magnifying-glass"></i>
    <input id="dashboardSearch" class="form-control" type="search" placeholder="ค้นหานักศึกษา โครงงาน หรืออาจารย์ที่ปรึกษา">
    <div id="dashboardSearchResults" class="search-results"></div>
</div>

<section class="summary-grid" id="dashboardSummary">
    <article class="summary-card"><span>นักศึกษา</span><strong data-summary="students">0</strong><i class="fa-solid fa-user-graduate"></i></article>
    <article class="summary-card"><span>อาจารย์ที่ปรึกษา</span><strong data-summary="advisors">0</strong><i class="fa-solid fa-chalkboard-user"></i></article>
    <article class="summary-card"><span>โครงงาน</span><strong data-summary="projects">0</strong><i class="fa-solid fa-diagram-project"></i></article>
    <article class="summary-card warning"><span>รอดำเนินการ</span><strong data-summary="pending">0</strong><i class="fa-solid fa-hourglass-half"></i></article>
</section>

<section class="row g-4">
    <div class="col-xl-7">
        <div class="card dashboard-card">
            <div class="card-header clean-header">
                <h2>สถานะโครงงาน</h2>
                <button class="icon-btn" data-action="refresh-dashboard" aria-label="รีเฟรชแดชบอร์ด"><i class="fa-solid fa-rotate"></i></button>
            </div>
            <div class="card-body chart-box"><canvas id="projectStatusChart"></canvas></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card dashboard-card">
            <div class="card-header clean-header"><h2>ประเภทเอกสารอัปโหลด</h2></div>
            <div class="card-body chart-box"><canvas id="uploadChart"></canvas></div>
        </div>
    </div>
</section>

<section class="row g-4 mt-1">
    <div class="col-xl-4">
        <div class="card dashboard-card">
            <div class="card-header clean-header"><h2>กิจกรรมล่าสุด</h2></div>
            <div class="list-group list-group-flush" id="recentActivities"></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card dashboard-card">
            <div class="card-header clean-header"><h2>ไฟล์ล่าสุด</h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle datatable" id="latestFilesTable">
                    <thead><tr><th>ไฟล์</th><th>ประเภท</th><th>สถานะ</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card dashboard-card">
            <div class="card-header clean-header"><h2>การแจ้งเตือนล่าสุด</h2></div>
            <div class="list-group list-group-flush" id="recentNotifications"></div>
        </div>
    </div>
</section>

<section class="row g-4 mt-1">
    <div class="col-xl-8">
        <div class="card dashboard-card">
            <div class="card-header clean-header"><h2>รายการรออนุมัติ</h2></div>
            <div class="table-responsive">
                <table class="table align-middle datatable tracking-table" id="pendingApprovalsTable">
                    <thead><tr><th>ขั้นตอน</th><th>ผู้ตรวจ</th><th>สถานะ</th><th>วันที่อัปเดต</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card dashboard-card">
            <div class="card-header clean-header"><h2>เมนูลัด</h2></div>
            <div class="quick-actions">
                <a class="quick-action" href="<?= e(route_url('student-add')) ?>"><i class="fa-solid fa-user-plus"></i><span>เพิ่มนักศึกษา</span></a>
                <a class="quick-action" href="<?= e(route_url('proposal')) ?>"><i class="fa-solid fa-upload"></i><span>อัปโหลดข้อเสนอ</span></a>
                <a class="quick-action" href="<?= e(route_url('barcode')) ?>"><i class="fa-solid fa-barcode"></i><span>สร้างบาร์โค้ด</span></a>
                <a class="quick-action" href="<?= e(route_url('reports')) ?>"><i class="fa-solid fa-chart-line"></i><span>ดูรายงาน</span></a>
            </div>
        </div>
    </div>
</section>
