<script setup lang="ts">
import { stackedCellProps } from '@/utils/stackedTable'
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import { easternInputToIso, easternInputValue, formatDateTime } from '@/utils/datetime'

interface Anomaly {
  id: number
  entity_type: 'interface' | 'circuit'
  entity: string
  sub: string | null
  site_name: string | null
  metric: string
  metric_key: 'throughput' | 'discards' | 'errors' | 'latency' | 'loss'
  direction: 'spike' | 'drop'
  baseline: number
  observed: number
  z_score: number
  series: number[]
  detected_at: string
  last_seen_at: string
  route: string | null
  /**
   * Circuit findings only. A degrade that never drops the circuit produces no alarm, so
   * the ISP fields the alarm surfaces carry had nowhere to live — this is the same
   * circuit-level ticket and dispatch, reachable from the finding that justifies it.
   */
  isp: {
    circuit_id: number
    circuit_code: string | null
    isp_name: string | null
    support_phone: string | null
    isp_ticket: string | null
    dispatch_at: string | null
    dispatch_end_at: string | null
    dispatch_note: string | null
    isp_ticket_url: string
    dispatch_url: string
  } | null
}
/**
 * Several circuits on one carrier losing packets at the same time.
 *
 * The prefix breakdown is the whole point: loss that follows the DESTINATION cannot be
 * caused by anything shared on our side, because every probe leaves through the same
 * firewall and the same head-end port. The clean prefixes are the evidence.
 */
interface Correlation {
  isp_name: string
  circuits_total: number
  circuits_degraded: number
  degraded_pct: number
  peak_loss_pct: number
  since: string | null
  suspect_hop: { host: string, named_by: number, of_probed: number, worst_loss_pct: number } | null
  prefixes: { prefix: string, total: number, degraded: number, degraded_pct: number, hot: boolean }[]
  hot_prefixes: { prefix: string, total: number, degraded: number, degraded_pct: number }[]
  clean_prefixes: { prefix: string, total: number, degraded: number, degraded_pct: number }[]
  fleet: { circuits_total: number, circuits_degraded: number, other_isps_degraded: number }
  circuits: {
    id: number
    circuit_id: string | null
    lec_circuit_id: string | null
    account_number: string | null
    site_name: string | null
    site_number: string | null
    monitored_ip: string
    prefix: string
    loss_polls_pct: number
    loss_peak_pct: number
    degraded_since: string | null
    isp_ticket: string | null
  }[]
  caveat: string
}
interface Summary {
  open: number
  by_metric: { throughput: number, discards: number, errors: number, latency: number, loss: number }
  by_type: { interface: number, circuit: number }
  worst_z: number
  oldest_at: string | null
}

const rows = ref<Anomaly[]>([])
const summary = ref<Summary | null>(null)
const correlations = ref<Correlation[]>([])
const openCorrelation = ref<string | null>(null)
const loading = ref(true)
const metricFilter = ref<string | null>(null)
const typeFilter = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const res = await api<{ data: Anomaly[], summary: Summary, correlations: Correlation[] }>('/api/anomalies')
    rows.value = res.data
    summary.value = res.summary
    correlations.value = res.correlations ?? []
  }
  finally { loading.value = false }
}
let poll: ReturnType<typeof setInterval> | null = null
onMounted(() => { load(); poll = setInterval(load, 60000) })
onBeforeUnmount(() => { if (poll) clearInterval(poll) })

const filtered = computed(() => rows.value.filter(a =>
  (!metricFilter.value || a.metric_key === metricFilter.value)
  && (!typeFilter.value || a.entity_type === typeFilter.value)))

/**
 * The finding as plain text a carrier can act on, ready to paste into a ticket or mail.
 *
 * The NOC had to read numbers off a screen to a Lumen tech on a live call. What the
 * carrier actually needs is the circuit IDs — theirs, not ours — the onset, the hop, and
 * the scope, in one block. Everything here comes off the same payload the card renders,
 * so the pasted text and the screen can never disagree.
 */
function ispReport(c: Correlation) {
  const pad = (v: string | number | null, w: number) => String(v ?? '—').padEnd(w)
  const lines = [
    `${c.isp_name} — packet loss across ${c.circuits_degraded} circuits`,
    `Reported by: Massey Services NOC`,
    `Started: ${startedAt(c.since)}`,
    `Scope: ${c.circuits_degraded} of ${c.circuits_total} monitored ${c.isp_name} circuits affected; `
      + `${c.fleet.other_isps_degraded} of ${c.fleet.circuits_total - c.circuits_total} circuits on all other carriers affected.`,
  ]
  if (c.suspect_hop) {
    lines.push(`Suspect hop: ${c.suspect_hop.host} — named by ${c.suspect_hop.named_by} of `
      + `${c.suspect_hop.of_probed} traced circuits, ${c.suspect_hop.worst_loss_pct}% loss at that hop.`)
  }
  lines.push('', 'Affected circuits:')
  lines.push(`  ${pad('Circuit ID', 20)}${pad('LEC circuit ID', 24)}${pad('Site', 28)}${pad('Monitored IP', 17)}Loss`)
  for (const x of c.circuits) {
    lines.push(`  ${pad(x.circuit_id, 20)}${pad(x.lec_circuit_id, 24)}`
      + `${pad(`${x.site_number ? `#${x.site_number} ` : ''}${x.site_name ?? ''}`.trim(), 28)}`
      + `${pad(x.monitored_ip, 17)}lossy on ${x.loss_polls_pct}% of polls, peak ${x.loss_peak_pct}%`)
  }
  lines.push('', 'Destination breakdown — same source, same egress, same minute:')
  for (const p of c.prefixes)
    lines.push(`  ${pad(p.prefix, 18)}${p.degraded} of ${p.total} circuits affected (${p.degraded_pct}%)`)

  lines.push('', `Measurement: ICMP echo to each circuit's gateway address, every 60s.`, c.caveat)

  return lines.join('\n')
}

/**
 * The wall-clock start, which is the thing the carrier asks for on the call, plus how
 * long it has run. Never "just now" when the poller has not stamped a crossing —
 * unstamped must read as unmeasured, not as recent.
 */
function startedAt(iso: string | null) {
  return iso ? `${formatDateTime(iso)} (${since(iso)} ago)` : 'start not recorded'
}

function fmtBytes(n: number) {
  const u = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = Math.abs(n); let i = 0
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v >= 100 || i === 0 ? 0 : 1)} ${u[i]}`
}
function fmtVal(a: Anomaly, v: number) {
  return a.metric_key === 'throughput' ? fmtBytes(v) : a.metric_key === 'latency' ? `${v.toFixed(0)} ms` : a.metric_key === 'loss' ? `${v.toFixed(1)}%` : v.toFixed(0)
}
function deviation(a: Anomaly) {
  const mult = a.baseline > 0 ? a.observed / a.baseline : 0
  return a.direction === 'drop' ? `${(mult * 100).toFixed(0)}%` : `${mult.toFixed(mult >= 10 ? 0 : 1)}×`
}
// Build a baseline-band sparkline: the shaded expected range (baseline ± the detector's
// threshold), a dashed baseline centreline, the actual series line, and a marker on the
// breach point — so an anomaly reads visually (the series leaving its own band), the way
// good anomaly tools show it, instead of a bare wiggle.
const SPARK_W = 108
const SPARK_H = 30
function spark(a: Anomaly) {
  const s = a.series ?? []
  if (!s.length) return null
  // Detector uses robust z (|z|>3). Recover the band half-width from baseline/observed/z:
  // z = (observed-baseline)/scaledMAD → band half-width ≈ 3·scaledMAD.
  const half = Math.abs(a.z_score) > 0.01 ? (3 * Math.abs(a.observed - a.baseline)) / Math.abs(a.z_score) : Math.abs(a.observed - a.baseline) || 1
  const bandLo = a.baseline - half
  const bandHi = a.baseline + half
  const lo = Math.min(...s, bandLo)
  const hi = Math.max(...s, bandHi)
  const rng = hi - lo || 1
  const x = (i: number) => (i / Math.max(1, s.length - 1)) * SPARK_W
  const y = (v: number) => SPARK_H - ((v - lo) / rng) * (SPARK_H - 2) - 1
  const line = s.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ')
  return {
    line,
    bandY: y(bandHi),
    bandH: Math.max(1, y(bandLo) - y(bandHi)),
    baseY: y(a.baseline),
    endX: x(s.length - 1),
    endY: y(s[s.length - 1]),
  }
}
function since(iso: string) {
  const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000))
  if (s < 60) return `${s}m`
  const h = Math.floor(s / 60)
  return h < 24 ? `${h}h ${String(s % 60).padStart(2, '0')}m` : `${Math.floor(h / 24)}d ${h % 24}h`
}
function oldestSince() { return summary.value?.oldest_at ? since(summary.value.oldest_at) : '—' }

// Severity band from |z| (drives the row's severity dot + sort emphasis).
function sev(z: number): 'high' | 'med' | 'low' {
  const az = Math.abs(z)
  return az >= 8 ? 'high' : az >= 5 ? 'med' : 'low'
}

const auth = useAuthStore()

// Logging the carrier's ticket against the finding that caused it. The endpoints are
// the CIRCUIT-level ones: the alert-level pair creates an outage row when none is open,
// and a circuit with packet loss that never dropped has not had an outage.
const ticketOpen = ref(false)
const ticketSaving = ref(false)
const ticketError = ref('')
const ticketFor = ref<Anomaly | null>(null)
const ticketForm = ref({ isp_ticket: '', dispatch_at: '', dispatch_end_at: '', dispatch_note: '' })

/** What this ticket was raised for, in the words the report will show. */
function ticketReason(a: Anomaly): string {
  return `${a.metric} ${a.direction === 'drop' ? 'below' : 'above'} baseline — ${fmtVal(a, a.observed)} against ${fmtVal(a, a.baseline)}`
}

const ticketHistory = ref<{ ticket_number: string, reason: string | null, opened_at: string | null, opened_by: string | null, closed_at: string | null, open: boolean }[]>([])

async function openTicket(a: Anomaly) {
  if (!a.isp)
    return
  ticketError.value = ''
  ticketFor.value = a
  ticketHistory.value = []
  try {
    const h = await api<{ data: typeof ticketHistory.value }>(`/api/circuits/${a.isp.circuit_id}/isp-tickets`)
    ticketHistory.value = h.data
  }
  catch {
    // A missing trail must not stop somebody logging a ticket.
  }
  ticketForm.value = {
    isp_ticket: a.isp.isp_ticket ?? '',
    dispatch_at: a.isp.dispatch_at ? easternInputValue(a.isp.dispatch_at) : '',
    dispatch_end_at: a.isp.dispatch_end_at ? easternInputValue(a.isp.dispatch_end_at) : '',
    dispatch_note: a.isp.dispatch_note ?? '',
  }
  ticketOpen.value = true
}

async function saveTicket() {
  const isp = ticketFor.value?.isp
  if (!isp)
    return
  ticketSaving.value = true
  ticketError.value = ''
  try {
    // The reason travels with the ticket. A number on its own is findable later but
    // not explicable — "Packet loss above baseline — 6.4% against 0.2%" is what makes
    // the trail worth keeping.
    await api(isp.isp_ticket_url, {
      method: 'POST',
      body: {
        isp_ticket: ticketForm.value.isp_ticket || null,
        reason: ticketFor.value ? ticketReason(ticketFor.value) : null,
        anomaly_id: ticketFor.value?.id ?? null,
        note: ticketForm.value.dispatch_note || null,
      },
    })
    await api(isp.dispatch_url, {
      method: 'POST',
      body: {
        dispatch_at: easternInputToIso(ticketForm.value.dispatch_at),
        dispatch_end_at: easternInputToIso(ticketForm.value.dispatch_end_at),
        dispatch_note: ticketForm.value.dispatch_note || null,
      },
    })
    ticketOpen.value = false
    await load()
  }
  catch (e: any) {
    ticketError.value = e?.data?.message ?? 'Could not save that ticket.'
  }
  finally { ticketSaving.value = false }
}

const headers = [
  { title: 'Entity', key: 'entity', minWidth: 190 },
  { title: 'Metric', key: 'metric', width: 118 },
  { title: 'Baseline', key: 'baseline', width: 84, align: 'end' as const },
  { title: 'Observed', key: 'observed', width: 84, align: 'end' as const },
  { title: 'Deviation', key: 'deviation', width: 84, align: 'end' as const, sortable: false },
  { title: 'Trend vs baseline', key: 'series', width: 120, sortable: false },
  { title: 'z', key: 'z_score', width: 64, align: 'end' as const },
  { title: 'Since', key: 'detected_at', width: 72, align: 'end' as const },
  { title: 'ISP ticket', key: 'isp', width: 150, sortable: false },
]
</script>

<template>
  <div class="anom">
    <div class="page-head page-head--tall">
      <div>
        <h4 class="text-h4 mb-1">Anomalies</h4>
        <p class="text-body-2 text-medium-emphasis mb-0" style="max-width: 72ch;">
          Metrics deviating from an entity's own baseline (median ± MAD, time-of-day aware) — the slow drift and odd spike a static threshold misses. Passive watch-list; no scan traffic, no paging.
        </p>
      </div>
      <span class="watch"><span class="watch__p" />{{ summary?.open ?? 0 }} active</span>
    </div>

    <!-- Carrier correlation. Present only when several circuits on one ISP are lossy
         at once; an operator working one circuit at a time can never see this. -->
    <VCard v-for="c in correlations" :key="c.isp_name" class="corr mb-4">
      <div class="corr__head">
        <VIcon icon="ri-broadcast-line" size="18" class="corr__ic" />
        <div class="corr__title">
          <div class="corr__h">{{ c.isp_name }} — {{ c.circuits_degraded }} of {{ c.circuits_total }} circuits losing packets</div>
          <div class="corr__sub">
            Since {{ startedAt(c.since) }} · peak {{ c.peak_loss_pct }}% loss ·
            {{ c.circuits_degraded }} of {{ c.fleet.circuits_degraded }} degraded circuits fleet-wide are on this carrier
          </div>
        </div>
        <div class="corr__acts">
          <CopyBtn :text="ispReport(c)" label="Copy for ISP" class="corr__copy" />
          <button type="button" class="corr__toggle" @click="openCorrelation = openCorrelation === c.isp_name ? null : c.isp_name">
            {{ openCorrelation === c.isp_name ? 'Hide' : 'Show' }} circuits
            <VIcon :icon="openCorrelation === c.isp_name ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'" size="16" />
          </button>
        </div>
      </div>

      <div v-if="c.suspect_hop" class="corr__hop">
        <VIcon icon="ri-route-line" size="15" />
        <span>Traces blame <code>{{ c.suspect_hop.host }}</code></span>
        <span class="corr__dim">named by {{ c.suspect_hop.named_by }} of {{ c.suspect_hop.of_probed }} traced circuits · {{ c.suspect_hop.worst_loss_pct }}% at that hop</span>
      </div>

      <!-- The argument. Same collector, same firewall, same head-end port — the split
           follows the destination, so it cannot be anything on our side. -->
      <div class="corr__pref">
        <div class="corr__prefk">Where it lands</div>
        <div class="corr__chips">
          <span v-for="p in c.prefixes" :key="p.prefix" class="pchip" :class="{ 'pchip--hot': p.hot, 'pchip--clean': p.degraded === 0 }">
            <code>{{ p.prefix }}</code>
            <b>{{ p.degraded }}/{{ p.total }}</b>
            <i>{{ p.degraded_pct }}%</i>
          </span>
        </div>
        <div v-if="c.hot_prefixes.length && c.clean_prefixes.length" class="corr__read">
          Loss follows the destination, not the source — every one of these circuits is probed from the same collector through the same firewall and head-end port, so a fault here would hit them evenly. It does not.
        </div>
      </div>

      <div v-if="openCorrelation === c.isp_name" class="corr__list">
        <div class="crow-head">
          <span>Site</span><span>Circuit ID</span><span>LEC circuit ID</span><span>Monitored IP</span><span>Loss</span><span>ISP ticket</span>
        </div>
        <div v-for="x in c.circuits" :key="x.id" class="crow">
          <RouterLink class="crow__site" :to="`/circuits?circuit=${x.id}`">{{ x.site_number ? `#${x.site_number} ` : '' }}{{ x.site_name ?? '—' }}</RouterLink>
          <!-- Both carrier references are copyable on their own: a tech asks for one or
               the other and retyping a 9-digit ID onto a call is how they get transposed. -->
          <CopyBtn v-if="x.circuit_id" :text="x.circuit_id" class="crow__cid" />
          <span v-else class="crow__cid">—</span>
          <CopyBtn v-if="x.lec_circuit_id" :text="x.lec_circuit_id" class="crow__cid" />
          <span v-else class="crow__cid">—</span>
          <CopyBtn :text="x.monitored_ip" class="crow__ip" />
          <span class="crow__loss">lossy {{ x.loss_polls_pct }}% of polls<i> peak {{ x.loss_peak_pct }}%</i></span>
          <span class="crow__tkt">{{ x.isp_ticket ? `Ticket ${x.isp_ticket}` : 'No ISP ticket' }}</span>
        </div>
      </div>

      <div class="corr__caveat"><VIcon icon="ri-information-line" size="14" />{{ c.caveat }}</div>
    </VCard>

    <!-- summary strip -->
    <div class="strip mb-4">
      <VCard class="scard">
        <div class="scard__k">Open anomalies</div>
        <div class="scard__big">{{ summary?.open ?? 0 }}<small> tracking</small></div>
        <div class="scard__s">worst z {{ summary?.worst_z ?? 0 }} · oldest {{ oldestSince() }}</div>
      </VCard>
      <VCard class="scard">
        <div class="scard__k">By metric</div>
        <div class="mrows">
          <button v-for="m in (['throughput','discards','errors','latency','loss'] as const)" :key="m" class="mrow" :class="{ on: metricFilter === m }" @click="metricFilter = metricFilter === m ? null : m">
            <span class="mrow__l text-capitalize">{{ m }}</span>
            <span class="mrow__bar"><i :style="{ width: `${Math.round(((summary?.by_metric[m] ?? 0) / Math.max(1, summary?.open ?? 1)) * 100)}%` }" /></span>
            <span class="mrow__n">{{ summary?.by_metric[m] ?? 0 }}</span>
          </button>
        </div>
      </VCard>
      <VCard class="scard">
        <div class="scard__k">By entity</div>
        <div class="d-flex ga-6">
          <button class="typ" :class="{ on: typeFilter === 'interface' }" @click="typeFilter = typeFilter === 'interface' ? null : 'interface'">
            <div class="typ__n">{{ summary?.by_type.interface ?? 0 }}</div>
            <div class="typ__l"><VIcon icon="ri-git-branch-line" size="14" />Interfaces</div>
          </button>
          <button class="typ" :class="{ on: typeFilter === 'circuit' }" @click="typeFilter = typeFilter === 'circuit' ? null : 'circuit'">
            <div class="typ__n">{{ summary?.by_type.circuit ?? 0 }}</div>
            <div class="typ__l"><VIcon icon="ri-signal-tower-line" size="14" />Circuits</div>
          </button>
        </div>
      </VCard>
    </div>

    <!-- filter pills -->
    <div class="list-pills mb-3">
      <button type="button" class="list-pill" :class="{ 'list-pill--on': !metricFilter && !typeFilter }" @click="metricFilter = null; typeFilter = null">All <span class="op">{{ summary?.open ?? 0 }}</span></button>
      <VDivider vertical class="mx-1" style="height: 22px; align-self: center" />
      <button v-for="m in (['throughput','discards','errors','latency','loss'] as const)" :key="m" type="button" class="list-pill text-capitalize" :class="{ 'list-pill--on': metricFilter === m }" @click="metricFilter = metricFilter === m ? null : m">
        <span class="list-pill__d" style="background: rgb(var(--v-theme-warning))" />{{ m }} <span class="op">{{ summary?.by_metric[m] ?? 0 }}</span>
      </button>
      <VDivider vertical class="mx-1" style="height: 22px; align-self: center" />
      <button type="button" class="list-pill" :class="{ 'list-pill--on': typeFilter === 'interface' }" @click="typeFilter = typeFilter === 'interface' ? null : 'interface'">Interfaces <span class="op">{{ summary?.by_type.interface ?? 0 }}</span></button>
      <button type="button" class="list-pill" :class="{ 'list-pill--on': typeFilter === 'circuit' }" @click="typeFilter = typeFilter === 'circuit' ? null : 'circuit'">Circuits <span class="op">{{ summary?.by_type.circuit ?? 0 }}</span></button>
    </div>

    <VCard class="list-surface">
      <VDataTable
        :headers="headers" :items="filtered" :loading="loading" density="comfortable"
        :items-per-page="25" :sort-by="[{ key: 'z_score', order: 'desc' }]" class="ftbl anom-ftbl stack-sm" :cell-props="stackedCellProps"
      >
        <template #item.entity="{ item }">
          <div class="d-flex align-center ga-3">
            <span class="sev-dot" :class="`sev-${sev(item.z_score)}`" :title="`severity: ${sev(item.z_score)}`" />
            <VIcon :icon="item.entity_type === 'circuit' ? 'ri-signal-tower-line' : 'ri-git-branch-line'" size="16" class="text-medium-emphasis" />
            <div class="min-w-0">
              <component :is="item.route ? 'RouterLink' : 'span'" :to="item.route" class="an-entity">{{ item.entity }}</component>
              <div class="text-caption text-medium-emphasis text-truncate">{{ item.sub }}<template v-if="item.site_name"> · {{ item.site_name }}</template></div>
            </div>
          </div>
        </template>
        <template #item.metric="{ item }">
          <VChip size="small" :color="item.direction === 'drop' ? 'info' : 'warning'" variant="tonal" label>
            <VIcon :icon="item.direction === 'spike' ? 'ri-arrow-up-line' : 'ri-arrow-down-line'" size="13" class="me-1" />{{ item.metric }}
          </VChip>
        </template>
        <template #item.baseline="{ item }"><span class="mono text-medium-emphasis">{{ fmtVal(item, item.baseline) }}</span></template>
        <template #item.observed="{ item }"><span class="mono font-weight-medium">{{ fmtVal(item, item.observed) }}</span></template>
        <template #item.deviation="{ item }"><span class="mono font-weight-bold" :class="item.direction === 'drop' ? 'text-info' : 'text-warning'">{{ deviation(item) }}</span></template>
        <template #item.series="{ item }">
          <svg v-if="spark(item)" class="anom-spark" :viewBox="`0 0 ${SPARK_W} ${SPARK_H}`" preserveAspectRatio="none">
            <!-- expected range (baseline ± threshold) -->
            <rect x="0" :y="spark(item)!.bandY" :width="SPARK_W" :height="spark(item)!.bandH" class="spark-band" />
            <!-- baseline centreline -->
            <line x1="0" :y1="spark(item)!.baseY" :x2="SPARK_W" :y2="spark(item)!.baseY" class="spark-base" />
            <!-- actual series -->
            <polyline fill="none" :stroke="item.direction === 'drop' ? 'rgb(var(--v-theme-info))' : 'rgb(var(--v-theme-warning))'" stroke-width="1.6" :points="spark(item)!.line" />
            <!-- current value -->
            <circle :cx="spark(item)!.endX" :cy="spark(item)!.endY" r="2.4" :fill="item.direction === 'drop' ? 'rgb(var(--v-theme-info))' : 'rgb(var(--v-theme-warning))'" />
          </svg>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.z_score="{ item }"><span class="z-pill" :class="`sev-${sev(item.z_score)}`">{{ item.z_score.toFixed(1) }}</span></template>
        <template #item.detected_at="{ item }"><span class="mono text-caption text-medium-emphasis">{{ since(item.detected_at) }}</span></template>
        <!-- Only a circuit has an ISP to raise a ticket with. An interface or device
             finding gets a dash rather than a button that would have nowhere to post. -->
        <template #item.isp="{ item }">
          <template v-if="item.isp">
            <div v-if="item.isp.isp_ticket" class="d-flex align-center ga-1">
              <CopyBtn :text="item.isp.isp_ticket" :label="`#${item.isp.isp_ticket}`" class="mono text-caption" />
              <VBtn v-if="auth.canAct" icon="ri-edit-line" size="x-small" variant="text" title="Edit ISP ticket" @click.stop="openTicket(item)" />
            </div>
            <div v-else-if="item.isp.dispatch_at" class="d-flex align-center ga-1">
              <span class="text-caption text-medium-emphasis">dispatch set</span>
              <VBtn v-if="auth.canAct" icon="ri-edit-line" size="x-small" variant="text" @click.stop="openTicket(item)" />
            </div>
            <VBtn
              v-else-if="auth.canAct"
              size="x-small" variant="tonal" color="primary" prepend-icon="ri-ticket-2-line"
              @click.stop="openTicket(item)"
            >
              Log ticket
            </VBtn>
            <span v-else class="text-disabled">—</span>
          </template>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #no-data>
          <div class="py-8 text-center text-medium-emphasis">
            <VIcon icon="ri-pulse-line" size="28" class="mb-2 text-success" />
            <div>No anomalies — everything's tracking its baseline.</div>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>

    <!-- Log the carrier's ticket against the finding that caused it. Circuit-level, so
         nothing here invents an outage for a circuit that never dropped. -->
    <VNavigationDrawer v-model="ticketOpen" temporary location="end" width="460" class="nodus-drawer">
      <VCard flat class="d-flex flex-column" style="block-size: 100%">
        <div class="pa-5 pb-4 border-b d-flex align-start justify-space-between">
          <div>
            <div class="sec-hd mb-1">
              ISP ticket
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ ticketFor?.isp?.circuit_code }}<template v-if="ticketFor?.isp?.isp_name"> · {{ ticketFor.isp.isp_name }}</template>
              <template v-if="ticketFor?.site_name"> · {{ ticketFor.site_name }}</template>
            </div>
          </div>
          <VBtn icon="ri-close-line" variant="text" size="small" @click="ticketOpen = false" />
        </div>

        <div class="pa-5 flex-grow-1 overflow-auto">
          <VAlert v-if="ticketError" type="error" variant="tonal" density="compact" class="mb-4">
            {{ ticketError }}
          </VAlert>

          <!-- What the ticket is FOR. Raised off a finding, it should say which one. -->
          <div v-if="ticketFor" class="tk-why mb-4">
            <div class="text-caption text-medium-emphasis">Raised for</div>
            <div class="text-body-2">
              {{ ticketFor.metric }} {{ ticketFor.direction === 'drop' ? 'below' : 'above' }} baseline —
              <span class="mono">{{ fmtVal(ticketFor, ticketFor.observed) }}</span>
              against <span class="mono">{{ fmtVal(ticketFor, ticketFor.baseline) }}</span>, since {{ since(ticketFor.detected_at) }}
            </div>
          </div>

          <VTextField
            v-model="ticketForm.isp_ticket" label="ISP ticket number" placeholder="e.g. CS0472281"
            variant="outlined" density="comfortable" class="mb-4 ce-mono"
          />
          <div v-if="ticketFor?.isp?.support_phone" class="text-caption text-medium-emphasis mb-4">
            <VIcon icon="ri-phone-line" size="13" class="me-1" />{{ ticketFor.isp.support_phone }}
          </div>

          <div class="text-caption text-medium-emphasis mb-2">
            Field dispatch, if the carrier committed to one
          </div>
          <VTextField
            v-model="ticketForm.dispatch_at" label="Arrival window starts" type="datetime-local"
            variant="outlined" density="comfortable" class="mb-4"
          />
          <VTextField
            v-model="ticketForm.dispatch_end_at" label="…and ends" type="datetime-local"
            variant="outlined" density="comfortable" class="mb-4"
            :disabled="!ticketForm.dispatch_at"
          />
          <VTextarea
            v-model="ticketForm.dispatch_note" label="Note" rows="3" variant="outlined" density="comfortable"
            placeholder="What the carrier said, who you spoke to…"
          />

          <!-- Every ticket ever raised on this circuit. The number on the circuit is
               overwritten on the next call; this is what makes the interaction
               findable afterwards. -->
          <template v-if="ticketHistory.length">
            <div class="text-caption text-medium-emphasis mt-6 mb-2">
              Previously raised on this circuit
            </div>
            <div v-for="h in ticketHistory" :key="h.ticket_number + (h.opened_at ?? '')" class="tk-hist">
              <div class="d-flex align-center ga-2">
                <span class="mono text-caption">#{{ h.ticket_number }}</span>
                <VChip size="x-small" :color="h.open ? 'info' : 'secondary'" variant="tonal" label>
                  {{ h.open ? 'open' : 'closed' }}
                </VChip>
              </div>
              <div v-if="h.reason" class="text-caption text-medium-emphasis">{{ h.reason }}</div>
              <div class="text-caption text-disabled">
                {{ h.opened_by || 'unknown' }} · {{ h.opened_at ? formatDateTime(h.opened_at) : '—' }}
                <template v-if="h.closed_at"> → closed {{ formatDateTime(h.closed_at) }}</template>
              </div>
            </div>
          </template>
        </div>

        <div class="pa-4 border-t d-flex justify-end ga-2">
          <VBtn variant="tonal" @click="ticketOpen = false">
            Cancel
          </VBtn>
          <VBtn color="primary" :loading="ticketSaving" @click="saveTicket">
            Save
          </VBtn>
        </div>
      </VCard>
    </VNavigationDrawer>
</template>

<style scoped lang="scss">
.tk-hist {
  padding: 8px 0;
  border-block-start: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}

.tk-why {
  padding: 10px 12px;
  border-inline-start: 3px solid rgba(var(--v-theme-primary), 0.5);
  background: rgba(var(--v-theme-on-surface), 0.04);
}

.anom { --mono: ui-monospace, "SF Mono", Menlo, monospace; max-inline-size: 100%; overflow-x: hidden; }
// Any table overflow scrolls INSIDE its own card, never the page.
.list-surface :deep(.v-table__wrapper) { overflow-x: auto; }
.mono { font-family: var(--mono); font-variant-numeric: tabular-nums; }
.min-w-0 { min-inline-size: 0; }
.an-entity { color: rgb(var(--v-theme-on-surface)); text-decoration: none; font-weight: 600; }
.an-entity:hover { color: rgb(var(--v-theme-primary)); }

// baseline-band sparkline
.anom-spark { inline-size: 112px; block-size: 30px; display: block; }
.spark-band { fill: rgba(var(--v-theme-on-surface), .09); }
.spark-base { stroke: rgba(var(--v-theme-on-surface), .35); stroke-width: .8; stroke-dasharray: 2 2; }

// severity dot + z pill (drives the at-a-glance triage)
.sev-dot { inline-size: 8px; block-size: 8px; border-radius: 50%; flex: none; }
.z-pill { font-family: var(--mono); font-size: 12px; font-weight: 700; padding: 1px 8px; border-radius: 6px; }
.sev-high { background: rgb(var(--v-theme-error)); }
.sev-med { background: rgb(var(--v-theme-warning)); }
.sev-low { background: rgba(var(--v-theme-on-surface), .35); }
.z-pill.sev-high { background: rgba(var(--v-theme-error), .16); color: rgb(var(--v-theme-error)); }
.z-pill.sev-med { background: rgba(var(--v-theme-warning), .16); color: rgb(var(--v-theme-warning)); }
.z-pill.sev-low { background: rgba(var(--v-theme-on-surface), .08); color: rgb(var(--v-theme-on-surface), .7); }

.watch { display: inline-flex; align-items: center; gap: 7px; font-family: var(--mono); font-size: 11px; color: rgb(var(--v-theme-warning));
  border: 1px solid rgba(var(--v-theme-warning), .3); background: rgba(var(--v-theme-warning), .08); border-radius: 999px; padding: 6px 12px; white-space: nowrap;
  &__p { inline-size: 6px; block-size: 6px; border-radius: 50%; background: rgb(var(--v-theme-warning)); } }

// Carrier correlation banner. Warning-toned, not error: several circuits are degraded,
// none is down, and the finding is an argument rather than an alarm.
.corr { border-inline-start: 3px solid rgb(var(--v-theme-warning)); padding: 16px 18px 12px; }
.corr__head { display: flex; align-items: flex-start; gap: 12px; }
.corr__ic { color: rgb(var(--v-theme-warning)); margin-block-start: 2px; flex: none; }
.corr__title { min-inline-size: 0; flex: 1; }
.corr__h { font-size: 15px; font-weight: 650; letter-spacing: -.01em; }
.corr__sub { font-size: 12px; color: rgb(var(--v-theme-on-surface), .62); margin-block-start: 3px; }
.corr__toggle { flex: none; display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600;
  color: rgb(var(--v-theme-primary)); background: none; border: 0; cursor: pointer; padding: 2px 0; }

.corr__hop { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px; margin-block-start: 12px;
  padding: 8px 12px; border-radius: 6px; background: rgba(var(--v-theme-on-surface), .04); font-size: 12.5px;
  code { font-family: var(--mono); font-weight: 650; color: rgb(var(--v-theme-warning)); } }
.corr__dim { color: rgb(var(--v-theme-on-surface), .55); font-size: 11.5px; }

.corr__pref { margin-block-start: 12px; }
.corr__prefk { font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; font-weight: 600;
  color: rgb(var(--v-theme-on-surface), .5); margin-block-end: 7px; }
.corr__chips { display: flex; flex-wrap: wrap; gap: 6px; }
.pchip { display: inline-flex; align-items: baseline; gap: 6px; padding: 4px 9px; border-radius: 6px;
  border: 1px solid rgba(var(--v-theme-on-surface), .12); background: rgba(var(--v-theme-on-surface), .03); font-size: 11.5px;
  code { font-family: var(--mono); }
  b { font-variant-numeric: tabular-nums; font-weight: 700; }
  i { font-style: normal; color: rgb(var(--v-theme-on-surface), .5); font-variant-numeric: tabular-nums; } }
.pchip--hot { border-color: rgba(var(--v-theme-warning), .45); background: rgba(var(--v-theme-warning), .1);
  b { color: rgb(var(--v-theme-warning)); } }
.pchip--clean { i { color: rgb(var(--v-theme-success)); } }
.corr__read { margin-block-start: 9px; font-size: 12px; color: rgb(var(--v-theme-on-surface), .68); max-inline-size: 84ch; }

.corr__list { margin-block-start: 12px; border-block-start: 1px solid rgba(var(--v-theme-on-surface), .08); }
.corr__acts { display: flex; align-items: center; gap: 16px; flex: none; }
.corr__copy { font-size: 12px; font-weight: 600; color: rgb(var(--v-theme-primary)); }

.crow-head, .crow { display: grid; grid-template-columns: 1.3fr .85fr 1.1fr .85fr 1.15fr .8fr; gap: 12px; align-items: baseline; }
.crow-head { padding: 4px 0 6px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em;
  font-weight: 600; color: rgb(var(--v-theme-on-surface), .42);
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .06); }
.crow { padding: 7px 0; font-size: 12.5px; color: inherit;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .05);
  &__site { font-weight: 600; text-decoration: none; color: inherit;
    &:hover { color: rgb(var(--v-theme-primary)); } }
  &__cid, &__ip { font-family: var(--mono); color: rgb(var(--v-theme-on-surface), .6); min-inline-size: 0; }
  &__loss { font-variant-numeric: tabular-nums; color: rgb(var(--v-theme-warning));
    i { font-style: normal; color: rgb(var(--v-theme-on-surface), .5); margin-inline-start: 6px; } }
  &__tkt { color: rgb(var(--v-theme-on-surface), .55); } }
.crow :deep(.copy-btn__text) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 1100px) {
  .crow-head { display: none; }
  .crow { grid-template-columns: 1fr 1fr; }
}

.corr__caveat { display: flex; align-items: flex-start; gap: 6px; margin-block-start: 12px; padding-block-start: 10px;
  border-block-start: 1px solid rgba(var(--v-theme-on-surface), .08);
  font-size: 11.5px; color: rgb(var(--v-theme-on-surface), .55); max-inline-size: 96ch;
  .v-icon { margin-block-start: 1px; flex: none; } }

.strip { display: grid; grid-template-columns: .9fr 1.3fr 1.1fr; gap: 12px; }
@media (max-width: 760px) { .strip { grid-template-columns: 1fr; } }
.scard { padding: 16px 18px;
  &__k { font-size: 10.5px; text-transform: uppercase; letter-spacing: .07em; color: rgb(var(--v-theme-on-surface), .5); font-weight: 600; margin-bottom: 10px; }
  &__big { font-size: 30px; font-weight: 750; letter-spacing: -.02em; font-variant-numeric: tabular-nums; line-height: 1; color: rgb(var(--v-theme-warning));
    small { font-size: 13px; color: rgb(var(--v-theme-on-surface), .55); font-weight: 600; } }
  &__s { font-size: 11.5px; color: rgb(var(--v-theme-on-surface), .45); margin-top: 6px; } }
.mrows { display: flex; flex-direction: column; gap: 6px; }
.mrow { display: flex; align-items: center; gap: 8px; font: inherit; font-size: 12.5px; background: transparent; border: 0; cursor: pointer; padding: 1px 0; color: rgb(var(--v-theme-on-surface), .8);
  &__l { inline-size: 84px; text-align: start; }
  &__bar { flex: 1; block-size: 6px; border-radius: 3px; background: rgba(var(--v-theme-on-surface), .08); overflow: hidden; i { display: block; block-size: 100%; background: rgb(var(--v-theme-warning)); } }
  &__n { font-family: var(--mono); font-size: 12px; color: rgb(var(--v-theme-on-surface), .6); inline-size: 16px; text-align: end; }
  &.on .mrow__l { color: rgb(var(--v-theme-warning)); font-weight: 600; } }
.typ { background: transparent; border: 0; cursor: pointer; text-align: start; padding: 0;
  &__n { font-size: 24px; font-weight: 750; font-variant-numeric: tabular-nums; line-height: 1; color: rgb(var(--v-theme-on-surface)); }
  &__l { font-size: 11.5px; color: rgb(var(--v-theme-on-surface), .5); margin-top: 3px; display: flex; align-items: center; gap: 5px; }
  &.on .typ__n { color: rgb(var(--v-theme-warning)); } }
.op { opacity: .6; font-variant-numeric: tabular-nums; }
</style>
