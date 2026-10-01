<script setup lang="ts">
// Inline copy-to-clipboard for ticket numbers etc. — shows the value and a copy
// icon; click copies the raw `text` (defaults to the shown label) and flashes a
// check. Handy for pasting an ISP ticket into a carrier portal or an email.
const props = defineProps<{ text: string, label?: string }>()
const copied = ref(false)

async function copy() {
  try {
    if (navigator.clipboard?.writeText)
      await navigator.clipboard.writeText(props.text)
    else {
      // Fallback for non-secure contexts.
      const ta = document.createElement('textarea')
      ta.value = props.text
      ta.style.position = 'fixed'
      ta.style.opacity = '0'
      document.body.appendChild(ta)
      ta.select()
      document.execCommand('copy')
      ta.remove()
    }
    copied.value = true
    setTimeout(() => (copied.value = false), 1200)
  }
  catch { /* clipboard blocked — no-op */ }
}
</script>

<template>
  <button
    type="button"
    class="copy-btn"
    :class="{ 'is-copied': copied }"
    :title="copied ? 'Copied' : `Copy ${text}`"
    @click.stop="copy"
  >
    <span class="copy-btn__text">{{ label ?? text }}</span>
    <VIcon :icon="copied ? 'ri-check-line' : 'ri-file-copy-line'" size="12" />
  </button>
</template>

<style scoped>
.copy-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font: inherit;
  color: inherit;
  background: transparent;
  border: 0;
  padding: 0;
  cursor: pointer;
  border-radius: 4px;
}
.copy-btn:hover { color: rgb(var(--v-theme-primary)); }
.copy-btn.is-copied { color: rgb(var(--v-theme-success)); }
.copy-btn .v-icon { opacity: 0.55; }
.copy-btn:hover .v-icon,
.copy-btn.is-copied .v-icon { opacity: 1; }
</style>
