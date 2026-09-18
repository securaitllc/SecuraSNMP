<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'

definePage({ meta: { layout: 'default' } })

const auth = useAuthStore()

// One device per line: "NAME (10.200.5.10)" or "NAME [10.200.5.10]" or "NAME 10.200.5.10".
const raw = ref('')
const role = ref('switch')
const vendor = ref('juniper')
const model = ref('')
const snmpVersion = ref('v2c')
const snmpCommunity = ref('')
const sshCredentialId = ref<number | null>(null)
const fallbackGeneral = ref(true)

// Must match the devices table enums exactly (role, vendor, snmp_version) —
// an out-of-enum value is rejected by the real DB.
const roles = ['switch', 'edgeconnect', 'firewall']
const vendors = ['juniper', 'silverpeak', 'fortigate']
const credentials = ref<{ id: number, name: string }[]>([])

const busy = ref(false)
const errorMessage = ref('')
const result = ref<null | {
  dry_run: boolean
  created_count: number, created: string[]
  skipped_existing_count: number, skipped_existing: string[]
  unmatched_site_count: number, unmatched_site: string[]
  general_count: number, general: string[]
}>(null)

// Parse the pasted block into {name, ip}. Tolerates (), [], or a bare trailing IP.
const parsed = computed(() => {
  const out: { name: string, ip: string }[] = []
  const bad: string[] = []
  for (const line of raw.value.split('\n')) {
    const t = line.trim()
    if (!t)
      continue
    const m = t.match(/^(.*?)[\s([]*\(?\[?\s*(\d{1,3}(?:\.\d{1,3}){3})\s*\)?\]?\s*$/)
    if (m && m[1].trim())
      out.push({ name: m[1].trim().replace(/[[(]$/, '').trim(), ip: m[2] })
    else
      bad.push(t)
  }
  return { devices: out, bad }
})

async function loadCreds() {
  try {
    const res = await api<{ data: { id: number, name: string }[] }>('/api/ssh-credentials')
    credentials.value = res.data
  }
  catch { /* viewer / not authorised — leave empty */ }
}

async function run(dryRun: boolean) {
  errorMessage.value = ''
  if (!parsed.value.devices.length) {
    errorMessage.value = 'Nothing to import — paste at least one "NAME (IP)" line.'
    return
  }
  busy.value = true
  try {
    result.value = await api('/api/devices/import', {
      method: 'POST',
      body: {
        devices: parsed.value.devices,
        role: role.value,
        vendor: vendor.value,
        model: model.value || null,
        snmp_version: snmpVersion.value,
        snmp_community: snmpCommunity.value || null,
        ssh_credential_id: sshCredentialId.value,
        fallback_general: fallbackGeneral.value,
        dry_run: dryRun,
      },
    })
  }
  catch (e: any) {
    // Surface the real server error (validation message / first field error)
    // instead of a blanket "failed" so the operator can actually fix the input.
    const d = e?.data
    const firstErr = d?.errors ? Object.values(d.errors).flat()[0] as string : null
    errorMessage.value = firstErr || d?.message || e?.message || 'Import failed — check the fields and try again.'
  }
  finally {
    busy.value = false
  }
}

onMounted(loadCreds)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap ga-2">
      <div>
        <h4 class="text-h4 mb-1">
          Device Import
        </h4>
        <span class="text-body-2 text-medium-emphasis">
          Bulk-add devices from a pasted list. Each device homes to its site by the
          <code>SC</code> number in its name (e.g. <code>…SC208…</code> → site #208).
        </span>
      </div>
    </div>

    <VAlert
      v-if="!auth.isAdmin"
      type="warning"
      variant="tonal"
    >
      Device import is admin-only.
    </VAlert>

    <VRow v-else>
      <VCol
        cols="12"
        md="7"
      >
        <VCard class="pa-4">
          <VTextarea
            v-model="raw"
            label="Devices — one per line: NAME (10.200.5.10)"
            placeholder="SITE01-SWA001 (10.0.10.10)&#10;SITE02-SWA001 [10.0.20.10]"
            rows="12"
            class="mono-area"
            auto-grow
          />
          <div class="text-caption text-medium-emphasis mt-1">
            Parsed {{ parsed.devices.length }} device(s)<span v-if="parsed.bad.length" class="text-warning">
              · {{ parsed.bad.length }} line(s) not recognised</span>.
          </div>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="5"
      >
        <VCard class="pa-4">
          <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-3">
            Applied to every imported device
          </div>
          <VRow dense>
            <VCol cols="6">
              <VSelect
                v-model="role"
                :items="roles"
                label="Role"
                density="comfortable"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="vendor"
                :items="vendors"
                label="Vendor"
                density="comfortable"
              />
            </VCol>
            <VCol cols="6">
              <VSelect
                v-model="snmpVersion"
                :items="['v2c', 'v3']"
                label="SNMP version"
                density="comfortable"
              />
            </VCol>
            <VCol cols="6">
              <VTextField
                v-model="model"
                label="Model (optional)"
                placeholder="EX3400"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="snmpCommunity"
                label="SNMP community"
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VSelect
                v-model="sshCredentialId"
                :items="credentials"
                item-title="name"
                item-value="id"
                label="SSH credential"
                clearable
                density="comfortable"
              />
            </VCol>
            <VCol cols="12">
              <VCheckbox
                v-model="fallbackGeneral"
                density="comfortable"
                hide-details
                label="Park unmatched devices in a General site (reassign later)"
              />
            </VCol>
          </VRow>

          <VAlert
            v-if="errorMessage"
            type="error"
            variant="tonal"
            class="mt-3"
          >
            {{ errorMessage }}
          </VAlert>

          <div class="d-flex ga-2 mt-4">
            <VBtn
              variant="tonal"
              :loading="busy"
              :disabled="!parsed.devices.length"
              @click="run(true)"
            >
              Preview (dry run)
            </VBtn>
            <VBtn
              color="primary"
              :loading="busy"
              :disabled="!parsed.devices.length"
              @click="run(false)"
            >
              Import {{ parsed.devices.length }} device(s)
            </VBtn>
          </div>
        </VCard>
      </VCol>

      <VCol
        v-if="result"
        cols="12"
      >
        <VCard class="pa-4">
          <div class="d-flex align-center ga-2 mb-3">
            <VChip
              :color="result.dry_run ? 'info' : 'success'"
              size="small"
              variant="tonal"
            >
              {{ result.dry_run ? 'Dry run — nothing written' : 'Imported' }}
            </VChip>
            <span class="text-body-2">
              {{ result.created_count }} {{ result.dry_run ? 'would be created' : 'created' }} ·
              {{ result.general_count }} to General ·
              {{ result.skipped_existing_count }} already present ·
              {{ result.unmatched_site_count }} no matching site
            </span>
          </div>
          <VRow>
            <VCol
              v-for="col in [
                { title: result.dry_run ? 'Would create' : 'Created', items: result.created, color: 'success' },
                { title: 'General (unassigned)', items: result.general, color: 'info' },
                { title: 'Skipped (already present)', items: result.skipped_existing, color: 'medium-emphasis' },
                { title: 'No matching site', items: result.unmatched_site, color: 'warning' },
              ]"
              :key="col.title"
              cols="12"
              md="3"
            >
              <div class="text-caption text-uppercase font-weight-medium mb-1" :class="`text-${col.color}`">
                {{ col.title }} ({{ col.items.length }})
              </div>
              <div class="result-list mono">
                <div v-for="n in col.items" :key="n">
                  {{ n }}
                </div>
                <div v-if="!col.items.length" class="text-medium-emphasis">
                  —
                </div>
              </div>
            </VCol>
          </VRow>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.mono-area :deep(textarea) { font-family: ui-monospace, Menlo, monospace; font-size: 12.5px; line-height: 1.5; }
.result-list { max-height: 260px; overflow-y: auto; font-size: 12.5px; }
.mono { font-family: ui-monospace, Menlo, monospace; }
</style>
