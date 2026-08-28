import { upload } from '@vercel/blob/client';

window.RmutpBlobUpload = async function ({ file, pathname, payload, onProgress }) {
    const base = document.querySelector('meta[name="app-base-url"]')?.content || '/';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    return upload(pathname, file, {
        access: 'private',
        handleUploadUrl: `${base.replace(/\/$/, '')}/api/blob-upload.php`,
        clientPayload: JSON.stringify(payload || {}),
        multipart: file.size > 4 * 1024 * 1024,
        headers: { 'X-CSRF-Token': csrf },
        onUploadProgress: onProgress
    });
};
