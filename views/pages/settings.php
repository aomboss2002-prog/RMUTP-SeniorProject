<?php page_header('ตั้งค่าระบบ', 'จัดการข้อมูลทั่วไป การแจ้งเตือน และการทำงานของ AI'); ?>
<form class="card settings-page" id="settingsForm" aria-busy="true">
    <section class="settings-section" aria-labelledby="settingsGeneralTitle">
        <header><span class="settings-section-number">01</span><h2 id="settingsGeneralTitle">ข้อมูลทั่วไป</h2><p>ปีการศึกษาของระบบ</p></header>
        <div class="settings-section-content">
            <label class="form-label" for="academicYear">ปีการศึกษา (พ.ศ.)</label>
            <input class="form-control settings-short-input" name="academic_year" id="academicYear" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" required disabled aria-describedby="academicYearHelp">
            <p class="settings-help" id="academicYearHelp">ปีที่แสดงในคลังโครงงาน ระบุ พ.ศ. 2400–2999<br>ไม่เปลี่ยนรหัสนักศึกษาหรือวันที่ของโครงงานเดิม</p>
        </div>
    </section>
    <section class="settings-section" aria-labelledby="settingsNotificationTitle">
        <header><span class="settings-section-number">02</span><h2 id="settingsNotificationTitle">การแจ้งเตือน</h2><p>กำหนดการรีเฟรชอัตโนมัติบนหน้าเว็บ</p></header>
        <div class="settings-section-content">
            <div class="form-check form-switch settings-switch"><input class="form-check-input" type="checkbox" role="switch" id="notificationsEnabled" name="notifications_enabled" disabled aria-describedby="notificationHelp"><label class="form-check-label" for="notificationsEnabled">รีเฟรชข้อมูลและการแจ้งเตือนอัตโนมัติ</label></div>
            <p class="settings-help" id="notificationHelp">เมื่อปิด ยังโหลดข้อมูลตอนเปิดหน้าหรือกดรีเฟรชได้ ไม่หยุดการเก็บแจ้งเตือนหรือส่งอีเมล<br>ค่าใหม่มีผลเมื่อโหลดหน้าอีกครั้ง</p>
            <label class="form-label" for="notificationRefresh">ตรวจสอบทุก</label>
            <div class="settings-interval"><input class="form-control settings-short-input" name="notification_refresh" id="notificationRefresh" type="number" min="10" max="300" step="1" required disabled aria-describedby="notificationIntervalHelp"><span>วินาที</span></div>
            <p class="settings-help" id="notificationIntervalHelp">ตั้งได้ 10–300 วินาที</p>
        </div>
    </section>
    <section class="settings-section" aria-labelledby="settingsAiTitle">
        <header><span class="settings-section-number">03</span><h2 id="settingsAiTitle">ผู้ช่วย AI</h2><p>ควบคุมการประมวลผลครั้งถัดไป</p></header>
        <div class="settings-section-content">
            <div class="form-check form-switch settings-switch"><input class="form-check-input" type="checkbox" role="switch" name="ai_title_enabled" id="aiTitleEnabled" disabled><label class="form-check-label" for="aiTitleEnabled">ตรวจสอบชื่อโครงงานด้วย AI</label></div>
            <div class="form-check form-switch settings-switch"><input class="form-check-input" type="checkbox" role="switch" name="ai_risk_enabled" id="aiRiskEnabled" disabled><label class="form-check-label" for="aiRiskEnabled">ประเมินความเสี่ยงโครงงาน (Risk Score)</label></div>
            <p class="settings-help">การปิดใช้งานไม่ลบผลการวิเคราะห์เดิม<br>ลำดับอนุมัติยังเป็นไปตามขั้นตอนของระบบ</p>
        </div>
    </section>
    <section class="settings-section settings-services" aria-labelledby="settingsServiceTitle">
        <header><span class="settings-section-number">04</span><h2 id="settingsServiceTitle">บริการระบบ</h2><p>ตรวจสอบการเชื่อมต่อและงานอัตโนมัติ</p></header>
        <div class="settings-section-content"><a class="btn btn-outline-primary" href="<?= e(route_url('system-health')) ?>"><i class="fa-solid fa-heart-pulse" aria-hidden="true"></i><span>ตรวจสอบสถานะระบบ</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a><p class="settings-help">ทดสอบอีเมล SMTP และดูสถานะ Cron ได้ที่หน้าสถานะระบบ</p><p class="settings-secret-note"><i class="fa-solid fa-lock" aria-hidden="true"></i><span>ค่าฐานข้อมูล อีเมล และคีย์ลับจัดการผ่าน Vercel<br>ไม่ต้องกรอกข้อมูลลับในหน้านี้</span></p></div>
    </section>
    <footer class="settings-footer">
        <div class="settings-feedback"><p id="settingsStatus" role="status" aria-live="polite" aria-atomic="true" data-state="loading">กำลังโหลดการตั้งค่า…</p><button class="btn btn-sm btn-outline-primary" type="button" id="settingsRetry" hidden>ลองโหลดอีกครั้ง</button></div>
        <div class="settings-buttons"><button class="btn btn-outline-secondary" type="button" id="settingsReset" disabled>คืนค่าที่บันทึกไว้</button><button class="btn btn-primary" type="submit" id="settingsSave" disabled><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span>บันทึกการตั้งค่า</span></button></div>
    </footer>
</form>
