<script setup lang="ts">
import { api } from '@/composables/useApi'
import type { SlaRow } from '@/types/models'

definePage({ meta: { layout: 'default' } })

const rows = ref<SlaRow[]>([])
const range = ref<'24h' | '7d' | '30d'>('30d')
const isLoading = ref(true)

const headers = [
  { title: 'Type', key: 'type' },
  { title: 'Name', key: 'name' },
  { title: 'Device / Site', key: 'device' },
  { title: 'Uptime', key: 'uptime_pct' },
  { title: 'Incidents', key: 'incidents' },
  { title: 'MTTR', key: 'mttr_seconds' },
]

function uptimeColor(pct: number): string {
  if (pct >= 99.9) return 'success'
  if (pct >= 99) return 'warning'
  return 'error'
}

function mins(seconds: number | null): string {
  if (seconds === null) return '—'
  const m = Math.round(seconds / 60)
  return m >= 60 ? `${Math.floor(m / 60)}h ${m % 60}m` : `${m}m`
}

async function load() {
  isLoading.value = true
  const res = await api<{ rows: SlaRow[] }>(`/api/sla?range=${range.value}`)
  rows.value = res.rows
  isLoading.value = false
}

function exportXlsx() {
  window.location.href = `/api/sla/export?range=${range.value}`
}

watch(range, load)
onMounted(load)
</script>

<template>
  <VCard title="SLA & Availability">
    <template #append>
      <div class="d-flex align-center ga-2">
        <VBtnToggle v-model="range" density="compact" variant="outlined" mandatory>
          <VBtn value="24h" size="small">24h</VBtn>
          <VBtn value="7d" size="small">7d</VBtn>
          <VBtn value="30d" size="small">30d</VBtn>
        </VBtnToggle>
        <VBtn size="small" prepend-icon="ri-file-excel-2-line" @click="exportXlsx">Export</VBtn>
      </div>
    </template>
    <VCardText class="pb-0">
      <VAlert type="info" variant="tonal" density="compact">
        Availability is computed from outage history over the selected window. Print this page (Ctrl+P) for a PDF, or export the data to Excel.
      </VAlert>
    </VCardText>
    <VDataTable :headers="headers" :items="rows" :loading="isLoading" density="compact" :items-per-page="25">
      <template #item.type="{ item }"><span class="text-capitalize">{{ item.type }}</span></template>
      <template #item.device="{ item }">{{ item.device ?? '—' }}</template>
      <template #item.uptime_pct="{ item }">
        <VChip :color="uptimeColor(item.uptime_pct)" size="small" label>{{ item.uptime_pct }}%</VChip>
      </template>
      <template #item.mttr_seconds="{ item }">{{ mins(item.mttr_seconds) }}</template>
      <template #no-data>
        <div class="py-4 text-medium-emphasis">No outages recorded in this window — everything at 100%.</div>
      </template>
    </VDataTable>
  </VCard>
</template>
