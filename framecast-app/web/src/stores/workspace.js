import { defineStore } from 'pinia'
import api from '../services/api'

export const useWorkspaceStore = defineStore('workspace', {
  state: () => ({
    workspace: null,
    usage: null,
    loading: false,
  }),

  getters: {
    planLabel: (state) => {
      const labels = { free: 'Free', studio: 'Studio', scale: 'Scale', enterprise: 'Enterprise' }
      return labels[state.workspace?.plan_tier] ?? 'Free'
    },
    planTier: (state) => state.workspace?.plan_tier ?? 'free',
    workspaceName: (state) => state.workspace?.name ?? 'My Workspace',
  },

  actions: {
    async load(workspaceId) {
      if (!workspaceId || this.loading) return
      this.loading = true
      try {
        const res = await api.get(`/workspaces/${workspaceId}`)
        this.workspace = res.data.data.workspace
        this.usage = res.data.data.usage ?? null
      } catch {
        // silent — sidebar falls back to defaults
      } finally {
        this.loading = false
      }
    },

    async updateName(workspaceId, name) {
      const res = await api.patch(`/workspaces/${workspaceId}`, { name })
      this.workspace = res.data.data.workspace
    },

    clear() {
      this.workspace = null
      this.usage = null
      this.loading = false
    },
  },
})
