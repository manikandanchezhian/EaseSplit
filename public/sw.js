// Easesplit service worker: offline app-shell caching + Web Push.
//
// Bump CACHE whenever app.html/this file changes meaningfully -- activate()
// deletes any cache under the old name, so stale entries never linger.
const CACHE = 'easesplit-v2';
const SHELL = ['/app.html', '/manifest.json', '/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  // Never cache API calls -- always go to network for live data.
  if (url.pathname.startsWith('/api/')) return;
  if (event.request.method !== 'GET') return;

  const isAppShell = event.request.mode === 'navigate' || url.pathname === '/app.html';

  if (isAppShell) {
    // Network-first for the app itself: whenever the phone has a
    // connection, load the latest deployed version instead of a
    // potentially stale cached one. Cache is only a fallback for offline
    // opens (e.g. flaky wifi at home).
    event.respondWith(
      fetch(event.request)
        .then((res) => {
          if (res.ok) caches.open(CACHE).then((cache) => cache.put('/app.html', res.clone()));
          return res;
        })
        .catch(() => caches.match('/app.html'))
    );
    return;
  }

  // Static assets (icons, manifest): cache-first, refreshed in the
  // background -- these rarely change and don't need to block on network.
  event.respondWith(
    caches.match(event.request).then((cached) => {
      const network = fetch(event.request)
        .then((res) => {
          if (res.ok) caches.open(CACHE).then((cache) => cache.put(event.request, res.clone()));
          return res;
        })
        .catch(() => cached);
      return cached || network;
    })
  );
});

self.addEventListener('push', (event) => {
  let data = { title: 'Easesplit', body: 'You have a new update.' };
  try { data = event.data.json(); } catch (e) {}

  event.waitUntil(
    self.registration.showNotification(data.title || 'Easesplit', {
      body: data.body || '',
      icon: '/icons/icon-192.png',
      badge: '/icons/icon-192.png',
      data: { url: data.url || '/app.html' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data?.url || '/app.html';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
      for (const client of clients) {
        if ('focus' in client) { client.navigate(url); return client.focus(); }
      }
      if (self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
