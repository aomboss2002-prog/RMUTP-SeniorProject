(function ($) {
    'use strict';

    let advisors = [];
    let students = [];
    let projects = [];
    const businessFaculty = 'คณะบริหารธุรกิจ';
    const businessMajors = [
        'บธ.บ. สาขาวิชาระบบสารสนเทศและนวัตกรรมดิจิทัล'
    ];

    function loadLookups() {
        const page = String($('body').data('page') || '');
        const requests = [];
        if (page === 'students' || $('#advisorInput').length) {
            requests.push(App.api('advisors').done((response) => { advisors = response.data || []; }));
        }
        if (page === 'students' || $('#uploadStudent').length) {
            requests.push(App.api('students').done((response) => { students = response.data || []; }));
        }
        if (page === 'documents' || $('#uploadProject, #projectInput, #barcodeProject, #timelineProject').length) {
            requests.push(App.api('projects').done((response) => { projects = response.data || []; }));
        }
        return $.when.apply($, requests).done(function () {
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

    function studentAcademicStatus(value) {
        if (['Completed', 'Inactive'].includes(value)) return value;
        if (!value || ['Active', 'Pending', 'Draft', 'Review', 'Approved', 'New'].includes(value)) return 'Active';
        return value;
    }

    function studentStatusBadge(value, type = 'display') {
        const status = studentAcademicStatus(value);
        const labels = { Active: 'กำลังศึกษา', Completed: 'สำเร็จการศึกษา', Inactive: 'ไม่ใช้งาน' };
        const label = labels[status] || 'ไม่ระบุ';
        if (type !== 'display') return label;
        const color = { Active: 'primary', Completed: 'success', Inactive: 'secondary' }[status] || 'secondary';
        return `<span class="badge rounded-pill text-bg-${color}">${label}</span>`;
    }

    function loadStudentsTable() {
        if ($.fn.DataTable.isDataTable('#studentsTable')) {
            $('#studentsTable').DataTable().ajax.reload(null, false);
            return;
        }
        const textColumn = (key) => ({ data: key, render: (value) => App.escapeHtml(value) });
        const table = App.enhanceTable('#studentsTable', {
            serverSide: true,
            processing: true,
            pageLength: 25,
            lengthMenu: [25, 50],
            searchDelay: 350,
            order: [[0, 'asc']],
            // The header's full export remains available; these export the visible page.
            buttons: ['copy', 'csv', 'excel', 'print'].map((extend) => ({
                extend, text: `${extend.toUpperCase()} (หน้านี้)`,
                exportOptions: { columns: [0, 1, 2, 3, 4], modifier: { page: 'current' } }
            })),
            ajax: (request, callback) => {
                App.api('students', { query: {
                    action: 'page', draw: request.draw, start: request.start, length: request.length,
                    search_text: request.search.value, status: $('#studentStatusFilter').val() || '',
                    sort_column: request.order[0]?.column ?? 0,
                    sort_direction: request.order[0]?.dir ?? 'asc'
                } }).done(callback).fail(() => callback({
                    draw: request.draw, recordsTotal: 0, recordsFiltered: 0, data: [],
                    error: 'โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่'
                }));
            },
            columns: [
                textColumn('code'),
                { data: null, render: (row) => `<strong>${App.escapeHtml(row.first_name)} ${App.escapeHtml(row.last_name)}</strong><span class="d-block text-muted">${App.escapeHtml(row.email)}</span>` },
                textColumn('major'), textColumn('advisor_name'),
                { data: 'status', render: (value, type) => studentStatusBadge(value, type) },
                { data: null, orderable: false, searchable: false, className: 'text-end', render: (row) => {
                    const id = App.escapeHtml(encodeURIComponent(row.id));
                    return `
                        <div class="row-actions" role="group" aria-label="จัดการนักศึกษา">
                            <a class="row-action view" href="${App.url(`admin/students/detail.php?id=${id}`)}" title="ดูรายละเอียด" aria-label="ดูรายละเอียด"><i class="fa-solid fa-eye"></i></a>
                            <a class="row-action edit" href="${App.url(`admin/students/edit.php?id=${id}`)}" title="แก้ไข" aria-label="แก้ไข"><i class="fa-solid fa-pen"></i></a>
                            <button class="row-action delete" type="button" data-action="delete-student" data-id="${App.escapeHtml(row.id)}" title="ลบ" aria-label="ลบ"><i class="fa-solid fa-trash"></i></button>
                        </div>`;
                } }
            ]
        });
        $('#studentStatusFilter').off('change').on('change', () => table.ajax.reload());
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
                    $form.find('[name="status"]').val(studentAcademicStatus(row.status));
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
            $('#studentStatus').html(studentStatusBadge(student.status));
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
            if ($.fn.DataTable.isDataTable('#advisorsTable')) $('#advisorsTable').DataTable().destroy();
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

    function pagedAdminTable(selector, resource, renderRow, extraQuery = {}, filterSelector = '') {
        if ($.fn.DataTable.isDataTable(selector)) {
            $(selector).DataTable().ajax.reload(null, false);
            return;
        }
        const columnCount = $(`${selector} thead th`).length;
        const table = App.enhanceTable(selector, {
            serverSide: true, processing: true, pageLength: 25, lengthMenu: [25, 50], searchDelay: 350,
            order: [[0, 'asc']], columnDefs: [{ targets: columnCount - 1, orderable: false, searchable: false }],
            buttons: [
                ...['copy', 'csv', 'excel', 'print'].map((extend) => ({
                    extend, text: `${extend.toUpperCase()} (หน้านี้)`,
                    exportOptions: { columns: Array.from({ length: columnCount - 1 }, (_, i) => i), modifier: { page: 'current' } }
                })),
                { text: 'CSV ทั้งหมด', action: () => App.api('export', { query: { kind: resource } }).done((response) => {
                    const rows = extraQuery.type ? response.data.filter((row) => row.type === extraQuery.type) : response.data;
                    App.downloadCsv(`${resource}.csv`, rows);
                }) }
            ],
            ajax: (request, callback) => {
                App.api(resource, { query: {
                    ...extraQuery, action: 'page', draw: request.draw, start: request.start, length: request.length,
                    search_text: request.search.value, status: filterSelector ? $(filterSelector).val() : '',
                    sort_column: request.order[0]?.column ?? 0, sort_direction: request.order[0]?.dir ?? 'asc'
                } }).done((response) => {
                    if (resource === 'documents' && response.counts) {
                        ['proposal', 'draft', 'complete'].forEach((key) => $(`[data-doc-count="${key}"]`).text(response.counts[key] || 0));
                    }
                    const data = response.data.map((row) => $(renderRow(row)).children('td').map(function () { return this.innerHTML; }).get());
                    callback({ ...response, data });
                    if (!data.length && response.recordsFiltered > 0 && request.start >= response.recordsFiltered) {
                        table.page('last').draw('page');
                    }
                }).fail(() => callback({ draw: request.draw, data: [], recordsTotal: 0, recordsFiltered: 0,
                    error: 'โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่' }));
            }
        });
        if (filterSelector) $(filterSelector).off('change').on('change', () => table.ajax.reload());
    }

    function loadProjectsTable() {
        loadProjectDeletionJobs();
        pagedAdminTable('#projectsTable', 'projects', (row) => `
                <tr>
                    <td>${App.escapeHtml(row.code)}</td>
                    <td title="${App.escapeHtml(row.title)}"><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.category)}</span></td>
                    <td title="${App.escapeHtml(row.student_name)}">${App.escapeHtml(row.student_name)}</td>
                    <td title="${App.escapeHtml(row.advisor_name)}">${App.escapeHtml(row.advisor_name)}</td>
                    <td><div class="progress"><div class="progress-bar" style="width:${Number(row.progress) || 0}%">${App.escapeHtml(row.progress)}%</div></div></td>
                    <td data-search="${App.escapeHtml(row.status)}">${App.badge(row.status)}</td>
                    <td class="text-end">
                        <div class="row-actions" role="group" aria-label="จัดการโครงงาน">
                            <button class="row-action delete" type="button" data-action="delete-project" data-id="${App.escapeHtml(row.id)}" data-title="${App.escapeHtml(row.title)}" title="ลบโครงงานพร้อมเอกสาร" aria-label="ลบโครงงานพร้อมเอกสาร"><i class="fa-solid fa-trash"></i></button>
                            <a class="row-action view" href="${App.escapeHtml(App.url(`admin/page.php?view=timeline&project=${encodeURIComponent(row.id)}`))}" title="ดูไทม์ไลน์" aria-label="ดูไทม์ไลน์"><i class="fa-solid fa-timeline"></i></a>
                            <a class="row-action edit" href="${App.escapeHtml(App.url(`admin/page.php?view=barcode&project=${encodeURIComponent(row.id)}`))}" title="ดูบาร์โค้ด" aria-label="ดูบาร์โค้ด"><i class="fa-solid fa-barcode"></i></a>
                            ${row.status === 'Completed' && row.complete_approved
                                ? '<button class="row-action approve" type="button" title="เสร็จสมบูรณ์แล้ว" aria-label="เสร็จสมบูรณ์แล้ว" disabled><i class="fa-solid fa-check"></i></button>'
                                : row.complete_approved
                                    ? `<button class="row-action approve" type="button" data-action="complete-project" data-id="${App.escapeHtml(row.id)}" title="ทำเครื่องหมายว่าเสร็จสมบูรณ์" aria-label="ทำเครื่องหมายว่าเสร็จสมบูรณ์"><i class="fa-solid fa-check"></i></button>`
                                    : '<button class="row-action approve" type="button" title="ต้องอนุมัติฉบับสมบูรณ์ก่อน" aria-label="ยังทำเป็นเสร็จสมบูรณ์ไม่ได้" disabled><i class="fa-solid fa-lock"></i></button>'}
                        </div>
                    </td>
                </tr>`, {}, '#projectStatusFilter');
    }

    function loadDocuments(type) {
        const tableSelector = type ? '#documentStageTable' : '#documentsTable';
        pagedAdminTable(tableSelector, 'documents', (row) => type ? `
                <tr>
                    <td title="${App.escapeHtml(row.title)} — ${App.escapeHtml(row.filename)}"><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.filename)}</span></td>
                    <td title="${App.escapeHtml(row.student_name)}">${App.escapeHtml(row.student_name)}</td>
                    <td>${App.escapeHtml(row.size)}</td>
                    <td>${App.badge(row.status)}</td>
                    <td>${App.escapeHtml(row.uploaded_at)}</td>
                    <td class="text-end">
                        <div class="row-actions" role="group" aria-label="จัดการเอกสาร">
                            <button class="row-action view" data-action="preview-file" data-url="${App.url(`api/file.php?id=${encodeURIComponent(row.id)}`)}" title="ดูตัวอย่าง" aria-label="ดูตัวอย่าง"><i class="fa-solid fa-eye"></i></button>
                            <button class="row-action delete" data-action="delete-document" data-id="${App.escapeHtml(row.id)}" title="ลบ" aria-label="ลบ"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>` : `
                <tr>
                    <td><strong>${App.escapeHtml(row.title)}</strong><span class="d-block text-muted">${App.escapeHtml(row.filename)}</span></td>
                    <td>${App.escapeHtml(row.type)}</td>
                    <td>${App.escapeHtml(row.project_title)}</td>
                    <td>${App.escapeHtml(row.size)}</td>
                    <td>${App.badge(row.status)}</td>
                    <td>${App.escapeHtml(row.uploaded_at)}</td>
                    <td class="text-end">${documentRow(row).match(/<td class="text-end">([\s\S]*)<\/td>/)?.[1] || ''}</td>
                </tr>`, type ? { type } : {});
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

            $('#documentUploadForm').on('submit', async function (event) {
                event.preventDefault();
                const $submit = $(this).find('[type="submit"]');
                if ($submit.prop('disabled')) return;
                const formData = new FormData(this);
                const $bar = $('#uploadProgress');
                const file = $file[0].files[0];
                if (!file || !/\.pdf$/i.test(file.name) || file.size < 1 || file.size > 20 * 1024 * 1024) {
                    App.toast('กรุณาเลือกไฟล์ PDF ขนาดไม่เกิน 20 MB', 'error');
                    return;
                }
                $submit.prop('disabled', true);
                try {
                    let response;
                    if ($('meta[name="storage-driver"]').attr('content') === 'vercel_blob') {
                        await loadAdminBlobUploader();
                        const stage = formData.get('type');
                        const prefix = ($('meta[name="blob-path-prefix"]').attr('content') || 'rmutp').replace(/^\/+|\/+$/g, '');
                        const pathname = `${prefix}/${stage}/${crypto.randomUUID()}.pdf`;
                        const blob = await window.RmutpBlobUpload({ file, pathname, payload: { kind: 'document', stage },
                            onProgress: ({ percentage }) => $bar.css('width', `${percentage}%`).text(`${Math.round(percentage)}%`) });
                        formData.delete('file');
                        formData.set('blob_pathname', blob.pathname);
                        formData.set('original_name', file.name);
                    }
                    response = await App.api('upload', { method: 'POST', formData,
                        xhr: function () {
                            const xhr = $.ajaxSettings.xhr();
                            if (!formData.has('blob_pathname')) xhr.upload.onprogress = event => {
                                if (event.lengthComputable) {
                                    const percent = Math.round(event.loaded / event.total * 100);
                                    $bar.css('width', `${percent}%`).text(`${percent}%`);
                                }
                            };
                            return xhr;
                        }
                    });
                    App.toast(response.message);
                    $bar.css('width', '0%').text('0%');
                    loadDocuments($('#documentUploadForm').data('type'));
                } catch (error) {
                    if (!error?.responseJSON) App.toast(error?.message || 'อัปโหลดไม่สำเร็จ', 'error');
                } finally {
                    $submit.prop('disabled', false);
                }
            });
        });
    }

    let adminBlobPromise;
    function loadAdminBlobUploader() {
        if (typeof window.RmutpBlobUpload === 'function') return Promise.resolve();
        if (!adminBlobPromise) adminBlobPromise = new Promise((resolve, reject) => {
            const url = $('meta[name="blob-upload-script"]').attr('content');
            if (!url) return reject(new Error('ไม่พบโมดูลอัปโหลด Cloud'));
            const script = document.createElement('script');
            script.src = url;
            script.onload = () => typeof window.RmutpBlobUpload === 'function' ? resolve() : reject(new Error('โมดูลอัปโหลดไม่พร้อม'));
            script.onerror = () => { script.remove(); reject(new Error('โหลดโมดูลอัปโหลดไม่สำเร็จ')); };
            document.head.appendChild(script);
        }).catch(error => { adminBlobPromise = null; throw error; });
        return adminBlobPromise;
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

    let timelineRevision = 0;
    function renderProjectTimeline(projectId) {
        const revision = ++timelineRevision;
        const $timeline = $('#projectTimeline');
        $timeline.text(projectId ? 'กำลังโหลดประวัติ...' : 'ยังไม่มีโครงงาน');
        if (!projectId) return;
        App.api('timeline', { query: { project_id: projectId } }).done(response => {
            if (revision !== timelineRevision) return;
            $timeline.html(response.data.map(row => `<div class="timeline-item"><strong>${App.escapeHtml(row.step)}</strong>
                <span class="d-block text-muted">${App.escapeHtml(row.reviewer)} · ${App.escapeHtml(row.created_at)}</span>
                ${row.status ? App.badge(row.status) : ''}<span class="d-block">ความก้าวหน้า ${App.escapeHtml(row.progress)}%</span></div>`).join('')
                || '<p class="text-muted">ยังไม่มีประวัติที่บันทึกไว้ ไม่สามารถสรุปวันส่งหรือวันอนุมัติย้อนหลังได้</p>');
        }).fail(() => { if (revision === timelineRevision) $timeline.text('โหลดประวัติไม่สำเร็จ กรุณาลองใหม่'); });
    }

    let reportsRevision = 0;
    function loadReports() {
        const from = $('#reportFrom').val() || '';
        const to = $('#reportTo').val() || '';
        const revision = ++reportsRevision;
        if (from && to && from > to) {
            App.toast('วันที่เริ่มต้นต้องไม่เกินวันที่สิ้นสุด', 'error');
            return;
        }
        App.api('reports', { query: { from, to } }).done(function (response) {
            if (revision !== reportsRevision) return;
            const projects = response.data.projects;
            if ($.fn.DataTable.isDataTable('#reportsTable')) $('#reportsTable').DataTable().destroy();
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
                email: '0767603050018@rmutp.ac.th', phone: '0812345678', year_level: 3,
                faculty: businessFaculty, major: businessMajors[0]
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
        $(document).on('change', '#importPreviewTable [data-import-status]', function () {
            const index = Number($(this).data('import-status'));
            if (!importRows[index]) return;
            const selected = importedStudentStatus(this.value);
            importRows[index].status = selected.status;
            importRows[index].status_label = selected.label;
            this.value = selected.status;
            Array.from(this.options).forEach(option => { option.defaultSelected = option.value === selected.status; });
            $(this).closest('td').attr('data-search', selected.label).attr('data-order', selected.label);
            const table = App.state.tables['#importPreviewTable'];
            if (table) table.row($(this).closest('tr')).invalidate('dom');
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
            <td data-search="${App.escapeHtml(row.status_label)}" data-order="${App.escapeHtml(row.status_label)}">
                <select class="form-select form-select-sm" style="min-width: 155px" data-import-status="${index}" aria-label="สถานะนักศึกษา ${App.escapeHtml(row.code)}">
                    ${['Active', 'Completed', 'Inactive'].map(status => `<option value="${status}"${row.status === status ? ' selected' : ''}>${importedStudentStatus(status).label}</option>`).join('')}
                </select>
            </td>
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

    function importedStudentStatus(value) {
        if (value === 'Completed') return { status: 'Completed', label: 'สำเร็จการศึกษา' };
        if (value === 'Inactive') return { status: 'Inactive', label: 'ไม่ใช้งาน' };
        return { status: 'Active', label: 'กำลังศึกษา' };
    }

    function buildImportedStudent(code, fullName, statusCode, faculty = businessFaculty, major = businessMajors[0]) {
        const normalizedCode = String(code || '').trim();
        const name = parseStudentName(fullName);
        // Every newly selected file starts as Active, regardless of its legacy status code.
        // The administrator can then choose a different status in the preview.
        const studentStatus = importedStudentStatus('Active');
        return {
            code: normalizedCode,
            first_name: name.first_name,
            last_name: name.last_name,
            email: `${normalizedCode.replace(/\D/g, '')}@rmutp.ac.th`,
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
                    const imported = buildImportedStudent(row.code, `${row.first_name || ''} ${row.last_name || ''}`, row.status_code || '10', row.faculty || businessFaculty, row.major || businessMajors[0]);
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
        const major = businessMajors[0];
        return Array.from(documentNode.querySelectorAll('tr')).map((tr) =>
            Array.from(tr.querySelectorAll('th,td')).map((cell) => (cell.textContent || '').replace(/\s+/g, ' ').trim())
        ).filter((cells) => /^\d{12}-\d$/.test(cells[1] || ''))
            .map((cells) => buildImportedStudent(cells[1], cells[2], cells[3], faculty, major));
    }

    function initProfile() {
        App.api('profile').done(function (response) {
            const profile = response.data;
            $('#adminNavbarName').text(profile.name || 'ผู้ดูแล');
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
                $('#adminNavbarName').text(response.data.name || 'ผู้ดูแล');
                $('#profileRolePreview').text(response.data.role);
                $('#profileAvatar').attr('src', App.url(response.data.avatar || 'assets/img/profile-admin.svg'));
            });
        });
    }

    function initSettings() {
        const $form = $('#settingsForm');
        const $inputs = $form.find('input');
        let loaded = null;
        let ready = false;
        let busy = false;
        let repair = false;
        function status(message, state = 'ready') {
            $('#settingsStatus').text(message).attr('data-state', state);
        }
        function values() {
            return {
                academic_year: String($('#academicYear').val()).trim(),
                notifications_enabled: $('#notificationsEnabled').prop('checked'),
                notification_refresh: Number($('#notificationRefresh').val()) * 1000,
                ai_title_enabled: $('#aiTitleEnabled').prop('checked'),
                ai_risk_enabled: $('#aiRiskEnabled').prop('checked')
            };
        }
        function fill(data) {
            $('#academicYear').val(data.academic_year);
            $('#notificationRefresh').val(data.notification_refresh / 1000);
            $('#notificationsEnabled').prop('checked', data.notifications_enabled);
            $('#aiTitleEnabled').prop('checked', data.ai_title_enabled);
            $('#aiRiskEnabled').prop('checked', data.ai_risk_enabled);
            $inputs.each(function () { this.setCustomValidity(''); }).removeAttr('aria-invalid');
        }
        function controls() {
            const changed = loaded && JSON.stringify(values()) !== JSON.stringify(loaded);
            $form.attr('aria-busy', busy ? 'true' : 'false');
            $inputs.prop('disabled', !ready || busy);
            $('#notificationRefresh').prop('disabled', !ready || busy || !$('#notificationsEnabled').prop('checked'));
            $('#settingsSave').prop('disabled', !ready || busy || (!changed && !repair));
            $('#settingsReset').prop('disabled', !ready || busy || !changed);
        }
        function bool(value, fallback = true) {
            return value == null ? fallback : value === true || value === 1 || value === '1' || value === 'true';
        }
        function load() {
            if (busy) return;
            busy = true;
            ready = false;
            controls();
            $('#settingsRetry').prop('hidden', true);
            status('กำลังโหลดการตั้งค่า…', 'loading');
            App.api('settings', { silentErrors: true }).done(function (response) {
                const data = response.data || {};
                const rawYear = String(data.academic_year == null ? '' : data.academic_year).trim();
                const year = /^\d{4}$/.test(rawYear) ? Number(rawYear) : 0;
                const displayYear = year >= 1900 && year <= 2399 ? String(year + 543) : year >= 2400 && year <= 2999 ? rawYear : '';
                repair = displayYear !== rawYear || displayYear === '';
                loaded = {
                    academic_year: displayYear,
                    notifications_enabled: bool(data.notifications_enabled),
                    notification_refresh: data.notification_refresh == null ? 30000 : Number(data.notification_refresh),
                    ai_title_enabled: bool(data.ai_title_enabled),
                    ai_risk_enabled: bool(data.ai_risk_enabled)
                };
                fill(loaded);
                ready = true;
                status(!displayYear ? 'กรุณาระบุปีการศึกษา พ.ศ. ที่ถูกต้อง แล้วบันทึกเพื่อแก้ไขค่าเดิม' : repair ? 'แปลงปี ค.ศ. เดิมเป็น พ.ศ. แล้ว กรุณาบันทึกเพื่อยืนยัน' : 'โหลดการตั้งค่าปัจจุบันแล้ว', !displayYear ? 'error' : 'ready');
            }).fail(function () {
                status('โหลดการตั้งค่าไม่สำเร็จ กรุณาลองอีกครั้ง', 'error');
                $('#settingsRetry').prop('hidden', false);
            }).always(function () { busy = false; controls(); });
        }
        $inputs.on('input change', function () {
            this.setCustomValidity('');
            $(this).removeAttr('aria-invalid');
            controls();
            status(JSON.stringify(values()) !== JSON.stringify(loaded) ? 'มีการแก้ไขที่ยังไม่ได้บันทึก' : 'ยังไม่มีการเปลี่ยนแปลง');
        });
        $('#settingsRetry').on('click', load);
        $('#settingsReset').on('click', function () {
            if (!ready || busy) return;
            fill(loaded);
            controls();
            status(repair ? 'คืนค่าแล้ว กรุณาระบุหรือยืนยันปี พ.ศ. ที่ถูกต้องก่อนบันทึก' : 'คืนค่าที่บันทึกไว้แล้ว');
        });
        $('#settingsForm').on('submit', function (event) {
            event.preventDefault();
            if (!ready || busy) return;
            const data = values();
            let invalid = '';
            let message = '';
            if (!/^\d{4}$/.test(data.academic_year) || Number(data.academic_year) < 2400 || Number(data.academic_year) > 2999) { invalid = '#academicYear'; message = 'กรุณาระบุปี พ.ศ. ตั้งแต่ 2400–2999'; }
            else if (!Number.isInteger(data.notification_refresh / 1000) || data.notification_refresh < 10000 || data.notification_refresh > 300000) { invalid = '#notificationRefresh'; message = 'กรุณาระบุช่วงเวลา 10–300 วินาที'; }
            if (invalid) {
                $(invalid).prop('disabled', false).attr('aria-invalid', 'true')[0].setCustomValidity(message);
                $(invalid)[0].reportValidity();
                status(message, 'error');
                return;
            }
            busy = true;
            controls();
            status('กำลังบันทึกการตั้งค่า…', 'loading');
            App.api('settings', { method: 'POST', data, silentErrors: true }).done(function () {
                loaded = data;
                repair = false;
                fill(loaded);
                status('บันทึกการตั้งค่าแล้ว การรีเฟรชอัตโนมัติใช้ค่าใหม่เมื่อโหลดหน้าอีกครั้ง', 'success');
            }).fail(function () {
                status('บันทึกไม่สำเร็จ ข้อมูลที่แก้ไขยังอยู่ กรุณาลองบันทึกอีกครั้ง', 'error');
            }).always(function () { busy = false; controls(); });
        });
        load();
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

    function loadProjectDeletionJobs() {
        App.api('projects', { query: { action: 'deletion-cleanups' }, silentErrors: true }).done(function (response) {
            const $panel = $('#projectDeletionJobs').empty();
            const jobs = response.data || [];
            $panel.toggleClass('d-none', jobs.length === 0);
            for (const job of jobs) {
                const $row = $('<div class="d-flex flex-wrap gap-2 align-items-center mb-2">');
                $('<span>').text(`ไฟล์รอลบ: ${job.title} (${job.files} ไฟล์)`).appendTo($row);
                $('<button type="button" class="btn btn-sm btn-outline-danger">').text('ลองลบไฟล์ค้างอีกครั้ง').attr('data-action', 'retry-project-files').attr('data-job', job.id).appendTo($row);
                $panel.append($row);
            }
        }).fail(function () { $('#projectDeletionJobs').removeClass('d-none').text('ตรวจรายการไฟล์รอลบไม่สำเร็จ กรุณารีเฟรชหน้าเพื่อตรวจอีกครั้ง'); });
    }

    $(document).on('click', '[data-action="delete-project"]', async function () {
        const $button = $(this);
        if ($button.prop('disabled')) return;
        $button.prop('disabled', true);
        const id = String($button.attr('data-id') || '');
        try {
            const confirmation = await Swal.fire({
                title: 'ลบโครงงานถาวร?', icon: 'warning',
                text: `โครงงาน: ${$button.attr('data-title')} — จะลบเอกสาร ผลพิจารณา บันทึกติดตาม และผล AI บัญชีผู้ใช้และกลุ่มยังอยู่ ไม่สามารถกู้คืนผ่านหน้าเว็บได้ ควรสำรองข้อมูลก่อน พิมพ์ ${id} เพื่อยืนยัน`,
                input: 'text', inputPlaceholder: id, showCancelButton: true,
                confirmButtonText: 'ยืนยันลบถาวร', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#b91c1c',
                inputValidator: value => value === id ? undefined : 'กรุณาพิมพ์รหัสโครงงานให้ตรงกัน'
            });
            if (!confirmation.isConfirmed) return;
            const response = await App.api('projects', { method: 'DELETE', query: { id }, data: { confirm_id: confirmation.value }, silentErrors: true });
            App.toast(response.message, response.data?.pending_files ? 'warning' : 'success');
            loadProjectsTable();
        } catch (error) {
            App.toast(error.responseJSON?.message || 'ลบไม่สำเร็จ กรุณารีเฟรชเพื่อตรวจสถานะก่อนลองใหม่', 'error');
        } finally { $button.prop('disabled', false); }
    });

    $(document).on('click', '[data-action="retry-project-files"]', async function () {
        const $button = $(this);
        if ($button.prop('disabled')) return;
        $button.prop('disabled', true);
        try {
            const confirmation = await App.confirmAction('ลองลบไฟล์ค้าง?', 'ลบเฉพาะไฟล์ของโครงงานที่ยืนยันลบไปแล้ว และไม่มีโครงงานอื่นใช้อยู่');
            if (!confirmation.isConfirmed) return;
            const response = await App.api('projects', { method: 'POST', query: { action: 'cleanup-delete' }, data: { job_id: $button.attr('data-job'), confirm: true }, silentErrors: true });
            App.toast(response.message, response.data?.pending ? 'warning' : 'success');
            loadProjectDeletionJobs();
        } catch (error) { App.toast(error.responseJSON?.message || 'ลบไฟล์ไม่สำเร็จ สามารถลองใหม่ได้', 'error'); }
        finally { $button.prop('disabled', false); }
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
        if (page === 'students') loadStudentsTable();
        if (page === 'student-add' || page === 'student-edit') initStudentForm();
        if (page === 'student-detail') loadStudentDetail();
        if (page === 'advisors') loadAdvisorsTable();
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
