<?php page_header('ปฏิทินอาจารย์', 'กำหนดส่ง นัดหมาย และการประชุมนักศึกษา'); ?>
<section class="row g-4">
    <div class="col-xl-5">
        <form class="card form-card" id="advisorCalendarForm">
            <div class="d-flex align-items-center gap-3 mb-4"><span class="feature-icon"><i class="fa-solid fa-calendar-plus"></i></span><div><h2 class="h5 mb-1">สร้างนัดหมาย</h2><span class="text-muted">นักศึกษาในกลุ่มจะได้รับการแจ้งเตือน</span></div></div>
            <label class="form-label" for="advisorCalendarGroup">กลุ่มโครงงาน</label><select class="form-select mb-3" id="advisorCalendarGroup" name="group_id" required></select>
            <label class="form-label" for="advisorCalendarTitle">หัวข้อนัดหมาย</label><input class="form-control mb-3" id="advisorCalendarTitle" name="title" required>
            <div class="row g-3 mb-3"><div class="col-sm-7"><label class="form-label" for="advisorCalendarDate">วันที่</label><input class="form-control" id="advisorCalendarDate" name="date" type="date" min="<?= date('Y-m-d') ?>" required></div><div class="col-sm-5"><label class="form-label" for="advisorCalendarTime">เวลา</label><input class="form-control" id="advisorCalendarTime" name="time" type="time"></div></div>
            <div class="row g-3 mb-3"><div class="col-sm-5"><label class="form-label" for="advisorCalendarType">ประเภท</label><select class="form-select" id="advisorCalendarType" name="type"><option>นัดหมาย</option><option>ประชุม</option><option>ตรวจเอกสาร</option><option>นำเสนอ</option></select></div><div class="col-sm-7"><label class="form-label" for="advisorCalendarLocation">สถานที่ / ลิงก์</label><input class="form-control" id="advisorCalendarLocation" name="location"></div></div>
            <label class="form-label" for="advisorCalendarDetails">รายละเอียด</label><textarea class="form-control mb-3" id="advisorCalendarDetails" name="details" rows="3"></textarea>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-calendar-check"></i><span>บันทึกและแจ้งกลุ่ม</span></button>
        </form>
    </div>
    <div class="col-xl-7"><div class="card h-100"><div class="card-header clean-header"><div><h2>กิจกรรมที่กำลังจะถึง</h2><small class="text-muted">แสดงเฉพาะกลุ่มที่คุณดูแล</small></div></div><div class="list-group list-group-flush" id="advisorCalendarList"></div></div></div>
</section>
