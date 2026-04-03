<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const mode = ref('magic') // 'magic' | 'password'
const state = ref('idle') // 'idle' | 'loading' | 'sent' | 'error'
const errorMessage = ref('')
const noPasswordSet = ref(false)

const form = reactive({ email: '', password: '' })

async function submitMagicLink() {
  if (!form.email) return
  state.value = 'loading'
  errorMessage.value = ''
  try {
    await authStore.requestMagicLink(form.email)
    state.value = 'sent'
  } catch (err) {
    state.value = 'error'
    errorMessage.value = err.response?.data?.error?.message ?? 'Unable to send magic link. Try again.'
  }
}

async function submitPassword() {
  if (!form.email || !form.password) return
  state.value = 'loading'
  errorMessage.value = ''
  noPasswordSet.value = false
  try {
    await authStore.login(form.email, form.password)
    router.push({ name: 'dashboard' })
  } catch (err) {
    state.value = 'error'
    const code = err.response?.data?.error?.code
    noPasswordSet.value = code === 'no_password_set'
    errorMessage.value = err.response?.data?.error?.message ?? 'Incorrect email or password.'
  }
}

function toggleMode() {
  mode.value = mode.value === 'magic' ? 'password' : 'magic'
  state.value = 'idle'
  errorMessage.value = ''
  noPasswordSet.value = false
}

function switchToMagicLink() {
  mode.value = 'magic'
  state.value = 'idle'
  errorMessage.value = ''
  noPasswordSet.value = false
}

function resend() {
  state.value = 'idle'
  submitMagicLink()
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
          The link expires in 15 minutes and can only be used once.<br><br>
          Didn't get it? <button class="auth-link auth-link-button" @click="resend">Resend</button>
        </div>

        <div class="auth-footer auth-footer-compact centered">
          <button class="auth-link auth-link-button" @click="state = 'idle'">← Back to login</button>
        </div>
      </template>

      <template v-else>
        <div class="auth-logo">F</div>
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-subtitle">Enter your email to receive a magic link, or sign in with your password.</p>

        <div v-if="state === 'error'" class="auth-error">
          {{ errorMessage }}
          <span v-if="noPasswordSet">
            <button class="auth-link auth-link-button auth-link-small" type="button" @click="switchToMagicLink">Send magic link instead →</button>
          </span>
        </div>

        <form @submit.prevent="mode === 'magic' ? submitMagicLink() : submitPassword()">
          <div class="auth-field">
            <label class="auth-label">Email</label>
            <input v-model="form.email" class="auth-input" type="email" required placeholder="you@example.com">
          </div>

          <button
            v-if="mode === 'magic'"
            type="submit"
            class="auth-btn-primary"
            :disabled="state === 'loading'"
          >
            {{ state === 'loading' ? 'Sending…' : '✦ Send Magic Link' }}
          </button>

          <div class="auth-divider">or</div>

          <div v-if="mode === 'password'" class="auth-password-section">
            <div class="auth-field">
              <label class="auth-label">Password</label>
            <input v-model="form.password" class="auth-input" type="password" required placeholder="········">
          </div>

            <button type="submit" class="auth-btn-primary auth-btn-primary-tight" :disabled="state === 'loading'">
              {{ state === 'loading' ? 'Signing in…' : 'Sign In' }}
            </button>

            <div class="auth-password-note">
              <button class="auth-link auth-link-button auth-link-small" type="button" @click="toggleMode">
                Forgot password? Use magic link
              </button>
            </div>
          </div>

          <button
            v-else
            type="button"
            class="auth-btn-secondary"
            @click="toggleMode"
          >
            Sign in with password
          </button>

          <button
            v-if="mode === 'password'"
            type="button"
            class="auth-btn-secondary"
            @click="toggleMode"
          >
            Use magic link instead
          </button>
        </form>

        <div class="auth-footer">
          Don't have an account? <router-link class="auth-link" :to="{ name: 'register' }">Create one</router-link>
        </div>
      </template>
    </div>
  </main>
</template>
