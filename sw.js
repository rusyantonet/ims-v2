// Service worker IMS: syarat install PWA + halaman awal tetap terbuka saat sinyal buruk.
// Data (api.php) dan foto (uploads/) TIDAK pernah disimpan di cache, jadi data selalu segar dan aman.
// Naikkan angka VERSION setiap kali Anda mengganti index.html / logo agar perangkat memuat versi terbaru.
const VERSION = 'v1';
const CACHE = 'ims-shell-' + VERSION;
const SHELL = ['./', 'index.html', 'logo.png', 'icon-192.png', 'favicon-32.png', 'site.webmanifest'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
  var r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin) return;              // api.php (POST), Google Fonts, Drive: lewat langsung
  if (/\/(api|install|cek)\.php$/.test(u.pathname) || u.pathname.indexOf('/uploads/') >= 0 || u.pathname.indexOf('/inc/') >= 0) return;
  if (r.mode === 'navigate') {
    if (!/\/(index\.html)?$/.test(u.pathname)) return;                          // hanya halaman aplikasi
    e.respondWith(fetch(r).then(function (res) {
      var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put('index.html', copy); });
      return res;
    }).catch(function () { return caches.match('index.html'); }));
    return;
  }
  e.respondWith(caches.match(r).then(function (hit) {                           // aset statis: pakai cache, perbarui di belakang
    var net = fetch(r).then(function (res) {
      if (res && res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(r, copy); }); }
      return res;
    }).catch(function () { return hit; });
    return hit || net;
  }));
});
