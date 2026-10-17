<script setup>
// Customer economics (owner, 2026-10-11, cost tracking step 3): what each customer paid us, after the platform's cut and
// refunds, against what their videos cost us. Costs are real from data.full_cost_since; earlier ones are estimates.
import { computed, onMounted, ref } from 'vue'
import api from '../../services/api'

const data = ref(null), loading = ref(false), error = ref(''), days = ref(''), paidOnly = ref(true), sort = ref('money_in_usd')
const usd = n => (n < 0 ? '−$' : '$') + Math.abs(Number(n || 0)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const rows = computed(() => [...(data.value?.rows || [])].sort((a, b) => (b[sort.value] ?? 0) - (a[sort.value] ?? 0)))
const LABELS = { appsumo: 'AppSumo', affiliate: 'Affiliate', google_ads: 'Google Ads', referral: 'Friend referral', paid_social: 'Paid social', email: 'Email',
  outreach: 'Outreach', community: 'Community', listing: 'Listing', ai: 'AI assistant', search: 'Search', social: 'Social', other_site: 'Other site', direct: 'Direct', unknown: 'Unknown' }
const label = c => LABELS[c] || c || '—'
const since = computed(() => data.value ? new Date(data.value.full_cost_since.replace(' ', 'T') + 'Z').toLocaleDateString() : '')

async function load() {
  loading.value = true; error.value = ''
  try { const { data: d } = await api.get('/admin/economics', { params: { days: days.value || undefined, paid_only: paidOnly.value ? 1 : 0 } }); data.value = d.data }
  catch (e) { error.value = e?.response?.data?.error?.message || 'Could not load customer economics.' }
  finally { loading.value = false }
}
onMounted(load)
</script>

<template>
<section class="econ">
  <header class="econ-head">
    <div>
      <h2>Customer economics</h2>
      <p class="econ-sub">Money in (after AppSumo's cut and refunds) against what each customer's videos cost us. Costs are real from {{ since }}; before that they are estimates from the credit ledger.</p>
    </div>
    <div class="econ-tools">
      <select v-model="days" aria-label="Period" @change="load"><option value="">All time</option><option value="90">Last 90 days</option><option value="30">Last 30 days</option></select>
      <label><input v-model="paidOnly" type="checkbox" @change="load" /> Paying customers only</label>
    </div>
  </header>
  <p v-if="error" class="econ-error" role="alert">{{ error }}</p>
  <p v-if="loading && !data" class="econ-note">Loading…</p>
  <template v-if="data">
    <div class="econ-cards">
      <div><small>Money in</small><b>{{ usd(data.totals.money_in_usd) }}</b><span>{{ data.totals.paying_workspaces }} paying</span></div>
      <div><small>Cost (real)</small><b>{{ usd(data.totals.cost_recorded_usd) }}</b><span>+ {{ usd(data.totals.cost_earlier_estimate_usd) }} estimated earlier</span></div>
      <div><small>Margin</small><b :class="data.totals.margin_usd < 0 ? 'neg' : 'pos'">{{ usd(data.totals.margin_usd) }}</b><span>money in − all cost</span></div>
      <div><small>Refunded</small><b>{{ usd(data.totals.refunded_usd) }}</b><span>reached customers back</span></div>
      <div><small>Unattributed cost</small><b>{{ usd(data.unattributed_cost_usd) }}</b><span>our own tools and scripts</span></div>
    </div>

    <h3 class="econ-h3">Where accounts came from</h3>
    <p class="econ-sub">Accounts that signed up {{ days ? `in the last ${days} days` : 'ever' }}, by where they first came from; paying and money in are theirs to date. "Unknown" is accounts from before 11 Oct 2026, when this started being recorded.</p>
    <div class="econ-scroll"><table class="econ-table">
      <thead><tr><th>Came from</th><th class="num">Sign-ups</th><th class="num">Paying</th><th class="num">Conversion</th><th class="num">Money in</th></tr></thead>
      <tbody><tr v-for="c in data.by_source || []" :key="c.channel"><td>{{ label(c.channel) }}</td><td class="num">{{ c.signups }}</td><td class="num">{{ c.paying }}</td><td class="num">{{ c.conversion_pct }}%</td><td class="num">{{ usd(c.money_in_usd) }}</td></tr></tbody>
    </table></div>

    <h3 class="econ-h3">Downloads</h3>
    <p class="econ-sub">Finished videos saved {{ days ? `in the last ${days} days` : 'since 15 Oct 2026, when this started being recorded' }}: from where, and whether in the app or through the API. Playing a video is not a download.</p>
    <div class="econ-scroll"><table class="econ-table">
      <thead><tr><th>From</th><th>How</th><th class="num">Downloads</th><th class="num">Workspaces</th></tr></thead>
      <tbody>
        <tr v-for="d in data.downloads || []" :key="d.source + d.via"><td>{{ { create: 'Create', ugc: 'UGC', editor: 'Classic editor' }[d.source] || d.source }}</td><td>{{ d.via === 'api' ? 'API / assistant' : 'App' }}</td><td class="num">{{ d.downloads }}</td><td class="num">{{ d.workspaces }}</td></tr>
        <tr v-if="!(data.downloads || []).length"><td colspan="4" class="econ-note">No downloads recorded in this period.</td></tr>
      </tbody>
    </table></div>

    <h3 class="econ-h3">Sales by channel</h3>
    <div class="econ-scroll"><table class="econ-table">
      <thead><tr><th>Channel</th><th>Sales</th><th>Refunded</th><th class="num">Money in</th></tr></thead>
      <tbody><tr v-for="c in data.by_channel" :key="c.channel"><td>{{ label(c.channel) }}</td><td>{{ c.sales }}</td><td>{{ c.refunded_sales }}</td><td class="num">{{ usd(c.money_in_usd) }}</td></tr></tbody>
    </table></div>

    <h3 class="econ-h3">By customer</h3>
    <div class="econ-scroll"><table class="econ-table">
      <thead><tr>
        <th>Workspace</th><th>Channel</th><th>Plan</th>
        <th class="num"><button @click="sort = 'money_in_usd'">Money in</button></th>
        <th class="num"><button @click="sort = 'refunded_usd'">Refunded</button></th>
        <th class="num"><button @click="sort = 'cost_recorded_usd'">Cost</button></th>
        <th class="num"><button @click="sort = 'margin_usd'">Margin</button></th>
        <th class="num"><button @click="sort = 'credits_used'">Credits used</button></th>
        <th class="num"><button @click="sort = 'downloads'">Downloads</button></th>
      </tr></thead>
      <tbody>
        <tr v-for="r in rows" :key="r.workspace_id">
          <td><b>{{ r.name }}</b><small>#{{ r.workspace_id }}<template v-if="r.owner_email"> · {{ r.owner_email }}</template><template v-if="r.client_of"> · client of #{{ r.client_of }}</template></small></td>
          <td>{{ label(r.acquisition) }}<small v-if="r.acquisition_detail">{{ r.acquisition_detail }}</small><small v-if="r.channels.length && r.channels.join() !== r.acquisition">sales: {{ r.channels.map(label).join(', ') }}</small></td>
          <td>{{ r.plan || '—' }}</td>
          <td class="num">{{ usd(r.money_in_usd) }}<small v-if="r.platform_fees_usd">paid {{ usd(r.paid_usd) }}, cut {{ usd(r.platform_fees_usd) }}</small><small v-if="r.unpriced_sales" class="warn">{{ r.unpriced_sales }} sale(s) not in USD</small></td>
          <td class="num">{{ r.refunded_usd ? usd(r.refunded_usd) : '—' }}</td>
          <td class="num">{{ usd(r.cost_recorded_usd) }}<small v-if="r.cost_earlier_estimate_usd">+ {{ usd(r.cost_earlier_estimate_usd) }} est.</small></td>
          <td class="num" :class="r.margin_usd < 0 ? 'neg' : 'pos'">{{ usd(r.margin_usd) }}</td>
          <td class="num">{{ r.credits_used.toLocaleString() }}<small>of {{ r.credits_granted.toLocaleString() }} granted</small></td>
          <td class="num">{{ r.downloads ?? 0 }}</td>
        </tr>
        <tr v-if="!rows.length"><td colspan="9" class="econ-note">Nothing in this period.</td></tr>
      </tbody>
    </table></div>
  </template>
</section>
</template>

<style scoped>
.econ { display: grid; gap: 18px; }
.econ-head { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; align-items: flex-end; }
.econ-head h2 { margin: 0; }
.econ-sub { margin: 6px 0 0; color: var(--color-text-secondary, #a0a0ae); font-size: 13px; max-width: 70ch; }
.econ-tools { display: flex; gap: 12px; align-items: center; font-size: 13px; }
.econ-tools select { font: inherit; padding: 6px 10px; border-radius: 8px; border: 1px solid var(--color-border-active, #33333f); background: var(--color-bg-soft, #15151d); color: inherit; }
.econ-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.econ-cards div { border: 1px solid var(--color-border, #23232f); border-radius: 12px; padding: 12px 14px; display: grid; gap: 2px; }
.econ-cards small { font-size: 11.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--color-text-secondary, #a0a0ae); }
.econ-cards b { font-size: 22px; font-variant-numeric: tabular-nums; }
.econ-cards span { font-size: 12px; color: var(--color-text-secondary, #a0a0ae); }
.econ-h3 { margin: 6px 0 0; font-size: 15px; }
.econ-scroll { overflow-x: auto; }
.econ-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.econ-table th, .econ-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--color-border, #23232f); vertical-align: top; }
.econ-table th { font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-secondary, #a0a0ae); }
.econ-table th button { all: unset; cursor: pointer; }
.econ-table td small { display: block; font-size: 11.5px; color: var(--color-text-secondary, #a0a0ae); }
.num { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
.neg { color: #ff8a65; } .pos { color: #6fd3a0; } .warn { color: #f5c451 !important; }
.econ-note, .econ-error { font-size: 13px; color: var(--color-text-secondary, #a0a0ae); }
.econ-error { color: #ff8a65; }
</style>
