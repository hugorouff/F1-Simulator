const CACHE_NAME = 'f1-simulator-v2';
const ASSETS_TO_CACHE = [
  'https://upload.wikimedia.org/wikipedia/commons/3/33/F1.svg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // 1. NE PAS intercepter les routes dynamiques, l'API ou les méthodes non-GET
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/replay') || event.request.method !== 'GET') {
    return; // Laisse le navigateur gérer directement la requête réseau
  }

  // 2. Pour le reste (assets statiques), stratégie Cache First avec repli réseau sécurisé
  event.respondWith(
    caches.match(event.request).then((cachedResponse) => {
      return cachedResponse || fetch(event.request).catch(() => {
        // Évite le crash total si le réseau échoue sur un asset
        return new Response('Hors-ligne', { status: 503, statusText: 'Service Unavailable' });
      });
    })
  );
});
