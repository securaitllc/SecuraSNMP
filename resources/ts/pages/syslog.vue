<script setup lang="ts">
import { api } from '@/composables/useApi'
import { formatDateTime } from '@/utils/datetime'
import type { Device, SyslogMessage } from '@/types/models'

definePage({ meta: { layout: 'default' } })

const messages = ref<SyslogMessage[]>([])
const devices = ref<Device[]>([])
const isLoading = ref(true)

const filters = ref({ device_id: null as number | null, severity: null as number | null, q: '' })

const severityLabels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']
const severityOptions = severityLabels.map((title, value) => ({ title: `${title} and worse`, value }))

function severityColor(sev: number | null): string {
  if (sev === null) return 'secondary'
  if (sev <= 2) return 'error'
  if (sev === 3) return 'error'
  if (sev === 4) return 'warning'
  return 'info'
}

const headers = [
  { title: 'Time', key: 'received_at' },
  { title: 'Severity', key: 'severity' },
  { title: 'Device', key: 'device' },
  { title: 'Source', key: 'source_ip' },
  { title: 'Message', key: 'message' },
]

async function load() {
  isLoading.value = true
  const params = new URLSearchParams()
  if (filters.value.device_id) params.set('device_id', String(filters.value.device_id))
  if (filters.value.severity !== null) params.set('severity', String(filters.value.severity))
  if (filters.value.q) params.set('q', filters.value.q)
  messages.value = await api<SyslogMessage[]>(`/api/syslog?${params.toString()}`)
  isLoading.value = false
}

onMounted(async () => {
  const res = await api<{ data: Device[] }>('/api/devices')
  devices.value = res.data
  await load()
})
</script>

<template>
  <div>
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">Syslog</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Live syslog stream from the fleet — filter by device, severity or message text.
        </p>
      </div>
      <VBtn variant="text" icon="ri-refresh-line" @click="load" />
    </div>

    <VCard>
    <VCardText>
      <VRow>
        <VCol cols="12" sm="4">
          <VSelect v-model="filters.device_id" :items="devices" item-title="name" item-value="id" label="Device" clearable density="compact" @update:model-value="load" />
        </VCol>
        <VCol cols="12" sm="4">
          <VSelect v-model="filters.severity" :items="severityOptions" label="Severity" clearable density="compact" @update:model-value="load" />
        </VCol>
        <VCol cols="12" sm="4">
          <VTextField v-model="filters.q" label="Search message" density="compact" append-inner-icon="ri-search-line" @keyup.enter="load" @click:append-inner="load" />
        </VCol>
      </VRow>
    </VCardText>
    <VDataTable :headers="headers" :items="messages" :loading="isLoading" density="compact" :items-per-page="25">
      <template #item.received_at="{ item }">{{ formatDateTime(item.received_at) }}</template>
      <template #item.severity="{ item }">
        <VChip :color="severityColor(item.severity)" size="x-small" label>
          {{ item.severity !== null ? severityLabels[item.severity] : '—' }}
        </VChip>
      </template>
      <template #item.device="{ item }">{{ item.device?.name ?? item.hostname ?? '—' }}</template>
      <template #item.message="{ item }">
        <span class="font-mono text-caption">{{ item.message }}</span>
      </template>
    </VDataTable>
  </VCard>
  </div>
</template>

<style scoped>
.font-mono { font-family: 'Roboto Mono', monospace; }
</style>
