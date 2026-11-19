<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useNotify } from '@/composables/useNotify'
import { useAuthStore } from '@/stores/auth'

/**
 * Alarm Console — one screen to see and clear EVERY alarm type.
 *
 * Nodus raises five independent alert kinds in five tables (device, tunnel,
 * interface, circuit, next-hop), each cleared through a different place in the UI —
 * and next-hop had no manual clear at all. This page normalises them into one list
 * and clears/acks any mix in a single call, so an operator never has to hunt for
 * the one screen that happens to own a given alarm.
 */
interface AlertRow {
  kind: 'device' | 'tunnel' | 'interface' | 'circuit' | 'nexthop'
  id: number
  severity: 'critical' | 'warning' | 'info'
  label: string | null
  device_id: number | null
  device_name: string | null
  site_id: number | null
  site_name: string | null
  started_at: string | null
  ticket_number: string | null
  acknowledged_at: string | null
  acknowledged_by_name: string | null
  active: boolean
  in_maintenance: boolean
  uid: string
}

const auth = useAuthStore()
const notify = useNotify()

const rows = ref<AlertRow[]>([])
const counts = ref<{ all: number, by_kind: Record<string, number> }>({ all: 0, by_kind: {} })
const loading = ref(false)
const capped = ref(false)

// Filters
const q = ref('')
const kind = ref<string | null>(null)
const severity = ref<string | null>(null)
const scope = ref<'active' | 'all'>('active')

const kindOptions = [
  { title: 'All types', value: null },
  { title: 'Device', value: 'device' },
  { title: 'Tunnel', value: 'tunnel' },
  { title: 'Interface', value: 'interface' },
  { title: 'Circuit', value: 'circuit' },
  { title: 'Next-hop', value: 'nexthop' },
]
const severityOptions = [
  { title: 'All severities', value: null },
  { title: 'Critical', value: 'critical' },
  { title: 'Warning', value: 'warning' },
  { title: 'Info', value: 'info' },
]
const kindLabel: Record<string, string> = {
  device: 'Device', tunnel: 'Tunnel', interface: 'Interface', circuit: 'Circuit', nexthop: 'Next-hop',
}
const severityColor: Record<string, string> = { critical: 'error', warning: 'warning', info: 'info' }

const headers = [
  { title: 'Severity', key: 'severity', width: 110 },
  { title: 'Type', key: 'kind', width: 110 },
  { title: 'Alarm', key: 'label' },
  { title: 'Device', key: 'device_name' },
  { title: 'Site', key: 'site_name' },
  { title: 'Age', key: 'started_at', width: 90 },
  { title: 'Ticket', key: 'ticket_number', width: 130 },
  { title: '', key: 'actions', sortable: false, align: 'end' as const, width: 150 },
]

async function load() {
  loading.value = true
  try {
    const res = await api<{ alerts: AlertRow[], counts: typeof counts.value, capped: boolean }>('/api/alerts', {
      query: {
        scope: scope.value,
        ...(kind.value ? { kind: kind.value } : {}),
        ...(severity.value ? { severity: severity.value } : {}),
        ...(q.value.trim() ? { q: q.value.trim() } : {}),
      },
    })
    rows.value = res.alerts.map(a => ({ ...a, uid: `${a.kind}:${a.id}` }))
    counts.value = res.counts
    capped.value = res.capped
    // Drop any selection no longer visible so a later clear can't act blind.
    selected.value = selected.value.filter(uid => rows.value.some(r => r.uid === uid))
  }
  finally { loading.value = false }
}

watch([kind, severity, scope], load)
let qTimer: ReturnType<typeof setTimeout> | undefined
watch(q, () => { clearTimeout(qTimer); qTimer = setTimeout(load, 300) })
onMounted(load)

// ---- selection + bulk ----
const selected = ref<string[]>([])
const rowsByUid = computed(() => Object.fromEntries(rows.value.map(r => [r.uid, r])))

function itemsFor(uids: string[]) {
  return uids
    .map(uid => rowsByUid.value[uid])
    .filter(Boolean)
    .map(r => ({ kind: r.kind, id: r.id }))
}

// ---- clear / acknowledge dialog (single row or bulk) ----
const dialog = ref(false)
const dialogAction = ref<'clear' | 'acknowledge'>('clear')
const dialogTargets = ref<string[]>([]) // uids
const note = ref('')
const busy = ref(false)

function openDialog(action: 'clear' | 'acknowledge', targets: string[]) {
  if (targets.length === 0)
    return
  dialogAction.value = action
  dialogTargets.value = targets
  note.value = ''
  dialog.value = true
}

async function submit() {
  const items = itemsFor(dialogTargets.value)
  if (items.length === 0) {
    dialog.value = false

    return
  }
  busy.value = true
  try {
    const path = dialogAction.value === 'clear' ? '/api/alerts/clear' : '/api/alerts/acknowledge'
    const res = await api<{ cleared?: number, acknowledged?: number }>(path, {
      method: 'POST',
      body: { items, note: note.value || null },
    })
    const n = res.cleared ?? res.acknowledged ?? 0
    notify.success(
      dialogAction.value === 'clear' ? `Cleared ${n} alarm${n === 1 ? '' : 's'}` : `Acknowledged ${n} alarm${n === 1 ? '' : 's'}`,
    )
    dialog.value = false
    selected.value = []
    await load()
  }
  catch {
    notify.error('Action failed', 'Try again, or reload the list.')
  }
  finally { busy.value = false }
}

function ageOf(ts: string | null): string {
  if (!ts)
    return '—'
  const mins = Math.max(0, Math.round((Date.now() - new Date(ts).getTime()) / 60000))
  if (mins < 60)
    return `${mins}m`
  const hrs = Math.round(mins / 60)
  if (hrs < 48)
    return `${hrs}h`

  return `${Math.round(hrs / 24)}d`
}
</script>

<template>
  <div class="d-flex flex-column ga-4">
    <div class="d-flex align-center flex-wrap ga-2">
      <div>
        <h1 class="text-h5 font-weight-medium">
          Alarm Console
        </h1>
        <div class="text-body-2 text-medium-emphasis">
          Every alarm type in one place — clear or acknowledge any of them.
        </div>
      </div>
      <VSpacer />
      <VChip v-for="(n, k) in counts.by_kind" :key="k" size="small" variant="tonal" class="text-capitalize">
        {{ kindLabel[k] ?? k }}: {{ n }}
      </VChip>
    </div>

    <VCard>
      <VCardText class="d-flex flex-wrap align-center ga-3">
        <VTextField
          v-model="q"
          placeholder="Search alarm, device, site, ticket…"
          density="compact"
          variant="outlined"
          hide-details
          prepend-inner-icon="ri-search-line"
          style="max-inline-size: 320px;"
        />
        <VSelect
          v-model="kind"
          :items="kindOptions"
          density="compact"
          variant="outlined"
          hide-details
          style="max-inline-size: 170px;"
        />
        <VSelect
          v-model="severity"
          :items="severityOptions"
          density="compact"
          variant="outlined"
          hide-details
          style="max-inline-size: 180px;"
        />
        <VBtnToggle v-model="scope" density="compact" variant="outlined" mandatory divided>
          <VBtn value="active">
            Active
          </VBtn>
          <VBtn value="all">
            All
          </VBtn>
        </VBtnToggle>
        <VSpacer />
        <template v-if="auth.canAct && selected.length">
          <span class="text-body-2 text-medium-emphasis">{{ selected.length }} selected</span>
          <VBtn size="small" variant="tonal" prepend-icon="ri-eye-line" @click="openDialog('acknowledge', selected)">
            Acknowledge
          </VBtn>
          <VBtn size="small" color="primary" prepend-icon="ri-check-double-line" @click="openDialog('clear', selected)">
            Clear selected
          </VBtn>
        </template>
      </VCardText>

      <VDataTable
        v-model="selected"
        :headers="headers"
        :items="rows"
        :loading="loading"
        item-value="uid"
        :show-select="auth.canAct"
        density="comfortable"
        :items-per-page="50"
        class="text-no-wrap"
      >
        <template #item.severity="{ item }">
          <VChip :color="severityColor[item.severity] ?? 'secondary'" size="small" label variant="flat">
            {{ item.severity }}
          </VChip>
        </template>
        <template #item.kind="{ item }">
          <span class="text-body-2">{{ kindLabel[item.kind] ?? item.kind }}</span>
        </template>
        <template #item.label="{ item }">
          <div class="d-flex align-center ga-2">
            <span>{{ item.label }}</span>
            <VChip v-if="item.in_maintenance" size="x-small" color="info" variant="tonal">
              maintenance
            </VChip>
            <VChip v-if="item.acknowledged_at" size="x-small" variant="tonal" color="secondary">
              ack{{ item.acknowledged_by_name ? ` · ${item.acknowledged_by_name}` : '' }}
            </VChip>
          </div>
        </template>
        <template #item.device_name="{ item }">
          <RouterLink v-if="item.device_id" :to="`/devices/${item.device_id}`" class="text-primary text-decoration-none">
            {{ item.device_name }}
          </RouterLink>
          <span v-else>{{ item.device_name ?? '—' }}</span>
        </template>
        <template #item.site_name="{ item }">
          {{ item.site_name ?? '—' }}
        </template>
        <template #item.started_at="{ item }">
          {{ ageOf(item.started_at) }}
        </template>
        <template #item.ticket_number="{ item }">
          <span class="text-caption text-medium-emphasis">{{ item.ticket_number ?? '—' }}</span>
        </template>
        <template #item.actions="{ item }">
          <div v-if="auth.canAct" class="d-flex justify-end ga-1">
            <VBtn
              v-if="!item.acknowledged_at"
              size="small"
              variant="text"
              icon="ri-eye-line"
              title="Acknowledge"
              @click="openDialog('acknowledge', [item.uid])"
            />
            <VBtn
              size="small"
              variant="text"
              color="primary"
              icon="ri-check-double-line"
              title="Clear"
              @click="openDialog('clear', [item.uid])"
            />
          </div>
        </template>
        <template #no-data>
          <div class="py-8 text-center text-medium-emphasis">
            No {{ scope === 'active' ? 'active ' : '' }}alarms match.
          </div>
        </template>
      </VDataTable>

      <VCardText v-if="capped" class="text-caption text-medium-emphasis pt-0">
        Showing the first 500. Narrow with the filters to see the rest.
      </VCardText>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardTitle class="text-h6">
          {{ dialogAction === 'clear' ? 'Clear' : 'Acknowledge' }}
          {{ dialogTargets.length }} alarm{{ dialogTargets.length === 1 ? '' : 's' }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">
            <template v-if="dialogAction === 'clear'">
              Clearing records who cleared it and why. An alarm whose condition is still
              truly present reopens on the next poll (a real outage is never hidden).
            </template>
            <template v-else>
              Acknowledging marks the alarm as seen. It stays open.
            </template>
          </p>
          <VTextarea
            v-model="note"
            label="Note (optional)"
            rows="3"
            variant="outlined"
            hide-details
            auto-grow
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" :disabled="busy" @click="dialog = false">
            Cancel
          </VBtn>
          <VBtn
            :color="dialogAction === 'clear' ? 'primary' : 'secondary'"
            variant="flat"
            :loading="busy"
            @click="submit"
          >
            {{ dialogAction === 'clear' ? 'Clear' : 'Acknowledge' }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
