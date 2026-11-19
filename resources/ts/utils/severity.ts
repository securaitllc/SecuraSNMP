/**
 * The severity palette. One definition, for the whole app.
 *
 * Colour in Nodus means severity and nothing else, which only works if a given
 * severity is one colour. It was four: red arrived as #E5484D from the theme,
 * #EF4444 on circuit charts, #F87171 on the wallboard and #FF4D49 — the old
 * template's error — on a contract annotation. Amber and green were three each,
 * and `theme.ts` itself disagreed with the documented palette on both. Side by
 * side on a wall display that reads as different states, not one state drawn by
 * different people.
 *
 * These values ARE the ones in `theme.ts`'s dark theme — the one the NOC and the
 * wallboard run. Charts cannot take a CSS variable, so they take the constant
 * instead of a hand-typed guess at it; anything rendered by Vuetify keeps using
 * the theme token (`color="error"`, `text-error`) and resolves to the same hex.
 * The light theme is deliberately left on its own values.
 *
 * Auto-imported — `resources/ts/utils` is in the AutoImport dirs, so these are
 * available in any page or component without an import statement.
 */

/** Down / service-affecting. */
export const SEV_CRITICAL = '#E5484D'

/** Degraded but up. */
export const SEV_WARNING = '#F5A623'

/** Healthy. */
export const SEV_OK = '#2BA24E'

/** Informational, and the one accent used for actions and the active nav item. */
export const SEV_INFO = '#4C8DFF'

/** Unknown / not measured. Never green: absence of a signal is not health. */
export const SEV_UNKNOWN = '#6B7482'

/**
 * Severity name to colour, for the chart and canvas paths that need a literal.
 */
export const SEVERITY_COLORS: Record<string, string> = {
  critical: SEV_CRITICAL,
  error: SEV_CRITICAL,
  down: SEV_CRITICAL,
  warning: SEV_WARNING,
  degraded: SEV_WARNING,
  success: SEV_OK,
  ok: SEV_OK,
  up: SEV_OK,
  info: SEV_INFO,
  unknown: SEV_UNKNOWN,
}

/** Colour for a severity/status name, falling back to unknown rather than to healthy. */
export function severityHex(name?: string | null): string {
  return SEVERITY_COLORS[String(name ?? '').toLowerCase()] ?? SEV_UNKNOWN
}
