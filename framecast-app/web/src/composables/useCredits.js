import { computed } from 'vue'

export function useCredits(mePayload) {
  const credits = computed(() => mePayload?.value?.credits ?? null)
  const balance = computed(() => credits.value?.balance ?? 0)
  const monthly = computed(() => credits.value?.credits_monthly ?? 0)
  const topup   = computed(() => credits.value?.credits_topup ?? 0)
  const planAlloc = computed(() => credits.value?.plan_monthly_allocation ?? 0)
  const renewsAt  = computed(() => credits.value?.billing_renews_at ?? null)

  const pct = computed(() => {
    if (!planAlloc.value) return null
    return Math.round((monthly.value / planAlloc.value) * 100)
  })

  const low = computed(() => planAlloc.value > 0 && pct.value !== null && pct.value <= 20)
  const exhausted = computed(() => balance.value <= 0)

  function canAfford(amount) {
    return balance.value >= amount
  }

  return { balance, monthly, topup, planAlloc, renewsAt, pct, low, exhausted, canAfford }
}
