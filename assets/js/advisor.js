(function ($) {
    'use strict';

    const refreshMs = 30000;
    const apiBase = App.url('advisor-api.php?endpoint=');
    let advisorMessages = [];
    let advisorMessagePage = 1;
    const advisorMessagesPerPage = 5;
    const pendingGetRequests = new Map();

    function page() {
        return $('body').data('page');
    }

    function token() {
        return sessionStorage.getItem('advisor_token') || '';
    }

    function request(endpoint, options = {}) {
        const ajaxOptions = Object.assign({
            url: apiBase + encodeURIComponent(endpoint),
            method: options.method || 'GET',
            dataType: 'json',
            headers: {
                Authorization: 'Bearer ' + token(),
                'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content') || ''
            }
        }, options);
        const isGet = ajaxOptions.method.toUpperCase() === 'GET';
        const requestKey = isGet ? `${ajaxOptions.url}|${JSON.stringify(ajaxOptions.data || null)}` : '';
        if (isGet && pendingGetRequests.has(requestKey)) return pendingGetRequests.get(requestKey);
        if (isGet) ajaxOptions.timeout = 30000;

        const pending = $.ajax(ajaxOptions).fail((xhr, status) => {
            if (status === 'abort') return;
            App.toast(xhr.responseJSON?.message || 'เชื่อมต่อ Advisor API ไม่สำเร็จ', 'error');
        });
        if (isGet) {
            pendingGetRequests.set(requestKey, pending);
            pending.always(function () {
                if (pendingGetRequests.get(requestKey) === pending) pendingGetRequests.delete(requestKey);
            });
        }
        return pending;
    }

    function fileUrl(document) {
        return document?.id ? App.url(`api/file.php?id=${encodeURIComponent(document.id)}`) : '#';
    }

    function downloadUrl(document) {
        return document?.id ? App.url(`api/file.php?id=${encodeURIComponent(document.id)}&mode=download`) : '#';
    }

    function renderSummary(summary) {
        const cards = [
            ['นักศึกษาทั้งหมด', summary.total_students, 'fa-user-graduate'],
            ['รอ Proposal', summary.waiting_proposal, 'fa-file-signature'],
            ['รอ Draft', summary.waiting_draft, 'fa-file-lines'],
            ['รอ Complete', summary.waiting_complete, 'fa-circle-check'],
            ['อนุมัติวันนี้', summary.approved_today, 'fa-stamp'],
        ];
        $('#advisorSummary').html(cards.map(([label, value, icon]) => `<article class="summary-card"><span>${label}</span><strong>${value}</strong><i class="fa-solid ${icon}"></i></article>`).join(''));
    }

    function renderCharts(students) {
        const progressCtx = document.getElementById('advisorProgressChart');
        const approvalCtx = document.getElementById('advisorApprovalChart');
        if (progressCtx && window.Chart) {
            App.state.charts.advisorProgressChart?.destroy();
            App.state.charts.advisorProgressChart = new Chart(progressCtx, {
                type: 'bar',
                data: { labels: students.map((row) => row.code), datasets: [{ label: 'ความคืบหน้า (%)', data: students.map((row) => row.progress), backgroundColor: '#0B3C8C', borderRadius: 12 }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100 } } }
            });
        }
        if (approvalCtx && window.Chart) {
            const counts = ['Approved', 'Pending', 'Review'].map((status) => students.filter((row) => [row.proposal, row.draft, row.complete, row.status].includes(status)).length);
            App.state.charts.advisorApprovalChart?.destroy();
            App.state.charts.advisorApprovalChart = new Chart(approvalCtx, {
                type: 'doughnut',
                data: { labels: ['อนุมัติแล้ว', 'รอดำเนินการ', 'ขอแก้ไข'], datasets: [{ data: counts, backgroundColor: ['#16a34a', '#f59e0b', '#38bdf8'] }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }
    }

    function renderNotifications(selector, rows) {
        $(selector).html(rows.map((row) => `<a class="list-group-item" href="${App.url('advisor/notifications.php')}"><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.message)}</span><small>${App.escapeHtml(row.created_at)}</small></a>`).join('') || '<div class="list-group-item text-muted">ยังไม่มีการแจ้งเตือน</div>');
    }

    function updateCounter(count) {
        $('#advisorNotificationCounter').text(count || 0).toggle((count || 0) > 0);
    }

    function loadNavbarProfile() {
        request('profile').done(function (response) {
            const data = response.data || {};
            $('#advisorNavbarName').text(data.name || 'อาจารย์');
        });
    }

    function loadDashboard() {
        request('dashboard').done((response) => {
            const data = response.data;
            $('#advisorNavbarName').text(data.advisor.name);
            renderSummary(data.summary);
            renderCharts(data.students);
            $('#advisorActivities').html(data.activities.map((row) => `<div class="list-group-item"><strong>${App.escapeHtml(row.title || row.author || 'กิจกรรม')}</strong><span class="d-block text-muted">${App.escapeHtml(row.message)}</span><small>${App.escapeHtml(row.created_at)}</small></div>`).join(''));
            renderNotifications('#advisorDashboardNotifications', data.notifications);
            updateCounter(data.unread);
        });
    }

    function loadStudents(target = '#advisorStudentsTable', forReports = false) {
        request('students').done((response) => {
            const rows = response.data;
            if (forReports) {
                $(target + ' tbody').html(rows.map((row) => `<tr><td>${App.escapeHtml(row.name)}</td><td>${App.escapeHtml(row.department)}</td><td>${App.escapeHtml(row.project_title)}</td><td>${row.progress}%</td><td>${App.badge(row.status)}</td></tr>`).join(''));
                App.enhanceTable(target);
                return;
            }
            $(target + ' tbody').html(rows.map((row) => `<tr>
                <td>${App.escapeHtml(row.code)}</td><td>${App.escapeHtml(row.name)}</td><td>${App.escapeHtml(row.department)}</td><td>${App.escapeHtml(row.project_title)}</td>
                <td>${App.badge(row.proposal)}</td><td>${App.badge(row.draft)}</td><td>${App.badge(row.complete)}</td><td>${App.badge(row.status)}</td>
                <td class="text-end"><div class="row-actions single"><a class="row-action view" href="${App.url(`advisor/student-detail.php?id=${row.id}`)}" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="fa-regular fa-eye"></i><span>ดูข้อมูล</span></a></div></td>
            </tr>`).join(''));
            App.enhanceTable(target);
            $('#advisorStudentStatusFilter').off('change').on('change', function () {
                App.state.tables[target]?.column(7).search(this.value ? App.label(this.value) : '').draw();
            });
        });
    }

    function loadGroups() {
        request('groups').done((response) => {
            $('#advisorGroupsTable tbody').html(response.data.map((group) => {
                const memberNames = group.members.map((member) => `${App.escapeHtml(member.code)} - ${App.escapeHtml(member.name)}`).join('<br>');
                return `<tr>
                    <td><strong>${App.escapeHtml(group.name)}</strong><span class="d-block text-muted">${App.escapeHtml(group.faculty)}</span></td>
                    <td>${App.escapeHtml(group.project?.title || '-')}</td>
                    <td>${App.escapeHtml(group.role_label)}</td>
                    <td>${group.member_count}/5 คน</td>
                    <td>${memberNames || '-'}</td>
                    <td>${App.badge(group.project?.status || 'Pending')}</td>
                </tr>`;
            }).join('') || '<tr><td colspan="6" class="text-center text-muted">ยังไม่มีกลุ่มที่ตอบรับคำเชิญ</td></tr>');
        });
    }

    function renderTimeline(selector, rows) {
        $(selector).html(rows.map((row) => `<div class="timeline-item"><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.date || 'รอดำเนินการ')}</span>${App.badge(row.status)}</div>`).join(''));
    }

    function loadStudentDetail() {
        const id = $('#advisorStudentId').val();
        request(`student/${id}`).done((response) => {
            const { student, group, members, project, documents, comments, timeline } = response.data;
            $('#detailStudentPhoto').attr('src', App.url(`api/profile-photo.php?id=${encodeURIComponent(student.id)}`));
            $('#detailStudentName').text(student.name);
            $('#detailStudentCode').text(student.code);
            $('#advisorStudentDetailGrid').html([
                ['รหัสนักศึกษา', student.code], ['ชื่อ-นามสกุล', student.name], ['สาขา', student.department], ['อีเมล', student.email],
                ['ชื่อโครงงาน', project.title], ['รหัสโครงงาน', project.code], ['ประเภท', project.category], ['ความคืบหน้า', `${project.progress}%`],
            ].map(([label, value]) => `<div class="detail-item"><span>${label}</span><strong>${App.escapeHtml(value || '-')}</strong></div>`).join(''));
            $('#advisorStudentDetailGrid .detail-item').eq(6).remove();
            if (group) {
                const memberNames = (members || []).map((member) => `${member.code} - ${member.name}`).join(', ');
                $('#advisorStudentDetailGrid').append(`<div class="detail-item"><span>กลุ่มโครงงาน</span><strong>${App.escapeHtml(group.name)}</strong></div><div class="detail-item"><span>สมาชิกกลุ่ม</span><strong>${App.escapeHtml(memberNames || '-')}</strong></div>`);
            }
            renderTimeline('#advisorStudentTimeline', timeline);
            $('#advisorStudentDocsTable tbody').html(documents.map((document) => `<tr><td>${App.escapeHtml(document.title)}</td><td>${App.badge(document.status)}</td><td>${App.escapeHtml(document.uploaded_at)}</td><td class="text-end">${documentActions(document)}</td></tr>`).join(''));
            $('#advisorStudentComments').html(comments.map((row) => `<div class="list-group-item"><strong>${App.escapeHtml(row.author)}</strong><span class="d-block">${App.escapeHtml(row.message)}</span><small>${App.escapeHtml(row.created_at)}</small></div>`).join('') || '<div class="list-group-item text-muted">ยังไม่มีความคิดเห็น</div>');
        });
    }

    function documentActions(document) {
        const documentLocked = ['Approved', 'Completed'].includes(document.status);
        return `<div class="row-actions wide${documentLocked ? ' is-locked' : ''}" role="group" aria-label="ตรวจเอกสาร">
            <button class="row-action view" data-action="advisor-preview" data-url="${fileUrl(document)}" title="ดูตัวอย่าง" aria-label="ดูตัวอย่าง"><i class="fa-solid fa-eye"></i></button>
            <a class="row-action edit" href="${downloadUrl(document)}" download title="ดาวน์โหลด" aria-label="ดาวน์โหลด"><i class="fa-solid fa-download"></i></a>
            ${documentLocked ? '' : `
                <button class="row-action approve" data-action="advisor-approve" data-stage="${document.type}" data-doc="${document.id}" title="อนุมัติ" aria-label="อนุมัติ"><i class="fa-solid fa-check"></i></button>
                <button class="row-action warning" data-action="advisor-revision" data-stage="${document.type}" data-doc="${document.id}" data-student="${document.student_id}" title="ให้กลับไปแก้ไข" aria-label="ให้กลับไปแก้ไข"><i class="fa-solid fa-pen"></i></button>
                <button class="row-action delete" data-action="advisor-reject" data-stage="${document.type}" data-doc="${document.id}" title="ยังไม่ผ่าน" aria-label="ยังไม่ผ่าน"><i class="fa-solid fa-xmark"></i></button>
            `}
        </div>`;
    }

    function loadStage() {
        const stage = $('#advisorStage').val();
        request(`stage/${stage}`).done((response) => {
            const rows = response.data || [];
            $('#advisorStageTable tbody').html(rows.map(({ document, student, project }) => `<tr>
                <td>${App.escapeHtml(student.code)}<span class="d-block text-muted">${App.escapeHtml(student.name)}</span></td>
                <td>${App.escapeHtml(project.title)}</td><td>${document.type === 'draft' ? `<strong>บทที่ ${Number(document.chapter || 0)}</strong><span class="d-block text-muted">${App.escapeHtml(document.filename)}</span>` : App.escapeHtml(document.filename)}</td><td>${App.badge(document.status)}</td><td>${App.escapeHtml(document.uploaded_at)}</td>
                <td class="text-end">${documentActions(document)}</td>
            </tr>`).join(''));
            App.enhanceTable('#advisorStageTable');
        });
    }

    function submitDecision(stage, action, documentId, comment = '') {
        request(`${stage}/${action}`, { method: 'POST', data: JSON.stringify({ document_id: documentId, comment }), contentType: 'application/json' }).done((response) => {
            App.toast(response.message);
            if (action === 'approve') {
                window.setTimeout(() => window.location.reload(), 700);
                return;
            }
            if (page() === 'advisor-student-detail') loadStudentDetail(); else loadStage();
        });
    }

    function renderAdvisorMessages() {
        const totalPages = Math.max(1, Math.ceil(advisorMessages.length / advisorMessagesPerPage));
        advisorMessagePage = Math.min(Math.max(1, advisorMessagePage), totalPages);
        const start = (advisorMessagePage - 1) * advisorMessagesPerPage;
        const rows = advisorMessages.slice(start, start + advisorMessagesPerPage);

        $('#advisorMessagesList').html(rows.map((row) => `<div class="list-group-item"><strong class="d-block">${App.escapeHtml(row.subject)}</strong><span class="d-block message-preview">${App.escapeHtml(row.message)}</span><small class="text-muted">${App.escapeHtml(row.sender)} → ${App.escapeHtml(row.receiver)} · ${App.escapeHtml(row.created_at)}</small></div>`).join('') || '<div class="list-group-item text-muted">ยังไม่มีข้อความ</div>');
        $('#advisorMessagePageInfo').text(`หน้า ${advisorMessagePage} / ${totalPages}`);
        $('[data-action="advisor-message-page"][data-direction="previous"]').prop('disabled', advisorMessagePage <= 1);
        $('[data-action="advisor-message-page"][data-direction="next"]').prop('disabled', advisorMessagePage >= totalPages);
        $('#advisorMessagesPagination').toggle(advisorMessages.length > advisorMessagesPerPage);
    }

    function loadMessages() {
        const selectedGroup = $('#advisorMessageGroup').val();
        request('groups').done((response) => {
            $('#advisorMessageGroup').html(response.data.map((group) => `<option value="${group.id}">${App.escapeHtml(group.name)} - ${App.escapeHtml(group.project?.title || '-')} (${group.member_count} คน)</option>`).join('') || '<option value="">ยังไม่มีกลุ่มที่ตอบรับคำเชิญ</option>');
            if (selectedGroup && $(`#advisorMessageGroup option[value="${CSS.escape(String(selectedGroup))}"]`).length) $('#advisorMessageGroup').val(selectedGroup);
        });
        request('messages').done((response) => {
            advisorMessages = response.data || [];
            renderAdvisorMessages();
        });
    }

    function loadNotifications() {
        request('invitations').done((response) => {
            const roleLabels = { chair: 'ประธาน', vice_chair: 'รองประธาน', committee: 'กรรมการ' };
            $('#advisorInvitationsTable tbody').html(response.data.map((row) => `<tr>
                <td><strong>${App.escapeHtml(row.group_name)}</strong></td>
                <td>${App.escapeHtml(row.student_code)} - ${App.escapeHtml(row.leader_name)}</td>
                <td>${App.escapeHtml(roleLabels[row.role] || row.role)}</td>
                <td>${App.badge(row.status === 'Accepted' ? 'Approved' : row.status)}</td>
                <td class="text-end">${row.status === 'Pending' ? `<button class="btn btn-sm btn-success" data-action="advisor-invitation" data-decision="accept" data-id="${row.id}">รับคำเชิญ</button> <button class="btn btn-sm btn-outline-danger" data-action="advisor-invitation" data-decision="reject" data-id="${row.id}">ปฏิเสธ</button>` : ''}</td>
            </tr>`).join('') || '<tr><td colspan="5" class="text-center text-muted">ยังไม่มีคำเชิญ</td></tr>');
        });
        request('notifications').done((response) => {
            updateCounter(response.unread);
            $('#advisorNotificationsTable tbody').html(response.data.map((row) => `<tr>
                <td><strong>${App.escapeHtml(row.group_name || 'ส่วนตัว')}</strong></td>
                <td>${App.escapeHtml(row.title)}</td>
                <td>${App.escapeHtml(row.message)}</td>
                <td>${App.escapeHtml(App.label(row.type))}</td>
                <td><span class="notification-read-state ${row.read ? 'is-read' : 'is-unread'}">${row.read ? 'อ่านแล้ว' : 'ยังไม่ได้อ่าน'}</span></td>
                <td>${App.escapeHtml(row.created_at)}</td>
            </tr>`).join(''));
            App.enhanceTable('#advisorNotificationsTable');
        });
    }

    function loadCalendar() {
        request('calendar').done((response) => {
            const selectedGroup = $('#advisorCalendarGroup').val();
            $('#advisorCalendarGroup').html((response.groups || []).map((group) => `<option value="${App.escapeHtml(group.id)}">${App.escapeHtml(group.name)}</option>`).join('') || '<option value="">ยังไม่มีกลุ่มที่ตอบรับคำเชิญ</option>');
            if (selectedGroup) $('#advisorCalendarGroup').val(selectedGroup);
            $('#advisorCalendarForm button[type="submit"]').prop('disabled', !(response.groups || []).length);
            $('#advisorCalendarList').html(response.data.map((row) => `<div class="list-group-item calendar-event-item"><div class="d-flex justify-content-between align-items-start gap-3"><div><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-primary fw-semibold"><i class="fa-solid fa-users me-1"></i>${App.escapeHtml(row.group_name)}</span></div><span class="badge text-bg-light">${App.escapeHtml(row.type)}</span></div><span class="d-block text-muted mt-2"><i class="fa-regular fa-calendar me-1"></i>${App.escapeHtml(row.date)}${row.time ? ` เวลา ${App.escapeHtml(row.time)}` : ''}${row.location ? ` · ${App.escapeHtml(row.location)}` : ''}</span>${row.details ? `<small class="d-block mt-1">${App.escapeHtml(row.details)}</small>` : ''}</div>`).join('') || '<div class="list-group-item text-muted text-center py-5">ยังไม่มีนัดหมาย</div>');
        });
    }

    function loadProfile() {
        request('profile').done((response) => {
            const data = response.data;
            $('#advisorNavbarName,#advisorProfileName').text(data.name);
            $('#advisorProfileDepartment').text(data.department);
            $('#advisorProfilePhoto').attr('src', App.url(data.photo || 'assets/img/profile-advisor.svg'));
            $('#apName').val(data.name); $('#apDepartment').val(data.department); $('#apEmail').val(data.email); $('#apPhone').val(data.phone);
        });
    }

    function initForms() {
        $('#advisorLoginForm').on('submit', function (event) {
            event.preventDefault();
            request('login', { method: 'POST', data: JSON.stringify(App.formToObject($(this))), contentType: 'application/json' }).done((response) => {
                sessionStorage.setItem('advisor_token', response.token);
                sessionStorage.setItem('advisor_user', JSON.stringify(response.user));
                window.location.href = App.url('advisor/dashboard.php');
            }).fail(() => {
                this.submit();
            });
        });
        $('#advisorMessageForm').on('submit', function (event) {
            event.preventDefault();
            request('message', { method: 'POST', data: JSON.stringify(App.formToObject($(this))), contentType: 'application/json' }).done((response) => { App.toast(response.message); this.reset(); loadMessages(); });
        });
        $('#advisorCalendarForm').on('submit', function (event) {
            event.preventDefault();
            request('calendar', { method: 'POST', data: JSON.stringify(App.formToObject($(this))), contentType: 'application/json' }).done((response) => {
                App.toast(response.message);
                this.reset();
                loadCalendar();
            });
        });
        $('#advisorProfileForm').on('submit', function (event) {
            event.preventDefault();
            request('profile', { method: 'POST', data: new FormData(this), processData: false, contentType: false }).done((response) => App.toast(response.message));
        });
    }

    $(document).on('click', '#advisorNotificationBell', function (event) {
        event.preventDefault();
        const targetUrl = App.url('advisor/notifications.php');
        request('notifications', { method: 'POST' }).done(function () {
            updateCounter(0);
        }).always(function () {
            window.location.href = targetUrl;
        });
    });
    $(document).on('click', '[data-action="advisor-preview"]', function () {
        $('#pdfPreviewFrame').attr('src', $(this).data('url'));
        bootstrap.Modal.getOrCreateInstance(document.getElementById('filePreviewModal')).show();
    });
    $(document).on('click', '[data-action="advisor-approve"],[data-action="advisor-reject"],[data-action="advisor-revision"]', function () {
        const action = $(this).data('action').replace('advisor-', '');
        const stage = $(this).data('stage');
        const doc = $(this).data('doc');
        const title = action === 'approve' ? 'อนุมัติเอกสาร?' : action === 'reject' ? 'ระบุว่าเอกสารยังไม่ผ่าน?' : 'ให้กลับไปแก้ไข?';
        Swal.fire({ title, input: 'textarea', inputLabel: 'ความคิดเห็นถึงนักศึกษา', showCancelButton: true, confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#0B3C8C' }).then((result) => {
            if (result.isConfirmed) submitDecision(stage, action, doc, result.value || '');
        });
    });
    $(document).on('click', '[data-action="advisor-refresh-stage"]', function (event) { event.preventDefault(); loadStage(); });
    $(document).on('click', '[data-action="advisor-message-page"]', function () {
        advisorMessagePage += $(this).data('direction') === 'next' ? 1 : -1;
        renderAdvisorMessages();
    });
    $(document).on('click', '[data-action="advisor-mark-read"]', function (event) { event.preventDefault(); request('notifications', { method: 'POST' }).done((response) => { App.toast(response.message); loadNotifications(); }); });
    $(document).on('click', '[data-action="advisor-invitation"]', function () {
        request('invitations', {
            method: 'POST',
            data: JSON.stringify({ invitation_id: $(this).data('id'), action: $(this).data('decision') }),
            contentType: 'application/json'
        }).done((response) => { App.toast(response.message); loadNotifications(); });
    });
    $(document).on('click', '[data-action="advisor-export-excel"]', function (event) { event.preventDefault(); App.toast('เตรียมไฟล์ Excel แล้ว'); });
    $(document).on('click', '[data-action="advisor-export-pdf"]', function (event) { event.preventDefault(); window.print(); });

    $(function () {
        const current = page();
        if (!String(current).startsWith('advisor-')) return;
        initForms();
        if (current === 'advisor-login') return;
        loadNavbarProfile();
        request('notifications').done((response) => updateCounter(response.unread));
        if (current === 'advisor-dashboard') loadDashboard();
        if (current === 'advisor-students') { loadGroups(); loadStudents(); }
        if (current === 'advisor-student-detail') loadStudentDetail();
        if (['advisor-proposal', 'advisor-draft', 'advisor-complete'].includes(current)) loadStage();
        if (current === 'advisor-messages') loadMessages();
        if (current === 'advisor-notifications') loadNotifications();
        if (current === 'advisor-calendar') loadCalendar();
        if (current === 'advisor-profile') loadProfile();
        if (current === 'advisor-reports') loadStudents('#advisorReportsTable', true);
        setInterval(() => {
            if (document.visibilityState !== 'visible') return;
            if (current === 'advisor-dashboard') loadDashboard();
            if (current === 'advisor-messages') loadMessages();
            if (current === 'advisor-notifications') loadNotifications();
            if (current === 'advisor-students') { loadGroups(); loadStudents(); }
            request('notifications').done((response) => updateCounter(response.unread));
        }, refreshMs);
    });
})(jQuery);
