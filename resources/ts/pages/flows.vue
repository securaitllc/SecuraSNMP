<script setup lang="ts">
import { api } from '@/composables/useApi'
import { watchDebounced } from '@vueuse/core'

interface Talker { src_ip: string; dst_ip: string; app: string | null; app_category: string | null; protocol: string | null; dst_port: number | null; device_id: number | null; bytes: number; packets: number; flows: number }
interface AppRow { app: string | null; app_category: string | null; bytes: number; flows: number }
interface Summary { total_bytes: number; flows: number; conversations: number; top_app: { app: string; bytes: number } | null }
interface Overview { summary: Summary; talkers: Talker[]; talker_total: number; apps: AppRow[] }
interface FlowRow { src_ip: string; dst_ip: string; src_port: number | null; dst_port: number | null; protocol: string | null; app: string | null; app_category: string | null; direction: string | null; bytes: number; recorded_at: string; device?: { name: string } }
interface Summarize { mode: 'summarize'; by: string; metric: string; rows: { key: string; value: number; flows: number }[] }
interface AcItem { label: string; kind: 'field' | 'value'; hint?: string }

const hours = ref(6)
const rangeOptions = [{ title: 'Last hour', value: 1 }, { title: 'Last 6 hours', value: 6 }, { title: 'Last 24 hours', value: 24 }, { title: 'Last 48 hours', value: 48 }]

const query = ref('')
const overview = ref<Overview | null>(null)
const summarize = ref<Summarize | null>(null)
const rawRows = ref<FlowRow[] | null>(null)
const dns = ref<Record<string, string | null>>({})
const error = ref('')
const busy = ref(false)

// ---- autocomplete ----
const inputEl = ref<HTMLTextAreaElement>()
const ac = ref<AcItem[]>([])
const acOpen = ref(false)
const acIndex = ref(0)

const SCHEMA: { name: string; hint: string; hasValues: boolean }[] = [
  { name: 'SrcIP', hint: 'source address', hasValues: true },
  { name: 'DstIP', hint: 'destination address', hasValues: true },
  { name: 'Port', hint: 'src or dst port', hasValues: false },
  { name: 'Protocol', hint: 'tcp / udp / icmp / esp', hasValues: true },
  { name: 'App', hint: 'classified application', hasValues: true },
  { name: 'Category', hint: 'SaaS / Voice / VPN …', hasValues: true },
  { name: 'Direction', hint: 'inbound / outbound / east-west', hasValues: true },
  { name: 'Device', hint: 'exporter device name', hasValues: true },
  { name: 'Bytes', hint: 'flow bytes (1M, 1G …)', hasValues: false },
  { name: 'Packets', hint: 'flow packets', hasValues: false },
]
const valueField: Record<string, string> = { srcip: 'srcip', dstip: 'dstip', app: 'app', protocol: 'protocol', direction: 'direction', category: 'category', device: 'device' }
const exampleQueries = [
  'App == "Microsoft 365" and Bytes > 1M',
  'Device == "HQ-FW" and Direction == outbound',
  'DstIP in (cidr("142.250.0.0/16"))',
  'Flows | summarize sum(Bytes) by App | top 10',
]

function fmtBytes(n: number): string {
  if (!n) return '0 B'
  const u = ['B', 'KB', 'MB', 'GB', 'TB']; let i = 0; let v = n
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++ }
  return `${v.toFixed(v < 10 && i > 0 ? 1 : 0)} ${u[i]}`
}
function pct(part: number, whole: number): number { return whole > 0 ? Math.round((part / whole) * 100) : 0 }
function catColor(c: string | null): string {
  return ({ SaaS: 'info', Voice: 'success', VPN: 'primary', Streaming: 'warning', Web: 'info', Email: 'secondary', 'Remote access': 'error', Infrastructure: 'secondary', Other: 'grey' } as Record<string, string>)[c ?? ''] ?? 'grey'
}
const isSummarizeQuery = computed(() => /\|\s*summarize/i.test(query.value))
const appMax = computed(() => Math.max(1, ...(overview.value?.apps ?? []).map(a => a.bytes)))

// ---- data ----
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
  try { dns.value = { ...dns.value, ...(await api<{ names: Record<string, string | null> }>(`/api/flows/resolve?ips=${uniq.join(',')}`)).names } }
  catch { /* best-effort */ }
}
async function loadRaw() {
  const r = await api<{ mode: 'rows'; rows: FlowRow[] } | { mode: 'summarize' }>(`/api/flows/search?q=${encodeURIComponent(query.value)}&hours=${hours.value}`)
  rawRows.value = r.mode === 'rows' ? r.rows : []
}

// ---- autocomplete engine ----
function context(): { kind: 'field' | 'value'; field: string; word: string; start: number } {
  const pos = inputEl.value?.selectionStart ?? query.value.length
  const before = query.value.slice(0, pos)
  const valM = before.match(/([A-Za-z]+)\s*(?:==|!=|in)\s*\(?\s*(?:cidr\(\s*)?"?([^"),]*)$/)
  if (valM)
    return { kind: 'value', field: valM[1].toLowerCase(), word: valM[2], start: pos - valM[2].length }
  const fM = before.match(/([A-Za-z]*)$/)
  const word = fM ? fM[1] : ''
  return { kind: 'field', field: '', word, start: pos - word.length }
}
async function refreshAc() {
  const c = context()
  if (c.kind === 'field') {
    ac.value = SCHEMA.filter(s => s.name.toLowerCase().startsWith(c.word.toLowerCase()))
      .map(s => ({ label: s.name, kind: 'field' as const, hint: s.hint }))
  }
  else {
    const f = valueField[c.field]
    if (!f) { ac.value = []; acOpen.value = false; return }
    try {
      const vals = (await api<{ values: string[] }>(`/api/flows/values?field=${f}&term=${encodeURIComponent(c.word)}&hours=${hours.value}`)).values
      ac.value = vals.map(v => ({ label: v, kind: 'value' as const }))
    }
    catch { ac.value = [] }
  }
  acIndex.value = 0
  acOpen.value = ac.value.length > 0
}
function applyAc(item: AcItem) {
  const c = context()
  const after = query.value.slice((inputEl.value?.selectionStart ?? query.value.length))
  const head = query.value.slice(0, c.start)
  if (item.kind === 'field') {
    query.value = head + item.label + ' == ' + after
    nextTick(() => { setCaret(head.length + item.label.length + 4); refreshAc() })
  }
  else {
    const needsQuote = !/^\d+$/.test(item.label)
    const val = needsQuote ? `"${item.label}"` : item.label
    query.value = head + val + after
    acOpen.value = false
    nextTick(() => setCaret(head.length + val.length))
  }
}
function setCaret(p: number) { const el = inputEl.value; if (el) { el.focus(); el.setSelectionRange(p, p) } }
function onKeydown(e: KeyboardEvent) {
  if (acOpen.value) {
    if (e.key === 'ArrowDown') { e.preventDefault(); acIndex.value = (acIndex.value + 1) % ac.value.length; return }
    if (e.key === 'ArrowUp') { e.preventDefault(); acIndex.value = (acIndex.value - 1 + ac.value.length) % ac.value.length; return }
    if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); applyAc(ac.value[acIndex.value]); return }
    if (e.key === 'Escape') { acOpen.value = false; return }
  }
}

// clickable drill-down
function addFilter(field: string, val: string) {
  const clause = `${field} == "${val}"`
  query.value = query.value.trim() ? `${query.value.trim()} and ${clause}` : clause
}
function drill(field: string, val: string) { query.value = `${field} == "${val}"` }
function useExample(q: string) { query.value = q; acOpen.value = false }

watchDebounced(query, () => { load(); refreshAc() }, { debounce: 350 })
watch(hours, () => { dns.value = {}; load() })
onMounted(load)
</script>

<template>
  <div class="flows-page">
    <div class="d-flex align-center flex-wrap gap-3 mb-4">
      <div class="me-auto">
        <div class="flows-title">Flows</div>
        <div class="flows-sub">Every conversation across the fleet — searched with KQL</div>
      </div>
      <VProgressCircular v-if="busy" indeterminate size="18" width="2" color="primary" />
      <VSelect v-model="hours" :items="rangeOptions" density="compact" variant="outlined" hide-details rounded="lg" style="min-width:160px" />
    </div>

    <!-- KQL search -->
    <div class="kql-shell mb-3">
      <div class="kql-row">
        <VIcon icon="ri-terminal-line" size="18" class="kql-lead" />
        <div class="kql-wrap">
          <textarea ref="inputEl" v-model="query" rows="1" spellcheck="false" class="kql-editor"
            placeholder='App == "Microsoft 365" and Bytes > 1M     ·     Device == "HQ-FW"     ·     Flows | summarize sum(Bytes) by App'
            @keydown="onKeydown" @click="refreshAc" @blur="acOpen = false" />
          <!-- autocomplete popover -->
          <div v-if="acOpen" class="ac-pop" @mousedown.prevent>
            <div v-for="(it, i) in ac" :key="it.label" class="ac-item" :class="{ sel: i === acIndex }"
              @mouseenter="acIndex = i" @click="applyAc(it)">
              <span class="ac-tok" :class="it.kind">{{ it.label }}</span>
              <span v-if="it.hint" class="ac-hint">{{ it.hint }}</span>
              <span class="ac-kind">{{ it.kind }}</span>
            </div>
          </div>
        </div>
        <VBtn v-if="query" icon="ri-close-line" size="small" variant="text" @click="query = ''" />
      </div>
      <VAlert v-if="error" type="warning" variant="tonal" density="compact" class="ma-3 mt-0">{{ error }}</VAlert>
      <div class="kql-foot">
        <span class="foot-lbl">Try</span>
        <button v-for="q in exampleQueries" :key="q" class="exq" @click="useExample(q)">{{ q }}</button>
        <span class="foot-lbl ms-auto">retention raw 48h · rollups 13mo</span>
      </div>
    </div>

    <!-- SUMMARIZE -->
    <div v-if="summarize" class="panel">
      <div class="panel-head">{{ summarize.metric }} by {{ summarize.by }}</div>
      <div class="pa-4">
        <div v-for="r in summarize.rows" :key="r.key" class="bar-row">
          <div class="bar-top"><span class="clickable" @click="drill(summarize.by, r.key)">{{ r.key || '—' }}</span><span class="mono muted">{{ fmtBytes(r.value) }} · {{ r.flows }} flows</span></div>
          <div class="bar-track"><i :style="{ width: pct(r.value, summarize.rows[0].value) + '%' }" /></div>
        </div>
      </div>
    </div>

    <!-- FLEET OVERVIEW -->
    <template v-else-if="overview">
      <div class="kpis mb-3">
        <div class="kpi"><div class="kpi-l">Throughput</div><div class="kpi-v mono">{{ fmtBytes(overview.summary.total_bytes) }}</div></div>
        <div class="kpi"><div class="kpi-l">Flows</div><div class="kpi-v mono">{{ overview.summary.flows.toLocaleString() }}</div></div>
        <div class="kpi"><div class="kpi-l">Conversations</div><div class="kpi-v mono">{{ overview.summary.conversations.toLocaleString() }}</div></div>
        <div class="kpi"><div class="kpi-l">Top app</div><div class="kpi-v sm">{{ overview.summary.top_app?.app ?? '—' }}</div></div>
      </div>

      <div class="grid2">
        <div class="panel">
          <div class="panel-head">Top talkers <button class="ghost-btn" @click="loadRaw">View raw flows</button></div>
          <table class="ftable">
            <thead><tr><th>Source → Destination</th><th>App</th><th class="r">Bytes</th><th class="r">% total</th></tr></thead>
            <tbody>
              <tr v-for="(t, i) in overview.talkers" :key="i">
                <td>
                  <div><span class="mono clickable" @click="addFilter('SrcIP', t.src_ip)">{{ t.src_ip }}</span><span v-if="dns[t.src_ip]" class="host">{{ dns[t.src_ip] }}</span></div>
                  <div><span class="arrow">→</span><span class="mono clickable" @click="addFilter('DstIP', t.dst_ip)">{{ t.dst_ip }}</span><span v-if="dns[t.dst_ip]" class="host">{{ dns[t.dst_ip] }}</span></div>
                </td>
                <td><span class="apptag clickable" :style="{ '--c': `var(--v-theme-${catColor(t.app_category)})` }" @click="addFilter('App', t.app ?? '')">{{ t.app }}</span></td>
                <td class="r mono">{{ fmtBytes(t.bytes) }}</td>
                <td class="r"><div class="bar-track sm"><i :style="{ width: pct(t.bytes, overview.talker_total) + '%' }" /></div></td>
              </tr>
              <tr v-if="!overview.talkers.length"><td colspan="4" class="empty">No flows match — widen the range or clear the query.</td></tr>
            </tbody>
          </table>
        </div>

        <div class="panel">
          <div class="panel-head">Applications</div>
          <div class="pa-4">
            <div v-for="(a, i) in overview.apps" :key="i" class="bar-row">
              <div class="bar-top"><span class="clickable" @click="addFilter('App', a.app ?? '')">{{ a.app }}</span><span class="mono muted">{{ fmtBytes(a.bytes) }}</span></div>
              <div class="bar-track"><i :style="{ width: pct(a.bytes, appMax) + '%', background: `rgb(var(--v-theme-${catColor(a.app_category)}))` }" /></div>
            </div>
            <div v-if="!overview.apps.length" class="empty">No application data.</div>
          </div>
        </div>
      </div>

      <div v-if="rawRows" class="panel mt-3">
        <div class="panel-head">Raw flows <button class="ghost-btn" @click="rawRows = null">Hide</button></div>
        <table class="ftable mono">
          <thead><tr><th>Time</th><th>Device</th><th>Source</th><th>Destination</th><th>App</th><th class="r">Bytes</th></tr></thead>
          <tbody>
            <tr v-for="(r, i) in rawRows" :key="i">
              <td class="muted">{{ new Date(r.recorded_at).toLocaleTimeString() }}</td>
              <td>{{ r.device?.name ?? '—' }}</td>
              <td>{{ r.src_ip }}<span class="muted">:{{ r.src_port }}</span></td>
              <td>{{ r.dst_ip }}<span class="muted">:{{ r.dst_port }}</span></td>
              <td><span class="apptag" :style="{ '--c': `var(--v-theme-${catColor(r.app_category)})` }">{{ r.app }}</span></td>
              <td class="r">{{ fmtBytes(r.bytes) }}</td>
            </tr>
            <tr v-if="!rawRows.length"><td colspan="6" class="empty">No matching flows.</td></tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.flows-title { font-size: 26px; font-weight: 800; letter-spacing: -.02em; line-height: 1.1; }
.flows-sub { color: rgba(var(--v-theme-on-surface), .6); font-size: 13px; }
.mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-variant-numeric: tabular-nums; }
.muted { color: rgba(var(--v-theme-on-surface), .5); }
.clickable { cursor: pointer; }
.clickable:hover { color: rgb(var(--v-theme-primary)); text-decoration: underline; }
.gap-3 { gap: 12px; }

/* KQL shell */
.kql-shell { background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), .12); border-radius: 14px; box-shadow: 0 1px 2px rgba(0,0,0,.05), 0 8px 24px rgba(0,0,0,.04); overflow: visible; }
.kql-row { display: flex; align-items: flex-start; gap: 10px; padding: 12px 12px 12px 16px; }
.kql-lead { color: rgb(var(--v-theme-primary)); margin-top: 8px; }
.kql-wrap { position: relative; flex: 1; }
.kql-editor { width: 100%; border: 0; outline: 0; resize: none; background: transparent; color: rgb(var(--v-theme-on-surface));
  font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 14px; line-height: 1.7; padding: 6px 0; min-height: 34px; }
.kql-editor::placeholder { color: rgba(var(--v-theme-on-surface), .35); }
.ac-pop { position: absolute; top: calc(100% + 4px); left: 0; z-index: 30; width: 380px; max-width: 92vw; max-height: 300px; overflow: auto;
  background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), .14); border-radius: 10px; box-shadow: 0 12px 32px rgba(0,0,0,.22); }
.ac-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; cursor: pointer; border-top: 1px solid rgba(var(--v-theme-on-surface), .07); }
.ac-item:first-child { border-top: 0; }
.ac-item.sel { background: rgba(var(--v-theme-primary), .1); }
.ac-tok { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 13px; font-weight: 600; }
.ac-tok.field { color: rgb(var(--v-theme-info)); }
.ac-tok.value { color: rgb(var(--v-theme-primary)); }
.ac-hint { font-size: 11.5px; color: rgba(var(--v-theme-on-surface), .55); }
.ac-kind { margin-left: auto; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: rgba(var(--v-theme-on-surface), .35); }
.kql-foot { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; padding: 0 16px 12px; }
.foot-lbl { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: rgba(var(--v-theme-on-surface), .4); font-weight: 600; }
.exq { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 11.5px; padding: 3px 10px; border-radius: 20px; cursor: pointer;
  border: 1px solid rgba(var(--v-theme-on-surface), .12); background: transparent; color: rgba(var(--v-theme-on-surface), .7); }
.exq:hover { color: rgb(var(--v-theme-on-surface)); border-color: rgba(var(--v-theme-primary), .5); }

/* KPIs */
.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
.kpi { background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), .12); border-radius: 12px; padding: 13px 15px; }
.kpi-l { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: rgba(var(--v-theme-on-surface), .45); }
.kpi-v { font-size: 22px; font-weight: 750; margin-top: 4px; letter-spacing: -.01em; }
.kpi-v.sm { font-size: 17px; font-weight: 700; }

.grid2 { display: grid; grid-template-columns: 1.5fr 1fr; gap: 14px; }
.panel { background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), .12); border-radius: 14px; overflow: hidden; }
.panel-head { display: flex; align-items: center; padding: 13px 16px; font-size: 13.5px; font-weight: 700; border-bottom: 1px solid rgba(var(--v-theme-on-surface), .1); }
.ghost-btn { margin-left: auto; font-size: 11.5px; font-weight: 600; color: rgba(var(--v-theme-on-surface), .55); background: none; border: 0; cursor: pointer; }
.ghost-btn:hover { color: rgb(var(--v-theme-primary)); }
.pa-4 { padding: 14px 16px; }

.ftable { width: 100%; border-collapse: collapse; font-size: 13px; }
.ftable thead th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: rgba(var(--v-theme-on-surface), .4); font-weight: 600; text-align: left; padding: 9px 16px; border-bottom: 1px solid rgba(var(--v-theme-on-surface), .1); }
.ftable th.r, .ftable td.r { text-align: right; }
.ftable tbody td { padding: 9px 16px; border-bottom: 1px solid rgba(var(--v-theme-on-surface), .07); vertical-align: middle; }
.ftable tbody tr:last-child td { border-bottom: 0; }
.ftable tbody tr:hover { background: rgba(var(--v-theme-primary), .04); }
.host { display: block; font-size: 11px; color: rgba(var(--v-theme-on-surface), .5); }
.arrow { color: rgba(var(--v-theme-on-surface), .4); margin-right: 6px; }
.apptag { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 2px 9px; border-radius: 6px; white-space: nowrap;
  background: rgba(var(--c), .14); color: rgb(var(--c)); }
.apptag::before { content: ''; width: 6px; height: 6px; border-radius: 2px; background: rgb(var(--c)); }
.empty { text-align: center; color: rgba(var(--v-theme-on-surface), .5); padding: 28px 0; }

.bar-row { padding: 8px 0; }
.bar-top { display: flex; justify-content: space-between; gap: 8px; font-size: 13px; font-weight: 500; margin-bottom: 6px; }
.bar-track { height: 8px; border-radius: 5px; background: rgba(var(--v-theme-on-surface), .08); overflow: hidden; }
.bar-track.sm { width: 80px; height: 6px; margin-left: auto; }
.bar-track i { display: block; height: 100%; border-radius: 5px; background: rgb(var(--v-theme-primary)); }

@media (max-width: 960px) { .kpis { grid-template-columns: repeat(2, 1fr); } .grid2 { grid-template-columns: 1fr; } }
</style>
