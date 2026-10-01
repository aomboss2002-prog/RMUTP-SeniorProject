import { createHmac, timingSafeEqual } from 'node:crypto';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

export function verifyTicket(ticket, secret, now = Math.floor(Date.now() / 1000)) {
    if (!secret || typeof ticket !== 'string' || ticket.length > 4096) throw new Error('Invalid ticket');
    const parts = ticket.split('.');
    if (parts.length !== 2 || !/^[a-f0-9]{64}$/.test(parts[1])) throw new Error('Invalid ticket');
    const signature = createHmac('sha256', secret).update('pdf-stream-v1:' + parts[0]).digest();
    if (!timingSafeEqual(signature, Buffer.from(parts[1], 'hex'))) throw new Error('Invalid ticket');
    const data = JSON.parse(Buffer.from(parts[0], 'base64url').toString('utf8'));
    if (!Number.isInteger(data.exp) || data.exp <= now || data.exp > now + 300
        || typeof data.download !== 'boolean'
        || typeof data.path !== 'string' || !/^[A-Za-z0-9_/-]+\/[A-Za-z0-9._-]+\.pdf$/i.test(data.path)
        || data.path.split('/').some(part => part === '..' || part === '.')
        || typeof data.filename !== 'string' || !/^[A-Za-z0-9._-]+\.pdf$/i.test(data.filename)) {
        throw new Error('Invalid ticket');
    }
    return data;
}

export default async function handler(req, res) {
    res.setHeader('Cache-Control', 'private, no-store');
    res.setHeader('Referrer-Policy', 'no-referrer');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    if (!['GET', 'HEAD'].includes(req.method)) {
        res.setHeader('Allow', 'GET, HEAD');
        res.statusCode = 405;
        return res.end();
    }
    const secret = process.env.BLOB_READ_WRITE_TOKEN || '';
    let ticket;
    try {
        ticket = verifyTicket(new URL(req.url, 'https://localhost').searchParams.get('ticket'), secret);
    } catch {
        res.statusCode = 403;
        return res.end('Access denied. Open the document again to renew access.');
    }
    const store = secret.split('_')[3];
    if (!/^[A-Za-z0-9]+$/.test(store || '')) {
        res.statusCode = 503;
        return res.end('Storage unavailable.');
    }
    const controller = new AbortController();
    res.on('close', () => controller.abort());
    try {
        const headers = { Authorization: `Bearer ${secret}`, 'Accept-Encoding': 'identity' };
        // Forward only a valid single range, never user-supplied destinations or credentials.
        if (/^bytes=(\d+-\d*|-\d+)$/.test(req.headers.range || '')) headers.Range = req.headers.range;
        const url = `https://${store}.private.blob.vercel-storage.com/${ticket.path.split('/').map(encodeURIComponent).join('/')}`;
        const upstream = await fetch(url, { method: req.method, headers, redirect: 'error', signal: controller.signal });
        if (![200, 206, 416].includes(upstream.status)) {
            await upstream.body?.cancel();
            res.statusCode = upstream.status === 404 ? 404 : 502;
            return res.end('Unable to read document.');
        }
        res.statusCode = upstream.status;
        for (const name of ['content-length', 'content-range', 'accept-ranges']) {
            const value = upstream.headers.get(name);
            if (value !== null) res.setHeader(name, value);
        }
        res.setHeader('Content-Type', 'application/pdf');
        res.setHeader('Content-Disposition', `${ticket.download ? 'attachment' : 'inline'}; filename="${ticket.filename}"`);
        if (req.method === 'HEAD' || !upstream.body) return res.end();
        // Real Node response streaming, not a buffered PHP/Lambda response.
        await pipeline(Readable.fromWeb(upstream.body), res);
    } catch {
        if (!res.headersSent) {
            res.statusCode = 502;
            res.end('Unable to read document.');
        } else res.destroy();
    }
}
