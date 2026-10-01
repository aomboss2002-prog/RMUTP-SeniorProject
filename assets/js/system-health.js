(function ($) {
    'use strict';

    const statusIcons = { healthy: 'fa-circle-check', degraded: 'fa-triangle-exclamation', critical: 'fa-circle-xmark', disabled: 'fa-circle-pause', unknown: 'fa-circle-question' };
    const statusLabels = { healthy: 'พร้อม', degraded: 'ควรตรวจสอบ', critical: 'ขัดข้อง', disabled: 'ปิดใช้งาน', unknown: 'ยังไม่ทราบ' };

    function textDate(value) {
        if (!value) return 'ยังไม่มีข้อมูล';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
    }

    function setStatus($element, status) {
        const state = statusIcons[status] ? status : 'unknown';
        $element.removeClass(function (_index, classes) { return (classes.match(/health-status-\S+/g) || []).join(' '); }).addClass('health-status-' + state);
    }

    function renderService(key, service) {
        const safe = service || { status: 'unknown', label: 'ไม่ทราบสถานะ', message: 'ไม่มีข้อมูล', metric: '—' };
        const $node = $('[data-health-node="' + key + '"]');
        setStatus($node, safe.status);
        $node.find('span i').attr('class', 'fa-solid ' + (statusIcons[safe.status] || statusIcons.unknown));
        $node.find('small').text(safe.label || statusLabels[safe.status] || statusLabels.unknown);
        const $card = $('[data-health-card="' + key + '"]');
        setStatus($card, safe.status);
        $card.find('[data-field="label"]').text(safe.label || statusLabels[safe.status] || statusLabels.unknown);
        $card.find('[data-field="message"]').text(safe.message || 'ไม่มีรายละเอียด');
        $card.find('[data-field="metric"]').text(safe.metric || '—');
    }

    function renderHistory(history) {
        const $history = $('#healthRunHistory').empty();
        if (!Array.isArray(history) || !history.length) {
            $history.html('<div class="health-empty"><i class="fa-regular fa-calendar-xmark" aria-hidden="true"></i><p>ยังไม่มีประวัติการทำงาน</p></div>');
            return;
        }
        const list = $('<ul></ul>');
        history.forEach(function (run) {
            const status = ['success', 'failed', 'started'].includes(run.status) ? run.status : 'unknown';
            const icon = status === 'success' ? 'fa-circle-check' : (status === 'failed' ? 'fa-circle-xmark' : 'fa-circle-notch');
            const duration = run.duration_ms === null ? 'กำลังทำงาน' : Number(run.duration_ms).toLocaleString() + ' ms';
            list.append('<li class="run-' + status + '"><i class="fa-solid ' + icon + '" aria-hidden="true"></i><div><strong>' + App.escapeHtml(textDate(run.started_at)) + '</strong><span>' + App.escapeHtml(duration) + (run.error_code ? ' · ' + App.escapeHtml(run.error_code) : '') + '</span></div></li>');
        });
        $history.append(list);
    }

    function websiteNotice(message, stale) {
        $('#healthWebsite').attr('aria-busy', 'false').toggleClass('health-website-stale', stale);
        $('#healthWebsiteNotice').text(message);
    }

    function websiteEmpty($target, message, list) {
        $target.empty().append($(list ? '<li>' : '<p>').addClass('health-website-empty').text(message));
    }

    function websiteCount(value) {
        return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value.toLocaleString('th-TH') : '--';
    }

    function renderWebsite(website) {
        const available = website && website.available === true;
        const summary = available ? website.summary || {} : {};
        $('[data-website-count]').each(function () { $(this).text(websiteCount(summary[$(this).data('website-count')])); });
        websiteNotice(available ? 'ข้อมูลเว็บไซต์จากการตรวจสอบล่าสุด · อัปเดตอัตโนมัติทุก 5 วินาที' : 'ไม่สามารถโหลดข้อมูลเว็บไซต์ได้ ข้อมูลอาจไม่เป็นปัจจุบัน กรุณาลองรีเฟรชอีกครั้ง', !available);
        const $statuses = $('#healthProjectStatus').empty();
        const entries = available && website.project_status && typeof website.project_status === 'object'
            ? Object.entries(website.project_status).filter((entry) => typeof entry[1] === 'number' && Number.isFinite(entry[1]) && entry[1] >= 0) : [];
        const total = entries.reduce((sum, entry) => sum + entry[1], 0);
        if (!entries.length || !total) websiteEmpty($statuses, available ? 'ยังไม่มีโครงงานในระบบ' : 'ไม่สามารถโหลดสถานะโครงงานได้', false);
        else entries.forEach(function ([status, count]) {
            const $row = $('<div>').addClass('health-project-row');
            const $label = $('<div>').append($('<span>').text(App.label(status)), $('<strong>').text(websiteCount(count)));
            const $bar = $('<div>').addClass('health-project-bar').attr('aria-hidden', 'true');
            $bar.append($('<span>').css('width', Math.min(100, count / total * 100) + '%'));
            $statuses.append($row.append($label, $bar));
        });

        const $documents = $('#healthRecentDocuments').empty();
        const documents = available && Array.isArray(website.recent_documents) ? website.recent_documents.slice(0, 6) : [];
        if (!documents.length) websiteEmpty($documents, available ? 'ยังไม่มีเอกสารที่อัปโหลด' : 'ไม่สามารถโหลดเอกสารล่าสุดได้', true);
        documents.forEach(function (doc) {
            const $item = $('<li>').addClass('health-document-item');
            const $copy = $('<div>').addClass('health-website-copy');
            $copy.append($('<strong>').text(doc.title || 'เอกสารไม่มีชื่อ'));
            const chapter = doc.chapter ? ' · บทที่ ' + doc.chapter : '';
            $copy.append($('<span>').text((App.label(doc.type) || 'เอกสาร') + chapter));
            $copy.append($('<time>').text(textDate(doc.uploaded_at)));
            $item.append($('<i>').addClass('fa-regular fa-file-lines').attr('aria-hidden', 'true'), $copy, $('<span>').addClass('health-document-status').text(App.label(doc.status) || 'ไม่ทราบสถานะ'));
            $documents.append($item);
        });

        const $activity = $('#healthRecentActivity').empty();
        const activityAvailable = available && website.activity_available === true;
        const activity = activityAvailable && Array.isArray(website.recent_activity) ? website.recent_activity.slice(0, 6) : [];
        if (!activity.length) websiteEmpty($activity, activityAvailable ? 'ยังไม่มีประวัติกิจกรรม' : 'ไม่สามารถโหลดประวัติกิจกรรมได้', true);
        const events = { submitted: 'ส่งเอกสาร', resubmitted: 'ส่งเอกสารอีกครั้ง', approved: 'อนุมัติเอกสาร', rejected: 'ไม่อนุมัติเอกสาร', revision_requested: 'ขอแก้ไขเอกสาร', needs_revision: 'ขอแก้ไขเอกสาร', uploaded: 'อัปโหลดเอกสาร', completed: 'เสร็จสิ้น', reviewed: 'ตรวจเอกสาร', progress_updated: 'อัปเดตความคืบหน้า', progress_changed: 'ปรับความก้าวหน้า', document_deleted: 'ลบเอกสาร' };
        activity.forEach(function (event) {
            const $copy = $('<div>').addClass('health-website-copy');
            $copy.append($('<strong>').text(events[event.event_type] || 'อัปเดตขั้นตอนโครงงาน'));
            const chapter = event.chapter ? ' · บทที่ ' + event.chapter : '';
            $copy.append($('<span>').text((event.actor_name || 'ผู้ใช้ระบบ') + ' · ' + (App.label(event.stage) || 'โครงงาน') + chapter));
            $copy.append($('<time>').text(textDate(event.occurred_at)));
            $activity.append($('<li>').addClass('health-activity-item').append($('<i>').addClass('fa-solid fa-clock-rotate-left').attr('aria-hidden', 'true'), $copy));
        });
    }

    function render(data) {
        renderWebsite(data.website);
        const services = data.services || {};
        Object.keys({ database: 1, storage: 1, email: 1, ai: 1, cron: 1 }).forEach((key) => renderService(key, services[key]));
        const overall = data.overall || {};
        const $readiness = $('#healthReadiness').removeClass('health-is-loading').attr('aria-busy', 'false');
        setStatus($readiness, overall.status);
        $('#healthOverallLabel').text(overall.label || 'ไม่ทราบสถานะ');
        $('#healthResponseTime').text((overall.response_ms ?? '—') + ' ms');
        $('#healthCheckedAt').text('ตรวจสอบล่าสุด ' + textDate(data.checked_at));
        $('.health-overall-mark i').attr('class', 'fa-solid ' + (statusIcons[overall.status] || statusIcons.unknown));

        const db = services.database || {}, storage = services.storage || {}, email = services.email || {}, ai = services.ai || {}, cron = services.cron || {};
        $('[data-detail="database"] [data-value="latency"]').text(db.latency_ms === null ? 'ไม่พร้อม' : (db.latency_ms + ' ms'));
        $('[data-detail="database"] [data-value="schema"]').text(db.schema_ready ? 'พร้อม' : (Array.isArray(db.missing_tables) && db.missing_tables.length ? 'ขาด: ' + db.missing_tables.join(', ') : 'ตรวจสอบไม่ได้'));
        $('#healthRepairSchema').toggleClass('d-none', !Array.isArray(db.missing_tables) || db.missing_tables.length === 0);
        $('[data-detail="storage"] [data-value="driver"]').text(storage.driver || '—');
        $('[data-detail="storage"] [data-value="configured"]').text(storage.configured ? 'พร้อม' : 'ต้องตรวจสอบ');
        $('[data-detail="email"] [data-value="transport"]').text((email.transport || '—').toUpperCase());
        $('[data-detail="email"] [data-value="sender"]').text(email.sender || 'ยังไม่กำหนด');
        $('#healthAiState').text(ai.label || '—'); setStatus($('#healthAiState'), ai.status);
        $('#healthAiEngine').text(ai.title_engine || 'auto'); $('#healthAiModel').text(ai.title_model || 'built-in');
        Object.keys(ai.queue || {}).forEach((key) => $('[data-queue="' + key + '"]').text(Number(ai.queue[key]).toLocaleString()));
        $('#healthAiLatest').text(textDate(ai.latest_completion)); $('#healthRiskLatest').text(textDate(ai.risk_latest));
        $('#healthCronState').text(cron.label || '—'); setStatus($('#healthCronState'), cron.status);
        $('#healthCronUtc').text(cron.schedule_utc || '02:00 UTC ทุกวัน'); $('#healthCronThai').text(cron.schedule_th || '09:00 น. ประเทศไทย');
        renderHistory(cron.history);
        $('#healthAnnouncement').text(overall.label + ' ตรวจสอบเมื่อ ' + textDate(data.checked_at));
    }

    let refreshTimer;
    let healthLoading = false;
    let diagnosticsRunning = 0;
    let stopped = false;
    let authExpired = false;
    let retryDelay = 5000;
    let backupPending = false;

    function scheduleHealth() {
        clearTimeout(refreshTimer);
        if (stopped || authExpired || document.hidden || navigator.onLine === false || diagnosticsRunning) return;
        refreshTimer = setTimeout(loadHealth, retryDelay);
    }

    function loadHealth() {
        clearTimeout(refreshTimer);
        if (stopped || authExpired || document.hidden || navigator.onLine === false || healthLoading || diagnosticsRunning) return;
        healthLoading = true;
        const $button = $('#healthRefresh').prop('disabled', true).find('i').addClass('fa-spin');
        $('#healthReadiness').attr('aria-busy', 'true');
        App.api('system-health', { silentErrors: true }).done(function (response) {
            retryDelay = 5000;
            render(response.data || {});
            $('#healthAutoStatus').text(document.hidden ? 'พักการอัปเดตขณะอยู่แท็บอื่น' : 'อัปเดตอัตโนมัติทุก 5 วินาที');
        }).fail(function (xhr) {
            websiteNotice('อัปเดตไม่สำเร็จ ข้อมูลเว็บไซต์ที่แสดงอาจเก่า โปรดตรวจสอบเวลาล่าสุด', true);
            $('#healthReadiness').removeClass('health-is-loading').attr('aria-busy', 'false');
            if ([401, 403].includes(xhr.status)) {
                authExpired = true;
                $('#healthAutoStatus').text('หยุดอัปเดต: กรุณาเข้าสู่ระบบผู้ดูแลอีกครั้ง');
            } else {
                retryDelay = Math.min(retryDelay * 2, 60000);
                $('#healthAutoStatus').text(`อัปเดตไม่สำเร็จ ข้อมูลที่แสดงอาจเก่า จะลองใหม่ใน ${retryDelay / 1000} วินาที`);
            }
        }).always(function () {
            healthLoading = false;
            $('#healthRefresh').prop('disabled', authExpired); $button.removeClass('fa-spin');
            scheduleHealth();
        });
    }

    function diagnostic(action, title, text, button) {
        if (backupPending || authExpired) return;
        App.confirmAction(title, text).then(function (result) {
            if (!result.isConfirmed) return;
            diagnosticsRunning++;
            clearTimeout(refreshTimer);
            const $button = $(button).prop('disabled', true); App.showLoader(true);
            const $result = $('#healthDiagnosticResult').removeClass('d-none alert-danger alert-success').addClass('alert-info').text('กำลังทดสอบ...');
            App.api('system-health', { method: 'POST', query: { action: action }, data: action === 'repair-ai-schema' ? { confirm: true } : {} }).done(function (response) {
                $result.removeClass('alert-info').addClass('alert-success').text(response.message || 'ทดสอบสำเร็จ');
                App.toast(response.message || 'ทดสอบสำเร็จ');
            }).fail(function (xhr) {
                const response = xhr.responseJSON || {};
                $result.removeClass('alert-info').addClass('alert-danger').text((response.message || 'ติดต่อเซิร์ฟเวอร์ไม่สำเร็จ กรุณาลองใหม่') + (response.error_code ? ' [' + response.error_code + ']' : ''));
            }).always(function () {
                diagnosticsRunning--;
                $button.prop('disabled', false); App.showLoader(diagnosticsRunning > 0);
                loadHealth();
            });
        });
    }

    async function backupDatabase() {
        if (backupPending || diagnosticsRunning || authExpired || stopped) return;
        backupPending = true;
        diagnosticsRunning++;
        clearTimeout(refreshTimer);
        const $button = $('#healthBackupDatabase').prop('disabled', true).attr('aria-busy', 'true');
        const $result = $('#healthBackupResult');
        let request;
        let requestTimer;
        let timedOut = false;
        try {
            const confirmation = await App.confirmAction('สำรองฐานข้อมูล?', 'ไฟล์มีข้อมูลส่วนบุคคลและค่าแฮชรหัสผ่าน โปรดเก็บอย่างปลอดภัย ไม่รวม PDF/รูปภาพ และ .env ต้องการสร้างไฟล์ .sql.gz หรือไม่?');
            if (!confirmation.isConfirmed || authExpired || stopped) return;
            $button.find('i').attr('class', 'fa-solid fa-circle-notch fa-spin');
            $result.removeClass('d-none alert-danger alert-success').addClass('alert-info').text('กำลังสร้างไฟล์สำรอง กรุณารอสักครู่...');
            $('#healthAutoStatus').text('พักการอัปเดตขณะสร้างไฟล์สำรอง');
            request = App.api('system-health', { method: 'POST', query: { action: 'backup-database' }, data: { confirm: true }, silentErrors: true });
            requestTimer = setTimeout(function () { timedOut = true; request.abort(); }, 45000);
            const response = await request;
            const data = response && response.data;
            if (!response.success || !data || typeof data.content_base64 !== 'string' || data.content_base64.length > 2796204 || !/^rmutp-database-\d{8}-\d{6}\.sql\.gz$/.test(data.filename)) {
                throw new Error('ข้อมูลไฟล์สำรองไม่ถูกต้อง กรุณาติดต่อผู้ดูแลระบบ');
            }
            const binary = atob(data.content_base64);
            if (!binary.length || binary.length > 2 * 1024 * 1024 || binary.length !== data.bytes || binary.charCodeAt(0) !== 31 || binary.charCodeAt(1) !== 139) {
                throw new Error('ข้อมูลไฟล์สำรองไม่ครบถ้วนหรือเกินขนาดที่รองรับ');
            }
            const bytes = Uint8Array.from(binary, function (character) { return character.charCodeAt(0); });
            const objectUrl = URL.createObjectURL(new Blob([bytes], { type: 'application/gzip' }));
            const link = document.createElement('a');
            try {
                link.href = objectUrl;
                link.download = data.filename;
                link.hidden = true;
                document.body.appendChild(link);
                link.click();
            } finally {
                link.remove();
                setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 60000);
            }
            let message = 'สร้างไฟล์สำรองแล้ว โปรดตรวจไฟล์ใน Downloads · ' + data.filename;
            if (Number.isSafeInteger(data.tables) && Number.isSafeInteger(data.rows)) message += ' · ' + data.tables.toLocaleString('th-TH') + ' ตาราง / ' + data.rows.toLocaleString('th-TH') + ' แถว';
            if (typeof data.sha256 === 'string' && /^[a-f0-9]{64}$/i.test(data.sha256)) message += ' · SHA-256: ' + data.sha256;
            $result.removeClass('alert-info alert-danger').addClass('alert-success').text(message);
        } catch (error) {
            const failure = error.responseJSON || {};
            let message = failure.message || (error instanceof Error ? error.message : 'ติดต่อเซิร์ฟเวอร์ไม่สำเร็จ ไม่ได้ลองสำรองซ้ำอัตโนมัติ');
            if ([401, 403].includes(error.status)) {
                authExpired = true;
                message = 'ไม่สามารถสำรองได้ กรุณาเข้าสู่ระบบผู้ดูแลอีกครั้ง';
                $('#healthAutoStatus').text('หยุดอัปเดต: กรุณาเข้าสู่ระบบผู้ดูแลอีกครั้ง');
            } else if (timedOut) message = 'รอผลการสำรองนานเกินไป ไม่ได้ลองซ้ำอัตโนมัติ';
            $result.removeClass('d-none alert-info alert-success').addClass('alert-danger').text(message + (failure.error_code ? ' [' + failure.error_code + ']' : '') + ' · รองรับ SQL ไม่เกิน 24 MiB / ไฟล์บีบอัด 2 MiB และประมวลผล 20 วินาที หากฐานข้อมูลใหญ่กว่านี้ ให้สำรองผ่านผู้ให้บริการฐานข้อมูล');
        } finally {
            clearTimeout(requestTimer);
            backupPending = false;
            diagnosticsRunning--;
            $button.prop('disabled', authExpired).attr('aria-busy', 'false').find('i').attr('class', 'fa-solid fa-download');
            loadHealth();
        }
    }

    $(function () {
        if ($('body').data('page') !== 'system-health') return;
        $('#healthRefresh').on('click', loadHealth);
        $('#healthBackupDatabase').on('click', backupDatabase);
        $('#healthRepairSchema').on('click', function () { diagnostic('repair-ai-schema', 'สร้างตาราง AI ที่ขาด?', 'ควรสำรองฐานข้อมูลก่อน ระบบจะสร้างเฉพาะ project_title_checks และ project_risk_scores ที่ยังไม่มี ไม่แก้ตารางเดิมและไม่ลบข้อมูล ต้องการดำเนินการต่อหรือไม่?', this); });
        $('#healthTestStorage').on('click', function () { diagnostic('test-storage', 'ทดสอบ Storage?', 'ระบบจะสร้างไฟล์ขนาดเล็ก ตรวจสอบ แล้วลบทันที', this); });
        $('#healthTestEmail').on('click', function () { diagnostic('test-email', 'ส่งอีเมลทดสอบ?', 'ระบบจะส่งข้อความทั่วไปไปยังอีเมลกู้คืนของผู้ดูแล', this); });
        $(document).on('visibilitychange', function () {
            clearTimeout(refreshTimer);
            if (document.hidden) $('#healthAutoStatus').text('พักการอัปเดตขณะอยู่แท็บอื่น');
            else loadHealth();
        });
        $(window).on('offline', function () {
            clearTimeout(refreshTimer);
            websiteNotice('ขาดการเชื่อมต่อ ข้อมูลเว็บไซต์ที่แสดงอาจเก่า', true);
            $('#healthAutoStatus').text('ขาดการเชื่อมต่อ จะอัปเดตเมื่ออินเทอร์เน็ตกลับมา');
        }).on('online', loadHealth).on('pagehide', function () {
            stopped = true;
            clearTimeout(refreshTimer);
        }).on('pageshow', function () { stopped = false; loadHealth(); });
        loadHealth();
    });
})(jQuery);
