// Stoop service worker: makes the site installable and shows an offline page instead of
// the browser's error when there's no connection. Pages themselves are never cached.
const OFFLINE = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open('stoop-v1').then((cache) => cache.add(OFFLINE)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') return;
    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));
});
