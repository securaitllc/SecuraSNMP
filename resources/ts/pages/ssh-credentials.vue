<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { SshCredential } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()

const credentials = ref<SshCredential[]>([])
const isLoading = ref(true)
const isDialogOpen = ref(false)
const isSaving = ref(false)
const editing = ref<SshCredential | null>(null)
const errorMessage = ref('')

function emptyForm() {
  return { name: '', username: '', password: '', notes: '' }
}
const form = ref(emptyForm())

const headers = [
  { title: 'Name', key: 'name' },
  { title: 'Username', key: 'username' },
  { title: 'Password', key: 'has_password' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function loadCredentials() {
  isLoading.value = true
  // Resource collection ({ data: [...] }) keeps the password masked; unwrap it.
  const res = await api<{ data: SshCredential[] }>('/api/ssh-credentials')
  credentials.value = res.data
  isLoading.value = false
}

function openCreateDialog() {
  editing.value = null
  form.value = emptyForm()
  errorMessage.value = ''
  isDialogOpen.value = true
}

function openEditDialog(cred: SshCredential) {
  editing.value = cred
  form.value = { name: cred.name, username: cred.username, password: '', notes: cred.notes ?? '' }
  errorMessage.value = ''
  isDialogOpen.value = true
}

async function save() {
  isSaving.value = true
  errorMessage.value = ''
  try {
    const payload: Record<string, unknown> = { ...form.value }
    // Blank password on edit means "keep the stored secret".
    if (editing.value && payload.password === '')
      delete payload.password

    if (editing.value)
      await api(`/api/ssh-credentials/${editing.value.id}`, { method: 'PUT', body: payload })
    else
      await api('/api/ssh-credentials', { method: 'POST', body: payload })

    isDialogOpen.value = false
    await loadCredentials()
  }
  catch {
    errorMessage.value = 'Could not save. Name must be unique; username and password are required on create.'
  }
  finally {
    isSaving.value = false
  }
}

async function remove(cred: SshCredential) {
  if (!confirm(`Delete SSH credential "${cred.name}"? Devices using it fall back to their inline SSH values.`))
    return

  await api(`/api/ssh-credentials/${cred.id}`, { method: 'DELETE' })
  await loadCredentials()
}

onMounted(() => {
  if (!auth.isAdmin) {
    isLoading.value = false

    return
  }
  loadCredentials()
})
</script>

<template>
  <div>
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">SSH Credentials</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Reusable SSH login profiles the pollers use to reach appliances.</p>
      </div>
      <VBtn v-if="auth.isAdmin" @click="openCreateDialog">
        Add Credential
      </VBtn>
    </div>

  <div v-if="!auth.isAdmin">
    <VAlert type="info" variant="tonal">
      SSH credentials are an administrator function.
    </VAlert>
  </div>

  <VCard v-else>
    <VCardText class="pb-0">
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
      >
        Store an SSH username/password once, then select it on each device's form under “SSH Credential”. Devices without a link fall back to their own inline SSH values.
      </VAlert>
    </VCardText>

    <VDataTable
      :headers="headers"
      :items="credentials"
      :loading="isLoading"
      density="compact"
    >
      <template #item.has_password="{ item }">
        <VIcon icon="ri-lock-line" size="small" /> set
      </template>
      <template #item.actions="{ item }">
        <VBtn
          icon="ri-edit-line"
          variant="text"
          size="small"
          @click="openEditDialog(item)"
        />
        <VBtn
          icon="ri-delete-bin-line"
          variant="text"
          size="small"
          @click="remove(item)"
        />
      </template>
    </VDataTable>
  </VCard>

  <VDialog
    v-model="isDialogOpen"
    max-width="560"
  >
    <VCard :title="editing ? 'Edit SSH Credential' : 'Add SSH Credential'">
      <VCardText>
        <VAlert
          v-if="errorMessage"
          type="error"
          variant="tonal"
          class="mb-4"
        >
          {{ errorMessage }}
        </VAlert>

        <VForm @submit.prevent="save">
          <VRow>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.name"
                label="Name"
                placeholder="Massey NOC"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.username"
                label="SSH Username"
                autocomplete="off"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.password"
                label="SSH Password / Key"
                type="password"
                autocomplete="new-password"
                :placeholder="editing ? 'Leave blank to keep current' : ''"
              />
            </VCol>
            <VCol cols="12">
              <VTextarea
                v-model="form.notes"
                label="Notes"
                rows="2"
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                :loading="isSaving"
              >
                Save
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </VDialog>
  </div>
</template>
