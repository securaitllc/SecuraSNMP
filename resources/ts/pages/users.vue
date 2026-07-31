<script setup lang="ts">
import { api } from '@/composables/useApi'
import type { ManagedUser } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
    requiresAdmin: true,
  },
})

const users = ref<ManagedUser[]>([])
const isLoading = ref(true)
const isDialogOpen = ref(false)
const isSaving = ref(false)
const editingUser = ref<ManagedUser | null>(null)
const errorMessage = ref('')

const roleOptions = [
  { title: 'Admin', value: 'admin' },
  { title: 'Analyst', value: 'analyst' },
  { title: 'Viewer', value: 'viewer' },
]

function emptyForm() {
  return {
    name: '',
    email: '',
    password: '',
    role: 'viewer',
    is_active: true,
  }
}

const form = ref(emptyForm())

const headers = [
  { title: 'Name', key: 'name' },
  { title: 'Email', key: 'email' },
  { title: 'Role', key: 'role' },
  { title: 'Active', key: 'is_active' },
  { title: 'Actions', key: 'actions', sortable: false },
]

async function loadUsers() {
  isLoading.value = true
  users.value = await api<ManagedUser[]>('/api/users')
  isLoading.value = false
}

function openCreateDialog() {
  editingUser.value = null
  form.value = emptyForm()
  errorMessage.value = ''
  isDialogOpen.value = true
}

function openEditDialog(user: ManagedUser) {
  editingUser.value = user
  form.value = {
    name: user.name,
    email: user.email,
    password: '',
    role: user.role,
    is_active: user.is_active,
  }
  errorMessage.value = ''
  isDialogOpen.value = true
}

async function saveUser() {
  isSaving.value = true
  errorMessage.value = ''

  try {
    const payload: Record<string, unknown> = { ...form.value }

    if (!payload.password)
      delete payload.password

    if (editingUser.value) {
      await api(`/api/users/${editingUser.value.id}`, {
        method: 'PUT',
        body: payload,
      })
    }
    else {
      await api('/api/users', {
        method: 'POST',
        body: payload,
      })
    }

    isDialogOpen.value = false
    await loadUsers()
  }
  catch {
    errorMessage.value = 'Could not save the user. Check the fields and try again.'
  }
  finally {
    isSaving.value = false
  }
}

async function deleteUser(user: ManagedUser) {
  if (!confirm(`Delete user "${user.name}"?`))
    return

  try {
    await api(`/api/users/${user.id}`, { method: 'DELETE' })
    await loadUsers()
  }
  catch {
    errorMessage.value = 'Could not delete this user.'
  }
}

onMounted(loadUsers)
</script>

<template>
  <VCard title="Users">
    <template #append>
      <VBtn @click="openCreateDialog">
        Add User
      </VBtn>
    </template>

    <VAlert
      v-if="errorMessage"
      type="error"
      variant="tonal"
      class="ma-4"
    >
      {{ errorMessage }}
    </VAlert>

    <VDataTable
      :headers="headers"
      :items="users"
      :loading="isLoading"
    >
      <template #item.is_active="{ item }">
        {{ item.is_active ? 'Yes' : 'No' }}
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
          @click="deleteUser(item)"
        />
      </template>
    </VDataTable>
  </VCard>

  <VDialog
    v-model="isDialogOpen"
    max-width="500"
  >
    <VCard :title="editingUser ? 'Edit User' : 'Add User'">
      <VCardText>
        <VForm @submit.prevent="saveUser">
          <VTextField
            v-model="form.name"
            label="Name"
            class="mb-4"
          />
          <VTextField
            v-model="form.email"
            label="Email"
            type="email"
            class="mb-4"
          />
          <VTextField
            v-model="form.password"
            label="Password"
            type="password"
            :placeholder="editingUser ? 'Leave blank to keep current' : ''"
            class="mb-4"
          />
          <VSelect
            v-model="form.role"
            :items="roleOptions"
            label="Role"
            class="mb-4"
          />
          <VSwitch
            v-model="form.is_active"
            label="Active"
            class="mb-4"
          />
          <VBtn
            type="submit"
            :loading="isSaving"
          >
            Save
          </VBtn>
        </VForm>
      </VCardText>
    </VCard>
  </VDialog>
</template>
