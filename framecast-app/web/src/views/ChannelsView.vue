<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'

const router = useRouter()
const authStore = useAuthStore()

const mePayload = ref(null)
const channels = ref([])
const brandKits = ref([])
const voiceProfiles = ref([])
const loading = ref(true)
const error = ref('')

const drawerOpen = ref(false)
const editTarget = ref(null)
const savePending = ref(false)
const saveError = ref('')

const formName = ref('')
const formDescription = ref('')
const formPlatformTargets = ref([])
const formDefaultLanguage = ref('en')
const formBrandKitId = ref('')
const formVoiceProfileId = ref('')

const deleteTarget = ref(null)
const deletePending = ref(false)
const deleteError = ref('')

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

// 5 named gradient classes cycling by channel.id % 5
const GRAD_CLASSES = ['grad-red', 'grad-amber', 'grad-teal', 'grad-purple', 'grad-blue']

const drawerTitle = computed(() => editTarget.value ? 'Edit Channel' : 'New Channel')
const totalVideos = computed(() =>
  channels.value.reduce((sum, ch) => sum + Number(ch.projects_count || 0), 0),
)
const formatsUsed = computed(() => {
  const formats = new Set(
    channels.value
      .map((channel) => platformFormat(channel.platform_targets))
      .filter(Boolean),
  )
  return formats.size
})
const channelsWithBrandKit = computed(() =>
  channels.value.filter((channel) => Boolean(channel.brand_kit_id)).length,
)

function channelGradClass(channel) {
  return GRAD_CLASSES[channel.id % GRAD_CLASSES.length]
}

function channelInitial(channel) {
  return (channel.name || '?').trim()[0].toUpperCase()
}

function platformFormat(targets) {
  if (!targets || targets.length === 0) return null
  const t = targets[0]
  if (t === 'youtube' || t === 'facebook') return '16:9'
  if (t === 'instagram_post') return '1:1'
  return '9:16'
}

function brandKitName(brandKitId) {
  if (!brandKitId) return null
  return brandKits.value.find((k) => k.id === brandKitId)?.name || null
}

function kitDotColor(channelId) {
  const hue = (channelId * 47 + 30) % 360
  return `hsl(${hue}, 60%, 38%)`
}

async function loadData() {
  loading.value = true
  error.value = ''
  try {
    const [channelsRes, brandKitsRes, voicesRes, meRes] = await Promise.all([
      api.get('/channels'),
      api.get('/brand-kits'),
      api.get('/voice-profiles'),
      api.get('/me'),
    ])
    channels.value = channelsRes.data?.data?.channels ?? []
    brandKits.value = brandKitsRes.data?.data?.brand_kits ?? []
    voiceProfiles.value = voicesRes.data?.data?.voice_profiles ?? []
    mePayload.value = meRes.data?.data?.user ?? null
  } catch (err) {
    error.value = err?.response?.data?.error?.message || 'Could not load channels.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editTarget.value = null
  formName.value = ''
  formDescription.value = ''
  formPlatformTargets.value = ['tiktok']
  formDefaultLanguage.value = 'en'
  formBrandKitId.value = ''
  formVoiceProfileId.value = ''
  saveError.value = ''
  drawerOpen.value = true
}

function openEdit(channel) {
  editTarget.value = channel
  formName.value = channel.name || ''
  formDescription.value = channel.description || ''
  formPlatformTargets.value = channel.platform_targets ? [...channel.platform_targets] : []
  formDefaultLanguage.value = channel.default_language || 'en'
  formBrandKitId.value = channel.brand_kit_id ? String(channel.brand_kit_id) : ''
  formVoiceProfileId.value = channel.default_voice_profile_id ? String(channel.default_voice_profile_id) : ''
  saveError.value = ''
  drawerOpen.value = true
}

function closeDrawer() {
  drawerOpen.value = false
  editTarget.value = null
}

function togglePlatform(value) {
  const idx = formPlatformTargets.value.indexOf(value)
  if (idx === -1) {
    formPlatformTargets.value.push(value)
  } else {
    formPlatformTargets.value.splice(idx, 1)
  }
}

async function save() {
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
    if (editTarget.value) {
      await api.patch(`/channels/${editTarget.value.id}`, payload)
    } else {
      await api.post('/channels', payload)
    }
    closeDrawer()
    await loadData()
  } catch (err) {
    saveError.value = err?.response?.data?.error?.message || 'Could not save channel.'
  } finally {
    savePending.value = false
  }
}

function askDelete(channel) {
  deleteTarget.value = channel
  deleteError.value = ''
}

async function doDelete() {
  if (!deleteTarget.value) return
  deletePending.value = true
  deleteError.value = ''
  try {
    await api.delete(`/channels/${deleteTarget.value.id}`)
    deleteTarget.value = null
    await loadData()
  } catch (err) {
    deleteError.value = err?.response?.data?.error?.message || 'Could not archive channel.'
  } finally {
    deletePending.value = false
  }
}

function openDetail(channel) {
  router.push({ name: 'channel-detail', params: { channelId: channel.id } })
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

onMounted(loadData)
</script>

<template>
  <div class="channels-shell">
    <AppSidebar :user="mePayload" active-page="channels" :channel-count="channels.length" @logout="logout" />

    <main class="main">
      <div class="topbar">
        <div class="topbar-left">
          <span class="bc-ws">My Workspace</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">Channels</span>
        </div>
        <div class="topbar-right">
          <button class="btn btn-primary btn-sm" type="button" @click="openCreate">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>
            New Channel
          </button>
        </div>
      </div>

      <div class="content">
        <div v-if="error" class="banner error">{{ error }}</div>
        <div v-if="loading" class="page-state">Loading channels…</div>

        <template v-else>
          <div class="stats-row">
            <article class="stat-card accent-stat">
              <div class="stat-label">Active Channels</div>
              <div class="stat-value">{{ channels.length }}</div>
              <div class="stat-change">{{ channels.length > 0 ? 'Content lanes live' : 'Create your first channel' }}</div>
            </article>
            <article class="stat-card">
              <div class="stat-label">Videos Across Channels</div>
              <div class="stat-value">{{ totalVideos }}</div>
              <div class="stat-change">{{ totalVideos > 0 ? 'Library connected' : 'No videos assigned yet' }}</div>
            </article>
            <article class="stat-card">
              <div class="stat-label">Formats In Use</div>
              <div class="stat-value">{{ formatsUsed }}</div>
              <div class="stat-change">{{ formatsUsed > 0 ? 'Aspect mix configured' : 'Formats appear once channels are set up' }}</div>
            </article>
            <article class="stat-card">
              <div class="stat-label">Brand Kits Attached</div>
              <div class="stat-value">{{ channelsWithBrandKit }}</div>
              <div class="stat-change">{{ channelsWithBrandKit > 0 ? 'Defaults ready to reuse' : 'No brand kits linked yet' }}</div>
            </article>
          </div>

          <section class="dash-section">
            <div class="section-hd">
              <div class="section-hd-left">
                <div class="eyebrow">Content lanes</div>
                <div class="section-title">All Channels</div>
              </div>
              <button class="btn btn-ghost btn-sm" type="button" @click="openCreate">+ New Channel</button>
            </div>

            <div v-if="channels.length === 0" class="empty-hero">
              <div class="empty-icon">📡</div>
              <div class="empty-title">No channels yet</div>
              <div class="empty-body">Channels let you organise videos by topic, brand, or platform.<br>Each channel can have its own brand kit, voice, and publishing style.</div>
              <button class="btn btn-primary" type="button" @click="openCreate">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>
                Create your first channel
              </button>
            </div>

            <div v-else class="channel-grid">
              <div
                v-for="channel in channels"
                :key="channel.id"
                class="channel-card"
                @click="openDetail(channel)"
              >
                <div :class="['channel-cover', channelGradClass(channel)]">
                  <span class="channel-initial">{{ channelInitial(channel) }}</span>
                  <div class="channel-hover-actions" @click.stop>
                    <button class="card-action-btn" type="button" title="Edit" @click="openEdit(channel)">
                      <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                    <button class="card-action-btn danger" type="button" title="Archive" @click="askDelete(channel)">
                      <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6M14 11v6"></path></svg>
                    </button>
                  </div>
                </div>
                <div class="channel-body">
                  <div class="channel-name">{{ channel.name }}</div>
                  <div class="channel-desc">{{ channel.description || 'Set the tone, defaults, and publishing format for this content lane.' }}</div>
                  <div class="channel-stats">
                    <div class="ch-stat">
                      <div class="ch-stat-val">{{ channel.projects_count ?? 0 }}</div>
                      <div class="ch-stat-label">Videos</div>
                    </div>
                    <div class="ch-stat">
                      <div class="ch-stat-val">0</div>
                      <div class="ch-stat-label">Series</div>
                    </div>
                    <div class="ch-stat">
                      <div class="ch-stat-val">{{ platformFormat(channel.platform_targets) || '—' }}</div>
                      <div class="ch-stat-label">Format</div>
                    </div>
                  </div>
                  <div class="channel-footer">
                    <div v-if="brandKitName(channel.brand_kit_id)" class="channel-kit">
                      <div class="kit-dot" :style="{ background: kitDotColor(channel.id) }"></div>
                      {{ brandKitName(channel.brand_kit_id) }}
                    </div>
                    <div v-else class="channel-kit muted">No brand kit</div>
                    <span class="channel-action">Open →</span>
                  </div>
                </div>
              </div>

              <!-- New channel card -->
              <button class="channel-card-new" type="button" @click="openCreate">
                <div class="channel-card-new-icon">+</div>
                <div>New Channel</div>
              </button>
            </div>
          </section>
        </template>
      </div>
    </main>

    <!-- Create / Edit Drawer -->
    <transition name="drawer">
      <div v-if="drawerOpen" class="drawer-overlay" @click.self="closeDrawer">
        <div class="drawer">
          <div class="drawer-header">
            <h2>{{ drawerTitle }}</h2>
            <button class="drawer-close" type="button" @click="closeDrawer">✕</button>
          </div>

          <div class="drawer-body">
            <div v-if="saveError" class="banner error" style="margin-bottom:16px;">{{ saveError }}</div>

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

          <div class="drawer-footer">
            <button class="btn btn-ghost" type="button" @click="closeDrawer">Cancel</button>
            <button class="btn btn-primary" type="button" :disabled="savePending || !formName.trim()" @click="save">
              {{ savePending ? 'Saving…' : (editTarget ? 'Save Changes' : 'Create Channel') }}
            </button>
          </div>
        </div>
      </div>
    </transition>

    <!-- Archive Confirm Modal -->
    <div v-if="deleteTarget" class="modal-overlay" @click.self="deleteTarget = null">
      <div class="confirm-modal">
        <h3>Archive channel?</h3>
        <p>
          <strong>{{ deleteTarget.name }}</strong> will be archived. Existing videos will remain accessible.
        </p>
        <div v-if="deleteError" class="banner error">{{ deleteError }}</div>
        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" :disabled="deletePending" @click="deleteTarget = null">Cancel</button>
          <button class="btn btn-danger" type="button" :disabled="deletePending" @click="doDelete">
            {{ deletePending ? 'Archiving…' : 'Archive' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.channels-shell { min-height: 100vh; background: var(--color-bg-deep); color: var(--color-text-primary); font-family: "DM Sans", sans-serif; display: flex; }
.main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* Topbar */
.topbar { position: sticky; top: 0; z-index: 90; height: 58px; background: rgba(10,10,15,0.88); border-bottom: 1px solid var(--color-border); backdrop-filter: blur(14px); padding: 0 28px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.topbar-left { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.bc-ws { color: var(--color-text-muted); }
.bc-sep { color: var(--color-text-muted); }
.bc-page { font-weight: 600; color: var(--color-text-primary); }
.topbar-right { display: flex; align-items: center; gap: 10px; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; appearance: none; }
.btn svg { display: block; }
.btn-primary { background: var(--color-accent); color: #fff; }
.btn-primary:disabled { opacity: 0.55; cursor: default; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-danger { background: #ef4444; color: #fff; }
.btn-danger:disabled { opacity: 0.55; cursor: default; }
.btn-sm { padding: 5px 10px; font-size: 12px; }

/* Content */
.content { padding: 28px; max-width: 1280px; width: 100%; display: flex; flex-direction: column; gap: 28px; }
.dash-section { display: flex; flex-direction: column; gap: 14px; }

/* Stats */
.stats-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.stat-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 16px 18px; min-width: 0; }
.accent-stat { background: rgba(255,107,53,0.12); border-color: rgba(255,107,53,0.24); }
.stat-label { font-size: 11px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.07em; font-family: "Space Mono", monospace; }
.stat-value { font-size: 26px; font-weight: 700; color: var(--color-text-primary); font-family: "Space Mono", monospace; margin-top: 8px; }
.accent-stat .stat-value { color: var(--color-accent); }
.stat-change { font-size: 12px; color: var(--color-text-secondary); margin-top: 6px; }

.section-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.section-hd-left { display: flex; flex-direction: column; gap: 2px; }
.eyebrow { font-family: "Space Mono", monospace; font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-accent); }
.section-title { font-size: 17px; font-weight: 700; color: var(--color-text-primary); }

/* Empty state */
.empty-hero { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 80px 24px; gap: 14px; border: 1px dashed var(--color-border); border-radius: 16px; margin-top: 8px; background: var(--color-bg-card); }
.empty-icon { font-size: 48px; }
.empty-title { font-size: 20px; font-weight: 700; color: var(--color-text-primary); }
.empty-body { font-size: 14px; color: var(--color-text-muted); line-height: 1.6; max-width: 400px; }

/* Channel grid */
.channel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }

/* Channel card */
.channel-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 14px; overflow: hidden; cursor: pointer; transition: 0.2s; display: flex; flex-direction: column; }
.channel-card:hover { border-color: var(--color-border-active); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.3); }

/* Cover */
.channel-cover { height: 80px; position: relative; display: flex; align-items: flex-end; padding: 12px; justify-content: space-between; }
.channel-initial { font-size: 28px; font-weight: 700; color: rgba(255,255,255,0.22); font-family: "Space Mono", monospace; position: relative; z-index: 1; line-height: 1; user-select: none; }

/* Gradient classes matching the HTML mockup */
.grad-red    { background: linear-gradient(135deg, #1a0005 0%, #3d0010 50%, #0a0a1a 100%); }
.grad-amber  { background: linear-gradient(135deg, #1a0e00 0%, #3d2600 50%, #0a0a1a 100%); }
.grad-teal   { background: linear-gradient(135deg, #001a18 0%, #003d36 50%, #0a0a1a 100%); }
.grad-purple { background: linear-gradient(135deg, #1a0a2e 0%, #2d1457 50%, #0a0a1a 100%); }
.grad-blue   { background: linear-gradient(135deg, #000d1a 0%, #001a3d 50%, #0a0a1a 100%); }

/* Hover action buttons in cover */
.channel-hover-actions { display: flex; gap: 4px; opacity: 0; transition: opacity 0.15s; margin-left: auto; }
.channel-card:hover .channel-hover-actions { opacity: 1; }
.card-action-btn { width: 24px; height: 24px; border-radius: 5px; border: 1px solid rgba(255,255,255,0.15); background: rgba(0,0,0,0.45); color: rgba(255,255,255,0.7); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.15s; }
.card-action-btn:hover { color: #fff; background: rgba(0,0,0,0.65); }
.card-action-btn.danger:hover { color: #f87171; }

/* Body */
.channel-body { padding: 14px 16px 16px; flex: 1; display: flex; flex-direction: column; gap: 10px; }
.channel-name { font-size: 15px; font-weight: 700; color: var(--color-text-primary); }
.channel-desc { font-size: 12px; color: var(--color-text-secondary); line-height: 1.5; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 36px; }

/* Stats */
.channel-stats { display: flex; gap: 12px; }
.ch-stat { display: flex; flex-direction: column; gap: 2px; }
.ch-stat-val { font-size: 15px; font-weight: 700; color: var(--color-text-primary); font-family: "Space Mono", monospace; }
.ch-stat-label { font-size: 10px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.06em; font-family: "Space Mono", monospace; }

/* Footer */
.channel-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 10px; border-top: 1px solid var(--color-border); margin-top: auto; }
.channel-kit { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--color-text-muted); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.channel-kit.muted { font-style: italic; }
.kit-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.channel-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.channel-link { border: 0; background: transparent; color: var(--color-text-muted); font-size: 11px; font-weight: 600; cursor: pointer; padding: 0; }
.channel-link:hover { color: var(--color-text-primary); }
.channel-link-danger:hover { color: #f87171; }
.channel-action { font-size: 12px; font-weight: 600; color: var(--color-accent); white-space: nowrap; flex-shrink: 0; }

/* New channel card */
.channel-card-new { background: transparent; border: 1px dashed var(--color-border); border-radius: 14px; min-height: 200px; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 10px; cursor: pointer; transition: 0.15s; color: var(--color-text-muted); font-size: 13px; font-weight: 500; }
.channel-card-new:hover { border-color: rgba(255,107,53,0.35); color: var(--color-accent); }
.channel-card-new-icon { font-size: 28px; }

/* Drawer */
.drawer-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; display: flex; justify-content: flex-end; }
.drawer { width: 420px; max-width: 100%; height: 100%; background: var(--color-bg-panel); border-left: 1px solid var(--color-border); display: flex; flex-direction: column; }
.drawer-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--color-border); flex-shrink: 0; }
.drawer-header h2 { font-size: 18px; font-weight: 700; color: var(--color-text-primary); }
.drawer-close { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; }
.drawer-body { flex: 1; overflow-y: auto; padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.drawer-footer { padding: 16px 24px; border-top: 1px solid var(--color-border); display: flex; gap: 10px; justify-content: flex-end; flex-shrink: 0; }

/* Form */
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

/* Modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 300; display: flex; align-items: center; justify-content: center; }
.confirm-modal { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 14px; padding: 28px; max-width: 380px; width: 90%; }
.confirm-modal h3 { font-size: 17px; font-weight: 700; margin-bottom: 10px; color: var(--color-text-primary); }
.confirm-modal p { font-size: 13px; color: var(--color-text-secondary); line-height: 1.5; margin-bottom: 16px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

/* Misc */
.banner { border-radius: 8px; padding: 12px 14px; font-size: 13px; border: 1px solid; }
.banner.error { border-color: rgba(248,113,113,0.35); background: rgba(248,113,113,0.08); color: #fca5a5; }
.page-state { padding: 60px 24px; color: var(--color-text-muted); font-size: 14px; text-align: center; }

/* Drawer transition */
.drawer-enter-active, .drawer-leave-active { transition: opacity 0.2s; }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-active .drawer, .drawer-leave-active .drawer { transition: transform 0.25s ease; }
.drawer-enter-from .drawer, .drawer-leave-to .drawer { transform: translateX(100%); }

@media (max-width: 760px) {
  .main { margin-left: 0; }
  .content { padding: 16px; }
  .stats-row { grid-template-columns: 1fr; }
  .section-hd { align-items: flex-start; flex-direction: column; gap: 10px; }
  .channel-grid { grid-template-columns: 1fr; }
  .channel-footer { align-items: flex-start; flex-direction: column; gap: 10px; }
  .channel-actions { width: 100%; justify-content: space-between; }
  .drawer { width: 100%; }
}
</style>
