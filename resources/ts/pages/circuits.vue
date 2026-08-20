<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useChartMode } from '@/composables/useChartTheme'
import { useAuthStore } from '@/stores/auth'
import { easternChartMs, formatDateTime } from '@/utils/datetime'
import type { Circuit, CircuitAlert, CircuitMetric, CircuitRenewal, IspProvider, Site } from '@/types/models'

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
// Contract renewal history — lazy-loaded alongside the outage history on expand.
const renewalsByCircuit = ref<Record<number, CircuitRenewal[]>>({})
async function loadRenewals(id: number) {
  if (renewalsByCircuit.value[id])
    return
  try {
    renewalsByCircuit.value[id] = await api<CircuitRenewal[]>(`/api/circuits/${id}/renewals`)
  }
  catch { /* leave unset — retried on next expand */ }
}
watch(expanded, ids => ids.forEach((id) => {
  loadCircuitAlerts(id)
  loadRenewals(id)
}))

// Renew-contract dialog (admin): a new end date, or a term to compute it, + a note.
const isRenewOpen = ref(false)
const isRenewing = ref(false)
const renewCircuit = ref<Circuit | null>(null)
const renewForm = ref({ new_end_date: '', term_months: null as number | null, note: '' })

function openRenew(circuit: Circuit) {
  renewCircuit.value = circuit
  renewForm.value = { new_end_date: '', term_months: circuit.contract_term_months ?? null, note: '' }
  isRenewOpen.value = true
}
async function submitRenew() {
  if (!renewCircuit.value)
    return
  isRenewing.value = true
  try {
    await api(`/api/circuits/${renewCircuit.value.id}/renew`, {
      method: 'POST',
      body: {
        new_end_date: renewForm.value.new_end_date || null,
        term_months: renewForm.value.term_months || null,
        note: renewForm.value.note || null,
      },
    })
    isRenewOpen.value = false
    delete renewalsByCircuit.value[renewCircuit.value.id]
    await loadData()
    await loadRenewals(renewCircuit.value.id)
  }
  finally { isRenewing.value = false }
}

const contractMeta: Record<string, { color: string, label: string }> = {
  expired: { color: 'error', label: 'Expired' },
  warning: { color: 'error', label: 'Expiring ≤30d' },
  notice: { color: 'warning', label: 'Expiring ≤60d' },
  ok: { color: 'success', label: 'Active' },
  none: { color: 'secondary', label: 'No contract' },
}

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

// The by-type default SLA target shown as a placeholder when no override is set.
// Circuit-editor section rail
const formSections = [
  { id: 'sec-identity', n: '1', label: 'Identity' },
  { id: 'sec-conn', n: '2', label: 'Connectivity' },
  { id: 'sec-contract', n: '3', label: 'Contract' },
  { id: 'sec-carrier', n: '4', label: 'Carrier & contacts' },
  { id: 'sec-advanced', n: '5', label: 'Advanced' },
]
const activeSection = ref('sec-identity')
const ceContent = ref<HTMLElement>()
function scrollToSection(id: string) {
  activeSection.value = id
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

const SLA_DEFAULT_BY_TYPE: Record<string, number> = { fiber: 99.5, cable: 99.5, lte: 99.5 }
function slaDefaultForType(type: string | null | undefined) {
  return SLA_DEFAULT_BY_TYPE[type ?? ''] ?? 99.5
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
// Max xaxis annotations we hand ApexCharts. A flapping circuit (down/up/down every
// poll) yields one problem point per poll — 700+ over a day — and that many xaxis
// annotations freezes the chart (and the tab) for seconds. Coalescing across brief
// recoveries keeps a bad stretch to ONE band; this cap is the final backstop.
const MAX_BANDS = 60

function problemBands(metrics: { recorded_at: string, response_time_ms: number | null, loss_pct?: number | null }[]) {
  const pts = metrics.map(m => ({
    // Same shifted scale as the series, or the bands would sit hours off the line.
    t: easternChartMs(m.recorded_at),
    kind: m.response_time_ms === null ? 'down' : ((m.loss_pct ?? 0) > 0 ? 'loss' : 'ok'),
  }))
  const gaps = pts.slice(1).map((p, i) => p.t - pts[i].t).filter(g => g > 0).sort((a, b) => a - b)
  const step = gaps.length ? gaps[Math.floor(gaps.length / 2)] : 60_000

  // Raw contiguous problem spans, tagged 'down' (worse) or 'loss'.
  const raw: { start: number, end: number, down: boolean }[] = []
  let i = 0
  while (i < pts.length) {
    if (pts[i].kind === 'ok') { i++; continue }
    const start = pts[i].t
    let end = pts[i].t
    let down = false
    while (i < pts.length && pts[i].kind !== 'ok') {
      down = down || pts[i].kind === 'down'
      end = pts[i].t
      i++
    }
    raw.push({ start, end, down })
  }

  // Merge spans separated only by a brief recovery (a few polls) so a flapping
  // circuit reads as one degraded stretch, not hundreds of hairline bands. The
  // merged span is 'down' if any part was down.
  const mergeGap = step * 5
  const merged: typeof raw = []
  for (const s of raw) {
    const last = merged[merged.length - 1]
    if (last && s.start - last.end <= mergeGap) {
      last.end = s.end
      last.down = last.down || s.down
    }
    else { merged.push({ ...s }) }
  }

  // Backstop: if a pathological series still exceeds the cap, keep the longest.
  const capped = merged.length > MAX_BANDS
    ? [...merged].sort((a, b) => (b.end - b.start) - (a.end - a.start)).slice(0, MAX_BANDS)
    : merged

  return capped.map(s => ({
    x: s.start - step / 2,
    x2: s.end + step / 2,
    fillColor: s.down ? '#ef4444' : '#f59e0b',
    opacity: s.down ? 0.16 : 0.12,
    borderWidth: 0,
  }))
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
    sla_target_pct: 99.5 as number | null,
    contract_down_mbps: null as number | null,
    contract_up_mbps: null as number | null,
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
    install_date: '' as string,
    contract_end_date: '' as string,
    contract_term_months: null as number | null,
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
  if (c.transport_degraded) return 'degraded'
  if ((c.sustained_loss_pct ?? c.last_loss_pct ?? 0) >= 20) return 'degraded'
  return 'up'
}
const statusFilter = ref<CircuitStatusKey | null>(null)

// Type tabs (All / Fiber / Cable / LTE) — the top category strip.
const typeFilter = ref<string | null>(null)
const typeScoped = computed(() =>
  typeFilter.value ? circuitRows.value.filter(c => (c.circuit_type ?? '') === typeFilter.value) : circuitRows.value)
const typeTabs = computed(() => {
  const c: Record<string, number> = { fiber: 0, cable: 0, lte: 0 }
  for (const x of circuits.value)
    if ((x.circuit_type ?? '') in c) c[x.circuit_type as string]++
  return [
    // Category dots use non-status hues (blue/violet/cyan/slate) — never the
    // green/amber/red reserved for severity, so they can't be misread as health.
    { value: null, label: 'All', count: circuits.value.length, color: '#7C8AA0' },
    { value: 'fiber', label: 'Fiber', count: c.fiber, color: '#4C8DFF' },
    { value: 'cable', label: 'Cable', count: c.cable, color: '#8B7CF6' },
    { value: 'lte', label: 'LTE', count: c.lte, color: '#06B6D4' },
  ]
})

const filteredRows = computed(() =>
  statusFilter.value ? typeScoped.value.filter(c => circuitStatusOf(c) === statusFilter.value) : typeScoped.value,
)
const statusCounts = computed(() => {
  const acc = { up: 0, down: 0, degraded: 0, maintenance: 0 }
  for (const c of typeScoped.value) acc[circuitStatusOf(c)]++
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
    sla_target_pct: circuit.sla_target_pct ?? null,
    contract_down_mbps: circuit.contract_down_mbps ?? null,
    contract_up_mbps: circuit.contract_up_mbps ?? null,
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
    install_date: circuit.install_date ?? '',
    contract_end_date: circuit.contract_end_date ?? '',
    contract_term_months: circuit.contract_term_months ?? null,
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
  <div>
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">Circuits</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          WAN links per site — ISP, type, live status and response time.
        </p>
      </div>
      <VBtn v-if="auth.isAdmin" @click="openCreateDialog">
        Add Circuit
      </VBtn>
    </div>

    <ListTabs v-model="typeFilter" :tabs="typeTabs" class="mt-4" />

    <VCard class="list-surface">
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
      <VSpacer />
      <div class="list-pills">
        <button
          v-for="s in statusChips"
          :key="s.key"
          type="button"
          class="list-pill"
          :class="{ 'list-pill--on': statusFilter === s.key }"
          @click="statusFilter = statusFilter === s.key ? null : s.key"
        >
          <span class="list-pill__d" :style="{ background: `rgb(var(--v-theme-${s.color}))` }" />
          {{ s.label }} · {{ statusCounts[s.key] }}
        </button>
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
          v-else-if="item.transport_degraded"
          size="small"
          color="warning"
          variant="flat"
          prepend-icon="ri-alert-line"
          :title="`${item.transport_reason} — the EdgeConnect's IP-SLA/gateway alarm shows this WAN transport failing (tunnels drop) even though the gateway ICMP still answers 0% loss`"
        >
          Transport degraded
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

              <!-- Contract accountability: install / expiration, renewal history -->
              <VRow class="mt-2">
                <VCol cols="12">
                  <div class="detail-kicker">Contract</div>
                  <VCard variant="tonal" class="pa-3">
                    <div class="d-flex align-center flex-wrap ga-4">
                      <div>
                        <div class="text-caption text-medium-emphasis">Installed</div>
                        <div class="text-body-2">{{ item.install_date ?? '—' }}</div>
                      </div>
                      <div>
                        <div class="text-caption text-medium-emphasis">Expires</div>
                        <div class="text-body-2">{{ item.contract_end_date ?? '—' }}</div>
                      </div>
                      <div>
                        <div class="text-caption text-medium-emphasis">Contract bandwidth</div>
                        <div class="text-body-2">{{ (item.contract_down_mbps || item.contract_up_mbps) ? `${item.contract_down_mbps ?? '—'} / ${item.contract_up_mbps ?? '—'} Mbps` : '—' }}</div>
                      </div>
                      <VChip
                        v-if="item.contract_status && item.contract_status !== 'none'"
                        size="small"
                        :color="contractMeta[item.contract_status].color"
                        variant="flat"
                      >
                        {{ contractMeta[item.contract_status].label }}
                        <template v-if="item.days_to_expiry != null">
                          · {{ item.days_to_expiry >= 0 ? `${item.days_to_expiry}d left` : `${-item.days_to_expiry}d ago` }}
                        </template>
                      </VChip>
                      <VSpacer />
                      <VBtn
                        v-if="auth.isAdmin"
                        size="small"
                        color="primary"
                        variant="tonal"
                        prepend-icon="ri-refresh-line"
                        @click="openRenew(item)"
                      >
                        Renew
                      </VBtn>
                    </div>

                    <template v-if="(renewalsByCircuit[item.id] ?? []).length">
                      <VDivider class="my-3" />
                      <div class="text-caption text-medium-emphasis mb-1">Renewal history</div>
                      <div
                        v-for="r in renewalsByCircuit[item.id]"
                        :key="r.id"
                        class="d-flex align-center ga-2 text-body-2 py-1"
                      >
                        <VIcon icon="ri-arrow-right-line" size="14" />
                        <span>{{ r.previous_end_date ?? '—' }} → <strong>{{ r.new_end_date }}</strong></span>
                        <span v-if="r.term_months" class="text-caption text-medium-emphasis">· {{ r.term_months }}mo</span>
                        <span v-if="r.note" class="text-caption text-medium-emphasis">· {{ r.note }}</span>
                        <VSpacer />
                        <span class="text-caption text-medium-emphasis">
                          {{ r.renewed_by_name ?? '—' }} · {{ formatDateTime(r.created_at) }}
                        </span>
                      </div>
                    </template>
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
    max-width="820"
    scrollable
  >
    <VCard class="circuit-editor">
      <!-- header -->
      <div class="ce-head">
        <div class="ce-ic"><VIcon icon="ri-plug-line" /></div>
        <div class="ce-title">
          <h2>{{ editingCircuit ? 'Edit Circuit' : 'Add Circuit' }}</h2>
          <p v-if="editingCircuit">{{ form.isp_name || 'ISP' }} · {{ form.circuit_id || '—' }}</p>
          <p v-else>Register a WAN circuit and how Nodus monitors it.</p>
        </div>
        <VBtn icon="ri-close-line" variant="text" size="small" class="ce-x" @click="isDialogOpen = false" />
      </div>

      <VForm @submit.prevent="saveCircuit">
        <div class="ce-body">
          <!-- section rail -->
          <nav class="ce-rail">
            <button v-for="s in formSections" :key="s.id" type="button"
              class="ce-rail-btn" :class="{ on: activeSection === s.id }" @click="scrollToSection(s.id)">
              <span class="ce-rail-n">{{ s.n }}</span> {{ s.label }}
            </button>
          </nav>

          <!-- scrollable content -->
          <div ref="ceContent" class="ce-content">
            <VAlert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mb-4">{{ errorMessage }}</VAlert>

            <!-- 1 Identity -->
            <section id="sec-identity" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">01</span><h3>Identity</h3></div>
              <p class="ce-sub">Which site this circuit serves and who provides it.</p>
              <VRow dense>
                <VCol cols="12">
                  <VAutocomplete v-model="form.site_id" :items="sites.map(s => ({ title: s.name, value: s.id }))"
                    label="Site" :menu-props="{ maxHeight: 320 }" auto-select-first placeholder="Type to search sites…" variant="outlined" density="comfortable" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VSelect v-model="form.isp_provider_id" :items="providers.map(p => ({ title: p.name, value: p.id }))"
                    label="ISP provider" clearable variant="outlined" density="comfortable"
                    hint="Reuses the provider's saved support phone & contacts" persistent-hint />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.isp_name" label="ISP name" :disabled="form.isp_provider_id !== null"
                    variant="outlined" density="comfortable" hint="Auto-filled from the provider" persistent-hint />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.circuit_id" label="Circuit ID" variant="outlined" density="comfortable" class="ce-mono" />
                </VCol>
                <VCol cols="12" sm="6">
                  <div class="ce-flabel">Circuit type</div>
                  <VBtnToggle v-model="form.circuit_type" mandatory divided density="comfortable" color="primary" class="ce-seg">
                    <VBtn v-for="o in circuitTypeOptions" :key="o.value" :value="o.value" size="small">{{ o.title }}</VBtn>
                  </VBtnToggle>
                </VCol>
              </VRow>
            </section>

            <!-- 2 Connectivity -->
            <section id="sec-conn" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">02</span><h3>Connectivity &amp; monitoring</h3></div>
              <p class="ce-sub">How Nodus reaches and measures the circuit.</p>
              <VRow dense>
                <VCol cols="12" sm="6">
                  <div class="ce-flabel">IP assignment</div>
                  <VBtnToggle v-model="form.ip_assignment" mandatory divided density="comfortable" color="primary" class="ce-seg">
                    <VBtn value="static" size="small">Static</VBtn>
                    <VBtn value="dhcp" size="small">DHCP</VBtn>
                  </VBtnToggle>
                </VCol>
                <VCol cols="12" sm="6">
                  <div class="ce-flabel">Monitor via</div>
                  <VBtnToggle v-model="form.monitor_via" mandatory divided density="comfortable" color="primary" class="ce-seg">
                    <VBtn value="icmp" size="small">Direct ICMP</VBtn>
                    <VBtn value="sdwan" size="small">SD‑WAN sourced</VBtn>
                  </VBtnToggle>
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.monitored_ip" class="ce-mono"
                    :label="form.ip_assignment === 'dhcp' ? 'Current public IP (monitored)' : 'Static public IP (monitored)'"
                    variant="outlined" density="comfortable" />
                </VCol>
                <VCol v-if="form.ip_assignment === 'static'" cols="12" sm="6">
                  <VTextField v-model="form.gateway_ip" label="Gateway IP" variant="outlined" density="comfortable" class="ce-mono" />
                </VCol>
                <VCol v-if="form.ip_assignment === 'static'" cols="12">
                  <VTextField v-model="form.subnet" label="Subnet" variant="outlined" density="comfortable" class="ce-mono" placeholder="e.g. 203.0.113.0/29" />
                </VCol>
                <VCol v-if="form.monitor_via === 'sdwan'" cols="12">
                  <div class="ce-cond">
                    <div class="ce-cond-lbl"><VIcon icon="ri-corner-down-right-line" size="14" /> SD‑WAN sourced ping</div>
                    <VRow dense>
                      <VCol cols="12" sm="6">
                        <VSelect v-model="form.wan_interface" :items="['wan0', 'wan1', 'wan2', 'wan3']"
                          label="WAN interface" variant="outlined" density="comfortable" hint="wan0 = cable modem (Massey)" persistent-hint />
                      </VCol>
                      <VCol cols="12" sm="6">
                        <VTextField v-model="form.ping_target" label="Ping target" variant="outlined" density="comfortable"
                          class="ce-mono" placeholder="8.8.8.8" hint="Public host to ping from the WAN" persistent-hint />
                      </VCol>
                    </VRow>
                  </div>
                </VCol>
              </VRow>
            </section>

            <!-- 3 Contract -->
            <section id="sec-contract" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">03</span><h3>Contract</h3></div>
              <p class="ce-sub">What you pay for and hold the ISP to.</p>
              <VRow dense>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.install_date" label="Install date" type="date" variant="outlined" density="comfortable" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.contract_end_date" label="Contract expires" type="date" variant="outlined" density="comfortable" />
                </VCol>
                <VCol cols="6" sm="4">
                  <VTextField v-model.number="form.contract_down_mbps" label="Download" type="number" min="0"
                    variant="outlined" density="comfortable" class="ce-mono" suffix="Mbps" placeholder="300" />
                </VCol>
                <VCol cols="6" sm="4">
                  <VTextField v-model.number="form.contract_up_mbps" label="Upload" type="number" min="0"
                    variant="outlined" density="comfortable" class="ce-mono" suffix="Mbps" placeholder="20" />
                </VCol>
                <VCol cols="12" sm="4">
                  <VTextField v-model.number="form.sla_target_pct" label="SLA target" type="number" step="0.1" min="0" max="100"
                    variant="outlined" density="comfortable" class="ce-mono" suffix="%" clearable
                    :placeholder="`${slaDefaultForType(form.circuit_type)}`" hint="Blank = 99.5% default" persistent-hint />
                </VCol>
              </VRow>
            </section>

            <!-- 4 Carrier & contacts -->
            <section id="sec-carrier" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">04</span><h3>Carrier &amp; contacts</h3></div>
              <p class="ce-sub">Last‑mile carrier (LEC) and who to call.</p>
              <VRow dense>
                <VCol cols="12" sm="6">
                  <VTextField v-model="form.account_number" label="Account number" variant="outlined" density="comfortable" class="ce-mono" />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField v-if="form.isp_provider_id === null" v-model="form.support_phone" label="Support phone"
                    variant="outlined" density="comfortable" hint="Or pick an ISP provider to reuse a saved number" persistent-hint />
                  <VTextField v-else :model-value="providers.find(p => p.id === form.isp_provider_id)?.support_phone ?? '—'"
                    label="Support phone (from provider)" readonly disabled variant="outlined" density="comfortable" />
                </VCol>
                <VCol cols="12" sm="5">
                  <VTextField v-model="form.lec_name" label="LEC name" placeholder="AT&T, Lumen…" variant="outlined" density="comfortable" />
                </VCol>
                <VCol cols="12" sm="7">
                  <VTextField v-model="form.lec_circuit_id" label="LEC circuit ID" variant="outlined" density="comfortable" class="ce-mono" />
                </VCol>
              </VRow>
            </section>

            <!-- 5 Advanced -->
            <section id="sec-advanced" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">05</span><h3>Advanced</h3></div>
              <p class="ce-sub">Shared uplinks and free‑form notes.</p>
              <VRow dense>
                <VCol cols="12">
                  <VSelect v-model="form.shared_site_ids"
                    :items="sites.filter(s => s.id !== form.site_id).map(s => ({ title: s.name, value: s.id }))"
                    label="Also serves (shared uplink)" multiple chips closable-chips clearable variant="outlined" density="comfortable"
                    hint="Other sites this circuit's internet feeds" persistent-hint />
                </VCol>
                <VCol cols="12">
                  <VTextarea v-model="form.notes" label="Notes" variant="outlined" rows="2" auto-grow density="comfortable" />
                </VCol>
              </VRow>
            </section>
          </div>
        </div>

        <!-- footer -->
        <div class="ce-foot">
          <span class="ce-status">{{ form.site_id && form.circuit_id && form.monitored_ip ? 'Ready to save' : 'Site, Circuit ID and monitored IP are required' }}</span>
          <VBtn variant="text" @click="isDialogOpen = false">Cancel</VBtn>
          <VBtn type="submit" color="primary" :loading="isSaving">{{ editingCircuit ? 'Save circuit' : 'Add circuit' }}</VBtn>
        </div>
      </VForm>
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

  <!-- Renew contract (admin) — new end date or a term, logged to the trail -->
  <VDialog v-model="isRenewOpen" max-width="460">
    <VCard :title="`Renew Contract — ${renewCircuit?.circuit_id ?? ''}`">
      <VCardText class="d-flex flex-column ga-4 pt-2">
        <div class="text-caption text-medium-emphasis">
          Current expiration: <strong>{{ renewCircuit?.contract_end_date ?? '—' }}</strong>.
          Enter a new date, or a term in months to compute it from the current end.
        </div>
        <VTextField v-model="renewForm.new_end_date" label="New expiration date" type="date" hide-details />
        <div class="text-caption text-center text-disabled">— or —</div>
        <VTextField v-model.number="renewForm.term_months" label="Renew for (months)" type="number" placeholder="36" hide-details />
        <VTextField v-model="renewForm.note" label="Note (optional)" placeholder="Renewed at same MRC, PO #…" hide-details />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn variant="text" @click="isRenewOpen = false">Cancel</VBtn>
        <VBtn color="primary" :loading="isRenewing" @click="submitRenew">Renew</VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
  </div>
</template>

<style scoped>
/* --- circuit editor (sectioned dialog) --- */
.circuit-editor { display: flex; flex-direction: column; max-height: 88vh; }
.ce-head { display: flex; align-items: center; gap: 12px; padding: 16px 20px; border-bottom: 1px solid rgba(var(--v-theme-on-surface), .1); }
.ce-ic { width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; background: rgba(var(--v-theme-primary), .14); color: rgb(var(--v-theme-primary)); }
.ce-title h2 { font-size: 18px; font-weight: 800; letter-spacing: -.01em; margin: 0; line-height: 1.2; }
.ce-title p { margin: 1px 0 0; font-size: 12.5px; color: rgba(var(--v-theme-on-surface), .6); }
.ce-x { margin-left: auto; }
.ce-body { display: grid; grid-template-columns: 178px 1fr; min-height: 0; flex: 1; }
.ce-rail { border-right: 1px solid rgba(var(--v-theme-on-surface), .1); padding: 12px 8px; background: rgba(var(--v-theme-on-surface), .02); }
.ce-rail-btn { display: flex; align-items: center; gap: 9px; width: 100%; text-align: left; border: 0; background: none; font: inherit; font-size: 13px; font-weight: 600; color: rgba(var(--v-theme-on-surface), .6); padding: 9px 10px; border-radius: 8px; cursor: pointer; }
.ce-rail-btn:hover { color: rgb(var(--v-theme-on-surface)); }
.ce-rail-btn.on { background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-on-surface)); box-shadow: 0 1px 2px rgba(0,0,0,.1); }
.ce-rail-n { width: 20px; height: 20px; border-radius: 6px; display: grid; place-items: center; font-size: 11px; font-family: 'IBM Plex Mono', monospace; background: rgba(var(--v-theme-on-surface), .1); color: rgba(var(--v-theme-on-surface), .6); }
.ce-rail-btn.on .ce-rail-n { background: rgb(var(--v-theme-primary)); color: #fff; }
.ce-content { padding: 18px 22px 8px; overflow-y: auto; }
.ce-sect { margin-bottom: 24px; scroll-margin-top: 8px; }
.ce-sh { display: flex; align-items: baseline; gap: 9px; }
.ce-sh h3 { font-size: 14.5px; font-weight: 750; margin: 0; letter-spacing: -.01em; }
.ce-num { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: rgba(var(--v-theme-on-surface), .4); }
.ce-sub { font-size: 12px; color: rgba(var(--v-theme-on-surface), .55); margin: 2px 0 12px; }
.ce-flabel { font-size: 11.5px; font-weight: 500; color: rgba(var(--v-theme-on-surface), .7); margin-bottom: 6px; }
.ce-seg { width: 100%; }
.ce-seg :deep(.v-btn) { flex: 1; }
.ce-cond { border-left: 2px solid rgba(var(--v-theme-primary), .5); padding: 4px 0 4px 14px; margin-top: 2px; }
.ce-cond-lbl { font-size: 11.5px; color: rgb(var(--v-theme-primary)); font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
.ce-mono :deep(input) { font-family: 'IBM Plex Mono', monospace; }
.ce-foot { display: flex; align-items: center; gap: 10px; padding: 13px 20px; border-top: 1px solid rgba(var(--v-theme-on-surface), .1); background: rgba(var(--v-theme-on-surface), .02); }
.ce-status { font-size: 12px; color: rgba(var(--v-theme-on-surface), .6); margin-right: auto; }
@media (max-width: 640px) { .ce-body { grid-template-columns: 1fr; } .ce-rail { display: none; } }

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
