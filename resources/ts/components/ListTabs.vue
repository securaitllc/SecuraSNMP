<script setup lang="ts">
// Reusable category tab-strip for list pages (Devices / Sites / Circuits) —
// mirrors the Reports page: a coloured dot + label + live count per tab, the
// active tab flush against the surface card below it.
interface Tab { value: string | null, label: string, count?: number, color?: string }
defineProps<{ tabs: Tab[] }>()
const model = defineModel<string | null>()
</script>

<template>
  <div class="list-tabs">
    <button
      v-for="t in tabs"
      :key="t.label"
      type="button"
      class="list-tab"
      :class="{ 'list-tab--on': model === t.value }"
      @click="model = t.value"
    >
      <span v-if="t.color" class="list-tab__dot" :style="{ background: t.color }" />
      {{ t.label }}
      <span v-if="t.count !== undefined" class="list-tab__ct">{{ t.count }}</span>
    </button>
  </div>
</template>

<style scoped>
/* Terminal tabs: caps text on a rule, the active one underlined in the accent.
   Counts are mono and dim — a number, not a badge. */
.list-tabs { display: flex; flex-wrap: wrap; gap: 0; border-block-end: 1px solid var(--nodus-rule); }
.list-tab {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 11px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase;
  color: var(--nodus-ink-dim);
  padding: 9px 0; margin-inline-end: 22px; cursor: pointer;
  border: 0; border-block-end: 2px solid transparent; margin-block-end: -1px;
  background: transparent;
}
.list-tab__dot { inline-size: 7px; block-size: 7px; flex: none; }
.list-tab__ct {
  font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 11px; font-weight: 500; letter-spacing: 0;
  color: var(--nodus-ink-dim);
}
.list-tab--on { color: rgb(var(--v-theme-on-surface)); border-block-end-color: rgb(var(--v-theme-primary)); }
.list-tab--on .list-tab__ct { color: rgb(var(--v-theme-on-surface)); }
</style>
