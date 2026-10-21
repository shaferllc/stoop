// Stoop service worker: makes the site installable and shows an offline page instead of
// the browser's error when there's no connection. Pages themselves are never cached.
const OFFLINE = '/offline.html';
const VERSION = 'stoop-v2';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.add(OFFLINE)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') return;
    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE)));
});

// Push notifications: show them, and open the right page when tapped.
self.addEventListener('push', (event) => {
    const data = event.data ? event.data.json() : {};
    event.waitUntil(self.registration.showNotification(data.title || 'Stoop', {
        body: data.body || '',
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/';
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((w) => w.url.startsWith(self.location.origin));
        return open ? open.navigate(url).then((w) => w.focus()) : self.clients.openWindow(url);
    }));
});
