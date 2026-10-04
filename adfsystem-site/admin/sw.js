/*
 * Service worker aplikasi ADF Store Admin (scope /admin/).
 * Halaman admin berisi data sensitif → TIDAK disimpan di cache; selalu diambil dari server.
 * Yang di-cache hanya ikon & halaman "offline" kecil untuk saat koneksi putus.
 */
const CACHE = 'adf-admin-v1';
const OFFLINE_URL = 'offline.html';
const PRECACHE = [OFFLINE_URL, 'app-icons/icon-192.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  // Hanya navigasi halaman (GET) yang diberi cadangan offline; semua permintaan lain langsung ke jaringan.
  if (req.mode === 'navigate' && req.method === 'GET') {
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
  }
});
