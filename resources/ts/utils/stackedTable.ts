/**
 * Turn a wide NOC table into readable cards on a phone.
 *
 * These tables are built for a 1440px wall: /circuits alone is 1086px of columns,
 * which on an iPhone means 703px of sideways scrolling to read one row. Nobody does
 * that at 2am — they give up and open a laptop.
 *
 * A table cannot restyle itself into cards on its own, because once `display: block`
 * drops the row/column grid the cells lose the header that gave them meaning. So
 * each cell carries its column title, and the CSS shows it as a label beside the
 * value. Pair this with `class="stack-sm"` on the VDataTable; the stacking itself
 * lives in styles.scss so every table stacks identically.
 *
 * Vuetify generates the <td>s, so the title is attached through cell-props rather
 * than by editing any row template — no table markup changes.
 */
export function stackedCellProps({ column }: { column: { title?: string, key?: string } }) {
  // The actions column is buttons, not a field; a label would read as a heading for
  // something that has no value.
  const isAction = column.key === 'actions' || !column.title

  return { 'data-label': isAction ? '' : (column.title ?? '') }
}

/**
 * Merge stacking with a table that already uses cell-props for something else
 * (reports colours its cells by severity, for instance).
 */
export function withStackedCellProps<T extends Record<string, unknown>>(
  existing: (ctx: any) => T,
): (ctx: any) => T & { 'data-label': string } {
  return ctx => ({ ...existing(ctx), ...stackedCellProps(ctx) })
}
