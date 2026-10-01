<script lang="ts" setup>
import { api } from '@/composables/useApi'

const route = useRoute('osint-cases-id')
const id = computed(() => route.params.id)

interface Ioc { id: number, type: string, value: string, confidence: string, source: string | null, first_seen: string | null }
interface CaseData {
  id: number
  case_number: string
  title: string
  severity: string
  status: string
  summary: string | null
  mitre: string[] | null
  owner?: { name: string } | null
  iocs: Ioc[]
}

const c = ref<CaseData | null>(null)
const loading = ref(true)
async function load() {
  loading.value = true
  try { c.value = await api<CaseData>(`/api/osint/cases/${id.value}`) }
  finally { loading.value = false }
}
onMounted(load)

const sevColor: Record<string, string> = { low: 'info', medium: 'secondary', high: 'warning', critical: 'error' }
const iocColor: Record<string, string> = { domain: 'error', host: 'error', ip: 'warning', phone: 'error', asn: 'warning', cert: 'info', url: 'error' }

async function setStatus(status: string) {
  c.value = await api<CaseData>(`/api/osint/cases/${id.value}/status`, { method: 'POST', body: { status } })
  await load()
}

// ---- run a lookup without leaving the case ----------------------------------
// The investigation continues inside the case rather than back in the workspace,
// so what a pivot turns up lands on the case being worked instead of starting a
// new one.
type Mode = 'domain' | 'ip' | 'phone'
const lookupOpen = ref(false)
const lookupMode = ref<Mode>('domain')
const lookupQuery = ref('')
const lookupBusy = ref(false)
const lookupError = ref('')
const lookupFound = ref<{ type: string, value: string, confidence?: string, source?: string }[]>([])
const lookupNote = ref('')

const lookupPlaceholder = computed(() => ({
  domain: 'masseyservices.nowsso.com',
  ip: '45.133.1.77',
  phone: '+1 (407) 555-0142',
}[lookupMode.value]))

/** Which of the results are already on this case — shown, not silently dropped. */
const alreadyOnCase = computed(() => {
  const have = new Set((c.value?.iocs ?? []).map(i => `${i.type}|${i.value}`))

  return lookupFound.value.filter(i => have.has(`${i.type}|${i.value}`)).length
})

async function runLookup() {
  if (!lookupQuery.value.trim())
    return
  lookupBusy.value = true
  lookupError.value = ''
  lookupNote.value = ''
  lookupFound.value = []
  try {
    const res = await api<{ iocs: typeof lookupFound.value }>(`/api/osint/lookup/${lookupMode.value}`, {
      method: 'POST', body: { target: lookupQuery.value.trim() },
    })

    lookupFound.value = res.iocs ?? []
    if (!lookupFound.value.length)
      lookupNote.value = 'Nothing new turned up for that target.'
  }
  catch (e: any) { lookupError.value = e?.data?.message ?? 'Lookup failed.' }
  finally { lookupBusy.value = false }
}

async function attachFound() {
  lookupBusy.value = true
  try {
    const r = await api<{ added: number, duplicates: number }>(`/api/osint/cases/${id.value}/iocs`, {
      method: 'POST', body: { iocs: lookupFound.value },
    })

    lookupNote.value = r.duplicates
      ? `${r.added} added · ${r.duplicates} already on the case`
      : `${r.added} added`
    lookupFound.value = []
    lookupQuery.value = ''
    await load()
  }
  finally { lookupBusy.value = false }
}

// Add IoC
const addOpen = ref(false)
const newIoc = ref({ type: 'domain', value: '', confidence: 'medium' })
async function addIoc() {
  await api(`/api/osint/cases/${id.value}/iocs`, { method: 'POST', body: newIoc.value })
  addOpen.value = false
  newIoc.value = { type: 'domain', value: '', confidence: 'medium' }
  await load()
}

function exportUrl(format: string) {
  return `/api/osint/cases/${id.value}/export${format === 'stix' ? '?format=stix' : ''}`
}
</script>

<template>
  <div v-if="c">
    <div class="d-flex align-center flex-wrap ga-2 mb-1">
      <VBtn icon="ri-arrow-left-line" variant="text" size="small" :to="{ name: 'osint-cases' }" />
      <span class="ce-mono text-medium-emphasis">{{ c.case_number }}</span>
      <VChip size="small" :color="sevColor[c.severity]" variant="tonal">{{ c.severity }}</VChip>
      <VChip size="small" variant="tonal">{{ c.status }}</VChip>
      <VSpacer />
      <VBtn variant="tonal" size="small" :href="exportUrl('csv')" target="_blank" prepend-icon="ri-file-download-line">CSV</VBtn>
      <VBtn variant="tonal" size="small" :href="exportUrl('stix')" target="_blank" prepend-icon="ri-code-box-line">STIX</VBtn>
    </div>
    <h4 class="text-h4 mb-1">{{ c.title }}</h4>
    <div class="d-flex flex-wrap ga-1 mb-4">
      <VChip v-for="m in (c.mitre ?? [])" :key="m" size="x-small" variant="tonal">MITRE {{ m }}</VChip>
      <span class="text-caption text-medium-emphasis">Owner: {{ c.owner?.name ?? '—' }}</span>
    </div>

    <VRow>
      <VCol cols="12" md="8">
        <VCard>
          <VCardItem>
            <VCardTitle class="text-body-1 d-flex align-center">
              Indicators of Compromise · {{ c.iocs.length }}
              <VSpacer />
              <VBtn size="small" variant="tonal" prepend-icon="ri-search-line" class="me-2" @click="lookupOpen = true">Run lookup</VBtn>
              <VBtn size="small" variant="tonal" prepend-icon="ri-add-line" @click="addOpen = true">Add IoC</VBtn>
            </VCardTitle>
          </VCardItem>
          <VTable>
            <thead><tr><th>Type</th><th>Value</th><th>Confidence</th><th>Source</th></tr></thead>
            <tbody>
              <tr v-for="i in c.iocs" :key="i.id">
                <td><VChip size="x-small" :color="iocColor[i.type] ?? 'secondary'" variant="tonal">{{ i.type }}</VChip></td>
                <td class="ce-mono">{{ i.value }}</td>
                <td>{{ i.confidence }}</td>
                <td class="text-medium-emphasis">{{ i.source ?? '—' }}</td>
              </tr>
              <tr v-if="!c.iocs.length"><td colspan="4" class="text-center text-medium-emphasis py-4">No IoCs.</td></tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
      <VCol cols="12" md="4">
        <VCard>
          <VCardItem><VCardTitle class="text-body-1">Status</VCardTitle></VCardItem>
          <VCardText class="d-flex flex-column ga-2">
            <VBtn v-for="s in ['investigating', 'contained', 'closed']" :key="s" :variant="c.status === s ? 'flat' : 'tonal'" :color="c.status === s ? 'primary' : undefined" size="small" @click="setStatus(s)">
              {{ s }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="lookupOpen" max-width="620">
      <VCard title="Run a lookup on this case">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            Anything found is attached to <b>{{ c?.case_number }}</b>. Indicators already on
            the case are skipped rather than duplicated.
          </p>
          <div class="d-flex ga-2 mb-3">
            <VSelect
              v-model="lookupMode" :items="['domain', 'ip', 'phone']"
              variant="outlined" density="compact" hide-details style="max-inline-size: 130px"
            />
            <VTextField
              v-model="lookupQuery" :placeholder="lookupPlaceholder" variant="outlined"
              density="compact" hide-details class="ce-mono" @keyup.enter="runLookup"
            />
            <VBtn color="primary" :loading="lookupBusy" @click="runLookup">Search</VBtn>
          </div>

          <VAlert v-if="lookupError" type="error" variant="tonal" density="compact" class="mb-3">
            {{ lookupError }}
          </VAlert>
          <VAlert v-else-if="lookupNote" type="info" variant="tonal" density="compact" class="mb-3">
            {{ lookupNote }}
          </VAlert>

          <div v-if="lookupFound.length">
            <div class="text-caption text-medium-emphasis mb-2">
              {{ lookupFound.length }} found<span v-if="alreadyOnCase"> · {{ alreadyOnCase }} already on this case</span>
            </div>
            <div class="d-flex flex-wrap ga-2">
              <VChip v-for="i in lookupFound" :key="i.type + i.value" size="small" class="ce-mono">
                {{ i.type }}: {{ i.value }}
              </VChip>
            </div>
          </div>
        </VCardText>
        <VCardActions class="justify-end pa-4 pt-0">
          <VBtn variant="tonal" @click="lookupOpen = false">Close</VBtn>
          <VBtn color="primary" :disabled="!lookupFound.length" :loading="lookupBusy" @click="attachFound">
            Attach {{ lookupFound.length || '' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog v-model="addOpen" transition="slide-x-reverse-transition" content-class="nodus-drawer nodus-drawer--sm">
      <VCard title="Add IoC">
        <VCardText class="d-flex flex-column ga-3">
          <VSelect v-model="newIoc.type" :items="['domain', 'host', 'ip', 'url', 'email', 'phone', 'asn', 'cert', 'hash']" label="Type" variant="outlined" density="comfortable" hide-details />
          <VTextField v-model="newIoc.value" label="Value" variant="outlined" density="comfortable" hide-details class="ce-mono" />
          <VSelect v-model="newIoc.confidence" :items="['low', 'medium', 'high']" label="Confidence" variant="outlined" density="comfortable" hide-details />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="addOpen = false">Cancel</VBtn>
          <VBtn color="primary" :disabled="!newIoc.value" @click="addIoc">Add</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
  <VProgressLinear v-else-if="loading" indeterminate color="primary" />
</template>

<style scoped>
.ce-mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; }
</style>
