<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { DiscoveryScan, SnmpCredential } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()

// ---- SNMP credentials ---------------------------------------------------
const credentials = ref<SnmpCredential[]>([])
const isCredDialogOpen = ref(false)
const isSavingCred = ref(false)
const credError = ref('')

function emptyCred() {
  return {
    name: '',
    snmp_version: 'v2c' as 'v2c' | 'v3',
    snmp_community: '',
    snmp_v3_username: '',
    snmp_v3_auth_key: '',
    snmp_v3_priv_key: '',
    notes: '',
  }
}
const credForm = ref(emptyCred())

const credHeaders = [
  { title: 'Name', key: 'name' },
  { title: 'Version', key: 'snmp_version' },
  { title: 'Secret', key: 'has_community' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function loadCredentials() {
  // The controller returns a Resource collection ({ data: [...] }) so secrets
  // are masked; unwrap it like the devices page does.
  const res = await api<{ data: SnmpCredential[] }>('/api/snmp-credentials')
  credentials.value = res.data
}

function openCredDialog() {
  credForm.value = emptyCred()
  credError.value = ''
  isCredDialogOpen.value = true
}

async function saveCred() {
  isSavingCred.value = true
  credError.value = ''
  try {
    await api('/api/snmp-credentials', { method: 'POST', body: credForm.value })
    isCredDialogOpen.value = false
    await loadCredentials()
  }
  catch {
    credError.value = 'Could not save. Name must be unique and the secret fields for the chosen version are required.'
  }
  finally {
    isSavingCred.value = false
  }
}

async function removeCred(cred: SnmpCredential) {
  if (!confirm(`Delete SNMP credential "${cred.name}"? Scans that used it keep their results.`))
    return

  await api(`/api/snmp-credentials/${cred.id}`, { method: 'DELETE' })
  await loadCredentials()
}

// ---- New scan -----------------------------------------------------------
const scanForm = ref({ name: '', snmp_credential_id: null as number | null, subnets: '' })
const isStartingScan = ref(false)
const scanError = ref('')

function parseSubnets(raw: string): string[] {
  return raw
    .split(/[\s,]+/)
    .map(s => s.trim())
    .filter(Boolean)
}

async function startScan() {
  isStartingScan.value = true
  scanError.value = ''
  try {
    await api('/api/discovery/scans', {
      method: 'POST',
      body: {
        name: scanForm.value.name || null,
        snmp_credential_id: scanForm.value.snmp_credential_id,
        subnets: parseSubnets(scanForm.value.subnets),
      },
    })
    scanForm.value = { name: '', snmp_credential_id: null, subnets: '' }
    await loadScans()
  }
  catch {
    scanError.value = 'Could not start the scan. Pick a credential and enter at least one valid CIDR (e.g. 10.15.0.0/22).'
  }
  finally {
    isStartingScan.value = false
  }
}

// ---- Scans list + detail ------------------------------------------------
const scans = ref<DiscoveryScan[]>([])
const isLoadingScans = ref(true)

const scanHeaders = [
  { title: 'Name', key: 'name' },
  { title: 'Subnets', key: 'subnets' },
  { title: 'Status', key: 'status' },
  { title: 'Responded', key: 'responded' },
  { title: 'New / Imported', key: 'counts' },
  { title: 'Actions', key: 'actions', sortable: false },
]

const statusColor: Record<string, string> = {
  pending: 'secondary',
  running: 'info',
  completed: 'success',
  failed: 'error',
}

async function loadScans() {
  scans.value = await api<DiscoveryScan[]>('/api/discovery/scans')
  isLoadingScans.value = false
}

// Poll while any scan is still in flight so the operator sees results appear.
let poller: ReturnType<typeof setInterval> | null = null
function ensurePolling() {
  const active = scans.value.some(s => s.status === 'pending' || s.status === 'running')
  if (active && !poller) {
    poller = setInterval(async () => {
      await loadScans()
      if (activeScan.value)
        await openScan(activeScan.value.id)
      if (!scans.value.some(s => s.status === 'pending' || s.status === 'running'))
        stopPolling()
    }, 4000)
  }
}
function stopPolling() {
  if (poller) {
    clearInterval(poller)
    poller = null
  }
}
watch(scans, ensurePolling)

const isDetailOpen = ref(false)
const activeScan = ref<DiscoveryScan | null>(null)
const selected = ref<number[]>([])
const isImporting = ref(false)

const detailHeaders = [
  { title: 'IP', key: 'ip_address' },
  { title: 'Name', key: 'sys_name' },
  { title: 'Vendor', key: 'vendor' },
  { title: 'Model', key: 'model' },
  { title: 'Role', key: 'suggested_role' },
  { title: 'Site', key: 'site' },
  { title: 'Status', key: 'status' },
  { title: '', key: 'actions', sortable: false },
]

async function openScan(id: number) {
  activeScan.value = await api<DiscoveryScan>(`/api/discovery/scans/${id}`)
  // Pre-select every importable (new) device so Import works immediately — the
  // operator can deselect any they don't want, instead of hunting for the
  // checkboxes and finding the button inert.
  selected.value = (activeScan.value.discovered_devices ?? [])
    .filter(d => d.status === 'new')
    .map(d => d.id)
  isDetailOpen.value = true
}

async function openScanFromRow(scan: DiscoveryScan) {
  await openScan(scan.id)
}

async function importSelected() {
  if (!activeScan.value || selected.value.length === 0)
    return

  isImporting.value = true
  try {
    await api(`/api/discovery/scans/${activeScan.value.id}/import`, {
      method: 'POST',
      body: { device_ids: selected.value },
    })
    selected.value = []
    await openScan(activeScan.value.id)
    await loadScans()
  }
  finally {
    isImporting.value = false
  }
}

async function ignoreDevice(deviceId: number) {
  if (!activeScan.value)
    return

  await api(`/api/discovery/discovered/${deviceId}/ignore`, { method: 'POST' })
  await openScan(activeScan.value.id)
}

async function removeScan(scan: DiscoveryScan) {
  if (!confirm(`Delete scan "${scan.name ?? scan.id}" and its discovered list?`))
    return

  await api(`/api/discovery/scans/${scan.id}`, { method: 'DELETE' })
  await loadScans()
}

onMounted(async () => {
  if (!auth.isAdmin) {
    isLoadingScans.value = false

    return
  }
  await Promise.all([loadCredentials(), loadScans()])
})

onBeforeUnmount(stopPolling)
</script>

<template>
  <div v-if="!auth.isAdmin">
    <VAlert type="info" variant="tonal">
      Discovery is an administrator function. Ask an admin to run network discovery.
    </VAlert>
  </div>

  <div v-else class="d-flex flex-column gap-6">
    <div class="mb-n2">
      <h4 class="text-h4 mb-1">Discovery</h4>
      <p class="text-body-2 text-medium-emphasis mb-0">
        Find unmonitored devices — set SNMP credentials, run a scan, then review and import results.
      </p>
    </div>
    <!-- SNMP credentials -->
    <VCard title="SNMP Credentials">
      <template #append>
        <VBtn @click="openCredDialog">
          Add Credential
        </VBtn>
      </template>
      <VDataTable
        :headers="credHeaders"
        :items="credentials"
        density="compact"
      >
        <template #item.snmp_version="{ item }">
          {{ item.snmp_version.toUpperCase() }}
        </template>
        <template #item.has_community="{ item }">
          <VIcon
            :icon="item.has_community || item.has_v3_auth_key ? 'ri-lock-line' : 'ri-lock-unlock-line'"
            size="small"
          />
          set
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon="ri-delete-bin-line"
            variant="text"
            size="small"
            @click="removeCred(item)"
          />
        </template>
      </VDataTable>
    </VCard>

    <!-- New scan -->
    <VCard title="New Discovery Scan">
      <VCardText>
        <VAlert
          v-if="scanError"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ scanError }}
        </VAlert>
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          Convention: hosts ending in <strong>.10</strong> are classified as switches, <strong>.254</strong> as SD-WAN (EdgeConnect). Each /24 (3rd octet) auto-maps to a site.
        </VAlert>
        <VForm @submit.prevent="startScan">
          <VRow>
            <VCol cols="12" sm="4">
              <VTextField
                v-model="scanForm.name"
                label="Scan name (optional)"
                placeholder="Loopback sweep"
              />
            </VCol>
            <VCol cols="12" sm="4">
              <VSelect
                v-model="scanForm.snmp_credential_id"
                :items="credentials"
                item-title="name"
                item-value="id"
                label="SNMP Credential"
              />
            </VCol>
            <VCol cols="12" sm="4">
              <VTextField
                v-model="scanForm.subnets"
                label="Subnets (CIDR, comma/space separated)"
                placeholder="10.15.0.0/22, 10.20.5.0/24"
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                :loading="isStartingScan"
              >
                Start Scan
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>

    <!-- Scans -->
    <VCard title="Scans">
      <template #append>
        <VBtn
          variant="text"
          icon="ri-refresh-line"
          @click="loadScans"
        />
      </template>
      <VDataTable
        :headers="scanHeaders"
        :items="scans"
        :loading="isLoadingScans"
        density="compact"
        hover
        class="scans-table"
        @click:row="(_: unknown, { item }: { item: DiscoveryScan }) => openScanFromRow(item)"
      >
        <template #item.name="{ item }">
          {{ item.name ?? `Scan #${item.id}` }}
        </template>
        <template #item.subnets="{ item }">
          {{ item.subnets.join(', ') }}
        </template>
        <template #item.status="{ item }">
          <VChip
            :color="statusColor[item.status]"
            size="small"
            label
          >
            {{ item.status }}
          </VChip>
        </template>
        <template #item.responded="{ item }">
          {{ item.hosts_responded }} / {{ item.hosts_total }}
        </template>
        <template #item.counts="{ item }">
          {{ item.new_count ?? 0 }} new / {{ item.imported_count ?? 0 }} imported
        </template>
        <template #item.actions="{ item }">
          <VBtn
            icon="ri-delete-bin-line"
            variant="text"
            size="small"
            @click.stop="removeScan(item)"
          />
        </template>
      </VDataTable>
    </VCard>

    <!-- Credential dialog -->
    <VDialog
      v-model="isCredDialogOpen"
      transition="slide-x-reverse-transition"
      content-class="nodus-drawer"
    >
      <VCard title="Add SNMP Credential">
        <VCardText>
          <VAlert
            v-if="credError"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ credError }}
          </VAlert>
          <VForm @submit.prevent="saveCred">
            <VRow>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="credForm.name"
                  label="Name"
                  placeholder="Massey read-only"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="credForm.snmp_version"
                  :items="['v2c', 'v3']"
                  label="SNMP Version"
                />
              </VCol>
              <template v-if="credForm.snmp_version === 'v2c'">
                <VCol cols="12">
                  <VTextField
                    v-model="credForm.snmp_community"
                    label="Community String"
                    type="password"
                    autocomplete="new-password"
                  />
                </VCol>
              </template>
              <template v-else>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="credForm.snmp_v3_username"
                    label="v3 Username"
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="credForm.snmp_v3_auth_key"
                    label="v3 Auth Key (SHA)"
                    type="password"
                    autocomplete="new-password"
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="credForm.snmp_v3_priv_key"
                    label="v3 Priv Key (AES)"
                    type="password"
                    autocomplete="new-password"
                  />
                </VCol>
              </template>
              <VCol cols="12">
                <VBtn
                  type="submit"
                  :loading="isSavingCred"
                >
                  Save
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Scan detail dialog -->
    <VDialog
      v-model="isDetailOpen"
      max-width="1100"
    >
      <VCard v-if="activeScan" :title="activeScan.name ?? `Scan #${activeScan.id}`">
        <template #append>
          <VChip
            :color="statusColor[activeScan.status]"
            size="small"
            label
          >
            {{ activeScan.status }}
          </VChip>
        </template>
        <VCardText>
          <VAlert
            v-if="activeScan.error"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ activeScan.error }}
          </VAlert>
          <div class="d-flex align-center justify-space-between mb-3">
            <div class="text-body-2 text-medium-emphasis">
              {{ activeScan.subnets.join(', ') }} · {{ activeScan.hosts_responded }} / {{ activeScan.hosts_total }} responded
            </div>
            <VBtn
              :disabled="selected.length === 0"
              :loading="isImporting"
              @click="importSelected"
            >
              Import Selected ({{ selected.length }})
            </VBtn>
          </div>
          <VDataTable
            v-model="selected"
            :headers="detailHeaders"
            :items="activeScan.discovered_devices ?? []"
            item-value="id"
            show-select
            density="compact"
          >
            <template #item.sys_name="{ item }">
              {{ item.sys_name ?? '—' }}
            </template>
            <template #item.vendor="{ item }">
              {{ item.vendor ?? '—' }}
            </template>
            <template #item.model="{ item }">
              {{ item.model ?? '—' }}
            </template>
            <template #item.suggested_role="{ item }">
              {{ item.suggested_role ?? '—' }}
            </template>
            <template #item.site="{ item }">
              {{ item.suggested_site?.name ?? '—' }}
            </template>
            <template #item.status="{ item }">
              <VChip
                :color="item.status === 'new' ? 'primary' : item.status === 'imported' ? 'success' : 'secondary'"
                size="x-small"
                label
              >
                {{ item.status }}
              </VChip>
              <span v-if="item.status === 'existing' && item.matched_device" class="text-caption text-medium-emphasis ml-1">
                → {{ item.matched_device.name }}
              </span>
            </template>
            <template #item.actions="{ item }">
              <VBtn
                v-if="item.status === 'new'"
                variant="text"
                size="x-small"
                @click="ignoreDevice(item.id)"
              >
                Ignore
              </VBtn>
            </template>
          </VDataTable>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.scans-table :deep(tbody tr) {
  cursor: pointer;
}
</style>
