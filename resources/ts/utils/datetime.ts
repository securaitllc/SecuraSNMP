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
