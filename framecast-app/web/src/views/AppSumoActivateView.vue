<script setup>
import { ref, reactive, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";
import api from "../services/api";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const token = ref("");
const state = ref("form"); // 'form' | 'loading' | 'fatal'
const errorMessage = ref("");
const form = reactive({ name: "", email: "", password: "" });

// Fatal errors handed back by the OAuth callback (no token to work with).
const FATAL = {
  oauth: "We couldn't complete the AppSumo sign-in. Please start activation again from your AppSumo account.",
  license: "We couldn't find your AppSumo license. Please start activation again from your AppSumo account.",
};

onMounted(() => {
  token.value = String(route.query.token || "");
  const err = String(route.query.error || "");
  if (!token.value) {
    state.value = "fatal";
    errorMessage.value =
      FATAL[err] ||
      "This activation link is missing or invalid. Please start again from your AppSumo account.";
  }
});

async function submit() {
  if (!form.email || !form.password) return;
  state.value = "loading";
  errorMessage.value = "";
  try {
    await api.post("/appsumo/activate", {
      token: token.value,
      email: form.email,
      password: form.password,
      ...(form.name ? { name: form.name } : {}),
    });
    // Account created + LTD credits provisioned — log in with the credentials
    // the buyer just set and drop them into the app.
    await authStore.login(form.email, form.password);
    router.push({ name: "dashboard" });
  } catch (err) {
    state.value = "form";
    const data = err.response?.data;
    const code = typeof data?.error === "string" ? data.error : null;
    const map = {
      invalid_or_expired_token:
        "Your activation link has expired. Please start again from your AppSumo account.",
      license_not_found_or_deactivated:
        "This license isn't active. If you were refunded, it's no longer valid.",
    };
    errorMessage.value =
      map[code] || data?.message || "We couldn't activate your account. Please try again.";
  }
}
</script>

<template>
  <main class="auth-screen auth-bg">
    <div class="auth-card">
      <div class="auth-logo">W</div>

      <template v-if="state === 'fatal'">
        <h1 class="auth-title">Activation link invalid</h1>
        <p class="auth-subtitle">{{ errorMessage }}</p>
        <a href="https://appsumo.com/account/products/" class="auth-btn-secondary" style="display:block;text-align:center;text-decoration:none;">
          Go to AppSumo
        </a>
      </template>

      <template v-else>
        <h1 class="auth-title">Activate your lifetime deal</h1>
        <p class="auth-subtitle">
          Create your WyvStudio account — your AppSumo credits and plan are applied automatically.
        </p>

        <div v-if="errorMessage" class="auth-error">{{ errorMessage }}</div>

        <form @submit.prevent="submit">
          <div class="auth-field">
            <label class="auth-label">Name <span class="auth-label-note">(optional)</span></label>
            <input v-model="form.name" class="auth-input" type="text" placeholder="Your name" />
          </div>

          <div class="auth-field">
            <label class="auth-label">Email</label>
            <input v-model="form.email" class="auth-input" type="email" required placeholder="you@example.com" />
          </div>

          <div class="auth-field">
            <label class="auth-label">Password</label>
            <input v-model="form.password" class="auth-input" type="password" required minlength="8" placeholder="At least 8 characters" />
          </div>

          <button type="submit" class="auth-btn-primary" :disabled="state === 'loading'">
            {{ state === "loading" ? "Activating…" : "Activate & enter WyvStudio" }}
          </button>
        </form>

        <div class="auth-footer">
          Already activated?
          <router-link class="auth-link" :to="{ name: 'login' }">Sign in</router-link>
        </div>
      </template>
    </div>
  </main>
</template>
