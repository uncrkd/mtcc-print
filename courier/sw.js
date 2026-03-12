// MTCC Courier Service Worker (stub)
// Enables "Add to Home Screen" on mobile devices
// Full offline caching will be added in future phases

var CACHE_NAME = 'mtcc-courier-v1';

self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});

self.addEventListener('fetch', function(event) {
    // Pass through all requests for now
    event.respondWith(fetch(event.request));
});
