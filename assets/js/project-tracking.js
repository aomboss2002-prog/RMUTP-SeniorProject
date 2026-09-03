(function () {
    'use strict';
    const statusLabels = {
        not_started: 'ยังไม่เริ่ม', awaiting_review: 'รอตรวจ', needs_revision: 'ต้องแก้ไข', completed: 'เสร็จแล้ว'
    };
    const statusIcons = {
        not_started: 'fa-minus', awaiting_review: 'fa-clock', needs_revision: 'fa-rotate-left', completed: 'fa-check'
    };
    const eventLabels = {
        submitted: 'ส่งเอกสาร', resubmitted: 'ส่งเอกสารแก้ไข', approved: 'อนุมัติแล้ว',
        revision_requested: 'ส่งกลับแก้ไข', rejected: 'ยังไม่ผ่าน', document_deleted: 'ลบเอกสาร',
        progress_changed: 'อัปเดตความก้าวหน้า', backfilled: 'นำเข้าประวัติเดิม'
    };
    function esc(value) { return App.escapeHtml(value == null ? '' : String(value)); }
    function stageLabel(row) {
        if ((row.stage || '') === 'draft') return `Draft chapter ${Number(row.chapter || 1)}`;
        return row.stage === 'proposal' ? 'Proposal' : (row.stage === 'complete' ? 'Complete' : (row.stage || 'Project'));
    }
    function formatDate(value) {
        if (!value) return '-';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' });
    }
    function renderSummary(selector, tracking) {
        const stage = tracking?.current_stage || {};
        const role = tracking?.responsible_role === 'advisor' ? 'อาจารย์ที่ปรึกษา' : (tracking?.responsible_role === 'student' ? 'นักศึกษา' : 'เสร็จสมบูรณ์');
        $(selector).html(`<div><small>ขั้นตอนปัจจุบัน</small><strong>${esc(stage.label || 'ยังไม่มีโครงงาน')}</strong><span>${esc(tracking?.next_action || 'ยังไม่มีกิจกรรม')}</span></div><div><small>ผู้ดำเนินการต่อ</small><strong>${esc(role)}</strong><span>${tracking?.inactive_days == null ? 'ยังไม่มีกิจกรรม' : `ไม่เคลื่อนไหว ${Number(tracking.inactive_days)} วัน`}</span></div>`);
    }
    function renderMilestones(selector, milestones) {
        const rows = milestones || [];
        $(selector).html(rows.map((row) => `<li class="milestone-item is-${esc(row.status)}"${row.current ? ' aria-current="step"' : ''}><span class="milestone-node"><i class="fa-solid ${statusIcons[row.status] || 'fa-minus'}" aria-hidden="true"></i></span><strong class="milestone-label">${esc(row.label)}</strong><span class="milestone-status">${esc(statusLabels[row.status] || row.status)}</span></li>`).join('') || '<li class="text-muted">ยังไม่มีขั้นตอนโครงงาน</li>');
    }
    function renderHistory(selector, history) {
        $(selector).html((history || []).slice().reverse().map((row) => `<article class="tracking-history-item"><strong>${esc(eventLabels[row.event_type] || row.event_type)} · ${esc(stageLabel(row))}</strong><span>${esc(row.actor_name || 'System')} · ${esc(formatDate(row.occurred_at))}</span><small>ความก้าวหน้า ${Number(row.previous_progress || 0)}% → ${Number(row.current_progress || 0)}%</small></article>`).join('') || '<div class="tracking-empty text-muted"><i class="fa-regular fa-clock me-2"></i>ยังไม่มีประวัติที่บันทึกไว้ ระบบจะแสดงข้อมูลเมื่อมีการส่งหรือตรวจเอกสารครั้งถัดไป</div>');
    }
    function renderChart(canvasId, history, currentProgress) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || !window.Chart) return;
        const meaningful = [];
        (history || []).forEach((row) => {
            const value = Number(row.current_progress || 0);
            if (!meaningful.length || meaningful[meaningful.length - 1].value !== value) meaningful.push({ label: formatDate(row.occurred_at), value });
        });
        if (!meaningful.length) meaningful.push({ label: 'ปัจจุบัน', value: Number(currentProgress || 0) });
        App.state.charts[canvasId]?.destroy();
        App.state.charts[canvasId] = new Chart(canvas, { type: 'line', data: { labels: meaningful.map((row) => row.label), datasets: [{ label: 'ความก้าวหน้า (%)', data: meaningful.map((row) => row.value), borderColor: '#0b3c8c', backgroundColor: 'rgba(11,60,140,.1)', fill: true, tension: .28, pointBackgroundColor: '#0b3c8c', pointRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, animation: { duration: 350 }, scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (value) => `${value}%` } } }, plugins: { legend: { display: false } } } });
    }
    function followupCards(rows, editable) {
        return (rows || []).map((row) => `<article class="followup-card" data-followup-id="${Number(row.id)}"><header><strong>${esc(row.advisor_name || 'อาจารย์ที่ปรึกษา')}</strong><span class="followup-meta">${esc(formatDate(row.created_at))}</span></header><p>${esc(row.note)}</p>${row.issue ? `<p><strong>ปัญหา:</strong> ${esc(row.issue)}</p>` : ''}${row.next_action ? `<p><strong>ทำต่อ:</strong> ${esc(row.next_action)}</p>` : ''}<div class="followup-meta">${row.followup_at ? `ติดตามครั้งถัดไป ${esc(formatDate(row.followup_at))}` : 'ไม่ได้กำหนดวันติดตาม'}</div>${editable && row.can_edit ? `<div class="followup-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-action="edit-followup" data-id="${Number(row.id)}">แก้ไข</button><button type="button" class="btn btn-sm btn-outline-danger" data-action="delete-followup" data-id="${Number(row.id)}">ลบ</button></div>` : ''}</article>`).join('') || '<p class="text-muted mb-0">ยังไม่มีบันทึกการติดตาม</p>';
    }
    function renderAll(config, tracking) {
        tracking = tracking || { milestones: [], history: [], followups: [], progress: 0 };
        if (config.progress) $(config.progress).text(`${Number(tracking.progress || 0)}%`);
        if (config.summary) renderSummary(config.summary, tracking);
        if (config.milestones) renderMilestones(config.milestones, tracking.milestones);
        if (config.history) renderHistory(config.history, tracking.history);
        if (config.followups) $(config.followups).html(followupCards(tracking.followups, !!config.editable));
        if (config.chart) renderChart(config.chart, tracking.history, tracking.progress);
    }
    window.ProjectTrackingUI = { renderAll, renderHistory, renderMilestones, renderChart, followupCards, formatDate };
})();
