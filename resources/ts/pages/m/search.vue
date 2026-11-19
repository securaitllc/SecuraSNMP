<script lang="ts" setup>
import { useDebounceFn } from '@vueuse/core'
import { api } from '@/composables/useApi'

definePage({ meta: { layout: 'mobile', title: 'Find' } })

interface Hit { type: string, id?: number, label: string, sub?: string, route: string }

const q = ref('')
const hits = ref<Hit[]>([])
const searching = ref(false)

// The desktop search returns desktop routes; map the ones a phone can show.
function toMobile(h: Hit): string | null {
  if (h.id && (h.type === 'device' || h.type === 'site' || h.type === 'circuit'))
    return `/m/${h.type}/${h.id}`
  let m = h.route.match(/^\/devices\/(\d+)/)
  if (m)
    return `/m/device/${m[1]}`
  m = h.route.match(/^\/sites\/(\d+)/) ?? h.route.match(/[?&]site_id=(\d+)/)
  if (m)
    return `/m/site/${m[1]}`
  m = h.route.match(/^\/circuits\/(\d+)/)
  if (m)
    return `/m/circuit/${m[1]}`

  return null
}

const run = useDebounceFn(async () => {
  const term = q.value.trim()
  if (term.length < 2) {
    hits.value = []

    return
  }
  searching.value = true
  try {
    hits.value = await api<Hit[]>(`/api/search?q=${encodeURIComponent(term)}`)
  }
  finally {
    searching.value = false
  }
}, 250)

watch(q, run)

// Circuits come back keyed by circuit_id text, not an id route — resolve by site
// when that is all we have.
const shown = computed(() => hits.value.map(h => ({ ...h, to: toMobile(h) })))
</script>

<template>
  <div>
    <VTextField
      v-model="q"
      placeholder="Site, device, IP, circuit, MAC…"
      prepend-inner-icon="ri-search-line"
      variant="outlined"
      density="comfortable"
      autofocus
      clearable
      inputmode="search"
      autocapitalize="off"
      autocorrect="off"
      :loading="searching"
    />

    <div
      v-if="shown.length"
      class="m-list"
    >
      <component
        :is="h.to ? 'RouterLink' : 'div'"
        v-for="(h, i) in shown"
        :key="i"
        :to="h.to ?? undefined"
        class="m-row"
      >
        <span class="bar" />
        <span class="main">
          <span class="t">{{ h.label }}</span>
          <span class="s">{{ h.type }}<template v-if="h.sub"> · {{ h.sub }}</template></span>
        </span>
        <span class="meta"><VIcon
          v-if="h.to"
          icon="ri-arrow-right-s-line"
        /></span>
      </component>
    </div>
    <div
      v-else-if="q.trim().length >= 2 && !searching"
      class="m-empty"
    >
      Nothing matches.
    </div>
    <div
      v-else-if="!q"
      class="m-empty"
    >
      Type at least two characters.
    </div>
  </div>
</template>
