/**
 * Service Worker for MTCC Print Services PWA
 *
 * Strategy:
 * - Network-first for HTML (always get fresh content, fall back to cache when offline)
 * - Cache-first for static assets (CSS, JS, images) — fast + offline-friendly
 * - No caching for API requests (POSTs always go to network)
 *
 * Cache is versioned. Bump CACHE_VERSION on deploy to invalidate old caches.
 */

const CACHE_VERSION = 'mtcc-v7';
const STATIC_CACHE = 'mtcc-static-' + CACHE_VERSION;
const RUNTIME_CACHE = 'mtcc-runtime-' + CACHE_VERSION;

// Minimal pre-cache — just the shell that's needed for the first load offline
const PRECACHE_URLS = [
  '/logo.png',
  '/mtcc-ps-logo.png',
  '/manifest.json',
];

// Install: cache the shell
self.addEventListener('install', event => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(STATIC_CACHE).then(cache => cache.addAll(PRECACHE_URLS)).catch(() => {})
  );
});

// Activate: clean up old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => {
      return Promise.all(
        keys.filter(k => !k.endsWith(CACHE_VERSION)).map(k => caches.delete(k))
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch strategy
self.addEventListener('fetch', event => {
  const req = event.request;
  const url = new URL(req.url);

  // Only handle same-origin requests
  if (url.origin !== location.origin) return;

  // Never cache non-GET requests
  if (req.method !== 'GET') return;

  // Never cache API calls / AJAX endpoints (detected by query params or POST-like URLs)
  if (url.pathname.includes('check_new_orders') ||
      url.pathname.includes('api.php') ||
      url.pathname.includes('/api/')) {
    return; // let it go to network normally
  }

  // Static assets: cache-first
  if (/\.(css|js|png|jpg|jpeg|gif|svg|woff2?|ttf|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then(cached => {
        if (cached) return cached;
        return fetch(req).then(res => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(RUNTIME_CACHE).then(cache => cache.put(req, copy));
          }
          return res;
        }).catch(() => cached);
      })
    );
    return;
  }

  // HTML / PHP pages: network-first, cache fallback
  if (req.mode === 'navigate' || url.pathname.endsWith('.php') || url.pathname === '/') {
    event.respondWith(
      fetch(req).then(res => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(RUNTIME_CACHE).then(cache => cache.put(req, copy));
        }
        return res;
      }).catch(() => {
        return caches.match(req).then(cached => {
          if (cached) return cached;
          // Offline fallback — just return the admin-orders page from cache
          return caches.match('/admin-orders.php');
        });
      })
    );
    return;
  }

  // Default: try network, fall back to cache
  event.respondWith(
    fetch(req).catch(() => caches.match(req))
  );
});

// Listen for messages from the page (e.g., to skipWaiting on update)
self.addEventListener('message', event => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
