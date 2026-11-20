<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'

definePage({ meta: { layout: 'default' } })

const auth = useAuthStore()

// One circuit per line: "SITE  IP  interface" (whitespace or tab separated).
//   AL0001-SC208   24.192.96.1   wan0
const raw = ref('')
const busy = ref(false)
const errorMessage = ref('')
const result = ref<null | {
  dry_run: boolean
  created_count: number, created: string[]
  skipped_existing_count: number, skipped_existing: string[]
  unmatched_site_count: number, unmatched_site: string[]
}>(null)

const parsed = computed(() => {
  const out: { site: string, ip: string, interface: string }[] = []
  const bad: string[] = []
  for (const line of raw.value.split('\n')) {
    const t = line.trim()
    if (!t)
      continue
    // site  ip  interface  (tabs or spaces between)
    const m = t.match(/^(\S.*?)\s+(\d{1,3}(?:\.\d{1,3}){3})\s+(\S+)\s*$/)
    if (m)
      out.push({ site: m[1].trim(), ip: m[2], interface: m[3].trim().toLowerCase() })
    else
      bad.push(t)
  }
  return { circuits: out, bad }
})

async function run(dryRun: boolean) {
  errorMessage.value = ''
  if (!parsed.value.circuits.length) {
    errorMessage.value = 'Nothing to import — paste at least one "SITE  IP  interface" line.'
    return
  }
  busy.value = true
  try {
    result.value = await api('/api/circuits/import', {
      method: 'POST',
      body: { circuits: parsed.value.circuits, dry_run: dryRun },
    })
  }
  catch (e: any) {
    const d = e?.data
    const firstErr = d?.errors ? Object.values(d.errors).flat()[0] as string : null
    errorMessage.value = firstErr || d?.message || e?.message || 'Import failed — check the fields and try again.'
  }
  finally {
    busy.value = false
  }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h4 class="text-h4 mb-1">
        Circuit Import
      </h4>
      <span class="text-body-2 text-medium-emphasis">
        Bulk-add circuits to start monitoring the WAN IP immediately — the CID, carrier and ISP
        provider are filled in later on each circuit. Match by the <code>SC</code> number in the
        site name. Format per line: <code>SITE&nbsp;&nbsp;IP&nbsp;&nbsp;interface</code>.
      </span>
    </div>

    <VAlert
      v-if="!auth.isAdmin"
      type="warning"
      variant="tonal"
    >
      Circuit import is admin-only.
    </VAlert>

    <VRow v-else>
      <VCol cols="12" md="7">
        <VCard class="pa-4">
          <VTextarea
            v-model="raw"
            label="Circuits — one per line: SITE  IP  interface"
            placeholder="AL0001-SC208   24.192.96.1   wan0&#10;AL0001-SC208   4.1.250.49    wan1&#10;FL0003-SC03    131.148.4.41  wan0"
            rows="12"
            class="mono-area"
            auto-grow
          />
          <div class="text-caption text-medium-emphasis mt-1">
            Parsed {{ parsed.circuits.length }} circuit(s)<span v-if="parsed.bad.length" class="text-warning">
              · {{ parsed.bad.length }} line(s) not recognised</span>.
          </div>
        </VCard>
      </VCol>

      <VCol cols="12" md="5">
        <VCard class="pa-4">
          <VAlert type="info" variant="tonal" density="compact" class="mb-3">
            New circuits start monitoring right away (ICMP). A <code>192.168.x.x</code> IP is
            flagged <strong>DHCP</strong> automatically — refine those afterwards. CID / carrier /
            ISP provider stay <code>Pending</code> until you add them.
          </VAlert>

          <VAlert
            v-if="errorMessage"
            type="error"
            variant="tonal"
            class="mb-3"
          >
            {{ errorMessage }}
          </VAlert>

          <div class="d-flex ga-2">
            <VBtn
              variant="tonal"
              :loading="busy"
              :disabled="!parsed.circuits.length"
              @click="run(true)"
            >
              Preview (dry run)
            </VBtn>
            <VBtn
              color="primary"
              :loading="busy"
              :disabled="!parsed.circuits.length"
              @click="run(false)"
            >
              Import {{ parsed.circuits.length }} circuit(s)
            </VBtn>
          </div>
        </VCard>
      </VCol>

      <VCol v-if="result" cols="12">
        <VCard class="pa-4">
          <div class="d-flex align-center ga-2 mb-3">
            <VChip :color="result.dry_run ? 'info' : 'success'" size="small" variant="tonal">
              {{ result.dry_run ? 'Dry run — nothing written' : 'Imported' }}
            </VChip>
            <span class="text-body-2">
              {{ result.created_count }} {{ result.dry_run ? 'would be created' : 'created' }} ·
              {{ result.skipped_existing_count }} already present ·
              {{ result.unmatched_site_count }} no matching site
            </span>
          </div>
          <VRow>
            <VCol
              v-for="col in [
                { title: result.dry_run ? 'Would create' : 'Created', items: result.created, color: 'success' },
                { title: 'Skipped (already present)', items: result.skipped_existing, color: 'medium-emphasis' },
                { title: 'No matching site', items: result.unmatched_site, color: 'warning' },
              ]"
              :key="col.title"
              cols="12"
              md="4"
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
