<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/composables/useApi'
import type { CircuitHistory, CircuitHistoryItem } from '@/types/models'

const props = defineProps<{ modelValue: boolean, circuitId: number | null }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: boolean): void }>()

const open = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const loading = ref(false)
const data = ref<CircuitHistory | null>(null)

watch(() => [props.modelValue, props.circuitId], async ([isOpen, id]) => {
  if (!isOpen || !id) return
  loading.value = true
  data.value = null
  try {
    data.value = await api<CircuitHistory>(`/api/circuits/${id}/history?days=7`)
  }
  finally { loading.value = false }
}, { immediate: true })

// ---- formatting helpers ----
function fmtDur(min: number): string {
  if (min < 1) return '<1m'
  const d = Math.floor(min / 1440); const h = Math.floor((min % 1440) / 60); const m = min % 60
  if (d > 0) return `${d}d ${h}h`
  if (h > 0) return `${h}h ${m}m`
  return `${m}m`
}
function fmtWhen(iso: string | null): string {
  if (!iso) return '—'
  const dt = new Date(iso)
  return dt.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const SOURCE_META: Record<string, { label: string, icon: string }> = {
  'circuit-ping': { label: 'Circuit ping', icon: 'ri-pulse-line' },
  'ip-sla': { label: 'IP-SLA (WAN)', icon: 'ri-speed-up-line' },
  'gateway': { label: 'Gateway', icon: 'ri-router-line' },
  'next-hop': { label: 'Next-hop', icon: 'ri-arrow-right-up-line' },
  'wan-link': { label: 'WAN link', icon: 'ri-link' },
  'edge-unreachable': { label: 'Edge unreachable', icon: 'ri-cloud-off-line' },
}
function meta(s: string) { return SOURCE_META[s] ?? { label: s, icon: 'ri-alarm-warning-line' } }
function sevColor(s: string) { return s === 'critical' ? 'error' : 'warning' }

const env = computed(() => data.value?.envelope ?? null)
const bounce = computed(() => data.value?.bounce ?? null)
const items = computed<CircuitHistoryItem[]>(() => data.value?.items ?? [])
const title = computed(() => {
  const c = data.value?.circuit
  return c ? `${c.isp_name} · ${c.circuit_id}${c.wan_interface ? ` · ${c.wan_interface}` : ''}` : 'Circuit history'
})
</script>

<template>
  <VDialog v-model="open" max-width="720" scrollable>
    <VCard>
      <VCardItem>
        <VCardTitle class="text-h6">{{ title }}</VCardTitle>
        <VCardSubtitle>Full outage history — every related alert in one place</VCardSubtitle>
        <template #append>
          <VBtn icon variant="text" size="small" @click="open = false"><VIcon icon="ri-close-line" /></VBtn>
        </template>
      </VCardItem>

      <VDivider />

      <VCardText style="max-height: 72vh;">
        <div v-if="loading" class="text-center py-8"><VProgressCircular indeterminate color="primary" /></div>

        <template v-else-if="data">
          <!-- Outage envelope — the TRUE down-since, not the loudest alarm -->
          <div v-if="env && env.down_since" class="envelope mb-5">
            <div class="env-main">
              <VIcon icon="ri-time-line" size="18" class="me-2" />
              <span class="env-word">Down</span>
              <span class="env-dur">{{ fmtDur(env.down_min) }}</span>
              <span class="env-since">since {{ fmtWhen(env.down_since) }}</span>
              <VChip
                v-if="bounce?.flapping"
                size="small" color="warning" variant="tonal" class="ms-auto"
                prepend-icon="ri-restart-line"
              >Bouncing · {{ bounce.flaps }} in {{ bounce.window_h }}h</VChip>
            </div>
            <div v-if="env.escalated && env.hard_down_since" class="env-sub">
              hard-down {{ fmtDur(env.hard_down_min) }} — critical since {{ fmtWhen(env.hard_down_since) }}
            </div>
          </div>
          <div v-else class="text-medium-emphasis mb-5 d-flex align-center ga-2">
            <VIcon icon="ri-checkbox-circle-line" color="success" size="18" /> No open outage right now.
          </div>

          <!-- Timeline of every related alert -->
          <div class="text-overline text-medium-emphasis mb-2">Alert timeline</div>
          <div v-if="items.length" class="timeline">
            <div v-for="it in items" :key="it.key" class="tl-row" :class="{ ongoing: it.ongoing }">
              <div class="tl-mark" :class="it.severity"></div>
              <div class="tl-body">
                <div class="tl-top">
                  <VIcon :icon="meta(it.source).icon" size="15" class="me-1" :color="sevColor(it.severity)" />
                  <span class="tl-title">{{ it.title }}</span>
                  <VChip size="x-small" variant="tonal" class="ms-1">{{ meta(it.source).label }}</VChip>
                  <VChip v-if="it.ongoing" size="x-small" color="error" variant="flat" class="ms-1">ongoing</VChip>
                </div>
                <div class="tl-meta">
                  <span>{{ fmtWhen(it.started_at) }} → {{ it.ended_at ? fmtWhen(it.ended_at) : 'now' }}</span>
                  <span class="tl-dur">{{ fmtDur(it.duration_min) }}</span>
                  <span v-if="it.ticket" class="tl-tk">#{{ it.ticket }}</span>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="text-medium-emphasis py-4">No related alerts in the last 7 days.</div>
        </template>
      </VCardText>
    </VCard>
  </VDialog>
</template>

<style scoped>
.envelope { border: 1px solid rgba(var(--v-theme-error), .35); background: rgba(var(--v-theme-error), .06); border-radius: 10px; padding: 14px 16px; }
.env-main { display: flex; align-items: center; gap: 10px; }
.env-word { font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: rgb(var(--v-theme-error)); font-size: 13px; }
.env-dur { font-family: 'IBM Plex Mono', monospace; font-size: 22px; font-weight: 600; }
.env-since { color: rgba(var(--v-theme-on-surface), .6); font-size: 13px; }
.env-sub { margin-top: 6px; font-size: 12.5px; color: rgba(var(--v-theme-on-surface), .6); padding-left: 26px; }

.timeline { display: flex; flex-direction: column; }
.tl-row { display: grid; grid-template-columns: 12px 1fr; gap: 12px; padding: 11px 0; border-bottom: 1px solid rgba(var(--v-theme-on-surface), .08); }
.tl-row:last-child { border-bottom: 0; }
.tl-row.ongoing .tl-title { font-weight: 600; }
.tl-mark { width: 9px; height: 9px; border-radius: 50%; margin-top: 5px; }
.tl-mark.critical { background: rgb(var(--v-theme-error)); }
.tl-mark.warning { background: rgb(var(--v-theme-warning)); }
.tl-top { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.tl-title { font-size: 14px; }
.tl-meta { display: flex; align-items: center; gap: 14px; font-size: 12px; color: rgba(var(--v-theme-on-surface), .55); margin-top: 3px; }
.tl-dur { font-family: 'IBM Plex Mono', monospace; }
.tl-tk { font-family: 'IBM Plex Mono', monospace; }
</style>
