<script lang="ts" setup>
import { useRoute } from 'vue-router'
import { api } from '@/composables/useApi'
import { ago, mobileTitle } from '@/composables/useMobile'

definePage({ meta: { layout: 'mobile', title: 'Site' } })

interface Site { id: number, name: string, site_number?: string | null, address?: string | null, region?: string | null, main_phone?: string | null, gm_name?: string | null, gm_phone?: string | null }
interface Dev { id: number, name: string, ip_address: string, role: string, is_down?: boolean, in_maintenance?: boolean, status: string }
interface Circ { id: number, circuit_id: string | null, isp_name: string | null, status: string | null, circuit_type?: string | null, isp_ticket?: string | null, dispatch_at?: string | null }
interface Alert { kind: string, id: number, severity: string, label: string | null, device_name: string | null, started_at: string | null, acknowledged_at: string | null }

const route = useRoute()
const id = computed(() => Number((route.params as { id: string }).id))

const site = ref<Site | null>(null)
const devices = ref<Dev[]>([])
const circuits = ref<Circ[]>([])
const alerts = ref<Alert[]>([])
const error = ref('')

async function load() {
  try {
    const [s, d, c, a] = await Promise.all([
      api<Site>(`/api/sites/${id.value}`),
      api<{ data: Dev[] }>(`/api/devices?site_id=${id.value}`),
      api<Circ[]>(`/api/circuits?site_id=${id.value}`),
      api<{ alerts: Alert[] }>(`/api/alerts?site_id=${id.value}`),
    ])
    site.value = s
    devices.value = d.data
    circuits.value = Array.isArray(c) ? c : (c as any).data ?? []
    alerts.value = a.alerts
    mobileTitle.value = s.name
  }
  catch (e: any) {
    error.value = e?.status === 404 ? 'This site no longer exists.' : 'Could not load this site.'
  }
}

onMounted(load)
watch(id, load)

function circuitSev(c: Circ): string {
  const s = (c.status ?? '').toLowerCase()
  if (s === 'down')
    return 'sev-critical'
  if (s === 'degraded')
    return 'sev-warning'

  return s === 'up' ? 'sev-ok' : ''
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

    <template v-else-if="site">
      <section class="m-section">
        <h2>{{ site.site_number ? `#${site.site_number} · ` : '' }}{{ site.region || 'Site' }}</h2>
        <div class="m-kv">
          <div v-if="site.address"><span>Address</span><span>{{ site.address }}</span></div>
          <div v-if="site.main_phone"><span>Main phone</span><span><a :href="`tel:${site.main_phone}`">{{ site.main_phone }}</a></span></div>
          <div v-if="site.gm_phone"><span>{{ site.gm_name || 'GM' }}</span><span><a :href="`tel:${site.gm_phone}`">{{ site.gm_phone }}</a></span></div>
        </div>
      </section>

      <section class="m-section">
        <h2>Active alarms · {{ alerts.length }}</h2>
        <div
          v-if="alerts.length"
          class="m-list"
        >
          <div
            v-for="a in alerts"
            :key="`${a.kind}-${a.id}`"
            class="m-row"
            :class="[`sev-${a.severity}`, { 'is-acked': !!a.acknowledged_at }]"
          >
            <span class="bar" />
            <span class="main"><span class="t">{{ a.device_name || a.kind }}</span><span class="s">{{ a.label || a.kind }}</span></span>
            <span class="meta">{{ ago(a.started_at) }}</span>
          </div>
        </div>
        <div
          v-else
          class="m-empty"
        >
          Nothing active here.
        </div>
      </section>

      <section class="m-section">
        <h2>Circuits · {{ circuits.length }}</h2>
        <div
          v-if="circuits.length"
          class="m-list"
        >
          <RouterLink
            v-for="c in circuits"
            :key="c.id"
            :to="`/m/circuit/${c.id}`"
            class="m-row"
            :class="circuitSev(c)"
          >
            <span class="bar" />
            <span class="main"><span class="t">{{ c.isp_name || 'ISP' }}</span><span class="s">{{ c.circuit_id || '—' }}<template v-if="c.circuit_type"> · {{ c.circuit_type }}</template></span></span>
            <span class="meta">{{ (c.status || '?').toUpperCase() }}<template v-if="c.isp_ticket"><br>#{{ c.isp_ticket }}</template></span>
          </RouterLink>
        </div>
      </section>

      <section class="m-section">
        <h2>Devices · {{ devices.length }}</h2>
        <div
          v-if="devices.length"
          class="m-list"
        >
          <RouterLink
            v-for="d in devices"
            :key="d.id"
            :to="`/m/device/${d.id}`"
            class="m-row"
            :class="d.is_down ? 'sev-critical' : (d.status === 'active' ? 'sev-ok' : '')"
          >
            <span class="bar" />
            <span class="main"><span class="t">{{ d.name }}</span><span class="s">{{ d.ip_address }} · {{ d.role }}</span></span>
            <span class="meta">{{ d.is_down ? 'DOWN' : d.in_maintenance ? 'MAINT' : (d.status === 'active' ? 'UP' : d.status.toUpperCase()) }}</span>
          </RouterLink>
        </div>
      </section>
    </template>
  </div>
</template>
