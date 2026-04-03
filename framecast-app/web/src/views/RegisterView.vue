<script setup>
import { ref, reactive } from 'vue'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()

const state = ref('idle') // 'idle' | 'loading' | 'sent' | 'error'
const errorMessage = ref('')

const form = reactive({ name: '', email: '', password: '' })

async function submit() {
  if (!form.name || !form.email) return
  state.value = 'loading'
  errorMessage.value = ''
  try {
    await authStore.requestMagicLink(form.email, form.name, form.password || null)
    state.value = 'sent'
  } catch (err) {
    state.value = 'error'
    errorMessage.value = err.response?.data?.error?.message ?? 'Unable to create account. Try again.'
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
          We sent a magic link to<br>
          <span class="auth-email-highlight">{{ form.email || 'you@example.com' }}</span>
        </p>
        <div class="auth-note centered">
          Click the link to activate your account. It expires in 15 minutes.
        </div>
        <div class="auth-footer auth-footer-compact centered">
          <router-link class="auth-link" :to="{ name: 'login' }">← Back to login</router-link>
        </div>
      </template>

      <template v-else>
        <div class="auth-logo">F</div>
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle">Set up your Framecast workspace. Takes 30 seconds.</p>

        <div v-if="state === 'error'" class="auth-error">
          {{ errorMessage }}
        </div>

        <form @submit.prevent="submit">
          <div class="auth-field">
            <label class="auth-label">Full name</label>
            <input v-model="form.name" class="auth-input" type="text" required placeholder="Korede A.">
          </div>

          <div class="auth-field">
            <label class="auth-label">Email</label>
            <input v-model="form.email" class="auth-input" type="email" required placeholder="you@example.com">
          </div>

          <div class="auth-field">
            <label class="auth-label">
              Password <span class="auth-label-note">(optional — you can use magic link only)</span>
            </label>
            <input v-model="form.password" class="auth-input" type="password" placeholder="Min. 8 characters">
          </div>

          <button type="submit" class="auth-btn-primary" :disabled="state === 'loading'">
            {{ state === 'loading' ? 'Creating account…' : 'Create Account' }}
          </button>
        </form>

        <div class="auth-footer">
          Already have an account? <router-link class="auth-link" :to="{ name: 'login' }">Sign in</router-link>
        </div>
      </template>
    </div>
  </main>
</template>
