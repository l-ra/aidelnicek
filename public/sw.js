/**
 * Service Worker pro Aidelnicek (M1 — placeholder pro M6 Web Push)
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', () => {
    self.clients.claim();
});
