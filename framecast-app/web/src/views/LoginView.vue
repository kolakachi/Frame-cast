<script setup>
import { reactive, ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const form = reactive({
  email: '',
})
const state = ref('idle')
const message = ref('Use your workspace email to request a magic link.')

async function submit() {
  state.value = 'loading'
  message.value = ''

  try {
    await authStore.requestMagicLink(form.email)
    state.value = 'success'
    message.value = 'Magic link requested. Delivery is logged until mail transport is wired.'
  } catch (error) {
    state.value = 'error'
    message.value = error.response?.data?.error?.message ?? 'Unable to request a magic link.'
  }
}
</script>

<template>
  <main class="min-h-screen bg-bg-deep px-6 py-16 text-text-primary">
    <div class="mx-auto max-w-md rounded-lg border border-border bg-bg-panel p-8 shadow-2xl">
      <p class="font-mono text-sm uppercase tracking-[0.3em] text-accent">Phase 0</p>
      <h1 class="mt-4 text-4xl font-semibold">Framecast login</h1>
      <p class="mt-3 text-sm text-text-secondary">{{ message }}</p>

      <form class="mt-8 space-y-4" @submit.prevent="submit">
        <label class="block space-y-2">
          <span class="text-sm text-text-secondary">Email</span>
          <input
            v-model="form.email"
            type="email"
            required
            class="w-full rounded-md border border-border bg-bg-card px-4 py-3 outline-none transition focus:border-border-active"
            placeholder="owner@framecast.test"
          />
        </label>

        <button
          type="submit"
          class="w-full rounded-md bg-accent px-4 py-3 font-semibold text-bg-deep transition hover:bg-accent-hover"
          :disabled="state === 'loading'"
        >
          {{ state === 'loading' ? 'Requesting...' : 'Send magic link' }}
        </button>
      </form>
    </div>
  </main>
</template>
