// MTCC Courier Service Worker — Offline Support
var CACHE_VERSION = 'mtcc-courier-v4';
var APP_SHELL = [
    '/courier/',
    '/courier/app.css',
    '/courier/app.js',
    '/courier/courier-issues.js',
    '/courier/courier-issues.css',
    '/courier/manifest.json',
    '/assets/logo.png'
];

// Install — cache app shell
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_VERSION).then(function(cache) {
            return cache.addAll(APP_SHELL);
        })
    );
    self.skipWaiting();
});

// Activate — clean old caches
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names.filter(function(n) { return n !== CACHE_VERSION; })
                     .map(function(n) { return caches.delete(n); })
            );
        }).then(function() { return clients.claim(); })
    );
});

// Push — handle push notifications (future: VAPID server push)
self.addEventListener('push', function(event) {
    var data = { title: 'MTCC Courier', body: 'New update available' };
    try {
        if (event.data) data = event.data.json();
    } catch (e) {
        if (event.data) data.body = event.data.text();
    }
    event.waitUntil(
        self.registration.showNotification(data.title || 'MTCC Courier', {
            body: data.body || data.message || '',
            icon: '/assets/logo.png',
            badge: '/assets/logo.png',
            tag: data.tag || 'courier-push',
            data: data.context || {}
        })
    );
});

// Notification click — open the app
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window' }).then(function(clientList) {
            for (var i = 0; i < clientList.length; i++) {
                if (clientList[i].url.indexOf('/courier/') !== -1 && 'focus' in clientList[i]) {
                    return clientList[i].focus();
                }
            }
            if (clients.openWindow) return clients.openWindow('/courier/');
        })
    );
});

// Fetch — network first, fall back to cache
self.addEventListener('fetch', function(event) {
    var url = new URL(event.request.url);

    // API calls — network only, cache last response for offline
    if (url.pathname.indexOf('/courier/api.php') !== -1) {
        event.respondWith(
            fetch(event.request.clone()).then(function(response) {
                // Cache successful API responses for offline
                if (response.ok) {
                    var clone = response.clone();
                    caches.open(CACHE_VERSION + '-api').then(function(cache) {
                        cache.put(event.request, clone);
                    });
                }
                return response;
            }).catch(function() {
                // Offline — serve cached API response
                return caches.match(event.request).then(function(cached) {
                    if (cached) return cached;
                    return new Response(JSON.stringify({
                        success: false,
                        error: 'You are offline',
                        offline: true
                    }), { headers: { 'Content-Type': 'application/json' } });
                });
            })
        );
        return;
    }

    // Static assets — network first, cache fallback
    event.respondWith(
        fetch(event.request).then(function(response) {
            if (response.ok) {
                var clone = response.clone();
                caches.open(CACHE_VERSION).then(function(cache) {
                    cache.put(event.request, clone);
                });
            }
            return response;
        }).catch(function() {
            return caches.match(event.request);
        })
    );
});
