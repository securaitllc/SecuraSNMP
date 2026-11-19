import { api } from '@/composables/useApi'
import { isIos, isStandalone } from '@/composables/useMobile'

/**
 * Alarm push for this phone (Web Push / VAPID).
 *
 * The server holds one subscription per device; the browser holds the matching
 * PushSubscription. "Subscribed" means both exist and agree on the endpoint.
 * iOS only grants push to an app opened from the home screen, so the flow tells
 * the person to install first instead of failing silently.
 */
export type Severity = 'info' | 'warning' | 'critical'

export interface PushStatus {
  enabled: boolean
  public_key: string | null
  subscriptions: { id: number, endpoint_tail: string, user_agent: string | null, min_severity: Severity, last_sent_at: string | null }[]
}

export type PushSupport = 'ok' | 'unsupported' | 'insecure' | 'needs-install' | 'denied'

export function pushSupport(): PushSupport {
  if (typeof window === 'undefined' || !('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window))
    return isIos() && !isStandalone() ? 'needs-install' : 'unsupported'
  if (!window.isSecureContext)
    return 'insecure'
  if (isIos() && !isStandalone())
    return 'needs-install'
  if (Notification.permission === 'denied')
    return 'denied'

  return 'ok'
}

function b64ToBytes(b64: string): ArrayBuffer {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4)
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++)
    out[i] = raw.charCodeAt(i)

  return out.buffer
}

async function registration(): Promise<ServiceWorkerRegistration> {
  const existing = await navigator.serviceWorker.getRegistration('/')
  if (existing)
    return existing

  return navigator.serviceWorker.register('/sw.js')
}

export function usePush() {
  const status = ref<PushStatus | null>(null)
  const browserEndpoint = ref<string | null>(null)
  const busy = ref(false)

  /** Both sides agree this phone is subscribed. */
  const subscribed = computed(() =>
    !!browserEndpoint.value && !!status.value?.subscriptions.some(s => browserEndpoint.value!.endsWith(s.endpoint_tail)))

  const mine = computed(() => status.value?.subscriptions.find(s => browserEndpoint.value?.endsWith(s.endpoint_tail)) ?? null)

  async function refresh(): Promise<void> {
    status.value = await api<PushStatus>('/api/push')
    if (pushSupport() === 'ok' || pushSupport() === 'denied') {
      try {
        const reg = await registration()
        const sub = await reg.pushManager.getSubscription()
        browserEndpoint.value = sub?.endpoint ?? null
      }
      catch {
        browserEndpoint.value = null
      }
    }
  }

  async function subscribe(minSeverity: Severity): Promise<void> {
    if (!status.value?.public_key)
      throw new Error('Push is not configured on this server.')

    busy.value = true
    try {
      const permission = await Notification.requestPermission()
      if (permission !== 'granted')
        throw new Error('Notifications were not allowed for this app.')

      const reg = await registration()
      const sub = await reg.pushManager.getSubscription()
        ?? await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(status.value.public_key) })

      const json = sub.toJSON()
      await api('/api/push/subscribe', {
        method: 'POST',
        body: { endpoint: sub.endpoint, keys: json.keys, min_severity: minSeverity },
      })
      await refresh()
    }
    finally {
      busy.value = false
    }
  }

  async function unsubscribe(): Promise<void> {
    busy.value = true
    try {
      const reg = await registration()
      const sub = await reg.pushManager.getSubscription()
      if (sub) {
        await api('/api/push/subscribe', { method: 'DELETE', body: { endpoint: sub.endpoint } })
        await sub.unsubscribe()
      }
      await refresh()
    }
    finally {
      busy.value = false
    }
  }

  async function test(): Promise<boolean> {
    if (!browserEndpoint.value)
      return false

    const res = await api<{ sent: boolean }>('/api/push/test', { method: 'POST', body: { endpoint: browserEndpoint.value } })

    return res.sent
  }

  return { status, subscribed, mine, busy, refresh, subscribe, unsubscribe, test, support: pushSupport }
}
