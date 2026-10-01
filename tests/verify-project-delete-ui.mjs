import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('assets/js/student.js', 'utf8');
const start = source.indexOf("    $(document).on('click', '[data-action=\"delete-project\"]'");
const end = source.indexOf("    $(document).on('click', '[data-action=\"retry-project-files\"]'", start);
assert.ok(start > 0 && end > start);
let handler;
let dialog;
let confirmation = { isConfirmed: false };
let failure = false;
let reloads = 0;
const requests = [];
const messages = [];
const button = {
    disabled: false,
    prop(key, value) { if (arguments.length === 1) return this[key]; this[key] = value; return this; },
    attr(key) { return key === 'data-id' ? 'PRJ001' : '<script>Test</script>'; }
};
const context = vm.createContext({
    document: {},
    $(target) { return target === button ? button : { on(event, selector, callback) { handler = callback; } }; },
    Swal: { async fire(options) { dialog = options; return confirmation; } },
    App: {
        async api(resource, options) {
            requests.push({ resource, options });
            if (failure) throw { responseJSON: { message: 'Rejected safely' } };
            return { success: true, message: 'Deleted', data: { pending_files: 0 } };
        },
        toast(...args) { messages.push(args); }
    },
    loadProjectsTable() { reloads++; }
});
vm.runInContext(source.slice(start, end), context);
await handler.call(button);
assert.equal(requests.length, 0, 'Cancel must not delete');
assert.equal(button.disabled, false);
assert.equal(dialog.html, undefined, 'Project name is plain text, not injected HTML');
assert.equal(dialog.inputValidator('PRJ001'), undefined);
assert.ok(dialog.inputValidator('PRJ002'));
confirmation = { isConfirmed: true, value: 'PRJ001' };
await handler.call(button);
assert.equal(requests.length, 1);
assert.equal(requests[0].resource, 'projects');
assert.equal(requests[0].options.method, 'DELETE');
assert.equal(requests[0].options.query.id, 'PRJ001');
assert.equal(requests[0].options.data.confirm_id, 'PRJ001');
assert.equal(reloads, 1);
failure = true;
await handler.call(button);
assert.equal(reloads, 1, 'Failed request must not report success');
assert.equal(button.disabled, false, 'Allow retry after failure');
assert.equal(messages.at(-1)[0], 'Rejected safely');
button.disabled = true;
await handler.call(button);
assert.equal(requests.length, 2, 'Repeated click while busy ignored');
console.log('PROJECT_DELETE_UI_OK: cancel, typed ID, escaped title, request, failure and duplicate-click guard');
