/**
 * Phone mode.
 *
 * The desktop app is a dense NOC console; on a phone it is unusable. Under
 * `/m/*` lives a separate, touch-first set of screens covering what someone
 * on call actually needs. Phones are sent there automatically; a person can
 * opt back out ("Use desktop site"), and a desktop can opt in with `?m=1`.
 */
const OPT_OUT_KEY = 'nodus:desktop-site'

function read(key: string): string | null {
  try {
    return localStorage.getItem(key)
  }
  catch {
    return null
  }
}

function write(key: string, value: string | null): void {
  try {
    value === null ? localStorage.removeItem(key) : localStorage.setItem(key, value)
  }
  catch {
    // private mode / blocked storage: the choice just doesn't persist
  }
}

/** A small, touch-driven screen — the thing we optimise for, not a UA sniff. */
export function isPhoneScreen(): boolean {
  if (typeof window === 'undefined')
    return false

  return window.matchMedia('(max-width: 600px)').matches
    && window.matchMedia('(pointer: coarse)').matches
}

export function wantsDesktopSite(): boolean {
  return read(OPT_OUT_KEY) === '1'
}

export function setWantsDesktopSite(on: boolean): void {
  write(OPT_OUT_KEY, on ? '1' : null)
}

/** Running from the home screen (what iOS requires before it will allow push). */
export function isStandalone(): boolean {
  if (typeof window === 'undefined')
    return false

  return window.matchMedia('(display-mode: standalone)').matches
    || (navigator as unknown as { standalone?: boolean }).standalone === true
}

export function isIos(): boolean {
  if (typeof navigator === 'undefined')
    return false

  // iPadOS reports as Mac; the touch check catches it.
  return /iPhone|iPad|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

/** "3m", "2h", "4d" — the compact age an alarm row needs. */
export function ago(iso: string | null | undefined): string {
  if (!iso)
    return ''

  const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  if (s < 60)
    return `${s}s`
  if (s < 3600)
    return `${Math.floor(s / 60)}m`
  if (s < 86400)
    return `${Math.floor(s / 3600)}h`

  return `${Math.floor(s / 86400)}d`
}

/** Title the phone shell shows; detail pages set it once their record loads. */
export const mobileTitle = ref<string | null>(null)
