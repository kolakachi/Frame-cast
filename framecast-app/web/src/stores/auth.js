import { defineStore } from 'pinia'
import api from '../services/api'

const STORAGE_KEY = 'framecast.auth'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    accessToken: null,
    user: null,
  }),

  getters: {
    isAuthenticated: (state) => Boolean(state.accessToken),
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

    async requestMagicLink(email) {
      await api.post('/auth/magic-link', { email })
    },

    async refreshAccessToken() {
      const response = await api.post('/auth/refresh')
      const accessToken = response.data?.data?.access_token ?? null

      this.setSession({
        accessToken,
        user: response.data?.data?.user ?? this.user,
      })

      return accessToken
    },
  },
})
