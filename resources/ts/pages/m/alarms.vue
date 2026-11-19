<script lang="ts" setup>
import { api } from '@/composables/useApi'
import { ago } from '@/composables/useMobile'
import { useNotify } from '@/composables/useNotify'
import { useAuthStore } from '@/stores/auth'

definePage({ meta: { layout: 'mobile', title: 'Alarms' } })

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
  circuit_id?: number | null
}

const auth = useAuthStore()
const notify = useNotify()

const rows = ref<AlertRow[]>([])
const loading = ref(true)
const error = ref('')
type Filter = 'all' | 'critical' | 'unacked'
const filter = ref<Filter>('all')
const filters: { key: Filter, label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'critical', label: 'Critical' },
  { key: 'unacked', label: 'Unacked' },
]
let timer: number | undefined

const KIND_LABEL: Record<AlertRow['kind'], string> = {
  device: 'Device', tunnel: 'Tunnel', interface: 'Port', circuit: 'Circuit', nexthop: 'Next hop',
}

async function load() {
  try {
    const res = await api<{ alerts: AlertRow[] }>('/api/alerts')
    rows.value = res.alerts
    error.value = ''
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not load alarms.'
  }
  finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  timer = window.setInterval(load, 20_000)
})
onUnmounted(() => clearInterval(timer))

const shown = computed(() => {
  const order = { critical: 0, warning: 1, info: 2 }

  return rows.value
    .filter(r => filter.value === 'all' || (filter.value === 'critical' ? r.severity === 'critical' : !r.acknowledged_at))
    .sort((a, b) => order[a.severity] - order[b.severity] || (b.started_at ?? '').localeCompare(a.started_at ?? ''))
})

const counts = computed(() => ({
  all: rows.value.length,
  critical: rows.value.filter(r => r.severity === 'critical').length,
  unacked: rows.value.filter(r => !r.acknowledged_at).length,
}))

// One alarm at a time on a phone: tap → sheet → ack/clear with a note.
const selected = ref<AlertRow | null>(null)
const action = ref<'acknowledge' | 'clear' | null>(null)
const note = ref('')
const saving = ref(false)

function open(row: AlertRow) {
  selected.value = row
  action.value = null
  note.value = ''
}

async function submit() {
  if (!selected.value || !action.value)
    return

  saving.value = true
  try {
    await api(`/api/alerts/${action.value}`, {
      method: 'POST',
      body: { items: [{ kind: selected.value.kind, id: selected.value.id }], note: note.value || null },
    })
    notify.success(action.value === 'clear' ? 'Alarm cleared' : 'Alarm acknowledged')
    selected.value = null
    await load()
  }
  catch (e: any) {
    notify.error('Could not update the alarm', e?.data?.message)
  }
  finally {
    saving.value = false
  }
}

function target(row: AlertRow): string | null {
  if (row.kind === 'circuit')
    return row.circuit_id ? `/m/circuit/${row.circuit_id}` : (row.site_id ? `/m/site/${row.site_id}` : null)
  if (row.device_id)
    return `/m/device/${row.device_id}`
  if (row.site_id)
    return `/m/site/${row.site_id}`

  return null
}
</script>

<template>
  <div>
    <div class="m-chips">
      <VChip
        v-for="f in filters"
        :key="f.key"
        size="small"
        label
        :variant="filter === f.key ? 'flat' : 'outlined'"
        :color="f.key === 'critical' ? 'error' : undefined"
        @click="filter = f.key"
      >
        {{ f.label }} · {{ counts[f.key] }}
      </VChip>
    </div>

    <div
      v-if="error"
      class="m-empty"
    >
      {{ error }}
    </div>

    <div
      v-else-if="shown.length"
      class="m-list"
    >
      <a
        v-for="r in shown"
        :key="`${r.kind}-${r.id}`"
        class="m-row"
        :class="[`sev-${r.severity}`, { 'is-acked': !!r.acknowledged_at }]"
        role="button"
        @click="open(r)"
      >
        <span class="bar" />
        <span class="main">
          <span class="t">{{ r.site_name || r.device_name || KIND_LABEL[r.kind] }}</span>
          <span class="s">{{ KIND_LABEL[r.kind] }}<template v-if="r.device_name && r.site_name"> · {{ r.device_name }}</template><template v-if="r.label"> · {{ r.label }}</template></span>
        </span>
        <span class="meta">
          {{ ago(r.started_at) }}<br>
          <template v-if="r.acknowledged_at">ACK</template>
          <template v-else-if="r.in_maintenance">MAINT</template>
          <template v-else-if="r.ticket_number">#{{ r.ticket_number }}</template>
        </span>
      </a>
    </div>

    <div
      v-else-if="!loading"
      class="m-empty"
    >
      No active alarms.
    </div>

    <VBottomSheet :model-value="!!selected" @update:model-value="v => { if (!v) selected = null }">
      <VCard
        v-if="selected"
        class="m-sheet"
        flat
      >
        <h3>{{ selected.site_name || selected.device_name || KIND_LABEL[selected.kind] }}</h3>
        <div class="sub">
          {{ KIND_LABEL[selected.kind] }}<template v-if="selected.device_name"> · {{ selected.device_name }}</template>
          <template v-if="selected.label"><br>{{ selected.label }}</template>
          <br>Since {{ selected.started_at ? new Date(selected.started_at).toLocaleString() : '—' }}
          <template v-if="selected.acknowledged_at"><br>Acknowledged by {{ selected.acknowledged_by_name ?? 'someone' }}</template>
        </div>

        <VBtn
          v-if="target(selected)"
          :to="target(selected) ?? '/m/alarms'"
          variant="outlined"
          block
          class="mb-3"
          prepend-icon="ri-arrow-right-line"
        >
          Open {{ selected.kind === 'circuit' ? 'circuit' : selected.device_id ? 'device' : 'site' }}
        </VBtn>

        <template v-if="auth.canAct">
          <VTextarea
            v-model="note"
            label="Note (optional)"
            rows="2"
            auto-grow
            variant="outlined"
            density="compact"
          />
          <div class="m-actions">
            <VBtn
              :disabled="!!selected.acknowledged_at || saving"
              :loading="saving && action === 'acknowledge'"
              variant="tonal"
              @click="action = 'acknowledge'; submit()"
            >
              Acknowledge
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              :disabled="saving"
              :loading="saving && action === 'clear'"
              @click="action = 'clear'; submit()"
            >
              Clear
            </VBtn>
          </div>
        </template>
      </VCard>
    </VBottomSheet>
  </div>
</template>
