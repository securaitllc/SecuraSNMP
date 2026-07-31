<script lang="ts" setup>
import { useAlarmTab } from '@/composables/useAlarmTab'
import { useAlertsStore } from '@/stores/alerts'
import type { DashboardAlert } from '@/types/models'

const store = useAlertsStore()
const router = useRouter()

// This component lives in the authenticated layout, so it owns the shared poll
// and the tab indicator for the whole session.
onMounted(() => store.startPolling())
onBeforeUnmount(() => store.stopPolling())
useAlarmTab()

const sevColor: Record<string, string> = { critical: 'error', warning: 'warning', info: 'info' }
const typeLabel: Record<string, string> = {
  circuit: 'Circuit', interface: 'Interface', tunnel: 'Tunnel', 'tunnel-quality': 'Tunnel', next_hop: 'Next-hop', alarm: 'Alarm', incident: 'Incident',
}

function since(iso: string | null): string {
  if (!iso)
    return ''
  const then = Date.parse(iso)
  if (Number.isNaN(then))
    return ''
  let secs = Math.max(0, Math.floor((Date.now() - then) / 1000))
  const d = Math.floor(secs / 86400)
  secs -= d * 86400
  const h = Math.floor(secs / 3600)
  const m = Math.floor((secs % 3600) / 60)
  return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`
}

function go(a: DashboardAlert) {
  if (a.device_id)
    router.push(`/devices/${a.device_id}`)
  else if (a.circuit_id)
    router.push('/circuits')
  else if (a.site_id)
    // A site outage / multi-device incident has no single device — open the site's
    // topology so the operator sees what's down. (Was a dead click before.)
    router.push({ path: '/topology', query: { site: a.site_id } })
}
</script>

<template>
  <IconBtn id="alarm-btn">
    <VBadge
      :model-value="store.activeCount > 0"
      :content="store.activeCount"
      color="error"
      bordered
      offset-x="2"
      offset-y="2"
    >
      <VIcon icon="ri-alarm-warning-line" />
    </VBadge>

    <VMenu
      activator="parent"
      width="380"
      location="bottom end"
      offset="14px"
    >
      <VCard>
        <VCardItem class="py-3">
          <VCardTitle class="text-body-1">
            Active Alerts
          </VCardTitle>
          <template #append>
            <VChip
              size="small"
              :color="store.activeCount > 0 ? 'error' : 'success'"
              variant="tonal"
            >
              {{ store.activeCount }} active
            </VChip>
          </template>
        </VCardItem>
        <VDivider />

        <div
          v-if="store.alerts.length === 0"
          class="text-center text-medium-emphasis py-6 px-4"
        >
          <VIcon
            icon="ri-shield-check-line"
            size="28"
            class="mb-2 text-success"
          />
          <div>All clear — no active alerts.</div>
        </div>

        <VList
          v-else
          class="py-0"
          max-height="360"
          style="overflow-y: auto;"
        >
          <template
            v-for="a in store.alerts.slice(0, 10)"
            :key="a.key"
          >
            <VListItem @click="go(a)">
              <template #prepend>
                <span
                  class="alarm-dot"
                  :style="{ backgroundColor: `rgb(var(--v-theme-${sevColor[a.severity] ?? 'warning'}))` }"
                />
              </template>
              <VListItemTitle class="text-body-2 font-weight-medium">
                {{ a.title }}
              </VListItemTitle>
              <VListItemSubtitle class="text-caption">
                {{ typeLabel[a.type] }} · {{ a.subtitle }}
                <span v-if="a.ticket_number">· #{{ a.ticket_number }}</span>
              </VListItemSubtitle>
              <template #append>
                <span class="text-caption text-medium-emphasis">{{ since(a.started_at) }}</span>
              </template>
            </VListItem>
            <VDivider />
          </template>
        </VList>

        <VCardText class="py-2 text-center">
          <VBtn
            variant="text"
            size="small"
            block
            @click="router.push('/')"
          >
            View dashboard
          </VBtn>
        </VCardText>
      </VCard>
    </VMenu>
  </IconBtn>
</template>

<style scoped>
.alarm-dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
</style>
