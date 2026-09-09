<script lang="ts" setup>
import navItemsRaw from '@/navigation/horizontal'
import { useAlertsStore } from '@/stores/alerts'
import { useAuthStore } from '@/stores/auth'

import { themeConfig } from '@themeConfig'

// Super-admin-only items (OSINT) are hidden from everyone else; the API enforces
// the same gate server-side. Menus are filtered recursively.
const auth = useAuthStore()
const navItems = computed(() => {
  const keep = (i: any) => !i.superAdmin || auth.isSuperAdmin

  return navItemsRaw.filter(keep).map((i: any) => i.children ? { ...i, children: i.children.filter(keep) } : i)
})

// Global refresh of the shared live feed (bell + dashboard read it).
const alertsStore = useAlertsStore()
const refreshing = ref(false)
async function refreshNow() {
  refreshing.value = true
  try { await alertsStore.refresh() }
  finally { refreshing.value = false }
}

// The bar carries the clock in the operator's timezone; everything in this app
// is decided on Eastern time, so the bar says so.
const clock = ref('')
let clockTimer: ReturnType<typeof setInterval> | null = null
function tick() {
  clock.value = new Intl.DateTimeFormat('en-US', {
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
    timeZone: 'America/New_York', timeZoneName: 'short',
  }).format(new Date())
}
onMounted(() => { tick(); clockTimer = setInterval(tick, 1000) })
onBeforeUnmount(() => { if (clockTimer) clearInterval(clockTimer) })

// Components
import Footer from '@/layouts/components/Footer.vue'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import UserProfile from '@/layouts/components/UserProfile.vue'
import NavBarI18n from '@core/components/I18n.vue'
import { HorizontalNavLayout } from '@layouts'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
</script>

<template>
  <HorizontalNavLayout :nav-items="navItems">
    <!-- 👉 navbar -->
    <template #navbar>
      <RouterLink
        to="/"
        class="app-logo"
      >
        <VNodeRenderer :nodes="themeConfig.app.logo" />

        <h1 class="app-logo-title leading-normal">
          {{ themeConfig.app.title }}
        </h1>
      </RouterLink>
      <VSpacer />

      <NavBarI18n
        v-if="themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length"
        :languages="themeConfig.app.i18n.langConfig"
      />

      <!-- Fleet + clock: the two facts the bar is read for from across a room. -->
      <div class="tb-facts mono d-none d-md-flex">
        <span v-if="alertsStore.counts">{{ alertsStore.counts.sites }} SITES · {{ alertsStore.counts.devices }} DEVICES</span>
        <span>{{ clock }}</span>
      </div>
      <IconBtn
        class="me-1"
        title="Refresh now — the live feed also auto-refreshes every 30s"
        :loading="refreshing"
        @click="refreshNow"
      >
        <VIcon icon="ri-refresh-line" />
      </IconBtn>
      <NavBarNotifications class="me-2" />
      <NavbarThemeSwitcher class="me-1" />
      <UserProfile />
    </template>

    <!-- 👉 Pages -->
    <slot />

    <!-- 👉 Footer -->
    <template #footer>
      <Footer />
    </template>

    <!-- 👉 Customizer -->
    <!-- <TheCustomizer /> -->
  </HorizontalNavLayout>
</template>

<style lang="scss" scoped>
.tb-facts {
  display: flex;
  align-items: center;
  gap: 18px;
  margin-inline-end: 14px;
  font-size: 11px;
  letter-spacing: .04em;
  color: rgba(var(--v-theme-on-surface), .5);
}

.app-logo {
  display: flex;
  align-items: center;
  column-gap: 0.5rem;

  .app-logo-title {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .06em;
    line-height: 1;
    text-transform: uppercase;
  }
}
</style>
