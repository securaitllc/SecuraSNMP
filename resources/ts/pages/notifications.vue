<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import { formatDateTime } from '@/utils/datetime'
import type { Device, MaintenanceWindow, NotificationChannel, NotificationLog, Site } from '@/types/models'

definePage({ meta: { layout: 'default' } })

const auth = useAuthStore()

// ---- SMTP server settings ----------------------------------------------
interface MailSettings {
  host: string | null
  port: number
  encryption: 'tls' | 'ssl' | 'none'
  username: string | null
  has_password: boolean
  from_address: string | null
  from_name: string | null
  enabled: boolean
}
const mail = ref<MailSettings>({
  host: '', port: 587, encryption: 'tls', username: '',
  has_password: false, from_address: '', from_name: 'Nodus Alerts', enabled: false,
})
const mailPassword = ref('')          // only sent when the operator types a new one
const mailBusy = ref(false)
const mailMsg = ref<{ type: 'success' | 'error', text: string } | null>(null)
const mailTestTo = ref('')

async function loadMail() {
  mail.value = await api<MailSettings>('/api/mail-settings')
  mailTestTo.value = mail.value.from_address ?? ''
}

function applyOffice365() {
  mail.value.host = 'smtp.office365.com'
  mail.value.port = 587
  mail.value.encryption = 'tls'
}

async function saveMail() {
  mailBusy.value = true
  mailMsg.value = null
  try {
    mail.value = await api<MailSettings>('/api/mail-settings', {
      method: 'PUT',
      body: { ...mail.value, password: mailPassword.value || undefined },
    })
    mailPassword.value = ''
    mailMsg.value = { type: 'success', text: 'SMTP settings saved.' }
  }
  catch (e: any) {
    mailMsg.value = { type: 'error', text: e?.data?.message ?? 'Save failed — check the fields.' }
  }
  finally { mailBusy.value = false }
}

async function sendMailTest() {
  mailBusy.value = true
  mailMsg.value = null
  try {
    const res = await api<{ ok: boolean, message: string }>('/api/mail-settings/test', {
      method: 'POST',
      body: { to: mailTestTo.value },
    })
    mailMsg.value = { type: 'success', text: res.message }
  }
  catch (e: any) {
    mailMsg.value = { type: 'error', text: e?.data?.error ?? e?.data?.message ?? 'Test failed — check SMTP host, credentials and firewall.' }
  }
  finally { mailBusy.value = false }
}

// ---- Channels -----------------------------------------------------------
const channels = ref<NotificationChannel[]>([])
const isChannelDialogOpen = ref(false)
const channelError = ref('')
const editingChannel = ref<NotificationChannel | null>(null)

function emptyChannel() {
  return { name: '', type: 'webhook' as 'email' | 'slack' | 'webhook' | 'teams', min_severity: 'warning' as const, enabled: true, config: { email: '', webhook_url: '', url: '' } }
}
const channelForm = ref(emptyChannel())

const channelHeaders = [
  { title: 'Name', key: 'name' },
  { title: 'Type', key: 'type' },
  { title: 'Min severity', key: 'min_severity' },
  { title: 'Destination', key: 'destination' },
  { title: 'Enabled', key: 'enabled' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function loadChannels() {
  const res = await api<{ data: NotificationChannel[] }>('/api/notification-channels')
  channels.value = res.data
}

function openChannelDialog() {
  editingChannel.value = null
  channelForm.value = emptyChannel()
  channelError.value = ''
  isChannelDialogOpen.value = true
}

async function saveChannel() {
  channelError.value = ''
  const cfg: Record<string, string> = {}
  if (channelForm.value.type === 'email') cfg.email = channelForm.value.config.email
  if (channelForm.value.type === 'slack' || channelForm.value.type === 'teams') cfg.webhook_url = channelForm.value.config.webhook_url
  if (channelForm.value.type === 'webhook') cfg.url = channelForm.value.config.url

  const payload = { name: channelForm.value.name, type: channelForm.value.type, min_severity: channelForm.value.min_severity, enabled: channelForm.value.enabled, config: cfg }
  try {
    if (editingChannel.value)
      await api(`/api/notification-channels/${editingChannel.value.id}`, { method: 'PUT', body: payload })
    else
      await api('/api/notification-channels', { method: 'POST', body: payload })
    isChannelDialogOpen.value = false
    await loadChannels()
  }
  catch {
    channelError.value = 'Could not save. Check the destination matches the channel type.'
  }
}

async function testChannel(channel: NotificationChannel) {
  const res = await api<{ status: string, error: string | null }>(`/api/notification-channels/${channel.id}/test`, { method: 'POST' })
  alert(res.status === 'sent' ? 'Test notification sent.' : `Test failed: ${res.error ?? 'unknown error'}`)
  await loadLogs()
}

async function removeChannel(channel: NotificationChannel) {
  if (!confirm(`Delete channel "${channel.name}"?`)) return
  await api(`/api/notification-channels/${channel.id}`, { method: 'DELETE' })
  await loadChannels()
}

// ---- Maintenance windows ------------------------------------------------
const windows = ref<MaintenanceWindow[]>([])
const sites = ref<Site[]>([])
const devices = ref<Device[]>([])
const isWindowDialogOpen = ref(false)
const windowError = ref('')

function emptyWindow() {
  const now = new Date()
  const plus2h = new Date(now.getTime() + 2 * 3600 * 1000)
  const local = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16)
  return { name: '', starts_at: local(now), ends_at: local(plus2h), site_id: null as number | null, device_id: null as number | null, reason: '' }
}
const windowForm = ref(emptyWindow())

const windowHeaders = [
  { title: 'Name', key: 'name' },
  { title: 'Scope', key: 'scope' },
  { title: 'Start', key: 'starts_at' },
  { title: 'End', key: 'ends_at' },
  { title: 'Active', key: 'active' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function loadWindows() {
  windows.value = await api<MaintenanceWindow[]>('/api/maintenance-windows')
}

function windowActive(w: MaintenanceWindow): boolean {
  const now = Date.now()
  return new Date(w.starts_at).getTime() <= now && new Date(w.ends_at).getTime() >= now
}

function windowScope(w: MaintenanceWindow): string {
  if (w.device) return `Device: ${w.device.name}`
  if (w.site) return `Site: ${w.site.name}`
  return 'Global (all)'
}

function openWindowDialog() {
  windowForm.value = emptyWindow()
  windowError.value = ''
  isWindowDialogOpen.value = true
}

async function saveWindow() {
  windowError.value = ''
  try {
    await api('/api/maintenance-windows', {
      method: 'POST',
      body: {
        name: windowForm.value.name,
        starts_at: new Date(windowForm.value.starts_at).toISOString(),
        ends_at: new Date(windowForm.value.ends_at).toISOString(),
        site_id: windowForm.value.site_id,
        device_id: windowForm.value.device_id,
        reason: windowForm.value.reason || null,
      },
    })
    isWindowDialogOpen.value = false
    await loadWindows()
  }
  catch {
    windowError.value = 'Could not save. End must be after start.'
  }
}

async function removeWindow(w: MaintenanceWindow) {
  if (!confirm(`Delete maintenance window "${w.name}"?`)) return
  await api(`/api/maintenance-windows/${w.id}`, { method: 'DELETE' })
  await loadWindows()
}

// ---- Notification log ---------------------------------------------------
const logs = ref<NotificationLog[]>([])
const logHeaders = [
  { title: 'Time', key: 'created_at' },
  { title: 'Event', key: 'event' },
  { title: 'Severity', key: 'severity' },
  { title: 'Channel', key: 'channel' },
  { title: 'Status', key: 'status' },
  { title: 'Subject', key: 'subject' },
]
const severityColor: Record<string, string> = { info: 'info', warning: 'warning', critical: 'error' }
const statusColor: Record<string, string> = { sent: 'success', failed: 'error', suppressed: 'secondary' }

async function loadLogs() {
  logs.value = await api<NotificationLog[]>('/api/notification-logs')
}

onMounted(async () => {
  if (!auth.isAdmin) return
  await Promise.all([loadChannels(), loadWindows(), loadLogs(),
    api<{ data: Device[] }>('/api/devices').then(r => (devices.value = r.data)),
    api<Site[]>('/api/sites').then(r => (sites.value = r)),
    loadMail(),
  ])
})
</script>

<template>
  <div v-if="!auth.isAdmin">
    <VAlert type="info" variant="tonal">
      Notifications are an administrator function.
    </VAlert>
  </div>

  <div v-else class="d-flex flex-column gap-6">
    <!-- SMTP server -->
    <VCard title="Email (SMTP) Server">
      <template #append>
        <VBtn variant="tonal" size="small" prepend-icon="ri-microsoft-line" @click="applyOffice365">
          Office 365 preset
        </VBtn>
      </template>
      <VCardText>
        <VAlert type="info" variant="tonal" density="compact" class="mb-4">
          Configure the mail relay Nodus uses to send alert emails. For Office 365, use an account
          (or shared mailbox with SMTP AUTH enabled) — host <code>smtp.office365.com</code>, port
          <code>587</code>, STARTTLS. Leave disabled to fall back to the container's env config.
        </VAlert>

        <VRow dense>
          <VCol cols="12" md="6">
            <VTextField v-model="mail.host" label="SMTP host" placeholder="smtp.office365.com" density="comfortable" />
          </VCol>
          <VCol cols="6" md="3">
            <VTextField v-model.number="mail.port" label="Port" type="number" density="comfortable" />
          </VCol>
          <VCol cols="6" md="3">
            <VSelect
              v-model="mail.encryption"
              :items="[{ title: 'STARTTLS (587)', value: 'tls' }, { title: 'SSL/TLS (465)', value: 'ssl' }, { title: 'None', value: 'none' }]"
              label="Encryption"
              density="comfortable"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="mail.username" label="Username" placeholder="alerts@yourdomain.com" autocomplete="off" density="comfortable" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField
              v-model="mailPassword"
              label="Password"
              type="password"
              autocomplete="new-password"
              :placeholder="mail.has_password ? '•••••••• (unchanged)' : 'App password'"
              density="comfortable"
            />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="mail.from_address" label="From address" placeholder="alerts@yourdomain.com" density="comfortable" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="mail.from_name" label="From name" placeholder="Nodus Alerts" density="comfortable" />
          </VCol>
          <VCol cols="12">
            <VSwitch v-model="mail.enabled" color="primary" label="Use these SMTP settings for outgoing email" density="comfortable" hide-details />
          </VCol>
        </VRow>

        <VAlert
          v-if="mailMsg"
          :type="mailMsg.type"
          variant="tonal"
          density="compact"
          class="mt-2"
        >
          {{ mailMsg.text }}
        </VAlert>

        <div class="d-flex align-center flex-wrap ga-3 mt-4">
          <VBtn color="primary" :loading="mailBusy" @click="saveMail">Save settings</VBtn>
          <VDivider vertical class="mx-1" />
          <VTextField
            v-model="mailTestTo"
            label="Send test to"
            placeholder="you@yourdomain.com"
            density="compact"
            hide-details
            style="max-width: 260px;"
          />
          <VBtn variant="tonal" :loading="mailBusy" :disabled="!mailTestTo" prepend-icon="ri-send-plane-line" @click="sendMailTest">
            Send test
          </VBtn>
        </div>
      </VCardText>
    </VCard>

    <!-- Channels -->
    <VCard title="Notification Channels">
      <template #append>
        <VBtn @click="openChannelDialog">Add Channel</VBtn>
      </template>
      <VCardText class="pb-0">
        <VAlert type="info" variant="tonal" density="compact">
          Alerts (device/circuit/interface/tunnel down, orchestrator alarms) are delivered to enabled channels that meet the alert's severity. Recovery notifications are sent when they clear.
        </VAlert>
      </VCardText>
      <VDataTable :headers="channelHeaders" :items="channels" density="compact">
        <template #item.type="{ item }">{{ item.type.toUpperCase() }}</template>
        <template #item.enabled="{ item }">
          <VChip :color="item.enabled ? 'success' : 'secondary'" size="x-small" label>{{ item.enabled ? 'on' : 'off' }}</VChip>
        </template>
        <template #item.destination="{ item }">{{ item.destination ?? '—' }}</template>
        <template #item.actions="{ item }">
          <VBtn size="x-small" variant="text" @click="testChannel(item)">Test</VBtn>
          <VBtn icon="ri-delete-bin-line" variant="text" size="small" @click="removeChannel(item)" />
        </template>
      </VDataTable>
    </VCard>

    <!-- Maintenance windows -->
    <VCard title="Maintenance Windows">
      <template #append>
        <VBtn @click="openWindowDialog">Add Window</VBtn>
      </template>
      <VCardText class="pb-0">
        <VAlert type="info" variant="tonal" density="compact">
          During an active window, notifications are suppressed for the matching scope (global, a site, or a device).
        </VAlert>
      </VCardText>
      <VDataTable :headers="windowHeaders" :items="windows" density="compact">
        <template #item.scope="{ item }">{{ windowScope(item) }}</template>
        <template #item.starts_at="{ item }">{{ formatDateTime(item.starts_at) }}</template>
        <template #item.ends_at="{ item }">{{ formatDateTime(item.ends_at) }}</template>
        <template #item.active="{ item }">
          <VChip :color="windowActive(item) ? 'warning' : 'secondary'" size="x-small" label>{{ windowActive(item) ? 'active' : 'scheduled' }}</VChip>
        </template>
        <template #item.actions="{ item }">
          <VBtn icon="ri-delete-bin-line" variant="text" size="small" @click="removeWindow(item)" />
        </template>
      </VDataTable>
    </VCard>

    <!-- Log -->
    <VCard title="Recent Notifications">
      <template #append>
        <VBtn variant="text" icon="ri-refresh-line" @click="loadLogs" />
      </template>
      <VDataTable :headers="logHeaders" :items="logs" density="compact" :items-per-page="15">
        <template #item.created_at="{ item }">{{ formatDateTime(item.created_at) }}</template>
        <template #item.severity="{ item }">
          <VChip :color="severityColor[item.severity]" size="x-small" label>{{ item.severity }}</VChip>
        </template>
        <template #item.channel="{ item }">{{ item.channel?.name ?? '—' }}</template>
        <template #item.status="{ item }">
          <VChip :color="statusColor[item.status]" size="x-small" label>{{ item.status }}</VChip>
        </template>
      </VDataTable>
    </VCard>

    <!-- Channel dialog -->
    <VDialog v-model="isChannelDialogOpen" max-width="560">
      <VCard title="Add Notification Channel">
        <VCardText>
          <VAlert v-if="channelError" type="error" variant="tonal" class="mb-4">{{ channelError }}</VAlert>
          <VForm @submit.prevent="saveChannel">
            <VRow>
              <VCol cols="12" sm="6">
                <VTextField v-model="channelForm.name" label="Name" placeholder="Ops Slack" />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect v-model="channelForm.type" :items="['email', 'slack', 'teams', 'webhook']" label="Type" />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect v-model="channelForm.min_severity" :items="['info', 'warning', 'critical']" label="Minimum severity" />
              </VCol>
              <VCol cols="12" sm="6">
                <VSwitch v-model="channelForm.enabled" label="Enabled" color="primary" />
              </VCol>
              <VCol cols="12" v-if="channelForm.type === 'email'">
                <VTextField v-model="channelForm.config.email" label="Email address" type="email" />
              </VCol>
              <VCol cols="12" v-else-if="channelForm.type === 'slack'">
                <VTextField v-model="channelForm.config.webhook_url" label="Slack Incoming Webhook URL" />
              </VCol>
              <VCol cols="12" v-else-if="channelForm.type === 'teams'">
                <VTextField
                  v-model="channelForm.config.webhook_url"
                  label="Teams workflow URL"
                  placeholder="https://prod-00.eastus.logic.azure.com/workflows/.../invoke?sig=..."
                  hint="Power Automate “When a Teams webhook request is received”, or an Azure Function relaying into Teams. Office 365 connector URLs are retired by Microsoft; an existing *.webhook.office.com URL still works while it lasts."
                  persistent-hint
                />
              </VCol>
              <VCol cols="12" v-else>
                <VTextField v-model="channelForm.config.url" label="Webhook URL (JSON POST)" />
              </VCol>
              <VCol cols="12"><VBtn type="submit">Save</VBtn></VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Window dialog -->
    <VDialog v-model="isWindowDialogOpen" max-width="620">
      <VCard title="Add Maintenance Window">
        <VCardText>
          <VAlert v-if="windowError" type="error" variant="tonal" class="mb-4">{{ windowError }}</VAlert>
          <VForm @submit.prevent="saveWindow">
            <VRow>
              <VCol cols="12" sm="6">
                <VTextField v-model="windowForm.name" label="Name" placeholder="Core upgrade" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model="windowForm.reason" label="Reason (optional)" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model="windowForm.starts_at" label="Start" type="datetime-local" />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField v-model="windowForm.ends_at" label="End" type="datetime-local" />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect v-model="windowForm.site_id" :items="sites" item-title="name" item-value="id" label="Site (optional)" clearable />
              </VCol>
              <VCol cols="12" sm="6">
                <VSelect v-model="windowForm.device_id" :items="devices" item-title="name" item-value="id" label="Device (optional)" clearable />
              </VCol>
              <VCol cols="12">
                <div class="text-caption text-medium-emphasis">Leave site and device empty for a global window (suppresses everything).</div>
              </VCol>
              <VCol cols="12"><VBtn type="submit">Save</VBtn></VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
