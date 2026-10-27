/**
 * Nodus service worker.
 *
 * This exists so the app can be installed to a phone's home screen and open as an
 * app rather than a browser tab. It deliberately does NOT try to make the NOC work
 * offline.
 *
 * THE RULE THAT SHAPES ALL OF THIS: a monitoring tool must never show a reading it
 * cannot stand behind. A service worker that cached /api responses would happily
 * paint yesterday's all-green dashboard on a phone with no signal — the same
 * false-healthy failure this app has been dug out of repeatedly, except now served
 * from the device itself and completely invisible to the server. So API traffic is
 * never cached and never replayed. Offline, the app is told plainly that it is
 * offline, and it shows nothing rather than something stale.
 *
 * What IS cached is the shell: the hashed JS/CSS/font files Vite emits. Those carry
 * a content hash in the filename, so a cached copy can never be the wrong version —
 * a new build requests new filenames.
 */

// Bump to retire every old cache at once.
const CACHE = 'nodus-shell-v2'

// Immutable build output, safe to serve from cache: the filename changes when the
// content does.
const HASHED_ASSET = /\/build\/assets\/.+\.(js|css|woff2?|ttf|svg|png|jpg|webp)$/i

self.addEventListener('install', event => {
  // Take over as soon as the new worker is ready; a stale shell helps nobody.
  self.skipWaiting()
  event.waitUntil(caches.open(CACHE))
})

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const names = await caches.keys()
    await Promise.all(names.filter(n => n !== CACHE).map(n => caches.delete(n)))
    await self.clients.claim()
  })())
})

self.addEventListener('fetch', event => {
  const { request } = event
  const url = new URL(request.url)

  // Only ever touch our own origin, and only plain GETs.
  if (request.method !== 'GET' || url.origin !== self.location.origin)
    return

  // Never cache, never replay: live state, auth, and anything that mutates.
  // An offline answer here must be an ERROR the app can show, not old data it
  // would render as current.
  if (url.pathname.startsWith('/api') || url.pathname.startsWith('/sanctum')) {
    event.respondWith(
      fetch(request).catch(() => new Response(
        JSON.stringify({
          message: 'This device is offline, so nothing could be read from the network.',
          reason: 'offline',
        }),
        { status: 503, headers: { 'Content-Type': 'application/json' } },
      )),
    )

    return
  }

  // Hashed build output — cache-first is safe because the URL changes with content.
  if (HASHED_ASSET.test(url.pathname)) {
    event.respondWith((async () => {
      const hit = await caches.match(request)
      if (hit)
        return hit

      const response = await fetch(request)
      if (response.ok)
        (await caches.open(CACHE)).put(request, response.clone())

      return response
    })())

    return
  }

  // Navigations are DELIBERATELY not intercepted.
  //
  // There used to be a fallback here that answered a failed navigation with the word
  // "Offline". Two things were wrong with it. It looked for '/offline.html', which is
  // never precached, so it always fell through to a bare 503 with no explanation and
  // no way forward. And worse, it swallowed the real reason: this deployment serves
  // HTTPS from Caddy's internal CA, whose certificate rotates every 12 hours, and a
  // browser that has not trusted that CA rejects the new one. With this handler in
  // place the user saw "Offline" instead of the certificate interstitial — so they
  // could not click through, and nothing told them what was actually wrong.
  //
  // The same rule this file already states about API data applies to errors: never
  // show something the app cannot stand behind. Letting the browser report its own
  // failure is more honest than a word we invented, so navigations pass straight
  // through to the network.
})
