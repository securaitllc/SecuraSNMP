<script lang="ts" setup>
import { api } from '@/composables/useApi'

definePage({ meta: { layout: 'mobile', title: 'Sites' } })

/** Every site, worst first. Same rows the dashboard map paints, so the colours agree. */
interface SiteRow {
  id: number
  name: string
  health: 'critical' | 'maintenance' | 'good'
  active_alert_count: number
  maintenance_alert_count?: number
  device_count: number
  circuit_count: number
}

const rows = ref<SiteRow[]>([])
const loading = ref(true)
const error = ref('')
const filter = ref<'all' | 'down' | 'maint'>('all')
const q = ref('')
let timer: number | undefined

async function load() {
  try {
    const d = await api<{ sites: SiteRow[] }>('/api/dashboard')
    rows.value = d.sites
    error.value = ''
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load the sites.'
  }
  finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  timer = window.setInterval(load, 60_000)
})
onUnmounted(() => clearInterval(timer))

const counts = computed(() => ({
  down: rows.value.filter(s => s.health === 'critical').length,
  maint: rows.value.filter(s => s.health === 'maintenance').length,
}))

const filters = computed(() => [
  { key: 'all' as const, label: `All ${rows.value.length}` },
  { key: 'down' as const, label: `Down ${counts.value.down}` },
  { key: 'maint' as const, label: `Maintenance ${counts.value.maint}` },
])

const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  const rank = { critical: 0, maintenance: 1, good: 2 }

  return rows.value
    .filter(s => filter.value === 'all' || (filter.value === 'down' ? s.health === 'critical' : s.health === 'maintenance'))
    .filter(s => !term || s.name.toLowerCase().includes(term))
    .sort((a, b) => (rank[a.health] - rank[b.health]) || (b.active_alert_count - a.active_alert_count) || a.name.localeCompare(b.name))
})

function sub(s: SiteRow): string {
  const bits = [`${s.device_count} dev`, `${s.circuit_count} ckt`]
  if (s.maintenance_alert_count)
    bits.push(`${s.maintenance_alert_count} in maint`)

  return bits.join(' · ')
}
</script>

<template>
  <div>
    <div
      v-if="error"
      class="m-empty"
    >
      {{ error }}
    </div>

    <VTextField
      v-model="q"
      placeholder="Filter sites"
      prepend-inner-icon="ri-search-line"
      variant="outlined"
      density="compact"
      clearable
      hide-details
      class="mb-2"
      autocapitalize="off"
      autocorrect="off"
    />

    <div class="m-chips">
      <VChip
        v-for="f in filters"
        :key="f.key"
        size="small"
        label
        :variant="filter === f.key ? 'flat' : 'outlined'"
        :color="filter === f.key ? (f.key === 'down' ? 'error' : 'primary') : undefined"
        @click="filter = f.key"
      >
        {{ f.label }}
      </VChip>
    </div>

    <div
      v-if="shown.length"
      class="m-list"
    >
      <RouterLink
        v-for="s in shown"
        :key="s.id"
        :to="`/m/site/${s.id}`"
        class="m-row"
        :class="s.health === 'critical' ? 'sev-critical' : s.health === 'maintenance' ? 'sev-warning' : 'sev-ok'"
      >
        <span class="bar" />
        <span class="main">
          <span class="t">{{ s.name }}</span>
          <span class="s">{{ sub(s) }}</span>
        </span>
        <span class="meta">{{ s.active_alert_count ? `${s.active_alert_count} alert${s.active_alert_count === 1 ? '' : 's'}` : (s.health === 'maintenance' ? 'MAINT' : 'OK') }}</span>
      </RouterLink>
    </div>
    <div
      v-else-if="!loading"
      class="m-empty"
    >
      {{ filter === 'down' ? 'No site is down.' : filter === 'maint' ? 'Nothing under maintenance.' : 'No sites match.' }}
    </div>
  </div>
</template>
