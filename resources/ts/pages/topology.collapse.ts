import type { Topology, TopologyNode } from '@/types/models'

/** Worst status across a set (down beats warn beats up). */
export function worstStatus(list: { status: string }[]): 'up' | 'warn' | 'down' {
  if (list.some(n => n.status === 'down'))
    return 'down'
  if (list.some(n => n.status === 'warn'))
    return 'warn'
  return 'up'
}

/**
 * Fold each core's fan of leaf access switches into one cluster card when the fan
 * is dense (≥ min) and not individually expanded. Pure — the Vue layer wires refs
 * to it. A leaf access switch is type 'switch' with nothing below it (no higher-col
 * neighbour) that homes to exactly one uplink; leaves are grouped by that uplink.
 */
export function collapseTopology(
  t: Topology,
  opts: { enabled: boolean, expanded: Set<string>, min: number },
): Topology {
  if (!opts.enabled)
    return t

  const colOf: Record<string, number> = Object.fromEntries(t.nodes.map(n => [n.id, n.col]))
  const nbrs: Record<string, string[]> = {}
  for (const e of t.edges) {
    if (e.overlay || e.ha)
      continue
    ;(nbrs[e.from] ??= []).push(e.to)
    ;(nbrs[e.to] ??= []).push(e.from)
  }

  const groups: Record<string, TopologyNode[]> = {}
  for (const n of t.nodes) {
    if (n.type !== 'switch')
      continue
    const ns = nbrs[n.id] ?? []
    if (ns.some(o => (colOf[o] ?? 0) > n.col))
      continue
    const up = ns.filter(o => (colOf[o] ?? 99) < n.col)
    if (up.length !== 1)
      continue
    ;(groups[up[0]] ??= []).push(n)
  }

  const removed = new Set<string>()
  const clusters: TopologyNode[] = []
  const clusterEdges: Topology['edges'] = []
  for (const [parent, kids] of Object.entries(groups)) {
    const cid = `clu-${parent}`
    if (kids.length < opts.min || opts.expanded.has(cid))
      continue
    kids.forEach(k => removed.add(k.id))
    const down = kids.filter(k => k.status === 'down').length
    const warn = kids.filter(k => k.status === 'warn').length
    const utils = kids.map(k => k.util).filter((u): u is number => u != null)
    const status = worstStatus(kids)
    const up = kids.length - down - warn
    const parts = [down ? `${down} down` : '', warn ? `${warn} degraded` : '', up ? `${up} up` : ''].filter(Boolean)
    clusters.push({
      id: cid, type: 'cluster', col: kids[0].col, label: `${kids.length} access switches`,
      sub: parts.join(' · '), status, ip: null, model: null, role: 'Access cluster',
      util: utils.length ? Math.max(...utils) : null, count: kids.length,
      members: kids.map(k => ({ id: k.id, label: k.label, status: k.status, util: k.util ?? null })),
    } as TopologyNode)
    clusterEdges.push({ from: parent, to: cid, label: `${kids.length}×`, status: status === 'down' ? 'down' : 'up' } as Topology['edges'][number])
  }
  if (!removed.size)
    return t

  return {
    ...t,
    nodes: [...t.nodes.filter(n => !removed.has(n.id)), ...clusters],
    edges: [...t.edges.filter(e => !removed.has(e.from) && !removed.has(e.to)), ...clusterEdges],
  }
}
