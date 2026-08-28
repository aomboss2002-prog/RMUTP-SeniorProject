<?php page_header('ข้อความ', 'ส่ง รับ แนบไฟล์ และติดตามสถานะการอ่านของข้อความ'); ?>
<section class="row g-4">
    <div class="col-xl-5">
        <form class="card form-card" id="advisorMessageForm">
            <label class="form-label">กลุ่มโครงงาน</label><select class="form-select mb-3" name="group_id" id="advisorMessageGroup" required></select>
            <label class="form-label">หัวข้อ</label><input class="form-control mb-3" name="subject" required>
            <label class="form-label">ข้อความ</label><textarea class="form-control mb-3" name="message" rows="5" required></textarea>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i><span>ส่งข้อความ</span></button>
        </form>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header clean-header"><h2>กล่องข้อความ</h2></div>
            <div class="list-group list-group-flush" id="advisorMessagesList"></div>
            <div class="message-pagination" id="advisorMessagesPagination">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-action="advisor-message-page" data-direction="previous"><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</button>
                <span id="advisorMessagePageInfo">หน้า 1 / 1</span>
                <button class="btn btn-sm btn-outline-primary" type="button" data-action="advisor-message-page" data-direction="next">ถัดไป <i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</section>
