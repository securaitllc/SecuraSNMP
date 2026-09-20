<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const version = ref('')
const latest = ref('')
const updateAvailable = ref(false)

onMounted(async () => {
  try {
    const v = await api<{ version: string }>('/api/version')
    version.value = v.version
  }
  catch { /* non-fatal */ }

  // Admins get an "update available" indicator (needs GITHUB_TOKEN configured).
  if (auth.isAdmin) {
    try {
      const u = await api<{ latest: string, update_available: boolean }>('/api/updates/check')
      latest.value = u.latest
      updateAvailable.value = u.update_available
    }
    catch { /* not configured — ignore */ }
  }
})
</script>

<template>
  <div class="h-100 d-flex align-center justify-space-between text-medium-emphasis">
    <!-- 👉 Footer: left content -->
    <div class="d-flex align-center text-base">
      &copy;
      {{ new Date().getFullYear() }},
      Made by
      <a
        href="https://securait.net"
        target="_blank"
        rel="noopener noreferrer"
        class="text-primary mx-1"
      >SecuraIT.net</a>
      with
      <VIcon
        icon="ri-heart-line"
        color="error"
        size="1.25rem"
        class="mx-1"
      />
      by wisef0x
    </div>

    <div class="d-flex align-center gap-2">
      <a
        v-if="updateAvailable"
        href="https://github.com/securaitllc/SecuraSNMP/releases"
        target="_blank"
        rel="noopener noreferrer"
        class="text-decoration-none"
      >
        <VChip color="warning" size="small" label prepend-icon="ri-download-2-line">
          Update available: v{{ latest }}
        </VChip>
      </a>
      <span v-if="version" class="text-disabled text-caption">v{{ version }}</span>
    </div>
  </div>
</template>
