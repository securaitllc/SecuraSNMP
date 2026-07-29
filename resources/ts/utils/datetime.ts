// Massey Services operates in the US Eastern zone. Timestamps are stored in
// UTC and rendered here in America/New_York, which is DST-aware and therefore
// labels each value EDT or EST automatically.
const EASTERN = 'America/New_York'

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso)
    return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime()))
    return '—'

  return new Intl.DateTimeFormat('en-US', {
    timeZone: EASTERN,
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
  }).format(d)
}

/**
 * A timestamp shifted so a UTC-rendering chart prints Eastern wall-clock time.
 *
 * ApexCharts formats 'datetime' axes in UTC, so a graph read hours off from the
 * tables beside it, which are pinned to Eastern. Converting the instant to its
 * Eastern components and re-stamping them as UTC makes the axis and tooltip show
 * Eastern for every viewer, not just those whose laptop happens to be in that zone.
 *
 * DST-aware: the offset is resolved per timestamp, not assumed.
 */
export function easternChartMs(iso: string | number | Date | null | undefined): number {
  if (iso === null || iso === undefined)
    return Number.NaN

  const d = iso instanceof Date ? iso : new Date(iso)
  if (Number.isNaN(d.getTime()))
    return Number.NaN

  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: EASTERN,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false,
  }).formatToParts(d)

  const at = (type: string) => Number(parts.find(p => p.type === type)?.value ?? 0)

  // hour can come back as 24 at midnight in some runtimes.
  return Date.UTC(at('year'), at('month') - 1, at('day'), at('hour') % 24, at('minute'), at('second'))
}

