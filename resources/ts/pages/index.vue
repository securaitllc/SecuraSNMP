<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAlertsStore } from '@/stores/alerts'
import { useAuthStore } from '@/stores/auth'
import type { AlertAction, DashboardAlert, DashboardSummary, SearchResult } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()
const router = useRouter()

// The dashboard reads the SHARED alerts store — the SAME single poll the header bell
// and tab title use — so the counts can never disagree (they did: bell 6 vs page 1)
// and they refresh together when an alarm auto-clears or a new one fires.
const store = useAlertsStore()
const data = computed<DashboardSummary | null>(() => store.loaded
  ? { sites: store.sites, availability: store.availability, traffic: store.traffic!, alerts: store.alerts, contracts_expiring: store.contracts, counts: store.counts! }
  : null)
const refreshing = ref(false)
const isLoading = computed(() => refreshing.value || !store.loaded)
const loadError = computed(() => (store.error && !store.loaded) ? 'Could not load the dashboard. Try refreshing.' : '')

async function loadDashboard() {
  refreshing.value = true
  try { await store.refresh() }
  finally { refreshing.value = false }
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

// The shared store owns the dashboard poll (started by the header bell, but ensure it
// here too for a direct dashboard mount). Only the insights feed is polled locally.
let insightsTimer: ReturnType<typeof setInterval> | null = null

function loadRail() {
  loadInsights()
  loadAnomalies()
  loadTalkers()
}
onMounted(() => {
  store.startPolling()
  loadRail()
  insightsTimer = setInterval(loadRail, 30000)
})

onBeforeUnmount(() => {
  if (insightsTimer)
    clearInterval(insightsTimer)
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
// Defaults to By-ISP grouping — that's the operator's primary triage view.
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

  const facts: { label: string, value: string, mono?: boolean, copy?: boolean }[] = []

  if (a.device_name && a.type !== 'incident')
    facts.push({ label: 'Device', value: a.device_name })
  if (a.device_ip)
    facts.push({ label: 'Device IP', value: a.device_ip, mono: true })
  if (a.ticket_number)
    facts.push({ label: a.type === 'circuit' ? 'ISP ticket' : 'Ticket', value: `#${a.ticket_number}`, mono: true, copy: true })
  if (a.acknowledged_at)
    facts.push({ label: 'Acknowledged', value: `${a.acknowledged_by ?? 'yes'} · ${since(a.acknowledged_at)}` })
  if (a.event_id)
    facts.push({ label: 'Event ID', value: a.event_id, mono: true })

  return facts
})

// Readout gauge for the alarm console: loss % drives the ring; no loss metric → a
// solid severity ring (state, not a fake number).
const alertLoss = computed(() => {
  const v = selectedAlert.value?.loss_pct
  return typeof v === 'number' ? Math.min(100, Math.max(0, v)) : null
})
const RING_C = 339 // 2πr for r=54
const lossOffset = computed(() => RING_C * (1 - (alertLoss.value ?? 0) / 100))
const readoutColor = computed(() => `rgb(var(--v-theme-${severityColor[selectedAlert.value?.severity ?? 'warning'] ?? 'warning'}))`)
const stateLabel = computed(() => {
  const a = selectedAlert.value
  if (!a)
    return ''
  if ((a.type === 'circuit' || a.type === 'incident') && a.severity === 'critical')
    return 'DOWN'
  return (a.severity ?? '').toUpperCase()
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

// Click a device/interface alarm to jump straight to where you troubleshoot it: an
// interface alarm opens that exact port on the device page; any other device-backed
// alarm opens the device. Circuit / multi-device alarms (no single device) keep the
// detail dialog.
function troubleshootAlert(alert: DashboardAlert) {
  // A single-port alarm (standalone OR the one interface inside an incident) opens that
  // exact port; any other device-backed alarm opens the device.
  if (alert.device_id && alert.if_name) {
    router.push(`/devices/${alert.device_id}?interface=${encodeURIComponent(alert.if_name)}`)
    return
  }
  if (alert.device_id) {
    router.push(`/devices/${alert.device_id}`)
    return
  }
  openAlert(alert)
}

// In-place ack/clear/mute on a flat alarm row — same actions as the By-ISP view.
const busyAlertKey = ref<string | null>(null)
async function runFlatAction(alert: DashboardAlert, act?: AlertAction | null) {
  if (!act)
    return
  busyAlertKey.value = alert.key
  try {
    await api(act.url, { method: 'POST', body: act.body ?? {} })
    await loadDashboard()
  }
  finally {
    busyAlertKey.value = null
  }
}
function muteFlatAlert(alert: DashboardAlert) {
  const act = alert.actions?.mute
  // eslint-disable-next-line no-alert
  if (act && confirm(`${act.label ?? 'Mute'} — silence this alarm?\n${alert.title}`))
    runFlatAction(alert, act)
}

// Per-alert-type leading icon for the modernized rows.
const typeIcon: Record<string, string> = {
  circuit: 'ri-link', tunnel: 'ri-shield-flash-line', 'tunnel-quality': 'ri-pulse-line',
  interface: 'ri-plug-line', next_hop: 'ri-arrow-right-up-line', alarm: 'ri-alarm-warning-line', incident: 'ri-error-warning-line',
}
function alertIcon(a: DashboardAlert): string { return typeIcon[a.type] ?? 'ri-alarm-warning-line' }

// Inline ISP dispatch ticket on circuit / SD-WAN transport alarms.
const ispEditKey = ref<string | null>(null)
const ispDraft = ref('')
function startIsp(alert: DashboardAlert) { ispEditKey.value = alert.key; ispDraft.value = alert.isp_ticket ?? '' }
async function saveIsp(alert: DashboardAlert) {
  if (!alert.isp_ticket_url) return
  busyAlertKey.value = alert.key
  try {
    await api(alert.isp_ticket_url, { method: 'POST', body: { isp_ticket: ispDraft.value.trim() || null } })
    ispEditKey.value = null
    await loadDashboard()
  }
  finally { busyAlertKey.value = null }
}

// Inline ISP field-dispatch ETA (+ note) on circuit / SD-WAN transport alarms.
const dispatchEditKey = ref<string | null>(null)
const dispatchAtDraft = ref('')
const dispatchNoteDraft = ref('')
function toLocalInput(iso: string): string {
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}
function dispatchChip(iso: string): string {
  return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
function startDispatch(alert: DashboardAlert) {
  dispatchEditKey.value = alert.key
  dispatchAtDraft.value = alert.dispatch_at ? toLocalInput(alert.dispatch_at) : ''
  dispatchNoteDraft.value = alert.dispatch_note ?? ''
}
async function saveDispatch(alert: DashboardAlert) {
  if (!alert.dispatch_url) return
  busyAlertKey.value = alert.key
  try {
    await api(alert.dispatch_url, { method: 'POST', body: {
      // datetime-local is a naive local wall-time; send a real instant so it round-trips.
      dispatch_at: dispatchAtDraft.value ? new Date(dispatchAtDraft.value).toISOString() : null,
      dispatch_note: dispatchNoteDraft.value.trim() || null,
    } })
    dispatchEditKey.value = null
    await loadDashboard()
  }
  finally { busyAlertKey.value = null }
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
interface Insights { trend_24h: number[]; raised_24h: number; resolved_24h: number; mttr_minutes: number | null; top_offenders: { site_id: number; site_name: string; count: number }[]; anomalies_open: number }
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

// ---- right rail: anomalies + top talkers ----
interface AnomalyRow { id: number; metric: string; site_name: string | null; entity?: string; sub?: string; z_score: number; series?: (number | null)[]; route?: string }
interface Talker { src_ip: string; dst_ip: string; bytes: number; flows: number; app?: string | null }
const anomalies = ref<AnomalyRow[]>([])
const topTalkers = ref<Talker[]>([])
const talkerMax = computed(() => Math.max(0, ...topTalkers.value.map(t => t.bytes)))
async function loadAnomalies() {
  try { anomalies.value = (await api<{ data: AnomalyRow[] }>('/api/anomalies')).data ?? [] }
  catch { /* non-fatal */ }
}
async function loadTalkers() {
  try { topTalkers.value = (await api<{ talkers: Talker[] }>('/api/flows/overview?hours=6')).talkers ?? [] }
  catch { /* non-fatal */ }
}

/** Robust-z severity colour: red past 5σ, amber past 3σ. */
function zColor(z: number): string {
  return z >= 5 ? 'rgb(var(--v-theme-error))' : z >= 3 ? 'rgb(var(--v-theme-warning))' : 'rgb(var(--v-theme-info))'
}
/** Bytes → compact human string (1.2 GB). */
function fmtBytes(n: number): string {
  if (!n) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; let v = n
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v < 10 && i > 0 ? 1 : 0)} ${u[i]}`
}

// Right-rail KPI chips (danger-flagged live outage counts), reusing the KPI actions.
</script>

<template>
  <div>
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

    <!-- Row 1: the network map (Sites Health) beside Top Talkers -->
    <VRow class="mb-2">
      <VCol cols="12" lg="8">
        <VCard>
          <VCardItem>
            <VCardTitle>Sites Health</VCardTitle>
            <template #append>
              <VChip size="small" color="success" variant="tonal">Active now</VChip>
            </template>
          </VCardItem>
          <VCardText>
            <SiteMap
              :sites="data?.sites ?? []"
              :alerts="data?.alerts ?? []"
              :height="440"
              @open-alert="openAlert"
            />
          </VCardText>
        </VCard>
      </VCol>

      <!-- Top Talkers — heaviest src→dst conversations from the flow collectors -->
      <VCol cols="12" lg="4">
        <VCard>
          <VCardItem>
            <VCardTitle class="text-body-1 d-flex align-center ga-2">
              <VIcon icon="ri-bar-chart-grouped-line" size="18" class="text-info" />Top Talkers
            </VCardTitle>
            <VCardSubtitle>Heaviest conversations · last 6h</VCardSubtitle>
            <template #append>
              <RouterLink to="/flows" class="rail-link">View all</RouterLink>
            </template>
          </VCardItem>
          <VCardText class="pt-0">
            <div v-if="!topTalkers.length" class="text-medium-emphasis text-body-2 py-2">No flow data in the window.</div>
            <RouterLink
              v-for="(t, i) in topTalkers.slice(0, 6)"
              :key="i"
              to="/flows"
              class="tk-row"
            >
              <div class="tk-top">
                <span class="tk-conv mono">{{ t.src_ip }} <VIcon icon="ri-arrow-right-line" size="13" class="text-disabled" /> {{ t.dst_ip }}</span>
                <span class="tk-bytes mono">{{ fmtBytes(t.bytes) }}</span>
              </div>
              <div class="tk-meta">
                <VChip size="x-small" variant="tonal" :color="(t.app && t.app !== 'Unclassified') ? 'info' : undefined">{{ t.app ?? 'Unclassified' }}</VChip>
                <span class="tk-flows">{{ t.flows.toLocaleString() }} flows</span>
                <span class="tk-track"><span class="tk-fill" :style="{ width: `${talkerMax ? Math.max(4, Math.round((t.bytes / talkerMax) * 100)) : 0}%` }" /></span>
              </div>
            </RouterLink>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

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
                  <button :class="{ on: alarmView === 'flat' }" @click="alarmView = 'flat'">
                    <VIcon icon="ri-list-check" />Flat
                  </button>
                  <button :class="{ on: alarmView === 'grouped' }" @click="alarmView = 'grouped'">
                    <VIcon icon="ri-router-line" />By ISP
                  </button>
                </div>
                <div v-if="(data?.counts.active_alerts ?? 0) > 0" class="d-flex ga-2">
                  <VChip v-if="severitySplit.critical" size="small" color="error" variant="tonal" prepend-icon="ri-circle-fill">{{ severitySplit.critical }} Critical</VChip>
                  <VChip v-if="severitySplit.warning" size="small" color="warning" variant="tonal" prepend-icon="ri-circle-fill">{{ severitySplit.warning }} Warning</VChip>
                </div>
                <VChip v-else size="small" color="success" variant="tonal">All clear</VChip>
              </div>
            </template>
          </VCardItem>

          <!-- Grouped-per-ISP view (default): device alarms, circuit outages and
               interface alerts folded per provider, one ISP ticket/dispatch each. -->
          <VCardText v-if="alarmView === 'grouped'">
            <AlarmGroups />
          </VCardText>

          <VCardText v-else class="pt-1">
            <div
              v-if="!isLoading && (data?.alerts.length ?? 0) === 0"
              class="all-clear"
            >
              <div class="all-clear-shield">
                <VIcon icon="ri-shield-check-fill" size="72" />
              </div>
              <div class="all-clear-title">All Clear</div>
              <div class="all-clear-sub">
                No active alerts across {{ data?.counts.sites ?? 0 }} sites · {{ data?.counts.devices ?? 0 }} devices
              </div>
            </div>

            <div
              v-for="alert in (data?.alerts ?? []).slice(0, 6)"
              :key="alert.key"
              class="al-row"
              :class="`sev-${alert.severity}`"
              role="button"
              tabindex="0"
              @click="troubleshootAlert(alert)"
              @keydown.enter="troubleshootAlert(alert)"
            >
              <span class="al-stripe" />
              <span class="al-ic"><VIcon :icon="alertIcon(alert)" size="17" /></span>
              <div class="al-body">
                <div class="al-l1">
                  <span v-if="alert.site_name" class="al-site">{{ alert.site_name }}</span>
                  <span class="al-ttl">{{ alert.title }}</span>
                </div>
                <div class="al-l2">
                  <span class="al-sub">{{ alert.subtitle }}</span>
                  <template v-if="alert.type === 'interface' && (alert.last_neighbor || alert.last_mac)">
                    <span class="al-k">· was</span><span class="al-m mono">{{ alert.last_neighbor ?? alert.last_mac }}</span>
                  </template>
                  <template v-else-if="alert.isp_name">
                    <span class="al-k">·</span><span class="al-m">{{ alert.isp_name }}</span>
                    <span v-if="alert.circuit_code" class="al-m mono">{{ alert.circuit_code }}</span>
                  </template>
                </div>
              </div>
              <div class="al-aside" @click.stop>
                <div v-if="auth.canAct && alert.actions" class="al-acts">
                  <VBtn
                    v-if="alert.actions.ack && !alert.acknowledged_at"
                    size="x-small" variant="tonal" density="comfortable"
                    :loading="busyAlertKey === alert.key"
                    @click="runFlatAction(alert, alert.actions.ack)"
                  >Ack</VBtn>
                  <VBtn
                    v-if="alert.actions.clear"
                    size="x-small" variant="tonal" color="secondary" density="comfortable"
                    :loading="busyAlertKey === alert.key"
                    @click="runFlatAction(alert, alert.actions.clear)"
                  >Clear</VBtn>
                  <VBtn
                    v-if="alert.actions.mute && auth.isAdmin"
                    size="x-small" variant="tonal" color="warning" density="comfortable"
                    @click="muteFlatAlert(alert)"
                  >{{ alert.actions.mute.label ?? 'Mute' }}</VBtn>
                </div>
                <div class="al-meta">
                  <span class="al-age">{{ since(alert.started_at) }}</span>
                  <!-- ISP dispatch ticket for circuit / SD-WAN transport: add → input → chip -->
                  <div v-if="ispEditKey === alert.key" class="al-isp-edit">
                    <input
                      v-model="ispDraft" class="mono" type="text" placeholder="ISP ticket #…"
                      @keydown.enter="saveIsp(alert)" @keydown.esc="ispEditKey = null"
                    >
                    <button class="al-isp-ok" :disabled="busyAlertKey === alert.key" @click="saveIsp(alert)"><VIcon icon="ri-check-line" size="13" /></button>
                  </div>
                  <span
                    v-else-if="alert.isp_ticket"
                    class="al-isp"
                    :class="{ editable: auth.canAct }"
                    @click="auth.canAct && startIsp(alert)"
                  ><VIcon icon="ri-ticket-2-line" size="11" />ISP {{ alert.isp_ticket }}</span>
                  <button
                    v-else-if="alert.isp_ticket_url && auth.canAct"
                    class="al-isp-add"
                    @click="startIsp(alert)"
                  ><VIcon icon="ri-add-line" size="12" />ISP ticket</button>
                  <span v-else-if="alert.ticket_number" class="al-tk mono">#{{ alert.ticket_number }}</span>

                  <!-- ISP field-dispatch ETA (+ note): add → datetime + note → chip -->
                  <div v-if="dispatchEditKey === alert.key" class="al-isp-edit">
                    <input
                      v-model="dispatchAtDraft" class="mono" type="datetime-local"
                      @keydown.esc="dispatchEditKey = null"
                    >
                    <input
                      v-model="dispatchNoteDraft" type="text" placeholder="note…"
                      @keydown.enter="saveDispatch(alert)" @keydown.esc="dispatchEditKey = null"
                    >
                    <button class="al-isp-ok" :disabled="busyAlertKey === alert.key" @click="saveDispatch(alert)"><VIcon icon="ri-check-line" size="13" /></button>
                  </div>
                  <span
                    v-else-if="alert.dispatch_at"
                    class="al-isp"
                    :class="{ editable: auth.canAct }"
                    :title="alert.dispatch_note || 'ISP dispatch ETA'"
                    @click="auth.canAct && startDispatch(alert)"
                  ><VIcon icon="ri-truck-line" size="11" />{{ dispatchChip(alert.dispatch_at) }}</span>
                  <button
                    v-else-if="alert.dispatch_url && auth.canAct"
                    class="al-isp-add"
                    @click="startDispatch(alert)"
                  ><VIcon icon="ri-add-line" size="12" />Dispatch</button>
                </div>
              </div>
            </div>

            <VBtn
              v-if="(data?.alerts.length ?? 0) > 6"
              variant="text"
              size="small"
              class="mt-2"
              append-icon="ri-arrow-right-line"
              @click="openAlertsList('all')"
            >
              View all {{ data?.alerts.length }} alerts
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Anomalies — robust-z metrics off baseline, clickable to the entity -->
      <VCol cols="12" lg="4">
        <VCard>
          <VCardItem>
            <VCardTitle class="text-body-1 d-flex align-center ga-2">
              <VIcon icon="ri-pulse-line" size="18" class="text-warning" />Anomalies
            </VCardTitle>
            <VCardSubtitle>Metrics off baseline · robust-z</VCardSubtitle>
            <template #append>
              <RouterLink to="/anomalies" class="rail-link">View all</RouterLink>
            </template>
          </VCardItem>
          <VCardText class="pt-0">
            <div v-if="!anomalies.length" class="text-medium-emphasis text-body-2 py-2">No anomalies off baseline.</div>
            <RouterLink
              v-for="a in anomalies.slice(0, 8)"
              :key="a.id"
              :to="a.route ?? '/anomalies'"
              class="mini-row"
            >
              <span class="mini-stripe" :style="{ background: zColor(a.z_score) }" />
              <span class="mini-main">
                <span class="mini-title">{{ a.metric }}</span>
                <span class="mini-sub text-truncate">{{ a.site_name ?? a.entity ?? '—' }}<template v-if="a.sub"> · {{ a.sub }}</template></span>
              </span>
              <Sparkline :points="a.series ?? []" :color="zColor(a.z_score)" :width="60" :height="22" class="flex-shrink-0" />
              <span class="mini-z" :style="{ color: zColor(a.z_score) }">+{{ a.z_score.toFixed(1) }}σ</span>
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
          <div class="sc-grid" :class="{ 'sc-has-topo': selectedAlert.site_id }">
            <!-- LANE 1 · live signal readout -->
            <aside class="sc-readout">
              <div class="sc-gauge">
                <svg width="128" height="128" viewBox="0 0 132 132">
                  <circle cx="66" cy="66" r="54" fill="none" stroke="rgba(var(--v-theme-on-surface),.10)" stroke-width="8" />
                  <circle
                    cx="66" cy="66" r="54" fill="none" :stroke="readoutColor" stroke-width="8" stroke-linecap="round"
                    stroke-dasharray="339"
                    :stroke-dashoffset="alertLoss !== null ? lossOffset : 0"
                    transform="rotate(-90 66 66)"
                  />
                  <text v-if="alertLoss !== null" x="66" y="63" text-anchor="middle" :fill="readoutColor" class="sc-gauge-num">{{ alertLoss }}%</text>
                  <text v-if="alertLoss !== null" x="66" y="82" text-anchor="middle" fill="rgb(var(--v-theme-on-surface))" opacity="0.5" class="sc-gauge-lbl">LOSS</text>
                  <text v-else x="66" y="72" text-anchor="middle" :fill="readoutColor" class="sc-gauge-state">{{ stateLabel }}</text>
                </svg>
                <div class="sc-state" :style="{ color: readoutColor }">
                  <span class="sc-dot" :style="{ background: readoutColor }" />{{ stateLabel }}
                </div>
              </div>
              <div class="sc-sig">
                <div class="sc-ey">Live signal</div>
                <div class="sc-kv"><span>Type</span><span>{{ alertTypeMeta[selectedAlert.type].label }}</span></div>
                <div v-if="alertLoss !== null" class="sc-kv"><span>Packet loss</span><span class="mono" :style="{ color: readoutColor }">{{ alertLoss }}%</span></div>
                <div class="sc-kv"><span>Down since</span><span class="mono">{{ since(selectedAlert.started_at) }}</span></div>
                <div v-if="selectedAlert.transport_reason" class="sc-kv"><span>Reason</span><span class="text-end">{{ selectedAlert.transport_reason }}</span></div>
              </div>
            </aside>

            <!-- LANE 2 · diagnosis, signals, runbook -->
            <div class="sc-main d-flex flex-column ga-4">
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
                      <CopyBtn
                        v-if="f.copy"
                        :text="f.value.replace('#', '')"
                        :label="f.value"
                      />
                      <template v-else>
                        {{ f.value }}
                      </template>
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
            </div>

            <!-- LANE 3 · live topology of the affected site -->
            <aside
              v-if="selectedAlert.site_id"
              class="sc-side"
            >
              <h4 class="alert-sec">
                Topology
              </h4>
              <TopologyStrip
                :key="selectedAlert.site_id"
                :site-id="selectedAlert.site_id"
                :show-diagnosis="false"
                @loaded="siteIncident = $event"
              />
            </aside>
          </div>
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
      max-width="800"
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
            class="aa-row"
            :class="[`sev-${alert.severity}`, { 'aa-row--selected': alert.alarm_ref && selectedAlarms.includes(alert.alarm_ref) }]"
          >
            <span class="aa-stripe" />
            <!-- Reserve the checkbox column for EVERY row when bulk-clear is available so
                 non-clearable alerts keep the same left edge; theirs is an invisible placeholder. -->
            <VCheckboxBtn
              v-if="canBulkClear"
              class="aa-check"
              :model-value="alert.alarm_ref ? selectedAlarms.includes(alert.alarm_ref) : false"
              :disabled="!alert.alarm_ref"
              density="compact"
              hide-details
              :style="alert.alarm_ref ? {} : { visibility: 'hidden' }"
              @update:model-value="alert.alarm_ref ? toggleAlarm(alert.alarm_ref as number) : undefined"
              @click.stop
            />
            <span class="aa-ic" :class="`ic-${alert.severity}`"><VIcon :icon="alertIcon(alert)" size="14" /></span>
            <div class="aa-body">
              <div class="aa-titleline">
                <span v-if="alert.site_name" class="aa-site">{{ alert.site_name }}</span>
                <span
                  class="aa-title"
                  role="button"
                  tabindex="0"
                  @click="openAlert(alert)"
                  @keydown.enter="openAlert(alert)"
                >{{ alert.title }}</span>
              </div>
              <div class="aa-meta">
                <VChip size="x-small" :color="severityColor[alert.severity] ?? 'warning'" variant="tonal">
                  {{ alertTypeMeta[alert.type].label }}
                </VChip>
                <span class="aa-detail">{{ alert.subtitle }}<template v-if="alert.detail"> — {{ alert.detail }}</template></span>
                <span v-if="alert.isp_ticket" class="aa-chip aa-chip--isp"><VIcon icon="ri-ticket-2-line" size="11" />ISP {{ alert.isp_ticket }}</span>
                <span v-if="alert.dispatch_at" class="aa-chip aa-chip--disp"><VIcon icon="ri-truck-line" size="11" />{{ dispatchChip(alert.dispatch_at) }}</span>
              </div>
            </div>
            <span class="aa-age">{{ since(alert.started_at) }}</span>
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

/* "All active alerts" rows — full names + reason WRAP (never truncated), severity
   stripe + type icon, ISP/dispatch chips. */
.aa-row {
  display: flex;
  gap: 12px;
  padding: 14px 4px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.aa-row--selected { background: rgba(var(--v-theme-warning), 0.08); }
.aa-stripe {
  flex: 0 0 3px;
  align-self: stretch;
  border-radius: 2px;
  background: rgba(var(--v-theme-on-surface), 0.18);
}
.aa-row.sev-critical .aa-stripe { background: rgb(var(--v-theme-error)); }
.aa-row.sev-warning .aa-stripe { background: rgb(var(--v-theme-warning)); }
.aa-check { flex: 0 0 auto; align-self: flex-start; }
.aa-ic {
  flex: 0 0 20px;
  inline-size: 20px;
  block-size: 20px;
  margin-block-start: 1px;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: rgba(var(--v-theme-on-surface), 0.6);
  background: rgba(var(--v-theme-on-surface), 0.08);
}
.aa-ic.ic-critical { color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), 0.14); }
.aa-ic.ic-warning { color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), 0.14); }
.aa-ic.ic-info { color: rgb(var(--v-theme-info)); background: rgba(var(--v-theme-info), 0.14); }
.aa-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.aa-titleline { display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.aa-site {
  font-size: 10.5px;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.6);
  background: rgba(var(--v-theme-on-surface), 0.05);
  padding: 2px 7px;
  border-radius: 5px;
}
.aa-title { font-weight: 600; font-size: 13.5px; text-wrap: balance; cursor: pointer; overflow-wrap: anywhere; }
.aa-title:hover { color: rgb(var(--v-theme-primary)); }
.aa-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.aa-detail { font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.55); overflow-wrap: anywhere; }
.aa-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; padding: 2px 8px; border-radius: 12px; white-space: nowrap; }
.aa-chip--isp { color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.10); }
.aa-chip--disp { color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), 0.10); }
.aa-age { flex: 0 0 auto; font-size: 11.5px; color: rgba(var(--v-theme-on-surface), 0.6); margin-block-start: 1px; }

/* Incident-members list (in the alert detail view) still uses .alert-row. */
.alert-row {
  display: grid;
  grid-template-columns: 3px 1fr auto;
  align-items: center;
  gap: 12px;
  padding: 10px 8px 10px 0;
  margin: 0 -8px;
  border-radius: 6px;
  cursor: pointer;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  transition: background 0.12s;
}
.alert-row::before {
  content: "";
  grid-column: 1;
  align-self: stretch;
  border-radius: 3px;
  background: rgba(var(--v-theme-on-surface), 0.18);
}
.alert-row.sev-critical::before { background: rgb(var(--v-theme-error)); }
.alert-row.sev-warning::before { background: rgb(var(--v-theme-warning)); }
.alert-row--selected { background: rgba(var(--v-theme-warning), 0.08); }

/* Modernized Active Alarms (Flat) rows */
.al-row { display: grid; grid-template-columns: 3px 34px 1fr auto; align-items: center; gap: 13px;
  padding: 12px 10px 12px 6px; margin: 0 -6px; border-radius: 10px; cursor: pointer; transition: background .12s; }
.al-row + .al-row { box-shadow: 0 -1px 0 rgba(var(--v-theme-on-surface), .08); }
.al-row:hover { background: rgba(var(--v-theme-on-surface), .045); }
.al-row:hover + .al-row { box-shadow: none; }
.al-row:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.al-stripe { grid-column: 1; align-self: stretch; border-radius: 3px; background: rgba(var(--v-theme-on-surface), .2); }
.al-row.sev-critical .al-stripe { background: rgb(var(--v-theme-error)); }
.al-row.sev-warning .al-stripe { background: rgb(var(--v-theme-warning)); }
.al-ic { grid-column: 2; width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; color: rgba(var(--v-theme-on-surface), .5); background: rgba(var(--v-theme-on-surface), .06); }
.al-row.sev-critical .al-ic { color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), .12); }
.al-row.sev-warning .al-ic { color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), .12); }
.al-body { grid-column: 3; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.al-l1 { display: flex; align-items: center; gap: 9px; min-width: 0; }
.al-site { flex: none; font-size: 11px; font-weight: 600; letter-spacing: .02em; color: rgba(var(--v-theme-on-surface), .62);
  background: rgba(var(--v-theme-on-surface), .07); padding: 2px 7px; border-radius: 5px; white-space: nowrap; }
.al-ttl { font-size: 14px; font-weight: 600; letter-spacing: -.005em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.al-l2 { display: flex; align-items: center; gap: 8px; font-size: 12px; color: rgba(var(--v-theme-on-surface), .56); min-width: 0; }
.al-l2 .al-k { color: rgba(var(--v-theme-on-surface), .36); }
.al-l2 .al-m { color: rgba(var(--v-theme-on-surface), .85); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.al-aside { grid-column: 4; display: flex; align-items: flex-end; gap: 14px; justify-self: end; }
.al-acts { display: flex; gap: 5px; opacity: 0; transition: opacity .12s; }
.al-row:hover .al-acts { opacity: 1; }
.al-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; white-space: nowrap; }
.al-age { font-size: 12.5px; color: rgba(var(--v-theme-on-surface), .6); }
.al-tk { font-size: 11px; color: rgba(var(--v-theme-on-surface), .4); }
.al-isp { display: inline-flex; align-items: center; gap: 5px; font-family: ui-monospace, Menlo, monospace; font-size: 11px;
  color: #ffca55; background: rgba(var(--v-theme-warning), .12); border: 1px solid rgba(var(--v-theme-warning), .32); border-radius: 5px; padding: 2px 8px; }
.al-isp.editable { cursor: pointer; }
.al-isp-add { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 500; color: rgba(var(--v-theme-on-surface), .55);
  background: none; border: 1px dashed rgba(var(--v-theme-on-surface), .22); border-radius: 5px; padding: 3px 9px; cursor: pointer; }
.al-isp-add:hover { color: rgb(var(--v-theme-on-surface)); border-color: rgba(var(--v-theme-warning), .5); }
.al-isp-edit { display: inline-flex; align-items: center; gap: 4px; }
.al-isp-edit input { font-family: ui-monospace, Menlo, monospace; font-size: 11px; color: rgb(var(--v-theme-on-surface));
  background: rgba(var(--v-theme-on-surface), .04); border: 1px solid rgba(var(--v-theme-warning), .5); border-radius: 5px; padding: 3px 7px; width: 120px; outline: none; }
.al-isp-ok { width: 22px; height: 22px; border-radius: 5px; border: 0; background: rgb(var(--v-theme-warning)); color: #241a02; display: grid; place-items: center; cursor: pointer; }
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

/* Signal-console layout for the alarm detail: readout | diagnosis | topology. */
.sc-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
.sc-grid.sc-has-topo { grid-template-columns: 260px minmax(0, 1fr) 360px; }
@media (max-width: 1279px) { .sc-grid.sc-has-topo { grid-template-columns: 1fr; } }
.sc-readout {
  border: 1px solid rgba(var(--v-theme-on-surface), .10);
  border-radius: 12px;
  padding: 20px 18px;
  background:
    radial-gradient(circle at 1px 1px, rgba(var(--v-theme-on-surface), .05) 1px, transparent 0) 0 0/16px 16px,
    rgba(var(--v-theme-on-surface), .02);
  display: flex; flex-direction: column; gap: 18px; align-self: start;
}
.sc-gauge { display: flex; flex-direction: column; align-items: center; gap: 12px; }
.sc-gauge-num { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 30px; font-weight: 600; }
.sc-gauge-lbl { font-size: 10px; letter-spacing: 1.5px; }
.sc-gauge-state { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 22px; font-weight: 700; letter-spacing: 1px; }
.sc-state { display: flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 20px; font-weight: 700; letter-spacing: .06em; font-size: 13px; background: rgba(var(--v-theme-on-surface), .06); }
.sc-dot { width: 8px; height: 8px; border-radius: 50%; }
.sc-ey { font-size: 10px; letter-spacing: .16em; text-transform: uppercase; color: rgba(var(--v-theme-on-surface), .5); margin-bottom: 8px; }
.sc-kv { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; font-size: 12.5px; border-bottom: 1px solid rgba(var(--v-theme-on-surface), .08); }
.sc-kv:last-child { border-bottom: 0; }
.sc-kv > span:first-child { color: rgba(var(--v-theme-on-surface), .55); flex-shrink: 0; }
.sc-kv .mono, .sc-readout .mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; }
.sc-side { align-self: start; }
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

.rail-link { font-size: 12px; color: rgb(var(--v-theme-primary)); text-decoration: none; }
.rail-link:hover { text-decoration: underline; }

/* Compact right-rail rows for Anomalies + Top Talkers — flat, severity-cued. */
.mini-row { display: flex; align-items: center; gap: 10px; padding: 9px 0; text-decoration: none; color: inherit;
  border-top: 1px solid rgba(var(--v-theme-on-surface), .08); }
.mini-row:first-of-type { border-top: 0; }
.mini-row:hover { .mini-title { color: rgb(var(--v-theme-primary)); } }
.mini-stripe { inline-size: 3px; align-self: stretch; border-radius: 3px; flex: none; }
.mini-main { flex: 1; min-inline-size: 0; display: flex; flex-direction: column; gap: 3px; }
.mini-title { font-size: 13px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  display: flex; align-items: center; gap: 3px; }
.mini-sub { font-size: 11.5px; color: rgba(var(--v-theme-on-surface), .55); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  display: flex; align-items: center; gap: 5px; }
.mini-z { font-family: ui-monospace, Menlo, monospace; font-size: 13px; font-weight: 600; flex: none; }
.mini-bytes { font-size: 13px; font-weight: 600; color: rgba(var(--v-theme-on-surface), .8); flex: none; }

/* Top Talkers rows with a byte-share bar */
.tk-row { display: flex; flex-direction: column; gap: 5px; padding: 9px 6px 10px; text-decoration: none; color: inherit; border-top: 1px solid rgba(var(--v-theme-on-surface), .08); }
.tk-row:first-of-type { border-top: 0; }
.tk-row:hover .tk-conv { color: rgb(var(--v-theme-primary)); }
.tk-top { display: flex; align-items: center; gap: 10px; }
.tk-conv { flex: 1; min-width: 0; display: flex; align-items: center; gap: 5px; font-size: 12.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tk-bytes { flex: none; font-size: 13.5px; font-weight: 600; }
.tk-meta { display: flex; align-items: center; gap: 8px; }
.tk-flows { font-size: 11.5px; color: rgba(var(--v-theme-on-surface), .38); white-space: nowrap; }
.tk-track { flex: 1; height: 4px; border-radius: 999px; background: rgba(var(--v-theme-on-surface), .06); overflow: hidden; }
.tk-fill { display: block; height: 100%; border-radius: 999px; background: color-mix(in srgb, rgb(var(--v-theme-info)) 62%, transparent); }
.mini-title.mono { font-family: ui-monospace, Menlo, monospace; font-size: 12px; }
</style>
