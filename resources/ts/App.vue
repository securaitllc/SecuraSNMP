<script setup lang="ts">
import { useTheme } from 'vuetify'
import { Toaster } from 'vue-sonner'
import ScrollToTop from '@core/components/ScrollToTop.vue'
import initCore from '@core/initCore'
import { initConfigStore, useConfigStore } from '@core/stores/config'
import { hexToRgb } from '@core/utils/colorConverter'
import 'vue-sonner/style.css'

const { global } = useTheme()

// Toasts follow the app theme so they don't flash a light card on a NOC wall
// display running dark.
const toasterTheme = computed(() => (global.name.value.includes('dark') ? 'dark' : 'light'))

// ℹ️ Sync current theme with initial loader theme
initCore()
initConfigStore()

const configStore = useConfigStore()
</script>

<template>
  <VLocaleProvider :rtl="configStore.isAppRTL">
    <!-- ℹ️ This is required to set the background color of active nav link based on currently active global theme's primary -->
    <VApp :style="`--v-global-theme-primary: ${hexToRgb(global.current.value.colors.primary)}`">
      <RouterView />

      <!-- Action feedback only (ack / clear / dispatch / save). Alarms live in the
           alarm list and the persistent banner — never in a toast that auto-hides. -->
      <Toaster
        position="top-right"
        rich-colors
        close-button
        :theme="toasterTheme"
        :duration="5000"
      />

      <ScrollToTop />
    </VApp>
  </VLocaleProvider>
</template>
