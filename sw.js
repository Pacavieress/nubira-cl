// Nubira Service Worker — unificado con OneSignal
// Versión de cache — incrementar para invalidar assets
const CACHE_VERSION = 'nubira-v1';
const STATIC_ASSETS = [
    '/manifest.json',
    '/img/icon-192.png',
    '/img/icon-512.png',
    '/img/logo2.webp',
    '/img/logo.webp',
];

// OneSignal — debe ir antes de cualquier listener propio
importScripts('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js');

// ── Install: pre-cachear assets estáticos ──────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then(cache => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

// ── Activate: limpiar versiones viejas ────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k))
            )
        )
    );
    self.clients.claim();
});

// ── Fetch: NetworkFirst para HTML, CacheFirst para assets estáticos ────────
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // Solo manejar requests del mismo origen
    if (url.origin !== self.location.origin) return;

    // CacheFirst para assets estáticos (imágenes, manifest)
    const isStatic = /\.(png|webp|jpg|jpeg|svg|ico|json|woff2?)$/.test(url.pathname);
    if (isStatic && event.request.method === 'GET') {
        event.respondWith(
            caches.match(event.request).then(cached => {
                if (cached) return cached;
                return fetch(event.request).then(response => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_VERSION).then(cache => cache.put(event.request, clone));
                    }
                    return response;
                }).catch(() => cached || Response.error());
            })
        );
        return;
    }

    // NetworkFirst para HTML — páginas PHP siempre frescas.
    // Timeout de 6s: si la red no responde (común en datos móviles), aborta y
    // cae al fallback en vez de dejar la navegación colgada indefinidamente.
    if (event.request.method === 'GET' && event.request.headers.get('accept')?.includes('text/html')) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 6000);
        event.respondWith(
            fetch(event.request, { signal: controller.signal })
                .then(response => { clearTimeout(timeoutId); return response; })
                .catch(() => {
                    clearTimeout(timeoutId);
                    return caches.match(event.request).then(cached => cached || Response.error());
                })
        );
        return;
    }
});
