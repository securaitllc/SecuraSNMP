import { toast } from 'vue-sonner'
import { api } from '@/composables/useApi'

// Baked in at build time by vite.config (define __APP_VERSION__).
declare const __APP_VERSION__: string

let started = false

/**
 * Detect when a newer version of the app has been deployed while this tab is open,
 * and prompt the operator to reload — so a stale cached bundle never hides a fix
 * (the recurring "I deployed but don't see the change" problem). Singleton per tab.
 */
export function useVersionGuard(intervalMs = 60_000) {
  if (started)
    return
  started = true

  const built = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : ''
  // Dev serves an unversioned build — nothing to compare against.
  if (!built || built === 'dev')
    return

  let prompted = false
  const check = async () => {
    if (prompted)
      return
    try {
      const { version } = await api<{ version: string }>('/api/version')
      if (version && version !== built) {
        prompted = true
        toast('A new version was deployed', {
          description: `You're on v${built}; v${version} is live — reload to get the latest.`,
          duration: Number.POSITIVE_INFINITY,
          action: { label: 'Reload', onClick: () => window.location.reload() },
        })
      }
    }
    catch { /* transient; retry next tick */ }
  }

  check()
  setInterval(check, intervalMs)
}
