<script lang="ts" setup>
import { api } from '@/composables/useApi'

definePage({ meta: { layout: 'mobile', title: 'Status' } })

/** The dashboard's fleet counters, nothing else: a phone glance, not a wall. */
interface Dashboard {
  counts: Record<string, number>
  sites: { id: number, name: string, health: string, active_alert_count: number, maintenance_alert_count?: number }[]
}

interface Incident {
  kind: 'device' | 'tunnel'
  site_id: number | null
  site_name: string | null
  root_device_id?: number
  root_device_name: string
  root_role: string
  affected: Record<string, number>
  affected_total: number
}
interface VulnSummary { open_findings: number, by_severity: Record<string, number>, coverage: { affected_devices: number, assessed_devices: number } }

const data = ref<Dashboard | null>(null)
const incidents = ref<Incident[]>([])
const vuln = ref<VulnSummary | null>(null)
const error = ref('')
const loading = ref(true)
let timer: number | undefined

async function load() {
  try {
    const [d, i] = await Promise.all([
      api<Dashboard>('/api/dashboard'),
      api<{ data: Incident[] }>('/api/incidents').catch(() => ({ data: [] as Incident[] })),
    ])
    data.value = d
    incidents.value = i.data
    error.value = ''
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load the fleet status.'
  }
  finally {
    loading.value = false
  }
}

// Exposure changes on a feed cadence, not a poll cadence: once per visit is enough.
async function loadVuln() {
  try {
    vuln.value = await api<VulnSummary>('/api/vulnerabilities/summary')
  }
  catch {
    vuln.value = null
  }
}

onMounted(() => {
  load()
  loadVuln()
  timer = window.setInterval(load, 30_000)
})

function affectedText(i: Incident): string {
  return Object.entries(i.affected ?? {}).map(([k, v]) => `${v} ${k}`).join(' · ')
}
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

    <section
      v-if="incidents.length"
      class="m-section"
    >
      <h2>Root cause</h2>
      <div class="m-list">
        <RouterLink
          v-for="(i, n) in incidents"
          :key="n"
          :to="i.kind === 'device' && i.root_device_id ? `/m/device/${i.root_device_id}` : (i.site_id ? `/m/site/${i.site_id}` : '/m/alarms')"
          class="m-row sev-critical"
        >
          <span class="bar" />
          <span class="main">
            <span class="t">{{ i.root_device_name }}<template v-if="i.site_name"> · {{ i.site_name }}</template></span>
            <span class="s">{{ i.kind === 'tunnel' ? 'SD-WAN tunnels' : i.root_role }} down → {{ affectedText(i) }}</span>
          </span>
          <span class="meta">{{ i.affected_total }} hit</span>
        </RouterLink>
      </div>
    </section>

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

    <section
      v-if="vuln"
      class="m-section"
    >
      <h2>Exposure</h2>
      <div class="m-tiles">
        <div
          class="m-tile"
          :class="(vuln.by_severity.critical ?? 0) > 0 ? 'sev-critical' : (vuln.by_severity.high ?? 0) > 0 ? 'sev-warning' : 'sev-ok'"
        >
          <span class="n">{{ (vuln.by_severity.critical ?? 0) + (vuln.by_severity.high ?? 0) }}</span>
          <span class="l">Critical + high CVEs</span>
        </div>
        <div
          class="m-tile"
          :class="vuln.coverage.affected_devices ? 'sev-warning' : 'sev-ok'"
        >
          <span class="n">{{ vuln.coverage.affected_devices }}</span>
          <span class="l">Devices affected · {{ vuln.open_findings }} findings</span>
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
      <h2>Sites with alerts <RouterLink to="/m/sites" class="m-more">all sites →</RouterLink></h2>
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
