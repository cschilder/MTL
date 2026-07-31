/**
 * MTL service worker.
 *
 * Two jobs: make the site usable on a bad connection, and give the Android
 * wrapper something to show when there is no connection at all.
 *
 * The caching strategy differs per kind of request, because they have
 * genuinely different needs:
 *
 *   navigations  network first, falling back to the cached copy and then to
 *                the offline page. A travel report that changed should show
 *                its new text; one that has not is still readable on a train.
 *   assets       cache first. Every URL under /assets/ carries a content hash,
 *                so a cached entry can never be stale — a change is a new URL.
 *   media        cache first, with a cap. Photographs are the bulk of the site
 *                and never change once uploaded.
 *   API          stale while revalidating. The globe draws immediately from
 *                the last payload and quietly updates.
 *
 * Bump CACHE_VERSION to discard everything after a deployment that changes the
 * shell.
 */

const CACHE_VERSION = 'v1';

const SHELL_CACHE = `mtl-shell-${CACHE_VERSION}`;
const PAGE_CACHE = `mtl-pages-${CACHE_VERSION}`;
const ASSET_CACHE = `mtl-assets-${CACHE_VERSION}`;
const MEDIA_CACHE = `mtl-media-${CACHE_VERSION}`;
const DATA_CACHE = `mtl-data-${CACHE_VERSION}`;

/** Entries kept per cache before the oldest are dropped. */
const LIMITS = {
  [PAGE_CACHE]: 60,
  [MEDIA_CACHE]: 400,
  [ASSET_CACHE]: 120,
};

const OFFLINE_URL = new URL('offline', self.registration.scope).pathname;

/**
 * The minimum needed to render something useful with no connection. Kept
 * short: a precache that fails one request fails the whole install.
 */
const SHELL = [
  OFFLINE_URL,
  new URL('assets/icons/favicon.svg', self.registration.scope).pathname,
  new URL('assets/icons/icon-192.png', self.registration.scope).pathname,
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(SHELL_CACHE);

      // Added one at a time: cache.addAll rejects the whole batch if any single
      // entry 404s, which would leave the worker permanently uninstalled.
      await Promise.all(
        SHELL.map(async (url) => {
          try {
            await cache.add(new Request(url, { cache: 'reload' }));
          } catch (error) {
            console.warn('[sw] could not precache', url, error);
          }
        }),
      );

      // Take over as soon as the install finishes rather than waiting for
      // every tab to close.
      await self.skipWaiting();
    })(),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keep = new Set([SHELL_CACHE, PAGE_CACHE, ASSET_CACHE, MEDIA_CACHE, DATA_CACHE]);

      await Promise.all(
        (await caches.keys())
          .filter((name) => name.startsWith('mtl-') && !keep.has(name))
          .map((name) => caches.delete(name)),
      );

      // Navigation preload lets the browser start the network request while
      // the worker is still booting, which removes the start-up latency this
      // file would otherwise add to every navigation.
      if (self.registration.navigationPreload) {
        await self.registration.navigationPreload.enable();
      }

      await self.clients.claim();
    })(),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Anything that changes state is none of this worker's business.
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  // Only this origin: a cross-origin response would be opaque and cannot be
  // inspected or safely reused.
  if (url.origin !== self.location.origin) return;

  // The management environment is never cached. It is personalised, it changes
  // constantly, and a stale admin screen is actively dangerous.
  if (url.pathname.includes('/admin')) return;

  // Nor is anything carrying a session-specific answer.
  if (url.pathname.endsWith('/login') || url.pathname.endsWith('/logout')) return;

  if (request.mode === 'navigate') {
    event.respondWith(handleNavigation(event));
    return;
  }

  if (url.pathname.includes('/assets/')) {
    event.respondWith(cacheFirst(request, ASSET_CACHE));
    return;
  }

  if (url.pathname.includes('/media/')) {
    event.respondWith(cacheFirst(request, MEDIA_CACHE));
    return;
  }

  if (url.pathname.includes('/api/')) {
    event.respondWith(staleWhileRevalidate(request, DATA_CACHE));
  }
});

/**
 * Navigations: try the network, fall back to what was cached, and only then to
 * the offline page.
 */
async function handleNavigation(event) {
  const cache = await caches.open(PAGE_CACHE);

  try {
    const preloaded = await event.preloadResponse;
    const response = preloaded || (await fetch(event.request));

    // Only complete, cacheable answers are stored; a redirect or an error page
    // would otherwise be served back later as if it were the real thing.
    if (response && response.ok && response.type === 'basic') {
      cache.put(event.request, response.clone());
      trim(PAGE_CACHE);
    }

    return response;
  } catch {
    const cached = await cache.match(event.request);

    if (cached) return cached;

    const shell = await caches.open(SHELL_CACHE);
    const offline = await shell.match(OFFLINE_URL);

    return (
      offline ||
      new Response('<h1>Offline</h1>', {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=UTF-8' },
      })
    );
  }
}

/**
 * Cache first, for content whose URL changes when the content does.
 */
async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);

  if (cached) return cached;

  try {
    const response = await fetch(request);

    // 206 responses are partial and must never be cached: replaying one as if
    // it were the whole file corrupts video playback.
    if (response.ok && response.status === 200 && response.type === 'basic') {
      cache.put(request, response.clone());
      trim(cacheName);
    }

    return response;
  } catch (error) {
    // A missing image is better than a broken page.
    return cached || Response.error();
  }
}

/**
 * Answer from the cache at once, then refresh it in the background.
 */
async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);

  const network = fetch(request)
    .then((response) => {
      if (response.ok && response.type === 'basic') {
        cache.put(request, response.clone());
      }

      return response;
    })
    .catch(() => null);

  return cached || (await network) || Response.error();
}

/**
 * Keeps a cache under its limit, oldest first.
 *
 * The Cache API preserves insertion order, so the first keys are the least
 * recently added.
 */
async function trim(cacheName) {
  const limit = LIMITS[cacheName];

  if (!limit) return;

  const cache = await caches.open(cacheName);
  const keys = await cache.keys();

  if (keys.length <= limit) return;

  await Promise.all(keys.slice(0, keys.length - limit).map((key) => cache.delete(key)));
}

/**
 * Lets the page ask for the caches to be emptied — the maintenance screen uses
 * this after a deployment.
 */
self.addEventListener('message', (event) => {
  if (event.data?.type !== 'mtl:clear-caches') return;

  event.waitUntil(
    (async () => {
      const names = (await caches.keys()).filter((name) => name.startsWith('mtl-'));

      await Promise.all(names.map((name) => caches.delete(name)));

      event.source?.postMessage({ type: 'mtl:caches-cleared', count: names.length });
    })(),
  );
});
