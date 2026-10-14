<script lang="ts" setup>
import { api } from '@/composables/useApi'

interface Integration {
  provider: string
  label: string
  purpose: string
  needs_key: boolean
  configured: boolean
  masked: string | null
  enabled: boolean
  meta: Record<string, any> | null
}

const rows = ref<Integration[]>([])
const drafts = reactive<Record<string, string>>({})
const busy = reactive<Record<string, boolean>>({})
const result = reactive<Record<string, { ok: boolean, message: string } | null>>({})
const loading = ref(true)

async function load() {
  loading.value = true
  try { rows.value = (await api<{ data: Integration[] }>('/api/osint/integrations')).data }
  finally { loading.value = false }
}
onMounted(load)

async function test(p: string) {
  busy[p] = true
  result[p] = null
  try {
    result[p] = await api<{ ok: boolean, message: string }>(`/api/osint/integrations/${p}/test`, {
      method: 'POST', body: { api_key: drafts[p] || undefined },
    })
  }
  catch { result[p] = { ok: false, message: 'Request failed.' } }
  finally { busy[p] = false }
}

async function save(p: string) {
  busy[p] = true
  try {
    await api(`/api/osint/integrations/${p}`, { method: 'POST', body: { api_key: drafts[p] ?? '' } })
    drafts[p] = ''
    result[p] = { ok: true, message: 'Saved.' }
    await load()
  }
  finally { busy[p] = false }
}
</script>

<template>
  <div>
    <div class="d-flex align-center ga-3 mb-1">
      <VIcon icon="ri-settings-4-line" size="24" class="text-primary" />
      <h4 class="text-h4 mb-0">OSINT · Integrations</h4>
      <VChip size="small" color="warning" variant="tonal" prepend-icon="ri-lock-2-line">super-admin</VChip>
    </div>
    <p class="text-body-2 text-medium-emphasis">
      API keys are AES-encrypted at rest (same store as SNMP credentials) and masked after saving — never written to a file or a log.
    </p>

    <VProgressLinear v-if="loading" indeterminate color="primary" class="mb-4" />

    <VCard v-for="r in rows" :key="r.provider" class="mb-3">
      <VCardText>
        <div class="d-flex align-center flex-wrap ga-2 mb-3">
          <span class="text-body-1 font-weight-medium">{{ r.label }}</span>
          <VChip size="x-small" variant="tonal">{{ r.purpose }}</VChip>
          <VSpacer />
          <VChip
            v-if="r.configured"
            size="small"
            color="success"
            variant="tonal"
            prepend-icon="ri-checkbox-circle-line"
          >configured · {{ r.masked }}</VChip>
          <VChip
            v-else-if="r.needs_key"
            size="small"
            color="warning"
            variant="tonal"
          >key required</VChip>
          <VChip
            v-else
            size="small"
            variant="tonal"
          >optional</VChip>
        </div>

        <div class="d-flex align-center ga-2">
          <VTextField
            v-model="drafts[r.provider]"
            :placeholder="r.configured ? 'Replace key…' : (r.needs_key ? 'Paste API key' : 'Paste key — or leave blank for the free tier')"
            type="password"
            density="comfortable"
            variant="outlined"
            hide-details
            autocomplete="off"
          />
          <VBtn
            variant="tonal"
            :loading="busy[r.provider]"
            @click="test(r.provider)"
          >Test</VBtn>
          <VBtn
            color="primary"
            :loading="busy[r.provider]"
            @click="save(r.provider)"
          >Save</VBtn>
        </div>

        <div
          v-if="result[r.provider]"
          class="text-caption mt-2"
          :class="result[r.provider]!.ok ? 'text-success' : 'text-error'"
        >
          <VIcon :icon="result[r.provider]!.ok ? 'ri-checkbox-circle-line' : 'ri-error-warning-line'" size="14" />
          {{ result[r.provider]!.message }}
        </div>
      </VCardText>
    </VCard>

    <VCard variant="tonal" class="mt-4">
      <VCardText class="d-flex align-center ga-3">
        <VIcon icon="ri-terminal-box-line" color="success" />
        <div>
          <div class="font-weight-medium">On-host engine — ready, no key</div>
          <div class="text-caption text-medium-emphasis">whois · dig · subfinder · amass · crt.sh certificate transparency — WHOIS, DNS, subdomains and certs run locally, free.</div>
        </div>
      </VCardText>
    </VCard>
  </div>
</template>
