<script setup lang="ts">
import { api } from '@/composables/useApi'

definePage({ meta: { layout: 'default' } })

interface MacRow {
  id: number
  mac: string
  ip: string | null
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

const route = useRoute()
const q = ref(typeof route.query.q === 'string' ? route.query.q : '')
const rows = ref<MacRow[]>([])
const capped = ref(false)
const loading = ref(false)
let debounce: ReturnType<typeof setTimeout> | null = null

// Location filter — endpoints share 192.168.255.x across every site, so scoping by site
// is the only reliable way to trace an IP to the right place. Defaults to All (null).
const siteFilter = ref<number | null>(null)
const siteOptions = ref<{ title: string, value: number }[]>([])
async function loadSites() {
  const res = await api<{ data?: { id: number, name: string }[] } | { id: number, name: string }[]>('/api/sites')
  const list = Array.isArray(res) ? res : (res.data ?? [])
  siteOptions.value = list.map(s => ({ title: s.name, value: s.id })).sort((a, b) => a.title.localeCompare(b.title))
}

async function search() {
  loading.value = true
  try {
    const params = new URLSearchParams({ q: q.value })
    if (siteFilter.value)
      params.set('site_id', String(siteFilter.value))
    const res = await api<{ capped: boolean, data: MacRow[] }>(`/api/mac-addresses?${params}`)
    rows.value = res.data
    capped.value = res.capped
  }
  finally { loading.value = false }
}
watch([q, siteFilter], () => {
  if (debounce) clearTimeout(debounce)
  debounce = setTimeout(search, 300)
})
onMounted(() => { loadSites(); search() })

// Endpoint type inferred from the OUI vendor — icon + a short kind label.
function deviceKind(vendor: string | null): { icon: string, label: string, color: string } {
  const v = (vendor ?? '').toLowerCase()
  if (/mitel|polycom|yealink|avaya|grandstream|snom/.test(v)) return { icon: 'ri-phone-line', label: 'VoIP handset', color: 'info' }
  if (/verkada|axis|hikvision|hanwha|avigilon|bosch/.test(v)) return { icon: 'ri-camera-line', label: 'camera', color: 'success' }
  if (/meraki|aruba|ubiquiti|ruckus|mist|aerohive|cambium/.test(v)) return { icon: 'ri-router-line', label: 'access point', color: 'success' }
  if (/^hp|hewlett|brother|canon|epson|lexmark|xerox|zebra|ricoh|kyocera/.test(v)) return { icon: 'ri-printer-line', label: 'printer', color: 'warning' }
  if (/apple|dell|lenovo|microsoft|intel|asus|acer/.test(v)) return { icon: 'ri-computer-line', label: 'workstation', color: 'secondary' }
  return { icon: 'ri-device-line', label: 'endpoint', color: 'secondary' }
}

function since(iso: string): string {
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000)
  if (s < 60) return `${Math.floor(s)}s`
  if (s < 3600) return `${Math.floor(s / 60)}m`
  if (s < 86400) return `${Math.floor(s / 3600)}h`
  return `${Math.floor(s / 86400)}d`
}
const resolved = computed(() => rows.value.filter(r => r.ip).length)
</script>

<template>
  <div>
    <div class="d-flex align-center flex-wrap ga-3 mb-1">
      <VIcon icon="ri-fingerprint-line" size="24" class="text-primary" />
      <h4 class="text-h4 mb-0">MAC Search</h4>
      <VSpacer />
      <div class="text-end">
        <span class="mono text-h6">{{ rows.length }}</span>
        <span class="text-caption text-medium-emphasis ms-1">shown</span>
      </div>
    </div>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Trace any endpoint by MAC or IP — the site, switch, port and vendor in one place.
    </p>

    <VCard>
      <VCardText class="d-flex flex-wrap ga-3 align-center">
        <VTextField
          v-model="q"
          placeholder="MAC, IP, vendor, site, or device — e.g. 0c:ee:99, 10.201.67, Mitel"
          prepend-inner-icon="ri-search-line"
          variant="outlined"
          density="comfortable"
          hide-details
          clearable
          style="flex: 1 1 340px;"
          class="mono-input"
        />
        <VAutocomplete
          v-model="siteFilter"
          :items="siteOptions"
          placeholder="All sites"
          prepend-inner-icon="ri-map-pin-line"
          variant="outlined"
          density="comfortable"
          hide-details
          clearable
          auto-select-first
          :menu-props="{ maxHeight: 360 }"
          style="flex: 0 1 280px;"
        />
      </VCardText>

      <VDivider />

      <!-- column header -->
      <div class="ms-head">
        <span />
        <span>Endpoint</span>
        <span>Vendor</span>
        <span>Site</span>
        <span>Switch · Port</span>
        <span>VLAN</span>
        <span class="text-end">Seen</span>
      </div>

      <VProgressLinear v-if="loading" indeterminate color="primary" />

      <div class="ms-body">
        <div
          v-for="r in rows"
          :key="r.id"
          class="ms-row"
        >
          <span class="ms-ic" :class="`k-${deviceKind(r.oui_vendor).color}`">
            <VIcon :icon="deviceKind(r.oui_vendor).icon" size="17" />
          </span>
          <div class="min-w-0">
            <div class="mono font-weight-medium ms-mac">
              <CopyBtn :text="r.mac" />
            </div>
            <div class="mono ms-ip" :class="{ 'text-warning': r.ip && r.ip.startsWith('169.254'), 'text-success': r.ip && !r.ip.startsWith('169.254'), 'text-disabled': !r.ip }">
              <CopyBtn v-if="r.ip" :text="r.ip" />
              <span v-else>— not in ARP</span>
              <span v-if="r.ip && r.ip.startsWith('169.254')" class="text-caption ms-1">· APIPA</span>
            </div>
          </div>
          <div class="min-w-0">
            <div class="text-truncate">{{ r.oui_vendor ?? '—' }}</div>
            <div class="text-caption text-medium-emphasis">{{ deviceKind(r.oui_vendor).label }}</div>
          </div>
          <div class="min-w-0">
            <RouterLink v-if="r.site_name" :to="`/sites?q=${encodeURIComponent(r.site_name)}`" class="ms-site text-truncate d-inline-block">{{ r.site_name }}</RouterLink>
            <span v-else class="text-medium-emphasis">—</span>
          </div>
          <div class="mono min-w-0">
            <RouterLink v-if="r.device_id" :to="`/devices/${r.device_id}`" class="text-truncate d-block">{{ r.device_name ?? '—' }}</RouterLink>
            <span class="text-caption text-medium-emphasis d-flex align-center ga-1">
              {{ r.interface_name ?? '—' }}
              <VChip v-if="r.interface_status === 'down'" size="x-small" color="error" variant="tonal">down</VChip>
            </span>
          </div>
          <div>
            <span v-if="r.vlan" class="mono ms-vlan">{{ r.vlan }}</span>
            <span v-else class="text-medium-emphasis">—</span>
          </div>
          <div class="text-end text-caption text-medium-emphasis" :title="r.last_seen_at">{{ since(r.last_seen_at) }}</div>
        </div>

        <div v-if="!loading && !rows.length" class="pa-8 text-center text-medium-emphasis">
          {{ q || siteFilter ? 'No endpoints match.' : 'Learned MACs appear here as the fleet is polled.' }}
        </div>
      </div>

      <VDivider />
      <div class="d-flex align-center justify-space-between px-4 py-2 text-caption text-medium-emphasis">
        <span>{{ resolved }} of {{ rows.length }} resolved to an IP · silent endpoints fill in as they talk</span>
        <span v-if="capped">Top 1000 — narrow by site, vendor or MAC</span>
      </div>
    </VCard>
  </div>
</template>

<style scoped>
.mono, .mono-input :deep(input) { font-family: 'IBM Plex Mono', ui-monospace, "SF Mono", Menlo, monospace; }
.min-w-0 { min-width: 0; }

.ms-head, .ms-row {
  display: grid;
  grid-template-columns: 40px minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, 0.9fr) minmax(0, 1.1fr) 74px 60px;
  gap: 16px;
  align-items: center;
  padding-inline: 18px;
}
.ms-head {
  padding-block: 10px;
  background: rgba(var(--v-theme-on-surface), 0.02);
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  font-size: 10.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.5);
}
.ms-body { min-height: 120px; }
.ms-row {
  padding-block: 12px;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06);
}
.ms-row:hover { background: rgba(var(--v-theme-on-surface), 0.025); }
.ms-ic {
  width: 34px; height: 34px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
}
.k-info { background: rgba(var(--v-theme-info), 0.12); color: rgb(var(--v-theme-info)); }
.k-success { background: rgba(var(--v-theme-success), 0.12); color: rgb(var(--v-theme-success)); }
.k-warning { background: rgba(var(--v-theme-warning), 0.12); color: rgb(var(--v-theme-warning)); }
.k-secondary { background: rgba(var(--v-theme-on-surface), 0.06); color: rgba(var(--v-theme-on-surface), 0.6); }
.ms-mac { font-size: 13.5px; }
.ms-ip { font-size: 12px; margin-top: 2px; }
.ms-site {
  font-size: 11px; font-weight: 600;
  color: rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.10);
  padding: 3px 9px; border-radius: 14px; text-decoration: none; max-width: 100%;
}
.ms-vlan {
  font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.7);
  background: rgba(var(--v-theme-on-surface), 0.06);
  padding: 2px 8px; border-radius: 6px;
}
</style>
