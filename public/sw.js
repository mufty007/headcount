/**
 * Service Worker — IMCA Portal PWA
 * Network-first for API; cache-first for shell assets; offline fallback.
 */

const CACHE_NAME = 'imca-portal-v1';

function portalBaseFromScope() {
  try {
    const u = new URL(self.registration.scope);
    // scope ends with /portal/ typically
    return u.pathname.replace(/\/?$/, '/');
  } catch (e) {
    return '/portal/';
  }
}

function appRootFromScope() {
  const portal = portalBaseFromScope();
  return portal.replace(/\/portal\/?$/, '/') || '/';
}

self.addEventListener('install', (event) => {
  const portal = portalBaseFromScope();
  const root = appRootFromScope();
  const urlsToCache = [
    portal,
    portal + 'events.php',
    portal + 'offline.php',
    root + 'public/css/tailwind-output.css',
    root + 'public/css/modern-design.css',
    root + 'public/css/modal.css',
  ].map((u) => u.replace(/\/{2,}/g, '/').replace(':/', '://'));

  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      Promise.allSettled(urlsToCache.map((url) => cache.add(url).catch(() => null)))
    ).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Never cache API
  if (url.pathname.includes('/api/')) {
    event.respondWith(
      fetch(req).catch(() => new Response(JSON.stringify({ success: false, offline: true }), {
        status: 503,
        headers: { 'Content-Type': 'application/json' },
      }))
    );
    return;
  }

  // Documents: network first, offline offline page
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
          return res;
        })
        .catch(() =>
          caches.match(req).then((cached) =>
            cached || caches.match(portalBaseFromScope() + 'offline.php')
          )
        )
    );
    return;
  }

  // Static assets: cache first, then network
  event.respondWith(
    caches.match(req).then((cached) => {
      if (cached) return cached;
      return fetch(req).then((res) => {
        if (res && res.status === 200 && res.type === 'basic') {
          const copy = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      }).catch(() => cached);
    })
  );
});
