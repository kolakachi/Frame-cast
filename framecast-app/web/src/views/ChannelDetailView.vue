<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const channelId = computed(() => route.params.channelId)
const mePayload = ref(null)
const channel = ref(null)
const brandKits = ref([])
const voiceProfiles = ref([])
const videos = ref([])
const loading = ref(true)
const error = ref('')
const activeTab = ref('overview')

const savePending = ref(false)
const saveError = ref('')
const formName = ref('')
const formDescription = ref('')
const formPlatformTargets = ref([])
const formDefaultLanguage = ref('en')
const formBrandKitId = ref('')
const formVoiceProfileId = ref('')

const archivePending = ref(false)
const archiveError = ref('')
const showArchiveConfirm = ref(false)

const GRAD_CLASSES = ['grad-red', 'grad-amber', 'grad-teal', 'grad-purple', 'grad-blue']
const EMOJIS = ['📡', '🎬', '📈', '🕵️', '🌆', '🔥', '🎭', '💡', '🎙️', '🌍']

const SOURCE_LABELS = {
  prompt: 'Prompt',
  script: 'Script',
  images: 'Images',
  product_description: 'Product',
  blank: 'Blank',
  video_upload: 'Video',
  url: 'URL',
  csv_topic: 'CSV',
}

const PLATFORM_OPTIONS = [
  { value: 'tiktok', label: 'TikTok', format: '9:16' },
  { value: 'instagram_reels', label: 'Instagram Reels', format: '9:16' },
  { value: 'youtube_shorts', label: 'YouTube Shorts', format: '9:16' },
  { value: 'instagram_post', label: 'Instagram Post', format: '1:1' },
  { value: 'youtube', label: 'YouTube', format: '16:9' },
  { value: 'facebook', label: 'Facebook', format: '16:9' },
]

const LANGUAGE_OPTIONS = [
  { value: 'en', label: 'English' },
  { value: 'es', label: 'Spanish' },
  { value: 'fr', label: 'French' },
  { value: 'de', label: 'German' },
  { value: 'pt', label: 'Portuguese' },
  { value: 'it', label: 'Italian' },
  { value: 'hi', label: 'Hindi' },
  { value: 'ja', label: 'Japanese' },
  { value: 'ar', label: 'Arabic' },
  { value: 'zh', label: 'Chinese' },
]

const channelGradClass = computed(() => {
  if (!channel.value) return GRAD_CLASSES[0]
  return GRAD_CLASSES[channel.value.id % GRAD_CLASSES.length]
})

const channelEmoji = computed(() => {
  if (!channel.value) return '📡'
  return EMOJIS[channel.value.id % EMOJIS.length]
})

const recentVideos = computed(() => videos.value.slice(0, 6))

const brandKitName = computed(() => {
  if (!channel.value?.brand_kit_id) return null
  const kit = brandKits.value.find((k) => k.id === channel.value.brand_kit_id)
  return kit?.name || null
})

const voiceName = computed(() => {
  if (!channel.value?.default_voice_profile_id) return null
  const v = voiceProfiles.value.find((p) => p.id === channel.value.default_voice_profile_id)
  return v?.name || v?.provider_voice_id || null
})

const platformLabels = computed(() => {
  if (!channel.value?.platform_targets?.length) return []
  return channel.value.platform_targets.map((t) => {
    const opt = PLATFORM_OPTIONS.find((o) => o.value === t)
    return opt?.label || t
  })
})

function languageLabel(code) {
  const lang = LANGUAGE_OPTIONS.find((l) => l.value === code)
  return lang?.label || code?.toUpperCase() || '—'
}

function platformFormat(targets) {
  if (!targets?.length) return null
  const t = targets[0]
  if (t === 'youtube' || t === 'facebook') return '16:9'
  if (t === 'instagram_post') return '1:1'
  return '9:16'
}

function formatDate(value) {
  if (!value) return '—'
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(value))
}

function statusColor(status) {
  if (status === 'published') return '#34d399'
  if (status === 'failed') return '#f87171'
  if (status === 'generating') return '#fbbf24'
  return 'var(--color-text-muted)'
}

function mapStatus(status) {
  const map = {
    draft:      { label: 'Draft',      cls: 'status-draft' },
    generating: { label: 'Generating', cls: 'status-generating' },
    ready:      { label: 'Ready',      cls: 'status-ready' },
    published:  { label: 'Published',  cls: 'status-published' },
    failed:     { label: 'Failed',     cls: 'status-failed' },
  }
  return map[status] || { label: status || 'Unknown', cls: 'status-draft' }
}

function sourceLabel(sourceType) {
  return SOURCE_LABELS[sourceType] || sourceType || 'Manual'
}

function vtClass(id) {
  return `vt-${(id % 5) + 1}`
}

function populateForm() {
  if (!channel.value) return
  formName.value = channel.value.name || ''
  formDescription.value = channel.value.description || ''
  formPlatformTargets.value = channel.value.platform_targets ? [...channel.value.platform_targets] : []
  formDefaultLanguage.value = channel.value.default_language || 'en'
  formBrandKitId.value = channel.value.brand_kit_id ? String(channel.value.brand_kit_id) : ''
  formVoiceProfileId.value = channel.value.default_voice_profile_id ? String(channel.value.default_voice_profile_id) : ''
}

function togglePlatform(value) {
  const idx = formPlatformTargets.value.indexOf(value)
  if (idx === -1) {
    formPlatformTargets.value.push(value)
  } else {
    formPlatformTargets.value.splice(idx, 1)
  }
}

async function loadData() {
  loading.value = true
  error.value = ''
  try {
    const [channelRes, brandKitsRes, voicesRes, meRes, videosRes] = await Promise.all([
      api.get(`/channels/${channelId.value}`),
      api.get('/brand-kits'),
      api.get('/voice-profiles'),
      api.get('/me'),
      api.get('/projects', { params: { channel_id: channelId.value, per_page: 24 } }),
    ])
    channel.value = channelRes.data?.data?.channel ?? null
    brandKits.value = brandKitsRes.data?.data?.brand_kits ?? []
    voiceProfiles.value = voicesRes.data?.data?.voice_profiles ?? []
    mePayload.value = meRes.data?.data?.user ?? null
    videos.value = videosRes.data?.data?.projects ?? []
    populateForm()
  } catch (err) {
    error.value = err?.response?.data?.error?.message || 'Could not load channel.'
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  savePending.value = true
  saveError.value = ''
  const payload = {
    name: formName.value.trim(),
    description: formDescription.value.trim() || null,
    platform_targets: formPlatformTargets.value,
    default_language: formDefaultLanguage.value || null,
    brand_kit_id: formBrandKitId.value ? Number(formBrandKitId.value) : null,
    default_voice_profile_id: formVoiceProfileId.value ? Number(formVoiceProfileId.value) : null,
  }
  try {
    await api.patch(`/channels/${channelId.value}`, payload)
    await loadData()
  } catch (err) {
    saveError.value = err?.response?.data?.error?.message || 'Could not save channel settings.'
  } finally {
    savePending.value = false
  }
}

async function archiveChannel() {
  archivePending.value = true
  archiveError.value = ''
  try {
    await api.delete(`/channels/${channelId.value}`)
    router.push({ name: 'channels' })
  } catch (err) {
    archiveError.value = err?.response?.data?.error?.message || 'Could not archive channel.'
    archivePending.value = false
  }
}

function openVideo(project) {
  router.push({ name: 'project-editor', params: { projectId: project.id } })
}

function openVariants(projectId) {
  router.push({ name: 'project-variants', params: { projectId } })
}

function openNewVideo() {
  router.push({
    name: 'dashboard',
    query: {
      new_video: '1',
      channel_id: String(channelId.value),
    },
  })
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

onMounted(loadData)
</script>

<template>
  <div class="shell">
    <AppSidebar :user="mePayload" active-page="channels" @logout="logout" />

    <main class="main">
      <!-- Topbar -->
      <div class="topbar">
        <div class="topbar-left">
          <button class="back-btn" type="button" @click="router.push({ name: 'channels' })">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"></path></svg>
          </button>
          <span class="bc-ws" @click="router.push({ name: 'channels' })">Channels</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">{{ channel?.name || '…' }}</span>
        </div>
        <div class="topbar-right">
          <button class="btn btn-primary btn-sm" type="button" @click="openNewVideo">+ New Video</button>
        </div>
      </div>

      <div v-if="error" class="banner error" style="margin: 20px 28px 0;">{{ error }}</div>
      <div v-if="loading" class="page-state">Loading channel…</div>

      <template v-else-if="channel">
        <!-- Hero -->
        <div class="hero-wrap">
          <div class="channel-hero" :class="channelGradClass">
            <div class="channel-hero-overlay"></div>
            <div class="channel-hero-content">
              <div class="channel-hero-icon">{{ channelEmoji }}</div>
              <div class="channel-hero-text">
                <div class="channel-hero-name">{{ channel.name }}</div>
                <div class="channel-hero-desc">
                  {{ channel.description || 'Set the defaults and output style for this channel.' }}
                  · {{ videos.length }} video{{ videos.length === 1 ? '' : 's' }}
                </div>
              </div>
              <div class="channel-hero-actions">
                <button class="btn btn-primary btn-sm" type="button" @click="openNewVideo">+ New Video</button>
                <button class="btn btn-sm" type="button" @click="activeTab = 'settings'">⚙ Settings</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="channel-tabs">
          <button :class="['ch-tab', activeTab === 'overview' ? 'active' : '']" type="button" @click="activeTab = 'overview'">Overview</button>
          <button :class="['ch-tab', activeTab === 'videos' ? 'active' : '']" type="button" @click="activeTab = 'videos'">
            Videos <span class="tab-cnt">{{ videos.length }}</span>
          </button>
          <button :class="['ch-tab', activeTab === 'brand' ? 'active' : '']" type="button" @click="activeTab = 'brand'">Brand</button>
          <button :class="['ch-tab', activeTab === 'settings' ? 'active' : '']" type="button" @click="activeTab = 'settings'">Settings</button>
        </div>

        <!-- ── Overview Tab ── -->
        <div v-if="activeTab === 'overview'" class="tab-content">

          <!-- Brand defaults -->
          <div class="section-hd">
            <div>
              <div class="eyebrow">Channel defaults</div>
              <div class="section-title">Brand Setup</div>
            </div>
            <button class="btn btn-ghost btn-sm" type="button" @click="activeTab = 'settings'">Edit settings →</button>
          </div>
          <div class="brand-defaults">
            <div class="brand-item">
              <div class="brand-item-label">Brand Kit</div>
              <div class="brand-item-val">{{ brandKitName || '—' }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Voice</div>
              <div class="brand-item-val">{{ voiceName || '—' }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Language</div>
              <div class="brand-item-val">{{ languageLabel(channel.default_language) }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Platforms</div>
              <div class="brand-item-val">{{ platformLabels.join(', ') || '—' }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Format</div>
              <div class="brand-item-val">{{ platformFormat(channel.platform_targets) || '—' }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Status</div>
              <div class="brand-item-val">{{ channel.status }}</div>
            </div>
          </div>

          <!-- Recent videos -->
          <div class="section-hd" style="margin-top: 32px;">
            <div>
              <div class="eyebrow">Latest output</div>
              <div class="section-title">Recent videos</div>
            </div>
            <div style="display:flex;gap:8px;">
              <button v-if="videos.length > 6" class="btn btn-ghost btn-sm" type="button" @click="activeTab = 'videos'">View all</button>
              <button class="btn btn-primary btn-sm" type="button" @click="openNewVideo">+ New Video</button>
            </div>
          </div>

          <div v-if="recentVideos.length === 0" class="empty-mini">
            <div class="empty-mini-text">No videos in this channel yet.</div>
            <button class="btn btn-primary btn-sm" type="button" @click="openNewVideo">+ New Video</button>
          </div>

          <div v-else class="ov-grid">
            <div
              v-for="video in recentVideos"
              :key="video.id"
              class="ov-card"
              @click="openVideo(video)"
            >
              <div class="ov-thumb" :class="vtClass(video.id)">
                <div class="ov-thumb-overlay"></div>
                <span class="ov-ar">{{ platformFormat(channel.platform_targets) || '9:16' }}</span>
              </div>
              <div class="ov-info">
                <div class="ov-title">{{ video.title || 'Untitled' }}</div>
                <div class="ov-meta">
                  <span :style="{ color: statusColor(video.status) }">{{ video.status }}</span>
                  · {{ formatDate(video.created_at) }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Videos Tab ── -->
        <div v-if="activeTab === 'videos'" class="tab-content">
          <div class="section-hd" style="margin-bottom:20px;">
            <div>
              <div class="eyebrow">All content</div>
              <div class="section-title">Videos</div>
            </div>
            <button class="btn btn-primary btn-sm" type="button" @click="openNewVideo">+ New Video</button>
          </div>

          <div v-if="videos.length === 0" class="empty-hero">
            <div class="empty-icon">🎬</div>
            <div class="empty-title">No videos yet</div>
            <div class="empty-body">Videos assigned to this channel will appear here.</div>
            <button class="btn btn-primary btn-sm" type="button" @click="openNewVideo">+ New Video</button>
          </div>

          <div v-else class="projects-grid">
            <article
              v-for="video in videos"
              :key="video.id"
              class="project-card"
              tabindex="0"
              role="button"
              @click="openVideo(video)"
              @keydown.enter="openVideo(video)"
            >
              <div class="project-thumb" :class="vtClass(video.id)">
                <div class="phone-frame">
                  <div class="phone-line"></div>
                  <div class="phone-line accent"></div>
                  <div class="phone-line"></div>
                  <div class="phone-line"></div>
                </div>
                <span class="aspect-badge">{{ platformFormat(channel.platform_targets) || video.aspect_ratio || '9:16' }}</span>
              </div>
              <div class="project-info">
                <div class="project-name">{{ video.title || `Project #${video.id}` }}</div>
                <div class="project-meta">
                  <span class="source-badge">{{ sourceLabel(video.source_type) }}</span>
                  <span>{{ Number(video.variants_count || 0) }} variants</span>
                </div>
                <div :class="['project-status', mapStatus(video.status).cls]">
                  {{ mapStatus(video.status).label }}
                </div>
                <div class="project-actions" @click.stop>
                  <button class="btn btn-ghost btn-sm" type="button" @click="openVariants(video.id)">Variants</button>
                  <button class="btn btn-ghost btn-sm" type="button" @click="openVideo(video)">Open</button>
                </div>
              </div>
            </article>
          </div>
        </div>

        <!-- ── Brand Tab ── -->
        <div v-if="activeTab === 'brand'" class="tab-content">
          <div class="section-hd" style="margin-bottom:20px;">
            <div>
              <div class="eyebrow">Channel defaults</div>
              <div class="section-title">Brand & Defaults</div>
            </div>
            <button class="btn btn-ghost btn-sm" type="button" @click="activeTab = 'settings'">Edit settings →</button>
          </div>
          <div class="brand-defaults">
            <div class="brand-item">
              <div class="brand-item-label">Brand Kit</div>
              <div class="brand-item-val">{{ brandKitName || '—' }}</div>
              <div class="brand-item-sub">Workspace brand kit</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Default Voice</div>
              <div class="brand-item-val">{{ voiceName || '—' }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Language</div>
              <div class="brand-item-val">{{ languageLabel(channel.default_language) }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Platforms</div>
              <div class="brand-item-val">{{ platformLabels.join(', ') || '—' }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Format</div>
              <div class="brand-item-val">{{ platformFormat(channel.platform_targets) || '—' }}</div>
            </div>
            <div class="brand-item">
              <div class="brand-item-label">Status</div>
              <div class="brand-item-val">{{ channel.status }}</div>
            </div>
          </div>
        </div>

        <!-- ── Settings Tab ── -->
        <div v-if="activeTab === 'settings'" class="tab-content">
          <div class="settings-layout">
            <div class="settings-main">
              <div class="card">
                <div class="card-hd">
                  <div class="eyebrow">Channel</div>
                  <div class="card-title">Settings</div>
                </div>

                <div v-if="saveError" class="banner error" style="margin-bottom:16px;">{{ saveError }}</div>

                <div class="fields">
                  <div class="field">
                    <label class="label">Channel Name <span class="req">*</span></label>
                    <input v-model="formName" class="input" type="text" placeholder="e.g. Dark History Shorts" maxlength="255" />
                  </div>

                  <div class="field">
                    <label class="label">Description</label>
                    <textarea v-model="formDescription" class="input textarea" rows="3" placeholder="What is this channel about?"></textarea>
                  </div>

                  <div class="field">
                    <label class="label">Platform Targets</label>
                    <div class="platform-chips">
                      <button
                        v-for="opt in PLATFORM_OPTIONS"
                        :key="opt.value"
                        :class="['chip', formPlatformTargets.includes(opt.value) ? 'active' : '']"
                        type="button"
                        @click="togglePlatform(opt.value)"
                      >
                        {{ opt.label }}
                        <span class="chip-format">{{ opt.format }}</span>
                      </button>
                    </div>
                  </div>

                  <div class="field">
                    <label class="label">Default Language</label>
                    <select v-model="formDefaultLanguage" class="input">
                      <option v-for="lang in LANGUAGE_OPTIONS" :key="lang.value" :value="lang.value">{{ lang.label }}</option>
                    </select>
                  </div>

                  <div class="field">
                    <label class="label">Brand Kit</label>
                    <select v-model="formBrandKitId" class="input">
                      <option value="">— None —</option>
                      <option v-for="kit in brandKits" :key="kit.id" :value="String(kit.id)">{{ kit.name }}</option>
                    </select>
                  </div>

                  <div class="field">
                    <label class="label">Default Voice</label>
                    <select v-model="formVoiceProfileId" class="input">
                      <option value="">— None —</option>
                      <option v-for="voice in voiceProfiles" :key="voice.id" :value="String(voice.id)">
                        {{ voice.name || voice.provider_voice_id }}
                      </option>
                    </select>
                  </div>
                </div>

                <div class="card-footer">
                  <button class="btn btn-primary" type="button" :disabled="savePending || !formName.trim()" @click="saveSettings">
                    {{ savePending ? 'Saving…' : 'Save Changes' }}
                  </button>
                </div>
              </div>

              <!-- Danger zone -->
              <div class="card danger-card">
                <div class="card-hd">
                  <div class="eyebrow danger-eyebrow">Danger Zone</div>
                  <div class="card-title">Archive Channel</div>
                </div>
                <p class="danger-body">Archiving removes this channel from your active list. Existing videos remain accessible and are not deleted.</p>
                <div v-if="archiveError" class="banner error" style="margin-top:10px;">{{ archiveError }}</div>
                <button class="btn btn-danger" type="button" @click="showArchiveConfirm = true">Archive channel</button>
              </div>
            </div>
          </div>
        </div>
      </template>
    </main>

    <!-- Archive confirm modal -->
    <div v-if="showArchiveConfirm" class="modal-overlay" @click.self="showArchiveConfirm = false">
      <div class="confirm-modal">
        <h3>Archive channel?</h3>
        <p>
          <strong>{{ channel?.name }}</strong> will be archived. Existing videos will remain accessible.
        </p>
        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" :disabled="archivePending" @click="showArchiveConfirm = false">Cancel</button>
          <button class="btn btn-danger" type="button" :disabled="archivePending" @click="archiveChannel">
            {{ archivePending ? 'Archiving…' : 'Archive' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.shell { min-height: 100vh; background: var(--color-bg-deep); color: var(--color-text-primary); font-family: "DM Sans", sans-serif; display: flex; }
.main { margin-left: var(--sidebar-width, 220px); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* Topbar */
.topbar { position: sticky; top: 0; z-index: 90; height: 58px; background: rgba(10,10,15,0.88); border-bottom: 1px solid var(--color-border); backdrop-filter: blur(14px); padding: 0 28px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.topbar-left { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.back-btn { display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-muted); cursor: pointer; transition: 0.15s; flex-shrink: 0; }
.back-btn:hover { color: var(--color-text-primary); border-color: var(--color-border-active); }
.bc-ws { color: var(--color-text-muted); cursor: pointer; }
.bc-ws:hover { color: var(--color-text-primary); }
.bc-sep { color: var(--color-text-muted); }
.bc-page { font-weight: 600; color: var(--color-text-primary); }

/* Hero */
.hero-wrap { padding: 20px 28px 0; flex-shrink: 0; }
.channel-hero { border-radius: 16px; overflow: hidden; position: relative; height: 180px; display: flex; align-items: flex-end; padding: 24px; }
.channel-hero-overlay { position: absolute; inset: 0; background: linear-gradient(135deg, rgba(0,0,0,0.55) 0%, transparent 60%, rgba(0,0,0,0.3)); }
.channel-hero-content { position: relative; z-index: 1; display: flex; align-items: flex-end; gap: 18px; width: 100%; }
.channel-hero-icon { font-size: 48px; line-height: 1; }
.channel-hero-text { flex: 1; min-width: 0; }
.channel-hero-name { font-size: 24px; font-weight: 700; color: #fff; margin-bottom: 4px; }
.channel-hero-desc { font-size: 13px; color: rgba(255,255,255,0.7); }
.channel-hero-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* Gradient classes */
.grad-red    { background: linear-gradient(135deg, #1a0005 0%, #3d0010 50%, #0a0a1a 100%); }
.grad-amber  { background: linear-gradient(135deg, #1a0e00 0%, #3d2600 50%, #0a0a1a 100%); }
.grad-teal   { background: linear-gradient(135deg, #001a18 0%, #003d36 50%, #0a0a1a 100%); }
.grad-purple { background: linear-gradient(135deg, #1a0a2e 0%, #2d1457 50%, #0a0a1a 100%); }
.grad-blue   { background: linear-gradient(135deg, #000d1a 0%, #001a3d 50%, #0a0a1a 100%); }

/* Tabs */
.channel-tabs { display: flex; gap: 2px; padding: 0 28px; border-bottom: 1px solid var(--color-border); background: var(--color-bg-deep); flex-shrink: 0; margin-top: 16px; }
.ch-tab { padding: 10px 18px 11px; font-size: 13px; font-weight: 500; color: var(--color-text-muted); cursor: pointer; border: none; border-bottom: 2px solid transparent; background: transparent; margin-bottom: -1px; transition: 0.15s; display: flex; align-items: center; gap: 6px; }
.ch-tab:hover { color: var(--color-text-primary); }
.ch-tab.active { color: var(--color-accent); border-bottom-color: var(--color-accent); font-weight: 600; }
.tab-cnt { font-family: "Space Mono", monospace; font-size: 10px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 999px; padding: 1px 6px; color: var(--color-text-muted); }

/* Tab content */
.tab-content { padding: 28px; flex: 1; }

/* Section header */
.section-hd { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 16px; }
.eyebrow { font-family: "Space Mono", monospace; font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-accent); margin-bottom: 3px; }
.section-title { font-size: 18px; font-weight: 700; color: var(--color-text-primary); }

/* Brand defaults */
.brand-defaults { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 20px; display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.brand-item { display: flex; flex-direction: column; gap: 5px; }
.brand-item-label { font-size: 10px; font-family: "Space Mono", monospace; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-muted); }
.brand-item-val { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.brand-item-sub { font-size: 11px; color: var(--color-text-muted); }

/* Overview video grid */
.ov-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.ov-card { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden; cursor: pointer; transition: border-color 0.15s; }
.ov-card:hover { border-color: var(--color-border-active); }
.ov-thumb { aspect-ratio: 16/9; position: relative; overflow: hidden; }
.ov-thumb-overlay { position: absolute; inset: 0; background: linear-gradient(180deg, transparent 40%, rgba(0,0,0,0.45)); }
.ov-ar { position: absolute; top: 8px; right: 8px; font-family: "Space Mono", monospace; font-size: 9px; background: rgba(0,0,0,0.5); color: var(--color-text-primary); padding: 2px 6px; border-radius: 4px; z-index: 1; }
.ov-info { padding: 10px 12px; }
.ov-title { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 3px; }
.ov-meta { font-size: 11px; color: var(--color-text-muted); }

/* Video thumb gradients */
.vt-1 { background: linear-gradient(135deg, #141729, #1a223d); }
.vt-2 { background: linear-gradient(135deg, #1a1407, #2d220a); }
.vt-3 { background: linear-gradient(135deg, #0a1a14, #0d2a1f); }
.vt-4 { background: linear-gradient(135deg, #1a0a0a, #2a1010); }
.vt-5 { background: linear-gradient(135deg, #0d0d1a, #1a1a2d); }

/* Videos tab — project card grid (matches VideosView style) */
.projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }
.project-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; transition: 0.22s ease; text-align: left; cursor: pointer; }
.project-card:hover { transform: translateY(-2px); border-color: var(--color-border-active); }

.project-thumb { height: 154px; position: relative; overflow: hidden; }
.project-thumb::after { content: ""; position: absolute; inset: auto 0 0; height: 50%; background: linear-gradient(180deg, transparent, rgba(0,0,0,0.35)); }
.phone-frame { width: 62px; height: 112px; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); border: 2px solid rgba(255,255,255,0.14); border-radius: 11px; display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 10px; }
.phone-line { height: 3px; border-radius: 999px; background: rgba(255,255,255,0.12); }
.phone-line.accent { background: var(--color-accent); opacity: 0.65; }
.aspect-badge { position: absolute; z-index: 1; padding: 4px 8px; border-radius: 4px; background: rgba(0,0,0,0.5); color: var(--color-text-primary); font-family: "Space Mono", monospace; font-size: 10px; top: 10px; right: 10px; }

.project-info { padding: 12px 14px 14px; }
.project-name { font-size: 13px; font-weight: 700; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 7px; }
.project-meta { display: flex; align-items: center; gap: 7px; font-size: 11px; color: var(--color-text-muted); flex-wrap: wrap; margin-bottom: 8px; }
.source-badge { display: inline-block; padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 600; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12); color: var(--color-text-muted); }
.project-status { font-size: 11px; font-weight: 600; margin-bottom: 10px; }
.status-draft      { color: var(--color-text-muted); }
.status-generating { color: #fbbf24; }
.status-ready      { color: #34d399; }
.status-published  { color: #34d399; }
.status-failed     { color: #f87171; }
.project-actions { display: flex; gap: 6px; }

/* Empty mini */
.empty-mini { padding: 32px; text-align: center; border: 1px dashed var(--color-border); border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.empty-mini-text { font-size: 13px; color: var(--color-text-muted); }

/* Empty hero */
.empty-hero { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 60px 24px; gap: 12px; border: 1px dashed var(--color-border); border-radius: 16px; }
.empty-icon { font-size: 40px; }
.empty-title { font-size: 18px; font-weight: 700; color: var(--color-text-primary); }
.empty-body { font-size: 13px; color: var(--color-text-muted); line-height: 1.6; max-width: 360px; }

/* Settings layout */
.settings-layout { max-width: 640px; display: flex; flex-direction: column; gap: 18px; }
.settings-main { display: flex; flex-direction: column; gap: 18px; }

/* Cards */
.card { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 22px; }
.card-hd { margin-bottom: 18px; }
.card-title { font-size: 18px; font-weight: 700; color: var(--color-text-primary); }
.card-footer { display: flex; justify-content: flex-end; margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--color-border); }
.danger-card { border-color: rgba(248,113,113,0.25); }
.danger-eyebrow { color: #f87171; }
.danger-body { font-size: 13px; color: var(--color-text-muted); line-height: 1.55; margin-bottom: 16px; }

/* Form */
.fields { display: flex; flex-direction: column; gap: 16px; }
.field { display: flex; flex-direction: column; gap: 6px; }
.label { font-size: 12px; font-weight: 600; color: var(--color-text-secondary); }
.req { color: var(--color-accent); }
.input { background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); font-size: 13px; padding: 9px 12px; outline: none; transition: border-color 0.15s; width: 100%; box-sizing: border-box; font-family: inherit; }
.input:focus { border-color: var(--color-accent); }
.textarea { resize: vertical; min-height: 76px; }
.platform-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); font-size: 12px; cursor: pointer; transition: 0.15s; }
.chip:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.chip.active { border-color: var(--color-accent); background: rgba(255,107,53,0.12); color: var(--color-accent); }
.chip-format { font-family: "Space Mono", monospace; font-size: 9px; opacity: 0.65; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 18px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; appearance: none; }
.btn svg { display: block; }
.btn-primary { background: var(--color-accent); color: #fff; }
.btn-primary:disabled { opacity: 0.55; cursor: default; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-danger { background: #ef4444; color: #fff; }
.btn-danger:disabled { opacity: 0.55; cursor: default; }
.btn-sm { padding: 5px 12px; font-size: 12px; }

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 300; display: flex; align-items: center; justify-content: center; }
.confirm-modal { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 14px; padding: 28px; max-width: 380px; width: 90%; }
.confirm-modal h3 { font-size: 17px; font-weight: 700; margin-bottom: 10px; color: var(--color-text-primary); }
.confirm-modal p { font-size: 13px; color: var(--color-text-secondary); line-height: 1.5; margin-bottom: 20px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

/* Misc */
.banner { border-radius: 8px; padding: 12px 14px; font-size: 13px; border: 1px solid; }
.banner.error { border-color: rgba(248,113,113,0.35); background: rgba(248,113,113,0.08); color: #fca5a5; }
.page-state { padding: 60px 24px; color: var(--color-text-muted); font-size: 14px; text-align: center; }

@media (max-width: 900px) {
  .brand-defaults { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 760px) {
  .main { margin-left: 0; }
  .tab-content { padding: 16px; }
  .brand-defaults { grid-template-columns: 1fr 1fr; }
  .hero-wrap { padding: 12px 16px 0; }
}
</style>
