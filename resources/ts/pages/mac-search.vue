<script setup lang="ts">
import { api } from '@/composables/useApi'
import { formatDateTime } from '@/utils/datetime'

definePage({ meta: { layout: 'default' } })

interface MacRow {
  id: number
  mac: string
  oui_vendor: string | null
  vlan: string
  device_id: number
  device_name: string | null
  site_id: number | null
  site_name: string | null
  interface_id: number | null
  interface_name: string | null
  interface_status: string | null
  first_seen_at: string
  last_seen_at: string
}

// Seed from ?q= so a global-search "endpoint" result deep-links straight into a
// pre-filled MAC search.
const route = useRoute()
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const rows = ref<MacRow[]>([])
const capped = ref(false)
const loading = ref(false)
let debounce: ReturnType<typeof setTimeout> | null = null

async function search() {
  loading.value = true
  try {
    const res = await api<{ capped: boolean, data: MacRow[] }>(`/api/mac-addresses?q=${encodeURIComponent(q.value)}`)
    rows.value = res.data
    capped.value = res.capped
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
  { title: 'Site', key: 'site_name', minWidth: 150 },
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
          placeholder="MAC, vendor, site, or device — e.g. b8:27:eb, Verkada, #185, or SC185SWA001"
          prepend-inner-icon="ri-search-line"
          density="compact"
          hide-details
          clearable
          style="max-width: 480px;"
        />
      </VCardText>

      <VAlert
        v-if="capped"
        type="info"
        variant="tonal"
        density="compact"
        class="mx-4 mt-3"
      >
        Showing the 1000 most-recently-seen (one per site — a roaming client counts once per location). The busiest site fills this — search a site (‘#185’, ‘Cleveland’), vendor, device, or MAC to scope it.
      </VAlert>

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
        <template #item.site_name="{ item }">
          <RouterLink v-if="item.site_name" :to="`/sites?q=${encodeURIComponent(item.site_name)}`">{{ item.site_name }}</RouterLink>
          <span v-else>—</span>
        </template>
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
