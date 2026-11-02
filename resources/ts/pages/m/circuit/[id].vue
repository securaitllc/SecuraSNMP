<script lang="ts" setup>
import { useRoute } from 'vue-router'
import { api } from '@/composables/useApi'
import { mobileTitle } from '@/composables/useMobile'
import { useNotify } from '@/composables/useNotify'
import { useAuthStore } from '@/stores/auth'

definePage({ meta: { layout: 'mobile', title: 'Circuit' } })

interface Circuit {
  id: number
  site_id: number
  isp_name: string | null
  circuit_id: string | null
  circuit_type: string | null
  account_number: string | null
  support_phone: string | null
  status: string | null
  monitored_ip: string | null
  gateway_ip: string | null
  last_checked_at: string | null
  last_loss_pct: number | null
  isp_ticket: string | null
  dispatch_at: string | null
  dispatch_end_at: string | null
  dispatch_note: string | null
  monitoring_enabled: boolean
  isp_provider?: { name: string, support_phone: string | null, ticket_url: string | null } | null
}

const route = useRoute()
const auth = useAuthStore()
const notify = useNotify()
const id = computed(() => Number((route.params as { id: string }).id))

const circuit = ref<Circuit | null>(null)
const error = ref('')

async function load() {
  try {
    circuit.value = await api<Circuit>(`/api/circuits/${id.value}`)
    mobileTitle.value = `${circuit.value.isp_name ?? 'Circuit'} · ${circuit.value.circuit_id ?? ''}`
  }
  catch (e: any) {
    error.value = e?.status === 404 ? 'This circuit no longer exists.' : 'Could not load this circuit.'
  }
}

onMounted(load)
watch(id, load)

const phone = computed(() => circuit.value?.support_phone || circuit.value?.isp_provider?.support_phone || null)
const sev = computed(() => {
  const s = (circuit.value?.status ?? '').toLowerCase()

  return s === 'down' ? 'sev-critical' : s === 'degraded' ? 'sev-warning' : s === 'up' ? 'sev-ok' : ''
})

// One sheet, four forms. Each posts to the existing desktop endpoint.
const sheet = ref<'ticket' | 'dispatch' | 'acknowledge' | 'clear' | null>(null)
const ticket = ref('')
const note = ref('')
const dispatchAt = ref('')
const dispatchEnd = ref('')
const saving = ref(false)

function openSheet(kind: typeof sheet.value) {
  sheet.value = kind
  ticket.value = circuit.value?.isp_ticket ?? ''
  note.value = ''
  dispatchAt.value = circuit.value?.dispatch_at?.slice(0, 16) ?? ''
  dispatchEnd.value = circuit.value?.dispatch_end_at?.slice(0, 16) ?? ''
}

async function submit() {
  if (!sheet.value || !circuit.value)
    return

  saving.value = true
  try {
    const base = `/api/circuits/${circuit.value.id}`
    if (sheet.value === 'ticket')
      await api(`${base}/isp-ticket`, { method: 'POST', body: { isp_ticket: ticket.value.trim() || null } })
    else if (sheet.value === 'dispatch')
      await api(`${base}/isp-dispatch`, { method: 'POST', body: { dispatch_at: dispatchAt.value || null, dispatch_end_at: dispatchEnd.value || null, dispatch_note: note.value || null } })
    else
      await api(`${base}/${sheet.value}`, { method: 'POST', body: { note: note.value || null } })

    notify.success({ ticket: 'ISP ticket saved', dispatch: 'Dispatch saved', acknowledge: 'Acknowledged', clear: 'Cleared' }[sheet.value])
    sheet.value = null
    await load()
  }
  catch (e: any) {
    notify.error('Could not save', e?.data?.message)
  }
  finally {
    saving.value = false
  }
}
</script>

<template>
  <div>
    <div
      v-if="error"
      class="m-empty"
    >
      {{ error }}
    </div>

    <template v-else-if="circuit">
      <section class="m-section">
        <div class="m-tiles">
          <div
            class="m-tile"
            :class="sev"
          >
            <span class="n" style="font-size:18px">{{ (circuit.status || 'unknown').toUpperCase() }}</span>
            <span class="l">{{ circuit.monitoring_enabled ? 'monitored' : 'monitoring paused' }}</span>
          </div>
          <div
            class="m-tile"
            :class="circuit.last_loss_pct && circuit.last_loss_pct > 0 ? 'sev-warning' : 'sev-ok'"
          >
            <span class="n">{{ circuit.last_loss_pct ?? '—' }}<small>%</small></span>
            <span class="l">Last loss</span>
          </div>
        </div>
      </section>

      <section class="m-section">
        <h2>ISP</h2>
        <div class="m-kv">
          <div><span>Provider</span><span>{{ circuit.isp_name || circuit.isp_provider?.name || '—' }}</span></div>
          <div><span>Circuit ID</span><span>{{ circuit.circuit_id || '—' }}</span></div>
          <div v-if="circuit.account_number"><span>Account</span><span>{{ circuit.account_number }}</span></div>
          <div v-if="phone"><span>Support</span><span><a :href="`tel:${phone}`">{{ phone }}</a></span></div>
          <div v-if="circuit.isp_provider?.ticket_url"><span>Portal</span><span><a :href="circuit.isp_provider.ticket_url" target="_blank" rel="noopener">open ticket portal →</a></span></div>
          <div><span>Site</span><span><RouterLink :to="`/m/site/${circuit.site_id}`">open site →</RouterLink></span></div>
        </div>
      </section>

      <section class="m-section">
        <h2>Ticket & dispatch</h2>
        <div class="m-kv">
          <div><span>ISP ticket</span><span>{{ circuit.isp_ticket || '—' }}</span></div>
          <div><span>Dispatch</span><span>{{ circuit.dispatch_at ? new Date(circuit.dispatch_at).toLocaleString() : '—' }}<template v-if="circuit.dispatch_end_at"> → {{ new Date(circuit.dispatch_end_at).toLocaleString() }}</template></span></div>
          <div v-if="circuit.dispatch_note"><span>Note</span><span>{{ circuit.dispatch_note }}</span></div>
          <div v-if="circuit.monitored_ip || circuit.gateway_ip"><span>Probe</span><span>{{ circuit.monitored_ip || circuit.gateway_ip }}</span></div>
        </div>

        <div
          v-if="auth.canAct"
          class="m-actions"
        >
          <VBtn
            variant="tonal"
            prepend-icon="ri-ticket-line"
            @click="openSheet('ticket')"
          >
            ISP ticket
          </VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="ri-truck-line"
            @click="openSheet('dispatch')"
          >
            Dispatch
          </VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="ri-check-line"
            @click="openSheet('acknowledge')"
          >
            Acknowledge
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            prepend-icon="ri-close-circle-line"
            @click="openSheet('clear')"
          >
            Clear
          </VBtn>
        </div>
      </section>

      <VBottomSheet :model-value="!!sheet" @update:model-value="v => { if (!v) sheet = null }">
        <VCard
          v-if="sheet"
          class="m-sheet"
          flat
        >
          <h3>{{ { ticket: 'ISP ticket', dispatch: 'Field dispatch', acknowledge: 'Acknowledge outage', clear: 'Clear outage' }[sheet] }}</h3>
          <div class="sub">
            {{ circuit.isp_name }} · {{ circuit.circuit_id }}
          </div>

          <VTextField
            v-if="sheet === 'ticket'"
            v-model="ticket"
            label="ISP ticket number"
            variant="outlined"
            density="comfortable"
            autocapitalize="characters"
          />
          <template v-if="sheet === 'dispatch'">
            <VTextField
              v-model="dispatchAt"
              label="Dispatch window start"
              type="datetime-local"
              variant="outlined"
              density="comfortable"
            />
            <VTextField
              v-model="dispatchEnd"
              label="Window end"
              type="datetime-local"
              variant="outlined"
              density="comfortable"
            />
          </template>
          <VTextarea
            v-if="sheet !== 'ticket'"
            v-model="note"
            label="Note (optional)"
            rows="2"
            auto-grow
            variant="outlined"
            density="compact"
          />

          <VBtn
            block
            :color="sheet === 'clear' ? 'error' : 'primary'"
            variant="tonal"
            :loading="saving"
            size="large"
            @click="submit"
          >
            Save
          </VBtn>
        </VCard>
      </VBottomSheet>
    </template>
  </div>
</template>
