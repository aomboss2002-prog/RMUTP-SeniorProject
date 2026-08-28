<?php page_header('401', 'กรุณาเข้าสู่ระบบก่อนใช้งานหน้านี้'); ?>
<section class="card"><div class="card-body text-center py-5">
    <i class="fa-solid fa-lock fa-3x text-warning mb-3"></i>
    <h2>ยังไม่ได้เข้าสู่ระบบ</h2>
    <a class="btn btn-primary mt-3" href="<?= e(route_url('login')) ?>">เข้าสู่ระบบ</a>
</div></section>
