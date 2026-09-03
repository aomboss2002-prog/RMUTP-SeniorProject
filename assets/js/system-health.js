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

    function render(data) {
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
        $('[data-detail="database"] [data-value="schema"]').text(db.schema_ready ? 'พร้อม' : 'ไม่ครบ');
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

    function loadHealth() {
        const $button = $('#healthRefresh').prop('disabled', true).find('i').addClass('fa-spin');
        $('#healthReadiness').attr('aria-busy', 'true');
        App.api('system-health').done(function (response) { render(response.data || {}); }).fail(function () {
            $('#healthReadiness').removeClass('health-is-loading').attr('aria-busy', 'false');
            $('#healthOverallLabel').text('โหลดสถานะไม่สำเร็จ');
        }).always(function () { $('#healthRefresh').prop('disabled', false); $button.removeClass('fa-spin'); });
    }

    function diagnostic(action, title, text, button) {
        App.confirmAction(title, text).then(function (result) {
            if (!result.isConfirmed) return;
            const $button = $(button).prop('disabled', true); App.showLoader(true);
            App.api('system-health', { method: 'POST', query: { action: action }, data: {} }).done(function (response) {
                App.toast(response.message || 'ทดสอบสำเร็จ'); loadHealth();
            }).always(function () { $button.prop('disabled', false); App.showLoader(false); });
        });
    }

    $(function () {
        if ($('body').data('page') !== 'system-health') return;
        $('#healthRefresh').on('click', loadHealth);
        $('#healthTestStorage').on('click', function () { diagnostic('test-storage', 'ทดสอบ Storage?', 'ระบบจะสร้างไฟล์ขนาดเล็ก ตรวจสอบ แล้วลบทันที', this); });
        $('#healthTestEmail').on('click', function () { diagnostic('test-email', 'ส่งอีเมลทดสอบ?', 'ระบบจะส่งข้อความทั่วไปไปยังอีเมลกู้คืนของผู้ดูแล', this); });
        loadHealth();
    });
})(jQuery);
