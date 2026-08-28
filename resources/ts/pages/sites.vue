<script setup lang="ts">
import { api } from '@/composables/useApi'
import { apiErrorMessage, apiFieldErrors } from '@/composables/useApiError'
import { useAlertsStore } from '@/stores/alerts'
import { useAuthStore } from '@/stores/auth'
import type { Site, SiteOverview } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const alertsStore = useAlertsStore()
const search = ref('')

const sites = ref<Site[]>([])
const isLoading = ref(true)
const isDialogOpen = ref(false)
const isSaving = ref(false)
const editingSite = ref<Site | null>(null)
const form = ref({ name: '', site_type: 'branch', hub_site_ids: [] as number[], address: '', occupancy: 'leased', lease_end_date: '', lease_notes: '', latitude: '' as string | number, longitude: '' as string | number, notes: '' })

const formSections = [
  { id: 'sec-identity', n: '1', label: 'Identity' },
  { id: 'sec-location', n: '2', label: 'Location' },
  { id: 'sec-lease', n: '3', label: 'Lease' },
  { id: 'sec-notes', n: '4', label: 'Notes' },
]
const activeSection = ref('sec-identity')
function scrollToSection(id: string) {
  activeSection.value = id
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

// Hub sites to home a branch to (a hub can't home to itself).
const hubOptions = computed(() =>
  sites.value.filter(s => s.site_type === 'hub' && s.id !== editingSite.value?.id).map(s => ({ title: s.name, value: s.id })))
const errorMessage = ref('')
/** Per-field server validation messages, bound to each input's error-messages. */
const fieldErrors = ref<Record<string, string>>({})

// Expanded rows + a lazy cache of each site's overview (fetched on first expand).
const expanded = ref<number[]>([])
const overviews = ref<Record<number, SiteOverview | 'loading'>>({})

const headers = [
  { title: 'Site', key: 'name', minWidth: 180 },
  { title: 'Address', key: 'address', minWidth: 240 },
  { title: 'Devices', key: 'devices_count', align: 'end' as const, width: 100 },
  { title: 'Circuits', key: 'circuits_count', align: 'end' as const, width: 100 },
  { title: '', key: 'actions', sortable: false, align: 'end' as const, width: 104 },
]

async function loadSites() {
  isLoading.value = true
  sites.value = await api<Site[]>('/api/sites')
  isLoading.value = false
}

async function loadOverview(siteId: number) {
  if (overviews.value[siteId] && overviews.value[siteId] !== 'loading')
    return
  overviews.value[siteId] = 'loading'
  try {
    overviews.value[siteId] = await api<SiteOverview>(`/api/sites/${siteId}/overview`)
  }
  catch {
    delete overviews.value[siteId]
  }
}

// Fetch overview when a row is newly expanded.
watch(expanded, (ids) => {
  ids.forEach(id => loadOverview(id))
})

function overviewFor(siteId: number): SiteOverview | null {
  const o = overviews.value[siteId]

  return o && o !== 'loading' ? o : null
}

// Jump straight to the device carrying the alarms — its page shows the alarm
// banner + full Alarm History.
function goToAlarmingDevice(siteId: number) {
  const o = overviewFor(siteId)
  if (!o)
    return
  const target = o.devices.find(d => d.active_alarms > 0) ?? o.devices[0]
  if (target)
    router.push(`/devices/${target.id}`)
}

// ---- formatting ----
function uptime(seconds: number | null): string {
  if (!seconds)
    return '—'
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)

  return d > 0 ? `${d}d ${h}h` : `${h}h`
}

function healthColor(pct: number | null, warn = 75, crit = 90): string {
  if (pct == null)
    return 'text-disabled'
  if (pct >= crit)
    return 'text-error'
  if (pct >= warn)
    return 'text-warning'

  return ''
}

const roleIcon: Record<string, string> = {
  switch: 'ri-router-line', firewall: 'ri-shield-check-line',
  edgeconnect: 'ri-global-line', router: 'ri-router-line',
}

function openCreateDialog() {
  editingSite.value = null
  form.value = { name: '', site_type: 'branch', hub_site_ids: [], address: '', occupancy: 'leased', lease_end_date: '', lease_notes: '', latitude: '', longitude: '', notes: '' }
  errorMessage.value = ''
  fieldErrors.value = {}
  isDialogOpen.value = true
}

function openEditDialog(site: Site) {
  editingSite.value = site
  form.value = {
    name: site.name,
    site_type: site.site_type ?? 'branch',
    hub_site_ids: site.hub_site_ids ?? [],
    address: site.address ?? '',
    occupancy: site.occupancy ?? 'leased',
    // Date-only prefix — a full ISO timestamp renders blank in <input type="date">.
    lease_end_date: site.lease_end_date ? String(site.lease_end_date).slice(0, 10) : '',
    lease_notes: site.lease_notes ?? '',
    latitude: site.latitude ?? '',
    longitude: site.longitude ?? '',
    notes: site.notes ?? '',
  }
  errorMessage.value = ''
  fieldErrors.value = {}
  isDialogOpen.value = true
}

// --- Address → coordinates (US Census geocoder) ---
const isGeocoding = ref(false)
const geocodeMsg = ref('')
const geocodeError = ref(false)

async function geocodeAddress() {
  geocodeMsg.value = ''
  geocodeError.value = false
  if (!form.value.address) {
    geocodeError.value = true
    geocodeMsg.value = 'Enter an address first.'
    return
  }
  isGeocoding.value = true
  try {
    const res = await api<{ latitude: number, longitude: number }>('/api/sites/geocode', {
      method: 'POST',
      body: { address: form.value.address },
    })
    form.value.latitude = res.latitude
    form.value.longitude = res.longitude
    geocodeMsg.value = `Found: ${res.latitude.toFixed(4)}, ${res.longitude.toFixed(4)}`
  }
  catch (e: any) {
    geocodeError.value = true
    geocodeMsg.value = e?.data?.error ?? 'Could not find that address.'
  }
  finally {
    isGeocoding.value = false
  }
}

function buildSitePayload() {
  return {
    name: form.value.name,
    site_type: form.value.site_type,
    // A hub never homes to another hub.
    hub_site_ids: form.value.site_type === 'hub' ? [] : form.value.hub_site_ids,
    address: form.value.address,
    occupancy: form.value.occupancy,
    // A Massey-owned site still has a lease end (ground lease / sub-lease), so
    // ownership never clears these — only an empty field does.
    lease_end_date: form.value.lease_end_date === '' ? null : form.value.lease_end_date,
    lease_notes: form.value.lease_notes || null,
    latitude: form.value.latitude === '' ? null : Number(form.value.latitude),
    longitude: form.value.longitude === '' ? null : Number(form.value.longitude),
    notes: form.value.notes,
  }
}

async function saveSite() {
  isSaving.value = true
  errorMessage.value = ''
  fieldErrors.value = {}

  try {
    const payload = buildSitePayload()

    if (editingSite.value) {
      await api(`/api/sites/${editingSite.value.id}`, { method: 'PUT', body: payload })
    }
    else {
      await api('/api/sites', { method: 'POST', body: payload })
    }

    isDialogOpen.value = false
    await loadSites()
  }
  catch (e) {
    // Say WHICH field and WHY — a bare "could not save" sends the operator hunting
    // through a form that is usually fine.
    fieldErrors.value = apiFieldErrors(e)
    errorMessage.value = apiErrorMessage(e, 'Could not save the site.')

    // Jump to the section holding the first rejected field so it is on screen.
    const first = Object.keys(fieldErrors.value)[0]
    if (first)
      scrollToSection(sectionForField(first))
  }
  finally {
    isSaving.value = false
  }
}

/** Which editor section a rejected field lives in, so we can scroll to it. */
function sectionForField(field: string): string {
  if (['address', 'latitude', 'longitude'].includes(field))
    return 'sec-location'
  if (['occupancy', 'lease_end_date', 'lease_notes'].includes(field))
    return 'sec-lease'
  if (field === 'notes')
    return 'sec-notes'

  return 'sec-identity'
}

async function deleteSite(site: Site) {
  if (!confirm(`Delete site "${site.name}"?`))
    return

  await api(`/api/sites/${site.id}`, { method: 'DELETE' })
  await loadSites()
}

// Sites impacted by any active alert, from the shared feed — drives the fleet
// banner and the per-row highlight.
const siteAlertMap = computed(() => alertsStore.siteAlertMap)
const impactedSites = computed(() => alertsStore.impactedSites)

// Type tabs (All / Hubs / Spokes) + health pill filter (with-alerts / healthy).
const typeFilter = ref<string | null>(null)
const healthFilter = ref<'alerts' | 'healthy' | null>(null)
const typeTabs = computed(() => {
  let hub = 0
  let spoke = 0
  for (const s of sites.value) (s.site_type === 'hub' ? hub++ : spoke++)
  return [
    { value: null, label: 'All', count: sites.value.length, color: '#7C8AA0' },
    { value: 'hub', label: 'Hubs', count: hub, color: '#8B7CF6' },
    { value: 'branch', label: 'Spokes', count: spoke, color: '#4C8DFF' },
  ]
})
const typeScoped = computed(() =>
  typeFilter.value ? sites.value.filter(s => (s.site_type ?? 'branch') === typeFilter.value) : sites.value)
const healthCounts = computed(() => {
  let alerts = 0
  for (const s of typeScoped.value) if ((siteAlertMap.value[s.id] ?? 0) > 0) alerts++
  return { alerts, healthy: typeScoped.value.length - alerts }
})

// Search matches name, address AND site number (operators look sites up by number).
function siteMatchesSearch(s: Site): boolean {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return true
  return [s.name, s.address, s.site_number].some(v => (v ?? '').toString().toLowerCase().includes(q))
}
const displayedSites = computed(() => {
  let list = typeScoped.value
  if (healthFilter.value === 'alerts')
    list = list.filter(s => (siteAlertMap.value[s.id] ?? 0) > 0)
  else if (healthFilter.value === 'healthy')
    list = list.filter(s => (siteAlertMap.value[s.id] ?? 0) === 0)
  if (search.value.trim())
    list = list.filter(siteMatchesSearch)
  // Always strict alphabetical by name — impacted sites already stand out via the
  // red row highlight (rowProps) and the "N sites impacted" banner, so we do NOT
  // float them out of name order.
  return [...list].sort((a, b) => a.name.localeCompare(b.name))
})

// The names behind the banner count, so "3 sites impacted" actually says which.
const impactedSiteNames = computed(() =>
  sites.value.filter(s => (siteAlertMap.value[s.id] ?? 0) > 0)
    .sort((a, b) => a.name.localeCompare(b.name))
    .map(s => s.name))

function rowProps(data: { item: Site }) {
  return { class: (siteAlertMap.value[data.item.id] ?? 0) > 0 ? 'site-row-alert' : '' }
}

onMounted(async () => {
  await loadSites()
  alertsStore.refresh()
  // Deep-link from global search: pre-filter and auto-expand the matched site.
  const q = route.query.q
  if (typeof q === 'string' && q) {
    search.value = q
    const match = sites.value.find(s => s.name.toLowerCase().includes(q.toLowerCase()))
    if (match)
      expanded.value = [match.id]
  }
})
</script>

<template>
  <div>
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">Sites</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Service centers and hubs — device &amp; circuit counts, with an alert flag when something's open.
        </p>
      </div>
      <VBtn v-if="auth.isAdmin" @click="openCreateDialog">
        Add Site
      </VBtn>
    </div>

    <ListTabs v-model="typeFilter" :tabs="typeTabs" class="mt-4" />

    <VCard class="list-surface">
    <VAlert
      v-if="impactedSites > 0"
      type="error"
      variant="tonal"
      density="compact"
      class="mx-4 mt-2"
      icon="ri-alarm-warning-line"
    >
      <strong>{{ impactedSites }}</strong> site{{ impactedSites > 1 ? 's' : '' }} impacted by active alerts — sorted to the top:
      <span class="font-weight-medium">{{ impactedSiteNames.join(', ') }}</span>
    </VAlert>

    <VCardText class="pb-0 d-flex align-center flex-wrap ga-3">
      <VTextField
        v-model="search"
        placeholder="Search site name, number or address…"
        prepend-inner-icon="ri-search-line"
        density="compact"
        hide-details
        clearable
        style="max-width: 360px;"
      />
      <VSpacer />
      <div class="list-pills">
        <button
          type="button"
          class="list-pill"
          :class="{ 'list-pill--on': healthFilter === null }"
          @click="healthFilter = null"
        >
          All · {{ typeScoped.length }}
        </button>
        <button
          type="button"
          class="list-pill"
          :class="{ 'list-pill--on': healthFilter === 'alerts' }"
          @click="healthFilter = healthFilter === 'alerts' ? null : 'alerts'"
        >
          <span class="list-pill__d" style="background: rgb(var(--v-theme-warning));" />
          With alerts · {{ healthCounts.alerts }}
        </button>
        <button
          type="button"
          class="list-pill"
          :class="{ 'list-pill--on': healthFilter === 'healthy' }"
          @click="healthFilter = healthFilter === 'healthy' ? null : 'healthy'"
        >
          <span class="list-pill__d" style="background: rgb(var(--v-theme-success));" />
          Healthy · {{ healthCounts.healthy }}
        </button>
      </div>
    </VCardText>

    <div class="site-grid pa-4 pt-2">
      <div
        v-if="isLoading"
        class="site-grid__state d-flex justify-center pa-8"
      >
        <VProgressCircular indeterminate size="28" />
      </div>
      <div
        v-else-if="displayedSites.length === 0"
        class="site-grid__state text-center text-medium-emphasis pa-8"
      >
        No sites match the current filters.
      </div>
      <template v-else>
        <!-- Health-first site card: status dot, name + number, KPI cluster;
             the chevron lazy-loads the per-site NOC overview (unchanged data path). -->
        <div
          v-for="item in displayedSites"
          :key="item.id"
          class="site-card"
          :class="{
            'site-card--alert': (siteAlertMap[item.id] ?? 0) > 0,
            'site-card--open': expanded.includes(item.id),
          }"
        >
          <div class="site-card__head">
            <span
              class="site-card__dot"
              :class="(siteAlertMap[item.id] ?? 0) > 0 ? 'is-alert' : 'is-ok'"
            />
            <span class="site-card__name">{{ item.name }}</span>
            <span
              v-if="item.site_number"
              class="site-card__code"
            >{{ item.site_number }}</span>
            <div class="site-card__actions">
              <VBtn
                v-if="auth.isAdmin"
                icon="ri-edit-line"
                variant="text"
                size="small"
                @click.stop="openEditDialog(item)"
              />
              <VBtn
                v-if="auth.isAdmin"
                icon="ri-delete-bin-line"
                variant="text"
                size="small"
                @click.stop="deleteSite(item)"
              />
              <VBtn
                :icon="expanded.includes(item.id) ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'"
                variant="text"
                size="small"
                :aria-label="expanded.includes(item.id) ? 'Collapse site' : 'Expand site'"
                @click.stop="expanded = expanded.includes(item.id) ? expanded.filter(i => i !== item.id) : [...expanded, item.id]"
              />
            </div>
          </div>

          <div class="site-card__addr">
            <VIcon icon="ri-map-pin-line" size="14" class="me-1" />{{ item.address ?? '—' }}
          </div>

          <div class="site-card__kpis">
            <div class="kpi">
              <div class="kpi__n">{{ item.devices_count ?? 0 }}</div>
              <div class="kpi__l">Devices</div>
            </div>
            <div class="kpi">
              <div class="kpi__n">{{ item.circuits_count ?? 0 }}</div>
              <div class="kpi__l">Circuits</div>
            </div>
            <div class="kpi">
              <div
                class="kpi__n"
                :class="{ 'kpi__n--alert': (siteAlertMap[item.id] ?? 0) > 0 }"
              >
                {{ siteAlertMap[item.id] ?? 0 }}
              </div>
              <div class="kpi__l">Alarms</div>
            </div>
          </div>

          <div
            v-if="expanded.includes(item.id)"
            class="site-card__detail"
          >
            <div
              v-if="overviewFor(item.id)"
              class="site-overview pa-4"
            >
              <!-- Posture summary -->
              <div class="d-flex flex-wrap ga-2 mb-4">
                <VChip
                  size="small"
                  variant="tonal"
                  prepend-icon="ri-router-line"
                >
                  {{ overviewFor(item.id)!.summary.devices }} devices
                </VChip>
                <VChip
                  size="small"
                  variant="tonal"
                  prepend-icon="ri-signal-tower-line"
                >
                  {{ overviewFor(item.id)!.summary.circuits }} circuits
                </VChip>
                <VChip
                  size="small"
                  :color="overviewFor(item.id)!.summary.circuits_down > 0 ? 'error' : 'success'"
                  variant="tonal"
                >
                  {{ overviewFor(item.id)!.summary.circuits_down }} circuits down
                </VChip>
                <VChip
                  size="small"
                  :color="overviewFor(item.id)!.summary.interfaces_down > 0 ? 'error' : 'success'"
                  variant="tonal"
                >
                  {{ overviewFor(item.id)!.summary.interfaces_down }} interfaces down
                </VChip>
                <VChip
                  size="small"
                  :color="overviewFor(item.id)!.summary.active_alarms > 0 ? 'error' : 'success'"
                  variant="tonal"
                  :class="{ 'cursor-pointer': overviewFor(item.id)!.summary.active_alarms > 0 }"
                  prepend-icon="ri-alarm-warning-line"
                  @click="goToAlarmingDevice(item.id)"
                >
                  {{ overviewFor(item.id)!.summary.active_alarms }} active alarms
                </VChip>
                <VChip
                  v-if="overviewFor(item.id)!.summary.max_temp != null"
                  size="small"
                  variant="tonal"
                  prepend-icon="ri-temp-hot-line"
                >
                  peak {{ Math.round(Number(overviewFor(item.id)!.summary.max_temp)) }}°C
                </VChip>
                <VSpacer />
                <VBtn
                  size="small"
                  color="primary"
                  variant="flat"
                  prepend-icon="ri-share-line"
                  @click="router.push(`/topology?site=${item.id}`)"
                >
                  View topology
                </VBtn>
              </div>

              <!-- Active alarms grouped per ISP circuit for this site -->
              <div
                v-if="overviewFor(item.id)!.summary.active_alarms > 0 || overviewFor(item.id)!.summary.circuits_down > 0"
                class="mb-4"
              >
                <div class="text-caption text-uppercase font-weight-medium text-medium-emphasis mb-2">
                  Active alarms — by ISP
                </div>
                <AlarmGroups :site-id="item.id" />
              </div>

              <!-- Site contacts (from the Massey directory import) -->
              <div
                v-if="item.main_phone || item.gm_name || item.om_name || item.sm_name"
                class="site-contacts mb-4"
              >
                <div class="text-caption text-uppercase font-weight-medium text-medium-emphasis mb-2">
                  Site contacts
                </div>
                <div class="d-flex flex-wrap ga-6">
                  <div v-if="item.main_phone">
                    <div class="text-caption text-medium-emphasis">Main phone</div>
                    <div class="font-weight-medium">
                      <VIcon icon="ri-phone-line" size="14" class="me-1" />{{ item.main_phone }}
                    </div>
                    <div v-if="item.fax" class="text-caption text-medium-emphasis">Fax {{ item.fax }}</div>
                  </div>
                  <div v-if="item.gm_name">
                    <div class="text-caption text-medium-emphasis">General Manager</div>
                    <div class="font-weight-medium">{{ item.gm_name }}</div>
                    <div class="text-caption text-medium-emphasis">
                      {{ [item.gm_phone, item.gm_ext ? `x${item.gm_ext}` : ''].filter(Boolean).join(' · ') || '—' }}
                    </div>
                  </div>
                  <div v-if="item.om_name">
                    <div class="text-caption text-medium-emphasis">Office Manager</div>
                    <div class="font-weight-medium">{{ item.om_name }}</div>
                    <div class="text-caption text-medium-emphasis">
                      {{ [item.om_phone, item.om_ext ? `x${item.om_ext}` : ''].filter(Boolean).join(' · ') || '—' }}
                    </div>
                  </div>
                  <div v-if="item.sm_name">
                    <div class="text-caption text-medium-emphasis">Service Manager</div>
                    <div class="font-weight-medium">{{ item.sm_name }}</div>
                    <div class="text-caption text-medium-emphasis">
                      {{ [item.sm_phone, item.sm_ext ? `x${item.sm_ext}` : ''].filter(Boolean).join(' · ') || '—' }}
                    </div>
                  </div>
                </div>
              </div>

              <!-- Devices at this location -->
              <div class="text-caption text-medium-emphasis mb-1">
                Devices at this location
              </div>
              <VTable
                density="compact"
                class="mb-4 overview-table"
              >
                <thead>
                  <tr>
                    <th>Device</th>
                    <th>IP</th>
                    <th>Role</th>
                    <th>Model / OS</th>
                    <th class="text-right">
                      CPU
                    </th>
                    <th class="text-right">
                      Mem
                    </th>
                    <th class="text-right">
                      Temp
                    </th>
                    <th class="text-right">
                      Uptime
                    </th>
                    <th class="text-right">
                      Alerts
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="d in overviewFor(item.id)!.devices"
                    :key="d.id"
                    class="cursor-pointer"
                    @click="router.push(`/devices/${d.id}`)"
                  >
                    <td>
                      <span class="d-flex align-center ga-2">
                        <VIcon
                          :icon="roleIcon[d.role ?? ''] ?? 'ri-computer-line'"
                          size="16"
                          :class="d.is_down ? 'text-error' : 'text-medium-emphasis'"
                        />
                        {{ d.name }}
                        <VChip
                          v-if="d.is_down"
                          size="x-small"
                          color="error"
                          variant="flat"
                          prepend-icon="ri-alarm-warning-line"
                        >
                          DOWN
                        </VChip>
                      </span>
                    </td>
                    <td>{{ d.ip_address ?? '—' }}</td>
                    <td class="text-capitalize">
                      {{ d.role ?? '—' }}
                    </td>
                    <td>
                      {{ d.model ?? '—' }}
                      <span
                        v-if="d.os_version"
                        class="text-caption text-medium-emphasis"
                      >· {{ d.os_version }}</span>
                    </td>
                    <td
                      class="text-right"
                      :class="healthColor(d.cpu_pct)"
                    >
                      {{ d.cpu_pct != null ? `${d.cpu_pct}%` : '—' }}
                    </td>
                    <td
                      class="text-right"
                      :class="healthColor(d.mem_pct)"
                    >
                      {{ d.mem_pct != null ? `${d.mem_pct}%` : '—' }}
                    </td>
                    <td
                      class="text-right"
                      :class="healthColor(d.temperature_c, 55, 70)"
                    >
                      {{ d.temperature_c != null ? `${d.temperature_c}°C` : '—' }}
                    </td>
                    <td class="text-right">
                      {{ uptime(d.uptime_seconds) }}
                    </td>
                    <td class="text-right">
                      <span
                        v-if="d.interfaces_down > 0 || d.active_alarms > 0"
                        class="text-error font-weight-medium"
                      >
                        {{ d.interfaces_down + d.active_alarms }}
                      </span>
                      <span
                        v-else
                        class="text-disabled"
                      >0</span>
                    </td>
                  </tr>
                  <tr v-if="overviewFor(item.id)!.devices.length === 0">
                    <td
                      colspan="9"
                      class="text-center text-medium-emphasis py-3"
                    >
                      No devices at this site.
                    </td>
                  </tr>
                </tbody>
              </VTable>

              <!-- Circuits feeding this location -->
              <div class="text-caption text-medium-emphasis mb-1">
                Circuits feeding this location
              </div>
              <VTable
                density="compact"
                class="overview-table"
              >
                <thead>
                  <tr>
                    <th>ISP</th>
                    <th>Circuit ID</th>
                    <th>Type</th>
                    <th>Monitored IP</th>
                    <th>Status</th>
                    <th>ISP ticket</th>
                    <th>Support</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="c in overviewFor(item.id)!.circuits"
                    :key="c.id"
                    class="cursor-pointer"
                    @click="router.push('/circuits')"
                  >
                    <td>{{ c.isp_name ?? '—' }}</td>
                    <td>{{ c.circuit_id ?? '—' }}</td>
                    <td class="text-capitalize">
                      {{ c.circuit_type ?? '—' }}
                    </td>
                    <td>{{ c.monitored_ip ?? '—' }}</td>
                    <td>
                      <span class="d-flex align-center ga-2">
                        <span
                          class="dot"
                          :style="{ backgroundColor: c.transport_degraded ? 'rgb(var(--v-theme-warning))' : (c.status === 'up' ? 'rgb(var(--v-theme-success))' : 'rgb(var(--v-theme-error))') }"
                        />
                        <span v-if="c.transport_degraded" class="text-warning" :title="c.transport_reason">Degraded · edge unreachable</span>
                        <span v-else class="text-capitalize">{{ c.status }}</span>
                      </span>
                    </td>
                    <td>{{ c.ticket_number ? `#${c.ticket_number}` : '—' }}</td>
                    <td>{{ c.support_phone ?? '—' }}</td>
                  </tr>
                  <tr v-if="overviewFor(item.id)!.circuits.length === 0">
                    <td
                      colspan="7"
                      class="text-center text-medium-emphasis py-3"
                    >
                      No circuits at this site.
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </div>
            <div
              v-else
              class="d-flex justify-center pa-6"
            >
              <VProgressCircular
                indeterminate
                size="24"
              />
            </div>
          </div>
        </div>
      </template>
    </div>
  </VCard>

  <VDialog
    v-model="isDialogOpen"
    transition="slide-x-reverse-transition"
    content-class="nodus-drawer"
    scrollable
  >
    <VCard class="site-editor">
      <div class="ce-head">
        <div class="ce-ic"><VIcon icon="ri-map-pin-2-line" /></div>
        <div class="ce-title">
          <h2>{{ editingSite ? 'Edit Site' : 'Add Site' }}</h2>
          <p>{{ editingSite ? `${form.name || 'Site'} · ${form.site_type}` : 'Register a monitored location.' }}</p>
        </div>
        <VBtn icon="ri-close-line" variant="text" size="small" class="ce-x" @click="isDialogOpen = false" />
      </div>

      <VForm class="ce-form" @submit.prevent="saveSite">
        <div class="ce-body">
          <nav class="ce-rail">
            <button v-for="s in formSections" :key="s.id" type="button" class="ce-rail-btn" :class="{ on: activeSection === s.id }" @click="scrollToSection(s.id)">
              <span class="ce-rail-n">{{ s.n }}</span> {{ s.label }}
            </button>
          </nav>
          <div class="ce-content">
            <VAlert v-if="errorMessage" type="error" variant="tonal" density="compact" class="mb-4">{{ errorMessage }}</VAlert>

            <section id="sec-identity" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">01</span><h3>Identity</h3></div>
              <p class="ce-sub">Name, type, and which hubs this site homes to.</p>
              <VRow dense>
                <VCol cols="12"><VTextField v-model="form.name" label="Name" variant="outlined" density="comfortable" :error-messages="fieldErrors.name" /></VCol>
                <VCol cols="12" :sm="form.site_type === 'branch' ? 5 : 12">
                  <VSelect v-model="form.site_type" label="Type"
                    :items="[{ title: 'Branch', value: 'branch' }, { title: 'Hub', value: 'hub' }]"
                    variant="outlined" density="comfortable" />
                </VCol>
                <VCol v-if="form.site_type === 'branch'" cols="12" sm="7">
                  <VSelect v-model="form.hub_site_ids" :items="hubOptions" label="Homes to hubs" multiple chips closable-chips clearable
                    variant="outlined" density="comfortable"
                    :hint="hubOptions.length ? 'A branch can home to multiple hubs' : 'No hubs defined yet — mark a site as Hub first'" persistent-hint />
                </VCol>
              </VRow>
            </section>

            <section id="sec-location" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">02</span><h3>Location</h3></div>
              <p class="ce-sub">Street address geocodes to the dashboard map pin.</p>
              <VRow dense>
                <VCol cols="12">
                  <div class="d-flex align-start ga-2">
                    <VTextField v-model="form.address" label="Address" variant="outlined" density="comfortable" class="flex-grow-1" :error-messages="fieldErrors.address" :hide-details="!fieldErrors.address" />
                    <VBtn variant="tonal" color="primary" :loading="isGeocoding" prepend-icon="ri-map-pin-2-line" class="mt-1" @click="geocodeAddress">Geocode</VBtn>
                  </div>
                  <div v-if="geocodeMsg" class="text-caption mt-1" :class="geocodeError ? 'text-error' : 'text-success'">{{ geocodeMsg }}</div>
                </VCol>
                <VCol cols="6"><VTextField v-model="form.latitude" label="Latitude" type="number" variant="outlined" density="comfortable" class="ce-mono" placeholder="28.5384" :error-messages="fieldErrors.latitude" /></VCol>
                <VCol cols="6"><VTextField v-model="form.longitude" label="Longitude" type="number" variant="outlined" density="comfortable" class="ce-mono" placeholder="-81.3789" :error-messages="fieldErrors.longitude" /></VCol>
              </VRow>
            </section>

            <!-- Lease: the end date drives ISP contract decisions. Massey-owned sites
                 STILL carry a lease end (ground lease / sub-lease), so ownership is
                 recorded for context only — it never hides or clears the date. -->
            <section id="sec-lease" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">03</span><h3>Lease</h3></div>
              <p class="ce-sub">When the lease on this location ends. Drives ISP contract decisions — renew, extend, or let it run out ahead of a move.</p>
              <VCheckbox
                :model-value="form.occupancy === 'owned'"
                density="comfortable"
                hide-details
                class="mb-2"
                @update:model-value="v => form.occupancy = v ? 'owned' : 'leased'"
              >
                <template #label>
                  <span>Massey-owned property</span>
                  <span class="text-medium-emphasis text-caption ms-2">— owned sites still have a lease end</span>
                </template>
              </VCheckbox>
              <VRow dense>
                <VCol cols="12" sm="5">
                  <VTextField
                    v-model="form.lease_end_date"
                    label="Lease ends"
                    type="date"
                    variant="outlined"
                    density="comfortable"
                    class="ce-mono"
                    :error-messages="fieldErrors.lease_end_date"
                  />
                </VCol>
                <VCol cols="12" sm="7">
                  <VTextField
                    v-model="form.lease_notes"
                    label="Lease notes"
                    variant="outlined"
                    density="comfortable"
                    placeholder="Renewal option, landlord, move plans…"
                    :error-messages="fieldErrors.lease_notes"
                  />
                </VCol>
              </VRow>
            </section>

            <section id="sec-notes" class="ce-sect">
              <div class="ce-sh"><span class="ce-num">04</span><h3>Notes</h3></div>
              <p class="ce-sub">Free‑form notes about the site.</p>
              <VRow dense><VCol cols="12"><VTextarea v-model="form.notes" label="Notes" variant="outlined" rows="2" auto-grow density="comfortable" /></VCol></VRow>
            </section>
          </div>
        </div>

        <div class="ce-foot">
          <span class="ce-status">{{ form.name ? 'Ready to save' : 'Name is required' }}</span>
          <VBtn variant="text" @click="isDialogOpen = false">Cancel</VBtn>
          <VBtn type="submit" color="primary" :loading="isSaving">{{ editingSite ? 'Save site' : 'Add site' }}</VBtn>
        </div>
      </VForm>
    </VCard>
  </VDialog>
  </div>
</template>

<style scoped>
.site-number {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 0.72rem;
  color: rgba(var(--v-theme-on-surface), 0.5);
  line-height: 1.1;
}
.dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.cursor-pointer {
  cursor: pointer;
}
.site-overview {
  background: rgba(var(--v-theme-on-surface), 0.02);
}
/* A site with active alerts: red left-border + faint tint so trouble locations
   stand out without expanding the row. Rows are rendered by VDataTable, so the
   class is reached through :deep(). */
:deep(.site-row-alert) {
  background: rgba(var(--v-theme-error), 0.04);
}
:deep(.site-row-alert td:first-child) {
  box-shadow: inset 3px 0 0 0 rgb(var(--v-theme-error));
}
.overview-table :deep(th) {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: rgb(var(--v-theme-on-surface));
  opacity: 0.6;
}

/* Health-first site card grid (re-skin of the former data table). */
.site-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 14px;
  align-items: start;
}
.site-grid__state {
  grid-column: 1 / -1;
}
.site-card {
  background: rgba(var(--v-theme-on-surface), 0.02);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px;
  padding: 15px 16px;
}
.site-card--alert {
  border-color: rgba(var(--v-theme-error), 0.35);
  background: rgba(var(--v-theme-error), 0.04);
}
/* An expanded card carries the full NOC overview — let it span the whole row. */
.site-card--open {
  grid-column: 1 / -1;
}
.site-card__head {
  display: flex;
  align-items: center;
  gap: 9px;
}
.site-card__dot {
  inline-size: 9px;
  block-size: 9px;
  border-radius: 50%;
  flex: none;
}
.site-card__dot.is-ok {
  background: rgb(var(--v-theme-success));
}
.site-card__dot.is-alert {
  background: rgb(var(--v-theme-error));
  box-shadow: 0 0 0 3px rgba(var(--v-theme-error), 0.2);
}
.site-card__name {
  flex: 1;
  min-inline-size: 0;
  font-weight: 600;
  font-size: 0.95rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.site-card__code {
  flex: none;
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 0.72rem;
  color: rgba(var(--v-theme-on-surface), 0.5);
}
.site-card__actions {
  display: flex;
  align-items: center;
  flex: none;
}
.site-card__addr {
  display: flex;
  align-items: center;
  margin-block-start: 10px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  font-size: 0.78rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.site-card__kpis {
  display: flex;
  gap: 22px;
  margin-block-start: 13px;
}
.kpi__n {
  font-family: ui-monospace, "SF Mono", Menlo, monospace;
  font-size: 1.2rem;
  font-weight: 600;
  line-height: 1.1;
}
.kpi__n--alert {
  color: rgb(var(--v-theme-error));
}
.kpi__l {
  margin-block-start: 3px;
  font-size: 0.64rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.45);
}
.site-card__detail {
  margin-block-start: 14px;
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

/* Site editor drawer shell — the rail/body/content rules that reshape the shared
   .ce-* skeleton for a drawer live in styles.scss under .nodus-drawer. */
.site-editor { display: flex; flex-direction: column; height: 100%; max-height: 100%; border-radius: 0; }
</style>
