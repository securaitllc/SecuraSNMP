<script lang="ts" setup>
import navItems from '@/navigation/vertical'
import { useConfigStore } from '@core/stores/config'
import { useAlertsStore } from '@/stores/alerts'
import { themeConfig } from '@themeConfig'

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'
import NavBarI18n from '@core/components/I18n.vue'

// @layouts plugin
import { VerticalNavLayout } from '@layouts'

const configStore = useConfigStore()

// Global refresh of the shared live feed (bell + dashboard read it), sitting next to
// the bell. The feed also auto-refreshes every 30s on its own.
const alertsStore = useAlertsStore()
const refreshing = ref(false)
async function refreshNow() {
  refreshing.value = true
  try { await alertsStore.refresh() }
  finally { refreshing.value = false }
}

// ℹ️ Provide animation name for vertical nav collapse icon.
const verticalNavHeaderActionAnimationName = ref<'rotate-180' | 'rotate-back-180' | null>(null)

watch([
  () => configStore.isVerticalNavCollapsed,
  () => configStore.isAppRTL,
], val => {
  if (configStore.isAppRTL)
    verticalNavHeaderActionAnimationName.value = val[0] ? 'rotate-back-180' : 'rotate-180'
  else
    verticalNavHeaderActionAnimationName.value = val[0] ? 'rotate-180' : 'rotate-back-180'
}, { immediate: true })
</script>

<template>
  <VerticalNavLayout :nav-items="navItems">
    <!-- 👉 navbar -->
    <template #navbar="{ toggleVerticalOverlayNavActive }">
      <div class="d-flex h-100 align-center">
        <IconBtn
          id="vertical-nav-toggle-btn"
          class="ms-n2 d-lg-none"
          @click="toggleVerticalOverlayNavActive(true)"
        >
          <VIcon icon="ri-menu-line" />
        </IconBtn>

        <NavbarThemeSwitcher />

        <VSpacer />

        <NavBarI18n
          v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
          :languages="themeConfig.app.i18n.langConfig"
        />
        <span class="d-none d-md-inline text-caption text-medium-emphasis me-1">Auto-refresh 30s</span>
        <IconBtn
          class="me-1"
          title="Refresh now — the live feed also auto-refreshes every 30s"
          :loading="refreshing"
          @click="refreshNow"
        >
          <VIcon icon="ri-refresh-line" />
        </IconBtn>
        <NavBarNotifications class="me-2" />
        <UserProfile />
      </div>
    </template>

    <!-- 👉 Pages -->
    <slot />

    <!-- 👉 Footer -->
    <template #footer>
      <Footer />
    </template>

    <!-- 👉 Customizer -->
    <!-- <TheCustomizer /> -->
  </VerticalNavLayout>
</template>

<style lang="scss">
@keyframes rotate-180 {
  from { transform: rotate(0deg); }
  to { transform: rotate(180deg); }
}

@keyframes rotate-back-180 {
  from { transform: rotate(180deg); }
  to { transform: rotate(0deg); }
}

.layout-vertical-nav {
  .nav-header {
    .header-action {
      animation-duration: 0.35s;
      animation-fill-mode: forwards;
      animation-name: v-bind(verticalNavHeaderActionAnimationName);
      transform: rotate(0deg);
    }
  }

  // The Nodus mark SVG ships with a viewBox but no width/height, so it collapses
  // to 0×0 and only the wordmark shows — which then vanishes in the collapsed
  // rail. Pin it to a fixed square so the mark is always visible, expanded or mini.
  .app-logo svg {
    inline-size: 1.75rem;
    block-size: 1.75rem;
  }
}
</style>
