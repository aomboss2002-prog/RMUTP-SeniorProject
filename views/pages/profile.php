<?php page_header('โปรไฟล์', 'จัดการข้อมูลบัญชีผู้ดูแลระบบปัจจุบัน', [
    ['label' => 'ตั้งค่า', 'href' => route_url('settings'), 'icon' => 'fa-gear', 'class' => 'btn btn-outline-primary'],
]); ?>
<section class="row g-4">
    <div class="col-xl-4">
        <div class="card profile-card">
            <img id="profileAvatar" src="<?= e(asset_url('img/profile-admin.svg')) ?>" alt="โปรไฟล์">
            <h2 id="profileNamePreview">ผู้ดูแลระบบ RMUTP</h2>
            <p id="profileRolePreview" class="text-muted">ผู้ดูแลระบบ</p>
        </div>
    </div>
    <div class="col-xl-8">
        <form class="card form-card" id="profileForm">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">ชื่อ</label><input class="form-control" name="name" id="profileName" required></div>
                <div class="col-md-6"><label class="form-label">อีเมล</label><input class="form-control" name="email" id="profileEmail" type="email" required></div>
                <div class="col-md-6"><label class="form-label">บทบาท</label><input class="form-control" name="role" id="profileRole" required></div>
                <div class="col-md-6"><label class="form-label">URL รูปโปรไฟล์</label><input class="form-control" name="avatar" id="profileAvatarInput"></div>
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i><span>บันทึกโปรไฟล์</span></button></div>
        </form>
    </div>
</section>
