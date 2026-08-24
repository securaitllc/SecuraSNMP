import { useAlertsStore } from '@/stores/alerts'

// Reflects the active-alarm count in the browser tab (title + a red-dot favicon)
// so a NOC sees trouble even when this tab is in the background.
export function useAlarmTab() {
  const store = useAlertsStore()

  function setFavicon(alarmed: boolean) {
    const size = 32
    const canvas = document.createElement('canvas')
    canvas.width = size
    canvas.height = size
    const ctx = canvas.getContext('2d')
    if (!ctx)
      return

    // Brand tile with an "S". roundRect is newish — feature-detect and fall back
    // to a plain rect so an older engine can never throw here.
    ctx.fillStyle = '#0f172a'
    ctx.beginPath()
    if (typeof ctx.roundRect === 'function')
      ctx.roundRect(0, 0, size, size, 7)
    else
      ctx.rect(0, 0, size, size)
    ctx.fill()
    ctx.fillStyle = '#3b82f6'
    ctx.font = 'bold 22px sans-serif'
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText('S', size / 2, size / 2 + 1)

    // Red alarm dot, top-right, when anything is active.
    if (alarmed) {
      ctx.fillStyle = '#ef4444'
      ctx.beginPath()
      ctx.arc(size - 8, 8, 7, 0, Math.PI * 2)
      ctx.fill()
      ctx.strokeStyle = '#0f172a'
      ctx.lineWidth = 2
      ctx.stroke()
    }

    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    if (!link) {
      link = document.createElement('link')
      link.rel = 'icon'
      document.head.appendChild(link)
    }
    link.type = 'image/png'
    link.href = canvas.toDataURL('image/png')
  }

  watch(
    () => store.activeCount,
    (n) => {
      document.title = n > 0 ? `(${n}) Nodus` : 'Nodus'
      // Never let a favicon/canvas quirk break the layout that hosts this.
      try {
        setFavicon(n > 0)
      }
      catch { /* ignore — cosmetic only */ }
    },
    { immediate: true },
  )
}
