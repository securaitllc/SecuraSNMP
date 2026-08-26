<script setup lang="ts">
import { api } from '@/composables/useApi'

interface ReportField { key: string, label: string, align?: string, default?: boolean }
interface ReportMeta { type: string, label: string, time_scoped: boolean, supports_role: boolean, fields: ReportField[] }
interface ReportResult { title: string, columns: ReportField[], rows: Record<string, any>[], summary: { label: string, value: string }[] }
interface SiteOption { id: number, name: string }

const catalog = ref<ReportMeta[]>([])
const selectedType = ref<string>('')
const range = ref('30d')
const customFrom = ref<string>('')
const customTo = ref<string>('')
const siteId = ref<number | null>(null)
const role = ref<string | null>(null)
const selectedFields = ref<string[]>([])
const result = ref<ReportResult | null>(null)
const loading = ref(false)
const runError = ref('')
const sites = ref<SiteOption[]>([])
const fieldDialog = ref(false)
const dragFrom = ref<number | null>(null)

// Range C — preset pills + a custom date range.
const rangeOptions = [
  { value: '24h', label: '24h' },
  { value: '7d', label: '7d' },
  { value: '30d', label: '30d' },
  { value: '90d', label: '90d' },
  { value: 'custom', label: 'Custom' },
]
const roleOptions = [
  { value: null, title: 'All roles' },
  { value: 'switch', title: 'Switches' },
  { value: 'edgeconnect', title: 'SD-WAN (EdgeConnect)' },
  { value: 'firewall', title: 'Firewalls' },
]

// One colour per report type — a dot on each tab so the type reads at a glance.
const typeColor: Record<string, string> = {
  'circuit-availability': '#2BA24E',
  'device-availability': '#4C8DFF',
  'tunnel-availability': '#8B7CF6',
  'alarm-summary': '#F5A623',
  'device-inventory': '#98A2B2',
  'site-leases': '#26C6F9',
}

const current = computed(() => catalog.value.find(r => r.type === selectedType.value) ?? null)
const availableFields = computed(() => (current.value?.fields ?? []).filter(f => !selectedFields.value.includes(f.key)))
// Feed the shared ListTabs component (same tab strip used on every list page).
const reportTabs = computed(() => catalog.value.map(r => ({ value: r.type, label: r.label, color: typeColor[r.type] ?? '#98A2B2' })))
// Custom-range validity only matters for time-scoped reports — a snapshot report
// (e.g. Device Inventory) ignores the window, so it must never be gated by it.
const customValid = computed(() =>
  !current.value?.time_scoped || range.value !== 'custom' || (!!customFrom.value && !!customTo.value))

onMounted(async () => {
  const [cat, siteList] = await Promise.all([
    api<{ reports: ReportMeta[] }>('/api/reports/catalog'),
    api<SiteOption[]>('/api/sites'),
  ])
  catalog.value = cat.reports
  sites.value = siteList
  if (cat.reports.length)
    selectType(cat.reports[0].type)
})

function selectType(type: string) {
  selectedType.value = type
  result.value = null
  runError.value = ''
  // Default the field selection to the report's default columns.
  selectedFields.value = (current.value?.fields ?? []).filter(f => f.default).map(f => f.key)
}

function labelFor(key: string): string {
  return current.value?.fields.find(f => f.key === key)?.label ?? key
}

function addField(key: string) {
  if (!selectedFields.value.includes(key))
    selectedFields.value.push(key)
}

function removeField(key: string) {
  selectedFields.value = selectedFields.value.filter(k => k !== key)
}

function dropAt(i: number) {
  const from = dragFrom.value
  if (from === null || from === i)
    return
  const arr = [...selectedFields.value]
  const [moved] = arr.splice(from, 1)
  arr.splice(i, 0, moved)
  selectedFields.value = arr
  dragFrom.value = null
}

function buildParams(): URLSearchParams {
  const p = new URLSearchParams()
  if (current.value?.time_scoped) {
    if (range.value === 'custom' && customFrom.value && customTo.value) {
      p.set('from', new Date(customFrom.value).toISOString())
      p.set('to', new Date(`${customTo.value}T23:59:59`).toISOString())
    }
    else {
      p.set('range', range.value === 'custom' ? '30d' : range.value)
    }
  }
  if (siteId.value)
    p.set('site_id', String(siteId.value))
  if (current.value?.supports_role && role.value)
    p.set('role', role.value)
  selectedFields.value.forEach(f => p.append('fields[]', f))
  return p
}

async function generate() {
  if (!selectedType.value)
    return
  loading.value = true
  result.value = null
  runError.value = ''
  try {
    result.value = await api<ReportResult>(`/api/reports/${selectedType.value}?${buildParams()}`)
  }
  catch {
    runError.value = 'The report failed to run. Check your filters and try again.'
  }
  finally {
    loading.value = false
  }
}

function exportReport() {
  const a = document.createElement('a')
  a.href = `/api/reports/${selectedType.value}/export?${buildParams()}`
  document.body.appendChild(a)
  a.click()
  a.remove()
}

const tableHeaders = computed(() =>
  (result.value?.columns ?? []).map(c => ({ title: c.label, key: c.key, align: (c.align ?? 'start') as 'start' | 'end' })),
)
const siteItems = computed(() => [{ id: 0, name: 'All sites' }, ...sites.value])

function uptimeClass(v: number): string {
  return v >= 99.9 ? 'text-success' : v >= 99 ? 'text-warning' : 'text-error'
}
function uptimeColor(v: number): string {
  return v >= 99.9 ? '#2BA24E' : v >= 99 ? '#F5A623' : '#E5484D'
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">Reports</h4>
        <p class="text-body-2 text-medium-emphasis mb-0" style="max-width: 60ch;">
          Live availability from real infrastructure outages — access-port flapping (a laptop
          unplugged for a meeting) is deliberately excluded, so the numbers are defensible.
        </p>
      </div>
      <div class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="ri-file-excel-2-line" :disabled="!selectedFields.length || !customValid" @click="exportReport">
          Export
        </VBtn>
        <VBtn color="primary" prepend-icon="ri-play-line" :loading="loading" :disabled="!selectedFields.length || !customValid" @click="generate">
          Run report
        </VBtn>
      </div>
    </div>

    <!-- Report type tabs (shared ListTabs, same as every list page) -->
    <ListTabs :tabs="reportTabs" :model-value="selectedType" class="mt-4" @update:model-value="v => v && selectType(v)" />

    <VCard class="list-surface">
      <VCardText>
        <!-- Filter bar -->
        <div class="report-filters">
          <div v-if="current?.time_scoped" class="rf">
            <span class="rf__l">Window</span>
            <VBtnToggle v-model="range" density="comfortable" variant="outlined" mandatory class="range-pills">
              <VBtn v-for="o in rangeOptions" :key="o.value" :value="o.value" size="small">{{ o.label }}</VBtn>
            </VBtnToggle>
          </div>

          <div v-if="current?.time_scoped && range === 'custom'" class="rf rf--dates">
            <VTextField v-model="customFrom" type="date" density="compact" hide-details label="From" style="min-inline-size: 150px;" />
            <VTextField v-model="customTo" type="date" density="compact" hide-details label="To" style="min-inline-size: 150px;" />
          </div>

          <div class="rf">
            <span class="rf__l">Site</span>
            <VSelect
              v-model="siteId"
              :items="siteItems"
              item-title="name"
              item-value="id"
              density="compact"
              hide-details
              style="min-inline-size: 180px;"
              @update:model-value="v => (siteId = v || null)"
            />
          </div>

          <div v-if="current?.supports_role" class="rf">
            <span class="rf__l">Role</span>
            <VSelect v-model="role" :items="roleOptions" density="compact" hide-details style="min-inline-size: 190px;" />
          </div>

          <div class="rf rf--fields">
            <span class="rf__l">Fields</span>
            <VBtn variant="tonal" size="small" prepend-icon="ri-layout-column-line" @click="fieldDialog = true">
              {{ selectedFields.length }} selected · Edit
            </VBtn>
          </div>
        </div>

        <VAlert v-if="runError" type="error" variant="tonal" density="compact" class="mb-2">
          {{ runError }}
        </VAlert>

        <!-- KPI cards -->
        <div v-if="result?.summary?.length" class="report-kpis">
          <div v-for="s in result.summary" :key="s.label" class="report-kpi">
            <div class="report-kpi__l">{{ s.label }}</div>
            <div class="report-kpi__v">{{ s.value }}</div>
          </div>
        </div>

        <!-- Result table -->
        <VDataTable
          v-if="result"
          :headers="tableHeaders"
          :items="result.rows"
          :items-per-page="25"
          density="compact"
          class="report-table mt-2"
        >
          <template #item.uptime_pct="{ item }">
            <div class="uptime-cell">
              <span class="uptime-bar">
                <i :style="{ inlineSize: `${Math.min(100, Number(item.uptime_pct))}%`, background: uptimeColor(Number(item.uptime_pct)) }" />
              </span>
              <span :class="uptimeClass(Number(item.uptime_pct))">{{ Number(item.uptime_pct).toFixed(3) }}%</span>
            </div>
          </template>
          <template #item.sla_status="{ item }">
            <VChip :color="item.sla_status === 'Breach' ? 'error' : 'success'" size="x-small" label>{{ item.sla_status }}</VChip>
          </template>
          <template #item.budget_used_pct="{ item }">
            <span :class="Number(item.budget_used_pct) > 100 ? 'text-error font-weight-medium' : ''">{{ item.budget_used_pct }}%</span>
          </template>
        </VDataTable>

        <div v-else class="text-center text-medium-emphasis py-12">
          Pick a window and fields, then <b>Run report</b>.
        </div>
      </VCardText>
    </VCard>

    <!-- Field builder (Fields option 2 — build & reorder) -->
    <VDialog v-model="fieldDialog" max-width="760">
      <VCard>
        <VCardItem>
          <VCardTitle>Choose fields</VCardTitle>
          <template #subtitle>Add columns, then drag them into the order you want.</template>
        </VCardItem>
        <VCardText>
          <div class="field-builder">
            <div class="fb-col">
              <div class="fb-h">Available</div>
              <button
                v-for="f in availableFields"
                :key="f.key"
                class="fb-item"
                @click="addField(f.key)"
              >
                <VIcon icon="ri-add-line" size="16" class="fb-add" />
                <span>{{ f.label }}</span>
              </button>
              <div v-if="!availableFields.length" class="fb-empty">All fields added</div>
            </div>

            <div class="fb-col">
              <div class="fb-h">In report — drag to reorder</div>
              <div
                v-for="(key, i) in selectedFields"
                :key="key"
                class="fb-item fb-item--sel"
                draggable="true"
                @dragstart="dragFrom = i"
                @dragover.prevent
                @drop="dropAt(i)"
              >
                <VIcon icon="ri-drag-move-line" size="16" class="fb-drag" />
                <span>{{ labelFor(key) }}</span>
                <VIcon icon="ri-close-line" size="16" class="fb-x" @click.stop="removeField(key)" />
              </div>
              <div v-if="!selectedFields.length" class="fb-empty">Add fields from the left</div>
            </div>
          </div>
        </VCardText>
        <VCardActions class="justify-end">
          <VBtn color="primary" variant="flat" @click="fieldDialog = false">Done</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/* Filter bar */
.report-filters {
  display: flex; flex-wrap: wrap; align-items: center; gap: 22px;
  padding-block-end: 18px; margin-block-end: 18px;
  border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.rf { display: flex; flex-direction: column; gap: 7px; }
.rf--dates { flex-direction: row; align-items: flex-end; gap: 10px; }
.rf--fields { margin-inline-start: auto; }
.rf__l { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.07em; opacity: 0.55; }

/* KPI cards */
.report-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-block-end: 4px; }
.report-kpi { padding: 14px 16px; border-radius: 11px; background: rgba(var(--v-theme-on-surface), 0.03); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.report-kpi__l { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.6; margin-block-end: 4px; }
.report-kpi__v { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 1.4rem; font-weight: 650; }

.report-table :deep(td) { font-variant-numeric: tabular-nums; }
.uptime-cell { display: inline-flex; align-items: center; gap: 10px; }
.uptime-bar { inline-size: 56px; block-size: 6px; border-radius: 3px; background: rgba(var(--v-theme-on-surface), 0.08); overflow: hidden; flex: none; }
.uptime-bar i { display: block; block-size: 100%; border-radius: 3px; }

/* Field builder */
.field-builder { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.fb-col { background: rgba(var(--v-theme-on-surface), 0.03); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 10px; padding: 8px; min-block-size: 220px; }
.fb-h { font-size: 0.68rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.55; padding: 6px 8px 10px; }
.fb-item {
  display: flex; align-items: center; gap: 8px; inline-size: 100%;
  font-size: 0.85rem; text-align: start;
  padding: 8px 10px; border-radius: 7px; cursor: pointer;
  background: transparent; border: 0; color: rgb(var(--v-theme-on-surface));
}
.fb-item:hover { background: rgba(var(--v-theme-on-surface), 0.05); }
.fb-item--sel { cursor: grab; margin-block-end: 2px; background: rgba(var(--v-theme-primary), 0.06); }
.fb-item--sel:active { cursor: grabbing; }
.fb-item span { flex: 1; }
.fb-add { opacity: 0.6; }
.fb-drag { opacity: 0.4; }
.fb-x { opacity: 0.5; cursor: pointer; }
.fb-x:hover { opacity: 1; color: rgb(var(--v-theme-error)); }
.fb-empty { font-size: 0.8rem; opacity: 0.4; padding: 10px; text-align: center; }
</style>
