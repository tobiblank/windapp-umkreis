// Wohinfahrma – Service Worker
// Vorbild: stegen-wind (dort seit 14.10.2026 im Einsatz, Stand 28.10.2026).
// Die Rangliste kommt immer frisch vom Server, nichts davon wird
// zwischengespeichert. Nur wenn das Netz ganz fehlt, zeigt die App statt einer
// Browser-Fehlerseite eine kurze Offline-Seite.
//   - Zweiter Versuch nach kurzer Pause, bevor die Offline-Seite kommt (beim
//     Wechsel Funk/WLAN scheitert der erste Abruf oft nur kurz; nur bei GET).
//   - Die Offline-Seite prueft selbst, ob das Netz zurueck ist, und laedt dann
//     die eigentliche Seite neu (siehe offline.html).

const CACHE = 'wohinfahrma-offline-1';
const OFFLINE = 'offline.html';   // Seite bringt ihre Optik selbst mit

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => c.add(new Request(OFFLINE, { cache: 'reload' })))   // nie eine alte Kopie aus dem HTTP-Cache
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

const pause = (ms) => new Promise((r) => setTimeout(r, ms));

async function seiteHolen(request) {
  try {
    return await fetch(request.clone());
  } catch (err) {
    if (request.method !== 'GET') throw err;
    await pause(1500);
    return fetch(request);
  }
}

self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return;   // alles andere normal aus dem Netz
  e.respondWith(
    seiteHolen(e.request).catch(async () =>
      (await caches.match(OFFLINE)) ||
      new Response('Kein Netz – bitte neu laden.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } })
    )
  );
});
