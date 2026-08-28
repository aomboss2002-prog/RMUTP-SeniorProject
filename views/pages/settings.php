<?php page_header('ตั้งค่า', 'กำหนดชื่อระบบ ปีการศึกษา ขั้นตอนอนุมัติ และรอบรีเฟรชการแจ้งเตือน', [
    ['label' => 'โปรไฟล์', 'href' => route_url('profile'), 'icon' => 'fa-user', 'class' => 'btn btn-outline-primary'],
]); ?>
<form class="card form-card" id="settingsForm">
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">ชื่อระบบ</label><input class="form-control" name="system_name" id="systemName" required></div>
        <div class="col-md-3"><label class="form-label">ปีการศึกษา</label><input class="form-control" name="academic_year" id="academicYear" required></div>
        <div class="col-md-3"><label class="form-label">รีเฟรชการแจ้งเตือน</label><input class="form-control" name="notification_refresh" id="notificationRefresh" type="number" min="5000" step="1000"></div>
        <div class="col-md-6">
            <label class="form-label">รูปแบบการอนุมัติ</label>
            <select class="form-select" name="approval_mode" id="approvalMode">
                <option value="advisor-first">อาจารย์ที่ปรึกษาก่อน</option>
                <option value="committee-first">คณะกรรมการก่อน</option>
                <option value="parallel">ตรวจพร้อมกัน</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">ธีม</label>
            <select class="form-select" id="themePreference"><option value="light">สว่าง</option><option value="contrast">คอนทราสต์สูง</option></select>
        </div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>บันทึกการตั้งค่า</span></button></div>
</form>
