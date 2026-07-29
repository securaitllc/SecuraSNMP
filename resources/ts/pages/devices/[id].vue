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
const vulnAssessable = ref(false)
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

const alarmHistoryHeaders = [
  { title: 'Severity', key: 'severity', width: 104 },
  { title: 'Ticket', key: 'ticket_number', width: 120 },
  { title: 'Event', key: 'alarm_id', width: 150 },
  { title: 'Description', key: 'description', minWidth: 260 },
  { title: 'Raised', key: 'first_seen_at', width: 150 },
  { title: 'Duration', key: 'duration', width: 96 },
  { title: 'Status', key: 'cleared_at', width: 150 },
  { title: 'By', key: 'acknowledged_by_name', width: 120 },
]

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

async function runTool(tool: 'ping' | 'traceroute' | 'snmpwalk') {
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

    const [sites, ifaces, vlanRows, neighborRows, ifaceHistoryRows, vulnRes] = await Promise.all([
      api<Site[]>('/api/sites'),
      api<DeviceInterface[]>(`/api/interfaces?device_id=${deviceId.value}`),
      api<DeviceVlan[]>(`/api/devices/${deviceId.value}/vlans`),
      api<LldpEndpoint[]>(`/api/devices/${deviceId.value}/neighbors`).catch(() => []),
      api<InterfaceAlert[]>(`/api/devices/${deviceId.value}/interface-alerts`).catch(() => []),
      loadMetrics(),
      loadAlarms(),
      api<{ data: DeviceVuln[]; assessable: boolean }>(`/api/devices/${deviceId.value}/vulnerabilities`).catch(() => ({ data: [], assessable: false })),
    ])
    sitesList.value = sites
    site.value = sites.find(s => s.id === device.value?.site_id) ?? null
    interfaces.value = ifaces
    vlans.value = vlanRows
    neighbors.value = neighborRows
    interfaceHistory.value = ifaceHistoryRows
    deviceVulns.value = vulnRes.data
    vulnAssessable.value = vulnRes.assessable
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

const activeVlans = computed(() => vlans.value.filter(v => v.status === 'active'))

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
function unreachableBands(ms: { recorded_at: string, response_time_ms: number | null }[]) {
  // Same shifted scale as the series, or the bands would sit hours off the line.
  const pts = ms.map(m => ({ t: easternChartMs(m.recorded_at), down: m.response_time_ms === null }))
  const gaps = pts.slice(1).map((p, i) => p.t - pts[i].t).filter(g => g > 0).sort((a, b) => a - b)
  const step = gaps.length ? gaps[Math.floor(gaps.length / 2)] : 60_000
  const bands: { x: number, x2: number, fillColor: string, opacity: number, borderWidth: number }[] = []
  let i = 0
  while (i < pts.length) {
    if (!pts[i].down) { i++; continue }
    const start = pts[i].t
    let end = pts[i].t
    while (i < pts.length && pts[i].down) { end = pts[i].t; i++ }
    bands.push({ x: start - step / 2, x2: end + step / 2, fillColor: '#ef4444', opacity: 0.16, borderWidth: 0 })
  }

  return bands
}

const chartOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent' },
  theme: { mode: chartMode.value },
  colors: ['#22c55e'],
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.02 } },
  dataLabels: { enabled: false },
  xaxis: { type: 'datetime' as const },
  yaxis: { labels: { formatter: (v: number) => `${Math.round(v)} ms` }, min: 0 },
  tooltip: { x: { format: 'MMM dd, HH:mm' }, y: { formatter: (v: number | null) => (v === null ? 'Timeout' : `${v} ms`) } },
  // Timestamps are shifted to Eastern; say so rather than leaving it ambiguous.
  grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
  markers: { size: 0 },
  // Red band over each unreachable (ICMP-timeout) stretch — the gap is coloured, no dots.
  annotations: { xaxis: unreachableBands(metrics.value) },
}))

const chartSeries = computed(() => [
  { name: 'Response time', data: metrics.value.map(m => [easternChartMs(m.recorded_at), m.response_time_ms] as [number, number | null]) },
])

const interfaceSearch = ref('')
const interfacePage = ref(1)
watch(interfaceSearch, () => { interfacePage.value = 1 })

const interfaceHeaders = [
  { title: 'Name', key: 'if_name' },
  { title: 'Status', key: 'status' },
  { title: 'In Octets', key: 'in_octets' },
  { title: 'Out Octets', key: 'out_octets' },
  { title: 'Utilization', key: 'utilization' },
  { title: 'In Discards (Δ)', key: 'in_discards_delta' },
  { title: 'Out Discards (Δ)', key: 'out_discards_delta' },
]

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

async function loadInterfaceData() {
  if (!selectedInterface.value) return
  isInterfaceLoading.value = true
  const id = selectedInterface.value.id
  const [metrics, alerts] = await Promise.all([
    api<InterfaceMetric[]>(`/api/interfaces/metrics?interface_id=${id}&range=${interfaceRange.value}`),
    api<InterfaceAlert[]>(`/api/interfaces/${id}/alerts`),
  ])
  interfaceMetrics.value = metrics
  interfaceAlerts.value = alerts
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

const ifTrafficSeries = computed(() => [
  { name: 'In', data: interfaceMetrics.value.map(m => [easternChartMs(m.recorded_at), bitsPerSec(m.in_octets_delta)] as [number, number]) },
  { name: 'Out', data: interfaceMetrics.value.map(m => [easternChartMs(m.recorded_at), bitsPerSec(m.out_octets_delta)] as [number, number]) },
])
const ifTrafficOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent' },
  theme: { mode: chartMode.value },
  colors: ['#22c55e', '#3b82f6'],
  stroke: { curve: 'smooth' as const, width: 2 },
  fill: { type: 'gradient', gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
  dataLabels: { enabled: false },
  xaxis: { type: 'datetime' as const },
  yaxis: { labels: { formatter: (v: number) => `${v.toFixed(2)} Mbps` }, min: 0 },
  tooltip: { x: { format: 'MMM dd, HH:mm' }, y: { formatter: (v: number) => `${v.toFixed(2)} Mbps` } },
  legend: { show: true },
  grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
  markers: { size: 0 },
}))

function alertDuration(a: InterfaceAlert): string {
  const start = new Date(a.started_at).getTime()
  const end = a.ended_at ? new Date(a.ended_at).getTime() : Date.now()
  const mins = Math.round((end - start) / 60000)
  return mins >= 60 ? `${Math.floor(mins / 60)}h ${mins % 60}m` : `${mins}m`
}

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

    <!-- Response time + Tools, side by side, up top under the device info. -->
    <VRow class="mb-4">
      <VCol cols="12" :md="auth.isAdmin ? 7 : 12">
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle class="d-flex align-center ga-2">
              <VIcon icon="ri-line-chart-line" size="20" /> Response Time (ICMP)
            </VCardTitle>
            <template #append>
              <VSelect
                v-model="range"
                :items="rangeOptions"
                density="compact"
                hide-details
                style="width: 128px;"
              />
            </template>
          </VCardItem>
          <VCardText>
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
      <VCol v-if="auth.isAdmin" cols="12" md="5">
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
            </div>
            <pre v-if="toolOutput" class="tool-output">{{ toolOutput.output || '(no output)' }}</pre>
            <div v-else class="text-medium-emphasis text-body-2">
              Run a live diagnostic against <span class="mono">{{ device?.ip_address }}</span>.
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Vulnerabilities: passive CVE correlation against this device's firmware. -->
    <VCard class="mb-5">
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
              <td class="font-weight-bold" :class="`text-${VULN_SEV_COLOR[v.severity] ?? 'secondary'}`">{{ v.cvss_score?.toFixed(1) ?? '—' }}</td>
              <td><a :href="v.reference_url ?? '#'" target="_blank" rel="noopener"><code>{{ v.cve_id }}</code></a></td>
              <td class="text-wrap" style="max-inline-size: 420px">{{ v.summary }}</td>
              <td><VChip size="x-small" variant="tonal" label>{{ v.matched_constraint }}</VChip></td>
              <td><VChip v-if="v.state === 'acknowledged'" size="x-small" color="secondary" label>ack’d</VChip></td>
            </tr>
          </tbody>
        </VTable>
      </VCardText>
    </VCard>

    <!-- Device detail: identity and live health in one card. These were two
         separate panels saying related things about the same box; an operator
         reading "is this device healthy" had to look in two places. -->
    <VCard class="mb-5 dev-detail">
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
              <dt>IP address</dt><dd class="mono">{{ device?.ip_address ?? '—' }}</dd>
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

    <!-- Connected endpoints (LLDP). Identity is grouped rather than spread across
         sparse columns: an AP advertises a MAC and no extension, a handset the
         reverse, so one column per attribute leaves most cells empty. -->
    <VCard
      v-if="sortedNeighbors.length"
      class="mb-5 endpoints"
    >
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          <VIcon icon="ri-plug-line" size="20" />
          Connected Endpoints
          <VChip size="x-small" variant="tonal" class="ms-1">{{ liveNeighborCount }}</VChip>
          <VChip v-if="goneNeighborCount" size="x-small" variant="tonal" color="warning" class="ms-1">
            {{ goneNeighborCount }} disconnected
          </VChip>
        </VCardTitle>
        <VCardSubtitle class="mt-1">
          Advertised over LLDP. An endpoint that does not speak LLDP will not appear here.
          Disconnected endpoints stay listed for 90 days so a down port can still be traced.
        </VCardSubtitle>
      </VCardItem>
      <VDivider />

      <VTable density="compact" class="endpoints__table">
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
                      class="font-weight-medium text-primary"
                    >{{ n.endpoint_model ?? n.remote_sysname ?? 'Unknown' }}</RouterLink>
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
      v-if="alarmedInterfaces.length > 0"
      class="mb-5"
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
      v-if="interfaceHistory.length"
      class="mb-5"
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
      class="mb-5"
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
        <div class="d-flex flex-wrap align-center ga-3">
          <VTextField
            v-model="alarmSearch"
            placeholder="Search description, ticket, event…"
            density="compact"
            append-inner-icon="ri-search-line"
            clearable
            hide-details
            style="min-width: 240px; flex: 1 1 240px"
          />
          <VSelect
            v-model="alarmSeverityFilter"
            :items="[{ title: 'Critical', value: 'critical' }, { title: 'Warning', value: 'warning' }, { title: 'Info', value: 'info' }]"
            placeholder="Severity"
            density="compact"
            variant="outlined"
            clearable
            hide-details
            style="min-width: 140px"
          />
          <VBtnToggle v-model="alarmStatusFilter" density="compact" variant="outlined">
            <VBtn value="active" size="small">Active</VBtn>
            <VBtn value="cleared" size="small">Cleared</VBtn>
          </VBtnToggle>
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
        <template #item.alarm_id="{ item }">
          <code class="text-caption">{{ item.alarm_id }}</code>
        </template>
        <template #item.description="{ item }">
          <span class="alarm-desc">{{ item.description }}</span>
        </template>
        <template #item.first_seen_at="{ item }">
          <span class="text-no-wrap">{{ formatDateTime(item.first_seen_at) }}</span>
        </template>
        <template #item.duration="{ item }">
          <span class="text-no-wrap" :class="item.cleared_at ? 'text-medium-emphasis' : 'text-warning font-weight-medium'">{{ item.duration }}</span>
        </template>
        <template #item.cleared_at="{ item }">
          <div v-if="item.cleared_at === null" class="d-flex align-center ga-1 text-error font-weight-medium">
            <span class="dot" style="background: rgb(var(--v-theme-error))" /> Active
          </div>
          <div v-else class="d-flex flex-column">
            <span class="text-no-wrap">Cleared {{ formatDateTime(item.cleared_at) }}</span>
            <VChip size="x-small" :color="item.cleared_kind === 'manual' ? 'info' : 'secondary'" label variant="tonal">
              {{ item.cleared_kind === 'manual' ? 'manual' : 'auto' }}
            </VChip>
          </div>
        </template>
        <template #item.acknowledged_by_name="{ item }">
          <span v-if="item.acknowledged_by_name || item.cleared_by_name" class="d-flex align-center ga-1">
            <VIcon icon="ri-user-line" size="14" class="text-medium-emphasis" />
            {{ item.cleared_by_name ?? item.acknowledged_by_name }}
          </span>
          <span v-else class="text-medium-emphasis">—</span>
        </template>
        <template #no-data>
          <div class="py-6 text-center text-medium-emphasis">No alarms match this filter.</div>
        </template>
      </VDataTable>
    </VCard>

    <VRow class="mb-1">
      <!-- Interfaces -->
      <VCol cols="12" md="8">
        <VCard>
          <VCardItem>
            <VCardTitle>Interfaces</VCardTitle>
            <template #append>
              <VChip size="small" variant="tonal">
                {{ interfaces.length }}
              </VChip>
            </template>
          </VCardItem>
          <VCardText class="pb-0">
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
            <template #item.status="{ item }">
              <span class="d-flex align-center ga-2">
                <span
                  class="dot"
                  :style="{
                    backgroundColor: item.status === 'up' ? 'rgb(var(--v-theme-success))' : 'rgb(var(--v-theme-error))',
                    opacity: item.admin_status === 'down' ? 0.3 : 1,
                  }"
                />
                <span :class="item.admin_status === 'down' ? 'text-disabled' : ''">
                  {{ item.admin_status === 'down' ? 'disabled' : item.status }}
                </span>
              </span>
            </template>
            <template #item.utilization="{ item }">
              <div v-if="item.speed_bps > 0" style="min-width: 120px">
                <div class="d-flex justify-space-between text-caption">
                  <span>↓{{ item.in_util_pct }}% ↑{{ item.out_util_pct }}%</span>
                  <span class="text-medium-emphasis">{{ ifSpeedLabel(item.speed_bps) }}</span>
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
          </VDataTable>
        </VCard>
      </VCol>

      <!-- Active VLANs -->
      <VCol cols="12" md="4">
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle>Active VLANs</VCardTitle>
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
            <div class="d-flex flex-wrap ga-2">
              <VChip
                v-for="vlan in activeVlans"
                :key="vlan.id"
                size="small"
                color="primary"
                variant="tonal"
              >
                {{ vlan.vlan_id }}{{ vlan.name ? ` · ${vlan.name}` : '' }}
              </VChip>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Interface detail: traffic + up/down history -->
    <VDialog v-model="isInterfaceOpen" max-width="900" scrollable>
      <VCard v-if="selectedInterface">
        <VCardItem>
          <VCardTitle>{{ selectedInterface.if_name }}</VCardTitle>
          <template #append>
            <div class="d-flex align-center ga-3">
              <VChip :color="selectedInterface.status === 'up' ? 'success' : 'error'" size="small" label>
                {{ selectedInterface.status }}
              </VChip>
              <VBtnToggle v-model="interfaceRange" density="compact" variant="outlined" mandatory>
                <VBtn value="1h" size="small">1h</VBtn>
                <VBtn value="6h" size="small">6h</VBtn>
                <VBtn value="24h" size="small">24h</VBtn>
                <VBtn value="7d" size="small">7d</VBtn>
              </VBtnToggle>
            </div>
          </template>
        </VCardItem>

        <VCardText>
          <!-- current stats -->
          <VRow class="mb-2">
            <VCol cols="6" sm="3">
              <div class="text-caption text-medium-emphasis">Link speed</div>
              <div class="text-h6">{{ ifSpeedLabel(selectedInterface.speed_bps) }}</div>
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

          <!-- traffic chart -->
          <div class="text-subtitle-2 mb-1">Traffic ({{ interfaceRange }})</div>
          <VueApexCharts
            v-if="interfaceMetrics.length"
            type="area"
            height="240"
            :options="ifTrafficOptions"
            :series="ifTrafficSeries"
          />
          <div v-else class="text-medium-emphasis text-body-2 py-6 text-center">
            No traffic samples in this range yet.
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
        </VCardText>
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

.tool-output,

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
.endpoints__row--gone .endpoints__port { text-decoration: line-through; }

/* Alarm history: descriptions WRAP instead of truncating (words were cutting), and
   cells top-align so a wrapped description doesn't push its row's other cells around. */
.alarm-history-table :deep(td) { vertical-align: top; padding-block: 10px; }
.alarm-desc {
  display: inline-block;
  max-inline-size: 560px;
  white-space: normal;
  line-height: 1.4;
}
.alarm-history-table :deep(code) {
  color: rgba(var(--v-theme-on-surface), 0.7);
  word-break: break-all;
}
</style>
