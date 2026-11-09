<script lang="ts" setup>
import { useRoute } from 'vue-router'
import { api } from '@/composables/useApi'
import { ago, mobileTitle } from '@/composables/useMobile'
import { useNotify } from '@/composables/useNotify'
import { useAuthStore } from '@/stores/auth'
import MSpark from '@/components/mobile/MSpark.vue'

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
interface PortAlert { id: number, severity: string, ticket_number?: string | null, started_at: string, acknowledged_at: string | null }
interface Port {
  id: number
  if_index: number
  if_name: string
  status: string
  admin_status: string | null
  alarm_suppressed: boolean
  speed_bps: number | null
  in_errors_delta: number | null
  out_errors_delta: number | null
  in_discards_delta: number | null
  out_discards_delta: number | null
  health?: string
  health_attention?: boolean
  peak_util_pct?: number
  mac_addresses_count?: number
  alerts?: PortAlert[]
}

const route = useRoute()
const auth = useAuthStore()
const notify = useNotify()
const id = computed(() => Number((route.params as { id: string }).id))

const device = ref<Device | null>(null)
const alerts = ref<Alert[]>([])
const error = ref('')
const ping = ref<{ reachable: boolean, rtt_ms: number | null, at: string } | null>(null)
const verify = ref<{ tunnels?: any[], alarms?: any[], next_hop_reachable?: boolean } | null>(null)
const busy = ref<'ping' | 'verify' | 'tdr' | 'port' | null>(null)
const latency = ref<(number | null)[]>([])
const cpu = ref<(number | null)[]>([])
const mem = ref<(number | null)[]>([])
const ports = ref<Port[]>([])
const cves = ref<{ total: number, worst: string | null } | null>(null)
const showAllPorts = ref(false)
const port = ref<Port | null>(null)
const tdr = ref<{ exit_code: number, output: string } | null>(null)

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

    return
  }
  await Promise.all([loadGraphs(), loadPorts(), loadCves()])
}

async function loadGraphs() {
  const [m, h] = await Promise.all([
    api<{ response_time_ms: number | null }[]>(`/api/devices/metrics?device_id=${id.value}&range=1h`).catch(() => []),
    api<{ cpu_pct: number | null, mem_pct: number | null }[]>(`/api/devices/${id.value}/health-history?range=1h`).catch(() => []),
  ])
  latency.value = m.map(x => x.response_time_ms)
  cpu.value = h.map(x => x.cpu_pct)
  mem.value = h.map(x => x.mem_pct)
}

async function loadPorts() {
  ports.value = await api<Port[]>(`/api/interfaces?device_id=${id.value}`).catch(() => [])
}

async function loadCves() {
  const rows = await api<{ data?: any[] } | any[]>(`/api/devices/${id.value}/vulnerabilities`).catch(() => null)
  const list: { severity?: string }[] = Array.isArray(rows) ? rows : (rows?.data ?? [])
  const order = ['critical', 'high', 'medium', 'low']
  const worst = order.find(sv => list.some(r => r.severity === sv)) ?? null
  cves.value = rows === null ? null : { total: list.length, worst }
}

// Ports that need eyes: down-but-enabled, or the health classifier flagged them.
const hotPorts = computed(() => ports.value.filter(p => (p.status === 'down' && p.admin_status !== 'down' && !p.alarm_suppressed) || p.health_attention))
const shownPorts = computed(() => showAllPorts.value ? ports.value : hotPorts.value)

function portSev(p: Port): string {
  if (p.status === 'down' && p.admin_status !== 'down' && !p.alarm_suppressed)
    return 'sev-critical'
  if (p.health_attention)
    return 'sev-warning'

  return p.status === 'up' ? 'sev-ok' : ''
}

function portState(p: Port): string {
  if (p.admin_status === 'down')
    return 'disabled'
  if (p.alarm_suppressed && p.status === 'down')
    return 'down · muted'

  return p.status
}

function speed(bps: number | null): string {
  if (!bps)
    return ''

  return bps >= 1e9 ? `${bps / 1e9}G` : bps >= 1e6 ? `${bps / 1e6}M` : `${bps}`
}

const canTdr = computed(() => auth.canAct && device.value?.vendor === 'juniper' && !!port.value && /^(ge|xe|et|fe|mge|xle)-\d+\/\d+\/\d+$/.test(port.value.if_name))

function openPort(p: Port) {
  port.value = p
  tdr.value = null
}

async function runTdr() {
  if (!port.value)
    return
  busy.value = 'tdr'
  try {
    tdr.value = await api(`/api/devices/${id.value}/tools/tdr`, { method: 'POST', body: { interface: port.value.if_name }, timeout: 118000 })
  }
  catch (e: any) {
    tdr.value = { exit_code: -1, output: e?.data?.message ?? 'The cable test failed to run.' }
  }
  finally {
    busy.value = null
  }
}

async function portAction(kind: 'ack-health' | 'acknowledge' | 'clear') {
  if (!port.value)
    return
  busy.value = 'port'
  try {
    if (kind === 'ack-health') {
      await api(`/api/interfaces/${port.value.id}/ack-health`, { method: 'POST' })
    }
    else {
      const alert = port.value.alerts?.[0]
      if (!alert)
        return
      await api(`/api/interface-alerts/${alert.id}/${kind}`, { method: 'POST' })
    }
    notify.success({ 'ack-health': 'Health acknowledged', 'acknowledge': 'Port alarm acknowledged', 'clear': 'Port alarm cleared' }[kind])
    await loadPorts()
    port.value = ports.value.find(p => p.id === port.value?.id) ?? null
  }
  catch (e: any) {
    notify.error('Could not save', e?.data?.message)
  }
  finally {
    busy.value = null
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

      <section class="m-section">
        <h2>Last hour</h2>
        <MSpark
          :points="latency"
          label="Ping"
          unit=" ms"
          color="rgb(var(--v-theme-info))"
        />
        <div
          v-if="cpu.length"
          class="m-two mt-2"
        >
          <MSpark
            :points="cpu"
            label="CPU"
            unit="%"
            :max="100"
            :height="44"
            color="rgb(var(--v-theme-warning))"
          />
          <MSpark
            :points="mem"
            label="Memory"
            unit="%"
            :max="100"
            :height="44"
            color="rgb(var(--v-theme-primary))"
          />
        </div>
      </section>

      <section
        v-if="ports.length"
        class="m-section"
      >
        <h2>Ports <a class="m-more" @click.prevent="showAllPorts = !showAllPorts">{{ showAllPorts ? `attention only (${hotPorts.length})` : `all ${ports.length} →` }}</a></h2>
        <div
          v-if="shownPorts.length"
          class="m-list"
        >
          <a
            v-for="p in shownPorts"
            :key="p.id"
            class="m-row"
            :class="portSev(p)"
            @click.prevent="openPort(p)"
          >
            <span class="bar" />
            <span class="main">
              <span class="t">{{ p.if_name }}</span>
              <span class="s">{{ portState(p) }}<template v-if="p.health && p.health !== 'clean' && p.health !== 'down'"> · {{ p.health }}</template><template v-if="p.speed_bps"> · {{ speed(p.speed_bps) }}</template><template v-if="p.peak_util_pct"> · peak {{ p.peak_util_pct }}%</template></span>
            </span>
            <span class="meta">{{ p.mac_addresses_count ? `${p.mac_addresses_count} MAC` : '' }}</span>
          </a>
        </div>
        <div
          v-else
          class="m-empty"
        >
          Every enabled port is up and clean.
        </div>
      </section>

      <section
        v-if="cves"
        class="m-section"
      >
        <h2>Exposure</h2>
        <div class="m-kv">
          <div><span>Known CVEs on this firmware</span><span :style="{ color: cves.worst === 'critical' || cves.worst === 'high' ? 'rgb(var(--v-theme-error))' : undefined }">{{ cves.total }}<template v-if="cves.worst"> · worst {{ cves.worst }}</template></span></div>
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

      <VBottomSheet :model-value="!!port" @update:model-value="v => { if (!v) port = null }">
        <VCard
          v-if="port"
          class="m-sheet"
          flat
        >
          <h3>{{ port.if_name }}</h3>
          <div class="sub">
            {{ portState(port) }}<template v-if="port.speed_bps"> · {{ speed(port.speed_bps) }}</template><template v-if="port.health && port.health !== 'clean' && port.health !== port.status"> · {{ port.health }}</template>
          </div>
          <div class="m-kv">
            <div><span>Errors in / out</span><span>{{ port.in_errors_delta ?? 0 }} / {{ port.out_errors_delta ?? 0 }}</span></div>
            <div><span>Discards in / out</span><span>{{ port.in_discards_delta ?? 0 }} / {{ port.out_discards_delta ?? 0 }}</span></div>
            <div v-if="port.peak_util_pct != null"><span>Peak util 24h</span><span>{{ port.peak_util_pct }}%</span></div>
            <div v-if="port.alerts?.[0]"><span>Port alarm</span><span>#{{ port.alerts[0].ticket_number || port.alerts[0].id }} · {{ ago(port.alerts[0].started_at) }}<template v-if="port.alerts[0].acknowledged_at"> · ACK</template></span></div>
          </div>

          <div
            v-if="auth.canAct"
            class="m-actions"
          >
            <VBtn
              v-if="canTdr"
              variant="tonal"
              :loading="busy === 'tdr'"
              :disabled="!!busy"
              prepend-icon="ri-plug-line"
              @click="runTdr"
            >
              Cable test
            </VBtn>
            <VBtn
              v-if="port.health_attention"
              variant="tonal"
              :loading="busy === 'port'"
              :disabled="!!busy"
              prepend-icon="ri-check-line"
              @click="portAction('ack-health')"
            >
              Ack health
            </VBtn>
            <VBtn
              v-if="port.alerts?.[0] && !port.alerts[0].acknowledged_at"
              variant="tonal"
              :loading="busy === 'port'"
              :disabled="!!busy"
              prepend-icon="ri-check-double-line"
              @click="portAction('acknowledge')"
            >
              Ack alarm
            </VBtn>
            <VBtn
              v-if="port.alerts?.[0]"
              color="error"
              variant="tonal"
              :loading="busy === 'port'"
              :disabled="!!busy"
              prepend-icon="ri-close-circle-line"
              @click="portAction('clear')"
            >
              Clear alarm
            </VBtn>
          </div>
          <div
            v-if="busy === 'tdr'"
            class="m-empty"
          >
            Testing the copper pairs… up to 2 minutes.
          </div>
          <pre
            v-if="tdr"
            class="m-pre"
            :class="tdr.exit_code === 0 ? '' : 'is-bad'"
          >{{ tdr.output }}</pre>
        </VCard>
      </VBottomSheet>
    </template>
  </div>
</template>
