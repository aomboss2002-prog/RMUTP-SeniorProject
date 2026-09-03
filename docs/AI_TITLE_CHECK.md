# Background AI project-title duplicate check

## Web/serverless mode

Vercel does not keep a CLI process alive, so Production uses request-scoped processing. A title check is completed inline when the title is saved, stale Risk Scores refresh when project details are opened, and `/api/ai-web-worker.php` processes a bounded backlog from the daily Vercel Cron. The endpoint requires `Authorization: Bearer <CRON_SECRET>`.

Set `CRON_SECRET` as a Production Secret and `AI_WEB_PROCESSING_ENABLED=true` in Vercel, then redeploy. Vercel automatically supplies this authorization header to the configured cron invocation. In Vercel `AI_TITLE_ENGINE=auto` selects `local-ngram-v1` immediately because localhost Ollama is not reachable from a serverless function. Local XAMPP continues to support Ollama and the continuous CLI worker.

ระบบจะเพิ่มงานลง `project_title_checks` หลังนักศึกษาสร้างหรือแก้ไขชื่อโครงงาน แล้วตอบกลับหน้าเว็บทันที โดย Worker จะตรวจชื่อในภายหลังและบันทึกผลกลับ MySQL

## การทำงานโดยไม่ใช้ Token

- ค่าเริ่มต้น `AI_TITLE_ENGINE=auto` จะเรียก Ollama ในเครื่องก่อน
- หาก Ollama ไม่พร้อม ระบบใช้ UTF-8 n-gram similarity ภายในโปรเจกต์เป็น fallback
- ไม่มีการส่งชื่อโครงงานไปยังบริการ AI ภายนอกและไม่มี API Token

## เปิดใช้งาน

เมื่อรัน `run.bat` ระบบจะเปิด Worker แบบซ่อนอยู่เบื้องหลังให้อัตโนมัติ หรือเปิดเองได้ด้วย:

```bat
ai-worker.bat
```

ประมวลผลงานที่ค้างหนึ่งรอบแล้วออก:

```bat
ai-worker.bat --once
```

## ใช้ Ollama Embedding

1. ติดตั้ง Ollama บนเครื่องที่รัน Worker
2. ดาวน์โหลดโมเดลหลายภาษา:

```bat
ollama pull bge-m3
```

3. ตรวจ `.env`:

```env
AI_WORKER_ENABLED=true
AI_TITLE_ENGINE=auto
AI_OLLAMA_URL=http://127.0.0.1:11434
AI_OLLAMA_MODEL=bge-m3
AI_TITLE_HIGH_THRESHOLD=0.85
AI_TITLE_REVIEW_THRESHOLD=0.70
```

หากต้องการบังคับให้ใช้ Ollama และให้ Job ล้มเหลวเมื่อ Ollama ไม่พร้อม ให้กำหนด `AI_TITLE_ENGINE=ollama` หากต้องการใช้เฉพาะตัวตรวจในเครื่อง ให้กำหนด `AI_TITLE_ENGINE=local`

## ฐานข้อมูล Production

ฐานข้อมูลใหม่จาก `database/database.sql` มีตารางพร้อมแล้ว สำหรับฐานข้อมูล Railway เดิมที่ปิด `DB_AUTO_MIGRATE` ให้นำเข้า `database/ai-title-check.sql` หนึ่งครั้ง

Vercel ไม่สามารถรักษา PHP Worker ที่ทำงานตลอดเวลาได้ จึงต้องรัน `ai-worker.bat` บนเครื่องที่เปิดอยู่ หรือใช้ Worker/VPS แยกที่เชื่อมต่อ MySQL ฐานเดียวกับเว็บไซต์

## Risk Score งานล่าช้าแบบไม่ใช้กำหนดส่ง

Worker เดียวกันจะประเมิน `project_risk_scores` เป็นระยะโดยไม่ต้องระบุวันส่ง คะแนนมาจากสัญญาณที่ตรวจสอบได้ ได้แก่ จำนวนวันที่ไม่มีกิจกรรม ขั้นตอนที่หยุดนิ่ง เอกสารรอพิจารณา การถูกส่งกลับแก้ไข ความคืบหน้าเทียบค่ากลางของโครงงานอื่น และการมีอาจารย์ที่ปรึกษา

```env
AI_RISK_ENABLED=true
AI_RISK_SCAN_INTERVAL=300
```

## ชุดข้อมูลทดสอบ AI จำนวน 60 โครงงาน

สร้างข้อมูลทดสอบที่แบ่ง Risk Score เป็น `low`, `watch`, `high` และ `critical`
อย่างละ 15 รายการ พร้อมทดสอบชื่อซ้ำกับฐานข้อมูล 59 โครงงานด้วยคำสั่ง:

```bat
C:\xampp\php\php.exe tests\seed-ai-projects.php
```

ข้อมูลชุดนี้ใช้คำนำหน้า `AITEST` และสามารถล้างเฉพาะข้อมูลทดสอบโดยไม่กระทบ
ข้อมูลจริงได้ด้วยคำสั่ง:

```bat
C:\xampp\php\php.exe tests\seed-ai-projects.php --clean
```

## ชุดทดสอบ Workflow นักศึกษาและอาจารย์ 60 บัญชี

ชุดนี้ประกอบด้วยนักศึกษา 54 คนและอาจารย์ 6 คน พร้อมโครงงานที่คละสถานะ
ยังไม่ส่ง รอตรวจ ส่งกลับแก้ไข กำลังทำฉบับร่าง รอตรวจฉบับสมบูรณ์ และเสร็จแล้ว:

```bat
C:\xampp\php\php.exe tests\seed-workflow-data.php
```

บัญชีตัวอย่าง:

- อาจารย์: `demo.advisor01@rmutp.ac.th` / `Demo@2026`
- นักศึกษา: `079990300001-1` / `079990300001-1`

ล้างเฉพาะข้อมูลชุด Workflow:

```bat
C:\xampp\php\php.exe tests\seed-workflow-data.php --clean
```

ระดับคะแนนคือ ต่ำ `0-29`, เฝ้าระวัง `30-59`, สูง `60-79` และวิกฤต `80-100` โดยผลทุกครั้งมี `factors` และคะแนนรายเหตุผล ไม่ใช้ Ollama สร้างตัวเลข จึงสามารถตรวจสอบย้อนหลังได้

สำหรับฐานข้อมูล Production เดิม ให้นำเข้า `database/ai-risk-score.sql` หนึ่งครั้งก่อนเปิด Worker เวอร์ชันนี้
