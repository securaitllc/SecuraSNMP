<script lang="ts" setup>
import { useRouter } from 'vue-router'
import { api } from '@/composables/useApi'
import { isIos, isStandalone, setWantsDesktopSite } from '@/composables/useMobile'
import { useNotify } from '@/composables/useNotify'
import { type Severity, usePush } from '@/composables/usePush'
import { useAuthStore } from '@/stores/auth'

definePage({ meta: { layout: 'mobile', title: 'Me' } })

const auth = useAuthStore()
const router = useRouter()
const notify = useNotify()
const push = usePush()

const severity = ref<Severity>('critical')
const version = ref('')
const support = computed(() => push.support())

onMounted(async () => {
  try {
    await push.refresh()
    if (push.mine.value)
      severity.value = push.mine.value.min_severity
  }
  catch {
    // push status is optional — the rest of the screen still works
  }
  try {
    version.value = (await api<{ version: string }>('/api/version')).version
  }
  catch {}
})

async function togglePush(on: boolean) {
  try {
    if (on)
      await push.subscribe(severity.value)
    else
      await push.unsubscribe()
    notify.success(on ? 'This phone will be alerted' : 'Push turned off for this phone')
  }
  catch (e: any) {
    notify.error('Push could not be changed', e?.message ?? e?.data?.message)
  }
}

async function changeSeverity(v: Severity) {
  severity.value = v
  if (push.subscribed.value) {
    try {
      await push.subscribe(v)
      notify.success(`Alerting from ${v} up`)
    }
    catch (e: any) {
      notify.error('Could not update', e?.message)
    }
  }
}

async function sendTest() {
  try {
    const ok = await push.test()
    ok ? notify.success('Test sent — check the tray') : notify.error('The push service refused the test')
  }
  catch (e: any) {
    notify.error('Test failed', e?.data?.message)
  }
}

function desktop() {
  setWantsDesktopSite(true)
  router.push('/')
}

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div>
    <section class="m-section">
      <h2>Signed in</h2>
      <div class="m-kv">
        <div><span>Name</span><span>{{ auth.user?.name }}</span></div>
        <div><span>Role</span><span>{{ auth.user?.role }}</span></div>
      </div>
    </section>

    <section class="m-section">
      <h2>Alarm push on this phone</h2>

      <div
        v-if="push.status.value && !push.status.value.enabled"
        class="m-empty"
      >
        Push is not configured on this server yet (VAPID keys).
      </div>

      <div
        v-else-if="support === 'needs-install'"
        class="m-kv"
      >
        <div style="display:block">
          <span>iPhone only allows notifications for an installed app.</span><br>
          <span style="font-family:inherit;text-align:start">Tap <strong>Share</strong> in Safari → <strong>Add to Home Screen</strong>, then open Nodus from the home screen and come back here.</span>
        </div>
      </div>

      <div
        v-else-if="support === 'denied'"
        class="m-empty"
      >
        Notifications are blocked for Nodus in this phone's settings. Allow them, then return here.
      </div>

      <div
        v-else-if="support !== 'ok'"
        class="m-empty"
      >
        This browser cannot receive push ({{ support }}).
      </div>

      <template v-else>
        <div class="m-kv">
          <div>
            <span>Alert this phone</span>
            <span><VSwitch
              :model-value="push.subscribed.value"
              :loading="push.busy.value"
              hide-details
              density="compact"
              inset
              @update:model-value="v => togglePush(!!v)"
            /></span>
          </div>
          <div>
            <span>From severity</span>
            <span>
              <VBtnToggle
                :model-value="severity"
                density="compact"
                mandatory
                variant="outlined"
                @update:model-value="changeSeverity"
              >
                <VBtn
                  value="critical"
                  size="small"
                >Critical</VBtn>
                <VBtn
                  value="warning"
                  size="small"
                >Warning+</VBtn>
                <VBtn
                  value="info"
                  size="small"
                >All</VBtn>
              </VBtnToggle>
            </span>
          </div>
          <div v-if="push.mine.value?.last_sent_at">
            <span>Last push</span><span>{{ new Date(push.mine.value.last_sent_at).toLocaleString() }}</span>
          </div>
        </div>
        <div
          v-if="push.subscribed.value"
          class="m-actions"
        >
          <VBtn
            variant="tonal"
            prepend-icon="ri-notification-3-line"
            @click="sendTest"
          >
            Send test
          </VBtn>
        </div>
        <p
          v-if="isIos() && isStandalone()"
          class="m-empty"
          style="padding:8px 0 0;text-align:start"
        >
          Critical alarms ring through; warnings and info arrive silently in the tray.
        </p>
      </template>
    </section>

    <section class="m-section">
      <h2>App</h2>
      <div class="m-kv">
        <div><span>Version</span><span>{{ version || '—' }}</span></div>
        <div><span>Installed</span><span>{{ isStandalone() ? 'home screen' : 'browser tab' }}</span></div>
      </div>
      <div class="m-actions">
        <VBtn
          variant="outlined"
          prepend-icon="ri-computer-line"
          @click="desktop"
        >
          Use desktop site
        </VBtn>
        <VBtn
          variant="outlined"
          color="error"
          prepend-icon="ri-logout-box-line"
          @click="logout"
        >
          Sign out
        </VBtn>
      </div>
    </section>
  </div>
</template>
