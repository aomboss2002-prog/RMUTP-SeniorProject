<?php page_header('สถานะระบบ', 'ตรวจสอบความพร้อมของบริการสำคัญโดยไม่แสดงข้อมูลลับ'); ?>

<section class="health-toolbar" aria-label="การตรวจสอบสถานะระบบ">
    <div>
        <span class="health-kicker">SYSTEM READINESS</span>
        <p id="healthCheckedAt" class="mb-0" aria-live="polite">กำลังตรวจสอบสถานะล่าสุด...</p>
    </div>
    <button type="button" class="btn btn-outline-primary" id="healthRefresh"><i class="fa-solid fa-rotate" aria-hidden="true"></i><span>รีเฟรช</span></button>
</section>

<section class="health-readiness health-is-loading" id="healthReadiness" aria-busy="true" aria-live="polite">
    <div class="health-overall">
        <span class="health-overall-mark"><i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i></span>
        <div><small>ภาพรวมระบบ</small><h2 id="healthOverallLabel">กำลังตรวจสอบ</h2><p><strong id="healthResponseTime">—</strong> เวลาตอบสนอง</p></div>
    </div>
    <ol class="health-rail" aria-label="ลำดับความพร้อมของบริการ">
        <?php foreach ([['database','fa-database','Database'],['storage','fa-box-archive','Storage'],['email','fa-envelope','Email'],['ai','fa-brain','AI'],['cron','fa-clock-rotate-left','Cron']] as [$key,$icon,$label]): ?>
        <li data-health-node="<?= e($key) ?>"><span><i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i></span><strong><?= e($label) ?></strong><small>กำลังตรวจสอบ</small></li>
        <?php endforeach; ?>
    </ol>
</section>

<section class="health-summary" id="healthSummary" aria-label="สรุปบริการ">
    <?php foreach ([['database','fa-database','Database'],['storage','fa-box-archive','Storage'],['email','fa-envelope','Email'],['ai','fa-brain','AI processing'],['cron','fa-calendar-check','Scheduled worker']] as [$key,$icon,$label]): ?>
    <article class="health-summary-card health-status-unknown" data-health-card="<?= e($key) ?>">
        <div class="health-card-icon"><i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i></div>
        <div class="health-card-copy"><span><?= e($label) ?></span><strong data-field="label">กำลังตรวจสอบ</strong><p data-field="message">กำลังโหลดข้อมูลที่ปลอดภัย...</p></div>
        <span class="health-metric" data-field="metric">—</span>
    </article>
    <?php endforeach; ?>
</section>

<div class="health-layout">
    <section class="health-panel health-service-details" aria-labelledby="healthServicesTitle">
        <header><div><span class="health-kicker">CORE SERVICES</span><h2 id="healthServicesTitle">รายละเอียดบริการ</h2></div><p>แสดงเฉพาะสถานะและข้อมูลการตั้งค่าที่ปลอดภัย</p></header>
        <div class="health-detail-list">
            <article data-detail="database"><i class="fa-solid fa-database" aria-hidden="true"></i><div><h3>ฐานข้อมูล</h3><p>การเชื่อมต่อและความพร้อมของ Schema</p></div><dl><div><dt>Latency</dt><dd data-value="latency">—</dd></div><div><dt>Schema</dt><dd data-value="schema">—</dd></div></dl></article>
            <article data-detail="storage"><i class="fa-solid fa-box-archive" aria-hidden="true"></i><div><h3>พื้นที่จัดเก็บ</h3><p>Driver และสถานะพร้อมเขียน</p></div><dl><div><dt>Driver</dt><dd data-value="driver">—</dd></div><div><dt>Configuration</dt><dd data-value="configured">—</dd></div></dl></article>
            <article data-detail="email"><i class="fa-solid fa-envelope" aria-hidden="true"></i><div><h3>อีเมล</h3><p>Transport และผู้ส่งแบบปกปิด</p></div><dl><div><dt>Transport</dt><dd data-value="transport">—</dd></div><div><dt>Sender</dt><dd data-value="sender">—</dd></div></dl></article>
        </div>
        <div class="health-actions" aria-label="เครื่องมือวินิจฉัย">
            <div><strong>ทดสอบแบบสั่งงานเท่านั้น</strong><span>การรีเฟรชหน้านี้จะไม่ส่งอีเมลหรือสร้างไฟล์</span></div>
            <button class="btn btn-outline-primary" type="button" id="healthTestStorage"><i class="fa-solid fa-hard-drive" aria-hidden="true"></i>ทดสอบ Storage</button>
            <button class="btn btn-primary" type="button" id="healthTestEmail"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i>ส่งอีเมลทดสอบ</button>
        </div>
    </section>

    <aside class="health-side-stack">
        <section class="health-panel" aria-labelledby="healthAiTitle">
            <header><div><span class="health-kicker">AUTOMATION</span><h2 id="healthAiTitle">AI operations</h2></div><span class="health-state-pill" id="healthAiState">—</span></header>
            <div class="health-engine"><span>Title engine</span><strong id="healthAiEngine">—</strong><small id="healthAiModel">—</small></div>
            <div class="health-queue" aria-label="คิวประมวลผล AI">
                <?php foreach (['queued'=>'รอ','processing'=>'กำลังทำ','completed'=>'สำเร็จ','failed'=>'ล้มเหลว'] as $key=>$label): ?><div><span><?= e($label) ?></span><strong data-queue="<?= e($key) ?>">0</strong></div><?php endforeach; ?>
            </div>
            <dl class="health-meta"><div><dt>Title ล่าสุด</dt><dd id="healthAiLatest">—</dd></div><div><dt>Risk Score ล่าสุด</dt><dd id="healthRiskLatest">—</dd></div></dl>
        </section>

        <section class="health-panel" aria-labelledby="healthCronTitle">
            <header><div><span class="health-kicker">SCHEDULE</span><h2 id="healthCronTitle">Scheduled worker</h2></div><span class="health-state-pill" id="healthCronState">—</span></header>
            <div class="health-schedule"><i class="fa-regular fa-clock" aria-hidden="true"></i><div><strong id="healthCronUtc">02:00 UTC ทุกวัน</strong><span id="healthCronThai">09:00 น. ประเทศไทย</span></div></div>
            <div class="health-run-history" id="healthRunHistory"><div class="health-empty"><i class="fa-regular fa-calendar-xmark" aria-hidden="true"></i><p>ยังไม่มีประวัติการทำงาน</p></div></div>
        </section>
    </aside>
</div>

<div class="visually-hidden" id="healthAnnouncement" aria-live="assertive"></div>
