<?php page_header('รายชื่ออาจารย์ที่ปรึกษา', 'ติดตามภาระงาน ภาควิชา และจำนวนนักศึกษาที่ดูแล', [
    ['label' => 'เพิ่มอาจารย์', 'href' => '#', 'icon' => 'fa-plus', 'attr' => 'data-action="add-advisor"'],
]); ?>
<div class="card">
    <div class="card-header clean-header"><h2>อาจารย์ที่ปรึกษา</h2></div>
    <div class="table-responsive">
        <table class="table align-middle datatable" id="advisorsTable">
            <thead><tr><th>ชื่อ</th><th>ภาควิชา</th><th>อีเมล</th><th>นักศึกษา</th><th>สถานะ</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>
