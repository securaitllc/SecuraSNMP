<script lang="ts" setup>
import { useRoute, useRouter } from 'vue-router'
import { useOnline } from '@vueuse/core'
import { mobileTitle } from '@/composables/useMobile'
import { useAuthStore } from '@/stores/auth'

/**
 * Phone shell: one title bar, the page, one bottom tab bar. Everything a thumb
 * can reach; nothing that needs a mouse. Pages under /m/* opt in with
 * definePage({ meta: { layout: 'mobile' } }).
 */
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const online = useOnline()

const { injectSkinClasses } = useSkins()
injectSkinClasses()

const tabs = [
  { to: '/m/alarms', icon: 'ri-alarm-warning-line', label: 'Alarms' },
  { to: '/m', icon: 'ri-dashboard-line', label: 'Status' },
  { to: '/m/search', icon: 'ri-search-line', label: 'Find' },
  { to: '/m/settings', icon: 'ri-settings-3-line', label: 'Me' },
]

const current = computed(() => {
  const p = route.path
  if (p === '/m' || p === '/m/')
    return '/m'
  const hit = tabs.find(t => t.to !== '/m' && p.startsWith(t.to))

  return hit?.to ?? null
})

// Detail screens (site/device/circuit) show a back arrow instead of a tab.
const isDetail = computed(() => /^\/m\/(site|device|circuit)\//.test(route.path))
const title = computed(() => mobileTitle.value ?? (route.meta.title as string | undefined) ?? 'Nodus')
watch(() => route.path, () => { mobileTitle.value = null })

function back() {
  if (window.history.length > 1)
    router.back()
  else
    router.push('/m/alarms')
}
</script>

<template>
  <div class="m-shell">
    <header class="m-top">
      <VBtn
        v-if="isDetail"
        icon="ri-arrow-left-line"
        variant="text"
        size="small"
        aria-label="Back"
        @click="back"
      />
      <span
        v-else
        class="m-brand"
      >NODUS</span>
      <span class="m-title">{{ title }}</span>
      <span
        class="m-net"
        :class="online ? 'is-on' : 'is-off'"
        :title="online ? 'Online' : 'Offline — nothing shown is live'"
      >{{ online ? 'LIVE' : 'OFFLINE' }}</span>
    </header>

    <main class="m-page">
      <RouterView v-slot="{ Component }">
        <Suspense :timeout="0">
          <Component :is="Component" />
        </Suspense>
      </RouterView>
    </main>

    <nav
      v-if="auth.isAuthenticated"
      class="m-tabs"
    >
      <RouterLink
        v-for="t in tabs"
        :key="t.to"
        :to="t.to"
        class="m-tab"
        :class="{ 'is-active': current === t.to }"
      >
        <VIcon
          :icon="t.icon"
          size="22"
        />
        <span>{{ t.label }}</span>
      </RouterLink>
    </nav>
  </div>
</template>

<style lang="scss">
// Phone shell + the shared vocabulary the /m pages use. Terminal rules apply:
// Plex type, 1px rules, no radius, severity is the only colour that means anything.
.m-shell {
  display: flex;
  flex-direction: column;
  min-block-size: 100dvh;
  background: rgb(var(--v-theme-background));
  color: rgb(var(--v-theme-on-background));
  font-family: 'IBM Plex Sans', 'Helvetica Neue', Arial, system-ui, sans-serif;
  -webkit-tap-highlight-color: transparent;
}

.m-top {
  position: sticky;
  inset-block-start: 0;
  z-index: 5;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 12px;
  padding-block-start: env(safe-area-inset-top);
  block-size: calc(48px + env(safe-area-inset-top));
  border-block-end: 1px solid var(--nodus-rule);
  background: rgb(var(--v-theme-surface));

  .m-brand {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 12px;
    letter-spacing: .12em;
    color: var(--nodus-ink-dim);
  }

  .m-title {
    flex: 1;
    font-weight: 600;
    font-size: 15px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .m-net {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 10px;
    letter-spacing: .1em;
    padding: 2px 6px;
    border: 1px solid var(--nodus-rule);

    &.is-on { color: rgb(var(--v-theme-success)); }
    &.is-off { color: rgb(var(--v-theme-error)); border-color: rgb(var(--v-theme-error)); }
  }
}

.m-page {
  flex: 1;
  padding: 12px 12px calc(72px + env(safe-area-inset-bottom));
  padding-inline-start: max(12px, env(safe-area-inset-left));
  padding-inline-end: max(12px, env(safe-area-inset-right));
}

.m-tabs {
  position: fixed;
  inset-inline: 0;
  inset-block-end: 0;
  z-index: 5;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  border-block-start: 1px solid var(--nodus-rule);
  background: rgb(var(--v-theme-surface));
  padding-block-end: env(safe-area-inset-bottom);

  .m-tab {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    block-size: 56px;
    font-size: 11px;
    letter-spacing: .02em;
    color: var(--nodus-ink-dim);
    text-decoration: none;

    &.is-active { color: rgb(var(--v-theme-primary)); }
  }
}

// Shared pieces --------------------------------------------------------------
.m-section {
  margin-block: 0 16px;

  > h2 {
    font-family: 'IBM Plex Mono', ui-monospace, monospace;
    font-size: 11px;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: var(--nodus-ink-dim);
    margin: 0 0 8px;
  }
}

.m-tiles {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 1px;
  background: var(--nodus-rule);
  border: 1px solid var(--nodus-rule);

  .m-tile {
    background: rgb(var(--v-theme-surface));
    padding: 12px;
    min-block-size: 72px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    text-decoration: none;
    color: inherit;

    .n {
      display: block;
      font-family: 'IBM Plex Mono', ui-monospace, monospace;
      font-size: 26px;
      line-height: 1;
      font-variant-numeric: tabular-nums;
    }

    .l {
      display: block;
      font-size: 12px;
      color: var(--nodus-ink-dim);
    }

    &.sev-critical .n { color: rgb(var(--v-theme-error)); }
    &.sev-warning .n { color: rgb(var(--v-theme-warning)); }
    &.sev-ok .n { color: rgb(var(--v-theme-success)); }
  }
}

.m-list {
  border: 1px solid var(--nodus-rule);
  background: rgb(var(--v-theme-surface));

  .m-row {
    display: grid;
    grid-template-columns: 4px 1fr auto;
    gap: 10px;
    align-items: center;
    min-block-size: 56px;
    padding: 8px 12px 8px 0;
    border-block-end: 1px solid var(--nodus-rule);
    text-decoration: none;
    color: inherit;

    &:last-child { border-block-end: 0; }

    .bar {
      align-self: stretch;
      background: var(--nodus-rule);
    }

    &.sev-critical .bar { background: rgb(var(--v-theme-error)); }
    &.sev-warning .bar { background: rgb(var(--v-theme-warning)); }
    &.sev-info .bar { background: rgb(var(--v-theme-info)); }
    &.sev-ok .bar { background: rgb(var(--v-theme-success)); }

    .main {
      display: flex;
      flex-direction: column;
      min-inline-size: 0;

      .t {
        display: block;
        font-size: 14px;
        font-weight: 500;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }

      .s {
        display: block;
        font-size: 12px;
        color: var(--nodus-ink-dim);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
    }

    .meta {
      font-family: 'IBM Plex Mono', ui-monospace, monospace;
      font-size: 11px;
      color: var(--nodus-ink-dim);
      text-align: end;
      white-space: nowrap;
    }

    &.is-acked { opacity: .6; }
  }
}

.m-kv {
  display: grid;
  grid-template-columns: 1fr;
  border: 1px solid var(--nodus-rule);
  background: rgb(var(--v-theme-surface));

  > div {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    border-block-end: 1px solid var(--nodus-rule);
    font-size: 13px;

    &:last-child { border-block-end: 0; }

    > span:first-child { color: var(--nodus-ink-dim); }
    > span:last-child { font-family: 'IBM Plex Mono', ui-monospace, monospace; text-align: end; word-break: break-all; }
  }
}

.m-actions {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-block-start: 12px;

  .v-btn { block-size: 48px; }
}

.m-empty {
  padding: 32px 12px;
  text-align: center;
  color: var(--nodus-ink-dim);
  font-size: 13px;
}

.m-chips {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-block-end: 8px;
  scrollbar-width: none;

  &::-webkit-scrollbar { display: none; }
}

// Teleported under <body>, so it needs the component class in the selector to
// beat Vuetify's own card rules.
.v-bottom-sheet .v-card.m-sheet {
  padding: 16px 16px calc(16px + env(safe-area-inset-bottom)) !important;
  max-block-size: 85dvh;
  overflow-y: auto;
  border-block-start: 1px solid var(--nodus-rule);

  h3 { font-size: 15px; margin: 0 0 4px; }
  .sub { font-size: 12px; color: var(--nodus-ink-dim); margin-block-end: 12px; }
}
</style>
