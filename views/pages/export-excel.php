<?php page_header('ส่งออก Excel', 'ดาวน์โหลดข้อมูล CSV ที่เปิดด้วย Excel ได้จาก API', [
    ['label' => 'นำเข้า Excel', 'href' => route_url('import-excel'), 'icon' => 'fa-file-import', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="export-grid">
    <button class="export-card" data-action="download-resource" data-resource="students"><i class="fa-solid fa-user-graduate"></i><strong>นักศึกษา</strong><span>ส่งออกข้อมูลนักศึกษา</span></button>
    <button class="export-card" data-action="download-resource" data-resource="advisors"><i class="fa-solid fa-chalkboard-user"></i><strong>อาจารย์ที่ปรึกษา</strong><span>ส่งออกข้อมูลการดูแลนักศึกษา</span></button>
    <button class="export-card" data-action="download-resource" data-resource="projects"><i class="fa-solid fa-diagram-project"></i><strong>โครงงาน</strong><span>ส่งออกข้อมูลโครงงาน</span></button>
    <button class="export-card" data-action="download-resource" data-resource="documents"><i class="fa-solid fa-folder-open"></i><strong>เอกสาร</strong><span>ส่งออกข้อมูลไฟล์ที่อัปโหลด</span></button>
</section>
