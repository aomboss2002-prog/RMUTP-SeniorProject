(function ($) {
    'use strict';

    const refreshMs = 12000;
    const advisorStatusRefreshMs = 30000;
    let projectCache = null;
    let uploadPreviewObjectUrl = null;
    let studentMessages = [];
    let studentMessagePage = 1;
    const studentMessagesPerPage = 5;

    function page() {
        return $('body').data('page');
    }

    function currentUser() {
        try {
            return JSON.parse(sessionStorage.getItem('rmutp_user') || '{}');
        } catch (_error) {
            return {};
        }
    }

    function studentId() {
        return currentUser().id || 'STU001';
    }

    function request(url, options = {}) {
        return $.ajax(Object.assign({
            url: App.url(url),
            method: options.method || 'GET',
            dataType: 'json',
            headers: { 'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content') || '' }
        }, options)).fail(function (xhr) {
            App.toast(xhr.responseJSON?.message || 'ไม่สามารถเชื่อมต่อ Student API ได้', 'error');
        });
    }

    function statusClass(status) {
        if (['Approved', 'Completed'].includes(status)) return 'complete';
        if (['Pending', 'Review', 'Resubmitted', 'Draft'].includes(status)) return 'pending';
        if (['Rejected', 'NeedsRevision'].includes(status)) return 'rejected';
        return 'not-started';
    }

    function fileUrl(document) {
        return document ? App.url(`api/file.php?id=${encodeURIComponent(document.id)}`) : '';
    }

    function downloadUrl(document) {
        return document ? App.url(`api/file.php?id=${encodeURIComponent(document.id)}&mode=download`) : '';
    }

    function showInlinePdfPreview(url, filename, isObjectUrl = false) {
        if (uploadPreviewObjectUrl && uploadPreviewObjectUrl !== url) URL.revokeObjectURL(uploadPreviewObjectUrl);
        uploadPreviewObjectUrl = isObjectUrl ? url : null;
        $('#studentPreviewFilename').text(filename || 'ตัวอย่างเอกสาร');
        $('#studentInlinePreviewFrame').attr('src', url);
        $('#studentInlinePreview').removeClass('d-none');
    }

    function hideInlinePdfPreview() {
        if (uploadPreviewObjectUrl) URL.revokeObjectURL(uploadPreviewObjectUrl);
        uploadPreviewObjectUrl = null;
        $('#studentInlinePreviewFrame').attr('src', 'about:blank');
        $('#studentInlinePreview').addClass('d-none');
    }

    function renderTimeline(selector, rows) {
        const titleText = (title, status) => {
            const draft = String(title || '').match(/^Draft Chapter (\d+) (Submitted|Approved)$/);
            if (draft) {
                if (draft[2] === 'Submitted') {
                    if (status === 'NeedsRevision') return `ฉบับร่าง บทที่ ${draft[1]} — ให้กลับไปแก้ไข`;
                    if (status === 'Rejected') return `ฉบับร่าง บทที่ ${draft[1]} — ยังไม่ผ่าน`;
                    return `ส่งฉบับร่าง บทที่ ${draft[1]} แล้ว`;
                }
                return status === 'Completed'
                    ? `อนุมัติฉบับร่าง บทที่ ${draft[1]} แล้ว`
                    : `รออนุมัติฉบับร่าง บทที่ ${draft[1]}`;
            }
            if (title === 'Proposal Submitted' && status === 'NeedsRevision') return 'ข้อเสนอโครงงาน — ให้กลับไปแก้ไข';
            if (title === 'Proposal Submitted' && status === 'Rejected') return 'ข้อเสนอโครงงาน — ยังไม่ผ่าน';
            if (title === 'Complete Submitted' && status === 'NeedsRevision') return 'ฉบับสมบูรณ์ — ให้กลับไปแก้ไข';
            if (title === 'Complete Submitted' && status === 'Rejected') return 'ฉบับสมบูรณ์ — ยังไม่ผ่าน';
            return ({
                'Proposal Submitted': 'ส่งข้อเสนอโครงงานแล้ว',
                'Proposal Approved': status === 'Completed' ? 'อนุมัติข้อเสนอโครงงานแล้ว' : 'รออนุมัติข้อเสนอโครงงาน',
                'Complete Submitted': 'ส่งฉบับสมบูรณ์แล้ว',
                'Complete Approved': status === 'Completed' ? 'อนุมัติฉบับสมบูรณ์แล้ว' : 'รออนุมัติฉบับสมบูรณ์',
                'Barcode Generated': 'สร้างบาร์โค้ดแล้ว'
            })[title] || title || '-';
        };
        const dateText = (value) => {
            if (!value) return 'ยังไม่มีวันที่ดำเนินการ';
            const parsed = new Date(String(value).replace(' ', 'T'));
            return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString('th-TH', {
                day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
            });
        };
        $(selector).html(rows.map((row, index) => `
            <div class="timeline-item${index === 0 ? ' is-latest' : ''}" data-step="${String(rows.length - index).padStart(2, '0')}">
                <div class="timeline-item-content">
                    <div class="timeline-item-heading">
                        <strong>${App.escapeHtml(titleText(row.title, row.status))}</strong>
                        ${index === 0 ? '<span class="timeline-latest-label">ล่าสุด</span>' : ''}
                    </div>
                    <span class="timeline-item-date"><i class="fa-regular fa-clock"></i> ${App.escapeHtml(dateText(row.date))}</span>
                </div>
                <div class="timeline-item-meta">
                    ${App.badge(row.status)}
                </div>
            </div>
        `).join('') || '<p class="text-muted mb-0">ยังไม่มีประวัติไทม์ไลน์</p>');
    }

    function loadProject(callback) {
        return request('api/student/project/').done(function (response) {
            projectCache = response.data;
            if (callback) callback(projectCache);
        });
    }

    function loadGroup() {
        return request('api/student/group/').done(function (response) {
            const data = response.data;
            const group = data.group;
            $('#studentGroupEmpty').toggleClass('d-none', !!group);
            $('#studentGroupDetail').toggleClass('d-none', !group);
            $('#studentGroupInvitations').html((data.received_invitations || []).map((invitation) => `
                <div class="alert alert-info d-flex justify-content-between align-items-center gap-3">
                    <span>คำเชิญเข้ากลุ่ม <strong>${App.escapeHtml(invitation.group_name)}</strong></span>
                    <span><button class="btn btn-sm btn-success" data-action="respond-group-invitation" data-decision="accept" data-id="${invitation.id}">เข้าร่วมกลุ่ม</button> <button class="btn btn-sm btn-outline-danger" data-action="respond-group-invitation" data-decision="reject" data-id="${invitation.id}">ปฏิเสธ</button></span>
                </div>`).join(''));
            if (!group) return;

            const isSolo = group.mode === 'solo' && data.members.length === 1;
            $('#studentGroupTitle').text(isSolo
                ? 'โครงงานเดี่ยว (1 คน)'
                : `${group.name} (${data.members.length}/${data.max_members} คน)`);
            $('#studentGroupMembers').html(data.members.map((member) => `
                <tr>
                    <td>${App.escapeHtml(member.code)}</td>
                    <td>${App.escapeHtml(`${member.first_name || ''} ${member.last_name || ''}`.trim())}</td>
                    <td>${isSolo ? 'ผู้จัดทำโครงงาน' : (member.id === group.leader_id ? 'หัวหน้ากลุ่ม' : 'สมาชิก')}</td>
                    <td class="text-end">${data.is_leader && member.id !== group.leader_id
                        ? `<button class="btn btn-sm btn-outline-danger" data-action="remove-group-member" data-id="${App.escapeHtml(member.id)}">นำออก</button>`
                        : ''}</td>
                </tr>`).join(''));
            $('#studentGroupAddForm').toggleClass(
                'd-none',
                isSolo || !data.is_leader || data.members.length >= data.max_members
            );
            const otherMembers = data.members.filter((member) => member.id !== group.leader_id);
            $('#studentGroupManagement').html(data.is_leader
                ? (isSolo
                    ? '<div class="alert alert-light border mb-0"><i class="fa-solid fa-lock me-2"></i>โครงงานเดี่ยวจำกัดผู้จัดทำ 1 คน ไม่สามารถเพิ่มสมาชิกได้</div>'
                    : (otherMembers.length ? `
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8"><label class="form-label">โอนสิทธิ์หัวหน้ากลุ่ม</label><select class="form-select" id="studentNewGroupLeader">${otherMembers.map((member) => `<option value="${App.escapeHtml(member.id)}">${App.escapeHtml(`${member.code} - ${member.first_name || ''} ${member.last_name || ''}`.trim())}</option>`).join('')}</select></div>
                        <div class="col-md-4"><button class="btn btn-outline-warning w-100" data-action="transfer-group-leader">โอนหัวหน้ากลุ่ม</button></div>
                    </div>` : '<button class="btn btn-outline-danger" data-action="disband-student-group">ยุบกลุ่ม</button>'))
                : '<button class="btn btn-outline-danger" data-action="leave-student-group">ออกจากกลุ่ม</button>');
            $('#groupAdvisorInvitationForm').removeClass('d-none');
            $('#groupAdvisorSubmitActions').toggleClass('d-none', !data.is_leader);
            loadGroupAdvisorForm(data.is_leader);
            $('#studentGroupSubmitNote').text(data.is_leader
                ? (isSolo
                    ? 'คุณทำโครงงานคนเดียวและเป็นผู้เลือกคณะกรรมการ รวมถึงส่ง Proposal, Draft และ Complete'
                    : 'คุณเป็นหัวหน้ากลุ่มและเป็นผู้ส่งเอกสาร Proposal, Draft และ Complete ให้กลุ่ม')
                : 'หัวหน้ากลุ่มเป็นผู้ส่งเอกสาร สมาชิกทุกคนสามารถดูไฟล์และสถานะร่วมกันได้');
        });
    }

    function syncUniqueAdvisorOptions(selector) {
        const selects = $(selector).toArray();
        const selectedByField = new Map(selects.map((select) => [select.id, String(select.value || '')]));
        selects.forEach((select) => {
            const ownValue = selectedByField.get(select.id);
            const selectedElsewhere = new Set(
                selects
                    .filter((other) => other.id !== select.id)
                    .map((other) => selectedByField.get(other.id))
                    .filter(Boolean)
            );
            $(select).find('option').each(function () {
                const value = String(this.value || '');
                this.disabled = value !== '' && value !== ownValue && selectedElsewhere.has(value);
            });
        });
    }

    $(document).on('change', '.group-advisor-select', function () {
        syncUniqueAdvisorOptions('.group-advisor-select');
    });

    $(document).on('change', '.advisor-role-select', function () {
        syncUniqueAdvisorOptions('.advisor-role-select');
    });

    function loadGroupAdvisorForm(isLeader = false) {
        request('api/student/profile/').done(function (response) {
            const advisors = response.data.advisors || [];
            const roles = response.data.advisor_roles || {};
            const statuses = response.data.advisor_invitation_statuses || {};
            const student = response.data.student || {};
            const statusLabels = { Pending: 'รอดำเนินการ', Accepted: 'ตอบรับแล้ว', Rejected: 'ปฏิเสธแล้ว' };
            const fields = {
                chair: ['#groupChairAdvisor', '#groupChairStatus'],
                vice_chair: ['#groupViceChairAdvisor', '#groupViceChairStatus'],
                committee: ['#groupCommitteeAdvisor', '#groupCommitteeStatus']
            };
            Object.entries(fields).forEach(([role, selectors]) => {
                const select = $(selectors[0]).empty().append('<option value="">เลือกอาจารย์</option>');
                advisors.forEach((advisor) => select.append(
                    $('<option></option>').val(advisor.id).text(`${advisor.name} - ${advisor.department || '-'}`)
                ));
                select.val(roles[role] || '');
                select.prop('disabled', !isLeader || statuses[role] === 'Accepted');
                $(selectors[1]).text(statuses[role] ? `สถานะคำเชิญ: ${statusLabels[statuses[role]] || statuses[role]}` : 'ยังไม่ได้ส่งคำเชิญ');
            });
            syncUniqueAdvisorOptions('.group-advisor-select');
            const hasEligibleAdvisors = advisors.length > 0;
            $('#groupAdvisorEligibilityNotice')
                .toggleClass('d-none', hasEligibleAdvisors)
                .html(hasEligibleAdvisors ? '' : `
                    <div class="alert alert-warning mb-0">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>
                        ไม่พบอาจารย์ที่คณะและสาขาตรงกับนักศึกษา
                        (${App.escapeHtml(student.faculty || '-')} / ${App.escapeHtml(student.major || '-')})
                        กรุณาให้ผู้ดูแลตรวจข้อมูลคณะและสาขาของอาจารย์
                    </div>
                `);
            $('#groupAdvisorSubmitActions button[type="submit"]').prop('disabled', !isLeader || !hasEligibleAdvisors);
        });
    }

    function refreshGroupAdvisorStatusesOnly(isLeader = false) {
        request('api/student/profile/').done(function (response) {
            const statuses = response.data.advisor_invitation_statuses || {};
            const statusLabels = { Pending: 'รอดำเนินการ', Accepted: 'ตอบรับแล้ว', Rejected: 'ปฏิเสธแล้ว' };
            const fields = {
                chair: ['#groupChairAdvisor', '#groupChairStatus'],
                vice_chair: ['#groupViceChairAdvisor', '#groupViceChairStatus'],
                committee: ['#groupCommitteeAdvisor', '#groupCommitteeStatus']
            };
            Object.entries(fields).forEach(([role, selectors]) => {
                const status = statuses[role] || '';
                $(selectors[1]).text(status ? `สถานะคำเชิญ: ${statusLabels[status] || status}` : 'ยังไม่ได้ส่งคำเชิญ');
                $(selectors[0]).prop('disabled', !isLeader || status === 'Accepted');
            });
            syncUniqueAdvisorOptions('.group-advisor-select');
        });
    }

    function loadDashboard() {
        $.when(loadProject(), request('api/student/timeline/'), request('api/student/notifications/')).done(function (projectResponse, timelineResponse, notificationResponse) {
            const data = projectResponse[0].data;
            const student = data.student;
            const project = data.project;
            $('#portalStudentPhoto').attr('src', App.url(`api/profile-photo.php?id=${encodeURIComponent(student.id)}`));
            $('#portalStudentName').text(`${student.first_name} ${student.last_name}`);
            $('#portalStudentMeta').text(`${student.code} · ${student.major}`);
            $('#portalProjectTitle').text(project.title || 'ยังไม่มีโครงงาน');
            $('#portalAdvisorText').text(`อาจารย์ที่ปรึกษา: ${data.advisor.name || '-'}`);
            $('#portalProgressText').text(`${data.progress}%`);
            $('#portalProgressBar').css('width', `${data.progress}%`).text(`${data.progress}%`);
            Object.keys(data.stages).forEach((key) => $(`[data-stage-status="${key}"]`).text(App.label(data.stages[key].status)));
            renderTimeline('#portalTimeline', timelineResponse[0].data);
            $('#portalAnnouncement').text(notificationResponse[0].announcement || '-');
            $('#portalNotifications').html(notificationResponse[0].data.slice(0, 5).map((row) => `
                <a class="list-group-item" href="${App.url('student/notifications.php')}">
                    <strong>${App.escapeHtml(row.title)}</strong>
                    <span class="d-block text-muted">${App.escapeHtml(row.message)}</span>
                </a>
            `).join('') || '<div class="list-group-item text-muted">ยังไม่มีการแจ้งเตือน</div>');
            updateCounter(notificationResponse[0].unread);
        });
    }

    function loadProfile() {
        request('api/student/profile/').done(function (response) {
            const student = response.data.student;
            const advisor = response.data.advisor;
            const advisors = response.data.advisors || [];
            const name = `${student.first_name} ${student.last_name}`;
            $('.profile-chip #portalNavbarName').text(name);
            $('#studentProfilePhoto').attr('src', App.url(`api/profile-photo.php?id=${encodeURIComponent(student.id)}`));
            $('#studentProfileName').text(name);
            $('#studentProfileCode').text(student.code);
            $('#spCode').val(student.code);
            $('#spName').val(name);
            $('#spDepartment').val(student.major);
            const advisorSelect = $('#spAdvisor').empty();
            advisorSelect.append('<option value="">เลือกอาจารย์ที่ปรึกษา</option>');
            advisors.forEach((item) => advisorSelect.append(
                $('<option></option>').val(item.id).text(`${item.name} - ${item.department || '-'}`)
            ));
            advisorSelect.val(advisor.id || student.advisor_id || '');
            advisorSelect.prop('disabled', !!response.data.group && !response.data.is_group_leader);
            const advisorRoles = response.data.advisor_roles || {};
            const roleSelects = {
                chair: $('#spChairAdvisor'),
                vice_chair: $('#spViceChairAdvisor'),
                committee: $('#spCommitteeAdvisor')
            };
            Object.entries(roleSelects).forEach(([role, select]) => {
                select.empty().append('<option value="">เลือกอาจารย์</option>');
                advisors.forEach((item) => select.append(
                    $('<option></option>').val(item.id).text(`${item.name} - ${item.department || '-'}`)
                ));
                select.val(advisorRoles[role] || (role === 'chair' ? advisor.id || student.advisor_id || '' : ''));
                select.prop('disabled', !!response.data.group && !response.data.is_group_leader);
            });
            syncUniqueAdvisorOptions('.advisor-role-select');
            $('#spEmail').val(student.email);
            $('#spPhone').val(student.phone);
            $('#spPhoto').val(student.photo || '');
        });

        $('#studentProfileForm').off('submit').on('submit', function (event) {
            event.preventDefault();
            const selectedAdvisorIds = $('.advisor-role-select:not(:disabled)').map(function () { return this.value; }).get();
            if (selectedAdvisorIds.length && (selectedAdvisorIds.some((id) => !id) || new Set(selectedAdvisorIds).size !== 3)) {
                App.toast('กรุณาเลือกประธาน รองประธาน และกรรมการเป็นอาจารย์คนละคน', 'error');
                return;
            }
            const formData = new FormData(this);
            request('api/student/profile/', {
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false
            }).done(function (response) {
                App.toast(response.message);
                $('#studentProfilePhoto').attr('src', App.url(`api/profile-photo.php?id=${encodeURIComponent(studentId())}&v=${Date.now()}`));
                loadProfile();
            });
        });
    }

    function loadProjectPage() {
        loadGroup();
        loadProject(function (data) {
            const project = data.project;
            const canEditProject = !data.group || data.is_group_leader;
            $('#studentProjectEditForm').removeClass('d-none');
            $('#studentProjectEditActions').toggleClass('d-none', !canEditProject);
            $('#studentProjectReadOnlyNote').toggleClass('d-none', canEditProject);
            $('#studentProjectInfo').html([
                ['รหัสโครงงาน', project.code],
                ['ชื่อโครงงาน', project.title],
                ['ประเภท', project.category],
                ['อาจารย์ที่ปรึกษา', data.advisor.name],
                ['สถานะปัจจุบัน', App.label(project.status)],
                ['ความคืบหน้า', `${data.progress}%`]
            ].map((item) => `<div class="detail-item"><span>${item[0]}</span><strong>${App.escapeHtml(item[1] || '-')}</strong></div>`).join(''));

            if (canEditProject) {
                const detailItems = $('#studentProjectInfo .detail-item');
                detailItems.eq(1).html('<span>ชื่อโครงงาน</span><input class="form-control mt-2" id="studentProjectTitle" name="title" maxlength="255" required>');
                $('#studentProjectTitle').val(project.title || '');
            }
            $('#studentProjectInfo .detail-item').eq(2).remove();

            $('#studentStagesTable tbody').html(['proposal', 'draft', 'complete', 'barcode'].map((key) => {
                const stage = data.stages[key];
                const href = key === 'barcode' ? 'portal-barcode' : `portal-${key}`;
                const comments = stage.comments || [];
                const latestComment = comments.length ? comments[comments.length - 1] : null;
                const showDecision = ['NeedsRevision', 'Rejected'].includes(stage.status);
                const decisionDetail = showDecision
                    ? `<div class="stage-decision-detail">
                        <strong>${stage.status === 'NeedsRevision' ? 'ให้กลับไปแก้ไข' : 'ยังไม่ผ่าน'}</strong>
                        ${latestComment
                            ? `<span>${App.escapeHtml(latestComment.message || '-')}</span>
                               <small>${App.escapeHtml(latestComment.author || 'อาจารย์')}${latestComment.created_at ? ` · ${App.escapeHtml(latestComment.created_at)}` : ''}</small>`
                            : '<span>อาจารย์ไม่ได้ระบุรายละเอียดเพิ่มเติม</span>'}
                    </div>`
                    : '';
                return `<tr>
                    <td>${App.escapeHtml(App.label(key))}</td>
                    <td>${App.badge(stage.status)}${decisionDetail}</td>
                    <td>${App.escapeHtml(stage.submit_date || '-')}</td>
                    <td>${App.escapeHtml(stage.approved_date || '-')}</td>
                    <td>${App.escapeHtml(stage.advisor || data.advisor.name || '-')}</td>
                    <td class="text-end">
                        <div class="row-actions single">
                            <a class="row-action view" href="${App.url(`student/${href.replace('portal-', '')}.php`)}" title="ดูขั้นตอน" aria-label="ดูขั้นตอน ${App.escapeHtml(App.label(key))}">
                                <i class="fa-regular fa-eye"></i><span>ดูขั้นตอน</span>
                            </a>
                        </div>
                    </td>
                </tr>`;
            }).join(''));

            renderProgressChart(data.progress);
        });
    }

    function renderProgressChart(progress) {
        const ctx = document.getElementById('studentProgressChart');
        if (!ctx || !window.Chart) return;
        if (App.state.charts.studentProgressChart) App.state.charts.studentProgressChart.destroy();
        App.state.charts.studentProgressChart = new Chart(ctx, {
            type: 'doughnut',
            data: { labels: ['เสร็จแล้ว', 'คงเหลือ'], datasets: [{ data: [progress, Math.max(0, 100 - progress)], backgroundColor: ['#0B3C8C', '#E2E8F0'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }

    function loadStage() {
        const stage = $('#portalStage').val();
        loadProject(function (data) {
            const hasChair = !!(data.group?.advisor_roles?.chair || data.student?.advisor_roles?.chair);
            const proposalApproved = ['Approved', 'Completed'].includes(data.stages?.proposal?.status);
            const draftUnlocked = stage !== 'draft' || proposalApproved;
            const allDraftChaptersApproved = Number(data.stages?.draft?.approved_count || 0) === 5;
            const completeUnlocked = stage !== 'complete' || allDraftChaptersApproved;
            const canSubmit = (!data.group || data.is_group_leader)
                && hasChair
                && draftUnlocked
                && completeUnlocked;
            $('#studentUploadForm').toggle(canSubmit);
            $('#studentUploadForm').next('.group-submit-notice').remove();
            if (!canSubmit) {
                const notice = !hasChair
                    ? 'ต้องมีประธานที่ตอบรับคำเชิญแล้ว จึงจะส่ง Proposal, Draft หรือ Complete ได้'
                    : !draftUnlocked
                        ? 'ยังส่งฉบับร่างไม่ได้ — ข้อเสนอโครงงานต้องได้รับการอนุมัติก่อน'
                    : !completeUnlocked
                        ? `ยังส่ง Complete ไม่ได้ — Draft ต้องอนุมัติครบทั้ง 5 บท (ขณะนี้ ${Number(data.stages?.draft?.approved_count || 0)}/5 บท)`
                        : 'หัวหน้ากลุ่มเป็นผู้ส่งเอกสาร สมาชิกสามารถดูไฟล์และสถานะร่วมกันได้';
                $('#studentUploadForm').after(`<div class="alert alert-info group-submit-notice"><i class="fa-solid fa-circle-info me-2"></i>${notice}</div>`);
            }
            const stageData = data.stages[stage];
            if (stage === 'draft') {
                const draftDocuments = stageData.documents || [];
                let approvedInOrder = 0;
                for (let chapter = 1; chapter <= 5; chapter++) {
                    const row = draftDocuments.find((item) => Number(item.chapter) === chapter);
                    if (!row || !['Approved', 'Completed'].includes(row.status)) break;
                    approvedInOrder = chapter;
                }
                const availableChapter = Math.min(approvedInOrder + 1, 5);
                const $chapter = $('#studentDraftChapter');
                $chapter.find('option').each(function () {
                    const number = Number(this.value);
                    const row = draftDocuments.find((item) => Number(item.chapter) === number);
                    const status = row ? App.label(row.status) : 'ยังไม่ส่ง';
                    $(this).prop('disabled', number > availableChapter).text(`บทที่ ${number} — ${status}`);
                });
                if (!$chapter.data('sequence-ready')) {
                    $chapter.val(availableChapter).data('sequence-ready', true);
                }
            }
            const selectedChapter = Number($('#studentDraftChapter').val() || 1);
            const document = stage === 'draft'
                ? (stageData.documents || []).find((row) => Number(row.chapter) === selectedChapter) || null
                : stageData.document;
            if (document && !uploadPreviewObjectUrl) {
                showInlinePdfPreview(fileUrl(document), document.filename);
            } else if (!document && !uploadPreviewObjectUrl) {
                hideInlinePdfPreview();
            }
            $('#studentStageInfo').html([
                ...(stage === 'draft' ? [
                    ['บทที่กำลังดู', `บทที่ ${selectedChapter}`],
                    ['ส่งแล้ว', `${stageData.uploaded_count || 0}/5 บท`],
                    ['อนุมัติแล้ว', `${stageData.approved_count || 0}/5 บท`]
                ] : []),
                ['สถานะ', App.label(document?.status || stageData.status)],
                ['วันที่ส่ง', stage === 'draft' ? (document?.uploaded_at || '-') : (stageData.submit_date || '-')],
                ['วันที่อนุมัติ', stage === 'draft'
                    ? (document && ['Approved', 'Completed'].includes(document.status) ? (document.approved_at || '-') : '-')
                    : (stageData.approved_date || '-')],
                ['เจ้าหน้าที่', stageData.officer],
                ['อาจารย์ที่ปรึกษา', stageData.advisor],
                ['ไฟล์', document ? document.filename : 'ยังไม่ได้ส่งไฟล์']
            ].map((item) => `<div class="detail-item"><span>${item[0]}</span><strong>${App.escapeHtml(item[1] || '-')}</strong></div>`).join(''));
            $('#studentStageComments').html(stageData.comments.map((row) => `
                <div class="list-group-item"><strong>${App.escapeHtml(row.author)}</strong><span class="d-block">${App.escapeHtml(row.message)}</span><small class="text-muted">${App.escapeHtml(row.created_at)}</small></div>
            `).join('') || '<div class="list-group-item text-muted">ยังไม่มีความคิดเห็น</div>');
            const actions = document ? `
                <button class="btn btn-outline-primary" data-action="preview-file" data-url="${App.escapeHtml(fileUrl(document))}"><i class="fa-solid fa-eye"></i><span>ดูตัวอย่าง</span></button>
                <a class="btn btn-outline-primary" href="${App.escapeHtml(downloadUrl(document))}" download><i class="fa-solid fa-download"></i><span>ดาวน์โหลด</span></a>
                <button class="btn btn-outline-primary" data-action="student-print-doc" data-url="${App.escapeHtml(fileUrl(document))}"><i class="fa-solid fa-print"></i><span>พิมพ์</span></button>
                ${['Approved', 'Completed'].includes(document.status) ? '' : '<button class="btn btn-outline-danger" data-action="student-delete-stage"><i class="fa-solid fa-trash"></i><span>ลบ</span></button>'}
            ` : '<span class="text-muted">ยังไม่ได้ส่งเอกสาร</span>';
            $('#studentStageActions').html(actions);
            if (stage === 'draft') {
                const selectedApproved = document && ['Approved', 'Completed'].includes(document.status);
                $('#studentUploadForm button[type="submit"]').prop('disabled', selectedApproved);
            }
        });
    }

    function initUpload() {
        const $file = $('#studentUploadFile');
        const $drop = $('#studentDropZone');
        $drop.on('dragover', function (event) {
            event.preventDefault();
            $drop.addClass('drag-over');
        }).on('dragleave drop', function (event) {
            event.preventDefault();
            $drop.removeClass('drag-over');
            const files = event.originalEvent.dataTransfer?.files;
            if (files && files.length) {
                $file[0].files = files;
                $file.trigger('change');
            }
        });

        $file.on('change', function () {
            const file = this.files[0];
            if (!file) return;
            if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
                this.value = '';
                hideInlinePdfPreview();
                return App.toast('เลือกได้เฉพาะไฟล์ PDF เท่านั้น', 'error');
            }
            showInlinePdfPreview(URL.createObjectURL(file), file.name, true);
            $drop.find('strong').text(file.name);
            $drop.find('span').text(`${(file.size / 1024 / 1024).toFixed(2)} MB · พร้อมอัปโหลด`);
        });

        $('#studentClosePreview').on('click', hideInlinePdfPreview);
        $('#studentDraftChapter').on('change', function () {
            hideInlinePdfPreview();
            loadStage();
        });

        $('[data-action="student-preview-selected"]').on('click', function () {
            const file = $file[0].files[0];
            if (!file) return App.toast('กรุณาเลือกไฟล์ PDF ก่อน', 'info');
            $('#pdfPreviewFrame').attr('src', URL.createObjectURL(file));
            bootstrap.Modal.getOrCreateInstance(document.getElementById('filePreviewModal')).show();
        });

        $('#studentUploadForm').on('submit', function (event) {
            event.preventDefault();
            const stage = $('#portalStage').val();
            const file = $file[0].files[0];
            if (!file) return App.toast('กรุณาเลือกไฟล์ PDF ก่อน', 'info');
            if (file.type !== 'application/pdf') return App.toast('อัปโหลดได้เฉพาะไฟล์ PDF เท่านั้น', 'error');
            if (file.size > 20 * 1024 * 1024) return App.toast('ขนาดไฟล์สูงสุด 20 MB', 'error');
            const formData = new FormData(this);
            $.ajax({
                url: App.url(`api/student/upload/${stage}/`),
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                headers: {
                    'X-Student-Id': studentId(),
                    'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content') || ''
                },
                xhr: function () {
                    const xhr = $.ajaxSettings.xhr();
                    xhr.upload.onprogress = function (event) {
                        if (event.lengthComputable) {
                            const percent = Math.round((event.loaded / event.total) * 100);
                            $('#studentUploadProgress').css('width', percent + '%').text(percent + '%');
                        }
                    };
                    return xhr;
                }
            }).done(function (response) {
                App.toast(response.message);
                $('#studentUploadProgress').css('width', '0%').text('0%');
                if (uploadPreviewObjectUrl) URL.revokeObjectURL(uploadPreviewObjectUrl);
                uploadPreviewObjectUrl = null;
                $file.val('');
                loadStage();
            }).fail(function (xhr) {
                $('#studentUploadProgress').css('width', '0%').text('0%');
                const message = xhr.responseJSON?.message || 'อัปโหลดไม่สำเร็จ';
                App.toast(message, 'error');
                if (xhr.status === 403 && /token/i.test(message)) {
                    setTimeout(() => window.location.reload(), 1400);
                }
            });
        });
    }

    function loadBarcode() {
        loadProject(function (data) {
            const barcode = data.stages?.barcode || {};
            const available = barcode.available === true;
            $('#studentBarcodeLocked').toggleClass('d-none', available);
            $('#studentBarcodeCanvas, #studentBarcodeLabel').toggleClass('d-none', !available);
            $('[data-action="student-print-barcode"], [data-action="student-download-barcode"]')
                .prop('disabled', !available)
                .toggleClass('disabled', !available);
            if (!available) {
                $('#studentBarcodeCanvas').empty();
                $('#studentBarcodeLabel').text('');
                return;
            }
            const code = barcode.code;
            renderBarcode('#studentBarcodeCanvas', '#studentBarcodeLabel', code);
        });
        $('[data-action="student-print-barcode"]').on('click', () => window.print());
        $('[data-action="student-download-barcode"]').on('click', () => downloadBarcode($('#studentBarcodeLabel').text()));
    }

    function renderBarcode(canvasSelector, labelSelector, value) {
        const bars = value.split('').map((char) => `<span class="barcode-bar" style="width:${(char.charCodeAt(0) % 4) + 2}px"></span>`).join('');
        $(canvasSelector).html(bars);
        $(labelSelector).text(value);
    }

    function downloadBarcode(label) {
        const canvas = document.createElement('canvas');
        canvas.width = 720;
        canvas.height = 260;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, 720, 260);
        let x = 70;
        label.split('').forEach((char) => {
            const width = (char.charCodeAt(0) % 4) + 4;
            ctx.fillStyle = '#111827';
            ctx.fillRect(x, 44, width, 140);
            x += width + 4;
        });
        ctx.fillStyle = '#0B3C8C';
        ctx.font = '24px Segoe UI';
        ctx.fillText(label, 70, 225);
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = label + '.png';
        link.click();
    }

    function loadTimeline() {
        request('api/student/timeline/').done((response) => renderTimeline('#studentFullTimeline', response.data));
    }

    function loadNotifications() {
        request('api/student/notifications/').done(function (response) {
            updateCounter(response.unread);
            $('#studentNotificationsTable tbody').html(response.data.map((row) => {
                const decision = row.decision_status || '';
                const decisionDetail = ['NeedsRevision', 'Rejected'].includes(decision)
                    ? `<div class="notification-decision ${decision === 'Rejected' ? 'is-rejected' : ''}">
                        <strong>${App.label(decision)}</strong>
                        <span>${App.escapeHtml(row.detail || 'อาจารย์ไม่ได้ระบุรายละเอียดเพิ่มเติม')}</span>
                        <small>${App.escapeHtml(row.advisor_name || '')}</small>
                    </div>`
                    : decision
                        ? `<div class="notification-decision is-approved"><strong>${App.label(decision)}</strong></div>`
                        : '';
                return `<tr>
                    <td><strong>${App.escapeHtml(row.group_name || 'ส่วนตัว')}</strong></td>
                    <td>${App.escapeHtml(row.title)}</td>
                    <td><span>${App.escapeHtml(row.message)}</span>${decisionDetail}</td>
                    <td>${App.escapeHtml(App.label(row.type))}</td>
                    <td><span class="notification-read-state ${row.read ? 'is-read' : 'is-unread'}">${row.read ? 'อ่านแล้ว' : 'ยังไม่ได้อ่าน'}</span></td>
                    <td>${App.escapeHtml(row.created_at)}</td>
                </tr>`;
            }).join(''));
            App.enhanceTable('#studentNotificationsTable');
        });
    }

    function updateCounter(count) {
        $('#portalNotificationCounter').text(count || 0).toggle((count || 0) > 0);
    }

    function loadNavbarProfile() {
        request('api/student/profile/').done(function (response) {
            const student = response.data.student || {};
            const name = `${student.first_name || ''} ${student.last_name || ''}`.trim() || student.name || 'นักศึกษา';
            $('.profile-chip #portalNavbarName').text(name);
        });
    }

    function loadDocuments() {
        loadProject(function (data) {
            const docs = [
                data.stages.proposal.document,
                ...(data.stages.draft.documents || []),
                data.stages.complete.document
            ].filter(Boolean).sort((a, b) => String(b.uploaded_at || '').localeCompare(String(a.uploaded_at || '')));
            $('#studentDocumentsTable tbody').html(docs.map((document) => `
                <tr><td>${App.escapeHtml(document.title)}</td><td>${App.escapeHtml(App.label(document.type))}</td><td>${App.badge(document.status)}</td><td>${App.escapeHtml(document.uploaded_at)}</td>
                <td class="text-end">
                    <div class="row-actions document-actions" role="group" aria-label="จัดการเอกสาร">
                        <button class="row-action view" data-action="preview-file" data-url="${App.escapeHtml(fileUrl(document))}" title="ดูตัวอย่าง" aria-label="ดูตัวอย่าง"><i class="fa-solid fa-eye"></i></button>
                        <a class="row-action edit" href="${App.escapeHtml(downloadUrl(document))}" download title="ดาวน์โหลด" aria-label="ดาวน์โหลด"><i class="fa-solid fa-download"></i></a>
                        <button class="row-action print" data-action="student-print-doc" data-url="${App.escapeHtml(fileUrl(document))}" title="พิมพ์" aria-label="พิมพ์"><i class="fa-solid fa-print"></i></button>
                    </div>
                </td></tr>
            `).join(''));
            App.enhanceTable('#studentDocumentsTable');
        });
    }

    function renderStudentMessages() {
        const totalPages = Math.max(1, Math.ceil(studentMessages.length / studentMessagesPerPage));
        studentMessagePage = Math.min(Math.max(1, studentMessagePage), totalPages);
        const start = (studentMessagePage - 1) * studentMessagesPerPage;
        const rows = studentMessages.slice(start, start + studentMessagesPerPage);
        $('#studentMessagesList').html(rows.map((row) => `
            <div class="list-group-item message-list-item">
                <strong class="d-block">${App.escapeHtml(row.subject)}</strong>
                <span class="d-block message-preview">${App.escapeHtml(row.message)}</span>
                <small class="text-muted">${App.escapeHtml(row.sender)} → ${App.escapeHtml(row.receiver)} · ${App.escapeHtml(row.created_at)}</small>
            </div>
        `).join('') || '<div class="list-group-item text-muted">ยังไม่มีข้อความ</div>');
        $('#studentMessagePageInfo').text(`หน้า ${studentMessagePage} / ${totalPages}`);
        $('[data-action="student-message-page"][data-direction="previous"]').prop('disabled', studentMessagePage <= 1);
        $('[data-action="student-message-page"][data-direction="next"]').prop('disabled', studentMessagePage >= totalPages);
        $('#studentMessagesPagination').toggle(studentMessages.length > studentMessagesPerPage);
    }

    function loadMessages() {
        request('api/student/messages/').done(function (response) {
            const messages = response.data.messages || [];
            const recipients = response.data.recipients || [];
            const selectedRecipient = $('#studentMessageRecipient').val();
            $('#studentMessageRecipient').html('<option value="">เลือกคณะกรรมการ</option>' + recipients.map((row) => `<option value="${App.escapeHtml(row.id)}">${App.escapeHtml(row.role_label)} — ${App.escapeHtml(row.name)}</option>`).join(''));
            if (selectedRecipient) $('#studentMessageRecipient').val(selectedRecipient);
            $('#studentMessageForm button[type="submit"]').prop('disabled', !recipients.length);
            studentMessages = messages;
            renderStudentMessages();
        });
    }

    function loadStatus() {
        loadProject(function (data) {
            const cards = [
                ['proposal', 'ข้อเสนอโครงงาน', 'fa-file-signature'],
                ['draft', 'ฉบับร่าง', 'fa-file-lines'],
                ['complete', 'ฉบับสมบูรณ์', 'fa-circle-check'],
                ['barcode', 'บาร์โค้ด', 'fa-barcode']
            ];
            $('#studentStatusGrid').html(cards.map(([key, label, icon]) => {
                const stage = data.stages[key];
                const status = stage.status;
                const comments = stage.comments || [];
                const latestComment = comments.length ? comments[comments.length - 1] : null;
                const decisionDetail = ['NeedsRevision', 'Rejected'].includes(status) && latestComment
                    ? `<div class="status-card-feedback">
                        <span><i class="fa-solid fa-comment-dots"></i> รายละเอียดจากอาจารย์</span>
                        <p>${App.escapeHtml(latestComment.message || '-')}</p>
                        <small>${App.escapeHtml(latestComment.author || 'อาจารย์')}${latestComment.created_at ? ` · ${App.escapeHtml(latestComment.created_at)}` : ''}</small>
                    </div>`
                    : '';
                return `<article class="status-card ${statusClass(status)}">
                    <i class="fa-solid ${icon}"></i>
                    <strong>${label}</strong>
                    <span>${App.label(status)}</span>
                    ${decisionDetail}
                </article>`;
            }).join(''));
        });
    }

    function initForms() {
        $('#studentProjectEditForm').on('submit', function (event) {
            event.preventDefault();
            request('api/student/project/', {
                method: 'POST',
                data: JSON.stringify({
                    title: $('#studentProjectTitle').val().trim()
                }),
                contentType: 'application/json'
            }).done((response) => { App.toast(response.message); loadProjectPage(); });
        });

        $('#studentGroupCreateForm').on('submit', function (event) {
            event.preventDefault();
            request('api/student/group/', {
                method: 'POST',
                data: JSON.stringify({ action: 'create', name: $('#studentGroupName').val().trim() }),
                contentType: 'application/json'
            }).done((response) => { App.toast(response.message); this.reset(); loadGroup(); });
        });

        $('#studentGroupAddForm').on('submit', function (event) {
            event.preventDefault();
            request('api/student/group/', {
                method: 'POST',
                data: JSON.stringify({ action: 'add', student_code: $('#studentGroupCode').val().trim() }),
                contentType: 'application/json'
            }).done((response) => { App.toast(response.message); this.reset(); loadGroup(); });
        });

        $('#groupAdvisorInvitationForm').on('submit', function (event) {
            event.preventDefault();
            const form = $(this);
            const submitButton = form.find('button[type="submit"]');
            if (submitButton.prop('disabled')) {
                return;
            }
            const selections = {
                chair_advisor_id: $('#groupChairAdvisor:not(:disabled)').val() || '',
                vice_chair_advisor_id: $('#groupViceChairAdvisor:not(:disabled)').val() || '',
                committee_advisor_id: $('#groupCommitteeAdvisor:not(:disabled)').val() || ''
            };
            const selectedIds = Object.values(selections).filter(Boolean);
            if (!selectedIds.length) {
                App.toast('กรุณาเลือกอาจารย์อย่างน้อย 1 ตำแหน่ง', 'error');
                return;
            }
            if (new Set(selectedIds).size !== selectedIds.length) {
                App.toast('อาจารย์แต่ละตำแหน่งต้องเป็นคนละคน', 'error');
                return;
            }
            const payload = Object.fromEntries(Object.entries(selections).filter(([, id]) => id));
            request('api/student/profile/', {
                method: 'POST',
                data: JSON.stringify(payload),
                contentType: 'application/json'
            }).done((response) => {
                App.toast(response.message);
                window.setTimeout(() => refreshGroupAdvisorStatusesOnly(true), 1500);
            }).always(() => {
                window.setTimeout(() => submitButton.prop('disabled', false), 1500);
            });
            submitButton.prop('disabled', true);
        });

        $('#studentMessageForm').on('submit', function (event) {
            event.preventDefault();
            request('api/student/messages/', {
                method: 'POST',
                data: JSON.stringify(App.formToObject($(this))),
                contentType: 'application/json'
            }).done((response) => {
                App.toast(response.message);
                this.reset();
                loadMessages();
            });
        });

        $('#studentPasswordForm').on('submit', function (event) {
            event.preventDefault();
            request('api/student/change-password/', {
                method: 'POST',
                data: JSON.stringify(App.formToObject($(this))),
                contentType: 'application/json'
            }).done((response) => App.toast(response.message));
        });

        $('#studentForgotPasswordForm').on('submit', function (event) {
            event.preventDefault();
            request('api/student/forgot-password/', {
                method: 'POST',
                data: JSON.stringify(App.formToObject($(this))),
                contentType: 'application/json'
            }).done((response) => App.toast(response.message));
        });
    }

    $(document).on('click', '[data-action="start-solo-project"]', function () {
        App.confirmAction('เลือกทำโครงงานคนเดียว?', 'คุณสามารถเลือกคณะกรรมการและส่งเอกสารได้โดยไม่ต้องเพิ่มสมาชิก').then((result) => {
            if (!result.isConfirmed) return;
            request('api/student/group/', {
                method: 'POST',
                data: JSON.stringify({ action: 'solo' }),
                contentType: 'application/json'
            }).done((response) => {
                App.toast(response.message);
                loadProjectPage();
            });
        });
    });

    $(document).on('click', '#portalNotificationBell', function (event) {
        event.preventDefault();
        const targetUrl = App.url('student/notifications.php');
        request('api/student/notifications/', { method: 'POST' }).done(function () {
            updateCounter(0);
        }).always(function () {
            window.location.href = targetUrl;
        });
    });
    $(document).on('click', '[data-action="remove-group-member"]', function () {
        request('api/student/group/', {
            method: 'POST',
            data: JSON.stringify({ action: 'remove', student_id: $(this).data('id') }),
            contentType: 'application/json'
        }).done((response) => { App.toast(response.message); loadGroup(); });
    });
    $(document).on('click', '[data-action="transfer-group-leader"]', function () {
        request('api/student/group/', {
            method: 'POST',
            data: JSON.stringify({ action: 'transfer_leader', student_id: $('#studentNewGroupLeader').val() }),
            contentType: 'application/json'
        }).done((response) => { App.toast(response.message); loadGroup(); loadProjectPage(); });
    });
    $(document).on('click', '[data-action="leave-student-group"]', function () {
        request('api/student/group/', {
            method: 'POST',
            data: JSON.stringify({ action: 'leave' }),
            contentType: 'application/json'
        }).done((response) => { App.toast(response.message); loadGroup(); loadProjectPage(); });
    });
    $(document).on('click', '[data-action="disband-student-group"]', function () {
        App.confirmAction('ยุบกลุ่ม?', 'ทำได้เฉพาะกลุ่มที่ยังไม่ส่งเอกสาร').then((result) => {
            if (!result.isConfirmed) return;
            request('api/student/group/', {
                method: 'POST', data: JSON.stringify({ action: 'disband' }), contentType: 'application/json'
            }).done((response) => { App.toast(response.message); loadGroup(); loadProjectPage(); });
        });
    });
    $(document).on('click', '[data-action="respond-group-invitation"]', function () {
        request('api/student/group/', {
            method: 'POST',
            data: JSON.stringify({ action: 'respond_invitation', invitation_id: $(this).data('id'), decision: $(this).data('decision') }),
            contentType: 'application/json'
        }).done((response) => { App.toast(response.message); loadGroup(); loadProjectPage(); });
    });
    $(document).on('click', '[data-action="student-mark-read"]', function (event) {
        event.preventDefault();
        request('api/student/notifications/', { method: 'POST' }).done(function (response) {
            App.toast(response.message);
            loadNotifications();
        });
    });
    $(document).on('click', '[data-action="student-print-doc"]', function () {
        const url = $(this).data('url');
        const win = window.open(url, '_blank');
        if (win) win.addEventListener('load', () => win.print());
    });
    $(document).on('click', '[data-action="student-message-page"]', function () {
        studentMessagePage += $(this).data('direction') === 'next' ? 1 : -1;
        renderStudentMessages();
    });
    $(document).on('click', '[data-action="student-delete-stage"]', function () {
        const stage = $('#portalStage').val();
        App.confirmAction('ลบเอกสาร?', 'สามารถลบไฟล์ที่ส่งแล้วได้เฉพาะก่อนอนุมัติเท่านั้น').then((result) => {
            if (!result.isConfirmed) return;
            request(`api/student/upload/${stage}/`, { method: 'DELETE' }).done((response) => {
                App.toast(response.message);
                loadStage();
            });
        });
    });

    $(function () {
        const currentPage = page();
        if (!String(currentPage).startsWith('portal-')) return;
        initForms();
        loadNavbarProfile();
        if (currentPage === 'portal-dashboard') loadDashboard();
        if (currentPage === 'portal-profile') loadProfile();
        if (currentPage === 'portal-project') loadProjectPage();
        if (['portal-proposal', 'portal-draft', 'portal-complete'].includes(currentPage)) { initUpload(); loadStage(); }
        if (currentPage === 'portal-barcode') loadBarcode();
        if (currentPage === 'portal-timeline') loadTimeline();
        if (currentPage === 'portal-notifications') loadNotifications();
        if (currentPage === 'portal-documents') loadDocuments();
        if (currentPage === 'portal-messages') loadMessages();
        if (currentPage === 'portal-status') loadStatus();

        request('api/student/notifications/').done((response) => updateCounter(response.unread));
        setInterval(function () {
            if (currentPage === 'portal-dashboard') loadDashboard();
            if (currentPage === 'portal-notifications') loadNotifications();
            if (currentPage === 'portal-messages') loadMessages();
            if (currentPage === 'portal-status') loadStatus();
            request('api/student/notifications/').done((response) => updateCounter(response.unread));
        }, refreshMs);

        setInterval(function () {
            if (currentPage === 'portal-project' && !$('#groupAdvisorInvitationForm').hasClass('d-none')) {
                if ($('.group-advisor-select').is(':focus')) {
                    return;
                }
                refreshGroupAdvisorStatusesOnly($('#groupAdvisorSubmitActions').is(':visible'));
            }
        }, advisorStatusRefreshMs);
    });
})(jQuery);
