<script setup lang="ts">
import type { OrgTopologySite } from '@/types/models'
import { NODE_GLYPH_BOX, nodeGlyphs } from '@/utils/nodeGlyphs'

defineProps<{ site: OrgTopologySite }>()
const emit = defineEmits<{ (e: 'open', id: number): void }>()

const chainIcons = ['cloud', 'gw', 'edge', 'switch'] as const

// A site whose only problems are on devices inside a maintenance window is neither
// red nor green. Green would be a lie — those devices really are down — so it gets
// its own neutral tone and says so in words.
const toneOf = (state: string) =>
  state === 'crit' ? 'error' : state === 'warn' ? 'warning' : state === 'maint' ? 'secondary' : 'success'
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
            :viewBox="`0 0 ${NODE_GLYPH_BOX} ${NODE_GLYPH_BOX}`"
          ><path
            :d="nodeGlyphs[ic]"
            fill="currentColor"
          /></svg>
        </div>
        <div
          v-if="i < chainIcons.length - 1"
          class="chain-link"
          :class="{ dn: site.chain[ic] }"
        />
      </template>
    </div>
    <div class="site-foot">
      <VChip
        size="small"
        :color="toneOf(site.state)"
        variant="tonal"
      >
        {{ site.summary }}
      </VChip>
      <span class="site-foot__count text-caption text-medium-emphasis">{{ site.device_count }} devices</span>
    </div>
  </VCard>
</template>

<style scoped>
/* The summary is a whole sentence ("Site isolated — all 2 WAN circuits down") and
   a Vuetify chip is one fixed-height nowrap line, so it clipped mid-word with
   text-overflow: clip — no ellipsis, just a severed sentence colliding with the
   device count. The chip wraps and grows instead; the count keeps its own space. */
.site-foot { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
.site-foot .v-chip { height: auto; min-height: 24px; min-width: 0; flex: 0 1 auto; }
.site-foot :deep(.v-chip__content) { white-space: normal; overflow: visible; text-overflow: clip; display: block; padding-block: 3px; line-height: 1.35; }
.site-foot__count { flex: 0 0 auto; white-space: nowrap; padding-block-start: 4px; }
.site-card { cursor: pointer; transition: transform .1s, border-color .15s; height: 100%; }
.site-card:hover { transform: translateY(-1px); }
.site-card.crit { border-inline-start: 3px solid rgb(var(--v-theme-error)); }
.site-card.warn { border-inline-start: 3px solid rgb(var(--v-theme-warning)); }
/* Dashed, not solid: planned work, not an incident. The down glyphs in the chain stay
   — the devices are genuinely down — but drained of the alarm red. */
.site-card.maint { border-inline-start: 3px dashed rgba(var(--v-theme-on-surface), 0.45); }
.site-card.maint .chain-node.dn,
.site-card.maint .chain-link.dn { filter: grayscale(1); opacity: .75; }
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
