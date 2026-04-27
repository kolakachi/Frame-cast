import { defineStore } from 'pinia'

export const useLimitStore = defineStore('limit', {
  state: () => ({
    open: false,
    title: 'Plan limit reached',
    subtitle: 'Upgrade your plan to continue.',
    rows: [],
  }),

  actions: {
    /**
     * Open the limit modal from a limit_context error response.
     * @param {object} limitContext  The error.limit_context from the API
     */
    openFromContext(limitContext) {
      if (!limitContext) return

      // budget context: { plan, spent_usd, budget_usd }
      if ('budget_usd' in limitContext) {
        const pct = Math.min(100, Math.round((limitContext.spent_usd / limitContext.budget_usd) * 100))
        this.title = 'AI budget reached'
        this.subtitle = `Your ${limitContext.plan} plan has a $${limitContext.budget_usd} monthly AI budget. Upgrade to continue generating content this month.`
        this.rows = [{ label: 'AI Spend', used: `$${limitContext.spent_usd}`, limit: `$${limitContext.budget_usd}`, pct, color: '#fbbf24' }]
      }
      // render limit context: { plan, used, limit }
      else if ('limit' in limitContext) {
        const pct = Math.min(100, limitContext.limit ? Math.round((limitContext.used / limitContext.limit) * 100) : 100)
        const label = limitContext.used !== undefined && typeof limitContext.used === 'number' ? 'Renders' : 'Voice Minutes'
        this.title = 'Plan limit reached'
        this.subtitle = `You've used all ${limitContext.limit} available on the ${limitContext.plan} plan this month. Upgrade to continue.`
        this.rows = [{ label, used: limitContext.used, limit: limitContext.limit, pct, color: '#f87171' }]
      } else {
        this.title = 'Plan limit reached'
        this.subtitle = 'Upgrade your plan to continue.'
        this.rows = []
      }

      this.open = true
    },

    close() {
      this.open = false
    },
  },
})
