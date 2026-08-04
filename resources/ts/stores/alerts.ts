import { defineStore } from 'pinia'
import { api } from '@/composables/useApi'
import type { DashboardAlert, DashboardSiteHealth, DashboardSummary } from '@/types/models'

// A single shared poll of the dashboard feed, so the header alarm bell, the tab
// title/favicon, and the Sites page all read one source instead of each polling.
export const useAlertsStore = defineStore('alerts', {
  state: () => ({
    alerts: [] as DashboardAlert[],
    sites: [] as DashboardSiteHealth[],
    counts: null as DashboardSummary['counts'] | null,
    loaded: false,
    _timer: null as ReturnType<typeof setInterval> | null,
  }),
  getters: {
    activeCount: state => state.counts?.active_alerts ?? 0,
    criticalCount: state => state.alerts.filter(a => a.severity === 'critical').length,
    // site id -> active alert count, for the Sites row highlight.
    siteAlertMap: (state): Record<number, number> =>
      Object.fromEntries(state.sites.map(s => [s.id, s.active_alert_count])),
    impactedSites: state => state.sites.filter(s => s.active_alert_count > 0).length,
  },
  actions: {
    async refresh() {
      try {
        const d = await api<DashboardSummary>('/api/dashboard')
        this.alerts = d.alerts
        this.sites = d.sites
        this.counts = d.counts
        this.loaded = true
      }
      catch {
        // Keep the last known feed on a transient failure.
      }
    },
    startPolling(ms = 30000) {
      if (this._timer)
        return
      this.refresh()
      this._timer = setInterval(() => this.refresh(), ms)
    },
    stopPolling() {
      if (this._timer) {
        clearInterval(this._timer)
        this._timer = null
      }
    },
  },
})
