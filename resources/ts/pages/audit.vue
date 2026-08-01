<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/utils/datetime'
import type { AuditLogEntry } from '@/types/models'

definePage({ meta: { layout: 'default' } })

const auth = useAuthStore()
const entries = ref<AuditLogEntry[]>([])
const isLoading = ref(true)

const headers = [
  { title: 'Time', key: 'created_at' },
  { title: 'User', key: 'user_name' },
  { title: 'Action', key: 'method' },
  { title: 'Resource', key: 'path' },
  { title: 'Status', key: 'status' },
  // This is the IP of the operator who made the request (the browser), NOT the
  // device being acted on — the acted-on resource is in the Resource column.
  { title: 'From (operator)', key: 'ip_address' },
]

const methodColor: Record<string, string> = { POST: 'success', PUT: 'info', PATCH: 'info', DELETE: 'error' }

async function load() {
  isLoading.value = true
  entries.value = await api<AuditLogEntry[]>('/api/audit-logs')
  isLoading.value = false
}

onMounted(() => {
  if (auth.isAdmin) load()
  else isLoading.value = false
})
</script>

<template>
  <div>
  <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
    <div>
      <h4 class="text-h4 mb-1">Audit Log</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">Every configuration and NOC action — who did it, to what, and when.</p>
    </div>
    <VBtn variant="text" icon="ri-refresh-line" @click="load" />
  </div>

  <div v-if="!auth.isAdmin">
    <VAlert type="info" variant="tonal">The audit log is an administrator function.</VAlert>
  </div>

  <VCard v-else>
    <VDataTable :headers="headers" :items="entries" :loading="isLoading" density="compact" :items-per-page="25">
      <template #item.created_at="{ item }">{{ formatDateTime(item.created_at) }}</template>
      <template #item.user_name="{ item }">{{ item.user_name ?? '—' }}</template>
      <template #item.method="{ item }">
        <VChip :color="methodColor[item.method] ?? 'secondary'" size="x-small" label>{{ item.method }}</VChip>
      </template>
      <template #item.status="{ item }">
        <span :class="item.status < 300 ? 'text-success' : 'text-error'">{{ item.status }}</span>
      </template>
    </VDataTable>
  </VCard>
  </div>
</template>
