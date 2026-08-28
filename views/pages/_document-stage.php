<?php page_header($stageTitle, 'อัปโหลด ดูตัวอย่าง แทนที่ ลบ และอนุมัติเอกสารโครงงาน', [
    ['label' => 'เอกสาร', 'href' => route_url('documents'), 'icon' => 'fa-folder-open', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="row g-4">
    <div class="col-xl-5">
        <form class="card upload-card" id="documentUploadForm" data-type="<?= e($stage) ?>">
            <input type="hidden" name="type" value="<?= e($stage) ?>">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="uploadTitle">ชื่อเอกสาร</label>
                    <input class="form-control" id="uploadTitle" name="title" value="<?= e($stageTitle) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="uploadStudent">นักศึกษา</label>
                    <select class="form-select" id="uploadStudent" name="student_id"></select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="uploadProject">โครงงาน</label>
                    <select class="form-select" id="uploadProject" name="project_id"></select>
                </div>
            </div>
            <label class="drop-zone mt-3" id="dropZone">
                <input type="file" id="documentFile" name="file" accept="application/pdf,.pdf" hidden required>
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <strong>ลากไฟล์ PDF มาวางที่นี่</strong>
                <span>หรือคลิกเพื่อเลือกไฟล์</span>
            </label>
            <div class="progress mt-3" role="progressbar" aria-label="ความคืบหน้าอัปโหลด">
                <div class="progress-bar" id="uploadProgress" style="width:0%">0%</div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-upload"></i><span>อัปโหลด</span></button>
                <button class="btn btn-outline-primary" type="button" data-action="preview-selected-file"><i class="fa-solid fa-eye"></i><span>ดูตัวอย่าง PDF</span></button>
            </div>
        </form>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header clean-header"><h2>ไฟล์<?= e($stageTitle) ?></h2></div>
            <div class="table-responsive">
                <table class="table align-middle datatable document-stage-table" id="documentStageTable" data-type="<?= e($stage) ?>">
                    <thead><tr><th>ชื่อเอกสาร</th><th>นักศึกษา</th><th>ขนาด</th><th>สถานะ</th><th>วันที่อัปโหลด</th><th></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</section>
