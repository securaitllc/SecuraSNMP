<script setup lang="ts">
import { api } from '@/composables/useApi'
import type { DashboardSummary } from '@/types/models'

definePage({ meta: { layout: 'blank' } })

const router = useRouter()
const data = ref<DashboardSummary | null>(null)
const clock = ref('')

function tick() {
  clock.value = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/New_York', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
  }).format(new Date()) + ' EDT'
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

// Auto-scroll the alert list on a wall-mounted screen: when the list is taller
// than the visible area, slowly cycle through it so every alarm rotates into
// view. Two identical copies + a translateY(-50%) loop = a seamless, gapless
// scroll; disabled entirely when everything already fits.
const viewport = ref<HTMLElement | null>(null)
const copy = ref<HTMLElement | null>(null)
const scrolling = ref(false)
// ~3s per alert keeps it readable; floor so short overflowing lists don't race.
const scrollDuration = computed(() => `${Math.max(12, alerts.value.length * 3)}s`)

function measure() {
  const vp = viewport.value
  const cp = copy.value
  scrolling.value = !!vp && !!cp && cp.offsetHeight > vp.clientHeight + 4
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
            <div v-for="a in alerts" :key="a.key" class="wb-alert" :class="a.severity">
              <span class="wb-sev">{{ a.severity }}</span>
              <span class="wb-alert-title">{{ a.title }}</span>
              <span class="wb-alert-sub">{{ a.subtitle }}</span>
            </div>
          </div>
          <!-- Seamless-loop clone; only rendered while scrolling. -->
          <div v-if="scrolling" class="wb-copy" aria-hidden="true">
            <div v-for="a in alerts" :key="`${a.key}-dup`" class="wb-alert" :class="a.severity">
              <span class="wb-sev">{{ a.severity }}</span>
              <span class="wb-alert-title">{{ a.title }}</span>
              <span class="wb-alert-sub">{{ a.subtitle }}</span>
            </div>
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
.wb-alert { display: flex; align-items: center; gap: 16px; padding: 14px 18px; border-radius: 10px; margin-block-end: 10px; background: #131a2a; border-inline-start: 4px solid #64748b; }
.wb-alert.critical { border-inline-start-color: #ef4444; }
.wb-alert.warning { border-inline-start-color: #f59e0b; }
.wb-sev { text-transform: uppercase; font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; min-inline-size: 72px; color: #cbd5e1; }
.wb-alert.critical .wb-sev { color: #f87171; }
.wb-alert.warning .wb-sev { color: #fbbf24; }
.wb-alert-title { font-weight: 600; }
.wb-alert-sub { margin-inline-start: auto; color: #94a3b8; }
</style>
