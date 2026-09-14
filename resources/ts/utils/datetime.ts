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


/**
 * Eastern wall-clock <-> instant, for `<input type="datetime-local">`.
 *
 * A datetime-local input has NO timezone: it hands back a naive "2026-09-01T08:00".
 * Posting that raw made the API (app timezone UTC) read it as 08:00 UTC, so a
 * dispatch typed as 09:00 came back as 05:00 EDT — every save silently walked the
 * time four hours backwards.
 *
 * Every timestamp this app SHOWS is Eastern (see formatDateTime), so a time typed
 * into it means Eastern too, whatever zone the operator's laptop is in. These two
 * convert on that contract, DST-aware, resolving the offset for the date in question
 * rather than assuming one.
 */
export function easternInputToIso(value: string | null | undefined): string | null {
  if (!value)
    return null

  const m = value.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/)
  if (!m)
    return null

  const [, y, mo, d, h, mi] = m.map(Number) as unknown as number[]

  // Treat the typed parts as UTC first, then subtract Eastern's offset at that
  // moment. Resolved twice so a value inside the DST switch lands on the right side.
  const naive = Date.UTC(y, mo - 1, d, h, mi)
  let instant = naive - (easternChartMs(naive) - naive)
  instant = naive - (easternChartMs(instant) - instant)

  return new Date(instant).toISOString()
}

/** An instant -> the "YYYY-MM-DDTHH:mm" a datetime-local shows, in Eastern. */
export function easternInputValue(iso: string | null | undefined): string {
  if (!iso)
    return ''

  const t = Date.parse(iso)
  if (Number.isNaN(t))
    return ''

  // easternChartMs re-stamps the Eastern components as UTC, so the UTC accessors
  // below read back exactly the wall-clock numbers Eastern would show.
  const d = new Date(easternChartMs(t))
  const p = (n: number) => String(n).padStart(2, '0')

  return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}-${p(d.getUTCDate())}T${p(d.getUTCHours())}:${p(d.getUTCMinutes())}`
}
