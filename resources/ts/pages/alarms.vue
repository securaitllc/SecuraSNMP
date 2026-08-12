<script setup lang="ts">
import { api } from '@/composables/useApi'
import { formatDateTime } from '@/utils/datetime'

definePage({ meta: { layout: 'default' } })

interface AlarmRow {
  id: number
  alarm_id: string
  severity: string
  description: string | null
  ticket_number: string | null
  device_name: string | null
  site_name: string | null
  first_seen_at: string
  cleared_at: string | null
  acknowledged_at: string | null
  acknowledged_by_name: string | null
  cleared_by_name: string | null
  active: boolean
}
interface SiteOption { id: number, name: string }

interface Incident {
  site_name: string | null
  root_device_name: string
  root_role: string
  ticket_number: string | null
  started_at: string | null
  affected_total: number
  affected: Record<string, number>
  suppressed_count: number
  suppressed_device_names: string[]
}

const alarms = ref<AlarmRow[]>([])
const incidents = ref<Incident[]>([])
const suppressedIds = ref<Set<number>>(new Set())
const expandedIncident = ref<number | null>(null)
const counts = ref({ all: 0, active: 0, cleared: 0 })
const capped = ref(false)
const loading = ref(false)
const sites = ref<SiteOption[]>([])

const scope = ref<string | null>('active')
const severity = ref<string | null>(null)
const siteId = ref<number | null>(null)
const search = ref('')

// List = the flat log; ISP = active alarms grouped per ISP circuit (ticket/dispatch).
const view = ref<'list' | 'isp'>('list')

// Scope tabs use non-severity hues (the severity palette is reserved for the
// severity column + pills); counts come live from the endpoint.
const scopeTabs = computed(() => [
  { value: 'all', label: 'All', count: counts.value.all, color: '#7C8AA0' },
  { value: 'active', label: 'Active', count: counts.value.active, color: '#4C8DFF' },
  { value: 'cleared', label: 'Cleared', count: counts.value.cleared, color: '#06B6D4' },
])
const severityChips = [
  { key: 'critical', label: 'Critical', color: 'error' },
  { key: 'warning', label: 'Warning', color: 'warning' },
  { key: 'info', label: 'Info', color: 'secondary' },
]
const headers = [
  { title: 'Opened', key: 'first_seen_at', minWidth: 170 },
  { title: 'Severity', key: 'severity', width: 110 },
  { title: 'Site', key: 'site_name', minWidth: 150 },
  { title: 'Device', key: 'device_name', minWidth: 150 },
  { title: 'Alarm', key: 'description', minWidth: 240 },
  { title: 'Ticket', key: 'ticket_number', width: 120 },
  { title: 'Duration', key: 'duration', sortable: false, width: 120 },
  { title: 'Status', key: 'status', align: 'center' as const, width: 110 },
]

const severityColor: Record<string, string> = { critical: 'error', warning: 'warning', info: 'secondary' }
const siteItems = computed(() => [{ id: 0, name: 'All sites' }, ...sites.value])

function durationOf(a: AlarmRow): string {
  const start = new Date(a.first_seen_at).getTime()
  const end = a.cleared_at ? new Date(a.cleared_at).getTime() : Date.now()
  const mins = Math.max(0, Math.round((end - start) / 60000))
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  if (h < 24) return `${h}h ${mins % 60}m`
  return `${Math.floor(h / 24)}d ${h % 24}h`
}

function params(): URLSearchParams {
  const p = new URLSearchParams()
  if (scope.value && scope.value !== 'all') p.set('scope', scope.value)
  if (severity.value) p.set('severity', severity.value)
  if (siteId.value) p.set('site_id', String(siteId.value))
  if (search.value.trim()) p.set('q', search.value.trim())
  return p
}

async function load() {
  loading.value = true
  try {
    const res = await api<{ alarms: AlarmRow[], counts: typeof counts.value, capped: boolean }>(`/api/alarms/log?${params()}`)
    alarms.value = res.alarms
    counts.value = res.counts
    capped.value = res.capped
  }
  finally {
    loading.value = false
  }
}

// Dependency-aware root-cause incidents. The suppressed symptom alarms are hidden from
// the list and rolled up under their root, so a core-switch outage reads as ONE incident.
async function loadIncidents() {
  try {
    const res = await api<{ data: Incident[], suppressed_alarm_ids: number[] }>('/api/incidents')
    incidents.value = res.data
    suppressedIds.value = new Set(res.suppressed_alarm_ids)
  }
  catch {
    incidents.value = []
    suppressedIds.value = new Set()
  }
}

// The rows actually shown: suppressed downstream symptoms are folded into their incident.
const visibleAlarms = computed(() => alarms.value.filter(a => !suppressedIds.value.has(a.id)))
const affectedBreakdown = (a: Record<string, number>) =>
  Object.entries(a).map(([k, v]) => `${v} ${k}`).join(' · ')

// Re-query the DB on any filter change; debounce the free-text box.
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
})
watch([scope, severity, siteId], load)

onMounted(async () => {
  sites.value = await api<SiteOption[]>('/api/sites')
  await Promise.all([load(), loadIncidents()])
})
</script>

<template>
  <div>
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">Alarms</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Every active and historical alarm across the fleet — searchable by device, site, ticket or text.
        </p>
      </div>
      <div class="switch-toggle">
        <button :class="{ on: view === 'list' }" @click="view = 'list'">
          <VIcon icon="ri-list-check" />List
        </button>
        <button :class="{ on: view === 'isp' }" @click="view = 'isp'">
          <VIcon icon="ri-router-line" />By ISP
        </button>
      </div>
    </div>

    <!-- Active alarms grouped per ISP circuit — one ticket + dispatch per provider. -->
    <div v-if="view === 'isp'" class="mt-4">
      <VAutocomplete
        v-model="siteId"
        :items="siteItems"
        item-title="name"
        item-value="id"
        density="compact"
        hide-details
        auto-select-first
        style="max-width: 220px;"
        class="mb-4"
        @update:model-value="v => (siteId = v || null)"
      />
      <AlarmGroups :site-id="siteId" />
    </div>

    <template v-else>
    <ListTabs v-model="scope" :tabs="scopeTabs" class="mt-4" />

    <!-- Dependency-aware root-cause incidents: one upstream failure, its whole downstream
         cascade rolled up and hidden from the list below. -->
    <div v-if="incidents.length" class="inc-wrap mt-4">
      <div
        v-for="(inc, idx) in incidents"
        :key="idx"
        class="inc"
      >
        <div class="inc__head">
          <VIcon icon="ri-focus-2-line" size="20" class="inc__ico" />
          <div class="inc__title">
            <div class="inc__root">
              Root cause: <strong>{{ inc.root_device_name }}</strong> unreachable
              <span class="inc__site">· {{ inc.site_name }}</span>
            </div>
            <div class="inc__sub">
              <strong>{{ inc.affected_total }}</strong> device{{ inc.affected_total === 1 ? '' : 's' }} affected — {{ affectedBreakdown(inc.affected) }}
            </div>
          </div>
          <VChip
            v-if="inc.suppressed_count"
            size="small"
            color="secondary"
            variant="tonal"
            class="inc__chip"
            @click="expandedIncident = expandedIncident === idx ? null : idx"
          >
            <VIcon start icon="ri-eye-off-line" size="14" />
            {{ inc.suppressed_count }} suppressed
            <VIcon end :icon="expandedIncident === idx ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'" size="14" />
          </VChip>
        </div>
        <div v-if="expandedIncident === idx && inc.suppressed_device_names.length" class="inc__names">
          <span v-for="n in inc.suppressed_device_names" :key="n" class="inc__name">{{ n }}</span>
        </div>
      </div>
    </div>

    <VCard class="list-surface">
      <VCardText class="pb-0 d-flex align-center flex-wrap ga-3">
        <VTextField
          v-model="search"
          placeholder="Search device, site, ticket, alarm ID or text…"
          prepend-inner-icon="ri-search-line"
          density="compact"
          hide-details
          clearable
          style="max-width: 380px;"
        />
        <VAutocomplete
          v-model="siteId"
          :items="siteItems"
          item-title="name"
          item-value="id"
          density="compact"
          hide-details
          auto-select-first
          style="max-width: 200px;"
          @update:model-value="v => (siteId = v || null)"
        />
        <VSpacer />
        <div class="list-pills">
          <button
            v-for="s in severityChips"
            :key="s.key"
            type="button"
            class="list-pill"
            :class="{ 'list-pill--on': severity === s.key }"
            @click="severity = severity === s.key ? null : s.key"
          >
            <span class="list-pill__d" :style="{ background: `rgb(var(--v-theme-${s.color}))` }" />
            {{ s.label }}
          </button>
        </div>
      </VCardText>

      <VAlert
        v-if="capped"
        type="info"
        variant="tonal"
        density="compact"
        class="mx-4 mt-3"
      >
        Showing the 500 most recent matches — narrow the search or filters to see older alarms.
      </VAlert>

      <VDataTable
        :headers="headers"
        :items="visibleAlarms"
        :loading="loading"
        :items-per-page="25"
        density="compact"
        class="alarms-table mt-2"
      >
        <template #item.first_seen_at="{ item }">
          {{ formatDateTime(item.first_seen_at) }}
        </template>
        <template #item.severity="{ item }">
          <VChip size="x-small" :color="severityColor[item.severity] ?? 'secondary'" variant="tonal" class="text-capitalize">
            {{ item.severity }}
          </VChip>
        </template>
        <template #item.site_name="{ item }">{{ item.site_name ?? '—' }}</template>
        <template #item.device_name="{ item }">{{ item.device_name ?? '—' }}</template>
        <template #item.description="{ item }">
          <div class="d-flex flex-column">
            <span>{{ item.description ?? '—' }}</span>
            <span class="text-caption text-medium-emphasis">{{ item.alarm_id }}</span>
          </div>
        </template>
        <template #item.ticket_number="{ item }">
          <CopyBtn v-if="item.ticket_number" :text="item.ticket_number" :label="`#${item.ticket_number}`" class="font-mono" />
          <span v-else>—</span>
        </template>
        <template #item.duration="{ item }">{{ durationOf(item) }}</template>
        <template #item.status="{ item }">
          <VChip size="x-small" :color="item.active ? 'error' : 'success'" variant="tonal">
            {{ item.active ? 'Active' : 'Cleared' }}
          </VChip>
        </template>
      </VDataTable>
    </VCard>
    </template>
  </div>
</template>

<style scoped>
.font-mono { font-family: ui-monospace, "SF Mono", Menlo, monospace; }
.alarms-table :deep(td) { font-variant-numeric: tabular-nums; }

.inc-wrap { display: flex; flex-direction: column; gap: 8px; }
.inc { border: 1px solid rgba(var(--v-theme-error), .4); border-inline-start-width: 3px; border-radius: 10px;
  background: rgba(var(--v-theme-error), .05); padding: 10px 14px; }
.inc__head { display: flex; align-items: center; gap: 12px; }
.inc__ico { color: rgb(var(--v-theme-error)); flex: none; }
.inc__title { flex: 1; min-inline-size: 0; }
.inc__root { font-size: 13.5px; }
.inc__site { color: rgba(var(--v-theme-on-surface), .55); font-size: 12px; }
.inc__sub { font-size: 12px; color: rgba(var(--v-theme-on-surface), .7); margin-block-start: 2px; }
.inc__chip { cursor: pointer; flex: none; }
.inc__names { display: flex; flex-wrap: wrap; gap: 6px; margin-block-start: 10px; padding-inline-start: 32px; }
.inc__name { font-size: 11px; font-family: ui-monospace, Menlo, monospace; padding: 2px 7px; border-radius: 5px;
  background: rgba(var(--v-theme-on-surface), .06); color: rgba(var(--v-theme-on-surface), .75); }
</style>
