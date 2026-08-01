<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useChartMode } from '@/composables/useChartTheme'
import { useAuthStore } from '@/stores/auth'
import { easternChartMs, formatDateTime } from '@/utils/datetime'
import type { Circuit, CircuitAlert, CircuitMetric, IspProvider, Site } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()

const route = useRoute()
const search = ref('')
const circuits = ref<Circuit[]>([])
const downCircuits = computed(() => circuits.value.filter(c => c.status === 'down'))
const sites = ref<Site[]>([])

// Expandable per-circuit detail: lazy-load the outage/ticket history on expand.
const expanded = ref<number[]>([])
const circuitAlerts = ref<Record<number, CircuitAlert[] | 'loading'>>({})

async function loadCircuitAlerts(id: number) {
  if (circuitAlerts.value[id] && circuitAlerts.value[id] !== 'loading')
    return
  circuitAlerts.value[id] = 'loading'
  try {
    circuitAlerts.value[id] = await api<CircuitAlert[]>(`/api/circuits/${id}/alerts`)
  }
  catch {
    delete circuitAlerts.value[id]
  }
}
watch(expanded, ids => ids.forEach(loadCircuitAlerts))

function alertsFor(id: number): CircuitAlert[] {
  const a = circuitAlerts.value[id]

  return a && a !== 'loading' ? a : []
}
function openTicketFor(id: number): string | null {
  return alertsFor(id).find(a => a.ended_at === null)?.ticket_number ?? null
}
function dispatchFor(id: number): string | null {
  return alertsFor(id).find(a => a.ended_at === null)?.dispatch_at ?? null
}
function providerFor(circuit: Circuit): IspProvider | null {
  return providers.value.find(p => p.id === circuit.isp_provider_id) ?? null
}

// Ongoing-outage detail dialog (ISP ticket + acknowledge / clear).
const isOutageOpen = ref(false)
const outageCircuit = ref<{ id: number, circuit_id: string, isp_name?: string | null, support_phone?: string | null } | null>(null)

function openOutage(circuit: Circuit) {
  outageCircuit.value = {
    id: circuit.id,
    circuit_id: circuit.circuit_id,
    isp_name: circuitIspName(circuit),
    support_phone: circuitSupportPhone(circuit),
  }
  isOutageOpen.value = true
}

async function onOutageUpdated() {
  await loadData()
  if (outageCircuit.value) {
    delete circuitAlerts.value[outageCircuit.value.id]
    await loadCircuitAlerts(outageCircuit.value.id)
  }
}
const providers = ref<IspProvider[]>([])
const isLoading = ref(true)
const isDialogOpen = ref(false)
const isSaving = ref(false)
const editingCircuit = ref<Circuit | null>(null)
const errorMessage = ref('')

function circuitTypeLabel(t: string | null): string {
  return ({ fiber: 'Fiber', cable: 'Cable Modem', lte: 'LTE' } as Record<string, string>)[t ?? ''] ?? (t ?? '—')
}
const circuitTypeOptions = [
  { title: 'Fiber', value: 'fiber' },
  { title: 'Cable Modem', value: 'cable' },
  { title: 'LTE', value: 'lte' },
]

// The short codes the NOC actually says out loud: a fibre circuit is dedicated
// internet access, a cable modem is broadband, LTE is LTE. Displayed rather than
// stored, so the underlying circuit_type stays the vendor-neutral value.
const CIRCUIT_TYPE_CODE: Record<string, { code: string, label: string, color: string }> = {
  fiber: { code: 'DIA', label: 'Fiber — Dedicated Internet Access', color: 'primary' },
  cable: { code: 'BB', label: 'Cable Modem — Broadband', color: 'info' },
  lte: { code: 'LTE', label: 'LTE — wireless backup', color: 'warning' },
}

function circuitTypeCode(type: string | null | undefined) {
  return CIRCUIT_TYPE_CODE[type ?? ''] ?? { code: (type ?? '—').toUpperCase(), label: type ?? 'Unknown', color: 'default' }
}

// A row's mini-graph goes RED when the circuit is currently unreachable (latest
// ping timed out) or degraded by packet loss — a glanceable problem signal.
function sparkColorFor(circuit: Circuit) {
  const problem = circuitLatest.value[circuit.id] === null || (circuit.last_loss_pct ?? 0) > 0

  return problem ? '#ef4444' : '#22c55e'
}

// NOC-style problem shading for the response-time graph: a translucent band over
// each contiguous stretch of UNREACHABLE (red) or PACKET-LOSS (amber) samples —
// no dots, the outage window itself is coloured (Grafana/PRTG/Smokeping do the
// same). The line keeps its gap during an outage; the band fills that empty space.
function problemBands(metrics: { recorded_at: string, response_time_ms: number | null, loss_pct?: number | null }[]) {
  const pts = metrics.map(m => ({
    // Same shifted scale as the series, or the bands would sit hours off the line.
    t: easternChartMs(m.recorded_at),
    kind: m.response_time_ms === null ? 'down' : ((m.loss_pct ?? 0) > 0 ? 'loss' : 'ok'),
  }))
  const gaps = pts.slice(1).map((p, i) => p.t - pts[i].t).filter(g => g > 0).sort((a, b) => a - b)
  const step = gaps.length ? gaps[Math.floor(gaps.length / 2)] : 60_000
  const bands: { x: number, x2: number, fillColor: string, opacity: number, borderWidth: number }[] = []
  let i = 0
  while (i < pts.length) {
    if (pts[i].kind === 'ok') { i++; continue }
    const kind = pts[i].kind
    const start = pts[i].t
    let end = pts[i].t
    while (i < pts.length && pts[i].kind === kind) { end = pts[i].t; i++ }
    bands.push({ x: start - step / 2, x2: end + step / 2, fillColor: kind === 'down' ? '#ef4444' : '#f59e0b', opacity: kind === 'down' ? 0.16 : 0.12, borderWidth: 0 })
  }

  return bands
}

// Per-circuit response-time state: the sparkline points (null = a timeout, drawn
// as a gap) and the most recent reading for the inline label.
const circuitSparklines = ref<Record<number, (number | null)[]>>({})
const circuitLatest = ref<Record<number, number | null | undefined>>({})

/**
 * Every circuit's sparkline in ONE request.
 *
 * This used to be a per-circuit fetch run through Promise.all, so a 100-row page
 * opened 100 parallel requests for 100 tiny graphs. Combined with one chart engine
 * per row, that was enough to hang the tab. The API now returns all of them keyed
 * by circuit id, already windowed and point-capped server-side.
 */
async function loadCircuitResponses() {
  try {
    const summary = await api<Record<number, { points: (number | null)[], latest: number | null }>>(
      '/api/circuits/metrics/summary',
    )

    for (const circuit of circuits.value) {
      const entry = summary[circuit.id]
      circuitSparklines.value[circuit.id] = entry?.points?.length ? entry.points : [null]
      circuitLatest.value[circuit.id] = entry ? entry.latest : undefined
    }
  }
  catch {
    // One failed summary must not blank the table — leave the rows unresolved
    // rather than asserting every circuit has no data.
    for (const circuit of circuits.value) {
      circuitSparklines.value[circuit.id] ??= [null]
      circuitLatest.value[circuit.id] ??= undefined
    }
  }
}

function latestLabel(circuitId: number): { text: string, timeout: boolean, missing: boolean } {
  const latest = circuitLatest.value[circuitId]
  if (latest === undefined)
    return { text: 'No data', timeout: false, missing: true }
  if (latest === null)
    return { text: 'Timeout', timeout: true, missing: false }

  return { text: `${Math.round(latest)} ms`, timeout: false, missing: false }
}

const graphRangeOptions = [
  { title: '1 Hour', value: '1h' },
  { title: '6 Hours', value: '6h' },
  { title: '24 Hours', value: '24h' },
  { title: '7 Days', value: '7d' },
  { title: '30 Days', value: '30d' },
]

const chartMode = useChartMode()
const rtChartOptions = computed(() => ({
  chart: { toolbar: { show: false }, background: 'transparent' },
  theme: { mode: chartMode.value },
  colors: ['#22c55e'],
  stroke: { curve: 'smooth' as const, width: 2 },
  dataLabels: { enabled: false },
  xaxis: { type: 'datetime' as const },
  yaxis: { labels: { formatter: (v: number) => `${Math.round(v)} ms` }, min: 0 },
  tooltip: { theme: chartMode.value, x: { format: 'MMM dd, HH:mm' }, y: { formatter: (v: number | null) => (v === null ? 'Timeout' : `${v} ms`) } },
  grid: { borderColor: 'rgba(150,150,150,0.15)', strokeDashArray: 4 },
  markers: { size: 0 },
  // Red band over unreachable stretches, amber over packet-loss — the gap is coloured, no dots.
  annotations: { xaxis: problemBands(rtMetrics.value) },
}))

const isRtGraphOpen = ref(false)
const rtGraphCircuit = ref<Circuit | null>(null)
const rtGraphRange = ref('24h')
const rtSeries = ref<{ name: string, type?: string, data: [number, number | null][] }[]>([])
const rtMetrics = ref<CircuitMetric[]>([])
const isRtGraphLoading = ref(false)
let rtRequestId = 0

async function loadRtGraph() {
  if (!rtGraphCircuit.value)
    return

  const requestId = ++rtRequestId
  isRtGraphLoading.value = true

  try {
    const metrics = await api<CircuitMetric[]>(`/api/circuits/metrics?circuit_id=${rtGraphCircuit.value.id}&range=${rtGraphRange.value}`)
    if (requestId !== rtRequestId)
      return

    rtMetrics.value = metrics
    rtSeries.value = [
      { name: 'Response time', data: metrics.map(m => [easternChartMs(m.recorded_at), m.response_time_ms]) },
    ]
  }
  finally {
    if (requestId === rtRequestId)
      isRtGraphLoading.value = false
  }
}

async function openRtGraph(circuit: Circuit) {
  rtGraphCircuit.value = circuit
  isRtGraphOpen.value = true
  await loadRtGraph()
}

watch(rtGraphRange, () => {
  if (isRtGraphOpen.value)
    loadRtGraph()
})

function emptyForm() {
  return {
    site_id: null as number | null,
    isp_provider_id: null as number | null,
    isp_name: '',
    circuit_type: 'fiber',
    ip_assignment: 'static',
    monitor_via: 'icmp',
    wan_interface: '',
    ping_target: '8.8.8.8',
    circuit_id: '',
    account_number: '',
    support_phone: '',
    monitored_ip: '',
    subnet: '',
    gateway_ip: '',
    lec_name: '',
    lec_circuit_id: '',
    notes: '',
    shared_site_ids: [] as number[],
  }
}

const form = ref(emptyForm())

// Picking a provider keeps isp_name in sync so existing displays and the
// required field stay populated; the support phone lives on the provider.
watch(() => form.value.isp_provider_id, (id) => {
  const provider = providers.value.find(p => p.id === id)
  if (provider)
    form.value.isp_name = provider.name
})

// Kept lean so the table fits without a horizontal scrollbar — support phone,
// monitored IP, account #, subnet etc. all show in the expandable detail row.
const headers = [
  { title: 'Site', key: 'site_name', minWidth: 180 },
  { title: 'ISP', key: 'isp_name', minWidth: 130 },
  { title: 'Type', key: 'circuit_type', width: 76 },
  { title: 'Circuit ID', key: 'circuit_id', minWidth: 168 },
  { title: 'Status', key: 'status', width: 130 },
  { title: 'Response Time', key: 'health', sortable: false, minWidth: 160 },
  { title: '', key: 'actions', sortable: false, align: 'end' as const, width: 212 },
]

function siteName(siteId: number) {
  return sites.value.find(s => s.id === siteId)?.name ?? '—'
}

// Rows carry a resolved site_name so the table can sort by site name (the raw
// site_id is a meaningless number to sort on).
const circuitRows = computed(() => circuits.value.map(c => ({ ...c, site_name: siteName(c.site_id) })))
const circuitSort = ref<{ key: string, order: 'asc' | 'desc' }[]>([{ key: 'site_name', order: 'asc' }])

// Status bucket for the quick-filter chips — mirrors the Status column logic.
type CircuitStatusKey = 'up' | 'down' | 'degraded' | 'maintenance'
function circuitStatusOf(c: Circuit): CircuitStatusKey {
  if (c.monitoring_enabled === false) return 'maintenance'
  if (c.status === 'down') return 'down'
  if ((c.sustained_loss_pct ?? c.last_loss_pct ?? 0) >= 20) return 'degraded'
  return 'up'
}
const statusFilter = ref<CircuitStatusKey | null>(null)
const filteredRows = computed(() =>
  statusFilter.value ? circuitRows.value.filter(c => circuitStatusOf(c) === statusFilter.value) : circuitRows.value,
)
const statusCounts = computed(() => {
  const acc = { up: 0, down: 0, degraded: 0, maintenance: 0 }
  for (const c of circuits.value) acc[circuitStatusOf(c)]++
  return acc
})
const statusChips: { key: CircuitStatusKey, label: string, color: string }[] = [
  { key: 'down', label: 'Down', color: 'error' },
  { key: 'degraded', label: 'Degraded', color: 'warning' },
  { key: 'up', label: 'Up', color: 'success' },
  { key: 'maintenance', label: 'Maintenance', color: 'secondary' },
]

function circuitIspName(circuit: Circuit) {
  return circuit.isp_provider?.name ?? circuit.isp_name ?? '—'
}

function circuitSupportPhone(circuit: Circuit) {
  return circuit.isp_provider?.support_phone ?? circuit.support_phone ?? '—'
}

async function loadData() {
  isLoading.value = true
  const [circuitResponse, siteResponse, providerResponse] = await Promise.all([
    api<Circuit[]>('/api/circuits'),
    api<Site[]>('/api/sites'),
    api<IspProvider[]>('/api/isp-providers'),
  ])

  circuits.value = circuitResponse
  sites.value = siteResponse
  providers.value = providerResponse
  isLoading.value = false

  await loadCircuitResponses()
}

function openCreateDialog() {
  editingCircuit.value = null
  form.value = emptyForm()
  errorMessage.value = ''
  isDialogOpen.value = true
}

function openEditDialog(circuit: Circuit) {
  editingCircuit.value = circuit
  form.value = {
    site_id: circuit.site_id,
    isp_provider_id: circuit.isp_provider_id,
    isp_name: circuit.isp_name,
    circuit_type: circuit.circuit_type,
    ip_assignment: circuit.ip_assignment ?? 'static',
    monitor_via: circuit.monitor_via ?? 'icmp',
    wan_interface: circuit.wan_interface ?? '',
    ping_target: circuit.ping_target ?? '8.8.8.8',
    circuit_id: circuit.circuit_id,
    account_number: circuit.account_number ?? '',
    support_phone: circuit.support_phone ?? '',
    monitored_ip: circuit.monitored_ip,
    subnet: circuit.subnet ?? '',
    gateway_ip: circuit.gateway_ip ?? '',
    lec_name: circuit.lec_name ?? '',
    lec_circuit_id: circuit.lec_circuit_id ?? '',
    notes: circuit.notes ?? '',
    shared_site_ids: circuit.shared_site_ids ?? [],
  }
  errorMessage.value = ''
  isDialogOpen.value = true
}

async function saveCircuit() {
  isSaving.value = true
  errorMessage.value = ''

  try {
    if (editingCircuit.value) {
      await api(`/api/circuits/${editingCircuit.value.id}`, {
        method: 'PUT',
        body: form.value,
      })
    }
    else {
      await api('/api/circuits', {
        method: 'POST',
        body: form.value,
      })
    }

    isDialogOpen.value = false
    await loadData()
  }
  catch (e: any) {
    // Surface the exact field that failed (Laravel 422 → { errors: { field: [msg] } })
    // instead of a generic message that hides which input to fix.
    const errs = e?.data?.errors as Record<string, string[]> | undefined
    errorMessage.value = errs
      ? Object.values(errs).flat().join(' ')
      : (e?.data?.message ?? 'Could not save the circuit. Check the fields and try again.')
  }
  finally {
    isSaving.value = false
  }
}

async function toggleMonitoring(circuit: Circuit) {
  const enabling = circuit.monitoring_enabled === false
  if (!enabling && !confirm(`Pause "${circuit.circuit_id}"? It stops pinging AND silences every alarm for this circuit — the WAN link, next-hop and IP-SLA on its uplink — until you resume it. Use for a flapping/backup link or a planned disconnect.`))
    return
  try {
    await api(`/api/circuits/${circuit.id}/monitoring`, { method: 'POST', body: { enabled: enabling } })
  }
  catch (e: any) {
    alert(`Could not change monitoring: ${e?.data?.message ?? e?.message ?? 'unknown error'}`)
    return
  }
  await loadData()
}

async function deleteCircuit(circuit: Circuit) {
  if (!confirm(`Delete circuit "${circuit.circuit_id}"?`))
    return

  try {
    await api(`/api/circuits/${circuit.id}`, { method: 'DELETE' })
  }
  catch (e: any) {
    // Surface the real reason instead of failing silently.
    alert(`Could not delete circuit: ${e?.data?.message ?? e?.message ?? 'unknown error'}`)
    return
  }
  await loadData()
}

const isHistoryOpen = ref(false)
const historyCircuit = ref<Circuit | null>(null)
const alerts = ref<CircuitAlert[]>([])
const isHistoryLoading = ref(false)
const savingAlertId = ref<number | null>(null)

const historyHeaders = [
  { title: 'Started', key: 'started_at' },
  { title: 'Ended', key: 'ended_at' },
  { title: 'Reason', key: 'cause', sortable: false },
  { title: 'Ticket Number', key: 'ticket_number' },
]

async function openHistory(circuit: Circuit) {
  historyCircuit.value = circuit
  isHistoryOpen.value = true
  isHistoryLoading.value = true
  alerts.value = await api<CircuitAlert[]>(`/api/circuits/${circuit.id}/alerts`)
  isHistoryLoading.value = false
}

async function saveTicketNumber(alert: CircuitAlert) {
  if (!historyCircuit.value)
    return

  savingAlertId.value = alert.id

  try {
    await api(`/api/circuits/${historyCircuit.value.id}/alerts/${alert.id}`, {
      method: 'PUT',
      body: { ticket_number: alert.ticket_number },
    })
  }
  finally {
    savingAlertId.value = null
  }
}

// The currently-open incident (if the circuit is down right now) and the last
// ISP ticket logged on any earlier outage — so a recurring issue can reference
// or reopen the prior ticket instead of starting from scratch.
const openHistoryAlert = computed(() => alerts.value.find(a => a.ended_at === null) ?? null)
const previousTicket = computed(() => {
  const withTicket = alerts.value.filter(a => a.ticket_number && a.id !== openHistoryAlert.value?.id)

  // The alerts endpoint returns newest-first, so the first match is the most recent.
  return withTicket.length ? withTicket[0].ticket_number : null
})

async function reusePreviousTicket() {
  const open = openHistoryAlert.value
  if (!open || !previousTicket.value)
    return

  open.ticket_number = previousTicket.value
  await saveTicketNumber(open)
}

onMounted(async () => {
  await loadData()

  // Deep-link by row id, used by the dashboard's "Go to circuit". The list is
  // client-side filtered and paged 25 at a time, so the row has to be searched
  // into view as well as expanded — landing on an unfiltered page 1 with nothing
  // selected is what made the button look like it opened the wrong circuit.
  const target = route.query.circuit
  if (typeof target === 'string' && target) {
    const match = circuits.value.find(c => String(c.id) === target)
    if (match) {
      search.value = match.circuit_id
      expanded.value = [match.id]

      return
    }
  }

  // Deep-link from global search: pre-filter and auto-expand the matched circuit.
  const q = route.query.q
  if (typeof q === 'string' && q) {
    search.value = q
    const match = circuits.value.find(c => c.circuit_id.toLowerCase().includes(q.toLowerCase()))
    if (match)
      expanded.value = [match.id]
  }
})
</script>

<template>
  <VCard title="Circuits">
    <template
      v-if="auth.isAdmin"
      #append
    >
      <VBtn @click="openCreateDialog">
        Add Circuit
      </VBtn>
    </template>

    <!-- Circuit-down banner: same at-a-glance alarm indicator as the device page,
         with the ISP + support line ready for the NOC to call. -->
    <VAlert
      v-if="downCircuits.length > 0"
      type="error"
      variant="tonal"
      prominent
      class="mx-4 mt-2"
      icon="ri-alarm-warning-line"
    >
      <div class="d-flex align-center justify-space-between flex-wrap ga-2">
        <div>
          <strong>{{ downCircuits.length }} circuit{{ downCircuits.length > 1 ? 's' : '' }} down</strong>
          —
          <span
            v-for="(c, i) in downCircuits.slice(0, 3)"
            :key="c.id"
          >{{ i > 0 ? ', ' : '' }}{{ c.isp_name }} {{ c.circuit_id }}</span>
          <span v-if="downCircuits.length > 3"> +{{ downCircuits.length - 3 }} more</span>
        </div>
        <div
          v-if="downCircuits[0]?.support_phone"
          class="text-body-2"
        >
          Call {{ downCircuits[0].isp_name }}: <strong>{{ downCircuits[0].support_phone }}</strong>
        </div>
      </div>
    </VAlert>

    <VCardText class="pb-0 d-flex flex-wrap align-center ga-4">
      <VTextField
        v-model="search"
        placeholder="Search circuit ID, ISP, IP, or site…"
        prepend-inner-icon="ri-search-line"
        density="compact"
        hide-details
        clearable
        style="max-width: 380px;"
      />
      <div class="app-filter-chips">
        <VChip
          v-for="s in statusChips"
          :key="s.key"
          :color="statusFilter === s.key ? s.color : undefined"
          :variant="statusFilter === s.key ? 'flat' : 'tonal'"
          size="small" label class="cursor-pointer"
          @click="statusFilter = statusFilter === s.key ? null : s.key"
        >
          {{ s.label }} · {{ statusCounts[s.key] }}
        </VChip>
      </div>
    </VCardText>

    <VDataTable
      v-model:expanded="expanded"
      v-model:sort-by="circuitSort"
      :headers="headers"
      :items="filteredRows"
      :items-per-page="25"
      :loading="isLoading"
      :search="search"
      item-value="id"
      show-expand
      density="compact"
    >
      <template #item.site_name="{ item }">
        {{ item.site_name }}
      </template>
      <template #item.isp_name="{ item }">
        {{ circuitIspName(item) }}
      </template>
      <template #item.status="{ item }">
        <VChip
          v-if="item.monitoring_enabled === false"
          size="small"
          color="secondary"
          variant="tonal"
          prepend-icon="ri-pause-circle-line"
        >
          Maintenance
        </VChip>
        <VChip
          v-else-if="item.status === 'down'"
          size="small"
          color="error"
          variant="flat"
          class="cursor-pointer"
          prepend-icon="ri-alarm-warning-line"
          @click.stop="openOutage(item)"
        >
          Down
        </VChip>
        <VChip
          v-else-if="(item.sustained_loss_pct ?? item.last_loss_pct ?? 0) >= 20"
          size="small"
          color="warning"
          variant="flat"
          prepend-icon="ri-signal-wifi-error-line"
          :title="`Sustained packet loss ${item.sustained_loss_pct ?? item.last_loss_pct}% (median of recent polls) — still passing traffic but degraded`"
        >
          Degraded · {{ item.sustained_loss_pct ?? item.last_loss_pct }}% loss
        </VChip>
        <span
          v-else
          class="d-flex align-center ga-2"
        >
          <span
            class="status-dot"
            :style="{ backgroundColor: 'rgb(var(--v-theme-success))' }"
          />
          up<span
            v-if="(item.sustained_loss_pct ?? 0) > 0"
            class="text-warning text-caption"
          > · {{ item.sustained_loss_pct }}% loss</span>
        </span>
      </template>
      <template #item.circuit_type="{ item }">
        <VChip
          size="x-small"
          variant="tonal"
          :color="circuitTypeCode(item.circuit_type).color"
          class="mono"
          :title="circuitTypeCode(item.circuit_type).label"
        >
          {{ circuitTypeCode(item.circuit_type).code }}
        </VChip>
      </template>
      <template #item.circuit_id="{ item }">
        <span class="text-no-wrap font-weight-medium">{{ item.circuit_id }}</span>
      </template>
      <template #item.health="{ item }">
        <div class="d-flex align-center ga-3">
          <div style="width: 72px;">
            <Sparkline
              :points="circuitSparklines[item.id] ?? [null]"
              :color="sparkColorFor(item)"
            />
          </div>
          <span
            class="text-caption text-no-wrap"
            :class="latestLabel(item.id).timeout ? 'text-error font-weight-medium' : (latestLabel(item.id).missing ? 'text-disabled' : '')"
          >
            {{ latestLabel(item.id).text }}
          </span>
        </div>
      </template>
      <template #item.actions="{ item }">
        <div class="d-flex flex-nowrap align-center justify-end">
        <VBtn
          icon="ri-line-chart-line"
          variant="text"
          size="small"
          @click="openRtGraph(item)"
        />
        <VBtn
          icon="ri-history-line"
          variant="text"
          size="small"
          @click="openHistory(item)"
        />
        <VBtn
          v-if="auth.isAdmin"
          :icon="item.monitoring_enabled === false ? 'ri-play-circle-line' : 'ri-pause-circle-line'"
          :color="item.monitoring_enabled === false ? 'success' : undefined"
          variant="text"
          size="small"
          :title="item.monitoring_enabled === false ? 'Resume monitoring' : 'Pause — silence all alarms for this circuit (ping, WAN link, next-hop). Use for a flapping/backup link or planned disconnect.'"
          @click="toggleMonitoring(item)"
        />
        <VBtn
          v-if="auth.isAdmin"
          icon="ri-edit-line"
          variant="text"
          size="small"
          @click="openEditDialog(item)"
        />
        <VBtn
          v-if="auth.isAdmin"
          icon="ri-delete-bin-line"
          variant="text"
          size="small"
          @click="deleteCircuit(item)"
        />
        </div>
      </template>

      <!-- Full per-circuit detail: every unique field + ISP escalation + outage/ticket history -->
      <template #expanded-row="{ columns, item }">
        <tr>
          <td
            :colspan="columns.length"
            class="pa-0"
          >
            <div class="circuit-detail pa-4">
              <VRow>
                <!-- Identity -->
                <VCol cols="12" md="7">
                  <div class="detail-kicker">Circuit</div>
                  <div class="detail-grid">
                    <div>
                      <div class="text-caption text-medium-emphasis">Circuit ID</div>
                      <div class="font-weight-medium">{{ item.circuit_id }}</div>
                    </div>
                    <div>
                      <div class="text-caption text-medium-emphasis">Account #</div>
                      <div>{{ item.account_number ?? '—' }}</div>
                    </div>
                    <div>
                      <div class="text-caption text-medium-emphasis">Type</div>
                      <div>{{ circuitTypeLabel(item.circuit_type) }}</div>
                    </div>
                    <div>
                      <div class="text-caption text-medium-emphasis">Status</div>
                      <div :class="item.status === 'down' ? 'text-error font-weight-medium' : ''">
                        {{ item.status === 'down' ? 'Down' : 'Up' }}
                      </div>
                    </div>
                    <div>
                      <div class="text-caption text-medium-emphasis">IP assignment</div>
                      <div class="text-uppercase">{{ item.ip_assignment ?? 'static' }}</div>
                    </div>
                    <div>
                      <div class="text-caption text-medium-emphasis">
                        {{ item.ip_assignment === 'dhcp' ? 'Current public IP' : 'Static public IP' }}
                      </div>
                      <div>{{ item.monitored_ip ?? '—' }}</div>
                    </div>
                    <div v-if="item.ip_assignment !== 'dhcp'">
                      <div class="text-caption text-medium-emphasis">Subnet mask</div>
                      <div>{{ item.subnet ?? '—' }}</div>
                    </div>
                    <div v-if="item.ip_assignment !== 'dhcp' && item.gateway_ip">
                      <div class="text-caption text-medium-emphasis">Gateway</div>
                      <div>{{ item.gateway_ip }}</div>
                    </div>
                    <div v-if="item.lec_name">
                      <div class="text-caption text-medium-emphasis">LEC (last mile)</div>
                      <div>{{ item.lec_name }}<span v-if="item.lec_circuit_id"> · {{ item.lec_circuit_id }}</span></div>
                    </div>
                    <div>
                      <div class="text-caption text-medium-emphasis">Site</div>
                      <div>{{ siteName(item.site_id) }}</div>
                    </div>
                    <div>
                      <div class="text-caption text-medium-emphasis">Last checked</div>
                      <div>{{ item.last_checked_at ? formatDateTime(item.last_checked_at) : '—' }}</div>
                    </div>
                  </div>
                  <div
                    v-if="item.notes"
                    class="mt-2"
                  >
                    <div class="text-caption text-medium-emphasis">Notes</div>
                    <div class="text-body-2">{{ item.notes }}</div>
                  </div>
                </VCol>

                <!-- ISP escalation -->
                <VCol cols="12" md="5">
                  <div class="detail-kicker">ISP escalation</div>
                  <VCard variant="tonal" class="pa-3 isp-card">
                    <div class="font-weight-medium mb-1">
                      {{ circuitIspName(item) }}
                    </div>
                    <div class="text-caption mb-2">
                      <VIcon icon="ri-customer-service-2-line" size="14" />
                      <a :href="`tel:${circuitSupportPhone(item)}`">{{ circuitSupportPhone(item) }}</a>
                      <span class="text-medium-emphasis"> · support</span>
                    </div>
                    <template v-if="providerFor(item)">
                      <VDivider class="mb-2" />
                      <div class="text-body-2 font-weight-medium">
                        {{ providerFor(item)!.account_rep_name ?? 'No rep on file' }}
                      </div>
                      <div v-if="providerFor(item)!.account_rep_mobile" class="text-caption">
                        <VIcon icon="ri-smartphone-line" size="14" />
                        <a :href="`tel:${providerFor(item)!.account_rep_mobile}`">{{ providerFor(item)!.account_rep_mobile }}</a>
                      </div>
                      <div v-if="providerFor(item)!.account_rep_email" class="text-caption">
                        <VIcon icon="ri-mail-line" size="14" />
                        <a :href="`mailto:${providerFor(item)!.account_rep_email}`">{{ providerFor(item)!.account_rep_email }}</a>
                      </div>
                    </template>
                    <div class="d-flex ga-2 mt-3">
                      <VBtn
                        size="small"
                        variant="tonal"
                        prepend-icon="ri-line-chart-line"
                        @click="openRtGraph(item)"
                      >
                        Graph
                      </VBtn>
                      <VBtn
                        v-if="item.status === 'down'"
                        size="small"
                        color="error"
                        variant="flat"
                        prepend-icon="ri-alarm-warning-line"
                        @click="openOutage(item)"
                      >
                        Manage outage
                      </VBtn>
                    </div>
                  </VCard>
                </VCol>
              </VRow>

              <VRow class="mt-2">
                <!-- Outage / ticket history — full width so the table isn't crunched -->
                <VCol cols="12">
                  <div class="detail-kicker">
                    Outage &amp; ISP ticket history
                    <span
                      v-if="openTicketFor(item.id)"
                      class="text-error"
                    >· open #{{ openTicketFor(item.id) }}</span>
                  </div>
                  <VChip
                    v-if="dispatchFor(item.id)"
                    size="x-small"
                    color="info"
                    variant="tonal"
                    class="mb-2"
                    prepend-icon="ri-calendar-schedule-line"
                  >
                    Dispatch {{ formatDateTime(dispatchFor(item.id)!) }}
                  </VChip>

                  <VTable density="compact" class="overview-table">
                    <thead>
                      <tr>
                        <th>Started</th>
                        <th>Ended</th>
                        <th>Reason</th>
                        <th>Ticket</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr
                        v-for="a in alertsFor(item.id).slice(0, 8)"
                        :key="a.id"
                      >
                        <td>{{ formatDateTime(a.started_at) }}</td>
                        <td>
                          <span
                            v-if="a.ended_at"
                          >{{ formatDateTime(a.ended_at) }}</span>
                          <span
                            v-else
                            class="text-error font-weight-medium"
                          >ongoing</span>
                        </td>
                        <td>
                          <VChip
                            v-if="a.cause"
                            size="x-small"
                            :color="a.cause === 'packet_loss' ? 'warning' : 'error'"
                            variant="tonal"
                          >
                            {{ a.cause === 'packet_loss' ? 'Packet loss' : 'Hard down' }}<span v-if="a.detected_loss_pct != null"> · {{ a.detected_loss_pct }}%</span>
                          </VChip>
                          <span v-else class="text-disabled">—</span>
                        </td>
                        <td>{{ a.ticket_number ? `#${a.ticket_number}` : '—' }}</td>
                      </tr>
                      <tr v-if="alertsFor(item.id).length === 0">
                        <td colspan="4" class="text-center text-medium-emphasis py-3">
                          No outages recorded.
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </VCol>
              </VRow>
            </div>
          </td>
        </tr>
      </template>
    </VDataTable>
  </VCard>

  <VDialog
    v-model="isDialogOpen"
    max-width="700"
  >
    <VCard :title="editingCircuit ? 'Edit Circuit' : 'Add Circuit'">
      <VCardText>
        <VAlert
          v-if="errorMessage"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ errorMessage }}
        </VAlert>

        <VForm @submit.prevent="saveCircuit">
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
              <VSelect
                v-model="form.circuit_type"
                :items="circuitTypeOptions"
                label="Circuit Type"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.ip_assignment"
                :items="[{ title: 'Static', value: 'static' }, { title: 'DHCP', value: 'dhcp' }]"
                label="IP assignment"
                hint="DHCP: enter the public IP the site currently holds"
                persistent-hint
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="form.monitor_via"
                :items="[{ title: 'Direct ICMP (ping the IP)', value: 'icmp' }, { title: 'SDWAN-sourced ping', value: 'sdwan' }]"
                label="Monitoring method"
                hint="Use SDWAN ping when the public IP is behind ISP NAT (DHCP) and can't be pinged"
                persistent-hint
              />
            </VCol>
            <template v-if="form.monitor_via === 'sdwan'">
              <VCol cols="6">
                <VSelect
                  v-model="form.wan_interface"
                  :items="['wan0', 'wan1', 'wan2', 'wan3']"
                  label="Silver Peak WAN interface"
                  hint="Which WAN this circuit is on (Massey: wan0 = cable modem)"
                  persistent-hint
                />
              </VCol>
              <VCol cols="6">
                <VTextField
                  v-model="form.ping_target"
                  label="Ping target"
                  hint="Public host to ping from the WAN (default 8.8.8.8)"
                  persistent-hint
                />
              </VCol>
            </template>
            <VCol cols="6">
              <VSelect
                v-model="form.isp_provider_id"
                :items="providers.map(p => ({ title: p.name, value: p.id }))"
                label="ISP Provider"
                clearable
                hint="Pick a provider to reuse its saved support phone & rep contacts"
                persistent-hint
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.isp_name"
                label="ISP Name"
                :disabled="form.isp_provider_id !== null"
                hint="Auto-filled from the provider when one is selected"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.circuit_id"
                label="Circuit ID"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.monitored_ip"
                :label="form.ip_assignment === 'dhcp' ? 'Current public IP (monitored)' : 'Static public IP (monitored)'"
              />
            </VCol>
            <VCol
              v-if="form.ip_assignment === 'static'"
              cols="6"
            >
              <VTextField
                v-model="form.subnet"
                label="Subnet"
              />
            </VCol>
            <VCol
              v-if="form.ip_assignment === 'static'"
              cols="6"
            >
              <VTextField
                v-model="form.gateway_ip"
                label="Gateway IP"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.account_number"
                label="Account Number"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-if="form.isp_provider_id === null"
                v-model="form.support_phone"
                label="Support Phone"
                hint="Or select an ISP Provider above to reuse a saved number"
              />
              <VTextField
                v-else
                :model-value="providers.find(p => p.id === form.isp_provider_id)?.support_phone ?? '—'"
                label="Support Phone (from provider)"
                readonly
                disabled
              />
            </VCol>
            <VCol cols="12">
              <div class="text-caption text-medium-emphasis mt-1">
                Local Exchange Carrier (LEC) — the last-mile provider, if different from the ISP
              </div>
            </VCol>
            <VCol cols="4">
              <VTextField
                v-model="form.lec_name"
                label="LEC name"
                placeholder="AT&T, Lumen…"
              />
            </VCol>
            <VCol cols="8">
              <VTextField
                v-model="form.lec_circuit_id"
                label="LEC circuit ID"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="form.shared_site_ids"
                :items="sites.filter(s => s.id !== form.site_id).map(s => ({ title: s.name, value: s.id }))"
                label="Also serves (shared uplink)"
                multiple
                chips
                closable-chips
                clearable
                hint="Other sites this circuit's internet feeds — e.g. a CORP LAB shared to another site"
                persistent-hint
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
    v-model="isHistoryOpen"
    max-width="700"
  >
    <VCard :title="`Alert History — ${historyCircuit?.circuit_id ?? ''}`">
      <VCardText>
        <VAlert
          v-if="openHistoryAlert && previousTicket && !openHistoryAlert.ticket_number"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          <div class="d-flex align-center justify-space-between ga-3 flex-wrap">
            <span class="text-body-2">
              This circuit is down again with no ticket yet. The last ISP ticket was
              <strong>#{{ previousTicket }}</strong> — reference or reopen it.
            </span>
            <VBtn
              v-if="auth.isAdmin"
              size="small"
              variant="flat"
              color="primary"
              :loading="savingAlertId === openHistoryAlert.id"
              @click="reusePreviousTicket"
            >
              Reuse #{{ previousTicket }}
            </VBtn>
          </div>
        </VAlert>

        <VDataTable
          :headers="historyHeaders"
          :items="alerts"
          :loading="isHistoryLoading"
        >
          <template #item.started_at="{ item }">
            {{ formatDateTime(item.started_at) }}
          </template>
          <template #item.ended_at="{ item }">
            {{ item.ended_at ? formatDateTime(item.ended_at) : 'Ongoing' }}
          </template>
          <template #item.cause="{ item }">
            <VChip
              v-if="item.cause"
              size="x-small"
              :color="item.cause === 'packet_loss' ? 'warning' : 'error'"
              variant="tonal"
            >
              {{ item.cause === 'packet_loss' ? 'Packet loss' : 'Hard down' }}<span v-if="item.detected_loss_pct != null"> · {{ item.detected_loss_pct }}%</span>
            </VChip>
            <span v-else class="text-disabled">—</span>
          </template>
          <template #item.ticket_number="{ item }">
            <VTextField
              v-if="auth.isAdmin"
              v-model="item.ticket_number"
              density="compact"
              variant="outlined"
              hide-details
              @blur="saveTicketNumber(item)"
            />
            <span v-else>{{ item.ticket_number ?? '—' }}</span>
            <VProgressCircular
              v-if="savingAlertId === item.id"
              indeterminate
              size="16"
              width="2"
              class="ms-2"
            />
          </template>
        </VDataTable>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isRtGraphOpen"
    max-width="900"
  >
    <VCard :title="`Response Time — ${rtGraphCircuit?.isp_name ?? ''} ${rtGraphCircuit?.circuit_id ?? ''}`">
      <template #append>
        <VSelect
          v-model="rtGraphRange"
          :items="graphRangeOptions"
          density="compact"
          style="width: 140px;"
        />
      </template>
      <VCardText>
        <div class="text-caption text-medium-emphasis mb-2">
          Round-trip latency in milliseconds. <span style="color:#ef4444;font-weight:600">Red</span> = unreachable, <span style="color:#f59e0b;font-weight:600">amber</span> = packet loss.
        </div>
        <div
          v-if="isRtGraphLoading"
          class="d-flex justify-center pa-8"
        >
          <VProgressCircular indeterminate />
        </div>
        <VueApexCharts
          v-else
          type="line"
          height="280"
          :options="rtChartOptions"
          :series="rtSeries"
        />
      </VCardText>
    </VCard>
  </VDialog>

  <CircuitOutageDialog
    v-model="isOutageOpen"
    :circuit="outageCircuit"
    @updated="onOutageUpdated"
  />
</template>

<style scoped>
.status-dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.cursor-pointer {
  cursor: pointer;
}
.circuit-detail {
  background: rgba(var(--v-theme-on-surface), 0.015);
  border-block-start: 2px solid rgb(var(--v-theme-primary));
  padding-block: 22px !important;
}

/* Section header inside the expanded row — small caps kicker with a hairline. */
.detail-kicker {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.5);
  padding-block-end: 8px;
  margin-block-end: 14px;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), 0.09);
}

/* Reflow the identity pairs by width instead of forcing a cramped 2-up. */
.detail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 16px 30px;
}
.detail-grid > div .text-caption { margin-block-end: 2px; }

.isp-card { max-inline-size: 440px; }

.overview-table :deep(th) {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.6;
}
.overview-table :deep(td),
.overview-table :deep(th) { white-space: nowrap; }
.circuit-detail a {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
</style>
