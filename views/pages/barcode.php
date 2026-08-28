<?php page_header('บาร์โค้ด', 'สร้างบาร์โค้ดสำหรับติดตามเอกสารโครงงานและพิมพ์ใช้งาน', [
    ['label' => 'โครงงาน', 'href' => route_url('projects'), 'icon' => 'fa-diagram-project', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="row g-4">
    <div class="col-lg-5">
        <div class="card form-card">
            <label class="form-label" for="barcodeProject">โครงงาน</label>
            <select class="form-select" id="barcodeProject"></select>
            <label class="form-label mt-3" for="barcodeText">ข้อความบาร์โค้ด</label>
            <input class="form-control" id="barcodeText" value="" readonly>
            <div class="alert alert-warning mt-3 mb-0 d-none" id="adminBarcodeLocked">สร้างบาร์โค้ดได้เมื่อฉบับสมบูรณ์ได้รับอนุมัติแล้วเท่านั้น</div>
            <div class="form-actions">
                <button class="btn btn-primary" data-action="generate-barcode"><i class="fa-solid fa-barcode"></i><span>สร้างบาร์โค้ด</span></button>
                <button class="btn btn-outline-primary" data-action="print-barcode"><i class="fa-solid fa-print"></i><span>พิมพ์</span></button>
                <button class="btn btn-outline-secondary" data-action="download-barcode"><i class="fa-solid fa-download"></i><span>PNG</span></button>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card barcode-card">
            <div id="barcodeCanvas" class="barcode-canvas"></div>
            <strong id="barcodeLabel"></strong>
        </div>
    </div>
</section>
