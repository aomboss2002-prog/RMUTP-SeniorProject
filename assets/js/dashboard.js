(function ($) {
    'use strict';

    let dashboardLoading = false;
    let dashboardUpdatedAt = null;
    const statusColors = ['#0B3C8C', '#F4C542', '#0F7C9F', '#168A4A', '#64748B'];
    const formatCount = (value) => Number(value).toLocaleString('th-TH');

    function dashboardSummary(data) {
        const summary = { ...(data.summary || {}) };
        const count = value => value !== null && value !== undefined && value !== ''
            && Number.isSafeInteger(Number(value)) && Number(value) >= 0 ? Number(value) : null;
        const students = count(summary.students);
        const advisors = count(summary.advisors);
        const projects = count(summary.projects);
        // Support older API responses without treating unavailable values as zero.
        if (count(summary.users) === null && students !== null && advisors !== null) summary.users = students + advisors;
        if (count(summary.completed) === null && data.project_status && typeof data.project_status === 'object') {
            summary.completed = Object.hasOwn(data.project_status, 'Completed') ? count(data.project_status.Completed) : 0;
        }
        const completed = count(summary.completed);
        if (count(summary.in_progress) === null && projects !== null && completed !== null && completed <= projects) summary.in_progress = projects - completed;
        return summary;
    }

    function renderList(selector, rows, renderer) {
        $(selector).html(rows.map(renderer).join('') || '<div class="list-group-item text-muted">ไม่มีข้อมูล</div>');
    }

    function chart(id, type, labels, values, colors, showLegend = type !== 'bar') {
        const ctx = document.getElementById(id);
        if (!ctx || !window.Chart) {
            return;
        }
        if (App.state.charts[id]) {
            App.state.charts[id].destroy();
            delete App.state.charts[id];
        }

        const $chartBox = $(ctx).closest('.chart-box');
        $chartBox.find('.dashboard-empty-state').remove();
        const hasChartData = values.some((value) => Number(value) > 0);
        if (!hasChartData) {
            ctx.hidden = true;
            $chartBox.append(`
                <div class="dashboard-empty-state" role="status">
                    <i class="fa-solid fa-chart-simple" aria-hidden="true"></i>
                    <strong>${id === 'projectStatusChart' ? 'ยังไม่มีโครงงานในระบบ' : 'ยังไม่มีข้อมูลสำหรับแสดงผล'}</strong>
                    <span>กราฟจะอัปเดตเมื่อมีข้อมูลในระบบ</span>
                </div>`);
            return;
        }

        ctx.hidden = false;
        App.state.charts[id] = new Chart(ctx, {
            type,
            data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: showLegend, position: 'bottom' } },
                scales: type === 'bar' ? {
                    x: { grid: { display: false }, ticks: { precision: 0 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                } : undefined
            }
        });
    }

    function formatDashboardDate(value) {
        const normalized = String(value || '').replace(' ', 'T');
        const date = new Date(normalized);
        if (!value || Number.isNaN(date.getTime())) {
            return String(value || '');
        }
        return date.toLocaleString('th-TH', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function renderRiskOverview(riskOverview) {
        const risk = riskOverview || {};
        const counts = Object.assign({ low: 0, watch: 0, high: 0, critical: 0 }, risk.counts || {});
        const levels = ['low', 'watch', 'high', 'critical'];
        const values = levels.map((level) => Number(counts[level]) || 0);
        const total = Number(risk.total) || values.reduce((sum, value) => sum + value, 0);
        const attention = values[2] + values[3];

        $('#riskCalculatedTotal').text(total.toLocaleString('th-TH'));
        $('#riskNeedsAttention').text(attention.toLocaleString('th-TH'));
        levels.forEach((level, index) => $(`[data-risk-count="${level}"]`).text(values[index].toLocaleString('th-TH')));
        $('#riskLatestCalculated').text(
            risk.latest_calculated_at
                ? `คำนวณล่าสุด ${formatDashboardDate(risk.latest_calculated_at)}`
                : 'ยังไม่มีผลการประเมินจาก AI'
        );
        $('#riskOverviewCard').attr('aria-busy', 'false').toggleClass('has-attention', attention > 0);

        chart(
            'riskDistributionChart',
            'doughnut',
            ['Low', 'Watch', 'High', 'Critical'],
            values,
            ['#168A4A', '#D59A00', '#D45A1F', '#8F1D2C'],
            false
        );
    }

    function loadDashboard() {
        if (dashboardLoading) return;
        dashboardLoading = true;
        $('[data-action="refresh-dashboard"]').prop('disabled', true);
        $('#dashboardSummary, #riskOverviewCard').attr('aria-busy', 'true');
        $('#dashboardUpdateStatus').removeClass('text-danger').text(dashboardUpdatedAt ? 'กำลังอัปเดตข้อมูล…' : 'กำลังโหลดข้อมูล…');
        App.showLoader(true);
        App.api('dashboard', { silentErrors: true }).done(function (response) {
            const data = response.data;
            const summary = dashboardSummary(data);
            ['users', 'students', 'advisors', 'projects', 'in_progress', 'completed'].forEach((key) => {
                const value = summary[key];
                $(`[data-summary="${key}"]`).text(value === null || value === undefined ? '—' : formatCount(value));
            });

            const statuses = data.project_status || {};
            chart('projectStatusChart', 'doughnut', Object.keys(statuses).map(App.label), Object.values(statuses), statusColors, false);
            $('#projectStatusLegend').html(Object.entries(statuses).map(([status, count], index) => `
                <li><span><i aria-hidden="true" style="background:${statusColors[index % statusColors.length]}"></i>${App.escapeHtml(App.label(status))}</span><strong>${formatCount(count)} <small>โครงงาน</small></strong></li>`).join('') || '<li>ยังไม่มีโครงงานในระบบ</li>');

            const uploads = data.uploads || {};
            chart('uploadChart', 'bar', Object.keys(uploads).map(App.label), Object.values(uploads), ['#0B3C8C', '#F4C542', '#168A4A']);

            renderRiskOverview(data.risk_overview);

            renderList('#recentActivities', data.activities, (row) => `
                <div class="list-group-item">
                    <strong>${App.escapeHtml(row.title)}</strong>
                    <span class="d-block text-muted">${App.escapeHtml(row.actor)} · ${App.escapeHtml(row.created_at)}</span>
                </div>`);

            renderList('#recentNotifications', data.notifications, (row) => `
                <a class="list-group-item" href="${App.url('admin/page.php?view=notifications')}">
                    <strong>${App.escapeHtml(row.title)}</strong>
                    <span class="d-block text-muted">${App.escapeHtml(row.message)}</span>
                </a>`);

            // Destroy before replacing rows; DataTables otherwise restores stale cached rows.
            ['#latestFilesTable', '#pendingApprovalsTable'].forEach((selector) => {
                if ($.fn.DataTable && $.fn.DataTable.isDataTable($(selector))) {
                    $(selector).DataTable().destroy();
                }
            });
            $('#latestFilesTable tbody').html(data.files.map((row) => `
                <tr><td>${App.escapeHtml(row.title)}</td><td>${App.escapeHtml(App.label(row.type))}</td><td>${App.badge(row.status)}</td></tr>`).join(''));
            App.enhanceTable('#latestFilesTable', { searching: false, pageLength: 5 });

            $('#pendingApprovalsTable tbody').html(data.approvals.map((row) => `
                <tr>
                    <td>${App.escapeHtml(row.step)}</td>
                    <td>${App.escapeHtml(row.reviewer)}</td>
                    <td>${App.badge(row.status)}</td>
                    <td>${App.escapeHtml(row.created_at)}</td>
                </tr>`).join(''));
            App.enhanceTable('#pendingApprovalsTable', { searching: false, pageLength: 5 });
            dashboardUpdatedAt = new Date().toISOString();
            $('#dashboardUpdateStatus').text(`อัปเดตล่าสุด ${formatDashboardDate(dashboardUpdatedAt)}`);
        }).fail(function () {
            $('#dashboardUpdateStatus').addClass('text-danger').text(dashboardUpdatedAt
                ? `อัปเดตไม่สำเร็จ · แสดงข้อมูลล่าสุด ${formatDashboardDate(dashboardUpdatedAt)} · กดรีเฟรชเพื่อลองอีกครั้ง`
                : 'ไม่สามารถโหลดข้อมูลได้ · กดรีเฟรชเพื่อลองอีกครั้ง');
            if (!dashboardUpdatedAt) {
                $('#dashboardSummary [data-summary]').text('—');
                $('#projectStatusLegend').html('<li>ไม่สามารถโหลดข้อมูลสถานะโครงงานได้</li>');
                $('#riskLatestCalculated').text('ไม่สามารถโหลดข้อมูลได้');
            }
        }).always(function () {
            dashboardLoading = false;
            $('[data-action="refresh-dashboard"]').prop('disabled', false);
            $('#dashboardSummary, #riskOverviewCard').attr('aria-busy', 'false');
            App.showLoader(false);
        });
    }

    function bindDashboardSearch() {
        let timer;
        let pending;
        let revision = 0;
        $('#dashboardSearch').off('input').on('input', function () {
            const keyword = $(this).val().trim();
            const currentRevision = ++revision;
            clearTimeout(timer);
            if (pending) pending.abort();
            const $results = $('#dashboardSearchResults');
            $results.hide().empty();
            if (!keyword) {
                return;
            }
            timer = setTimeout(() => {
            pending = App.api('dashboard', { query: { action: 'search', q: keyword } }).done((response) => {
            if (currentRevision !== revision) return;
            const studentMatches = response.data.students || [];
            const projectMatches = response.data.projects || [];
            const html = [
                ...studentMatches.map((row) => `<a class="search-result" href="${App.url(`admin/students/detail.php?id=${encodeURIComponent(row.id)}`)}"><span>${App.escapeHtml(row.first_name)} ${App.escapeHtml(row.last_name)}</span><small>${App.escapeHtml(row.code)}</small></a>`),
                ...projectMatches.map((row) => `<a class="search-result" href="${App.url('admin/page.php?view=projects')}"><span>${App.escapeHtml(row.title)}</span><small>${App.escapeHtml(row.code)}</small></a>`)
            ].join('');
            $results.html(html || '<div class="search-result text-muted">ไม่พบข้อมูล</div>').show();
            });
            }, 300);
        });
    }

    $(document).on('click', '[data-action="refresh-dashboard"]', loadDashboard);

    $(function () {
        if ($('body').data('page') === 'dashboard') {
            bindDashboardSearch();
            loadDashboard();
        }
    });
})(jQuery);
