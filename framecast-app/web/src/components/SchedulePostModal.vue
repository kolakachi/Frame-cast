<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const router = useRouter()

const props = defineProps({
  exportJobId: { type: Number, default: null },
})
const emit = defineEmits(['close', 'scheduled'])

// ── State ─────────────────────────────────────────────────
const accounts   = ref([])
const exportJobs = ref([])
const loading    = ref(true)
const saving     = ref(false)
const error      = ref('')

const selectedExportId  = ref(props.exportJobId)
const selectedAccountIds= ref([])
const captions          = ref({})   // { accountId: string }
const titles            = ref({})   // { accountId: string } — YouTube only
const activeCaption     = ref(null) // accountId of the caption tab open
const generatingCaption = ref({})   // { accountId: bool }
const resultMode        = ref(false)
const resultPosts       = ref([])   // [{ id, platform, display_name, status }]
const pollTimer         = ref(null)
const category          = ref('Education')
const visibility        = ref('public')
const whenMode          = ref('schedule') // schedule | now | draft
const scheduledDate     = ref('')
const scheduledTime     = ref('09:00')

const PLATFORMS = { youtube: '▶ YouTube', tiktok: '♪ TikTok', instagram: '◈ Instagram', facebook: 'f Facebook' }
const CATEGORIES = ['Education', 'Entertainment', 'People & Blogs', 'News & Politics', 'Science & Technology', 'How-to & Style']

const selectedAccounts = computed(() =>
  accounts.value.filter(a => selectedAccountIds.value.includes(a.id))
)

const canSubmit = computed(() =>
  selectedAccountIds.value.length > 0 &&
  selectedExportId.value &&
  (whenMode.value !== 'schedule' || (scheduledDate.value && scheduledTime.value))
)

// ── Load ──────────────────────────────────────────────────
onMounted(async () => {
  try {
    const requests = [api.get('/social/accounts')]
    if (!props.exportJobId) requests.push(api.get('/exports/completed'))

    const [accRes, expRes] = await Promise.all(requests)
    accounts.value = (accRes.data?.data?.accounts ?? []).filter(a => a.status === 'active')
    exportJobs.value = expRes?.data?.data?.exports ?? []

    const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1)
    scheduledDate.value = tomorrow.toISOString().split('T')[0]
  } finally {
    loading.value = false
  }
})

function toggleAccount(accountId) {
  if (selectedAccountIds.value.includes(accountId)) {
    selectedAccountIds.value = selectedAccountIds.value.filter(id => id !== accountId)
    if (activeCaption.value === accountId) {
      activeCaption.value = selectedAccountIds.value[0] ?? null
    }
  } else {
    selectedAccountIds.value = [...selectedAccountIds.value, accountId]
    if (!activeCaption.value) activeCaption.value = accountId
  }
}

// ── AI caption ───────────────────────────────────────────
async function generateCaption(accountId, platform) {
  if (!selectedExportId.value || generatingCaption.value[accountId]) return
  generatingCaption.value = { ...generatingCaption.value, [accountId]: true }
  try {
    const res = await api.post('/social/generate-caption', {
      export_job_id: selectedExportId.value,
      platform,
    })
    captions.value = { ...captions.value, [accountId]: res.data?.data?.caption ?? '' }
  } finally {
    generatingCaption.value = { ...generatingCaption.value, [accountId]: false }
  }
}

// ── Submit ────────────────────────────────────────────────
async function submit() {
  if (!canSubmit.value || saving.value) return
  saving.value = true
  error.value  = ''

  const scheduledAt = whenMode.value === 'schedule'
    ? new Date(`${scheduledDate.value}T${scheduledTime.value}`).toISOString()
    : null

  try {
    const created = []
    for (const accountId of selectedAccountIds.value) {
      const account = accounts.value.find(a => a.id === accountId)
      const res = await api.post('/scheduled-posts', {
        export_job_id:     selectedExportId.value,
        social_account_id: accountId,
        caption:           captions.value[accountId] ?? captions.value['default'] ?? '',
        title:             account?.platform === 'youtube' ? (titles.value[accountId] ?? '') : undefined,
        category:          account?.platform === 'youtube' ? category.value : undefined,
        visibility:        visibility.value,
        scheduled_at:      scheduledAt,
        publish_now:       whenMode.value === 'now',
      })
      created.push({
        id:           res.data?.data?.post?.id,
        platform:     account?.platform,
        display_name: account?.platform_display_name || account?.platform_username,
        status:       whenMode.value === 'now' ? 'pending' : (whenMode.value === 'draft' ? 'draft' : 'scheduled'),
      })
    }

    resultPosts.value = created
    resultMode.value  = true
    emit('scheduled', { mode: whenMode.value, posts: created })

    if (whenMode.value === 'now') {
      startPolling(created.map(p => p.id).filter(Boolean))
    }
  } catch (e) {
    error.value = e?.response?.data?.error?.message || 'Failed to schedule post.'
    saving.value = false
  }
}

function startPolling(ids) {
  if (!ids.length) return
  let attempts = 0
  pollTimer.value = setInterval(async () => {
    attempts++
    if (attempts > 15) { clearInterval(pollTimer.value); return }
    try {
      const res = await api.get('/scheduled-posts', { params: { per_page: 50 } })
      const posts = res.data?.data?.posts ?? []
      let allDone = true
      resultPosts.value = resultPosts.value.map(rp => {
        const fresh = posts.find(p => p.id === rp.id)
        if (!fresh) return rp
        if (fresh.status === 'pending' || fresh.status === 'processing') allDone = false
        return { ...rp, status: fresh.status, error_message: fresh.failure_reason }
      })
      if (allDone) clearInterval(pollTimer.value)
    } catch { /* silent */ }
  }, 2000)
}

onUnmounted(() => { if (pollTimer.value) clearInterval(pollTimer.value) })
</script>

<template>
  <Teleport to="body">
    <div class="sp-backdrop" @click.self="emit('close')">
      <div class="sp-modal">
        <div class="sp-header">
          <div class="sp-title">Schedule Post</div>
          <button class="sp-close" @click="emit('close')">×</button>
        </div>

        <div v-if="loading" class="sp-loading">Loading…</div>

        <!-- ── Result state ─────────────────────────────────── -->
        <div v-else-if="resultMode" class="sp-result">
          <div
            v-for="rp in resultPosts" :key="rp.id"
            :class="['sp-result-row', rp.status === 'published' ? 'success' : rp.status === 'failed' ? 'fail' : 'pending']"
          >
            <span class="sp-result-icon">
              {{ rp.status === 'published' ? '✓' : rp.status === 'failed' ? '✕' : '…' }}
            </span>
            <div class="sp-result-info">
              <div class="sp-result-name">{{ { youtube:'YouTube', tiktok:'TikTok', instagram:'Instagram', facebook:'Facebook' }[rp.platform] }} · {{ rp.display_name }}</div>
              <div class="sp-result-status">
                <template v-if="rp.status === 'published'">Published successfully</template>
                <template v-else-if="rp.status === 'failed'">Failed{{ rp.error_message ? ` — ${rp.error_message}` : '' }}</template>
                <template v-else-if="rp.status === 'scheduled'">Scheduled</template>
                <template v-else-if="rp.status === 'draft'">Saved as draft</template>
                <template v-else>Publishing…</template>
              </div>
            </div>
          </div>
          <div class="sp-footer" style="margin-top:16px">
            <button class="sp-btn" @click="emit('close')">Close</button>
            <button class="sp-btn sp-btn-primary" @click="emit('close'); router.push({ name: 'calendar' })">View in Calendar →</button>
          </div>
        </div>

        <template v-else>
          <!-- No accounts connected -->
          <div v-if="accounts.length === 0" class="sp-empty">
            <div class="sp-empty-icon">🔗</div>
            <div class="sp-empty-title">No accounts connected</div>
            <div class="sp-empty-desc">Connect a social account in Settings before scheduling posts.</div>
            <button class="sp-btn sp-btn-primary" @click="emit('close'); $router.push({ name: 'settings', query: { section: 'accounts' } })">Go to Settings →</button>
          </div>

          <template v-else>
            <!-- Export picker (only when not launched from editor) -->
            <div v-if="!exportJobId" class="sp-field">
              <div class="sp-label">Video export</div>
              <div v-if="!exportJobs.length" class="sp-no-exports">No completed exports yet. Export a video first.</div>
              <select v-else v-model="selectedExportId" class="sp-select">
                <option :value="null" disabled>Select a video export…</option>
                <option v-for="e in exportJobs" :key="e.id" :value="e.id">
                  {{ e.project_title }}{{ e.aspect_ratio ? ` · ${e.aspect_ratio}` : '' }}{{ e.completed_at ? ` · ${new Date(e.completed_at).toLocaleDateString('en', { month: 'short', day: 'numeric' })}` : '' }}
                </option>
              </select>
            </div>

            <!-- Platform selector -->
            <div class="sp-field">
              <div class="sp-label">Publish to</div>
              <div class="sp-platform-grid">
                <button
                  v-for="account in accounts" :key="account.id"
                  :class="['sp-platform-opt', selectedAccountIds.includes(account.id) ? 'selected' : '']"
                  @click="toggleAccount(account.id)"
                >
                  <span class="sp-plat-icon">{{ { youtube:'▶', tiktok:'♪', instagram:'◈', facebook:'f' }[account.platform] }}</span>
                  <div>
                    <div class="sp-plat-name">{{ { youtube:'YouTube', tiktok:'TikTok', instagram:'Instagram', facebook:'Facebook' }[account.platform] }}</div>
                    <div class="sp-plat-handle">{{ account.platform_display_name || account.platform_username }}</div>
                  </div>
                </button>
              </div>
            </div>

            <!-- Caption (per platform tab) -->
            <div v-if="selectedAccounts.length > 0" class="sp-field">
              <div class="sp-label">Caption</div>
              <div class="sp-caption-tabs">
                <button
                  v-for="acc in selectedAccounts" :key="acc.id"
                  :class="['sp-caption-tab', activeCaption === acc.id ? 'active' : '']"
                  @click="activeCaption = acc.id"
                >{{ { youtube:'▶', tiktok:'♪' }[acc.platform] }} {{ acc.platform_display_name?.split(' ')[0] || acc.platform }}</button>
              </div>
              <template v-for="acc in selectedAccounts" :key="acc.id">
                <div v-if="activeCaption === acc.id">
                  <textarea
                    v-model="captions[acc.id]"
                    class="sp-textarea"
                    rows="3"
                    :placeholder="acc.platform === 'youtube' ? 'Describe your video…' : 'Write your caption…'"
                    :maxlength="acc.platform === 'youtube' ? 5000 : 2200"
                  ></textarea>
                  <div class="sp-caption-footer">
                    <div class="sp-char-count">{{ (captions[acc.id] ?? '').length }} / {{ acc.platform === 'youtube' ? 5000 : 2200 }}</div>
                    <button class="sp-ai-btn" :disabled="!selectedExportId || generatingCaption[acc.id]" @click="generateCaption(acc.id, acc.platform)">
                      {{ generatingCaption[acc.id] ? 'Generating…' : '✦ Generate with AI' }}
                    </button>
                  </div>
                  <!-- YouTube extra fields -->
                  <template v-if="acc.platform === 'youtube'">
                    <input v-model="titles[acc.id]" class="sp-input" style="margin-top:8px" placeholder="Video title (YouTube)" maxlength="100">
                  </template>
                </div>
              </template>
            </div>

            <!-- YouTube category + visibility -->
            <div v-if="selectedAccounts.some(a => a.platform === 'youtube')" style="display:grid;grid-template-columns:1fr 1fr;gap:10px" class="sp-field">
              <div>
                <div class="sp-label">Category</div>
                <select v-model="category" class="sp-select">
                  <option v-for="c in CATEGORIES" :key="c" :value="c">{{ c }}</option>
                </select>
              </div>
              <div>
                <div class="sp-label">Visibility</div>
                <select v-model="visibility" class="sp-select">
                  <option value="public">Public</option>
                  <option value="unlisted">Unlisted</option>
                  <option value="private">Private</option>
                </select>
              </div>
            </div>

            <!-- When -->
            <div class="sp-field">
              <div class="sp-label">When</div>
              <div class="sp-when-tabs">
                <button :class="['sp-when-tab', whenMode === 'schedule' ? 'active' : '']" @click="whenMode = 'schedule'">Schedule for later</button>
                <button :class="['sp-when-tab', whenMode === 'now' ? 'active' : '']" @click="whenMode = 'now'">Publish now</button>
                <button :class="['sp-when-tab', whenMode === 'draft' ? 'active' : '']" @click="whenMode = 'draft'">Save as draft</button>
              </div>
              <div v-if="whenMode === 'schedule'" class="sp-datetime-row" style="margin-top:10px">
                <input v-model="scheduledDate" class="sp-input" type="date">
                <input v-model="scheduledTime" class="sp-input" type="time">
              </div>
            </div>

            <div v-if="error" class="sp-error">{{ error }}</div>

            <div class="sp-footer">
              <button class="sp-btn" @click="emit('close')">Cancel</button>
              <button class="sp-btn sp-btn-primary" :disabled="!canSubmit || saving" @click="submit">
                {{ saving ? 'Scheduling…' : whenMode === 'now' ? 'Publish now' : whenMode === 'draft' ? 'Save draft' : `Schedule for ${selectedAccountIds.length} platform${selectedAccountIds.length !== 1 ? 's' : ''}` }}
              </button>
            </div>
          </template>
        </template>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.sp-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 300; }
.sp-modal { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 14px; padding: 24px; width: min(520px, calc(100vw - 32px)); max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 60px rgba(0,0,0,.5); }
.sp-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.sp-title { font-size: 15px; font-weight: 600; }
.sp-close { background: none; border: none; color: var(--color-text-muted); cursor: pointer; font-size: 20px; line-height: 1; padding: 0; }
.sp-loading { padding: 24px 0; color: var(--color-text-muted); font-size: 13px; text-align: center; }
.sp-empty { text-align: center; padding: 24px 0; }
.sp-empty-icon { font-size: 32px; margin-bottom: 12px; }
.sp-empty-title { font-size: 14px; font-weight: 600; margin-bottom: 6px; }
.sp-empty-desc { font-size: 12px; color: var(--color-text-muted); margin-bottom: 16px; }
.sp-field { margin-bottom: 18px; }
.sp-label { font-size: 11px; font-weight: 500; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; }
.sp-platform-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
.sp-platform-opt { border: 1px solid var(--color-border); border-radius: 8px; padding: 10px 12px; cursor: pointer; transition: .15s; display: flex; align-items: center; gap: 10px; background: transparent; color: var(--color-text-primary); font-family: inherit; text-align: left; }
.sp-platform-opt:hover { background: var(--color-bg-elevated); border-color: var(--color-border-active); }
.sp-platform-opt.selected { border-color: var(--color-accent); background: rgba(255,107,53,.08); }
.sp-plat-icon { font-size: 16px; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; border-radius: 6px; background: var(--color-bg-elevated); flex-shrink: 0; }
.sp-plat-name { font-size: 12px; font-weight: 500; }
.sp-plat-handle { font-size: 10px; color: var(--color-text-muted); }
.sp-caption-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--color-border); margin-bottom: 8px; }
.sp-caption-tab { padding: 5px 12px; font-size: 11px; font-weight: 500; color: var(--color-text-muted); cursor: pointer; border-bottom: 2px solid transparent; transition: .15s; background: none; border-top: none; border-left: none; border-right: none; font-family: inherit; }
.sp-caption-tab.active { color: var(--color-accent); border-bottom-color: var(--color-accent); }
.sp-textarea { width: 100%; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); font-family: inherit; font-size: 13px; padding: 10px 12px; resize: vertical; outline: none; transition: border-color .15s; line-height: 1.5; }
.sp-textarea:focus { border-color: rgba(255,107,53,.45); }
.sp-caption-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 4px; }
.sp-char-count { font-size: 11px; color: var(--color-text-muted); }
.sp-ai-btn { font-size: 11px; font-weight: 500; color: var(--color-accent); background: rgba(255,107,53,.08); border: 1px solid rgba(255,107,53,.2); border-radius: 5px; padding: 3px 9px; cursor: pointer; font-family: inherit; transition: .15s; }
.sp-ai-btn:hover:not(:disabled) { background: rgba(255,107,53,.15); }
.sp-ai-btn:disabled { opacity: .45; cursor: not-allowed; }
.sp-no-exports { font-size: 12px; color: var(--color-text-muted); padding: 10px 12px; background: var(--color-bg-elevated); border-radius: 8px; border: 1px solid var(--color-border); }
.sp-input { width: 100%; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); font-family: inherit; font-size: 13px; padding: 9px 12px; outline: none; transition: border-color .15s; }
.sp-input:focus { border-color: rgba(255,107,53,.45); }
.sp-select { width: 100%; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); font-family: inherit; font-size: 13px; padding: 9px 12px; outline: none; }
.sp-when-tabs { display: flex; gap: 4px; flex-wrap: wrap; }
.sp-when-tab { padding: 6px 12px; font-size: 12px; font-weight: 500; border-radius: 6px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-muted); cursor: pointer; transition: .15s; font-family: inherit; }
.sp-when-tab:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.sp-when-tab.active { background: rgba(255,107,53,.1); color: var(--color-accent); border-color: rgba(255,107,53,.3); }
.sp-datetime-row { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.sp-result { padding: 8px 0; }
.sp-result-row { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 8px; margin-bottom: 8px; border: 1px solid var(--color-border); }
.sp-result-row.success { border-color: rgba(52,211,153,.25); background: rgba(52,211,153,.06); }
.sp-result-row.fail    { border-color: rgba(248,113,113,.25); background: rgba(248,113,113,.06); }
.sp-result-row.pending { border-color: rgba(255,107,53,.2);  background: rgba(255,107,53,.05); }
.sp-result-icon { font-size: 15px; font-weight: 700; width: 22px; text-align: center; flex-shrink: 0; margin-top: 1px; }
.sp-result-row.success .sp-result-icon { color: #34d399; }
.sp-result-row.fail    .sp-result-icon { color: #f87171; }
.sp-result-row.pending .sp-result-icon { color: var(--color-accent); }
.sp-result-info { flex: 1; min-width: 0; }
.sp-result-name   { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.sp-result-status { font-size: 12px; color: var(--color-text-muted); }
.sp-error { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.2); color: #fca5a5; border-radius: 8px; padding: 10px 12px; font-size: 12px; margin-bottom: 14px; }
.sp-footer { display: flex; justify-content: flex-end; gap: 8px; padding-top: 16px; border-top: 1px solid var(--color-border); }
.sp-btn { padding: 8px 16px; border-radius: 7px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid var(--color-border); color: var(--color-text-primary); background: transparent; font-family: inherit; transition: .15s; }
.sp-btn:hover { background: var(--color-bg-elevated); }
.sp-btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.sp-btn-primary:hover:not(:disabled) { opacity: .9; }
.sp-btn:disabled { opacity: .5; cursor: not-allowed; }
</style>
