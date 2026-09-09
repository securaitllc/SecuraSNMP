<script setup lang="ts">
import { api } from '@/composables/useApi'

interface Coverage { total_devices: number; with_version: number; without_version: number; affected_devices: number; clean_devices: number }
interface VendorRow { total: number; critical: number; high: number }
interface TopDevice {
  device_id: number; device_name: string | null; site_name: string | null; vendor: string | null
  model: string | null; os_version: string | null; target_version: string | null; eol: boolean
  critical: number; high: number; total: number; max_cvss: number
}
interface Summary { coverage: Coverage; open_findings: number; by_severity: Record<string, number>; by_vendor: Record<string, VendorRow>; top_devices: TopDevice[] }
interface Finding {
  id: number; device_id: number; cve_id: string; state: string
  detected_os_version: string | null; matched_constraint: string | null; first_seen_at: string | null
  acknowledged_at: string | null; ack_note: string | null
  cvss_score: number | null; severity: string; summary: string | null; reference_url: string | null
  device_name: string; vendor: string | null; model: string | null; site_name: string | null
}

const summary = ref<Summary | null>(null)
const findings = ref<Finding[]>([])
const loading = ref(true)

const severityFilter = ref<string | null>(null)
const vendorFilter = ref<string | null>(null)
const stateFilter = ref<'open' | 'acknowledged' | null>(null)

const SEV_ORDER = ['critical', 'high', 'medium', 'low']
const SEV_CLASS: Record<string, string> = { critical: 'c', high: 'h', medium: 'm', low: 'l' }

async function loadSummary() {
  summary.value = await api<Summary>('/api/vulnerabilities/summary')
}
async function loadFindings() {
  const qs = new URLSearchParams()
  if (severityFilter.value) qs.set('severity', severityFilter.value)
  if (vendorFilter.value) qs.set('vendor', vendorFilter.value)
  if (stateFilter.value) qs.set('state', stateFilter.value)
  if (deviceFilter.value) qs.set('device_id', String(deviceFilter.value.id))
  const res = await api<{ data: Finding[] }>(`/api/vulnerabilities?${qs.toString()}`)
  findings.value = res.data
}
async function reload() {
  loading.value = true
  try { await Promise.all([loadSummary(), loadFindings()]) }
  finally { loading.value = false }
}

watch([severityFilter, vendorFilter, stateFilter], loadFindings)
onMounted(reload)

const vendors = computed(() => Object.keys(summary.value?.by_vendor ?? {}))
const sevCounts = computed(() => SEV_ORDER.map(s => ({ sev: s, n: summary.value?.by_severity?.[s] ?? 0 })))
const sevTotal = computed(() => sevCounts.value.reduce((a, b) => a + b.n, 0))
const worstCvss = computed(() => {
  const d = summary.value?.top_devices ?? []
  return d.length ? Math.max(...d.map(x => x.max_cvss)) : 0
})

// Parse the fixed release out of a finding's constraint for the table's Fix column —
// mirrors the backend RemediationPlanner ("X before Y" / "≥ A < B").
function fixLabel(c: string | null): string {
  if (!c) return '—'
  const before = c.match(/before\s+([0-9][0-9A-Za-z.-]*)/i)
  if (before) return before[1]
  const lt = c.match(/[<≤]\s*v?([0-9][0-9.]*)/)
  if (lt) return lt[1]
  return 'EOL train'
}

// Click a remediation row → filter the Findings table to that device, then scroll to it.
const deviceFilter = ref<{ id: number, name: string } | null>(null)
async function selectDevice(id: number, name: string) {
  deviceFilter.value = deviceFilter.value?.id === id ? null : { id, name }
  await loadFindings()
  if (deviceFilter.value)
    document.getElementById('findings-table')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
function clearDeviceFilter() {
  deviceFilter.value = null
  loadFindings()
}

// Acknowledge dialog
const ackDialog = ref(false)
const ackTarget = ref<Finding | null>(null)
const ackNote = ref('')
const ackBusy = ref(false)
function openAck(f: Finding) { ackTarget.value = f; ackNote.value = ''; ackDialog.value = true }
async function confirmAck() {
  if (!ackTarget.value) return
  ackBusy.value = true
  try {
    await api(`/api/vulnerabilities/${ackTarget.value.id}/acknowledge`, { method: 'POST', body: { note: ackNote.value || null } })
    ackDialog.value = false
    await reload()
  } finally { ackBusy.value = false }
}

const headers = [
  { title: 'CVE', key: 'cve_id', width: 140 },
  { title: 'CVSS', key: 'cvss_score', width: 74 },
  { title: 'Device', key: 'device_name' },
  { title: 'Summary', key: 'summary' },
  { title: 'Fix', key: 'fix', width: 130, sortable: false },
  { title: '', key: 'actions', sortable: false, width: 52 },
]
function fmtDate(s: string | null) { return s ? new Date(s).toLocaleDateString() : '—' }
</script>

<template>
  <div class="vuln">
    <!-- header -->
    <div class="vh">
      <div>
        <p class="vh__eyebrow">Passive CVE correlation</p>
        <h4 class="text-h4 mb-1">Vulnerability Console</h4>
        <div class="text-body-2 text-medium-emphasis">
          Firmware matched against NVD — no scan traffic sent to devices. Ranked by what an upgrade actually clears.
        </div>
      </div>
      <div class="d-flex align-center ga-3">
        <span class="vh__passive"><span class="vh__pulse" />Passive · {{ summary?.coverage?.with_version ?? 0 }} assessed</span>
        <VBtn variant="tonal" prepend-icon="ri-refresh-line" :loading="loading" @click="reload">Refresh</VBtn>
      </div>
    </div>

    <!-- posture strip -->
    <div class="posture">
      <div class="posture__stats">
        <div class="stat">
          <div class="stat__k">Exposed devices</div>
          <div class="stat__v text-error">{{ summary?.coverage?.affected_devices ?? 0 }}<small> / {{ summary?.coverage?.total_devices ?? 0 }}</small></div>
          <div class="stat__s">{{ summary?.coverage?.clean_devices ?? 0 }} clean · {{ summary?.coverage?.without_version ?? 0 }} unknown</div>
        </div>
        <div class="stat">
          <div class="stat__k">Open findings</div>
          <div class="stat__v">{{ summary?.open_findings ?? 0 }}</div>
          <div class="stat__s">across {{ vendors.length }} vendor{{ vendors.length === 1 ? '' : 's' }}</div>
        </div>
        <div class="stat">
          <div class="stat__k">Worst CVSS</div>
          <div class="stat__v text-error">{{ worstCvss.toFixed(1) }}</div>
          <div class="stat__s">most-exposed device</div>
        </div>
        <div class="stat stat--vendors">
          <div class="stat__k">By vendor</div>
          <div class="stat__vendors">
            <span v-for="v in vendors" :key="v">
              <span class="text-capitalize">{{ v }}</span>
              <b :class="summary?.by_vendor[v].critical ? 'text-error' : 'text-warning'">{{ summary?.by_vendor[v].total }}</b>
            </span>
            <span v-if="!vendors.length" class="text-success">all clean</span>
          </div>
        </div>
      </div>

      <div v-if="sevTotal" class="sevbar">
        <div
          v-for="s in sevCounts.filter(x => x.n)"
          :key="s.sev"
          :class="`sv-${SEV_CLASS[s.sev]}`"
          :style="{ flexGrow: s.n }"
          :title="`${s.n} ${s.sev}`"
        >{{ s.n }}</div>
      </div>
      <div class="sevlegend">
        <button
          v-for="s in sevCounts" :key="s.sev" type="button"
          class="sevlegend__item" :class="[`sv-${SEV_CLASS[s.sev]}`, { on: severityFilter === s.sev }]"
          @click="severityFilter = severityFilter === s.sev ? null : s.sev"
        >
          <span class="sevlegend__dot" /><span class="text-capitalize">{{ s.sev }}</span><b>{{ s.n }}</b>
        </button>
      </div>
    </div>

    <!-- remediation queue -->
    <div class="vsec">
      <h2>Remediation queue</h2><span class="vsec__tag">what to patch first</span>
      <span class="vsec__hint">ranked by risk · current → target firmware · findings cleared</span>
    </div>
    <div v-if="!summary?.top_devices?.length" class="vempty">
      <VIcon icon="ri-shield-check-line" color="success" size="20" class="me-2" />No exposed devices — the fleet is clean.
    </div>
    <div v-else class="queue">
      <div
        v-for="d in summary.top_devices" :key="d.device_id"
        class="rq" :class="[d.critical ? 'sev-c' : 'sev-h', { on: deviceFilter?.id === d.device_id }]"
        @click="selectDevice(d.device_id, d.device_name ?? 'device')"
      >
        <div class="rq__stripe" />
        <div class="rq__dev">
          <div class="rq__name">
            {{ d.device_name }}
            <span class="rq__cvss" :class="d.critical ? 'c' : 'h'">{{ d.max_cvss.toFixed(1) }}</span>
          </div>
          <div class="rq__meta">
            <span class="rq__vendor">{{ d.vendor }}</span>
            <span>{{ d.site_name ?? '—' }}<template v-if="d.model"> · {{ d.model }}</template></span>
          </div>
        </div>
        <div class="rq__fix">
          <span class="cur">{{ d.os_version ?? '—' }}</span>
          <VIcon icon="ri-arrow-right-line" size="14" class="arrow" />
          <span v-if="d.eol" class="eol">EOL — supported release</span>
          <span v-else-if="d.target_version" class="tgt">{{ d.target_version }}</span>
          <span v-else class="rev">review</span>
        </div>
        <div class="rq__act">
          <span class="rq__clears"><b>{{ d.total }}</b> finding{{ d.total === 1 ? '' : 's' }}<template v-if="d.critical"> · <b class="text-error">{{ d.critical }} crit</b></template></span>
          <VIcon icon="ri-arrow-right-s-line" size="18" class="rq__chev" />
        </div>
      </div>
    </div>

    <!-- findings -->
    <div class="vsec" id="findings-table">
      <h2>Findings</h2><span class="vsec__tag">detail</span>
      <VChip v-if="deviceFilter" size="small" color="primary" label closable class="ms-1" @click:close="clearDeviceFilter">
        {{ deviceFilter.name }}
      </VChip>
      <span class="vsec__hint">{{ findings.length }} shown</span>
    </div>

    <div class="ffilter list-pills">
      <button
        v-for="s in sevCounts" :key="s.sev" type="button"
        class="list-pill" :class="{ 'list-pill--on': severityFilter === s.sev }"
        @click="severityFilter = severityFilter === s.sev ? null : s.sev"
      >
        <span class="list-pill__d" :style="{ background: `var(--sev-${SEV_CLASS[s.sev]})` }" />
        <span class="text-capitalize">{{ s.sev }}</span> {{ s.n }}
      </button>
      <VDivider vertical class="mx-1" style="height: 22px; align-self: center" />
      <button
        v-for="v in vendors" :key="v" type="button"
        class="list-pill text-capitalize" :class="{ 'list-pill--on': vendorFilter === v }"
        @click="vendorFilter = vendorFilter === v ? null : v"
      >{{ v }}</button>
      <VDivider vertical class="mx-1" style="height: 22px; align-self: center" />
      <button type="button" class="list-pill" :class="{ 'list-pill--on': stateFilter === 'acknowledged' }" @click="stateFilter = stateFilter === 'acknowledged' ? null : 'acknowledged'">Ack’d</button>
    </div>

    <VCard class="list-surface">
      <VDataTable
        :headers="headers" :items="findings" :loading="loading" density="comfortable"
        :items-per-page="25" :sort-by="[{ key: 'cvss_score', order: 'desc' }]" class="ftbl"
      >
        <template #item.cve_id="{ item }">
          <a :href="item.reference_url ?? '#'" target="_blank" rel="noopener" class="cve-link">
            <VIcon icon="ri-alert-line" size="13" /><code>{{ item.cve_id }}</code>
          </a>
        </template>
        <template #item.cvss_score="{ item }">
          <span class="cvss-pill" :class="SEV_CLASS[item.severity]">{{ item.cvss_score == null ? '—' : Number(item.cvss_score).toFixed(1) }}</span>
        </template>
        <template #item.device_name="{ item }">
          <RouterLink :to="`/devices/${item.device_id}`" class="fdev-link">{{ item.device_name }}</RouterLink>
          <div class="text-caption text-medium-emphasis">{{ item.site_name ?? '—' }} · <span class="mono">{{ item.detected_os_version }}</span></div>
        </template>
        <template #item.summary="{ item }">
          <div class="fsum">{{ item.summary ?? '—' }}</div>
        </template>
        <template #item.fix="{ item }">
          <span class="ffix mono">{{ fixLabel(item.matched_constraint) }}</span>
        </template>
        <template #item.actions="{ item }">
          <VChip v-if="item.state === 'acknowledged'" size="x-small" color="secondary" label>ack’d</VChip>
          <VBtn v-else icon variant="text" size="small" @click="openAck(item)">
            <VIcon icon="ri-check-double-line" />
            <VTooltip activator="parent">Acknowledge (accepted risk)</VTooltip>
          </VBtn>
        </template>
        <template #no-data>
          <div class="py-6 text-center text-medium-emphasis">No findings for this filter.</div>
        </template>
      </VDataTable>
    </VCard>

    <!-- acknowledge dialog -->
    <VDialog v-model="ackDialog" max-width="480">
      <VCard>
        <VCardItem><VCardTitle>Acknowledge finding</VCardTitle></VCardItem>
        <VCardText>
          <p class="text-body-2 mb-3">
            <code>{{ ackTarget?.cve_id }}</code> on <strong>{{ ackTarget?.device_name }}</strong>.
            Acknowledging marks it as accepted/known risk — it stays visible under “Ack’d”.
          </p>
          <VTextarea v-model="ackNote" label="Note (optional)" rows="3" variant="outlined" hide-details placeholder="e.g. mitigated via ACL / upgrade scheduled" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="ackDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="ackBusy" @click="confirmAck">Acknowledge</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped lang="scss">
.vuln {
  // severity tokens mapped to the app theme so both light + dark work
  --sev-c: rgb(var(--v-theme-error));
  --sev-h: rgb(var(--v-theme-warning));
  --sev-m: rgb(var(--v-theme-info));
  --sev-l: rgb(var(--v-theme-on-surface), .4);
  --mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace;

  display: flex;
  flex-direction: column;
  gap: 18px;
}
.mono { font-family: var(--mono); }

.vh {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  &__eyebrow { font-family: var(--mono); font-size: 11px; letter-spacing: .2em; text-transform: uppercase; color: rgb(var(--v-theme-primary)); margin: 0 0 4px; }
  &__passive {
    display: inline-flex; align-items: center; gap: 7px; font-family: var(--mono); font-size: 11px;
    color: rgb(var(--v-theme-success)); border: 1px solid rgba(var(--v-theme-success), .3);
    background: rgba(var(--v-theme-success), .07); border-radius: 999px; padding: 5px 11px; white-space: nowrap;
  }
  &__pulse { inline-size: 6px; block-size: 6px; border-radius: 50%; background: rgb(var(--v-theme-success)); animation: vpulse 2.4s infinite; }
}
@keyframes vpulse { 0% { box-shadow: 0 0 0 0 rgba(var(--v-theme-success), .45); } 70% { box-shadow: 0 0 0 7px rgba(var(--v-theme-success), 0); } 100% { box-shadow: 0 0 0 0 rgba(var(--v-theme-success), 0); } }
@media (prefers-reduced-motion: reduce) { .vh__pulse { animation: none; } }

.posture {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px; padding: 20px 22px;

  &__stats { display: flex; gap: 30px; flex-wrap: wrap; margin-bottom: 18px; }
}
.stat {
  &__k { font-size: 11px; text-transform: uppercase; letter-spacing: .07em; color: rgb(var(--v-theme-on-surface), .5); font-weight: 600; }
  &__v { font-size: 30px; font-weight: 750; letter-spacing: -.02em; font-variant-numeric: tabular-nums; line-height: 1.15; margin-top: 2px; small { font-size: 14px; color: rgb(var(--v-theme-on-surface), .55); font-weight: 600; } }
  &__s { font-size: 11.5px; color: rgb(var(--v-theme-on-surface), .45); margin-top: 1px; }
  &--vendors { align-self: center; }
  &__vendors { display: flex; flex-direction: column; gap: 2px; font-size: 13px; margin-top: 4px; b { margin-inline-start: 6px; font-variant-numeric: tabular-nums; } }
}
.sevbar {
  display: flex; block-size: 34px; border-radius: 8px; overflow: hidden;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  > div { display: flex; align-items: center; justify-content: center; font-family: var(--mono); font-size: 12px; font-weight: 700; color: #0b0e14; min-inline-size: 30px; transition: flex-grow .3s; }
  .sv-c { background: var(--sev-c); } .sv-h { background: var(--sev-h); }
  .sv-m { background: var(--sev-m); color: #fff; } .sv-l { background: var(--sev-l); color: #fff; }
}
.sevlegend {
  display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;
  &__item {
    display: inline-flex; align-items: center; gap: 6px; font: inherit; font-size: 12px; cursor: pointer;
    color: rgb(var(--v-theme-on-surface), .8); background: transparent;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 999px; padding: 3px 11px;
    b { font-variant-numeric: tabular-nums; }
    &.on { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .12); }
    &.sv-c &__dot, &.sv-c .sevlegend__dot { background: var(--sev-c); }
  }
  &__dot { inline-size: 8px; block-size: 8px; border-radius: 2px; }
  .sv-c .sevlegend__dot { background: var(--sev-c); }
  .sv-h .sevlegend__dot { background: var(--sev-h); }
  .sv-m .sevlegend__dot { background: var(--sev-m); }
  .sv-l .sevlegend__dot { background: var(--sev-l); }
}

.vsec {
  display: flex; align-items: baseline; gap: 10px; margin: 8px 2px -4px;
  h2 { font-size: 15px; font-weight: 700; letter-spacing: -.01em; margin: 0; }
  &__tag { font-family: var(--mono); font-size: 10.5px; text-transform: uppercase; letter-spacing: .12em; color: rgb(var(--v-theme-primary)); }
  &__hint { margin-inline-start: auto; font-size: 12px; color: rgb(var(--v-theme-on-surface), .45); }
}
.vempty { display: flex; align-items: center; padding: 20px; color: rgb(var(--v-theme-on-surface), .6); font-size: 14px;
  border: 1px dashed rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 12px; }

.queue { display: flex; flex-direction: column; gap: 8px; }
.rq {
  display: grid; grid-template-columns: 4px 1.5fr 1.3fr auto; gap: 14px; align-items: center;
  background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 11px; padding: 12px 15px 12px 0; cursor: pointer; transition: border-color .12s, background .12s;
  &:hover { border-color: rgba(var(--v-theme-primary), .5); }
  &.on { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .05); }
  &__stripe { align-self: stretch; border-radius: 0 3px 3px 0; }
  &.sev-c .rq__stripe { background: var(--sev-c); } &.sev-h .rq__stripe { background: var(--sev-h); }
  &__dev { min-inline-size: 0; }
  &__name { font-weight: 650; font-size: 14px; display: flex; align-items: center; gap: 8px; }
  &__cvss { font-family: var(--mono); font-size: 11px; font-weight: 700; color: #0b0e14; padding: 1px 6px; border-radius: 5px; &.c { background: var(--sev-c); } &.h { background: var(--sev-h); } }
  &__meta { font-size: 12px; color: rgb(var(--v-theme-on-surface), .6); margin-top: 2px; display: flex; gap: 8px; flex-wrap: wrap; }
  &__vendor { font-family: var(--mono); font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 4px; padding: 0 5px; }
  &__fix { font-size: 12.5px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-family: var(--mono);
    .cur { color: rgb(var(--v-theme-error)); } .arrow { color: rgb(var(--v-theme-on-surface), .4); }
    .tgt { color: rgb(var(--v-theme-success)); font-weight: 700; } .eol { color: rgb(var(--v-theme-warning)); font-weight: 700; }
    .rev { color: rgb(var(--v-theme-on-surface), .55); } }
  &__act { display: flex; align-items: center; gap: 8px; justify-self: end; }
  &__clears { font-size: 12px; color: rgb(var(--v-theme-on-surface), .7); white-space: nowrap; b { color: rgb(var(--v-theme-on-surface)); } }
  &__chev { color: rgb(var(--v-theme-on-surface), .4); }
}
@media (max-width: 760px) {
  .rq { grid-template-columns: 4px 1fr; row-gap: 8px; }
  .rq__fix, .rq__act { grid-column: 2; justify-self: start; }
}

.ffilter { margin-bottom: -6px; }
.cve-link { display: inline-flex; align-items: center; gap: 5px; color: rgb(var(--v-theme-primary)); text-decoration: none; code { color: inherit; font-family: var(--mono); font-size: 12px; } }
.cvss-pill { font-family: var(--mono); font-size: 12px; font-weight: 700; color: #0b0e14; border-radius: 5px; padding: 2px 7px; display: inline-block;
  &.c { background: var(--sev-c); } &.h { background: var(--sev-h); } &.m { background: var(--sev-m); color: #fff; } &.l { background: var(--sev-l); color: #fff; } }
.fdev-link { color: rgb(var(--v-theme-on-surface)); text-decoration: none; font-weight: 600; &:hover { color: rgb(var(--v-theme-primary)); } }
.fsum { font-size: 12.5px; color: rgb(var(--v-theme-on-surface), .7); max-inline-size: 42ch; }
.ffix { color: rgb(var(--v-theme-success)); font-size: 12px; }
</style>
