<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useChartMode } from '@/composables/useChartTheme'
import { useAuthStore } from '@/stores/auth'
import type { Device, DeviceAlarm, DeviceInterface, DeviceMetric, DeviceVlan, InterfaceAlert, InterfaceMetric, Site, SshCredential, ToolResult } from '@/types/models'
import { easternChartMs, formatDateTime } from '@/utils/datetime'

const auth = useAuthStore()

definePage({
  meta: {
    layout: 'default',
  },
})

const route = useRoute()
const router = useRouter()
const deviceId = computed(() => (route.params as Record<string, string>).id)

const device = ref<Device | null>(null)
const site = ref<Site | null>(null)
const sitesList = ref<Site[]>([])
const sshCredentials = ref<SshCredential[]>([])
const metrics = ref<DeviceMetric[]>([])
const interfaces = ref<DeviceInterface[]>([])

/** What LLDP says is plugged into each port — phones, APs, downstream switches. */
interface LldpEndpoint {
  id: number
  local_port: string | null
  remote_sysname: string | null
  remote_port: string | null
  neighbor_type: string | null
  remote_mgmt_addr: string | null
  remote_mac: string | null
  extension: string | null
  endpoint_model: string | null
  remote_device_id: number | null
  last_seen_at: string | null
  /** Set once the endpoint stopped being reported — the row is history from then on. */
  absent_since?: string | null
}
const neighbors = ref<LldpEndpoint[]>([])

interface DeviceVuln {
  id: number; cve_id: string; state: string; cvss_score: number | null; severity: string
  summary: string | null; reference_url: string | null; matched_constraint: string | null
}
const deviceVulns = ref<DeviceVuln[]>([])
// Assessability follows the device's ACTUAL polled OS version (loaded with the
// device itself), not the vuln-panel fetch — so a transient failure of that one
// request can no longer flash the "no firmware version" banner on a device that
// clearly has one.
const vulnAssessable = computed(() => !!device.value?.os_version)
const VULN_SEV_COLOR: Record<string, string> = { critical: 'error', high: 'warning', medium: 'info', low: 'secondary' }

// Sort by port the way an operator reads a patch panel: ge-0/0/2 before ge-0/0/13.
// Endpoints that have disconnected sink below the live ones — still on the page,
// because "the port is down, what was on it?" is asked at exactly that moment.
const sortedNeighbors = computed(() => [...neighbors.value].sort((a, b) => {
  const gone = Number(!!a.absent_since) - Number(!!b.absent_since)
  if (gone !== 0)
    return gone

  const n = (p: string | null) => Number.parseInt(p?.split('/').pop() ?? '', 10)
  const [x, y] = [n(a.local_port), n(b.local_port)]

  return Number.isNaN(x) || Number.isNaN(y)
    ? (a.local_port ?? '').localeCompare(b.local_port ?? '')
    : x - y
}))

const liveNeighborCount = computed(() => neighbors.value.filter(n => !n.absent_since).length)

// On-demand LLDP pull: re-read this device's neighbors now instead of waiting for
// the 10-minute sweep. Only switches / SD-WAN appliances speak LLDP.
const canPullLldp = computed(() => ['switch', 'edgeconnect'].includes(device.value?.role ?? ''))
const refreshingLldp = ref(false)
async function refreshNeighbors() {
  refreshingLldp.value = true
  try {
    await api(`/api/devices/${deviceId.value}/lldp/refresh`, { method: 'POST' })
    neighbors.value = await api<LldpEndpoint[]>(`/api/devices/${deviceId.value}/neighbors`).catch(() => [])
  }
  finally {
    refreshingLldp.value = false
  }
}

/**
 * What LLDP last saw on a given port.
 *
 * This is the question a down interface raises — "ge-0/0/10 is down, what was
 * plugged into it?" — so the answer belongs on the alarm row, not three cards away.
 * Port names are compared loosely because LLDP and IF-MIB spell them differently
 * across vendors (ge-0/0/10 vs GigabitEthernet0/0/10 vs a trailing unit suffix).
 */
function lastEndpointOn(ifName: string | null | undefined): LldpEndpoint | null {
  if (!ifName)
    return null
  const key = (p: string) => p.toLowerCase().replace(/[^a-z0-9/]/g, '')
  const want = key(ifName)

  const matches = neighbors.value.filter(n => n.local_port && key(n.local_port) === want)
  if (matches.length === 0)
    return null

  // Most recently seen wins when a port has cycled through endpoints.
  return matches.sort((a, b) =>
    Date.parse(b.last_seen_at ?? '') - Date.parse(a.last_seen_at ?? ''))[0]
}

function endpointLabel(n: LldpEndpoint): string {
  const name = n.endpoint_model ?? n.remote_sysname ?? 'Unknown endpoint'

  return n.extension ? `${name} · ext ${n.extension}` : name
}
const goneNeighborCount = computed(() => neighbors.value.filter(n => n.absent_since).length)

const endpointColorMap: Record<string, string> = {
  phone: 'info', ap: 'primary', switch: 'secondary', router: 'warning', server: 'success',
}
function endpointColor(type: string | null | undefined) {
  return endpointColorMap[type ?? ''] ?? 'grey'
}

const endpointIcon: Record<string, string> = {
  phone: 'ri-cellphone-line',
  ap: 'ri-wifi-line',
  switch: 'ri-router-line',
  router: 'ri-global-line',
  server: 'ri-server-line',
}
const vlans = ref<DeviceVlan[]>([])
const alarms = ref<DeviceAlarm[]>([])
const isLoading = ref(true)
const range = ref('24h')

// The device header sticks under the navbar so the name and reachability stay on
// screen while reading interfaces, alarms and config further down the page.
// A sentinel above it reports when it has stuck, which is what lets it compact —
// a full-height header pinned to the top would eat the viewport it is meant to serve.
const headerSentinel = ref<HTMLElement | null>(null)
const headerStuck = ref(false)
let headerObserver: IntersectionObserver | null = null

onMounted(() => {
  if (!headerSentinel.value)
    return
  headerObserver = new IntersectionObserver(
    ([entry]) => { headerStuck.value = !entry.isIntersecting },
    { rootMargin: '-64px 0px 0px 0px', threshold: 0 },   // 64px = navbar height
  )
  headerObserver.observe(headerSentinel.value)
})

onBeforeUnmount(() => {
  headerObserver?.disconnect()
  headerObserver = null
})

const health = computed(() => device.value?.health ?? null)
const sensors = computed(() => device.value?.sensors ?? [])

// EdgeConnect (Silver Peak) memory health from the real signals, not "% used":
// reclaimable = free + buffers + cached, and swap. Per Aruba, the appliance is
// healthy when reclaimable > 250 MB AND swap used < 250 MB.
const edgeMem = computed(() => {
  const h = health.value
  if (device.value?.vendor !== 'silverpeak' || !h || h.mem_reclaimable_mb == null)
    return null
  const reclaimable = h.mem_reclaimable_mb
  const swap = h.swap_used_mb ?? 0
  return { reclaimable, swap, ok: reclaimable > 250 && swap < 250 }
})
const activeAlarms = computed(() => alarms.value.filter(a => a.cleared_at === null))

// Alarm-history filtering — a busy hub can accumulate hundreds of alarms, so give
// the operator search + severity + status filters rather than a raw scroll.
const alarmSearch = ref('')
const alarmSeverityFilter = ref<string | null>(null)
const alarmStatusFilter = ref<'active' | 'cleared' | null>(null)
const criticalActive = computed(() => activeAlarms.value.filter(a => (a.severity ?? 'warning') === 'critical').length)
const clearedCount = computed(() => alarms.value.filter(a => a.cleared_at !== null).length)

/** How long an alarm lasted (raised→cleared), or how long it has been open. */
function alarmDuration(a: DeviceAlarm): string {
  const start = new Date(a.first_seen_at).getTime()
  const end = a.cleared_at ? new Date(a.cleared_at).getTime() : Date.now()
  const s = Math.max(0, Math.floor((end - start) / 1000))
  const d = Math.floor(s / 86400)
  const h = Math.floor((s % 86400) / 3600)
  const m = Math.floor((s % 3600) / 60)
  if (d) return `${d}d ${h}h`
  if (h) return `${h}h ${m}m`
  if (m) return `${m}m`
  return `${s}s`
}

// Enriched rows for the table: duration + how it cleared (auto vs a manual NOC clear).
const alarmRows = computed(() => alarms.value
  .filter(a => !alarmStatusFilter.value || (alarmStatusFilter.value === 'active' ? a.cleared_at === null : a.cleared_at !== null))
  .filter(a => !alarmSeverityFilter.value || (a.severity ?? 'warning') === alarmSeverityFilter.value)
  .map(a => ({
    ...a,
    duration: alarmDuration(a),
    cleared_kind: a.cleared_at ? (a.cleared_manually ? 'manual' : 'auto') : null,
  })))

// The vendor Event ID (alarm_id) is long and unscannable in a list — it lives in
// the row's drill-down dialog. The list stays readable and fits without scrolling.
const alarmHistoryHeaders = [
  { title: 'Severity', key: 'severity', width: 96 },
  { title: 'Ticket', key: 'ticket_number', width: 116 },
  { title: 'Description', key: 'description' },
  { title: 'Raised', key: 'first_seen_at', width: 128 },
  { title: 'Duration', key: 'duration', width: 84 },
  { title: 'Status', key: 'cleared_at', width: 132 },
]

// Compact date for the dense table (no year/seconds/zone) — "Jul 29, 1:27 PM".
function fmtAlarmDate(s: string | null): string {
  if (!s) return '—'
  return new Date(s).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

// --- Alarm history + acknowledge / clear (same NOC workflow as the dashboard) ---
const selectedAlarm = ref<DeviceAlarm | null>(null)
const isAlarmOpen = ref(false)
const alarmNote = ref('')
const alarmBusy = ref(false)

async function loadAlarms() {
  alarms.value = await api<DeviceAlarm[]>(`/api/alarms?device_id=${deviceId.value}`)
}

function openAlarm(a: DeviceAlarm) {
  selectedAlarm.value = a
  alarmNote.value = a.ack_note ?? ''
  isAlarmOpen.value = true
}

async function alarmAction(path: 'acknowledge' | 'clear') {
  const id = selectedAlarm.value?.id
  if (!id) return
  alarmBusy.value = true
  try {
    await api(`/api/alarms/${id}/${path}`, { method: 'POST', body: { note: alarmNote.value || null } })
    isAlarmOpen.value = false
    await loadAlarms()
  }
  finally { alarmBusy.value = false }
}

const alarmSeverityColor: Record<string, string> = {
  critical: 'error', warning: 'warning', info: 'info',
}

function scrollToAlarms() {
  document.getElementById('alarm-history')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// --- Interface-down alarms (the alarms for a switch — Junipers have no SNMP
// alarm table, so these ARE its alarms). Same ack / clear / mute workflow. ---
const alarmedInterfaces = computed(() =>
  interfaces.value.filter(i => !i.alarm_suppressed && (i.alerts?.length ?? 0) > 0),
)
const ifaceAlarmBusy = ref<number | null>(null)
const ifaceClear = ref<{ open: boolean, alertId: number | null, note: string }>({ open: false, alertId: null, note: '' })

async function ackIfaceAlert(alertId: number) {
  ifaceAlarmBusy.value = alertId
  try {
    await api(`/api/interface-alerts/${alertId}/acknowledge`, { method: 'POST' })
    await loadAll()
  }
  finally { ifaceAlarmBusy.value = null }
}
function openIfaceClear(alertId: number) {
  ifaceClear.value = { open: true, alertId, note: '' }
}
async function submitIfaceClear() {
  const id = ifaceClear.value.alertId
  if (!id) return
  ifaceAlarmBusy.value = id
  try {
    await api(`/api/interface-alerts/${id}/clear`, { method: 'POST', body: { note: ifaceClear.value.note || null } })
    ifaceClear.value.open = false
    await loadAll()
  }
  finally { ifaceAlarmBusy.value = null }
}
async function muteIface(interfaceId: number) {
  ifaceAlarmBusy.value = interfaceId
  try {
    await api(`/api/interfaces/${interfaceId}/suppress`, { method: 'POST' })
    await loadAll()
  }
  finally { ifaceAlarmBusy.value = null }
}

function formatUptime(seconds: number | null | undefined): string {
  if (!seconds) return '—'
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`
}

/** Meter fill colour — same thresholds as the text, expressed as a Vuetify colour. */
function meterColor(pct: number | null | undefined): string {
  if (pct === null || pct === undefined) return 'grey'

  return pct >= 90 ? 'error' : pct >= 75 ? 'warning' : 'success'
}

/** EdgeConnect memory needs explaining: 98% used is normal on this gear. */
const edgeMemHint = computed(() => {
  const m = edgeMem.value
  if (!m) return undefined

  return `Reclaimable ${m.reclaimable} MB, swap ${m.swap} MB — `
    + (m.ok ? 'healthy for this platform' : 'below the healthy threshold')
})

function healthColor(pct: number | null | undefined): string {
  if (pct === null || pct === undefined) return ''
  return pct >= 90 ? 'text-error' : pct >= 75 ? 'text-warning' : 'text-success'
}

// --- Inline device edit (so admins change details without leaving the page) ---
const vendorOptions = [
  { title: 'Juniper', value: 'juniper' },
  { title: 'Silver Peak', value: 'silverpeak' },
  { title: 'Fortinet', value: 'fortigate' },
]
const roleOptions = [
  { title: 'Switch', value: 'switch' },
  { title: 'EdgeConnect', value: 'edgeconnect' },
  { title: 'Firewall', value: 'firewall' },
]
const statusOptions = [
  { title: 'Active', value: 'active' },
  { title: 'Inactive', value: 'inactive' },
]

const isEditOpen = ref(false)
const isSavingEdit = ref(false)
const editError = ref('')
const editForm = ref({
  name: '', ip_address: '', next_hop_ip: '', vendor: '', model: '', role: '',
  status: 'active', site_id: null as number | null, ssh_credential_id: null as number | null,
})

function openEdit() {
  const d = device.value
  if (!d) return
  editForm.value = {
    name: d.name, ip_address: d.ip_address ?? '', next_hop_ip: d.next_hop_ip ?? '', vendor: d.vendor ?? '', model: d.model ?? '',
    role: d.role ?? '', status: d.status ?? 'active', site_id: d.site_id ?? null,
    ssh_credential_id: d.ssh_credential_id ?? null,
  }
  editError.value = ''
  isEditOpen.value = true
}

async function saveEdit() {
  isSavingEdit.value = true
  editError.value = ''
  try {
    await api(`/api/devices/${deviceId.value}`, { method: 'PUT', body: { ...editForm.value } })
    isEditOpen.value = false
    await loadAll()
  }
  catch {
    editError.value = 'Could not save. Check the fields and try again.'
  }
  finally {
    isSavingEdit.value = false
  }
}

// --- Enable LLDP on the Silver Peak's LAN interfaces (config push over SSH) ---
const isLldpOpen = ref(false)
const lldpInterfaces = ref<string[]>(['lan0', 'lan1'])
const lldpBusy = ref(false)
const lldpMsg = ref('')
const lldpError = ref('')

function openLldp() {
  lldpInterfaces.value = ['lan0', 'lan1']
  lldpMsg.value = ''
  lldpError.value = ''
  isLldpOpen.value = true
}

async function enableLldp() {
  lldpBusy.value = true
  lldpMsg.value = ''
  lldpError.value = ''
  try {
    const res = await api<{ message: string }>(`/api/devices/${deviceId.value}/lldp/enable`, {
      method: 'POST',
      body: { interfaces: lldpInterfaces.value },
    })
    lldpMsg.value = res.message
  }
  catch (e: any) {
    lldpError.value = e?.data?.error ?? e?.data?.message ?? 'Could not enable LLDP. Check the SSH credential and interface names.'
  }
  finally {
    lldpBusy.value = false
  }
}

// --- Looking glass ---
const toolOutput = ref<ToolResult | null>(null)
const toolRunning = ref('')

async function runTool(tool: 'ping' | 'traceroute' | 'snmpwalk' | 'snmptest' | 'fortisdwan') {
  toolRunning.value = tool
  toolOutput.value = null
  try {
    toolOutput.value = await api<ToolResult>(`/api/devices/${deviceId.value}/tools/${tool}`, { method: 'POST' })
  }
  catch {
    toolOutput.value = { tool, target: '', exit_code: -1, output: 'Failed to run the tool.' }
  }
  finally {
    toolRunning.value = ''
  }
}

async function loadMetrics() {
  metrics.value = await api<DeviceMetric[]>(`/api/devices/metrics?device_id=${deviceId.value}&range=${range.value}`)
}

async function loadAll() {
  isLoading.value = true
  try {
    const deviceResponse = await api<{ data: Device }>(`/api/devices/${deviceId.value}`)
    device.value = deviceResponse.data

    // NOTE: the destructured names must line up with THIS array's order. The vuln
    // request is deliberately the 6th item (matching `vulnRes`); loadMetrics /
    // loadAlarms set their own refs internally and return void, so they go last.
    const [sites, ifaces, vlanRows, neighborRows, ifaceHistoryRows, vulnRes] = await Promise.all([
      api<Site[]>('/api/sites'),
      api<DeviceInterface[]>(`/api/interfaces?device_id=${deviceId.value}`),
      api<DeviceVlan[]>(`/api/devices/${deviceId.value}/vlans`),
      api<LldpEndpoint[]>(`/api/devices/${deviceId.value}/neighbors`).catch(() => []),
      api<InterfaceAlert[]>(`/api/devices/${deviceId.value}/interface-alerts`).catch(() => []),
      api<{ data: DeviceVuln[]; assessable: boolean }>(`/api/devices/${deviceId.value}/vulnerabilities`).catch(() => ({ data: [] as DeviceVuln[], assessable: false })),
      loadMetrics(),
      loadAlarms(),
      loadHealthHistory(),
    ])
    sitesList.value = sites
    site.value = sites.find(s => s.id === device.value?.site_id) ?? null
    interfaces.value = ifaces
    void loadSparklines() // non-blocking — sparklines fill in a moment later
    vlans.value = vlanRows
    neighbors.value = neighborRows
    interfaceHistory.value = ifaceHistoryRows
    deviceVulns.value = vulnRes.data
    // SSH credential profiles for the inline editor's dropdown (admin only).
    if (auth.isAdmin && sshCredentials.value.length === 0) {
      const res = await api<{ data: SshCredential[] }>('/api/ssh-credentials')
      sshCredentials.value = res.data
    }
  }
  finally {
    isLoading.value = false
  }
}

onMounted(loadAll)
watch(range, loadMetrics)

// ---- derived stats ----
const latest = computed(() => (metrics.value.length ? metrics.value[metrics.value.length - 1] : null))
const isReachable = computed(() => latest.value !== null && latest.value.response_time_ms !== null)

const reachablePct = computed(() => {
  if (!metrics.value.length)
    return null
  const up = metrics.value.filter(m => m.response_time_ms !== null).length

  return Math.round((up / metrics.value.length) * 100)
})

const responseStats = computed(() => {
  const vals = metrics.value.map(m => m.response_time_ms).filter((v): v is number => v !== null)
  if (!vals.length)
    return null

  return {
    avg: Math.round(vals.reduce((a, b) => a + b, 0) / vals.length),
    min: Math.round(Math.min(...vals)),
    max: Math.round(Math.max(...vals)),
  }
})

const activeVlans = computed(() =>
  vlans.value.filter(v => v.status === 'active').slice().sort((a, b) => vlanTag(a) - vlanTag(b)),
)

// The authoritative 802.1Q tag is what the switch appends to the name ("...+999").
// Prefer it over the stored vlan_id so rows polled before the id fix (still keyed
// by the internal OID index) show the CORRECT id immediately, not after a re-poll.
function vlanTag(v: DeviceVlan): number {
  const m = (v.name || '').match(/\+(\d{1,4})$/)
  const t = m ? Number(m[1]) : 0
  return t >= 1 && t <= 4094 ? t : v.vlan_id
}
// Clean name for display: strip the trailing "+<tag>" the switch appends (only
// the final "+NN", so a legit "-99" stays). Hide an empty / id-only / "VLANnnnn".
function vlanName(v: DeviceVlan): string {
  const n = (v.name || '').replace(/\+\d+$/, '').trim()
  if (!n || n === String(vlanTag(v)) || /^vlan0*\d+$/i.test(n))
    return ''
  return n
}

function since(iso: string | null): string {
  if (!iso)
    return '—'
  const then = Date.parse(iso)
  if (Number.isNaN(then))
    return '—'
  let secs = Math.max(0, Math.floor((Date.now() - then) / 1000))
  const days = Math.floor(secs / 86400); secs -= days * 86400
  const hours = Math.floor(secs / 3600); secs -= hours * 3600
  const mins = Math.floor(secs / 60)
  if (days > 0)
    return `${days}d ${hours}h ago`
  if (hours > 0)
    return `${hours}h ${mins}m ago`

  return `${mins}m ago`
}

const rangeOptions = [
  { title: '1 Hour', value: '1h' },
  { title: '6 Hours', value: '6h' },
  { title: '24 Hours', value: '24h' },
  { title: '7 Days', value: '7d' },
  { title: '30 Days', value: '30d' },
]

const chartMode = useChartMode()
// NOC-style shading: a translucent red band over each contiguous ICMP-timeout
// (unreachable) stretch — no dots, the gap in the line is coloured instead.
// A flapping device (ICMP up/down every poll) would otherwise yield one xaxis
// annotation per down point — hundreds — and that many freezes ApexCharts and the
// tab. Coalesce down stretches across brief recoveries into one band, and cap.
const MAX_BANDS = 60

function unreachableBands(ms: { recorded_at: string, response_time_ms: number | null }[]) {
  // Same shifted scale as the series, or the bands would sit hours off the line.
  const pts = ms.map(m => ({ t: easternChartMs(m.recorded_at), down: m.response_time_ms === null }))
  const gaps = pts.slice(1).map((p, i) => p.t - pts[i].t).filter(g => g > 0).sort((a, b) => a - b)
  const step = gaps.length ? gaps[Math.floor(gaps.length / 2)] : 60_000

  const raw: { start: number, end: number }[] = []
  let i = 0
  while (i < pts.length) {
    if (!pts[i].down) { i++; continue }
    const start = pts[i].t
    let end = pts[i].t
    while (i < pts.length && pts[i].down) { end = pts[i].t; i++ }
    raw.push({ start, end })
  }

  // Merge down stretches separated only by a brief recovery (≤5 polls).
  const mergeGap = step * 5
  const merged: typeof raw = []
  for (const s of raw) {
    const last = merged[merged.length - 1]
    if (last && s.start - last.end <= mergeGap)
      last.end = s.end
    else
      merged.push({ ...s })
  }

  const capped = merged.length > MAX_BANDS
    ? [...merged].sort((a, b) => (b.end - b.start) - (a.end - a.start)).slice(0, MAX_BANDS)
    : merged

  return capped.map(s => ({ x: s.start - step / 2, x2: s.end + step / 2, fillColor: '#ef4444', opacity: 0.16, borderWidth: 0 }))
}

// A fully-unreachable device has EVERY response_time_ms null. An area+gradient series
// of all-nulls sends ApexCharts into a hang (it can't build the fill path / y-scale) —
// which froze the whole device page. So when there is no reachable sample we feed the
// chart an EMPTY series and pin an explicit x-range; the red timeout bands still render
// over that window, so you still SEE the ICMP outage — the page just never hangs.
const hasSamples = computed(() => metrics.value.some(m => m.response_time_ms !== null))
const chartRange = computed(() => {
  if (!metrics.value.length)
    return null
  return { min: easternChartMs(metrics.value[0].recorded_at), max: easternChartMs(metrics.value[metrics.value.length - 1].recorded_at) }
})

const chartOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent', animations: { enabled: false } },
  theme: { mode: chartMode.value },
  colors: ['#22c55e'],
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
  dataLabels: { enabled: false },
  // Pin the window so the timeout bands position correctly even with an empty line.
  xaxis: { type: 'datetime' as const, ...(chartRange.value ?? {}) },
  yaxis: { labels: { formatter: (v: number) => `${Math.round(v)} ms` }, min: 0, ...(hasSamples.value ? {} : { max: 1 }) },
  tooltip: { x: { format: 'MMM dd, HH:mm' }, y: { formatter: (v: number | null) => (v === null ? 'Timeout' : `${v} ms`) } },
  // Timestamps are shifted to Eastern; say so rather than leaving it ambiguous.
  grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
  markers: { size: 0 },
  // Red band over each unreachable (ICMP-timeout) stretch — the gap is coloured, no dots.
  annotations: { xaxis: unreachableBands(metrics.value) },
}))

const chartSeries = computed(() => [
  {
    name: 'Response time',
    data: hasSamples.value
      ? metrics.value.map(m => [easternChartMs(m.recorded_at), m.response_time_ms] as [number, number | null])
      : [],
  },
])

// ---- Live ICMP: poll one probe every few seconds into a rolling real-time graph ----
const liveOn = ref(false)
const liveData = ref<[number, number | null][]>([])
const liveLatest = ref<number | null | undefined>(undefined)
let liveTimer: ReturnType<typeof setInterval> | null = null
const LIVE_INTERVAL = 2500
const LIVE_MAX = 120 // ~5 min at 2.5s

async function pollLivePing() {
  let rtt: number | null = null
  try {
    const r = await api<{ rtt_ms: number | null }>(`/api/devices/${deviceId.value}/ping`, { method: 'POST' })
    rtt = r.rtt_ms
  }
  catch { rtt = null }
  liveLatest.value = rtt
  liveData.value = [...liveData.value, [Date.now(), rtt]].slice(-LIVE_MAX)
}
function toggleLive() {
  liveOn.value = !liveOn.value
  if (liveOn.value) {
    liveData.value = []
    liveLatest.value = undefined
    void pollLivePing()
    liveTimer = setInterval(pollLivePing, LIVE_INTERVAL)
  }
  else if (liveTimer) {
    clearInterval(liveTimer)
    liveTimer = null
  }
}
onBeforeUnmount(() => { if (liveTimer) clearInterval(liveTimer) })

const liveSeries = computed(() => [{ name: 'Live ICMP', data: liveData.value }])
const liveHasSamples = computed(() => liveData.value.some(p => p[1] !== null))
const liveOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent', animations: { enabled: false } },
  theme: { mode: chartMode.value },
  colors: ['#22c55e'],
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
  dataLabels: { enabled: false },
  // Browser-local time — this is a real-time view, not the historical Eastern series.
  xaxis: { type: 'datetime' as const, labels: { datetimeUTC: false } },
  yaxis: { labels: { formatter: (v: number) => `${Math.round(v)} ms` }, min: 0, ...(liveHasSamples.value ? {} : { max: 1 }) },
  tooltip: { x: { format: 'HH:mm:ss' }, y: { formatter: (v: number | null) => (v === null ? 'Timeout' : `${v} ms`) } },
  grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
  markers: { size: 0 },
}))

// ── CPU / Memory trend (Juniper RE, Silver Peak, FortiGate — all via health:monitor) ──
interface HealthPoint { recorded_at: string, cpu_pct: number | null, mem_pct: number | null, temperature_c: number | null }
const healthHistory = ref<HealthPoint[]>([])
const healthRange = ref('24h')

async function loadHealthHistory() {
  healthHistory.value = await api<HealthPoint[]>(`/api/devices/${deviceId.value}/health-history?range=${healthRange.value}`)
}
watch(healthRange, loadHealthHistory)

const healthSeries = computed(() => [
  { name: 'CPU', data: healthHistory.value.map(h => [easternChartMs(h.recorded_at), h.cpu_pct] as [number, number | null]) },
  { name: 'Memory', data: healthHistory.value.map(h => [easternChartMs(h.recorded_at), h.mem_pct] as [number, number | null]) },
])
// Pin the x-axis to the SELECTED window ending NOW — otherwise ApexCharts auto-ranges
// to the last data point, so a device that stopped reporting (down) looks current: its
// stale line sits at the right edge as if it were live. With the axis pinned to now, the
// gap since it went dark is visible, and we shade it "no data".
const HEALTH_RANGE_MS: Record<string, number> = { '1h': 3_600_000, '6h': 21_600_000, '24h': 86_400_000, '7d': 604_800_000 }
const healthXaxis = computed(() => {
  const now = easternChartMs(new Date().toISOString())
  return { min: now - (HEALTH_RANGE_MS[healthRange.value] ?? HEALTH_RANGE_MS['24h']), max: now }
})
const healthGapBands = computed(() => {
  const pts = healthHistory.value.filter(h => h.cpu_pct != null || h.mem_pct != null)
  if (!pts.length)
    return []
  const lastMs = easternChartMs(pts[pts.length - 1].recorded_at)
  const now = easternChartMs(new Date().toISOString())
  // A gap of >3 health polls (~15 min) with nothing recorded = the device stopped
  // reporting (unreachable). Shade last-data → now so the outage reads at a glance.
  if (now - lastMs < 15 * 60_000)
    return []
  return [{
    x: lastMs, x2: now, fillColor: '#64748b', opacity: 0.16, borderWidth: 0,
    label: { text: 'no data — device not reporting', position: 'top', orientation: 'horizontal' as const,
      style: { color: '#e2e8f0', background: '#475569', fontSize: '10px' } },
  }]
})
const healthOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent', animations: { enabled: false } },
  theme: { mode: chartMode.value },
  colors: ['#3b82f6', '#a855f7'],
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
  dataLabels: { enabled: false },
  xaxis: { type: 'datetime' as const, ...healthXaxis.value },
  yaxis: { labels: { formatter: (v: number) => `${Math.round(v)}%` }, min: 0, max: 100 },
  tooltip: { x: { format: 'MMM dd, HH:mm' }, y: { formatter: (v: number | null) => (v === null ? '—' : `${Math.round(v)}%`) } },
  grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
  markers: { size: 0 },
  legend: { show: true, position: 'top' as const },
  annotations: { xaxis: healthGapBands.value },
}))
const hasHealthHistory = computed(() => healthHistory.value.some(h => h.cpu_pct != null || h.mem_pct != null))

// ---- Live CPU/memory: poll a read every few seconds into a rolling real-time graph ----
const healthLiveOn = ref(false)
const healthLiveData = ref<{ t: number, cpu: number | null, mem: number | null }[]>([])
let healthLiveTimer: ReturnType<typeof setInterval> | null = null
const HEALTH_LIVE_INTERVAL = 4000
const HEALTH_LIVE_MAX = 90

async function pollLiveHealth() {
  let cpu: number | null = null
  let mem: number | null = null
  try {
    const r = await api<{ cpu_pct: number | null, mem_pct: number | null }>(`/api/devices/${deviceId.value}/health-live`, { method: 'POST' })
    cpu = r.cpu_pct
    mem = r.mem_pct
  }
  catch { /* keep nulls — reads as a gap */ }
  healthLiveData.value = [...healthLiveData.value, { t: Date.now(), cpu, mem }].slice(-HEALTH_LIVE_MAX)
}
function toggleHealthLive() {
  healthLiveOn.value = !healthLiveOn.value
  if (healthLiveOn.value) {
    healthLiveData.value = []
    void pollLiveHealth()
    healthLiveTimer = setInterval(pollLiveHealth, HEALTH_LIVE_INTERVAL)
  }
  else if (healthLiveTimer) {
    clearInterval(healthLiveTimer)
    healthLiveTimer = null
  }
}
onBeforeUnmount(() => { if (healthLiveTimer) clearInterval(healthLiveTimer) })

const healthLiveSeries = computed(() => [
  { name: 'CPU', data: healthLiveData.value.map(p => [p.t, p.cpu] as [number, number | null]) },
  { name: 'Memory', data: healthLiveData.value.map(p => [p.t, p.mem] as [number, number | null]) },
])
const healthLiveLatest = computed(() => healthLiveData.value[healthLiveData.value.length - 1])
const healthLiveOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent', animations: { enabled: false } },
  theme: { mode: chartMode.value },
  colors: ['#3b82f6', '#a855f7'],
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
  dataLabels: { enabled: false },
  xaxis: { type: 'datetime' as const, labels: { datetimeUTC: false } },
  yaxis: { labels: { formatter: (v: number) => `${Math.round(v)}%` }, min: 0, max: 100 },
  tooltip: { x: { format: 'HH:mm:ss' }, y: { formatter: (v: number | null) => (v === null ? '—' : `${Math.round(v)}%`) } },
  grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
  markers: { size: 0 },
  legend: { show: true, position: 'top' as const },
}))

const interfaceSearch = ref('')
const interfacePage = ref(1)
watch(interfaceSearch, () => { interfacePage.value = 1 })

const interfaceHeaders = [
  { title: 'Interface', key: 'if_name' },
  { title: 'Health', key: 'health', width: 130 },
  { title: 'Endpoints', key: 'mac_addresses_count', align: 'end' as const, width: 104 },
  { title: '24h', key: 'spark', width: 96, sortable: false },
  { title: 'Throughput (now)', key: 'throughput', align: 'end' as const, width: 150, sortable: false },
  { title: 'Utilization', key: 'utilization', width: 210, sortable: false },
  { title: 'Peak', key: 'peak_util_pct', align: 'end' as const, width: 72 },
]

/** Current bandwidth (bits/s) in/out for a row, derived from util % × link speed. */
function ifThroughput(i: DeviceInterface): { in: number, out: number } {
  const speed = i.speed_bps || 0
  return { in: (i.in_util_pct / 100) * speed, out: (i.out_util_pct / 100) * speed }
}

function ifSpeedLabel(bps: number): string {
  if (!bps) return '—'
  if (bps >= 1e9) return `${bps / 1e9} Gbps`
  if (bps >= 1e6) return `${bps / 1e6} Mbps`
  return `${bps} bps`
}

// --- Interface detail (traffic + up/down history) ---
const POLL_SECONDS = 300 // interfaces are polled every 5 minutes
const selectedInterface = ref<DeviceInterface | null>(null)
const isInterfaceOpen = ref(false)
const interfaceMetrics = ref<InterfaceMetric[]>([])
const interfaceAlerts = ref<InterfaceAlert[]>([])

// Interface alert HISTORY for the whole device, cleared ones included. The
// interfaces endpoint returns only open alerts, so clearing one used to make it
// vanish with no record of the ticket, who cleared it or why.
const interfaceHistory = ref<InterfaceAlert[]>([])

/** cleared_by is the loaded user relation on this endpoint, not the raw column. */
function clearedByName(a: InterfaceAlert): string | null {
  return typeof a.cleared_by === 'object' && a.cleared_by !== null ? a.cleared_by.name : null
}
const interfaceRange = ref('24h')
const isInterfaceLoading = ref(false)

interface LearnedMac { mac: string, oui_vendor: string | null, vlan: string, first_seen_at: string, last_seen_at: string }
const interfaceMacs = ref<LearnedMac[]>([])

async function loadInterfaceData() {
  if (!selectedInterface.value) return
  isInterfaceLoading.value = true
  const id = selectedInterface.value.id
  const [metrics, alerts, macs] = await Promise.all([
    api<InterfaceMetric[]>(`/api/interfaces/metrics?interface_id=${id}&range=${interfaceRange.value}`),
    api<InterfaceAlert[]>(`/api/interfaces/${id}/alerts`),
    api<{ data: LearnedMac[] }>(`/api/mac-addresses?interface_id=${id}`),
  ])
  interfaceMetrics.value = metrics
  interfaceAlerts.value = alerts
  interfaceMacs.value = macs.data
  isInterfaceLoading.value = false
}

async function openInterface(iface: DeviceInterface) {
  selectedInterface.value = iface
  isInterfaceOpen.value = true
  await loadInterfaceData()
}
watch(interfaceRange, loadInterfaceData)

function bitsPerSec(octetsDelta: number): number {
  return +(octetsDelta * 8 / POLL_SECONDS / 1e6).toFixed(3) // Mbps
}

function formatBytes(n: number): string {
  if (!n)
    return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let v = n
  let i = 0
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024
    i++
  }

  return `${v.toFixed(i === 0 ? 0 : 2)} ${units[i]}`
}

// What the interface graph plots: throughput, discards, or errors.
const chartMetric = ref<'traffic' | 'discards' | 'errors'>('traffic')
const chartMetricOptions = [
  { value: 'traffic', label: 'Traffic', icon: 'ri-exchange-line' },
  { value: 'discards', label: 'Discards', icon: 'ri-stack-line' },
  { value: 'errors', label: 'Errors', icon: 'ri-error-warning-line' },
] as const

const ifTrafficSeries = computed(() => {
  const m = interfaceMetrics.value
  const at = (r: InterfaceMetric) => easternChartMs(r.recorded_at)
  if (chartMetric.value === 'discards') {
    return [
      { name: 'In discards', data: m.map(r => [at(r), r.in_discards_delta] as [number, number]) },
      { name: 'Out discards', data: m.map(r => [at(r), r.out_discards_delta] as [number, number]) },
    ]
  }
  if (chartMetric.value === 'errors') {
    return [
      { name: 'In errors', data: m.map(r => [at(r), r.in_errors_delta ?? 0] as [number, number]) },
      { name: 'Out errors', data: m.map(r => [at(r), r.out_errors_delta ?? 0] as [number, number]) },
    ]
  }
  return [
    { name: 'In', data: m.map(r => [at(r), bitsPerSec(r.in_octets_delta)] as [number, number]) },
    { name: 'Out', data: m.map(r => [at(r), bitsPerSec(r.out_octets_delta)] as [number, number]) },
  ]
})

const ifTrafficOptions = computed(() => {
  const metric = chartMetric.value
  const isTraffic = metric === 'traffic'
  const unit = isTraffic ? 'Mbps' : 'pkts'
  const fmt = (v: number) => isTraffic ? `${v.toFixed(2)} Mbps` : `${Math.round(v)} pkts`
  const colors = metric === 'errors' ? ['#e5484d', '#f59e0b'] : metric === 'discards' ? ['#f59e0b', '#a855f7'] : ['#22c55e', '#3b82f6']
  const t = ifTrafficTotals.value
  const inPts = ifTrafficSeries.value[0]?.data ?? []
  const avgIn = inPts.length ? inPts.reduce((s, p) => s + p[1], 0) / inPts.length : 0
  return {
    chart: { toolbar: { show: false }, background: 'transparent' },
    theme: { mode: chartMode.value },
    colors,
    stroke: { curve: 'smooth' as const, width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
    dataLabels: { enabled: false },
    xaxis: { type: 'datetime' as const },
    yaxis: { labels: { formatter: (v: number) => fmt(v) }, min: 0 },
    tooltip: { x: { format: 'MMM dd, HH:mm' }, y: { formatter: (v: number) => fmt(v) } },
    legend: { show: true },
    grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
    markers: { size: 0 },
    // The avg/peak/error annotations only make sense on the throughput view.
    annotations: isTraffic
      ? {
          yaxis: avgIn > 0
            ? [{ y: avgIn, borderColor: '#9AA4B2', strokeDashArray: 4, label: { text: `avg in ${avgIn.toFixed(1)} Mbps`, style: { color: '#fff', background: '#6B7482' } } }]
            : [],
          xaxis: ifErrorEvents.value.map(x => ({ x, borderColor: '#E5484D', strokeDashArray: 0, opacity: 0.5, label: { text: 'errors', style: { color: '#fff', background: '#E5484D', fontSize: '9px' } } })),
          points: t.peakMbps > 0
            ? [{ x: t.peakAt, y: +t.peakMbps.toFixed(3), marker: { size: 5, fillColor: '#22c55e', strokeColor: '#fff' }, label: { text: `peak ${t.peakMbps >= 1000 ? `${(t.peakMbps / 1000).toFixed(1)}G` : `${t.peakMbps.toFixed(0)}M`}bps`, style: { color: '#fff', background: '#16a34a', fontSize: '9px' } } }]
            : [],
        }
        // Explicit empty arrays — ApexCharts keeps stale annotations if omitted.
        : { yaxis: [], xaxis: [], points: [] },
  }
})
const ifChartEmpty = computed(() => interfaceMetrics.value.length === 0)

function alertDuration(a: InterfaceAlert): string {
  const start = new Date(a.started_at).getTime()
  const end = a.ended_at ? new Date(a.ended_at).getTime() : Date.now()
  const mins = Math.round((end - start) / 60000)
  return mins >= 60 ? `${Math.floor(mins / 60)}h ${mins % 60}m` : `${mins}m`
}

// ── Interface health (pill + proactive actions) ─────────────────────────────
// The pill tells you WHY a port needs attention; clicking the row opens the
// detail dialog where it can be acted on (ack / clear / mute / note).
const healthMeta: Record<string, { label: string, color: string, icon: string }> = {
  clean: { label: 'Clean', color: 'success', icon: 'ri-checkbox-circle-line' },
  errors: { label: 'CRC errors', color: 'error', icon: 'ri-error-warning-line' },
  congested: { label: 'Discards', color: 'warning', icon: 'ri-stack-line' },
  flapping: { label: 'Flapping', color: 'purple', icon: 'ri-swap-2-line' },
  down: { label: 'Down', color: 'error', icon: 'ri-close-circle-line' },
  admin_down: { label: 'Admin down', color: 'secondary', icon: 'ri-forbid-2-line' },
  muted: { label: 'Muted', color: 'secondary', icon: 'ri-volume-mute-line' },
}
function ifHealth(i: DeviceInterface) {
  return healthMeta[i.health ?? 'clean'] ?? healthMeta.clean
}

// Per-interface traffic sparklines for the whole device (one call, bps series).
const sparklines = ref<Record<number, { in: number[], out: number[] }>>({})
async function loadSparklines() {
  try {
    sparklines.value = await api<Record<number, { in: number[], out: number[] }>>(`/api/interfaces/sparklines?device_id=${deviceId.value}`)
  }
  catch { sparklines.value = {} }
}
/** Polyline points for a mini sparkline, normalised to an 88×22 box. */
function sparkPoints(vals: number[] | undefined, w = 88, h = 22): string {
  if (!vals || vals.length < 2)
    return ''
  const max = Math.max(...vals, 1)
  const step = w / (vals.length - 1)
  return vals.map((v, i) => `${(i * step).toFixed(1)},${(h - (v / max) * (h - 3) - 1.5).toFixed(1)}`).join(' ')
}

function formatBps(bps: number): string {
  if (bps >= 1e9) return `${(bps / 1e9).toFixed(1)}G`
  if (bps >= 1e6) return `${(bps / 1e6).toFixed(0)}M`
  if (bps >= 1e3) return `${(bps / 1e3).toFixed(0)}k`
  return `${Math.round(bps)}`
}

// Device-wide interface KPIs for the summary strip above the table.
const ifKpis = computed(() => {
  const list = interfaces.value
  const up = list.filter(i => i.status === 'up' && i.admin_status !== 'down').length
  const down = list.filter(i => i.status === 'down' && i.admin_status !== 'down').length
  const errors = list.filter(i => i.health === 'errors').length
  const congested = list.filter(i => i.health === 'congested').length
  const flapping = list.filter(i => i.health === 'flapping').length
  const inBps = list.reduce((s, i) => s + (i.in_util_pct / 100) * i.speed_bps, 0)
  const outBps = list.reduce((s, i) => s + (i.out_util_pct / 100) * i.speed_bps, 0)
  const busiest = list.reduce<DeviceInterface | null>((a, b) => ((b.peak_util_pct ?? 0) > (a?.peak_util_pct ?? -1) ? b : a), null)
  return { up, down, errors, congested, flapping, inBps, outBps, busiest }
})

// Proactive actions from the interface detail dialog. Ack/clear on a real
// down-alert reuse the existing alert workflow; ack-health / note / mute act on
// the interface itself.
const ifaceNote = ref('')
const ifaceActionBusy = ref(false)
watch(selectedInterface, (i) => { ifaceNote.value = i?.note ?? '' })

async function reloadAfterAction() {
  await loadAll()
  if (selectedInterface.value) {
    const fresh = interfaces.value.find(i => i.id === selectedInterface.value!.id)
    if (fresh)
      selectedInterface.value = fresh
  }
}
async function saveIfaceNote() {
  if (!selectedInterface.value) return
  ifaceActionBusy.value = true
  try {
    await api(`/api/interfaces/${selectedInterface.value.id}/note`, { method: 'POST', body: { note: ifaceNote.value } })
    await reloadAfterAction()
  }
  finally { ifaceActionBusy.value = false }
}
async function ackHealthIface() {
  if (!selectedInterface.value) return
  ifaceActionBusy.value = true
  try {
    await api(`/api/interfaces/${selectedInterface.value.id}/ack-health`, { method: 'POST' })
    await reloadAfterAction()
  }
  finally { ifaceActionBusy.value = false }
}
// TDR cable test (Juniper copper ports) — a per-interface diagnostic popup that
// tells cable faults apart from interface faults.
const tdrRunning = ref(false)
const tdrResult = ref<ToolResult | null>(null)
const tdrOpen = ref(false)
const canTdr = computed(() =>
  device.value?.vendor === 'juniper'
  && !!selectedInterface.value
  && /^(ge|xe|et|fe|mge|xle)-\d+\/\d+\/\d+$/.test(selectedInterface.value.if_name),
)
async function runTdr() {
  if (!selectedInterface.value) return
  tdrRunning.value = true
  tdrResult.value = null
  tdrOpen.value = true
  try {
    // TDR polls the switch for several seconds — allow more than the default 60s cap
    // (still under nginx's 120s), so a slow cable test isn't aborted by the client.
    tdrResult.value = await api<ToolResult>(`/api/devices/${deviceId.value}/tools/tdr`, { method: 'POST', body: { interface: selectedInterface.value.if_name }, timeout: 118000 })
  }
  catch (e: any) {
    tdrResult.value = { tool: 'tdr', target: selectedInterface.value.if_name, exit_code: -1, output: e?.data?.message ?? 'The cable test failed to run.' }
  }
  finally { tdrRunning.value = false }
}

async function toggleMuteIface() {
  if (!selectedInterface.value) return
  const i = selectedInterface.value
  ifaceActionBusy.value = true
  try {
    await api(`/api/interfaces/${i.id}/${i.alarm_suppressed ? 'unsuppress' : 'suppress'}`, { method: 'POST' })
    await reloadAfterAction()
  }
  finally { ifaceActionBusy.value = false }
}

// The open down-alert on the selected interface (if any) — drives the ack/clear
// buttons in the dialog.
const selectedOpenAlert = computed(() => selectedInterface.value?.alerts?.[0] ?? null)

function within24h(iso?: string | null): boolean {
  return !!iso && Date.now() - Date.parse(iso) < 24 * 3600 * 1000
}
// A recent speed downshift or duplex flip is a cabling/negotiation clue — surface it.
const linkChangeNote = computed(() => {
  const i = selectedInterface.value
  if (!i)
    return ''
  const notes: string[] = []
  if (within24h(i.speed_changed_at) && i.prev_speed_bps)
    notes.push(`speed ${ifSpeedLabel(i.prev_speed_bps)} → ${ifSpeedLabel(i.speed_bps)}`)
  if (within24h(i.duplex_changed_at) && i.prev_duplex)
    notes.push(`duplex ${i.prev_duplex} → ${i.duplex}`)
  return notes.length ? `${notes.join(' · ')} — recent change, suspect cabling/negotiation` : ''
})

// Total bytes transferred over the charted window + the peak sample, for the
// chart header and its peak marker.
const ifTrafficTotals = computed(() => {
  const inB = interfaceMetrics.value.reduce((s, m) => s + m.in_octets_delta, 0)
  const outB = interfaceMetrics.value.reduce((s, m) => s + m.out_octets_delta, 0)
  // bitsPerSec() returns Mbps (the chart's unit); keep peak in Mbps for the chart
  // marker AND expose it as bits/s for the byte-formatter in the header.
  let peakMbps = 0
  let peakAt = 0
  for (const m of interfaceMetrics.value) {
    const mbps = Math.max(bitsPerSec(m.in_octets_delta), bitsPerSec(m.out_octets_delta))
    if (mbps > peakMbps) { peakMbps = mbps; peakAt = easternChartMs(m.recorded_at) }
  }
  return { inB, outB, peakMbps, peakBps: peakMbps * 1e6, peakAt }
})
// Timestamps where CRC/in+out errors were recorded — dropped on the chart so a
// traffic dip can be lined up with the error burst that caused it.
const ifErrorEvents = computed(() =>
  interfaceMetrics.value
    .filter(m => (m.in_errors_delta ?? 0) + (m.out_errors_delta ?? 0) > 0)
    .map(m => easternChartMs(m.recorded_at)),
)

// ── Section index (right-rail "on this device" nav) ─────────────────────────
// Declared last so the conditional-section getters (sortedNeighbors, etc.) are
// already initialised — the computed is read during watch setup. Lists the cards
// this page actually shows and scroll-spies which one is in view.
const activeSection = ref('device-detail')
const vcMembers = computed(() => device.value?.members ?? [])
const vcDegraded = computed(() => vcMembers.value.some(m => m.status === 'missing'))
const pageSections = computed(() => [
  { id: 'overview', label: 'Response Time', icon: 'ri-pulse-line', show: true, chip: null as number | null, attn: false },
  { id: 'cpu-memory', label: 'CPU & Memory', icon: 'ri-cpu-line', show: true, chip: null, attn: false },
  { id: 'device-detail', label: 'Device Detail', icon: 'ri-server-line', show: true, chip: null, attn: false },
  { id: 'virtual-chassis', label: 'Virtual Chassis', icon: 'ri-stack-line', show: vcMembers.value.length > 0, chip: vcMembers.value.length || null, attn: vcDegraded.value },
  { id: 'endpoints', label: 'Connected Endpoints', icon: 'ri-plug-line', show: sortedNeighbors.value.length > 0 || canPullLldp.value, chip: liveNeighborCount.value || null, attn: false },
  { id: 'interface-alarms', label: 'Interface Alarms', icon: 'ri-alarm-warning-line', show: alarmedInterfaces.value.length > 0, chip: alarmedInterfaces.value.length || null, attn: true },
  { id: 'interface-alarm-history', label: 'Interface Alarm History', icon: 'ri-history-line', show: interfaceHistory.value.length > 0, chip: interfaceHistory.value.length || null, attn: false },
  { id: 'alarm-history', label: 'Alarm History', icon: 'ri-alarm-warning-line', show: true, chip: activeAlarms.value.length || null, attn: activeAlarms.value.length > 0 },
  { id: 'interfaces', label: 'Interfaces & VLANs', icon: 'ri-node-tree', show: true, chip: interfaces.value.length || null, attn: false },
  { id: 'vulnerabilities', label: 'Vulnerabilities', icon: 'ri-shield-keyhole-line', show: true, chip: deviceVulns.value.length || null, attn: deviceVulns.value.some(v => v.severity === 'critical') },
].filter(s => s.show))

function scrollToSection(id: string) {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

let sectionObserver: IntersectionObserver | null = null
function bindSectionSpy() {
  sectionObserver?.disconnect()
  for (const s of pageSections.value) {
    const el = document.getElementById(s.id)
    if (el)
      sectionObserver?.observe(el)
  }
}
onMounted(() => {
  sectionObserver = new IntersectionObserver(
    (entries) => {
      const visible = entries.filter(e => e.isIntersecting)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
      if (visible[0]?.target.id)
        activeSection.value = visible[0].target.id
    },
    { rootMargin: '-72px 0px -55% 0px', threshold: 0 },
  )
  requestAnimationFrame(bindSectionSpy)
})
// Re-bind when conditional sections appear/disappear after data loads.
watch(pageSections, () => requestAnimationFrame(bindSectionSpy), { flush: 'post' })
onBeforeUnmount(() => {
  sectionObserver?.disconnect()
  sectionObserver = null
})

</script>

<template>
  <div>
    <!-- Device header — the identity + live pulse of the device, professionalized. -->
    <div ref="headerSentinel" class="dev-header-sentinel" />
    <VCard
      class="dev-header mb-4"
      :class="{ 'is-stuck': headerStuck }"
      flat
    >
      <div class="d-flex align-center ga-3 pa-4">
        <VBtn
          icon="ri-arrow-left-line"
          variant="tonal"
          size="small"
          @click="router.push('/devices')"
        />
        <div
          class="dev-status-rail"
          :class="isReachable ? 'is-up' : (isLoading ? 'is-idle' : 'is-down')"
        />
        <div class="flex-grow-1">
          <div class="d-flex align-center ga-2 flex-wrap">
            <h1 class="text-h5 font-weight-bold mb-0">
              {{ device?.name ?? '…' }}
            </h1>
            <VChip
              v-if="!isLoading"
              size="small"
              :color="isReachable ? 'success' : 'error'"
              variant="flat"
            >
              <span class="pulse-dot" :class="isReachable ? 'is-up' : 'is-down'" />
              {{ isReachable ? 'Reachable' : 'Unreachable' }}
            </VChip>
            <VChip
              v-if="device?.ssh_credential_id || device?.ssh_credential"
              size="small"
              color="info"
              variant="tonal"
              prepend-icon="ri-terminal-box-line"
            >
              SSH: {{ device?.ssh_credential_name ?? 'inline' }}
            </VChip>
            <VChip
              v-if="activeAlarms.length > 0"
              size="small"
              color="error"
              variant="flat"
              prepend-icon="ri-alarm-warning-line"
              class="cursor-pointer"
              @click="scrollToAlarms"
            >
              {{ activeAlarms.length }} alarm{{ activeAlarms.length > 1 ? 's' : '' }}
            </VChip>
            <VSpacer />
            <!-- Last check, popped with colour, tucked up here so it doesn't eat a card. -->
            <VChip
              v-if="latest"
              size="small"
              variant="tonal"
              :color="isReachable ? 'success' : 'error'"
              prepend-icon="ri-pulse-line"
            >
              Last check {{ since(latest.recorded_at) }}
            </VChip>
          </div>
          <div class="dev-header__sub text-body-2 text-medium-emphasis mt-1 d-flex align-center ga-2 flex-wrap">
            <span class="font-weight-medium text-high-emphasis">{{ device?.vendor }} {{ device?.model }}</span>
            <span class="dev-dot">·</span>
            <span class="mono">{{ device?.ip_address }}</span>
            <span class="dev-dot">·</span>
            <span><VIcon icon="ri-map-pin-line" size="14" class="me-1" />{{ site?.name ?? '—' }}</span>
          </div>
        </div>
      </div>
    </VCard>

    <!-- Alarm banner: the first thing a NOC sees when this device has raised alarms -->
    <VAlert
      v-if="activeAlarms.length > 0"
      type="error"
      variant="tonal"
      prominent
      class="mb-4"
      icon="ri-alarm-warning-line"
    >
      <div class="d-flex align-center justify-space-between flex-wrap ga-2">
        <div>
          <strong>{{ activeAlarms.length }} active alarm{{ activeAlarms.length > 1 ? 's' : '' }}</strong>
          on this device
          <span
            v-if="activeAlarms[0]?.ticket_number"
            class="text-medium-emphasis"
          >· latest #{{ activeAlarms[0].ticket_number }} — {{ activeAlarms[0].description }}</span>
        </div>
        <VBtn
          size="small"
          variant="flat"
          color="error"
          @click="scrollToAlarms"
        >
          View alarms
        </VBtn>
      </div>
    </VAlert>

    <div class="dev-body">
      <!-- Section index: jump-nav for the cards this device page shows. -->
      <aside class="dev-index">
        <div class="dev-index__title">On this device</div>
        <nav class="dev-index__list">
          <button
            v-for="s in pageSections"
            :key="s.id"
            type="button"
            class="dev-index__row"
            :class="{ 'is-active': activeSection === s.id }"
            @click="scrollToSection(s.id)"
          >
            <span class="dev-index__ico"><VIcon :icon="s.icon" size="18" /></span>
            <span class="dev-index__label"><span v-if="s.attn" class="dev-index__attn" />{{ s.label }}</span>
            <span v-if="s.chip != null" class="dev-index__chip" :class="{ 'is-attn': s.attn }">{{ s.chip }}</span>
          </button>
        </nav>
      </aside>

      <div class="dev-sections">
        <!-- Response time + Tools, side by side, up top under the device info. -->
        <VRow id="overview" class="mb-4 scroll-anchor">
      <VCol cols="12" :md="auth.isAdmin ? 6 : 12">
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle class="d-flex align-center ga-2">
              <VIcon icon="ri-line-chart-line" size="20" /> Response Time (ICMP)
            </VCardTitle>
            <template #append>
              <div class="d-flex align-center ga-2">
                <VBtn
                  :color="liveOn ? 'error' : undefined"
                  :variant="liveOn ? 'flat' : 'tonal'"
                  size="small"
                  :prepend-icon="liveOn ? 'ri-stop-circle-line' : 'ri-pulse-line'"
                  @click="toggleLive"
                >
                  {{ liveOn ? 'Stop' : 'Live' }}
                </VBtn>
                <VSelect
                  v-if="!liveOn"
                  v-model="range"
                  :items="rangeOptions"
                  density="compact"
                  hide-details
                  style="width: 128px;"
                />
              </div>
            </template>
          </VCardItem>
          <VCardText>
            <!-- LIVE mode: real-time ICMP, one probe every 2.5s -->
            <template v-if="liveOn">
              <div class="d-flex align-baseline ga-3 mb-2 flex-wrap">
                <span class="text-h4 font-weight-bold" :class="liveLatest ? 'text-success' : (liveLatest === null ? 'text-error' : '')">
                  {{ liveLatest != null ? Math.round(liveLatest) : (liveLatest === null ? '—' : '·') }}<span class="text-body-2 text-medium-emphasis font-weight-regular"> ms</span>
                </span>
                <VChip size="x-small" color="error" variant="flat" label class="live-pulse">
                  ● LIVE
                </VChip>
                <span class="text-caption text-medium-emphasis">
                  real-time · every 2.5s · {{ liveData.length }} probes
                </span>
              </div>
              <VueApexCharts
                type="area"
                height="188"
                :options="liveOptions"
                :series="liveSeries"
              />
            </template>

            <template v-else>
            <div class="d-flex align-baseline ga-3 mb-2 flex-wrap">
              <span class="text-h4 font-weight-bold" :class="isReachable ? 'text-success' : 'text-error'">
                {{ latest && latest.response_time_ms !== null ? Math.round(latest.response_time_ms) : (latest ? '—' : '·') }}<span class="text-body-2 text-medium-emphasis font-weight-regular"> ms</span>
              </span>
              <VChip size="x-small" :color="isReachable ? 'success' : 'error'" variant="tonal" label>
                {{ isLoading ? '—' : (isReachable ? 'UP' : 'DOWN') }}
              </VChip>
              <span class="text-caption text-medium-emphasis">
                {{ reachablePct === null ? 'no data yet' : `${reachablePct}% reachable · ${metrics.length} checks · pinged every 60s` }}
              </span>
            </div>
            <VueApexCharts
              v-if="!isLoading"
              type="area"
              height="188"
              :options="chartOptions"
              :series="chartSeries"
            />
            </template>
            <div v-if="responseStats" class="d-flex ga-6 mt-1">
              <div>
                <span class="text-caption text-medium-emphasis">Avg</span>
                <span class="text-body-1 font-weight-medium ms-1">{{ responseStats.avg }} ms</span>
              </div>
              <div>
                <span class="text-caption text-medium-emphasis">Min</span>
                <span class="text-body-1 font-weight-medium ms-1">{{ responseStats.min }} ms</span>
              </div>
              <div>
                <span class="text-caption text-medium-emphasis">Max</span>
                <span class="text-body-1 font-weight-medium ms-1">{{ responseStats.max }} ms</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="auth.isAdmin" cols="12" md="6">
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle class="d-flex align-center ga-2">
              <VIcon icon="ri-terminal-box-line" size="20" /> Tools · Looking Glass
            </VCardTitle>
          </VCardItem>
          <VCardText>
            <div class="d-flex ga-2 mb-3 flex-wrap">
              <VBtn size="small" variant="tonal" prepend-icon="ri-signal-tower-line" :loading="toolRunning === 'ping'" @click="runTool('ping')">Ping</VBtn>
              <VBtn size="small" variant="tonal" prepend-icon="ri-route-line" :loading="toolRunning === 'traceroute'" @click="runTool('traceroute')">Traceroute</VBtn>
              <VBtn size="small" variant="tonal" prepend-icon="ri-search-line" :loading="toolRunning === 'snmpwalk'" @click="runTool('snmpwalk')">SNMP walk</VBtn>
              <VBtn size="small" variant="tonal" color="primary" prepend-icon="ri-pulse-line" :loading="toolRunning === 'snmptest'" @click="runTool('snmptest')">Test SNMP</VBtn>
              <VBtn v-if="device?.vendor === 'fortigate'" size="small" variant="tonal" color="primary" prepend-icon="ri-router-line" :loading="toolRunning === 'fortisdwan'" @click="runTool('fortisdwan')">SD-WAN health</VBtn>
            </div>
            <pre v-if="toolOutput" class="tool-output">{{ toolOutput.output || '(no output)' }}</pre>
            <div v-else class="text-medium-emphasis text-body-2">
              Run a live diagnostic against <span class="mono">{{ device?.ip_address }}</span>.
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- CPU / Memory trend (Juniper RE, Silver Peak, FortiGate) -->
    <VCard id="cpu-memory" class="mb-4 scroll-anchor">
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon icon="ri-cpu-line" size="20" /> CPU &amp; Memory
        </VCardTitle>
        <template #append>
          <div class="d-flex align-center ga-2">
            <VBtn
              :color="healthLiveOn ? 'error' : undefined"
              :variant="healthLiveOn ? 'flat' : 'tonal'"
              size="small"
              :prepend-icon="healthLiveOn ? 'ri-stop-circle-line' : 'ri-pulse-line'"
              @click="toggleHealthLive"
            >
              {{ healthLiveOn ? 'Stop' : 'Live' }}
            </VBtn>
            <div v-if="!healthLiveOn" class="switch-toggle">
              <button v-for="r in ['1h', '6h', '24h', '7d']" :key="r" :class="{ on: healthRange === r }" @click="healthRange = r">{{ r }}</button>
            </div>
          </div>
        </template>
      </VCardItem>
      <VCardText>
        <template v-if="healthLiveOn">
          <div class="d-flex align-center ga-3 mb-2 flex-wrap">
            <VChip size="x-small" color="error" variant="flat" label class="live-pulse">● LIVE</VChip>
            <span class="text-caption">CPU <strong :class="healthLiveLatest?.cpu != null ? 'text-info' : 'text-medium-emphasis'">{{ healthLiveLatest?.cpu != null ? `${Math.round(healthLiveLatest.cpu)}%` : '—' }}</strong></span>
            <span class="text-caption">Mem <strong :class="healthLiveLatest?.mem != null ? 'text-info' : 'text-medium-emphasis'">{{ healthLiveLatest?.mem != null ? `${Math.round(healthLiveLatest.mem)}%` : '—' }}</strong></span>
            <span class="text-caption text-medium-emphasis">real-time · every 4s · {{ healthLiveData.length }} reads</span>
          </div>
          <VueApexCharts type="area" height="240" :options="healthLiveOptions" :series="healthLiveSeries" />
        </template>
        <template v-else>
          <VueApexCharts
            v-if="hasHealthHistory"
            type="area"
            height="240"
            :options="healthOptions"
            :series="healthSeries"
          />
          <div v-else class="text-medium-emphasis text-body-2 py-6 text-center">
            No CPU/memory samples in this range yet — health is polled every 5 minutes.
          </div>
        </template>
      </VCardText>
    </VCard>

    <!-- Device detail: identity and live health in one card. These were two
         separate panels saying related things about the same box; an operator
         reading "is this device healthy" had to look in two places. -->
    <VCard id="device-detail" class="mb-5 dev-detail scroll-anchor">
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon icon="ri-server-line" size="20" />
          Device Detail
        </VCardTitle>
        <template
          v-if="auth.isAdmin"
          #append
        >
          <div class="d-flex ga-2">
            <VBtn
              v-if="device?.vendor === 'silverpeak'"
              variant="tonal"
              size="small"
              color="info"
              prepend-icon="ri-share-line"
              @click="openLldp"
            >
              Enable LLDP
            </VBtn>
            <VBtn
              variant="tonal"
              size="small"
              prepend-icon="ri-edit-line"
              @click="openEdit"
            >
              Edit
            </VBtn>
          </div>
        </template>
      </VCardItem>
      <VDivider />

      <VCardText>
        <div class="dev-detail__grid">
          <!-- identity -->
          <section>
            <div class="dev-detail__legend">Identity</div>
            <dl class="dev-detail__dl">
              <dt>Vendor</dt><dd class="text-capitalize">{{ device?.vendor ?? '—' }}</dd>
              <dt>Model</dt><dd>{{ device?.model ?? '—' }}</dd>
              <dt>Serial</dt><dd class="mono">{{ device?.serial_number ?? '—' }}</dd>
              <dt>OS version</dt><dd class="mono">{{ device?.os_version ?? '—' }}</dd>
            </dl>
          </section>

          <!-- placement -->
          <section>
            <div class="dev-detail__legend">Placement</div>
            <dl class="dev-detail__dl">
              <dt>Site</dt><dd>{{ site?.name ?? '—' }}</dd>
              <dt>Role</dt><dd class="text-capitalize">{{ device?.role ?? '—' }}</dd>
              <dt>IP address</dt><dd class="mono"><CopyBtn v-if="device?.ip_address" :text="device.ip_address" /><span v-else>—</span></dd>
              <dt>Admin status</dt>
              <dd>
                <VChip
                  size="x-small"
                  label
                  :color="device?.status === 'active' ? 'success' : 'default'"
                  variant="tonal"
                >{{ device?.status ?? '—' }}</VChip>
              </dd>
            </dl>
          </section>

          <!-- live health -->
          <section class="dev-detail__health">
            <div class="dev-detail__legend">
              Health
              <span v-if="!health" class="text-disabled font-weight-regular"> · no SNMP reading yet</span>
            </div>

            <div class="dev-detail__meters">
              <div>
                <div class="d-flex justify-space-between align-center mb-1">
                  <span class="text-caption text-medium-emphasis">CPU</span>
                  <span class="text-body-2 mono" :class="healthColor(health?.cpu_pct)">
                    {{ health?.cpu_pct ?? '—' }}<span v-if="health?.cpu_pct != null">%</span>
                  </span>
                </div>
                <VProgressLinear
                  :model-value="health?.cpu_pct ?? 0"
                  :color="meterColor(health?.cpu_pct)"
                  height="4"
                  rounded
                />
              </div>

              <div>
                <div class="d-flex justify-space-between align-center mb-1">
                  <span class="text-caption text-medium-emphasis">
                    Memory
                    <VIcon
                      v-if="edgeMem"
                      icon="ri-information-line"
                      size="13"
                      :title="edgeMemHint"
                    />
                  </span>
                  <span class="text-body-2 mono" :class="healthColor(health?.mem_pct)">
                    {{ health?.mem_pct ?? '—' }}<span v-if="health?.mem_pct != null">%</span>
                  </span>
                </div>
                <VProgressLinear
                  :model-value="health?.mem_pct ?? 0"
                  :color="meterColor(health?.mem_pct)"
                  height="4"
                  rounded
                />
              </div>

              <div class="dev-detail__pair">
                <div>
                  <div class="text-caption text-medium-emphasis">Temperature</div>
                  <div class="text-body-1 mono">
                    {{ health?.temperature_c != null ? `${health.temperature_c}°C` : '—' }}
                  </div>
                </div>
                <div>
                  <div class="text-caption text-medium-emphasis">Uptime</div>
                  <div class="text-body-1 mono">{{ formatUptime(health?.uptime_seconds) }}</div>
                </div>
              </div>
            </div>
          </section>
        </div>

        <div v-if="sensors.length" class="dev-detail__legend mt-5 mb-2">Environment sensors</div>
        <VTable v-if="sensors.length" density="compact" class="mt-4">
          <thead>
            <tr><th>Sensor</th><th>Type</th><th class="text-right">Reading</th><th>Status</th></tr>
          </thead>
          <tbody>
            <tr v-for="s in sensors" :key="s.id">
              <td>{{ s.name }}</td>
              <td>{{ s.sensor_type }}</td>
              <td class="text-right">{{ s.value ?? '—' }} {{ s.unit ?? '' }}</td>
              <td>
                <VChip :color="s.status === 'ok' ? 'success' : 'error'" size="x-small" label>{{ s.status }}</VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!-- Virtual Chassis: the physical member switches behind one management IP. A
         Juniper VC is one managed device but several switches, each with its own
         serial; a member that drops out of the VC table reads Offline here. -->
    <VCard
      v-if="vcMembers.length"
      id="virtual-chassis"
      class="mb-5 scroll-anchor"
    >
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon icon="ri-stack-line" size="20" />
          Virtual Chassis
          <VChip size="small" variant="tonal" class="ms-1">{{ vcMembers.length }} members</VChip>
          <VChip v-if="vcDegraded" size="small" color="error" variant="tonal" label prepend-icon="ri-error-warning-line">
            member offline
          </VChip>
        </VCardTitle>
      </VCardItem>
      <VCardText class="pt-0">
        <div class="text-body-2 text-medium-emphasis mb-3">
          One managed device on <span class="mono">{{ device?.ip_address }}</span> — physically {{ vcMembers.length }} switches, each with its own serial.
        </div>
        <VTable density="compact" class="text-no-wrap">
          <thead>
            <tr>
              <th>Member</th><th>Role</th><th>Model</th><th>Serial</th><th>Version</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in vcMembers" :key="m.member_id">
              <td class="font-weight-medium">FPC {{ m.member_id }}</td>
              <td>
                <VChip
                  size="x-small" label variant="tonal" class="text-capitalize"
                  :color="m.role === 'master' ? 'primary' : m.role === 'backup' ? 'info' : 'secondary'"
                >
                  {{ m.role ?? '—' }}
                </VChip>
              </td>
              <td>{{ m.model ?? '—' }}</td>
              <td><span class="mono">{{ m.serial_number ?? '—' }}</span></td>
              <td><span class="mono">{{ m.sw_version ?? '—' }}</span></td>
              <td>
                <VChip size="x-small" label variant="tonal" :color="m.status === 'missing' ? 'error' : 'success'">
                  {{ m.status === 'missing' ? 'Offline' : 'Online' }}
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!-- Connected endpoints (LLDP). Identity is grouped rather than spread across
         sparse columns: an AP advertises a MAC and no extension, a handset the
         reverse, so one column per attribute leaves most cells empty. -->
    <VCard
      id="endpoints"
      v-if="sortedNeighbors.length || canPullLldp"
      class="mb-5 endpoints scroll-anchor"
    >
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon icon="ri-plug-line" size="20" />
          Connected Endpoints
          <VChip v-if="sortedNeighbors.length" size="x-small" variant="tonal" class="ms-1">{{ liveNeighborCount }}</VChip>
          <VChip v-if="goneNeighborCount" size="x-small" variant="tonal" color="warning" class="ms-1">
            {{ goneNeighborCount }} disconnected
          </VChip>
          <VBtn
            v-if="auth.canAct && canPullLldp"
            size="small"
            variant="tonal"
            prepend-icon="ri-refresh-line"
            :loading="refreshingLldp"
            class="ms-auto"
            @click="refreshNeighbors"
          >
            Pull latest
          </VBtn>
        </VCardTitle>
        <VCardSubtitle class="mt-1">
          Advertised over LLDP. An endpoint that does not speak LLDP will not appear here.
          Disconnected endpoints stay listed for 90 days so a down port can still be traced.
        </VCardSubtitle>
      </VCardItem>
      <VDivider />

      <VCardText v-if="!sortedNeighbors.length" class="text-medium-emphasis">
        No LLDP neighbors discovered yet. Click <strong>Pull latest</strong> to read them from the device now
        (otherwise they appear after the next discovery sweep).
      </VCardText>

      <VTable v-else density="compact" class="endpoints__table">
        <thead>
          <tr>
            <th style="width: 8.5rem;">Port</th>
            <th>Endpoint</th>
            <th style="width: 13rem;">Address</th>
            <th style="width: 9rem;">Remote port</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="n in sortedNeighbors" :key="n.id" :class="{ 'endpoints__row--gone': n.absent_since }">
            <td>
              <span class="mono endpoints__port">{{ n.local_port ?? '—' }}</span>
            </td>

            <td>
              <div class="d-flex align-center ga-3">
                <VAvatar size="28" rounded variant="tonal" :color="endpointColor(n.neighbor_type)">
                  <VIcon :icon="endpointIcon[n.neighbor_type ?? ''] ?? 'ri-question-line'" size="16" />
                </VAvatar>
                <div class="endpoints__ident">
                  <div class="d-flex align-center ga-2 flex-wrap">
                    <RouterLink
                      v-if="n.remote_device_id"
                      :to="`/devices/${n.remote_device_id}`"
                      class="font-weight-medium text-primary endpoint-link d-inline-flex align-center ga-1"
                      :title="`Open ${n.remote_sysname ?? 'device'}`"
                    >
                      {{ n.endpoint_model ?? n.remote_sysname ?? 'Unknown' }}
                      <VIcon icon="ri-arrow-right-up-line" size="14" />
                    </RouterLink>
                    <span v-else class="font-weight-medium">
                      {{ n.endpoint_model ?? n.remote_sysname ?? 'Unknown' }}
                    </span>

                    <VChip v-if="n.extension" size="x-small" color="info" variant="tonal" class="mono">
                      ext {{ n.extension }}
                    </VChip>
                    <VChip v-if="n.remote_device_id" size="x-small" variant="tonal" color="success">
                      managed
                    </VChip>
                    <VChip
                      v-if="n.absent_since"
                      size="x-small"
                      variant="tonal"
                      color="warning"
                      :title="`Last reported ${formatDateTime(n.last_seen_at)}`"
                    >
                      gone {{ since(n.absent_since) }}
                    </VChip>
                  </div>
                  <div v-if="n.endpoint_model && n.remote_sysname" class="text-caption text-disabled mono">
                    {{ n.remote_sysname }}
                  </div>
                </div>
              </div>
            </td>

            <td>
              <div v-if="n.remote_mgmt_addr" class="mono text-body-2">{{ n.remote_mgmt_addr }}</div>
              <div v-if="n.remote_mac" class="mono text-caption text-medium-emphasis">{{ n.remote_mac }}</div>
              <span v-if="!n.remote_mgmt_addr && !n.remote_mac" class="text-disabled">not advertised</span>
            </td>

            <td class="mono text-medium-emphasis">{{ n.remote_port ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>


    <!-- Interface alarms: down ports (a switch's alarms — no SNMP alarm table). -->
    <VCard
      id="interface-alarms"
      v-if="alarmedInterfaces.length > 0"
      class="mb-5 scroll-anchor"
    >
      <VCardItem>
        <VCardTitle>Interface Alarms</VCardTitle>
        <template #append>
          <VChip
            size="small"
            color="warning"
            variant="tonal"
          >
            {{ alarmedInterfaces.length }} down
          </VChip>
        </template>
      </VCardItem>
      <VCardText>
        <VTable density="compact">
          <thead>
            <tr>
              <th>Ticket</th>
              <th>Interface</th>
              <th>Last endpoint</th>
              <th>Down since</th>
              <th>Acknowledged</th>
              <th class="text-right">
                Actions
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="i in alarmedInterfaces"
              :key="i.id"
            >
              <td class="mono">
                {{ i.alerts?.[0]?.ticket_number ? `#${i.alerts[0].ticket_number}` : '—' }}
              </td>
              <td class="mono">
                <VIcon
                  icon="ri-alert-line"
                  size="14"
                  color="warning"
                  class="me-1"
                />{{ i.if_name }}
              </td>
              <td>
                <template v-if="lastEndpointOn(i.if_name)">
                  <div class="text-body-2">{{ endpointLabel(lastEndpointOn(i.if_name)!) }}</div>
                  <div class="text-caption text-medium-emphasis mono">
                    {{ [lastEndpointOn(i.if_name)!.remote_mgmt_addr, lastEndpointOn(i.if_name)!.remote_mac].filter(Boolean).join(' · ') || '—' }}
                  </div>
                  <div v-if="lastEndpointOn(i.if_name)!.absent_since" class="text-caption text-disabled">
                    last seen {{ since(lastEndpointOn(i.if_name)!.last_seen_at) }}
                  </div>
                </template>
                <span v-else class="text-disabled text-caption">no LLDP record</span>
              </td>
              <td class="text-caption">
                {{ i.alerts?.[0]?.started_at ? formatDateTime(i.alerts[0].started_at) : '—' }}
              </td>
              <td>
                <VChip
                  v-if="i.alerts?.[0]?.acknowledged_at"
                  size="x-small"
                  color="info"
                  variant="tonal"
                >
                  ack'd
                </VChip>
                <span
                  v-else
                  class="text-disabled"
                >—</span>
              </td>
              <td class="text-right">
                <VBtn
                  v-if="i.alerts?.[0] && !i.alerts[0].acknowledged_at && auth.canAct"
                  size="x-small"
                  variant="tonal"
                  class="me-1"
                  :loading="ifaceAlarmBusy === i.alerts[0].id"
                  @click="ackIfaceAlert(i.alerts[0].id)"
                >
                  Ack
                </VBtn>
                <VBtn
                  v-if="i.alerts?.[0] && auth.canAct"
                  size="x-small"
                  variant="tonal"
                  color="success"
                  class="me-1"
                  :loading="ifaceAlarmBusy === i.alerts[0].id"
                  @click="openIfaceClear(i.alerts[0].id)"
                >
                  Clear
                </VBtn>
                <VBtn
                  v-if="auth.isAdmin"
                  size="x-small"
                  variant="text"
                  :loading="ifaceAlarmBusy === i.id"
                  @click="muteIface(i.id)"
                >
                  Mute
                </VBtn>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!-- Alarm history: active + cleared alarms for this device -->
    <!-- Interface alarm history — clearing an alert must leave a record, not make
         it disappear. The device page loads interfaces with open alerts only, so
         without this the ticket, the operator and the note were all invisible. -->
    <VCard
      id="interface-alarm-history"
      v-if="interfaceHistory.length"
      class="mb-5 scroll-anchor"
    >
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon icon="ri-history-line" size="20" />
          Interface Alarm History
          <VChip size="x-small" variant="tonal" class="ms-1">{{ interfaceHistory.length }}</VChip>
        </VCardTitle>
      </VCardItem>
      <VDivider />
      <VTable density="compact" class="text-no-wrap">
        <thead>
          <tr>
            <th>Ticket</th>
            <th>Port</th>
            <th>Severity</th>
            <th>Raised</th>
            <th>Status</th>
            <th>Cleared by</th>
            <th>Note</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="a in interfaceHistory" :key="a.id">
            <td class="mono">{{ a.ticket_number ?? '—' }}</td>
            <td class="mono">{{ a.device_interface?.if_name ?? '—' }}</td>
            <td>
              <VChip size="x-small" variant="tonal" :color="alarmSeverityColor[a.severity ?? 'warning'] ?? 'warning'">
                {{ a.severity }}
              </VChip>
            </td>
            <td class="text-caption">{{ formatDateTime(a.started_at) }}</td>
            <td>
              <VChip
                size="x-small"
                variant="tonal"
                :color="a.ended_at ? 'success' : 'error'"
              >
                {{ a.ended_at ? (a.cleared_manually ? 'cleared manually' : 'auto-cleared') : 'open' }}
              </VChip>
            </td>
            <td class="text-caption">{{ clearedByName(a) ?? (a.ended_at ? 'system' : '—') }}</td>
            <td class="text-caption text-medium-emphasis">{{ a.clear_note ?? a.ack_note ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>


    <VCard
      id="alarm-history"
      class="mb-5 scroll-anchor"
    >
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon icon="ri-alarm-warning-line" size="20" />
          Alarm History
        </VCardTitle>
        <template #append>
          <div class="d-flex align-center ga-2">
            <VChip size="small" :color="criticalActive > 0 ? 'error' : 'secondary'" variant="tonal" label>
              {{ criticalActive }} critical
            </VChip>
            <VChip size="small" :color="activeAlarms.length > 0 ? 'warning' : 'success'" variant="tonal" label>
              {{ activeAlarms.length }} active
            </VChip>
            <VChip size="small" color="secondary" variant="tonal" label>{{ clearedCount }} cleared</VChip>
          </div>
        </template>
      </VCardItem>

      <VCardText class="pb-0">
        <div class="d-flex flex-wrap align-center ga-2">
          <VTextField
            v-model="alarmSearch"
            placeholder="Search alarms…"
            density="compact"
            variant="solo-filled"
            flat
            prepend-inner-icon="ri-search-line"
            clearable
            hide-details
            style="max-width: 280px"
          />
          <VSpacer />
          <div class="app-filter-chips">
            <VChip
              v-for="s in ['critical', 'warning', 'info']"
              :key="s"
              :color="alarmSeverityColor[s]"
              :variant="alarmSeverityFilter === s ? 'flat' : 'tonal'"
              size="small" label
              class="cursor-pointer text-capitalize"
              @click="alarmSeverityFilter = alarmSeverityFilter === s ? null : s"
            >
              {{ s }}
            </VChip>
            <VDivider vertical class="mx-1" style="height: 20px; align-self: center" />
            <VChip
              :color="alarmStatusFilter === 'active' ? 'error' : undefined"
              :variant="alarmStatusFilter === 'active' ? 'flat' : 'tonal'"
              size="small" label class="cursor-pointer"
              @click="alarmStatusFilter = alarmStatusFilter === 'active' ? null : 'active'"
            >
              Active
            </VChip>
            <VChip
              :color="alarmStatusFilter === 'cleared' ? 'success' : undefined"
              :variant="alarmStatusFilter === 'cleared' ? 'flat' : 'tonal'"
              size="small" label class="cursor-pointer"
              @click="alarmStatusFilter = alarmStatusFilter === 'cleared' ? null : 'cleared'"
            >
              Cleared
            </VChip>
          </div>
        </div>
      </VCardText>

      <VDataTable
        :headers="alarmHistoryHeaders"
        :items="alarmRows"
        :search="alarmSearch"
        density="comfortable"
        hover
        :items-per-page="15"
        :sort-by="[{ key: 'first_seen_at', order: 'desc' }]"
        class="alarm-history-table"
        @click:row="(_: unknown, { item }: { item: DeviceAlarm }) => openAlarm(item)"
      >
        <template #item.severity="{ item }">
          <VChip :color="alarmSeverityColor[item.severity ?? 'warning']" size="small" label class="text-capitalize font-weight-medium">
            {{ item.severity ?? 'warning' }}
          </VChip>
        </template>
        <template #item.ticket_number="{ item }">
          <span class="font-weight-medium">{{ item.ticket_number ? `#${item.ticket_number}` : '—' }}</span>
        </template>
        <template #item.description="{ item }">
          <span class="alarm-desc">{{ item.description }}</span>
        </template>
        <template #item.first_seen_at="{ item }">
          <span class="text-no-wrap text-caption">{{ fmtAlarmDate(item.first_seen_at) }}</span>
        </template>
        <template #item.duration="{ item }">
          <span class="text-no-wrap" :class="item.cleared_at ? 'text-medium-emphasis' : 'text-warning font-weight-medium'">{{ item.duration }}</span>
        </template>
        <template #item.cleared_at="{ item }">
          <div v-if="item.cleared_at === null" class="d-flex align-center ga-1 text-error font-weight-medium">
            <span class="dot" style="background: rgb(var(--v-theme-error))" /> Active
          </div>
          <div v-else class="d-flex align-center ga-1 flex-wrap">
            <span class="text-no-wrap text-caption text-medium-emphasis">{{ fmtAlarmDate(item.cleared_at) }}</span>
            <VChip size="x-small" :color="item.cleared_kind === 'manual' ? 'info' : 'secondary'" label variant="tonal">
              {{ item.cleared_kind === 'manual' ? 'manual' : 'auto' }}
            </VChip>
          </div>
        </template>
        <template #no-data>
          <div class="py-6 text-center text-medium-emphasis">No alarms match this filter.</div>
        </template>
      </VDataTable>
    </VCard>

    <VRow id="interfaces" class="mb-1 scroll-anchor">
      <!-- Interfaces -->
      <VCol cols="12">
        <VCard>
          <VCardItem>
            <VCardTitle class="d-flex align-center ga-2">
              <VIcon icon="ri-exchange-line" size="20" />
              Interfaces
            </VCardTitle>
            <template #append>
              <VChip size="small" variant="tonal">
                {{ interfaces.length }}
              </VChip>
            </template>
          </VCardItem>
          <!-- Device-wide interface KPIs — triage the whole device at a glance. -->
          <div class="if-kpis">
            <div class="if-kpi">
              <div class="if-kpi__l">Ports</div>
              <div class="if-kpi__v">{{ ifKpis.up }}<small>up</small> <span class="text-medium-emphasis">/ {{ ifKpis.down }} dn</span></div>
            </div>
            <div class="if-kpi">
              <div class="if-kpi__l">Throughput</div>
              <div class="if-kpi__v"><VIcon icon="ri-arrow-down-line" size="15" class="text-info" />{{ formatBps(ifKpis.inBps) }} <VIcon icon="ri-arrow-up-line" size="15" class="text-success" />{{ formatBps(ifKpis.outBps) }}</div>
            </div>
            <div class="if-kpi">
              <div class="if-kpi__l">Busiest</div>
              <div class="if-kpi__v if-kpi__v--sm">{{ ifKpis.busiest ? ifKpis.busiest.if_name : '—' }} <small v-if="ifKpis.busiest">{{ ifKpis.busiest.peak_util_pct }}%</small></div>
            </div>
            <div class="if-kpi">
              <div class="if-kpi__l">Errors</div>
              <div class="if-kpi__v" :class="ifKpis.errors ? 'text-error' : 'text-medium-emphasis'">{{ ifKpis.errors }}</div>
            </div>
            <div class="if-kpi">
              <div class="if-kpi__l">Flapping</div>
              <div class="if-kpi__v" :class="ifKpis.flapping ? 'text-purple' : 'text-medium-emphasis'">{{ ifKpis.flapping }}</div>
            </div>
          </div>
          <VCardText class="pb-0 pt-3">
            <VTextField
              v-model="interfaceSearch"
              label="Filter interfaces"
              density="compact"
              append-inner-icon="ri-search-line"
              clearable
              hide-details
            />
          </VCardText>
          <VDataTable
            v-model:page="interfacePage"
            :headers="interfaceHeaders"
            :items="interfaces"
            :search="interfaceSearch"
            :loading="isLoading"
            density="compact"
            hover
            :items-per-page="15"
            class="interface-table"
            @click:row="(_: unknown, { item }: { item: DeviceInterface }) => openInterface(item)"
          >
            <template #item.if_name="{ item }">
              <span class="if-name">{{ item.if_name }}</span>
            </template>
            <template #item.mac_addresses_count="{ item }">
              <VChip
                v-if="(item.mac_addresses_count ?? 0) > 0"
                size="x-small"
                variant="tonal"
                color="info"
                prepend-icon="ri-fingerprint-line"
              >
                {{ item.mac_addresses_count }}
              </VChip>
              <span v-else class="text-disabled">—</span>
            </template>
            <template #item.health="{ item }">
              <VChip
                size="x-small" label
                :color="ifHealth(item).color"
                :variant="item.health_attention ? 'flat' : 'tonal'"
                class="if-health"
                :prepend-icon="ifHealth(item).icon"
              >
                {{ ifHealth(item).label }}
              </VChip>
            </template>
            <template #item.spark="{ item }">
              <svg v-if="sparkPoints(sparklines[item.id]?.in)" class="if-spark" width="88" height="22" viewBox="0 0 88 22">
                <polyline fill="none" stroke="#22c55e" stroke-width="1.4" :points="sparkPoints(sparklines[item.id]?.in)" />
                <polyline v-if="sparkPoints(sparklines[item.id]?.out)" fill="none" stroke="#3b82f6" stroke-width="1.4" opacity="0.75" :points="sparkPoints(sparklines[item.id]?.out)" />
              </svg>
              <span v-else class="text-disabled if-spark-empty">—</span>
            </template>
            <template #item.utilization="{ item }">
              <div v-if="item.speed_bps > 0" class="if-util">
                <div class="if-util__row">
                  <span class="if-num"><VIcon icon="ri-arrow-down-line" size="12" />{{ item.in_util_pct }}% <VIcon icon="ri-arrow-up-line" size="12" />{{ item.out_util_pct }}%</span>
                  <span class="text-medium-emphasis if-util__speed">{{ ifSpeedLabel(item.speed_bps) }}</span>
                </div>
                <VProgressLinear
                  :model-value="Math.max(item.in_util_pct, item.out_util_pct)"
                  :color="Math.max(item.in_util_pct, item.out_util_pct) >= 90 ? 'error' : Math.max(item.in_util_pct, item.out_util_pct) >= 75 ? 'warning' : 'primary'"
                  height="4"
                  rounded
                />
              </div>
              <span v-else class="text-medium-emphasis">—</span>
            </template>
            <template #item.throughput="{ item }">
              <span v-if="item.speed_bps > 0" class="if-num if-thru">
                <span class="text-info"><VIcon icon="ri-arrow-down-line" size="12" />{{ formatBps(ifThroughput(item).in) }}</span>
                <span class="text-success"><VIcon icon="ri-arrow-up-line" size="12" />{{ formatBps(ifThroughput(item).out) }}</span>
              </span>
              <span v-else class="text-disabled">—</span>
            </template>
            <template #item.peak_util_pct="{ item }">
              <span v-if="item.speed_bps > 0" class="if-num" :class="(item.peak_util_pct ?? 0) >= 90 ? 'text-error font-weight-medium' : (item.peak_util_pct ?? 0) >= 75 ? 'text-warning' : 'text-medium-emphasis'">{{ item.peak_util_pct ?? 0 }}%</span>
              <span v-else class="text-disabled">—</span>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <!-- Active VLANs -->
      <VCol cols="12">
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle class="d-flex align-center ga-2">
              <VIcon icon="ri-price-tag-3-line" size="20" />
              Active VLANs
            </VCardTitle>
            <template #append>
              <VChip size="small" variant="tonal">
                {{ activeVlans.length }}
              </VChip>
            </template>
          </VCardItem>
          <VCardText>
            <div
              v-if="!isLoading && activeVlans.length === 0"
              class="text-medium-emphasis text-body-2"
            >
              No active VLANs discovered. VLANs are collected over SNMP from switches that expose the Q-BRIDGE table.
            </div>
            <div class="vlan-grid">
              <div
                v-for="vlan in activeVlans"
                :key="vlan.id"
                class="vlan-tag"
              >
                <span class="vlan-tag__id">{{ vlanTag(vlan) }}</span>
                <span v-if="vlanName(vlan)" class="vlan-tag__name">{{ vlanName(vlan) }}</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

        <!-- Vulnerabilities: passive CVE correlation against this device's firmware. Kept last — informational, not live-ops. -->
        <VCard id="vulnerabilities" class="mb-5 scroll-anchor">
          <VCardItem>
            <VCardTitle class="d-flex align-center ga-2">
              <VIcon icon="ri-shield-keyhole-line" size="20" />
              Vulnerabilities
              <VChip v-if="deviceVulns.length" size="small" color="error" label class="ms-1">{{ deviceVulns.length }}</VChip>
            </VCardTitle>
            <template #append>
              <VBtn size="small" variant="text" append-icon="ri-arrow-right-line" :to="'/vulnerabilities'">All findings</VBtn>
            </template>
          </VCardItem>
          <VCardText>
            <VAlert v-if="!vulnAssessable" type="info" variant="tonal" density="compact">
              No firmware version has been polled for this device yet, so it can’t be assessed. It will appear once <code>health:monitor</code> reads its OS version.
            </VAlert>
            <div v-else-if="!deviceVulns.length" class="d-flex align-center ga-2 text-success">
              <VIcon icon="ri-shield-check-line" /> No known CVEs match this firmware.
            </div>
            <VTable v-else density="compact" class="text-no-wrap">
              <thead>
                <tr>
                  <th>Severity</th><th>CVSS</th><th>CVE</th><th>Details</th><th>Matched</th><th />
                </tr>
              </thead>
              <tbody>
                <tr v-for="v in deviceVulns" :key="v.id">
                  <td><VChip :color="VULN_SEV_COLOR[v.severity] ?? 'secondary'" size="x-small" label class="text-capitalize">{{ v.severity }}</VChip></td>
                  <td class="font-weight-bold" :class="`text-${VULN_SEV_COLOR[v.severity] ?? 'secondary'}`">{{ v.cvss_score == null ? '—' : Number(v.cvss_score).toFixed(1) }}</td>
                  <td><a :href="v.reference_url ?? '#'" target="_blank" rel="noopener"><code>{{ v.cve_id }}</code></a></td>
                  <td class="text-wrap" style="max-inline-size: 420px">{{ v.summary }}</td>
                  <td><VChip size="x-small" variant="tonal" label>{{ v.matched_constraint }}</VChip></td>
                  <td><VChip v-if="v.state === 'acknowledged'" size="x-small" color="secondary" label>ack’d</VChip></td>
                </tr>
              </tbody>
            </VTable>
          </VCardText>
        </VCard>
      </div>
    </div>

    <!-- Interface detail: traffic + up/down history -->
    <VDialog v-model="isInterfaceOpen" max-width="900" scrollable>
      <VCard v-if="selectedInterface">
        <VCardItem>
          <VCardTitle>{{ selectedInterface.if_name }}</VCardTitle>
          <template #append>
            <div class="d-flex align-center ga-3">
              <VChip :color="ifHealth(selectedInterface).color" size="small" label :prepend-icon="ifHealth(selectedInterface).icon">
                {{ ifHealth(selectedInterface).label }}
              </VChip>
              <div class="switch-toggle">
                <button v-for="r in ['1h', '6h', '24h', '7d']" :key="r" :class="{ on: interfaceRange === r }" @click="interfaceRange = r">{{ r }}</button>
              </div>
            </div>
          </template>
        </VCardItem>

        <VCardText>
          <!-- current stats -->
          <VRow class="mb-2">
            <VCol cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Link speed / duplex</div>
              <div class="text-h6">
                {{ ifSpeedLabel(selectedInterface.speed_bps) }}<span
                  v-if="selectedInterface.duplex && selectedInterface.duplex !== 'unknown'"
                  class="text-body-2"
                  :class="selectedInterface.duplex === 'half' ? 'text-warning' : 'text-medium-emphasis'"
                > · {{ selectedInterface.duplex }}</span>
              </div>
              <div v-if="linkChangeNote" class="text-caption text-warning mt-1"><VIcon icon="ri-error-warning-line" size="16" class="me-1" />{{ linkChangeNote }}</div>
            </VCol>
            <VCol cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Utilization</div>
              <div class="text-h6">↓{{ selectedInterface.in_util_pct }}% ↑{{ selectedInterface.out_util_pct }}%</div>
            </VCol>
            <VCol cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Discards Δ (in/out)</div>
              <div class="text-h6">{{ selectedInterface.in_discards_delta }} / {{ selectedInterface.out_discards_delta }}</div>
            </VCol>
            <VCol cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Total traffic (in/out)</div>
              <div class="text-body-1">{{ formatBytes(selectedInterface.in_octets) }} / {{ formatBytes(selectedInterface.out_octets) }}</div>
            </VCol>
          </VRow>

          <!-- proactive health + actions -->
          <div class="if-actions">
            <div class="if-actions__head">
              <VChip :color="ifHealth(selectedInterface).color" size="small" label :prepend-icon="ifHealth(selectedInterface).icon">
                {{ ifHealth(selectedInterface).label }}
              </VChip>
              <span class="if-actions__detail text-medium-emphasis">
                <template v-if="selectedInterface.health === 'errors'">{{ (selectedInterface.errors_recent ?? 0).toLocaleString() }} interface errors in 24h — check cabling / SFP / duplex.</template>
                <template v-else-if="selectedInterface.health === 'congested'">{{ (selectedInterface.discards_recent ?? 0).toLocaleString() }} discards in 24h — the link is congested.</template>
                <template v-else-if="selectedInterface.health === 'flapping'">Flapping — {{ selectedInterface.flap_count ?? 0 }} state changes; last {{ selectedInterface.last_flap_at ? since(selectedInterface.last_flap_at) : '—' }} ago.</template>
                <template v-else-if="selectedInterface.health === 'down'">Interface is operationally down<span v-if="selectedOpenAlert?.ticket_number"> · #{{ selectedOpenAlert.ticket_number }}</span>.</template>
                <template v-else-if="selectedInterface.health === 'admin_down'">Administratively disabled on the device.</template>
                <template v-else-if="selectedInterface.health === 'muted'">Muted — alarms suppressed for this port.</template>
                <template v-else>No faults in the last 24h.</template>
              </span>
              <span v-if="selectedInterface.health_ack_at" class="if-actions__ack text-disabled">acknowledged{{ selectedInterface.health_ack_by ? ` by ${selectedInterface.health_ack_by}` : '' }}</span>
            </div>

            <div v-if="auth.canAct" class="if-actions__btns">
              <VBtn v-if="selectedOpenAlert && !selectedOpenAlert.acknowledged_at" size="small" variant="tonal" color="info" prepend-icon="ri-check-line" :loading="ifaceActionBusy" @click="ackIfaceAlert(selectedOpenAlert.id)">Acknowledge</VBtn>
              <VBtn v-if="selectedOpenAlert" size="small" variant="tonal" color="success" prepend-icon="ri-checkbox-circle-line" :loading="ifaceActionBusy" @click="openIfaceClear(selectedOpenAlert.id)">Clear alarm</VBtn>
              <VBtn v-if="['errors', 'congested', 'flapping'].includes(selectedInterface.health ?? '')" size="small" variant="tonal" color="primary" prepend-icon="ri-eye-line" :loading="ifaceActionBusy" @click="ackHealthIface">Acknowledge</VBtn>
              <VBtn v-if="auth.isAdmin" size="small" variant="tonal" :color="selectedInterface.alarm_suppressed ? 'secondary' : 'warning'" :prepend-icon="selectedInterface.alarm_suppressed ? 'ri-volume-up-line' : 'ri-volume-mute-line'" :loading="ifaceActionBusy" @click="toggleMuteIface">{{ selectedInterface.alarm_suppressed ? 'Un-mute' : 'Mute' }}</VBtn>
              <VBtn v-if="canTdr" size="small" variant="tonal" color="info" prepend-icon="ri-pulse-line" :loading="tdrRunning" @click="runTdr">Test cable (TDR)</VBtn>
            </div>

            <div class="if-actions__note">
              <VTextarea
                v-model="ifaceNote"
                :readonly="!auth.canAct"
                label="Operator note"
                placeholder="e.g. Known bad SFP — RMA #12345 ordered 2026-07-31"
                rows="2"
                auto-grow
                density="compact"
                hide-details
                maxlength="500"
              />
              <div v-if="auth.canAct" class="if-actions__note-save">
                <span v-if="selectedInterface.note_at" class="text-disabled text-caption">last edited {{ since(selectedInterface.note_at) }} ago{{ selectedInterface.note_by ? ` by ${selectedInterface.note_by}` : '' }}</span>
                <VBtn size="small" variant="text" :loading="ifaceActionBusy" @click="saveIfaceNote">Save note</VBtn>
              </div>
            </div>
          </div>

          <!-- traffic chart -->
          <div class="if-chart-head">
            <div class="switch-toggle">
              <button
                v-for="o in chartMetricOptions"
                :key="o.value"
                :class="{ on: chartMetric === o.value }"
                @click="chartMetric = o.value"
              >
                <VIcon :icon="o.icon" />{{ o.label }}
              </button>
            </div>
            <span v-if="interfaceMetrics.length && chartMetric === 'traffic'" class="text-caption text-medium-emphasis if-chart-totals">
              {{ formatBytes(ifTrafficTotals.inB) }} in / {{ formatBytes(ifTrafficTotals.outB) }} out · peak {{ formatBps(ifTrafficTotals.peakBps) }}bps
            </span>
          </div>
          <VueApexCharts
            v-if="!ifChartEmpty"
            type="area"
            height="240"
            :options="ifTrafficOptions"
            :series="ifTrafficSeries"
          />
          <div v-else class="text-medium-emphasis text-body-2 py-6 text-center">
            No samples in this range yet.
          </div>

          <!-- up/down history -->
          <div class="text-subtitle-2 mt-4 mb-2">Up / Down history</div>
          <div v-if="!interfaceAlerts.length" class="text-medium-emphasis text-body-2">
            No down events recorded — interface has been stable.
          </div>
          <VTable v-else density="compact">
            <thead>
              <tr><th>Went down</th><th>Recovered</th><th>Duration</th><th>Status</th></tr>
            </thead>
            <tbody>
              <tr v-for="a in interfaceAlerts" :key="a.id">
                <td>{{ formatDateTime(a.started_at) }}</td>
                <td>{{ a.ended_at ? formatDateTime(a.ended_at) : '—' }}</td>
                <td>{{ alertDuration(a) }}</td>
                <td>
                  <VChip :color="a.ended_at ? 'success' : 'error'" size="x-small" label>
                    {{ a.ended_at ? 'recovered' : 'down' }}
                  </VChip>
                </td>
              </tr>
            </tbody>
          </VTable>

          <!-- learned MACs (OUI) — retained after aging, so a down port still lists them -->
          <div class="text-subtitle-2 mt-4 mb-2">Learned MACs</div>
          <div v-if="!interfaceMacs.length" class="text-medium-emphasis text-body-2">
            No MACs learned on this port within retention.
          </div>
          <VTable v-else density="compact">
            <thead>
              <tr><th>MAC</th><th>Vendor (OUI)</th><th>VLAN</th><th>First seen</th><th>Last seen</th></tr>
            </thead>
            <tbody>
              <tr v-for="m in interfaceMacs" :key="m.mac">
                <td><CopyBtn :text="m.mac" class="font-mono" /></td>
                <td>{{ m.oui_vendor ?? '—' }}</td>
                <td>{{ m.vlan }}</td>
                <td>{{ formatDateTime(m.first_seen_at) }}</td>
                <td>{{ formatDateTime(m.last_seen_at) }}</td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- TDR cable-test result -->
    <VDialog v-model="tdrOpen" max-width="640" scrollable>
      <VCard>
        <VCardItem>
          <VCardTitle class="d-flex align-center ga-2">
            <VIcon icon="ri-pulse-line" size="20" /> Cable test (TDR) · <span class="if-name">{{ tdrResult?.target }}</span>
          </VCardTitle>
        </VCardItem>
        <VCardText>
          <div v-if="tdrRunning" class="text-center py-8">
            <VProgressCircular indeterminate color="primary" />
            <div class="text-medium-emphasis mt-3">Running TDR on the port — a few seconds…</div>
          </div>
          <pre v-else class="tool-output">{{ tdrResult?.output }}</pre>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn @click="tdrOpen = false">Close</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Inline device edit -->
    <VDialog
      v-model="isEditOpen"
      max-width="620"
    >
      <VCard title="Edit Device">
        <VCardText>
          <VAlert
            v-if="editError"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ editError }}
          </VAlert>
          <VForm @submit.prevent="saveEdit">
            <VRow>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.name"
                  label="Name"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="editForm.ip_address"
                  label="IP Address"
                />
              </VCol>
              <VCol cols="6" sm="6">
                <VSelect
                  v-model="editForm.vendor"
                  :items="vendorOptions"
                  label="Vendor"
                />
              </VCol>
              <VCol cols="6" sm="6">
                <VTextField
                  v-model="editForm.model"
                  label="Model"
                />
              </VCol>
              <VCol cols="6" sm="4">
                <VSelect
                  v-model="editForm.role"
                  :items="roleOptions"
                  label="Role"
                />
              </VCol>
              <VCol cols="6" sm="4">
                <VSelect
                  v-model="editForm.status"
                  :items="statusOptions"
                  label="Admin Status"
                />
              </VCol>
              <VCol
                v-if="editForm.role === 'edgeconnect'"
                cols="12"
                sm="6"
              >
                <VTextField
                  v-model="editForm.next_hop_ip"
                  label="Next-hop IP (SD-WAN gateway)"
                  placeholder="e.g. 99.12.40.9"
                  hint="Set this so the topology shows the next-hop node + monitors reachability"
                  persistent-hint
                />
              </VCol>
              <VCol cols="12" sm="4">
                <VSelect
                  v-model="editForm.site_id"
                  :items="sitesList.map(s => ({ title: s.name, value: s.id }))"
                  label="Site"
                  clearable
                />
              </VCol>
              <VCol cols="12">
                <VSelect
                  v-model="editForm.ssh_credential_id"
                  :items="sshCredentials"
                  item-title="name"
                  item-value="id"
                  label="SSH Credential (shared profile)"
                  clearable
                  hint="Assign a shared SSH credential so this device can be verified / backed up over SSH. Works for any role — switches, firewalls, EdgeConnect."
                  persistent-hint
                />
              </VCol>
            </VRow>
            <VBtn
              type="submit"
              class="mt-4"
              :loading="isSavingEdit"
            >
              Save
            </VBtn>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Enable LLDP on Silver Peak LAN interfaces -->
    <VDialog
      v-model="isLldpOpen"
      max-width="480"
    >
      <VCard title="Enable LLDP">
        <VCardText>
          <div class="text-body-2 text-medium-emphasis mb-3">
            Sends <code>conf t → int &lt;intf&gt; lldp enable → exit</code> over SSH to
            {{ device?.name }}. Pick the LAN interfaces connected to the switch (not every
            appliance has lan1).
          </div>
          <VCombobox
            v-model="lldpInterfaces"
            label="LAN interfaces"
            multiple
            chips
            closable-chips
            hint="e.g. lan0, lan1 — type and press enter to add"
            persistent-hint
          />
          <VAlert
            v-if="lldpMsg"
            type="success"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ lldpMsg }}
          </VAlert>
          <VAlert
            v-if="lldpError"
            type="error"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ lldpError }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn @click="isLldpOpen = false">
            Close
          </VBtn>
          <VBtn
            color="primary"
            variant="flat"
            :loading="lldpBusy"
            :disabled="lldpInterfaces.length === 0"
            @click="enableLldp"
          >
            Enable LLDP
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Alarm detail + acknowledge / clear -->
    <VDialog
      v-model="isAlarmOpen"
      max-width="480"
    >
      <VCard v-if="selectedAlarm">
        <VCardItem>
          <VCardTitle>{{ selectedAlarm.ticket_number ? `#${selectedAlarm.ticket_number}` : 'Alarm' }}</VCardTitle>
          <template #append>
            <VChip
              size="small"
              :color="alarmSeverityColor[selectedAlarm.severity ?? 'warning']"
              variant="tonal"
            >
              {{ selectedAlarm.severity ?? 'warning' }}
            </VChip>
          </template>
        </VCardItem>
        <VCardText>
          <div class="d-flex flex-column ga-3">
            <div>
              <div class="text-caption text-medium-emphasis">Description</div>
              <div>{{ selectedAlarm.description }}</div>
            </div>
            <div class="alarm-detail-grid">
              <div>
                <div class="text-caption text-medium-emphasis">Event ID (vendor)</div>
                <div class="text-break">{{ selectedAlarm.alarm_id }}</div>
              </div>
              <div>
                <div class="text-caption text-medium-emphasis">Raised</div>
                <div>{{ formatDateTime(selectedAlarm.first_seen_at) }}</div>
              </div>
              <div>
                <div class="text-caption text-medium-emphasis">Status</div>
                <div :class="selectedAlarm.cleared_at ? '' : 'text-error font-weight-medium'">
                  {{ selectedAlarm.cleared_at ? `Cleared ${formatDateTime(selectedAlarm.cleared_at)}` : 'Active' }}
                </div>
              </div>
              <div v-if="selectedAlarm.acknowledged_at">
                <div class="text-caption text-medium-emphasis">Acknowledged</div>
                <div>{{ selectedAlarm.acknowledged_by_name ?? 'yes' }}</div>
              </div>
            </div>
            <VAlert
              v-if="selectedAlarm.ack_note || selectedAlarm.clear_note"
              type="info"
              variant="tonal"
              density="compact"
            >
              <div v-if="selectedAlarm.ack_note" class="text-body-2">
                <strong>Ack:</strong> {{ selectedAlarm.ack_note }}
              </div>
              <div v-if="selectedAlarm.clear_note" class="text-body-2">
                <strong>Clear:</strong> {{ selectedAlarm.clear_note }}
              </div>
            </VAlert>
            <VTextarea
              v-if="selectedAlarm.cleared_at === null"
              v-model="alarmNote"
              label="Investigation / resolution note (optional)"
              rows="2"
              auto-grow
              hide-details
              density="comfortable"
            />
          </div>
        </VCardText>
        <VCardActions v-if="selectedAlarm.cleared_at === null">
          <VBtn
            v-if="auth.canAct"
            :loading="alarmBusy"
            variant="tonal"
            @click="alarmAction('acknowledge')"
          >
            {{ selectedAlarm.acknowledged_at ? 'Save note' : 'Acknowledge' }}
          </VBtn>
          <VBtn
            v-if="auth.canAct"
            :loading="alarmBusy"
            color="success"
            variant="flat"
            @click="alarmAction('clear')"
          >
            Clear
          </VBtn>
          <VSpacer />
          <VBtn @click="isAlarmOpen = false">
            Close
          </VBtn>
        </VCardActions>
        <VCardActions v-else>
          <VSpacer />
          <VBtn @click="isAlarmOpen = false">
            Close
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="ifaceClear.open"
      max-width="440"
    >
      <VCard title="Clear interface alarm">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">
            Won't reopen until the port flaps (goes up, then down again).
          </p>
          <VTextarea
            v-model="ifaceClear.note"
            label="Resolution note (optional)"
            rows="2"
            auto-grow
          />
          <div class="d-flex justify-end ga-2 mt-3">
            <VBtn
              variant="text"
              @click="ifaceClear.open = false"
            >
              Cancel
            </VBtn>
            <VBtn
              color="success"
              :loading="ifaceAlarmBusy === ifaceClear.alertId"
              @click="submitIfaceClear"
            >
              Clear alarm
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.live-pulse { animation: live-pulse 1.4s ease-in-out infinite; }
@keyframes live-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .45; } }
@media (prefers-reduced-motion: reduce) { .live-pulse { animation: none; } }

/* Section index layout — a sticky right-rail "on this device" nav beside the
   stacked section cards. The rail collapses on narrower screens. */
.dev-body {
  display: flex;
  align-items: flex-start;
  gap: 20px;
}

.dev-sections {
  flex: 1 1 auto;
  min-inline-size: 0;   /* let flex children (tables) shrink instead of overflowing */
}

.dev-index {
  position: sticky;
  /* Clears the 64px navbar AND the sticky device header pinned beneath it
     (~50px when compact) so the rail never slides behind the device name. */
  top: 124px;
  flex: 0 0 218px;
  inline-size: 218px;
  max-block-size: calc(100vh - 140px);
  overflow-y: auto;
}

.dev-index__title {
  font-size: 0.6875rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  opacity: 0.6;
  padding-inline: 12px;
  margin-block-end: 6px;
}

.dev-index__list { display: flex; flex-direction: column; gap: 2px; }

.dev-index__row {
  display: flex;
  align-items: center;
  gap: 11px;
  inline-size: 100%;
  padding: 8px 12px;
  border: 0;
  border-inline-start: 3px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.dev-index__row:hover { background: rgba(var(--v-theme-on-surface), 0.05); }
.dev-index__row:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 1px; }

.dev-index__ico {
  flex: 0 0 20px;
  display: grid;
  place-items: center;
  color: rgba(var(--v-theme-on-surface), 0.48);
  transition: color 0.15s ease;
}
.dev-index__label {
  flex: 1;
  display: flex;
  align-items: center;
  min-inline-size: 0;
  font-size: 0.855rem;
  color: rgba(var(--v-theme-on-surface), 0.72);
}
.dev-index__attn {
  flex: 0 0 auto;
  inline-size: 7px;
  block-size: 7px;
  border-radius: 50%;
  background: rgb(var(--v-theme-error));
  margin-inline-end: 7px;
}
.dev-index__chip {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-variant-numeric: tabular-nums;
  font-size: 0.72rem;
  font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.55);
}
.dev-index__chip.is-attn { color: rgb(var(--v-theme-error)); }

/* Active row: thin primary track + icon + bold label. */
.dev-index__row.is-active {
  background: rgba(var(--v-theme-primary), 0.10);
  border-inline-start-color: rgb(var(--v-theme-primary));
}
.dev-index__row.is-active .dev-index__ico { color: rgb(var(--v-theme-primary)); }
.dev-index__row.is-active .dev-index__label { color: rgba(var(--v-theme-on-surface), 0.95); font-weight: 650; }

/* Jump targets sit below the sticky device header, so offset the scroll. */
.scroll-anchor { scroll-margin-top: 150px; }

/* ── Interfaces table ──────────────────────────────────── */
.interface-table :deep(th) {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.65;
  white-space: nowrap;
}
.if-name { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 0.82rem; }
.if-num { font-variant-numeric: tabular-nums; font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 0.8rem; white-space: nowrap; }
.if-status { min-inline-size: 62px; justify-content: center; font-weight: 600; }
.if-util { min-inline-size: 150px; }
.if-util__row { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; margin-block-end: 3px; }
.if-util__speed { font-size: 0.7rem; }
.if-thru { display: inline-flex; flex-direction: column; align-items: flex-end; line-height: 1.35; gap: 1px; }
.if-thru .v-icon { margin-inline-end: 1px; }
.if-health { font-weight: 600; }
.if-health :deep(.v-icon) { font-size: 13px; }
.if-spark { display: block; }
.if-spark-empty { font-family: ui-monospace, Menlo, monospace; }

/* Interface KPI summary strip */
.if-kpis {
  display: flex; flex-wrap: wrap; gap: 1px;
  background: rgba(var(--v-border-color), var(--v-border-opacity));
  border-block: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  margin-block-start: 4px;
}
.if-kpi { flex: 1; min-inline-size: 120px; padding: 10px 16px; background: rgb(var(--v-theme-surface)); }
.if-kpi__l { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.6; margin-block-end: 3px; }
.if-kpi__v { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 1.15rem; font-weight: 650; font-variant-numeric: tabular-nums; }
.if-kpi__v small { font-size: 0.72rem; opacity: 0.7; font-weight: 400; margin-inline-start: 2px; }
.if-kpi__v--sm { font-size: 0.9rem; }

/* Interface detail — proactive actions */
.if-actions {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px; padding: 14px 16px; margin-block-end: 18px;
  background: rgba(var(--v-theme-on-surface), 0.02);
}
.if-actions__head { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
.if-actions__detail { font-size: 0.85rem; }
.if-actions__ack { font-size: 0.72rem; margin-inline-start: auto; }
.if-actions__btns { display: flex; flex-wrap: wrap; gap: 8px; margin-block-start: 12px; }
.if-actions__note { margin-block-start: 12px; }
.if-actions__note-save { display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-block-start: 4px; }
.if-chart-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-block-end: 12px; flex-wrap: wrap; }
.if-chart-totals { font-variant-numeric: tabular-nums; }

/* ── Active VLANs grid ─────────────────────────────────── */
.vlan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(176px, 1fr));
  gap: 8px;
}
.vlan-tag {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 7px 10px;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.10);
  border-radius: 9px;
  background: rgba(var(--v-theme-on-surface), 0.02);
}
.vlan-tag__id {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-weight: 700;
  font-size: 0.82rem;
  color: rgb(var(--v-theme-primary));
  line-height: 1;
}
.vlan-tag__name {
  font-size: 0.72rem;
  letter-spacing: 0.02em;
  color: rgba(var(--v-theme-on-surface), 0.6);
  text-transform: uppercase;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Below the lg breakpoint the rail would crowd the cards — hide it, cards go full width. */
@media (max-width: 1279px) {
  .dev-index { display: none; }
}

/* Device header — subtle panel with a status rail so the identity block reads as
   a proper header, not loose text. */
.dev-header-sentinel { block-size: 1px; margin-block-end: -1px; }

.dev-header {
  position: sticky;
  /* Clears the app navbar, which is itself sticky at 64px. */
  inset-block-start: 64px;
  z-index: 5;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.10);
  /* Opaque at all times. The gradient is a tint ON TOP of the surface colour, not
     instead of it — as a bare gradient to transparent it left the panel see-through,
     so page content scrolled visibly through the pinned device identity. */
  background-color: rgb(var(--v-theme-surface));
  background-image: linear-gradient(180deg, rgba(var(--v-theme-on-surface), 0.02), transparent);
  transition: padding .15s ease, box-shadow .15s ease, background-color .15s ease;
}

/* Once pinned it must be opaque — content scrolls underneath it — and compact,
   so it costs as little vertical space as possible while still identifying the
   device. The sub-line and the back label fall away; the name, state and alarm
   count are what an operator needs to keep in view. */
.dev-header.is-stuck {
  /* Flat surface once pinned: the tint would read as a seam against the content
     sliding under it. */
  background-image: none;
  box-shadow: 0 4px 14px -8px rgba(0, 0, 0, 0.55);
  border-color: rgba(var(--v-theme-on-surface), 0.16);
}
.dev-header.is-stuck :deep(.pa-4) { padding-block: 8px !important; }
.dev-header.is-stuck :deep(h1) { font-size: 1.05rem !important; }
.dev-header.is-stuck .dev-header__sub { display: none !important; }
.dev-status-rail {
  inline-size: 4px;
  align-self: stretch;
  border-radius: 3px;
  min-block-size: 40px;
  background: rgb(var(--v-theme-secondary));
}
.dev-status-rail.is-up { background: rgb(var(--v-theme-success)); }
.dev-status-rail.is-down { background: rgb(var(--v-theme-error)); }
.dev-status-rail.is-idle { background: rgba(var(--v-theme-on-surface), 0.2); }
.pulse-dot {
  display: inline-block;
  inline-size: 7px;
  block-size: 7px;
  border-radius: 50%;
  margin-inline-end: 5px;
  background: currentColor;
}
.pulse-dot.is-up { animation: pulse 2s ease-in-out infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.35; } }
@media (prefers-reduced-motion: reduce) { .pulse-dot.is-up { animation: none; } }
.dev-dot { opacity: 0.4; }
.mono { font-family: 'Roboto Mono', ui-monospace, monospace; }

.tool-output {
  font-family: 'Roboto Mono', ui-monospace, monospace;
  font-size: 0.72rem;
  line-height: 1.5;
  white-space: pre-wrap;
  word-break: break-word;
  max-block-size: 340px;
  overflow: auto;
  margin: 0;
  padding: 12px 14px;
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.interface-table :deep(tbody tr) {
  cursor: pointer;
}

.dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.cursor-pointer {
  cursor: pointer;
}
.alarm-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 24px;
}
.text-break {
  overflow-wrap: anywhere;
  word-break: break-word;
}

.dev-detail__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
  gap: 28px 32px;
}

.dev-detail__legend {
  font-size: 0.7rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin-block-end: 10px;
}

.dev-detail__dl {
  display: grid;
  grid-template-columns: minmax(6.5rem, auto) 1fr;
  gap: 8px 16px;
  margin: 0;
}
.dev-detail__dl dt {
  font-size: 0.8125rem;
  color: rgba(var(--v-theme-on-surface), 0.6);
}
.dev-detail__dl dd {
  margin: 0;
  font-size: 0.875rem;
}

.dev-detail__meters { display: flex; flex-direction: column; gap: 16px; }
.dev-detail__pair { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

.endpoints__table th {
  font-size: 0.7rem !important;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}
.endpoints__port {
  display: inline-block;
  white-space: nowrap;
  font-size: 0.8125rem;
  padding: 2px 6px;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.06);
}
.endpoints__ident { min-inline-size: 0; }
.endpoints__table tbody tr:hover { background: rgba(var(--v-theme-on-surface), 0.03); }
/* History rows: readable, but clearly not a live adjacency. */
.endpoints__row--gone td { opacity: .62; }
.endpoint-link { text-decoration: none; cursor: pointer; }
.endpoint-link:hover { text-decoration: underline; }
.endpoint-link .v-icon { opacity: 0.7; }
.endpoints__row--gone .endpoints__port { text-decoration: line-through; }

/* Alarm history: descriptions WRAP instead of truncating (words were cutting), and
   cells top-align so a wrapped description doesn't push its row's other cells around. */
.alarm-history-table :deep(td) { vertical-align: top; padding-block: 10px; }
.alarm-desc {
  display: inline-block;
  white-space: normal;
  line-height: 1.4;
}
</style>
