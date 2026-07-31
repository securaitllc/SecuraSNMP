<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { api } from '@/composables/useApi'
import { themeConfig } from '@themeConfig'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'

definePage({
  meta: {
    layout: 'blank',
  },
})

const auth = useAuthStore()
const router = useRouter()

const step = ref<'scan' | 'recovery'>('scan')
const qrSvg = ref('')
const secret = ref('')
const code = ref('')
const recoveryCodes = ref<string[]>([])
const loading = ref(true)
const submitting = ref(false)
const error = ref('')

onMounted(async () => {
  try {
    const res = await api<{ qr_svg: string, secret: string }>('/api/2fa/enroll', { method: 'POST' })
    qrSvg.value = res.qr_svg
    secret.value = res.secret
  }
  catch (e: any) {
    error.value = e?.data?.message ?? 'Could not start two-factor setup.'
  }
  finally {
    loading.value = false
  }
})

async function confirm() {
  error.value = ''
  submitting.value = true
  try {
    const res = await api<{ recovery_codes: string[] }>('/api/2fa/confirm', { method: 'POST', body: { code: code.value } })
    recoveryCodes.value = res.recovery_codes
    step.value = 'recovery'
  }
  catch (e: any) {
    error.value = e?.data?.errors?.code?.[0] ?? e?.data?.message ?? 'That code is not valid.'
  }
  finally {
    submitting.value = false
  }
}

function copyCodes() {
  navigator.clipboard?.writeText(recoveryCodes.value.join('\n')).catch(() => {})
}

async function finish() {
  // Refresh the user so needsMfaSetup clears, then leave the setup gate.
  await auth.fetchUser()
  await router.push({ name: 'root' })
}
</script>

<template>
  <div class="mfa-wrap">
    <VCard flat :max-width="460" class="pa-6 pa-lg-8 mfa-card">
      <div class="app-logo mb-5">
        <VNodeRenderer :nodes="themeConfig.app.logo" />
        <h1 class="app-logo-title">{{ themeConfig.app.title }}</h1>
      </div>

      <template v-if="step === 'scan'">
        <h4 class="text-h5 mb-1">Set up two-factor authentication</h4>
        <p class="text-medium-emphasis mb-5">
          Two-factor is required on this account. Scan the QR with Google Authenticator, Microsoft Authenticator, or 1Password, then enter the 6-digit code.
        </p>

        <VAlert v-if="error" type="error" variant="tonal" class="mb-4">{{ error }}</VAlert>

        <div v-if="loading" class="text-center py-8">
          <VProgressCircular indeterminate color="primary" />
        </div>

        <template v-else>
          <div class="mfa-qr mb-4" v-html="qrSvg" />
          <div class="mfa-secret mb-5">
            <span class="text-caption text-medium-emphasis">Can't scan? Enter this key manually</span>
            <code class="mfa-secret__key">{{ secret }}</code>
          </div>

          <VForm @submit.prevent="confirm">
            <VOtpInput v-model="code" :length="6" type="number" class="mb-4" />
            <VBtn block type="submit" :loading="submitting" :disabled="code.length !== 6">Verify &amp; enable</VBtn>
          </VForm>
        </template>
      </template>

      <template v-else>
        <h4 class="text-h5 mb-1">Save your recovery codes</h4>
        <p class="text-medium-emphasis mb-4">
          Two-factor is now enabled. Store these one-time codes somewhere safe — each lets you sign in once if you lose your authenticator.
        </p>

        <div class="mfa-codes mb-4">
          <code v-for="c in recoveryCodes" :key="c">{{ c }}</code>
        </div>

        <div class="d-flex ga-3">
          <VBtn variant="tonal" prepend-icon="ri-file-copy-line" @click="copyCodes">Copy</VBtn>
          <VBtn block color="primary" @click="finish">I've saved them — continue</VBtn>
        </div>
      </template>
    </VCard>
  </div>
</template>

<style scoped>
.mfa-wrap {
  min-block-size: 100dvh;
  display: flex; align-items: center; justify-content: center;
  padding: 1.5rem;
  background: rgb(var(--v-theme-background));
}
.mfa-card { inline-size: 100%; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.app-logo { display: flex; align-items: center; gap: 10px; }
.app-logo-title { font-size: 1.5rem; font-weight: 700; }
.mfa-qr {
  display: flex; justify-content: center; padding: 14px;
  background: #fff; border-radius: 12px; inline-size: fit-content; margin-inline: auto;
}
.mfa-qr :deep(svg) { inline-size: 200px; block-size: 200px; display: block; }
.mfa-secret { display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; }
.mfa-secret__key {
  font-family: ui-monospace, Menlo, monospace; letter-spacing: 2px; font-size: 0.95rem;
  background: rgba(var(--v-theme-on-surface), 0.06); padding: 6px 12px; border-radius: 8px;
}
.mfa-codes {
  display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
  padding: 14px; border-radius: 10px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.mfa-codes code { font-family: ui-monospace, Menlo, monospace; font-size: 0.9rem; letter-spacing: 1px; text-align: center; }
</style>
