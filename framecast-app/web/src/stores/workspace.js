import { defineStore } from 'pinia'
import api, { setApiAccessToken } from '../services/api'
import { useAuthStore } from './auth'

export const useWorkspaceStore = defineStore('workspace', {
  state: () => ({
    workspace: null,
    usage: null,
    loading: false,
    // The agency and its client workspaces, once fetched. Null means we have
    // not asked; an empty clients array means we asked and there are none.
    clients: null,
    agency: null,
    canOwnClients: false,
    sharedCredits: null,
    switching: false,
  }),

  getters: {
    planLabel: (state) => {
      const labels = {
        free: 'Free', starter: 'Starter', creator: 'Creator', pro: 'Pro',
        agency: 'Agency', enterprise: 'Enterprise',
        // AppSumo lifetime-deal tiers
        appsumo_starter: 'Starter (Lifetime)', appsumo_creator: 'Creator (Lifetime)', appsumo_agency: 'Agency (Lifetime)',
        // legacy tier aliases
        studio: 'Studio', scale: 'Scale',
      }
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

    async loadClients() {
      try {
        const { data } = await api.get('/workspaces/clients')
        this.agency = data.data.agency
        this.clients = data.data.clients
        this.canOwnClients = Boolean(data.data.can_own_clients)
        this.sharedCredits = data.data.shared_credits
      } catch {
        // An agency-only endpoint answers 403 for everyone else; the switcher
        // simply does not appear.
        this.clients = []
        this.canOwnClients = false
      }
    },

    async createClient(name) {
      const { data } = await api.post('/workspaces/clients', { name })
      await this.loadClients()
      return data.data.client
    },

    async archiveClient(id) {
      await api.delete(`/workspaces/clients/${id}`)
      await this.loadClients()
    },

    /**
     * Move into another workspace.
     *
     * The new token carries a different workspace, so everything held in
     * memory is about to describe the wrong account. A full reload is the
     * honest way to do that: the alternative is hunting down every store and
     * cached list, and missing one means showing one client's work under
     * another client's name.
     */
    async switchTo(id) {
      if (this.switching) return
      this.switching = true
      try {
        const { data } = await api.post(`/workspaces/switch/${id}`)
        const auth = useAuthStore()
        auth.setSession({ accessToken: data.data.access_token, user: auth.user })
        setApiAccessToken(data.data.access_token)
        window.location.assign('/dashboard')
      } catch (e) {
        this.switching = false
        throw e
      }
    },

    clear() {
      this.workspace = null
      this.usage = null
      this.loading = false
      this.clients = null
      this.agency = null
      this.canOwnClients = false
      this.sharedCredits = null
    },
  },
})
