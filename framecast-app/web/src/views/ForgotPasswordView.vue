<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const router = useRouter()

// Email is preserved across nav from LoginView via ?email= query when the
// user clicked "Forgot password?" with something already typed.
const email = ref('')
const state = ref('idle') // 'idle' | 'loading' | 'sent' | 'error'
const errorMessage = ref('')

onMounted(() => {
  const q = route.query.email
  if (typeof q === 'string') email.value = q
})

async function submit() {
  if (!email.value.trim() || state.value === 'loading') return
  state.value = 'loading'
  errorMessage.value = ''
  try {
    await api.post('/auth/password/forgot', { email: email.value.trim() })
    state.value = 'sent'
  } catch (e) {
    state.value = 'error'
    const code = e.response?.data?.error?.code
    errorMessage.value =
      code === 'rate_limited'
        ? 'Too many requests from this address — please try again in an hour.'
        : (e.response?.data?.error?.message ?? 'Could not send the reset link. Please try again.')
  }
}
</script>

<template>
  <div class="auth-shell">
    <div class="auth-card">
      <template v-if="state === 'sent'">
        <div class="auth-magic-icon">✉</div>
        <h1 class="auth-title">Check your inbox</h1>
        <p class="auth-subtitle">
          If <strong>{{ email.trim() }}</strong> is registered, a reset link is on its way. The link expires in 60 minutes and can only be used once.
        </p>
        <p class="auth-subtitle" style="font-size:13px;opacity:.75;">
          Didn't see it? Check spam, or
          <button class="auth-link auth-link-button auth-link-small" type="button" @click="state = 'idle'">try again</button>.
        </p>
        <div class="auth-footer">
          <router-link class="auth-link" :to="{ name: 'login' }">← Back to sign in</router-link>
        </div>
      </template>

      <template v-else>
        <div class="auth-logo">W</div>
        <h1 class="auth-title">Forgot your password?</h1>
        <p class="auth-subtitle">Enter your email and we'll send you a reset link.</p>

        <div v-if="state === 'error'" class="auth-error">{{ errorMessage }}</div>

        <form @submit.prevent="submit">
          <div class="auth-field">
            <label class="auth-label">Email</label>
            <input
              v-model="email"
              class="auth-input"
              type="email"
              required
              autocomplete="email"
              placeholder="you@example.com"
            />
          </div>

          <button type="submit" class="auth-btn-primary" :disabled="state === 'loading'">
            {{ state === 'loading' ? 'Sending…' : 'Send reset link' }}
          </button>
        </form>

        <div class="auth-footer">
          <router-link class="auth-link" :to="{ name: 'login' }">← Back to sign in</router-link>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* Reuses .auth-shell / .auth-card / .auth-input etc. from the global
   auth stylesheet shared with LoginView / RegisterView / MagicLinkView. */
</style>
