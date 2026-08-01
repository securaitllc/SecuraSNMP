<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/utils/datetime'
import type { CircuitAlert } from '@/types/models'

const auth = useAuthStore()

// A single place to view + act on a circuit outage (ISP ticket, acknowledge,
// clear) so the dashboard, Circuits, and ISP Providers pages all drill into the
// same detail instead of each re-implementing it.
interface CircuitRef {
  id: number
  circuit_id: string
  isp_name?: string | null
  support_phone?: string | null
}

const props = defineProps<{
  modelValue: boolean
  circuit: CircuitRef | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', v: boolean): void
  (e: 'updated'): void
}>()

const isOpen = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})

const alerts = ref<CircuitAlert[]>([])
const loading = ref(false)
const busy = ref(false)
const ticket = ref('')
const note = ref('')
const dispatchAt = ref('')
const dispatchNote = ref('')

// ISO (UTC) -> value a <input type="datetime-local"> accepts, in local time.
function toDatetimeLocal(iso: string): string {
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')

  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

const openAlert = computed(() => alerts.value.find(a => a.ended_at === null) ?? null)
const previousTicket = computed(() =>
  alerts.value.find(a => a.ticket_number && a.id !== openAlert.value?.id)?.ticket_number ?? null)

const runbook = [
  'Confirm the outage with a manual ping to the monitored IP.',
  'Call the ISP support line and open — or reference the previous — ticket.',
  'Record the ISP ticket number below so the whole NOC can track it.',
  'Acknowledge while the ISP works it; Clear once restored or if it was a false positive.',
]

async function load() {
  if (!props.circuit)
    return
  loading.value = true
  try {
    alerts.value = await api<CircuitAlert[]>(`/api/circuits/${props.circuit.id}/alerts`)
    ticket.value = openAlert.value?.ticket_number ?? ''
    // Prefill the existing note so it can be seen and edited, not silently lost.
    note.value = openAlert.value?.ack_note ?? ''
    dispatchAt.value = openAlert.value?.dispatch_at ? toDatetimeLocal(openAlert.value.dispatch_at) : ''
    dispatchNote.value = openAlert.value?.dispatch_note ?? ''
  }
  catch {
    alerts.value = []
  }
  finally {
    loading.value = false
  }
}

watch(() => [props.modelValue, props.circuit?.id], () => {
  if (props.modelValue && props.circuit)
    load()
})

function since(iso: string | null): string {
  if (!iso)
    return '—'
  const then = Date.parse(iso)
  if (Number.isNaN(then))
    return '—'
  let secs = Math.max(0, Math.floor((Date.now() - then) / 1000))
  const d = Math.floor(secs / 86400)
  secs -= d * 86400
  const h = Math.floor(secs / 3600)
  const m = Math.floor((secs % 3600) / 60)
  return d > 0 ? `${d}d ${h}h` : h > 0 ? `${h}h ${m}m` : `${m}m`
}

async function post(path: 'ticket' | 'acknowledge' | 'clear' | 'dispatch') {
  if (!props.circuit)
    return
  busy.value = true
  try {
    const body = path === 'ticket'
      ? { ticket_number: ticket.value || null }
      : path === 'dispatch'
        ? { dispatch_at: dispatchAt.value || null, note: dispatchNote.value || null }
        : { note: note.value || null }
    await api(`/api/circuits/${props.circuit.id}/${path}`, { method: 'POST', body })
    emit('updated')
    if (path === 'clear')
      isOpen.value = false
    else
      await load()
  }
  finally {
    busy.value = false
  }
}

function clearDispatch() {
  dispatchAt.value = ''
  dispatchNote.value = ''
  post('dispatch')
}
</script>

<template>
  <VDialog
    v-model="isOpen"
    max-width="480"
  >
    <VCard v-if="circuit">
      <VCardItem>
        <VCardTitle>{{ circuit.circuit_id }}</VCardTitle>
        <template #append>
          <VChip
            size="small"
            :color="openAlert ? 'error' : 'success'"
            variant="tonal"
          >
            {{ openAlert ? 'Down' : 'Up' }}
          </VChip>
        </template>
      </VCardItem>
      <VCardText>
        <div
          v-if="loading"
          class="d-flex justify-center py-6"
        >
          <VProgressCircular indeterminate size="24" />
        </div>
        <div
          v-else
          class="d-flex flex-column ga-3"
        >
          <div class="outage-grid">
            <div v-if="circuit.isp_name">
              <div class="text-caption text-medium-emphasis">ISP</div>
              <div>{{ circuit.isp_name }}</div>
            </div>
            <div v-if="circuit.support_phone">
              <div class="text-caption text-medium-emphasis">ISP support</div>
              <div><a :href="`tel:${circuit.support_phone}`">{{ circuit.support_phone }}</a></div>
            </div>
            <div v-if="openAlert">
              <div class="text-caption text-medium-emphasis">Down for</div>
              <div>{{ since(openAlert.started_at) }}</div>
            </div>
            <div v-if="openAlert?.acknowledged_at">
              <div class="text-caption text-medium-emphasis">Acknowledged</div>
              <div>{{ openAlert.acknowledged_by_name ?? 'yes' }} · {{ since(openAlert.acknowledged_at) }} ago</div>
            </div>
            <div v-if="openAlert?.dispatch_at">
              <div class="text-caption text-medium-emphasis">Dispatch ETA</div>
              <div class="font-weight-medium">{{ formatDateTime(openAlert.dispatch_at) }}</div>
            </div>
          </div>

          <VAlert
            v-if="!openAlert"
            type="success"
            variant="tonal"
            density="compact"
          >
            This circuit is currently up — no ongoing outage.
          </VAlert>

          <VAlert
            v-if="openAlert && !openAlert.ticket_number && previousTicket"
            type="info"
            variant="tonal"
            density="compact"
          >
            <div class="text-body-2">
              This circuit last had ISP ticket <strong>#{{ previousTicket }}</strong>. Reference or reopen it.
            </div>
          </VAlert>

          <template v-if="openAlert">
            <div>
              <div class="text-caption text-medium-emphasis mb-1">
                Recommended actions
              </div>
              <ol class="runbook">
                <li
                  v-for="(step, i) in runbook"
                  :key="i"
                  class="text-body-2"
                >
                  {{ step }}
                </li>
              </ol>
            </div>

            <div class="d-flex align-end ga-2">
              <VTextField
                v-model="ticket"
                label="ISP ticket #"
                placeholder="e.g. INC-39900"
                hide-details
                density="comfortable"
                class="flex-grow-1"
              />
              <VBtn
                v-if="auth.canAct"
                :loading="busy"
                variant="tonal"
                @click="post('ticket')"
              >
                Save ticket
              </VBtn>
            </div>

            <!-- ISP dispatch ETA — on record + accountability -->
            <VDivider />
            <div>
              <div class="text-caption text-medium-emphasis mb-1">
                ISP dispatch (ETA)
              </div>
              <VAlert
                v-if="openAlert.dispatch_at"
                type="info"
                variant="tonal"
                density="compact"
                class="mb-2"
              >
                <div class="text-body-2">
                  Dispatch scheduled <strong>{{ formatDateTime(openAlert.dispatch_at) }}</strong>
                  <span v-if="openAlert.dispatch_by_name">· logged by {{ openAlert.dispatch_by_name }}</span>
                </div>
                <div
                  v-if="openAlert.dispatch_note"
                  class="text-caption"
                >
                  {{ openAlert.dispatch_note }}
                </div>
              </VAlert>
              <div class="d-flex align-end ga-2">
                <VTextField
                  v-model="dispatchAt"
                  type="datetime-local"
                  label="Dispatch date/time"
                  hide-details
                  density="comfortable"
                  class="flex-grow-1"
                />
                <VBtn
                  v-if="auth.canAct"
                  :loading="busy"
                  variant="tonal"
                  @click="post('dispatch')"
                >
                  Save dispatch
                </VBtn>
              </div>
              <VTextField
                v-model="dispatchNote"
                label="Dispatch note (optional)"
                placeholder="e.g. Tech replacing the SFP"
                hide-details
                density="comfortable"
                class="mt-2"
              />
              <VBtn
                v-if="openAlert.dispatch_at && auth.canAct"
                size="small"
                variant="text"
                color="error"
                class="mt-1"
                @click="clearDispatch"
              >
                Clear dispatch
              </VBtn>
            </div>

            <VDivider />
            <VTextarea
              v-model="note"
              label="Investigation / resolution note"
              hint="Saved with Acknowledge / Save note below."
              rows="2"
              auto-grow
              persistent-hint
              density="comfortable"
            />
          </template>
        </div>
      </VCardText>
      <VCardActions v-if="openAlert && auth.canAct">
        <VBtn
          :loading="busy"
          variant="tonal"
          @click="post('acknowledge')"
        >
          {{ openAlert.acknowledged_at ? 'Save note' : 'Acknowledge' }}
        </VBtn>
        <VBtn
          :loading="busy"
          color="success"
          variant="flat"
          @click="post('clear')"
        >
          Clear
        </VBtn>
        <VSpacer />
        <VBtn @click="isOpen = false">
          Close
        </VBtn>
      </VCardActions>
      <VCardActions v-else>
        <VSpacer />
        <VBtn @click="isOpen = false">
          Close
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.outage-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 24px;
}
.runbook {
  margin: 0;
  padding-left: 18px;
}
.runbook li {
  margin-bottom: 2px;
}
a {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
</style>
