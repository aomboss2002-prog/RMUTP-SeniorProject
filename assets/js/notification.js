(function ($) {
    'use strict';

    function loadNotifications(showToast) {
        const showDetails = $('body').data('page') === 'notifications';
        App.api('notifications', { query: showDetails ? {} : { summary: 1 } }).done(function (response) {
            $('#notificationCounter').text(response.unread || 0).toggle(response.unread > 0);
            if (showDetails) {
                $('#notificationsTable tbody').html(response.data.map((row) => `
                    <tr>
                        <td>${App.escapeHtml(row.title)}</td>
                        <td>${App.escapeHtml(row.message)}</td>
                        <td>${App.escapeHtml(App.label(row.type))}</td>
                        <td>${row.read ? App.badge('Approved') : App.badge('Pending')}</td>
                        <td>${App.escapeHtml(row.created_at)}</td>
                    </tr>`).join(''));
                App.enhanceTable('#notificationsTable');
            }
            if (showToast && response.unread > 0) {
                App.toast(`มีการแจ้งเตือนที่ยังไม่ได้อ่าน ${response.unread} รายการ`, 'info');
            }
        });
    }

    $(document).on('click', '#notificationBell', function (event) {
        event.preventDefault();
        const targetUrl = App.url('admin/page.php?view=notifications');
        App.api('notifications', { method: 'POST' }).done(function () {
            $('#notificationCounter').text(0).hide();
        }).always(function () {
            window.location.href = targetUrl;
        });
    });

    $(document).on('click', '[data-action="mark-all-read"]', function (event) {
        event.preventDefault();
        App.api('notifications', { method: 'POST' }).done(function (response) {
            App.toast(response.message);
            loadNotifications(false);
        });
    });

    $(function () {
        const currentPage = String($('body').data('page') || '');
        if (currentPage === 'login' || currentPage.startsWith('portal-') || currentPage.startsWith('advisor-')) return;
        loadNotifications(false);
        setInterval(() => {
            if (document.visibilityState === 'visible') loadNotifications(true);
        }, 30000);
    });
})(jQuery);
