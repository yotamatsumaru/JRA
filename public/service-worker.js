/**
 * JRA Analytics Service Worker (Phase 6-P PWA)
 *
 *  キャッシュ戦略:
 *   - CSS / JS / 画像 / フォント / マニフェスト → Cache First (stale-while-revalidate 風)
 *   - HTML / API → Network First (オフライン時はキャッシュにフォールバック)
 *  - インストール時に主要静的アセットをプリキャッシュ
 *  - 古いバージョンのキャッシュは activate で削除
 */

const VERSION = 'jra-pwa-v1';
const STATIC_CACHE  = `${VERSION}-static`;
const RUNTIME_CACHE = `${VERSION}-runtime`;

// 必要最低限のシェル (URL は scope を意識して相対で書く)
const PRECACHE_URLS = [
    '/JRA/',
    '/JRA/manifest.json',
    '/JRA/icon.svg',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS).catch(() => null))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((k) => k !== STATIC_CACHE && k !== RUNTIME_CACHE)
                    .map((k) => caches.delete(k))
            )
        ).then(() => self.clients.claim())
    );
});

/**
 * fetch ハンドラ
 *  - GET 以外はそのまま素通り
 *  - 静的アセットは Cache First
 *  - HTML / その他は Network First (失敗時 cache → 失敗時 オフラインHTML)
 */
self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== location.origin) return;

    const isAsset = /\.(?:css|js|woff2?|ttf|eot|otf|png|jpg|jpeg|gif|webp|svg|ico)$/i.test(url.pathname);

    if (isAsset) {
        event.respondWith(cacheFirst(req));
    } else {
        event.respondWith(networkFirst(req));
    }
});

async function cacheFirst(req) {
    const cached = await caches.match(req);
    if (cached) {
        // バックグラウンドで更新
        fetch(req).then((res) => {
            if (res && res.ok) {
                caches.open(RUNTIME_CACHE).then((c) => c.put(req, res.clone())).catch(() => null);
            }
        }).catch(() => null);
        return cached;
    }
    try {
        const res = await fetch(req);
        if (res && res.ok) {
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(req, res.clone()).catch(() => null);
        }
        return res;
    } catch (e) {
        return new Response('', { status: 504, statusText: 'offline' });
    }
}

async function networkFirst(req) {
    try {
        const res = await fetch(req);
        if (res && res.ok) {
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(req, res.clone()).catch(() => null);
        }
        return res;
    } catch (e) {
        const cached = await caches.match(req);
        if (cached) return cached;
        // 最低限のオフライン HTML
        if (req.headers.get('accept')?.includes('text/html')) {
            return new Response(
                '<!doctype html><meta charset="utf-8"><title>オフライン</title>' +
                '<style>body{font-family:sans-serif;background:#0f172a;color:#e5e7eb;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}h1{color:#fbbf24}</style>' +
                '<h1>オフラインです</h1><p>ネットワーク接続を確認して、もう一度お試しください。</p>',
                { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
            );
        }
        return new Response('', { status: 504, statusText: 'offline' });
    }
}

// メッセージで即時更新を許可
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});
