// ODIR HR service worker.
// Purpose: PWA installability only. Every request goes straight to the
// network; nothing is cached, so payroll and company data never sit in
// an offline store on the device.
self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// An empty fetch listener satisfies the installability requirement while
// leaving all traffic on the network exactly as if no worker existed.
self.addEventListener('fetch', () => {});
