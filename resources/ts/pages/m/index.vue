<script lang="ts" setup>
import { api } from '@/composables/useApi'

definePage({ meta: { layout: 'mobile', title: 'Status' } })

/** The dashboard's fleet counters, nothing else: a phone glance, not a wall. */
interface Dashboard {
  counts: Record<string, number>
  sites: { id: number, name: string, health: string, active_alert_count: number, maintenance_alert_count?: number }[]
}

const data = ref<Dashboard | null>(null)
const error = ref('')
const loading = ref(true)
let timer: number | undefined

async function load() {
  try {
    data.value = await api<Dashboard>('/api/dashboard')
    error.value = ''
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load the fleet status.'
  }
  finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  timer = window.setInterval(load, 30_000)
})
onUnmounted(() => clearInterval(timer))

const c = computed(() => data.value?.counts ?? {})
const impacted = computed(() => (data.value?.sites ?? [])
  .filter(s => s.health === 'critical' || s.active_alert_count > 0)
  .sort((a, b) => b.active_alert_count - a.active_alert_count)
  .slice(0, 12))

function sev(n: number | undefined, warnAt = 1): string {
  if (!n)
    return 'sev-ok'

  return n >= warnAt ? 'sev-critical' : 'sev-warning'
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

    <section class="m-section">
      <h2>Right now</h2>
      <div class="m-tiles">
        <RouterLink
          to="/m/alarms"
          class="m-tile"
          :class="sev(c.active_alerts)"
        >
          <span class="n">{{ c.active_alerts ?? '—' }}</span>
          <span class="l">Active alerts</span>
        </RouterLink>
        <div
          class="m-tile"
          :class="sev(c.active_incidents)"
        >
          <span class="n">{{ c.active_incidents ?? '—' }}</span>
          <span class="l">Incidents</span>
        </div>
        <div
          class="m-tile"
          :class="sev(c.sites_impacted)"
        >
          <span class="n">{{ c.sites_impacted ?? '—' }}</span>
          <span class="l">Sites impacted</span>
        </div>
        <div
          class="m-tile"
          :class="sev(c.circuits_down)"
        >
          <span class="n">{{ c.circuits_down ?? '—' }}</span>
          <span class="l">Circuits down</span>
        </div>
        <div
          class="m-tile"
          :class="sev(c.tunnels_down)"
        >
          <span class="n">{{ c.tunnels_down ?? '—' }}</span>
          <span class="l">Tunnels down</span>
        </div>
        <div
          class="m-tile"
          :class="sev(c.interfaces_down)"
        >
          <span class="n">{{ c.interfaces_down ?? '—' }}</span>
          <span class="l">Ports down</span>
        </div>
      </div>
    </section>

    <section class="m-section">
      <h2>Fleet</h2>
      <div class="m-kv">
        <div><span>Sites</span><span>{{ c.sites ?? '—' }}</span></div>
        <div><span>Devices reachable</span><span>{{ c.devices_reachable ?? '—' }} / {{ c.devices ?? '—' }}</span></div>
        <div><span>In maintenance</span><span>{{ c.devices_maintenance ?? 0 }} devices · {{ c.alerts_maintenance ?? 0 }} alerts</span></div>
      </div>
    </section>

    <section class="m-section">
      <h2>Sites with alerts</h2>
      <div
        v-if="impacted.length"
        class="m-list"
      >
        <RouterLink
          v-for="s in impacted"
          :key="s.id"
          :to="`/m/site/${s.id}`"
          class="m-row"
          :class="s.health === 'critical' ? 'sev-critical' : 'sev-warning'"
        >
          <span class="bar" />
          <span class="main"><span class="t">{{ s.name }}</span></span>
          <span class="meta">{{ s.active_alert_count }} alert{{ s.active_alert_count === 1 ? '' : 's' }}</span>
        </RouterLink>
      </div>
      <div
        v-else-if="!loading"
        class="m-empty"
      >
        No site has an active alert.
      </div>
    </section>
  </div>
</template>
