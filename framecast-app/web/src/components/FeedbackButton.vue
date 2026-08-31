<script setup>
// Quiet, always-available feedback entry in the sidebar. The triggered
// prompts (post-export, rageclick) catch emotional moments; this catches the
// people who have something to say on THEIR schedule — which no triggered
// prompt can time. Same /feedback endpoint, trigger 'manual'.
import { ref } from 'vue'
import api from '../services/api'

const open = ref(false)
const message = ref('')
const sending = ref(false)
const sent = ref(false)

async function send() {
  if (sending.value || !message.value.trim()) return
  sending.value = true
  try {
    await api.post('/feedback', {
      message: message.value.trim(),
      page: window.location.pathname,
      trigger: 'manual',
    })
    sent.value = true
    message.value = ''
    setTimeout(() => { open.value = false; sent.value = false }, 2200)
  } catch { open.value = false }
  finally { sending.value = false }
}
</script>

<template>
  <button class="fb-btn" type="button" title="Send feedback" @click="open = !open">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8m-8 4h5m-9 6V6a2 2 0 012-2h12a2 2 0 012 2v9a2 2 0 01-2 2H8l-4 3z"/>
    </svg>
    <span class="fb-label">Feedback</span>
  </button>

  <Teleport to="body">
    <div v-if="open" class="fb-pop">
      <template v-if="sent">
        <div class="fb-title">Thank you! 🙏</div>
        <div class="fb-sub">A human reads every one.</div>
      </template>
      <template v-else>
        <button class="fb-close" type="button" @click="open = false">×</button>
        <div class="fb-title">Tell us anything</div>
        <div class="fb-sub">Bug, idea, complaint — it goes straight to the founders.</div>
        <textarea v-model="message" class="fb-input" rows="4" maxlength="2000" placeholder="What's on your mind?"></textarea>
        <button class="fb-send" type="button" :disabled="sending || !message.trim()" @click="send">
          {{ sending ? 'Sending…' : 'Send' }}
        </button>
      </template>
    </div>
  </Teleport>
</template>

<style scoped>
.fb-btn { position: relative; display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 10px; border-radius: 7px; border: none; background: transparent; color: var(--color-text-muted); font-family: inherit; font-size: 12px; cursor: pointer; transition: .15s; text-align: left; }
.fb-btn:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.fb-pop { position: fixed; left: 210px; bottom: 18px; z-index: 240; width: 290px; background: #17171f; border: 1px solid #2a2a36; border-radius: 12px; padding: 16px; box-shadow: 0 12px 40px rgba(0,0,0,.5); color: #ececf3; display: flex; flex-direction: column; gap: 8px; }
.fb-close { position: absolute; top: 8px; right: 12px; background: none; border: none; color: #a1a1b5; font-size: 16px; cursor: pointer; }
.fb-title { font-size: 13.5px; font-weight: 700; }
.fb-sub { font-size: 11.5px; color: #a1a1b5; line-height: 1.5; }
.fb-input { width: 100%; padding: 8px 10px; background: #0f0f16; border: 1px solid #2a2a36; border-radius: 8px; color: #ececf3; font-family: inherit; font-size: 12.5px; resize: none; }
.fb-send { align-self: flex-end; background: #ff6b35; color: #fff; border: none; border-radius: 7px; padding: 7px 16px; font-size: 12px; font-weight: 700; font-family: inherit; cursor: pointer; }
.fb-send:disabled { opacity: .5; cursor: default; }
.sidebar.collapsed .fb-label { display: none; }
</style>
