<script lang="ts" setup>
import { api } from '@/composables/useApi'

interface CaseRow {
  id: number
  case_number: string
  title: string
  severity: string
  status: string
  iocs_count: number
  owner?: { name: string } | null
  created_at: string
}

const rows = ref<CaseRow[]>([])
const loading = ref(true)
onMounted(async () => {
  try { rows.value = (await api<{ data: CaseRow[] }>('/api/osint/cases')).data }
  finally { loading.value = false }
})

const sevColor: Record<string, string> = { low: 'info', medium: 'secondary', high: 'warning', critical: 'error' }
const statusColor: Record<string, string> = { open: 'info', investigating: 'warning', contained: 'success', closed: 'secondary' }
</script>

<template>
  <div>
    <div class="d-flex align-center ga-3 mb-4">
      <VIcon icon="ri-folder-shield-2-line" size="24" class="text-primary" />
      <h4 class="text-h4 mb-0">OSINT Cases</h4>
      <VSpacer />
      <VBtn color="primary" prepend-icon="ri-search-eye-line" :to="{ name: 'osint' }">New investigation</VBtn>
    </div>

    <VCard>
      <VProgressLinear v-if="loading" indeterminate color="primary" />
      <VTable>
        <thead>
          <tr>
            <th>Case</th><th>Title</th><th>Severity</th><th>Status</th><th>IoCs</th><th>Owner</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!loading && !rows.length"><td colspan="6" class="text-center text-medium-emphasis py-6">No cases yet.</td></tr>
          <tr v-for="c in rows" :key="c.id" style="cursor: pointer" @click="$router.push({ name: 'osint-cases-id', params: { id: c.id } })">
            <td class="ce-mono">{{ c.case_number }}</td>
            <td>{{ c.title }}</td>
            <td><VChip size="x-small" :color="sevColor[c.severity]" variant="tonal">{{ c.severity }}</VChip></td>
            <td><VChip size="x-small" :color="statusColor[c.status]" variant="tonal">{{ c.status }}</VChip></td>
            <td>{{ c.iocs_count }}</td>
            <td class="text-medium-emphasis">{{ c.owner?.name ?? '—' }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>

<style scoped>
.ce-mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; }
</style>
