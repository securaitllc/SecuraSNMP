<script setup lang="ts">
import { api } from '@/composables/useApi'

/**
 * IP address management.
 *
 * Two tabs over the same derived data: the fleet's ranges grouped by site, and the
 * planner that says which /24 a new site can take. Both read from what the network
 * reports — ARP, the switch FDB, device addresses and circuit subnets — so a range
 * nobody wrote down still shows as occupied.
 */

type Range = {
  cidr: string
  kind: 'wan' | 'lan'
  label: string | null
  gateway: string | null
  isp: string | null
  lec: string | null
  circuit_type: string | null
  usable: number
  seen: number
  fresh?: number
  pct: number
  shared_with: number[]
  state: 'ok' | 'warning' | 'critical'
  scope?: 'routed' | 'site-local'
  note: string | null
  recorded: boolean
}
type SiteGroup = {
  site_id: number
  site_number: string | null
  site_name: string
  address: string | null
  state: 'ok' | 'warning' | 'critical'
  ranges: Range[]
}
type Block = { octet: number, cidr: string, seen: number, pct: number, state: 'free' | 'used' | 'busy' | 'reserved' }
type Row = {
  ip: string
  state: 'assigned' | 'discovered' | 'conflict' | 'free'
  also_on?: string | null
  mac: string | null
  vendor: string | null
  device_name: string | null
  device_role: string | null
  interface?: string | null
  prefix_len?: number | null
  is_public?: boolean
  last_seen_at: string | null
  stale: boolean
}

const tab = ref<'ranges' | 'space'>('ranges')
const loading = ref(false)
const sites = ref<SiteGroup[]>([])
const summary = ref<Record<string, number>>({})
const space = ref<{ blocks: Block[], runs: { from: number, to: number, size: number }[], summary: Record<string, any> } | null>(null)
const search = ref('')
const kindFilter = ref<'all' | 'wan' | 'lan' | 'attn' | 'local'>('all')
const grouped = ref(true)
const expanded = ref<Set<number>>(new Set())
const siteNames = computed(() => Object.fromEntries(sites.value.map(s => [s.site_id, s.site_name])))

async function load() {
  loading.value = true
  try {
    const r = await api<{ sites: SiteGroup[], summary: Record<string, number> }>('/api/ipam/ranges')

    sites.value = r.sites
    summary.value = r.summary
  }
  finally { loading.value = false }
}

async function loadSpace() {
  if (space.value)
    return
  space.value = await api('/api/ipam/space')
}

watch(tab, t => { if (t === 'space') void loadSpace() })
onMounted(load)

/** A range matches the search on its own address, or on the site it belongs to. */
function matches(g: SiteGroup, r: Range) {
  const q = search.value.trim().toLowerCase()
  if (!q)
    return true

  return [r.cidr, r.gateway, r.label, r.isp, r.lec, g.site_name, g.site_number]
    .some(v => (v ?? '').toString().toLowerCase().includes(q))
}

const visible = computed(() => sites.value
  .map(g => ({
    ...g,
    ranges: g.ranges.filter(r => {
      if (!matches(g, r))
        return false

      // Locally-significant ranges are repeated at every site rather than allocated,
      // so they are not part of the address plan and stay out of the way by default.
      const local = r.scope === 'site-local'
      if (kindFilter.value === 'local')
        return local
      if (local)
        return false

      if (kindFilter.value === 'all')
        return true
      if (kindFilter.value === 'attn')
        return r.state !== 'ok'

      return r.kind === kindFilter.value
    }),
  }))
  .filter(g => g.ranges.length))

/** Grouping off flattens to one sortable list — the same rows, worst first. */
const flat = computed(() => visible.value
  .flatMap(g => g.ranges.map(r => ({ ...r, site: g })))
  .sort((a, b) => (b.pct - a.pct)))

const counts = computed(() => {
  const all = sites.value.flatMap(g => g.ranges)

  return {
    all: new Set(all.filter(r => r.scope !== 'site-local').map(r => r.cidr)).size,
    wan: new Set(all.filter(r => r.kind === 'wan').map(r => r.cidr)).size,
    lan: new Set(all.filter(r => r.kind === 'lan' && r.scope !== 'site-local').map(r => r.cidr)).size,
    attn: new Set(all.filter(r => r.state !== 'ok' && r.scope !== 'site-local').map(r => r.cidr)).size,
    local: new Set(all.filter(r => r.scope === 'site-local').map(r => r.cidr)).size,
  }
})

function toneOf(s: string) {
  return s === 'critical' ? 'error' : s === 'warning' ? 'warning' : 'success'
}
function barColor(r: Range) {
  if (r.kind === 'wan')
    return 'rgb(var(--v-theme-secondary))'

  return r.state === 'critical'
    ? 'rgb(var(--v-theme-error))'
    : r.state === 'warning' ? 'rgb(var(--v-theme-warning))' : 'rgb(var(--v-theme-primary))'
}
function toggle(id: number) {
  const s = new Set(expanded.value)

  s.has(id) ? s.delete(id) : s.add(id)
  expanded.value = s
}
function isOpen(id: number) { return expanded.value.size === 0 || expanded.value.has(id) }

// ---- range detail ----------------------------------------------------------
const detailOpen = ref(false)
const detailCidr = ref('')
const detailSite = ref<string>('')
const detailRows = ref<Row[]>([])
const detailSummary = ref<Record<string, number>>({})
const detailLoading = ref(false)
const detailFilter = ref<'all' | 'assigned' | 'discovered' | 'conflict' | 'free'>('all')

async function openDetail(cidr: string, siteName: string, siteId?: number) {
  detailOpen.value = true
  detailCidr.value = cidr
  detailSite.value = siteName
  detailLoading.value = true
  detailRows.value = []
  detailFilter.value = 'all'
  try {
    const p = new URLSearchParams({ cidr })
    if (siteId)
      p.set('site_id', String(siteId))
    const r = await api<{ rows: Row[], summary: Record<string, number> }>(`/api/ipam/range?${p}`)

    detailRows.value = r.rows
    detailSummary.value = r.summary
  }
  finally { detailLoading.value = false }
}

const detailVisible = computed(() => detailFilter.value === 'all'
  ? detailRows.value
  : detailRows.value.filter(r => r.state === detailFilter.value))

function stateColor(s: string) {
  if (s === 'conflict')
    return 'error'
  if (s === 'assigned')
    return 'primary'

  return s === 'free' ? 'success' : 'info'
}
function ago(iso: string | null) {
  if (!iso)
    return '—'
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (mins < 60)
    return `${mins}m`
  if (mins < 1440)
    return `${Math.round(mins / 60)}h`

  return `${Math.round(mins / 1440)}d`
}
</script>

<template>
  <div>
    <div class="d-flex align-start justify-space-between flex-wrap ga-3 mb-1">
      <div>
        <h4 class="text-h4 mb-1">
          IP Address Management
        </h4>
        <p class="text-body-2 text-medium-emphasis mb-0" style="max-width: 78ch">
          Every range the fleet uses — the subnets the ISP allocated and the LANs behind them — built from what
          the network reports rather than from what was written down.
        </p>
      </div>
      <VBtn variant="tonal" prepend-icon="ri-refresh-line" :loading="loading" @click="space = null; load(); tab === 'space' && loadSpace()">
        Refresh
      </VBtn>
    </div>

    <VTabs v-model="tab" class="mt-4">
      <VTab value="ranges">
        Ranges
      </VTab>
      <VTab value="space">
        Address space
      </VTab>
    </VTabs>

    <VWindow v-model="tab" class="mt-4">
      <!-- ============================ RANGES ============================ -->
      <VWindowItem value="ranges">
        <div class="stat-strip mb-4">
          <div v-for="s in [
            { k: 'Sites', v: summary.sites },
            { k: 'Ranges', v: summary.ranges },
            { k: 'Addresses seen', v: summary.addresses_seen },
            { k: 'WAN', v: summary.wan },
            { k: 'LAN', v: summary.lan },
            { k: 'Not recorded', v: summary.unrecorded_lan },
          ]" :key="s.k" class="stat"
          >
            <div class="stat__v mono">
              {{ (s.v ?? 0).toLocaleString() }}
            </div>
            <div class="stat__k">
              {{ s.k }}
            </div>
          </div>
        </div>

        <div class="d-flex align-center flex-wrap ga-2 mb-3">
          <div class="list-pills">
            <button
              v-for="f in [
                { k: 'all', label: 'All ranges', n: counts.all },
                { k: 'wan', label: 'WAN', n: counts.wan },
                { k: 'lan', label: 'LAN', n: counts.lan },
                { k: 'attn', label: 'Needs attention', n: counts.attn },
                { k: 'local', label: 'Site-local', n: counts.local },
              ]"
              :key="f.k" class="list-pill" :class="{ 'list-pill--on': kindFilter === f.k }"
              @click="kindFilter = f.k as any"
            >
              {{ f.label }} <span class="mono ms-1" style="opacity:.75">{{ f.n }}</span>
            </button>
          </div>

          <VDivider vertical class="mx-1" style="height: 22px; align-self: center" />

          <button class="list-pill" :class="{ 'list-pill--on': grouped }" @click="grouped = !grouped">
            <VIcon icon="ri-list-check" size="14" class="me-1" /> Group by site
          </button>

          <VSpacer />
          <VTextField
            v-model="search" placeholder="Search range, site or gateway…" density="compact"
            variant="outlined" prepend-inner-icon="ri-search-line" hide-details style="max-inline-size: 270px"
          />
        </div>

        <VCard>
          <VProgressLinear v-if="loading" indeterminate />

          <!-- grouped: site rollup, then its ranges -->
          <template v-if="grouped">
            <div v-for="g in visible" :key="g.site_id">
              <button class="grp" :style="{ borderInlineStartColor: `rgb(var(--v-theme-${toneOf(g.state)}))` }" @click="toggle(g.site_id)">
                <VIcon :icon="isOpen(g.site_id) ? 'ri-arrow-down-s-line' : 'ri-arrow-right-s-line'" size="18" class="text-disabled" />
                <div class="flex-grow-1 text-start">
                  <div class="font-weight-medium">
                    {{ g.site_name }}
                  </div>
                  <div class="text-caption text-disabled">
                    {{ g.address || '—' }}
                  </div>
                </div>
                <span class="text-caption text-medium-emphasis">{{ g.ranges.length }} range{{ g.ranges.length === 1 ? '' : 's' }}</span>
              </button>

              <template v-if="isOpen(g.site_id)">
                <div v-for="r in g.ranges" :key="r.cidr + g.site_id" class="rng" @click="openDetail(r.cidr, g.site_name, r.kind === 'lan' ? undefined : g.site_id)">
                  <span class="mono rng__net">{{ r.cidr }}</span>
                  <VChip size="x-small" :color="r.kind === 'wan' ? 'info' : 'primary'" variant="tonal" label>
                    {{ r.kind.toUpperCase() }}
                  </VChip>
                  <span class="mono text-caption text-medium-emphasis rng__gw">{{ r.gateway || '—' }}</span>
                  <div class="rng__bar">
                    <div class="bar">
                      <div :style="{ inlineSize: `${Math.min(100, r.pct)}%`, background: barColor(r) }" />
                    </div>
                    <div class="text-caption text-disabled mono mt-1">
                      {{ r.seen }} / {{ r.usable }}
                      <span v-if="r.note" class="ms-2">· {{ r.note }}</span>
                      <span v-if="r.kind === 'lan' && !r.recorded" class="ms-2 text-warning">· not recorded</span>
                    </div>
                  </div>
                  <span class="text-caption font-weight-medium rng__state" :class="`text-${toneOf(r.state)}`">
                    {{ r.state === 'ok' ? '' : r.state === 'warning' ? 'Filling up' : 'Nearly full' }}
                  </span>
                </div>
              </template>
            </div>
          </template>

          <!-- flat: the same rows, most-consumed first -->
          <template v-else>
            <div v-for="r in flat" :key="r.cidr + r.site.site_id" class="rng rng--flat" @click="openDetail(r.cidr, r.site.site_name, r.kind === 'lan' ? undefined : r.site.site_id)">
              <span class="mono rng__net">{{ r.cidr }}</span>
              <VChip size="x-small" :color="r.kind === 'wan' ? 'info' : 'primary'" variant="tonal" label>
                {{ r.kind.toUpperCase() }}
              </VChip>
              <span class="text-caption text-medium-emphasis rng__site">{{ r.site.site_name }}</span>
              <div class="rng__bar">
                <div class="bar">
                  <div :style="{ inlineSize: `${Math.min(100, r.pct)}%`, background: barColor(r) }" />
                </div>
                <div class="text-caption text-disabled mono mt-1">
                  {{ r.seen }} / {{ r.usable }}
                </div>
              </div>
              <span class="text-caption font-weight-medium rng__state" :class="`text-${toneOf(r.state)}`">
                {{ r.state === 'ok' ? '' : r.state === 'warning' ? 'Filling up' : 'Nearly full' }}
              </span>
            </div>
          </template>

          <div v-if="!loading && !visible.length" class="pa-8 text-center text-medium-emphasis">
            No ranges match.
          </div>

          <VDivider />
          <div class="pa-3 text-caption text-disabled d-flex justify-space-between flex-wrap ga-2">
            <span>LAN ranges are observed from the appliance ARP tables, refreshed every 180s.</span>
            <span>
            {{ summary.unrecorded_lan ?? 0 }} LAN range(s) are live but not recorded on their site.
            <span v-if="summary.unreadable_wan" class="text-warning">
              · {{ summary.unreadable_wan }} circuit subnet(s) are not a CIDR and could not be mapped.
            </span>
          </span>
          </div>
        </VCard>
      </VWindowItem>

      <!-- ========================= ADDRESS SPACE ========================= -->
      <VWindowItem value="space">
        <template v-if="space">
          <div class="stat-strip mb-4">
            <div v-for="s in [
              { k: 'Blocks', v: space.summary.total },
              { k: 'In use', v: space.summary.in_use },
              { k: 'Free', v: space.summary.free },
              { k: 'Largest free run', v: space.summary.largest_run },
            ]" :key="s.k" class="stat"
            >
              <div class="stat__v mono">
                {{ s.v }}
              </div>
              <div class="stat__k">
                {{ s.k }}
              </div>
            </div>
          </div>

          <VRow>
            <VCol cols="12" md="8">
              <VCard class="pa-4">
                <div class="d-flex justify-space-between align-center flex-wrap ga-2 mb-3">
                  <span class="sec-hd">Every /24 in {{ space.summary.total ? '10.200.0.0/16' : '' }} · .0 → .255</span>
                  <div class="d-flex ga-4 text-caption text-medium-emphasis">
                    <span><i class="sw" style="background: rgb(var(--v-theme-primary))" /> In use</span>
                    <span><i class="sw" style="background: rgb(var(--v-theme-warning))" /> Over 70%</span>
                    <span><i class="sw sw--free" /> Free</span>
                    <span><i class="sw sw--res" /> Reserved</span>
                  </div>
                </div>
                <div class="grid16">
                  <div
                    v-for="b in space.blocks" :key="b.octet" class="blk" :class="`blk--${b.state}`"
                    :title="`${b.cidr} — ${b.state === 'free' ? 'free' : b.state === 'reserved' ? 'reserved by convention' : `${b.seen} of 254 seen`}`"
                    @click="b.state !== 'free' && b.state !== 'reserved' ? openDetail(b.cidr, 'Address space') : null"
                  >
                    {{ b.octet }}
                  </div>
                </div>
              </VCard>
            </VCol>

            <VCol cols="12" md="4">
              <VCard class="pa-4 mb-4">
                <div class="sec-hd mb-3">
                  Contiguous free runs
                </div>
                <div v-for="r in space.runs" :key="r.from" class="run">
                  <div>
                    <div class="mono text-body-2 font-weight-medium">
                      10.200.{{ r.from }}.0/24 – 10.200.{{ r.to }}.0/24
                    </div>
                    <div class="text-caption text-disabled">
                      {{ r.size }} consecutive block{{ r.size === 1 ? '' : 's' }}
                    </div>
                  </div>
                </div>
              </VCard>

              <VAlert v-if="space.summary.suggested" color="primary" variant="tonal" density="comfortable">
                <div class="sec-hd mb-1">
                  Suggested next block
                </div>
                <div class="mono text-h6 mb-1">
                  {{ space.summary.suggested }}
                </div>
                <div class="text-caption">
                  The start of the largest untouched run, so a new site can grow into its neighbours later
                  without a renumber.
                </div>
              </VAlert>
            </VCol>
          </VRow>

          <VAlert color="warning" variant="tonal" density="comfortable" class="mt-4">
            Occupancy is read from the wire, so a range nobody documented still counts as taken. The third octet
            does <b>not</b> reliably match the service-centre number, and co-located centres share one LAN — a new
            site's block is a decision, not a formula.
          </VAlert>
        </template>
        <VCard v-else class="pa-8 text-center text-medium-emphasis">
          <VProgressCircular indeterminate size="22" class="me-2" /> Reading the address space…
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- range detail: right-side drawer, the house pattern -->
    <VNavigationDrawer v-model="detailOpen" temporary location="end" width="920" class="nodus-drawer">
      <VCard flat class="d-flex flex-column" style="block-size: 100%">
        <div class="pa-5 pb-4 border-b">
          <div class="d-flex align-start justify-space-between">
            <div>
              <div class="sec-hd mb-1">
                Range detail
              </div>
              <div class="mono text-h5">
                {{ detailCidr }}
              </div>
              <div class="text-caption text-medium-emphasis mt-1">
                {{ detailSite }}
              </div>
            </div>
            <VBtn icon="ri-close-line" variant="text" size="small" @click="detailOpen = false" />
          </div>
        </div>

        <div class="pa-5 flex-grow-1 overflow-auto">
          <VProgressLinear v-if="detailLoading" indeterminate class="mb-4" />

          <div class="d-flex ga-2 mb-4 flex-wrap">
            <VChip size="small" variant="tonal">
              {{ detailSummary.usable ?? 0 }} usable
            </VChip>
            <VChip size="small" color="primary" variant="tonal">
              {{ detailSummary.assigned ?? 0 }} assigned
            </VChip>
            <VChip size="small" color="info" variant="tonal">
              {{ detailSummary.discovered ?? 0 }} discovered
            </VChip>
            <VChip v-if="detailSummary.conflict" size="small" color="error" variant="tonal">
              {{ detailSummary.conflict }} conflict
            </VChip>
            <VChip size="small" color="success" variant="tonal">
              {{ detailSummary.free ?? 0 }} free
            </VChip>
            <VChip v-if="detailSummary.stale" size="small" color="warning" variant="tonal">
              {{ detailSummary.stale }} stale
            </VChip>
          </div>

          <div class="list-pills mb-3">
            <button
              v-for="f in ['all', 'free', 'assigned', 'discovered', 'conflict']" :key="f"
              class="list-pill" :class="{ 'list-pill--on': detailFilter === f }" @click="detailFilter = f as any"
            >
              {{ f.charAt(0).toUpperCase() + f.slice(1) }}
            </button>
          </div>

          <VTable density="compact" class="addr-table">
            <thead>
              <tr>
                <th style="width: 148px">
                  Address
                </th>
                <th style="width: 96px">
                  State
                </th>
                <th>Device</th>
                <th style="width: 150px">
                  Interface
                </th>
                <th style="width: 168px">
                  MAC / vendor
                </th>
                <th style="width: 64px">
                  Seen
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in detailVisible" :key="r.ip">
                <td class="mono">
                  {{ r.ip }}<span v-if="r.prefix_len" class="text-disabled">/{{ r.prefix_len }}</span>
                  <VChip v-if="r.is_public" size="x-small" color="warning" variant="tonal" label class="ms-2">
                    public
                  </VChip>
                </td>
                <td>
                  <VChip size="x-small" :color="stateColor(r.state)" variant="tonal" label>
                    {{ r.state }}
                  </VChip>
                </td>
                <td>
                  <template v-if="r.device_name">
                    <div class="font-weight-medium">
                      {{ r.device_name }}
                    </div>
                    <div v-if="r.device_role" class="text-caption text-disabled">
                      {{ r.device_role }}
                    </div>
                  </template>
                  <span v-else-if="r.also_on" class="text-medium-emphasis">
                    Answered by {{ r.also_on }}
                  </span>
                  <span v-else-if="r.state === 'free'" class="text-success">Available</span>
                  <span v-else class="text-medium-emphasis">Not on a known device</span>
                </td>
                <td class="mono text-caption">
                  {{ r.interface || '—' }}
                </td>
                <td>
                  <div class="mono text-caption">
                    {{ r.mac || '—' }}
                  </div>
                  <div v-if="r.vendor" class="text-caption text-disabled">
                    {{ r.vendor }}
                  </div>
                </td>
                <td class="mono text-caption" :class="r.stale ? 'text-warning' : 'text-disabled'">
                  {{ ago(r.last_seen_at) }}
                </td>
              </tr>
              <tr v-if="!detailLoading && !detailVisible.length">
                <td colspan="6" class="text-center text-medium-emphasis py-6">
                  Nothing in this range yet.
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </VCard>
    </VNavigationDrawer>
  </div>
</template>

<style scoped lang="scss">
.mono { font-family: 'IBM Plex Mono', ui-monospace, monospace; }

/**
 * A large range enumerates every address, so the detail table is long AND was wider
 * than the drawer — which pushed its horizontal scrollbar to the very bottom of 254
 * rows, where it could not be reached while reading. Fixing the layout and letting
 * long values truncate removes the sideways scroll entirely; the header stays put so
 * the columns remain identifiable however far down you are.
 */
.addr-table {
  :deep(table) {
    inline-size: 100%;
    table-layout: fixed;
  }

  :deep(th) {
    position: sticky;
    z-index: 1;
    background: rgb(var(--v-theme-surface));
    inset-block-start: 0;
  }

  :deep(td) {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  // The device and interface names are the ones that overflow; let them wrap to a
  // second line rather than truncating an identifier someone needs to read in full.
  :deep(td:nth-child(3)),
  :deep(td:nth-child(4)) {
    white-space: normal;
    word-break: break-word;
  }
}

.sec-hd {
  color: rgba(var(--v-theme-on-surface), .55);
  font-size: .62rem;
  font-weight: 600;
  letter-spacing: .1em;
  text-transform: uppercase;
}

.stat-strip {
  display: grid;
  overflow: hidden;
  border: 1px solid rgba(var(--v-theme-on-surface), .1);
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), .1);
  gap: 1px;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
}
.stat { background: rgb(var(--v-theme-surface)); padding: 13px 16px; }
.stat__v { font-size: 20px; font-weight: 600; }
.stat__k {
  color: rgba(var(--v-theme-on-surface), .55);
  font-size: .62rem;
  font-weight: 600;
  letter-spacing: .1em;
  margin-block-start: 2px;
  text-transform: uppercase;
}

.grp {
  display: flex;
  align-items: center;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .07);
  border-inline-start: 3px solid transparent;
  background: rgba(var(--v-theme-on-surface), .022);
  cursor: pointer;
  font: inherit;
  gap: 9px;
  inline-size: 100%;
  padding: 11px 16px;
}

.rng {
  display: grid;
  align-items: center;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .055);
  cursor: pointer;
  gap: 14px;
  grid-template-columns: 210px 62px 1fr 240px 110px;
  padding-block: 10px;
  padding-inline: 16px 16px;

  &:hover { background: rgba(var(--v-theme-on-surface), .03); }
  &--flat { grid-template-columns: 210px 62px 1fr 240px 110px; }
}
.rng__net { padding-inline-start: 34px; font-size: 12.5px; font-weight: 500; }
.rng--flat .rng__net { padding-inline-start: 0; }
.rng__state { text-align: end; }

.bar {
  display: flex;
  overflow: hidden;
  border-radius: 999px;
  background: rgba(var(--v-theme-on-surface), .09);
  block-size: 6px;
}

.grid16 { display: grid; gap: 4px; grid-template-columns: repeat(16, 1fr); }
.blk {
  display: grid;
  border-radius: 3px;
  aspect-ratio: 1;
  font-family: 'IBM Plex Mono', monospace;
  font-size: 8.5px;
  font-weight: 600;
  place-items: center;

  &--used { background: rgb(var(--v-theme-primary)); color: #fff; }
  &--busy { background: rgb(var(--v-theme-warning)); color: #5c4208; }
  &--free { background: rgba(var(--v-theme-on-surface), .11); color: rgba(var(--v-theme-on-surface), .45); }
  &--reserved {
    background: repeating-linear-gradient(45deg, rgba(var(--v-theme-on-surface), .22) 0 3px, rgba(var(--v-theme-on-surface), .06) 3px 6px);
    color: rgba(var(--v-theme-on-surface), .5);
  }
  &--used, &--busy { cursor: pointer; }
}

.sw { display: inline-block; border-radius: 2px; block-size: 8px; inline-size: 8px; margin-inline-end: 5px; }
.sw--free { background: rgba(var(--v-theme-on-surface), .14); }
.sw--res { background: repeating-linear-gradient(45deg, rgba(var(--v-theme-on-surface), .3) 0 2px, transparent 2px 4px); }

.run {
  display: flex;
  align-items: center;
  border-block-end: 1px solid rgba(var(--v-theme-on-surface), .07);
  justify-content: space-between;
  padding-block: 9px;

  &:last-child { border-block-end: 0; }
}
.border-b { border-block-end: 1px solid rgba(var(--v-theme-on-surface), .1); }
</style>
