<?php page_header('บาร์โค้ด', 'ดู พิมพ์ หรือดาวน์โหลดบาร์โค้ดโครงงานของคุณ'); ?>
<section class="card barcode-card">
    <div class="alert alert-warning text-center mb-4 d-none" id="studentBarcodeLocked">
        <i class="fa-solid fa-lock me-2"></i>
        บาร์โค้ดรหัสโครงงานจะสร้างได้เมื่อฉบับสมบูรณ์ได้รับอนุมัติแล้ว
    </div>
    <div id="studentBarcodeCanvas" class="barcode-canvas"></div>
    <strong id="studentBarcodeLabel">กำลังโหลด...</strong>
    <div class="form-actions">
        <button class="btn btn-primary" data-action="student-print-barcode"><i class="fa-solid fa-print"></i><span>พิมพ์</span></button>
        <button class="btn btn-outline-primary" data-action="student-download-barcode"><i class="fa-solid fa-download"></i><span>ดาวน์โหลด PNG</span></button>
    </div>
</section>
