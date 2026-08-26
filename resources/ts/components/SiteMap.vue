<script setup lang="ts">
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import 'leaflet.markercluster'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import { useTheme } from 'vuetify'
import type { DashboardAlert, DashboardSiteHealth } from '@/types/models'

const props = withDefaults(defineProps<{
  sites: DashboardSiteHealth[]
  alerts?: DashboardAlert[]
  height?: number
}>(), { height: 340 })

const emit = defineEmits<{
  (e: 'select', site: DashboardSiteHealth): void
  (e: 'openAlert', alert: DashboardAlert): void
}>()

const theme = useTheme()
const router = useRouter()
const mapEl = ref<HTMLDivElement | null>(null)

// Drill down from a location into its device list ("then I can go from there").
function viewDevices(site: DashboardSiteHealth) {
  router.push({ path: '/devices', query: { site_id: site.id } })
}
let map: L.Map | null = null
let cluster: L.MarkerClusterGroup | null = null
let tiles: L.TileLayer | null = null

const plotted = computed(() => props.sites.filter(s => s.latitude !== null && s.longitude !== null))
const hasCoords = computed(() => plotted.value.length > 0)

// ---- click popover: the location's alarms (overlaid on the map) ----
const selected = ref<DashboardSiteHealth | null>(null)
const selectedAlerts = computed(() =>
  selected.value ? (props.alerts ?? []).filter(a => a.site_id === selected.value!.id) : [])
const sevColor: Record<string, string> = { critical: 'error', warning: 'warning', info: 'info' }

// Follow the app theme (not just the OS), so the basemap flips with the toggle.
const isDark = computed(() => theme.global.current.value.dark)
// Tiles are proxied + cached by our own server (MapTileController), not fetched
// from the CARTO CDN by each browser — the operators' path to the internet is the
// slow part, the server reaches CARTO in ~0.07s. Served from the local LAN + cache.
function tileUrl(dark: boolean): string {
  return `/map-tiles/${dark ? 'dark' : 'light'}/{z}/{x}/{y}`
}

// Leaflet renders divIcon/tooltip content as raw HTML, so any site-provided
// string (the name) must be escaped to avoid stored XSS.
function esc(s: string): string {
  return String(s).replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' }[c] as string))
}

// Three-state health from the site's alarms: DOWN (a service-affecting critical),
// DEGRADED (only warnings), or HEALTHY. Derived from the alert list so a warning-only
// site reads amber, not red — the flat dashboard uses the same severity split.
function siteSeverity(site: DashboardSiteHealth): 'down' | 'degraded' | 'healthy' {
  const at = (props.alerts ?? []).filter(a => a.site_id === site.id)
  if (at.some(a => a.severity === 'critical'))
    return 'down'
  if (at.length > 0 || site.active_alert_count > 0)
    return 'degraded'
  return 'healthy'
}

const healthCounts = computed(() => {
  let healthy = 0; let degraded = 0; let down = 0
  for (const s of plotted.value) {
    const sev = siteSeverity(s)
    if (sev === 'down') down++
    else if (sev === 'degraded') degraded++
    else healthy++
  }
  return { healthy, degraded, down }
})

// A flat severity dot with a map-coloured ring — green healthy / amber degraded /
// red down. The down count sits on the dot; no pulse (a flat wall reads calmer).
function siteIcon(site: DashboardSiteHealth): L.DivIcon {
  const sev = siteSeverity(site)
  const label = sev === 'down' && site.active_alert_count > 0 ? String(site.active_alert_count) : ''
  return L.divIcon({
    className: '',
    html: `<span class="sm-pin is-${sev}">${label}</span>`,
    iconSize: [22, 22],
    iconAnchor: [11, 11],
  })
}

// Cluster badge: a ring whose filled arc is the share of sites impacted, wrapped
// around the impacted count.
//
// A solid red disc reading "68" was read as sixty-eight sites down when one site in
// the cluster had a single alarm. The number now counts only impacted sites, the
// total sits under it in small type, and the arc carries the proportion — so one bad
// site in a big cluster looks like one bad site.
function clusterIcon(c: L.MarkerCluster): L.DivIcon {
  const count = c.getChildCount()
  const impacted = c.getAllChildMarkers().filter(m => (m.options as any).health === 'critical').length
  const size = count < 10 ? 38 : count < 50 ? 44 : 50
  // A single impacted site in a large cluster would otherwise draw an arc too thin
  // to see; floor it at a readable sliver.
  const pct = impacted > 0 ? Math.max(8, Math.round((impacted / count) * 100)) : 100
  const body = impacted > 0
    ? `<b>${impacted}</b><i>of ${count}</i>`
    : `<b>${count}</b>`
  const title = impacted > 0
    ? `${impacted} of ${count} sites impacted — click to zoom in`
    : `${count} sites · all healthy — click to zoom in`
  return L.divIcon({
    className: '',
    html: `<span class="sm-cluster ${impacted > 0 ? 'is-critical' : 'is-good'}"`
      + ` style="width:${size}px;height:${size}px;--sm-pct:${pct}%" title="${title}">${body}</span>`,
    iconSize: [size, size],
    iconAnchor: [size / 2, size / 2],
  })
}

function rebuildMarkers() {
  if (!map || !cluster)
    return
  cluster.clearLayers()
  for (const site of plotted.value) {
    const marker = L.marker([site.latitude as number, site.longitude as number], {
      icon: siteIcon(site),
      // Cluster's impacted-arc counts DOWN sites (a critical, service-affecting outage).
      health: siteSeverity(site) === 'down' ? 'critical' : 'good',
    } as L.MarkerOptions)
    marker.bindTooltip(
      `<strong>${esc(site.name)}</strong><br>${site.device_count} devices · ${site.circuit_count} circuits · ${site.active_alert_count} active`,
      { direction: 'top', offset: [0, -12] },
    )
    marker.on('click', () => {
      selected.value = selected.value?.id === site.id ? null : site
      emit('select', site)
    })
    cluster.addLayer(marker)
  }
  if (plotted.value.length)
    map.fitBounds(cluster.getBounds().pad(0.3), { maxZoom: 7 })
}

/**
 * ctrl/⌘ + scroll zooms the map; a plain wheel is left alone so the page scrolls.
 *
 * Steps a whole zoom level per event rather than scaling by deltaY. Leaflet's
 * default zoomSnap is 1, so any fractional target is rounded back — a scaled
 * delta small enough to feel right for a trackpad pinch rounds to zero for a
 * mouse notch and the map only recenters, never zooms. Direction is all we need;
 * magnitude is the map's business. Same approach as the topology view.
 */
function onMapWheel(e: WheelEvent) {
  if (!e.ctrlKey && !e.metaKey)
    return // plain scroll = page scroll

  e.preventDefault()
  if (!map || e.deltaY === 0)
    return

  map.setZoomAround(
    map.mouseEventToContainerPoint(e),
    map.getZoom() + (e.deltaY < 0 ? 1 : -1),
  )
}

onMounted(() => {
  if (!mapEl.value)
    return
  // Plain scroll must scroll the PAGE — an operator scrolling past the map to
  // reach the alarm list should not have the map swallow the wheel and zoom.
  // Only ctrl/⌘ + scroll zooms, matching the topology view. Handled manually
  // rather than via Leaflet's scrollWheelZoom so trackpad pinch (which arrives
  // as a wheel event with ctrlKey set) keeps working too.
  map = L.map(mapEl.value, { scrollWheelZoom: false, worldCopyJump: true })
    .setView([39.5, -98.35], 4) // continental US

  mapEl.value.addEventListener('wheel', onMapWheel, { passive: false })

  tiles = L.tileLayer(tileUrl(isDark.value), {
    attribution: '© OpenStreetMap © CARTO',
    maxZoom: 19,
  }).addTo(map)

  cluster = L.markerClusterGroup({
    iconCreateFunction: clusterIcon,
    showCoverageOnHover: false,
    maxClusterRadius: 45,
  })
  map.addLayer(cluster)
  rebuildMarkers()
})

onBeforeUnmount(() => {
  mapEl.value?.removeEventListener('wheel', onMapWheel)
  map?.remove()
  map = null
  cluster = null
  tiles = null
})

watch(() => props.sites, rebuildMarkers, { deep: true })
// Flip the basemap when the app theme toggles (light map ↔ dark map).
watch(isDark, dark => tiles?.setUrl(tileUrl(dark)))
</script>

<template>
  <div class="site-map">
    <div
      ref="mapEl"
      class="site-map__map"
      :style="{ height: `${props.height}px` }"
    />

    <div class="site-map__hint">
      Ctrl + scroll to zoom · drag to pan
    </div>

    <!-- click popover: the location's alarms -->
    <div
      v-if="selected"
      class="site-map__pop"
    >
      <div class="site-map__pop-head">
        <span class="site-map__tooltip-name">{{ selected.name }}</span>
        <button
          type="button"
          class="site-map__pop-x"
          aria-label="Close"
          @click="selected = null"
        >×</button>
      </div>
      <div
        v-if="selectedAlerts.length === 0"
        class="site-map__pop-empty"
      >
        No active alerts at this location.
      </div>
      <button
        type="button"
        class="site-map__pop-devices"
        @click="viewDevices(selected)"
      >
        <span>{{ selected.device_count }} device(s) — view all</span>
        <span aria-hidden="true">→</span>
      </button>
      <button
        v-for="a in selectedAlerts"
        :key="a.key"
        type="button"
        class="site-map__pop-row"
        @click="emit('openAlert', a)"
      >
        <span
          class="site-map__pop-dot"
          :style="{ backgroundColor: `rgb(var(--v-theme-${sevColor[a.severity] ?? 'warning'}))` }"
        />
        <span class="site-map__pop-title">{{ a.title }}</span>
        <span class="site-map__pop-sub">{{ a.subtitle }}</span>
      </button>
    </div>

    <div
      v-if="!hasCoords"
      class="site-map__empty"
    >
      No sites have coordinates yet. Add latitude &amp; longitude to a site to plot it.
    </div>

    <div class="site-map__legend">
      <span class="site-map__legend-item"><span class="site-map__legend-dot is-healthy" /><b>{{ healthCounts.healthy }}</b> healthy</span>
      <span class="site-map__legend-item"><span class="site-map__legend-dot is-degraded" /><b>{{ healthCounts.degraded }}</b> degraded</span>
      <span class="site-map__legend-item"><span class="site-map__legend-dot is-down" /><b>{{ healthCounts.down }}</b> down</span>
    </div>
  </div>
</template>

<style scoped>
.site-map { position: relative; width: 100%; }
.site-map__hint {
  position: absolute; bottom: 8px; left: 10px; z-index: 500;
  padding: 2px 8px; border-radius: 4px;
  font-size: 0.7rem; line-height: 1.6;
  color: rgba(var(--v-theme-on-surface), 0.6);
  background: rgba(var(--v-theme-surface), 0.75);
  pointer-events: none;
}
.site-map__map {
  width: 100%; border-radius: 8px; z-index: 0;
  background: rgba(var(--v-theme-on-surface), 0.04);
}

/* Blend Leaflet's default (light) chrome into the app theme — the white zoom
   buttons + attribution look awkward on the dark basemap otherwise. */
:deep(.leaflet-bar) {
  border: 1px solid rgba(var(--v-theme-on-surface), 0.18);
  box-shadow: 0 1px 6px rgba(0, 0, 0, .35);
}
:deep(.leaflet-control-zoom a) {
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  border-bottom-color: rgba(var(--v-theme-on-surface), 0.14);
}
:deep(.leaflet-control-zoom a:hover) { background: rgba(var(--v-theme-on-surface), 0.08); }
:deep(.leaflet-control-attribution) {
  background: rgba(var(--v-theme-surface), 0.82) !important;
  color: rgba(var(--v-theme-on-surface), 0.5);
}
:deep(.leaflet-control-attribution a) { color: rgba(var(--v-theme-on-surface), 0.7); }
:deep(.leaflet-tooltip) {
  background: rgb(var(--v-theme-surface));
  color: rgb(var(--v-theme-on-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.14);
  box-shadow: 0 2px 10px rgba(0, 0, 0, .3);
}
:deep(.leaflet-tooltip-top::before) { border-top-color: rgb(var(--v-theme-surface)); }

/* pins + clusters (global so Leaflet's injected divIcon markup is styled) */
:deep(.sm-pin) {
  display: grid; place-items: center;
  width: 15px; height: 15px; border-radius: 50%;
  color: #fff; font-size: 10px; font-weight: 700; line-height: 1;
  border: 2px solid rgb(var(--v-theme-surface));
  box-shadow: 0 0 0 1px rgba(0, 0, 0, .4);
}
:deep(.sm-pin.is-healthy) { background: rgb(var(--v-theme-success)); }
:deep(.sm-pin.is-degraded) { background: rgb(var(--v-theme-warning)); }
:deep(.sm-pin.is-down) { width: 17px; height: 17px; background: rgb(var(--v-theme-error)); box-shadow: 0 0 0 1px rgba(0,0,0,.4), 0 0 0 4px rgba(var(--v-theme-error), .16); }
/* The ring is a conic gradient: filled portion = share of sites impacted, the
   rest a muted track. An inset disc masks the middle so it reads as a ring, and
   the count sits on top of it. */
:deep(.sm-cluster) {
  position: relative;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  border-radius: 50%;
  background:
    conic-gradient(from -90deg, var(--sm-ring) var(--sm-pct, 100%), rgba(var(--v-theme-on-surface), 0.22) 0);
  box-shadow: 0 2px 8px rgba(0, 0, 0, .45);
  transition: transform .12s ease;
}
:deep(.sm-cluster)::before {
  content: '';
  position: absolute; inset: 4px;
  border-radius: 50%;
  background: rgb(var(--v-theme-surface));
}
:deep(.sm-cluster:hover) { transform: scale(1.06); }
:deep(.sm-cluster) b,
:deep(.sm-cluster) i {
  position: relative; /* above the masking disc */
  line-height: 1;
}
:deep(.sm-cluster) b { font-size: 15px; font-weight: 800; letter-spacing: -.01em; }
:deep(.sm-cluster) i {
  font-style: normal; font-size: 8.5px; font-weight: 600;
  margin-block-start: 2px; letter-spacing: .02em;
  color: rgba(var(--v-theme-on-surface), .65);
}
:deep(.sm-cluster.is-good) { --sm-ring: rgb(var(--v-theme-success)); }
:deep(.sm-cluster.is-good) b { color: rgba(var(--v-theme-on-surface), .92); }
:deep(.sm-cluster.is-critical) { --sm-ring: rgb(var(--v-theme-error)); }
:deep(.sm-cluster.is-critical) b { color: rgb(var(--v-theme-error)); }

.site-map__pop {
  position: absolute; top: 8px; left: 8px;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  border-radius: 8px; box-shadow: 0 4px 16px rgba(0, 0, 0, .22);
  padding: 6px; width: 300px; max-width: calc(100% - 16px); z-index: 500;
}
.site-map__tooltip-name { font-weight: 600; font-size: 13px; }
.site-map__pop-head { display: flex; align-items: center; justify-content: space-between; padding: 4px 6px 6px; }
.site-map__pop-x { border: 0; background: transparent; font-size: 18px; line-height: 1; cursor: pointer; color: rgba(var(--v-theme-on-surface), 0.6); }
.site-map__pop-empty { padding: 8px 6px 10px; font-size: 12.5px; color: rgba(var(--v-theme-on-surface), 0.6); }
.site-map__pop-row {
  display: grid; grid-template-columns: 12px 1fr auto; align-items: center; gap: 8px;
  width: 100%; text-align: left; border: 0; background: transparent; cursor: pointer;
  padding: 7px 6px; border-radius: 6px; font: inherit;
}
.site-map__pop-row:hover { background: rgba(var(--v-theme-on-surface), 0.05); }
.site-map__pop-devices {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  width: 100%; border: 0; cursor: pointer; font: inherit; font-size: 12.5px; font-weight: 600;
  padding: 8px 10px; margin-bottom: 4px; border-radius: 6px;
  color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), 0.10);
}
.site-map__pop-devices:hover { background: rgba(var(--v-theme-primary), 0.18); }
.site-map__pop-dot { width: 9px; height: 9px; border-radius: 50%; }
.site-map__pop-title { font-size: 13px; font-weight: 550; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.site-map__pop-sub { font-size: 11px; color: rgba(var(--v-theme-on-surface), 0.6); }

.site-map__empty {
  position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
  text-align: center; padding: 24px; font-size: 13px; color: rgba(var(--v-theme-on-surface), 0.6);
  background: rgba(var(--v-theme-surface), 0.7); z-index: 400;
}

.site-map__legend {
  position: absolute; bottom: 8px; right: 10px; display: flex; align-items: center; gap: 12px;
  font-size: 11.5px; color: rgba(var(--v-theme-on-surface), 0.7);
  background: rgba(var(--v-theme-surface), 0.85); padding: 4px 10px; border-radius: 6px;
  z-index: 500;
}
.site-map__legend-item { display: flex; align-items: center; gap: 5px; }
.site-map__legend-item b { font-weight: 600; font-variant-numeric: tabular-nums; }
.site-map__legend-dot { width: 8px; height: 8px; border-radius: 50%; }
.site-map__legend-dot.is-healthy { background: rgb(var(--v-theme-success)); }
.site-map__legend-dot.is-degraded { background: rgb(var(--v-theme-warning)); }
.site-map__legend-dot.is-down { background: rgb(var(--v-theme-error)); }
</style>
