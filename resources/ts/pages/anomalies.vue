<script setup lang="ts">
import { api } from '@/composables/useApi'

interface Anomaly {
  id: number
  entity_type: 'interface' | 'circuit'
  entity: string
  sub: string | null
  site_name: string | null
  metric: string
  metric_key: 'throughput' | 'discards' | 'latency' | 'loss'
  direction: 'spike' | 'drop'
  baseline: number
  observed: number
  z_score: number
  series: number[]
  detected_at: string
  last_seen_at: string
  route: string | null
}
interface Summary {
  open: number
  by_metric: { throughput: number, discards: number, latency: number, loss: number }
  by_type: { interface: number, circuit: number }
  worst_z: number
  oldest_at: string | null
}

const rows = ref<Anomaly[]>([])
const summary = ref<Summary | null>(null)
const loading = ref(true)
const metricFilter = ref<string | null>(null)
const typeFilter = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const res = await api<{ data: Anomaly[], summary: Summary }>('/api/anomalies')
    rows.value = res.data
    summary.value = res.summary
  }
  finally { loading.value = false }
}
let poll: ReturnType<typeof setInterval> | null = null
onMounted(() => { load(); poll = setInterval(load, 60000) })
onBeforeUnmount(() => { if (poll) clearInterval(poll) })

const filtered = computed(() => rows.value.filter(a =>
  (!metricFilter.value || a.metric_key === metricFilter.value)
  && (!typeFilter.value || a.entity_type === typeFilter.value)))

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
// Normalise a series to an SVG polyline (0..96 × 0..26, newest at right).
function sparkPoints(series: number[]) {
  if (!series?.length) return ''
  const max = Math.max(...series, 1); const min = Math.min(...series, 0)
  const rng = max - min || 1; const w = 96; const h = 24
  return series.map((v, i) => `${((i / Math.max(1, series.length - 1)) * w).toFixed(1)},${(h - ((v - min) / rng) * h + 1).toFixed(1)}`).join(' ')
}
function since(iso: string) {
  const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000))
  if (s < 60) return `${s}m`
  const h = Math.floor(s / 60)
  return h < 24 ? `${h}h ${String(s % 60).padStart(2, '0')}m` : `${Math.floor(h / 24)}d ${h % 24}h`
}
function oldestSince() { return summary.value?.oldest_at ? since(summary.value.oldest_at) : '—' }

const headers = [
  { title: 'Entity', key: 'entity', minWidth: 230 },
  { title: 'Metric', key: 'metric', width: 132 },
  { title: 'Baseline', key: 'baseline', width: 90, align: 'end' as const },
  { title: 'Observed', key: 'observed', width: 90, align: 'end' as const },
  { title: 'Deviation', key: 'deviation', width: 92, align: 'end' as const, sortable: false },
  { title: 'Trend', key: 'series', width: 108, sortable: false },
  { title: 'z', key: 'z_score', width: 64, align: 'end' as const },
  { title: 'Since', key: 'detected_at', width: 78, align: 'end' as const },
]
</script>

<template>
  <div class="anom">
    <div class="d-flex align-start justify-space-between flex-wrap ga-3 mb-4">
      <div>
        <h4 class="text-h4 mb-1">Anomalies</h4>
        <p class="text-body-2 text-medium-emphasis mb-0" style="max-width: 72ch;">
          Metrics deviating from an entity's own baseline (median ± MAD, time-of-day aware) — the slow drift and odd spike a static threshold misses. Passive watch-list; no scan traffic, no paging.
        </p>
      </div>
      <span class="watch"><span class="watch__p" />{{ summary?.open ?? 0 }} active</span>
    </div>

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
          <button v-for="m in (['throughput','discards','latency','loss'] as const)" :key="m" class="mrow" :class="{ on: metricFilter === m }" @click="metricFilter = metricFilter === m ? null : m">
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
      <button v-for="m in (['throughput','discards','latency','loss'] as const)" :key="m" type="button" class="list-pill text-capitalize" :class="{ 'list-pill--on': metricFilter === m }" @click="metricFilter = metricFilter === m ? null : m">
        <span class="list-pill__d" style="background: rgb(var(--v-theme-warning))" />{{ m }} <span class="op">{{ summary?.by_metric[m] ?? 0 }}</span>
      </button>
      <VDivider vertical class="mx-1" style="height: 22px; align-self: center" />
      <button type="button" class="list-pill" :class="{ 'list-pill--on': typeFilter === 'interface' }" @click="typeFilter = typeFilter === 'interface' ? null : 'interface'">Interfaces <span class="op">{{ summary?.by_type.interface ?? 0 }}</span></button>
      <button type="button" class="list-pill" :class="{ 'list-pill--on': typeFilter === 'circuit' }" @click="typeFilter = typeFilter === 'circuit' ? null : 'circuit'">Circuits <span class="op">{{ summary?.by_type.circuit ?? 0 }}</span></button>
    </div>

    <VCard class="list-surface">
      <VDataTable
        :headers="headers"
        :items="filtered"
        :loading="loading"
        :items-per-page="25"
        :sort-by="[{ key: 'z_score', order: 'desc' }]"
        density="compact"
        class="anom-table"
      >
        <template #item.entity="{ item }">
          <div class="d-flex align-center ga-2">
            <VIcon :icon="item.entity_type === 'circuit' ? 'ri-signal-tower-line' : 'ri-git-branch-line'" size="16" class="text-warning" />
            <div class="min-w-0">
              <component :is="item.route ? 'RouterLink' : 'span'" :to="item.route" class="an-entity">{{ item.entity }}</component>
              <div class="text-caption text-medium-emphasis text-truncate">{{ item.sub }}<template v-if="item.site_name"> · {{ item.site_name }}</template></div>
            </div>
          </div>
        </template>
        <template #item.metric="{ item }">
          <VChip size="x-small" :color="item.direction === 'drop' ? 'info' : 'warning'" variant="tonal" label>
            <VIcon :icon="item.direction === 'spike' ? 'ri-arrow-up-line' : 'ri-arrow-down-line'" size="12" class="me-1" />{{ item.metric }}
          </VChip>
        </template>
        <template #item.baseline="{ item }"><span class="mono text-medium-emphasis">{{ fmtVal(item, item.baseline) }}</span></template>
        <template #item.observed="{ item }"><span class="mono font-weight-medium">{{ fmtVal(item, item.observed) }}</span></template>
        <template #item.deviation="{ item }"><span class="mono font-weight-bold" :class="item.direction === 'drop' ? 'text-info' : 'text-warning'">{{ deviation(item) }}</span></template>
        <template #item.series="{ item }">
          <svg v-if="item.series?.length" class="anom-spark" viewBox="0 0 96 26" preserveAspectRatio="none">
            <polyline fill="none" :stroke="item.direction === 'drop' ? 'rgb(var(--v-theme-info))' : 'rgb(var(--v-theme-warning))'" stroke-width="1.5" :points="sparkPoints(item.series)" />
          </svg>
          <span v-else class="text-disabled">—</span>
        </template>
        <template #item.z_score="{ item }"><span class="mono">{{ item.z_score.toFixed(1) }}</span></template>
        <template #item.detected_at="{ item }"><span class="mono text-caption">{{ since(item.detected_at) }}</span></template>
        <template #no-data>
          <div class="py-6 text-center text-medium-emphasis">
            <VIcon icon="ri-pulse-line" size="26" class="mb-2 text-success" />
            <div>No anomalies — everything's tracking its baseline.</div>
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>

<style scoped lang="scss">
.anom { --mono: ui-monospace, "SF Mono", Menlo, monospace; }
.mono { font-family: var(--mono); font-variant-numeric: tabular-nums; }
.min-w-0 { min-inline-size: 0; }
.an-entity { color: rgb(var(--v-theme-on-surface)); text-decoration: none; font-weight: 600; }
.an-entity:hover { color: rgb(var(--v-theme-primary)); }
.anom-spark { inline-size: 96px; block-size: 26px; display: block; }

.watch { display: inline-flex; align-items: center; gap: 7px; font-family: var(--mono); font-size: 11px; color: rgb(var(--v-theme-warning));
  border: 1px solid rgba(var(--v-theme-warning), .3); background: rgba(var(--v-theme-warning), .08); border-radius: 999px; padding: 6px 12px; white-space: nowrap;
  &__p { inline-size: 6px; block-size: 6px; border-radius: 50%; background: rgb(var(--v-theme-warning)); } }

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
