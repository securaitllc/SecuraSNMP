<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { DashboardAlert, DashboardSummary, SearchResult } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()
const router = useRouter()

const data = ref<DashboardSummary | null>(null)
const isLoading = ref(true)
const loadError = ref('')

async function loadDashboard(silent = false) {
  if (!silent)
    isLoading.value = true
  loadError.value = ''
  try {
    data.value = await api<DashboardSummary>('/api/dashboard')
  }
  catch {
    if (!silent)
      loadError.value = 'Could not load the dashboard. Try refreshing.'
  }
  finally {
    isLoading.value = false
  }
}

// --- Global search (IP, hostname, address, ISP ticket, alarm/event id) ---
const searchResults = ref<SearchResult[]>([])
const searchIcon: Record<string, string> = {
  device: 'ri-router-line', circuit: 'ri-signal-tower-line', site: 'ri-map-pin-line',
  ticket: 'ri-coupon-3-line', alarm: 'ri-alarm-warning-line', endpoint: 'ri-cellphone-line',
}
let searchTimer: ReturnType<typeof setTimeout> | null = null
function onSearch(q: string) {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    searchResults.value = q && q.trim().length >= 2 ? await api<SearchResult[]>(`/api/search?q=${encodeURIComponent(q)}`) : []
  }, 180)
}
function onSearchSelect(r: SearchResult | null) {
  if (r?.route) router.push(r.route)
}

// Auto-refresh the dashboard so new alarms appear without a manual reload. The
// 30s cadence is the display refresh; detection still happens on the server-side
// SNMP poll cycle. isLoading only shows the spinner on the first load.
let refreshTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  loadDashboard()
  loadInsights()
  refreshTimer = setInterval(() => { loadDashboard(true); loadInsights() }, 30000)
})

onBeforeUnmount(() => {
  if (refreshTimer)
    clearInterval(refreshTimer)
})

// ---- formatting helpers ----
function formatCount(n: number): string {
  return n.toLocaleString()
}

function since(iso: string | null): string {
  if (!iso)
    return '—'
  const then = Date.parse(iso)
  if (Number.isNaN(then))
    return '—'
  let secs = Math.max(0, Math.floor((Date.now() - then) / 1000))
  const days = Math.floor(secs / 86400)
  secs -= days * 86400
  const hours = Math.floor(secs / 3600)
  secs -= hours * 3600
  const mins = Math.floor(secs / 60)
  if (days > 0)
    return `${days}d ${hours}h`
  if (hours > 0)
    return `${hours}h ${mins}m`

  return `${mins}m`
}

// ---- KPI strip ----
// A KPI is clickable when it maps to a filterable slice of active alerts and
// that slice is non-empty — clicking opens the alerts list scoped to that type.
const kpis = computed(() => {
  const c = data.value?.counts
  if (!c)
    return []

  const open = (type: 'all' | DashboardAlert['type'], count: number) =>
    count > 0 ? () => openAlertsList(type) : undefined

  return [
    { label: 'Sites', value: c.sites, danger: false },
    { label: 'Devices', value: c.devices, danger: false },
    { label: 'Active Alerts', value: c.active_alerts, danger: c.active_alerts > 0, action: open('all', c.active_alerts) },
    { label: 'Circuits Down', value: c.circuits_down, danger: c.circuits_down > 0, action: open('circuit', c.circuits_down) },
    { label: 'Interfaces Down', value: c.interfaces_down, danger: c.interfaces_down > 0, action: open('interface', c.interfaces_down) },
    { label: 'Tunnels Down', value: c.tunnels_down, danger: c.tunnels_down > 0, action: open('tunnel', c.tunnels_down) },
    // Degraded is deliberately NOT flagged danger — a threshold breach is not an
    // outage, and colouring it red is how the real ones got lost in the first place.
    // It still needs a way in, otherwise the category and its bulk clear are
    // unreachable.
    { label: 'Link Quality', value: c.tunnels_degraded ?? 0, danger: false, action: open('tunnel-quality', c.tunnels_degraded ?? 0) },
  ]
})

// ---- alerts breakdown (flat operator-style bars, one row per resource type) ----
// Bar length is relative to the largest bucket so the busiest category always
// reads as full-width. Rows are clickable to open that slice of the alert list.
const alertBreakdown = computed(() => {
  const c = data.value?.counts
  if (!c)
    return []

  // Colour each bar by the WORST real severity among that type's alerts (X.733:
  // an access-port or a redundant tunnel-down is amber, not red) so the summary
  // bars agree with the per-alert chips instead of blanket-reddening by type.
  const flat = (data.value?.alerts ?? []).flatMap(a => a.type === 'incident' ? (a.members ?? []) : [a])
  const worstColor = (type: string) => {
    const sevs = flat.filter(a => a.type === type).map(a => a.severity)
    if (sevs.includes('critical')) return 'rgb(var(--v-theme-error))'
    if (sevs.includes('warning')) return 'rgb(var(--v-theme-warning))'
    return 'rgb(var(--v-theme-success))'
  }
  const rows: { key: 'interface' | 'tunnel' | 'circuit' | 'alarm', label: string, value: number, color: string }[] = [
    { key: 'interface', label: 'Interfaces', value: c.interfaces_down, color: worstColor('interface') },
    { key: 'circuit', label: 'Circuits', value: c.circuits_down, color: worstColor('circuit') },
    { key: 'tunnel', label: 'Tunnels', value: c.tunnels_down, color: worstColor('tunnel') },
    { key: 'alarm', label: 'Alarms', value: c.active_alarms, color: worstColor('alarm') },
  ]
  const max = Math.max(1, ...rows.map(r => r.value))

  return rows.map(r => ({ ...r, pct: Math.round((r.value / max) * 100) }))
})

// ---- clickable alerts ----
const selectedAlert = ref<DashboardAlert | null>(null)
const isAlertDetailOpen = ref(false)
const isAlertsListOpen = ref(false)

// Active Alarms view: grouped-per-ISP (the redesign) or the flat breakdown+list.
const alarmView = ref<'grouped' | 'flat'>('grouped')

// Alerts-list scope: 'all' or one resource type. Set by the KPI cards and the
// breakdown rows so a click drills straight into the relevant outages.
const alertsFilter = ref<'all' | DashboardAlert['type']>('all')
const alertsFilterLabel: Record<'all' | DashboardAlert['type'], string> = {
  all: 'Active Alerts', circuit: 'Circuits Down', interface: 'Interfaces Down',
  tunnel: 'Tunnels Down', 'tunnel-quality': 'Link Quality', alarm: 'Active Alarms',
  next_hop: 'Next-hop Down', incident: 'Incidents',
}
function openAlertsList(type: 'all' | DashboardAlert['type']) {
  alertsFilter.value = type
  isAlertsListOpen.value = true
}
const filteredAlerts = computed(() => {
  const all = data.value?.alerts ?? []
  if (alertsFilter.value === 'all')
    return all

  // A type filter (Alarms / Tunnels / …) must look INSIDE correlated incidents —
  // their individual signals are what the operator wants when drilling a KPI, or
  // the popup comes up empty because every signal is hidden in an incident.
  const flat: DashboardAlert[] = []
  for (const a of all) {
    if (a.type === 'incident' && a.members)
      flat.push(...a.members)
    else
      flat.push(a)
  }

  return flat.filter(a => a.type === alertsFilter.value)
})

// Per-type NOC runbook shown in the detail dialog — "what to do" for each alert.
const runbooks: Record<DashboardAlert['type'], string[]> = {
  circuit: [
    'Confirm the outage with a manual ping to the monitored IP.',
    'Call the ISP and open — or reference the previous — ticket.',
    'Record the ISP ticket number below so the whole NOC can track it.',
    'Acknowledge while the ISP works it; Clear once restored or if it was a false positive.',
  ],
  interface: [
    'Open the device and check the physical port, optics, and cabling.',
    'Confirm it is not an intended shutdown — admin-down ports are already excluded.',
    'Review the port’s neighbor and traffic history before dispatching a tech.',
  ],
  tunnel: [
    'Check the underlay WAN circuits at both tunnel endpoints.',
    'Inspect EdgeConnect reachability and next-hop health.',
    'If the underlay is healthy, escalate to the SD-WAN overlay.',
  ],
  'tunnel-quality': [
    'This is a sampled latency, jitter or loss figure crossing its threshold — the tunnel is up and passing traffic.',
    'On bulk or management tunnels this usually reflects orchestrator or cloud conditions upstream, not a site fault.',
    'Confirm the site is otherwise healthy: underlay circuits up, no WAN or next-hop alarms.',
    'If a whole batch fired together, select them and Clear in bulk with a note naming the upstream event.',
    'Only escalate if it persists on a business-critical tunnel or coincides with a user-reported problem.',
  ],
  alarm: [
    'Review the vendor Event ID for the exact appliance condition.',
    'Acknowledge with a note while you investigate.',
    'Clear with a resolution note once fixed — it will not reopen unless it flaps.',
  ],
  next_hop: [
    'Verify the upstream router / gateway is reachable.',
    'Check the WAN path and any recent config or routing changes.',
  ],
  incident: [
    'These signals are one outage on the same device — work the root, not each symptom.',
    'Open a correlated signal below to see its detail and act on it (acknowledge / clear).',
  ],
}

// A whole-site outage (several devices unreachable at once) is one event, not the
// same-device incident above — its runbook points at the shared cause.
const siteOutageRunbook = [
  'Several devices at this site went unreachable together — treat it as one site-wide outage, not separate device faults.',
  'Check the site’s WAN edge (the SD-WAN appliance) and site power first — that is what drops everything at once.',
  'Then the uplink: circuit / next-hop, and escalate to the ISP if the edge is healthy.',
  'Open each device below to acknowledge or clear it once the site is restored.',
]

// The runbook for the open alert — site outages get their own; everything else is
// keyed by type.
const runbookSteps = computed<string[]>(() => {
  const a = selectedAlert.value
  if (!a) return []
  return a.is_site_outage ? siteOutageRunbook : runbooks[a.type]
})

// The single-device root-cause analysis (from the topology strip) is right for a
// same-device incident but wrong for a site outage — a site outage speaks for
// itself via its own subtitle/members, so don't let "Switch down — X" override it.
const useSiteRootCause = computed(() =>
  (siteIncident.value?.active ?? false) && !selectedAlert.value?.is_site_outage)

// When drilling from a correlated incident into one of its signals, remember the
// incident so the detail view can offer a way back.
const parentIncident = ref<DashboardAlert | null>(null)

// The site's root-cause analysis, reported by the topology strip once it loads.
// The dialog states it; the strip no longer repeats it underneath.
const siteIncident = ref<{
  active: boolean
  summary: string
  symptoms: string[]
  action: string | null
  support_phone: string | null
} | null>(null)

const showWhy = ref(false)

/** The support line to call, whichever source knows it. Rendered once, as a button. */
const supportPhone = computed(() =>
  selectedAlert.value?.support_phone ?? siteIncident.value?.support_phone ?? null)

const telHref = computed(() => {
  const raw = supportPhone.value
  return raw ? `tel:${raw.replace(/[^\d+]/g, '')}` : null
})

/**
 * The facts worth stating for this alert, minus anything the header already shows.
 * Returning them as a list keeps one rendering path for every alert type instead of
 * a stack of type-specific v-ifs, each of which was a chance to print something twice.
 */
const alertFacts = computed(() => {
  const a = selectedAlert.value
  if (!a)
    return []

  const facts: { label: string, value: string, mono?: boolean }[] = []

  if (a.device_name && a.type !== 'incident')
    facts.push({ label: 'Device', value: a.device_name })
  if (a.device_ip)
    facts.push({ label: 'Device IP', value: a.device_ip, mono: true })
  if (a.ticket_number)
    facts.push({ label: a.type === 'circuit' ? 'ISP ticket' : 'Ticket', value: `#${a.ticket_number}`, mono: true })
  if (a.acknowledged_at)
    facts.push({ label: 'Acknowledged', value: `${a.acknowledged_by ?? 'yes'} · ${since(a.acknowledged_at)}` })
  if (a.event_id)
    facts.push({ label: 'Event ID', value: a.event_id, mono: true })

  return facts
})

function resetAlertView() {
  alarmNote.value = ''
  siteIncident.value = null
  showWhy.value = false
}

function openAlert(alert: DashboardAlert) {
  parentIncident.value = null
  selectedAlert.value = alert
  resetAlertView()
  circuitTicket.value = alert.ticket_number ?? ''
  isAlertDetailOpen.value = true
}

function drillMember(member: DashboardAlert) {
  parentIncident.value = selectedAlert.value
  selectedAlert.value = member
  resetAlertView()
  circuitTicket.value = member.ticket_number ?? ''
}

function backToIncident() {
  if (!parentIncident.value)
    return
  selectedAlert.value = parentIncident.value
  parentIncident.value = null
  alarmNote.value = ''
}

function goToAlertResource(alert: DashboardAlert) {
  isAlertDetailOpen.value = false
  isAlertsListOpen.value = false
  if (alert.circuit_id)
    // By id — '/circuits' alone dropped the operator on an unfiltered list.
    router.push({ path: '/circuits', query: { circuit: String(alert.circuit_id) } })
  else if (alert.device_id)
    router.push(`/devices/${alert.device_id}`)
}

// --- Alarm acknowledge / clear (NOC workflow) ---
const alarmNote = ref('')
const alarmBusy = ref(false)

async function acknowledgeAlarm() {
  const id = selectedAlert.value?.alarm_db_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/alarms/${id}/acknowledge`, { method: 'POST', body: { note: alarmNote.value || null } })
    isAlertDetailOpen.value = false
    alarmNote.value = ''
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

async function clearAlarm() {
  const id = selectedAlert.value?.alarm_db_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/alarms/${id}/clear`, { method: 'POST', body: { note: alarmNote.value || null } })
    isAlertDetailOpen.value = false
    alarmNote.value = ''
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

// --- Circuit acknowledge / clear / ISP ticket (same NOC workflow as alarms) ---
const circuitTicket = ref('')

async function circuitAction(path: 'acknowledge' | 'clear', keepOpen = false) {
  const id = selectedAlert.value?.circuit_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/circuits/${id}/${path}`, { method: 'POST', body: { note: alarmNote.value || null } })
    if (!keepOpen)
      isAlertDetailOpen.value = false
    alarmNote.value = ''
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

async function saveCircuitTicket() {
  const id = selectedAlert.value?.circuit_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/circuits/${id}/ticket`, { method: 'POST', body: { ticket_number: circuitTicket.value || null } })
    if (selectedAlert.value)
      selectedAlert.value.ticket_number = circuitTicket.value || null
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

// Type drives the LABEL only. Colour is driven by SEVERITY everywhere so the
// meaning is consistent across the app:
//   critical = RED  = service-affecting (something is DOWN, or appliance CRI/MAJ)
//   warning  = AMBER = degraded but still up (jitter, IP-SLA, high CPU, MIN/WARN)
//   info     = grey  = informational only
const alertTypeMeta: Record<DashboardAlert['type'], { label: string }> = {
  circuit: { label: 'Circuit' },
  interface: { label: 'Interface' },
  tunnel: { label: 'Tunnel' },
  'tunnel-quality': { label: 'Link quality' },
  next_hop: { label: 'Next-hop' },
  alarm: { label: 'Alarm' },
  incident: { label: 'Incident' },
}
const severityColor: Record<string, string> = { critical: 'error', warning: 'warning', info: 'info' }

// ---- bulk clear -----------------------------------------------------------
// A single upstream latency event raises one alarm per tunnel per appliance, so
// these arrive in floods. Clearing them one at a time is attrition, and an operator
// doing it by reflex eventually clears a real outage.
const selectedAlarms = ref<number[]>([])
const isBulkClearOpen = ref(false)
const bulkNote = ref('')
const bulkBusy = ref(false)

/** Only alerts backed by a DeviceAlarm row can be cleared this way. */
const clearableAlerts = computed(() => filteredAlerts.value.filter(a => a.alarm_ref))
const canBulkClear = computed(() => auth.canAct && clearableAlerts.value.length > 0)
const allSelected = computed(() =>
  clearableAlerts.value.length > 0 && selectedAlarms.value.length === clearableAlerts.value.length)

function toggleAlarm(id: number) {
  const i = selectedAlarms.value.indexOf(id)
  if (i === -1)
    selectedAlarms.value.push(id)
  else
    selectedAlarms.value.splice(i, 1)
}

function toggleAll() {
  selectedAlarms.value = allSelected.value ? [] : clearableAlerts.value.map(a => a.alarm_ref as number)
}

// Selecting rows then changing the filter would leave a selection the operator can
// no longer see — and then clear it blind.
watch([alertsFilter, isAlertsListOpen], () => { selectedAlarms.value = [] })

async function submitBulkClear() {
  if (selectedAlarms.value.length === 0)
    return
  bulkBusy.value = true
  try {
    await api('/api/alarms/bulk-clear', {
      method: 'POST',
      body: { ids: selectedAlarms.value, note: bulkNote.value || null },
    })
    isBulkClearOpen.value = false
    bulkNote.value = ''
    selectedAlarms.value = []
    await loadDashboard()
  }
  finally { bulkBusy.value = false }
}

// ---- redesign: productivity insights (24h trend, MTTR, recurring offenders) ----
interface Insights { trend_24h: number[]; raised_24h: number; resolved_24h: number; mttr_minutes: number | null; top_offenders: { site_id: number; site_name: string; count: number }[] }
const insights = ref<Insights | null>(null)
async function loadInsights() {
  try { insights.value = await api<Insights>('/api/dashboard/insights') }
  catch { /* non-fatal — the hero degrades gracefully */ }
}

// Fleet health for the hero ring (sites healthy vs alerting).
const fleetHealth = computed(() => {
  const sites = data.value?.sites ?? []
  const total = data.value?.counts.sites ?? sites.length
  const alerting = sites.filter(s => (s.active_alert_count ?? 0) > 0).length
  return { total, alerting, healthy: Math.max(0, total - alerting), pct: total ? Math.round(((total - alerting) / total) * 100) : 100 }
})
// The ring's healthy arc length (of 100) — clamped so a tiny outage still shows red.
const ringHealthy = computed(() => {
  const f = fleetHealth.value
  if (!f.total || !f.alerting) return 100
  return Math.min(98.5, Math.round((f.healthy / f.total) * 1000) / 10)
})

// Active-alarm severity split for the hero (looks inside correlated incidents).
const severitySplit = computed(() => {
  const flat = (data.value?.alerts ?? []).flatMap(a => a.type === 'incident' ? (a.members ?? []) : [a])
  return {
    critical: flat.filter(a => a.severity === 'critical').length,
    warning: flat.filter(a => a.severity === 'warning').length,
    info: flat.filter(a => a.severity !== 'critical' && a.severity !== 'warning').length,
  }
})

// 24h trend sparkline points.
const trendSpark = computed(() => {
  const t = insights.value?.trend_24h ?? []
  if (t.length < 2) return ''
  const max = Math.max(1, ...t)
  const w = 240, h = 40
  return t.map((v, i) => `${((i / (t.length - 1)) * w).toFixed(1)},${(h - (v / max) * h).toFixed(1)}`).join(' ')
})

const topOffenders = computed(() => insights.value?.top_offenders ?? [])
const offenderMax = computed(() => Math.max(1, ...topOffenders.value.map(o => o.count)))

// Right-rail KPI chips (danger-flagged live outage counts), reusing the KPI actions.
const railKpis = computed(() => kpis.value.filter(k => ['Circuits Down', 'Interfaces Down', 'Tunnels Down', 'Link Quality'].includes(k.label)))
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-medium">
          Network Overview
        </h1>
        <div class="text-body-2 text-medium-emphasis">
          Signed in as {{ auth.user?.name }} ({{ auth.user?.role }})
        </div>
      </div>
      <div class="d-flex align-center ga-3">
        <span class="text-caption text-medium-emphasis">Auto-refreshes every 30s</span>
        <VBtn
          variant="tonal"
          size="small"
          prepend-icon="ri-refresh-line"
          :loading="isLoading"
          @click="loadDashboard"
        >
          Refresh
        </VBtn>
      </div>
    </div>

    <!-- Global search -->
    <VAutocomplete
      :items="searchResults"
      item-title="label"
      item-value="route"
      return-object
      no-filter
      clearable
      hide-details
      density="comfortable"
      variant="solo"
      class="mb-4"
      placeholder="Search device, IP, hostname, site, MAC, phone extension, ISP ticket, or alarm/event ID…"
      prepend-inner-icon="ri-search-line"
      :menu-props="{ maxHeight: 400 }"
      @update:search="onSearch"
      @update:model-value="onSearchSelect"
    >
      <template #item="{ props, item }">
        <VListItem
          v-bind="props"
          :prepend-icon="searchIcon[item.raw.type]"
          :title="item.raw.label"
          :subtitle="item.raw.sub ?? item.raw.type"
        >
          <template #append>
            <span class="text-caption text-medium-emphasis text-capitalize">{{ item.raw.type }}</span>
          </template>
        </VListItem>
      </template>
      <template #no-data>
        <VListItem title="Type at least 2 characters to search" />
      </template>
    </VAutocomplete>

    <VAlert
      v-if="loadError"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ loadError }}
    </VAlert>

    <!-- Health hero: fleet ring + active-alarm severity split + 24h trend & MTTR.
         Replaces the flat KPI tiles with a status summary an operator reads at a glance. -->
    <div class="hero-strip mb-4">
      <VCard class="hero-card">
        <div class="hero-title">Fleet health</div>
        <div class="hero-ring">
          <svg viewBox="0 0 36 36" class="ring-svg">
            <circle cx="18" cy="18" r="15.9" fill="none" class="ring-bg" stroke-width="3.4" />
            <circle
              cx="18" cy="18" r="15.9" fill="none" class="ring-ok" stroke-width="3.4"
              :stroke-dasharray="`${ringHealthy} 100`" stroke-dashoffset="25" stroke-linecap="round" transform="rotate(-90 18 18)"
            />
          </svg>
          <div>
            <div class="hero-big">{{ fleetHealth.healthy }}<small> / {{ fleetHealth.total }}</small></div>
            <div class="hero-sub">sites healthy</div>
            <div class="hero-legend">
              <span><i class="hdot ok" />Healthy {{ fleetHealth.healthy }}</span>
              <span><i class="hdot crit" />Alerting {{ fleetHealth.alerting }}</span>
            </div>
          </div>
        </div>
      </VCard>

      <VCard class="hero-card">
        <div class="hero-title">Active alarms</div>
        <div class="hero-big mb-2">{{ data?.counts.active_alerts ?? 0 }}<small> open</small></div>
        <div class="sev-split">
          <div v-if="severitySplit.critical" class="ss c" :style="{ flexGrow: severitySplit.critical }">{{ severitySplit.critical }}</div>
          <div v-if="severitySplit.warning" class="ss w" :style="{ flexGrow: severitySplit.warning }">{{ severitySplit.warning }}</div>
          <div v-if="severitySplit.info" class="ss i" :style="{ flexGrow: severitySplit.info }">{{ severitySplit.info }}</div>
          <div v-if="!(data?.counts.active_alerts)" class="ss-clear">All clear</div>
        </div>
        <div class="hero-legend mt-2">
          <span><i class="hdot crit" />Critical {{ severitySplit.critical }}</span>
          <span><i class="hdot warn" />Warning {{ severitySplit.warning }}</span>
        </div>
      </VCard>

      <VCard class="hero-card">
        <div class="hero-title">Last 24 hours</div>
        <div class="d-flex justify-space-between align-baseline">
          <div>
            <div class="hero-big">{{ insights?.raised_24h ?? 0 }}</div>
            <div class="hero-sub">alarms raised · {{ insights?.resolved_24h ?? 0 }} cleared</div>
          </div>
          <div class="hero-mttr">MTTR {{ insights?.mttr_minutes != null ? insights.mttr_minutes + 'm' : '—' }}</div>
        </div>
        <svg v-if="trendSpark" viewBox="0 0 240 40" class="hero-spark" preserveAspectRatio="none">
          <polyline fill="none" :points="trendSpark" />
        </svg>
      </VCard>
    </div>

    <!-- Sites Health: map + per-site table -->
    <VCard class="mb-5">
      <VCardItem>
        <VCardTitle>Sites Health</VCardTitle>
        <template #append>
          <VChip
            size="small"
            color="success"
            variant="tonal"
          >
            Active now
          </VChip>
        </template>
      </VCardItem>
      <VCardText>
        <SiteMap
          :sites="data?.sites ?? []"
          :alerts="data?.alerts ?? []"
          :height="480"
          @open-alert="openAlert"
        />
      </VCardText>
    </VCard>

    <VRow class="mb-2">
      <!-- Active Alerts — the operator work surface -->
      <VCol
        cols="12"
        lg="8"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Active Alarms</VCardTitle>
            <template #append>
              <div class="d-flex align-center ga-3">
                <div class="switch-toggle">
                  <button :class="{ on: alarmView === 'grouped' }" @click="alarmView = 'grouped'">
                    <VIcon icon="ri-router-line" />By ISP
                  </button>
                  <button :class="{ on: alarmView === 'flat' }" @click="alarmView = 'flat'">
                    <VIcon icon="ri-list-check" />Flat
                  </button>
                </div>
                <VChip
                  size="small"
                  :color="(data?.counts.active_alerts ?? 0) > 0 ? 'error' : 'success'"
                  variant="tonal"
                >
                  {{ data?.counts.active_alerts ?? 0 }} active
                </VChip>
              </div>
            </template>
          </VCardItem>

          <!-- Grouped-per-ISP view (default): device alarms, circuit outages and
               interface alerts folded per provider, one ISP ticket/dispatch each. -->
          <VCardText v-if="alarmView === 'grouped'">
            <AlarmGroups />
          </VCardText>

          <VCardText v-else>
            <div
              v-if="(data?.counts.active_alerts ?? 0) > 0"
              class="mb-2"
            >
              <div
                v-for="row in alertBreakdown"
                :key="row.key"
                class="breakdown-row"
                :class="{ 'is-clickable': row.value > 0 }"
                role="button"
                tabindex="0"
                @click="row.value > 0 && openAlertsList(row.key)"
                @keydown.enter="row.value > 0 && openAlertsList(row.key)"
              >
                <span class="breakdown-label text-body-2">{{ row.label }}</span>
                <span class="breakdown-track">
                  <span
                    class="breakdown-fill"
                    :style="{ width: `${row.pct}%`, backgroundColor: row.color }"
                  />
                </span>
                <span
                  class="breakdown-value text-body-2"
                  :class="row.value > 0 ? 'font-weight-medium' : 'text-disabled'"
                >{{ row.value }}</span>
              </div>
            </div>

            <!-- Severity legend — the app-wide convention -->
            <div
              v-if="(data?.counts.active_alerts ?? 0) > 0"
              class="d-flex ga-4 text-caption text-medium-emphasis mt-1 mb-2"
            >
              <span class="d-flex align-center ga-1">
                <span class="dot" :style="{ backgroundColor: 'rgb(var(--v-theme-error))' }" />
                Critical — service down
              </span>
              <span class="d-flex align-center ga-1">
                <span class="dot" :style="{ backgroundColor: 'rgb(var(--v-theme-warning))' }" />
                Warning — degraded
              </span>
            </div>

            <VDivider
              v-if="(data?.counts.active_alerts ?? 0) > 0"
              class="mb-2"
            />

            <div
              v-if="!isLoading && (data?.alerts.length ?? 0) === 0"
              class="all-clear"
            >
              <div class="all-clear-shield">
                <VIcon
                  icon="ri-shield-check-fill"
                  size="72"
                />
              </div>
              <div class="all-clear-title">
                All Clear
              </div>
              <div class="all-clear-sub">
                No active alerts across {{ data?.counts.sites ?? 0 }} sites · {{ data?.counts.devices ?? 0 }} devices
              </div>
            </div>

            <div
              v-for="alert in (data?.alerts ?? []).slice(0, 6)"
              :key="alert.key"
              class="alert-row"
              role="button"
              tabindex="0"
              @click="openAlert(alert)"
              @keydown.enter="openAlert(alert)"
            >
              <div class="d-flex align-center justify-space-between ga-2">
                <span class="d-flex align-center ga-2 min-w-0">
                  <!-- Site first: "Lumen — DSLTL18-23703944" does not say where it is. -->
                  <span
                    v-if="alert.site_name"
                    class="alert-site"
                  >{{ alert.site_name }}</span>
                  <span class="font-weight-medium text-truncate">{{ alert.title }}</span>
                </span>
                <span class="text-caption text-medium-emphasis flex-shrink-0">{{ since(alert.started_at) }}</span>
              </div>
              <div class="d-flex align-center ga-2">
                <VChip
                  size="x-small"
                  :color="severityColor[alert.severity] ?? 'warning'"
                  variant="tonal"
                >
                  {{ alertTypeMeta[alert.type].label }}
                </VChip>
                <span class="text-caption text-medium-emphasis text-truncate">{{ alert.subtitle }}</span>
                <span
                  v-if="alert.ticket_number"
                  class="text-caption text-medium-emphasis"
                >· #{{ alert.ticket_number }}</span>
              </div>
            </div>

            <VBtn
              v-if="(data?.alerts.length ?? 0) > 6"
              variant="text"
              size="small"
              class="mt-1"
              @click="openAlertsList('all')"
            >
              View all {{ data?.alerts.length }} alerts
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Right rail: live outage KPIs with click-to-drill + recurring offenders -->
      <VCol cols="12" lg="4">
        <div class="rail-kpis mb-4">
          <VCard
            v-for="k in railKpis"
            :key="k.label"
            class="rail-kpi"
            :class="{ 'cursor-pointer': k.action, 'is-danger': k.danger }"
            @click="k.action?.()"
          >
            <div class="rail-kpi__k">{{ k.label }}</div>
            <div class="rail-kpi__v" :class="k.danger ? 'text-error' : ''">{{ isLoading ? '—' : formatCount(k.value) }}</div>
          </VCard>
        </div>
        <VCard>
          <VCardItem><VCardTitle class="text-body-1">Top offenders · 7 days</VCardTitle></VCardItem>
          <VCardText class="pt-0">
            <div v-if="!topOffenders.length" class="text-medium-emphasis text-body-2 py-2">No recurring alarms — quiet week.</div>
            <RouterLink
              v-for="(o, i) in topOffenders"
              :key="o.site_id"
              :to="`/sites?q=${encodeURIComponent(o.site_name)}`"
              class="offender-row"
            >
              <span class="offender-rank">{{ i + 1 }}</span>
              <span class="offender-name">{{ o.site_name }}</span>
              <span class="offender-bar"><i :style="{ width: `${Math.round((o.count / offenderMax) * 100)}%` }" /></span>
              <span class="offender-n">{{ o.count }}</span>
            </RouterLink>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Contracts expiring — ops accountability, not a network outage -->
      <VCol
        v-if="(data?.contracts_expiring?.length ?? 0) > 0"
        cols="12"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Contracts Expiring</VCardTitle>
            <template #append>
              <VChip size="small" color="warning" variant="tonal">
                {{ data?.contracts_expiring.length }} within 60 days
              </VChip>
            </template>
          </VCardItem>
          <VCardText>
            <RouterLink
              v-for="c in (data?.contracts_expiring ?? [])"
              :key="c.id"
              to="/circuits"
              class="contract-row"
            >
              <span class="d-flex align-center ga-2 min-w-0">
                <VChip
                  size="x-small"
                  :color="c.status === 'expired' || c.status === 'warning' ? 'error' : 'warning'"
                  variant="flat"
                >
                  {{ c.days_to_expiry != null && c.days_to_expiry < 0 ? 'Expired' : `${c.days_to_expiry}d` }}
                </VChip>
                <span class="font-weight-medium text-truncate">{{ c.isp_name ?? '—' }} — {{ c.circuit_id }}</span>
                <span class="text-caption text-medium-emphasis text-truncate">{{ c.site_name }}</span>
              </span>
              <span class="text-caption text-medium-emphasis flex-shrink-0">{{ c.contract_end_date }}</span>
            </RouterLink>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Alert detail dialog -->
    <VDialog
      v-model="isAlertDetailOpen"
      max-width="1440"
      width="97vw"
      scrollable
    >
      <VCard v-if="selectedAlert">
        <VCardItem>
          <!-- Where, then what. Severity, kind and age are stated once, here, so the
               body never has to repeat them as fields. -->
          <div class="alert-head">
            <div class="min-w-0">
              <div class="alert-head__meta">
                <span
                  v-if="selectedAlert.site_name"
                  class="alert-site"
                >{{ selectedAlert.site_name }}</span>
                <span class="alert-head__sev" :class="`is-${selectedAlert.severity}`">
                  {{ selectedAlert.severity }}
                </span>
                <span class="alert-head__dot">·</span>
                <span>{{ alertTypeMeta[selectedAlert.type].label }}</span>
                <span class="alert-head__dot">·</span>
                <span>{{ since(selectedAlert.started_at) }}</span>
              </div>
              <div class="alert-head__title">
                {{ selectedAlert.title }}
              </div>
            </div>

            <!-- The support line appears once, as the action it actually is. -->
            <VBtn
              v-if="telHref"
              :href="telHref"
              variant="tonal"
              size="small"
              prepend-icon="ri-phone-line"
              class="flex-shrink-0"
            >
              {{ supportPhone }}
            </VBtn>
          </div>
        </VCardItem>
        <VCardText>
          <VBtn
            v-if="parentIncident"
            variant="text"
            size="small"
            prepend-icon="ri-arrow-left-line"
            class="mb-2 ps-1"
            @click="backToIncident()"
          >
            Back to incident
          </VBtn>

          <!-- One shell for every alert type: what is wrong on the left, the path it
               depends on to the right. Type-specific parts are sections inside it, so
               a circuit and a tunnel read the same way. -->
          <VRow>
            <VCol
              cols="12"
              :md="selectedAlert.site_id ? 5 : 12"
              class="d-flex flex-column ga-4"
            >
              <section>
                <h4 class="alert-sec">
                  Diagnosis
                </h4>
                <p class="alert-lede">
                  {{ useSiteRootCause ? siteIncident?.summary : selectedAlert.subtitle }}
                </p>

                <!-- Detail lines: the alert's own, plus the correlated symptoms the
                     site analysis found. Deduplicated so nothing is said twice. -->
                <ul class="alert-signals">
                  <li v-if="selectedAlert.detail && selectedAlert.detail !== selectedAlert.subtitle">
                    {{ selectedAlert.detail }}
                  </li>
                  <li
                    v-for="sym in (useSiteRootCause ? (siteIncident?.symptoms ?? []) : [])"
                    :key="sym"
                  >
                    {{ sym }}
                  </li>
                </ul>

                <!-- The reasoning is available but not in the way of an operator who
                     already knows it. -->
                <div v-if="useSiteRootCause && siteIncident?.action">
                  <button
                    type="button"
                    class="alert-why"
                    @click="showWhy = !showWhy"
                  >
                    <VIcon
                      :icon="showWhy ? 'ri-arrow-down-s-line' : 'ri-arrow-right-s-line'"
                      size="16"
                    />
                    Why this happens
                  </button>
                  <p
                    v-if="showWhy"
                    class="alert-why-body"
                  >
                    {{ siteIncident.action }}
                  </p>
                </div>

                <dl
                  v-if="alertFacts.length"
                  class="alert-facts"
                >
                  <template
                    v-for="f in alertFacts"
                    :key="f.label"
                  >
                    <dt>{{ f.label }}</dt>
                    <dd :class="{ mono: f.mono }">
                      {{ f.value }}
                    </dd>
                  </template>
                </dl>

                <VAlert
                  v-if="selectedAlert.type === 'circuit' && !selectedAlert.ticket_number && selectedAlert.previous_ticket_number"
                  type="info"
                  variant="tonal"
                  density="compact"
                  class="mt-3 text-body-2"
                >
                  Last ISP ticket <strong>#{{ selectedAlert.previous_ticket_number }}</strong> — reference or reopen it for this recurring outage.
                </VAlert>
              </section>

              <!-- Correlated incident: its signals are the thing to act on. -->
              <section v-if="selectedAlert.type === 'incident'">
                <h4 class="alert-sec">
                  Correlated signals ({{ selectedAlert.member_count }})
                </h4>
                <div
                  v-for="m in selectedAlert.members"
                  :key="m.key"
                  class="alert-row"
                  role="button"
                  tabindex="0"
                  @click="drillMember(m)"
                  @keydown.enter="drillMember(m)"
                >
                  <div class="d-flex align-center justify-space-between ga-2">
                    <span class="font-weight-medium text-truncate">{{ m.title }}</span>
                    <span class="text-caption text-medium-emphasis flex-shrink-0">{{ since(m.started_at) }}</span>
                  </div>
                  <div class="d-flex align-center ga-2">
                    <VChip
                      size="x-small"
                      :color="severityColor[m.severity] ?? 'warning'"
                      variant="tonal"
                    >
                      {{ alertTypeMeta[m.type].label }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis">{{ m.subtitle }}</span>
                  </div>
                </div>
              </section>

              <section>
                <h4 class="alert-sec">
                  What to do
                </h4>
                <ol class="runbook">
                  <li
                    v-for="(step, i) in runbookSteps"
                    :key="i"
                    class="text-body-2"
                  >
                    {{ step }}
                  </li>
                </ol>
              </section>

              <section
                v-if="(selectedAlert.type === 'circuit' && selectedAlert.circuit_id) || (selectedAlert.type === 'alarm' && selectedAlert.alarm_db_id)"
                class="d-flex flex-column ga-2"
              >
                <div
                  v-if="selectedAlert.type === 'circuit' && selectedAlert.circuit_id"
                  class="d-flex align-end ga-2"
                >
                  <VTextField
                    v-model="circuitTicket"
                    label="ISP ticket #"
                    placeholder="e.g. INC-39900"
                    hide-details
                    density="comfortable"
                    class="flex-grow-1"
                  />
                  <VBtn
                    :loading="alarmBusy"
                    variant="tonal"
                    @click="saveCircuitTicket()"
                  >
                    Save
                  </VBtn>
                </div>

                <VTextarea
                  v-model="alarmNote"
                  label="Investigation / resolution note (optional)"
                  rows="2"
                  auto-grow
                  hide-details
                  density="comfortable"
                />
              </section>
            </VCol>

            <!-- The dependency path. Diagram only — the narrative is stated once, left. -->
            <VCol
              v-if="selectedAlert.site_id"
              cols="12"
              md="7"
            >
              <h4 class="alert-sec">
                Path
              </h4>
              <TopologyStrip
                :key="selectedAlert.site_id"
                :site-id="selectedAlert.site_id"
                :show-diagnosis="false"
                @loaded="siteIncident = $event"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <template v-if="auth.canAct && selectedAlert.type === 'alarm' && selectedAlert.alarm_db_id">
            <VBtn
              :loading="alarmBusy"
              variant="tonal"
              @click="acknowledgeAlarm()"
            >
              {{ selectedAlert.acknowledged_at ? 'Save note' : 'Acknowledge' }}
            </VBtn>
            <VBtn
              :loading="alarmBusy"
              color="success"
              variant="flat"
              @click="clearAlarm()"
            >
              Clear
            </VBtn>
          </template>
          <template v-if="auth.canAct && selectedAlert.type === 'circuit' && selectedAlert.circuit_id">
            <VBtn
              :loading="alarmBusy"
              variant="tonal"
              @click="circuitAction('acknowledge')"
            >
              {{ selectedAlert.acknowledged_at ? 'Save note' : 'Acknowledge' }}
            </VBtn>
            <VBtn
              :loading="alarmBusy"
              color="success"
              variant="flat"
              @click="circuitAction('clear')"
            >
              Clear
            </VBtn>
          </template>
          <VSpacer />
          <VBtn @click="isAlertDetailOpen = false">
            Close
          </VBtn>
          <VBtn
            v-if="selectedAlert.device_id || selectedAlert.circuit_id"
            color="primary"
            variant="flat"
            @click="goToAlertResource(selectedAlert)"
          >
            {{ selectedAlert.circuit_id ? 'Go to circuit' : 'Go to device' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Full alerts list dialog -->
    <VDialog
      v-model="isAlertsListOpen"
      max-width="640"
    >
      <VCard :title="alertsFilterLabel[alertsFilter]">
        <template #append>
          <VChip
            v-if="alertsFilter !== 'all'"
            size="small"
            variant="tonal"
            @click="alertsFilter = 'all'"
          >
            Show all
          </VChip>
        </template>
        <VCardText>
          <!-- Bulk clear. Shown only when there is something an analyst may act on;
               a viewer never sees a control they cannot use. -->
          <div
            v-if="canBulkClear"
            class="d-flex align-center ga-3 mb-3 pb-3 bulk-bar"
          >
            <VCheckboxBtn
              :model-value="allSelected"
              :indeterminate="selectedAlarms.length > 0 && !allSelected"
              density="compact"
              @update:model-value="toggleAll"
            />
            <span class="text-caption text-medium-emphasis">
              {{ selectedAlarms.length
                ? `${selectedAlarms.length} selected`
                : `Select alarms to clear (${clearableAlerts.length} clearable)` }}
            </span>
            <VSpacer />
            <VBtn
              size="small"
              variant="tonal"
              color="warning"
              prepend-icon="ri-check-double-line"
              :disabled="selectedAlarms.length === 0"
              @click="isBulkClearOpen = true"
            >
              Clear selected
            </VBtn>
          </div>

          <div
            v-if="filteredAlerts.length === 0"
            class="text-center text-medium-emphasis py-6"
          >
            No active alerts.
          </div>
          <div
            v-for="alert in filteredAlerts"
            :key="alert.key"
            class="alert-row"
            :class="{ 'alert-row--selected': alert.alarm_ref && selectedAlarms.includes(alert.alarm_ref) }"
          >
            <div class="d-flex align-center justify-space-between ga-2">
              <div class="d-flex align-center ga-2 flex-grow-1 min-w-0">
                <!-- Reserve the checkbox column for EVERY row when bulk-clear is
                     available, so non-clearable alerts (tunnels, SSH-sourced
                     circuits) keep the same left edge instead of shifting left.
                     Non-clearable rows get an invisible, disabled placeholder. -->
                <VCheckboxBtn
                  v-if="canBulkClear"
                  :model-value="alert.alarm_ref ? selectedAlarms.includes(alert.alarm_ref) : false"
                  :disabled="!alert.alarm_ref"
                  density="compact"
                  hide-details
                  :style="{ flex: '0 0 auto', inlineSize: '32px', ...(alert.alarm_ref ? {} : { visibility: 'hidden' }) }"
                  @update:model-value="alert.alarm_ref ? toggleAlarm(alert.alarm_ref as number) : undefined"
                  @click.stop
                />
                <span
                  v-if="alert.site_name"
                  class="alert-site"
                >{{ alert.site_name }}</span>
                <span
                  class="font-weight-medium text-truncate cursor-pointer"
                  role="button"
                  tabindex="0"
                  @click="openAlert(alert)"
                  @keydown.enter="openAlert(alert)"
                >{{ alert.title }}</span>
              </div>
              <span class="text-caption text-medium-emphasis flex-shrink-0">{{ since(alert.started_at) }}</span>
            </div>
            <div class="d-flex align-center ga-2">
              <VChip
                size="x-small"
                :color="severityColor[alert.severity] ?? 'warning'"
                variant="tonal"
              >
                {{ alertTypeMeta[alert.type].label }}
              </VChip>
              <span class="text-caption text-medium-emphasis text-truncate">{{ alert.subtitle }} — {{ alert.detail }}</span>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Bulk-clear confirmation. States the count plainly and takes a note, because
         a clear is a NOC decision that someone may have to account for later. -->
    <VDialog
      v-model="isBulkClearOpen"
      max-width="480"
    >
      <VCard title="Clear selected alarms">
        <VCardText>
          <p class="mb-4">
            Clearing <strong>{{ selectedAlarms.length }}</strong>
            {{ selectedAlarms.length === 1 ? 'alarm' : 'alarms' }}.
            An alarm the appliance is still reporting stays cleared until it genuinely
            flaps, so this will not simply reappear on the next poll.
          </p>
          <VTextarea
            v-model="bulkNote"
            label="Clear note (optional)"
            placeholder="e.g. Orchestrator latency event — not traffic affecting"
            rows="2"
            auto-grow
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="bulkBusy"
            @click="isBulkClearOpen = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="warning"
            variant="flat"
            :loading="bulkBusy"
            @click="submitBulkClear"
          >
            Clear {{ selectedAlarms.length }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.bulk-bar { border-block-end: 1px solid rgba(var(--v-theme-on-surface), 0.12); }
.alert-row--selected { background: rgba(var(--v-theme-warning), 0.08); }
.min-w-0 { min-inline-size: 0; }

/* KPI cards: a soft accent shadow at the bottom edge — primary normally, red
   when the metric is service-affecting. */
.kpi-strip {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
@media (min-width: 600px) { .kpi-strip { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
/* One row from here up — the columns share whatever width is going, so adding a
   metric narrows the cards instead of wrapping them. */
@media (min-width: 960px) {
  .kpi-strip {
    grid-template-columns: none;
    grid-auto-flow: column;
    grid-auto-columns: minmax(0, 1fr);
  }
}

.kpi-body { padding: 12px 14px; }
.kpi-label {
  font-size: 0.72rem; line-height: 1.2; letter-spacing: .02em;
  /* Cards get narrow at seven across; keep the label on one line. */
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kpi-value { font-size: 1.5rem; font-weight: 500; line-height: 1.25; margin-block-start: 2px; }
@media (min-width: 960px) and (max-width: 1279px) {
  .kpi-body { padding: 10px 12px; }
  .kpi-value { font-size: 1.3rem; }
}

.kpi-card {
  box-shadow: 0 6px 16px -10px rgba(var(--v-theme-primary), 0.65);
  transition: box-shadow .15s ease, transform .15s ease;
}
.kpi-card.is-danger { box-shadow: 0 6px 16px -9px rgba(var(--v-theme-error), 0.8); }
.kpi-card.cursor-pointer:hover { transform: translateY(-2px); }

/* All-clear hero — a big, reassuring green shield when nothing is firing. */
.all-clear {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 40px 16px; gap: 6px;
}
.all-clear-shield {
  color: rgb(var(--v-theme-success));
  filter: drop-shadow(0 6px 20px rgba(var(--v-theme-success), 0.4));
  line-height: 0; margin-bottom: 6px;
}
.all-clear-title { font-size: 1.75rem; font-weight: 700; letter-spacing: .5px; color: rgb(var(--v-theme-success)); }
.all-clear-sub { font-size: .9rem; color: rgba(var(--v-theme-on-surface), 0.6); }

.dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.alert-row {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 9px 8px;
  margin: 0 -8px;
  border-radius: 6px;
  cursor: pointer;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.alert-row:last-of-type {
  border-bottom: none;
}
.alert-row:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.alert-row:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

.contract-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 9px 8px;
  margin: 0 -8px;
  border-radius: 6px;
  color: inherit;
  text-decoration: none;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.contract-row:last-of-type { border-bottom: none; }
.contract-row:hover { background: rgba(var(--v-theme-on-surface), 0.04); }

.cursor-pointer {
  cursor: pointer;
}

/* Active-alerts breakdown — flat labeled bars, one row per resource type. */
.breakdown-row {
  display: grid;
  grid-template-columns: 84px 1fr 28px;
  align-items: center;
  gap: 10px;
  padding: 4px 6px;
  margin: 0 -6px;
  border-radius: 6px;
}
.breakdown-row.is-clickable {
  cursor: pointer;
}
.breakdown-row.is-clickable:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.breakdown-row:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.breakdown-track {
  height: 8px;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  overflow: hidden;
}
.breakdown-fill {
  display: block;
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s ease;
}
.breakdown-value {
  text-align: right;
}

/* NOC runbook — compact numbered steps in the alert detail dialog. */
.runbook {
  margin: 0;
  padding-left: 18px;
}
.runbook li {
  margin-bottom: 2px;
}

.availability-table :deep(tbody tr) {
  cursor: pointer;
}

/* Alarm detail fields: two columns that wrap, so a long vendor Event ID never
   overflows the dialog or collides with the neighbouring field. */
.alert-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 24px;
}
.alert-detail-wide {
  grid-column: 1 / -1;
}
.text-break {
  overflow-wrap: anywhere;
  word-break: break-word;
}

/* The location an alert belongs to, read before the alert itself. Muted so it
   frames the title rather than competing with it. */
.alert-site {
  flex-shrink: 0;
  font-family: ui-monospace, Menlo, monospace;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .01em;
  padding: 1px 6px;
  border-radius: 4px;
  color: rgba(var(--v-theme-on-surface), .75);
  background: rgba(var(--v-theme-on-surface), .08);
}

/* ---- alert detail: one shell for every alert type ---- */
.alert-head {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;
  inline-size: 100%;
}
.alert-head__meta {
  display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
  font-size: 11.5px; letter-spacing: .04em; text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), .6);
}
.alert-head__dot { opacity: .45; }
.alert-head__sev { font-weight: 700; }
.alert-head__sev.is-critical { color: rgb(var(--v-theme-error)); }
.alert-head__sev.is-warning { color: rgb(var(--v-theme-warning)); }
.alert-head__title {
  margin-block-start: 2px;
  font-size: 1.15rem; font-weight: 600; line-height: 1.3;
  overflow-wrap: anywhere;
}

/* Section headings: quiet labels, not competing chrome. */
.alert-sec {
  margin-block-end: 6px;
  font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), .55);
}
.alert-lede { margin: 0; font-size: .95rem; line-height: 1.45; }

.alert-signals {
  margin: 8px 0 0; padding: 0; list-style: none;
  display: flex; flex-direction: column; gap: 2px;
}
.alert-signals li {
  position: relative; padding-inline-start: 14px;
  font-size: .85rem; color: rgba(var(--v-theme-on-surface), .72);
}
.alert-signals li::before {
  content: ''; position: absolute; inset-block-start: .55em; inset-inline-start: 0;
  inline-size: 5px; block-size: 5px; border-radius: 50%;
  background: rgba(var(--v-theme-on-surface), .35);
}

/* The reasoning, one click away instead of in the way. */
.alert-why {
  display: inline-flex; align-items: center; gap: 2px;
  margin-block-start: 10px; padding: 0; border: 0; background: none;
  font: inherit; font-size: .8rem; font-weight: 600; cursor: pointer;
  color: rgb(var(--v-theme-primary));
}
.alert-why-body {
  margin: 6px 0 0; font-size: .85rem; line-height: 1.5;
  color: rgba(var(--v-theme-on-surface), .72);
}

.alert-facts {
  display: grid; grid-template-columns: auto 1fr; gap: 4px 14px;
  margin: 14px 0 0;
}
.alert-facts dt {
  font-size: 11px; letter-spacing: .04em; text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), .5);
}
.alert-facts dd { margin: 0; font-size: .875rem; }
.alert-facts dd.mono { font-family: ui-monospace, Menlo, monospace; }
</style>

<style scoped lang="scss">
// ---- redesign: health hero + right rail ----
.hero-strip { display: grid; grid-template-columns: 1.1fr 1.3fr 1.3fr; gap: 16px; }
@media (max-width: 960px) { .hero-strip { grid-template-columns: 1fr; } }
.hero-card { padding: 18px 20px; }
.hero-title { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: rgba(var(--v-theme-on-surface), .5); font-weight: 600; margin-bottom: 12px; }
.hero-big { font-size: 30px; font-weight: 750; letter-spacing: -.02em; line-height: 1.05; font-variant-numeric: tabular-nums;
  small { font-size: 14px; color: rgba(var(--v-theme-on-surface), .55); font-weight: 600; } }
.hero-sub { font-size: 12.5px; color: rgba(var(--v-theme-on-surface), .55); margin-top: 2px; }
.hero-mttr { font-family: ui-monospace, Menlo, monospace; font-size: 13px; color: rgb(var(--v-theme-success)); font-weight: 600; }
.hero-ring { display: flex; align-items: center; gap: 16px;
  .ring-svg { inline-size: 92px; block-size: 92px; flex: none; }
  .ring-bg { stroke: rgba(var(--v-theme-on-surface), .12); }
  .ring-ok { stroke: rgb(var(--v-theme-success)); transition: stroke-dasharray .5s; } }
.hero-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 8px; font-size: 11.5px; color: rgba(var(--v-theme-on-surface), .6);
  .hdot { display: inline-block; inline-size: 8px; block-size: 8px; border-radius: 2px; margin-inline-end: 5px; }
  .hdot.ok { background: rgb(var(--v-theme-success)); } .hdot.crit { background: rgb(var(--v-theme-error)); } .hdot.warn { background: rgb(var(--v-theme-warning)); } }
.sev-split { display: flex; block-size: 26px; border-radius: 7px; overflow: hidden; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  .ss { display: flex; align-items: center; justify-content: center; font-family: ui-monospace, Menlo, monospace; font-size: 11px; font-weight: 700; color: #0b0e14; min-inline-size: 26px; }
  .ss.c { background: rgb(var(--v-theme-error)); } .ss.w { background: rgb(var(--v-theme-warning)); } .ss.i { background: rgb(var(--v-theme-info)); color: #fff; }
  .ss-clear { display: flex; align-items: center; padding-inline: 12px; font-size: 12px; color: rgb(var(--v-theme-success)); font-weight: 600; } }
.hero-spark { inline-size: 100%; block-size: 40px; margin-top: 10px;
  polyline { stroke: rgb(var(--v-theme-primary)); stroke-width: 1.6; } }

.rail-kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.rail-kpi { padding: 13px 14px;
  &__k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: rgba(var(--v-theme-on-surface), .5); font-weight: 600; }
  &__v { font-size: 24px; font-weight: 750; font-variant-numeric: tabular-nums; line-height: 1.1; margin-top: 3px; }
  &.is-danger { border-color: rgba(var(--v-theme-error), .4); } }
.offender-row { display: flex; align-items: center; gap: 11px; padding: 8px 0; text-decoration: none; color: inherit; border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  &:first-of-type { border-top: 0; }
  .offender-rank { font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: rgba(var(--v-theme-on-surface), .4); inline-size: 12px; }
  .offender-name { flex: 1; min-inline-size: 0; font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .offender-bar { inline-size: 54px; block-size: 6px; border-radius: 3px; background: rgba(var(--v-theme-on-surface), .1); overflow: hidden; flex: none;
    i { display: block; block-size: 100%; background: rgb(var(--v-theme-error)); } }
  .offender-n { font-family: ui-monospace, Menlo, monospace; font-size: 12px; color: rgba(var(--v-theme-on-surface), .6); inline-size: 22px; text-align: end; }
  &:hover .offender-name { color: rgb(var(--v-theme-primary)); } }
</style>
