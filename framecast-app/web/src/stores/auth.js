import { defineStore } from 'pinia'
import api, { refreshApi } from '../services/api'

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
      this.accessToken = null
      this.user = null
      window.localStorage.removeItem(STORAGE_KEY)
    },

    async requestMagicLink(email, name = null, password = null) {
      await api.post('/auth/magic-link', {
        email,
        ...(name ? { name } : {}),
        ...(password ? { password } : {}),
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
