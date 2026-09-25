<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";
import { useWorkspaceStore } from "../stores/workspace";
import api from "../services/api";
import AppSidebar from "../components/AppSidebar.vue";
import NotifBell from "../components/NotifBell.vue";

const router = useRouter();
const authStore = useAuthStore();
const workspaceStore = useWorkspaceStore();

const mePayload = ref(null);
const billing = ref(null);
const usage = ref(null);
const loading = ref(true);
const error = ref("");
const checkoutPending = ref("");
// The pass buys the UGC gate, so it is pointless to anyone who already has it.
const hasUgcAccess = computed(() => workspaceStore.capabilities?.ugc_ads === true);

async function logout() { await authStore.logout(); router.push({ name: "login" }); }

// ── Catalogue ─────────────────────────────────────────────
const ONE_TIME = [
  { key: "lifetime_starter", rank: 1, name: "Starter", price: "$89",  credits: 4000, blurb: "Enough to find your footing.", feats: ["1 channel", "2 characters", "All visual modes", "No watermark"] },
  { key: "lifetime_creator", rank: 2, name: "Creator", price: "$199", credits: 12000, blurb: "The one most people need.", feats: ["3 channels", "5 characters", "Series mode", "Social publishing", "API & ChatGPT/Claude access"], popular: true },
  { key: "lifetime_agency",  rank: 3, name: "Agency",  price: "$399", credits: 20000, blurb: "For running several brands.", feats: ["Unlimited channels", "Up to 50 active client workspaces", "10 characters", "Priority export", "Everything included"] },
];

const MONTHLY = [
  { key: "starter", name: "Starter", price: "$29",  credits: 2000,  feats: ["1 channel", "Stock presenters"] },
  { key: "creator", name: "Creator", price: "$59",  credits: 4000,  feats: ["3 channels", "Upload your own face", "API & ChatGPT/Claude access"], popular: true },
  { key: "pro",     name: "Pro",     price: "$99",  credits: 6500,  feats: ["10 channels", "50 characters", "API & ChatGPT/Claude access"] },
  { key: "agency",  name: "Agency",  price: "$199", credits: 13500, feats: ["Unlimited channels", "Up to 50 active client workspaces", "Credit rollover"] },
];

// ── Entitlements ──────────────────────────────────────────
const planTier = computed(() => billing.value?.plan_tier ?? "free");
const hasSubscription = computed(() => Boolean(billing.value?.has_subscription));

// A one-time plan already held — bought here, or redeemed through AppSumo.
const hasOneTimePlan = computed(
  () => planTier.value.startsWith("lifetime_") || planTier.value.startsWith("appsumo_")
);

const currentRank = computed(() => {
  const t = planTier.value;
  if (t.endsWith("_agency")) return 3;
  if (t.endsWith("_creator")) return 2;
  if (t.endsWith("_starter")) return 1;
  return 0;
});

// Holders are offered only what is bigger than what they have, so nobody is
// invited to "upgrade" sideways or downward.
const oneTimePlans = computed(() =>
  hasOneTimePlan.value ? ONE_TIME.filter((p) => p.rank > currentRank.value) : ONE_TIME
);

// Withheld from one-time holders: applySubscription() writes plan_tier
// unconditionally, so subscribing would overwrite an AppSumo/lifetime tier and
// cancelling would never restore it. Their routes are a bigger pack or a top-up.
const showMonthly = computed(() => !hasOneTimePlan.value && !hasSubscription.value);

const atTopTier = computed(() => hasOneTimePlan.value && oneTimePlans.value.length === 0);

const planLabel = computed(() => {
  const t = planTier.value;
  if (t === "free") return "Free plan";
  return t.replace(/^appsumo_/, "AppSumo ").replace(/^lifetime_/, "One-time ").replace(/^\w/, (c) => c.toUpperCase());
});

// ── Checkout ──────────────────────────────────────────────
async function startCheckout(selection, id) {
  if (checkoutPending.value) return;
  checkoutPending.value = id;
  error.value = "";
  try {
    const { data } = await api.post("/billing/kelviq/checkout", selection);
    if (data?.data?.url) { window.location.href = data.data.url; return; }
    error.value = "Could not start checkout. Please try again.";
  } catch (e) {
    error.value = e.response?.data?.error?.message ?? "Could not start checkout.";
  } finally {
    checkoutPending.value = "";
  }
}

async function openBillingPortal() {
  checkoutPending.value = "portal";
  error.value = "";
  try {
    const { data } = await api.post("/billing/portal");
    if (data?.data?.url) window.open(data.data.url, "_blank");
  } catch (e) {
    error.value = e.response?.data?.error?.message ?? "Could not open the billing portal.";
  } finally {
    checkoutPending.value = "";
  }
}

onMounted(async () => {
  try {
    const [me, status] = await Promise.all([
      api.get("/me"),
      api.get("/billing/status").catch(() => null),
    ]);
    mePayload.value = me.data.data.user;
    usage.value = me.data.data.usage;
    if (status) billing.value = status.data.data.billing;
  } catch {
    error.value = "Could not load your plan. Please refresh.";
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <div class="plans-shell">
    <AppSidebar :user="mePayload" active-page="settings" @logout="logout" />

    <main class="main">
      <div class="topbar">
        <div class="topbar-left">
          <span class="bc-ws">My Workspace</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">Plans</span>
        </div>
        <div class="topbar-right"><NotifBell /></div>
      </div>

      <div class="content">
        <div v-if="error" class="banner error">{{ error }}</div>

        <div v-if="!loading" class="plans-head">
          <h1 class="plans-title">Choose your plan</h1>
          <p class="plans-sub">
            Pay once for a credit pack, or subscribe for credits that refill every month.
            Checkout is handled securely by Kelviq.
          </p>
          <div class="plans-current">
            <span class="cur-chip">{{ planLabel }}</span>
            <span v-if="usage" class="cur-credits">{{ (usage.credits_balance ?? 0).toLocaleString() }} credits left</span>
          </div>
        </div>

        <template v-if="!loading">
          <!-- Draft-and-publish framing: credits go further than the "full ad"
               count implies, because you iterate cheap and only spend full on winners. -->
          <div class="plans-workflow">
            <div class="pw-lead">Your credits go further than they look</div>
            <div class="pw-modes">
              <div class="pw-mode">
                <span class="pw-badge draft">Draft · 480p</span>
                <p>Spin up concepts and test hooks at <b>less than half the credits</b>. Try lots of angles cheaply.</p>
              </div>
              <div class="pw-arrow">→</div>
              <div class="pw-mode">
                <span class="pw-badge full">Publish · 720p</span>
                <p>Found the winner? Render it in <b>full quality</b> — spend the credits only on the one you'll post.</p>
              </div>
            </div>
            <p class="pw-foot">Iterate in draft, publish in full. A Starter month is ~9 drafts to explore, then your best few in full.</p>
          </div>

          <!-- Already on the largest pack — nothing to sell, so say so. -->
          <div v-if="atTopTier" class="plans-note top-tier">
            You're on our largest one-time plan. To add credits, use a top-up in
            <router-link to="/settings">Settings</router-link>.
          </div>

          <!-- ONE-TIME -->
          <section v-if="oneTimePlans.length" class="plans-section">
            <div class="sec-head">
              <h2 class="sec-title">{{ hasOneTimePlan ? 'Upgrade your pack' : 'Pay once' }}</h2>
              <p class="sec-sub">A one-time credit pack. No subscription, nothing to cancel.</p>
            </div>
            <div class="plan-grid">
              <div v-for="p in oneTimePlans" :key="p.key" :class="['plan-card', p.popular ? 'popular' : '']">
                <div v-if="p.popular" class="plan-tag">Most popular</div>
                <div class="plan-name">{{ p.name }}</div>
                <div class="plan-price">{{ p.price }}<span class="plan-per">once</span></div>
                <div class="plan-credits">{{ p.credits.toLocaleString() }} credits</div>
                <div class="plan-blurb">{{ p.blurb }}</div>
                <ul class="plan-feats">
                  <li v-for="f in p.feats" :key="f">{{ f }}</li>
                </ul>
                <button
                  class="btn btn-primary plan-btn"
                  type="button"
                  :disabled="Boolean(checkoutPending)"
                  @click="startCheckout({ lifetime: p.key }, p.key)"
                >{{ checkoutPending === p.key ? 'Opening checkout…' : `Get ${p.name}` }}</button>
              </div>
            </div>
          </section>

          <!-- MONTHLY -->
          <section v-if="showMonthly" class="plans-section">
            <div class="sec-head">
              <h2 class="sec-title">Or subscribe monthly</h2>
              <p class="sec-sub">Credits refill every month, at a better rate per credit. Cancel any time.</p>
            </div>
            <div class="plan-grid">
              <div v-for="p in MONTHLY" :key="p.key" :class="['plan-card', p.popular ? 'popular' : '']">
                <div v-if="p.popular" class="plan-tag">Most popular</div>
                <div class="plan-name">{{ p.name }}</div>
                <div class="plan-price">{{ p.price }}<span class="plan-per">/month</span></div>
                <div class="plan-credits">{{ p.credits.toLocaleString() }} credits every month</div>
                <ul class="plan-feats">
                  <li v-for="f in p.feats" :key="f">{{ f }}</li>
                </ul>
                <button
                  class="btn btn-ghost plan-btn"
                  type="button"
                  :disabled="Boolean(checkoutPending)"
                  @click="startCheckout({ plan: p.key }, p.key)"
                >{{ checkoutPending === p.key ? 'Opening checkout…' : `Subscribe — ${p.name}` }}</button>
              </div>
            </div>
          </section>

          <!-- Existing subscriber: the portal is the only safe way to change tier. -->
          <section v-if="hasSubscription" class="plans-section">
            <div class="sec-head">
              <h2 class="sec-title">Change your subscription</h2>
              <p class="sec-sub">
                Moving up or down a tier, updating your card and cancelling all happen in the billing portal.
              </p>
            </div>
            <button
              class="btn btn-primary"
              type="button"
              :disabled="Boolean(checkoutPending)"
              @click="openBillingPortal"
            >{{ checkoutPending === 'portal' ? 'Opening…' : 'Open billing portal' }}</button>
          </section>

          <!-- Deliberately a row under the packs, not a plan card: a $9 option
               sitting beside the packs anchors the whole product cheap. This
               only catches someone who was about to leave. -->
          <div v-if="!hasUgcAccess" class="pass-row">
            <div>
              <div class="pass-title">Just want to try UGC? <span class="pass-tag">$9 once</span></div>
              <div class="pass-sub">600 credits — one 15-second ad, or two drafts. No watermark, no subscription. One per customer.</div>
            </div>
            <button class="plan-btn" :disabled="checkoutPending === 'pass'" @click="startCheckout({ pass: true }, 'pass')">
              {{ checkoutPending === 'pass' ? 'Starting…' : 'Get the Test Pass' }}
            </button>
          </div>

          <div class="plans-foot">
            Prefer to just add credits? Top-up packs are in
            <router-link to="/settings">Settings</router-link>.
          </div>
        </template>
      </div>
    </main>
  </div>
</template>

<style scoped>
.plans-shell { display: flex; min-height: 100vh; background: #0a0a0f; color: #e8e8ee; }
/* The sidebar is position:fixed (220px); offset main like the other views so
   left-aligned content isn't clipped under it. */
.main { margin-left: var(--sidebar-width, 220px); flex: 1; min-width: 0; display: flex; flex-direction: column; }
.topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid rgba(255,255,255,0.08); }
.topbar-left { font-size: 13px; color: #8a8a9a; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.bc-sep { margin: 0 8px; opacity: 0.5; }
.bc-page { color: #e8e8ee; }
.content { padding: 24px; max-width: 1100px; }
.banner { padding: 10px 14px; border-radius: 8px; font-size: 13px; margin-bottom: 16px; }
.banner.error { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.25); color: #fca5a5; }

/* Buttons are defined per-view in this codebase rather than globally — without
   these the plan CTAs render as bare text. */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.04); color: #e8e8ee; font: inherit; font-size: 13px; cursor: pointer; text-decoration: none; }
.btn:hover { background: rgba(255,255,255,0.08); }
.btn-primary { background: #ff6b35; border-color: #ff6b35; color: #0a0a0f; font-weight: 600; }
.btn-primary:hover { background: #ff8055; border-color: #ff8055; }
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border-color: rgba(255,255,255,0.14); }
.btn-ghost:hover { background: rgba(255,255,255,0.06); }

.plans-head { margin-bottom: 26px; }
.plans-title { font-size: 26px; font-weight: 700; color: var(--color-text-primary); letter-spacing: -.4px; }
.plans-sub { margin-top: 6px; font-size: 14px; color: var(--color-text-muted); max-width: 62ch; line-height: 1.6; }
.plans-current { display: flex; align-items: center; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
.cur-chip { font-family: "Space Mono", monospace; font-size: 12px; padding: 5px 11px; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); }
.cur-credits { font-size: 13px; color: var(--color-text-muted); }

.plans-section { margin-top: 30px; }
.sec-head { margin-bottom: 14px; }
.sec-title { font-size: 17px; font-weight: 700; color: var(--color-text-primary); }
.sec-sub { margin-top: 4px; font-size: 13px; color: var(--color-text-muted); }

.plan-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(232px, 1fr)); gap: 14px; }
.plan-card { position: relative; display: flex; flex-direction: column; padding: 20px; border-radius: 12px; border: 1px solid var(--color-border); background: var(--color-bg-card); }
.plan-card.popular { border-color: var(--color-accent); }
.plan-tag { position: absolute; top: -9px; left: 20px; font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; background: var(--color-accent); color: #fff; }
.plan-name { font-size: 15px; font-weight: 700; color: var(--color-text-primary); }
.plan-price { margin-top: 6px; font-size: 30px; font-weight: 800; color: var(--color-text-primary); letter-spacing: -1px; }
.plan-per { margin-left: 6px; font-size: 13px; font-weight: 500; color: var(--color-text-muted); letter-spacing: 0; }
.plan-credits { margin-top: 4px; font-size: 13px; font-weight: 600; color: var(--color-accent); }
.plan-blurb { margin-top: 8px; font-size: 12.5px; color: var(--color-text-muted); line-height: 1.5; }
.plan-feats { margin: 14px 0 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 6px; }
.plan-feats li { font-size: 12.5px; color: var(--color-text-secondary); padding-left: 18px; position: relative; }
.plan-feats li::before { content: "✓"; position: absolute; left: 0; color: var(--color-accent); font-weight: 700; }
.plan-btn { margin-top: 18px; width: 100%; }

.plans-note { margin-top: 12px; font-size: 12px; color: var(--color-text-muted); line-height: 1.55; max-width: 68ch; }
.plans-note.top-tier { margin-top: 0; padding: 14px 16px; border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); font-size: 13.5px; color: var(--color-text-secondary); }
.plans-foot { margin-top: 34px; padding-top: 18px; border-top: 1px solid var(--color-border); font-size: 13px; color: var(--color-text-muted); }
.plans-note a, .plans-foot a { color: var(--color-accent); text-decoration: none; }
.plans-note a:hover, .plans-foot a:hover { text-decoration: underline; }

.plans-workflow {
  margin: 4px 0 22px; padding: 16px 18px; border-radius: 14px;
  border: 1px solid var(--color-border); background: var(--color-surface, rgba(255,255,255,0.02));
}
.pw-lead { font-weight: 700; font-size: 14.5px; margin-bottom: 12px; }
.pw-modes { display: flex; align-items: stretch; gap: 14px; flex-wrap: wrap; }
.pw-mode { flex: 1 1 240px; min-width: 220px; }
.pw-mode p { margin: 6px 0 0; font-size: 13px; color: var(--color-text-muted); line-height: 1.45; }
.pw-badge { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .02em;
  padding: 3px 9px; border-radius: 999px; }
.pw-badge.draft { background: color-mix(in srgb, var(--color-text-muted) 18%, transparent); color: var(--color-text); }
.pw-badge.full { background: var(--color-primary); color: #fff; }
.pw-arrow { align-self: center; font-size: 20px; color: var(--color-text-muted); }
.pw-foot { margin: 12px 0 0; font-size: 12.5px; color: var(--color-text-muted); }
@media (max-width: 620px) { .pw-arrow { display: none; } }
.pass-row {
  display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
  margin: 18px auto 0; max-width: 760px; padding: 16px 20px; border-radius: 14px;
  border: 1px dashed rgba(255, 107, 53, 0.45); background: rgba(255, 107, 53, 0.07);
}
.pass-title { font-weight: 600; font-size: 15px; color: var(--color-text-primary); }
.pass-tag {
  margin-left: 8px; font-size: 11px; font-weight: 700; letter-spacing: 0.03em;
  background: var(--color-accent); color: #0a0a0f; padding: 2px 8px; border-radius: 999px;
}
.pass-sub { margin-top: 4px; font-size: 12.5px; color: var(--color-text-secondary); }
.pass-row button { margin-left: auto; white-space: nowrap; }
</style>
