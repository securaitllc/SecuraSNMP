<script setup lang="ts">
import { api } from '@/composables/useApi'

interface ReportField { key: string, label: string, align?: string, default?: boolean }
interface ReportMeta { type: string, label: string, time_scoped: boolean, supports_role: boolean, fields: ReportField[] }
interface ReportResult { title: string, columns: ReportField[], rows: Record<string, any>[], summary: { label: string, value: string }[] }
interface SiteOption { id: number, name: string }

const catalog = ref<ReportMeta[]>([])
const selectedType = ref<string>('')
const range = ref('30d')
const siteId = ref<number | null>(null)
const role = ref<string | null>(null)
const selectedFields = ref<string[]>([])
const result = ref<ReportResult | null>(null)
const loading = ref(false)
const sites = ref<SiteOption[]>([])

const rangeOptions = [
  { value: '24h', label: '24h' },
  { value: '7d', label: '7 days' },
  { value: '30d', label: '30 days' },
  { value: '90d', label: '90 days' },
]
const roleOptions = [
  { value: null, title: 'All roles' },
  { value: 'switch', title: 'Switches' },
  { value: 'edgeconnect', title: 'SD-WAN (EdgeConnect)' },
  { value: 'firewall', title: 'Firewalls' },
]

const current = computed(() => catalog.value.find(r => r.type === selectedType.value) ?? null)

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
  // Default the field selection to the report's default columns.
  selectedFields.value = (current.value?.fields ?? []).filter(f => f.default).map(f => f.key)
}

function buildParams(): URLSearchParams {
  const p = new URLSearchParams()
  if (current.value?.time_scoped)
    p.set('range', range.value)
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
  try {
    result.value = await api<ReportResult>(`/api/reports/${selectedType.value}?${buildParams()}`)
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
</script>

<template>
  <div>
    <h4 class="text-h4 mb-1">Reports</h4>
    <p class="text-body-1 text-medium-emphasis mb-5">
      Live reports built from real availability. Circuit, device and SD-WAN uptime come from infrastructure outages —
      access-port flapping (a laptop unplugged for a meeting) is deliberately excluded, so the numbers are defensible.
    </p>

    <VRow>
      <!-- Report picker + options -->
      <VCol cols="12" md="4" lg="3">
        <VCard>
          <VCardItem><VCardTitle class="text-body-1">Report</VCardTitle></VCardItem>
          <VList density="compact" class="py-0">
            <VListItem
              v-for="r in catalog"
              :key="r.type"
              :active="r.type === selectedType"
              @click="selectType(r.type)"
            >
              <VListItemTitle>{{ r.label }}</VListItemTitle>
            </VListItem>
          </VList>

          <VDivider />
          <VCardText class="d-flex flex-column ga-4">
            <div v-if="current?.time_scoped">
              <div class="text-caption text-medium-emphasis mb-1">Time window</div>
              <VBtnToggle v-model="range" density="comfortable" variant="outlined" mandatory divided>
                <VBtn v-for="o in rangeOptions" :key="o.value" :value="o.value" size="small">{{ o.label }}</VBtn>
              </VBtnToggle>
            </div>

            <VSelect
              v-model="siteId"
              :items="siteItems"
              item-title="name"
              item-value="id"
              label="Site"
              density="compact"
              hide-details
              @update:model-value="v => (siteId = v || null)"
            />

            <VSelect
              v-if="current?.supports_role"
              v-model="role"
              :items="roleOptions"
              label="Device role"
              density="compact"
              hide-details
            />

            <div>
              <div class="text-caption text-medium-emphasis mb-2">Fields to include</div>
              <div class="d-flex flex-wrap ga-2">
                <VChip
                  v-for="f in current?.fields ?? []"
                  :key="f.key"
                  :color="selectedFields.includes(f.key) ? 'primary' : undefined"
                  :variant="selectedFields.includes(f.key) ? 'flat' : 'tonal'"
                  size="small"
                  @click="selectedFields.includes(f.key)
                    ? selectedFields = selectedFields.filter(x => x !== f.key)
                    : selectedFields.push(f.key)"
                >
                  {{ f.label }}
                </VChip>
              </div>
            </div>

            <div class="d-flex ga-2">
              <VBtn color="primary" :loading="loading" :disabled="!selectedFields.length" @click="generate">
                Run report
              </VBtn>
              <VBtn variant="tonal" prepend-icon="ri-file-excel-2-line" :disabled="!selectedFields.length" @click="exportReport">
                Export
              </VBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Result -->
      <VCol cols="12" md="8" lg="9">
        <VCard>
          <VCardItem>
            <VCardTitle class="text-body-1">{{ result?.title ?? current?.label ?? 'Report' }}</VCardTitle>
          </VCardItem>

          <VCardText v-if="result?.summary?.length" class="pt-0">
            <div class="report-kpis">
              <div v-for="s in result.summary" :key="s.label" class="report-kpi">
                <div class="report-kpi__l">{{ s.label }}</div>
                <div class="report-kpi__v">{{ s.value }}</div>
              </div>
            </div>
          </VCardText>

          <VDataTable
            v-if="result"
            :headers="tableHeaders"
            :items="result.rows"
            :items-per-page="25"
            density="compact"
            class="report-table"
          >
            <template #item.uptime_pct="{ item }">
              <span :class="Number(item.uptime_pct) >= 99.9 ? 'text-success' : Number(item.uptime_pct) >= 99 ? 'text-warning' : 'text-error'">
                {{ Number(item.uptime_pct).toFixed(3) }}%
              </span>
            </template>
          </VDataTable>

          <VCardText v-else class="text-center text-medium-emphasis py-12">
            Pick a report, choose your window and fields, then <b>Run report</b>.
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.report-kpis { display: flex; flex-wrap: wrap; gap: 1px; border-radius: 10px; overflow: hidden; background: rgba(var(--v-border-color), var(--v-border-opacity)); }
.report-kpi { flex: 1; min-inline-size: 120px; padding: 12px 16px; background: rgb(var(--v-theme-surface)); }
.report-kpi__l { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.6; margin-block-end: 3px; }
.report-kpi__v { font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 1.25rem; font-weight: 650; }
.report-table :deep(td) { font-variant-numeric: tabular-nums; }
</style>
