<script lang="ts" setup>
import { useRoute } from 'vue-router'
import { api } from '@/composables/useApi'
import { ago, mobileTitle } from '@/composables/useMobile'
import { useNotify } from '@/composables/useNotify'
import { useAuthStore } from '@/stores/auth'

definePage({ meta: { layout: 'mobile', title: 'Device' } })

interface Device {
  id: number
  site_id: number
  name: string
  ip_address: string
  vendor: string
  model: string
  role: string
  os_version: string | null
  serial_number: string | null
  status: string
  is_down?: boolean
  in_maintenance?: boolean
  last_success_at?: string | null
  stale_minutes?: number | null
  last_error?: string | null
  health?: { cpu_pct?: number | null, mem_pct?: number | null, measured?: boolean } | null
}
interface Alert { kind: string, id: number, severity: string, label: string | null, started_at: string | null, acknowledged_at: string | null }

const route = useRoute()
const auth = useAuthStore()
const notify = useNotify()
const id = computed(() => Number((route.params as { id: string }).id))

const device = ref<Device | null>(null)
const alerts = ref<Alert[]>([])
const error = ref('')
const ping = ref<{ reachable: boolean, rtt_ms: number | null, at: string } | null>(null)
const verify = ref<{ tunnels?: any[], alarms?: any[], next_hop_reachable?: boolean } | null>(null)
const busy = ref<'ping' | 'verify' | null>(null)

async function load() {
  try {
    const [d, a] = await Promise.all([
      api<{ data: Device }>(`/api/devices/${id.value}`),
      api<{ alerts: Alert[] }>('/api/alerts'),
    ])
    device.value = d.data
    alerts.value = a.alerts.filter(x => (x as any).device_id === id.value)
    mobileTitle.value = d.data.name
  }
  catch (e: any) {
    error.value = e?.status === 404 ? 'This device no longer exists.' : 'Could not load this device.'
  }
}

onMounted(load)
watch(id, load)

async function doPing() {
  busy.value = 'ping'
  try {
    ping.value = await api(`/api/devices/${id.value}/ping`, { method: 'POST' })
  }
  catch (e: any) {
    notify.error('Ping failed', e?.data?.message)
  }
  finally {
    busy.value = null
  }
}

async function doVerify() {
  busy.value = 'verify'
  try {
    verify.value = await api(`/api/devices/${id.value}/verify`, { method: 'POST' })
    notify.success('Verified over SSH')
  }
  catch (e: any) {
    notify.error('Verify failed', e?.data?.error ?? e?.data?.message)
  }
  finally {
    busy.value = null
  }
}

const tunnelsUp = computed(() => (verify.value?.tunnels ?? []).filter(t => String(t.status ?? '').toLowerCase() === 'up').length)

const state = computed(() => {
  const d = device.value
  if (!d)
    return ''
  if (d.is_down)
    return 'DOWN'
  if (d.in_maintenance)
    return 'MAINTENANCE'

  return d.status === 'active' ? 'UP' : d.status.toUpperCase()
})
</script>

<template>
  <div>
    <div
      v-if="error"
      class="m-empty"
    >
      {{ error }}
    </div>

    <template v-else-if="device">
      <section class="m-section">
        <div class="m-tiles">
          <div
            class="m-tile"
            :class="device.is_down ? 'sev-critical' : (device.in_maintenance ? 'sev-warning' : 'sev-ok')"
          >
            <span class="n" style="font-size:18px">{{ state }}</span>
            <span class="l">{{ device.ip_address }}</span>
          </div>
          <div
            class="m-tile"
            :class="alerts.length ? 'sev-critical' : 'sev-ok'"
          >
            <span class="n">{{ alerts.length }}</span>
            <span class="l">Active alarms</span>
          </div>
        </div>
      </section>

      <section class="m-section">
        <h2>About</h2>
        <div class="m-kv">
          <div><span>Site</span><span><RouterLink :to="`/m/site/${device.site_id}`">open site →</RouterLink></span></div>
          <div><span>Role</span><span>{{ device.role }} · {{ device.vendor }}</span></div>
          <div><span>Model</span><span>{{ device.model }}<template v-if="device.os_version"> · {{ device.os_version }}</template></span></div>
          <div v-if="device.serial_number"><span>Serial</span><span>{{ device.serial_number }}</span></div>
          <div v-if="device.last_success_at"><span>Last poll</span><span>{{ ago(device.last_success_at) }} ago<template v-if="device.stale_minutes && device.stale_minutes > 15"> · STALE</template></span></div>
          <div v-if="device.health?.measured !== false && (device.health?.cpu_pct != null || device.health?.mem_pct != null)"><span>CPU / Mem</span><span>{{ device.health?.cpu_pct ?? '—' }}% / {{ device.health?.mem_pct ?? '—' }}%</span></div>
          <div v-if="device.last_error"><span>Last error</span><span>{{ device.last_error }}</span></div>
        </div>
      </section>

      <section
        v-if="alerts.length"
        class="m-section"
      >
        <h2>Alarms</h2>
        <div class="m-list">
          <RouterLink
            v-for="a in alerts"
            :key="`${a.kind}-${a.id}`"
            to="/m/alarms"
            class="m-row"
            :class="[`sev-${a.severity}`, { 'is-acked': !!a.acknowledged_at }]"
          >
            <span class="bar" />
            <span class="main"><span class="t">{{ a.label || a.kind }}</span><span class="s">{{ a.kind }}</span></span>
            <span class="meta">{{ ago(a.started_at) }}</span>
          </RouterLink>
        </div>
      </section>

      <section class="m-section">
        <h2>Check</h2>
        <div class="m-actions">
          <VBtn
            variant="tonal"
            :loading="busy === 'ping'"
            :disabled="!!busy"
            prepend-icon="ri-pulse-line"
            @click="doPing"
          >
            Ping
          </VBtn>
          <VBtn
            v-if="auth.canAct && device.role === 'edgeconnect'"
            variant="tonal"
            :loading="busy === 'verify'"
            :disabled="!!busy"
            prepend-icon="ri-shield-check-line"
            @click="doVerify"
          >
            Verify (SSH)
          </VBtn>
        </div>
        <div
          v-if="ping"
          class="m-kv mt-3"
        >
          <div><span>ICMP</span><span :style="{ color: ping.reachable ? 'rgb(var(--v-theme-success))' : 'rgb(var(--v-theme-error))' }">{{ ping.reachable ? `reachable · ${ping.rtt_ms} ms` : 'no reply' }}</span></div>
        </div>
        <div
          v-if="verify"
          class="m-kv mt-3"
        >
          <div><span>Tunnels</span><span>{{ tunnelsUp }} up / {{ (verify.tunnels ?? []).length }}</span></div>
          <div><span>Next hop</span><span>{{ verify.next_hop_reachable === false ? 'UNREACHABLE' : 'reachable' }}</span></div>
          <div><span>Open alarms on box</span><span>{{ (verify.alarms ?? []).length }}</span></div>
        </div>
      </section>
    </template>
  </div>
</template>
