<script setup>
import { ref, reactive, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";
import api from "../services/api";

const authStore = useAuthStore();
const route = useRoute();
const router = useRouter();

const state = ref("idle"); // 'idle' | 'loading' | 'error'
const errorMessage = ref("");

const form = reactive({ name: "", email: "", password: "" });

// Keep the selected offer through account creation and checkout retries.
const PLAN_LABELS = {
  lifetime_starter: "Starter — $89, 4,000 credits",
  lifetime_creator: "Creator — $199, 12,000 credits",
  lifetime_agency: "Agency — $399, 20,000 credits",
  starter: "Starter — $29/month",
  creator: "Creator — $59/month",
  pro: "Pro — $99/month",
  agency: "Agency — $199/month",
  // The $9 pass arrives as ?pass=1 rather than a plan key, but travels the
  // same road: parked here, picked up after account creation, turned into a
  // checkout. Without an entry here the signup gate bounced the buyer back to
  // pricing — the landing offer led nowhere.
  ugc_pass: "UGC Test Pass — $9, 600 credits",
};

const pendingPlan = ref("");
const pendingPlanLabel = computed(() => PLAN_LABELS[pendingPlan.value] ?? "");

// While campaigns are running the free tier is closed: a signup has to arrive
// having chosen a plan. Asked of the server rather than hardcoded, so the tier
// reopens with one env var and no redeploy of this page's logic.
const checkingPolicy = ref(true);

onMounted(async () => {
  const plan = route.query.pass ? "ugc_pass" : String(route.query.plan ?? "");
  if (PLAN_LABELS[plan]) {
    pendingPlan.value = plan;
    try {
      localStorage.setItem("wyv_pending_plan", plan);
    } catch {
      // The API also persists the plan; the browser cache is optional.
    }
  }

  try {
    const { data } = await api.get("/public/signup-policy");
    // No plan chosen and the gate is on: send them to pick one rather than
    // showing a form that would create an account they cannot use.
    if (data?.data?.require_plan && !pendingPlan.value) {
      window.location.href = "https://wyvstudio.com/#pricing";
      return;
    }
  } catch {
    // Policy unreachable — let the signup through rather than stranding
    // someone behind a check that failed.
  } finally {
    checkingPolicy.value = false;
  }
});

async function submit() {
  if (!form.name || !form.email) return;
  state.value = "loading";
  errorMessage.value = "";
  try {
    await authStore.register(
      form.email,
      form.name,
      form.password || null,
      pendingPlan.value || null
    );
    if (pendingPlan.value) {
      await router.replace({ name: 'continue-checkout', query: { plan: pendingPlan.value } });
    } else {
      await router.replace({ name: 'dashboard' });
    }
  } catch (err) {
    state.value = "error";
    errorMessage.value =
      err.response?.data?.error?.message ?? Object.values(err.response?.data?.errors ?? {}).flat()[0] ??
      "Unable to create account. Try again.";
  }
}
</script>

<template>
  <main class="auth-screen auth-bg">
    <div class="auth-card">
      <!-- Hold the form until the signup policy is known. Without this the
           form paints immediately and someone quick can submit before the
           redirect fires — which is exactly how a gated signup slipped
           through as a free account. -->
      <template v-if="checkingPolicy">
        <div class="auth-magic-icon auth-magic-icon-pulse">✦</div>
        <h1 class="auth-title centered">One moment…</h1>
        <p class="auth-subtitle auth-subtitle-compact centered">Getting your plan ready.</p>
      </template>

      <template v-else>
        <div class="auth-logo">W</div>
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle">
          Set up your WyvStudio workspace. Takes 30 seconds.
        </p>

        <div v-if="pendingPlanLabel" class="auth-plan-note">
          You're signing up for <strong>{{ pendingPlanLabel }}</strong>. We'll take
          you to secure checkout as soon as your account is created.
        </div>

        <div v-if="state === 'error'" class="auth-error">
          {{ errorMessage }}
        </div>

        <form @submit.prevent="submit">
          <div class="auth-field">
            <label class="auth-label">Full name</label>
            <input
              v-model="form.name"
              class="auth-input"
              type="text"
              required
              placeholder="Korede A."
            />
          </div>

          <div class="auth-field">
            <label class="auth-label">Email</label>
            <input
              v-model="form.email"
              class="auth-input"
              type="email"
              required
              placeholder="you@example.com"
            />
          </div>

          <div class="auth-field">
            <label class="auth-label">
              Password
              <span class="auth-label-note"
                >(optional — you can use magic link only)</span
              >
            </label>
            <input
              v-model="form.password"
              class="auth-input"
              type="password"
              placeholder="Min. 8 characters"
            />
          </div>

          <button
            type="submit"
            class="auth-btn-primary"
            :disabled="state === 'loading'"
          >
            {{ state === "loading" ? "Creating account…" : "Create Account" }}
          </button>
        </form>

        <div class="auth-footer">
          Already have an account?
          <router-link class="auth-link" :to="{ name: 'login', query: pendingPlan ? { plan: pendingPlan } : {} }"
            >Sign in</router-link
          >
        </div>
      </template>
    </div>
  </main>
</template>
