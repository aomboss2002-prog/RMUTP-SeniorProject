<?php page_header('โปรไฟล์อาจารย์', 'ข้อมูลอาจารย์ที่ปรึกษา เปลี่ยนเบอร์โทร อีเมล รหัสผ่าน และรูปโปรไฟล์'); ?>
<section class="row g-4">
    <div class="col-xl-4"><div class="card profile-card"><img id="advisorProfilePhoto" src="<?= e(asset_url('img/profile-advisor.svg')) ?>" alt="อาจารย์"><h2 id="advisorProfileName">อาจารย์</h2><p id="advisorProfileDepartment" class="text-muted"></p></div></div>
    <div class="col-xl-8">
        <form class="card form-card" id="advisorProfileForm">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">ชื่อ</label><input class="form-control" id="apName" disabled></div>
                <div class="col-md-6"><label class="form-label">สาขา/ภาควิชา</label><input class="form-control" id="apDepartment" disabled></div>
                <div class="col-md-6"><label class="form-label">อีเมล</label><input class="form-control" name="email" id="apEmail" type="email" required></div>
                <div class="col-md-6"><label class="form-label">เบอร์โทรศัพท์</label><input class="form-control" name="phone" id="apPhone" required></div>
                <div class="col-md-6"><label class="form-label">รหัสผ่านใหม่</label><input class="form-control" name="password" type="password" minlength="6"></div>
                <div class="col-md-6"><label class="form-label">รูปโปรไฟล์</label><input class="form-control" name="photo" type="file" accept="image/*"></div>
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>บันทึกโปรไฟล์</span></button></div>
        </form>
    </div>
</section>
