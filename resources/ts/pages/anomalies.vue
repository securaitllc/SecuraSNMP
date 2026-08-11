<script setup lang="ts">
import { api } from '@/composables/useApi'

interface Anomaly {
  id: number
  entity_type: 'interface' | 'circuit'
  entity: string
  sub: string | null
  site_name: string | null
  metric: string
  metric_key: 'throughput' | 'discards' | 'latency'
  direction: 'spike' | 'drop'
  baseline: number
  observed: number
  z_score: number
  detected_at: string
  last_seen_at: string
  route: string | null
}

const rows = ref<Anomaly[]>([])
const loading = ref(true)

async function load() {
  loading.value = true
  try { rows.value = (await api<{ data: Anomaly[] }>('/api/anomalies')).data }
  finally { loading.value = false }
}
let poll: ReturnType<typeof setInterval> | null = null
onMounted(() => { load(); poll = setInterval(load, 60000) })
onBeforeUnmount(() => { if (poll) clearInterval(poll) })

function fmtBytes(n: number) {
  const u = ['B', 'KB', 'MB', 'GB', 'TB']
  let v = Math.abs(n); let i = 0
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v >= 100 || i === 0 ? 0 : 1)} ${u[i]}`
}
// A human deviation string per metric.
function deviation(a: Anomaly): string {
  if (a.metric_key === 'latency')
    return `${a.observed.toFixed(0)} ms vs ${a.baseline.toFixed(0)} ms baseline`
  const mult = a.baseline > 0 ? a.observed / a.baseline : 0
  const obs = a.metric_key === 'throughput' ? fmtBytes(a.observed) : a.observed.toFixed(0)
  const base = a.metric_key === 'throughput' ? fmtBytes(a.baseline) : a.baseline.toFixed(0)
  return mult >= 1
    ? `${obs} — ${mult.toFixed(1)}× baseline (${base})`
    : `${obs} — ${(mult * 100).toFixed(0)}% of baseline (${base})`
}

const headers = [
  { title: 'Entity', key: 'entity', minWidth: 220 },
  { title: 'Metric', key: 'metric', width: 130 },
  { title: 'Deviation', key: 'deviation', minWidth: 260, sortable: false },
  { title: 'z-score', key: 'z_score', width: 90, align: 'end' as const },
  { title: 'Since', key: 'detected_at', width: 120 },
]
function since(iso: string) {
  const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000))
  if (s < 60) return `${s}m`
  const h = Math.floor(s / 60)
  return h < 24 ? `${h}h ${s % 60}m` : `${Math.floor(h / 24)}d ${h % 24}h`
}
</script>

<template>
  <div>
    <h4 class="text-h4 mb-1">Anomalies</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Metrics deviating from an entity's own baseline (median ± MAD, time-of-day aware) — the slow drift and odd spike a static threshold misses. A watch-list, not a page: passive, no scan traffic.
    </p>

    <VCard class="list-surface">
      <VDataTable
        :headers="headers"
        :items="rows"
        :loading="loading"
        :items-per-page="25"
        :sort-by="[{ key: 'z_score', order: 'desc' }]"
        density="compact"
      >
        <template #item.entity="{ item }">
          <div class="d-flex align-center ga-2">
            <VIcon :icon="item.entity_type === 'circuit' ? 'ri-signal-tower-line' : 'ri-git-branch-line'" size="16" class="text-warning" />
            <div>
              <component
                :is="item.route ? 'RouterLink' : 'span'"
                :to="item.route"
                class="an-entity"
              >{{ item.entity }}</component>
              <div class="text-caption text-medium-emphasis">{{ item.sub }}<template v-if="item.site_name"> · {{ item.site_name }}</template></div>
            </div>
          </div>
        </template>
        <template #item.metric="{ item }">
          <VChip size="x-small" color="warning" variant="tonal" label>
            <VIcon :icon="item.direction === 'spike' ? 'ri-arrow-up-line' : 'ri-arrow-down-line'" size="12" class="me-1" />{{ item.metric }}
          </VChip>
        </template>
        <template #item.deviation="{ item }">
          <span class="mono text-body-2">{{ deviation(item) }}</span>
        </template>
        <template #item.z_score="{ item }">
          <span class="mono font-weight-medium">{{ item.z_score.toFixed(1) }}</span>
        </template>
        <template #item.detected_at="{ item }">
          <span class="text-body-2">{{ since(item.detected_at) }}</span>
        </template>
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

<style scoped>
.mono { font-family: ui-monospace, "SF Mono", Menlo, monospace; }
.an-entity { color: rgb(var(--v-theme-on-surface)); text-decoration: none; font-weight: 600; }
.an-entity:hover { color: rgb(var(--v-theme-primary)); }
</style>
