<script setup lang="ts">
import { api } from '@/composables/useApi'

interface DeviceLite { id: number; name: string; role: string; vendor: string | null }
interface TopTalker { src_ip: string; dst_ip: string; app: string | null; app_category: string | null; protocol: string | null; dst_port: number | null; bytes: number; packets: number; flows: number }
interface AppRow { app: string | null; app_category: string | null; bytes: number; packets: number; flows: number }
interface Summary { total_bytes: number; flows: number; conversations: number; top_talker: { src_ip: string; dst_ip: string; bytes: number } | null; top_app: { app: string; bytes: number } | null }
interface FlowRow { src_ip: string; dst_ip: string; src_port: number | null; dst_port: number | null; protocol: string | null; app: string | null; app_category: string | null; direction: string | null; bytes: number; recorded_at: string; device?: { name: string } }
type SearchResult = { mode: 'rows'; count: number; bytes: number; rows: FlowRow[] } | { mode: 'summarize'; by: string; metric: string; rows: { key: string; value: number; flows: number }[] }

interface Exporter { device_id: number; name: string | null; bytes: number; flows: number }

const devices = ref<DeviceLite[]>([])
const exporters = ref<Exporter[]>([])
const deviceId = ref<number | null>(null)
const hours = ref(6)
const rangeOptions = [{ title: 'Last hour', value: 1 }, { title: 'Last 6 hours', value: 6 }, { title: 'Last 24 hours', value: 24 }, { title: 'Last 48 hours', value: 48 }]

const summary = ref<Summary | null>(null)
const talkers = ref<TopTalker[]>([])
const apps = ref<AppRow[]>([])
const talkerTotal = ref(0)

const query = ref('')
const searchResult = ref<SearchResult | null>(null)
const searchError = ref('')
const busy = ref(false)

const exampleQueries = [
  'App == "Microsoft 365" and Bytes > 1M',
  'SrcIP in (cidr("10.0.0.0/8")) and Direction == outbound',
  'Protocol == "esp"',
  'Flows | summarize sum(Bytes) by App | top 10',
]
const schemaFields = ['SrcIP', 'DstIP', 'Port', 'Protocol', 'App', 'Category', 'Direction', 'Bytes', 'Packets']

function fmtBytes(n: number): string {
  if (!n) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; let v = n
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v < 10 && i > 0 ? 1 : 0)} ${u[i]}`
}
function pct(part: number, whole: number): number { return whole > 0 ? Math.round((part / whole) * 100) : 0 }
function catColor(c: string | null): string {
  return ({ SaaS: 'info', Voice: 'success', VPN: 'primary', Streaming: 'warning', Web: 'info', Email: 'secondary', 'Remote access': 'error', Other: 'secondary' } as Record<string, string>)[c ?? ''] ?? 'secondary'
}

async function loadDevices() {
  const res = await api<{ data: DeviceLite[] }>('/api/devices')
  devices.value = res.data
}

async function loadExporters() {
  const res = await api<{ exporters: Exporter[] }>(`/api/flows/exporters?hours=${hours.value}`)
  exporters.value = res.exporters
  // Default to the busiest exporter — not the alphabetically-first device, which may
  // export nothing (that made the page look empty).
  if (!deviceId.value)
    deviceId.value = exporters.value[0]?.device_id ?? devices.value[0]?.id ?? null
}

async function loadDeviceFlows() {
  if (!deviceId.value) return
  busy.value = true
  try {
    const q = `?hours=${hours.value}`
    const [s, t, a] = await Promise.all([
      api<Summary>(`/api/devices/${deviceId.value}/flows/summary${q}`),
      api<{ talkers: TopTalker[]; total_bytes: number }>(`/api/devices/${deviceId.value}/flows/top-talkers${q}`),
      api<{ apps: AppRow[] }>(`/api/devices/${deviceId.value}/flows/apps${q}`),
    ])
    summary.value = s; talkers.value = t.talkers; talkerTotal.value = t.total_bytes; apps.value = a.apps
  } finally { busy.value = false }
}

async function runSearch() {
  if (!query.value.trim()) { searchResult.value = null; return }
  busy.value = true; searchError.value = ''
  try {
    const params = new URLSearchParams({ q: query.value, hours: String(hours.value) })
    if (deviceId.value) params.set('device_id', String(deviceId.value))
    searchResult.value = await api<SearchResult>(`/api/flows/search?${params.toString()}`)
  } catch (e: any) {
    searchError.value = e?.data?.error ?? 'That query could not be parsed.'
    searchResult.value = null
  } finally { busy.value = false }
}

function clearSearch() { query.value = ''; searchResult.value = null; searchError.value = '' }
function useExample(q: string) { query.value = q; runSearch() }
function addField(f: string) { query.value = (query.value.trim() ? query.value.trim() + ' and ' : '') + f + ' == ' }

const appMax = computed(() => Math.max(1, ...apps.value.map(a => a.bytes)))

onMounted(async () => { await loadDevices(); await loadExporters(); await loadDeviceFlows() })
watch([deviceId, hours], () => { clearSearch(); loadDeviceFlows() })
watch(hours, loadExporters)
</script>

<template>
  <div class="flows-page">
    <!-- header -->
    <div class="d-flex align-center flex-wrap gap-3 mb-4">
      <div class="me-auto">
        <h2 class="text-h5 font-weight-bold mb-0">Flows</h2>
        <div class="text-medium-emphasis text-body-2">NetFlow / sFlow — who's using the network, by conversation and application</div>
      </div>
      <VSelect v-model="deviceId" :items="devices" item-title="name" item-value="id" label="Device" density="compact" variant="outlined" hide-details style="min-width:240px" />
      <VSelect v-model="hours" :items="rangeOptions" density="compact" variant="outlined" hide-details style="min-width:160px" />
    </div>

    <!-- exporters: which devices are actually sending flows right now -->
    <div v-if="exporters.length" class="d-flex flex-wrap gap-2 align-center mb-4">
      <span class="text-caption text-disabled me-1">Exporting now</span>
      <VChip v-for="e in exporters.slice(0, 8)" :key="e.device_id" size="small"
        :color="e.device_id === deviceId ? 'primary' : undefined"
        :variant="e.device_id === deviceId ? 'flat' : 'tonal'" @click="deviceId = e.device_id">
        {{ e.name }} · {{ fmtBytes(e.bytes) }}
      </VChip>
    </div>

    <!-- KQL search -->
    <VCard class="mb-4" variant="outlined">
      <VCardText>
        <div class="d-flex align-center mb-2">
          <span class="text-caption text-uppercase font-weight-bold text-medium-emphasis">Query</span>
          <span class="text-caption text-disabled ms-2">KQL over the flow record · retention raw 48h · rollups 13mo</span>
        </div>
        <div class="d-flex gap-2 align-start">
          <VTextarea v-model="query" placeholder='Flows | where SrcIP in (cidr("10.86.10.0/24")) and Port == 443 and App == "Microsoft 365" and Bytes > 1M'
            class="kql-input flex-grow-1" rows="1" auto-grow variant="outlined" density="compact" hide-details
            @keydown.enter.exact.prevent="runSearch" />
          <VBtn color="primary" :loading="busy" @click="runSearch">Run</VBtn>
          <VBtn v-if="searchResult || searchError" variant="tonal" @click="clearSearch">Clear</VBtn>
        </div>
        <VAlert v-if="searchError" type="warning" variant="tonal" density="compact" class="mt-2">{{ searchError }}</VAlert>
        <div class="d-flex flex-wrap gap-2 mt-3 align-center">
          <span class="text-caption text-disabled me-1">Fields</span>
          <VChip v-for="f in schemaFields" :key="f" size="x-small" variant="outlined" class="mono-chip" @click="addField(f)">{{ f }}</VChip>
          <span class="text-caption text-disabled ms-3 me-1">Try</span>
          <VChip v-for="q in exampleQueries" :key="q" size="x-small" color="primary" variant="tonal" class="mono-chip" @click="useExample(q)">{{ q }}</VChip>
        </div>
      </VCardText>
    </VCard>

    <!-- SEARCH RESULTS -->
    <template v-if="searchResult">
      <template v-if="searchResult.mode === 'summarize'">
        <VCard variant="outlined">
          <VCardTitle class="text-body-1">{{ searchResult.metric }} by {{ searchResult.by }}</VCardTitle>
          <VCardText>
            <div v-for="r in searchResult.rows" :key="r.key" class="mb-3">
              <div class="d-flex justify-space-between text-body-2 mb-1"><span class="font-weight-medium">{{ r.key || '—' }}</span><span class="mono">{{ fmtBytes(r.value) }} · {{ r.flows }} flows</span></div>
              <VProgressLinear :model-value="pct(r.value, searchResult.rows[0].value)" color="primary" height="8" rounded />
            </div>
          </VCardText>
        </VCard>
      </template>
      <template v-else>
        <div class="text-caption text-uppercase font-weight-bold text-medium-emphasis mb-2">Results · {{ searchResult.count.toLocaleString() }} flows · {{ fmtBytes(searchResult.bytes) }}</div>
        <VCard variant="outlined">
          <VTable density="compact" class="mono">
            <thead><tr><th>Time</th><th>Source</th><th>Destination</th><th>App</th><th>Proto</th><th class="text-end">Bytes</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in searchResult.rows" :key="i">
                <td class="text-disabled">{{ new Date(r.recorded_at).toLocaleTimeString() }}</td>
                <td>{{ r.src_ip }}<span class="text-disabled">:{{ r.src_port }}</span></td>
                <td>{{ r.dst_ip }}<span class="text-disabled">:{{ r.dst_port }}</span></td>
                <td><VChip size="x-small" :color="catColor(r.app_category)" variant="tonal">{{ r.app }}</VChip></td>
                <td>{{ r.protocol }}</td>
                <td class="text-end">{{ fmtBytes(r.bytes) }}</td>
              </tr>
              <tr v-if="!searchResult.rows.length"><td colspan="6" class="text-center text-disabled py-6">No flows match this query in the window.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </template>
    </template>

    <!-- DEVICE OVERVIEW (no active search) -->
    <template v-else>
      <VRow class="mb-1">
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Throughput</div><div class="text-h6 mono">{{ fmtBytes(summary?.total_bytes ?? 0) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Flows</div><div class="text-h6 mono">{{ (summary?.flows ?? 0).toLocaleString() }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Conversations</div><div class="text-h6 mono">{{ (summary?.conversations ?? 0).toLocaleString() }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Top app</div><div class="text-h6">{{ summary?.top_app?.app ?? '—' }}</div></VCardText></VCard></VCol>
      </VRow>

      <VRow>
        <VCol cols="12" md="7">
          <VCard variant="outlined">
            <VCardTitle class="text-body-1">Top talkers</VCardTitle>
            <VTable density="compact">
              <thead><tr><th>Source → Destination</th><th>App</th><th class="text-end">Bytes</th><th class="text-end">% link</th></tr></thead>
              <tbody>
                <tr v-for="(t, i) in talkers" :key="i">
                  <td class="mono">{{ t.src_ip }} <span class="text-disabled">→</span> {{ t.dst_ip }}</td>
                  <td><VChip size="x-small" :color="catColor(t.app_category)" variant="tonal">{{ t.app }}</VChip></td>
                  <td class="text-end mono">{{ fmtBytes(t.bytes) }}</td>
                  <td class="text-end" style="min-width:90px"><VProgressLinear :model-value="pct(t.bytes, talkerTotal)" color="primary" height="6" rounded /></td>
                </tr>
                <tr v-if="!talkers.length"><td colspan="4" class="text-center text-disabled py-6">No flow data yet for this device in the window.</td></tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
        <VCol cols="12" md="5">
          <VCard variant="outlined">
            <VCardTitle class="text-body-1">Applications</VCardTitle>
            <VCardText>
              <div v-for="(a, i) in apps" :key="i" class="mb-3">
                <div class="d-flex justify-space-between text-body-2 mb-1"><span class="font-weight-medium">{{ a.app }}</span><span class="mono text-medium-emphasis">{{ fmtBytes(a.bytes) }}</span></div>
                <VProgressLinear :model-value="pct(a.bytes, appMax)" :color="catColor(a.app_category)" height="8" rounded />
              </div>
              <div v-if="!apps.length" class="text-center text-disabled py-6">No application data yet.</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.kql-input :deep(textarea) { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 13px; }
.mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-variant-numeric: tabular-nums; }
.mono-chip { font-family: 'IBM Plex Mono', ui-monospace, monospace; cursor: pointer; }
.gap-2 { gap: 8px; } .gap-3 { gap: 12px; }
</style>
