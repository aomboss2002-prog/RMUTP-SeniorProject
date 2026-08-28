(function ($) {
    'use strict';

    const BASE_URL = $('meta[name="app-base-url"]').attr('content') || '/';
    const API_URL = BASE_URL + 'api/admin/index.php';
    const state = { tables: {}, charts: {} };

    function api(resource, options = {}) {
        const query = options.query || {};
        const endpoint = resource === 'auth' ? BASE_URL + 'api/auth/index.php' : API_URL;
        const url = endpoint + '?' + $.param(Object.assign({ resource }, query));
        const ajaxOptions = {
            url,
            method: options.method || 'GET',
            dataType: 'json',
            headers: { 'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content') || '' }
        };

        if (options.formData) {
            ajaxOptions.data = options.formData;
            ajaxOptions.processData = false;
            ajaxOptions.contentType = false;
        } else if (options.data) {
            ajaxOptions.data = JSON.stringify(options.data);
            ajaxOptions.contentType = 'application/json';
        }

        return $.ajax(ajaxOptions).fail(function (xhr) {
            const response = xhr.responseJSON || {};
            toast(response.message || 'ไม่สามารถเชื่อมต่อ API ได้', 'error');
        });
    }

    function url(path = '') {
        return BASE_URL + String(path).replace(/^\//, '');
    }

    function showLoader(show) {
        $('#appLoader').toggleClass('show', !!show);
    }

    function toast(message, icon = 'success') {
        if (!window.Swal) {
            alert(message);
            return;
        }
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon,
            title: message,
            showConfirmButton: false,
            timer: 2200,
            timerProgressBar: true
        });
    }

    function confirmAction(title, text) {
        return Swal.fire({
            title,
            text,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#0B3C8C',
            cancelButtonColor: '#64748B',
            confirmButtonText: 'Confirm'
        });
    }

    function badge(status) {
        const map = {
            Approved: 'success',
            Completed: 'success',
            Active: 'success',
            Pending: 'warning',
            Resubmitted: 'info',
            'Not Started': 'secondary',
            Review: 'info',
            NeedsRevision: 'warning',
            Draft: 'secondary',
            Rejected: 'danger',
            Inactive: 'danger'
        };
        const labels = {
            Approved: 'อนุมัติแล้ว',
            Completed: 'เสร็จสมบูรณ์',
            Active: 'ใช้งานอยู่',
            Pending: 'รอดำเนินการ',
            Resubmitted: 'ส่งกลับมาแก้ไข',
            'Not Started': 'ยังไม่เริ่ม',
            Review: 'รอตรวจสอบ',
            NeedsRevision: 'ให้กลับไปแก้ไข',
            Draft: 'ฉบับร่าง',
            Rejected: 'ยังไม่ผ่าน',
            Inactive: 'ปิดใช้งาน',
            New: 'ใหม่'
        };
        return `<span class="badge rounded-pill text-bg-${map[status] || 'primary'}">${escapeHtml(labels[status] || status || labels.New)}</span>`;
    }

    function label(value) {
        const labels = {
            Approved: 'อนุมัติแล้ว',
            Completed: 'เสร็จสมบูรณ์',
            Active: 'ใช้งานอยู่',
            Pending: 'รอดำเนินการ',
            Resubmitted: 'ส่งกลับมาแก้ไข',
            'Not Started': 'ยังไม่เริ่ม',
            Review: 'รอตรวจสอบ',
            NeedsRevision: 'ให้กลับไปแก้ไข',
            Draft: 'ฉบับร่าง',
            Rejected: 'ยังไม่ผ่าน',
            Inactive: 'ปิดใช้งาน',
            proposal: 'ข้อเสนอ',
            draft: 'ฉบับร่าง',
            complete: 'ฉบับสมบูรณ์',
            Approval: 'การอนุมัติ',
            Upload: 'การอัปโหลด',
            System: 'ระบบ',
            Proposal: 'ข้อเสนอ',
            Complete: 'ฉบับสมบูรณ์'
        };
        return labels[value] || value || '';
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
        });
    }

    function enhanceTable(selector, options = {}) {
        const $table = $(selector);
        if (!$table.length || !$.fn.DataTable) {
            return null;
        }
        if ($.fn.DataTable.isDataTable($table)) {
            $table.DataTable().destroy();
        }
        const table = $table.DataTable(Object.assign({
            responsive: true,
            pageLength: 8,
            lengthMenu: [5, 8, 10, 25],
            order: [],
            dom: '<"table-toolbar"Bf>rt<"table-footer"ip>',
            buttons: [
                { extend: 'copy', text: '<i class="fa-regular fa-copy"></i><span>คัดลอก</span>', className: 'export-btn export-copy', titleAttr: 'คัดลอกข้อมูล' },
                { extend: 'csv', text: '<i class="fa-solid fa-file-csv"></i><span>CSV</span>', className: 'export-btn export-csv', titleAttr: 'ส่งออก CSV' },
                { extend: 'excel', text: '<i class="fa-solid fa-file-excel"></i><span>Excel</span>', className: 'export-btn export-excel', titleAttr: 'ส่งออก Excel' },
                { extend: 'print', text: '<i class="fa-solid fa-print"></i><span>พิมพ์</span>', className: 'export-btn export-print', titleAttr: 'พิมพ์ตาราง' }
            ],
            language: {
                search: '_INPUT_',
                searchPlaceholder: 'ค้นหาข้อมูล...',
                lengthMenu: 'แสดง _MENU_ รายการ',
                info: 'แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ',
                infoEmpty: 'ไม่มีข้อมูล',
                infoFiltered: '(กรองจากทั้งหมด _MAX_ รายการ)',
                loadingRecords: 'กำลังโหลด...',
                processing: 'กำลังประมวลผล...',
                zeroRecords: 'ไม่พบข้อมูล',
                emptyTable: 'ไม่มีข้อมูลในตาราง',
                paginate: {
                    first: 'หน้าแรก',
                    previous: 'ก่อนหน้า',
                    next: 'ถัดไป',
                    last: 'หน้าสุดท้าย'
                }
            }
        }, options));
        state.tables[selector] = table;
        return table;
    }

    function formToObject($form) {
        const data = {};
        $form.serializeArray().forEach((field) => {
            data[field.name] = field.value;
        });
        return data;
    }

    function downloadCsv(filename, rows) {
        if (!rows || !rows.length) {
            toast('ไม่มีข้อมูลสำหรับส่งออก', 'info');
            return;
        }
        const headers = Object.keys(rows[0]);
        const csvRows = [
            headers.join(','),
            ...rows.map((row) => headers.map((key) => `"${String(row[key] ?? '').replace(/"/g, '""')}"`).join(','))
        ];
        const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
    }

    function initLayout() {
        const $body = $('body');
        const $sidebar = $('#sidebar');
        $sidebar.find('.sidebar-link').each(function () {
            const label = $(this).find('span').text().trim();
            if (label) $(this).attr('title', label);
        });

        function updateSidebarMode() {
            if (window.innerWidth >= 992) {
                $body.addClass('sidebar-collapsed sidebar-hover-mode');
                $sidebar.removeClass('show');
            } else {
                $body.removeClass('sidebar-collapsed sidebar-hover-mode');
            }
        }
        localStorage.removeItem('rmutp_sidebar_collapsed');
        updateSidebarMode();

        $('#sidebarToggle').on('click', function () {
            if (window.innerWidth < 992) {
                $sidebar.toggleClass('show');
            }
        });
        $('.content-area').on('click', function () {
            if (window.innerWidth < 992) {
                $sidebar.removeClass('show');
            }
        });
        $(window).on('resize', updateSidebarMode);
    }

    function initInterfacePolish() {
        const navbar = document.querySelector('.top-navbar');
        let scrollFrame = null;

        function updateNavbarElevation() {
            scrollFrame = null;
            if (navbar) navbar.classList.toggle('is-scrolled', window.scrollY > 8);
        }

        updateNavbarElevation();
        window.addEventListener('scroll', function () {
            if (scrollFrame !== null) return;
            scrollFrame = window.requestAnimationFrame(updateNavbarElevation);
        }, { passive: true });

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        function unreadTotal() {
            return Array.from(document.querySelectorAll('.notification-counter')).reduce(function (total, counter) {
                const count = Number.parseInt(counter.textContent, 10);
                return total + (Number.isFinite(count) ? count : 0);
            }, 0);
        }

        let previousUnreadTotal = unreadTotal();
        let notificationFrame = null;
        const observer = new MutationObserver(function () {
            if (notificationFrame !== null) return;
            notificationFrame = window.requestAnimationFrame(function () {
                notificationFrame = null;
                const currentUnreadTotal = unreadTotal();
                if (currentUnreadTotal === previousUnreadTotal) return;
                previousUnreadTotal = currentUnreadTotal;
                document.querySelectorAll('.notification-counter').forEach(function (counter) {
                    counter.classList.remove('is-count-changed');
                    void counter.offsetWidth;
                    counter.classList.add('is-count-changed');
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true, characterData: true });
    }

    function initLogin() {
        $('#loginForm').on('submit', function (event) {
            event.preventDefault();
            showLoader(true);
            api('auth', { method: 'POST', query: { action: 'login' }, data: formToObject($(this)) })
                .done(function (response) {
                    const user = response.data || {};
                    sessionStorage.setItem('rmutp_user', JSON.stringify(user));
                    toast(response.message || 'เข้าสู่ระบบสำเร็จ');

                    const redirectPage = user.redirect_page || 'dashboard';
                    const destinations = {
                        dashboard: 'admin/dashboard.php',
                        'portal-dashboard': 'student/dashboard.php',
                        'advisor-dashboard': 'advisor/dashboard.php'
                    };
                    const redirectUrl = url(destinations[redirectPage] || 'login.php');

                    window.location.href = redirectUrl;
                })
                .always(() => showLoader(false));
        });
    }

    function initGlobalActions() {
        $('#logoutBtn').on('click', function () {
            api('auth', { method: 'POST', query: { action: 'logout' } }).always(function () {
                sessionStorage.removeItem('rmutp_user');
                window.location.href = url('login.php');
            });
        });

        $(document).on('click', '[data-action="download-resource"]', function (event) {
            event.preventDefault();
            const resource = $(this).data('resource');
            api('export', { query: { kind: resource } }).done((response) => {
                downloadCsv(`${resource}-${new Date().toISOString().slice(0, 10)}.csv`, response.data);
            });
        });

        $(document).on('click', '[data-action="preview-file"]', function () {
            const url = $(this).data('url');
            $('#pdfPreviewFrame').attr('src', url || '');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('filePreviewModal')).show();
        });
    }

    function initUiMotion() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const elements = document.querySelectorAll('.content-area .card, .content-area .summary-card, .content-area .status-card');
        elements.forEach((element, index) => {
            element.classList.add('ui-reveal');
            element.style.setProperty('--reveal-delay', `${Math.min(index % 8, 7) * 55}ms`);
        });

        requestAnimationFrame(() => requestAnimationFrame(() => {
            elements.forEach((element) => element.classList.add('is-visible'));
        }));
    }

    window.App = {
        api,
        badge,
        label,
        escapeHtml,
        enhanceTable,
        formToObject,
        toast,
        confirmAction,
        showLoader,
        downloadCsv,
        url,
        state
    };

    $(function () {
        $(document).ajaxSend(function (_event, _xhr, options) {
            if ((options.type || 'GET').toUpperCase() !== 'GET') $('button[type="submit"]').prop('disabled', true);
        });
        $(document).ajaxComplete(function () {
            $('button[type="submit"]').prop('disabled', false);
        });
        initLayout();
        initGlobalActions();
        initInterfacePolish();
        initUiMotion();
        if ($('body').data('page') === 'login') {
            initLogin();
        }
    });
})(jQuery);
