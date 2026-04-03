<script setup>
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const state = ref('verifying') // 'verifying' | 'expired' | 'error'

onMounted(async () => {
  const token = route.query.token

  if (!token) {
    state.value = 'expired'
    return
  }

  try {
    await authStore.verifyMagicLink(token)
    router.replace({ name: 'dashboard' })
  } catch (err) {
    const code = err.response?.data?.error?.code
    state.value = code === 'invalid_magic_link' ? 'expired' : 'error'
  }
})
</script>

<template>
  <main class="auth-screen auth-bg">
    <div class="auth-card auth-card-centered">
      <template v-if="state === 'verifying'">
        <div class="auth-magic-icon auth-magic-icon-pulse">✦</div>
        <h1 class="auth-title centered">Signing you in…</h1>
        <p class="auth-subtitle auth-subtitle-compact centered">Verifying your magic link.</p>
      </template>

      <template v-else-if="state === 'expired'">
        <div class="auth-magic-icon auth-magic-icon-danger">✕</div>
        <h1 class="auth-title centered">Link expired</h1>
        <p class="auth-subtitle centered">
          This magic link has already been used or has expired.<br>
          Links are valid for 15 minutes and can only be used once.
        </p>
        <router-link class="auth-btn-primary auth-btn-link" :to="{ name: 'login' }">Request a new link</router-link>
      </template>

      <template v-else>
        <div class="auth-magic-icon auth-magic-icon-warning">⚠</div>
        <h1 class="auth-title centered">Something went wrong</h1>
        <p class="auth-subtitle centered">We couldn't verify your link. Please try again.</p>
        <router-link class="auth-btn-primary auth-btn-link" :to="{ name: 'login' }">Back to login</router-link>
      </template>
    </div>
  </main>
</template>
