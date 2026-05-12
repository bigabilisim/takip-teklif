const APP_VERSION = '2.0.61';
const CACHE_NAME = `yenileme-pwa-v${APP_VERSION}`;
const CORE_ASSETS = [
    '/offline.html',
    '/assets/app.css',
    '/assets/app.js',
    '/assets/pwa-icon.svg'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(CORE_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') {
        return;
    }

    const url = new URL(event.request.url);
    const cacheable = url.origin === self.location.origin && (
        url.pathname.startsWith('/assets/')
        || url.pathname === '/offline.html'
        || url.pathname === '/manifest.webmanifest'
    );

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (cacheable && response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
                }
                return response;
            })
            .catch(() => {
                if (event.request.mode === 'navigate') {
                    return caches.match('/offline.html');
                }
                return caches.match(event.request);
            })
    );
});

self.addEventListener('push', (event) => {
    const fallback = {
        title: 'Yenileme Takip Sistemi',
        body: 'Yeni yenileme bildirimi var.',
        url: '/renewals'
    };

    let data = fallback;
    if (event.data) {
        try {
            data = { ...fallback, ...event.data.json() };
        } catch (_) {
            data = { ...fallback, body: event.data.text() || fallback.body };
        }
    }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/assets/pwa-icon.svg',
            badge: '/assets/pwa-icon.svg',
            tag: data.tag || ('takip-event-' + Date.now()),
            renotify: true,
            data: { url: data.url || '/renewals' }
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = new URL(event.notification.data?.url || '/renewals', self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            for (const client of clients) {
                if (client.url === targetUrl && 'focus' in client) {
                    return client.focus();
                }
            }
            return self.clients.openWindow(targetUrl);
        })
    );
});
