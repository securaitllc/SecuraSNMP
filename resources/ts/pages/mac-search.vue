<script setup lang="ts">
import { api } from '@/composables/useApi'
import { formatDateTime } from '@/utils/datetime'

definePage({ meta: { layout: 'default' } })

interface MacRow {
  id: number
  mac: string
  oui_vendor: string | null
  vlan: number
  device_id: number
  device_name: string | null
  interface_id: number | null
  interface_name: string | null
  interface_status: string | null
  first_seen_at: string
  last_seen_at: string
}

const q = ref('')
const rows = ref<MacRow[]>([])
const loading = ref(false)
let debounce: ReturnType<typeof setTimeout> | null = null

async function search() {
  loading.value = true
  try {
    rows.value = await api<MacRow[]>(`/api/mac-addresses?q=${encodeURIComponent(q.value)}`)
  }
  finally { loading.value = false }
}
watch(q, () => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(search, 300)
})
onMounted(search)

const headers = [
  { title: 'MAC', key: 'mac', minWidth: 170 },
  { title: 'Vendor (OUI)', key: 'oui_vendor', minWidth: 200 },
  { title: 'Device', key: 'device_name', minWidth: 160 },
  { title: 'Port', key: 'interface_name', minWidth: 140 },
  { title: 'VLAN', key: 'vlan', width: 80 },
  { title: 'Last seen', key: 'last_seen_at', minWidth: 170 },
]
</script>

<template>
  <div>
    <h4 class="text-h4 mb-1">MAC Search</h4>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Where a MAC — or a vendor — has been seen across the fleet: device, port, VLAN, last-seen. Retained after aging out, so down ports still resolve.
    </p>

    <VCard class="list-surface">
      <VCardText class="pb-0">
        <VTextField
          v-model="q"
          placeholder="MAC (any format) or vendor — e.g. b8:27:eb, 00000c, or ‘Aruba’"
          prepend-inner-icon="ri-search-line"
          density="compact"
          hide-details
          clearable
          style="max-width: 480px;"
        />
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="rows"
        :loading="loading"
        :items-per-page="25"
        density="compact"
        class="mt-2"
      >
        <template #item.mac="{ item }">
          <CopyBtn :text="item.mac" class="font-mono" />
        </template>
        <template #item.oui_vendor="{ item }">{{ item.oui_vendor ?? '—' }}</template>
        <template #item.device_name="{ item }">
          <RouterLink v-if="item.device_id" :to="`/devices/${item.device_id}`">{{ item.device_name ?? '—' }}</RouterLink>
          <span v-else>—</span>
        </template>
        <template #item.interface_name="{ item }">
          <span class="font-mono">{{ item.interface_name ?? '—' }}</span>
          <VChip v-if="item.interface_status === 'down'" size="x-small" color="error" variant="tonal" class="ms-1">down</VChip>
        </template>
        <template #item.last_seen_at="{ item }">{{ formatDateTime(item.last_seen_at) }}</template>
        <template #no-data>
          <div class="pa-4 text-medium-emphasis">{{ q ? 'No MACs match.' : 'Learned MACs will appear as the fleet is polled.' }}</div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>

<style scoped>
.font-mono { font-family: ui-monospace, "SF Mono", Menlo, monospace; }
</style>
