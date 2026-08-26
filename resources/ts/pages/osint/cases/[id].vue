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

    <VDialog v-model="addOpen" max-width="460">
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
