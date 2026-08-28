(function ($) {
    'use strict';

    function renderList(selector, rows, renderer) {
        $(selector).html(rows.map(renderer).join('') || '<div class="list-group-item text-muted">ไม่มีข้อมูล</div>');
    }

    function chart(id, type, labels, values, colors) {
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
                    <strong>ยังไม่มีข้อมูลสำหรับแสดงผล</strong>
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
                plugins: { legend: { display: type !== 'bar', position: 'bottom' } },
                scales: type === 'bar' ? {
                    x: { grid: { display: false }, ticks: { precision: 0 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                } : undefined
            }
        });
    }

    function loadDashboard() {
        App.showLoader(true);
        App.api('dashboard').done(function (response) {
            const data = response.data;
            Object.keys(data.summary).forEach((key) => $(`[data-summary="${key}"]`).text(data.summary[key]));

            const statuses = data.project_status || {};
            chart('projectStatusChart', 'doughnut', Object.keys(statuses).map(App.label), Object.values(statuses), ['#0B3C8C', '#F4C542', '#0F7C9F', '#168A4A', '#64748B']);

            const uploads = data.uploads || {};
            chart('uploadChart', 'bar', Object.keys(uploads).map(App.label), Object.values(uploads), ['#0B3C8C', '#F4C542', '#168A4A']);

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

            bindDashboardSearch(data.students, data.projects);
        }).always(() => App.showLoader(false));
    }

    function bindDashboardSearch(students, projects) {
        $('#dashboardSearch').off('input').on('input', function () {
            const keyword = $(this).val().trim().toLowerCase();
            const $results = $('#dashboardSearchResults');
            if (!keyword) {
                $results.hide().empty();
                return;
            }
            const studentMatches = students.filter((row) => `${row.code} ${row.first_name} ${row.last_name} ${row.major}`.toLowerCase().includes(keyword)).slice(0, 4);
            const projectMatches = projects.filter((row) => `${row.code} ${row.title} ${row.student_name}`.toLowerCase().includes(keyword)).slice(0, 4);
            const html = [
                ...studentMatches.map((row) => `<a class="search-result" href="${App.url(`admin/students/detail.php?id=${row.id}`)}"><span>${App.escapeHtml(row.first_name)} ${App.escapeHtml(row.last_name)}</span><small>${App.escapeHtml(row.code)}</small></a>`),
                ...projectMatches.map((row) => `<a class="search-result" href="${App.url('admin/page.php?view=projects')}"><span>${App.escapeHtml(row.title)}</span><small>${App.escapeHtml(row.code)}</small></a>`)
            ].join('');
            $results.html(html || '<div class="search-result text-muted">ไม่พบข้อมูล</div>').show();
        });
    }

    $(document).on('click', '[data-action="refresh-dashboard"]', loadDashboard);

    $(function () {
        if ($('body').data('page') === 'dashboard') {
            loadDashboard();
        }
    });
})(jQuery);
