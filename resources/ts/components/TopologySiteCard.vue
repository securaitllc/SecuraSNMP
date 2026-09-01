<script setup lang="ts">
import type { OrgTopologySite } from '@/types/models'

defineProps<{ site: OrgTopologySite }>()
const emit = defineEmits<{ (e: 'open', id: number): void }>()

const glyphs: Record<string, string> = {
  cloud: 'M6 15a4 4 0 0 1 .3-8A5.5 5.5 0 0 1 17 8.5a3.5 3.5 0 0 1-.5 6.9z',
  gw: 'M4 8h12M4 8l3-3M4 8l3 3M16 14H4M16 14l-3-3M16 14l-3 3',
  edge: 'M3 6h14v8H3zM6 9v2M10 9v2M14 9v2M8 3v3M12 3v3',
  switch: 'M2 7h16v6H2zM5 10h1M8 10h1M11 10h1M14 10h1',
}
const chainIcons = ['cloud', 'gw', 'edge', 'switch'] as const
</script>

<template>
  <VCard
    class="site-card pa-4"
    :class="site.state"
    @click="emit('open', site.id)"
  >
    <div class="font-weight-medium d-flex align-center ga-2">
      <span>{{ site.name }}</span>
      <VChip
        v-if="site.hub_inferred"
        size="x-small"
        variant="tonal"
        color="info"
        title="Hub inferred from the branch's SD-WAN tunnels — no hub is set on this site."
      >
        auto-homed
      </VChip>
    </div>
    <div class="text-caption text-medium-emphasis mono">
      {{ site.address ?? '—' }}
    </div>
    <div class="chain my-3">
      <template
        v-for="(ic, i) in chainIcons"
        :key="ic"
      >
        <div
          class="chain-node"
          :class="{ dn: site.chain[ic] }"
        >
          <svg
            width="15"
            height="15"
            viewBox="0 0 20 20"
          ><path
            :d="glyphs[ic]"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
          /></svg>
        </div>
        <div
          v-if="i < chainIcons.length - 1"
          class="chain-link"
          :class="{ dn: site.chain[ic] }"
        />
      </template>
    </div>
    <div class="d-flex align-center justify-space-between">
      <VChip
        size="small"
        :color="site.state === 'crit' ? 'error' : site.state === 'warn' ? 'warning' : 'success'"
        variant="tonal"
      >
        {{ site.summary }}
      </VChip>
      <span class="text-caption text-medium-emphasis">{{ site.device_count }} devices</span>
    </div>
  </VCard>
</template>

<style scoped>
.site-card { cursor: pointer; transition: transform .1s, border-color .15s; height: 100%; }
.site-card:hover { transform: translateY(-1px); }
.site-card.crit { border-inline-start: 3px solid rgb(var(--v-theme-error)); }
.site-card.warn { border-inline-start: 3px solid rgb(var(--v-theme-warning)); }
.mono { font-family: ui-monospace, Menlo, monospace; }
.chain { display: flex; align-items: center; gap: 5px; }
.chain-node {
  width: 26px; height: 26px; border-radius: 7px; display: grid; place-items: center;
  background: rgba(var(--v-theme-on-surface), 0.04); border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  color: rgba(var(--v-theme-on-surface), 0.55);
}
.chain-node.dn { border-color: rgba(var(--v-theme-error), 0.5); background: rgba(var(--v-theme-error), 0.12); color: rgb(var(--v-theme-error)); }
.chain-link { flex: 1; height: 2px; border-radius: 2px; background: rgba(var(--v-theme-on-surface), 0.2); }
.chain-link.dn { background: rgb(var(--v-theme-error)); }
</style>
