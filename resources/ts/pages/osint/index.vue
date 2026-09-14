<script lang="ts" setup>
import { api } from '@/composables/useApi'

type Mode = 'domain' | 'ip' | 'phone'
interface Ioc { type: string, value: string, confidence?: string, source?: string }

const router = useRouter()
const mode = ref<Mode>('domain')
const query = ref('')
const loading = ref(false)
const error = ref('')
const result = ref<any>(null)
const collected = ref<Ioc[]>([])

const placeholder = computed(() => ({
  domain: 'masseyservices.nowsso.com',
  ip: '45.133.1.77',
  phone: '+1 (407) 555-0142',
}[mode.value]))

const verdictColor: Record<string, string> = { malicious: 'error', suspicious: 'warning', clean: 'success' }

async function run() {
  if (!query.value.trim())
    return
  loading.value = true
  error.value = ''
  result.value = null
  try {
    const res = await api<{ result: any, iocs: Ioc[] }>(`/api/osint/lookup/${mode.value}`, {
      method: 'POST', body: { target: query.value.trim() },
    })
    result.value = res.result
    for (const i of res.iocs ?? []) {
      if (!collected.value.some(c => c.type === i.type && c.value === i.value))
        collected.value.push(i)
    }
  }
  catch (e: any) { error.value = e?.data?.message ?? 'Lookup failed.' }
  finally { loading.value = false }
}

function removeIoc(i: Ioc) {
  collected.value = collected.value.filter(c => !(c.type === i.type && c.value === i.value))
}

// Where the collected indicators go: a new case, or one already open.
//
// Before this, "Create case" was the only exit — so a second round of searching
// produced a second case rather than enriching the one being worked.
const openCases = ref<{ id: number, case_number: string, title: string }[]>([])
const attachOpen = ref(false)
const attachTo = ref<number | null>(null)
const attaching = ref(false)
const attachNote = ref('')

async function loadOpenCases() {
  const rows = await api<any[]>('/api/osint/cases')

  openCases.value = (Array.isArray(rows) ? rows : rows?.data ?? [])
    .filter((c: any) => c.status !== 'closed')
    .map((c: any) => ({ id: c.id, case_number: c.case_number, title: c.title }))
}

async function attachToCase() {
  if (!attachTo.value)
    return
  attaching.value = true
  attachNote.value = ''
  try {
    const r = await api<{ added: number, duplicates: number }>(
      `/api/osint/cases/${attachTo.value}/iocs`,
      { method: 'POST', body: { iocs: collected.value } },
    )

    // Say what actually happened — "2 added, 5 already on the case" beats a bare tick.
    attachNote.value = r.duplicates
      ? `${r.added} added · ${r.duplicates} already on the case`
      : `${r.added} added`
    collected.value = []
    attachOpen.value = false
    router.push({ name: 'osint-cases-id', params: { id: attachTo.value } })
  }
  finally { attaching.value = false }
}

// Create case
const caseDialog = ref(false)
const caseForm = ref({ title: '', severity: 'high', mitre: ['T1566'] as string[] })
const savingCase = ref(false)
function openCase() {
  caseForm.value.title = result.value?.host ? `Investigation — ${result.value.host}` : 'OSINT investigation'
  caseDialog.value = true
}
async function createCase() {
  savingCase.value = true
  try {
    const c = await api<{ id: number }>('/api/osint/cases', {
      method: 'POST',
      body: { ...caseForm.value, iocs: collected.value },
    })
    caseDialog.value = false
    router.push({ name: 'osint-cases-id', params: { id: c.id } })
  }
  finally { savingCase.value = false }
}
</script>

<template>
  <div>
    <div class="d-flex align-center flex-wrap ga-3 mb-1">
      <VIcon icon="ri-search-eye-line" size="24" class="text-info" />
      <h4 class="text-h4 mb-0">OSINT Console</h4>
      <VChip size="small" color="warning" variant="tonal" prepend-icon="ri-lock-2-line">super-admin</VChip>
      <VSpacer />
      <VBtn variant="text" prepend-icon="ri-folder-shield-2-line" :to="{ name: 'osint-cases' }">Cases</VBtn>
      <VBtn variant="text" prepend-icon="ri-settings-4-line" :to="{ name: 'osint-settings' }">Integrations</VBtn>
    </div>
    <p class="text-body-2 text-medium-emphasis">Investigate a domain, IP or phone — collect IoCs, open a case. Every lookup is audit-logged.</p>

    <VCard class="mb-4">
      <VCardText>
        <VTabs v-model="mode" density="comfortable" color="primary" class="mb-3">
          <VTab value="domain">Domain</VTab>
          <VTab value="ip">IP</VTab>
          <VTab value="phone">Phone</VTab>
        </VTabs>
        <div class="d-flex ga-2">
          <VTextField
            v-model="query"
            :placeholder="placeholder"
            density="comfortable"
            variant="outlined"
            hide-details
            class="ce-mono"
            @keydown.enter="run"
          />
          <VBtn color="primary" size="large" :loading="loading" prepend-icon="ri-search-line" @click="run">Investigate</VBtn>
        </div>
        <VAlert v-if="error" type="error" variant="tonal" density="compact" class="mt-3">{{ error }}</VAlert>
      </VCardText>
    </VCard>

    <!-- Domain result -->
    <template v-if="result && mode === 'domain'">
      <VAlert
        :type="result.risk.verdict === 'clean' ? 'success' : (result.risk.verdict === 'malicious' ? 'error' : 'warning')"
        variant="tonal"
        class="mb-4"
      >
        <div class="d-flex align-center">
          <div>
            <div class="font-weight-medium text-capitalize">{{ result.risk.verdict }} — risk {{ result.risk.score }}/100</div>
            <div class="text-caption">{{ result.risk.reasons.join(' · ') || 'No strong signals' }}</div>
          </div>
        </div>
      </VAlert>

      <VRow>
        <VCol cols="12" md="4">
          <VCard height="100%">
            <VCardItem><VCardTitle class="text-body-1">WHOIS</VCardTitle></VCardItem>
            <VCardText>
              <div class="osint-kv"><span>Registrar</span><span>{{ result.whois.registrar ?? '—' }}</span></div>
              <div class="osint-kv"><span>Created</span><span :class="{ 'text-error': result.whois.created_days !== null && result.whois.created_days <= 30 }">{{ result.whois.created ?? '—' }}</span></div>
              <div class="osint-kv"><span>Name servers</span><span class="ce-mono">{{ result.whois.nameservers?.join(', ') || '—' }}</span></div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard height="100%">
            <VCardItem><VCardTitle class="text-body-1">DNS</VCardTitle></VCardItem>
            <VCardText>
              <div class="osint-kv"><span>A</span><span class="ce-mono">{{ result.dns.a?.join(', ') || '—' }}</span></div>
              <div class="osint-kv"><span>MX</span><span class="ce-mono">{{ result.dns.mx?.join(', ') || '—' }}</span></div>
              <div class="osint-kv"><span>NS</span><span class="ce-mono">{{ result.dns.ns?.join(', ') || '—' }}</span></div>
              <div class="osint-kv"><span>TXT</span><span class="ce-mono text-truncate d-inline-block" style="max-width: 60%">{{ result.dns.txt?.join(' ') || '—' }}</span></div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard height="100%">
            <VCardItem><VCardTitle class="text-body-1">Hosting</VCardTitle></VCardItem>
            <VCardText>
              <template v-if="result.ip_enrichment?.configured">
                <div class="osint-kv"><span>IP</span><span class="ce-mono">{{ result.ip_enrichment.ip }}</span></div>
                <div class="osint-kv"><span>ASN</span><span>AS{{ result.ip_enrichment.asn }} · {{ result.ip_enrichment.org }}</span></div>
                <div class="osint-kv"><span>Geo</span><span>{{ result.ip_enrichment.city }}, {{ result.ip_enrichment.country }}</span></div>
                <div class="osint-kv"><span>Flags</span><span>
                  <VChip v-if="result.ip_enrichment.is_threat" size="x-small" color="error">threat</VChip>
                  <VChip v-if="result.ip_enrichment.is_proxy" size="x-small" color="warning">proxy</VChip>
                  <VChip v-if="result.ip_enrichment.is_tor" size="x-small" color="warning">tor</VChip>
                  <span v-if="!result.ip_enrichment.is_threat && !result.ip_enrichment.is_proxy">—</span>
                </span></div>
              </template>
              <div v-else class="text-caption text-medium-emphasis">
                <span v-if="result.ip_enrichment && !result.ip_enrichment.configured">
                  Add an ipdata key in <RouterLink :to="{ name: 'osint-settings' }">Integrations</RouterLink> to enrich the host IP.
                </span>
                <span v-else>No host IP resolved for this domain (no A record).</span>
              </div>
            </VCardText>
          </VCard>
        </VCol>
        <VCol cols="12">
          <VCard>
            <VCardItem><VCardTitle class="text-body-1">Subdomains · {{ result.subdomains.length }}</VCardTitle></VCardItem>
            <VCardText>
              <div v-if="!result.subdomains.length" class="d-flex align-center flex-wrap ga-2 mb-1">
                <span class="text-caption text-medium-emphasis">None found via CT/passive sources.</span>
                <VChip
                  v-for="(status, src) in (result.sub_sources || {})"
                  :key="src"
                  size="x-small"
                  :color="String(status).startsWith('ok') ? 'success' : 'warning'"
                  variant="tonal"
                >{{ src }}: {{ status }}</VChip>
              </div>
              <div class="d-flex flex-wrap ga-2">
                <VChip v-for="s in result.subdomains" :key="s.name" size="small" variant="tonal" class="ce-mono">
                  {{ s.name }} <span class="text-medium-emphasis ml-1">· {{ s.source }}</span>
                </VChip>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- IP result -->
    <VCard v-if="result && mode === 'ip'">
      <VCardText>
        <div v-if="!result.configured" class="text-medium-emphasis">
          Add an ipdata key in <RouterLink :to="{ name: 'osint-settings' }">Integrations</RouterLink> to run IP lookups.
        </div>
        <template v-else>
          <div class="osint-kv"><span>IP</span><span class="ce-mono">{{ result.ip }}</span></div>
          <div class="osint-kv"><span>ASN</span><span>AS{{ result.asn }} · {{ result.org }}</span></div>
          <div class="osint-kv"><span>Geo</span><span>{{ result.city }}, {{ result.country }}</span></div>
          <div class="osint-kv"><span>Flags</span><span>
            <VChip v-if="result.is_threat" size="x-small" color="error">threat</VChip>
            <VChip v-if="result.is_proxy" size="x-small" color="warning">proxy</VChip>
            <VChip v-if="result.is_vpn" size="x-small" color="warning">vpn</VChip>
            <VChip v-if="result.is_tor" size="x-small" color="warning">tor</VChip>
          </span></div>
        </template>
      </VCardText>
    </VCard>

    <!-- Phone result -->
    <VCard v-if="result && mode === 'phone'">
      <VCardText>
        <div v-if="!result.configured" class="text-medium-emphasis">
          Add a reverse-phone provider key in <RouterLink :to="{ name: 'osint-settings' }">Integrations</RouterLink>.
        </div>
        <template v-else>
          <VAlert :type="result.verdict === 'clean' ? 'success' : (result.verdict === 'malicious' ? 'error' : 'warning')" variant="tonal" density="compact" class="mb-3">
            <span class="text-capitalize">{{ result.verdict }}</span> — fraud score {{ result.fraud_score }}
          </VAlert>
          <div class="osint-kv"><span>Line type</span><span :class="{ 'text-error': result.is_voip }">{{ result.line_type }}{{ result.is_voip ? ' (VoIP)' : '' }}</span></div>
          <div class="osint-kv"><span>Carrier</span><span>{{ result.carrier ?? '—' }}</span></div>
          <div class="osint-kv"><span>Region</span><span>{{ result.region }} {{ result.country }}</span></div>
          <div class="osint-kv"><span>Recent abuse</span><span :class="{ 'text-error': result.recent_abuse }">{{ result.recent_abuse ? 'yes' : 'no' }}</span></div>
        </template>
      </VCardText>
    </VCard>

    <!-- IoC tray -->
    <VDialog v-model="attachOpen" max-width="460">
      <VCard title="Add to an existing case">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ collected.length }} indicator{{ collected.length === 1 ? '' : 's' }} will be attached.
            Anything already on the case is skipped.
          </p>
          <VSelect
            v-model="attachTo" :items="openCases" item-value="id"
            :item-title="(c: any) => `${c.case_number} · ${c.title}`"
            label="Open case" variant="outlined" density="comfortable"
            no-data-text="No open cases yet"
          />
        </VCardText>
        <VCardActions class="justify-end pa-4 pt-0">
          <VBtn variant="tonal" @click="attachOpen = false">Cancel</VBtn>
          <VBtn color="primary" :loading="attaching" :disabled="!attachTo" @click="attachToCase">Attach</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VCard v-if="collected.length" class="mt-4" color="surface" variant="flat">
      <VCardText class="d-flex align-center flex-wrap ga-2">
        <span class="text-caption text-uppercase text-medium-emphasis">Collected IoCs</span>
        <VChip v-for="i in collected" :key="i.type + i.value" size="small" closable class="ce-mono" @click:close="removeIoc(i)">
          {{ i.type }} · {{ i.value }}
        </VChip>
        <VSpacer />
        <VBtn
          variant="tonal" prepend-icon="ri-folder-shared-line" class="me-2"
          @click="loadOpenCases(); attachOpen = true"
        >
          Add to existing case
        </VBtn>
        <VBtn color="primary" prepend-icon="ri-folder-add-line" @click="openCase">Create case · {{ collected.length }} IoCs</VBtn>
      </VCardText>
    </VCard>

    <VDialog v-model="caseDialog" transition="slide-x-reverse-transition" content-class="nodus-drawer">
      <VCard title="Create case">
        <VCardText class="d-flex flex-column ga-3">
          <VTextField v-model="caseForm.title" label="Title" variant="outlined" density="comfortable" hide-details />
          <VSelect v-model="caseForm.severity" :items="['low', 'medium', 'high', 'critical']" label="Severity" variant="outlined" density="comfortable" hide-details />
          <VCombobox v-model="caseForm.mitre" label="MITRE ATT&CK" multiple chips variant="outlined" density="comfortable" hint="e.g. T1566, T1598" persistent-hint />
          <div class="text-caption text-medium-emphasis">{{ collected.length }} IoCs will be attached.</div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="caseDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="savingCase" @click="createCase">Create</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.osint-kv { display: flex; justify-content: space-between; gap: 12px; padding: 4px 0; font-size: 13px; }
.osint-kv > span:first-child { color: rgba(var(--v-theme-on-surface), 0.6); }
.ce-mono :deep(input), .ce-mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; }
</style>
