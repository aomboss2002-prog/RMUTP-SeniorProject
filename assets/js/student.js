(function ($) {
    'use strict';

    let advisors = [];
    let students = [];
    let projects = [];
    const businessFaculty = 'คณะบริหารธุรกิจ';
    const businessMajors = [
        'บช.บ. บัญชีบัณฑิต (ได้รับการรับรองจากสภาวิชาชีพบัญชี)',
        'บธ.บ. สาขาวิชาการจัดการ',
        'บธ.บ. สาขาวิชาการจัดการโลจิสติกส์และโซ่อุปทาน',
        'บธ.บ. สาขาวิชาการตลาด',
        'บธ.บ. สาขาวิชานวัตกรรมทางการเงินและการลงทุน',
        'บธ.บ. สาขาวิชาระบบสารสนเทศและนวัตกรรมดิจิทัล',
        'บธ.บ. สาขาวิชาการจัดการธุรกิจระหว่างประเทศ (หลักสูตรนานาชาติ)',
        'วท.บ. สาขาวิชาการวิเคราะห์ข้อมูลทางธุรกิจ',
        'บธ.บ. สาขาวิชาการเป็นผู้ประกอบการ',
        'บธ.บ. สาขาวิชานวัตกรรมธุรกิจบริการยั่งยืน'
    ];

    function loadLookups() {
        return $.when(App.api('advisors'), App.api('students'), App.api('projects')).done(function (advisorResponse, studentResponse, projectResponse) {
            advisors = advisorResponse[0].data || [];
            students = studentResponse[0].data || [];
            projects = projectResponse[0].data || [];
            fillSelect('#advisorInput', advisors, 'id', 'name');
            fillSelect('#uploadStudent', students, 'id', (row) => `${row.first_name} ${row.last_name}`);
            fillSelect('#uploadProject, #projectInput, #barcodeProject, #timelineProject', projects, 'id', (row) => `${row.code} - ${row.title}`);
        });
    }

    function fillSelect(selector, rows, valueKey, labelKey) {
        const $select = $(selector);
        if (!$select.length) {
            return;
        }
        const html = rows.map((row) => {
            const label = typeof labelKey === 'function' ? labelKey(row) : row[labelKey];
            return `<option value="${App.escapeHtml(row[valueKey])}">${App.escapeHtml(label)}</option>`;
        }).join('');
        $select.html(html);
    }

    function advisorName(id) {
        return (advisors.find((row) => row.id === id) || {}).name || id || '';
    }

    function projectName(id) {
        return (projects.find((row) => row.id === id) || {}).title || id || '';
    }

    function studentName(id) {
        const student = students.find((row) => row.id === id) || {};
        return `${student.first_name || ''} ${student.last_name || ''}`.trim() || id || '';
    }

    function loadNavbarProfile() {
        App.api('profile').done(function (response) {
            const profile = response.data || {};
            $('#adminNavbarName').text(profile.name || profile.role || 'ผู้ดูแล');
        });
    }

    function loadStudentsTable() {
        $.when(loadLookups()).done(function () {
            $('#studentsTable tbody').html(students.map((row) => `
                <tr data-status="${App.escapeHtml(row.status)}">
                    <td>${App.escapeHtml(row.code)}</td>
                    <td><strong>${App.escapeHtml(row.first_name)} ${App.escapeHtml(row.last_name)}</strong><span class="d-block text-muted">${App.escapeHtml(row.email)}</span></td>
                    <td>${App.escapeHtml(row.major)}</td>
                    <td>${App.escapeHtml(advisorName(row.advisor_id))}</td>
                    <td data-search="${App.escapeHtml(row.status)}">${App.badge(row.status)}</td>
                    <td class="text-end">
                        <div class="row-actions" role="group" aria-label="จัดการนักศึกษา">
                            <a class="row-action view" href="${App.url(`admin/students/detail.php?id=${row.id}`)}" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="fa-solid fa-eye"></i></a>
                            <a class="row-action edit" href="${App.url(`admin/students/edit.php?id=${row.id}`)}" title="แก้ไข" aria-label="แก้ไข"><i class="fa-solid fa-pen"></i></a>
                            <button class="row-action delete" type="button" data-action="delete-student" data-id="${row.id}" title="ลบ" aria-label="ลบ"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>`).join(''));
            const table = App.enhanceTable('#studentsTable');
            $('#studentStatusFilter').off('change').on('change', function () {
                table.column(4).search($(this).val()).draw();
            });
        });
    }

    function initStudentForm() {
        const $form = $('#studentForm');
        let previewObjectUrl = null;
        const $photoInput = $('#studentPhotoFile');
        const $photoPreview = $('#studentPhotoPreview');

        $('#studentCodeInput').on('input', function () {
            const digits = String(this.value).replace(/\D/g, '').slice(0, 13);
            this.value = digits.length > 12 ? `${digits.slice(0, 12)}-${digits.slice(12)}` : digits;
        });
        $('#phoneInput').on('input', function () {
            this.value = String(this.value).replace(/\D/g, '').slice(0, 10);
        });

        $photoInput.on('change', function () {
            const file = this.files[0];
            if (!file) return;
            if (!['image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(file.type) || file.size > 5 * 1024 * 1024) {
                this.value = '';
                return App.toast('กรุณาเลือกไฟล์ JPG, PNG, WEBP หรือ GIF ขนาดไม่เกิน 5 MB', 'error');
            }
            if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = URL.createObjectURL(file);
            $photoPreview.attr('src', previewObjectUrl);
        });

        (function () {
            const mode = $form.data('mode');
            const id = $form.data('id');
            if (mode === 'edit' && id) {
                App.api('students', { query: { id } }).done(function (response) {
                    const row = response.data.student;
                    Object.keys(row).forEach((key) => $form.find(`[name="${key}"]`).val(row[key]));
                    $photoPreview.attr('src', App.url(`api/profile-photo.php?id=${encodeURIComponent(id)}&v=${Date.now()}`));
                });
            }
            $form.on('submit', function (event) {
                event.preventDefault();
                const formData = new FormData(this);
                if (mode === 'edit') {
                    formData.append('_method', 'PUT');
                    formData.append('id', id);
                }
                App.showLoader(true);
                App.api('students', { method: 'POST', formData })
                    .done(function (response) {
                        App.toast(response.message);
                        window.location.href = mode === 'edit' ? App.url(`admin/students/detail.php?id=${id}`) : App.url('admin/students/index.php');
                    })
                    .always(() => App.showLoader(false));
            });
        })();
    }

    function loadStudentDetail() {
        const id = $('#studentDetailId').val();
        App.api('students', { query: { id } }).done(function (response) {
            const data = response.data;
            const student = data.student;
            ProjectTrackingUI.renderAll({ progress: '#adminTrackingProgress', summary: '#adminTrackingSummary', milestones: '#adminMilestones', history: '#adminTrackingHistory', followups: '#adminTrackingFollowups', chart: 'adminTrackingChart' }, data.tracking);
            $('#studentPhoto').attr('src', App.url(`api/profile-photo.php?id=${encodeURIComponent(student.id)}`));
            $('#studentFullName').text(`${student.first_name} ${student.last_name}`);
            $('#studentCode').text(student.code);
            $('#studentStatus').html(App.badge(student.status));
            $('#studentAdvisor').html(`
                <strong>${App.escapeHtml(data.advisor?.name || '')}</strong>
                <span class="d-block text-muted">${App.escapeHtml(data.advisor?.department || '')}</span>
                <span class="d-block">${App.escapeHtml(data.advisor?.email || '')}</span>`);
            $('#studentInfoGrid').html([
                ['อีเมล', student.email],
                ['เบอร์โทรศัพท์', student.phone],
                ['คณะ', student.faculty],
                ['สาขา', student.major],
                ['ชั้นปี', student.year_level],
                ['โครงงาน', data.project?.title || '']
            ].map((item) => `<div class="detail-item"><span>${item[0]}</span><strong>${App.escapeHtml(item[1])}</strong></div>`).join(''));
            $('#studentTimeline').html(data.timeline.map(timelineItem).join('') || '<p class="text-muted mb-0">ยังไม่มีข้อมูลไทม์ไลน์</p>');
            $('#studentFilesTable tbody').html(data.files.map(documentRow).join(''));
            App.enhanceTable('#studentFilesTable', { searching: false, pageLength: 5 });
            $('#studentComments').html(data.comments.map((row) => `
                <div class="list-group-item">
                    <strong>${App.escapeHtml(row.author)}</strong>
                    <span class="d-block">${App.escapeHtml(row.message)}</span>
                    <small class="text-muted">${App.escapeHtml(row.created_at)}</small>
                </div>`).join('') || '<div class="list-group-item text-muted">ยังไม่มีความคิดเห็น</div>');
            $('#approvalHistoryTable tbody').html(data.approvals.map((row) => `
                <tr><td>${App.escapeHtml(row.step)}</td><td>${App.escapeHtml(row.reviewer)}</td><td>${App.badge(row.status)}</td><td>${App.escapeHtml(row.created_at)}</td></tr>`).join(''));
            App.enhanceTable('#approvalHistoryTable', { searching: false, pageLength: 5 });
        });
    }

    function timelineItem(row) {
        return `<div class="timeline-item"><strong>${App.escapeHtml(row.step || row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.reviewer || row.actor || '')} · ${App.escapeHtml(row.created_at || row.updated_at || '')}</span>${App.badge(row.status || 'Review')}</div>`;
    }

    function documentRow(row) {
        const fileUrl = App.url(`api/file.php?id=${encodeURIComponent(row.id)}`);
        return `
            <tr>
                <td><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.filename)}</span></td>
                <td>${App.escapeHtml(App.label(row.type))}</td>
                <td>${App.badge(row.status)}</td>
                <td class="text-end">
                    <div class="row-actions" role="group" aria-label="จัดการเอกสาร">
                        <button class="row-action view" data-action="preview-file" data-url="${App.escapeHtml(fileUrl)}" title="ดูตัวอย่าง" aria-label="ดูตัวอย่าง"><i class="fa-solid fa-eye"></i></button>
                        <a class="row-action edit" href="${App.url(`admin/page.php?view=${encodeURIComponent(row.type)}`)}" title="จัดการเอกสาร" aria-label="จัดการเอกสาร"><i class="fa-solid fa-rotate"></i></a>
                        <button class="row-action delete" data-action="delete-document" data-id="${App.escapeHtml(row.id)}" title="ลบ" aria-label="ลบ"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </td>
            </tr>`;
    }

    function loadAdvisorsTable() {
        App.api('advisors').done(function (response) {
            advisors = response.data || [];
            $('#advisorsTable tbody').html(advisors.map((row) => `
                <tr>
                    <td><strong>${App.escapeHtml(row.name)}</strong></td>
                    <td>${App.escapeHtml(row.department)}</td>
                    <td>${App.escapeHtml(row.email)}</td>
                    <td>${App.escapeHtml(row.students)}</td>
                    <td>${App.badge(row.status)}</td>
                    <td class="text-end"><div class="row-actions"><button class="row-action view" data-action="show-advisor" data-id="${row.id}" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="fa-regular fa-eye"></i></button><button class="row-action delete" type="button" data-action="delete-advisor" data-id="${row.id}" title="ลบ" aria-label="ลบ"><i class="fa-solid fa-trash"></i></button></div></td>
                </tr>`).join(''));
            App.enhanceTable('#advisorsTable');
        });
    }

    function loadProjectsTable() {
        App.api('projects').done(function (response) {
            projects = response.data;
            $('#projectStatusFilter').val('');
            $('#projectsTable tbody').html(projects.map((row) => `
                <tr>
                    <td>${App.escapeHtml(row.code)}</td>
                    <td title="${App.escapeHtml(row.title)}"><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.category)}</span></td>
                    <td title="${App.escapeHtml(row.student_name)}">${App.escapeHtml(row.student_name)}</td>
                    <td title="${App.escapeHtml(row.advisor_name)}">${App.escapeHtml(row.advisor_name)}</td>
                    <td><div class="progress"><div class="progress-bar" style="width:${Number(row.progress) || 0}%">${App.escapeHtml(row.progress)}%</div></div></td>
                    <td data-search="${App.escapeHtml(row.status)}">${App.badge(row.status)}</td>
                    <td class="text-end">
                        <div class="row-actions" role="group" aria-label="จัดการโครงงาน">
                            <a class="row-action view" href="${App.url(`admin/page.php?view=timeline&project=${row.id}`)}" title="ดูไทม์ไลน์" aria-label="ดูไทม์ไลน์"><i class="fa-solid fa-timeline"></i></a>
                            <a class="row-action edit" href="${App.url(`admin/page.php?view=barcode&project=${row.id}`)}" title="ดูบาร์โค้ด" aria-label="ดูบาร์โค้ด"><i class="fa-solid fa-barcode"></i></a>
                            ${row.status === 'Completed' && row.complete_approved
                                ? '<button class="row-action approve" type="button" title="เสร็จสมบูรณ์แล้ว" aria-label="เสร็จสมบูรณ์แล้ว" disabled><i class="fa-solid fa-check"></i></button>'
                                : row.complete_approved
                                    ? `<button class="row-action approve" type="button" data-action="complete-project" data-id="${App.escapeHtml(row.id)}" title="ทำเครื่องหมายว่าเสร็จสมบูรณ์" aria-label="ทำเครื่องหมายว่าเสร็จสมบูรณ์"><i class="fa-solid fa-check"></i></button>`
                                    : '<button class="row-action approve" type="button" title="ต้องอนุมัติฉบับสมบูรณ์ก่อน" aria-label="ยังทำเป็นเสร็จสมบูรณ์ไม่ได้" disabled><i class="fa-solid fa-lock"></i></button>'}
                        </div>
                    </td>
                </tr>`).join(''));
            const table = App.enhanceTable('#projectsTable');
            $('#projectStatusFilter').off('change').on('change', function () {
                table.column(5).search($(this).val()).draw();
            });
        });
    }

    function loadDocuments(type) {
        $.when(loadLookups(), App.api('documents', { query: type ? { type } : {} })).done(function (_lookups, documentsResponse) {
            const rows = documentsResponse[0].data || [];
            const tableSelector = type ? '#documentStageTable' : '#documentsTable';
            $(tableSelector + ' tbody').html(rows.map((row) => type ? `
                <tr>
                    <td title="${App.escapeHtml(row.title)} — ${App.escapeHtml(row.filename)}"><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.filename)}</span></td>
                    <td title="${App.escapeHtml(studentName(row.student_id))}">${App.escapeHtml(studentName(row.student_id))}</td>
                    <td>${App.escapeHtml(row.size)}</td>
                    <td>${App.badge(row.status)}</td>
                    <td>${App.escapeHtml(row.uploaded_at)}</td>
                    <td class="text-end">
                        <div class="row-actions" role="group" aria-label="จัดการเอกสาร">
                            <button class="row-action view" data-action="preview-file" data-url="${App.url(`api/file.php?id=${encodeURIComponent(row.id)}`)}" title="ดูตัวอย่าง" aria-label="ดูตัวอย่าง"><i class="fa-solid fa-eye"></i></button>
                            <button class="row-action delete" data-action="delete-document" data-id="${row.id}" title="ลบ" aria-label="ลบ"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>` : `
                <tr>
                    <td><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.filename)}</span></td>
                    <td>${App.escapeHtml(row.type)}</td>
                    <td>${App.escapeHtml(projectName(row.project_id))}</td>
                    <td>${App.escapeHtml(row.size)}</td>
                    <td>${App.badge(row.status)}</td>
                    <td>${App.escapeHtml(row.uploaded_at)}</td>
                    <td class="text-end">${documentRow(row).match(/<td class="text-end">([\s\S]*)<\/td>/)?.[1] || ''}</td>
                </tr>`).join(''));
            App.enhanceTable(tableSelector);
            if (!type) {
                ['proposal', 'draft', 'complete'].forEach((key) => $(`[data-doc-count="${key}"]`).text(rows.filter((row) => row.type === key).length));
            }
        });
    }

    function initUpload() {
        $.when(loadLookups()).done(function () {
            const $file = $('#documentFile');
            const $drop = $('#dropZone');

            $drop.on('dragover', function (event) {
                event.preventDefault();
                $drop.addClass('drag-over');
            }).on('dragleave drop', function (event) {
                event.preventDefault();
                $drop.removeClass('drag-over');
                const files = event.originalEvent.dataTransfer?.files;
                if (files && files.length) {
                    $file[0].files = files;
                    App.toast(files[0].name, 'info');
                }
            });

            $('[data-action="preview-selected-file"]').on('click', function () {
                const file = $file[0].files[0];
                if (!file) {
                    App.toast('Choose a PDF first', 'info');
                    return;
                }
                $('#pdfPreviewFrame').attr('src', URL.createObjectURL(file));
                bootstrap.Modal.getOrCreateInstance(document.getElementById('filePreviewModal')).show();
            });

            $('#documentUploadForm').on('submit', function (event) {
                event.preventDefault();
                const formData = new FormData(this);
                const $bar = $('#uploadProgress');
                $.ajax({
                    url: 'api/index.php?resource=upload',
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function () {
                        const xhr = $.ajaxSettings.xhr();
                        xhr.upload.onprogress = function (event) {
                            if (event.lengthComputable) {
                                const percent = Math.round((event.loaded / event.total) * 100);
                                $bar.css('width', percent + '%').text(percent + '%');
                            }
                        };
                        return xhr;
                    }
                }).done(function (response) {
                    App.toast(response.message);
                    $bar.css('width', '0%').text('0%');
                    loadDocuments($('#documentUploadForm').data('type'));
                }).fail(function (xhr) {
                    App.toast(xhr.responseJSON?.message || 'Upload failed', 'error');
                });
            });
        });
    }

    function initBarcode() {
        function updateBarcodeProject() {
            const project = projects.find((row) => row.id === $('#barcodeProject').val());
            const available = !!project && project.barcode_available === true;
            $('#barcodeText').val(available ? project.code : '');
            $('#adminBarcodeLocked').toggleClass('d-none', available);
            if (!available) {
                const reason = !project
                    ? 'ไม่พบข้อมูลโครงงาน'
                    : !project.complete_approved
                        ? 'ยังสร้างบาร์โค้ดไม่ได้: ต้องส่งและอนุมัติฉบับสมบูรณ์ก่อน'
                        : 'ยังสร้างบาร์โค้ดไม่ได้: โครงงานยังไม่มีรหัสโครงงาน';
                $('#adminBarcodeLocked').text(reason);
            }
            $('[data-action="generate-barcode"], [data-action="print-barcode"], [data-action="download-barcode"]')
                .prop('disabled', !available)
                .toggleClass('disabled', !available);
            if (available) {
                renderBarcode(project.code);
            } else {
                $('#barcodeCanvas').empty();
                $('#barcodeLabel').text('');
            }
        }
        loadLookups().done(function () {
            const projectParam = new URLSearchParams(window.location.search).get('project');
            if (projectParam) {
                $('#barcodeProject').val(projectParam);
            }
            updateBarcodeProject();
        });
        $('#barcodeProject').on('change', updateBarcodeProject);
        $('[data-action="generate-barcode"]').on('click', () => renderBarcode($('#barcodeText').val()));
        $('[data-action="print-barcode"]').on('click', () => window.print());
        $('[data-action="download-barcode"]').on('click', downloadBarcodePng);
    }

    function renderBarcode(value) {
        const text = value || '';
        if (!text) {
            $('#barcodeCanvas').empty();
            $('#barcodeLabel').text('');
            return;
        }
        const bars = text.split('').map((char) => {
            const width = (char.charCodeAt(0) % 4) + 2;
            return `<span class="barcode-bar" style="width:${width}px"></span>`;
        }).join('');
        $('#barcodeCanvas').html(bars);
        $('#barcodeLabel').text(text);
    }

    function downloadBarcodePng() {
        const label = $('#barcodeLabel').text();
        const canvas = document.createElement('canvas');
        canvas.width = 720;
        canvas.height = 260;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
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
        link.download = `${label}.png`;
        link.href = canvas.toDataURL('image/png');
        link.click();
    }

    function initTimeline() {
        loadLookups().done(function () {
            const selected = new URLSearchParams(window.location.search).get('project') || projects[0]?.id;
            $('#timelineProject').val(selected);
            renderProjectTimeline(selected);
        });
        $('#timelineProject').on('change', function () {
            renderProjectTimeline($(this).val());
        });
    }

    function renderProjectTimeline(projectId) {
        const project = projects.find((row) => row.id === projectId) || projects[0] || {};
        const steps = [
            { step: 'Proposal', status: project.status === 'Draft' ? 'Pending' : 'Approved', reviewer: project.advisor_name, created_at: project.updated_at },
            { step: 'Draft', status: ['Approved', 'Completed'].includes(project.status) ? 'Approved' : 'Review', reviewer: project.advisor_name, created_at: project.updated_at },
            { step: 'Complete', status: project.status === 'Completed' ? 'Completed' : 'Pending', reviewer: 'Committee', created_at: project.updated_at }
        ];
        $('#projectTimeline').html(steps.map(timelineItem).join(''));
    }

    function loadReports() {
        App.api('reports').done(function (response) {
            const projects = response.data.projects;
            $('#reportsTable tbody').html(projects.map((row) => `
                <tr><td>${App.escapeHtml(row.code)}</td><td>${App.escapeHtml(row.title)}</td><td>${App.escapeHtml(row.student_name)}</td><td>${App.escapeHtml(row.advisor_name)}</td><td>${App.badge(row.status)}</td><td>${App.escapeHtml(row.progress)}%</td></tr>`).join(''));
            App.enhanceTable('#reportsTable');

            const statusCounts = countBy(projects, 'status');
            renderChart('reportStatusChart', 'pie', statusCounts);
            const docCounts = countBy(response.data.documents, 'type');
            renderChart('reportDocumentChart', 'bar', docCounts);
        });
    }

    function countBy(rows, key) {
        return rows.reduce((acc, row) => {
            acc[row[key]] = (acc[row[key]] || 0) + 1;
            return acc;
        }, {});
    }

    function renderChart(id, type, counts) {
        const ctx = document.getElementById(id);
        if (!ctx || !window.Chart) {
            return;
        }
        if (App.state.charts[id]) {
            App.state.charts[id].destroy();
        }
        App.state.charts[id] = new Chart(ctx, {
            type,
            data: { labels: Object.keys(counts), datasets: [{ data: Object.values(counts), backgroundColor: ['#0B3C8C', '#F4C542', '#0F7C9F', '#168A4A', '#64748B'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }

    function initImport() {
        let importRows = [];
        let importRequestId = 0;
        const $submit = $('[data-action="import-preview"]');

        $('#excelFile').on('change', async function () {
            const file = this.files[0];
            if (!file) return;
            const requestId = ++importRequestId;
            importRows = [];
            $submit.prop('disabled', true);
            updateImportSummary({ state: 'loading' });
            renderImportRows(importRows, 'กำลังเปรียบเทียบรายชื่อกับฐานข้อมูล...');
            try {
                const fileRows = await parseStudentImportFile(file);
                const response = await App.api('students');
                if (requestId !== importRequestId) return;
                if (!response || !Array.isArray(response.data)) {
                    throw new Error('ระบบไม่สามารถตรวจสอบรายชื่อเดิมจากฐานข้อมูลได้');
                }

                const currentStudents = response.data;
                const existingCodes = new Set(currentStudents.map((row) => normalizeImportCode(row.code)).filter(Boolean));
                const existingEmails = new Set(currentStudents.map((row) => normalizeImportEmail(row.email)).filter(Boolean));

                importRows = fileRows.filter((row) => {
                    const code = normalizeImportCode(row.code);
                    const email = normalizeImportEmail(row.email);
                    return !existingCodes.has(code) && !existingEmails.has(email);
                });

                const existingCount = fileRows.length - importRows.length;
                renderImportRows(importRows);
                $submit.prop('disabled', importRows.length === 0);
                updateImportSummary({
                    state: importRows.length ? 'ready' : 'empty',
                    total: fileRows.length,
                    existing: existingCount,
                    pending: importRows.length
                });
                App.toast(
                    importRows.length
                        ? `พบรายชื่อใหม่ ${importRows.length} จาก ${fileRows.length} รายการ สามารถเพิ่มเบอร์โทรภายหลังได้`
                        : `รายชื่อทั้ง ${fileRows.length} รายการมีอยู่ในระบบแล้ว`,
                    'info'
                );
            } catch (error) {
                if (requestId !== importRequestId) return;
                importRows = [];
                renderImportRows(importRows, 'ไม่สามารถเปรียบเทียบรายชื่อกับฐานข้อมูลได้ กรุณาลองใหม่');
                $submit.prop('disabled', true);
                updateImportSummary({ state: 'error' });
                const message = error?.responseJSON?.message || error?.message || 'ไม่สามารถอ่านไฟล์หรือเปรียบเทียบฐานข้อมูลได้';
                if (typeof error?.status !== 'number') App.toast(message, 'error');
            }
        });
        $('[data-action="download-sample-csv"]').on('click', function () {
            App.downloadCsv('student-import-sample.csv', [{
                code: '076760305001-8', first_name: 'สุขุม', last_name: 'พวงแสงเพ็ญ',
                email: '0767603050018@rmutp.com', phone: '0812345678', year_level: 3,
                faculty: businessFaculty, major: businessMajors[5]
            }]);
        });
        $('[data-action="import-preview"]').on('click', function () {
            const invalidPhone = importRows.findIndex((row) => row.phone && !/^\d{9,10}$/.test(row.phone));
            if (invalidPhone >= 0) {
                App.toast(`เบอร์โทรในรายการที่ ${invalidPhone + 1} ต้องเป็นตัวเลข 9-10 หลัก หรือเว้นว่างไว้`, 'error');
                return;
            }
            App.showLoader(true);
            App.api('import', { method: 'POST', data: { rows: importRows } }).done((response) => {
                App.toast(response.message);
                window.setTimeout(() => {
                    window.location.href = App.url('admin/students/index.php');
                }, 900);
            }).always(() => App.showLoader(false));
        });
        $(document).on('input', '#importPreviewTable [data-import-phone]', function () {
            const index = Number($(this).data('import-phone'));
            this.value = String(this.value).replace(/\D/g, '').slice(0, 10);
            if (importRows[index]) importRows[index].phone = this.value;
        });
        renderImportRows(importRows, 'เลือกไฟล์ Excel หรือ CSV เพื่อดูรายชื่อที่ยังไม่มีในระบบ');
    }

    function normalizeImportCode(value) {
        return String(value || '').replace(/\D/g, '');
    }

    function normalizeImportEmail(value) {
        return String(value || '').trim().toLowerCase();
    }

    function updateImportSummary({ state = 'idle', total = 0, existing = 0, pending = 0 } = {}) {
        const $summary = $('#importReconcileSummary');
        if (!$summary.length) return;

        $summary.removeClass('is-idle is-loading is-ready is-empty is-error').addClass(`is-${state}`);
        if (state === 'loading') {
            $summary.html('<i class="fa-solid fa-circle-notch fa-spin" aria-hidden="true"></i><span>กำลังตรวจสอบรายชื่อกับฐานข้อมูล...</span>');
            return;
        }
        if (state === 'error') {
            $summary.html('<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><span>เปรียบเทียบฐานข้อมูลไม่สำเร็จ จึงยังไม่สามารถยืนยันนำเข้าได้</span>');
            return;
        }
        if (state === 'idle') {
            $summary.html('<span>เลือกไฟล์เพื่อเปรียบเทียบกับฐานข้อมูล</span>');
            return;
        }

        $summary.html(`
            <span class="import-count-item">พบในไฟล์ <strong>${App.escapeHtml(total)}</strong> รายการ</span>
            <span class="import-count-separator" aria-hidden="true">•</span>
            <span class="import-count-item import-count-existing">มีในระบบแล้ว <strong>${App.escapeHtml(existing)}</strong> รายการ</span>
            <span class="import-count-separator" aria-hidden="true">•</span>
            <span class="import-count-item import-count-pending">รอเพิ่ม <strong>${App.escapeHtml(pending)}</strong> รายการ</span>
            ${state === 'empty' ? '<span class="import-all-current"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> ข้อมูลเป็นปัจจุบันแล้ว</span>' : ''}
        `);
    }

    function renderImportRows(rows, emptyMessage = 'รายชื่อทั้งหมดในไฟล์มีอยู่ในระบบแล้ว ไม่มีรายการที่ต้องเพิ่ม') {
        const existingTable = App.state.tables['#importPreviewTable'];
        if (existingTable) existingTable.destroy();
        $('#importPreviewTable tbody').html(rows.map((row, index) => `<tr>
            <td><strong>${App.escapeHtml(row.code)}</strong></td>
            <td>${App.escapeHtml(`${row.first_name} ${row.last_name}`)}</td>
            <td>${App.escapeHtml(row.email)}</td>
            <td><input class="form-control form-control-sm" type="tel" inputmode="numeric" maxlength="10" data-import-phone="${index}" value="${App.escapeHtml(row.phone || '')}" placeholder="เพิ่มภายหลังได้" aria-label="เบอร์โทร ${App.escapeHtml(row.code)} (ไม่บังคับ)"></td>
            <td>${App.escapeHtml(row.year_level)}</td>
            <td>${App.escapeHtml(row.status_label)}</td>
        </tr>`).join(''));
        App.enhanceTable('#importPreviewTable', { responsive: false, autoWidth: false, scrollX: true });
        if (!rows.length) $('#importPreviewTable tbody .dataTables_empty').text(emptyMessage);
    }

    function parseStudentName(fullName) {
        const cleaned = String(fullName || '').trim().replace(/^(นาย|นางสาว|นาง)\s*/, '');
        const parts = cleaned.split(/\s+/).filter(Boolean);
        return { first_name: parts.shift() || '', last_name: parts.join(' ') };
    }

    function studentYearFromCode(code) {
        const digits = String(code || '').replace(/\D/g, '');
        const entryYear = 2500 + Number(digits.slice(2, 4));
        const currentBuddhistYear = new Date().getFullYear() + 543;
        return Math.max(1, currentBuddhistYear - entryYear + 1);
    }

    function importedStudentStatus(code) {
        const value = String(code || '').trim();
        if (value === '40') return { status: 'Completed', label: 'สำเร็จการศึกษา' };
        if (['10', '11', '12', '13', '14', '15', '16', '84'].includes(value)) {
            return { status: 'Active', label: 'กำลังศึกษา' };
        }
        return { status: 'Inactive', label: `ไม่ใช้งาน (${value || '-'})` };
    }

    function buildImportedStudent(code, fullName, statusCode, faculty = businessFaculty, major = businessMajors[5]) {
        const normalizedCode = String(code || '').trim();
        const name = parseStudentName(fullName);
        const studentStatus = importedStudentStatus(statusCode);
        return {
            code: normalizedCode,
            first_name: name.first_name,
            last_name: name.last_name,
            email: `${normalizedCode.replace(/\D/g, '')}@rmutp.com`,
            phone: '',
            faculty,
            major,
            year_level: studentYearFromCode(normalizedCode),
            status: studentStatus.status,
            status_label: studentStatus.label
        };
    }

    function parseCsvLine(line) {
        const cells = [];
        let value = '';
        let quoted = false;
        for (let index = 0; index < line.length; index += 1) {
            const character = line[index];
            if (character === '"' && quoted && line[index + 1] === '"') {
                value += '"';
                index += 1;
            } else if (character === '"') {
                quoted = !quoted;
            } else if (character === ',' && !quoted) {
                cells.push(value.trim());
                value = '';
            } else {
                value += character;
            }
        }
        cells.push(value.trim());
        return cells;
    }

    async function parseStudentImportFile(file) {
        const filename = file.name.toLowerCase();
        if (filename.endsWith('.csv')) {
            const text = await file.text();
            const lines = text.replace(/^\uFEFF/, '').split(/\r?\n/).filter((line) => line.trim() !== '');
            const headers = parseCsvLine(lines.shift() || '').map((header) => header.toLowerCase());
            return lines.map(parseCsvLine).map((cells) => Object.fromEntries(headers.map((header, index) => [header, cells[index] || ''])))
                .filter((row) => /^\d{12}-\d$/.test(row.code || ''))
                .map((row) => {
                    const imported = buildImportedStudent(row.code, `${row.first_name || ''} ${row.last_name || ''}`, row.status_code || '10', row.faculty || businessFaculty, row.major || businessMajors[5]);
                    imported.phone = String(row.phone || '').replace(/\D/g, '').slice(0, 10);
                    if (Number(row.year_level) > 0) imported.year_level = Number(row.year_level);
                    return imported;
                });
        }
        if (!filename.endsWith('.xls')) throw new Error('รองรับเฉพาะไฟล์ .xls หรือ .csv');

        const buffer = await file.arrayBuffer();
        const html = new TextDecoder('windows-874').decode(buffer);
        if (!/<table\b/i.test(html)) throw new Error('ไฟล์ .xls นี้ไม่ใช่แบบฟอร์มตารางที่ระบบรองรับ');
        const documentNode = new DOMParser().parseFromString(html, 'text/html');
        const faculty = businessFaculty;
        const major = businessMajors.find((item) => item.includes('ระบบสารสนเทศและนวัตกรรมดิจิทัล')) || businessMajors[5];
        return Array.from(documentNode.querySelectorAll('tr')).map((tr) =>
            Array.from(tr.querySelectorAll('th,td')).map((cell) => (cell.textContent || '').replace(/\s+/g, ' ').trim())
        ).filter((cells) => /^\d{12}-\d$/.test(cells[1] || ''))
            .map((cells) => buildImportedStudent(cells[1], cells[2], cells[3], faculty, major));
    }

    function initProfile() {
        App.api('profile').done(function (response) {
            const profile = response.data;
            $('#profileName, #profileNamePreview').val(profile.name).text(profile.name);
            $('#profileEmail').val(profile.email);
            $('#profileRole, #profileRolePreview').val(profile.role).text(profile.role);
            $('#profileAvatar').attr('src', App.url(profile.avatar || 'assets/img/profile-admin.svg'));
            $('#profileAvatarInput').val(profile.avatar || '');
        });
        $('#profileForm').on('submit', function (event) {
            event.preventDefault();
            App.api('profile', { method: 'POST', data: App.formToObject($(this)) }).done((response) => {
                App.toast(response.message);
                $('#profileNamePreview').text(response.data.name);
                $('#profileRolePreview').text(response.data.role);
                $('#profileAvatar').attr('src', App.url(response.data.avatar || 'assets/img/profile-admin.svg'));
            });
        });
    }

    function initSettings() {
        App.api('settings').done(function (response) {
            const settings = response.data;
            $('#systemName').val(settings.system_name);
            $('#academicYear').val(settings.academic_year);
            $('#approvalMode').val(settings.approval_mode);
            $('#notificationRefresh').val(settings.notification_refresh);
        });
        $('#settingsForm').on('submit', function (event) {
            event.preventDefault();
            App.api('settings', { method: 'POST', data: App.formToObject($(this)) }).done((response) => App.toast(response.message));
        });
        $('#themePreference').on('change', function () {
            $('body').toggleClass('high-contrast', $(this).val() === 'contrast');
            App.toast('Theme updated', 'info');
        });
    }

    $(document).on('click', '[data-action="delete-student"]', function () {
        const id = $(this).data('id');
        App.confirmAction('ลบนักศึกษา?', 'บัญชีนักศึกษาจะถูกลบออกจากฐานข้อมูล').then((result) => {
            if (result.isConfirmed) {
                App.api('students', { method: 'DELETE', query: { id } }).done((response) => {
                    App.toast(response.message);
                    loadStudentsTable();
                });
            }
        });
    });

    $(document).on('click', '[data-action="delete-advisor"]', function () {
        const id = $(this).data('id');
        App.confirmAction('ลบอาจารย์?', 'บัญชีอาจารย์จะถูกลบออกจากฐานข้อมูล และถอดออกจากโครงงานที่เกี่ยวข้อง').then((result) => {
            if (!result.isConfirmed) return;
            App.api('advisors', { method: 'DELETE', query: { id } }).done((response) => {
                App.toast(response.message);
                loadAdvisorsTable();
            });
        });
    });

    $(document).on('click', '[data-action="delete-document"]', function () {
        const id = $(this).data('id');
        App.confirmAction('Delete file?', 'The document record will be removed.').then((result) => {
            if (result.isConfirmed) {
                App.api('documents', { method: 'DELETE', query: { id } }).done((response) => {
                    App.toast(response.message);
                    const type = $('#documentStageTable').data('type') || null;
                    loadDocuments(type);
                });
            }
        });
    });

    $(document).on('click', '[data-action="complete-project"]', function () {
        const id = $(this).data('id');
        if (!String(id).startsWith('PRJ')) {
            App.toast('ไม่พบรหัสโครงงานที่ถูกต้อง', 'error');
            return;
        }
        App.api('projects', { method: 'POST', query: { action: 'status' }, data: { id, status: 'Completed' } }).done(function (response) {
            App.toast(response.message);
            loadProjectsTable();
        });
    });

    $(document).on('click', '[data-action="add-comment"]', function () {
        Swal.fire({
            title: 'Add Comment',
            input: 'textarea',
            inputPlaceholder: 'Comment',
            showCancelButton: true,
            confirmButtonColor: '#0B3C8C'
        }).then((result) => {
            if (!result.isConfirmed || !result.value) {
                return;
            }
            App.api('comments', { method: 'POST', data: { student_id: $('#studentDetailId').val(), message: result.value } }).done(function (response) {
                App.toast(response.message);
                loadStudentDetail();
            });
        });
    });

    $(document).on('click', '[data-action="add-advisor"]', function (event) {
        event.preventDefault();
        Swal.fire({
            title: '<span class="advisor-modal-title"><i class="fa-solid fa-user-tie"></i> เพิ่มอาจารย์</span>',
            html: `<div class="advisor-modal-form">
                <p class="advisor-modal-subtitle">กรอกข้อมูลเพื่อสร้างบัญชีอาจารย์ในระบบ</p>
                <label for="advisorNameSwal">ชื่อ-นามสกุล <span>*</span></label>
                <div class="advisor-modal-control"><i class="fa-regular fa-user"></i><input id="advisorNameSwal" type="text" placeholder="เช่น ผศ.ดร. สมชาย ใจดี" autocomplete="name"></div>
                <label for="advisorEmailSwal">อีเมล <span>*</span></label>
                <div class="advisor-modal-control"><i class="fa-regular fa-envelope"></i><input id="advisorEmailSwal" type="email" placeholder="advisor@rmutp.ac.th" autocomplete="email"></div>
                <label for="advisorFacultySwal">คณะ <span>*</span></label>
                <div class="advisor-modal-control"><i class="fa-solid fa-building-columns"></i><select id="advisorFacultySwal"><option value="${businessFaculty}">${businessFaculty}</option></select></div>
                <label for="advisorDeptSwal">สาขา / ภาควิชา <span>*</span></label>
                <div class="advisor-modal-control"><i class="fa-solid fa-graduation-cap"></i><select id="advisorDeptSwal">${businessMajors.map((major) => `<option value="${App.escapeHtml(major)}">${App.escapeHtml(major)}</option>`).join('')}</select></div>
                <label for="advisorPasswordSwal">รหัสผ่าน <span>*</span></label>
                <div class="advisor-modal-control"><i class="fa-solid fa-lock"></i><input id="advisorPasswordSwal" type="password" minlength="8" autocomplete="new-password" placeholder="อย่างน้อย 8 ตัวอักษร"><button id="advisorPasswordToggle" type="button" aria-label="แสดงหรือซ่อนรหัสผ่าน"><i class="fa-regular fa-eye"></i></button></div>
            </div>`,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: '<i class="fa-solid fa-user-plus"></i> เพิ่มอาจารย์',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#0B3C8C',
            cancelButtonColor: '#E8EEF7',
            buttonsStyling: true,
            customClass: {
                popup: 'advisor-create-modal',
                confirmButton: 'advisor-modal-confirm',
                cancelButton: 'advisor-modal-cancel',
                actions: 'advisor-modal-actions'
            },
            didOpen: () => {
                $('#advisorNameSwal').trigger('focus');
                $('#advisorPasswordToggle').on('click', function () {
                    const input = document.getElementById('advisorPasswordSwal');
                    const show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    $(this).find('i').toggleClass('fa-eye', !show).toggleClass('fa-eye-slash', show);
                });
            },
            preConfirm: () => {
                const advisor = {
                    name: $('#advisorNameSwal').val().trim(),
                    email: $('#advisorEmailSwal').val().trim(),
                    faculty: $('#advisorFacultySwal').val(),
                    department: $('#advisorDeptSwal').val().trim(),
                    password: $('#advisorPasswordSwal').val(),
                    phone: '',
                    status: 'Active'
                };
                if (!advisor.name || !advisor.department || !/^\S+@\S+\.\S+$/.test(advisor.email)) {
                    Swal.showValidationMessage('กรุณากรอกชื่อ สาขา และอีเมลให้ถูกต้อง');
                    return false;
                }
                if (advisor.password.length < 8) {
                    Swal.showValidationMessage('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
                    return false;
                }
                return advisor;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                App.api('advisors', { method: 'POST', data: result.value }).done((response) => {
                    App.toast(response.message);
                    loadAdvisorsTable();
                });
            }
        });
    });

    $(document).on('click', '[data-action="show-advisor"]', function () {
        const advisor = advisors.find((row) => row.id === $(this).data('id'));
        $('#recordModalTitle').text(advisor?.name || 'Advisor');
        $('#recordModalBody').html(`<p><strong>Email:</strong> ${App.escapeHtml(advisor?.email || '')}</p><p><strong>Department:</strong> ${App.escapeHtml(advisor?.department || '')}</p><p><strong>Students:</strong> ${App.escapeHtml(advisor?.students || 0)}</p>`);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('recordModal')).show();
    });

    $(document).on('click', '[data-action="refresh-reports"]', loadReports);

    $(function () {
        const page = $('body').data('page');
        if (String(page || '').startsWith('portal-') || String(page || '').startsWith('advisor-') || page === 'login') return;
        loadNavbarProfile();
        if (page === 'students') loadStudentsTable();
        if (page === 'student-add' || page === 'student-edit') initStudentForm();
        if (page === 'student-detail') loadStudentDetail();
        if (page === 'advisors') { loadLookups().done(loadAdvisorsTable); }
        if (page === 'projects') loadProjectsTable();
        if (page === 'documents') loadDocuments();
        if (['proposal', 'draft', 'complete'].includes(page)) { initUpload(); loadDocuments(page); }
        if (page === 'barcode') initBarcode();
        if (page === 'timeline') initTimeline();
        if (page === 'reports') loadReports();
        if (page === 'import-excel') initImport();
        if (page === 'profile') initProfile();
        if (page === 'settings') initSettings();
    });
})(jQuery);
