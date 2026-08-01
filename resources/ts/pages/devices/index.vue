<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useChartMode } from '@/composables/useChartTheme'
import { useAuthStore } from '@/stores/auth'
import { easternChartMs, formatDateTime } from '@/utils/datetime'
import type { Device, DeviceAlarm, DeviceInterface, DeviceMetric, InterfaceAlert, InterfaceMetric, NextHopAlert, Site, SshCredential, Tunnel, TunnelAlert, TunnelMetric } from '@/types/models'

// ApexCharts (~500 KB) only appears inside the interface/tunnel dialogs. Loading
// it lazily keeps it off the devices-list critical path — the list was spending
// ~1.5 s of main-thread time evaluating the chart bundle before it was usable.
const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'))

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()

const devices = ref<Device[]>([])
const search = ref('')
const sites = ref<Site[]>([])
const sshCredentials = ref<SshCredential[]>([])
const isLoading = ref(true)
const isDialogOpen = ref(false)
const isSaving = ref(false)
const editingDevice = ref<Device | null>(null)
const errorMessage = ref('')

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
const snmpVersionOptions = [
  { title: 'v2c', value: 'v2c' },
  { title: 'v3', value: 'v3' },
]
const statusOptions = [
  { title: 'Active', value: 'active' },
  { title: 'Inactive', value: 'inactive' },
]

const router = useRouter()
const route = useRoute()

// Drill-down from the dashboard map: /devices?site_id=<id> shows just that
// site's devices. Kept in sync with the URL so the chip clears cleanly.
const siteFilter = ref<number | null>(route.query.site_id ? Number(route.query.site_id) : null)
const siteFilterName = computed(() => sites.value.find(s => s.id === siteFilter.value)?.name ?? null)

// Quick status filter (mirrors the Status-pip buckets below).
type DeviceStatusKey = 'reachable' | 'unreachable' | 'inactive' | 'unknown'
const statusFilter = ref<DeviceStatusKey | null>(null)
const siteScoped = computed(() =>
  siteFilter.value === null ? devices.value : devices.value.filter(d => d.site_id === siteFilter.value))

// Role tabs (the top category strip). Counts are over the site-scoped fleet so
// they stay stable as the status/search sub-filters change.
const roleFilter = ref<string | null>(null)
const roleScoped = computed(() =>
  roleFilter.value ? siteScoped.value.filter(d => d.role === roleFilter.value) : siteScoped.value)
const roleTabs = computed(() => {
  const c: Record<string, number> = { switch: 0, edgeconnect: 0, firewall: 0 }
  for (const d of siteScoped.value)
    if (d.role in c) c[d.role]++
  return [
    { value: null, label: 'All', count: siteScoped.value.length, color: '#7C8AA0' },
    { value: 'switch', label: 'Switches', count: c.switch, color: '#4C8DFF' },
    { value: 'edgeconnect', label: 'SD-WAN', count: c.edgeconnect, color: '#8B7CF6' },
    { value: 'firewall', label: 'Firewalls', count: c.firewall, color: '#F5A623' },
  ]
})

// Text search over every field an operator would look up — including IP and site,
// which aren't table columns (so Vuetify's column-only :search never matched them).
function deviceMatchesSearch(d: Device): boolean {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return true
  const site = sites.value.find(s => s.id === d.site_id)?.name ?? ''
  return [d.name, d.ip_address, d.next_hop_ip, d.vendor, d.model, d.serial_number, d.os_version, site]
    .some(v => (v ?? '').toString().toLowerCase().includes(q))
}
const displayedDevices = computed(() => {
  const scoped = statusFilter.value
    ? roleScoped.value.filter(d => deviceStatusKey(d) === statusFilter.value)
    : roleScoped.value
  return search.value.trim() ? scoped.filter(deviceMatchesSearch) : scoped
})
const deviceStatusCounts = computed(() => {
  const acc = { reachable: 0, unreachable: 0, inactive: 0, unknown: 0 }
  for (const d of roleScoped.value) acc[deviceStatusKey(d)]++
  return acc
})
const deviceStatusChips: { key: DeviceStatusKey, label: string, color: string }[] = [
  { key: 'unreachable', label: 'Unreachable', color: 'error' },
  { key: 'inactive', label: 'Inactive', color: 'error' },
  { key: 'unknown', label: 'No data', color: 'warning' },
  { key: 'reachable', label: 'Reachable', color: 'success' },
]

function clearSiteFilter() {
  siteFilter.value = null
  router.replace({ query: { ...route.query, site_id: undefined } })
}

// A lightweight inline-SVG sparkline instead of a per-row ApexCharts instance —
// rendering 130+ charts made the devices list crawl. Returns polyline points in a
// 72×30 box, nulls (timeouts) dropped.
function sparkPoints(id: number): string {
  const data = (deviceSparklines.value[id] ?? []).filter((v): v is number => v != null)
  if (data.length < 2)
    return ''
  const w = 70; const h = 26; const pad = 2
  const min = Math.min(...data); const max = Math.max(...data); const range = max - min || 1
  const step = (w - pad * 2) / (data.length - 1)

  return data.map((v, i) => `${(pad + i * step).toFixed(1)},${(pad + (h - pad * 2) * (1 - (v - min) / range)).toFixed(1)}`).join(' ')
}

// Trend colour: green while the device answers ICMP, red when the latest ping
// timed out (device unreachable). No reading yet → neutral.
function sparkColor(id: number): string {
  const latest = deviceLatest.value[id]
  if (latest === null)
    return 'rgb(var(--v-theme-error))'
  if (latest === undefined)
    return 'rgb(var(--v-theme-on-surface))'

  return 'rgb(var(--v-theme-success))'
}

// Per-device ICMP response-time state for the Health column: sparkline points
// (null = a ping timeout, drawn as a gap) and the latest reading for the label.
const deviceSparklines = ref<Record<number, (number | null)[]>>({})
const deviceLatest = ref<Record<number, number | null | undefined>>({})

// One batch request for every device's response-time (was one request per
// device — brutal on a large fleet). We build the two maps as plain objects and
// assign each ref ONCE, so the whole fill is a single reactive update + a single
// re-render — never a per-device write storm that janks the table mid-load.
async function loadDeviceResponses() {
  const sparks: Record<number, (number | null)[]> = {}
  const latest: Record<number, number | null | undefined> = {}
  try {
    const summary = await api<Record<number, { points: (number | null)[], latest: number | null | undefined }>>('/api/devices/metrics/summary')
    for (const device of devices.value) {
      const s = summary[device.id]
      sparks[device.id] = s && s.points.length > 0 ? s.points : [null]
      latest[device.id] = s ? s.latest : undefined
    }
  }
  catch {
    for (const device of devices.value) {
      sparks[device.id] = [null]
      latest[device.id] = undefined
    }
  }
  deviceSparklines.value = sparks
  deviceLatest.value = latest
}

// Compact status pip: green = active + answering ICMP, red = disabled or timing
// out, amber = active but no ping reading yet (unknown). The word is dropped for
// a coloured check to keep the column narrow.
function deviceStatus(item: Device): { color: string, label: string } {
  const latest = deviceLatest.value[item.id]
  if (item.status !== 'active' || latest === null)
    return { color: 'error', label: item.status !== 'active' ? 'Inactive' : 'Unreachable' }
  if (latest === undefined)
    return { color: 'warning', label: 'Active · no data yet' }

  return { color: 'success', label: 'Active · reachable' }
}

// Same buckets as deviceStatus(), as a single key for the quick-filter chips.
function deviceStatusKey(item: Device): DeviceStatusKey {
  const latest = deviceLatest.value[item.id]
  if (item.status !== 'active')
    return 'inactive'
  if (latest === null)
    return 'unreachable'
  if (latest === undefined)
    return 'unknown'

  return 'reachable'
}

function deviceLatestLabel(deviceId: number): { text: string, timeout: boolean, missing: boolean } {
  const latest = deviceLatest.value[deviceId]
  if (latest === undefined)
    return { text: 'No data', timeout: false, missing: true }
  if (latest === null)
    return { text: 'Timeout', timeout: true, missing: false }

  return { text: `${Math.round(latest)} ms`, timeout: false, missing: false }
}

// Precompute each device's sparkline (points string + colour + label) ONCE per
// data change, instead of calling sparkPoints/sparkColor/deviceLatestLabel from
// the template — those re-ran for every row on every re-render (hover, sort),
// which made the list feel frozen while the ping data was filling in.
const deviceSpark = computed(() => {
  const out: Record<number, { points: string, color: string, text: string, cls: string }> = {}
  for (const d of devices.value) {
    const label = deviceLatestLabel(d.id)
    out[d.id] = {
      points: sparkPoints(d.id),
      color: sparkColor(d.id),
      text: label.text,
      cls: label.timeout ? 'text-error font-weight-medium' : (label.missing ? 'text-disabled' : ''),
    }
  }

  return out
})

function openDevicePage(device: Device) {
  router.push(`/devices/${device.id}`)
}

// A device is SSH-capable when a shared credential profile is linked or inline
// SSH is set. The shield surfaces this for ANY role (switch, firewall, EdgeConnect).
function sshEnabled(device: Device): boolean {
  return !!(device.ssh_credential_id || device.ssh_credential)
}

// EdgeConnect runs the SSH verify (tunnels / next-hop); other SSH-capable roles
// don't have a verify flow yet, so the shield takes them to their device page
// (looking glass lives there).
function onShieldClick(device: Device) {
  if (device.role === 'edgeconnect')
    openVerify(device)
  else
    openDevicePage(device)
}

function emptyForm() {
  return {
    site_id: null as number | null,
    name: '',
    ip_address: '',
    next_hop_ip: '',
    vendor: 'juniper',
    model: '',
    serial_number: '',
    os_version: '',
    role: 'switch',
    ha_group: '',
    ha_role: null as string | null,
    snmp_version: 'v2c',
    snmp_community: '',
    snmp_v3_username: '',
    snmp_v3_auth_key: '',
    snmp_v3_priv_key: '',
    ssh_username: '',
    ssh_credential: '',
    ssh_credential_id: null as number | null,
    status: 'active',
    notes: '',
  }
}

const form = ref(emptyForm())

const headers = [
  { title: 'Name', key: 'name', minWidth: 180 },
  { title: 'Vendor', key: 'vendor', minWidth: 110 },
  { title: 'Model', key: 'model', minWidth: 140 },
  { title: 'Serial', key: 'serial_number', minWidth: 120 },
  { title: 'Status', key: 'status', align: 'center', width: 84 },
  { title: 'Resp Time', key: 'health', sortable: false, minWidth: 168 },
  { title: 'Actions', key: 'actions', sortable: false, align: 'end' as const, width: 190 },
]

// Newest first by default, so a just-added (or just-imported) device is right at
// the top instead of buried alphabetically.
// Device names are site-prefixed (FL0092-HCF_SDW…), so sorting by name groups
// the list by site — the NOC's expected order, not newest-added.
const deviceSort = ref<{ key: string, order: 'asc' | 'desc' }[]>([{ key: 'name', order: 'asc' }])

// Flag devices added in the last 24h so they're easy to spot.
function isRecent(iso?: string | null): boolean {
  if (!iso)
    return false
  return Date.now() - new Date(iso).getTime() < 24 * 3600 * 1000
}

const CREDENTIAL_KEYS = ['snmp_community', 'snmp_v3_auth_key', 'snmp_v3_priv_key', 'ssh_credential'] as const

function buildPayload() {
  const payload: Record<string, unknown> = { ...form.value }

  for (const key of CREDENTIAL_KEYS) {
    if (payload[key] === '')
      delete payload[key]
  }

  return payload
}

async function loadData() {
  isLoading.value = true
  const [deviceResponse, siteResponse] = await Promise.all([
    api<{ data: Device[] }>('/api/devices'),
    api<Site[]>('/api/sites'),
  ])

  devices.value = deviceResponse.data
  sites.value = siteResponse
  isLoading.value = false

  // The SSH credentials list (for the device form's global-credential select)
  // is admin-only; viewers skip it to avoid a 403.
  if (auth.isAdmin && sshCredentials.value.length === 0) {
    const res = await api<{ data: SshCredential[] }>('/api/ssh-credentials')
    sshCredentials.value = res.data
  }

  // Fire-and-forget: the table is already interactive; sparklines fill in when
  // the batch returns without blocking the page or gating loadData.
  void loadDeviceResponses()
}

// Bulk-mute the "interface down" false alarms from unused admin-up ports that
// appear when a switch is first onboarded.
const suppressDialog = ref(false)
const suppressing = ref(false)
const notify = useNotify()
async function clearFalseInterfaceAlarms() {
  suppressing.value = true
  try {
    const res = await api<{ suppressed: number }>('/api/interfaces/suppress-down', { method: 'POST' })
    if (res.suppressed > 0) {
      notify.success(
        `Muted ${res.suppressed} false interface-down alarm${res.suppressed === 1 ? '' : 's'}`,
        'A port that comes back up re-arms automatically.',
      )
    }
    else {
      notify.info('No down admin-up interfaces to clear.')
    }
    suppressDialog.value = false
    await loadData()
  }
  catch (e: any) {
    notify.error('Could not mute interface alarms', e?.message ?? 'Retry, or check the poller logs.')
  }
  finally {
    suppressing.value = false
  }
}

function openCreateDialog() {
  editingDevice.value = null
  form.value = emptyForm()
  errorMessage.value = ''
  isDialogOpen.value = true
}

function openEditDialog(device: Device) {
  editingDevice.value = device
  form.value = {
    site_id: device.site_id,
    name: device.name,
    ip_address: device.ip_address,
    next_hop_ip: device.next_hop_ip ?? '',
    vendor: device.vendor,
    model: device.model,
    serial_number: device.serial_number ?? '',
    os_version: device.os_version ?? '',
    role: device.role,
    ha_group: device.ha_group ?? '',
    ha_role: device.ha_role ?? null,
    snmp_version: device.snmp_version ?? 'v2c',
    snmp_community: '',
    snmp_v3_username: device.snmp_v3_username ?? '',
    snmp_v3_auth_key: '',
    snmp_v3_priv_key: '',
    ssh_username: device.ssh_username ?? '',
    ssh_credential: '',
    ssh_credential_id: device.ssh_credential_id ?? null,
    status: device.status,
    notes: device.notes ?? '',
  }
  errorMessage.value = ''
  isDialogOpen.value = true
}

async function saveDevice() {
  isSaving.value = true
  errorMessage.value = ''

  try {
    const payload = buildPayload()

    if (editingDevice.value) {
      await api(`/api/devices/${editingDevice.value.id}`, {
        method: 'PUT',
        body: payload,
      })
    }
    else {
      await api('/api/devices', {
        method: 'POST',
        body: payload,
      })
    }

    isDialogOpen.value = false
    await loadData()
  }
  catch {
    errorMessage.value = 'Could not save the device. Check the fields and try again.'
  }
  finally {
    isSaving.value = false
  }
}

async function deleteDevice(device: Device) {
  if (!confirm(`Delete device "${device.name}"?`))
    return

  await api(`/api/devices/${device.id}`, { method: 'DELETE' })
  await loadData()
}

const isInterfacesOpen = ref(false)
const interfacesDevice = ref<Device | null>(null)
const interfaces = ref<DeviceInterface[]>([])
const isInterfacesLoading = ref(false)

const interfaceHeaders = [
  { title: 'Name', key: 'if_name' },
  { title: 'Status', key: 'status' },
  { title: 'In Octets', key: 'in_octets' },
  { title: 'Out Octets', key: 'out_octets' },
  { title: 'In Discards (Δ)', key: 'in_discards_delta' },
  { title: 'Out Discards (Δ)', key: 'out_discards_delta' },
  { title: 'Last Polled', key: 'last_polled_at' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function openInterfaces(device: Device) {
  interfacesDevice.value = device
  isInterfacesOpen.value = true
  isInterfacesLoading.value = true
  interfaces.value = await api<DeviceInterface[]>(`/api/interfaces?device_id=${device.id}`)
  isInterfacesLoading.value = false
}

const isInterfaceHistoryOpen = ref(false)
const historyInterface = ref<DeviceInterface | null>(null)
const interfaceAlerts = ref<InterfaceAlert[]>([])
const isInterfaceHistoryLoading = ref(false)

const interfaceHistoryHeaders = [
  { title: 'Started', key: 'started_at' },
  { title: 'Ended', key: 'ended_at' },
]

async function openInterfaceHistory(deviceInterface: DeviceInterface) {
  historyInterface.value = deviceInterface
  isInterfaceHistoryOpen.value = true
  isInterfaceHistoryLoading.value = true
  interfaceAlerts.value = await api<InterfaceAlert[]>(`/api/interfaces/${deviceInterface.id}/alerts`)
  isInterfaceHistoryLoading.value = false
}

const isInterfaceGraphOpen = ref(false)
const graphInterface = ref<DeviceInterface | null>(null)
const interfaceGraphRange = ref('24h')
const interfaceOctetsSeries = ref<{ name: string, data: [number, number][] }[]>([])
const interfaceDiscardsSeries = ref<{ name: string, data: [number, number][] }[]>([])
const isInterfaceGraphLoading = ref(false)
const interfaceGraphError = ref('')
let interfaceGraphRequestId = 0

const graphRangeOptions = [
  { title: '1 Hour', value: '1h' },
  { title: '6 Hours', value: '6h' },
  { title: '24 Hours', value: '24h' },
  { title: '7 Days', value: '7d' },
  { title: '30 Days', value: '30d' },
]

const chartMode = useChartMode()
const lineChartOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent' },
  theme: { mode: chartMode.value },
  stroke: { curve: 'smooth' as const, width: 2 },
  xaxis: { type: 'datetime' as const },
  dataLabels: { enabled: false },
  tooltip: { theme: chartMode.value },
}))

async function loadInterfaceGraph() {
  if (!graphInterface.value)
    return

  const requestId = ++interfaceGraphRequestId
  isInterfaceGraphLoading.value = true
  interfaceGraphError.value = ''

  try {
    const metrics = await api<InterfaceMetric[]>(`/api/interfaces/metrics?interface_id=${graphInterface.value.id}&range=${interfaceGraphRange.value}`)

    if (requestId !== interfaceGraphRequestId)
      return

    interfaceOctetsSeries.value = [
      { name: 'In', data: metrics.map(m => [easternChartMs(m.recorded_at), m.in_octets_delta]) },
      { name: 'Out', data: metrics.map(m => [easternChartMs(m.recorded_at), m.out_octets_delta]) },
    ]
    interfaceDiscardsSeries.value = [
      { name: 'In', data: metrics.map(m => [easternChartMs(m.recorded_at), m.in_discards_delta]) },
      { name: 'Out', data: metrics.map(m => [easternChartMs(m.recorded_at), m.out_discards_delta]) },
    ]
  }
  catch {
    if (requestId === interfaceGraphRequestId)
      interfaceGraphError.value = 'Could not load graph data.'
  }
  finally {
    if (requestId === interfaceGraphRequestId)
      isInterfaceGraphLoading.value = false
  }
}

async function openInterfaceGraph(deviceInterface: DeviceInterface) {
  graphInterface.value = deviceInterface
  isInterfaceGraphOpen.value = true
  await loadInterfaceGraph()
}

watch(interfaceGraphRange, () => {
  if (isInterfaceGraphOpen.value)
    loadInterfaceGraph()
})

const isVerifyOpen = ref(false)
const verifyDevice = ref<Device | null>(null)
const verifyAlarms = ref<DeviceAlarm[]>([])
const verifyTunnels = ref<Tunnel[]>([])
const verifyNextHopReachable = ref<boolean | null>(null)
const isVerifyLoading = ref(false)
const isVerifyRunning = ref(false)
const verifyError = ref('')

const alarmHeaders = [
  { title: 'Alarm ID', key: 'alarm_id' },
  { title: 'Description', key: 'description' },
  { title: 'First Seen', key: 'first_seen_at' },
]

const tunnelHeaders = [
  { title: 'Tunnel', key: 'tunnel_name' },
  { title: 'Status', key: 'status' },
  { title: 'In Discards (Δ)', key: 'in_discards_delta' },
  { title: 'Out Discards (Δ)', key: 'out_discards_delta' },
  { title: 'Last Checked', key: 'last_checked_at' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function loadVerifyData(device: Device) {
  isVerifyLoading.value = true
  const [alarms, tunnels, nextHopAlerts] = await Promise.all([
    api<DeviceAlarm[]>(`/api/alarms?device_id=${device.id}`),
    api<Tunnel[]>(`/api/tunnels?device_id=${device.id}`),
    api<NextHopAlert[]>(`/api/devices/${device.id}/next-hop-alerts`),
  ])
  verifyAlarms.value = alarms.filter(a => a.cleared_at === null)
  verifyTunnels.value = tunnels
  verifyNextHopReachable.value = device.next_hop_ip ? !nextHopAlerts.some(a => a.ended_at === null) : null
  isVerifyLoading.value = false
}

async function openVerify(device: Device) {
  verifyDevice.value = device
  isVerifyOpen.value = true
  verifyError.value = ''
  await loadVerifyData(device)
}

async function runVerifyNow() {
  if (!verifyDevice.value)
    return

  isVerifyRunning.value = true
  verifyError.value = ''

  try {
    await api(`/api/devices/${verifyDevice.value.id}/verify`, { method: 'POST' })
    await loadVerifyData(verifyDevice.value)
  }
  catch (e: any) {
    // Show the actual reason from the API (login failed / connection / no cred).
    verifyError.value = e?.data?.error ?? 'Verification failed. Check SSH credentials and connectivity.'
  }
  finally {
    isVerifyRunning.value = false
  }
}

const isTunnelHistoryOpen = ref(false)
const historyTunnel = ref<Tunnel | null>(null)
const tunnelAlerts = ref<TunnelAlert[]>([])
const isTunnelHistoryLoading = ref(false)

const tunnelHistoryHeaders = [
  { title: 'Started', key: 'started_at' },
  { title: 'Ended', key: 'ended_at' },
]

async function openTunnelHistory(tunnel: Tunnel) {
  historyTunnel.value = tunnel
  isTunnelHistoryOpen.value = true
  isTunnelHistoryLoading.value = true
  tunnelAlerts.value = await api<TunnelAlert[]>(`/api/tunnels/${tunnel.id}/alerts`)
  isTunnelHistoryLoading.value = false
}

const isTunnelGraphOpen = ref(false)
const graphTunnel = ref<Tunnel | null>(null)
const tunnelGraphRange = ref('24h')
const tunnelDiscardsSeries = ref<{ name: string, data: [number, number][] }[]>([])
const isTunnelGraphLoading = ref(false)
const tunnelGraphError = ref('')
let tunnelGraphRequestId = 0

async function loadTunnelGraph() {
  if (!graphTunnel.value)
    return

  const requestId = ++tunnelGraphRequestId
  isTunnelGraphLoading.value = true
  tunnelGraphError.value = ''

  try {
    const metrics = await api<TunnelMetric[]>(`/api/tunnels/metrics?tunnel_id=${graphTunnel.value.id}&range=${tunnelGraphRange.value}`)

    if (requestId !== tunnelGraphRequestId)
      return

    tunnelDiscardsSeries.value = [
      { name: 'In', data: metrics.map(m => [easternChartMs(m.recorded_at), m.in_discards_delta]) },
      { name: 'Out', data: metrics.map(m => [easternChartMs(m.recorded_at), m.out_discards_delta]) },
    ]
  }
  catch {
    if (requestId === tunnelGraphRequestId)
      tunnelGraphError.value = 'Could not load graph data.'
  }
  finally {
    if (requestId === tunnelGraphRequestId)
      isTunnelGraphLoading.value = false
  }
}

async function openTunnelGraph(tunnel: Tunnel) {
  graphTunnel.value = tunnel
  isTunnelGraphOpen.value = true
  await loadTunnelGraph()
}

watch(tunnelGraphRange, () => {
  if (isTunnelGraphOpen.value)
    loadTunnelGraph()
})

onMounted(loadData)
</script>

<template>
  <div>
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">Devices</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Every polled appliance — reachability, identity and response time at a glance.
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn
          v-if="auth.isAdmin"
          variant="tonal"
          color="warning"
          prepend-icon="ri-notification-off-line"
          @click="suppressDialog = true"
        >
          Clear false interface alarms
        </VBtn>
        <VBtn
          v-if="auth.isAdmin"
          @click="openCreateDialog"
        >
          Add Device
        </VBtn>
      </div>
    </div>

    <ListTabs v-model="roleFilter" :tabs="roleTabs" class="mt-4" />

    <VCard class="list-surface">
    <VDialog
      v-model="suppressDialog"
      max-width="480"
    >
      <VCard>
        <VCardTitle>Clear false interface alarms</VCardTitle>
        <VCardText>
          Mutes every <strong>admin-up but down</strong> interface across all devices — the
          unused access ports (no cable) that flood the alarm list when a switch is first
          added. Real uplinks stay monitored, and any muted port that comes back up
          re-arms its alarm automatically.
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="suppressDialog = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="warning"
            :loading="suppressing"
            @click="clearFalseInterfaceAlarms"
          >
            Clear alarms
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VCardText class="pb-0 d-flex align-center flex-wrap ga-3">
      <VTextField
        v-model="search"
        placeholder="Search device name, IP, vendor, model, site…"
        prepend-inner-icon="ri-search-line"
        density="compact"
        hide-details
        clearable
        style="max-width: 380px;"
      />
      <VChip
        v-if="siteFilter !== null"
        color="primary"
        variant="tonal"
        closable
        prepend-icon="ri-map-pin-line"
        @click:close="clearSiteFilter"
      >
        {{ siteFilterName ?? `Site #${siteFilter}` }} · {{ displayedDevices.length }} device(s)
      </VChip>
      <VSpacer />
      <div class="list-pills">
        <button
          v-for="s in deviceStatusChips"
          :key="s.key"
          type="button"
          class="list-pill"
          :class="{ 'list-pill--on': statusFilter === s.key }"
          @click="statusFilter = statusFilter === s.key ? null : s.key"
        >
          <span class="list-pill__d" :style="{ background: `rgb(var(--v-theme-${s.color}))` }" />
          {{ s.label }} · {{ deviceStatusCounts[s.key] }}
        </button>
      </div>
    </VCardText>

    <VDataTable
      v-model:sort-by="deviceSort"
      :headers="headers"
      :items="displayedDevices"
      :items-per-page="25"
      :loading="isLoading"
      density="compact"
      hover
      class="devices__table"
      @click:row="(_e: Event, row: { item: Device }) => openDevicePage(row.item)"
    >
      <template #item.status="{ item }">
        <VIcon
          icon="ri-checkbox-circle-fill"
          size="18"
          :color="deviceStatus(item).color"
          :title="deviceStatus(item).label"
        />
      </template>
      <template #item.name="{ item }">
        <div class="d-flex flex-column py-1">
          <div class="d-flex align-center">
            <a
              class="text-primary cursor-pointer"
              @click.stop="openDevicePage(item)"
            >{{ item.name }}</a>
            <span
              v-if="isRecent(item.created_at)"
              class="new-dot ms-2"
              title="Recently added"
            />
          </div>
          <span class="text-medium-emphasis text-caption">{{ item.ip_address }}</span>
        </div>
      </template>
      <template #item.health="{ item }">
        <div class="d-flex align-center ga-3">
          <svg
            width="72"
            height="26"
            viewBox="0 0 70 26"
            class="flex-shrink-0"
          >
            <polyline
              v-if="deviceSpark[item.id]?.points"
              :points="deviceSpark[item.id].points"
              fill="none"
              :stroke="deviceSpark[item.id].color"
              stroke-width="1.5"
              stroke-linejoin="round"
              stroke-linecap="round"
            />
          </svg>
          <span
            class="text-caption text-no-wrap"
            :class="deviceSpark[item.id]?.cls"
          >
            {{ deviceSpark[item.id]?.text }}
          </span>
        </div>
      </template>
      <template #item.actions="{ item }">
        <div class="d-flex flex-nowrap align-center justify-end">
        <VBtn
          icon="ri-external-link-line"
          variant="text"
          size="small"
          @click.stop="openDevicePage(item)"
        />
        <VBtn
          icon="ri-list-check-2"
          variant="text"
          size="small"
          @click.stop="openInterfaces(item)"
        />
        <VBtn
          v-if="(item.role === 'edgeconnect' || sshEnabled(item)) && auth.canAct"
          icon="ri-shield-check-line"
          variant="text"
          size="small"
          color="info"
          :title="item.role === 'edgeconnect' ? 'SSH enabled — verify tunnels / next-hop' : 'SSH credential enabled'"
          @click.stop="onShieldClick(item)"
        />
        <VBtn
          v-if="auth.isAdmin"
          icon="ri-edit-line"
          variant="text"
          size="small"
          @click.stop="openEditDialog(item)"
        />
        <VBtn
          v-if="auth.isAdmin"
          icon="ri-delete-bin-line"
          variant="text"
          size="small"
          @click.stop="deleteDevice(item)"
        />
        </div>
      </template>
    </VDataTable>
  </VCard>

  <VDialog
    v-model="isDialogOpen"
    max-width="700"
  >
    <VCard :title="editingDevice ? 'Edit Device' : 'Add Device'">
      <VCardText>
        <VAlert
          v-if="errorMessage"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ errorMessage }}
        </VAlert>

        <VForm @submit.prevent="saveDevice">
          <VRow>
            <VCol cols="6">
              <VAutocomplete
                v-model="form.site_id"
                :items="sites.map(s => ({ title: s.name, value: s.id }))"
                label="Site"
                :menu-props="{ maxHeight: 320 }"
                auto-select-first
                placeholder="Type to search sites…"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.name"
                label="Name"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.ip_address"
                label="IP Address"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.next_hop_ip"
                label="Next-Hop IP"
                placeholder="Upstream gateway to probe (EdgeConnect only)"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.model"
                label="Model"
                placeholder="EX2300, EC10104, ..."
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.vendor"
                :items="vendorOptions"
                label="Vendor"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.serial_number"
                label="Serial Number"
                placeholder="Auto-filled by SNMP; enter manually if blank"
                persistent-placeholder
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.os_version"
                label="OS Version"
                placeholder="e.g. 18.4R2-S3.3"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.role"
                :items="roleOptions"
                label="Role"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.status"
                :items="statusOptions"
                label="Status"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.ha_group"
                label="HA group (optional)"
                placeholder="e.g. corp-fw, cc-sdwan"
                hint="Give both HA members the same label to pair them"
                persistent-hint
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.ha_role"
                :items="[{ title: '— none —', value: null }, { title: 'Active', value: 'active' }, { title: 'Standby', value: 'standby' }]"
                label="HA role"
                :disabled="!form.ha_group"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.snmp_version"
                :items="snmpVersionOptions"
                label="SNMP Version"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.snmp_community"
                label="SNMP Community"
                :placeholder="editingDevice ? 'Leave blank to keep current' : ''"
                type="password"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.snmp_v3_username"
                label="SNMPv3 Username"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.snmp_v3_auth_key"
                label="SNMPv3 Auth Key"
                :placeholder="editingDevice ? 'Leave blank to keep current' : ''"
                type="password"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.snmp_v3_priv_key"
                label="SNMPv3 Priv Key"
                :placeholder="editingDevice ? 'Leave blank to keep current' : ''"
                type="password"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="form.ssh_credential_id"
                :items="sshCredentials"
                item-title="name"
                item-value="id"
                label="SSH Credential (shared)"
                clearable
                hint="Pick a shared SSH credential to use for this device. Leave empty to use the inline values below."
                persistent-hint
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.ssh_username"
                label="SSH Username (inline)"
                :disabled="form.ssh_credential_id !== null"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.ssh_credential"
                label="SSH Password/Key (inline)"
                :placeholder="editingDevice ? 'Leave blank to keep current' : ''"
                type="password"
                :disabled="form.ssh_credential_id !== null"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                label="Notes"
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                :loading="isSaving"
              >
                Save
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isInterfacesOpen"
    max-width="900"
  >
    <VCard :title="`Interfaces — ${interfacesDevice?.name ?? ''}`">
      <VCardText>
        <VDataTable
          :headers="interfaceHeaders"
          :items="interfaces"
          :loading="isInterfacesLoading"
          density="compact"
        >
          <template #item.status="{ item }">
            <span class="d-flex align-center ga-2">
              <span
                class="status-dot"
                :style="{ backgroundColor: item.status === 'up' ? 'rgb(var(--v-theme-success))' : 'rgb(var(--v-theme-error))' }"
              />
              {{ item.status }}
            </span>
          </template>
          <template #item.last_polled_at="{ item }">
            {{ item.last_polled_at ? formatDateTime(item.last_polled_at) : 'Never' }}
          </template>
          <template #item.actions="{ item }">
            <VBtn
              icon="ri-line-chart-line"
              variant="text"
              size="small"
              @click="openInterfaceGraph(item)"
            />
            <VBtn
              icon="ri-history-line"
              variant="text"
              size="small"
              @click="openInterfaceHistory(item)"
            />
          </template>
        </VDataTable>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isInterfaceGraphOpen"
    max-width="900"
  >
    <VCard :title="`Graph — ${graphInterface?.if_name ?? ''}`">
      <template #append>
        <VSelect
          v-model="interfaceGraphRange"
          :items="graphRangeOptions"
          density="compact"
          style="width: 140px;"
        />
      </template>
      <VCardText>
        <VAlert
          v-if="interfaceGraphError"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ interfaceGraphError }}
        </VAlert>

        <div
          v-if="isInterfaceGraphLoading"
          class="d-flex justify-center pa-8"
        >
          <VProgressCircular indeterminate />
        </div>

        <template v-else-if="!interfaceGraphError">
          <div class="text-subtitle-1 mb-2">
            Traffic (bytes/interval)
          </div>
          <VueApexCharts
            type="line"
            height="250"
            :options="lineChartOptions"
            :series="interfaceOctetsSeries"
          />

          <div class="text-subtitle-1 mb-2 mt-4">
            Discards (per interval)
          </div>
          <VueApexCharts
            type="line"
            height="200"
            :options="lineChartOptions"
            :series="interfaceDiscardsSeries"
          />
        </template>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isInterfaceHistoryOpen"
    max-width="600"
  >
    <VCard :title="`Alert History — ${historyInterface?.if_name ?? ''}`">
      <VCardText>
        <VDataTable
          :headers="interfaceHistoryHeaders"
          :items="interfaceAlerts"
          :loading="isInterfaceHistoryLoading"
        >
          <template #item.started_at="{ item }">
            {{ formatDateTime(item.started_at) }}
          </template>
          <template #item.ended_at="{ item }">
            {{ item.ended_at ? formatDateTime(item.ended_at) : 'Ongoing' }}
          </template>
        </VDataTable>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isVerifyOpen"
    max-width="900"
  >
    <VCard :title="`SSH Verify — ${verifyDevice?.name ?? ''}`">
      <template #append>
        <VBtn
          :loading="isVerifyRunning"
          @click="runVerifyNow"
        >
          Verify Now
        </VBtn>
      </template>
      <VCardText>
        <VAlert
          v-if="verifyError"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ verifyError }}
        </VAlert>

        <div class="mb-4">
          <div class="text-subtitle-1 mb-2">
            Next-Hop Status
          </div>
          <span
            v-if="verifyNextHopReachable === null"
            class="text-disabled"
          >Not configured</span>
          <span
            v-else
            class="d-flex align-center ga-2"
          >
            <span
              class="status-dot"
              :style="{ backgroundColor: verifyNextHopReachable ? 'rgb(var(--v-theme-success))' : 'rgb(var(--v-theme-error))' }"
            />
            {{ verifyNextHopReachable ? 'Reachable' : 'Unreachable' }}
          </span>
        </div>

        <div class="text-subtitle-1 mb-2">
          Active Alarms
        </div>
        <VDataTable
          :headers="alarmHeaders"
          :items="verifyAlarms"
          :loading="isVerifyLoading"
          density="compact"
          class="mb-4"
        >
          <template #item.first_seen_at="{ item }">
            {{ formatDateTime(item.first_seen_at) }}
          </template>
        </VDataTable>

        <div class="text-subtitle-1 mb-2">
          Tunnels
        </div>
        <VDataTable
          :headers="tunnelHeaders"
          :items="verifyTunnels"
          :loading="isVerifyLoading"
          density="compact"
        >
          <template #item.status="{ item }">
            <span class="d-flex align-center ga-2">
              <span
                class="status-dot"
                :style="{ backgroundColor: item.status === 'up' ? 'rgb(var(--v-theme-success))' : 'rgb(var(--v-theme-error))' }"
              />
              {{ item.status }}
            </span>
          </template>
          <template #item.last_checked_at="{ item }">
            {{ item.last_checked_at ? formatDateTime(item.last_checked_at) : 'Never' }}
          </template>
          <template #item.actions="{ item }">
            <VBtn
              icon="ri-line-chart-line"
              variant="text"
              size="small"
              @click="openTunnelGraph(item)"
            />
            <VBtn
              icon="ri-history-line"
              variant="text"
              size="small"
              @click="openTunnelHistory(item)"
            />
          </template>
        </VDataTable>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isTunnelHistoryOpen"
    max-width="600"
  >
    <VCard :title="`Alert History — ${historyTunnel?.tunnel_name ?? ''}`">
      <VCardText>
        <VDataTable
          :headers="tunnelHistoryHeaders"
          :items="tunnelAlerts"
          :loading="isTunnelHistoryLoading"
        >
          <template #item.started_at="{ item }">
            {{ formatDateTime(item.started_at) }}
          </template>
          <template #item.ended_at="{ item }">
            {{ item.ended_at ? formatDateTime(item.ended_at) : 'Ongoing' }}
          </template>
        </VDataTable>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isTunnelGraphOpen"
    max-width="900"
  >
    <VCard :title="`Graph — ${graphTunnel?.tunnel_name ?? ''}`">
      <template #append>
        <VSelect
          v-model="tunnelGraphRange"
          :items="graphRangeOptions"
          density="compact"
          style="width: 140px;"
        />
      </template>
      <VCardText>
        <VAlert
          v-if="tunnelGraphError"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ tunnelGraphError }}
        </VAlert>

        <div
          v-if="isTunnelGraphLoading"
          class="d-flex justify-center pa-8"
        >
          <VProgressCircular indeterminate />
        </div>

        <VueApexCharts
          v-else-if="!tunnelGraphError"
          type="line"
          height="250"
          :options="lineChartOptions"
          :series="tunnelDiscardsSeries"
        />
      </VCardText>
    </VCard>
  </VDialog>
  </div>
</template>

<style scoped>
.status-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.new-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: rgb(var(--v-theme-success));
}

/* The whole row opens the device — say so with the cursor, since only the name
   used to be clickable and everything else looked inert. */
.devices__table :deep(tbody tr) { cursor: pointer; }
</style>
