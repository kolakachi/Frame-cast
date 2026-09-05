<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";
import api from "../services/api";
import AppSidebar from "../components/AppSidebar.vue";
import NotifBell from "../components/NotifBell.vue";

const router = useRouter();
const authStore = useAuthStore();

const mePayload = ref(null);
const billing = ref(null);
const usage = ref(null);
const loading = ref(true);
const error = ref("");
const checkoutPending = ref("");

async function logout() { await authStore.logout(); router.push({ name: "login" }); }

// ── Catalogue ─────────────────────────────────────────────
// Short counts use the same conservative ~300 credits/short the pricing page
// quotes — above the measured median, so nobody is promised more than they get.
const ONE_TIME = [
  { key: "lifetime_starter", rank: 1, name: "Starter", price: "$89",  credits: 4000,  shorts: 13, blurb: "Enough to find your footing.", feats: ["1 channel", "2 characters", "All visual modes", "No watermark"] },
  { key: "lifetime_creator", rank: 2, name: "Creator", price: "$199", credits: 12000, shorts: 40, blurb: "The one most people need.", feats: ["3 channels", "5 characters", "Series mode", "Social publishing"], popular: true },
  { key: "lifetime_agency",  rank: 3, name: "Agency",  price: "$399", credits: 20000, shorts: 66, blurb: "For running several brands.", feats: ["Unlimited channels", "10 characters", "Priority export", "Everything included"] },
];

const MONTHLY = [
  { key: "starter", name: "Starter", price: "$19",  credits: 1500,  feats: ["1 channel", "2 characters"] },
  { key: "creator", name: "Creator", price: "$39",  credits: 3000,  feats: ["3 channels", "5 characters"], popular: true },
  { key: "pro",     name: "Pro",     price: "$79",  credits: 6500,  feats: ["5 channels", "10 characters"] },
  { key: "agency",  name: "Agency",  price: "$149", credits: 13000, feats: ["Unlimited channels", "Credit rollover"] },
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
                <div class="plan-credits">{{ p.credits.toLocaleString() }} credits ≈ {{ p.shorts }} shorts</div>
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
            <p class="plans-note">
              Short counts assume a 30-second video with AI visuals at ~300 credits.
              Stock-footage shorts cost a fraction of that and go much further.
            </p>
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
.plans-shell { display: flex; min-height: 100vh; background: var(--color-bg-deep); }
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.content { padding: 24px 28px 80px; max-width: 1120px; }

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
</style>
