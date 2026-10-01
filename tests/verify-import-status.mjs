import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('assets/js/student.js', 'utf8');
const handlers = new Map();
let preview;
let submitted;
function $(target) {
    const chain = {
        on(event, selector, callback) {
            handlers.set(typeof selector === 'string' ? selector : target, callback || selector);
            return chain;
        },
        prop() { return chain; },
        data: key => target[key],
        closest() { return chain; },
        attr() { return chain; },
    };
    return chain;
}
const App = {
    state: { tables: {} }, toast() {}, showLoader() {},
    api(resource, options) {
        if (resource === 'students') return Promise.resolve({ data: [] });
        if (resource === 'import') submitted = options.data.rows;
        const chain = { done() { return chain; }, always() { return chain; } };
        return chain;
    },
};
const majorList = source.match(/const businessMajors = (\[[\s\S]*?\]);/)[1];
const majors = vm.runInNewContext(majorList);
assert.equal(majors.length, 1);
assert.equal(majors[0], 'บธ.บ. สาขาวิชาระบบสารสนเทศและนวัตกรรมดิจิทัล');
const context = vm.createContext({ $, App, document: {}, businessFaculty: 'Faculty', businessMajors: majors,
    renderImportRows: rows => { preview = rows; }, updateImportSummary() {},
    normalizeImportCode: value => value, normalizeImportEmail: value => value,
});
vm.runInContext(source.slice(source.indexOf('    function parseStudentName'), source.indexOf('    function parseCsvLine')), context);
assert.equal(context.buildImportedStudent('076760305001-8', 'Test Student', '').email, '0767603050018@rmutp.ac.th');
assert.equal(context.buildImportedStudent('076760305001-8', 'Test Student', '').major, majors[0]);
assert.ok(source.includes("email: '0767603050018@rmutp.ac.th'"), 'Sample CSV uses university domain');
const importApi = readFileSync('api/index.php', 'utf8');
assert.ok(importApi.includes("$email = strtolower(str_replace('-', '', $code) . '@rmutp.ac.th');"), 'Backend import uses same university domain');
for (const legacy of ['40', '90', '', 'Completed', 'Inactive', '10']) {
    assert.equal(context.buildImportedStudent('076760305001-8', 'Test Student', legacy).status, 'Active');
}
context.parseStudentImportFile = async () => Array.from({ length: 12 }, (_, i) =>
    context.buildImportedStudent(`076760305${String(i).padStart(3, '0')}-8`, 'Test Student', '40'));
vm.runInContext(source.slice(source.indexOf('    function initImport()'), source.indexOf('    function normalizeImportCode')), context);
context.initImport();
await handlers.get('#excelFile').call({ files: [{ name: 'test.xls' }] });
assert.equal(preview.length, 12);
assert.ok(preview.every(row => row.status === 'Active'));
for (const [index, value] of [[0, 'Completed'], [9, 'Inactive']]) {
    const select = { 'import-status': index, value,
        options: ['Active', 'Completed', 'Inactive'].map(value => ({ value })) };
    handlers.get('#importPreviewTable [data-import-status]').call(select);
    assert.equal(preview[index].status, value);
    assert.equal(select.options.find(option => option.defaultSelected).value, value);
}
handlers.get('[data-action="import-preview"]').call({});
assert.equal(submitted[0].status, 'Completed');
assert.equal(submitted[9].status, 'Inactive');
assert.equal(submitted[1].status, 'Active');
assert.ok(submitted.every(row => row.email.endsWith('@rmutp.ac.th')), 'Submitted emails use university domain');
await handlers.get('#excelFile').call({ files: [{ name: 'another.csv' }] });
assert.ok(preview.every(row => row.status === 'Active'), 'New file resets defaults');
console.log('IMPORT_STATUS_OK: Active defaults, per-row selection, submitted payload, later rows and file reset');
