(function () {
    'use strict';

    const root = document.querySelector('.public-catalog-body');
    if (!root) return;

    const baseUrl = document.querySelector('meta[name="app-base-url"]')?.content || '/';
    const endpoint = `${baseUrl}api/public/index.php`;
    const form = document.getElementById('publicCatalogFilters');
    const search = document.getElementById('catalogSearch');
    const clearSearch = document.getElementById('clearCatalogSearch');
    const resetFilters = document.getElementById('resetCatalogFilters');
    const results = document.getElementById('publicCatalogResults');
    const summary = document.getElementById('catalogResultSummary');
    const pagination = document.getElementById('publicCatalogPagination');
    const completedCount = document.getElementById('publicCompletedCount');
    const passwordInput = document.getElementById('loginPassword');
    const passwordToggle = document.getElementById('toggleLoginPassword');
    let currentPage = 1;
    let requestController = null;
    let debounceTimer = null;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (character) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        })[character]);
    }

    function safeDownloadUrl(value) {
        if (!value) return '';
        try {
            const raw = String(value);
            const appRelative = raw.startsWith('/api/') && baseUrl !== '/'
                ? `${baseUrl.replace(/\/$/, '')}${raw}`
                : raw;
            const parsed = new URL(appRelative, window.location.origin + baseUrl);
            return parsed.origin === window.location.origin ? parsed.href : '';
        } catch (_error) {
            return '';
        }
    }

    function formatDate(value) {
        if (!value) return 'ไม่ระบุวันที่';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return String(value);
        return new Intl.DateTimeFormat('th-TH', { day: 'numeric', month: 'short', year: 'numeric' }).format(parsed);
    }

    function skeletons() {
        return Array.from({ length: 3 }, () => '<div class="catalog-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>').join('');
    }

    function stateMessage(icon, title, message, canRetry) {
        return `<div class="catalog-state">
            <span class="catalog-state__icon"><i class="fa-solid ${icon}" aria-hidden="true"></i></span>
            <h3>${escapeHtml(title)}</h3><p>${escapeHtml(message)}</p>
            ${canRetry ? '<button type="button" id="retryCatalog"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i> ลองใหม่อีกครั้ง</button>' : ''}
        </div>`;
    }

    function renderCard(item, index) {
        const authors = Array.isArray(item.authors) ? item.authors.filter(Boolean).join(', ') : (item.authors || 'ไม่ระบุผู้จัดทำ');
        const downloadUrl = item.available ? safeDownloadUrl(item.download_url) : '';
        const action = downloadUrl
            ? `<a class="download-button" href="${escapeHtml(downloadUrl)}"><i class="fa-solid fa-download" aria-hidden="true"></i> ดาวน์โหลด PDF</a>`
            : '<span class="file-unavailable"><i class="fa-regular fa-clock" aria-hidden="true"></i> ไฟล์ไม่พร้อมให้ดาวน์โหลด</span>';

        return `<article class="thesis-card" style="--item-index:${index}">
            <div class="thesis-card__pdf" aria-hidden="true"><i class="fa-solid fa-file-pdf"></i></div>
            <div class="thesis-card__main">
                <div class="thesis-card__top">
                    <span class="completed-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> ฉบับสมบูรณ์</span>
                    <span class="thesis-card__category">${escapeHtml(item.category || 'โครงงาน')}</span>
                </div>
                <h3>${escapeHtml(item.title || 'ไม่มีชื่อผลงาน')}</h3>
                <p class="thesis-card__authors"><i class="fa-regular fa-user" aria-hidden="true"></i>${escapeHtml(authors)}</p>
                <ul class="thesis-card__meta">
                    <li><i class="fa-solid fa-hashtag" aria-hidden="true"></i>${escapeHtml(item.code || item.document_id || '—')}</li>
                    <li><i class="fa-regular fa-calendar" aria-hidden="true"></i>ปีการศึกษา ${escapeHtml(item.academic_year || '—')}</li>
                    <li><i class="fa-solid fa-building-columns" aria-hidden="true"></i>${escapeHtml(item.faculty || 'ไม่ระบุคณะ')}</li>
                    <li><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>${escapeHtml(item.major || 'ไม่ระบุสาขาวิชา')}</li>
                </ul>
            </div>
            <div class="thesis-card__action">
                <span class="thesis-card__date">เสร็จสมบูรณ์ ${escapeHtml(formatDate(item.completed_at))}</span>
                ${action}
            </div>
        </article>`;
    }

    function fillOptions(selectId, values, emptyLabel) {
        const select = document.getElementById(selectId);
        if (!select) return;
        const current = select.value;
        const unique = Array.from(new Set(Array.isArray(values) ? values.filter(Boolean).map(String) : []));
        select.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>` + unique.map((value) => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`).join('');
        if (unique.includes(current)) select.value = current;
    }

    function populateFilters(filters) {
        if (!filters || typeof filters !== 'object') return;
        fillOptions('catalogYear', filters.years, 'ทุกปีการศึกษา');
        fillOptions('catalogFaculty', filters.faculties, 'ทุกคณะ');
        fillOptions('catalogMajor', filters.majors, 'ทุกสาขาวิชา');
    }

    function renderPagination(pageData) {
        const page = Math.max(1, Number(pageData.page) || 1);
        const totalPages = Math.max(1, Number(pageData.total_pages) || 1);
        if (totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }
        const start = Math.max(1, Math.min(page - 2, Math.max(1, totalPages - 4)));
        const end = Math.min(totalPages, start + 4);
        const buttons = [`<button type="button" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''} aria-label="หน้าก่อนหน้า"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>`];
        for (let value = start; value <= end; value += 1) {
            buttons.push(`<button type="button" data-page="${value}" class="${value === page ? 'is-active' : ''}" ${value === page ? 'aria-current="page"' : ''}>${value}</button>`);
        }
        buttons.push(`<button type="button" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''} aria-label="หน้าถัดไป"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>`);
        pagination.innerHTML = buttons.join('');
    }

    function queryString() {
        const params = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
            const clean = String(value).trim();
            if (clean) params.set(key, clean);
        });
        params.set('page', String(currentPage));
        return params.toString();
    }

    async function loadCatalog() {
        if (requestController) requestController.abort();
        requestController = new AbortController();
        results.setAttribute('aria-busy', 'true');
        results.innerHTML = skeletons();
        pagination.innerHTML = '';
        summary.textContent = 'กำลังโหลดรายการผลงาน...';

        try {
            const response = await fetch(`${endpoint}?${queryString()}`, { headers: { Accept: 'application/json' }, signal: requestController.signal });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (!payload.success || !payload.data) throw new Error(payload.message || 'Invalid response');

            const data = payload.data;
            const items = Array.isArray(data.items) ? data.items : [];
            const pageData = data.pagination || {};
            const total = Math.max(0, Number(pageData.total) || 0);
            populateFilters(data.filters);
            completedCount.textContent = new Intl.NumberFormat('th-TH').format(total);
            summary.innerHTML = total ? `พบ <strong>${new Intl.NumberFormat('th-TH').format(total)}</strong> ผลงานฉบับสมบูรณ์` : 'ไม่พบผลงานที่ตรงกับเงื่อนไข';
            results.innerHTML = items.length ? items.map(renderCard).join('') : stateMessage('fa-folder-open', 'ยังไม่พบผลงาน', 'ลองเปลี่ยนคำค้นหาหรือล้างตัวกรองเพื่อดูรายการอื่น', false);
            renderPagination(pageData);
        } catch (error) {
            if (error.name === 'AbortError') return;
            completedCount.textContent = '—';
            summary.textContent = 'ไม่สามารถโหลดรายการผลงานได้';
            results.innerHTML = stateMessage('fa-triangle-exclamation', 'เชื่อมต่อคลังผลงานไม่สำเร็จ', 'กรุณาตรวจสอบการเชื่อมต่อแล้วลองใหม่อีกครั้ง', true);
        } finally {
            results.setAttribute('aria-busy', 'false');
        }
    }

    function scheduleLoad() {
        window.clearTimeout(debounceTimer);
        debounceTimer = window.setTimeout(() => { currentPage = 1; loadCatalog(); }, 360);
    }

    form.addEventListener('input', (event) => {
        clearSearch.hidden = !search.value;
        if (event.target === search) scheduleLoad();
    });
    form.addEventListener('change', () => { currentPage = 1; loadCatalog(); });
    form.addEventListener('submit', (event) => { event.preventDefault(); currentPage = 1; loadCatalog(); });
    clearSearch.addEventListener('click', () => { search.value = ''; clearSearch.hidden = true; search.focus(); currentPage = 1; loadCatalog(); });
    resetFilters.addEventListener('click', () => { form.reset(); clearSearch.hidden = true; currentPage = 1; loadCatalog(); });
    pagination.addEventListener('click', (event) => {
        const button = event.target.closest('[data-page]');
        if (!button || button.disabled) return;
        currentPage = Number(button.dataset.page) || 1;
        loadCatalog();
        document.getElementById('catalogHeading').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    results.addEventListener('click', (event) => { if (event.target.closest('#retryCatalog')) loadCatalog(); });
    passwordToggle.addEventListener('click', () => {
        const show = passwordInput.type === 'password';
        passwordInput.type = show ? 'text' : 'password';
        passwordToggle.setAttribute('aria-pressed', String(show));
        passwordToggle.setAttribute('aria-label', show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน');
        passwordToggle.querySelector('i').className = show ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
        passwordInput.focus();
    });

    loadCatalog();
})();
