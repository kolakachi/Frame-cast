import { defineStore } from 'pinia'
import api, { refreshApi } from '../services/api'
import { useWorkspaceStore } from './workspace'

const STORAGE_KEY = 'framecast.auth'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    accessToken: null,
    user: null,
  }),

  getters: {
    isAuthenticated: (state) => Boolean(state.accessToken),
    isOnboarded: (state) => Boolean(state.user?.preferences?.onboarded),
  },

  actions: {
    hydrate() {
      const stored = window.localStorage.getItem(STORAGE_KEY)

      if (!stored) {
        return
      }

      try {
        const parsed = JSON.parse(stored)
        this.accessToken = parsed.accessToken ?? null
        this.user = parsed.user ?? null
      } catch {
        this.clearSession()
      }
    },

    persist() {
      window.localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          accessToken: this.accessToken,
          user: this.user,
        }),
      )
    },

    setSession(payload) {
      this.accessToken = payload.accessToken ?? null
      this.user = payload.user ?? null
      this.persist()
    },

    clearSession() {
      // A logout ends any impersonation with it — otherwise the banner (and
      // the stashed admin token) would leak into the next login.
      window.localStorage.removeItem('framecast.impersonating')
      window.localStorage.removeItem('framecast.admin_return')
      this.accessToken = null
      this.user = null
      window.localStorage.removeItem(STORAGE_KEY)
      // Drop workspace-scoped state too, so logging in as a different user
      // doesn't inherit the previous workspace's name/plan/usage in the
      // sidebar until something forces a refetch.
      useWorkspaceStore().clear()
    },

    async requestMagicLink(email, name = null, password = null, plan = null) {
      await api.post('/auth/magic-link', {
        email,
        ...(name ? { name } : {}),
        ...(password ? { password } : {}),
        // Passed so the sign-in email can name the plan they picked. The key
        // only — the wording comes from the server.
        ...(plan ? { plan } : {}),
      })
    },

    async login(email, password) {
      const response = await api.post('/auth/login', { email, password })
      this._applySessionData(response.data.data)
    },

    async verifyMagicLink(token) {
      const response = await api.get('/auth/magic-link/verify', { params: { token } })
      this._applySessionData(response.data.data)
    },

    async logout() {
      try {
        await api.post('/auth/logout')
      } finally {
        this.clearSession()
      }
    },

    async refreshAccessToken(client = refreshApi) {
      const response = await client.post('/auth/refresh')
      const accessToken = response.data?.data?.access_token ?? null

      this.setSession({
        accessToken,
        user: response.data?.data?.user ?? this.user,
      })

      return accessToken
    },

    /**
     * Re-read the signed-in user from the API and merge it into the cached
     * session.
     *
     * The stored user is written at sign-in and only refreshed when the access
     * token is, so a session that predates a newly added field never sees it.
     * That is not cosmetic: a route guard reading such a field treats "missing"
     * as "false" and turns the user away from a page they can use. Callers that
     * gate on a user flag should ensure it exists before deciding.
     *
     * Returns the user, or null when the call fails — the caller decides what
     * an unknown answer means.
     */
    async refreshUser() {
      try {
        const response = await api.get('/me')
        const user = response.data?.data?.user
        if (!user) return null
        this.user = { ...(this.user ?? {}), ...user }
        this.persist()
        return this.user
      } catch {
        return null
      }
    },

    markOnboarded() {
      if (!this.user) return
      this.user = { ...this.user, preferences: { ...(this.user.preferences ?? {}), onboarded: true } }
      this.persist()
    },

    _applySessionData(data) {
      this.setSession({
        accessToken: data.access_token ?? null,
        user: data.user ?? null,
      })
    },
  },
})
