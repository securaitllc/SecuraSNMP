<script setup lang="ts">
import { api } from '@/composables/useApi'
import { watchDebounced } from '@vueuse/core'

interface Talker { src_ip: string; dst_ip: string; app: string | null; app_category: string | null; protocol: string | null; dst_port: number | null; device_id: number | null; bytes: number; packets: number; flows: number }
interface AppRow { app: string | null; app_category: string | null; bytes: number; flows: number }
interface Summary { total_bytes: number; flows: number; conversations: number; top_app: { app: string; bytes: number } | null }
interface Overview { summary: Summary; talkers: Talker[]; talker_total: number; apps: AppRow[] }
interface FlowRow { src_ip: string; dst_ip: string; src_port: number | null; dst_port: number | null; protocol: string | null; app: string | null; app_category: string | null; direction: string | null; bytes: number; recorded_at: string; device?: { name: string } }
interface Summarize { mode: 'summarize'; by: string; metric: string; rows: { key: string; value: number; flows: number }[] }

const hours = ref(6)
const rangeOptions = [{ title: 'Last hour', value: 1 }, { title: 'Last 6 hours', value: 6 }, { title: 'Last 24 hours', value: 24 }, { title: 'Last 48 hours', value: 48 }]

const query = ref('')
const overview = ref<Overview | null>(null)
const summarize = ref<Summarize | null>(null)
const rawRows = ref<FlowRow[] | null>(null)
const dns = ref<Record<string, string | null>>({})
const suggestions = ref<string[]>([])
const suggestField = ref('')
const error = ref('')
const busy = ref(false)

const schemaFields = ['SrcIP', 'DstIP', 'Port', 'Protocol', 'App', 'Category', 'Direction', 'Device', 'Bytes', 'Packets']
const exampleQueries = [
  'App == "Microsoft 365" and Bytes > 1M',
  'SrcIP in (cidr("10.0.0.0/8")) and Direction == outbound',
  'Device == "HQ-FW" and App == "Unclassified"',
  'Flows | summarize sum(Bytes) by App | top 10',
]
const valueFieldMap: Record<string, string> = { SrcIP: 'srcip', DstIP: 'dstip', App: 'app', Protocol: 'protocol', Direction: 'direction', Category: 'category', Device: 'device' }

function fmtBytes(n: number): string {
  if (!n) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; let v = n
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v < 10 && i > 0 ? 1 : 0)} ${u[i]}`
}
function pct(part: number, whole: number): number { return whole > 0 ? Math.round((part / whole) * 100) : 0 }
function catColor(c: string | null): string {
  return ({ SaaS: 'info', Voice: 'success', VPN: 'primary', Streaming: 'warning', Web: 'info', Email: 'secondary', 'Remote access': 'error', Infrastructure: 'secondary', Other: 'secondary' } as Record<string, string>)[c ?? ''] ?? 'secondary'
}
const isSummarizeQuery = computed(() => /\|\s*summarize/i.test(query.value))
const appMax = computed(() => Math.max(1, ...(overview.value?.apps ?? []).map(a => a.bytes)))

async function load() {
  busy.value = true; error.value = ''; rawRows.value = null
  try {
    if (isSummarizeQuery.value) {
      const r = await api<Summarize | { mode: 'rows' }>(`/api/flows/search?q=${encodeURIComponent(query.value)}&hours=${hours.value}`)
      summarize.value = r.mode === 'summarize' ? r : null; overview.value = null
    }
    else {
      const r = await api<Overview>(`/api/flows/overview?q=${encodeURIComponent(query.value)}&hours=${hours.value}`)
      overview.value = r; summarize.value = null
      resolveNames(r.talkers.flatMap(t => [t.src_ip, t.dst_ip]))
    }
  }
  catch (e: any) { error.value = e?.data?.error ?? 'That query could not be parsed.'; overview.value = null; summarize.value = null }
  finally { busy.value = false }
}

async function resolveNames(ips: string[]) {
  const uniq = [...new Set(ips)].filter(ip => !(ip in dns.value)).slice(0, 80)
  if (!uniq.length) return
  try {
    const r = await api<{ names: Record<string, string | null> }>(`/api/flows/resolve?ips=${uniq.join(',')}`)
    dns.value = { ...dns.value, ...r.names }
  } catch { /* DNS is best-effort */ }
}

async function loadRaw() {
  const r = await api<{ mode: 'rows'; rows: FlowRow[] } | { mode: 'summarize' }>(`/api/flows/search?q=${encodeURIComponent(query.value)}&hours=${hours.value}`)
  rawRows.value = r.mode === 'rows' ? r.rows : []
}

function drill(field: string, val: string) { query.value = `${field} == "${val}"` }
function addFilter(field: string, val: string) {
  const clause = `${field} == "${val}"`
  query.value = query.value.trim() ? `${query.value.trim()} and ${clause}` : clause
}
function insertField(f: string) {
  query.value = (query.value.trim() ? query.value.trim() + ' and ' : '') + f + ' == '
  loadSuggestions(f)
}
async function loadSuggestions(field: string) {
  const f = valueFieldMap[field]
  suggestField.value = field
  if (!f) { suggestions.value = []; return }
  try { suggestions.value = (await api<{ values: string[] }>(`/api/flows/values?field=${f}&hours=${hours.value}`)).values }
  catch { suggestions.value = [] }
}
function pickSuggestion(v: string) { query.value = query.value.replace(/==\s*$/, `== "${v}"`); suggestions.value = [] }
function useExample(q: string) { query.value = q }
function clearQuery() { query.value = ''; suggestions.value = [] }

watchDebounced(query, load, { debounce: 450 })
watch(hours, () => { dns.value = {}; load() })
onMounted(load)
</script>

<template>
  <div class="flows-page">
    <div class="d-flex align-center flex-wrap gap-3 mb-3">
      <div class="me-auto">
        <h2 class="text-h5 font-weight-bold mb-0">Flows</h2>
        <div class="text-medium-emphasis text-body-2">Every conversation across the fleet — search with KQL</div>
      </div>
      <VProgressCircular v-if="busy" indeterminate size="20" width="2" color="primary" />
      <VSelect v-model="hours" :items="rangeOptions" density="compact" variant="outlined" hide-details style="min-width:160px" />
    </div>

    <!-- KQL bar -->
    <VCard variant="outlined" class="mb-3">
      <VCardText>
        <VTextarea v-model="query" placeholder='e.g.  App == "Microsoft 365" and Bytes > 1M    ·    Device == "HQ-FW"    ·    Flows | summarize sum(Bytes) by App'
          class="kql-input" rows="1" auto-grow variant="outlined" density="compact" hide-details clearable
          @click:clear="clearQuery" />
        <VAlert v-if="error" type="warning" variant="tonal" density="compact" class="mt-2">{{ error }}</VAlert>

        <!-- value suggestions (after picking a field) -->
        <div v-if="suggestions.length" class="d-flex flex-wrap gap-2 mt-3 align-center">
          <span class="text-caption text-disabled me-1">{{ suggestField }} values</span>
          <VChip v-for="v in suggestions" :key="v" size="x-small" color="primary" variant="tonal" class="mono-chip" @click="pickSuggestion(v)">{{ v }}</VChip>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3 align-center">
          <span class="text-caption text-disabled me-1">Fields</span>
          <VChip v-for="f in schemaFields" :key="f" size="x-small" variant="outlined" class="mono-chip" @click="insertField(f)">{{ f }}</VChip>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-2 align-center">
          <span class="text-caption text-disabled me-1">Try</span>
          <VChip v-for="q in exampleQueries" :key="q" size="x-small" variant="text" class="mono-chip text-medium-emphasis" @click="useExample(q)">{{ q }}</VChip>
        </div>
      </VCardText>
    </VCard>

    <!-- SUMMARIZE -->
    <VCard v-if="summarize" variant="outlined">
      <VCardTitle class="text-body-1">{{ summarize.metric }} by {{ summarize.by }}</VCardTitle>
      <VCardText>
        <div v-for="r in summarize.rows" :key="r.key" class="mb-3">
          <div class="d-flex justify-space-between text-body-2 mb-1"><span class="font-weight-medium clickable" @click="drill(summarize.by, r.key)">{{ r.key || '—' }}</span><span class="mono">{{ fmtBytes(r.value) }} · {{ r.flows }} flows</span></div>
          <VProgressLinear :model-value="pct(r.value, summarize.rows[0].value)" color="primary" height="8" rounded />
        </div>
      </VCardText>
    </VCard>

    <!-- FLEET OVERVIEW -->
    <template v-else-if="overview">
      <VRow class="mb-1">
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Throughput</div><div class="text-h6 mono">{{ fmtBytes(overview.summary.total_bytes) }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Flows</div><div class="text-h6 mono">{{ overview.summary.flows.toLocaleString() }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Conversations</div><div class="text-h6 mono">{{ overview.summary.conversations.toLocaleString() }}</div></VCardText></VCard></VCol>
        <VCol cols="6" md="3"><VCard variant="outlined"><VCardText><div class="text-caption text-disabled text-uppercase">Top app</div><div class="text-h6">{{ overview.summary.top_app?.app ?? '—' }}</div></VCardText></VCard></VCol>
      </VRow>

      <VRow>
        <VCol cols="12" md="7">
          <VCard variant="outlined">
            <VCardTitle class="text-body-1 d-flex align-center">Top talkers <VSpacer /><VBtn size="x-small" variant="text" @click="loadRaw">View raw flows</VBtn></VCardTitle>
            <VTable density="compact">
              <thead><tr><th>Source → Destination</th><th>App</th><th class="text-end">Bytes</th><th class="text-end">% total</th></tr></thead>
              <tbody>
                <tr v-for="(t, i) in overview.talkers" :key="i">
                  <td>
                    <span class="mono clickable" @click="addFilter('SrcIP', t.src_ip)">{{ t.src_ip }}</span>
                    <span v-if="dns[t.src_ip]" class="text-disabled text-caption d-block">{{ dns[t.src_ip] }}</span>
                    <span class="text-disabled">→</span>
                    <span class="mono clickable" @click="addFilter('DstIP', t.dst_ip)">{{ t.dst_ip }}</span>
                    <span v-if="dns[t.dst_ip]" class="text-disabled text-caption d-block">{{ dns[t.dst_ip] }}</span>
                  </td>
                  <td><VChip size="x-small" :color="catColor(t.app_category)" variant="tonal" class="clickable" @click="addFilter('App', t.app ?? '')">{{ t.app }}</VChip></td>
                  <td class="text-end mono">{{ fmtBytes(t.bytes) }}</td>
                  <td class="text-end" style="min-width:90px"><VProgressLinear :model-value="pct(t.bytes, overview.talker_total)" color="primary" height="6" rounded /></td>
                </tr>
                <tr v-if="!overview.talkers.length"><td colspan="4" class="text-center text-disabled py-6">No flows match — widen the time range or clear the query.</td></tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
        <VCol cols="12" md="5">
          <VCard variant="outlined">
            <VCardTitle class="text-body-1">Applications</VCardTitle>
            <VCardText>
              <div v-for="(a, i) in overview.apps" :key="i" class="mb-3">
                <div class="d-flex justify-space-between text-body-2 mb-1"><span class="font-weight-medium clickable" @click="addFilter('App', a.app ?? '')">{{ a.app }}</span><span class="mono text-medium-emphasis">{{ fmtBytes(a.bytes) }}</span></div>
                <VProgressLinear :model-value="pct(a.bytes, appMax)" :color="catColor(a.app_category)" height="8" rounded />
              </div>
              <div v-if="!overview.apps.length" class="text-center text-disabled py-6">No application data.</div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- raw flows (on demand) -->
      <VCard v-if="rawRows" variant="outlined" class="mt-3">
        <VCardTitle class="text-body-1 d-flex align-center">Raw flows <VSpacer /><VBtn size="x-small" variant="text" @click="rawRows = null">Hide</VBtn></VCardTitle>
        <VTable density="compact" class="mono">
          <thead><tr><th>Time</th><th>Device</th><th>Source</th><th>Destination</th><th>App</th><th class="text-end">Bytes</th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in rawRows" :key="i">
              <td class="text-disabled">{{ new Date(r.recorded_at).toLocaleTimeString() }}</td>
              <td>{{ r.device?.name ?? '—' }}</td>
              <td>{{ r.src_ip }}<span class="text-disabled">:{{ r.src_port }}</span></td>
              <td>{{ r.dst_ip }}<span class="text-disabled">:{{ r.dst_port }}</span></td>
              <td><VChip size="x-small" :color="catColor(r.app_category)" variant="tonal">{{ r.app }}</VChip></td>
              <td class="text-end">{{ fmtBytes(r.bytes) }}</td>
            </tr>
            <tr v-if="!rawRows.length"><td colspan="6" class="text-center text-disabled py-6">No matching flows.</td></tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.kql-input :deep(textarea) { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 13px; }
.mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-variant-numeric: tabular-nums; }
.mono-chip { font-family: 'IBM Plex Mono', ui-monospace, monospace; cursor: pointer; }
.clickable { cursor: pointer; }
.clickable:hover { text-decoration: underline; }
.gap-2 { gap: 8px; } .gap-3 { gap: 12px; }
</style>
