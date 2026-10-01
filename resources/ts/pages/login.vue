<script setup lang="ts">
import { useAuthStore } from '@/stores/auth'

definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})

declare const __APP_VERSION__: string

const auth = useAuthStore()
const router = useRouter()

const form = ref({
  email: '',
  password: '',
  remember: false,
})

const step = ref<'credentials' | 'code'>('credentials')
const codeMode = ref<'app' | 'recovery'>('app')
const digits = ref<string[]>(['', '', '', '', '', ''])
const recoveryCode = ref('')

const showPassword = ref(false)
const capsLock = ref(false)
const isSubmitting = ref(false)
const errorMessage = ref('')
const failedOnce = ref(false)

const emailInput = ref<HTMLInputElement | null>(null)
const passwordInput = ref<HTMLInputElement | null>(null)
const digitInputs = ref<HTMLInputElement[]>([])
const recoveryInput = ref<HTMLInputElement | null>(null)

// Arrived here because the session expired mid-session (the API layer redirects on a
// 401/419 and remembers where the operator was) — say so, and send them back there.
const params = new URLSearchParams(window.location.search)
const sessionExpired = ref(params.get('expired') === '1')
const next = safeNext(params.get('next'))

// Only a path on THIS origin is ever followed — browsers read "/\evil" and
// "//evil" as another host, so resolve the value and check where it points.
function safeNext(raw: string | null): string | null {
  if (!raw || !raw.startsWith('/') || /^\/[/\\]/.test(raw))
    return null
  try {
    const u = new URL(raw, window.location.origin)
    if (u.origin !== window.location.origin || u.pathname.startsWith('/login'))
      return null

    return u.pathname + u.search + u.hash
  }
  catch {
    return null
  }
}

// "Topology" out of "/topology?site=49" — enough to tell the operator where they were.
const whereYouWere = computed(() => {
  if (!next)
    return ''
  const seg = next.split('?')[0].split('/').filter(Boolean)[0] ?? ''

  return seg ? seg.charAt(0).toUpperCase() + seg.slice(1).replace(/-/g, ' ') : ''
})

const code = computed(() => codeMode.value === 'app' ? digits.value.join('') : recoveryCode.value.trim())
const canVerify = computed(() => codeMode.value === 'app' ? code.value.length === 6 : code.value.length > 0)

const version = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : ''

// Same clock as the app bar: the operator's timezone, never the browser's.
const clock = ref('')
let clockTimer: ReturnType<typeof setInterval> | null = null
function tick() {
  clock.value = new Intl.DateTimeFormat('en-US', {
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
    timeZone: 'America/New_York', timeZoneName: 'short',
  }).format(new Date())
}

onMounted(() => {
  tick()
  clockTimer = setInterval(tick, 1000)
  emailInput.value?.focus()
})
onUnmounted(() => { if (clockTimer) clearInterval(clockTimer) })

function onKey(e: KeyboardEvent) {
  capsLock.value = e.getModifierState?.('CapsLock') ?? false
}

async function submit() {
  if (isSubmitting.value)
    return
  if (step.value === 'code' && !canVerify.value)
    return

  errorMessage.value = ''
  isSubmitting.value = true

  try {
    const result = await auth.login(
      form.value.email.trim(),
      form.value.password,
      step.value === 'code' ? code.value : undefined,
      form.value.remember,
    )
    if (result === '2fa') {
      // Credentials were right — collect the authenticator code next.
      step.value = 'code'
      await nextTick()
      digitInputs.value[0]?.focus()

      return
    }
    // The router guard routes to two-factor setup if enrolment is still required.
    if (next)
      window.location.assign(next)
    else
      await router.push({ name: 'root' })
  }
  catch (e: any) {
    if (step.value === 'code') {
      errorMessage.value = e?.data?.errors?.code?.[0] ?? 'That code is not valid.'
      digits.value = ['', '', '', '', '', '']
      recoveryCode.value = ''
      await nextTick()
      ;(codeMode.value === 'app' ? digitInputs.value[0] : recoveryInput.value)?.focus()
    }
    else {
      errorMessage.value = 'Email or password is wrong, or the account is disabled.'
      failedOnce.value = true
      await nextTick()
      passwordInput.value?.select()
    }
  }
  finally {
    isSubmitting.value = false
  }
}

// ── six-box code entry ────────────────────────────────────────────────────
function onDigitInput(i: number, e: Event) {
  const el = e.target as HTMLInputElement
  const v = el.value.replace(/\D/g, '')
  digits.value[i] = v.slice(-1)
  el.value = digits.value[i]
  if (v && i < 5)
    digitInputs.value[i + 1]?.focus()
  if (digits.value.every(d => d !== ''))
    submit()
}

function onDigitKey(i: number, e: KeyboardEvent) {
  if (e.key === 'Backspace' && !digits.value[i] && i > 0) {
    digits.value[i - 1] = ''
    digitInputs.value[i - 1]?.focus()
    e.preventDefault()
  }
  else if (e.key === 'ArrowLeft' && i > 0) {
    digitInputs.value[i - 1]?.focus()
  }
  else if (e.key === 'ArrowRight' && i < 5) {
    digitInputs.value[i + 1]?.focus()
  }
}

function onDigitPaste(e: ClipboardEvent) {
  const text = (e.clipboardData?.getData('text') ?? '').replace(/\D/g, '').slice(0, 6)
  if (!text)
    return
  e.preventDefault()
  digits.value = Array.from({ length: 6 }, (_, i) => text[i] ?? '')
  digitInputs.value[Math.min(text.length, 5)]?.focus()
  if (text.length === 6)
    submit()
}

async function switchCodeMode(mode: 'app' | 'recovery') {
  codeMode.value = mode
  errorMessage.value = ''
  digits.value = ['', '', '', '', '', '']
  recoveryCode.value = ''
  await nextTick()
  ;(mode === 'app' ? digitInputs.value[0] : recoveryInput.value)?.focus()
}

async function backToCredentials() {
  step.value = 'credentials'
  codeMode.value = 'app'
  digits.value = ['', '', '', '', '', '']
  recoveryCode.value = ''
  form.value.password = ''
  errorMessage.value = ''
  await nextTick()
  passwordInput.value?.focus()
}
</script>

<template>
  <div class="lg">
    <!-- ── field: a schematic of the fleet, not a live status board ────── -->
    <div
      class="lg-field"
      aria-hidden="true"
    >
      <svg
        viewBox="0 0 1080 1010"
        fill="none"
      >
        <defs>
          <pattern
            id="lg-grid"
            width="34"
            height="34"
            patternUnits="userSpaceOnUse"
          >
            <path
              d="M34 0H0V34"
              stroke="#111721"
              stroke-width="1"
            />
          </pattern>
        </defs>
        <rect
          width="1080"
          height="1010"
          fill="url(#lg-grid)"
        />

        <g
          stroke="#141B26"
          fill="none"
        >
          <ellipse cx="420" cy="470" rx="330" ry="300" />
          <ellipse cx="420" cy="470" rx="255" ry="232" />
          <ellipse cx="420" cy="470" rx="180" ry="164" />
          <ellipse cx="420" cy="470" rx="108" ry="98" />
        </g>

        <g
          stroke="#1C2634"
          stroke-width="1"
        >
          <path d="M420 470 L250 300" /><path d="M420 470 L600 250" /><path d="M420 470 L640 520" />
          <path d="M420 470 L300 640" /><path d="M420 470 L520 700" /><path d="M420 470 L180 470" />
          <path d="M420 470 L690 380" /><path d="M420 470 L360 220" /><path d="M420 470 L560 610" />
          <path d="M250 300 L180 200" /><path d="M600 250 L700 190" /><path d="M300 640 L220 740" />
          <path d="M520 700 L610 780" /><path d="M640 520 L760 560" />
        </g>
        <g
          class="lg-lit"
          stroke-width="1.4"
        >
          <path d="M420 470 L600 250" /><path d="M600 250 L700 190" />
        </g>

        <g fill="#1E2836">
          <circle cx="250" cy="300" r="4" /><circle cx="640" cy="520" r="4" /><circle cx="300" cy="640" r="4" />
          <circle cx="690" cy="380" r="4" /><circle cx="360" cy="220" r="4" /><circle cx="560" cy="610" r="4" />
          <circle cx="180" cy="200" r="3" /><circle cx="220" cy="740" r="3" /><circle cx="610" cy="780" r="3" />
          <circle cx="760" cy="560" r="3" /><circle cx="150" cy="380" r="3" /><circle cx="330" cy="820" r="3" />
          <circle cx="740" cy="700" r="3" /><circle cx="480" cy="150" r="3" /><circle cx="820" cy="300" r="3" />
          <circle cx="90" cy="600" r="3" />
        </g>

        <circle cx="700" cy="190" r="5" class="lg-n-up" />
        <circle cx="600" cy="250" r="5" class="lg-n-up" />
        <circle cx="520" cy="700" r="5" class="lg-n-warn" />
        <circle cx="180" cy="470" r="5" class="lg-n-crit" />
        <circle cx="180" cy="470" r="13" class="lg-n-ring" fill="none" stroke-width="1" />

        <circle
          cx="420"
          cy="470"
          r="9"
          class="lg-hub"
          stroke-width="2"
        />
        <circle
          cx="420"
          cy="470"
          r="22"
          class="lg-hub-ring"
          fill="none"
          stroke-width="1"
        />
        <text
          x="444"
          y="474"
          class="lg-hub-label"
        >HUB</text>
      </svg>
    </div>
    <div class="lg-wash" />

    <!-- ── content ─────────────────────────────────────────────────────── -->
    <div class="lg-stage">
      <div class="lg-brand">
        <span class="lg-mark" />
        <span class="lg-name">NODUS</span>
        <span class="cap">Massey Services</span>
      </div>

      <div class="lg-row">
        <div class="lg-copy">
          <div class="lg-kick">
            <span class="lg-bar" />
            <span class="cap">Florida · 131 service centers</span>
          </div>
          <h1>One hub.<br>Every branch.<br><em>Measured.</em></h1>
          <p>Nodus watches 304 devices and 255 circuits across the fleet, and tells you which ones it could not reach.</p>

          <!-- A key to the colour language, NOT a readout of current state —
               nothing on this page has been measured yet. -->
          <div class="lg-key">
            <span class="cap">What the colours mean</span>
            <div class="lg-key-row mono">
              <span><i class="lg-i-up" />Reporting</span>
              <span><i class="lg-i-warn" />Degraded</span>
              <span><i class="lg-i-crit" />Down</span>
              <span><i class="lg-i-idle" />Not polled</span>
            </div>
          </div>
        </div>

        <!-- ── card ────────────────────────────────────────────────────── -->
        <main class="lg-card">
          <form
            novalidate
            @submit.prevent="submit"
          >
            <!-- step 1 -->
            <template v-if="step === 'credentials'">
              <div class="cap">
                Sign in
              </div>
              <h2>Operations console</h2>

              <div
                v-if="sessionExpired && !errorMessage"
                class="lg-note"
                role="status"
              >
                Your session ended. Sign in again to continue<template v-if="whereYouWere"> where you were — {{ whereYouWere }}</template>.
              </div>

              <div class="lg-stack">
                <label class="lg-field-g">
                  <span class="cap">Email</span>
                  <input
                    ref="emailInput"
                    v-model="form.email"
                    type="email"
                    name="email"
                    autocomplete="username"
                    spellcheck="false"
                    class="mono"
                    required
                  >
                </label>

                <label
                  class="lg-field-g"
                  :class="{ 'is-bad': errorMessage }"
                >
                  <span class="lg-fh">
                    <span class="cap">Password</span>
                    <button
                      type="button"
                      class="cap lg-toggle"
                      @click="showPassword = !showPassword"
                    >{{ showPassword ? 'Hide' : 'Show' }}</button>
                  </span>
                  <input
                    ref="passwordInput"
                    v-model="form.password"
                    :type="showPassword ? 'text' : 'password'"
                    name="password"
                    autocomplete="current-password"
                    class="mono"
                    required
                    @keydown="onKey"
                    @keyup="onKey"
                    @blur="capsLock = false"
                  >
                  <span
                    v-if="errorMessage || capsLock"
                    class="lg-ff"
                  >
                    <span
                      v-if="errorMessage"
                      class="lg-bad"
                      role="alert"
                    >{{ errorMessage }}</span>
                    <span
                      v-if="capsLock"
                      class="lg-warn"
                    >Caps Lock is on</span>
                  </span>
                </label>

                <label class="lg-check">
                  <input
                    v-model="form.remember"
                    type="checkbox"
                  >
                  <span class="lg-box" />
                  Keep me signed in on this device
                </label>

                <button
                  type="submit"
                  class="lg-btn"
                  :disabled="isSubmitting"
                >
                  <span>{{ isSubmitting ? 'Signing in' : failedOnce ? 'Try again' : 'Sign in' }}</span>
                  <kbd v-if="!isSubmitting">↵</kbd>
                  <span
                    v-else
                    class="lg-spin"
                  />
                </button>

                <p class="lg-fine">
                  Every sign-in is recorded with the address it came from.
                </p>
              </div>
            </template>

            <!-- step 2 -->
            <template v-else>
              <div class="cap">
                Step 2 of 2
              </div>
              <h2>{{ codeMode === 'app' ? 'Authenticator code' : 'Recovery code' }}</h2>
              <p class="lg-sub">
                <template v-if="codeMode === 'app'">
                  Signing in as <span class="mono lg-ink">{{ form.email }}</span> ·
                  <button
                    type="button"
                    class="lg-link"
                    @click="backToCredentials"
                  >Not you?</button>
                </template>
                <template v-else-if="auth.recoveryCodesLeft !== null">
                  Each code works once. You have <span class="mono lg-ink">{{ auth.recoveryCodesLeft }}</span> left.
                </template>
                <template v-else>
                  Each code works once.
                </template>
              </p>

              <div class="lg-stack">
                <div
                  v-if="codeMode === 'app'"
                  class="lg-field-g"
                  :class="{ 'is-bad': errorMessage }"
                >
                  <span class="cap">6-digit code from your app</span>
                  <div
                    class="lg-digits mono"
                    @paste="onDigitPaste"
                  >
                    <input
                      v-for="i in 6"
                      :key="i"
                      :ref="el => { if (el) digitInputs[i - 1] = el as HTMLInputElement }"
                      :value="digits[i - 1]"
                      type="text"
                      inputmode="numeric"
                      autocomplete="one-time-code"
                      maxlength="1"
                      :aria-label="`Digit ${i}`"
                      @input="onDigitInput(i - 1, $event)"
                      @keydown="onDigitKey(i - 1, $event)"
                    >
                  </div>
                  <span class="lg-ff">
                    <span
                      v-if="errorMessage"
                      class="lg-bad"
                      role="alert"
                    >{{ errorMessage }}</span>
                    <span
                      v-else
                      class="lg-dim"
                    >Digits move on their own. Paste works too.</span>
                  </span>
                </div>

                <label
                  v-else
                  class="lg-field-g"
                  :class="{ 'is-bad': errorMessage }"
                >
                  <span class="cap">Recovery code</span>
                  <input
                    ref="recoveryInput"
                    v-model="recoveryCode"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    class="mono lg-upper"
                  >
                  <span
                    v-if="errorMessage"
                    class="lg-ff"
                  >
                    <span
                      class="lg-bad"
                      role="alert"
                    >{{ errorMessage }}</span>
                  </span>
                </label>

                <button
                  type="submit"
                  class="lg-btn"
                  :class="{ 'is-wait': !canVerify }"
                  :disabled="isSubmitting || !canVerify"
                >
                  <span>{{ isSubmitting ? 'Verifying' : 'Verify' }}</span>
                  <kbd v-if="!isSubmitting">↵</kbd>
                  <span
                    v-else
                    class="lg-spin"
                  />
                </button>

                <div class="lg-links">
                  <button
                    v-if="codeMode === 'app'"
                    type="button"
                    class="lg-link"
                    @click="switchCodeMode('recovery')"
                  >Use a recovery code instead</button>
                  <button
                    v-else
                    type="button"
                    class="lg-link"
                    @click="switchCodeMode('app')"
                  >Use my authenticator instead</button>
                  <button
                    type="button"
                    class="lg-dim lg-plain"
                    @click="backToCredentials"
                  >← Back</button>
                </div>

                <p class="lg-fine">
                  Locked out of your app? An admin can reset your two-factor from Users.
                </p>
              </div>
            </template>
          </form>
        </main>
      </div>

      <footer class="lg-foot mono">
        <span>NODUS {{ version ? `v${version}` : '' }}</span>
        <span>{{ clock }}</span>
      </footer>
    </div>
  </div>
</template>

<style lang="scss">
// The login is the one screen outside the shell, so it carries the Terminal
// tokens itself. The field behind is a SCHEMATIC of a site's topology — it is
// never fed live data, because nothing has been measured before you sign in.
$ground: #090B10;
$rule: #1D2530;
$rule-soft: #232B37;
$ink: #F2F5F8;
$ink-body: #D7DCE4;
$ink-mid: #79828F;
$ink-dim: #5C6472;
$ink-ghost: #3A4250;
$blue: #2563EB;
$blue-ink: #4E8CF5;
$blue-soft: #A9C3F8;
$crit: #F0575B;
$warn: #E8A23A;

.lg {
  position: relative;
  min-block-size: 100vh;
  // Vertical must stay scrollable — a short viewport on the two-factor step
  // would otherwise clip the card. Only the wide schematic is clipped.
  overflow-x: hidden;
  background: $ground;
  color: $ink-body;
  font-size: 12px;

  .cap { font-size: 10px; letter-spacing: .14em; text-transform: uppercase; color: $ink-dim; font-weight: 600; }
  .mono { font-family: 'IBM Plex Mono', ui-monospace, Menlo, monospace; font-variant-numeric: tabular-nums; }
  .lg-ink { color: $ink; }
  .lg-dim { color: $ink-dim; }
  .lg-bad { color: $crit; }
  .lg-warn { color: $warn; }
}

// ── field ─────────────────────────────────────────────────────────────────
.lg-field {
  position: absolute;
  inset: 0;

  svg {
    position: absolute;
    // Put the hub a little right of centre, vertically centred, at 1:1 scale.
    inset-inline-start: calc(52% - 420px);
    inset-block-start: calc(50% - 470px);
    inline-size: 1080px;
    block-size: 1010px;
  }

  .lg-lit { stroke: $blue; opacity: .85; }
  .lg-n-up { fill: $blue-ink; }
  .lg-n-warn { fill: $warn; }
  .lg-n-crit { fill: $crit; }
  .lg-n-ring { stroke: $crit; opacity: .45; }
  .lg-hub { fill: $ground; stroke: $blue; }
  .lg-hub-ring { stroke: $blue; opacity: .35; }
  .lg-hub-label {
    fill: $blue-ink;
    font-family: 'IBM Plex Mono', monospace;
    font-size: 11px;
    letter-spacing: 1px;
  }
}

.lg-wash {
  position: absolute;
  inset: 0;
  background:
    radial-gradient(620px 560px at 58% 48%, rgba(37, 99, 235, .15), transparent 66%),
    linear-gradient(90deg, #{$ground} 0%, rgba(9, 11, 16, .97) 30%, rgba(9, 11, 16, .55) 46%, rgba(9, 11, 16, .8) 64%, #{$ground} 76%);
}

// ── stage ─────────────────────────────────────────────────────────────────
.lg-stage {
  position: relative;
  min-block-size: 100vh;
  display: flex;
  flex-direction: column;
  padding: 40px 60px;

  @media (max-width: 1099px) { padding: 24px; }
}
.lg-brand { display: flex; align-items: center; gap: 11px; }
.lg-mark { inline-size: 15px; block-size: 15px; border: 2px solid $blue; flex: 0 0 auto; }
.lg-name { font-weight: 700; letter-spacing: .06em; font-size: 13px; color: $ink-body; }

.lg-row {
  flex: 1 1 auto;
  display: flex;
  align-items: center;
  gap: 40px;
  padding-block: 32px;
}

.lg-copy {
  inline-size: 520px;
  flex: 0 0 auto;

  @media (max-width: 1099px) { display: none; }

  h1 {
    font-size: 62px;
    line-height: 1;
    font-weight: 600;
    letter-spacing: -.035em;
    color: $ink;
    margin: 0;

    em { font-style: normal; color: #4E5764; }
  }
  p { margin: 20px 0 0; font-size: 14px; line-height: 1.6; color: $ink-mid; max-inline-size: 400px; }
}
.lg-kick { display: flex; align-items: center; gap: 12px; margin-block-end: 20px; }
.lg-bar { inline-size: 26px; block-size: 1px; background: $blue; }

.lg-key {
  margin-block-start: 36px;

  .lg-key-row { display: flex; flex-wrap: wrap; gap: 26px; margin-block-start: 12px; font-size: 11px; color: $ink-dim; }
  i { inline-size: 7px; block-size: 7px; display: inline-block; margin-inline-end: 8px; }
  .lg-i-up { background: $blue-ink; }
  .lg-i-warn { background: $warn; }
  .lg-i-crit { background: $crit; }
  .lg-i-idle { background: #1E2836; }
}

// ── card ──────────────────────────────────────────────────────────────────
.lg-card {
  margin-inline-start: auto;
  inline-size: 436px;
  flex: 0 0 auto;
  background: rgba(13, 17, 24, .86);
  border: 1px solid $rule;
  backdrop-filter: blur(14px);
  box-shadow: 0 30px 70px rgba(0, 0, 0, .6);
  padding: 38px 42px;

  @media (max-width: 1099px) { margin-inline: auto; inline-size: 100%; max-inline-size: 460px; }
  @media (max-width: 599px) { padding: 28px 22px; }

  h2 { font-size: 20px; font-weight: 600; margin: 9px 0 0; letter-spacing: -.01em; color: $ink; }
}
.lg-sub { margin: 10px 0 0; color: $ink-mid; }

.lg-stack { margin-block-start: 34px; display: flex; flex-direction: column; gap: 26px; }

.lg-field-g {
  display: flex;
  flex-direction: column;
  gap: 9px;

  input {
    inline-size: 100%;
    block-size: 42px;
    background: transparent;
    border: 0;
    border-block-end: 1px solid $rule-soft;
    color: $ink;
    font-size: 14px;
    outline: none;
    padding: 0;
    border-radius: 0;

    &:focus { border-block-end-color: $blue; }
  }
  &.is-bad {
    > .cap, .lg-fh > .cap { color: $crit; }
    input { border-block-end-color: $crit; }
  }
}
.lg-fh { display: flex; justify-content: space-between; align-items: center; }
.lg-ff { display: flex; justify-content: space-between; gap: 12px; font-size: 11px; }
.lg-toggle { background: none; border: 0; padding: 0; cursor: pointer; color: $blue-ink !important; }
.lg-upper { text-transform: uppercase; }

.lg-digits {
  display: flex;
  gap: 8px;

  input {
    inline-size: 100%;
    block-size: 56px;
    border: 1px solid $rule;
    border-block-end-color: $rule;
    background: rgba(9, 11, 16, .5);
    text-align: center;
    font-size: 22px;
    font-weight: 500;

    &:focus { border-color: $blue; }
  }
}

.lg-check {
  display: flex;
  align-items: center;
  gap: 10px;
  color: $ink-mid;
  cursor: pointer;
  user-select: none;

  input { position: absolute; opacity: 0; inline-size: 0; block-size: 0; }
  .lg-box { inline-size: 13px; block-size: 13px; border: 1px solid $ink-ghost; flex: 0 0 auto; position: relative; }
  input:checked + .lg-box { border-color: $blue; background: $blue; }
  input:checked + .lg-box::after {
    content: ""; position: absolute; inset-inline-start: 4px; inset-block-start: 1px;
    inline-size: 3px; block-size: 7px; border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(42deg);
  }
  input:focus-visible + .lg-box { outline: 1px solid $blue; outline-offset: 2px; }
}

.lg-btn {
  display: flex;
  align-items: center;
  justify-content: space-between;
  block-size: 46px;
  padding: 0 16px;
  border: 0;
  background: $blue;
  color: #fff;
  font: inherit;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
  cursor: pointer;

  kbd { font-family: 'IBM Plex Mono', Menlo, monospace; font-size: 11px; border: 1px solid rgba(255, 255, 255, .32); padding: 2px 7px; opacity: .8; }
  &:focus-visible { outline: 1px solid #fff; outline-offset: 2px; }
  &:disabled { cursor: default; }
  &.is-wait, &.is-wait:disabled {
    background: #1B2A4A;
    color: $blue-ink;

    kbd { border-color: rgba(78, 140, 245, .35); }
  }
}
.lg-spin {
  inline-size: 10px; block-size: 10px; border-radius: 50%;
  border: 2px solid currentColor; border-inline-end-color: transparent;
  animation: lg-spin .8s linear infinite;
}
@keyframes lg-spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .lg-spin { animation: none; } }

.lg-links { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.lg-link { background: none; border: 0; padding: 0; font: inherit; color: $blue-ink; cursor: pointer; &:hover { color: $blue-soft; } }
.lg-plain { background: none; border: 0; padding: 0; font: inherit; cursor: pointer; }
.lg-fine { margin: -4px 0 0; color: $ink-dim; font-size: 11px; line-height: 1.6; }

.lg-note {
  margin-block-start: 22px;
  padding: 10px 12px;
  line-height: 1.45;
  border-inline-start: 2px solid $blue;
  color: $blue-soft;
  background: rgba(15, 22, 38, .8);
}

.lg-foot {
  display: flex;
  justify-content: space-between;
  font-size: 10px;
  color: $ink-ghost;
  letter-spacing: .06em;
}
</style>
