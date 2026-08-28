<?php page_header('เปลี่ยนรหัสผ่าน', 'อัปเดตรหัสผ่านสำหรับพอร์ทัลนักศึกษา'); ?>
<form class="card form-card" id="studentPasswordForm">
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">รหัสผ่านปัจจุบัน</label><input class="form-control" name="current_password" type="password" required></div>
        <div class="col-md-6"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" name="new_password" type="password" minlength="6" required></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-key"></i><span>เปลี่ยนรหัสผ่าน</span></button></div>
</form>
