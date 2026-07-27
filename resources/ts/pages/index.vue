<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { DashboardAlert, DashboardSummary, SearchResult } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()
const router = useRouter()

const data = ref<DashboardSummary | null>(null)
const isLoading = ref(true)
const loadError = ref('')

async function loadDashboard(silent = false) {
  if (!silent)
    isLoading.value = true
  loadError.value = ''
  try {
    data.value = await api<DashboardSummary>('/api/dashboard')
  }
  catch {
    if (!silent)
      loadError.value = 'Could not load the dashboard. Try refreshing.'
  }
  finally {
    isLoading.value = false
  }
}

// --- Global search (IP, hostname, address, ISP ticket, alarm/event id) ---
const searchResults = ref<SearchResult[]>([])
const searchIcon: Record<string, string> = {
  device: 'ri-router-line', circuit: 'ri-signal-tower-line', site: 'ri-map-pin-line',
  ticket: 'ri-coupon-3-line', alarm: 'ri-alarm-warning-line',
}
let searchTimer: ReturnType<typeof setTimeout> | null = null
function onSearch(q: string) {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(async () => {
    searchResults.value = q && q.trim().length >= 2 ? await api<SearchResult[]>(`/api/search?q=${encodeURIComponent(q)}`) : []
  }, 180)
}
function onSearchSelect(r: SearchResult | null) {
  if (r?.route) router.push(r.route)
}

// Auto-refresh the dashboard so new alarms appear without a manual reload. The
// 30s cadence is the display refresh; detection still happens on the server-side
// SNMP poll cycle. isLoading only shows the spinner on the first load.
let refreshTimer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  loadDashboard()
  refreshTimer = setInterval(() => loadDashboard(true), 30000)
})

onBeforeUnmount(() => {
  if (refreshTimer)
    clearInterval(refreshTimer)
})

// ---- formatting helpers ----
function formatCount(n: number): string {
  return n.toLocaleString()
}

function since(iso: string | null): string {
  if (!iso)
    return '—'
  const then = Date.parse(iso)
  if (Number.isNaN(then))
    return '—'
  let secs = Math.max(0, Math.floor((Date.now() - then) / 1000))
  const days = Math.floor(secs / 86400)
  secs -= days * 86400
  const hours = Math.floor(secs / 3600)
  secs -= hours * 3600
  const mins = Math.floor(secs / 60)
  if (days > 0)
    return `${days}d ${hours}h`
  if (hours > 0)
    return `${hours}h ${mins}m`

  return `${mins}m`
}

// ---- KPI strip ----
// A KPI is clickable when it maps to a filterable slice of active alerts and
// that slice is non-empty — clicking opens the alerts list scoped to that type.
const kpis = computed(() => {
  const c = data.value?.counts
  if (!c)
    return []

  const open = (type: 'all' | DashboardAlert['type'], count: number) =>
    count > 0 ? () => openAlertsList(type) : undefined

  return [
    { label: 'Sites', value: c.sites, danger: false },
    { label: 'Devices', value: c.devices, danger: false },
    { label: 'Active Alerts', value: c.active_alerts, danger: c.active_alerts > 0, action: open('all', c.active_alerts) },
    { label: 'Circuits Down', value: c.circuits_down, danger: c.circuits_down > 0, action: open('circuit', c.circuits_down) },
    { label: 'Interfaces Down', value: c.interfaces_down, danger: c.interfaces_down > 0, action: open('interface', c.interfaces_down) },
    { label: 'Tunnels Down', value: c.tunnels_down, danger: c.tunnels_down > 0, action: open('tunnel', c.tunnels_down) },
    // Degraded is deliberately NOT flagged danger — a threshold breach is not an
    // outage, and colouring it red is how the real ones got lost in the first place.
    // It still needs a way in, otherwise the category and its bulk clear are
    // unreachable.
    { label: 'Link Quality', value: c.tunnels_degraded ?? 0, danger: false, action: open('tunnel-quality', c.tunnels_degraded ?? 0) },
  ]
})

// ---- alerts breakdown (flat operator-style bars, one row per resource type) ----
// Bar length is relative to the largest bucket so the busiest category always
// reads as full-width. Rows are clickable to open that slice of the alert list.
const alertBreakdown = computed(() => {
  const c = data.value?.counts
  if (!c)
    return []

  // Down categories are service-affecting = RED (critical). The alarms bucket is
  // device-reported and mostly degraded conditions = AMBER (warning).
  const rows: { key: 'interface' | 'tunnel' | 'circuit' | 'alarm', label: string, value: number, color: string }[] = [
    { key: 'interface', label: 'Interfaces', value: c.interfaces_down, color: 'rgb(var(--v-theme-error))' },
    { key: 'circuit', label: 'Circuits', value: c.circuits_down, color: 'rgb(var(--v-theme-error))' },
    { key: 'tunnel', label: 'Tunnels', value: c.tunnels_down, color: 'rgb(var(--v-theme-error))' },
    { key: 'alarm', label: 'Alarms', value: c.active_alarms, color: 'rgb(var(--v-theme-warning))' },
  ]
  const max = Math.max(1, ...rows.map(r => r.value))

  return rows.map(r => ({ ...r, pct: Math.round((r.value / max) * 100) }))
})

// ---- clickable alerts ----
const selectedAlert = ref<DashboardAlert | null>(null)
const isAlertDetailOpen = ref(false)
const isAlertsListOpen = ref(false)

// Alerts-list scope: 'all' or one resource type. Set by the KPI cards and the
// breakdown rows so a click drills straight into the relevant outages.
const alertsFilter = ref<'all' | DashboardAlert['type']>('all')
const alertsFilterLabel: Record<'all' | DashboardAlert['type'], string> = {
  all: 'Active Alerts', circuit: 'Circuits Down', interface: 'Interfaces Down',
  tunnel: 'Tunnels Down', 'tunnel-quality': 'Link Quality', alarm: 'Active Alarms',
  next_hop: 'Next-hop Down', incident: 'Incidents',
}
function openAlertsList(type: 'all' | DashboardAlert['type']) {
  alertsFilter.value = type
  isAlertsListOpen.value = true
}
const filteredAlerts = computed(() => {
  const all = data.value?.alerts ?? []
  if (alertsFilter.value === 'all')
    return all

  // A type filter (Alarms / Tunnels / …) must look INSIDE correlated incidents —
  // their individual signals are what the operator wants when drilling a KPI, or
  // the popup comes up empty because every signal is hidden in an incident.
  const flat: DashboardAlert[] = []
  for (const a of all) {
    if (a.type === 'incident' && a.members)
      flat.push(...a.members)
    else
      flat.push(a)
  }

  return flat.filter(a => a.type === alertsFilter.value)
})

// Per-type NOC runbook shown in the detail dialog — "what to do" for each alert.
const runbooks: Record<DashboardAlert['type'], string[]> = {
  circuit: [
    'Confirm the outage with a manual ping to the monitored IP.',
    'Call the ISP support line (shown above) and open — or reference the previous — ticket.',
    'Record the ISP ticket number below so the whole NOC can track it.',
    'Acknowledge while the ISP works it; Clear once restored or if it was a false positive.',
  ],
  interface: [
    'Open the device and check the physical port, optics, and cabling.',
    'Confirm it is not an intended shutdown — admin-down ports are already excluded.',
    'Review the port’s neighbor and traffic history before dispatching a tech.',
  ],
  tunnel: [
    'Check the underlay WAN circuits at both tunnel endpoints.',
    'Inspect EdgeConnect reachability and next-hop health.',
    'If the underlay is healthy, escalate to the SD-WAN overlay.',
  ],
  'tunnel-quality': [
    'This is a sampled latency, jitter or loss figure crossing its threshold — the tunnel is up and passing traffic.',
    'On bulk or management tunnels this usually reflects orchestrator or cloud conditions upstream, not a site fault.',
    'Confirm the site is otherwise healthy: underlay circuits up, no WAN or next-hop alarms.',
    'If a whole batch fired together, select them and Clear in bulk with a note naming the upstream event.',
    'Only escalate if it persists on a business-critical tunnel or coincides with a user-reported problem.',
  ],
  alarm: [
    'Review the vendor Event ID for the exact appliance condition.',
    'Acknowledge with a note while you investigate.',
    'Clear with a resolution note once fixed — it will not reopen unless it flaps.',
  ],
  next_hop: [
    'Verify the upstream router / gateway is reachable.',
    'Check the WAN path and any recent config or routing changes.',
  ],
  incident: [
    'These signals are one outage on the same device — work the root, not each symptom.',
    'Open a correlated signal below to see its detail and act on it (acknowledge / clear).',
  ],
}

// When drilling from a correlated incident into one of its signals, remember the
// incident so the detail view can offer a way back.
const parentIncident = ref<DashboardAlert | null>(null)

function openAlert(alert: DashboardAlert) {
  parentIncident.value = null
  selectedAlert.value = alert
  alarmNote.value = ''
  circuitTicket.value = alert.ticket_number ?? ''
  isAlertDetailOpen.value = true
}

function drillMember(member: DashboardAlert) {
  parentIncident.value = selectedAlert.value
  selectedAlert.value = member
  alarmNote.value = ''
  circuitTicket.value = member.ticket_number ?? ''
}

function backToIncident() {
  if (!parentIncident.value)
    return
  selectedAlert.value = parentIncident.value
  parentIncident.value = null
  alarmNote.value = ''
}

function goToAlertResource(alert: DashboardAlert) {
  isAlertDetailOpen.value = false
  isAlertsListOpen.value = false
  if (alert.circuit_id)
    // By id — '/circuits' alone dropped the operator on an unfiltered list.
    router.push({ path: '/circuits', query: { circuit: String(alert.circuit_id) } })
  else if (alert.device_id)
    router.push(`/devices/${alert.device_id}`)
}

// --- Alarm acknowledge / clear (NOC workflow) ---
const alarmNote = ref('')
const alarmBusy = ref(false)

async function acknowledgeAlarm() {
  const id = selectedAlert.value?.alarm_db_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/alarms/${id}/acknowledge`, { method: 'POST', body: { note: alarmNote.value || null } })
    isAlertDetailOpen.value = false
    alarmNote.value = ''
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

async function clearAlarm() {
  const id = selectedAlert.value?.alarm_db_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/alarms/${id}/clear`, { method: 'POST', body: { note: alarmNote.value || null } })
    isAlertDetailOpen.value = false
    alarmNote.value = ''
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

// --- Circuit acknowledge / clear / ISP ticket (same NOC workflow as alarms) ---
const circuitTicket = ref('')

async function circuitAction(path: 'acknowledge' | 'clear', keepOpen = false) {
  const id = selectedAlert.value?.circuit_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/circuits/${id}/${path}`, { method: 'POST', body: { note: alarmNote.value || null } })
    if (!keepOpen)
      isAlertDetailOpen.value = false
    alarmNote.value = ''
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

async function saveCircuitTicket() {
  const id = selectedAlert.value?.circuit_id
  if (!id)
    return
  alarmBusy.value = true
  try {
    await api(`/api/circuits/${id}/ticket`, { method: 'POST', body: { ticket_number: circuitTicket.value || null } })
    if (selectedAlert.value)
      selectedAlert.value.ticket_number = circuitTicket.value || null
    await loadDashboard()
  }
  finally { alarmBusy.value = false }
}

// Type drives the LABEL only. Colour is driven by SEVERITY everywhere so the
// meaning is consistent across the app:
//   critical = RED  = service-affecting (something is DOWN, or appliance CRI/MAJ)
//   warning  = AMBER = degraded but still up (jitter, IP-SLA, high CPU, MIN/WARN)
//   info     = grey  = informational only
const alertTypeMeta: Record<DashboardAlert['type'], { label: string }> = {
  circuit: { label: 'Circuit' },
  interface: { label: 'Interface' },
  tunnel: { label: 'Tunnel' },
  'tunnel-quality': { label: 'Link quality' },
  next_hop: { label: 'Next-hop' },
  alarm: { label: 'Alarm' },
  incident: { label: 'Incident' },
}
const severityColor: Record<string, string> = { critical: 'error', warning: 'warning', info: 'info' }

// ---- bulk clear -----------------------------------------------------------
// A single upstream latency event raises one alarm per tunnel per appliance, so
// these arrive in floods. Clearing them one at a time is attrition, and an operator
// doing it by reflex eventually clears a real outage.
const selectedAlarms = ref<number[]>([])
const isBulkClearOpen = ref(false)
const bulkNote = ref('')
const bulkBusy = ref(false)

/** Only alerts backed by a DeviceAlarm row can be cleared this way. */
const clearableAlerts = computed(() => filteredAlerts.value.filter(a => a.alarm_ref))
const canBulkClear = computed(() => auth.canAct && clearableAlerts.value.length > 0)
const allSelected = computed(() =>
  clearableAlerts.value.length > 0 && selectedAlarms.value.length === clearableAlerts.value.length)

function toggleAlarm(id: number) {
  const i = selectedAlarms.value.indexOf(id)
  if (i === -1)
    selectedAlarms.value.push(id)
  else
    selectedAlarms.value.splice(i, 1)
}

function toggleAll() {
  selectedAlarms.value = allSelected.value ? [] : clearableAlerts.value.map(a => a.alarm_ref as number)
}

// Selecting rows then changing the filter would leave a selection the operator can
// no longer see — and then clear it blind.
watch([alertsFilter, isAlertsListOpen], () => { selectedAlarms.value = [] })

async function submitBulkClear() {
  if (selectedAlarms.value.length === 0)
    return
  bulkBusy.value = true
  try {
    await api('/api/alarms/bulk-clear', {
      method: 'POST',
      body: { ids: selectedAlarms.value, note: bulkNote.value || null },
    })
    isBulkClearOpen.value = false
    bulkNote.value = ''
    selectedAlarms.value = []
    await loadDashboard()
  }
  finally { bulkBusy.value = false }
}

</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between flex-wrap ga-2 mb-4">
      <div>
        <h1 class="text-h5 font-weight-medium">
          Network Overview
        </h1>
        <div class="text-body-2 text-medium-emphasis">
          Signed in as {{ auth.user?.name }} ({{ auth.user?.role }})
        </div>
      </div>
      <div class="d-flex align-center ga-3">
        <span class="text-caption text-medium-emphasis">Auto-refreshes every 30s</span>
        <VBtn
          variant="tonal"
          size="small"
          prepend-icon="ri-refresh-line"
          :loading="isLoading"
          @click="loadDashboard"
        >
          Refresh
        </VBtn>
      </div>
    </div>

    <!-- Global search -->
    <VAutocomplete
      :items="searchResults"
      item-title="label"
      item-value="route"
      return-object
      no-filter
      clearable
      hide-details
      density="comfortable"
      variant="solo"
      class="mb-4"
      placeholder="Search device, IP, hostname, site address, ISP ticket, or alarm/event ID…"
      prepend-inner-icon="ri-search-line"
      :menu-props="{ maxHeight: 400 }"
      @update:search="onSearch"
      @update:model-value="onSearchSelect"
    >
      <template #item="{ props, item }">
        <VListItem
          v-bind="props"
          :prepend-icon="searchIcon[item.raw.type]"
          :title="item.raw.label"
          :subtitle="item.raw.sub ?? item.raw.type"
        >
          <template #append>
            <span class="text-caption text-medium-emphasis text-capitalize">{{ item.raw.type }}</span>
          </template>
        </VListItem>
      </template>
      <template #no-data>
        <VListItem title="Type at least 2 characters to search" />
      </template>
    </VAutocomplete>

    <VAlert
      v-if="loadError"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      {{ loadError }}
    </VAlert>

    <!-- KPI strip — one row on a desktop screen, whatever the card count. A grid
         of equal fractions rather than 12-column spans, which forced the seventh
         metric onto a lonely second row. -->
    <div class="kpi-strip mb-4">
      <VCard
        v-for="kpi in kpis"
        :key="kpi.label"
        class="kpi-card"
        :class="{ 'cursor-pointer': kpi.action, 'is-danger': kpi.danger }"
        @click="kpi.action?.()"
      >
        <VCardText class="kpi-body">
          <div class="kpi-label text-medium-emphasis">
            {{ kpi.label }}
          </div>
          <div
            class="kpi-value"
            :class="kpi.danger ? 'text-error' : ''"
          >
            {{ isLoading ? '—' : formatCount(kpi.value) }}
          </div>
        </VCardText>
      </VCard>
    </div>

    <!-- Sites Health: map + per-site table -->
    <VCard class="mb-5">
      <VCardItem>
        <VCardTitle>Sites Health</VCardTitle>
        <template #append>
          <VChip
            size="small"
            color="success"
            variant="tonal"
          >
            Active now
          </VChip>
        </template>
      </VCardItem>
      <VCardText>
        <SiteMap
          :sites="data?.sites ?? []"
          :alerts="data?.alerts ?? []"
          :height="480"
          @open-alert="openAlert"
        />
      </VCardText>
    </VCard>

    <VRow class="mb-2">
      <!-- Active Alerts -->
      <VCol
        cols="12"
      >
        <VCard>
          <VCardItem>
            <VCardTitle>Active Alerts</VCardTitle>
            <template #append>
              <VChip
                size="small"
                :color="(data?.counts.active_alerts ?? 0) > 0 ? 'error' : 'success'"
                variant="tonal"
              >
                {{ data?.counts.active_alerts ?? 0 }} active
              </VChip>
            </template>
          </VCardItem>
          <VCardText>
            <div
              v-if="(data?.counts.active_alerts ?? 0) > 0"
              class="mb-2"
            >
              <div
                v-for="row in alertBreakdown"
                :key="row.key"
                class="breakdown-row"
                :class="{ 'is-clickable': row.value > 0 }"
                role="button"
                tabindex="0"
                @click="row.value > 0 && openAlertsList(row.key)"
                @keydown.enter="row.value > 0 && openAlertsList(row.key)"
              >
                <span class="breakdown-label text-body-2">{{ row.label }}</span>
                <span class="breakdown-track">
                  <span
                    class="breakdown-fill"
                    :style="{ width: `${row.pct}%`, backgroundColor: row.color }"
                  />
                </span>
                <span
                  class="breakdown-value text-body-2"
                  :class="row.value > 0 ? 'font-weight-medium' : 'text-disabled'"
                >{{ row.value }}</span>
              </div>
            </div>

            <!-- Severity legend — the app-wide convention -->
            <div
              v-if="(data?.counts.active_alerts ?? 0) > 0"
              class="d-flex ga-4 text-caption text-medium-emphasis mt-1 mb-2"
            >
              <span class="d-flex align-center ga-1">
                <span class="dot" :style="{ backgroundColor: 'rgb(var(--v-theme-error))' }" />
                Critical — service down
              </span>
              <span class="d-flex align-center ga-1">
                <span class="dot" :style="{ backgroundColor: 'rgb(var(--v-theme-warning))' }" />
                Warning — degraded
              </span>
            </div>

            <VDivider
              v-if="(data?.counts.active_alerts ?? 0) > 0"
              class="mb-2"
            />

            <div
              v-if="!isLoading && (data?.alerts.length ?? 0) === 0"
              class="all-clear"
            >
              <div class="all-clear-shield">
                <VIcon
                  icon="ri-shield-check-fill"
                  size="72"
                />
              </div>
              <div class="all-clear-title">
                All Clear
              </div>
              <div class="all-clear-sub">
                No active alerts across {{ data?.counts.sites ?? 0 }} sites · {{ data?.counts.devices ?? 0 }} devices
              </div>
            </div>

            <div
              v-for="alert in (data?.alerts ?? []).slice(0, 6)"
              :key="alert.key"
              class="alert-row"
              role="button"
              tabindex="0"
              @click="openAlert(alert)"
              @keydown.enter="openAlert(alert)"
            >
              <div class="d-flex align-center justify-space-between ga-2">
                <span class="font-weight-medium text-truncate">{{ alert.title }}</span>
                <span class="text-caption text-medium-emphasis flex-shrink-0">{{ since(alert.started_at) }}</span>
              </div>
              <div class="d-flex align-center ga-2">
                <VChip
                  size="x-small"
                  :color="severityColor[alert.severity] ?? 'warning'"
                  variant="tonal"
                >
                  {{ alertTypeMeta[alert.type].label }}
                </VChip>
                <span class="text-caption text-medium-emphasis text-truncate">{{ alert.subtitle }}</span>
                <span
                  v-if="alert.ticket_number"
                  class="text-caption text-medium-emphasis"
                >· #{{ alert.ticket_number }}</span>
              </div>
            </div>

            <VBtn
              v-if="(data?.alerts.length ?? 0) > 6"
              variant="text"
              size="small"
              class="mt-1"
              @click="openAlertsList('all')"
            >
              View all {{ data?.alerts.length }} alerts
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Alert detail dialog -->
    <VDialog
      v-model="isAlertDetailOpen"
      max-width="1440"
      width="97vw"
      scrollable
    >
      <VCard v-if="selectedAlert">
        <VCardItem>
          <VCardTitle>{{ selectedAlert.title }}</VCardTitle>
          <template #append>
            <VChip
              size="small"
              :color="severityColor[selectedAlert.severity] ?? 'warning'"
              variant="tonal"
            >
              {{ alertTypeMeta[selectedAlert.type].label }}
            </VChip>
          </template>
        </VCardItem>
        <VCardText>
          <VBtn
            v-if="parentIncident"
            variant="text"
            size="small"
            prepend-icon="ri-arrow-left-line"
            class="mb-2 ps-1"
            @click="backToIncident()"
          >
            Back to incident
          </VBtn>

          <!-- Correlated incident: signals/actions on the left, topology on the
               right so the wide dialog gives a real topology view -->
          <VRow
            v-if="selectedAlert.type === 'incident'"
          >
            <VCol
              cols="12"
              :md="selectedAlert.site_id ? 4 : 12"
              class="d-flex flex-column ga-3"
            >
              <div>
                <div class="text-caption text-medium-emphasis">
                  Correlated outage
                </div>
                <div>{{ selectedAlert.subtitle }}</div>
                <div class="text-caption text-medium-emphasis mt-1">
                  {{ selectedAlert.detail }} · active for {{ since(selectedAlert.started_at) }}
                </div>
              </div>
              <div>
                <div class="text-caption text-medium-emphasis mb-1">
                  Correlated signals ({{ selectedAlert.member_count }}) — open one to act on it
                </div>
                <div
                  v-for="m in selectedAlert.members"
                  :key="m.key"
                  class="alert-row"
                  role="button"
                  tabindex="0"
                  @click="drillMember(m)"
                  @keydown.enter="drillMember(m)"
                >
                  <div class="d-flex align-center justify-space-between ga-2">
                    <span class="font-weight-medium text-truncate">{{ m.title }}</span>
                    <span class="text-caption text-medium-emphasis flex-shrink-0">{{ since(m.started_at) }}</span>
                  </div>
                  <div class="d-flex align-center ga-2">
                    <VChip
                      size="x-small"
                      :color="severityColor[m.severity] ?? 'warning'"
                      variant="tonal"
                    >
                      {{ alertTypeMeta[m.type].label }}
                    </VChip>
                    <span class="text-caption text-medium-emphasis text-truncate">{{ m.subtitle }}</span>
                  </div>
                </div>
              </div>
              <div>
                <div class="text-caption text-medium-emphasis mb-1">
                  Recommended actions
                </div>
                <ol class="runbook">
                  <li
                    v-for="(step, i) in runbooks.incident"
                    :key="i"
                    class="text-body-2"
                  >
                    {{ step }}
                  </li>
                </ol>
              </div>
            </VCol>

            <!-- Topology context for the incident's site -->
            <VCol
              v-if="selectedAlert.site_id"
              cols="12"
              md="8"
            >
              <div class="text-caption text-medium-emphasis mb-1">
                Site topology
              </div>
              <TopologyStrip :site-id="selectedAlert.site_id" />
            </VCol>
          </VRow>

          <div
            v-else
            class="d-flex flex-column ga-3"
          >
            <div>
              <div class="text-caption text-medium-emphasis">
                What's wrong
              </div>
              <div>{{ selectedAlert.subtitle }} — {{ selectedAlert.detail }}</div>
            </div>
            <div class="alert-detail-grid">
              <div>
                <div class="text-caption text-medium-emphasis">
                  Active for
                </div>
                <div>{{ since(selectedAlert.started_at) }}</div>
              </div>
              <div v-if="selectedAlert.type === 'alarm' && selectedAlert.ticket_number">
                <div class="text-caption text-medium-emphasis">
                  Tracking ticket
                </div>
                <div class="font-weight-medium">#{{ selectedAlert.ticket_number }}</div>
              </div>
              <div v-if="selectedAlert.event_id" class="alert-detail-wide">
                <div class="text-caption text-medium-emphasis">
                  Event ID (vendor)
                </div>
                <div class="text-break">{{ selectedAlert.event_id }}</div>
              </div>
              <div v-if="selectedAlert.device_ip">
                <div class="text-caption text-medium-emphasis">
                  Device IP
                </div>
                <div>{{ selectedAlert.device_ip }}</div>
              </div>
              <div v-if="selectedAlert.type === 'alarm' && selectedAlert.acknowledged_at">
                <div class="text-caption text-medium-emphasis">
                  Acknowledged
                </div>
                <div>{{ selectedAlert.acknowledged_by ?? 'yes' }} · {{ since(selectedAlert.acknowledged_at) }}</div>
              </div>
              <div v-if="selectedAlert.type === 'circuit' && selectedAlert.ticket_number">
                <div class="text-caption text-medium-emphasis">
                  ISP ticket
                </div>
                <div>#{{ selectedAlert.ticket_number }}</div>
              </div>
              <div v-if="selectedAlert.type === 'circuit' && selectedAlert.support_phone">
                <div class="text-caption text-medium-emphasis">
                  ISP support
                </div>
                <div>{{ selectedAlert.support_phone }}</div>
              </div>
            </div>

            <VAlert
              v-if="selectedAlert.type === 'circuit' && !selectedAlert.ticket_number && selectedAlert.previous_ticket_number"
              type="info"
              variant="tonal"
              density="compact"
            >
              <div class="text-body-2">
                This circuit last had ISP ticket
                <strong>#{{ selectedAlert.previous_ticket_number }}</strong>.
                Reference it or reopen it with the ISP for this recurring outage.
              </div>
            </VAlert>

            <!-- Topology context for this alarm's site: root cause + dependency chain -->
            <div v-if="selectedAlert.site_id">
              <div class="text-caption text-medium-emphasis mb-1">
                Site topology
              </div>
              <TopologyStrip :site-id="selectedAlert.site_id" />
            </div>

            <!-- What to do — per-type NOC runbook -->
            <div>
              <div class="text-caption text-medium-emphasis mb-1">
                Recommended actions
              </div>
              <ol class="runbook">
                <li
                  v-for="(step, i) in runbooks[selectedAlert.type]"
                  :key="i"
                  class="text-body-2"
                >
                  {{ step }}
                </li>
              </ol>
            </div>

            <!-- Circuit: record the ISP-provided ticket -->
            <div
              v-if="selectedAlert.type === 'circuit' && selectedAlert.circuit_id"
              class="d-flex align-end ga-2"
            >
              <VTextField
                v-model="circuitTicket"
                label="ISP ticket #"
                placeholder="e.g. INC-39900"
                hide-details
                density="comfortable"
                class="flex-grow-1"
              />
              <VBtn
                :loading="alarmBusy"
                variant="tonal"
                @click="saveCircuitTicket()"
              >
                Save ticket
              </VBtn>
            </div>

            <VTextarea
              v-if="(selectedAlert.type === 'alarm' && selectedAlert.alarm_db_id) || (selectedAlert.type === 'circuit' && selectedAlert.circuit_id)"
              v-model="alarmNote"
              label="Investigation / resolution note (optional)"
              rows="2"
              auto-grow
              hide-details
              density="comfortable"
              class="mt-1"
            />
          </div>
        </VCardText>
        <VCardActions>
          <template v-if="auth.canAct && selectedAlert.type === 'alarm' && selectedAlert.alarm_db_id">
            <VBtn
              :loading="alarmBusy"
              variant="tonal"
              @click="acknowledgeAlarm()"
            >
              {{ selectedAlert.acknowledged_at ? 'Save note' : 'Acknowledge' }}
            </VBtn>
            <VBtn
              :loading="alarmBusy"
              color="success"
              variant="flat"
              @click="clearAlarm()"
            >
              Clear
            </VBtn>
          </template>
          <template v-if="auth.canAct && selectedAlert.type === 'circuit' && selectedAlert.circuit_id">
            <VBtn
              :loading="alarmBusy"
              variant="tonal"
              @click="circuitAction('acknowledge')"
            >
              {{ selectedAlert.acknowledged_at ? 'Save note' : 'Acknowledge' }}
            </VBtn>
            <VBtn
              :loading="alarmBusy"
              color="success"
              variant="flat"
              @click="circuitAction('clear')"
            >
              Clear
            </VBtn>
          </template>
          <VSpacer />
          <VBtn @click="isAlertDetailOpen = false">
            Close
          </VBtn>
          <VBtn
            v-if="selectedAlert.device_id || selectedAlert.circuit_id"
            color="primary"
            variant="flat"
            @click="goToAlertResource(selectedAlert)"
          >
            {{ selectedAlert.circuit_id ? 'Go to circuit' : 'Go to device' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Full alerts list dialog -->
    <VDialog
      v-model="isAlertsListOpen"
      max-width="640"
    >
      <VCard :title="alertsFilterLabel[alertsFilter]">
        <template #append>
          <VChip
            v-if="alertsFilter !== 'all'"
            size="small"
            variant="tonal"
            @click="alertsFilter = 'all'"
          >
            Show all
          </VChip>
        </template>
        <VCardText>
          <!-- Bulk clear. Shown only when there is something an analyst may act on;
               a viewer never sees a control they cannot use. -->
          <div
            v-if="canBulkClear"
            class="d-flex align-center ga-3 mb-3 pb-3 bulk-bar"
          >
            <VCheckboxBtn
              :model-value="allSelected"
              :indeterminate="selectedAlarms.length > 0 && !allSelected"
              density="compact"
              @update:model-value="toggleAll"
            />
            <span class="text-caption text-medium-emphasis">
              {{ selectedAlarms.length
                ? `${selectedAlarms.length} selected`
                : `Select alarms to clear (${clearableAlerts.length} clearable)` }}
            </span>
            <VSpacer />
            <VBtn
              size="small"
              variant="tonal"
              color="warning"
              prepend-icon="ri-check-double-line"
              :disabled="selectedAlarms.length === 0"
              @click="isBulkClearOpen = true"
            >
              Clear selected
            </VBtn>
          </div>

          <div
            v-if="filteredAlerts.length === 0"
            class="text-center text-medium-emphasis py-6"
          >
            No active alerts.
          </div>
          <div
            v-for="alert in filteredAlerts"
            :key="alert.key"
            class="alert-row"
            :class="{ 'alert-row--selected': alert.alarm_ref && selectedAlarms.includes(alert.alarm_ref) }"
          >
            <div class="d-flex align-center justify-space-between ga-2">
              <div class="d-flex align-center ga-2 flex-grow-1 min-w-0">
                <VCheckboxBtn
                  v-if="canBulkClear && alert.alarm_ref"
                  :model-value="selectedAlarms.includes(alert.alarm_ref)"
                  density="compact"
                  class="flex-shrink-0"
                  @update:model-value="toggleAlarm(alert.alarm_ref as number)"
                  @click.stop
                />
                <span
                  class="font-weight-medium text-truncate cursor-pointer"
                  role="button"
                  tabindex="0"
                  @click="openAlert(alert)"
                  @keydown.enter="openAlert(alert)"
                >{{ alert.title }}</span>
              </div>
              <span class="text-caption text-medium-emphasis flex-shrink-0">{{ since(alert.started_at) }}</span>
            </div>
            <div class="d-flex align-center ga-2">
              <VChip
                size="x-small"
                :color="severityColor[alert.severity] ?? 'warning'"
                variant="tonal"
              >
                {{ alertTypeMeta[alert.type].label }}
              </VChip>
              <span class="text-caption text-medium-emphasis text-truncate">{{ alert.subtitle }} — {{ alert.detail }}</span>
            </div>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Bulk-clear confirmation. States the count plainly and takes a note, because
         a clear is a NOC decision that someone may have to account for later. -->
    <VDialog
      v-model="isBulkClearOpen"
      max-width="480"
    >
      <VCard title="Clear selected alarms">
        <VCardText>
          <p class="mb-4">
            Clearing <strong>{{ selectedAlarms.length }}</strong>
            {{ selectedAlarms.length === 1 ? 'alarm' : 'alarms' }}.
            An alarm the appliance is still reporting stays cleared until it genuinely
            flaps, so this will not simply reappear on the next poll.
          </p>
          <VTextarea
            v-model="bulkNote"
            label="Clear note (optional)"
            placeholder="e.g. Orchestrator latency event — not traffic affecting"
            rows="2"
            auto-grow
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            :disabled="bulkBusy"
            @click="isBulkClearOpen = false"
          >
            Cancel
          </VBtn>
          <VBtn
            color="warning"
            variant="flat"
            :loading="bulkBusy"
            @click="submitBulkClear"
          >
            Clear {{ selectedAlarms.length }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.bulk-bar { border-block-end: 1px solid rgba(var(--v-theme-on-surface), 0.12); }
.alert-row--selected { background: rgba(var(--v-theme-warning), 0.08); }
.min-w-0 { min-inline-size: 0; }

/* KPI cards: a soft accent shadow at the bottom edge — primary normally, red
   when the metric is service-affecting. */
.kpi-strip {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
@media (min-width: 600px) { .kpi-strip { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
/* One row from here up — the columns share whatever width is going, so adding a
   metric narrows the cards instead of wrapping them. */
@media (min-width: 960px) {
  .kpi-strip {
    grid-template-columns: none;
    grid-auto-flow: column;
    grid-auto-columns: minmax(0, 1fr);
  }
}

.kpi-body { padding: 12px 14px; }
.kpi-label {
  font-size: 0.72rem; line-height: 1.2; letter-spacing: .02em;
  /* Cards get narrow at seven across; keep the label on one line. */
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.kpi-value { font-size: 1.5rem; font-weight: 500; line-height: 1.25; margin-block-start: 2px; }
@media (min-width: 960px) and (max-width: 1279px) {
  .kpi-body { padding: 10px 12px; }
  .kpi-value { font-size: 1.3rem; }
}

.kpi-card {
  box-shadow: 0 6px 16px -10px rgba(var(--v-theme-primary), 0.65);
  transition: box-shadow .15s ease, transform .15s ease;
}
.kpi-card.is-danger { box-shadow: 0 6px 16px -9px rgba(var(--v-theme-error), 0.8); }
.kpi-card.cursor-pointer:hover { transform: translateY(-2px); }

/* All-clear hero — a big, reassuring green shield when nothing is firing. */
.all-clear {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 40px 16px; gap: 6px;
}
.all-clear-shield {
  color: rgb(var(--v-theme-success));
  filter: drop-shadow(0 6px 20px rgba(var(--v-theme-success), 0.4));
  line-height: 0; margin-bottom: 6px;
}
.all-clear-title { font-size: 1.75rem; font-weight: 700; letter-spacing: .5px; color: rgb(var(--v-theme-success)); }
.all-clear-sub { font-size: .9rem; color: rgba(var(--v-theme-on-surface), 0.6); }

.dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}

.alert-row {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 9px 8px;
  margin: 0 -8px;
  border-radius: 6px;
  cursor: pointer;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.08);
}
.alert-row:last-of-type {
  border-bottom: none;
}
.alert-row:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.alert-row:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}

.cursor-pointer {
  cursor: pointer;
}

/* Active-alerts breakdown — flat labeled bars, one row per resource type. */
.breakdown-row {
  display: grid;
  grid-template-columns: 84px 1fr 28px;
  align-items: center;
  gap: 10px;
  padding: 4px 6px;
  margin: 0 -6px;
  border-radius: 6px;
}
.breakdown-row.is-clickable {
  cursor: pointer;
}
.breakdown-row.is-clickable:hover {
  background: rgba(var(--v-theme-on-surface), 0.04);
}
.breakdown-row:focus-visible {
  outline: 2px solid rgb(var(--v-theme-primary));
  outline-offset: -2px;
}
.breakdown-track {
  height: 8px;
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.08);
  overflow: hidden;
}
.breakdown-fill {
  display: block;
  height: 100%;
  border-radius: 4px;
  transition: width 0.3s ease;
}
.breakdown-value {
  text-align: right;
}

/* NOC runbook — compact numbered steps in the alert detail dialog. */
.runbook {
  margin: 0;
  padding-left: 18px;
}
.runbook li {
  margin-bottom: 2px;
}

.availability-table :deep(tbody tr) {
  cursor: pointer;
}

/* Alarm detail fields: two columns that wrap, so a long vendor Event ID never
   overflows the dialog or collides with the neighbouring field. */
.alert-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 24px;
}
.alert-detail-wide {
  grid-column: 1 / -1;
}
.text-break {
  overflow-wrap: anywhere;
  word-break: break-word;
}
</style>
