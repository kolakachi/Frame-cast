<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'

const route = useRoute()
const token = computed(() => String(route.params.token || ''))

const loading = ref(true)
const error   = ref('')
const approval = ref(null)
const submitting = ref(false)
const decision   = ref('')
const comment    = ref('')
const reviewerName = ref('')
const justDecided = ref(false)

const apiBase = import.meta.env.VITE_API_URL || ''
const api = axios.create({ baseURL: `${apiBase}/api/v1` })

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get(`/approve/${token.value}`)
    approval.value = data?.data?.approval ?? null
    if (!approval.value) error.value = 'This approval link is invalid.'
    if (approval.value) reviewerName.value = approval.value.reviewer_name || ''
  } catch (e) {
    error.value = e?.response?.data?.error?.message || 'Could not load approval.'
  } finally {
    loading.value = false
  }
}

async function submit(d) {
  if (submitting.value) return
  decision.value = d
  submitting.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/approve/${token.value}/decide`, {
      decision: d,
      comment: comment.value || null,
      reviewer_name: reviewerName.value || null,
    })
    approval.value = data?.data?.approval ?? approval.value
    justDecided.value = true
  } catch (e) {
    error.value = e?.response?.data?.error?.message || 'Could not submit response.'
  } finally {
    submitting.value = false
  }
}

onMounted(load)

const statusLabel = computed(() => {
  const s = approval.value?.status
  if (!s) return ''
  return { pending: 'Awaiting your review', approved: 'Approved', rejected: 'Rejected', cancelled: 'Cancelled' }[s] || s
})
const isReviewable = computed(() => approval.value?.status === 'pending' && !approval.value?.is_expired)
</script>

<template>
  <div class="rv-shell">
    <header class="rv-head">
      <a href="https://wyvstudio.com" class="rv-brand" target="_blank" rel="noopener">
        <svg width="22" height="22" viewBox="0 0 64 64" fill="none"><rect width="64" height="64" rx="14" fill="#ff6b35"/><path d="M14 18 L22 46 L32 28 L42 46 L50 18" stroke="white" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
        <span><em>Wyv</em>Studio</span>
      </a>
    </header>

    <main class="rv-main">
      <div v-if="loading" class="rv-loading">Loading…</div>

      <div v-else-if="error && !approval" class="rv-error-page">
        <div class="rv-error-icon">⚠</div>
        <div class="rv-error-title">Could not load this approval</div>
        <div class="rv-error-body">{{ error }}</div>
      </div>

      <template v-else-if="approval">
        <div class="rv-card">
          <div class="rv-card-head">
            <div>
              <div class="rv-title">{{ approval.project_title }}</div>
              <div :class="['rv-status', `rv-status-${approval.status}`]">
                {{ approval.is_expired && approval.status === 'pending' ? 'Link expired' : statusLabel }}
              </div>
            </div>
            <div v-if="approval.expires_at && approval.status === 'pending' && !approval.is_expired" class="rv-expiry">
              Expires {{ new Date(approval.expires_at).toLocaleDateString() }}
            </div>
          </div>

          <!-- Video player -->
          <div v-if="approval.video_url" class="rv-video-wrap">
            <video :src="approval.video_url" controls playsinline class="rv-video"></video>
          </div>
          <div v-else class="rv-video-missing">
            Video is not yet available.
          </div>

          <!-- Script preview -->
          <details v-if="approval.project_script" class="rv-script">
            <summary>View script</summary>
            <div class="rv-script-body">{{ approval.project_script }}</div>
          </details>

          <!-- After-decision message -->
          <div v-if="justDecided || ['approved', 'rejected', 'cancelled'].includes(approval.status)" class="rv-decided">
            <div class="rv-decided-icon">{{ approval.status === 'approved' ? '✓' : approval.status === 'rejected' ? '✕' : '·' }}</div>
            <div class="rv-decided-title">
              <template v-if="approval.status === 'approved'">Thanks — you approved this video.</template>
              <template v-else-if="approval.status === 'rejected'">Thanks — you rejected this video.</template>
              <template v-else>This approval has been cancelled.</template>
            </div>
            <div v-if="approval.comment" class="rv-decided-comment">
              Your note: <em>"{{ approval.comment }}"</em>
            </div>
            <div class="rv-decided-sub">The team has been notified. You can close this page.</div>
          </div>

          <!-- Review form -->
          <div v-else-if="isReviewable" class="rv-review">
            <div class="rv-field">
              <label class="rv-label">Your name (optional)</label>
              <input v-model="reviewerName" class="rv-input" placeholder="So the team knows who reviewed" />
            </div>
            <div class="rv-field">
              <label class="rv-label">Notes for the team (optional)</label>
              <textarea v-model="comment" rows="3" class="rv-textarea" placeholder="Any feedback, changes you'd like, or context…"></textarea>
            </div>

            <div v-if="error" class="rv-error-inline">{{ error }}</div>

            <div class="rv-actions">
              <button class="rv-btn rv-btn-reject" :disabled="submitting" @click="submit('rejected')">
                ✕ Reject
              </button>
              <button class="rv-btn rv-btn-approve" :disabled="submitting" @click="submit('approved')">
                ✓ Approve
              </button>
            </div>
          </div>

          <div v-else-if="approval.is_expired" class="rv-expired-notice">
            This approval link has expired. Please ask the requester to send a new link.
          </div>
        </div>

        <div class="rv-footnote">
          You're reviewing as <strong>{{ approval.reviewer_email }}</strong>. No account required.
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
.rv-shell { min-height: 100vh; background: #0a0a0f; color: #ececf3; font-family: "DM Sans", -apple-system, sans-serif; }
.rv-head { padding: 18px 32px; border-bottom: 1px solid #25252f; }
.rv-brand { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; font-size: 16px; font-weight: 700; }
.rv-brand em { color: #ff6b35; font-style: normal; }
.rv-main { max-width: 720px; margin: 32px auto 80px; padding: 0 20px; }

.rv-loading, .rv-error-page { text-align: center; padding: 60px 20px; color: #8b8b9a; }
.rv-error-icon { font-size: 36px; color: #f87171; margin-bottom: 12px; }
.rv-error-title { font-size: 18px; font-weight: 600; color: #ececf3; margin-bottom: 6px; }
.rv-error-body { font-size: 13px; }

.rv-card { background: #14141c; border: 1px solid #25252f; border-radius: 14px; padding: 24px; }
.rv-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.rv-title { font-size: 20px; font-weight: 700; letter-spacing: -.3px; margin-bottom: 6px; }
.rv-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.rv-status-pending { background: rgba(96,165,250,.12); color: #60a5fa; }
.rv-status-approved { background: rgba(52,211,153,.12); color: #34d399; }
.rv-status-rejected { background: rgba(248,113,113,.12); color: #f87171; }
.rv-status-cancelled { background: rgba(255,255,255,.05); color: #8b8b9a; }
.rv-expiry { font-size: 11px; color: #8b8b9a; }

.rv-video-wrap { background: #000; border-radius: 10px; overflow: hidden; margin-bottom: 18px; aspect-ratio: 9 / 16; max-height: 540px; display: flex; align-items: center; justify-content: center; }
.rv-video { width: 100%; height: 100%; display: block; }
.rv-video-missing { background: #1a1a23; border: 1px dashed #2a2a36; border-radius: 10px; padding: 32px; text-align: center; color: #8b8b9a; font-size: 13px; margin-bottom: 18px; }

.rv-script { background: #1a1a23; border: 1px solid #25252f; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; }
.rv-script summary { cursor: pointer; font-size: 12px; color: #8b8b9a; font-weight: 500; }
.rv-script-body { margin-top: 10px; font-size: 13px; line-height: 1.55; color: #c9cad4; white-space: pre-wrap; }

.rv-review { padding-top: 8px; }
.rv-field { margin-bottom: 14px; }
.rv-label { display: block; font-size: 11px; color: #8b8b9a; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 6px; font-weight: 500; }
.rv-input, .rv-textarea { width: 100%; background: #0a0a0f; border: 1px solid #25252f; border-radius: 7px; color: #ececf3; padding: 10px 12px; font-size: 14px; font-family: inherit; outline: none; transition: border-color .15s; }
.rv-input:focus, .rv-textarea:focus { border-color: #ff6b35; }
.rv-textarea { resize: vertical; line-height: 1.5; }

.rv-error-inline { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.2); color: #fca5a5; border-radius: 7px; padding: 10px 12px; font-size: 12px; margin: 8px 0; }

.rv-actions { display: flex; gap: 10px; margin-top: 18px; }
.rv-btn { flex: 1; padding: 12px 18px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; border: 1px solid transparent; font-family: inherit; transition: .15s; }
.rv-btn:disabled { opacity: .5; cursor: not-allowed; }
.rv-btn-approve { background: #16a34a; color: #fff; border-color: #16a34a; }
.rv-btn-approve:hover:not(:disabled) { background: #15803d; }
.rv-btn-reject { background: transparent; color: #f87171; border-color: rgba(248,113,113,.4); }
.rv-btn-reject:hover:not(:disabled) { background: rgba(248,113,113,.1); }

.rv-decided { text-align: center; padding: 28px 16px; }
.rv-decided-icon { font-size: 32px; margin-bottom: 10px; color: #34d399; }
.rv-decided-title { font-size: 16px; font-weight: 600; margin-bottom: 8px; }
.rv-decided-comment { font-size: 13px; color: #c9cad4; margin-bottom: 10px; }
.rv-decided-sub { font-size: 12px; color: #8b8b9a; }

.rv-expired-notice { background: rgba(248,113,113,.08); border: 1px solid rgba(248,113,113,.2); border-radius: 8px; padding: 14px 16px; color: #fca5a5; font-size: 13px; text-align: center; }

.rv-footnote { text-align: center; font-size: 12px; color: #8b8b9a; margin-top: 20px; }
</style>
