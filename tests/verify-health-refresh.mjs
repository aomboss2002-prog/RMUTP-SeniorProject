import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';

const requests = [];
const timers = new Map();
const messages = new Map();
let timerId = 0;
let renders = 0;
const document = { hidden: false };
const navigator = { onLine: true };
const context = vm.createContext({
    document, navigator,
    setTimeout(fn, delay) { timers.set(++timerId, { fn, delay }); return timerId; },
    clearTimeout(id) { timers.delete(id); },
    $(selector) {
        const chain = { prop: () => chain, find: () => chain, addClass: () => chain,
            removeClass: () => chain, attr: () => chain,
            text(value) { messages.set(selector, value); return chain; } };
        return chain;
    },
    render() { renders++; },
    websiteNotice(message) { messages.set('#healthWebsiteNotice', message); },
    App: { api(resource, options) {
        assert.equal(resource, 'system-health');
        assert.equal(options.silentErrors, true);
        assert.equal(options.method, undefined, 'Automatic refresh must only read, never send email or probe storage');
        const request = {};
        const chain = { done(fn) { request.done = fn; return chain; },
            fail(fn) { request.fail = fn; return chain; }, always(fn) { request.always = fn; return chain; } };
        requests.push(request);
        return chain;
    } },
});
const source = readFileSync('assets/js/system-health.js', 'utf8');
vm.runInContext(source.slice(source.indexOf('    let refreshTimer;'), source.indexOf('    function diagnostic')), context);
const run = code => vm.runInContext(code, context);
const success = () => { const req = requests.at(-1); req.done({ data: {} }); req.always(); };
const failure = status => { const req = requests.at(-1); req.fail({ status }); req.always(); };
run('loadHealth(); loadHealth()');
assert.equal(requests.length, 1, 'No overlapping reads');
success();
assert.equal(renders, 1);
assert.equal([...timers.values()][0].delay, 5000);
run('loadHealth()'); failure(503);
assert.equal([...timers.values()][0].delay, 10000);
run('loadHealth()'); failure(503);
assert.equal([...timers.values()][0].delay, 20000);
document.hidden = true;
run('loadHealth()');
assert.equal(requests.length, 3);
assert.equal(timers.size, 0);
document.hidden = false;
navigator.onLine = false;
run('loadHealth()'); assert.equal(requests.length, 3);
navigator.onLine = true;
run('diagnosticsRunning = 1; loadHealth()'); assert.equal(requests.length, 3);
run('diagnosticsRunning = 0; loadHealth()'); success();
assert.equal([...timers.values()][0].delay, 5000, 'Recover normal interval');
run('loadHealth()'); failure(403);
assert.equal(timers.size, 0);
run('loadHealth()'); assert.equal(requests.length, 5, 'Stop when session expires');
assert.ok(messages.get('#healthAutoStatus').includes('เข้าสู่ระบบ'));
console.log('HEALTH_REFRESH_OK: 5s reads, no overlap, backoff, hidden/offline/diagnostic pause and auth stop');
