<?php page_header('นำเข้า Excel', 'ดูตัวอย่างข้อมูลจากไฟล์ก่อนส่งเข้า REST API', [
    ['label' => 'ส่งออก Excel', 'href' => route_url('export-excel'), 'icon' => 'fa-file-export', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="row g-4">
    <div class="col-xl-5">
        <div class="card upload-card">
            <label class="drop-zone" id="excelDropZone">
                <input type="file" id="excelFile" accept=".csv,.xls,.xlsx" hidden>
                <i class="fa-solid fa-file-excel"></i>
                <strong>ลากไฟล์ Excel หรือ CSV มาวางที่นี่</strong>
                <span>ไฟล์ CSV จะแสดงตัวอย่างทันที</span>
            </label>
            <div class="form-actions">
                <button class="btn btn-primary" data-action="import-preview"><i class="fa-solid fa-file-import"></i><span>นำเข้าข้อมูลตัวอย่าง</span></button>
                <button class="btn btn-outline-primary" data-action="download-sample-csv"><i class="fa-solid fa-download"></i><span>ดาวน์โหลด CSV ตัวอย่าง</span></button>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header clean-header"><h2>ตัวอย่างข้อมูล</h2></div>
            <div class="table-responsive">
                <table class="table align-middle datatable" id="importPreviewTable">
                    <thead><tr><th>รหัส</th><th>ชื่อ</th><th>นามสกุล</th><th>อีเมล</th><th>สาขา</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</section>
