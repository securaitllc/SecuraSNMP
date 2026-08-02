<script setup lang="ts">
import { api } from '@/composables/useApi'
import type { DashboardSummary } from '@/types/models'

definePage({ meta: { layout: 'blank' } })

const router = useRouter()
const data = ref<DashboardSummary | null>(null)
const clock = ref('')

function tick() {
  const d = new Date()
  clock.value = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).format(d) + ' EDT'
  now.value = d.getTime()
}

async function load() {
  data.value = await api<DashboardSummary>('/api/dashboard')
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
const alerts = computed(() => data.value?.alerts ?? [])
const now = ref(Date.now())

interface WbAlert { key: string, severity: string, title: string, subtitle: string, site_name: string | null, started_at: string, dispatch_at?: string | null, dispatched_by?: string | null }
interface SiteGroup { site: string, alarms: WbAlert[] }
interface SevGroup { severity: string, label: string, count: number, sites: SiteGroup[] }

// (1) critical-first + (3) group by site — criticals lead in their own section,
// newest first, clustered under each site so one bad site reads as one block.
const SEV_ORDER: Record<string, number> = { critical: 0, warning: 1, info: 2 }
const SEV_LABEL: Record<string, string> = { critical: 'service down', warning: 'degraded', info: 'info' }
const groups = computed<SevGroup[]>(() => {
  const list = [...(alerts.value as WbAlert[])].sort((a, b) =>
    (SEV_ORDER[a.severity] ?? 3) - (SEV_ORDER[b.severity] ?? 3)
    || new Date(b.started_at).getTime() - new Date(a.started_at).getTime())
  const out: SevGroup[] = []
  for (const sev of ['critical', 'warning', 'info']) {
    const arr = list.filter(a => (a.severity || 'info') === sev)
    if (!arr.length) continue
    const sites: SiteGroup[] = []
    const idx: Record<string, number> = {}
    for (const a of arr) {
      const key = a.site_name || '—'
      if (idx[key] === undefined) { idx[key] = sites.length; sites.push({ site: key, alarms: [] }) }
      sites[idx[key]].alarms.push(a)
    }
    out.push({ severity: sev, label: SEV_LABEL[sev], count: arr.length, sites })
  }
  return out
})

// Field dispatch stamp for the wall: "Fri Aug 1 · 21:50" in the NOC's timezone.
function dispatchLabel(iso: string): string {
  const d = new Date(iso)
  const day = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', weekday: 'short', month: 'short', day: 'numeric' }).format(d)
  const t = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', hour: '2-digit', minute: '2-digit', hour12: false }).format(d)
  return `${day} · ${t}`
}

// (2) alarm age — recomputed each clock tick via `now`.
function ageOf(started: string): string {
  const mins = Math.max(0, Math.floor((now.value - new Date(started).getTime()) / 60000))
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  if (h < 24) return `${h}h ${String(mins % 60).padStart(2, '0')}m`
  return `${Math.floor(h / 24)}d ${String(h % 24).padStart(2, '0')}h`
}

// (4) pause-on-newest — a fresh critical flashes and the scroll holds at the top
// (where criticals already sort) for a few seconds so the NOC sees it, then resumes.
const freshKeys = ref<Set<string>>(new Set())
const paused = ref(false)
let prevKeys = new Set<string>()
let pauseTimer: ReturnType<typeof setTimeout> | null = null
watch(alerts, (list) => {
  const keys = new Set((list as WbAlert[]).map(a => a.key))
  if (prevKeys.size) {
    const newCrit = (list as WbAlert[]).filter(a => a.severity === 'critical' && !prevKeys.has(a.key))
    if (newCrit.length) {
      const fresh = new Set(freshKeys.value)
      for (const a of newCrit) {
        fresh.add(a.key)
        setTimeout(() => { const s = new Set(freshKeys.value); s.delete(a.key); freshKeys.value = s }, 8000)
      }
      freshKeys.value = fresh
      paused.value = true
      if (pauseTimer) clearTimeout(pauseTimer)
      pauseTimer = setTimeout(() => { paused.value = false; nextTick(measure) }, 6000)
    }
  }
  prevKeys = keys
})

// Auto-scroll: when the list overflows the visible area, slowly cycle through it
// (two identical copies + a translateY(-50%) loop = seamless). Idle when it fits,
// and paused briefly on a fresh critical (which sits at the top).
const viewport = ref<HTMLElement | null>(null)
const copy = ref<HTMLElement | null>(null)
const overflow = ref(false)
const scrolling = computed(() => overflow.value && !paused.value)
const scrollDuration = computed(() => `${Math.max(12, alerts.value.length * 3)}s`)

function measure() {
  const vp = viewport.value
  const cp = copy.value
  overflow.value = !!vp && !!cp && cp.offsetHeight > vp.clientHeight + 4
}

watch(alerts, () => nextTick(measure))

// The alerts viewport lives inside a v-else, so it doesn't exist at mount (data
// loads async). Observe it the moment it appears — observing also fires an
// initial measure — and re-measure whenever its size changes.
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
      <VBtn variant="text" color="grey" icon="ri-close-line" @click="router.push('/')" />
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
      <div class="wb-alerts-title">Active Alerts</div>
      <div v-if="!alerts.length" class="wb-clear">✓ All clear</div>
      <div v-else ref="viewport" class="wb-alerts-viewport">
        <div
          class="wb-alerts-track"
          :class="{ 'wb-scrolling': scrolling }"
          :style="scrolling ? { animationDuration: scrollDuration } : undefined"
        >
          <div ref="copy" class="wb-copy">
            <template v-for="g in groups" :key="g.severity">
              <div class="wb-sec" :class="g.severity">
                {{ g.severity }} — {{ g.label }}<span class="wb-sec-count">{{ g.count }}</span>
              </div>
              <template v-for="s in g.sites" :key="g.severity + s.site">
                <div class="wb-site">
                  {{ s.site }}<span class="wb-site-count">{{ s.alarms.length }} alarm{{ s.alarms.length > 1 ? 's' : '' }}</span>
                </div>
                <div v-for="a in s.alarms" :key="a.key" class="wb-alert" :class="[a.severity, { fresh: freshKeys.has(a.key) }]">
                  <span class="wb-sev">{{ a.severity }}</span>
                  <span class="wb-alert-title">{{ a.title }}</span>
                  <span class="wb-alert-sub">{{ a.subtitle }}</span>
                  <span v-if="a.dispatch_at" class="wb-dispatch">🚚 Dispatched {{ dispatchLabel(a.dispatch_at) }}</span>
                  <span v-if="freshKeys.has(a.key)" class="wb-new">New</span>
                  <span class="wb-age">{{ ageOf(a.started_at) }}</span>
                </div>
              </template>
            </template>
          </div>
          <!-- Seamless-loop clone; only rendered while scrolling. -->
          <div v-if="scrolling" class="wb-copy" aria-hidden="true">
            <template v-for="g in groups" :key="`${g.severity}-dup`">
              <div class="wb-sec" :class="g.severity">
                {{ g.severity }} — {{ g.label }}<span class="wb-sec-count">{{ g.count }}</span>
              </div>
              <template v-for="s in g.sites" :key="`${g.severity + s.site}-dup`">
                <div class="wb-site">
                  {{ s.site }}<span class="wb-site-count">{{ s.alarms.length }} alarm{{ s.alarms.length > 1 ? 's' : '' }}</span>
                </div>
                <div v-for="a in s.alarms" :key="`${a.key}-dup`" class="wb-alert" :class="a.severity">
                  <span class="wb-sev">{{ a.severity }}</span>
                  <span class="wb-alert-title">{{ a.title }}</span>
                  <span class="wb-alert-sub">{{ a.subtitle }}</span>
                  <span v-if="a.dispatch_at" class="wb-dispatch">🚚 Dispatched {{ dispatchLabel(a.dispatch_at) }}</span>
                  <span class="wb-age">{{ ageOf(a.started_at) }}</span>
                </div>
              </template>
            </template>
          </div>
        </div>
      </div>
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
.wb-alerts-title { font-size: 1.1rem; font-weight: 600; margin-block-end: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; }
.wb-clear { font-size: 1.4rem; color: #4ade80; padding: 24px 0; }

/* Auto-scroll viewport: clip the overflow, let the track glide through it. */
.wb-alerts-viewport { flex: 1; min-block-size: 0; overflow: hidden; position: relative; }
.wb-alerts-track { display: flex; flex-direction: column; }
.wb-copy { display: flex; flex-direction: column; }
.wb-scrolling { animation-name: wb-scroll; animation-timing-function: linear; animation-iteration-count: infinite; will-change: transform; }
@keyframes wb-scroll {
  from { transform: translateY(0); }
  to { transform: translateY(-50%); }
}
@media (prefers-reduced-motion: reduce) {
  .wb-scrolling { animation: none; }
}
/* Severity section headers (critical-first ordering). */
.wb-sec { display: flex; align-items: center; gap: 10px; margin: 14px 0 8px; font-size: 0.74rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: #94a3b8; }
.wb-sec.critical { color: #f87171; }
.wb-sec.warning { color: #fbbf24; }
.wb-sec-count { font-family: ui-monospace, Menlo, monospace; font-size: 0.68rem; color: #94a3b8; background: rgba(255, 255, 255, 0.06); border-radius: 999px; padding: 1px 8px; }

/* Per-site grouping headers. */
.wb-site { display: flex; align-items: center; gap: 10px; margin: 10px 0 6px; font-size: 0.84rem; font-weight: 700; color: #cbd5e1; }
.wb-site-count { margin-inline-start: auto; font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; }

.wb-alert { display: flex; align-items: center; gap: 16px; padding: 14px 18px; border-radius: 10px; margin-block-end: 8px; background: #131a2a; border-inline-start: 4px solid #64748b; }
.wb-alert.critical { border-inline-start-color: #ef4444; }
.wb-alert.warning { border-inline-start-color: #f59e0b; }
.wb-sev { text-transform: uppercase; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; min-inline-size: 72px; color: #cbd5e1; }
.wb-alert.critical .wb-sev { color: #f87171; }
.wb-alert.warning .wb-sev { color: #fbbf24; }
.wb-alert-title { font-weight: 600; }
.wb-alert-sub { color: #94a3b8; }
.wb-dispatch { flex-shrink: 0; font-size: 0.7rem; font-weight: 700; color: #7dd3fc; background: rgba(125, 211, 252, 0.12); border: 1px solid rgba(125, 211, 252, 0.32); border-radius: 6px; padding: 3px 9px; white-space: nowrap; }
.wb-age { margin-inline-start: auto; font-family: ui-monospace, Menlo, monospace; font-size: 0.8rem; color: #64748b; font-variant-numeric: tabular-nums; flex-shrink: 0; }
.wb-new { background: #ef4444; color: #1a0505; font-size: 0.58rem; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; border-radius: 4px; padding: 2px 6px; flex-shrink: 0; }

/* (4) fresh-critical flash. */
.wb-alert.fresh { animation: wb-flash 1.6s ease-out infinite; }
@keyframes wb-flash {
  0%, 100% { box-shadow: 0 0 0 rgba(239, 68, 68, 0); }
  50% { box-shadow: 0 0 22px rgba(239, 68, 68, 0.45); }
}
@media (prefers-reduced-motion: reduce) {
  .wb-alert.fresh { animation: none; }
}
</style>
