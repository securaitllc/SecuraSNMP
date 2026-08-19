<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'
import { VNodeRenderer } from '@layouts/components/VNodeRenderer'
import { themeConfig } from '@themeConfig'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

const auth = useAuthStore()
const router = useRouter()

const form = ref({
  email: '',
  password: '',
  code: '',
  remember: false,
})

const isPasswordVisible = ref(false)
const isSubmitting = ref(false)
const errorMessage = ref('')
const step = ref<'credentials' | 'code'>('credentials')

async function onSubmit() {
  errorMessage.value = ''
  isSubmitting.value = true

  try {
    const result = await auth.login(form.value.email, form.value.password, step.value === 'code' ? form.value.code : undefined, form.value.remember)
    if (result === '2fa') {
      // Credentials were right — collect the authenticator code next.
      step.value = 'code'
      return
    }
    // The router guard routes to two-factor setup if enrolment is still required.
    await router.push({ name: 'root' })
  }
  catch (e: any) {
    errorMessage.value = step.value === 'code'
      ? (e?.data?.errors?.code?.[0] ?? 'That two-factor code is not valid.')
      : 'Invalid email or password, or the account is inactive.'
  }
  finally {
    isSubmitting.value = false
  }
}

function backToCredentials() {
  step.value = 'credentials'
  form.value.code = ''
  errorMessage.value = ''
}
</script>

<template>
  <a href="javascript:void(0)">
    <div class="app-logo auth-logo">
      <VNodeRenderer :nodes="themeConfig.app.logo" />
      <h1 class="app-logo-title">
        {{ themeConfig.app.title }}
      </h1>
    </div>
  </a>

  <VRow
    no-gutters
    class="auth-wrapper"
  >
    <VCol
      md="8"
      class="d-none d-md-flex align-center justify-center position-relative"
    >
      <div class="auth-scene-wrap">
        <svg
          class="auth-scene"
          viewBox="0 0 680 720"
          fill="none"
          role="img"
          aria-label="Nodus — network operations"
        >
          <defs>
            <pattern id="ns-grid" width="26" height="26" patternUnits="userSpaceOnUse">
              <circle cx="1" cy="1" r="1" class="ns-grid-dot" />
            </pattern>
            <radialGradient id="ns-glow" cx="50%" cy="42%" r="55%">
              <stop offset="0%" class="ns-glow-in" />
              <stop offset="100%" class="ns-glow-out" />
            </radialGradient>
          </defs>

          <rect width="680" height="720" fill="url(#ns-grid)" />
          <rect width="680" height="720" fill="url(#ns-glow)" />

          <!-- tier labels -->
          <text class="ns-tier" x="40" y="96">WAN</text>
          <text class="ns-tier" x="40" y="360">CORE</text>
          <text class="ns-tier" x="40" y="600">ACCESS</text>

          <!-- links (drawn first, under nodes) -->
          <g class="ns-link">
            <path d="M340 120 C340 200 340 240 300 300" />
            <path d="M340 120 C340 200 340 240 380 300" />
            <path d="M300 348 C240 430 180 470 150 548" />
            <path d="M300 348 C300 440 300 470 300 548" />
            <path d="M380 348 C440 430 500 470 530 548" />
            <path d="M380 348 C420 450 430 500 415 548" />
          </g>
          <path class="ns-flow" d="M340 120 C340 200 340 240 380 300" />
          <path class="ns-flow ns-flow-2" d="M380 348 C440 430 500 470 530 548" />
          <path class="ns-flow ns-flow-3" d="M300 348 C300 440 300 470 300 548" />

          <!-- WAN cloud -->
          <g transform="translate(304 72)">
            <path class="ns-cloud" d="M14 30a12 12 0 0 1 1-23 16 16 0 0 1 31 3 10 10 0 0 1-2 20z" />
          </g>

          <!-- CORE: the Nodus knot + two core routers -->
          <g class="ns-core-ring" transform="translate(340 324)">
            <circle r="46" />
          </g>
          <image href="/favicon.svg" x="304" y="288" width="72" height="72" />

          <g class="ns-node" transform="translate(150 300)">
            <rect x="-42" y="-24" width="84" height="48" rx="10" />
            <path class="ns-glyph" d="M-22 0h44M-12 -8l-8 8 8 8M12 -8l8 8-8 8" />
            <circle class="ns-dot ok" cx="34" cy="-16" r="4.5" />
            <text class="ns-cap" x="0" y="40">core-a</text>
          </g>
          <g class="ns-node" transform="translate(530 300)">
            <rect x="-42" y="-24" width="84" height="48" rx="10" />
            <path class="ns-glyph" d="M-22 0h44M-12 -8l-8 8 8 8M12 -8l8 8-8 8" />
            <circle class="ns-dot ok" cx="34" cy="-16" r="4.5" />
            <text class="ns-cap" x="0" y="40">core-b</text>
          </g>
          <path class="ns-link ns-link-ha" d="M192 300 L488 300" />

          <!-- ACCESS row -->
          <g class="ns-node" transform="translate(150 576)">
            <rect x="-40" y="-22" width="80" height="44" rx="9" />
            <path class="ns-glyph" d="M-18 -6v12M-18 -6l-4 4M-18 -6l4 4M0 6V-6M0 6l-4-4M0 6l4-4M18 -2v8" />
            <circle class="ns-dot ok" cx="32" cy="-14" r="4.5" />
          </g>
          <g class="ns-node" transform="translate(300 576)">
            <rect x="-40" y="-22" width="80" height="44" rx="9" />
            <path class="ns-glyph" d="M-24 -8h48v16H-24zM-24 0h48" />
            <circle class="ns-dot ok" cx="32" cy="-14" r="4.5" />
          </g>
          <g class="ns-node" transform="translate(415 576)">
            <rect x="-40" y="-22" width="80" height="44" rx="9" />
            <path class="ns-glyph" d="M-20 -8h40v14h-40zM-14 -1h2M-8 -1h2M-2 -1h2" />
            <circle class="ns-dot warn" cx="32" cy="-14" r="4.5" />
          </g>
          <g class="ns-node" transform="translate(530 576)">
            <rect x="-40" y="-22" width="80" height="44" rx="9" />
            <path class="ns-glyph" d="M-14 -8a8 6 0 0 1 8-5 10 7 0 0 1 19 1 6 6 0 0 1-1 12h-24a6 6 0 0 1-2-8z" />
            <circle class="ns-dot ok" cx="32" cy="-14" r="4.5" />
          </g>
        </svg>
      </div>
    </VCol>
    <VCol
      cols="12"
      md="4"
      class="auth-card-v2 d-flex align-center justify-center"
      style="background-color: rgb(var(--v-theme-surface));"
    >
      <VCard
        flat
        :max-width="500"
        class="mt-12 mt-sm-0 pa-5 pa-lg-7"
      >
        <VCardText>
          <h4 class="text-h4 mb-1">
            Welcome to <span class="text-capitalize">{{ themeConfig.app.title }}</span>
          </h4>

          <p class="mb-0">
            Sign in to your network operations console
          </p>
        </VCardText>

        <VCardText>
          <VAlert
            v-if="errorMessage"
            type="error"
            variant="tonal"
            class="mb-4"
          >
            {{ errorMessage }}
          </VAlert>

          <VForm @submit.prevent="onSubmit">
            <VRow>
              <template v-if="step === 'credentials'">
                <VCol cols="12">
                  <VTextField
                    v-model="form.email"
                    autofocus
                    label="Email"
                    type="email"
                    placeholder="admin@example.com"
                  />
                </VCol>

                <VCol cols="12">
                  <VTextField
                    v-model="form.password"
                    label="Password"
                    placeholder="············"
                    :type="isPasswordVisible ? 'text' : 'password'"
                    autocomplete="current-password"
                    :append-inner-icon="isPasswordVisible ? 'ri-eye-off-line' : 'ri-eye-line'"
                    @click:append-inner="isPasswordVisible = !isPasswordVisible"
                  />

                  <VCheckbox
                    v-model="form.remember"
                    label="Keep me signed in on this device"
                    density="compact"
                    hide-details
                    class="mt-2"
                  />

                  <VBtn
                    block
                    type="submit"
                    class="mt-4"
                    :loading="isSubmitting"
                  >
                    Login
                  </VBtn>
                </VCol>
              </template>

              <template v-else>
                <VCol cols="12">
                  <VTextField
                    v-model="form.code"
                    autofocus
                    label="Authenticator or recovery code"
                    placeholder="123456"
                    autocomplete="one-time-code"
                    @keyup.enter="onSubmit"
                  />
                  <VBtn
                    block
                    type="submit"
                    class="mt-4"
                    :loading="isSubmitting"
                    :disabled="!form.code.trim()"
                  >
                    Verify
                  </VBtn>
                  <VBtn
                    block
                    variant="text"
                    size="small"
                    class="mt-2"
                    @click="backToCredentials"
                  >
                    Back
                  </VBtn>
                </VCol>
              </template>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss">
@use "@core-scss/template/pages/page-auth";

// Bigger, more prominent Nodus lockup on the login screen.
.auth-logo {
  .app-logo-title { font-size: 1.9rem !important; font-weight: 700; letter-spacing: .2px; }
  :deep(svg), svg { block-size: 2.1rem; inline-size: 2.1rem; }
}

.auth-scene-wrap {
  inline-size: 100%;
  block-size: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 3rem 2.5rem;
}
.auth-scene {
  inline-size: 100%;
  max-inline-size: 620px;
  block-size: auto;

  .ns-grid-dot { fill: rgba(var(--v-theme-on-surface), 0.05); }
  .ns-glow-in { stop-color: rgba(var(--v-theme-primary), 0.14); }
  .ns-glow-out { stop-color: rgba(var(--v-theme-primary), 0); }

  .ns-tier {
    fill: rgba(var(--v-theme-on-surface), 0.32);
    font-family: ui-monospace, Menlo, monospace;
    font-size: 13px; font-weight: 600; letter-spacing: 2px;
  }
  .ns-cap {
    fill: rgba(var(--v-theme-on-surface), 0.4);
    font-family: ui-monospace, Menlo, monospace;
    font-size: 12px; text-anchor: middle;
  }

  .ns-link { stroke: rgba(var(--v-theme-on-surface), 0.16); stroke-width: 2; fill: none; }
  .ns-link-ha { stroke-dasharray: 3 7; stroke: rgba(var(--v-theme-primary), 0.5); }
  .ns-flow {
    stroke: rgb(var(--v-theme-primary)); stroke-width: 2.5; fill: none;
    stroke-dasharray: 5 12; stroke-linecap: round; opacity: .9;
  }

  .ns-core-ring circle { fill: rgba(var(--v-theme-primary), 0.06); stroke: rgba(var(--v-theme-primary), 0.45); stroke-width: 1.5; }
  .ns-cloud { fill: none; stroke: rgba(var(--v-theme-on-surface), 0.5); stroke-width: 2.4; stroke-linejoin: round; }

  .ns-node rect { fill: rgba(var(--v-theme-on-surface), 0.035); stroke: rgba(var(--v-theme-on-surface), 0.28); stroke-width: 1.6; }
  .ns-glyph { stroke: rgba(var(--v-theme-on-surface), 0.55); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; fill: none; }
  .ns-dot.ok { fill: rgb(var(--v-theme-success)); }
  .ns-dot.warn { fill: rgb(var(--v-theme-warning)); }

  @media (prefers-reduced-motion: no-preference) {
    .ns-flow { animation: nsflow 1.4s linear infinite; }
    .ns-flow-2 { animation-duration: 1.9s; }
    .ns-flow-3 { animation-duration: 1.15s; }
    .ns-core-ring circle { transform-origin: center; animation: nspulse 3s ease-in-out infinite; }
  }
}

@keyframes nsflow { to { stroke-dashoffset: -34; } }
@keyframes nspulse {
  0%, 100% { opacity: 1; }
  50% { opacity: .45; }
}
</style>
