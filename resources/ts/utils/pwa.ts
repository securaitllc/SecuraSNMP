/**
 * Register the service worker so Nodus can be installed to a phone's home screen.
 *
 * The worker caches only the hashed build output and never API traffic — see
 * public/sw.js for why that line matters in a monitoring tool. Registration is
 * skipped in development, where a worker intercepting Vite's requests only gets in
 * the way, and on insecure origins, where the browser refuses anyway.
 */
export function registerServiceWorker(): void {
  if (!('serviceWorker' in navigator))
    return

  // Secure context only. localhost counts as secure, which is what makes a local
  // check possible at all; plain-HTTP LAN access does not, and that is a browser
  // rule rather than ours.
  if (!window.isSecureContext)
    return

  // import.meta.env.DEV is compiled out of the production bundle.
  if (import.meta.env.DEV)
    return

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // A failed registration costs nothing — the app runs exactly as before, just
      // without the home-screen install. Never surface it to an operator.
    })
  })
}

/**
 * True when running from the home screen rather than in a browser tab.
 *
 * iOS reports this through a non-standard flag on navigator; everything else uses
 * the display-mode media query.
 */
export function isInstalled(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches
    || (navigator as unknown as { standalone?: boolean }).standalone === true
}
