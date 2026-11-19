/**
 * Node glyphs for the topology canvas.
 *
 * Remix Icon paths, copied verbatim from @iconify-json/ri — the same set the rest of
 * the app draws through VIcon. They used to be hand-drawn path data, which is why an
 * appliance and a switch looked like two sketches of the same box, and why the
 * next-hop markers had no glyph at all: the map was keyed `gw` while the nodes are
 * typed `nexthop`.
 *
 * A VIcon cannot be dropped inside an <svg> canvas, so the path data is inlined here.
 * Every entry is a 24x24 FILLED path — draw with fill, not stroke, scaled from a
 * 24-unit box.
 *
 * To change one, take the path out of the icon set. Never draw a new one by hand.
 */
export const NODE_GLYPH_BOX = 24

export const nodeGlyphs: Record<string, string> = {
  // ri-cloud-line
  cloud: 'M12 2a7 7 0 0 1 6.992 7.339A6 6 0 0 1 17 21H7A6 6 0 0 1 5.008 9.339A7 7 0 0 1 12 2m0 2a5 5 0 0 0-4.994 5.243l.07 1.488l-1.404.494A4.002 4.002 0 0 0 7 19h10a4 4 0 1 0-3.796-5.265l-1.898-.633A6 6 0 0 1 17 9a5 5 0 0 0-5-5',
  // ri-signpost-line
  nexthop: 'M12 5h5.414l4.293 4.293a1 1 0 0 1 0 1.414L17.414 15H12v7h-2v-7H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6V2h2zm4.586 8l3-3l-3-3H5v6z',
  // ri-signpost-line
  gw: 'M12 5h5.414l4.293 4.293a1 1 0 0 1 0 1.414L17.414 15H12v7h-2v-7H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6V2h2zm4.586 8l3-3l-3-3H5v6z',
  // ri-router-line
  edge: 'M11 14v-3h2v3h5a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1zM2.51 8.837C3.835 4.864 7.584 2 12 2c4.418 0 8.166 2.864 9.49 6.837l-1.898.632a8.004 8.004 0 0 0-15.183 0zm3.797 1.265a6.003 6.003 0 0 1 11.387 0l-1.898.633a4.002 4.002 0 0 0-7.592 0zM7 16v4h10v-4z',
  // ri-git-merge-line
  switch: 'M7.105 8.79A3 3 0 0 0 10 11h4a5 5 0 0 1 4.927 4.146A3.001 3.001 0 0 1 18 21a3 3 0 0 1-1.105-5.79A3 3 0 0 0 14 13h-4a4.98 4.98 0 0 1-3-1v3.17a3.001 3.001 0 1 1-2 0V8.83a3.001 3.001 0 1 1 2.105-.04M6 7a1 1 0 1 0 0-2a1 1 0 0 0 0 2m0 12a1 1 0 1 0 0-2a1 1 0 0 0 0 2m12 0a1 1 0 1 0 0-2a1 1 0 0 0 0 2',
  // ri-shield-check-line
  fw: 'm12 1l8.217 1.826a1 1 0 0 1 .783.976v9.987a6 6 0 0 1-2.672 4.992L12 23l-6.328-4.219A6 6 0 0 1 3 13.79V3.802a1 1 0 0 1 .783-.976zm0 2.049L5 4.604v9.185a4 4 0 0 0 1.781 3.328L12 20.597l5.219-3.48A4 4 0 0 0 19 13.79V4.604zm4.452 5.173l1.415 1.414L11.503 16L7.26 11.757l1.414-1.414l2.828 2.828z',
  // ri-server-line
  device: 'M5 11h14V5H5zm16-7v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1m-2 9H5v6h14zM7 15h3v2H7zm0-8h3v2H7z',
  // ri-stack-line
  cluster: 'm20.083 15.2l1.202.721a.5.5 0 0 1 0 .858l-8.77 5.262a1 1 0 0 1-1.03 0l-8.77-5.262a.5.5 0 0 1 0-.858l1.202-.721L12 20.05zm0-4.7l1.202.721a.5.5 0 0 1 0 .858L12 17.649l-9.285-5.57a.5.5 0 0 1 0-.858l1.202-.721L12 15.35zm-7.569-9.191l8.771 5.262a.5.5 0 0 1 0 .858L12 12.999L2.715 7.43a.5.5 0 0 1 0-.858l8.77-5.262a1 1 0 0 1 1.03 0M12 3.332L5.887 7L12 10.668L18.113 7z',
}
