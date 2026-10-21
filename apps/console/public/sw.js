/*
 * Service worker ADF Console — supaya bisa dipasang sebagai aplikasi.
 * Halaman (berisi data rahasia) TIDAK pernah disimpan di HP: selalu diambil dari server; bila tidak ada internet
 * tampil offline.html. Yang disimpan hanya file tampilan statis (logo, ikon).
 */
const CACHE = 'adf-console-v1';
const STATIC = ['/offline.html', '/logo.png', '/favicon.svg', '/icon-192.png', '/apple-touch-icon.png'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.addAll(STATIC)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET' || new URL(req.url).origin !== self.location.origin) {
        return;
    }
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match('/offline.html')));
        return;
    }
    if (STATIC.includes(new URL(req.url).pathname)) {
        e.respondWith(caches.match(req).then((hit) => hit || fetch(req)));
    }
});
