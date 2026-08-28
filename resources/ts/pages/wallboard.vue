<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { DashboardSummary } from '@/types/models'

definePage({ meta: { layout: 'blank' } })

const router = useRouter()
const auth = useAuthStore()

// The X exits to the dashboard for a normal operator; a wall-display (kiosk)
// account has nowhere else to go, so it signs out instead.
async function closeBoard() {
  if (auth.isDisplay) {
    await auth.logout()
    router.push({ name: 'login' })
    return
  }
  router.push('/')
}

const data = ref<DashboardSummary | null>(null)
const clock = ref('')
const now = ref(Date.now())

function tick() {
  const d = new Date()
  clock.value = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).format(d) + ' EDT'
  now.value = d.getTime()
}

// Alarm rows come from the per-ISP grouping (same source as the dashboard/Alarms
// "By ISP" view), so the wall reads one line per provider — not five per outage.
interface GAlarm { key: string, severity: string, description: string | null, first_seen_at: string }
interface GCircuit { id: number, isp_name: string | null, wan_interface: string | null, gateway_ip: string | null }
interface GTicket { isp_ticket: string | null, dispatch_at: string | null, dispatch_by_name: string | null }
interface Group { kind: 'circuit' | 'site', circuit: GCircuit | null, state: string, label?: string, ticket: GTicket | null, alarms: GAlarm[] }
interface SiteGroups { site_id: number, site_name: string | null, groups: Group[] }

const grouped = ref<SiteGroups[]>([])

async function load() {
  const [dash, grp] = await Promise.all([
    api<DashboardSummary>('/api/dashboard'),
    api<{ sites: SiteGroups[] }>('/api/alarms/grouped'),
  ])
  data.value = dash
  grouped.value = grp.sites
}

let poll: ReturnType<typeof setInterval> | null = null
let clockTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  load(); tick()
  poll = setInterval(load, 15000)
  clockTimer = setInterval(tick, 1000)
  ro = new ResizeObserver(() => measure())
  if (viewport.value) ro.observe(viewport.value)
})
onBeforeUnmount(() => {
  if (poll) clearInterval(poll)
  if (clockTimer) clearInterval(clockTimer)
  ro?.disconnect()
})

const counts = computed(() => data.value?.counts)
const healthySites = computed(() => (data.value?.sites ?? []).filter(s => s.health === 'good').length)

interface Row {
  key: string
  site_name: string | null
  isp: string
  path: string
  state: string
  count: number
  isp_ticket: string | null
  dispatch_at: string | null
  dispatch_by: string | null
  needs_ticket: boolean
  oldest_at: string
}

const rows = computed<Row[]>(() => {
  const out: Row[] = []
  for (const site of grouped.value) {
    for (const g of site.groups) {
      const isCircuit = g.kind === 'circuit' && !!g.circuit
      const oldest = g.alarms.reduce((m, a) => (a.first_seen_at < m ? a.first_seen_at : m), g.alarms[0]?.first_seen_at ?? '')
      out.push({
        key: `${site.site_id}-${isCircuit ? `c${g.circuit!.id}` : `b-${g.state}`}`,
        site_name: site.site_name,
        isp: isCircuit ? (g.circuit!.isp_name ?? 'Circuit') : 'Site-wide',
        path: isCircuit
          ? [g.circuit!.wan_interface, g.circuit!.gateway_ip ? `gw ${g.circuit!.gateway_ip}` : null].filter(Boolean).join(' · ')
          : (g.label ?? ''),
        state: g.state,
        count: g.alarms.length,
        isp_ticket: g.ticket?.isp_ticket ?? null,
        dispatch_at: g.ticket?.dispatch_at ?? null,
        dispatch_by: g.ticket?.dispatch_by_name ?? null,
        needs_ticket: isCircuit && (g.state === 'down' || g.state === 'critical') && !g.ticket?.isp_ticket,
        oldest_at: oldest,
      })
    }
  }
  return out
})

// Criticals + circuit/site-downs PIN at the top and never scroll off; everything
// else scrolls below.
const PIN = new Set(['down', 'critical'])
const pinnedRows = computed(() => rows.value.filter(r => PIN.has(r.state)))
const scrollRows = computed(() => rows.value.filter(r => !PIN.has(r.state)))
const hasAny = computed(() => rows.value.length > 0)

const STATE_LABEL: Record<string, string> = { down: 'Down', critical: 'Critical', degraded: 'Degraded', warning: 'Warning' }

function ageOf(iso: string): string {
  if (!iso) return ''
  const mins = Math.max(0, Math.floor((now.value - new Date(iso).getTime()) / 60000))
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  if (h < 24) return `${h}h ${String(mins % 60).padStart(2, '0')}m`
  return `${Math.floor(h / 24)}d ${String(h % 24).padStart(2, '0')}h`
}
function dispatchLabel(iso: string): string {
  const d = new Date(iso)
  const day = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', weekday: 'short', month: 'short', day: 'numeric' }).format(d)
  const t = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', hour: '2-digit', minute: '2-digit', hour12: false }).format(d)
  return `${day} · ${t}`
}

// Auto-scroll the degraded/info list when it overflows. 40% slower than before —
// operators read a wall from across the room, so ~4.2s per row, floor 17s.
const viewport = ref<HTMLElement | null>(null)
const copy = ref<HTMLElement | null>(null)
const overflow = ref(false)
const scrolling = computed(() => overflow.value)
const scrollDuration = computed(() => `${Math.max(17, scrollRows.value.length * 4.2)}s`)

function measure() {
  const vp = viewport.value
  const cp = copy.value
  overflow.value = !!vp && !!cp && cp.offsetHeight > vp.clientHeight + 4
}
watch(scrollRows, () => nextTick(measure))

let ro: ResizeObserver | null = null
watch(viewport, (el, old) => {
  if (old) ro?.unobserve(old)
  if (el) ro?.observe(el)
}, { flush: 'post' })
</script>

<template>
  <div class="wallboard">
    <div class="wb-header">
      <div class="wb-title">Nodus · NOC</div>
      <div class="wb-clock">{{ clock }}</div>
      <VBtn variant="text" color="grey" icon="ri-close-line" @click="closeBoard" />
    </div>

    <div class="wb-tiles">
      <div class="wb-tile" :class="{ ok: (counts?.active_alerts ?? 0) === 0, bad: (counts?.active_alerts ?? 0) > 0 }">
        <div class="wb-num">{{ counts?.active_alerts ?? 0 }}</div>
        <div class="wb-label">Active Alerts</div>
      </div>
      <div class="wb-tile" :class="{ ok: (counts?.circuits_down ?? 0) === 0, bad: (counts?.circuits_down ?? 0) > 0 }">
        <div class="wb-num">{{ counts?.circuits_down ?? 0 }}</div>
        <div class="wb-label">Circuits Down</div>
      </div>
      <div class="wb-tile" :class="{ ok: (counts?.interfaces_down ?? 0) === 0, bad: (counts?.interfaces_down ?? 0) > 0 }">
        <div class="wb-num">{{ counts?.interfaces_down ?? 0 }}</div>
        <div class="wb-label">Interfaces Down</div>
      </div>
      <div class="wb-tile" :class="{ ok: (counts?.tunnels_down ?? 0) === 0, bad: (counts?.tunnels_down ?? 0) > 0 }">
        <div class="wb-num">{{ counts?.tunnels_down ?? 0 }}</div>
        <div class="wb-label">Tunnels Down</div>
      </div>
      <div class="wb-tile ok">
        <div class="wb-num">{{ healthySites }}/{{ counts?.sites ?? 0 }}</div>
        <div class="wb-label">Sites Healthy</div>
      </div>
    </div>

    <div class="wb-alerts">
      <div v-if="!hasAny" class="wb-clear"><VIcon icon="ri-checkbox-circle-line" size="20" class="me-2" />All clear</div>

      <template v-else>
        <!-- Pinned: criticals + circuit/site downs. Frozen at the top. -->
        <div v-if="pinnedRows.length" class="wb-pinhdr">Critical — pinned</div>
        <div
          v-for="r in pinnedRows"
          :key="r.key"
          class="wb-row"
          :class="r.state"
        >
          <span class="wb-isp">{{ r.isp }}</span>
          <span class="wb-site">{{ r.site_name }}</span>
          <span v-if="r.path" class="wb-path">{{ r.path }}</span>
          <span class="wb-count">{{ r.count }} alarm{{ r.count > 1 ? 's' : '' }}</span>
          <span v-if="r.needs_ticket" class="wb-tkt pending">ticket pending</span>
          <span v-else-if="r.isp_ticket" class="wb-tkt"><VIcon icon="ri-ticket-2-line" size="16" class="me-1" />{{ r.isp_ticket }}<span v-if="r.dispatch_at"> · <VIcon icon="ri-truck-line" size="16" class="me-1" />{{ dispatchLabel(r.dispatch_at) }}</span></span>
          <span class="wb-age">{{ ageOf(r.oldest_at) }}</span>
        </div>

        <!-- Scrolling: degraded / warning. Auto-scrolls when it overflows. -->
        <div v-if="scrollRows.length" class="wb-scrollhdr">Degraded / Warning</div>
        <div v-if="scrollRows.length" ref="viewport" class="wb-scrollview">
          <div class="wb-track" :class="{ 'wb-scrolling': scrolling }" :style="scrolling ? { animationDuration: scrollDuration } : undefined">
            <div ref="copy" class="wb-copy">
              <div v-for="r in scrollRows" :key="r.key" class="wb-row" :class="r.state">
                <span class="wb-isp">{{ r.isp }}</span>
                <span class="wb-site">{{ r.site_name }}</span>
                <span v-if="r.path" class="wb-path">{{ r.path }}</span>
                <span class="wb-count">{{ r.count }} alarm{{ r.count > 1 ? 's' : '' }}</span>
                <span v-if="r.isp_ticket" class="wb-tkt"><VIcon icon="ri-ticket-2-line" size="16" class="me-1" />{{ r.isp_ticket }}<span v-if="r.dispatch_at"> · <VIcon icon="ri-truck-line" size="16" class="me-1" />{{ dispatchLabel(r.dispatch_at) }}</span></span>
                <span class="wb-age">{{ ageOf(r.oldest_at) }}</span>
              </div>
            </div>
            <div v-if="scrolling" class="wb-copy" aria-hidden="true">
              <div v-for="r in scrollRows" :key="`${r.key}-dup`" class="wb-row" :class="r.state">
                <span class="wb-isp">{{ r.isp }}</span>
                <span class="wb-site">{{ r.site_name }}</span>
                <span v-if="r.path" class="wb-path">{{ r.path }}</span>
                <span class="wb-count">{{ r.count }} alarm{{ r.count > 1 ? 's' : '' }}</span>
                <span v-if="r.isp_ticket" class="wb-tkt"><VIcon icon="ri-ticket-2-line" size="16" class="me-1" />{{ r.isp_ticket }}<span v-if="r.dispatch_at"> · <VIcon icon="ri-truck-line" size="16" class="me-1" />{{ dispatchLabel(r.dispatch_at) }}</span></span>
                <span class="wb-age">{{ ageOf(r.oldest_at) }}</span>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.wallboard {
  block-size: 100vh;
  padding: 24px 32px;
  background: #0b0f1a;
  color: #e6edf3;
  font-family: 'Roboto', sans-serif;
  display: flex;
  flex-direction: column;
}
.wb-header { display: flex; align-items: center; gap: 16px; margin-block-end: 24px; }
.wb-title { font-size: 1.6rem; font-weight: 700; letter-spacing: 0.04em; }
.wb-clock { margin-inline-start: auto; font-size: 1.6rem; font-variant-numeric: tabular-nums; color: #7dd3fc; }
.wb-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-block-end: 28px; }
.wb-tile { padding: 28px; border-radius: 14px; text-align: center; background: #131a2a; border: 1px solid #1f2a44; }
.wb-tile.ok { border-color: #14532d; }
.wb-tile.bad { border-color: #7f1d1d; background: #1a1113; box-shadow: 0 0 24px rgba(239, 68, 68, 0.25); }
.wb-num { font-size: 3.2rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
.wb-tile.bad .wb-num { color: #f87171; }
.wb-tile.ok .wb-num { color: #4ade80; }
.wb-label { margin-block-start: 8px; text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.8rem; color: #94a3b8; }

.wb-alerts { flex: 1; min-block-size: 0; display: flex; flex-direction: column; }
.wb-clear { font-size: 1.4rem; color: #4ade80; padding: 24px 0; }
.wb-pinhdr { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #f87171; margin: 4px 0 10px; }
.wb-scrollhdr { font-size: 0.74rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #94a3b8; margin: 18px 0 10px; padding-block-start: 12px; border-block-start: 1px dashed #1f2a44; }

/* Scroll viewport: clip the overflow, let the track glide through it. */
.wb-scrollview { flex: 1; min-block-size: 0; overflow: hidden; position: relative; }
.wb-track { display: flex; flex-direction: column; }
.wb-copy { display: flex; flex-direction: column; }
.wb-scrolling { animation-name: wb-scroll; animation-timing-function: linear; animation-iteration-count: infinite; will-change: transform; }
@keyframes wb-scroll { from { transform: translateY(0); } to { transform: translateY(-50%); } }
@media (prefers-reduced-motion: reduce) { .wb-scrolling { animation: none; } }

.wb-row { display: flex; align-items: center; gap: 16px; padding: 15px 18px; border-radius: 10px; margin-block-end: 9px; background: #131a2a; border-inline-start: 5px solid #64748b; }
.wb-row.down, .wb-row.critical { border-inline-start-color: #ef4444; background: #1a1113; }
.wb-row.degraded, .wb-row.warning { border-inline-start-color: #f59e0b; }
.wb-isp { font-size: 1.15rem; font-weight: 800; min-inline-size: 130px; }
.wb-site { color: #cbd5e1; font-size: 0.95rem; }
.wb-path { font-family: ui-monospace, Menlo, monospace; font-size: 0.78rem; color: #64748b; }
.wb-count { font-size: 0.72rem; color: #94a3b8; background: rgba(255, 255, 255, 0.06); border-radius: 999px; padding: 2px 10px; }
.wb-tkt { margin-inline-start: auto; font-family: ui-monospace, Menlo, monospace; font-size: 0.82rem; color: #7dd3fc; white-space: nowrap; }
.wb-tkt.pending { color: #f87171; font-weight: 700; }
.wb-age { font-family: ui-monospace, Menlo, monospace; font-size: 0.9rem; color: #64748b; font-variant-numeric: tabular-nums; min-inline-size: 60px; text-align: end; }
.wb-tkt + .wb-age { margin-inline-start: 0; }
.wb-row > .wb-age:not(.wb-tkt + .wb-age) { margin-inline-start: auto; }
</style>
