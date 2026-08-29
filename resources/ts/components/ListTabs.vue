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
.list-tabs { display: flex; flex-wrap: wrap; gap: 4px; }
.list-tab {
  display: inline-flex; align-items: center; gap: 8px;
  font-size: 0.8rem; font-weight: 600;
  color: rgba(var(--v-theme-on-surface), 0.6);
  padding: 9px 14px; cursor: pointer;
  border: 1px solid transparent; border-block-end: 0;
  border-start-start-radius: 10px; border-start-end-radius: 10px;
  background: transparent;
}
.list-tab__dot { inline-size: 7px; block-size: 7px; border-radius: 50%; flex: none; }
.list-tab__ct {
  font-family: ui-monospace, "SF Mono", Menlo, monospace; font-size: 0.68rem;
  color: rgba(var(--v-theme-on-surface), 0.5);
  background: rgba(var(--v-theme-on-surface), 0.06);
  border-radius: 999px; padding: 1px 7px;
}
.list-tab--on {
  color: rgb(var(--v-theme-on-surface));
  background: rgb(var(--v-theme-surface));
  border-color: rgba(var(--v-border-color), var(--v-border-opacity));
}
.list-tab--on .list-tab__ct { color: rgb(var(--v-theme-on-surface)); }
</style>
