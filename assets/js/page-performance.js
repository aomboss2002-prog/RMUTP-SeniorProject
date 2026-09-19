/* Opt-in diagnostics: timings only, stored in this browser tab, never sent to a server. */
(() => {
    'use strict';
    if (window.RmutpPerformanceActive) return;
    window.RmutpPerformanceActive = true;
    const key = 'rmutp-page-performance';
    const id = `${Date.now()}-${Math.random().toString(36).slice(2)}`;
    const started = performance.now();
    let lcp = null;
    let stopped = false;
    let observer;
    let host;
    let root;
    let interval;
    let finishTimer;
    let records = [];
    try { records = JSON.parse(sessionStorage.getItem(key) || '[]'); } catch (_error) {}
    if (!Array.isArray(records)) records = [];
    records = records.slice(-49);

    // Keep route/filter names, never student IDs, reset tokens, credentials or signed URLs.
    function safePath(value) {
        try {
            const url = new URL(value, location.href);
            const query = new URLSearchParams();
            for (const name of ['view', 'stage', 'resource']) {
                if (url.searchParams.has(name)) query.set(name, url.searchParams.get(name));
            }
            return `${url.origin === location.origin ? '' : url.origin}${url.pathname}${query.size ? `?${query}` : ''}`;
        } catch (_error) { return '(unknown)'; }
    }
    const round = (value) => Number.isFinite(value) ? Math.round(value) : null;
    const seconds = (value) => value === null ? '—' : `${(value / 1000).toFixed(2)} s`;
    try {
        observer = new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) lcp = round(entry.startTime);
        });
        observer.observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (_error) { /* Unsupported metrics are displayed as unavailable. */ }

    function snapshot() {
        const nav = performance.getEntriesByType('navigation')[0];
        const resources = performance.getEntriesByType('resource')
            .filter((entry) => !entry.name.includes('/page-performance.js'));
        const apis = resources.filter((entry) => ['fetch', 'xmlhttprequest'].includes(entry.initiatorType));
        const row = {
            id, at: new Date().toISOString(), page: safePath(location.href),
            rolePage: document.body?.dataset.page || '',
            observationMs: round(performance.now()),
            navigationType: nav?.type || 'unknown',
            ttfbMs: nav ? round(nav.responseStart - nav.startTime) : null,
            domReadyMs: nav?.domContentLoadedEventEnd > 0 ? round(nav.domContentLoadedEventEnd) : null,
            loadMs: nav?.loadEventEnd > 0 ? round(nav.loadEventEnd) : null,
            lcpMs: lcp,
            completedRequests: resources.length,
            apiRequests: apis.length,
            slowestApiMs: apis.length ? round(Math.max(...apis.map((entry) => entry.duration))) : null,
            knownTransferBytes: resources.reduce((total, entry) => total + (entry.transferSize || 0), 0),
            slowestResources: [...resources].sort((a, b) => b.duration - a.duration).slice(0, 10).map((entry) => ({
                path: safePath(entry.name), type: entry.initiatorType,
                durationMs: round(entry.duration),
                status: entry.responseStatus || null,
                transferBytes: entry.transferSize || 0
            }))
        };
        records = records.filter((record) => record.id !== id).concat(row).slice(-50);
        try { sessionStorage.setItem(key, JSON.stringify(records)); } catch (_error) {}
        return row;
    }

    function table(target, headings, rows) {
        const element = root.querySelector(target);
        element.replaceChildren();
        const header = document.createElement('tr');
        for (const heading of headings) {
            const cell = document.createElement('th'); cell.textContent = heading; header.appendChild(cell);
        }
        element.appendChild(header);
        for (const values of rows) {
            const row = document.createElement('tr');
            for (const value of values) {
                const cell = document.createElement('td'); cell.textContent = String(value); row.appendChild(cell);
            }
            element.appendChild(row);
        }
    }

    function render(row) {
        if (!root) return;
        root.querySelector('#state').textContent = stopped ? 'บันทึกผลแล้ว' : 'กำลังวัด · ช่วงเก็บข้อมูล 15 วินาที';
        root.querySelector('#metrics').textContent = `TTFB ${seconds(row.ttfbMs)} · DOM ${seconds(row.domReadyMs)} · Load ${seconds(row.loadMs)} · LCP ${seconds(row.lcpMs)}`;
        root.querySelector('#network').textContent = `${row.completedRequests} คำขอที่เสร็จแล้ว · API ${row.apiRequests} · API ช้าที่สุด ${seconds(row.slowestApiMs)} · ขนาดรับส่งที่อ่านได้ ${(row.knownTransferBytes / 1024).toFixed(0)} KB`;
        table('#history', ['หน้าที่เปิดล่าสุด', 'TTFB', 'Load', 'LCP'], records.slice(-10).reverse().map((item) => [item.page, seconds(item.ttfbMs), seconds(item.loadMs), seconds(item.lcpMs)]));
        table('#resources', ['ทรัพยากรที่ช้าในหน้านี้', 'เวลา'], row.slowestResources.slice(0, 5).map((item) => [item.path, seconds(item.durationMs)]));
    }

    function mount() {
        host = document.createElement('aside');
        host.style.cssText = 'position:fixed;bottom:12px;right:12px;width:min(680px,calc(100vw - 24px));z-index:2147483647;';
        root = host.attachShadow({ mode: 'open' });
        root.innerHTML = `<style>
            :host{font:13px/1.5 system-ui,sans-serif;color:#172b4d}section{background:#fff;border:1px solid #b8c9de;border-radius:12px;box-shadow:0 8px 32px #0003;padding:14px}header{display:flex;justify-content:space-between;align-items:center;gap:8px}button{font:inherit;cursor:pointer;padding:5px 10px;border:1px solid #bac8db;border-radius:6px;background:#f4f7fb;color:#172b4d}nav{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0}#details{max-height:55vh;overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}td,th{text-align:left;padding:5px;border-bottom:1px solid #e4eaf2}td:first-child{overflow-wrap:anywhere}p{margin:8px 0}small{color:#536780}#metrics{font-weight:600}[hidden]{display:none!important}
        </style><section><header><strong>ตรวจเวลาโหลดหน้าเว็บ</strong><button id="toggle" aria-expanded="true">ย่อ</button></header>
        <div id="details"><p id="state"></p><p id="metrics"></p><p id="network"></p>
        <small>TTFB = รอข้อมูลแรกจากเซิร์ฟเวอร์ · Load = เหตุการณ์โหลดหน้า · LCP = ส่วนเนื้อหาหลักปรากฏ<br>Load ไม่รวมการรอ API ทั้งหมด ค่า LCP อาจรวมแผงตรวจนี้ ขนาด 0 อาจเกิดจากแคชหรือข้อจำกัดข้ามโดเมน ผลนี้ไม่ใช่คะแนน Lighthouse</small>
        <nav><button id="download">ดาวน์โหลด JSON</button><button id="clear">ล้างผล</button><button id="stop">ปิดโหมดตรวจ</button></nav>
        <table id="history"></table><p><small>เก็บ 50 ครั้งล่าสุดในแท็บนี้ แสดง 10 ครั้งล่าสุด</small></p><table id="resources"></table></div></section>`;
        document.body.appendChild(host);
        root.querySelector('#toggle').onclick = () => {
            const details = root.querySelector('#details'); details.hidden = !details.hidden;
            root.querySelector('#toggle').textContent = details.hidden ? 'ขยาย' : 'ย่อ';
            root.querySelector('#toggle').setAttribute('aria-expanded', String(!details.hidden));
        };
        root.querySelector('#download').onclick = () => {
            const url = URL.createObjectURL(new Blob([JSON.stringify({ version: 1, note: 'Browser timing samples; not end-to-end data readiness. Cross-origin byte sizes may be unavailable.', records }, null, 2)], { type: 'application/json' }));
            const link = document.createElement('a'); link.href = url; link.download = 'page-performance.json'; link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        };
        root.querySelector('#clear').onclick = () => { records = []; render(snapshot()); };
        root.querySelector('#stop').onclick = () => {
            finish();
            try { sessionStorage.removeItem('rmutp-perf-enabled'); } catch (_error) {}
            const url = new URL(location.href); url.searchParams.delete('perf'); history.replaceState(null, '', url);
            host.remove();
        };
        render(snapshot());
    }
    function finish() {
        if (stopped) return;
        stopped = true; clearInterval(interval); clearTimeout(finishTimer);
        observer?.disconnect(); render(snapshot());
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true });
    else mount();
    interval = setInterval(() => render(snapshot()), 1000);
    finishTimer = setTimeout(finish, Math.max(0, 15000 - (performance.now() - started)));
    window.addEventListener('pagehide', finish, { once: true });
})();
