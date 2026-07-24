<script setup lang="ts">
import { api } from '@/composables/useApi'
import type { SearchResult } from '@/types/models'

const router = useRouter()
const open = ref(false)
const query = ref('')
const results = ref<SearchResult[]>([])

const typeIcon: Record<string, string> = {
  device: 'ri-router-line',
  circuit: 'ri-signal-tower-line',
  site: 'ri-map-pin-line',
  ticket: 'ri-coupon-3-line',
  alarm: 'ri-alarm-warning-line',
}

function onKeydown(e: KeyboardEvent) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    open.value = true
  }
}

let timer: ReturnType<typeof setTimeout> | null = null
watch(query, (q) => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(async () => {
    results.value = q.trim().length >= 2 ? await api<SearchResult[]>(`/api/search?q=${encodeURIComponent(q)}`) : []
  }, 180)
})

watch(open, (v) => {
  if (!v) { query.value = ''; results.value = [] }
})

function go(r: SearchResult) {
  open.value = false
  router.push(r.route)
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <VDialog v-model="open" max-width="600" scrollable>
    <VCard>
      <VTextField
        v-model="query"
        autofocus
        placeholder="Search devices, circuits, sites…"
        prepend-inner-icon="ri-search-line"
        variant="solo"
        flat
        hide-details
        density="comfortable"
      />
      <VDivider />
      <VList v-if="results.length" lines="two" max-height="360">
        <VListItem
          v-for="r in results"
          :key="`${r.type}-${r.label}`"
          :prepend-icon="typeIcon[r.type]"
          :title="r.label"
          :subtitle="r.sub ?? r.type"
          @click="go(r)"
        >
          <template #append>
            <span class="text-caption text-medium-emphasis text-capitalize">{{ r.type }}</span>
          </template>
        </VListItem>
      </VList>
      <VCardText v-else-if="query.length >= 2" class="text-medium-emphasis">
        No matches.
      </VCardText>
      <VCardText v-else class="text-medium-emphasis text-caption">
        Type to search. Press <kbd>Ctrl/⌘ K</kbd> anywhere to open this.
      </VCardText>
    </VCard>
  </VDialog>
</template>
