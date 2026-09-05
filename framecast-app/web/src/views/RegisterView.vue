<script setup>
import { ref, reactive, computed, onMounted } from "vue";
import { useRoute } from "vue-router";
import { useAuthStore } from "../stores/auth";

const authStore = useAuthStore();
const route = useRoute();

const state = ref("idle"); // 'idle' | 'loading' | 'sent' | 'error'
const errorMessage = ref("");

const form = reactive({ name: "", email: "", password: "" });

// The pricing page sends ?plan=lifetime_starter (etc). Registration is magic
// link, so the user leaves for their inbox and comes back on a fresh page load
// with no query string — the intent has to be parked somewhere that survives
// that. Stashed here, picked up after login, and turned into a Kelviq checkout
// so clicking a price on the site actually ends at a payment page.
const PLAN_LABELS = {
  lifetime_starter: "Starter — $89, 4,000 credits",
  lifetime_creator: "Creator — $199, 12,000 credits",
  lifetime_agency: "Agency — $399, 20,000 credits",
  starter: "Starter — $19/month",
  creator: "Creator — $39/month",
  pro: "Pro — $79/month",
  agency: "Agency — $149/month",
};

const pendingPlan = ref("");
const pendingPlanLabel = computed(() => PLAN_LABELS[pendingPlan.value] ?? "");

onMounted(() => {
  const plan = String(route.query.plan ?? "");
  if (!PLAN_LABELS[plan]) return;
  pendingPlan.value = plan;
  try {
    localStorage.setItem("wyv_pending_plan", plan);
  } catch {
    // Private window or storage blocked — the plan is simply forgotten and the
    // user lands on Settings, where every plan now has a button.
  }
});

async function submit() {
  if (!form.name || !form.email) return;
  state.value = "loading";
  errorMessage.value = "";
  try {
    await authStore.requestMagicLink(
      form.email,
      form.name,
      form.password || null
    );
    state.value = "sent";
  } catch (err) {
    state.value = "error";
    errorMessage.value =
      err.response?.data?.error?.message ??
      "Unable to create account. Try again.";
  }
}
</script>

<template>
  <main class="auth-screen auth-bg">
    <div class="auth-card">
      <template v-if="state === 'sent'">
        <div class="auth-magic-icon">✉</div>
        <h1 class="auth-title centered">Check your email</h1>
        <p class="auth-subtitle centered">
          We sent a magic link to<br />
          <span class="auth-email-highlight">{{
            form.email || "you@example.com"
          }}</span>
        </p>
        <p v-if="pendingPlanLabel" class="auth-subtitle centered">
          Open it and we'll take you straight to checkout for
          <strong>{{ pendingPlanLabel }}</strong>.
        </p>
        <div class="auth-note centered">
          Click the link to activate your account. It expires in 15 minutes.
        </div>
        <div class="auth-footer auth-footer-compact centered">
          <router-link class="auth-link" :to="{ name: 'login' }"
            >← Back to login</router-link
          >
        </div>
      </template>

      <template v-else>
        <div class="auth-logo">W</div>
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle">
          Set up your WyvStudio workspace. Takes 30 seconds.
        </p>

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
          <router-link class="auth-link" :to="{ name: 'login' }"
            >Sign in</router-link
          >
        </div>
      </template>
    </div>
  </main>
</template>
