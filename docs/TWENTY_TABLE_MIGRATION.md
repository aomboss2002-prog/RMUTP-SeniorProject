# ย้ายฐานข้อมูลเป็น 20 ตาราง

โค้ดและ migration นี้ยังไม่ถูกนำไปใช้กับ Production อัตโนมัติ ต้องทำในช่วงปิดรับการเขียนข้อมูล ทั้งหน้าเว็บ Cron และ local worker ใช้ฐานข้อมูลสำเนาทดสอบก่อนเสมอ

## ปลายทางข้อมูล

| ข้อมูล | ที่เก็บใหม่ |
| --- | --- |
| PHP session | `user_sessions.session_data` รวมกับ metadata เดิม; บัญชีที่ยังไม่ล็อกอินใช้ user_type/user_id เป็น NULL |
| ประวัติความก้าวหน้า | `activities` ชนิด `progress_updated` พร้อม project/document, actor, old_value/new_value, event_key และ metadata |
| บันทึกอาจารย์ | `comments` ชนิด `advisor_followup`; เหตุการณ์และรายละเอียดก่อน/หลังแก้ใน `activities` |
| ตรวจชื่อซ้ำ | JSON แยกงานใน `app_state` คีย์ `ai-title:`; คงหมายเลขงาน สถานะ จำนวนครั้งและผลเดิม |
| Risk Score | JSON แยกโครงงานใน `app_state` คีย์ `ai-risk:` |
| ประวัติ Cron | JSON แยกรอบใน `app_state` คีย์ `job-run:`; ไม่มีไฟล์ชั่วคราวบน Vercel |
| ประวัติ migration | `settings` คีย์ `migration:<SHA-256 ของ version>`; ค่า JSON เก็บ version และ applied_at |

คีย์ `sequence:` ใน `app_state` ใช้สร้างหมายเลขและล็อกคิว ไม่สร้างตารางหรือ service ใหม่ ประวัติทั้งหมดถูกเก็บไว้ ไม่มีการตัดทิ้งเพื่อให้เหลือ 20 ตาราง ประวัติบันทึกอาจารย์เดิมใช้เหตุการณ์ `followup_imported` ไม่แต่งประวัติการแก้ไขย้อนหลัง

## ขั้นตอน

1. สำรองฐานข้อมูลทั้งชุด และเก็บโค้ดรุ่นเดิมคู่กัน ตรวจว่าสามารถกู้คืนได้
2. ปิดรับการเขียนข้อมูลจากผู้ใช้ทั้งหมด หยุด Cron และ worker รวมถึง deployment เก่าที่เชื่อมฐานข้อมูลเดียวกัน
3. กำหนด Environment ของ CLI ให้ชี้ฐานข้อมูลเป้าหมาย อย่าใส่รหัสผ่านลง command history หรือ Git สคริปต์ใช้ค่าการเชื่อมต่อเดิมของโปรเจกต์ และบังคับ `DB_AUTO_MIGRATE=false` ใน process นี้
4. ตรวจอย่างเดียว (ไม่แก้ไข):

```powershell
C:\xampp\php\php.exe scripts\migrate-twenty-tables.php
```

5. ยืนยันว่าหยุดการเขียนและสำรองแล้ว จึงคัดลอก/ตรวจข้อมูล โดยยังเก็บตารางเดิม:

```powershell
C:\xampp\php\php.exe scripts\migrate-twenty-tables.php --apply --maintenance-confirmed --backup-confirmed
```

6. ตรวจ `verified_rows` และฐานข้อมูลปลายทาง สคริปต์ตรวจทุกฟิลด์ที่ย้าย รันซ้ำได้ หากข้อมูลปลายทางต่างจากต้นทางจะหยุด ไม่เขียนทับ และไม่ DROP
7. ขณะยังหยุดการเขียน ใช้คำสั่งต่อไปนี้เพื่อตรวจซ้ำแล้ว DROP เฉพาะ 7 ตารางเดิม:

```powershell
C:\xampp\php\php.exe scripts\migrate-twenty-tables.php --apply --maintenance-confirmed --backup-confirmed --drop-legacy
```

8. ต้องได้รายการ 20 ตารางตรงกับ `APPLICATION_TABLES` ใน `app/consolidated-schema.php` แล้วจึง Deploy โค้ดใหม่ เปิดเว็บไซต์และ worker ใหม่ ทดสอบ Login ทุกบทบาท, Proposal/Draft/Complete, บันทึกอาจารย์, AI และ Cron

MySQL DDL ไม่สามารถ rollback เหมือน transaction ได้: การเพิ่มคอลัมน์ทำก่อน transaction และ DROP ทำหลัง copy/verify commit แล้วเท่านั้น การเรียกซ้ำหลัง DROP บางตารางสำเร็จจะตรวจและจัดการเฉพาะตารางที่เหลือ ห้ามใช้ `install.bat` ของโค้ดเก่าเพราะจะสร้างตารางเก่ากลับมา

หากมีตารางอื่นนอกเหนือจากรายการที่รองรับ สคริปต์จะหยุดให้ตรวจเอง ไม่ลบตารางที่ไม่รู้จัก ส่วน SQL migration แยก AI/Tracking รุ่นเดิมจะปฏิเสธการรันเพื่อป้องกันสร้างตารางเก่า

## ทดสอบและย้อนกลับ

`tests/verify-twenty-tables.php` ใช้ MariaDB ทดสอบบน localhost พอร์ต 33319 เท่านั้น และตรวจว่า data directory อยู่ใต้ TEMP/twenty-tables-* ก่อนสร้าง/ลบ schema ทดสอบ ไม่ใช้ฐานข้อมูลจาก `.env`

หากต้องย้อนกลับ ให้หยุดการเขียนแล้วกู้คืนฐานข้อมูลสำรองทั้งชุดพร้อมโค้ดรุ่นเดิม ห้ามสลับเฉพาะโค้ด เพราะรุ่นเดิมต้องใช้ตารางที่ถูกลบแล้ว ข้อมูลที่เกิดหลังย้ายต้องสำรองและวางแผนรวมกลับแยกต่างหาก

ข้อควรติดตาม: การค้นคิว/สรุป AI ใช้ SQL JSON projection ภายใต้ prefix ที่มี primary-key index แต่ไม่มีดัชนีสถานะ/เวลาเฉพาะเหมือนตารางเดิม ควรวัดประสิทธิภาพเมื่อประวัติมีจำนวนมากก่อนใช้งานจริง ไม่ควรลบประวัติโดยไม่มีนโยบายสำรองที่ตกลงกัน
