import { defineStore } from 'pinia'
import { api } from '@/composables/useApi'

export interface AuthUser {
  id: number
  name: string
  email: string
  role: 'super_admin' | 'admin' | 'analyst' | 'viewer' | 'display'
  avatar?: string | null
  two_factor_enabled?: boolean
  mfa_required?: boolean
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null as AuthUser | null,
    // Set when the password passed and the authenticator step is pending.
    recoveryCodesLeft: null as number | null,
    initialized: false,
  }),
  getters: {
    isAuthenticated: state => state.user !== null,
    // super_admin is a superset of admin everywhere in the UI.
    isAdmin: state => state.user?.role === 'admin' || state.user?.role === 'super_admin',
    // Top tier — gates the OSINT tool (nav item + pages).
    isSuperAdmin: state => state.user?.role === 'super_admin',
    // Analyst or admin: may act on alarms (ack/clear/dispatch/verify) but only
    // admin may add/remove/import/change config. Viewer is read-only.
    canAct: state => ['super_admin', 'admin', 'analyst'].includes(state.user?.role ?? ''),
    // Wall-TV kiosk account: locked to the wallboard, nothing else.
    isDisplay: state => state.user?.role === 'display',
    // Signed in but MFA is enforced and this account hasn't enrolled yet — the
    // app must funnel them into two-factor setup before anything else.
    needsMfaSetup: state => state.user !== null && state.user.mfa_required === true && state.user.two_factor_enabled !== true,
  },
  actions: {
    /** @returns 'ok' when signed in, or '2fa' when a second-factor code is needed. */
    async login(email: string, password: string, code?: string, remember = false): Promise<'ok' | '2fa'> {
      const response = await api<{ user?: AuthUser, two_factor_required?: boolean, recovery_codes_left?: number }>('/api/login', {
        method: 'POST',
        body: { email, password, remember, ...(code ? { code } : {}) },
      })

      if (response.two_factor_required) {
        this.recoveryCodesLeft = response.recovery_codes_left ?? null
        return '2fa'
      }

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
