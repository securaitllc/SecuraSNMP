/**
 * Turning a failed API call into something an operator can act on.
 *
 * The editors used to `catch { errorMessage = 'Could not save…' }`, which threw the
 * real reason away: a 422 naming the exact field, a 419 meaning "your session
 * expired", a 500 meaning "this is our bug, not your input". The operator was left
 * re-checking a form that was fine. These helpers keep the specifics.
 *
 * Laravel validation failures come back as
 *   { message: "...", errors: { field: ["msg", ...] } }
 * on a 422; ofetch exposes that parsed body as `error.data`.
 */

interface ApiErrorish {
  status?: number
  statusCode?: number
  data?: { message?: string, errors?: Record<string, string[]> }
}

function statusOf(e: unknown): number | undefined {
  const err = e as ApiErrorish

  return err?.status ?? err?.statusCode
}

/** Field name → first message, e.g. { lease_end_date: 'is not a valid date' }. */
export function apiFieldErrors(e: unknown): Record<string, string> {
  const errors = (e as ApiErrorish)?.data?.errors
  if (!errors)
    return {}

  return Object.fromEntries(
    Object.entries(errors).map(([field, msgs]) => [field, Array.isArray(msgs) ? msgs[0] : String(msgs)]),
  )
}

/** 'Lease ends' from 'lease_end_date' — matches the labels on the inputs. */
export function fieldLabel(field: string): string {
  const known: Record<string, string> = {
    name: 'Name',
    site_type: 'Type',
    hub_site_ids: 'Homes to hubs',
    address: 'Address',
    latitude: 'Latitude',
    longitude: 'Longitude',
    occupancy: 'Occupancy',
    lease_end_date: 'Lease ends',
    lease_notes: 'Lease notes',
    notes: 'Notes',
  }

  return known[field] ?? field.replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())
}

/**
 * One sentence naming what actually went wrong — the fields when the server
 * rejected input, the real cause otherwise. Never the useless catch-all.
 */
export function apiErrorMessage(e: unknown, fallback = 'Could not save. Please try again.'): string {
  const status = statusOf(e)
  const fields = apiFieldErrors(e)
  const names = Object.keys(fields)

  if (names.length) {
    const listed = names.map(f => `${fieldLabel(f)} — ${fields[f]}`)

    return names.length === 1
      ? `Check ${listed[0]}`
      : `Check these fields: ${listed.join(' · ')}`
  }

  if (status === 419 || status === 401)
    return 'Your session expired. Reload the page and sign in again.'
  if (status === 403)
    return 'You do not have permission to do that.'
  if (status === 404)
    return 'That record no longer exists — it may have been deleted.'
  if (status === 500)
    return (e as ApiErrorish)?.data?.message
      ? `Server error: ${(e as ApiErrorish).data!.message}`
      : 'The server hit an error saving this. Nothing was changed — check the logs.'

  return (e as ApiErrorish)?.data?.message || fallback
}
