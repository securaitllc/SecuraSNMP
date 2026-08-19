<script setup lang="ts">
import { api } from '@/composables/useApi'
import { useAuthStore } from '@/stores/auth'
import type { IspProvider, IspProviderOverview } from '@/types/models'

definePage({
  meta: {
    layout: 'default',
  },
})

const auth = useAuthStore()

const providers = ref<IspProvider[]>([])
const isLoading = ref(true)
const isDialogOpen = ref(false)
const isSaving = ref(false)
const editing = ref<IspProvider | null>(null)
const errorMessage = ref('')
const search = ref('')

// Expanded rows + a lazy cache of each provider's overview.
const expanded = ref<number[]>([])
const overviews = ref<Record<number, IspProviderOverview | 'loading'>>({})

function emptyForm() {
  return {
    name: '',
    support_phone: '',
    ticket_url: '',
    account_rep_name: '',
    account_rep_mobile: '',
    account_rep_phone: '',
    account_rep_email: '',
    notes: '',
  }
}
const form = ref(emptyForm())

const headers = [
  { title: 'ISP', key: 'name' },
  { title: 'Support Phone', key: 'support_phone' },
  { title: 'Account Rep', key: 'account_rep_name' },
  { title: 'Circuits', key: 'circuits_count', align: 'end' as const },
  { title: 'Down', key: 'circuits_down_count', align: 'end' as const },
  { title: '', key: 'actions', sortable: false, align: 'end' as const },
]

async function loadProviders() {
  isLoading.value = true
  providers.value = await api<IspProvider[]>('/api/isp-providers')
  isLoading.value = false
}

async function loadOverview(id: number) {
  if (overviews.value[id] && overviews.value[id] !== 'loading')
    return
  overviews.value[id] = 'loading'
  try {
    overviews.value[id] = await api<IspProviderOverview>(`/api/isp-providers/${id}/overview`)
  }
  catch {
    delete overviews.value[id]
  }
}

watch(expanded, ids => ids.forEach(id => loadOverview(id)))

function overviewFor(id: number): IspProviderOverview | null {
  const o = overviews.value[id]

  return o && o !== 'loading' ? o : null
}

// Ongoing-outage detail dialog, shared with the Circuits page.
const isOutageOpen = ref(false)
const outageProviderId = ref<number | null>(null)
const outageCircuit = ref<{ id: number, circuit_id: string, isp_name?: string | null, support_phone?: string | null } | null>(null)

function openOutage(providerId: number, providerName: string, circuitId: number, circuitLabel: string) {
  outageProviderId.value = providerId
  outageCircuit.value = {
    id: circuitId,
    circuit_id: circuitLabel,
    isp_name: providerName,
    support_phone: overviewFor(providerId)?.contact.support_phone ?? null,
  }
  isOutageOpen.value = true
}

async function onOutageUpdated() {
  await loadProviders()
  if (outageProviderId.value !== null) {
    delete overviews.value[outageProviderId.value]
    await loadOverview(outageProviderId.value)
  }
}

function openCreateDialog() {
  editing.value = null
  form.value = emptyForm()
  errorMessage.value = ''
  isDialogOpen.value = true
}

function openEditDialog(provider: IspProvider) {
  editing.value = provider
  form.value = {
    name: provider.name,
    support_phone: provider.support_phone ?? '',
    ticket_url: provider.ticket_url ?? '',
    account_rep_name: provider.account_rep_name ?? '',
    account_rep_mobile: provider.account_rep_mobile ?? '',
    account_rep_phone: provider.account_rep_phone ?? '',
    account_rep_email: provider.account_rep_email ?? '',
    notes: provider.notes ?? '',
  }
  errorMessage.value = ''
  isDialogOpen.value = true
}

async function save() {
  isSaving.value = true
  errorMessage.value = ''
  try {
    if (editing.value)
      await api(`/api/isp-providers/${editing.value.id}`, { method: 'PUT', body: form.value })
    else
      await api('/api/isp-providers', { method: 'POST', body: form.value })

    isDialogOpen.value = false
    await loadProviders()
  }
  catch {
    errorMessage.value = 'Could not save the provider. Check the fields (the name must be unique).'
  }
  finally {
    isSaving.value = false
  }
}

async function remove(provider: IspProvider) {
  if (!confirm(`Delete ISP provider "${provider.name}"? Circuits keep their data but lose the link.`))
    return

  await api(`/api/isp-providers/${provider.id}`, { method: 'DELETE' })
  await loadProviders()
}

onMounted(loadProviders)
</script>

<template>
  <div>
    <div class="d-flex align-end justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">ISP Providers</h4>
        <p class="text-body-2 text-medium-emphasis mb-0">Carriers behind the circuits — support lines and account details for the NOC.</p>
      </div>
      <VBtn v-if="auth.isAdmin" @click="openCreateDialog">
        Add Provider
      </VBtn>
    </div>

    <VCard>
    <VCardText class="pb-0">
      <VTextField
        v-model="search"
        placeholder="Search ISP name, phone, or rep…"
        prepend-inner-icon="ri-search-line"
        density="compact"
        hide-details
        clearable
        style="max-width: 360px;"
      />
    </VCardText>

    <VDataTable
      v-model:expanded="expanded"
      :headers="headers"
      :items="providers"
      :loading="isLoading"
      :search="search"
      item-value="id"
      show-expand
      density="compact"
    >
      <template #item.name="{ item }">
        <span class="d-flex align-center ga-2">
          <VIcon
            icon="ri-global-line"
            size="18"
            class="text-medium-emphasis"
          />
          <span class="font-weight-medium">{{ item.name }}</span>
        </span>
      </template>
      <template #item.support_phone="{ item }">
        {{ item.support_phone ?? '—' }}
      </template>
      <template #item.account_rep_name="{ item }">
        {{ item.account_rep_name ?? '—' }}
      </template>
      <template #item.circuits_count="{ item }">
        {{ item.circuits_count ?? 0 }}
      </template>
      <template #item.circuits_down_count="{ item }">
        <span :class="(item.circuits_down_count ?? 0) > 0 ? 'text-error font-weight-medium' : 'text-disabled'">
          {{ item.circuits_down_count ?? 0 }}
        </span>
      </template>
      <template #item.actions="{ item }">
        <VBtn
          v-if="auth.isAdmin"
          icon="ri-edit-line"
          variant="text"
          size="small"
          @click.stop="openEditDialog(item)"
        />
        <VBtn
          v-if="auth.isAdmin"
          icon="ri-delete-bin-line"
          variant="text"
          size="small"
          @click.stop="remove(item)"
        />
      </template>

      <!-- Per-provider overview: escalation contacts + sites served + circuits -->
      <template #expanded-row="{ columns, item }">
        <tr>
          <td
            :colspan="columns.length"
            class="pa-0"
          >
            <div
              v-if="overviewFor(item.id)"
              class="isp-overview pa-4"
            >
              <VRow>
                <!-- Escalation contacts — first thing a NOC needs during an outage -->
                <VCol cols="12" md="4">
                  <div class="text-caption text-medium-emphasis mb-1">
                    Escalation contacts
                  </div>
                  <VCard variant="tonal" class="pa-3">
                    <div class="d-flex align-center ga-2 mb-2">
                      <VIcon icon="ri-customer-service-2-line" size="18" />
                      <a
                        v-if="overviewFor(item.id)!.contact.support_phone"
                        :href="`tel:${overviewFor(item.id)!.contact.support_phone}`"
                        class="font-weight-medium text-high-emphasis"
                      >{{ overviewFor(item.id)!.contact.support_phone }}</a>
                      <span v-else class="text-medium-emphasis">No support line</span>
                      <span class="text-caption text-medium-emphasis">24/7 support</span>
                    </div>
                    <a
                      v-if="item.ticket_url"
                      :href="item.ticket_url"
                      target="_blank"
                      rel="noopener"
                      class="d-flex align-center ga-1 mb-2 text-primary"
                    >
                      <VIcon
                        icon="ri-ticket-2-line"
                        size="16"
                      />Open ticket online
                    </a>
                    <VDivider class="mb-2" />
                    <div class="text-body-2 font-weight-medium">
                      {{ overviewFor(item.id)!.contact.account_rep_name ?? 'No rep on file' }}
                    </div>
                    <div
                      v-if="overviewFor(item.id)!.contact.account_rep_mobile"
                      class="text-caption"
                    >
                      <VIcon icon="ri-smartphone-line" size="14" />
                      <a :href="`tel:${overviewFor(item.id)!.contact.account_rep_mobile}`">{{ overviewFor(item.id)!.contact.account_rep_mobile }}</a>
                    </div>
                    <div
                      v-if="overviewFor(item.id)!.contact.account_rep_phone"
                      class="text-caption"
                    >
                      <VIcon icon="ri-phone-line" size="14" />
                      <a :href="`tel:${overviewFor(item.id)!.contact.account_rep_phone}`">{{ overviewFor(item.id)!.contact.account_rep_phone }}</a>
                    </div>
                    <div
                      v-if="overviewFor(item.id)!.contact.account_rep_email"
                      class="text-caption"
                    >
                      <VIcon icon="ri-mail-line" size="14" />
                      <a :href="`mailto:${overviewFor(item.id)!.contact.account_rep_email}`">{{ overviewFor(item.id)!.contact.account_rep_email }}</a>
                    </div>
                  </VCard>
                </VCol>

                <!-- Posture + sites served with circuits -->
                <VCol cols="12" md="8">
                  <div class="d-flex flex-wrap ga-2 mb-3">
                    <VChip size="small" variant="tonal" prepend-icon="ri-signal-tower-line">
                      {{ overviewFor(item.id)!.summary.circuits }} circuits
                    </VChip>
                    <VChip
                      size="small"
                      :color="overviewFor(item.id)!.summary.circuits_down > 0 ? 'error' : 'success'"
                      variant="tonal"
                    >
                      {{ overviewFor(item.id)!.summary.circuits_down }} down
                    </VChip>
                    <VChip size="small" variant="tonal" prepend-icon="ri-map-pin-line">
                      {{ overviewFor(item.id)!.summary.sites_served }} sites
                    </VChip>
                    <VChip size="small" variant="tonal">
                      {{ overviewFor(item.id)!.summary.fiber }} fiber · {{ overviewFor(item.id)!.summary.cable }} cable
                    </VChip>
                  </div>

                  <div
                    v-for="s in overviewFor(item.id)!.sites"
                    :key="s.site_id"
                    class="mb-3"
                  >
                    <div class="text-body-2 font-weight-medium mb-1">
                      <VIcon icon="ri-map-pin-line" size="16" class="text-medium-emphasis" />
                      {{ s.site_name }}
                    </div>
                    <VTable density="compact" class="overview-table">
                      <thead>
                        <tr>
                          <th>Circuit ID</th>
                          <th>Type</th>
                          <th>Monitored IP</th>
                          <th>Status</th>
                          <th>ISP ticket</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="c in s.circuits" :key="c.id">
                          <td>{{ c.circuit_id }}</td>
                          <td class="text-capitalize">
                            {{ c.circuit_type }}
                          </td>
                          <td>{{ c.monitored_ip ?? '—' }}</td>
                          <td>
                            <VChip
                              v-if="c.status === 'down'"
                              size="x-small"
                              color="error"
                              variant="flat"
                              class="cursor-pointer"
                              prepend-icon="ri-alarm-warning-line"
                              @click="openOutage(item.id, item.name, c.id, c.circuit_id)"
                            >
                              Down
                            </VChip>
                            <span
                              v-else
                              class="d-flex align-center ga-2"
                            >
                              <span
                                class="dot"
                                :style="{ backgroundColor: 'rgb(var(--v-theme-success))' }"
                              />
                              <span class="text-capitalize">up</span>
                            </span>
                          </td>
                          <td>{{ c.ticket_number ? `#${c.ticket_number}` : '—' }}</td>
                        </tr>
                      </tbody>
                    </VTable>
                  </div>
                  <div
                    v-if="overviewFor(item.id)!.sites.length === 0"
                    class="text-medium-emphasis text-body-2"
                  >
                    No circuits linked to this provider yet.
                  </div>
                </VCol>
              </VRow>
            </div>
            <div
              v-else
              class="d-flex justify-center pa-6"
            >
              <VProgressCircular indeterminate size="24" />
            </div>
          </td>
        </tr>
      </template>
    </VDataTable>
  </VCard>

  <VDialog
    v-model="isDialogOpen"
    max-width="640"
  >
    <VCard :title="editing ? 'Edit ISP Provider' : 'Add ISP Provider'">
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
                label="ISP Name"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.support_phone"
                label="Support Phone"
                placeholder="1-800-555-0100"
              />
            </VCol>
            <VCol cols="12">
              <VTextField
                v-model="form.ticket_url"
                label="Online ticket URL"
                placeholder="https://portal.isp.com/support/new"
                prepend-inner-icon="ri-ticket-2-line"
              />
            </VCol>
            <VCol cols="12">
              <div class="text-subtitle-2 text-medium-emphasis">
                Account Representative
              </div>
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.account_rep_name"
                label="Rep Name"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.account_rep_email"
                label="Rep Email"
                type="email"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.account_rep_mobile"
                label="Rep Mobile"
              />
            </VCol>
            <VCol cols="12" sm="6">
              <VTextField
                v-model="form.account_rep_phone"
                label="Rep Office Phone"
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

  <CircuitOutageDialog
    v-model="isOutageOpen"
    :circuit="outageCircuit"
    @updated="onOutageUpdated"
  />
  </div>
</template>

<style scoped>
.dot {
  display: inline-block;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.cursor-pointer {
  cursor: pointer;
}
.isp-overview {
  background: rgba(var(--v-theme-on-surface), 0.02);
}
.overview-table :deep(th) {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.6;
}
.isp-overview a {
  color: rgb(var(--v-theme-primary));
  text-decoration: none;
}
</style>
