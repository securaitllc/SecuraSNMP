import { defineStore } from 'pinia'
import { api } from '@/composables/useApi'

export interface AuthUser {
  id: number
  name: string
  email: string
  role: 'admin' | 'analyst' | 'viewer'
  avatar?: string | null
  two_factor_enabled?: boolean
  mfa_required?: boolean
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as AuthUser | null,
    initialized: false,
  }),
  getters: {
    isAuthenticated: state => state.user !== null,
    isAdmin: state => state.user?.role === 'admin',
    // Analyst or admin: may act on alarms (ack/clear/dispatch/verify) but only
    // admin may add/remove/import/change config. Viewer is read-only.
    canAct: state => state.user?.role === 'admin' || state.user?.role === 'analyst',
    // Signed in but MFA is enforced and this account hasn't enrolled yet — the
    // app must funnel them into two-factor setup before anything else.
    needsMfaSetup: state => state.user !== null && state.user.mfa_required === true && state.user.two_factor_enabled !== true,
  },
  actions: {
    /** @returns 'ok' when signed in, or '2fa' when a second-factor code is needed. */
    async login(email: string, password: string, code?: string): Promise<'ok' | '2fa'> {
      const response = await api<{ user?: AuthUser, two_factor_required?: boolean }>('/api/login', {
        method: 'POST',
        body: { email, password, ...(code ? { code } : {}) },
      })

      if (response.two_factor_required)
        return '2fa'

      this.user = response.user ?? null

      return 'ok'
    },
    async logout() {
      await api('/api/logout', { method: 'POST' })
      this.user = null
    },
    async fetchUser() {
      try {
        this.user = await api<AuthUser>('/api/user')
      }
      catch {
        this.user = null
      }
      finally {
        this.initialized = true
      }
    },
  },
})
