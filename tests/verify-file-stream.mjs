import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { createServer } from 'node:http';
import handler, { verifyTicket } from '../api/file-stream.mjs';

const secret = 'vercel_blob_rw_teststore_test-secret';
const now = Math.floor(Date.now() / 1000);
const payload = { path: 'rmutp/draft/test.pdf', filename: 'test.pdf', download: false, exp: now + 300 };
function sign(data) {
    const encoded = Buffer.from(JSON.stringify(data)).toString('base64url');
    return encoded + '.' + createHmac('sha256', secret).update('pdf-stream-v1:' + encoded).digest('hex');
}
const ticket = sign(payload);
assert.deepEqual(verifyTicket(ticket, secret, now), payload);
assert.throws(() => verifyTicket(ticket, 'wrong', now));
assert.throws(() => verifyTicket(sign({ ...payload, exp: now }), secret, now));
assert.throws(() => verifyTicket(sign({ ...payload, path: '../private.pdf' }), secret, now));
assert.throws(() => verifyTicket(sign({ ...payload, filename: 'x.pdf\r\nX: bad' }), secret, now));
assert.throws(() => verifyTicket(ticket + '.extra', secret, now));

const originalFetch = globalThis.fetch;
const originalSecret = process.env.BLOB_READ_WRITE_TOKEN;
process.env.BLOB_READ_WRITE_TOKEN = secret;
let upstreamCalls = 0;
const bytes = Buffer.alloc(6 * 1024 * 1024, 65);
bytes.write('%PDF-1.7\n');
globalThis.fetch = async (url, options) => {
    upstreamCalls++;
    assert.equal(url, 'https://teststore.private.blob.vercel-storage.com/rmutp/draft/test.pdf');
    assert.equal(options.headers.Authorization, `Bearer ${secret}`);
    assert.equal(options.redirect, 'error');
    const partial = options.headers.Range === 'bytes=0-99';
    const body = partial ? bytes.subarray(0, 100) : bytes;
    return new Response(options.method === 'HEAD' ? null : body, {
        status: partial ? 206 : 200,
        headers: { 'Content-Length': String(body.length), 'Accept-Ranges': 'bytes',
            ...(partial ? { 'Content-Range': `bytes 0-99/${bytes.length}` } : {}) },
    });
};
const server = createServer((req, res) => handler(req, res));
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}/api/file-stream`;
try {
    const denied = await originalFetch(base + '?ticket=invalid');
    assert.equal(denied.status, 403);
    assert.equal(upstreamCalls, 0);
    const full = await originalFetch(base + '?ticket=' + ticket);
    assert.equal(full.status, 200);
    assert.equal(full.headers.get('cache-control'), 'private, no-store');
    assert.deepEqual(Buffer.from(await full.arrayBuffer()), bytes);
    const partial = await originalFetch(base + '?ticket=' + ticket, { headers: { Range: 'bytes=0-99' } });
    assert.equal(partial.status, 206);
    assert.equal((await partial.arrayBuffer()).byteLength, 100);
    const head = await originalFetch(base + '?ticket=' + ticket, { method: 'HEAD' });
    assert.equal(head.headers.get('content-length'), String(bytes.length));
    assert.equal((await head.arrayBuffer()).byteLength, 0);
    const download = await originalFetch(base + '?ticket=' + sign({ ...payload, download: true }));
    assert.equal(download.headers.get('content-disposition'), 'attachment; filename="test.pdf"');
    await download.arrayBuffer();
    console.log('FILE_STREAM_OK: 6 MB intact, ranges, HEAD, downloads and denied/expired/tampered tickets');
} finally {
    globalThis.fetch = originalFetch;
    if (originalSecret === undefined) delete process.env.BLOB_READ_WRITE_TOKEN;
    else process.env.BLOB_READ_WRITE_TOKEN = originalSecret;
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
}
