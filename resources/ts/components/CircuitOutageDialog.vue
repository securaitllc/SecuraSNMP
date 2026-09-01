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
const dispatchEndAt = ref('')
const dispatchNote = ref('')
// The circuit's own ticket + dispatch, which exist independently of any outage.
const circuitDispatch = ref<{ isp_ticket: string | null, dispatch_at: string | null, dispatch_end_at: string | null, dispatch_note: string | null } | null>(null)

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
    // The ISP ticket and the dispatch window live on the CIRCUIT as well as on the
    // outage, because an ISP schedules a truck for a circuit whether or not we happen
    // to have an alert open on it right now.
    circuitDispatch.value = await api(`/api/circuits/${props.circuit.id}`).catch(() => null)
    ticket.value = openAlert.value?.ticket_number ?? circuitDispatch.value?.isp_ticket ?? ''
    // Prefill the existing note so it can be seen and edited, not silently lost.
    note.value = openAlert.value?.ack_note ?? ''

    const at = openAlert.value?.dispatch_at ?? circuitDispatch.value?.dispatch_at ?? null
    const end = openAlert.value?.dispatch_end_at ?? circuitDispatch.value?.dispatch_end_at ?? null

    dispatchAt.value = at ? toDatetimeLocal(at) : ''
    dispatchEndAt.value = end ? toDatetimeLocal(end) : ''
    dispatchNote.value = openAlert.value?.dispatch_note ?? circuitDispatch.value?.dispatch_note ?? ''
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
    // With an outage open the action belongs to that outage. With none open there is
    // no alert to hang it on, so ticket and dispatch go to the circuit-level
    // endpoints instead — a scheduled truck roll must be recordable on a circuit that
    // is currently up (an intermittent, a repair booked after a clear, a planned
    // SD-WAN cutover). Both sides mirror to each other, so either path is complete.
    const onAlert = openAlert.value !== null
    const endpoint = onAlert || path === 'acknowledge' || path === 'clear'
      ? path
      : (path === 'ticket' ? 'isp-ticket' : 'isp-dispatch')

    const body = path === 'ticket'
      ? (onAlert ? { ticket_number: ticket.value || null } : { isp_ticket: ticket.value || null })
      : path === 'dispatch'
        ? {
            dispatch_at: dispatchAt.value || null,
            dispatch_end_at: dispatchEndAt.value || null,
            ...(onAlert ? { note: dispatchNote.value || null } : { dispatch_note: dispatchNote.value || null }),
          }
        : { note: note.value || null }
    await api(`/api/circuits/${props.circuit.id}/${endpoint}`, { method: 'POST', body })
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
  dispatchEndAt.value = ''
  dispatchNote.value = ''
  post('dispatch')
}

/** "Tue 2 Sep, 08:00 – 12:00", or just the time when the ISP gave a single ETA. */
function dispatchWindow(at: string | null, end: string | null): string {
  if (!at)
    return '—'
  if (!end)
    return formatDateTime(at)

  const a = new Date(at); const b = new Date(end)
  const t = (d: Date) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`

  return a.toDateString() === b.toDateString()
    ? `${formatDateTime(at)} – ${t(b)}`
    : `${formatDateTime(at)} – ${formatDateTime(end)}`
}

// What the dialog shows, whichever record it came from.
const shownDispatchAt = computed(() => openAlert.value?.dispatch_at ?? circuitDispatch.value?.dispatch_at ?? null)
const shownDispatchEnd = computed(() => openAlert.value?.dispatch_end_at ?? circuitDispatch.value?.dispatch_end_at ?? null)
const shownDispatchNote = computed(() => openAlert.value?.dispatch_note ?? circuitDispatch.value?.dispatch_note ?? null)
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
            <div v-if="shownDispatchAt">
              <div class="text-caption text-medium-emphasis">Dispatch</div>
              <div class="font-weight-medium">{{ dispatchWindow(shownDispatchAt, shownDispatchEnd) }}</div>
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
          </template>

          <!-- ISP ticket + dispatch window.
               Deliberately OUTSIDE the open-outage branch: an ISP schedules a truck
               for a CIRCUIT, not for our alert row. A dispatch booked for tomorrow on
               a circuit that is up right now — an intermittent, a repair agreed after
               a clear, a planned cutover — had nowhere to be recorded before. -->
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

          <!-- ISP dispatch window — on record + accountability -->
          <VDivider />
          <div>
              <div class="text-caption text-medium-emphasis mb-1">
                ISP dispatch window
              </div>
              <VAlert
                v-if="shownDispatchAt"
                type="info"
                variant="tonal"
                density="compact"
                class="mb-2"
              >
                <div class="text-body-2">
                  Dispatch scheduled <strong>{{ dispatchWindow(shownDispatchAt, shownDispatchEnd) }}</strong>
                  <span v-if="openAlert?.dispatch_by_name">· logged by {{ openAlert.dispatch_by_name }}</span>
                </div>
                <div
                  v-if="shownDispatchNote"
                  class="text-caption"
                >
                  {{ shownDispatchNote }}
                </div>
              </VAlert>
              <div class="d-flex align-end ga-2">
                <VTextField
                  v-model="dispatchAt"
                  type="datetime-local"
                  label="Window start"
                  hide-details
                  density="comfortable"
                  class="flex-grow-1"
                />
                <VTextField
                  v-model="dispatchEndAt"
                  type="datetime-local"
                  label="Window end (optional)"
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
                  Save
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
                v-if="shownDispatchAt && auth.canAct"
                size="small"
                variant="text"
                color="error"
                class="mt-1"
                @click="clearDispatch"
              >
                Clear dispatch
              </VBtn>
          </div>

          <template v-if="openAlert">
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
