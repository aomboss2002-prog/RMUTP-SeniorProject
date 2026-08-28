<?php page_header('แดชบอร์ดอาจารย์', 'ภาพรวมการดูแลนักศึกษา การรออนุมัติ สถิติ และการแจ้งเตือนแบบอัตโนมัติ', [
    ['label' => 'ดูนักศึกษา', 'href' => route_url('advisor-students'), 'icon' => 'fa-user-graduate'],
    ['label' => 'ส่งข้อความ', 'href' => route_url('advisor-messages'), 'icon' => 'fa-paper-plane', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="summary-grid" id="advisorSummary"></section>
<section class="row g-4 mt-1">
    <div class="col-xl-7"><div class="card dashboard-card"><div class="card-header clean-header"><h2>ความคืบหน้านักศึกษา</h2></div><div class="card-body chart-box"><canvas id="advisorProgressChart"></canvas></div></div></div>
    <div class="col-xl-5"><div class="card dashboard-card"><div class="card-header clean-header"><h2>สถิติการอนุมัติ</h2></div><div class="card-body chart-box"><canvas id="advisorApprovalChart"></canvas></div></div></div>
</section>
<section class="row g-4 mt-1">
    <div class="col-xl-7"><div class="card"><div class="card-header clean-header"><h2>กิจกรรมล่าสุด</h2></div><div class="list-group list-group-flush" id="advisorActivities"></div></div></div>
    <div class="col-xl-5"><div class="card"><div class="card-header clean-header"><h2>การแจ้งเตือน</h2></div><div class="list-group list-group-flush" id="advisorDashboardNotifications"></div></div></div>
</section>
