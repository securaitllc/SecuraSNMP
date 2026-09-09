import { computed } from 'vue'
import { useTheme } from 'vuetify'

/**
 * Reactive ApexCharts theme mode that follows the app's light/dark theme. Add
 * `theme: { mode: chartMode.value }` to a chart's (computed) options so axis
 * labels, legend and tooltip text stay legible in dark mode.
 */
export function useChartMode() {
  const theme = useTheme()

  return computed<'dark' | 'light'>(() => (theme.global.current.value.dark ? 'dark' : 'light'))
}
