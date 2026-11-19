import { toast } from 'vue-sonner'
import { api } from '@/composables/useApi'

// Baked in at build time by vite.config (define __APP_VERSION__).
declare const __APP_VERSION__: string

let started = false

// One-shot guard so a genuinely mismatched version can't turn into a reload loop.
const RELOAD_KEY = 'nodus:version-reload'

/**
 * Detect when a newer version of the app has been deployed while this tab is open
 * and AUTO-RELOAD to it — so a stale cached bundle never silently hides a fix (the
 * recurring "I deployed but don't see the change / this button does nothing"
 * problem, made worse by Safari's aggressive caching). A reload lands on the same
 * route with the current code. A brief toast explains why; if the auto-reload is
 * somehow suppressed, its Reload action is the manual fallback. Singleton per tab.
 */
export function useVersionGuard(intervalMs = 60_000) {
  if (started)
    return
  started = true

  const built = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : ''
  // Dev serves an unversioned build — nothing to compare against.
  if (!built || built === 'dev')
    return

  let handled = false
  const check = async () => {
    if (handled)
      return
    try {
      const { version } = await api<{ version: string }>('/api/version')
      if (!version || version === built)
        return
      handled = true

      // Only auto-reload once per live version — if we already reloaded to fetch
      // this version and STILL see a mismatch (e.g. a proxy serving a stale
      // bundle), fall back to a manual prompt instead of looping.
      if (sessionStorage.getItem(RELOAD_KEY) === version) {
        toast('A new version is available', {
          description: `You're on v${built}; v${version} is live — reload to get the latest.`,
          duration: Number.POSITIVE_INFINITY,
          action: { label: 'Reload', onClick: () => window.location.reload() },
        })
        return
      }

      sessionStorage.setItem(RELOAD_KEY, version)
      toast(`Updating to v${version}…`, { duration: 2500 })
      setTimeout(() => window.location.reload(), 1200)
    }
    catch { /* transient; retry next tick */ }
  }

  check()
  setInterval(check, intervalMs)
}
