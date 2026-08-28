<?php page_header('แดชบอร์ดนักศึกษา', 'ติดตามสถานะโครงงาน อาจารย์ที่ปรึกษา เอกสาร ไทม์ไลน์ และการแจ้งเตือนของคุณ', [
    ['label' => 'อัปโหลดข้อเสนอ', 'href' => route_url('portal-proposal'), 'icon' => 'fa-upload'],
    ['label' => 'ข้อความ', 'href' => route_url('portal-messages'), 'icon' => 'fa-envelope', 'class' => 'btn btn-outline-primary'],
]); ?>

<section class="row g-4">
    <div class="col-xl-4">
        <div class="card profile-card student-mini-profile">
            <img id="portalStudentPhoto" src="<?= e(asset_url('img/profile-student.svg')) ?>" alt="รูปนักศึกษา">
            <h2 id="portalStudentName">นักศึกษา</h2>
            <p id="portalStudentMeta" class="text-muted mb-0"></p>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header clean-header"><h2>ความคืบหน้าโครงงานโดยรวม</h2><span id="portalProgressText">0%</span></div>
            <div class="card-body">
                <h3 class="portal-project-title" id="portalProjectTitle">โครงงาน</h3>
                <p class="text-muted" id="portalAdvisorText"></p>
                <div class="progress portal-progress"><div class="progress-bar" id="portalProgressBar" style="width:0%">0%</div></div>
            </div>
        </div>
        <section class="summary-grid compact mt-4">
            <a class="summary-card link-card" href="<?= e(route_url('portal-proposal')) ?>"><span>ข้อเสนอโครงงาน</span><strong data-stage-status="proposal">-</strong><i class="fa-solid fa-file-signature"></i></a>
            <a class="summary-card link-card" href="<?= e(route_url('portal-draft')) ?>"><span>ฉบับร่าง</span><strong data-stage-status="draft">-</strong><i class="fa-solid fa-file-lines"></i></a>
            <a class="summary-card link-card" href="<?= e(route_url('portal-complete')) ?>"><span>ฉบับสมบูรณ์</span><strong data-stage-status="complete">-</strong><i class="fa-solid fa-circle-check"></i></a>
            <a class="summary-card link-card" href="<?= e(route_url('portal-barcode')) ?>"><span>บาร์โค้ด</span><strong data-stage-status="barcode">-</strong><i class="fa-solid fa-barcode"></i></a>
        </section>
    </div>
</section>

<section class="row g-4 mt-1">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header clean-header"><h2>ไทม์ไลน์</h2></div>
            <div class="card-body timeline" id="portalTimeline"></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header clean-header"><h2>การแจ้งเตือน</h2></div>
            <div class="list-group list-group-flush" id="portalNotifications"></div>
        </div>
    </div>
    <div class="col-xl-3">
        <div class="card">
            <div class="card-header clean-header"><h2>ประกาศล่าสุด</h2></div>
            <div class="card-body"><p class="mb-0" id="portalAnnouncement">กำลังโหลด...</p></div>
        </div>
    </div>
</section>
