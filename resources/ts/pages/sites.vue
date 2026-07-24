<script setup lang="ts">
import { api } from '@/composables/useApi'
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
const form = ref({ name: '', site_type: 'branch', hub_site_ids: [] as number[], address: '', latitude: '' as string | number, longitude: '' as string | number, notes: '' })

// Hub sites to home a branch to (a hub can't home to itself).
const hubOptions = computed(() =>
  sites.value.filter(s => s.site_type === 'hub' && s.id !== editingSite.value?.id).map(s => ({ title: s.name, value: s.id })))
const errorMessage = ref('')

// Expanded rows + a lazy cache of each site's overview (fetched on first expand).
const expanded = ref<number[]>([])
const overviews = ref<Record<number, SiteOverview | 'loading'>>({})

const headers = [
  { title: 'Site', key: 'name' },
  { title: 'Address', key: 'address' },
  { title: 'Devices', key: 'devices_count', align: 'end' as const },
  { title: 'Circuits', key: 'circuits_count', align: 'end' as const },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
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
  form.value = { name: '', site_type: 'branch', hub_site_ids: [], address: '', latitude: '', longitude: '', notes: '' }
  errorMessage.value = ''
  isDialogOpen.value = true
}

function openEditDialog(site: Site) {
  editingSite.value = site
  form.value = {
    name: site.name,
    site_type: site.site_type ?? 'branch',
    hub_site_ids: site.hub_site_ids ?? [],
    address: site.address ?? '',
    latitude: site.latitude ?? '',
    longitude: site.longitude ?? '',
    notes: site.notes ?? '',
  }
  errorMessage.value = ''
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
    latitude: form.value.latitude === '' ? null : Number(form.value.latitude),
    longitude: form.value.longitude === '' ? null : Number(form.value.longitude),
    notes: form.value.notes,
  }
}

async function saveSite() {
  isSaving.value = true
  errorMessage.value = ''

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
  catch {
    errorMessage.value = 'Could not save the site. Check the fields and try again.'
  }
  finally {
    isSaving.value = false
  }
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

// Impacted sites float to the top (most alerts first) so the NOC can narrow down
// at a glance; everything else follows in site-name order.
const sortedSites = computed(() => {
  const alertsOf = (s: Site) => siteAlertMap.value[s.id] ?? 0
  return [...sites.value].sort((a, b) =>
    (alertsOf(b) - alertsOf(a)) || a.name.localeCompare(b.name))
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
  <VCard title="Sites">
    <template
      v-if="auth.isAdmin"
      #append
    >
      <VBtn @click="openCreateDialog">
        Add Site
      </VBtn>
    </template>

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

    <VCardText class="pb-0">
      <VTextField
        v-model="search"
        placeholder="Search site name or address…"
        prepend-inner-icon="ri-search-line"
        density="compact"
        hide-details
        clearable
        style="max-width: 360px;"
      />
    </VCardText>

    <VDataTable
      v-model:expanded="expanded"
      :headers="headers"
      :items="sortedSites"
      :loading="isLoading"
      :search="search"
      item-value="id"
      show-expand
      density="comfortable"
      :row-props="rowProps"
    >
      <template #item.name="{ item }">
        <span class="d-flex align-center ga-2">
          <VIcon
            icon="ri-map-pin-line"
            size="18"
            class="text-medium-emphasis"
          />
          <span class="font-weight-medium">{{ item.name }}</span>
        </span>
      </template>
      <template #item.address="{ item }">
        {{ item.address ?? '—' }}
      </template>
      <template #item.devices_count="{ item }">
        {{ item.devices_count ?? 0 }}
      </template>
      <template #item.circuits_count="{ item }">
        {{ item.circuits_count ?? 0 }}
      </template>
      <template #item.actions="{ item }">
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
      </template>

      <!-- Per-site NOC overview: posture summary + devices + circuits -->
      <template #expanded-row="{ columns, item }">
        <tr>
          <td
            :colspan="columns.length"
            class="pa-0"
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
                          class="text-medium-emphasis"
                        />
                        {{ d.name }}
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
                          :style="{ backgroundColor: c.status === 'up' ? 'rgb(var(--v-theme-success))' : 'rgb(var(--v-theme-error))' }"
                        />
                        <span class="text-capitalize">{{ c.status }}</span>
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
          </td>
        </tr>
      </template>
    </VDataTable>
  </VCard>

  <VDialog
    v-model="isDialogOpen"
    max-width="500"
  >
    <VCard :title="editingSite ? 'Edit Site' : 'Add Site'">
      <VCardText>
        <VAlert
          v-if="errorMessage"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ errorMessage }}
        </VAlert>

        <VForm @submit.prevent="saveSite">
          <VTextField
            v-model="form.name"
            label="Name"
            class="mb-4"
          />
          <VRow class="mb-1">
            <VCol :cols="form.site_type === 'branch' ? 5 : 12">
              <VSelect
                v-model="form.site_type"
                :items="[{ title: 'Branch', value: 'branch' }, { title: 'Hub', value: 'hub' }]"
                label="Type"
                hint="Hubs anchor the WAN; branches home to a hub"
                persistent-hint
              />
            </VCol>
            <VCol
              v-if="form.site_type === 'branch'"
              cols="7"
            >
              <VSelect
                v-model="form.hub_site_ids"
                :items="hubOptions"
                label="Homes to hubs"
                multiple
                chips
                closable-chips
                clearable
                :hint="hubOptions.length ? 'A branch can home to multiple hubs' : 'No hubs defined yet — mark a site as Hub first'"
                persistent-hint
              />
            </VCol>
          </VRow>
          <div class="d-flex align-start ga-2 mb-4">
            <VTextField
              v-model="form.address"
              label="Address"
              hide-details
              class="flex-grow-1"
            />
            <VBtn
              variant="tonal"
              :loading="isGeocoding"
              prepend-icon="ri-map-pin-2-line"
              @click="geocodeAddress"
            >
              Find on map
            </VBtn>
          </div>
          <div
            v-if="geocodeMsg"
            class="text-caption mb-2"
            :class="geocodeError ? 'text-error' : 'text-success'"
          >
            {{ geocodeMsg }}
          </div>
          <VRow class="mb-1">
            <VCol cols="6">
              <VTextField
                v-model="form.latitude"
                label="Latitude"
                type="number"
                placeholder="28.5384"
                hint="Places the site on the dashboard map"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="form.longitude"
                label="Longitude"
                type="number"
                placeholder="-81.3789"
              />
            </VCol>
          </VRow>
          <VTextarea
            v-model="form.notes"
            label="Notes"
            class="mb-4"
          />
          <VBtn
            type="submit"
            :loading="isSaving"
          >
            Save
          </VBtn>
        </VForm>
      </VCardText>
    </VCard>
  </VDialog>
</template>

<style scoped>
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
</style>
