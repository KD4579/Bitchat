self.addEventListener('install', (e) => {
  console.log('SW Installed');
});
self.addEventListener('activate', (e) => {
  console.log('SW Activated');
});
self.addEventListener('fetch', (event) => {
  event.respondWith(fetch(event.request));
});
