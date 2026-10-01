<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { SshCredential } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()

const credentials = ref<SshCredential[]>([])
const isLoading = ref(true)
const isDialogOpen = ref(false)
const isSaving = ref(false)
const editing = ref<SshCredential | null>(null)
const errorMessage = ref('')

function emptyForm() {
  return { name: '', username: '', password: '', notes: '' }
}
const form = ref(emptyForm())

const headers = [
  { title: 'Name', key: 'name' },
  { title: 'Username', key: 'username' },
  { title: 'Password', key: 'has_password' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function loadCredentials() {
  isLoading.value = true
  // Resource collection ({ data: [...] }) keeps the password masked; unwrap it.
  const res = await api<{ data: SshCredential[] }>('/api/ssh-credentials')
  credentials.value = res.data
  isLoading.value = false
}

function openCreateDialog() {
  editing.value = null
  form.value = emptyForm()
  errorMessage.value = ''
  isDialogOpen.value = true
}

function openEditDialog(cred: SshCredential) {
  editing.value = cred
  form.value = { name: cred.name, username: cred.username, password: '', notes: cred.notes ?? '' }
  errorMessage.value = ''
  isDialogOpen.value = true
}

async function save() {
  isSaving.value = true
  errorMessage.value = ''
  try {
    const payload: Record<string, unknown> = { ...form.value }
    // Blank password on edit means "keep the stored secret".
    if (editing.value && payload.password === '')
      delete payload.password

    if (editing.value)
      await api(`/api/ssh-credentials/${editing.value.id}`, { method: 'PUT', body: payload })
    else
      await api('/api/ssh-credentials', { method: 'POST', body: payload })

    isDialogOpen.value = false
    await loadCredentials()
  }
  catch {
    errorMessage.value = 'Could not save. Name must be unique; username and password are required on create.'
  }
  finally {
    isSaving.value = false
  }
}

async function remove(cred: SshCredential) {
  if (!confirm(`Delete SSH credential "${cred.name}"? Devices using it fall back to their inline SSH values.`))
    return

  await api(`/api/ssh-credentials/${cred.id}`, { method: 'DELETE' })
  await loadCredentials()
}

/**
 * Reassigning replaced hardware onto a different profile.
 *
 * Swapping an appliance swaps its login, and doing that one device at a time is both
 * slow and silently wrong when it goes astray: nothing on screen fails, the SSH
 * pollers just stop being able to log in. The candidate list comes from the serial
 * trail rather than from memory — "the ones we replaced" is a fact the platform
 * already holds.
 */
type Replaced = {
  id: number
  name: string
  ip_address: string | null
  role: string | null
  site_name: string | null
  ssh_credential_id: number | null
  ssh_credential_name: string | null
}
type AssignResult = {
  device_id: number
  device_name: string
  site_name?: string | null
  status: 'applied' | 'failed' | 'unchanged'
  reason?: string
  from?: string
  to?: string
}

const bulkOpen = ref(false)
const bulkDays = ref(30)
const bulkTarget = ref<number | null>(null)
const bulkVerify = ref(true)
const bulkLoading = ref(false)
const bulkRunning = ref(false)
const bulkError = ref('')
const replaced = ref<Replaced[]>([])
const picked = ref<number[]>([])
const results = ref<AssignResult[] | null>(null)

const DAY_WINDOWS = [7, 30, 90, 365]

/** Devices already on the chosen profile cannot move; show them, never select them. */
const movable = computed(() => replaced.value.filter(d => d.ssh_credential_id !== bulkTarget.value))

const tally = computed(() => {
  const r = results.value ?? []
  return {
    applied: r.filter(x => x.status === 'applied').length,
    failed: r.filter(x => x.status === 'failed').length,
    unchanged: r.filter(x => x.status === 'unchanged').length,
  }
})

async function openBulk() {
  bulkError.value = ''
  results.value = null
  bulkTarget.value = null
  bulkOpen.value = true
  await loadReplaced()
}

async function loadReplaced() {
  bulkLoading.value = true
  try {
    const r = await api<{ data: Replaced[] }>(`/api/devices/replaced?days=${bulkDays.value}`)
    replaced.value = r.data
    picked.value = r.data.map(d => d.id)
  }
  catch {
    bulkError.value = 'Could not load the replaced-device list.'
  }
  finally {
    bulkLoading.value = false
  }
}

watch(bulkDays, loadReplaced)
// Devices already on the target are not candidates; drop them from the selection
// so the count on the button is the number that will actually move.
watch(bulkTarget, () => {
  picked.value = picked.value.filter(id => movable.value.some(d => d.id === id))
})

/**
 * A verified run is bounded by the gateway, not by the server's appetite: nginx
 * gives up at 120s and an unreachable device costs the full connect timeout, so
 * the API caps a verified batch at twelve. Chunking here means the operator picks
 * however many they like and still gets one combined report.
 */
const VERIFIED_CHUNK = 12

const bulkProgress = ref({ done: 0, total: 0 })

async function runBulk() {
  if (!bulkTarget.value || picked.value.length === 0)
    return

  bulkRunning.value = true
  bulkError.value = ''
  results.value = null

  const size = bulkVerify.value ? VERIFIED_CHUNK : picked.value.length
  const chunks: number[][] = []
  for (let i = 0; i < picked.value.length; i += size)
    chunks.push(picked.value.slice(i, i + size))

  bulkProgress.value = { done: 0, total: picked.value.length }
  const collected: AssignResult[] = []

  try {
    for (const chunk of chunks) {
      const res = await api<{ results: AssignResult[] }>('/api/devices/bulk-ssh-credential', {
        method: 'POST',
        body: { device_ids: chunk, ssh_credential_id: bulkTarget.value, verify: bulkVerify.value },
      })
      collected.push(...res.results)
      bulkProgress.value.done += chunk.length
      // Show the report as it fills so a long verified run is not a blank wait.
      results.value = [...collected]
    }
    await loadReplaced()
  }
  catch (e: any) {
    bulkError.value = e?.data?.message ?? 'The reassignment could not be completed.'
    // Whatever already came back still happened — each device commits on its own,
    // so a failure partway through never invalidates the devices already reported.
    if (collected.length)
      results.value = [...collected]
  }
  finally {
    bulkRunning.value = false
    bulkProgress.value = { done: 0, total: 0 }
  }
}

onMounted(() => {
  if (!auth.isAdmin) {
    isLoading.value = false

    return
  }
  loadCredentials()
})
</script>

<template>
  <div>
    <div class="page-head">
      <div>
        <h4 class="text-h4 mb-1">SSH Credentials</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Reusable SSH login profiles the pollers use to reach appliances.</p>
      </div>
      <div v-if="auth.isAdmin" class="d-flex ga-2">
        <VBtn variant="tonal" prepend-icon="ri-swap-line" @click="openBulk">
          Reassign Devices
        </VBtn>
        <VBtn @click="openCreateDialog">
          Add Credential
        </VBtn>
      </div>
    </div>

  <div v-if="!auth.isAdmin">
    <VAlert type="info" variant="tonal">
      SSH credentials are an administrator function.
    </VAlert>
  </div>

  <VCard v-else>
    <VCardText class="pb-0">
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
      >
        Store an SSH username/password once, then select it on each device's form under “SSH Credential”. Devices without a link fall back to their own inline SSH values.
      </VAlert>
    </VCardText>

    <VDataTable
      :headers="headers"
      :items="credentials"
      :loading="isLoading"
      density="compact"
    >
      <template #item.has_password="{ item }">
        <VIcon icon="ri-lock-line" size="small" /> set
      </template>
      <template #item.actions="{ item }">
        <VBtn
          icon="ri-edit-line"
          variant="text"
          size="small"
          @click="openEditDialog(item)"
        />
        <VBtn
          icon="ri-delete-bin-line"
          variant="text"
          size="small"
          @click="remove(item)"
        />
      </template>
    </VDataTable>
  </VCard>

  <VDialog
    v-model="bulkOpen"
    transition="slide-x-reverse-transition"
    content-class="nodus-drawer"
  >
    <VCard title="Reassign Replaced Devices">
      <VCardText>
        <VAlert type="info" variant="tonal" density="compact" class="mb-4">
          These are the devices whose <strong>serial number changed</strong> — the hardware that
          was physically swapped. Each one is logged into with the new credential before its
          record is changed, so a wrong profile cannot quietly stop the pollers reaching it.
        </VAlert>

        <VAlert v-if="bulkError" type="error" variant="tonal" density="compact" class="mb-4">
          {{ bulkError }}
        </VAlert>

        <VRow class="mb-1">
          <VCol cols="12" sm="6">
            <VSelect
              v-model="bulkTarget"
              :items="credentials.map(c => ({ value: c.id, title: `${c.name} (${c.username})` }))"
              label="Move them onto"
              placeholder="Choose a credential profile"
              density="comfortable"
            />
          </VCol>
          <VCol cols="12" sm="6">
            <VSelect
              v-model="bulkDays"
              :items="DAY_WINDOWS.map(d => ({ value: d, title: `Replaced in the last ${d} days` }))"
              label="Window"
              density="comfortable"
            />
          </VCol>
        </VRow>

        <VCheckbox
          v-model="bulkVerify"
          density="compact"
          label="Log in to each device first, and only change the ones that answer"
          hide-details
          class="mb-1"
        />
        <p v-if="!bulkVerify" class="text-caption text-warning mb-3">
          Without verification the record is changed whether or not the credential works.
          Use this only for devices that are unreachable right now and whose new login you already know.
        </p>
        <div v-else class="text-caption text-medium-emphasis mb-3">
          Each device is logged into in turn, and an unreachable one costs up to 8 seconds.
          Larger selections are sent in batches of {{ VERIFIED_CHUNK }} so a long run cannot
          outlast the gateway; the report fills in as each batch comes back.
        </div>

        <VProgressLinear v-if="bulkLoading" indeterminate class="mb-3" />

        <div v-else-if="!replaced.length" class="text-body-2 text-medium-emphasis mb-3">
          No device has had its serial change in this window. Widen it, or there is nothing to reassign.
        </div>

        <template v-else>
          <div class="d-flex align-center justify-space-between mb-2">
            <span class="text-body-2 font-weight-medium">
              {{ movable.length }} candidate{{ movable.length === 1 ? '' : 's' }}
              <span v-if="replaced.length !== movable.length" class="text-medium-emphasis">
                · {{ replaced.length - movable.length }} already on this profile
              </span>
            </span>
            <div class="d-flex ga-1">
              <VBtn size="x-small" variant="text" @click="picked = movable.map(d => d.id)">
                All
              </VBtn>
              <VBtn size="x-small" variant="text" @click="picked = []">
                None
              </VBtn>
            </div>
          </div>

          <div class="dev-list mb-4">
            <label
              v-for="d in replaced" :key="d.id" class="dev-row"
              :class="{ 'dev-row--off': d.ssh_credential_id === bulkTarget }"
            >
              <input
                v-model="picked" type="checkbox" :value="d.id"
                :disabled="d.ssh_credential_id === bulkTarget"
              >
              <span class="min-w-0">
                <span class="d-block text-body-2 text-truncate">{{ d.name }}</span>
                <span class="d-block text-caption text-disabled text-truncate">
                  {{ d.site_name }} · {{ d.ip_address }} · now on {{ d.ssh_credential_name ?? 'inline credential' }}
                </span>
              </span>
            </label>
          </div>
        </template>

        <template v-if="results">
          <VDivider class="mb-3" />
          <div class="d-flex ga-4 mb-3 text-body-2">
            <span class="text-success">{{ tally.applied }} changed</span>
            <span :class="tally.failed ? 'text-error' : 'text-disabled'">{{ tally.failed }} failed</span>
            <span class="text-disabled">{{ tally.unchanged }} unchanged</span>
          </div>
          <div class="dev-list mb-2">
            <div v-for="r in results" :key="r.device_id" class="dev-row">
              <VIcon
                :icon="r.status === 'applied' ? 'ri-check-line' : r.status === 'failed' ? 'ri-close-line' : 'ri-subtract-line'"
                :color="r.status === 'applied' ? 'success' : r.status === 'failed' ? 'error' : undefined"
                size="small"
              />
              <span class="min-w-0">
                <span class="d-block text-body-2 text-truncate">{{ r.device_name }}</span>
                <span class="d-block text-caption text-disabled text-truncate">
                  {{ r.reason ?? `${r.from ?? 'inline credential'} → ${r.to}` }}
                </span>
              </span>
            </div>
          </div>
          <p v-if="tally.failed" class="text-caption text-medium-emphasis">
            Devices that failed were left on the credential they had. Fix the login or the
            reachability and run this again — nothing needs undoing.
          </p>
        </template>
      </VCardText>

      <VCardActions class="px-4 pb-4">
        <VSpacer />
        <VBtn variant="tonal" @click="bulkOpen = false">
          Close
        </VBtn>
        <VBtn
          color="primary"
          :loading="bulkRunning"
          :disabled="!bulkTarget || !picked.length"
          @click="runBulk"
        >
          <template v-if="bulkRunning && bulkProgress.total">
            {{ bulkProgress.done }} / {{ bulkProgress.total }}
          </template>
          <template v-else>
            {{ bulkVerify ? 'Verify and reassign' : 'Reassign' }} {{ picked.length || '' }}
          </template>
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isDialogOpen"
    transition="slide-x-reverse-transition"
    content-class="nodus-drawer"
  >
    <VCard :title="editing ? 'Edit SSH Credential' : 'Add SSH Credential'">
      <VCardText>
        <VAlert
          v-if="errorMessage"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ errorMessage }}
        </VAlert>

        <VForm @submit.prevent="save">
          <VRow>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.name"
                label="Name"
                placeholder="Massey NOC"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.username"
                label="SSH Username"
                autocomplete="off"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.password"
                label="SSH Password / Key"
                type="password"
                autocomplete="new-password"
                :placeholder="editing ? 'Leave blank to keep current' : ''"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                label="Notes"
                rows="2"
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                :loading="isSaving"
              >
                Save
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </VDialog>
  </div>
</template>

<style lang="scss" scoped>
.dev-list {
  max-block-size: 300px;
  overflow-y: auto;
  border: 1px solid rgba(var(--v-theme-on-surface), .12);
  border-radius: 4px;
}
.dev-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 10px;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .07);
  cursor: pointer;

  &:last-child { border-block-end: 0; }
  &--off { cursor: default; opacity: .5; }
}
.min-w-0 { min-inline-size: 0; }
</style>
