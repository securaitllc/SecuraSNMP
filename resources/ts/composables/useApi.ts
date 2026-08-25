import { ofetch } from 'ofetch'

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))

  return match ? decodeURIComponent(match[1]) : null
}

// When the session ends server-side (idle/absolute lifetime, or a CSRF-token rotation),
// every poll returns 401/419. The app used to swallow those and keep showing the last
// data, so the UI looked FROZEN until the operator did a hard refresh — the only thing
// that re-ran the auth check and sent them to the login screen. Do that automatically:
// on the first auth failure, boot fresh at /login (a full reload, which also clears any
// stale timers/state). Debounced so a burst of concurrent 401s triggers ONE redirect.
let redirecting = false
function onAuthLost() {
  if (redirecting)
    return
  redirecting = true
  const back = encodeURIComponent(window.location.pathname + window.location.search)
  window.location.assign(`/login?expired=1&next=${back}`)
}

// These endpoints ARE the auth handshake — a 401 from them is normal (e.g. the boot-time
// "am I logged in?" probe) and must never trigger the redirect, or booting logged-out
// would loop.
function isAuthHandshake(url: string): boolean {
  return url.includes('/api/user')
    || url.includes('/api/login')
    || url.includes('/api/logout')
    || url.includes('/sanctum/csrf-cookie')
}

export const api = ofetch.create({
  credentials: 'include',
  // A request must never hang forever: without a cap, a slow/stuck endpoint holds a
  // browser connection open, and after ~6 the browser queues EVERY request — the whole
  // app freezes. Abort after 60s so the poll loop recovers on its own.
  timeout: 60000,
  retry: 0,
  async onRequest({ options }) {
    const method = (options.method ?? 'GET').toUpperCase()
    const headers = new Headers(options.headers)

    headers.set('Accept', 'application/json')

    if (method !== 'GET') {
      // Refresh the CSRF cookie before a mutating request. Never let this block forever
      // and never let its own failure abort the caller — the real request still runs.
      try {
        await ofetch('/sanctum/csrf-cookie', { credentials: 'include', timeout: 15000, retry: 0 })
      }
      catch {
        // best-effort; the request below may still succeed or surface its own error
      }

      const token = readCookie('XSRF-TOKEN')

      if (token)
        headers.set('X-XSRF-TOKEN', token)
    }

    options.headers = headers
  },
  onResponseError({ request, response }) {
    const status = response?.status
    const url = typeof request === 'string' ? request : (request as Request).url ?? ''

    // 401 = the session is gone; 419 = the CSRF token/session is stale. Either way the
    // operator must re-authenticate — send them to login instead of freezing on old data.
    if ((status === 401 || status === 419) && !isAuthHandshake(url) && !window.location.pathname.startsWith('/login'))
      onAuthLost()
  },
})
