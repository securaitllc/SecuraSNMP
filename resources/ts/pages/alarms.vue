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

const alarms = ref<AlarmRow[]>([])
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

// Re-query the DB on any filter change; debounce the free-text box.
let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(load, 300)
})
watch([scope, severity, siteId], load)

onMounted(async () => {
  sites.value = await api<SiteOption[]>('/api/sites')
  await load()
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
      <VBtnToggle v-model="view" mandatory density="comfortable" variant="outlined" color="primary">
        <VBtn value="list" prepend-icon="ri-list-check">List</VBtn>
        <VBtn value="isp" prepend-icon="ri-router-line">By ISP</VBtn>
      </VBtnToggle>
    </div>

    <!-- Active alarms grouped per ISP circuit — one ticket + dispatch per provider. -->
    <div v-if="view === 'isp'" class="mt-4">
      <VSelect
        v-model="siteId"
        :items="siteItems"
        item-title="name"
        item-value="id"
        density="compact"
        hide-details
        style="max-width: 220px;"
        class="mb-4"
        @update:model-value="v => (siteId = v || null)"
      />
      <AlarmGroups :site-id="siteId" />
    </div>

    <template v-else>
    <ListTabs v-model="scope" :tabs="scopeTabs" class="mt-4" />

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
        <VSelect
          v-model="siteId"
          :items="siteItems"
          item-title="name"
          item-value="id"
          density="compact"
          hide-details
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
        :items="alarms"
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
          <span class="font-mono">{{ item.ticket_number ? `#${item.ticket_number}` : '—' }}</span>
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
</style>
