<?php page_header('403 ไม่มีสิทธิ์เข้าถึง', 'หน้านี้ถูกจำกัดสิทธิ์ตามการตั้งค่าระบบ', [
    ['label' => 'แดชบอร์ด', 'href' => route_url('dashboard'), 'icon' => 'fa-gauge-high'],
]); ?>
<section class="error-state"><i class="fa-solid fa-lock"></i><h2>ถูกจำกัดสิทธิ์</h2><p>บทบาทปัจจุบันไม่สามารถเปิดหน้านี้ได้</p></section>
