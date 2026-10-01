import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('assets/js/student.js', 'utf8');
const requests = [];
const operations = [];
const values = { '#reportFrom': '2026-09-01', '#reportTo': '2026-09-02' };
const dom = new Map();
function $(selector) {
    return {
        val: () => values[selector],
        text: value => dom.set(selector, value),
        html: value => { operations.push('html:' + selector); dom.set(selector, value); },
        DataTable: () => ({ destroy: () => operations.push('destroy') }),
    };
}
$.fn = { DataTable: { isDataTable: () => true } };
const App = {
    api: (resource, options) => {
        const request = { resource, options };
        const chain = { done: fn => { request.done = fn; return chain; }, fail: fn => { request.fail = fn; return chain; } };
        requests.push(request);
        return chain;
    },
    escapeHtml: value => String(value ?? '').replaceAll('<', '&lt;'),
    badge: value => value,
    toast: () => operations.push('toast'),
    enhanceTable: () => operations.push('enhance'),
};
const context = vm.createContext({ $, App, renderChart: () => {}, countBy: () => ({}) });
vm.runInContext(source.slice(source.indexOf('    let timelineRevision'), source.indexOf('    function countBy')), context);
vm.runInContext('loadReports()', context);
assert.equal(requests[0].resource, 'reports');
assert.equal(requests[0].options.query.from, values['#reportFrom']);
assert.equal(requests[0].options.query.to, values['#reportTo']);
requests[0].done({ data: { projects: [], documents: [] } });
assert.deepEqual(operations.slice(0, 3), ['destroy', 'html:#reportsTable tbody', 'enhance']);
values['#reportFrom'] = '2026-09-03';
vm.runInContext('loadReports()', context);
assert.equal(requests.length, 1, 'Reversed dates must not call API');
vm.runInContext('renderProjectTimeline("P1"); renderProjectTimeline("P2")', context);
requests[2].done({ data: [{ step: '<script>Draft', created_at: '2026-09-02', progress: 46, status: 'Review' }] });
const latest = dom.get('#projectTimeline');
assert.ok(latest.includes('&lt;script>Draft'));
requests[1].done({ data: [] });
assert.equal(dom.get('#projectTimeline'), latest, 'Old requests must not overwrite selected project');
vm.runInContext('renderProjectTimeline("P3")', context);
requests[3].done({ data: [] });
assert.ok(dom.get('#projectTimeline').includes('ยังไม่มีประวัติ'));
assert.ok(!source.includes("url: 'api/index.php?resource=upload'"));
assert.ok(source.includes("App.api('upload', { method: 'POST', formData,"));
console.log('ADMIN_WORKFLOW_UI_OK: date query, table rebuild, timeline races/escaping/empty state and upload API path');
