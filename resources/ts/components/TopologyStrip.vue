<script setup lang="ts">
import { api } from '@/composables/useApi'
import type { Topology, TopologyNode } from '@/types/models'

const props = withDefaults(defineProps<{
  siteId: number
  /**
   * Show the root-cause banner. Off when the surrounding dialog already states the
   * diagnosis — printing it in both places is how one outage came to be described
   * three times on the same screen.
   */
  showDiagnosis?: boolean
}>(), { showDiagnosis: true })

// The caller can render the diagnosis itself rather than duplicating this one.
const emit = defineEmits<{ (e: 'loaded', incident: Topology['incident'] | null): void }>()

const topo = ref<Topology | null>(null)
const loading = ref(false)

async function load(id: number) {
  loading.value = true
  topo.value = null
  try {
    topo.value = await api<Topology>(`/api/sites/${id}/topology`)
    emit('loaded', topo.value?.incident ?? null)
  }
  finally {
    loading.value = false
  }
}
watch(() => props.siteId, id => id && load(id), { immediate: true })

// Nodes laid out left→right by their dependency column (0 ISP … 4 firewall).
const columns = computed(() => {
  const byCol: Record<number, TopologyNode[]> = {}
  for (const n of topo.value?.nodes ?? [])
    (byCol[n.col] ??= []).push(n)

  return Object.keys(byCol).map(Number).sort((a, b) => a - b).map(c => byCol[c])
})

const statusColor: Record<string, string> = { up: 'success', warn: 'warning', down: 'error' }
const glyph: Record<string, string> = {
  cloud: 'ri-cloud-line', nexthop: 'ri-arrow-left-right-line', gw: 'ri-arrow-left-right-line',
  edge: 'ri-router-line', switch: 'ri-git-merge-line', fw: 'ri-shield-check-line', device: 'ri-hard-drive-2-line',
}
</script>

<template>
  <div class="topo-strip">
    <div
      v-if="loading"
      class="text-caption text-medium-emphasis py-2"
    >
      Loading topology…
    </div>

    <template v-else-if="topo">
      <!-- root-cause + remediation for this site -->
      <VAlert
        v-if="props.showDiagnosis && topo.incident.active"
        :type="topo.incident.symptoms.length && topo.incident.root_type === 'access' ? 'warning' : 'error'"
        variant="tonal"
        density="compact"
        class="mb-3"
      >
        <div class="font-weight-medium">
          {{ topo.incident.summary }}
        </div>
        <div
          v-if="topo.incident.symptoms.length"
          class="text-caption"
        >
          symptoms: {{ topo.incident.symptoms.join(' · ') }}
        </div>
        <div
          v-if="topo.incident.action"
          class="text-body-2 mt-1"
        >
          {{ topo.incident.action }}
        </div>
        <div
          v-if="topo.incident.support_phone"
          class="d-flex align-center ga-1 mt-1"
        >
          <VIcon
            icon="ri-phone-line"
            size="18"
          />
          <span class="font-weight-bold">Call ISP: {{ topo.incident.support_phone }}</span>
        </div>
      </VAlert>

      <!-- compact dependency chain -->
      <div class="strip-flow">
        <template
          v-for="(col, ci) in columns"
          :key="ci"
        >
          <VIcon
            v-if="ci > 0"
            icon="ri-arrow-right-s-line"
            size="18"
            class="strip-arrow"
          />
          <div class="strip-col">
            <div
              v-for="n in col"
              :key="n.id"
              class="strip-node"
              :class="`is-${n.status}`"
            >
              <VIcon
                :icon="glyph[n.type] ?? 'ri-circle-line'"
                size="15"
              />
              <div class="strip-node-text">
                <div class="strip-name">
                  {{ n.label }}
                </div>
                <div class="strip-sub">
                  {{ n.sub }}
                </div>
              </div>
              <span
                class="strip-dot"
                :style="{ backgroundColor: `rgb(var(--v-theme-${statusColor[n.status] ?? 'success'}))` }"
              />
            </div>
          </div>
        </template>
      </div>

      <RouterLink
        :to="`/topology?site=${siteId}`"
        class="text-caption text-primary d-inline-block mt-2 text-decoration-none"
      >
        Open full topology →
      </RouterLink>
    </template>
  </div>
</template>

<style scoped>
.strip-flow {
  display: flex; align-items: center; gap: 3px; overflow-x: auto; padding-bottom: 6px;
}
.strip-col { display: flex; flex-direction: column; gap: 6px; flex: 1 1 0; min-width: 0; }
.strip-arrow { color: rgba(var(--v-theme-on-surface), 0.35); flex: 0 0 auto; }
.strip-node {
  display: flex; align-items: center; gap: 8px; min-width: 0;
  padding: 6px 8px; border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.03);
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
}
.strip-node.is-warn { border-color: rgba(var(--v-theme-warning), 0.6); }
.strip-node.is-down { border-color: rgba(var(--v-theme-error), 0.65); background: rgba(var(--v-theme-error), 0.06); }
.strip-node-text { min-width: 0; flex: 1; }
.strip-name { font-size: 12.5px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
.strip-sub { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.55); font-family: ui-monospace, Menlo, monospace; white-space: nowrap; }
.strip-dot { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; }
</style>
