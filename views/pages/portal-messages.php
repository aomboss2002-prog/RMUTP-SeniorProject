<?php page_header('ข้อความ', 'ติดต่ออาจารย์ที่ปรึกษาและติดตามสถานะการอ่านข้อความ'); ?>
<section class="row g-4">
    <div class="col-xl-5">
        <form class="card form-card" id="studentMessageForm">
            <div class="mb-3"><label class="form-label" for="studentMessageRecipient">ส่งถึง</label><select class="form-select" id="studentMessageRecipient" name="advisor_id" required><option value="">เลือกคณะกรรมการ</option></select><div class="form-text">ส่งได้เฉพาะอาจารย์ที่ตอบรับตำแหน่งในกลุ่มแล้ว</div></div>
            <div class="mb-3"><label class="form-label">หัวข้อ</label><input class="form-control" name="subject" required></div>
            <div class="mb-3"><label class="form-label">ข้อความ</label><textarea class="form-control" name="message" rows="5" required></textarea></div>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i><span>ส่งข้อความ</span></button>
        </form>
    </div>
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header clean-header"><h2>กล่องข้อความ</h2></div>
            <div class="list-group list-group-flush" id="studentMessagesList"></div>
            <div class="message-pagination" id="studentMessagesPagination">
                <button class="btn btn-sm btn-outline-secondary" type="button" data-action="student-message-page" data-direction="previous"><i class="fa-solid fa-chevron-left"></i> ก่อนหน้า</button>
                <span id="studentMessagePageInfo">หน้า 1 / 1</span>
                <button class="btn btn-sm btn-outline-primary" type="button" data-action="student-message-page" data-direction="next">ถัดไป <i class="fa-solid fa-chevron-right"></i></button>
            </div>
        </div>
    </div>
</section>
