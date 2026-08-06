<script setup lang="ts">
import avatar1 from '@images/avatars/avatar-1.png'
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function logout() {
  await auth.logout()
  await router.push({ name: 'login' })
}

interface UpdateCheckResult {
  current: string
  latest: string
  update_available: boolean
}

const isUpdateDialogOpen = ref(false)
const isChecking = ref(false)
const updateResult = ref<UpdateCheckResult | null>(null)
const updateError = ref('')

const updateCommand = 'git pull && docker compose build && docker compose up -d && docker compose exec app php artisan migrate --force'

async function checkForUpdates() {
  isUpdateDialogOpen.value = true
  isChecking.value = true
  updateError.value = ''
  updateResult.value = null

  try {
    updateResult.value = await api<UpdateCheckResult>('/api/updates/check')
  }
  catch {
    updateError.value = 'Could not check for updates.'
  }
  finally {
    isChecking.value = false
  }
}

// Current avatar: the user's own, else the default image.
const avatarSrc = computed(() => auth.user?.avatar || avatar1)

// --- Self-service avatar change ---
const isAvatarDialogOpen = ref(false)
const avatarBusy = ref(false)
const avatarError = ref('')
const avatarPreview = ref<string | null>(null)
const avatarInput = ref<HTMLInputElement | null>(null)

function openAvatarDialog() {
  avatarError.value = ''
  avatarPreview.value = auth.user?.avatar || null
  isAvatarDialogOpen.value = true
}

// Downscale to a 256px square thumbnail so the stored data URI stays tiny.
function resizeToDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => {
      const size = 256
      const canvas = document.createElement('canvas')
      canvas.width = size
      canvas.height = size
      const ctx = canvas.getContext('2d')
      if (!ctx) {
        reject(new Error('no canvas'))

        return
      }
      const scale = Math.max(size / img.width, size / img.height)
      const w = img.width * scale
      const h = img.height * scale
      ctx.drawImage(img, (size - w) / 2, (size - h) / 2, w, h)
      resolve(canvas.toDataURL('image/jpeg', 0.85))
    }
    img.onerror = () => reject(new Error('bad image'))
    img.src = URL.createObjectURL(file)
  })
}

async function onAvatarPicked(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file)
    return
  avatarError.value = ''
  if (!file.type.startsWith('image/')) {
    avatarError.value = 'Please choose an image file.'

    return
  }
  try {
    avatarPreview.value = await resizeToDataUrl(file)
  }
  catch {
    avatarError.value = 'Could not read that image.'
  }
}

async function saveAvatar(clear = false) {
  avatarBusy.value = true
  avatarError.value = ''
  try {
    const user = await api<typeof auth.user>('/api/avatar', { method: 'POST', body: { avatar: clear ? null : avatarPreview.value } })
    auth.user = user
    isAvatarDialogOpen.value = false
  }
  catch (e: any) {
    avatarError.value = e?.data?.errors?.avatar?.[0] ?? e?.data?.message ?? 'Could not update avatar.'
  }
  finally {
    avatarBusy.value = false
  }
}

// --- Self-service password change ---
const isPwDialogOpen = ref(false)
const pwBusy = ref(false)
const pwError = ref('')
const pwOk = ref(false)
const pwForm = ref({ current_password: '', password: '', password_confirmation: '' })

function openPwDialog() {
  pwForm.value = { current_password: '', password: '', password_confirmation: '' }
  pwError.value = ''
  pwOk.value = false
  isPwDialogOpen.value = true
}

async function changePassword() {
  pwError.value = ''
  if (pwForm.value.password !== pwForm.value.password_confirmation) {
    pwError.value = 'New password and confirmation do not match.'

    return
  }
  pwBusy.value = true
  try {
    await api('/api/password', { method: 'POST', body: pwForm.value })
    pwOk.value = true
    pwForm.value = { current_password: '', password: '', password_confirmation: '' }
  }
  catch (e: any) {
    pwError.value = e?.data?.errors?.current_password?.[0]
      ?? e?.data?.errors?.password?.[0]
      ?? e?.data?.message ?? 'Could not change password.'
  }
  finally {
    pwBusy.value = false
  }
}
</script>

<template>
  <VBadge
    dot
    bordered
    location="bottom right"
    offset-x="2"
    offset-y="2"
    color="success"
    class="user-profile-badge"
  >
    <VAvatar
      class="cursor-pointer"
      size="38"
    >
      <VImg :src="avatarSrc" />

      <!-- SECTION Menu -->
      <VMenu
        activator="parent"
        width="230"
        location="bottom end"
        offset="15px"
      >
        <VList>
          <VListItem class="px-4">
            <div class="d-flex gap-x-2 align-center">
              <VAvatar>
                <VImg :src="avatarSrc" />
              </VAvatar>

              <div>
                <div class="text-body-2 font-weight-medium text-high-emphasis">
                  {{ auth.user?.name }}
                </div>
                <div class="text-capitalize text-caption text-disabled">
                  {{ auth.user?.role }}
                </div>
              </div>
            </div>
          </VListItem>

          <VDivider class="my-1" />

          <VListItem
            class="px-4"
            @click="openAvatarDialog"
          >
            <template #prepend>
              <VIcon
                icon="ri-image-edit-line"
                size="20"
              />
            </template>
            <VListItemTitle>Change Avatar</VListItemTitle>
          </VListItem>

          <VListItem
            class="px-4"
            @click="openPwDialog"
          >
            <template #prepend>
              <VIcon
                icon="ri-lock-password-line"
                size="20"
              />
            </template>
            <VListItemTitle>Change Password</VListItemTitle>
          </VListItem>

          <VListItem
            v-if="auth.isAdmin"
            class="px-4"
            @click="checkForUpdates"
          >
            <template #prepend>
              <VIcon
                icon="ri-refresh-line"
                size="20"
              />
            </template>
            <VListItemTitle>Check for Updates</VListItemTitle>
          </VListItem>

          <VDivider
            v-if="auth.isAdmin"
            class="my-1"
          />

          <VListItem class="px-4">
            <VBtn
              block
              color="error"
              size="small"
              append-icon="ri-logout-box-r-line"
              @click="logout"
            >
              Logout
            </VBtn>
          </VListItem>
        </VList>
      </VMenu>
      <!-- !SECTION -->
    </VAvatar>
  </VBadge>

  <VDialog
    v-model="isUpdateDialogOpen"
    max-width="600"
  >
    <VCard title="Check for Updates">
      <VCardText>
        <div
          v-if="isChecking"
          class="d-flex align-center ga-2"
        >
          <VProgressCircular
            indeterminate
            size="20"
          />
          Checking for updates...
        </div>

        <VAlert
          v-else-if="updateError"
          type="error"
          variant="tonal"
        >
          {{ updateError }}
        </VAlert>

        <template v-else-if="updateResult">
          <p class="mb-2">
            Current version: <strong>{{ updateResult.current }}</strong>
          </p>

          <VAlert
            v-if="!updateResult.update_available"
            type="success"
            variant="tonal"
          >
            You're on the latest version.
          </VAlert>

          <template v-else>
            <VAlert
              type="info"
              variant="tonal"
              class="mb-4"
            >
              Version {{ updateResult.latest }} is available.
            </VAlert>

            <p class="mb-2 text-body-2">
              Run this on the server to update:
            </p>
            <VSheet
              color="grey-darken-4"
              rounded
              class="pa-3"
            >
              <code class="text-white">{{ updateCommand }}</code>
            </VSheet>
          </template>
        </template>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isAvatarDialogOpen"
    max-width="380"
  >
    <VCard title="Change Avatar">
      <VCardText>
        <VAlert
          v-if="avatarError"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ avatarError }}
        </VAlert>

        <div class="d-flex flex-column align-center ga-4">
          <VAvatar size="120">
            <VImg :src="avatarPreview || avatarSrc" />
          </VAvatar>

          <input
            ref="avatarInput"
            type="file"
            accept="image/*"
            class="d-none"
            @change="onAvatarPicked"
          >
          <VBtn
            variant="tonal"
            prepend-icon="ri-upload-2-line"
            @click="avatarInput?.click()"
          >
            Choose image
          </VBtn>
          <div class="text-caption text-disabled">
            Scaled to a 256px thumbnail.
          </div>
        </div>

        <div class="d-flex justify-space-between ga-2 mt-5">
          <VBtn
            variant="text"
            color="error"
            :disabled="!auth.user?.avatar"
            @click="saveAvatar(true)"
          >
            Remove
          </VBtn>
          <div class="d-flex ga-2">
            <VBtn
              variant="text"
              @click="isAvatarDialogOpen = false"
            >
              Close
            </VBtn>
            <VBtn
              :loading="avatarBusy"
              :disabled="!avatarPreview || avatarPreview === auth.user?.avatar"
              @click="saveAvatar(false)"
            >
              Save
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>
  </VDialog>

  <VDialog
    v-model="isPwDialogOpen"
    max-width="440"
  >
    <VCard title="Change Password">
      <VCardText>
        <VAlert
          v-if="pwOk"
          type="success"
          variant="tonal"
          class="mb-4"
        >
          Password updated.
        </VAlert>
        <VAlert
          v-if="pwError"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ pwError }}
        </VAlert>

        <VForm @submit.prevent="changePassword">
          <VTextField
            v-model="pwForm.current_password"
            label="Current password"
            type="password"
            autocomplete="current-password"
            class="mb-3"
          />
          <VTextField
            v-model="pwForm.password"
            label="New password"
            type="password"
            autocomplete="new-password"
            hint="At least 10 characters"
            persistent-hint
            class="mb-3"
          />
          <VTextField
            v-model="pwForm.password_confirmation"
            label="Confirm new password"
            type="password"
            autocomplete="new-password"
            class="mb-4"
          />
          <div class="d-flex justify-end ga-2">
            <VBtn
              variant="text"
              @click="isPwDialogOpen = false"
            >
              Close
            </VBtn>
            <VBtn
              type="submit"
              :loading="pwBusy"
            >
              Update password
            </VBtn>
          </div>
        </VForm>
      </VCardText>
    </VCard>
  </VDialog>
</template>

<style lang="scss">
.user-profile-badge {
  &.v-badge--bordered.v-badge--dot .v-badge__badge::after {
    color: rgb(var(--v-theme-background));
  }
}
</style>
