// Service worker: caches the app shell so it opens fast and shows an offline page without signal.
// Pages and API calls always go to the network first, so payroll data is never stale.
const VERSION = 'payroll-v1';
const SHELL = ['offline.html', 'assets/vendor/bootstrap/bootstrap.min.css?v=5.3.3', 'assets/vendor/bootstrap/bootstrap.bundle.min.js?v=5.3.3', 'assets/app.css?v=1', 'assets/app.js?v=1', 'icons/icon-192.png', 'manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return; // never cache writes
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }
  if (/\/(assets|icons)\//.test(url.pathname) || url.pathname.endsWith('manifest.webmanifest')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => {
      const copy = res.clone();
      caches.open(VERSION).then((c) => c.put(req, copy));
      return res;
    })));
  }
});
