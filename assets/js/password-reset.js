(() => {
    'use strict';

    const endpoint = `${document.querySelector('meta[name="app-base-url"]')?.content || '/'}api/password-reset.php`;
    let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const message = document.getElementById('passwordResetMessage');

    function showMessage(text, success) {
        if (!message) return;
        message.textContent = text;
        message.hidden = false;
        message.classList.toggle('is-success', success);
        message.classList.toggle('is-error', !success);
    }

    async function submit(form) {
        const button = form.querySelector('button[type="submit"]');
        const original = button?.innerHTML || '';
        if (button) {
            button.disabled = true;
            button.innerHTML = '<span>กำลังดำเนินการ...</span><i class="fa-solid fa-spinner fa-spin"></i>';
        }
        try {
            // The public catalog avoids opening a database-backed session on
            // initial render. Create it only when password recovery is used.
            if (document.body.dataset.page === 'login') {
                const csrfResponse = await fetch(`${document.querySelector('meta[name="app-base-url"]')?.content || '/'}api/csrf-token.php`, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                const csrfResult = await csrfResponse.json();
                if (!csrfResponse.ok || !csrfResult.token) throw new Error('Unable to initialize password recovery.');
                csrfToken = csrfResult.token;
            }
            const payload = Object.fromEntries(new FormData(form).entries());
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify(payload),
            });
            const result = await response.json().catch(() => ({success: false, message: 'ระบบตอบกลับไม่ถูกต้อง'}));
            showMessage(result.message || 'ไม่สามารถดำเนินการได้', Boolean(result.success));
            if (result.success && payload.action === 'request') form.reset();
            if (result.success && payload.action === 'reset') {
                form.hidden = true;
                window.setTimeout(() => { window.location.href = `${document.querySelector('meta[name="app-base-url"]')?.content || '/'}login.php`; }, 1800);
            }
        } catch (error) {
            showMessage('ไม่สามารถเชื่อมต่อระบบได้ กรุณาลองใหม่อีกครั้ง', false);
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = original;
            }
        }
    }

    ['forgotPasswordForm', 'resetPasswordForm', 'loginForgotPasswordForm'].forEach((id) => {
        document.getElementById(id)?.addEventListener('submit', (event) => {
            event.preventDefault();
            submit(event.currentTarget);
        });
    });
})();
