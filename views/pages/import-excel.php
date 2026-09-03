<?php page_header('นำเข้ารายชื่อนักศึกษา', 'อ่านแบบฟอร์มรายชื่อนักศึกษา ตรวจสอบ และเพิ่มเบอร์โทรภายหลังได้', [
    ['label' => 'ส่งออก Excel', 'href' => route_url('export-excel'), 'icon' => 'fa-file-export', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="row g-4 import-student-layout">
    <div class="col-xl-4 col-xxl-3 import-upload-column">
        <div class="card upload-card">
            <label class="drop-zone" id="excelDropZone">
                <input type="file" id="excelFile" accept=".csv,.xls" hidden>
                <i class="fa-solid fa-file-excel"></i>
                <strong>ลากไฟล์ Excel หรือ CSV มาวางที่นี่</strong>
                <span>รองรับ CSV และแบบฟอร์ม .xls ของมหาวิทยาลัย</span>
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" type="button" data-action="import-preview" disabled><i class="fa-solid fa-file-import"></i><span>ยืนยันนำเข้าฐานข้อมูล</span></button>
                <button class="btn btn-outline-primary" data-action="download-sample-csv"><i class="fa-solid fa-download"></i><span>ดาวน์โหลด CSV ตัวอย่าง</span></button>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 import-preview-column">
        <div class="card import-preview-card">
            <div class="card-header clean-header import-preview-header">
                <div class="import-preview-heading">
                    <h2>รายชื่อที่ยังไม่มีในระบบ</h2>
                    <div class="import-reconcile-summary is-idle" id="importReconcileSummary" role="status" aria-live="polite">
                        <span>เลือกไฟล์เพื่อเปรียบเทียบกับฐานข้อมูล</span>
                    </div>
                </div>
            </div>
            <div class="table-responsive import-preview-table-wrap" tabindex="0" aria-label="ตารางตรวจสอบรายชื่อนักศึกษา เลื่อนในแนวนอนได้">
                <table class="table align-middle datatable" id="importPreviewTable">
                    <thead><tr><th>รหัส</th><th>ชื่อ-นามสกุล</th><th>อีเมล</th><th>เบอร์โทร (ไม่บังคับ)</th><th>ชั้นปี</th><th>สถานะ</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</section>
