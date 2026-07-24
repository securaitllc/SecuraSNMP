import { defineStore } from 'pinia'
import { api } from '@/composables/useApi'

export interface AuthUser {
  id: number
  name: string
  email: string
  role: 'admin' | 'analyst' | 'viewer'
  avatar?: string | null
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
  },
  actions: {
    async login(email: string, password: string) {
      const response = await api<{ user: AuthUser }>('/api/login', {
        method: 'POST',
        body: { email, password },
      })

      this.user = response.user
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
