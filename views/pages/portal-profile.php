<?php page_header('โปรไฟล์', 'ดูข้อมูลนักศึกษาและแก้ไขข้อมูลติดต่อที่อนุญาตให้แก้ไขได้', [
    ['label' => 'เปลี่ยนรหัสผ่าน', 'href' => route_url('portal-change-password'), 'icon' => 'fa-key', 'class' => 'btn btn-outline-primary'],
]); ?>

<section class="row g-4">
    <div class="col-xl-4">
        <div class="card profile-card">
            <img id="studentProfilePhoto" src="<?= e(asset_url('img/profile-student.svg')) ?>" alt="โปรไฟล์นักศึกษา">
            <h2 id="studentProfileName">นักศึกษา</h2>
            <p id="studentProfileCode" class="text-muted"></p>
        </div>
    </div>
    <div class="col-xl-8">
        <form class="card form-card" id="studentProfileForm">
            <input type="hidden" name="photo" id="spPhoto">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">รหัสนักศึกษา</label>
                    <input class="form-control" id="spCode" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ชื่อ-นามสกุล</label>
                    <input class="form-control" id="spName" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">สาขา</label>
                    <input class="form-control" id="spDepartment" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label">อีเมล</label>
                    <input class="form-control" name="email" id="spEmail" type="email" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">เบอร์โทรศัพท์</label>
                    <input class="form-control" name="phone" id="spPhone" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">อัปโหลดรูปโปรไฟล์</label>
                    <input class="form-control" name="photo_file" id="spPhotoFile" type="file" accept="image/*">
                </div>
            </div>
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    <i class="fa-solid fa-floppy-disk"></i>
                    <span>บันทึกโปรไฟล์</span>
                </button>
            </div>
        </form>
    </div>
</section>
