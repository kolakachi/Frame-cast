<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'

const route = useRoute()
const router = useRouter()

const token = ref('')
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')

// 'verifying' → 'ready' (form shown) | 'invalid' (token bad) → 'submitting' → 'done' | 'error'
const state = ref('verifying')
const errorMessage = ref('')

onMounted(async () => {
  token.value = String(route.query.token ?? '')
  if (!token.value) {
    state.value = 'invalid'
    errorMessage.value = 'This reset link is missing its token. Open the link from your email exactly as it was sent.'
    return
  }
  try {
    const res = await api.get('/auth/password/verify', { params: { token: token.value } })
    email.value = res.data?.data?.email ?? ''
    state.value = 'ready'
  } catch (e) {
    state.value = 'invalid'
    errorMessage.value = e.response?.data?.error?.message ?? 'This reset link is no longer valid.'
  }
})

const passwordsMatch = computed(() => password.value === passwordConfirm.value)
const canSubmit = computed(() =>
  state.value === 'ready'
  && password.value.length >= 8
  && passwordsMatch.value
)

async function submit() {
  if (!canSubmit.value) return
  state.value = 'submitting'
  errorMessage.value = ''
  try {
    await api.post('/auth/password/reset', { token: token.value, password: password.value })
    state.value = 'done'
  } catch (e) {
    const code = e.response?.data?.error?.code
    if (code === 'invalid_token') {
      state.value = 'invalid'
      errorMessage.value = e.response.data.error.message
    } else {
      state.value = 'error'
      errorMessage.value = e.response?.data?.error?.message ?? 'Could not reset the password. Please try again.'
    }
  }
}
</script>

<template>
  <div class="auth-shell">
    <div class="auth-card">
      <template v-if="state === 'verifying'">
        <div class="auth-logo">W</div>
        <h1 class="auth-title">Checking your reset link…</h1>
      </template>

      <template v-else-if="state === 'invalid'">
        <div class="auth-logo" style="background:rgba(255,80,80,0.18);color:#fca5a5;">!</div>
        <h1 class="auth-title">Link expired or invalid</h1>
        <p class="auth-subtitle">{{ errorMessage }}</p>
        <div class="auth-footer">
          <router-link class="auth-link" :to="{ name: 'forgot-password' }">Request a new reset link</router-link>
        </div>
      </template>

      <template v-else-if="state === 'done'">
        <div class="auth-logo" style="background:rgba(52,211,153,0.18);color:#86efac;">✓</div>
        <h1 class="auth-title">Password updated</h1>
        <p class="auth-subtitle">You can now sign in with your new password.</p>
        <router-link class="auth-btn-primary" :to="{ name: 'login' }" style="display:inline-flex;justify-content:center;text-decoration:none;">
          Sign in
        </router-link>
      </template>

      <template v-else>
        <div class="auth-logo">W</div>
        <h1 class="auth-title">Set a new password</h1>
        <p class="auth-subtitle" v-if="email">For <strong>{{ email }}</strong>.</p>

        <div v-if="state === 'error' || (state === 'ready' && errorMessage)" class="auth-error">
          {{ errorMessage }}
        </div>

        <form @submit.prevent="submit">
          <div class="auth-field">
            <label class="auth-label">New password</label>
            <input
              v-model="password"
              class="auth-input"
              type="password"
              required
              minlength="8"
              autocomplete="new-password"
              placeholder="At least 8 characters"
            />
          </div>
          <div class="auth-field">
            <label class="auth-label">Confirm new password</label>
            <input
              v-model="passwordConfirm"
              class="auth-input"
              type="password"
              required
              minlength="8"
              autocomplete="new-password"
              placeholder="Re-enter the same password"
            />
            <div v-if="passwordConfirm && !passwordsMatch" style="font-size:12px;color:#fca5a5;margin-top:6px;">Passwords don't match.</div>
          </div>

          <button type="submit" class="auth-btn-primary" :disabled="!canSubmit || state === 'submitting'">
            {{ state === 'submitting' ? 'Saving…' : 'Update password' }}
          </button>
        </form>

        <div class="auth-footer">
          <router-link class="auth-link" :to="{ name: 'login' }">← Back to sign in</router-link>
        </div>
      </template>
    </div>
  </div>
</template>
