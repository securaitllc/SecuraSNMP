<script setup lang="ts">
import { api } from '@/composables/useApi'
import { formatDateTime } from '@/utils/datetime'
import { useAuthStore } from '@/stores/auth'
import type { DeviceInterface, OrgTopologySite, Site, Topology, TopologyEdge, TopologyNode } from '@/types/models'
import { collapseTopology } from './topology.collapse'

const auth = useAuthStore()

definePage({ meta: { layout: 'default' } })

const router = useRouter()

const sites = ref<Site[]>([])
const selectedSiteId = ref<number | null>(null)
const mode = ref<'site' | 'org'>('site')
const topo = ref<Topology | null>(null)
// Collapse dense access-switch fans into one cluster card so a big site (e.g. #893,
// 30+ switches off a core) reads as a handful of nodes, not a hairball. Default on;
// an operator can expand a single cluster (side panel) or turn collapse off entirely.
const CLUSTER_MIN = 6
const collapseDense = ref(true)
const expandedClusters = ref<Set<string>>(new Set())
const org = ref<OrgTopologySite[]>([])
const isLoading = ref(true)
const hoveredId = ref<string | null>(null)

// Hover card — follows the cursor, shows a node's health or a link's load/loss.
const hoverCard = ref<{ x: number, y: number, node?: TopologyNode, edge?: TopologyEdge } | null>(null)
function onHoverMove(ev: MouseEvent, payload: { node?: TopologyNode, edge?: TopologyEdge }) {
  hoverCard.value = { x: ev.clientX, y: ev.clientY, ...payload }
}
const hoverCardStyle = computed(() => {
  const h = hoverCard.value
  if (!h)
    return {}
  const flipX = h.x > window.innerWidth - 260
  const flipY = h.y > window.innerHeight - 210
  return {
    left: `${h.x + (flipX ? -14 : 14)}px`,
    top: `${h.y + (flipY ? -14 : 14)}px`,
    transform: `translate(${flipX ? '-100%' : '0'}, ${flipY ? '-100%' : '0'})`,
  }
})
function nodeLabelOf(id: string): string {
  return layout.value.nodes.find(n => n.id === id)?.label ?? id
}
function edgeKind(e: TopologyEdge): string {
  return e.overlay ? 'SD-WAN overlay' : e.ha ? 'HA sync' : e.stp_blocked ? `${e.label} · STP blocked` : e.label
}
// A physical link's load ≈ the busier of its two endpoints' busiest-interface util.
function edgeUtil(e: TopologyEdge): number | null {
  const vals = [e.from, e.to]
    .map(id => layout.value.nodes.find(n => n.id === id)?.util)
    .filter((v): v is number => v != null)
  return vals.length ? Math.max(...vals) : null
}
const selectedId = ref<string | null>(null)

// ---- geometry ----
const NH = 88
const GAP = 26
const TOP_PAD = 130
// Generous per-char width estimates so a node is never too narrow for its name
// (over-wide is fine; too-narrow clips). Name is 15.5px bold, sub is 12.5px mono.
const NAME_PX = 9.4
const SUB_PX = 7.8

const glyphs: Record<string, string> = {
  cloud: 'M6 15a4 4 0 0 1 .3-8A5.5 5.5 0 0 1 17 8.5a3.5 3.5 0 0 1-.5 6.9z',
  gw: 'M4 8h12M4 8l3-3M4 8l3 3M16 14H4M16 14l-3-3M16 14l-3 3',
  edge: 'M3 6h14v8H3zM6 9v2M10 9v2M14 9v2M8 3v3M12 3v3',
  switch: 'M2 7h16v6H2zM5 10h1M8 10h1M11 10h1M14 10h1',
  fw: 'M10 2 3 5v5c0 4 3 6.5 7 8 4-1.5 7-4 7-8V5z',
  device: 'M3 4h14v9H3zM7 17h6M10 13v4',
  cluster: 'M2 7h16v6H2zM5 10h1M8 10h1M11 10h1M14 10h1',
}

function statusColor(s: string): string {
  return s === 'up'
    ? 'rgb(var(--v-theme-success))'
    : s === 'warn' ? 'rgb(var(--v-theme-warning))' : 'rgb(var(--v-theme-error))'
}
function statusColorName(s: string): string {
  return s === 'up' ? 'success' : s === 'warn' ? 'warning' : 'error'
}
// Icon for the selected-node header tile, by node kind.
function nodeTypeIcon(type: string): string {
  return ({
    cloud: 'ri-cloud-line', nexthop: 'ri-router-line', gw: 'ri-router-line',
    edge: 'ri-router-line', switch: 'ri-git-merge-line', fw: 'ri-shield-check-line', device: 'ri-server-line', cluster: 'ri-stack-line',
  } as Record<string, string>)[type] ?? 'ri-server-line'
}
// Health-bar colour by utilisation: <75 healthy, 75–90 warning, ≥90 critical.
function metricColor(pct: number | null | undefined): string {
  const v = pct ?? 0
  if (v >= 90)
    return 'rgb(var(--v-theme-error))'
  if (v >= 75)
    return 'rgb(var(--v-theme-warning))'

  return 'rgb(var(--v-theme-success))'
}

// Collapse transform: fold each core's fan of leaf access switches into one cluster
// card when dense (≥ CLUSTER_MIN) and not individually expanded. Runs on the raw
// payload BEFORE the tier layout, so all the positioning/edge logic below is reused
// unchanged — it just sees fewer nodes. Logic is the pure collapseTopology() helper.
const displayTopo = computed<Topology | null>(() => {
  const t = topo.value
  if (!t)
    return t
  return collapseTopology(t, { enabled: collapseDense.value, expanded: expandedClusters.value, min: CLUSTER_MIN })
})

function expandCluster(cid: string, memberId?: string) {
  const next = new Set(expandedClusters.value)
  next.add(cid)
  expandedClusters.value = next
  if (memberId)
    selectedId.value = memberId
}
// Reset per-cluster expansions whenever the site or collapse toggle changes.
watch([selectedSiteId, collapseDense], () => { expandedClusters.value = new Set() })

const layout = computed(() => {
  const t = displayTopo.value
  if (!t)
    return { nodes: [], edges: [], w: 1180, h: 380 }

  const byCol: Record<number, TopologyNode[]> = {}
  t.nodes.forEach(n => (byCol[n.col] ??= []).push(n))
  const cols = Object.keys(byCol).map(Number).sort((a, b) => a - b)
  const maxCol = Math.max(...cols)

  // Each column is exactly as wide as its longest hostname/sub needs — so the
  // full name always shows, never truncated or clipped. 46px left (icon+pad),
  // text, then 28px right (status dot).
  const colWidth: Record<number, number> = {}
  for (const c of cols) {
    let w = 148
    for (const n of byCol[c]) {
      const serialLen = n.serial ? (`S/N ${n.serial}`).length : 0
      const epLen = Math.max(endpointSummary(n).length, tunnelDownSummary(n).length + 3)
      w = Math.max(w, 46 + Math.max((n.label?.length ?? 0) * NAME_PX, (n.sub?.length ?? 0) * SUB_PX, serialLen * SUB_PX, epLen * SUB_PX) + 28)
    }
    colWidth[c] = Math.round(w)
  }

  // Cumulative x with a gap between columns — wide enough that the edge label and
  // any ROOT CAUSE / STP BLK badge sit readably in the space between two nodes.
  const GAP_X = 150
  const colX: Record<number, number> = {}
  let x = 20
  for (let c = 0; c <= maxCol; c++) {
    colX[c] = x
    x += (colWidth[c] ?? 148) + GAP_X
  }
  // A same-column peer mesh routes its link + STP/label out to the RIGHT of the
  // rightmost column (so it never overlaps the green next-hop lines entering on
  // the left). Reserve right margin for that bus + its label when one exists.
  const colOf: Record<string, number> = Object.fromEntries(t.nodes.map(n => [n.id, n.col]))
  const hasSameColMesh = t.edges.some(e => !e.overlay && !e.ha && colOf[e.from] !== undefined && colOf[e.from] === colOf[e.to])
  const totalW = x - GAP_X + 20 + (hasSameColMesh ? 150 : 0)

  const maxCount = Math.max(1, ...cols.map(c => byCol[c].length))
  const rowH = maxCount * NH + (maxCount - 1) * GAP
  const cy = TOP_PAD + rowH / 2
  const h = TOP_PAD + rowH + 60

  const pos: Record<string, { x: number, y: number, w: number }> = {}
  const nodes = t.nodes.map((n) => {
    const col = byCol[n.col]
    const i = col.indexOf(n)
    const k = col.length
    const start = cy - (k * NH + (k - 1) * GAP) / 2
    const nw = colWidth[n.col]
    // An operator-saved position overrides the auto-computed tier layout.
    const saved = savedPositions.value[n.id]
    const nx = saved ? saved.x : colX[n.col]
    const ny = saved ? saved.y : start + i * (NH + GAP)
    pos[n.id] = { x: nx, y: ny, w: nw }

    // A passive HA standby is idle, not "up" — render it in a muted colour so it
    // reads distinctly from the live active appliance.
    const color = n.ha_role === 'standby' ? 'rgb(var(--v-theme-secondary))' : statusColor(n.status)

    return { ...n, x: nx, y: ny, nw, color }
  })

  const center = (id: string) => ({ x: pos[id].x + pos[id].w / 2, y: pos[id].y + NH / 2 })

  const edges = t.edges.filter(e => pos[e.from] && pos[e.to]).map((e, i) => {
    const a = pos[e.from]; const b = pos[e.to]
    let d: string; let lx: number; let ly: number
    if (e.overlay) {
      const ax = a.x + a.w / 2; const ay = a.y
      const bx = b.x + b.w / 2; const by = b.y
      const peak = Math.min(ay, by) - 92
      d = `M ${ax} ${ay} C ${ax} ${peak}, ${bx} ${peak}, ${bx} ${by}`
      lx = (ax + bx) / 2; ly = peak + 14
    }
    else if (e.ha) {
      // HA sync link: the two appliances are stacked in the same column — connect
      // them with a short vertical line between their facing edges.
      const cx = a.x + a.w / 2
      const upper = a.y < b.y ? a : b
      const lower = a.y < b.y ? b : a
      const y1 = upper.y + NH
      const y2 = lower.y
      d = `M ${cx} ${y1} L ${cx} ${y2}`
      lx = cx; ly = (y1 + y2) / 2
    }
    else if (Math.round(a.x) === Math.round(b.x)) {
      // Two peer switches STACKED in the same column (an access-fabric mesh). A
      // straight A→B link would route its midpoint through the middle of the
      // column — right under the cards. Elbow the link out into the open space
      // on the RIGHT of the column and park the label + STP badge there, clear of
      // the green next-hop lines that enter every card on the LEFT.
      const y1 = center(e.from).y; const y2 = center(e.to).y
      const rightX = a.x + a.w
      const busX = rightX + 22
      d = `M ${rightX} ${y1} H ${busX} V ${y2} H ${rightX}`
      const tw = e.label.length * 6.4 + 12
      lx = busX + tw / 2 + 4; ly = (y1 + y2) / 2
      return { ...e, d, lx, ly, tw, wid: `wp${i}` }
    }
    else {
      const x1 = a.x + a.w; const y1 = center(e.from).y; const x2 = b.x; const y2 = center(e.to).y
      const mx = (x1 + x2) / 2
      d = `M ${x1} ${y1} C ${mx} ${y1}, ${mx} ${y2}, ${x2} ${y2}`
      lx = (x1 + x2) / 2; ly = (y1 + y2) / 2 - 8
    }

    return { ...e, d, lx, ly, tw: e.label.length * 6.4 + 12, wid: `wp${i}` }
  })

  // Grow the viewBox to include any operator-dragged nodes so nothing clips.
  let w = Math.max(totalW, 900)
  let hh = h
  for (const n of nodes) {
    w = Math.max(w, n.x + n.nw + 40)
    hh = Math.max(hh, n.y + NH + 40)
  }
  return { nodes, edges, w, h: hh }
})

// Healthy links get a "packet" that flows along the path (upstream→downstream),
// so a good link reads as alive — the green counterpart to the red down-link dash.
const reducedMotion = ref(false)

function neighborsOf(id: string): Set<string> {
  const set = new Set<string>([id])
  topo.value?.edges.forEach((e) => {
    if (e.from === id) set.add(e.to)
    if (e.to === id) set.add(e.from)
  })
  return set
}
// Click-to-trace: the uplink path from the SELECTED node to the ISP (col 0). Walking
// toward lower columns lights the whole path to the internet and dims everything else,
// so a dense site (e.g. #893, 30+ devices) stays legible — one path at a time.
const tracedPath = computed(() => {
  const sel = selectedId.value
  const order: string[] = []
  const nodeSet = new Set<string>()
  const edgeSet = new Set<string>()
  if (sel) {
    const colOf: Record<string, number> = Object.fromEntries(layout.value.nodes.map(n => [n.id, n.col]))
    if (colOf[sel] !== undefined) {
      // A hop is worth taking if it IS the ISP cloud (col 0) or can itself keep
      // climbing toward it — this steers the walk down the circuit that actually
      // reaches the internet and skips a dead-end next-hop gw (col 1 leaf).
      const climbs = (id: string, oc: number) => oc === 0 || layout.value.edges.some((e) => {
        const o = e.from === id ? e.to : (e.to === id ? e.from : null)
        return o != null && (colOf[o] ?? 99) < oc
      })
      let cur = sel
      order.push(cur); nodeSet.add(cur)
      for (let guard = 0; (colOf[cur] ?? 0) > 0 && guard < 20; guard++) {
        const cands = layout.value.edges
          .map(e => ({ e, other: e.from === cur ? e.to : (e.to === cur ? e.from : null) }))
          .filter((c): c is { e: typeof c.e, other: string } =>
            c.other != null && (colOf[c.other] ?? 99) < (colOf[cur] ?? 0))
          .sort((a, b) => {
            const ca = climbs(a.other, colOf[a.other] ?? 99) ? 1 : 0
            const cb = climbs(b.other, colOf[b.other] ?? 99) ? 1 : 0
            if (ca !== cb) return cb - ca // a hop that reaches the internet wins
            return (colOf[b.other] ?? 0) - (colOf[a.other] ?? 0) // else nearest tier
          })
        const up = cands[0]
        if (!up) break
        order.push(up.other); nodeSet.add(up.other); edgeSet.add(`${up.e.from}-${up.e.to}`)
        cur = up.other
      }
    }
  }
  return { nodes: nodeSet, edges: edgeSet, order }
})

// The traced path as node objects, ISP-first (reads top→down like a route sheet).
const tracePathNodes = computed(() =>
  tracedPath.value.order.map(id => layout.value.nodes.find(n => n.id === id)).filter(Boolean).reverse())

function hopRole(n: TopologyNode): string {
  return ({ cloud: 'Internet', gw: 'Gateway', nexthop: 'Gateway', edge: 'SD-WAN', fw: 'Firewall', cluster: 'Access', switch: 'Switch', device: 'Device' } as Record<string, string>)[n.type] ?? 'Node'
}
// Busiest hop on the path (the bottleneck) — drives the summary + the amber flag.
const routeBottleneck = computed(() => {
  const uts = tracePathNodes.value.map(n => n.util).filter((v): v is number => v != null)
  return uts.length ? Math.max(...uts) : null
})
const routeSummary = computed(() => {
  const hops = tracePathNodes.value
  const down = hops.find(h => h.status === 'down')
  if (down)
    return { text: `broken at ${down.label}`, tone: 'down' as const }
  const b = routeBottleneck.value
  const busiest = b != null ? hops.find(h => h.util === b) : null
  if (b != null && busiest)
    return { text: `${hops.length} hops · busiest ${b}% @ ${busiest.label}`, tone: b >= 90 ? 'down' as const : b >= 75 ? 'warn' as const : 'ok' as const }
  return { text: `${hops.length} hops to the internet`, tone: 'ok' as const }
})

function nodeDim(id: string): boolean {
  if (selectedId.value)
    return !tracedPath.value.nodes.has(id)
  return hoveredId.value !== null && !neighborsOf(hoveredId.value).has(id)
}

// Vertically-centred text stack inside a device rectangle: name, model, serial
// (when known), and the tunnels badge — laid out around the box mid-line so the
// content sits centred no matter how many rows a device has.
const ROW_GAP = 16
// Short "what's plugged in" summary for a switch, shown right on the node so the
// APs / phones / cameras read at a glance in the graph — not just the side panel.
const EP_SHORT: Record<string, string> = { ap: 'AP', phone: 'phone', camera: 'cam', switch: 'sw', router: 'rtr', other: 'other' }
function endpointSummary(n: any): string {
  const eps = n.lldp_endpoints as { type: string }[] | undefined
  if (!eps || !eps.length)
    return ''
  const by: Record<string, number> = {}
  for (const e of eps) by[e.type] = (by[e.type] ?? 0) + 1

  return ['ap', 'phone', 'camera', 'switch', 'router', 'other']
    .filter(t => by[t]).map(t => `${by[t]} ${EP_SHORT[t]}${by[t] > 1 ? 's' : ''}`).join(' · ')
}
// The hub peers with a tunnel down — shown on the SD-WAN node so the operator can
// see WHICH peer is the problem right in the graph (and search for it), not just
// in the side panel.
function tunnelDownSummary(n: any): string {
  const hubs = (n.tunnel_hubs as { hub: string, down: number }[] | undefined)?.filter(h => h.down > 0) ?? []
  if (!hubs.length)
    return ''
  const names = hubs.slice(0, 2).map(h => h.hub).join(', ')

  return hubs.length > 2 ? `${names} +${hubs.length - 2}` : names
}
function nodeRows(n: any): string[] {
  const rows = ['name', 'sub']
  if (n.serial)
    rows.push('serial')
  if (endpointSummary(n))
    rows.push('endpoints')
  if (tunnelDownSummary(n))
    rows.push('tunneldown')
  if (n.tunnels)
    rows.push('tunnels')
  return rows
}
function rowY(n: any, key: string): number {
  const rows = nodeRows(n)
  const i = rows.indexOf(key)
  const first = n.y + NH / 2 - ((rows.length - 1) * ROW_GAP) / 2 + 5
  return first + i * ROW_GAP
}
function edgeState(e: TopologyEdge): 'hl' | 'dim' | '' {
  if (selectedId.value)
    return tracedPath.value.edges.has(`${e.from}-${e.to}`) ? 'hl' : 'dim'
  if (hoveredId.value === null)
    return ''
  return (e.from === hoveredId.value || e.to === hoveredId.value) ? 'hl' : 'dim'
}

const selectedNode = computed(() => layout.value.nodes.find(n => n.id === selectedId.value) ?? null)
const statusLabel: Record<string, string> = { up: 'Reachable', warn: 'Degraded', down: 'Down' }

// LLDP endpoints grouped by kind (APs, phones, cameras, …). Groups are EXPANDED
// by default so a selected switch immediately shows what's plugged into it; the
// operator can collapse a kind to tidy up. Grouping + a scroll cap keep it
// readable even at 40+ neighbours.
const EP_ORDER = ['ap', 'phone', 'camera', 'switch', 'router', 'other']
const EP_LABEL: Record<string, string> = { ap: 'Access points', phone: 'Phones', camera: 'Cameras', switch: 'Switches', router: 'Routers', other: 'Other' }
const lldpDialog = ref(false)
watch(selectedId, () => { lldpDialog.value = false })

// A branch homes to ~2 hubs (list them); a HUB appliance has a tunnel to every
// one of the ~130 branches, so we roll those up to a count + only surface the
// ones that are down.
const tunnelHubView = computed(() => {
  const hubs = selectedNode.value?.tunnel_hubs ?? []
  const down = hubs.filter(h => h.down > 0)
  return { hubs, down, total: hubs.length, downCount: down.length, aggregate: hubs.length > 6 }
})
// Mitel MiNet phones advertise "regDN 500206,MINET_6920" — regDN is the extension
// assigned to the phone and MINET_6920 is the Mitel 6920 model. Split it into a
// readable extension + model; other kinds keep their name.
function parseEndpoint(e: { name: string }): { title: string, sub: string } {
  const m = (e.name || '').match(/regDN\s*(\d+)\s*,\s*MI?NET[_-]?(\w+)/i)
  if (m)
    return { title: `Ext ${m[1]}`, sub: `Mitel ${m[2]}` }

  return { title: e.name || '—', sub: '' }
}
const endpointGroups = computed(() => {
  const eps = selectedNode.value?.lldp_endpoints ?? []
  const by: Record<string, any[]> = {}
  for (const e of eps) (by[e.type] ??= []).push(e)
  return EP_ORDER.filter(t => by[t]?.length)
    .map(t => ({ type: t, label: EP_LABEL[t] ?? t, items: by[t].slice().sort((a, b) => String(a.port).localeCompare(String(b.port), undefined, { numeric: true })) }))
})

// ---- zoom + pan (ctrl+scroll to zoom, drag to pan) ----
const svgRef = ref<SVGSVGElement | null>(null)
const zoom = ref(1)
const pan = ref({ x: 0, y: 0 })
const panning = ref(false)
let panStart = { x: 0, y: 0, px: 0, py: 0 }
const viewTransform = computed(() => `translate(${pan.value.x} ${pan.value.y}) scale(${zoom.value})`)

// ---- arrange mode: drag nodes to a hand-corrected layout, saved globally ----
// Operator-saved positions (viewBox/world coords, keyed by node id). When present they
// override the auto-computed tier layout — LLDP can't always resolve the real uplink,
// so an operator drags the map into shape once and everyone sees it.
const savedPositions = ref<Record<string, { x: number, y: number }>>({})
const editLayout = ref(false)
const savingLayout = ref(false)
let dragNode: { id: string, ox: number, oy: number } | null = null

/** Pointer position in world (pre-transform) coords. */
function svgWorld(e: PointerEvent) {
  const svg = svgRef.value!
  const rect = svg.getBoundingClientRect()
  const sx = (e.clientX - rect.left) / rect.width * layout.value.w
  const sy = (e.clientY - rect.top) / rect.height * layout.value.h
  return { x: (sx - pan.value.x) / zoom.value, y: (sy - pan.value.y) / zoom.value }
}

function clampZoom(z: number) { return Math.min(3, Math.max(0.4, z)) }

function onWheel(e: WheelEvent) {
  if (!e.ctrlKey && !e.metaKey)
    return // plain scroll = page scroll; only ctrl/⌘+scroll zooms
  e.preventDefault()
  const svg = svgRef.value
  if (!svg)
    return
  const rect = svg.getBoundingClientRect()
  const sx = (e.clientX - rect.left) / rect.width * layout.value.w
  const sy = (e.clientY - rect.top) / rect.height * layout.value.h
  const wx = (sx - pan.value.x) / zoom.value
  const wy = (sy - pan.value.y) / zoom.value
  const z2 = clampZoom(zoom.value * (e.deltaY < 0 ? 1.12 : 0.89))
  pan.value = { x: sx - wx * z2, y: sy - wy * z2 }
  zoom.value = z2
}
// `moved` distinguishes a pan-drag from a click. We deliberately do NOT capture
// the pointer on the SVG — capturing steals the click from the node, which is why
// the Selected Node panel never updated.
let moved = false
function onPointerDown(e: PointerEvent) {
  // In arrange mode, a press on a node card starts dragging THAT node, not a pan.
  const nodeEl = (e.target as Element)?.closest?.('[data-node-id]') as SVGElement | null
  if (editLayout.value && nodeEl) {
    const id = nodeEl.getAttribute('data-node-id')!
    const node = layout.value.nodes.find(n => n.id === id)
    if (node) {
      const w = svgWorld(e)
      dragNode = { id, ox: w.x - node.x, oy: w.y - node.y }
      moved = false
      nodeEl.setPointerCapture?.(e.pointerId)
      return
    }
  }
  panning.value = true
  moved = false
  panStart = { x: e.clientX, y: e.clientY, px: pan.value.x, py: pan.value.y }
}
function onPointerMove(e: PointerEvent) {
  if (dragNode) {
    const w = svgWorld(e)
    savedPositions.value = { ...savedPositions.value, [dragNode.id]: { x: w.x - dragNode.ox, y: w.y - dragNode.oy } }
    moved = true
    return
  }
  if (!panning.value)
    return
  if (Math.abs(e.clientX - panStart.x) + Math.abs(e.clientY - panStart.y) > 3)
    moved = true
  const svg = svgRef.value!
  const rect = svg.getBoundingClientRect()
  const kx = layout.value.w / rect.width
  const ky = layout.value.h / rect.height
  pan.value = { x: panStart.px + (e.clientX - panStart.x) * kx, y: panStart.py + (e.clientY - panStart.y) * ky }
}
function onPointerUp() {
  if (dragNode) {
    dragNode = null
    void persistLayout()
    return
  }
  panning.value = false
}
function selectNode(id: string) {
  if (moved || editLayout.value)
    return // was a pan/drag, not a click
  selectedId.value = id
}

/** Persist the whole arrangement (global, all users). */
async function persistLayout() {
  if (!selectedSiteId.value || Object.keys(savedPositions.value).length === 0)
    return
  savingLayout.value = true
  try {
    await api(`/api/sites/${selectedSiteId.value}/topology/positions`, { method: 'POST', body: { positions: savedPositions.value } })
  }
  catch { /* transient; next drop retries */ }
  finally { savingLayout.value = false }
}

/** Clear the saved arrangement and fall back to the auto layout. */
async function resetLayout() {
  if (!selectedSiteId.value)
    return
  await api(`/api/sites/${selectedSiteId.value}/topology/positions`, { method: 'DELETE' }).catch(() => {})
  savedPositions.value = {}
  await loadTopology(true)
}

// ---- interface stats (per-device, shown in the selected-node panel) ----
// Driven off selectedId so switching sites (which re-selects a node) always
// refreshes — the previous device's stats never linger.
const ifStats = ref<DeviceInterface[]>([])
const ifLoading = ref(false)
async function loadInterfaceStats(nodeId: string | null) {
  ifStats.value = []
  const node = nodeId ? layout.value.nodes.find(n => n.id === nodeId) : null
  if (!node?.device_id)
    return
  ifLoading.value = true
  try {
    const rows = await api<DeviceInterface[]>(`/api/interfaces?device_id=${node.device_id}`)
    // Lead with anything unhealthy — errors, drops, or down — then by index.
    ifStats.value = rows.sort((a, b) => ifPriority(b) - ifPriority(a) || a.if_index - b.if_index)
  }
  finally {
    ifLoading.value = false
  }
}
watch(selectedId, id => loadInterfaceStats(id))

// Most recent poll across the shown interfaces — when the deltas were pulled.
const ifPolledAt = computed(() => {
  const stamps = ifStats.value.map(i => i.last_polled_at).filter(Boolean) as string[]
  return stamps.length ? stamps.sort().at(-1)! : null
})
function ifPriority(i: DeviceInterface): number {
  return (i.in_errors_delta + i.out_errors_delta) * 100
    + (i.in_discards_delta + i.out_discards_delta) * 10
    + (i.status === 'down' && i.admin_status === 'up' ? 1 : 0)
}
function ifUtil(i: DeviceInterface): number {
  return Math.max(i.in_util_pct, i.out_util_pct)
}
function lldpIcon(type: string): string {
  return ({ ap: 'ri-wifi-line', phone: 'ri-phone-line', camera: 'ri-camera-line', switch: 'ri-git-merge-line', router: 'ri-router-line' } as Record<string, string>)[type] ?? 'ri-plug-line'
}
function formatUptime(seconds: number): string {
  const d = Math.floor(seconds / 86400)
  const h = Math.floor((seconds % 86400) / 3600)
  const m = Math.floor((seconds % 3600) / 60)
  if (d > 0)
    return `${d}d ${h}h`
  if (h > 0)
    return `${h}h ${m}m`
  return `${m}m`
}
function zoomBy(f: number) {
  const cx = layout.value.w / 2; const cy = layout.value.h / 2
  const wx = (cx - pan.value.x) / zoom.value; const wy = (cy - pan.value.y) / zoom.value
  const z2 = clampZoom(zoom.value * f)
  pan.value = { x: cx - wx * z2, y: cy - wy * z2 }
  zoom.value = z2
}
function resetView() { zoom.value = 1; pan.value = { x: 0, y: 0 } }

function goToNode(n: TopologyNode | null) {
  if (!n)
    return
  if (n.device_id)
    router.push(`/devices/${n.device_id}`)
  else if (n.circuit_id)
    router.push(`/circuits?q=${encodeURIComponent(n.sub)}`)
}

// ---- data ----
const route = useRoute()
// Clicking the location search selects the current text so the operator can just
// start typing the next site — no manual clearing.
function selectSearchText(e: FocusEvent) {
  const el = e.target as HTMLInputElement | null
  requestAnimationFrame(() => el?.select?.())
}
async function loadSites() {
  sites.value = await api<Site[]>('/api/sites')
  // Deep-link: /topology?site=<id> (e.g. from an alarm popup) pre-selects it.
  const wanted = Number(route.query.site)
  if (wanted && sites.value.some(s => s.id === wanted))
    selectedSiteId.value = wanted
  else if (!selectedSiteId.value && sites.value.length)
    selectedSiteId.value = sites.value[0].id
}
async function loadTopology(preserveSelection = false) {
  if (!selectedSiteId.value)
    return
  if (!preserveSelection)
    isLoading.value = true
  try {
    topo.value = await api<Topology>(`/api/sites/${selectedSiteId.value}/topology`)
    const t = topo.value
    // Operator-saved arrangement (global). Don't clobber an in-progress drag.
    if (!dragNode)
      savedPositions.value = (t as { positions?: Record<string, { x: number, y: number }> }).positions ?? {}
    // On a live refresh, keep the operator's selected node (and its stats) if it
    // still exists; only auto-pick a default on first load / site switch, or if
    // the selection vanished. This is what stops the 30s refresh from wiping the
    // interface stats the operator is reading.
    const stillThere = selectedId.value && t.nodes.some(n => n.id === selectedId.value)
    if (!preserveSelection || !stillThere) {
      selectedId.value = t.nodes.find(n => n.status === 'down')?.id
        ?? t.nodes.find(n => n.type === 'edge')?.id
        ?? t.nodes[0]?.id ?? null
    }
  }
  finally {
    isLoading.value = false
  }
}
async function loadOrg() {
  const res = await api<{ sites: OrgTopologySite[] }>('/api/topology')
  org.value = res.sites
}

// --- interface-alarm NOC actions (from the switch panel) ---
const alertBusy = ref<number | null>(null)
const clearDialog = ref<{ open: boolean, alertId: number | null, note: string }>({ open: false, alertId: null, note: '' })

async function ackInterfaceAlert(alertId: number) {
  alertBusy.value = alertId
  try {
    await api(`/api/interface-alerts/${alertId}/acknowledge`, { method: 'POST' })
    await loadTopology(true)
  }
  finally { alertBusy.value = null }
}
function openClear(alertId: number) {
  clearDialog.value = { open: true, alertId, note: '' }
}
async function submitClear() {
  const id = clearDialog.value.alertId
  if (!id)
    return
  alertBusy.value = id
  try {
    await api(`/api/interface-alerts/${id}/clear`, { method: 'POST', body: { note: clearDialog.value.note || null } })
    clearDialog.value.open = false
    await loadTopology(true)
  }
  finally { alertBusy.value = null }
}
async function muteInterface(interfaceId: number) {
  alertBusy.value = interfaceId
  try {
    await api(`/api/interfaces/${interfaceId}/suppress`, { method: 'POST' })
    await loadTopology(true)
  }
  finally { alertBusy.value = null }
}

function openSite(id: number) {
  selectedSiteId.value = id
  mode.value = 'site'
}

watch(selectedSiteId, () => { if (mode.value === 'site') { resetView(); loadTopology() } })
watch(mode, (m) => { if (m === 'org') loadOrg(); else loadTopology() })

// ---- organization view at scale (130+ sites): triage by exception ----
const orgSearch = ref('')
const orgFilter = ref<'impacted' | 'all' | 'healthy'>('impacted')
const orgStats = computed(() => {
  const impacted = org.value.filter(s => s.state !== 'up').length
  return { total: org.value.length, impacted, healthy: org.value.length - impacted }
})
// Default to showing everything when nothing is broken, else lead with exceptions.
watch(org, (list) => {
  orgFilter.value = list.some(s => s.state !== 'up') ? 'impacted' : 'all'
}, { once: true })
const orgFiltered = computed(() => {
  const q = orgSearch.value.trim().toLowerCase()
  return org.value.filter((s) => {
    const byState = orgFilter.value === 'all'
      || (orgFilter.value === 'impacted' && s.state !== 'up')
      || (orgFilter.value === 'healthy' && s.state === 'up')
    const byText = !q || s.name.toLowerCase().includes(q) || (s.address ?? '').toLowerCase().includes(q)
    return byState && byText
  })
})

// Hub-and-spoke grouping: branches nested under the hub they home to, so 130+
// sites read as a few hub sections instead of a flat wall.
const hasHubs = computed(() => org.value.some(s => s.site_type === 'hub'))
const collapsedHubs = ref<Set<number>>(new Set())
function toggleHub(id: number) {
  const s = new Set(collapsedHubs.value)
  s.has(id) ? s.delete(id) : s.add(id)
  collapsedHubs.value = s
}
const orgGroups = computed(() => {
  const shown = new Set(orgFiltered.value.map(s => s.id))
  const hubs = org.value.filter(s => s.site_type === 'hub')
  const hubIds = new Set(hubs.map(h => h.id))

  // A branch can home to MULTIPLE hubs, so it appears under each of its hubs.
  const hubsOf = (s: OrgTopologySite) => {
    const ids = s.hub_site_ids && s.hub_site_ids.length ? s.hub_site_ids : (s.hub_site_id ? [s.hub_site_id] : [])
    return ids
  }

  const groups = hubs.map((hub) => {
    const branches = org.value.filter(s => s.site_type !== 'hub' && hubsOf(s).includes(hub.id))
    return {
      hub,
      branches: branches.filter(b => shown.has(b.id)),
      total: branches.length,
      impacted: [hub, ...branches].filter(m => m.state !== 'up').length,
      hubShown: shown.has(hub.id),
    }
  }).filter(g => g.hubShown || g.branches.length > 0)

  const unassigned = org.value.filter(s => s.site_type !== 'hub' && ! hubsOf(s).some(id => hubIds.has(id)))
  return { groups, unassigned: unassigned.filter(u => shown.has(u.id)), unassignedTotal: unassigned.length }
})

let timer: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  reducedMotion.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  await loadSites()

  const deepLinked = !!(Number(route.query.site) && sites.value.some(s => s.id === Number(route.query.site)))
  if (deepLinked) {
    // A direct link to a site (e.g. from an alarm popup) wins — open that map.
    await loadTopology()
  }
  else {
    // Default landing: Organization → Impacted Sites. If nothing is impacted,
    // drop into the HQ location's map instead of a wall of green.
    mode.value = 'org'
    await loadOrg()
    if (orgStats.value.impacted === 0) {
      const hq = sites.value.find(s => s.site_type === 'hub')
        ?? sites.value.find(s => /\bhq\b/i.test(s.name))
        ?? sites.value[0]
      if (hq) {
        selectedSiteId.value = hq.id
        mode.value = 'site'
        await loadTopology()
      }
    }
  }

  // Keep the topology live (node statuses / incidents update), but PRESERVE the
  // operator's selected node + its stats across refreshes — see loadTopology.
  timer = setInterval(() => { mode.value === 'org' ? loadOrg() : loadTopology(true) }, 30000)
})
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>

<template>
  <div>
    <div class="mb-2">
      <h1 class="text-h5 font-weight-medium mb-0">
        Network Topology
      </h1>
      <div class="text-body-2 text-medium-emphasis">
        Logical view · click a device to trace its path to the internet · live, updates every 30s
      </div>
    </div>
    <!-- Controls on their own row so nothing gets squeezed / overlaps -->
    <div class="topo-controls mb-4">
      <div class="topo-modetoggle">
        <button
          type="button"
          class="seg-btn"
          :class="{ active: mode === 'site' }"
          @click="mode = 'site'"
        >
          Location
        </button>
        <button
          type="button"
          class="seg-btn"
          :class="{ active: mode === 'org' }"
          @click="mode = 'org'"
        >
          Organization
        </button>
      </div>
      <VAutocomplete
        v-if="mode === 'site'"
        v-model="selectedSiteId"
        :items="sites.map(s => ({ title: s.name, value: s.id }))"
        density="compact"
        hide-details
        variant="outlined"
        auto-select-first
        prepend-inner-icon="ri-search-line"
        placeholder="Search a location…"
        class="topo-siteselect"
        @focus="selectSearchText"
      />
    </div>

    <!-- Root-cause banner -->
    <VAlert
      v-if="mode === 'site' && topo?.incident.active"
      type="error"
      variant="tonal"
      class="mb-4"
      border="start"
    >
      <div class="d-flex align-center justify-space-between flex-wrap ga-3">
        <div>
          <div class="font-weight-medium">
            {{ topo.incident.summary }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            Root cause: <strong>{{ topo.incident.root_label }}</strong>
            <span v-if="topo.incident.symptoms.length">· symptoms: {{ topo.incident.symptoms.join(' · ') }}</span>
          </div>
          <!-- Per-layer readout: is it the circuit, the next-hop, or the tunnels? -->
          <div v-if="topo.incident.layers" class="topo-layers mt-2">
            <span class="topo-layer" :class="`ls-${topo.incident.layers.circuit.state}`">
              <VIcon :icon="topo.incident.layers.circuit.state === 'up' ? 'ri-checkbox-circle-fill' : 'ri-close-circle-fill'" size="13" />
              Circuit — {{ topo.incident.layers.circuit.label }}
            </span>
            <span class="topo-layer" :class="`ls-${topo.incident.layers.next_hop.state}`">
              <VIcon :icon="topo.incident.layers.next_hop.state === 'up' ? 'ri-checkbox-circle-fill' : 'ri-close-circle-fill'" size="13" />
              Next-hop — {{ topo.incident.layers.next_hop.label }}
            </span>
            <span class="topo-layer" :class="`ls-${topo.incident.layers.tunnels.state}`">
              <VIcon :icon="topo.incident.layers.tunnels.state === 'up' ? 'ri-checkbox-circle-fill' : (topo.incident.layers.tunnels.state === 'degraded' ? 'ri-error-warning-fill' : 'ri-close-circle-fill')" size="13" />
              Tunnels — {{ topo.incident.layers.tunnels.label }}
            </span>
            <span class="topo-layer" :class="topo.incident.layers.passing_traffic ? 'ls-up' : 'ls-down'">
              <VIcon :icon="topo.incident.layers.passing_traffic ? 'ri-checkbox-circle-fill' : 'ri-close-circle-fill'" size="13" />
              {{ topo.incident.layers.passing_traffic ? 'Still passing traffic' : 'Not passing traffic' }}
            </span>
          </div>
          <div
            v-if="topo.incident.action"
            class="text-body-2 mt-1 d-flex align-center ga-1"
          >
            <VIcon
              icon="ri-tools-line"
              size="15"
            />
            <span>{{ topo.incident.action }}</span>
          </div>
        </div>
        <div
          v-if="topo.incident.support_phone"
          class="call-isp d-flex align-center ga-2"
        >
          <VIcon
            icon="ri-phone-line"
            size="24"
          />
          <span>
            <span class="call-isp-label">Call ISP</span>
            <a
              :href="`tel:${topo.incident.support_phone}`"
              class="call-isp-num"
            >{{ topo.incident.support_phone }}</a>
          </span>
        </div>
      </div>
    </VAlert>

    <!-- SITE topology canvas -->
    <VCard
      v-if="mode === 'site'"
      class="topo-canvas"
    >
      <div class="canvas-holder">
        <div class="zoom-ctl">
          <VBtn
            icon
            size="x-small"
            variant="tonal"
            @click="zoomBy(1.2)"
          >
            <VIcon icon="ri-add-line" />
          </VBtn>
          <VBtn
            icon
            size="x-small"
            variant="tonal"
            @click="zoomBy(0.83)"
          >
            <VIcon icon="ri-subtract-line" />
          </VBtn>
          <VBtn
            icon
            size="x-small"
            variant="tonal"
            @click="resetView"
          >
            <VIcon icon="ri-focus-3-line" />
          </VBtn>
          <VBtn
            icon
            size="x-small"
            :variant="collapseDense ? 'flat' : 'tonal'"
            :color="collapseDense ? 'primary' : undefined"
            :title="collapseDense ? 'Dense access-switch fans collapsed — click to expand all' : 'Collapse dense access-switch fans'"
            @click="collapseDense = !collapseDense"
          >
            <VIcon :icon="collapseDense ? 'ri-node-tree' : 'ri-stack-line'" />
          </VBtn>
          <VDivider class="my-1" />
          <VBtn
            v-if="auth.canAct"
            icon
            size="x-small"
            :variant="editLayout ? 'flat' : 'tonal'"
            :color="editLayout ? 'primary' : undefined"
            :title="editLayout ? 'Done arranging' : 'Arrange nodes (drag to reposition, saved for everyone)'"
            @click="editLayout = !editLayout"
          >
            <VIcon :icon="editLayout ? 'ri-check-line' : 'ri-drag-move-2-line'" />
          </VBtn>
          <VBtn
            v-if="editLayout"
            icon
            size="x-small"
            variant="tonal"
            color="error"
            :loading="savingLayout"
            title="Reset to auto layout"
            @click="resetLayout"
          >
            <VIcon icon="ri-restart-line" />
          </VBtn>
        </div>
        <div
          v-if="editLayout"
          class="arrange-hint"
        >
          Arrange mode — drag nodes into place. Saved for all users on drop.
        </div>
        <svg
          ref="svgRef"
          class="topo-svg"
          :class="{ grabbing: panning }"
          :viewBox="`0 0 ${layout.w} ${layout.h}`"
          role="img"
          aria-label="Network topology"
          @wheel="onWheel"
          @pointerdown="onPointerDown"
          @pointermove="onPointerMove"
          @pointerup="onPointerUp"
          @pointerleave="onPointerUp"
        >
          <defs>
            <!-- Marker glyphs (Remix icons) so down/warning badges are crisp vectors, not font/emoji glyphs. Paths carry no fill so the marker class drives colour. -->
            <symbol id="topo-x" viewBox="0 0 24 24"><path d="m12 10.587l4.95-4.95l1.414 1.414l-4.95 4.95l4.95 4.95l-1.415 1.414l-4.95-4.95l-4.949 4.95l-1.414-1.415l4.95-4.95l-4.95-4.95L7.05 5.638z" /></symbol>
            <symbol id="topo-warn" viewBox="0 0 24 24"><path d="m12.866 3l9.526 16.5a1 1 0 0 1-.866 1.5H2.474a1 1 0 0 1-.866-1.5L11.134 3a1 1 0 0 1 1.732 0m-8.66 16h15.588L12 5.5zM11 16h2v2h-2zm0-7h2v5h-2z" /></symbol>
          </defs>
          <g :transform="viewTransform">
            <!-- edges -->
            <g
              v-for="e in layout.edges"
              :key="`${e.from}-${e.to}`"
            class="edge"
            :class="[e.status, { root: e.root, overlay: e.overlay, ha: e.ha, 'stp-blocked': e.stp_blocked, hl: edgeState(e) === 'hl', 'is-dim': edgeState(e) === 'dim' }]"
          >
            <path
              :id="e.wid"
              class="wire"
              :d="e.d"
            />
            <path
              class="wire-hit"
              :d="e.d"
              @mouseenter="onHoverMove($event, { edge: e })"
              @mousemove="onHoverMove($event, { edge: e })"
              @mouseleave="hoverCard = null"
            />
            <circle
              v-if="e.status === 'up' && !e.ha && !e.stp_blocked && !reducedMotion && edgeState(e) !== 'dim'"
              class="flow-dot"
              r="2.6"
            >
              <animateMotion
                :dur="e.overlay ? '2.8s' : '2.1s'"
                repeatCount="indefinite"
                rotate="auto"
              >
                <mpath :href="`#${e.wid}`" />
              </animateMotion>
            </circle>
            <rect
              class="elabel-bg"
              :x="e.lx - e.tw / 2"
              :y="e.ly - 11"
              :width="e.tw"
              height="16"
              rx="5"
            />
            <text
              class="elabel"
              :x="e.lx"
              :y="e.ly + 1"
              text-anchor="middle"
            >{{ e.label }}</text>
            <g v-if="e.root">
              <rect
                class="rootbadge-bg"
                :x="e.lx - 39"
                :y="e.ly + 9"
                width="78"
                height="15"
                rx="4"
              />
              <text
                class="rootbadge-tx"
                :x="e.lx"
                :y="e.ly + 20"
                text-anchor="middle"
              >ROOT CAUSE</text>
            </g>
            <g v-if="e.stp_blocked">
              <rect
                class="stpbadge-bg"
                :x="e.lx - 26"
                :y="e.ly - 29"
                width="52"
                height="15"
                rx="4"
              />
              <text
                class="stpbadge-tx"
                :x="e.lx"
                :y="e.ly - 18"
                text-anchor="middle"
              >STP BLK</text>
            </g>
          </g>

          <!-- nodes -->
          <g
            v-for="n in layout.nodes"
            :key="n.id"
            :data-node-id="n.id"
            class="node"
            :class="{ 'is-dim': nodeDim(n.id), sel: n.id === selectedId, draggable: editLayout }"
            tabindex="0"
            role="button"
            :aria-label="n.label"
            @mouseenter="hoveredId = n.id; onHoverMove($event, { node: n })"
            @mousemove="onHoverMove($event, { node: n })"
            @mouseleave="hoveredId = null; hoverCard = null"
            @focus="hoveredId = n.id"
            @blur="hoveredId = null"
            @click="selectNode(n.id)"
            @keydown.enter="selectedId = n.id"
          >
            <!-- Next-hop = a small logical gateway pill, NOT a device card -->
            <template v-if="n.type === 'nexthop'">
              <rect
                class="nh-pill"
                :x="n.x"
                :y="n.y + (NH - 44) / 2"
                :width="n.nw"
                height="44"
                rx="10"
              />
              <g :transform="`translate(${n.x + 12}, ${n.y + NH / 2 - 10})`">
                <path
                  class="glyph"
                  :d="glyphs.gw"
                />
              </g>
              <circle
                :cx="n.x + n.nw - 14"
                :cy="n.y + NH / 2"
                r="4.5"
                :fill="n.color"
              />
              <title>{{ n.label }} · {{ n.sub }}</title>
              <text
                class="nh-ip"
                :x="n.x + 34"
                :y="n.y + NH / 2 - 2"
              >{{ n.label }}</text>
              <text
                class="nh-if"
                :x="n.x + 34"
                :y="n.y + NH / 2 + 12"
              >{{ n.sub }}</text>
            </template>
            <template v-else>
            <rect
              class="card"
              :x="n.x"
              :y="n.y"
              :width="n.nw"
              :height="NH"
              rx="12"
            />
            <rect
              :x="n.x + 1.5"
              :y="n.y + 12"
              width="4"
              :height="NH - 24"
              rx="2"
              :fill="n.color"
            />
            <g :transform="`translate(${n.x + 16}, ${n.y + NH / 2 - 11}) scale(1.05)`">
              <path
                class="glyph"
                :d="glyphs[n.type]"
              />
            </g>
            <circle
              :cx="n.x + n.nw - 16"
              :cy="n.y + NH - 17"
              r="5"
              :fill="n.color"
            />
            <use
              v-if="n.status === 'down'"
              href="#topo-x"
              class="node-x"
              :x="n.x + n.nw - 16 - 4"
              :y="n.y + NH - 17 - 4"
              width="8"
              height="8"
            />
            <title>{{ n.label }} · {{ n.sub }}{{ n.serial ? ` · S/N ${n.serial}` : '' }}</title>
            <!-- Full hostname: the node is sized to fit it, no truncation. -->
            <text
              class="nname"
              :x="n.x + 44"
              :y="rowY(n, 'name')"
            >{{ n.label }}</text>
            <text
              class="nsub"
              :x="n.x + 44"
              :y="rowY(n, 'sub')"
            >{{ n.sub }}</text>
            <text
              v-if="n.serial"
              class="nser"
              :x="n.x + 44"
              :y="rowY(n, 'serial')"
            >S/N {{ n.serial }}</text>
            <text
              v-if="endpointSummary(n)"
              class="nendpoints"
              :x="n.x + 44"
              :y="rowY(n, 'endpoints')"
            >{{ endpointSummary(n) }}</text>
            <g v-if="tunnelDownSummary(n)">
              <use
                href="#topo-warn"
                class="ntunneldown-ico"
                :x="n.x + 44"
                :y="rowY(n, 'tunneldown') - 10"
                width="11"
                height="11"
              />
              <text
                class="ntunneldown"
                :x="n.x + 44 + 15"
                :y="rowY(n, 'tunneldown')"
              >{{ tunnelDownSummary(n) }}</text>
            </g>
            <g v-if="n.tunnels">
              <rect
                :x="n.x + 44"
                :y="rowY(n, 'tunnels') - 12"
                :width="n.tunnels.length * 6.2 + 12"
                height="16"
                rx="5"
                :fill="n.status === 'up' ? 'rgba(var(--v-theme-success),0.14)' : 'rgba(var(--v-theme-error),0.14)'"
              />
              <text
                class="ntun"
                :x="n.x + 50"
                :y="rowY(n, 'tunnels')"
                :fill="n.color"
              >{{ n.tunnels }}</text>
            </g>
            </template>
            </g>
          </g>
        </svg>
        <div class="zoom-hint">
          Ctrl + scroll to zoom · drag to pan
        </div>
        <!-- Legend, in-graph -->
        <div class="topo-legend">
          <span class="li"><span class="ln" style="background:rgb(var(--v-theme-success))" />Up</span>
          <span class="li"><span class="ln" style="background:rgb(var(--v-theme-error))" />Down</span>
          <span class="li"><span class="dt" style="background:rgb(var(--v-theme-success))" />Reachable</span>
          <span class="li"><span class="dt" style="background:rgb(var(--v-theme-warning))" />Degraded</span>
          <span class="li"><span class="dt" style="background:rgb(var(--v-theme-error))" />Down</span>
        </div>
      </div>

      <!-- hover card: node health or link load/loss, follows the cursor -->
      <div v-if="hoverCard" class="topo-hc" :style="hoverCardStyle">
        <template v-if="hoverCard.node">
          <div class="topo-hc__t"><span class="topo-hc__dot" :style="{ background: statusColor(hoverCard.node.status) }" />{{ hoverCard.node.label }}</div>
          <div class="topo-hc__s">{{ hoverCard.node.role || hoverCard.node.sub }}</div>
          <template v-if="hoverCard.node.stats">
            <div class="topo-hc__row"><span>Busiest link</span><b>{{ hoverCard.node.stats.util }}%</b></div>
            <div class="topo-hc__bar"><i :style="{ width: `${hoverCard.node.stats.util}%`, background: metricColor(hoverCard.node.stats.util) }" /></div>
            <div class="topo-hc__row"><span>Errors Δ</span><b :class="{ bad: hoverCard.node.stats.errors > 0 }">{{ hoverCard.node.stats.errors }}</b></div>
            <div class="topo-hc__row"><span>Discards Δ</span><b :class="{ warnv: hoverCard.node.stats.discards > 0 }">{{ hoverCard.node.stats.discards }}</b></div>
            <div class="topo-hc__row"><span>Ports up</span><b>{{ hoverCard.node.stats.ports - hoverCard.node.stats.ports_down }}/{{ hoverCard.node.stats.ports }}</b></div>
          </template>
          <div v-else-if="hoverCard.node.type === 'cluster'" class="topo-hc__s">{{ hoverCard.node.count }} access switches · click to open</div>
          <div v-else class="topo-hc__s">No SNMP interface data</div>
        </template>
        <template v-else-if="hoverCard.edge">
          <div class="topo-hc__t"><span class="topo-hc__dot" :style="{ background: statusColor(hoverCard.edge.status) }" />{{ edgeKind(hoverCard.edge) }}</div>
          <div class="topo-hc__s">{{ nodeLabelOf(hoverCard.edge.from) }} &#8644; {{ nodeLabelOf(hoverCard.edge.to) }}</div>
          <div class="topo-hc__row"><span>Status</span><b>{{ statusLabel[hoverCard.edge.status] }}</b></div>
          <template v-if="hoverCard.edge.loss_pct != null">
            <div class="topo-hc__row"><span>Packet loss</span><b :class="{ bad: hoverCard.edge.loss_pct > 1 }">{{ hoverCard.edge.loss_pct.toFixed(1) }}%</b></div>
          </template>
          <template v-else-if="edgeUtil(hoverCard.edge) != null">
            <div class="topo-hc__row"><span>Link load</span><b>{{ edgeUtil(hoverCard.edge) }}%</b></div>
            <div class="topo-hc__bar"><i :style="{ width: `${edgeUtil(hoverCard.edge)}%`, background: metricColor(edgeUtil(hoverCard.edge) ?? 0) }" /></div>
          </template>
        </template>
      </div>
    </VCard>

    <!-- SITE lower: selected-node detail -->
    <VRow
      v-if="mode === 'site' && selectedNode"
      class="mt-1"
    >
      <VCol
        cols="12"
        md="5"
      >
        <VCard
          v-if="selectedNode"
          class="pa-4 h-100"
        >
          <!-- header: kind icon tile + name + live status -->
          <div class="d-flex align-center ga-3 mb-3">
            <div
              class="sn-icon"
              :style="{ '--sn-c': statusColor(selectedNode.status) }"
            >
              <VIcon
                :icon="nodeTypeIcon(selectedNode.type)"
                size="20"
              />
            </div>
            <div
              class="flex-grow-1"
              style="min-width: 0;"
            >
              <div class="text-subtitle-1 font-weight-bold text-truncate">
                {{ selectedNode.label }}
              </div>
              <div class="text-caption text-medium-emphasis text-truncate">
                {{ selectedNode.role }}
              </div>
            </div>
            <VChip
              size="small"
              :color="statusColorName(selectedNode.status)"
              variant="flat"
              label
            >
              {{ statusLabel[selectedNode.status] }}
            </VChip>
          </div>

          <!-- traced uplink path (click-to-trace lights it on the graph) -->
          <div v-if="tracePathNodes.length > 1" class="sn-route">
            <div class="sn-route__head">
              <span class="sn-route__lbl">Path to internet</span>
              <span class="sn-route__sum" :class="`t-${routeSummary.tone}`">{{ routeSummary.text }}</span>
            </div>
            <ol class="sn-route__list">
              <li
                v-for="(hn, i) in tracePathNodes"
                :key="hn.id"
                class="sn-route__hop"
                :class="{
                  on: hn.id === selectedId,
                  hot: hn.util != null && hn.util === routeBottleneck && (routeBottleneck ?? 0) >= 75,
                  down: hn.status === 'down',
                  last: i === tracePathNodes.length - 1,
                }"
              >
                <button type="button" class="sn-route__btn" @click="selectedId = hn.id">
                  <span class="sn-route__rail"><span class="sn-route__dot" :style="{ background: statusColor(hn.status) }" /></span>
                  <span class="sn-route__body">
                    <span class="sn-route__top">
                      <span class="sn-route__role">{{ hopRole(hn) }}</span>
                      <span class="sn-route__name">{{ hn.label }}</span>
                    </span>
                    <span v-if="hn.util != null" class="sn-route__util">
                      <span class="sn-route__bar"><i :style="{ width: `${hn.util}%`, background: metricColor(hn.util) }" /></span>
                      <span class="sn-route__pct">{{ hn.util }}%</span>
                    </span>
                  </span>
                </button>
              </li>
            </ol>
          </div>

          <!-- cluster: member switches (collapsed access fan) -->
          <div v-if="selectedNode.type === 'cluster' && selectedNode.members" class="sn-cluster">
            <div class="sn-cluster__head">
              <span class="sn-cluster__lbl">{{ selectedNode.members.length }} access switches</span>
              <button type="button" class="sn-cluster__expand" @click="expandCluster(selectedNode.id)">Expand in map</button>
            </div>
            <div class="sn-cluster__list">
              <button
                v-for="m in selectedNode.members"
                :key="m.id"
                type="button"
                class="sn-cluster__item"
                @click="expandCluster(selectedNode!.id, m.id)"
              >
                <span class="sn-path__dot" :style="{ background: statusColor(m.status) }" />
                <span class="sn-cluster__name">{{ m.label }}</span>
                <span v-if="m.util != null" class="sn-cluster__util">
                  <span class="sn-path__util-bar"><span class="sn-path__util-fill" :style="{ width: `${m.util}%`, background: metricColor(m.util) }" /></span>
                  <span class="sn-path__util-pct">{{ m.util }}%</span>
                </span>
              </button>
            </div>
          </div>

          <!-- meta rows -->
          <div v-if="selectedNode.type !== 'cluster'" class="sn-meta">
            <div class="sn-meta-row">
              <VIcon
                icon="ri-global-line"
                size="15"
                class="text-disabled"
              />
              <span class="lbl">Address</span>
              <span class="val mono">{{ selectedNode.ip ?? '—' }}</span>
            </div>
            <div class="sn-meta-row">
              <VIcon
                icon="ri-cpu-line"
                size="15"
                class="text-disabled"
              />
              <span class="lbl">Model</span>
              <span class="val">{{ selectedNode.model ?? '—' }}</span>
            </div>
            <div
              v-if="selectedNode.serial"
              class="sn-meta-row"
            >
              <VIcon
                icon="ri-barcode-line"
                size="15"
                class="text-disabled"
              />
              <span class="lbl">Serial</span>
              <span class="val mono">{{ selectedNode.serial }}</span>
            </div>
            <div
              v-if="selectedNode.lec_name"
              class="sn-meta-row"
            >
              <VIcon
                icon="ri-building-line"
                size="15"
                class="text-disabled"
              />
              <span class="lbl">LEC</span>
              <span class="val">{{ selectedNode.lec_name }}</span>
            </div>
            <div
              v-if="selectedNode.lec_circuit_id"
              class="sn-meta-row"
            >
              <VIcon
                icon="ri-links-line"
                size="15"
                class="text-disabled"
              />
              <span class="lbl">LEC circuit</span>
              <span class="val mono">{{ selectedNode.lec_circuit_id }}</span>
            </div>
            <div
              v-if="selectedNode.tunnels"
              class="sn-meta-row"
            >
              <VIcon
                icon="ri-links-line"
                size="15"
                class="text-disabled"
              />
              <span class="lbl">Tunnels</span>
              <span class="val">{{ selectedNode.tunnels }}</span>
            </div>
          </div>

          <!-- health as metric bars -->
          <div
            v-if="selectedNode.health"
            class="sn-health mt-3"
          >
            <div
              v-if="selectedNode.health.cpu_pct != null"
              class="sn-metric"
            >
              <div class="sn-metric-top">
                <span>CPU</span><span class="mono">{{ Math.round(selectedNode.health.cpu_pct) }}%</span>
              </div>
              <div class="sn-bar">
                <div
                  class="sn-bar-fill"
                  :style="{ width: `${Math.min(100, selectedNode.health.cpu_pct)}%`, background: metricColor(selectedNode.health.cpu_pct) }"
                />
              </div>
            </div>
            <div
              v-if="selectedNode.health.mem_pct != null"
              class="sn-metric"
            >
              <div class="sn-metric-top">
                <span>RAM</span><span class="mono">{{ Math.round(selectedNode.health.mem_pct) }}%</span>
              </div>
              <div class="sn-bar">
                <!-- EdgeConnect (Silver Peak) reserves nearly all RAM by design —
                     don't paint its high memory red like a switch. -->
                <div
                  class="sn-bar-fill"
                  :style="{ width: `${Math.min(100, selectedNode.health.mem_pct)}%`, background: selectedNode.role === 'edgeconnect' ? 'rgb(var(--v-theme-success))' : metricColor(selectedNode.health.mem_pct) }"
                />
              </div>
            </div>
            <div class="d-flex ga-4 mt-2">
              <span
                v-if="selectedNode.health.temperature_c != null"
                class="sn-stat"
                :class="{ 'text-error': selectedNode.health.temperature_c >= 70 }"
              >
                <VIcon
                  icon="ri-temp-hot-line"
                  size="14"
                />{{ Math.round(selectedNode.health.temperature_c) }}°C
              </span>
              <span
                v-if="selectedNode.health.uptime_seconds != null"
                class="sn-stat"
              >
                <VIcon
                  icon="ri-time-line"
                  size="14"
                />{{ formatUptime(selectedNode.health.uptime_seconds) }}
              </span>
            </div>
          </div>

          <!-- SD-WAN overlay tunnels, grouped by hub — each hub up unless any of
               its tunnels is down. -->
          <div
            v-if="selectedNode.tunnel_hubs && selectedNode.tunnel_hubs.length"
            class="ha-box mt-3"
          >
            <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-2">
              SD-WAN tunnels by hub
            </div>

            <!-- SNMP says tunnels are down but the SSH per-hub table is stale-all-up.
                 Warn so the green grid below isn't read as "everything's fine". -->
            <VAlert
              v-if="selectedNode.tunnels_stale"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-2 text-caption"
              icon="ri-error-warning-line"
            >
              <strong>Tunnels down</strong> — the appliance reports it, but not which
              ones. The per-hub detail below is from the last SSH poll and may lag.
            </VAlert>

            <!-- Hub appliance: many destinations → roll up to a count + list only
                 the ones that are down. -->
            <template v-if="tunnelHubView.aggregate">
              <div class="sn-hub-row">
                <VIcon
                  :icon="tunnelHubView.downCount > 0 ? 'ri-error-warning-line' : (selectedNode.tunnels_stale ? 'ri-time-line' : 'ri-checkbox-circle-line')"
                  size="15"
                  :color="tunnelHubView.downCount > 0 ? 'error' : (selectedNode.tunnels_stale ? 'grey' : 'success')"
                />
                <span>{{ tunnelHubView.total }} destinations</span>
                <VChip
                  size="x-small"
                  :color="tunnelHubView.downCount > 0 ? 'error' : (selectedNode.tunnels_stale ? 'grey' : 'success')"
                  variant="tonal"
                  class="ms-auto"
                >
                  {{ tunnelHubView.downCount > 0
                    ? `${tunnelHubView.downCount} with tunnels down`
                    : (selectedNode.tunnels_stale ? 'all up · pending refresh' : 'all up') }}
                </VChip>
              </div>
              <div
                v-for="h in tunnelHubView.down"
                :key="h.hub"
                class="sn-hub-row"
              >
                <VIcon
                  icon="ri-error-warning-line"
                  size="15"
                  color="error"
                />
                <span class="sn-hub-name">{{ h.hub }}</span>
                <VChip
                  size="x-small"
                  color="error"
                  variant="tonal"
                  class="ms-auto"
                >
                  {{ h.down }}/{{ h.total }} down
                </VChip>
              </div>
            </template>

            <!-- Branch: a handful of hubs → list each. -->
            <template v-else>
              <div
                v-for="h in tunnelHubView.hubs"
                :key="h.hub"
                class="sn-hub-row"
              >
                <VIcon
                  :icon="h.down > 0 ? 'ri-error-warning-line' : (selectedNode.tunnels_stale ? 'ri-time-line' : 'ri-cloud-line')"
                  size="15"
                  :color="h.down > 0 ? 'error' : (selectedNode.tunnels_stale ? 'grey' : 'success')"
                />
                <span class="sn-hub-name">{{ h.hub }}</span>
                <VChip
                  size="x-small"
                  :color="h.down > 0 ? 'error' : (selectedNode.tunnels_stale ? 'grey' : 'success')"
                  variant="tonal"
                  class="ms-auto"
                >
                  {{ h.down > 0
                    ? `${h.down}/${h.total} down`
                    : (selectedNode.tunnels_stale ? `${h.total} up · pending` : `${h.total} up`) }}
                </VChip>
              </div>
            </template>
          </div>

          <!-- HA pair members + redundancy state -->
          <div
            v-if="selectedNode.ha && selectedNode.ha_members"
            class="ha-box mt-3"
          >
            <div class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1">
              HA pair
            </div>
            <div
              v-for="m in selectedNode.ha_members"
              :key="m.name"
              class="d-flex align-center ga-2 mono"
            >
              <span
                class="ha-dot"
                :class="m.status === 'up' ? 'up' : 'down'"
              />
              <span>{{ m.name }}</span>
              <span
                v-if="m.role"
                class="text-caption text-medium-emphasis"
              >· {{ m.role }}</span>
            </div>
          </div>

          <!-- Interface-down alarms on this switch: which ports are down and since
               when — the alarms that make the node degraded. -->
          <div
            v-if="selectedNode.alarmed_interfaces && selectedNode.alarmed_interfaces.length"
            class="lldp-box mt-3 alarm-box"
          >
            <div class="text-caption text-uppercase font-weight-medium mb-2 text-warning">
              Interface alarms ({{ selectedNode.alarmed_interfaces.length }})
            </div>
            <div
              v-for="a in selectedNode.alarmed_interfaces"
              :key="a.id"
              class="alarm-item"
            >
              <div class="d-flex align-center ga-2 flex-wrap">
                <VIcon
                  icon="ri-alert-line"
                  size="15"
                  color="warning"
                />
                <span class="mono font-weight-medium">{{ a.name }}</span>
                <span
                  v-if="a.ticket"
                  class="text-caption mono text-medium-emphasis"
                >#{{ a.ticket }}</span>
                <VChip
                  v-if="a.acknowledged"
                  size="x-small"
                  color="info"
                  variant="tonal"
                >
                  ack'd
                </VChip>
                <span class="text-caption text-medium-emphasis ms-auto">{{ a.since ? `down ${formatDateTime(a.since)}` : 'down' }}</span>
              </div>
              <div v-if="auth.canAct" class="d-flex ga-1 mt-1">
                <VBtn
                  v-if="a.alert_id && !a.acknowledged"
                  size="x-small"
                  variant="tonal"
                  :loading="alertBusy === a.alert_id"
                  @click="ackInterfaceAlert(a.alert_id)"
                >
                  Ack
                </VBtn>
                <VBtn
                  v-if="a.alert_id"
                  size="x-small"
                  variant="tonal"
                  color="success"
                  :loading="alertBusy === a.alert_id"
                  @click="openClear(a.alert_id)"
                >
                  Clear
                </VBtn>
                <VBtn
                  v-if="auth.isAdmin"
                  size="x-small"
                  variant="text"
                  :loading="alertBusy === a.id"
                  @click="muteInterface(a.id)"
                >
                  Mute
                </VBtn>
              </div>
            </div>
          </div>

          <!-- LLDP neighbours open in a dialog so the panel stays short even with a
               busy access switch (40+ endpoints). -->
          <VBtn
            v-if="selectedNode.lldp_endpoints && selectedNode.lldp_endpoints.length"
            class="mt-3"
            variant="tonal"
            size="small"
            block
            prepend-icon="ri-node-tree"
            @click="lldpDialog = true"
          >
            LLDP neighbors ({{ selectedNode.lldp_endpoints.length }})
          </VBtn>

          <VBtn
            v-if="selectedNode.device_id || selectedNode.circuit_id"
            class="mt-3"
            variant="tonal"
            size="small"
            @click="goToNode(selectedNode)"
          >
            {{ selectedNode.circuit_id ? 'Open circuit' : 'Open device' }} →
          </VBtn>
        </VCard>
      </VCol>

      <!-- Interface stats for a device node: CRC/errors + drops at a glance -->
      <VCol
        v-if="selectedNode.device_id"
        cols="12"
        md="7"
      >
        <VCard class="pa-4 h-100 d-flex flex-column">
          <div class="d-flex align-center justify-space-between mb-2 ga-2 flex-0-0">
            <span class="text-caption text-medium-emphasis text-uppercase font-weight-medium">
              Interface stats
            </span>
            <div class="d-flex align-center ga-2">
              <span class="text-caption text-medium-emphasis">
                <template v-if="ifPolledAt">deltas as of {{ formatDateTime(ifPolledAt) }}</template>
                <template v-else>errors / drops = deltas since last poll</template>
              </span>
              <VBtn
                icon="ri-refresh-line"
                size="x-small"
                variant="tonal"
                :loading="ifLoading"
                title="Refresh interface stats"
                @click="loadInterfaceStats(selectedId)"
              />
            </div>
          </div>

          <div
            v-if="ifLoading"
            class="text-body-2 text-medium-emphasis py-4"
          >
            Loading…
          </div>
          <div
            v-else-if="!ifStats.length"
            class="text-body-2 text-medium-emphasis py-4"
          >
            No SNMP interface data for this device.
          </div>
          <div
            v-else
            class="if-scroll"
          >
            <table class="if-table">
              <thead>
                <tr>
                  <th class="text-left">
                    Interface
                  </th>
                  <th>Status</th>
                  <th>Util%</th>
                  <th>In err</th>
                  <th>Out err</th>
                  <th>In drop</th>
                  <th>Out drop</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="i in ifStats"
                  :key="i.id"
                >
                  <td class="text-left mono text-truncate">
                    {{ i.if_name }}
                  </td>
                  <td>
                    <VIcon
                      v-if="i.status === 'down' && i.admin_status === 'up'"
                      icon="ri-close-circle-line"
                      size="16"
                      class="if-x"
                      aria-hidden="true"
                    />
                    <span
                      v-else
                      class="dot"
                      :class="i.status === 'up' ? 'dot-up' : 'dot-admin'"
                    />
                    {{ i.status === 'down' && i.admin_status === 'down' ? 'shut' : i.status }}
                  </td>
                  <td class="mono">
                    {{ ifUtil(i).toFixed(0) }}
                  </td>
                  <td
                    class="mono"
                    :class="i.in_errors_delta > 0 ? 'bad' : ''"
                  >
                    {{ i.in_errors_delta }}
                  </td>
                  <td
                    class="mono"
                    :class="i.out_errors_delta > 0 ? 'bad' : ''"
                  >
                    {{ i.out_errors_delta }}
                  </td>
                  <td
                    class="mono"
                    :class="i.in_discards_delta > 0 ? 'warnv' : ''"
                  >
                    {{ i.in_discards_delta }}
                  </td>
                  <td
                    class="mono"
                    :class="i.out_discards_delta > 0 ? 'warnv' : ''"
                  >
                    {{ i.out_discards_delta }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- ORG view — triage at scale: exceptions first, healthy on demand -->
    <template v-else>
      <VCard class="pa-3 mb-3">
        <div class="d-flex align-center flex-wrap ga-3 justify-space-between">
          <div class="d-flex align-center ga-4">
            <div>
              <span class="text-h6 font-weight-medium">{{ orgStats.total }}</span>
              <span class="text-caption text-medium-emphasis ms-1">sites</span>
            </div>
            <div>
              <span
                class="text-h6 font-weight-medium"
                :class="orgStats.impacted > 0 ? 'text-error' : ''"
              >{{ orgStats.impacted }}</span>
              <span class="text-caption text-medium-emphasis ms-1">impacted</span>
            </div>
            <div>
              <span class="text-h6 font-weight-medium text-success">{{ orgStats.healthy }}</span>
              <span class="text-caption text-medium-emphasis ms-1">healthy</span>
            </div>
          </div>
          <div class="d-flex align-center ga-2 flex-wrap">
            <VTextField
              v-model="orgSearch"
              placeholder="Search location…"
              prepend-inner-icon="ri-search-line"
              density="compact"
              hide-details
              clearable
              style="min-width: 220px;"
            />
            <div class="topo-modetoggle">
              <button
                type="button"
                class="seg-btn"
                :class="{ active: orgFilter === 'impacted' }"
                @click="orgFilter = 'impacted'"
              >
                Impacted
              </button>
              <button
                type="button"
                class="seg-btn"
                :class="{ active: orgFilter === 'healthy' }"
                @click="orgFilter = 'healthy'"
              >
                Healthy
              </button>
              <button
                type="button"
                class="seg-btn"
                :class="{ active: orgFilter === 'all' }"
                @click="orgFilter = 'all'"
              >
                All
              </button>
            </div>
          </div>
        </div>
      </VCard>

      <div
        v-if="orgFiltered.length === 0"
        class="text-center text-medium-emphasis py-10"
      >
        <VIcon
          icon="ri-shield-check-line"
          size="34"
          class="mb-2 text-success"
        />
        <div>{{ orgFilter === 'impacted' ? 'All locations healthy — no active incidents.' : 'No locations match.' }}</div>
      </div>

      <!-- Flat grid when no hubs are modeled yet -->
      <div
        v-if="!hasHubs"
        class="org-grid"
      >
        <TopologySiteCard
          v-for="s in orgFiltered"
          :key="s.id"
          :site="s"
          @open="openSite"
        />
      </div>

      <!-- Hub-and-spoke: branches grouped under their hub -->
      <template v-else>
        <div
          v-for="g in orgGroups.groups"
          :key="g.hub.id"
          class="mb-4"
        >
          <div
            class="hub-header"
            @click="toggleHub(g.hub.id)"
          >
            <VIcon :icon="collapsedHubs.has(g.hub.id) ? 'ri-arrow-right-s-line' : 'ri-arrow-down-s-line'" />
            <span
              class="hub-name"
              @click.stop="openSite(g.hub.id)"
            >{{ g.hub.name }}</span>
            <VChip
              size="x-small"
              color="primary"
              variant="tonal"
            >Hub</VChip>
            <VChip
              size="x-small"
              :color="g.hub.state === 'crit' ? 'error' : g.hub.state === 'warn' ? 'warning' : 'success'"
              variant="tonal"
            >{{ g.hub.summary }}</VChip>
            <VSpacer />
            <span class="text-caption text-medium-emphasis">
              {{ g.total }} branches ·
              <span :class="g.impacted > 0 ? 'text-error font-weight-medium' : ''">{{ g.impacted }} impacted</span>
            </span>
          </div>
          <div
            v-if="!collapsedHubs.has(g.hub.id) && g.branches.length"
            class="org-grid mt-3"
          >
            <TopologySiteCard
              v-for="b in g.branches"
              :key="b.id"
              :site="b"
              @open="openSite"
            />
          </div>
          <div
            v-else-if="!collapsedHubs.has(g.hub.id)"
            class="text-caption text-medium-emphasis mt-2 ms-9"
          >
            No branches match the current filter.
          </div>
        </div>

        <div v-if="orgGroups.unassigned.length">
          <div class="hub-header">
            <VIcon icon="ri-question-line" />
            <span class="hub-name">Unassigned</span>
            <VSpacer />
            <span class="text-caption text-medium-emphasis">{{ orgGroups.unassignedTotal }} sites</span>
          </div>
          <div class="org-grid mt-3">
            <TopologySiteCard
              v-for="u in orgGroups.unassigned"
              :key="u.id"
              :site="u"
              @open="openSite"
            />
          </div>
        </div>
      </template>
    </template>

    <VDialog
      v-model="lldpDialog"
      max-width="560"
      scrollable
    >
      <VCard :title="`LLDP neighbors — ${selectedNode?.label ?? ''}`">
        <VCardText style="max-height: 70vh;">
          <div
            v-for="g in endpointGroups"
            :key="g.type"
            class="lldp-group"
          >
            <div class="lldp-group-head" style="cursor: default;">
              <VIcon
                :icon="lldpIcon(g.type)"
                size="15"
                :class="`lldp-${g.type}`"
              />
              <span class="lldp-group-label">{{ g.label }}</span>
              <VChip
                size="x-small"
                variant="tonal"
                class="ms-auto"
              >
                {{ g.items.length }}
              </VChip>
            </div>
            <div
              v-for="(e, i) in g.items"
              :key="i"
              class="lldp-row"
            >
              <span
                class="mono lldp-port text-truncate"
                :title="e.port ?? ''"
              >{{ e.port ?? '—' }}</span>
              <span class="lldp-name text-truncate">
                {{ parseEndpoint(e).title }}
                <span
                  v-if="parseEndpoint(e).sub"
                  class="text-medium-emphasis"
                >· {{ parseEndpoint(e).sub }}</span>
              </span>
              <span
                v-if="e.ip"
                class="mono lldp-ip"
              >{{ e.ip }}</span>
            </div>
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn @click="lldpDialog = false">
            Close
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <VDialog
      v-model="clearDialog.open"
      max-width="440"
    >
      <VCard title="Clear interface alarm">
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-3">
            The alarm won't reopen until the port flaps (goes up, then down again).
          </p>
          <VTextarea
            v-model="clearDialog.note"
            label="Resolution note (optional)"
            rows="2"
            auto-grow
          />
          <div class="d-flex justify-end ga-2 mt-3">
            <VBtn
              variant="text"
              @click="clearDialog.open = false"
            >
              Cancel
            </VBtn>
            <VBtn
              color="success"
              :loading="alertBusy === clearDialog.alertId"
              @click="submitClear"
            >
              Clear alarm
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.topo-controls {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.topo-modetoggle {
  display: inline-flex;
  flex: 0 0 auto;
  border: 1px solid rgba(var(--v-theme-on-surface), 0.24);
  border-radius: 8px;
  overflow: hidden;
}
.seg-btn {
  appearance: none;
  background: transparent;
  border: 0;
  border-right: 1px solid rgba(var(--v-theme-on-surface), 0.24);
  padding: 8px 18px;
  font: inherit;
  font-size: 13px;
  font-weight: 550;
  line-height: 1;
  white-space: nowrap;
  min-width: 118px;
  cursor: pointer;
  color: rgba(var(--v-theme-on-surface), 0.7);
}
.seg-btn:last-child { border-right: 0; }
.seg-btn:hover { background: rgba(var(--v-theme-on-surface), 0.04); }
.seg-btn.active {
  background: rgba(var(--v-theme-primary), 0.14);
  color: rgb(var(--v-theme-primary));
}
.seg-btn:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.topo-siteselect { width: 260px; max-width: 100%; }

.topo-canvas {
  background-image: radial-gradient(rgba(var(--v-theme-on-surface), 0.05) 1.2px, transparent 1.2px);
  background-size: 22px 22px;
}
/* Bound the canvas on the HOLDER (scroll), not the SVG. The SVG keeps height:auto so
   its element aspect ratio matches its viewBox — the pan/zoom handlers map screen→world
   through rect.height, which is only correct when the viewBox fills the element. */
.canvas-holder { position: relative; padding: 8px; max-block-size: min(80vh, 860px); overflow: auto; }
.topo-svg { display: block; width: 100%; height: auto; touch-action: none; cursor: grab; }
.topo-svg.grabbing { cursor: grabbing; }
/* White packet with a green glow — reads as "flow" without being a second green. */
.flow-dot { fill: #ffffff; stroke: rgba(var(--v-theme-success), 0.9); stroke-width: 0.6; filter: drop-shadow(0 0 3px rgba(var(--v-theme-success), 0.9)); }
.zoom-ctl { position: absolute; top: 14px; right: 14px; z-index: 2; display: flex; flex-direction: column; gap: 6px; }
.node.draggable { cursor: move; }
.arrange-hint {
  position: absolute; top: 14px; left: 14px; z-index: 2;
  background: rgba(var(--v-theme-primary), 0.92); color: rgb(var(--v-theme-on-primary));
  padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
  box-shadow: 0 2px 8px rgba(0,0,0,0.25);
}

/* Legend overlaid inside the graph (bottom-left). */
.topo-legend {
  position: absolute; left: 14px; bottom: 14px; z-index: 2;
  display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center;
  background: rgba(var(--v-theme-surface), 0.9); border: 1px solid rgba(var(--v-theme-on-surface), 0.1);
  border-radius: 8px; padding: 6px 12px; font-size: 11.5px; color: rgba(var(--v-theme-on-surface), 0.75);
}
.topo-legend .li { display: flex; align-items: center; gap: 6px; }
.topo-legend .ln { width: 20px; height: 3px; border-radius: 2px; }
.topo-legend .dt { width: 8px; height: 8px; border-radius: 50%; }
.zoom-hint {
  position: absolute; bottom: 12px; left: 16px; font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.5); pointer-events: none;
}

.node { cursor: pointer; transition: opacity .15s; }
.node.is-dim { opacity: .35; }
.node .card {
  fill: rgb(var(--v-theme-surface));
  stroke: rgba(var(--v-theme-on-surface), 0.16);
  stroke-width: 1;
  filter: drop-shadow(0 6px 16px rgba(0, 0, 0, .12));
  transition: stroke .15s;
}
.node.sel .card { stroke: rgb(var(--v-theme-primary)); stroke-width: 2; }
.node:focus-visible { outline: none; }
.node:focus-visible .card { stroke: rgb(var(--v-theme-primary)); stroke-width: 2; }
.nname { fill: rgb(var(--v-theme-on-surface)); font-weight: 640; font-size: 15.5px; }
.nsub { fill: rgba(var(--v-theme-on-surface), 0.6); font-size: 12.5px; font-family: ui-monospace, Menlo, monospace; }
.nser { fill: rgba(var(--v-theme-on-surface), 0.42); font-size: 11px; font-family: ui-monospace, Menlo, monospace; }
.nendpoints { fill: rgb(var(--v-theme-info)); font-size: 11px; font-weight: 600; }
.ntunneldown { fill: rgb(var(--v-theme-error)); font-size: 11px; font-weight: 700; }
.ntunneldown-ico { fill: rgb(var(--v-theme-error)); }
.node-x { fill: #fff; font-size: 8px; font-weight: 800; text-anchor: middle; dominant-baseline: central; pointer-events: none; }
.call-isp { color: rgb(var(--v-theme-error)); }
.call-isp-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; opacity: .8; line-height: 1; }
.call-isp-num { font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums; color: rgb(var(--v-theme-error)); text-decoration: none; line-height: 1.1; }
.call-isp-num:hover { text-decoration: underline; }
/* Next-hop pill: dashed + light so it reads as a logical gateway, not a device. */
.nh-pill {
  fill: rgba(var(--v-theme-surface), 0.7);
  stroke: rgba(var(--v-theme-on-surface), 0.3);
  stroke-width: 1;
  stroke-dasharray: 4 3;
}
.node.sel .nh-pill { stroke: rgb(var(--v-theme-primary)); stroke-dasharray: none; stroke-width: 2; }
.nh-ip { fill: rgb(var(--v-theme-on-surface)); font-size: 13px; font-weight: 600; font-family: ui-monospace, Menlo, monospace; }
.nh-if { fill: rgba(var(--v-theme-on-surface), 0.55); font-size: 11px; font-family: ui-monospace, Menlo, monospace; }
.ntun { font-size: 10.5px; font-family: ui-monospace, Menlo, monospace; font-weight: 600; }
.glyph { stroke: rgb(var(--v-theme-on-surface)); stroke-width: 1.6; fill: none; stroke-linecap: round; stroke-linejoin: round; opacity: .82; }

.edge .wire { fill: none; stroke: rgba(var(--v-theme-on-surface), 0.28); stroke-width: 2.4; transition: opacity .15s; }
.edge.up .wire { stroke: color-mix(in srgb, rgb(var(--v-theme-success)) 55%, rgba(var(--v-theme-on-surface), 0.3)); }
.edge.down .wire { stroke: rgb(var(--v-theme-error)); stroke-width: 2.6; stroke-dasharray: 7 6; }
.edge.down.root .wire { stroke-width: 3.4; }
/* SD-WAN overlay = the encrypted tunnels riding to the hubs (the cloud fabric),
   not the ISP circuits themselves — a muted steel-blue reads as its own layer
   without the neon look, matching the graphite/green operator style. */
.edge.overlay .wire { stroke: #6E8CA8; stroke-dasharray: 4 6; opacity: .85; stroke-width: 2; }
.edge.overlay .elabel { fill: #7E97AF; font-weight: 600; }
/* A down overlay must still read red. This rule follows .edge.down at equal
   specificity, so without it the steel-blue overlay colour won and a tunnel
   outage drew as a healthy line. */
.edge.overlay.down .wire { stroke: rgb(var(--v-theme-error)); stroke-width: 2.6; opacity: 1; }
.edge.overlay.down .elabel { fill: rgb(var(--v-theme-error)); }
.edge.ha .wire { stroke: color-mix(in srgb, rgb(var(--v-theme-secondary)) 75%, rgba(var(--v-theme-on-surface), 0.3)); stroke-dasharray: 5 4; stroke-width: 2; }
.edge.ha .elabel { fill: rgb(var(--v-theme-secondary)); font-weight: 600; }
.edge.is-dim { opacity: .25; }
.edge.hl .wire { stroke-width: 3.6; }
.elabel { fill: rgba(var(--v-theme-on-surface), 0.6); font-size: 10.5px; font-family: ui-monospace, Menlo, monospace; }
.elabel-bg { fill: rgb(var(--v-theme-surface)); }
.edge.down .elabel { fill: rgb(var(--v-theme-error)); font-weight: 600; }
.rootbadge-bg { fill: rgb(var(--v-theme-error)); }
.rootbadge-tx { fill: #fff; font-size: 9.5px; font-weight: 700; letter-spacing: .06em; }
.stpbadge-bg { fill: rgb(var(--v-theme-warning)); }
.stpbadge-tx { fill: #1b1b1b; font-size: 10px; font-weight: 800; letter-spacing: .05em; }
.edge.stp-blocked .wire { stroke: rgb(var(--v-theme-warning)); stroke-dasharray: 7 4; stroke-width: 2.6; opacity: .9; }
.edge.stp-blocked .elabel { fill: rgb(var(--v-theme-warning)); font-weight: 700; }
@media (prefers-reduced-motion: no-preference) {
  .edge.down .wire { animation: topoflow 1s linear infinite; }
  @keyframes topoflow { to { stroke-dashoffset: -26; } }
}

.legend-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: rgba(var(--v-theme-on-surface), 0.6); }
.lg-line { width: 22px; height: 4px; border-radius: 2px; }
.lg-dot { width: 9px; height: 9px; border-radius: 50%; }
.detail-grid { display: grid; grid-template-columns: 84px 1fr; gap: 7px 12px; font-size: 12.5px; }
/* Selected-node panel */
.sn-icon { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
  color: var(--sn-c); background: color-mix(in srgb, var(--sn-c) 14%, transparent);
  border: 1px solid color-mix(in srgb, var(--sn-c) 35%, transparent); }
.sn-path { margin: 10px 0 4px; }
.sn-path__lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: rgba(var(--v-theme-on-surface), .5); font-weight: 600; margin-bottom: 6px; }
.sn-path__hops { display: flex; align-items: center; flex-wrap: wrap; gap: 2px; }
.sn-path__arr { color: rgba(var(--v-theme-on-surface), .35); }
.sn-path__hop { display: inline-flex; align-items: center; gap: 5px; font: inherit; font-size: 11.5px; cursor: pointer;
  background: rgba(var(--v-theme-on-surface), .05); border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px; padding: 3px 8px; color: rgb(var(--v-theme-on-surface)); white-space: nowrap; }
.sn-path__hop.on { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .12); }
.sn-path__hop:hover { border-color: rgba(var(--v-theme-primary), .5); }
.sn-path__dot { inline-size: 7px; block-size: 7px; border-radius: 50%; flex: none; }
.sn-path__util { display: inline-flex; align-items: center; gap: 4px; margin-inline-start: 2px; }
.sn-path__util-bar { inline-size: 30px; block-size: 4px; border-radius: 2px; background: rgba(var(--v-theme-on-surface), .12); overflow: hidden; }
.sn-path__util-fill { display: block; block-size: 100%; border-radius: 2px; }
.sn-path__util-pct { font-size: 10px; font-variant-numeric: tabular-nums; color: rgba(var(--v-theme-on-surface), .6); }
/* Path to internet — vertical route sheet (internet at top → this device at bottom),
   each hop shows role, health and load; the busiest hop (bottleneck) is flagged. */
.sn-route { margin: 12px 0 6px; }
.sn-route__head { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
.sn-route__lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: rgba(var(--v-theme-on-surface), .5); font-weight: 600; }
.sn-route__sum { font-size: 11px; font-weight: 600; font-variant-numeric: tabular-nums; }
.sn-route__sum.t-ok { color: rgba(var(--v-theme-on-surface), .55); }
.sn-route__sum.t-warn { color: rgb(var(--v-theme-warning)); }
.sn-route__sum.t-down { color: rgb(var(--v-theme-error)); }
.sn-route__list { list-style: none; margin: 0; padding: 0; }
.sn-route__btn { display: flex; gap: 10px; inline-size: 100%; text-align: start; cursor: pointer; padding: 0; background: none; border: 0; font: inherit; color: inherit; }
.sn-route__rail { position: relative; flex: 0 0 12px; display: flex; justify-content: center; }
.sn-route__rail::before { content: ''; position: absolute; inset-block: 0; inline-size: 2px; background: rgba(var(--v-theme-on-surface), .15); }
.sn-route__hop:first-child .sn-route__rail::before { inset-block-start: 14px; }
.sn-route__hop.last .sn-route__rail::before { inset-block-end: calc(100% - 14px); }
.sn-route__dot { position: relative; z-index: 1; margin-block-start: 10px; inline-size: 9px; block-size: 9px; border-radius: 50%; box-shadow: 0 0 0 3px rgb(var(--v-theme-surface)); }
.sn-route__body { flex: 1; min-inline-size: 0; margin-block: 2px; padding: 6px 9px; border-radius: 8px; border: 1px solid transparent; }
.sn-route__btn:hover .sn-route__body { background: rgba(var(--v-theme-on-surface), .045); }
.sn-route__hop.on .sn-route__body { border-color: rgb(var(--v-theme-primary)); background: rgba(var(--v-theme-primary), .08); }
.sn-route__hop.hot .sn-route__body { border-color: rgba(var(--v-theme-warning), .55); }
.sn-route__hop.down .sn-route__body { border-color: rgba(var(--v-theme-error), .55); }
.sn-route__top { display: flex; align-items: baseline; gap: 8px; }
.sn-route__role { flex: 0 0 52px; font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: rgba(var(--v-theme-on-surface), .45); font-weight: 700; }
.sn-route__name { font-size: 12px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sn-route__util { display: flex; align-items: center; gap: 7px; margin-block-start: 5px; padding-inline-start: 60px; }
.sn-route__bar { flex: 1; block-size: 4px; border-radius: 2px; background: rgba(var(--v-theme-on-surface), .12); overflow: hidden; }
.sn-route__bar i { display: block; block-size: 100%; border-radius: 2px; }
.sn-route__pct { font-size: 10px; font-variant-numeric: tabular-nums; color: rgba(var(--v-theme-on-surface), .6); min-inline-size: 30px; text-align: end; }
.sn-cluster { margin: 12px 0 4px; }
.sn-cluster__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.sn-cluster__lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: rgba(var(--v-theme-on-surface), .5); font-weight: 600; }
.sn-cluster__expand { font: inherit; font-size: 11px; font-weight: 600; color: rgb(var(--v-theme-primary)); cursor: pointer; }
.sn-cluster__list { display: flex; flex-direction: column; gap: 2px; max-block-size: 240px; overflow-y: auto; }
.sn-cluster__item { display: flex; align-items: center; gap: 7px; font: inherit; font-size: 12px; text-align: start;
  padding: 5px 7px; border-radius: 7px; cursor: pointer; border: 1px solid transparent; }
.sn-cluster__item:hover { background: rgba(var(--v-theme-primary), .07); border-color: rgba(var(--v-theme-primary), .3); }
.sn-cluster__name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sn-cluster__util { display: inline-flex; align-items: center; gap: 4px; flex: none; }
.sn-meta { display: flex; flex-direction: column; }
.sn-meta-row { display: grid; grid-template-columns: 18px 92px 1fr; align-items: center; gap: 8px; padding: 5px 0; font-size: 12.5px; }
.sn-meta-row + .sn-meta-row { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.06); }
.sn-meta-row .lbl { color: rgba(var(--v-theme-on-surface), 0.6); }
.sn-meta-row .val { text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sn-health { display: flex; flex-direction: column; gap: 9px; }
.sn-hub-row { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 12.5px; }
.sn-hub-row + .sn-hub-row { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.06); }
.sn-hub-name { font-family: ui-monospace, Menlo, monospace; font-size: 12px; }
.sn-metric-top { display: flex; justify-content: space-between; font-size: 11.5px; color: rgba(var(--v-theme-on-surface), 0.7); margin-bottom: 3px; }
.sn-bar { height: 6px; border-radius: 4px; background: rgba(var(--v-theme-on-surface), 0.1); overflow: hidden; }
.sn-bar-fill { height: 100%; border-radius: 4px; transition: width .3s ease; }
.sn-stat { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; color: rgba(var(--v-theme-on-surface), 0.7); }
.ha-box { padding: 8px 10px; border-radius: 8px; background: rgba(var(--v-theme-on-surface), 0.03); border: 1px solid rgba(var(--v-theme-on-surface), 0.1); font-size: 12.5px; }
.ha-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.ha-dot.up { background: rgb(var(--v-theme-success)); }
.ha-dot.down { background: rgb(var(--v-theme-error)); }
.lldp-box { padding: 8px 10px; border-radius: 8px; background: rgba(var(--v-theme-on-surface), 0.03); border: 1px solid rgba(var(--v-theme-on-surface), 0.1); font-size: 12.5px; }
.lldp-group + .lldp-group { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.lldp-group-head { display: flex; align-items: center; gap: 7px; width: 100%; padding: 6px 2px; background: none; border: none; cursor: pointer; color: inherit; text-align: left; }
.lldp-group-head:hover { background: rgba(var(--v-theme-on-surface), 0.04); }
.lldp-group-label { font-size: 12.5px; font-weight: 500; }
.lldp-scroll { max-height: 200px; overflow-y: auto; padding: 2px 0 6px 25px; }
.lldp-row { display: grid; grid-template-columns: 92px 1fr auto; align-items: center; gap: 8px; padding: 3px 0; }
.lldp-port { font-size: 11.5px; color: rgba(var(--v-theme-on-surface), 0.7); }
.lldp-ip { font-size: 11px; color: rgb(var(--v-theme-info)); }
.alarm-box { border-color: rgba(var(--v-theme-warning), 0.4); background: rgba(var(--v-theme-warning), 0.06); }
.alarm-item { padding: 6px 0; }
.alarm-item + .alarm-item { border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08); }
.lldp-name { font-size: 12px; }
.lldp-ap { color: rgb(var(--v-theme-info)); }
.lldp-phone { color: rgb(var(--v-theme-success)); }
.lldp-camera { color: rgb(var(--v-theme-warning)); }
.lldp-switch, .lldp-router { color: rgba(var(--v-theme-on-surface), 0.6); }
.mono { font-family: ui-monospace, Menlo, monospace; font-size: 12px; }

.org-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
.hub-header {
  display: flex; align-items: center; gap: 10px; padding: 8px 6px; cursor: pointer;
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.1);
}
.hub-header:hover { background: rgba(var(--v-theme-on-surface), 0.02); }
.hub-name { font-weight: 640; font-size: 15px; }
.hub-name:hover { color: rgb(var(--v-theme-primary)); }
.site-card { cursor: pointer; transition: transform .1s, border-color .15s; }
.site-card:hover { transform: translateY(-1px); }
.site-card.crit { border-inline-start: 3px solid rgb(var(--v-theme-error)); }
.site-card.warn { border-inline-start: 3px solid rgb(var(--v-theme-warning)); }
.chain { display: flex; align-items: center; gap: 5px; }
.chain-node {
  width: 26px; height: 26px; border-radius: 7px; display: grid; place-items: center;
  background: rgba(var(--v-theme-on-surface), 0.04); border: 1px solid rgba(var(--v-theme-on-surface), 0.12);
  color: rgba(var(--v-theme-on-surface), 0.55);
}
.chain-node.dn { border-color: rgba(var(--v-theme-error), 0.5); background: rgba(var(--v-theme-error), 0.12); color: rgb(var(--v-theme-error)); }
.chain-link { flex: 1; height: 2px; border-radius: 2px; background: rgba(var(--v-theme-on-surface), 0.2); }
.chain-link.dn { background: rgb(var(--v-theme-error)); }

/* interface stats table */
/* Fill the card's available height (it sits beside the taller node-detail column)
   instead of a stunted fixed box; scroll only when the interface list overflows. */
.if-scroll { flex: 1 1 auto; min-block-size: 120px; max-block-size: 62vh; overflow-y: auto; overflow-x: auto; }
.if-table thead th { position: sticky; top: 0; z-index: 1; background: rgb(var(--v-theme-surface)); }
/* wide invisible hit area so the thin link is easy to hover */
.wire-hit { fill: none; stroke: transparent; stroke-width: 14; pointer-events: stroke; cursor: help; }
.topo-hc { position: fixed; z-index: 40; pointer-events: none; inline-size: 224px;
  background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-theme-on-surface), .14);
  border-radius: 10px; padding: 10px 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, .45); font-size: 12px; }
.topo-hc__t { display: flex; align-items: center; gap: 7px; font-weight: 650; font-size: 12.5px; }
.topo-hc__dot { inline-size: 8px; block-size: 8px; border-radius: 50%; flex: none; }
.topo-hc__s { color: rgba(var(--v-theme-on-surface), .55); font-size: 11px; margin: 2px 0 8px; }
.topo-hc__row { display: flex; justify-content: space-between; gap: 12px; padding: 2px 0; }
.topo-hc__row span { color: rgba(var(--v-theme-on-surface), .6); }
.topo-hc__row b { font-variant-numeric: tabular-nums; }
.topo-hc__row b.bad { color: rgb(var(--v-theme-error)); }
.topo-hc__row b.warnv { color: rgb(var(--v-theme-warning)); }
.topo-hc__bar { block-size: 5px; border-radius: 3px; background: rgba(var(--v-theme-on-surface), .12); overflow: hidden; margin: 1px 0 4px; }
.topo-hc__bar i { display: block; block-size: 100%; border-radius: 3px; }
.topo-layers { display: flex; flex-wrap: wrap; gap: 6px; }
.topo-layer { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600;
  padding: 3px 9px; border-radius: 999px; border: 1px solid transparent; }
.topo-layer.ls-up { color: rgb(var(--v-theme-success)); background: rgba(var(--v-theme-success), .1); border-color: rgba(var(--v-theme-success), .3); }
.topo-layer.ls-degraded { color: rgb(var(--v-theme-warning)); background: rgba(var(--v-theme-warning), .1); border-color: rgba(var(--v-theme-warning), .35); }
.topo-layer.ls-down { color: rgb(var(--v-theme-error)); background: rgba(var(--v-theme-error), .1); border-color: rgba(var(--v-theme-error), .35); }
.if-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.if-table th {
  position: sticky; top: 0; z-index: 1;
  background: rgb(var(--v-theme-surface));
  text-align: right; font-weight: 600; padding: 4px 8px; white-space: nowrap;
  color: rgba(var(--v-theme-on-surface), 0.6);
  border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.12);
}
.if-table td { text-align: right; padding: 4px 8px; border-bottom: 1px solid rgba(var(--v-theme-on-surface), 0.06); }
.if-table td.text-left, .if-table th.text-left { text-align: left; }
.if-table td.text-truncate { max-width: 150px; }
.if-table .bad { color: rgb(var(--v-theme-error)); font-weight: 700; }
.if-table .warnv { color: rgb(var(--v-theme-warning)); font-weight: 700; }
.dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-inline-end: 4px; vertical-align: middle; }
.dot-up { background: rgb(var(--v-theme-success)); }
.dot-down { background: rgb(var(--v-theme-error)); }
.if-x { display: inline-block; width: 7px; margin-inline-end: 4px; color: rgb(var(--v-theme-error)); font-weight: 800; font-size: 12px; line-height: 1; }
.dot-admin { background: rgba(var(--v-theme-on-surface), 0.3); }
</style>
