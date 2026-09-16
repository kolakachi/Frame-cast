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
    maxClients: 50,
    sharedCredits: null,
    // Per-client spend for the window below, keyed by workspace id. Null until
    // asked, so "no spend yet" and "not loaded" stay different answers.
    clientUsage: null,
    clientUsageDays: 30,
    loadFailed: false,
    switching: false,
  }),

  getters: {
    /**
     * The plan to show, or null when we do not yet know.
     *
     * Null matters: this used to fall back to 'Free', so a workspace that had
     * not loaded — or whose fetch 404'd — told a paying customer they were on
     * the free plan, directly contradicting the billing panel one click away.
     * Not knowing and being free are different facts and must render
     * differently.
     */
    planLabel: (state) => {
      const labels = {
        free: 'Free', starter: 'Starter', creator: 'Creator', pro: 'Pro',
        agency: 'Agency', enterprise: 'Enterprise',
        // AppSumo lifetime-deal tiers
        appsumo_starter: 'Starter (Lifetime)', appsumo_creator: 'Creator (Lifetime)', appsumo_agency: 'Agency (Lifetime)',
        // Bought one-time through Kelviq. Their absence here was why a
        // lifetime customer's sidebar read 'Free Plan'.
        lifetime_starter: 'Starter (Lifetime)', lifetime_creator: 'Creator (Lifetime)', lifetime_agency: 'Agency (Lifetime)',
        // legacy tier aliases
        studio: 'Studio', scale: 'Scale',
      }
      const tier = state.workspace?.plan_tier
      if (!tier) return null
      // An unrecognised tier is still not free — name it rather than lie.
      return labels[tier] ?? tier.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    },
    planTier: (state) => state.workspace?.plan_tier ?? null,
    workspaceName: (state) => state.workspace?.name ?? 'My Workspace',
  },

  actions: {
    async load(workspaceId) {
      if (!workspaceId || this.loading) return
      this.loading = true
      try {
        const res = await api.get(`/workspaces/${workspaceId}`)
        this.loadFailed = false
        this.workspace = res.data.data.workspace
        this.usage = res.data.data.usage ?? null
      } catch {
        // Kept quiet on purpose — a sidebar is not the place to shout about a
        // failed fetch — but recorded, so the getters can decline to name a
        // plan instead of guessing at one.
        this.loadFailed = true
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
        this.maxClients = data.data.max_clients ?? 50
        this.sharedCredits = data.data.shared_credits
      } catch {
        // An agency-only endpoint answers 403 for everyone else; the switcher
        // simply does not appear.
        this.clients = []
        this.canOwnClients = false
      }
    },

    /**
     * Who spent the shared pool. The balance is one number for the whole
     * agency; this is the only place that says which client it went on.
     */
    async loadClientUsage(days = 30) {
      try {
        const { data } = await api.get('/workspaces/clients/usage', { params: { days } })
        const byId = {}
        for (const row of data.data.usage ?? []) byId[row.workspace_id] = row
        this.clientUsage = byId
        this.clientUsageDays = data.data.days ?? days
      } catch {
        this.clientUsage = {}
      }
    },

    async inviteViewer(clientId, email, role = 'client') {
      const { data } = await api.post(`/workspaces/clients/${clientId}/viewers`, { email, role })
      await this.loadClients()
      return data.data.viewer
    },

    async fundClient(clientId, amount) {
      const { data } = await api.post(`/workspaces/clients/${clientId}/credits`, { amount })
      await this.loadClients()
      return data.data.client
    },

    async unfundClient(clientId) {
      await api.delete(`/workspaces/clients/${clientId}/credits`)
      await this.loadClients()
    },

    async loadViewers(clientId, { page = 1, q = '' } = {}) {
      const { data } = await api.get(`/workspaces/clients/${clientId}/viewers`, { params: { page, q } })
      return { viewers: data.data.viewers, pagination: data.meta.pagination }
    },

    async setViewerRole(clientId, userId, role) {
      await api.patch(`/workspaces/clients/${clientId}/viewers/${userId}`, { role })
    },

    async setCap(clientId, cap) {
      await api.patch(`/workspaces/clients/${clientId}`, { monthly_credit_cap: cap })
      await this.loadClients()
    },

    async removeViewer(clientId, userId) {
      await api.delete(`/workspaces/clients/${clientId}/viewers/${userId}`)
      await this.loadClients()
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
