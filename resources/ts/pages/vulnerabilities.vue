<script setup lang="ts">
import { api } from '@/composables/useApi'

interface Coverage { total_devices: number; with_version: number; without_version: number; affected_devices: number; clean_devices: number }
interface VendorRow { total: number; critical: number; high: number }
interface TopDevice { device_id: number; device_name: string | null; site_name: string | null; vendor: string | null; os_version: string | null; critical: number; high: number; total: number; max_cvss: number }
interface Summary { coverage: Coverage; open_findings: number; by_severity: Record<string, number>; by_vendor: Record<string, VendorRow>; top_devices: TopDevice[] }
interface Finding {
  id: number; device_id: number; cve_id: string; state: string
  detected_os_version: string | null; matched_constraint: string | null; first_seen_at: string | null
  acknowledged_at: string | null; ack_note: string | null
  cvss_score: number | null; severity: string; summary: string | null; reference_url: string | null
  device_name: string; vendor: string | null; model: string | null; site_name: string | null
}

const summary = ref<Summary | null>(null)
const findings = ref<Finding[]>([])
const loading = ref(true)

const severityFilter = ref<string | null>(null)
const vendorFilter = ref<string | null>(null)
const stateFilter = ref<'open' | 'acknowledged' | null>(null)

const SEV_COLOR: Record<string, string> = { critical: 'error', high: 'warning', medium: 'info', low: 'secondary' }
const SEV_ORDER = ['critical', 'high', 'medium', 'low']
function sevColor(s: string) { return SEV_COLOR[s] ?? 'secondary' }

async function loadSummary() {
  summary.value = await api<Summary>('/api/vulnerabilities/summary')
}
async function loadFindings() {
  const qs = new URLSearchParams()
  if (severityFilter.value) qs.set('severity', severityFilter.value)
  if (vendorFilter.value) qs.set('vendor', vendorFilter.value)
  if (stateFilter.value) qs.set('state', stateFilter.value)
  if (deviceFilter.value) qs.set('device_id', String(deviceFilter.value.id))
  const res = await api<{ data: Finding[] }>(`/api/vulnerabilities?${qs.toString()}`)
  findings.value = res.data
}
async function reload() {
  loading.value = true
  try { await Promise.all([loadSummary(), loadFindings()]) }
  finally { loading.value = false }
}

watch([severityFilter, vendorFilter, stateFilter], loadFindings)
onMounted(reload)

const vendors = computed(() => Object.keys(summary.value?.by_vendor ?? {}))

// Expand a top device to see its actual CVEs inline (fetched on demand, so it's
// correct regardless of the findings filter), each clickable through to NVD.
// Clicking a device in "Most exposed devices" filters the full-width Findings table
// below to that device (where its CVEs have room to read), then scrolls to it — a
// clean master→detail, not a cramped sidebar expand.
const deviceFilter = ref<{ id: number, name: string } | null>(null)
async function selectDevice(id: number, name: string) {
  deviceFilter.value = deviceFilter.value?.id === id ? null : { id, name }
  await loadFindings()
  if (deviceFilter.value)
    document.getElementById('findings-table')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
function clearDeviceFilter() {
  deviceFilter.value = null
  loadFindings()
}
const coveragePct = computed(() => {
  const c = summary.value?.coverage
  return c && c.total_devices ? Math.round((c.with_version / c.total_devices) * 100) : 0
})

// Acknowledge dialog
const ackDialog = ref(false)
const ackTarget = ref<Finding | null>(null)
const ackNote = ref('')
const ackBusy = ref(false)
function openAck(f: Finding) { ackTarget.value = f; ackNote.value = ''; ackDialog.value = true }
async function confirmAck() {
  if (!ackTarget.value) return
  ackBusy.value = true
  try {
    await api(`/api/vulnerabilities/${ackTarget.value.id}/acknowledge`, { method: 'POST', body: { note: ackNote.value || null } })
    ackDialog.value = false
    await reload()
  } finally { ackBusy.value = false }
}

const headers = [
  { title: 'Severity', key: 'severity', width: 110 },
  { title: 'CVSS', key: 'cvss_score', width: 80 },
  { title: 'CVE', key: 'cve_id', width: 150 },
  { title: 'Device', key: 'device_name' },
  { title: 'Site', key: 'site_name' },
  { title: 'Evidence', key: 'matched_constraint' },
  { title: 'First seen', key: 'first_seen_at', width: 120 },
  { title: '', key: 'actions', sortable: false, width: 60 },
]

function fmtDate(s: string | null) { return s ? new Date(s).toLocaleDateString() : '—' }
</script>

<template>
  <div class="d-flex flex-column ga-6">
    <!-- Header -->
    <div class="d-flex flex-wrap align-center justify-space-between ga-4">
      <div>
        <h4 class="text-h4 mb-1">Vulnerability Posture</h4>
        <div class="text-body-2 text-medium-emphasis">
          Passive CVE correlation against polled firmware — no scan traffic sent to devices.
        </div>
      </div>
      <VBtn variant="tonal" prepend-icon="ri-refresh-line" :loading="loading" @click="reload">Refresh</VBtn>
    </div>

    <!-- Severity KPI tiles -->
    <VRow>
      <VCol v-for="sev in SEV_ORDER" :key="sev" cols="6" md="3">
        <VCard :class="`vuln-tile vuln-tile--${sev}`" @click="severityFilter = severityFilter === sev ? null : sev">
          <VCardText class="d-flex align-center justify-space-between py-4">
            <div>
              <div class="text-caption text-uppercase text-medium-emphasis">{{ sev }}</div>
              <div class="text-h3 font-weight-bold" :class="`text-${sevColor(sev)}`">
                {{ summary?.by_severity?.[sev] ?? 0 }}
              </div>
            </div>
            <VAvatar :color="sevColor(sev)" variant="tonal" size="48" rounded>
              <VIcon icon="ri-shield-keyhole-line" size="26" />
            </VAvatar>
          </VCardText>
          <div v-if="severityFilter === sev" :class="`vuln-tile__active bg-${sevColor(sev)}`" />
        </VCard>
      </VCol>
    </VRow>

    <VRow>
      <!-- Coverage -->
      <VCol cols="12" md="4">
        <VCard class="h-100">
          <VCardItem>
            <VCardTitle>Fleet coverage</VCardTitle>
            <template #append>
              <VChip size="small" :color="coveragePct >= 80 ? 'success' : coveragePct >= 50 ? 'warning' : 'error'" label>
                {{ coveragePct }}% assessed
              </VChip>
            </template>
          </VCardItem>
          <VCardText>
            <VProgressLinear
              :model-value="coveragePct" height="10" rounded
              :color="coveragePct >= 80 ? 'success' : 'warning'" bg-color="secondary" class="mb-4"
            />
            <div class="d-flex flex-column ga-3">
              <div class="d-flex align-center justify-space-between">
                <span class="d-flex align-center ga-2"><VIcon icon="ri-error-warning-line" color="error" size="18" /> Affected devices</span>
                <strong class="text-error">{{ summary?.coverage?.affected_devices ?? 0 }}</strong>
              </div>
              <div class="d-flex align-center justify-space-between">
                <span class="d-flex align-center ga-2"><VIcon icon="ri-shield-check-line" color="success" size="18" /> Clean (version known)</span>
                <strong>{{ summary?.coverage?.clean_devices ?? 0 }}</strong>
              </div>
              <div class="d-flex align-center justify-space-between">
                <span class="d-flex align-center ga-2"><VIcon icon="ri-question-line" color="secondary" size="18" /> No version (unassessed)</span>
                <strong>{{ summary?.coverage?.without_version ?? 0 }}</strong>
              </div>
              <VDivider />
              <div class="d-flex align-center justify-space-between text-medium-emphasis">
                <span>Open findings</span><strong>{{ summary?.open_findings ?? 0 }}</strong>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- By vendor -->
      <VCol cols="12" md="3">
        <VCard class="h-100">
          <VCardItem><VCardTitle>By vendor</VCardTitle></VCardItem>
          <VCardText>
            <div v-if="!vendors.length" class="text-medium-emphasis text-body-2 py-4">No open findings.</div>
            <div v-for="v in vendors" :key="v" class="mb-4">
              <div class="d-flex align-center justify-space-between mb-1">
                <span class="text-capitalize font-weight-medium">{{ v }}</span>
                <span class="text-body-2 text-medium-emphasis">{{ summary?.by_vendor[v].total }} findings</span>
              </div>
              <div class="d-flex ga-1">
                <VChip size="x-small" color="error" label>{{ summary?.by_vendor[v].critical }} crit</VChip>
                <VChip size="x-small" color="warning" label>{{ summary?.by_vendor[v].high }} high</VChip>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Top exposed devices -->
      <VCol cols="12" md="5">
        <VCard class="h-100">
          <VCardItem><VCardTitle>Most exposed devices</VCardTitle></VCardItem>
          <VList class="py-0">
            <template v-for="(d, i) in summary?.top_devices ?? []" :key="d.device_id">
              <VListItem
                class="cursor-pointer"
                :active="deviceFilter?.id === d.device_id"
                color="primary"
                @click="selectDevice(d.device_id, d.device_name ?? 'device')"
              >
                <template #prepend>
                  <VAvatar size="30" :color="d.critical ? 'error' : 'warning'" variant="tonal" class="text-caption font-weight-bold">
                    {{ i + 1 }}
                  </VAvatar>
                </template>
                <VListItemTitle class="font-weight-medium">{{ d.device_name }}</VListItemTitle>
                <VListItemSubtitle>{{ d.site_name ?? '—' }} · {{ d.vendor }} {{ d.os_version }}</VListItemSubtitle>
                <template #append>
                  <div class="d-flex align-center ga-1">
                    <VChip v-if="d.critical" size="x-small" color="error" label>{{ d.critical }}</VChip>
                    <VChip v-if="d.high" size="x-small" color="warning" label>{{ d.high }}</VChip>
                    <VChip size="x-small" variant="tonal" label>CVSS {{ d.max_cvss.toFixed(1) }}</VChip>
                    <VIcon icon="ri-arrow-right-s-line" size="18" class="text-medium-emphasis" />
                  </div>
                </template>
              </VListItem>
              <VDivider v-if="i < (summary?.top_devices?.length ?? 0) - 1" />
            </template>
            <VListItem v-if="!summary?.top_devices?.length">
              <VListItemTitle class="text-medium-emphasis">No exposed devices 🎉</VListItemTitle>
            </VListItem>
          </VList>
        </VCard>
      </VCol>
    </VRow>

    <!-- Findings -->
    <VCard id="findings-table">
      <VCardItem>
        <VCardTitle class="d-flex align-center ga-2">
          Findings
          <VChip
            v-if="deviceFilter"
            size="small" color="primary" label closable
            @click:close="clearDeviceFilter"
          >
            {{ deviceFilter.name }}
          </VChip>
        </VCardTitle>
        <template #append>
          <div class="app-filter-chips">
            <VChip
              v-for="v in vendors" :key="v"
              :color="vendorFilter === v ? 'primary' : undefined"
              :variant="vendorFilter === v ? 'flat' : 'tonal'"
              size="small" label class="cursor-pointer text-capitalize"
              @click="vendorFilter = vendorFilter === v ? null : v"
            >
              {{ v }}
            </VChip>
            <VDivider vertical class="mx-1" style="height: 20px; align-self: center" />
            <VChip
              :color="stateFilter === 'open' ? 'error' : undefined"
              :variant="stateFilter === 'open' ? 'flat' : 'tonal'"
              size="small" label class="cursor-pointer"
              @click="stateFilter = stateFilter === 'open' ? null : 'open'"
            >
              Open
            </VChip>
            <VChip
              :color="stateFilter === 'acknowledged' ? 'success' : undefined"
              :variant="stateFilter === 'acknowledged' ? 'flat' : 'tonal'"
              size="small" label class="cursor-pointer"
              @click="stateFilter = stateFilter === 'acknowledged' ? null : 'acknowledged'"
            >
              Ack’d
            </VChip>
          </div>
        </template>
      </VCardItem>

      <VDataTable
        :headers="headers" :items="findings" :loading="loading" density="comfortable"
        :items-per-page="25" :sort-by="[{ key: 'cvss_score', order: 'desc' }]"
      >
        <template #item.severity="{ item }">
          <VChip :color="sevColor(item.severity)" size="small" label class="text-capitalize font-weight-medium">
            {{ item.severity }}
          </VChip>
        </template>
        <template #item.cvss_score="{ item }">
          <span class="font-weight-bold" :class="`text-${sevColor(item.severity)}`">{{ item.cvss_score == null ? '—' : Number(item.cvss_score).toFixed(1) }}</span>
        </template>
        <template #item.cve_id="{ item }">
          <a :href="item.reference_url ?? '#'" target="_blank" rel="noopener" class="cve-link d-inline-flex align-center ga-1">
            <code>{{ item.cve_id }}</code><VIcon icon="ri-external-link-line" size="14" />
          </a>
        </template>
        <template #item.device_name="{ item }">
          <RouterLink :to="`/devices/${item.device_id}`" class="text-high-emphasis text-decoration-none font-weight-medium">
            {{ item.device_name }}
          </RouterLink>
          <div class="text-caption text-medium-emphasis">{{ item.vendor }} {{ item.model }} · {{ item.detected_os_version }}</div>
        </template>
        <template #item.site_name="{ item }">{{ item.site_name ?? '—' }}</template>
        <template #item.matched_constraint="{ item }">
          <VChip size="x-small" variant="tonal" label>{{ item.matched_constraint ?? '—' }}</VChip>
        </template>
        <template #item.first_seen_at="{ item }">
          <span class="text-body-2">{{ fmtDate(item.first_seen_at) }}</span>
          <VChip v-if="item.state === 'acknowledged'" size="x-small" color="secondary" label class="ms-1">ack’d</VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn v-if="item.state !== 'acknowledged'" icon variant="text" size="small" @click="openAck(item)">
            <VIcon icon="ri-check-double-line" />
            <VTooltip activator="parent">Acknowledge (accepted risk)</VTooltip>
          </VBtn>
        </template>
        <template #expanded-row="{ item }">{{ item.summary }}</template>
        <template #no-data>
          <div class="py-6 text-center text-medium-emphasis">No findings for this filter.</div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Acknowledge dialog -->
    <VDialog v-model="ackDialog" max-width="480">
      <VCard>
        <VCardItem><VCardTitle>Acknowledge finding</VCardTitle></VCardItem>
        <VCardText>
          <p class="text-body-2 mb-3">
            <code>{{ ackTarget?.cve_id }}</code> on <strong>{{ ackTarget?.device_name }}</strong>.
            Acknowledging marks it as accepted/known risk — it stays visible under “Ack’d”.
          </p>
          <VTextarea v-model="ackNote" label="Note (optional)" rows="3" variant="outlined" hide-details placeholder="e.g. mitigated via ACL / upgrade scheduled" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="ackDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="ackBusy" @click="confirmAck">Acknowledge</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped lang="scss">
.vuln-tile {
  position: relative;
  cursor: pointer;
  overflow: hidden;
  transition: transform .15s ease, box-shadow .15s ease;

  &:hover { transform: translateY(-2px); }

  &__active {
    position: absolute;
    inset-block-end: 0;
    inset-inline: 0;
    block-size: 3px;
  }
}

.cve-link {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;

  code { color: inherit; }
}
</style>
