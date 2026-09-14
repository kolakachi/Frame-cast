<script setup>
import axios from 'axios'
import { computed, onMounted, ref } from 'vue'

// A dedicated client. The shared one carries the signed-in user's bearer token
// and refreshes it on a 401 — an affiliate is not a user, and reusing it would
// both leak a user session into these calls and set off a refresh loop.
const baseURL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000'
const portal = axios.create({
  baseURL: `${baseURL}/api/v1/affiliate/portal`,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})

const STORE_KEY = 'wyv_affiliate_session'

const token = ref('')
const affiliate = ref(null)
const totals = ref(null)
const lastPayout = ref(null)
const days = ref([])
const payouts = ref([])
const form = ref({ code: '', key: '' })
const error = ref('')
const loading = ref(false)
const booting = ref(true)
const range = ref(30)
const copied = ref(false)

const signedIn = computed(() => Boolean(token.value && affiliate.value))

// Charts are drawn against the busiest day, so a quiet week still reads as a
// quiet week rather than being rescaled into looking busy.
const peakClicks = computed(() => Math.max(1, ...days.value.map((d) => d.clicks)))
const activeDays = computed(() => days.value.filter((d) => d.clicks || d.sales).slice().reverse())

function money(n) {
  return `$${Number(n ?? 0).toFixed(2)}`
}

function authHeader() {
  return { headers: { Authorization: `Bearer ${token.value}` } }
}

function persist(value) {
  try {
    if (value) localStorage.setItem(STORE_KEY, value)
    else localStorage.removeItem(STORE_KEY)
  } catch {
    // A browser refusing storage costs a re-login, nothing more.
  }
}

async function signIn() {
  error.value = ''
  loading.value = true
  try {
    const { data } = await portal.post('/login', {
      code: form.value.code.trim(),
      key: form.value.key.trim(),
    })
    token.value = data.data.token
    affiliate.value = data.data.affiliate
    persist(token.value)
    form.value.key = ''
    await load()
  } catch (e) {
    error.value = e.response?.data?.error?.message ?? 'Could not sign in. Check the code and key.'
  } finally {
    loading.value = false
  }
}

async function signOut() {
  try {
    await portal.post('/logout', {}, authHeader())
  } catch {
    // Signing out locally is what matters; the session expires regardless.
  }
  token.value = ''
  affiliate.value = null
  totals.value = null
  days.value = []
  payouts.value = []
  persist(null)
}

async function load() {
  if (!token.value) return
  loading.value = true
  error.value = ''
  const to = new Date()
  const from = new Date(to.getTime() - (range.value - 1) * 86400000)
  const iso = (d) => d.toISOString().slice(0, 10)
  try {
    const [summary, daily, paid] = await Promise.all([
      portal.get('/summary', authHeader()),
      portal.get('/daily', { params: { from: iso(from), to: iso(to) }, ...authHeader() }),
      portal.get('/payouts', authHeader()),
    ])
    affiliate.value = summary.data.data.affiliate
    totals.value = summary.data.data.totals
    lastPayout.value = summary.data.data.last_payout
    days.value = daily.data.data.days
    payouts.value = paid.data.data.payouts
  } catch (e) {
    if (e.response?.status === 401) {
      // The session ended — expired, revoked, or the account was paused.
      token.value = ''
      affiliate.value = null
      persist(null)
      error.value = 'Your session has ended. Sign in again.'
    } else {
      error.value = 'Could not load your figures. Try again in a moment.'
    }
  } finally {
    loading.value = false
    booting.value = false
  }
}

function setRange(days_) {
  range.value = days_
  load()
}

async function copyLink() {
  try {
    await navigator.clipboard?.writeText(affiliate.value.link)
    copied.value = true
    setTimeout(() => { copied.value = false }, 1600)
  } catch {
    error.value = `Could not copy — your link is ${affiliate.value.link}`
  }
}

onMounted(async () => {
  try {
    token.value = localStorage.getItem(STORE_KEY) ?? ''
  } catch {
    token.value = ''
  }
  if (token.value) await load()
  else booting.value = false
})
</script>

<template>
  <div class="ap">
    <header class="ap-top">
      <div class="ap-brand">WyvStudio <span>Affiliates</span></div>
      <button v-if="signedIn" class="ap-btn ap-btn-ghost" @click="signOut">Sign out</button>
    </header>

    <div v-if="booting" class="ap-center">Loading…</div>

    <!-- Sign in -->
    <main v-else-if="!signedIn" class="ap-center">
      <form class="ap-card ap-login" @submit.prevent="signIn">
        <h1>Your referral dashboard</h1>
        <p class="ap-muted">
          Sign in with the code and access key we sent you. Your code appears in
          your links; the key does not — keep it to yourself.
        </p>

        <label>
          Referral code
          <input v-model="form.code" autocomplete="username" placeholder="e.g. k7m2xq9p" required />
        </label>
        <label>
          Access key
          <input v-model="form.key" type="password" autocomplete="current-password" placeholder="xxxxx-xxxxx-xxxxx-xxxxx" required />
        </label>

        <div v-if="error" class="ap-error">{{ error }}</div>

        <button class="ap-btn ap-btn-primary" type="submit" :disabled="loading">
          {{ loading ? 'Checking…' : 'Sign in' }}
        </button>
        <p class="ap-fineprint">Lost your key? Reply to the email we sent and we will issue a new one.</p>
      </form>
    </main>

    <!-- Dashboard -->
    <main v-else class="ap-main">
      <div class="ap-hello">
        <div>
          <h1>{{ affiliate.name }}</h1>
          <p class="ap-muted">Earning {{ affiliate.commission_percent }}% of the net on every sale you send.</p>
        </div>
        <div class="ap-linkbox">
          <code>{{ affiliate.link }}</code>
          <button class="ap-btn ap-btn-ghost" @click="copyLink">{{ copied ? 'Copied' : 'Copy link' }}</button>
        </div>
      </div>

      <div v-if="error" class="ap-error">{{ error }}</div>

      <section class="ap-metrics">
        <div class="ap-metric">
          <div class="ap-metric-label">Visits</div>
          <div class="ap-metric-value">{{ totals.clicks }}</div>
          <div class="ap-metric-sub">all time</div>
        </div>
        <div class="ap-metric">
          <div class="ap-metric-label">Sales</div>
          <div class="ap-metric-value">{{ totals.sales }}</div>
          <div class="ap-metric-sub">
            <template v-if="totals.conversion_rate !== null">{{ totals.conversion_rate }}% of visits</template>
            <template v-else>no visits yet</template>
          </div>
        </div>
        <!-- The gross sits beside the commission on purpose: a share of the net
             does not reconcile with the rate on their agreement unless they can
             see both numbers. -->
        <div class="ap-metric">
          <div class="ap-metric-label">Revenue you sent</div>
          <div class="ap-metric-value">{{ money(totals.revenue) }}</div>
          <div class="ap-metric-sub">what your customers paid</div>
        </div>
        <div class="ap-metric ap-metric-due">
          <div class="ap-metric-label">Owed to you</div>
          <div class="ap-metric-value">{{ money(totals.owed) }}</div>
          <div class="ap-metric-sub">on the next payment</div>
        </div>
        <div class="ap-metric">
          <div class="ap-metric-label">Paid to you</div>
          <div class="ap-metric-value">{{ money(totals.paid) }}</div>
          <div class="ap-metric-sub">
            <template v-if="lastPayout">last {{ money(lastPayout.amount) }} on {{ lastPayout.paid_at }}</template>
            <template v-else>nothing sent yet</template>
          </div>
        </div>
      </section>

      <section class="ap-card">
        <div class="ap-card-head">
          <h2>Visits and sales by day</h2>
          <div class="ap-ranges">
            <button
              v-for="r in [7, 30, 90]"
              :key="r"
              :class="['ap-range', range === r ? 'ap-range-on' : '']"
              @click="setRange(r)"
            >{{ r }} days</button>
          </div>
        </div>

        <div class="ap-chart">
          <div
            v-for="d in days"
            :key="d.date"
            class="ap-bar-slot"
            :title="`${d.date} · ${d.clicks} visit(s) · ${d.sales} sale(s) · ${money(d.commission)}`"
          >
            <div class="ap-bar" :style="{ height: `${Math.round((d.clicks / peakClicks) * 100)}%` }"></div>
            <!-- A sale is a different event from a visit, so it is marked
                 rather than stacked into the same bar. -->
            <div v-if="d.sales" class="ap-bar-sale"></div>
          </div>
        </div>
        <div class="ap-chart-axis">
          <span>{{ days[0]?.date }}</span>
          <span class="ap-legend"><i class="ap-key-visit"></i>visits <i class="ap-key-sale"></i>sale</span>
          <span>{{ days[days.length - 1]?.date }}</span>
        </div>

        <div class="ap-table-wrap">
          <table v-if="activeDays.length">
            <thead>
              <tr><th>Date</th><th class="ap-num">Visits</th><th class="ap-num">Sales</th><th class="ap-num">Conversion</th><th class="ap-num">Revenue</th><th class="ap-num">Your commission</th></tr>
            </thead>
            <tbody>
              <tr v-for="d in activeDays" :key="d.date">
                <td>{{ d.date }}</td>
                <td class="ap-num">{{ d.clicks }}</td>
                <td class="ap-num">{{ d.sales }}</td>
                <td class="ap-num ap-dim">{{ d.clicks ? `${((d.sales / d.clicks) * 100).toFixed(1)}%` : '—' }}</td>
                <td class="ap-num ap-dim">{{ money(d.revenue) }}</td>
                <td class="ap-num"><strong>{{ money(d.commission) }}</strong></td>
              </tr>
            </tbody>
          </table>
          <div v-else class="ap-empty">No visits in this window yet.</div>
        </div>
      </section>

      <section class="ap-card">
        <div class="ap-card-head"><h2>Payments</h2></div>
        <div class="ap-table-wrap">
          <table v-if="payouts.length">
            <thead>
              <tr><th>Reference</th><th>Sent</th><th>Covering</th><th class="ap-num">Sales</th><th class="ap-num">Amount</th></tr>
            </thead>
            <tbody>
              <tr v-for="p in payouts" :key="p.reference" :class="p.status === 'void' ? 'ap-void' : ''">
                <td><code>{{ p.reference }}</code><span v-if="p.status === 'void'" class="ap-pill">void</span></td>
                <td>{{ p.paid_at || '—' }}</td>
                <td class="ap-dim">{{ p.period_start }} → {{ p.period_end }}</td>
                <td class="ap-num">{{ p.sales_count }}</td>
                <td class="ap-num"><strong>{{ money(p.total_amount) }}</strong></td>
              </tr>
            </tbody>
          </table>
          <div v-else class="ap-empty">No payments sent yet. Anything owed goes out on the next run.</div>
        </div>
      </section>

      <p class="ap-fineprint">
        <strong>Revenue you sent</strong> is what your customers paid in total.
        Commission is {{ affiliate.commission_percent }}% of the net — what the customer paid,
        less the sales tax remitted to their government and Kelviq's payment processing fee.
        Kelviq is our merchant of record; WyvStudio deducts nothing of its own before your
        share. Refunded orders earn nothing.
        Figures update as sales come in; questions go to your usual contact.
      </p>
    </main>
  </div>
</template>

<style scoped>
.ap {
  min-height: 100vh;
  background: var(--color-bg-deep, #0a0a0f);
  color: var(--color-text-primary, #ececf3);
  font-size: 14px;
}
.ap-top {
  display: flex; align-items: center; justify-content: space-between;
  padding: 16px 24px; border-bottom: 1px solid var(--color-border, #2a2a36);
}
.ap-brand { font-weight: 800; letter-spacing: -.2px; }
.ap-brand span { color: var(--color-accent, #ff6b35); font-weight: 600; }

.ap-center { display: flex; align-items: center; justify-content: center; padding: 48px 20px; min-height: 70vh; }
.ap-main { max-width: 1040px; margin: 0 auto; padding: 28px 24px 60px; display: flex; flex-direction: column; gap: 22px; }

.ap-card {
  background: var(--color-bg-card, #17171f);
  border: 1px solid var(--color-border, #2a2a36);
  border-radius: 12px;
}
.ap-card-head {
  display: flex; align-items: center; justify-content: space-between; gap: 14px;
  padding: 16px 18px; border-bottom: 1px solid var(--color-border, #2a2a36);
}
.ap-card-head h2 { font-size: 14px; font-weight: 700; margin: 0; }

.ap-login { width: 100%; max-width: 400px; padding: 28px; display: flex; flex-direction: column; gap: 14px; }
.ap-login h1 { font-size: 20px; margin: 0; }
.ap-login label { display: flex; flex-direction: column; gap: 6px; font-size: 12px; color: var(--color-text-secondary, #a1a1b5); }
.ap-login input, .ap-hello input {
  background: var(--color-bg-elevated, #1d1d28);
  border: 1px solid var(--color-border, #2a2a36);
  border-radius: 9px; padding: 11px 13px; font: inherit; font-size: 14px;
  color: var(--color-text-primary, #ececf3); outline: none; width: 100%; box-sizing: border-box;
}
.ap-login input:focus-visible { border-color: var(--color-accent, #ff6b35); }

.ap-muted { color: var(--color-text-secondary, #a1a1b5); font-size: 12.5px; line-height: 1.6; margin: 0; }
.ap-dim { color: var(--color-text-muted, #6a6a7c); }
.ap-fineprint { color: var(--color-text-muted, #6a6a7c); font-size: 11.5px; line-height: 1.7; margin: 0; }
.ap-error {
  padding: 10px 13px; border-radius: 8px; font-size: 12.5px;
  background: #ef44441a; border: 1px solid #ef444440; color: #fca5a5;
}
.ap-empty { padding: 26px 18px; text-align: center; color: var(--color-text-muted, #6a6a7c); font-size: 12.5px; }

.ap-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  padding: 9px 16px; border-radius: 9px; font-size: 13px; font-weight: 600;
  cursor: pointer; border: 1px solid transparent; transition: all .15s; font-family: inherit;
}
.ap-btn:disabled { opacity: .55; cursor: not-allowed; }
.ap-btn-primary { background: var(--color-accent, #ff6b35); color: #1a0d05; }
.ap-btn-primary:hover:not(:disabled) { background: var(--color-accent-hover, #ff875a); }
.ap-btn-ghost {
  background: transparent; color: var(--color-text-secondary, #a1a1b5);
  border-color: var(--color-border, #2a2a36);
}
.ap-btn-ghost:hover:not(:disabled) { color: var(--color-text-primary, #ececf3); border-color: var(--color-border-active, #494960); }

.ap-hello { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.ap-hello h1 { font-size: 22px; margin: 0 0 4px; }
.ap-linkbox {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
  background: var(--color-bg-card, #17171f); border: 1px solid var(--color-border, #2a2a36);
  border-radius: 10px; padding: 8px 8px 8px 12px;
}
.ap-linkbox code { font-size: 12px; color: var(--color-text-secondary, #a1a1b5); word-break: break-all; }

.ap-metrics { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; }
.ap-metric {
  background: var(--color-bg-card, #17171f); border: 1px solid var(--color-border, #2a2a36);
  border-radius: 12px; padding: 16px;
}
.ap-metric-label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
  color: var(--color-text-muted, #6a6a7c); margin-bottom: 8px;
}
.ap-metric-value { font-size: 26px; font-weight: 700; line-height: 1; font-variant-numeric: tabular-nums; }
.ap-metric-sub { font-size: 11px; color: var(--color-text-muted, #6a6a7c); margin-top: 6px; }
/* Only the figure they are waiting on carries colour. */
.ap-metric-due .ap-metric-value { color: var(--color-accent, #ff6b35); }

.ap-ranges { display: flex; gap: 6px; }
.ap-range {
  padding: 5px 11px; border-radius: 7px; font-size: 11.5px; font-weight: 600; font-family: inherit;
  background: transparent; border: 1px solid var(--color-border, #2a2a36);
  color: var(--color-text-muted, #6a6a7c); cursor: pointer; transition: all .15s;
}
.ap-range:hover { color: var(--color-text-primary, #ececf3); }
.ap-range-on { background: #ff6b3522; border-color: var(--color-accent, #ff6b35); color: var(--color-accent, #ff6b35); }

.ap-chart { display: flex; align-items: flex-end; gap: 2px; height: 132px; padding: 18px 18px 0; }
.ap-bar-slot { flex: 1; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 3px; position: relative; }
.ap-bar { width: 100%; min-height: 2px; background: #494960; border-radius: 2px 2px 0 0; }
.ap-bar-slot:hover .ap-bar { background: var(--color-border-active, #6a6a8c); }
.ap-bar-sale { position: absolute; top: -2px; width: 6px; height: 6px; border-radius: 50%; background: var(--color-accent, #ff6b35); }
.ap-chart-axis {
  display: flex; justify-content: space-between; align-items: center;
  padding: 8px 18px 14px; font-size: 10.5px; color: var(--color-text-muted, #6a6a7c);
}
.ap-legend { display: flex; align-items: center; gap: 6px; }
.ap-legend i { width: 8px; height: 8px; border-radius: 2px; display: inline-block; }
.ap-key-visit { background: #494960; }
.ap-key-sale { background: var(--color-accent, #ff6b35); border-radius: 50% !important; }

.ap-table-wrap { overflow-x: auto; border-top: 1px solid var(--color-border, #2a2a36); }
.ap-table-wrap table { width: 100%; border-collapse: collapse; }
.ap-table-wrap th {
  text-align: left; padding: 10px 18px; font-size: 10.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .5px; color: var(--color-text-muted, #6a6a7c);
  border-bottom: 1px solid var(--color-border, #2a2a36); white-space: nowrap;
}
.ap-table-wrap td { padding: 11px 18px; border-bottom: 1px solid var(--color-border, #2a2a36); font-size: 13px; }
.ap-table-wrap tr:last-child td { border-bottom: none; }
.ap-num { text-align: right; font-variant-numeric: tabular-nums; }
th.ap-num { text-align: right; }
.ap-void td { opacity: .5; }
.ap-pill {
  margin-left: 7px; padding: 2px 7px; border-radius: 999px; font-size: 9.5px;
  font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
  background: #6a6a7c22; color: var(--color-text-muted, #6a6a7c); border: 1px solid #6a6a7c40;
}

@media (max-width: 1180px) {
  .ap-metrics { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 860px) {
  .ap-metrics { grid-template-columns: repeat(2, 1fr); }
  .ap-hello { flex-direction: column; }
}
</style>
