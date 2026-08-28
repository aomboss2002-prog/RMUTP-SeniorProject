<form class="card form-card" id="studentForm" data-mode="<?= e($mode) ?>" data-id="<?= e($_GET['id'] ?? '') ?>">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="studentCodeInput">รหัสนักศึกษา</label>
            <input class="form-control" id="studentCodeInput" name="code" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="firstNameInput">ชื่อ</label>
            <input class="form-control" id="firstNameInput" name="first_name" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="lastNameInput">นามสกุล</label>
            <input class="form-control" id="lastNameInput" name="last_name" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="emailInput">อีเมล</label>
            <input class="form-control" id="emailInput" name="email" type="email" required>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phoneInput">เบอร์โทรศัพท์</label>
            <input class="form-control" id="phoneInput" name="phone" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="yearInput">ชั้นปี</label>
            <select class="form-select" id="yearInput" name="year_level"><option>1</option><option>2</option><option>3</option><option selected>4</option></select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="facultyInput">คณะ</label>
            <select class="form-select" id="facultyInput" name="faculty" required>
                <option value="คณะบริหารธุรกิจ">คณะบริหารธุรกิจ</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="majorInput">สาขา</label>
            <select class="form-select" id="majorInput" name="major" required>
                <?php foreach (app_majors() as $major): ?>
                    <option value="<?= e($major) ?>"><?= e($major) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label" for="studentPhotoFile">รูปนักศึกษา</label>
            <div class="student-photo-upload">
                <img id="studentPhotoPreview" src="<?= e(asset_url('img/profile-student.svg')) ?>" alt="ตัวอย่างรูปนักศึกษา">
                <div class="flex-grow-1">
                    <input class="form-control" id="studentPhotoFile" name="photo_file" type="file" accept="image/jpeg,image/png,image/webp,image/gif,.jpg,.jpeg,.png,.webp,.gif">
                    <small class="text-muted d-block mt-2">รองรับ JPG, PNG, WEBP หรือ GIF ขนาดไม่เกิน 5 MB</small>
                </div>
            </div>
        </div>
    </div>
    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>บันทึกนักศึกษา</span></button>
        <button class="btn btn-outline-secondary" type="reset"><i class="fa-solid fa-rotate-left"></i><span>ล้างข้อมูล</span></button>
    </div>
</form>
