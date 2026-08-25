<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/utils/datetime'

// Active alarms grouped by site → ISP circuit. Each next-hop maps 1:1 to a
// circuit, so the ISP ticket + dispatch are recorded once per provider (reusing
// the circuit ticket/dispatch endpoints) and shown on every alarm that rides it.
const props = defineProps<{ siteId?: number | null, embedded?: boolean }>()

const auth = useAuthStore()

interface GAction { url: string, body?: Record<string, unknown>, label?: string, admin?: boolean }
interface GActions { ack: GAction, clear: GAction, mute: GAction | null }
interface GMac { mac: string, vendor: string | null }
interface GAlarm { key: string, id: number | null, alarm_id: string | null, severity: string, description: string | null, ticket_number: string | null, first_seen_at: string, device_name: string | null, device_id?: number | null, if_name?: string | null, last_neighbor?: string | null, macs?: GMac[], macs_more?: number, acknowledged_at?: string | null, actions?: GActions }
interface GCircuit { id: number, isp_name: string | null, wan_interface: string | null, gateway_ip: string | null, support_phone: string | null, circuit_id: string | null, status: string | null }
interface GTicket { isp_ticket: string | null, dispatch_at: string | null, dispatch_note: string | null, dispatch_by_name: string | null }
interface Group { kind: 'circuit' | 'site', circuit: GCircuit | null, state: string, label?: string, ticket: GTicket | null, alarms: GAlarm[] }
interface SiteGroups { site_id: number, site_name: string | null, groups: Group[] }

const sites = ref<SiteGroups[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const q = props.siteId ? `?site_id=${props.siteId}` : ''
    sites.value = (await api<{ sites: SiteGroups[] }>(`/api/alarms/grouped${q}`)).sites
  }
  finally { loading.value = false }
}
// Auto-refresh so the grouped list stays live as alarms auto-clear / new ones fire —
// it used to load once and only update on an operator action, drifting from the counts.
let poll: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  load()
  poll = setInterval(load, 30000)
})
onBeforeUnmount(() => { if (poll) clearInterval(poll) })
defineExpose({ load })

// State → colour. Down/critical = red, degraded/warning = amber, else grey.
const STATE_COLOR: Record<string, string> = { down: 'error', critical: 'error', degraded: 'warning', warning: 'warning' }
const STATE_LABEL: Record<string, string> = { down: 'Down', critical: 'Critical', degraded: 'Degraded', warning: 'Warning' }
function stateColor(s: string) { return STATE_COLOR[s] ?? 'secondary' }
const sevColor: Record<string, string> = { critical: 'error', warning: 'warning', info: 'secondary' }

function path(c: GCircuit) {
  return [c.wan_interface, c.gateway_ip ? `gw ${c.gateway_ip}` : null].filter(Boolean).join(' · ')
}
function ageOf(iso: string) {
  const mins = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000))
  if (mins < 60) return `${mins}m`
  const h = Math.floor(mins / 60)
  return h < 24 ? `${h}h ${String(mins % 60).padStart(2, '0')}m` : `${Math.floor(h / 24)}d ${String(h % 24).padStart(2, '0')}h`
}

// --- Per-alarm NOC actions (ack / clear / mute), routed by the server-supplied URLs ---
const busyKey = ref<string | null>(null)
async function runAction(a: GAlarm, act: GAction | null | undefined) {
  if (!act) return
  busyKey.value = a.key
  try {
    await api(act.url, { method: 'POST', body: act.body ?? {} })
    await load()
  }
  finally { busyKey.value = null }
}

// Mute (circuit pause / port suppress) silences more than one alarm, so confirm.
const muteDialog = ref(false)
const muteTarget = ref<{ alarm: GAlarm, act: GAction } | null>(null)
function askMute(a: GAlarm) {
  if (a.actions?.mute) { muteTarget.value = { alarm: a, act: a.actions.mute }; muteDialog.value = true }
}
async function confirmMute() {
  if (muteTarget.value) await runAction(muteTarget.value.alarm, muteTarget.value.act)
  muteDialog.value = false
}

// --- ISP ticket + dispatch entry (analyst+) ---
const dialog = ref(false)
const saving = ref(false)
const editSite = ref<string>('')
const editCircuit = ref<GCircuit | null>(null)
const form = ref({ isp_ticket: '', dispatch_at: '', dispatch_note: '' })

function openEdit(siteName: string | null, g: Group) {
  if (!g.circuit) return
  editSite.value = siteName ?? ''
  editCircuit.value = g.circuit
  form.value = {
    isp_ticket: g.ticket?.isp_ticket ?? '',
    // datetime-local wants "YYYY-MM-DDTHH:mm"
    dispatch_at: g.ticket?.dispatch_at ? new Date(g.ticket.dispatch_at).toISOString().slice(0, 16) : '',
    dispatch_note: g.ticket?.dispatch_note ?? '',
  }
  dialog.value = true
}

async function save() {
  if (!editCircuit.value) return
  saving.value = true
  try {
    const id = editCircuit.value.id
    await api(`/api/circuits/${id}/ticket`, { method: 'POST', body: { ticket_number: form.value.isp_ticket || null } })
    await api(`/api/circuits/${id}/dispatch`, {
      method: 'POST',
      body: { dispatch_at: form.value.dispatch_at || null, note: form.value.dispatch_note || null },
    })
    dialog.value = false
    await load()
  }
  finally { saving.value = false }
}
</script>

<template>
  <div class="agroups">
    <div v-if="loading && !sites.length" class="ag-empty">Loading…</div>
    <div v-else-if="!sites.length" class="ag-empty"><VIcon icon="ri-checkbox-circle-line" size="16" color="success" class="me-1" />No active alarms.</div>

    <div v-for="site in sites" :key="site.site_id" class="ag-site">
      <div v-if="!props.siteId" class="ag-site-name">{{ site.site_name ?? `Site ${site.site_id}` }}</div>

      <div
        v-for="(g, gi) in site.groups"
        :key="gi"
        class="ag-card"
        :class="[`is-${g.state}`, { 'is-bucket': g.kind === 'site' }]"
      >
        <!-- circuit header -->
        <div class="ag-head">
          <template v-if="g.kind === 'circuit' && g.circuit">
            <span class="ag-isp">{{ g.circuit.isp_name ?? 'Circuit' }}</span>
            <span class="ag-path">{{ path(g.circuit) }}</span>
          </template>
          <template v-else>
            <span class="ag-isp">Site-wide</span>
            <span class="ag-path">{{ g.label ?? 'not tied to one ISP' }}</span>
          </template>

          <VChip :color="stateColor(g.state)" size="small" variant="flat" class="ag-state">
            {{ STATE_LABEL[g.state] ?? g.state }}
          </VChip>
          <span v-if="g.circuit?.support_phone" class="ag-phone"><VIcon icon="ri-phone-line" size="16" class="me-1" />{{ g.circuit.support_phone }}</span>
          <VBtn
            v-if="g.kind === 'circuit' && auth.canAct"
            size="small" variant="tonal" color="primary" prepend-icon="ri-price-tag-3-line"
            class="ag-edit" @click="openEdit(site.site_name, g)"
          >
            {{ g.ticket?.isp_ticket ? 'Edit ticket' : 'ISP ticket / dispatch' }}
          </VBtn>
        </div>

        <!-- ticket + dispatch stamp -->
        <div v-if="g.kind === 'circuit' && (g.ticket?.isp_ticket || g.ticket?.dispatch_at)" class="ag-ticket">
          <span v-if="g.ticket?.isp_ticket" class="ag-tkt"><VIcon icon="ri-ticket-2-line" size="16" class="me-1" /><CopyBtn :text="g.ticket.isp_ticket" /></span>
          <span v-if="g.ticket?.dispatch_at" class="ag-disp">
            <VIcon icon="ri-truck-line" size="16" class="me-1" />{{ formatDateTime(g.ticket.dispatch_at) }}
            <span v-if="g.ticket.dispatch_by_name" class="ag-by">· {{ g.ticket.dispatch_by_name }}</span>
          </span>
          <span v-if="g.ticket?.dispatch_note" class="ag-note">— {{ g.ticket.dispatch_note }}</span>
        </div>

        <!-- nested alarms -->
        <div class="ag-alarms">
          <div v-for="a in g.alarms" :key="a.key" class="ag-alarm" :class="a.severity">
            <!-- primary line: title + device on the left, actions right-aligned so they
                 line up across every row and never get pushed down by learned MACs -->
            <div class="ag-alarm__row">
              <span class="ag-sev">{{ a.severity }}</span>
              <span class="ag-atitle">{{ a.description }}</span>
              <RouterLink
                v-if="a.device_id"
                :to="a.if_name ? `/devices/${a.device_id}?interface=${encodeURIComponent(a.if_name)}` : `/devices/${a.device_id}`"
                class="ag-dev ag-dev--link"
                :title="a.if_name ? `Open ${a.if_name} on ${a.device_name} to troubleshoot` : 'Open device to investigate'"
              >
                {{ a.device_name }}<VIcon icon="ri-arrow-right-up-line" size="11" />
              </RouterLink>
              <span v-else-if="a.device_name" class="ag-dev">{{ a.device_name }}</span>
              <div class="ag-alarm__right">
                <CopyBtn v-if="g.kind === 'circuit' && g.ticket?.isp_ticket" :text="g.ticket.isp_ticket" class="ag-inherit" />
                <span v-if="a.acknowledged_at" class="ag-ackd" title="Acknowledged"><VIcon icon="ri-checkbox-circle-line" size="16" class="me-1" />ack</span>
                <span class="ag-age">{{ ageOf(a.first_seen_at) }}</span>
                <span v-if="auth.canAct && a.actions" class="ag-actions">
                  <VBtn v-if="!a.acknowledged_at" size="x-small" variant="tonal" :loading="busyKey === a.key" @click="runAction(a, a.actions.ack)">Ack</VBtn>
                  <VBtn size="x-small" variant="tonal" color="secondary" :loading="busyKey === a.key" @click="runAction(a, a.actions.clear)">Clear</VBtn>
                  <VBtn v-if="a.actions.mute && auth.isAdmin" size="x-small" variant="tonal" color="warning" @click="askMute(a)">{{ a.actions.mute.label ?? 'Mute' }}</VBtn>
                </span>
              </div>
            </div>
            <!-- second line: LLDP neighbor + learned MACs (the variable-length content) -->
            <div v-if="a.last_neighbor || (a.macs && a.macs.length) || a.macs_more" class="ag-alarm__endpoints">
              <span v-if="a.last_neighbor" class="ag-neighbor" title="Last connected here (LLDP)">⇢ {{ a.last_neighbor }}</span>
              <span v-for="m in (a.macs ?? [])" :key="m.mac" class="ag-mac" :title="m.vendor || 'unknown vendor'">
                {{ m.mac }}<span v-if="m.vendor" class="ag-macv">{{ m.vendor }}</span>
              </span>
              <span v-if="a.macs_more" class="ag-macmore" title="more learned MACs">+{{ a.macs_more }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ISP ticket + dispatch dialog -->
    <VDialog v-model="dialog" max-width="480">
      <VCard>
        <VCardTitle>ISP ticket &amp; dispatch</VCardTitle>
        <VCardSubtitle v-if="editCircuit">{{ editCircuit.isp_name }} · {{ editSite }}</VCardSubtitle>
        <VCardText class="d-flex flex-column ga-4 pt-4">
          <VTextField v-model="form.isp_ticket" label="ISP ticket number" placeholder="LUMEN-4471822" clearable hide-details />
          <VTextField v-model="form.dispatch_at" label="Dispatch date/time" type="datetime-local" hide-details />
          <VTextField v-model="form.dispatch_note" label="Dispatch note (optional)" placeholder="Tech en route" clearable hide-details />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="saving" @click="save">Save</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Mute confirm (circuit pause / port suppress silences more than one alarm) -->
    <VDialog v-model="muteDialog" max-width="420">
      <VCard :title="muteTarget?.act.label ?? 'Mute'">
        <VCardText class="text-body-2">
          {{ muteTarget?.alarm.description }}<span v-if="muteTarget?.alarm.device_name"> · {{ muteTarget?.alarm.device_name }}</span>.
          This silences its alarms until it's un-muted. Continue?
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="muteDialog = false">Cancel</VBtn>
          <VBtn color="warning" @click="confirmMute">{{ muteTarget?.act.label ?? 'Mute' }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.ag-empty { color: rgb(var(--v-theme-on-surface), .6); padding: 20px 4px; font-size: .9rem; }
.ag-site { margin-bottom: 18px; }
.ag-site-name { font-size: 1.05rem; font-weight: 700; margin: 6px 0 10px; }

.ag-card {
  border: 1px solid rgba(var(--v-border-color), .12);
  border-radius: 12px; margin-bottom: 12px; overflow: hidden;
  background: rgb(var(--v-theme-surface));
}
.ag-card.is-down { border-color: rgba(var(--v-theme-error), .5); }
.ag-card.is-degraded { border-color: rgba(var(--v-theme-warning), .5); }
.ag-card.is-bucket { opacity: .92; }

.ag-head { display: flex; align-items: center; gap: 12px; padding: 13px 16px; flex-wrap: wrap; }
.ag-isp { font-weight: 700; font-size: 1rem; }
.ag-path { font-family: ui-monospace, monospace; font-size: .74rem; color: rgb(var(--v-theme-on-surface), .6);
  background: rgba(var(--v-border-color), .08); border-radius: 6px; padding: 2px 8px; }
.ag-state { font-weight: 700; letter-spacing: .04em; }
.ag-phone { font-family: ui-monospace, monospace; font-size: .72rem; color: rgb(var(--v-theme-info)); }
.ag-edit { margin-left: auto; }

.ag-ticket { display: flex; gap: 14px; flex-wrap: wrap; padding: 0 16px 12px; font-size: .8rem;
  border-bottom: 1px dashed rgba(var(--v-border-color), .12); margin-bottom: 6px; }
.ag-tkt { font-family: ui-monospace, monospace; color: rgb(var(--v-theme-info)); font-weight: 600; }
.ag-disp { color: rgb(var(--v-theme-on-surface), .8); }
.ag-by { color: rgb(var(--v-theme-on-surface), .5); }
.ag-note { color: rgb(var(--v-theme-on-surface), .6); }

.ag-alarms { padding: 4px 12px 12px; }
.ag-alarm { display: flex; flex-direction: column; gap: 7px; padding: 9px 12px; border-radius: 8px;
  border-left: 3px solid rgb(var(--v-theme-secondary)); background: rgba(var(--v-border-color), .04); margin: 6px 0; }
.ag-alarm.critical { border-left-color: rgb(var(--v-theme-error)); }
.ag-alarm.warning { border-left-color: rgb(var(--v-theme-warning)); }
.ag-alarm__row { display: flex; align-items: center; gap: 12px; }
/* Right group holds ack/age/actions and is pushed to the row's end, so it lines up
   across every alarm regardless of title length or learned-MAC count. */
.ag-alarm__right { margin-left: auto; display: flex; align-items: center; gap: 10px; flex: none; }
/* Second line: neighbor + MACs, indented to sit under the title (past the severity tag). */
.ag-alarm__endpoints { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding-left: 74px; }
.ag-sev { text-transform: uppercase; font-size: .6rem; font-weight: 800; letter-spacing: .07em; min-width: 62px; flex: none;
  color: rgb(var(--v-theme-on-surface), .7); }
.ag-alarm.critical .ag-sev { color: rgb(var(--v-theme-error)); }
.ag-alarm.warning .ag-sev { color: rgb(var(--v-theme-warning)); }
.ag-atitle { font-size: .86rem; flex: 0 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ag-dev { display: inline-flex; align-items: center; gap: 3px; font-family: ui-monospace, monospace; font-size: .68rem; flex: none;
  color: rgb(var(--v-theme-on-surface), .55); background: rgba(var(--v-border-color), .1); border-radius: 5px; padding: 1px 7px;
  text-decoration: none; }
.ag-neighbor { font-size: .66rem; color: rgb(var(--v-theme-on-surface), .6);
  border: 1px dashed rgba(var(--v-border-color), .4); border-radius: 5px; padding: 1px 7px; }
.ag-mac { display: inline-flex; align-items: center; gap: 5px; font-family: ui-monospace, monospace; font-size: .64rem;
  color: rgb(var(--v-theme-on-surface), .7); background: rgba(var(--v-border-color), .1); border-radius: 5px; padding: 1px 6px; }
.ag-macv { color: rgb(var(--v-theme-primary)); font-family: var(--v-font-family, inherit); }
.ag-macmore { font-size: .62rem; color: rgb(var(--v-theme-info)); }
.ag-dev--link { color: rgb(var(--v-theme-info)); cursor: pointer; }
.ag-dev--link:hover { background: rgba(var(--v-theme-info), .14); color: rgb(var(--v-theme-info)); }
.ag-dev .v-icon { opacity: .6; }
.ag-inherit { font-family: ui-monospace, monospace; font-size: .64rem; color: rgb(var(--v-theme-warning));
  border: 1px solid rgba(var(--v-theme-warning), .35); border-radius: 5px; padding: 1px 6px; }
.ag-age { font-family: ui-monospace, monospace; font-size: .76rem;
  color: rgb(var(--v-theme-on-surface), .5); }
.ag-ackd { font-size: .62rem; font-weight: 700; color: rgb(var(--v-theme-success)); text-transform: uppercase; letter-spacing: .05em; }
.ag-actions { display: flex; gap: 6px; flex-shrink: 0; }
</style>
