<?php
$stage = $meta['stage'] ?? 'proposal';
$stageTitle = $meta['title'] ?? ucfirst($stage);
page_header($stageTitle, 'ส่งไฟล์ PDF แทนที่ก่อนอนุมัติ ดูตัวอย่าง ดาวน์โหลด และติดตามรายละเอียดการตรวจ');
?>
<input type="hidden" id="portalStage" value="<?= e($stage) ?>">
<section class="row g-4">
    <div class="col-xl-5">
        <form class="card upload-card" id="studentUploadForm">
            <?php if ($stage === 'draft'): ?>
                <div class="mb-3">
                    <label class="form-label" for="studentDraftChapter">เลือกบทที่ต้องการส่ง</label>
                    <select class="form-select" id="studentDraftChapter" name="draft_chapter">
                        <?php for ($chapter = 1; $chapter <= 5; $chapter++): ?>
                            <option value="<?= $chapter ?>">บทที่ <?= $chapter ?></option>
                        <?php endfor; ?>
                    </select>
                    <div class="form-text">ส่งและติดตามผลการอนุมัติแยกทีละบท</div>
                </div>
            <?php endif; ?>
            <label class="drop-zone" id="studentDropZone">
                <input type="file" id="studentUploadFile" name="file" accept="application/pdf,.pdf" hidden>
                <i class="fa-solid fa-cloud-arrow-up"></i>
                <strong>ลากไฟล์ PDF มาวางที่นี่</strong>
                <span>เฉพาะ PDF ขนาดไม่เกิน 20 MB</span>
            </label>
            <div class="upload-inline-preview d-none" id="studentInlinePreview">
                <div class="upload-preview-header">
                    <span><i class="fa-solid fa-file-pdf"></i> <strong id="studentPreviewFilename">ตัวอย่างเอกสาร</strong></span>
                    <button class="btn btn-sm btn-light" type="button" id="studentClosePreview" aria-label="ปิดตัวอย่าง"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <iframe id="studentInlinePreviewFrame" title="ตัวอย่างไฟล์ PDF"></iframe>
            </div>
            <div class="progress mt-3"><div class="progress-bar" id="studentUploadProgress" style="width:0%">0%</div></div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-upload"></i><span>อัปโหลด / แทนที่</span></button>
                <button class="btn btn-outline-primary" type="button" data-action="student-preview-selected"><i class="fa-solid fa-expand"></i><span>ดูเต็มจอ</span></button>
            </div>
        </form>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header clean-header"><h2>รายละเอียดการส่งเอกสาร</h2></div>
            <div class="card-body detail-grid" id="studentStageInfo"></div>
            <div class="card-body border-top">
                <div class="form-actions" id="studentStageActions"></div>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-header clean-header"><h2>ความคิดเห็น</h2></div>
            <div class="list-group list-group-flush" id="studentStageComments"></div>
        </div>
    </div>
</section>
