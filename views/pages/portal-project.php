<?php page_header('โครงงานของฉัน', 'ดูข้อเสนอ ฉบับร่าง ฉบับสมบูรณ์ บาร์โค้ด ความคิดเห็น และรายละเอียดการอนุมัติ'); ?>
<section class="row g-4">
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header clean-header"><h2>ข้อมูลโครงงาน</h2></div>
            <div class="card-body">
                <form id="studentProjectEditForm">
                    <div class="detail-grid" id="studentProjectInfo"></div>
                    <div class="form-actions mt-3" id="studentProjectEditActions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> บันทึกข้อมูลโครงงาน</button></div>
                </form>
                <p id="studentProjectReadOnlyNote" class="alert alert-info mt-3 mb-0 d-none">เฉพาะหัวหน้ากลุ่มเท่านั้นที่แก้ไขข้อมูลโครงงานได้</p>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header clean-header"><h2>ความคืบหน้า</h2></div>
            <div class="card-body">
                <canvas id="studentProgressChart" height="220"></canvas>
            </div>
        </div>
    </div>
</section>
<section class="card mt-4" id="studentGroupCard">
    <div class="card-header clean-header">
        <h2>รูปแบบการทำโครงงาน <span class="text-muted fs-6">(เดี่ยว หรือกลุ่มสูงสุด 5 คน)</span></h2>
    </div>
    <div class="card-body">
        <div id="studentGroupEmpty">
            <div id="studentGroupInvitations" class="mb-3"></div>
            <div class="project-mode-choice mb-4">
                <div>
                    <span class="project-mode-icon"><i class="fa-solid fa-user"></i></span>
                    <strong>ทำโครงงานคนเดียว</strong>
                    <small>ไม่ต้องตั้งชื่อกลุ่ม คุณเป็นผู้ส่งเอกสารและเลือกคณะกรรมการเอง</small>
                </div>
                <button class="btn btn-outline-primary" type="button" data-action="start-solo-project">เลือกทำคนเดียว</button>
            </div>
            <div class="project-mode-divider"><span>หรือสร้างกลุ่ม</span></div>
            <form id="studentGroupCreateForm" class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="studentGroupName">ชื่อกลุ่มโครงงาน</label>
                    <input class="form-control" id="studentGroupName" name="name" required>
                </div>
                <div class="col-md-4"><button class="btn btn-primary w-100" type="submit">สร้างกลุ่ม</button></div>
            </form>
        </div>
        <div id="studentGroupDetail" class="d-none">
            <h3 id="studentGroupTitle" class="h5"></h3>
            <div class="table-responsive mt-3">
                <table class="table align-middle"><thead><tr><th>รหัสนักศึกษา</th><th>ชื่อ-นามสกุล</th><th>หน้าที่</th><th></th></tr></thead><tbody id="studentGroupMembers"></tbody></table>
            </div>
            <form id="studentGroupAddForm" class="row g-3 align-items-end d-none">
                <input type="hidden" name="action" value="add">
                <div class="col-md-8"><label class="form-label" for="studentGroupCode">รหัสนักศึกษาที่ต้องการเพิ่ม</label><input class="form-control" id="studentGroupCode" name="student_code" required></div>
                <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="submit">เพิ่มสมาชิก</button></div>
            </form>
            <div id="studentGroupManagement" class="mt-3"></div>
            <form id="groupAdvisorInvitationForm" class="row g-3 mt-3 d-none">
                <div class="col-12"><h3 class="h5 mb-0">เลือกคณะกรรมการโครงงาน</h3><p class="text-muted mb-0">เลือกและส่งคำเชิญทีละตำแหน่งได้ เช่น เลือกประธานก่อนได้ โดยไม่ต้องรอเลือกครบทั้ง 3 คน</p></div>
                <div class="col-12 d-none" id="groupAdvisorEligibilityNotice"></div>
                <div class="col-md-4">
                    <label class="form-label" for="groupChairAdvisor">ประธาน</label>
                    <select class="form-select group-advisor-select" id="groupChairAdvisor"></select>
                    <small class="text-muted" id="groupChairStatus"></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="groupViceChairAdvisor">รองประธาน</label>
                    <select class="form-select group-advisor-select" id="groupViceChairAdvisor"></select>
                    <small class="text-muted" id="groupViceChairStatus"></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="groupCommitteeAdvisor">กรรมการ</label>
                    <select class="form-select group-advisor-select" id="groupCommitteeAdvisor"></select>
                    <small class="text-muted" id="groupCommitteeStatus"></small>
                </div>
                <div class="col-12" id="groupAdvisorSubmitActions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-paper-plane"></i> ส่งคำเชิญให้อาจารย์</button></div>
            </form>
            <p id="studentGroupSubmitNote" class="alert alert-info mt-3 mb-0"></p>
        </div>
    </div>
</section>
<section class="card mt-4">
    <div class="card-header clean-header"><h2>ขั้นตอนโครงงาน</h2></div>
    <div class="table-responsive">
        <table class="table align-middle" id="studentStagesTable">
            <thead><tr><th>ขั้นตอน</th><th>สถานะ</th><th>วันที่ส่ง</th><th>วันที่อนุมัติ</th><th>อาจารย์ที่ปรึกษา</th><th></th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</section>
