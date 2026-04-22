<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import LimitModal from '../components/LimitModal.vue'

const router = useRouter()
const authStore = useAuthStore()

const mePayload = ref(null)
const usage    = ref(null)
const channels  = ref([])
const brandKits = ref([])
const voices    = ref([])
const loading   = ref(true)
const saveError = ref('')
const saveState = ref('idle')
const brandSavePending = ref(false)
const limitModalOpen = ref(false)
const limitModalContext = ref('usage')
const deleteTarget = ref(null)
const deletePending = ref(false)

// ── Channel modal ─────────────────────────────────────────
const channelModal = ref({ open: false, mode: 'create' })
const channelForm  = ref({
  id: null, name: '', description: '',
  default_language: 'en',
  default_voice_profile_id: '',
  brand_kit_id: '',
  platforms: [],
})

const platformOptions = [
  { value: 'tiktok', label: 'TikTok' },
  { value: 'reels', label: 'Reels' },
  { value: 'shorts', label: 'Shorts' },
  { value: 'instagram_square', label: 'Instagram Square' },
  { value: 'youtube', label: 'YouTube' },
]

const platformAliases = new Map([
  ['tiktok', 'tiktok'],
  ['tik tok', 'tiktok'],
  ['reels', 'reels'],
  ['instagram reels', 'reels'],
  ['shorts', 'shorts'],
  ['youtube shorts', 'shorts'],
  ['instagram square', 'instagram_square'],
  ['instagram_square', 'instagram_square'],
  ['youtube', 'youtube'],
])

const fontOptions = [
  'DM Sans',
  'Space Mono',
  'Fraunces',
  'Sora',
  'Archivo',
  'Bricolage Grotesque',
  'Outfit',
  'Manrope',
  'Playfair Display',
  'IBM Plex Sans',
]

function normalizePlatformTarget(target) {
  const key = String(target || '').trim().toLowerCase()
  return platformAliases.get(key) || ''
}

function normalizePlatformTargets(targets) {
  if (!Array.isArray(targets)) return []
  return [...new Set(targets.map(normalizePlatformTarget).filter(Boolean))]
}

function platformLabel(value) {
  return platformOptions.find((option) => option.value === normalizePlatformTarget(value))?.label || value
}

function channelPlatformLabels(channel) {
  const normalized = normalizePlatformTargets(channel.platform_targets)
  return normalized.length ? normalized.map(platformLabel).join(', ') : 'No platform targets'
}

function voiceName(voiceId) {
  if (!voiceId) return 'No default voice'
  return voices.value.find((voice) => String(voice.id) === String(voiceId))?.name || 'Unknown voice'
}

function normalizeFont(font, fallback) {
  const normalized = String(font || '').replace(/\s+(bold|regular|medium|semibold)$/i, '').trim()
  return fontOptions.includes(normalized) ? normalized : fallback
}

function togglePlatform(p) {
  const idx = channelForm.value.platforms.indexOf(p)
  if (idx === -1) channelForm.value.platforms.push(p)
  else channelForm.value.platforms.splice(idx, 1)
}

function openNewChannel() {
  if ((usage.value?.active_channels || 0) >= (usage.value?.channel_limit || 0)) {
    limitModalContext.value = 'channels'
    limitModalOpen.value = true
    return
  }
  channelForm.value = { id: null, name: '', description: '', default_language: 'en', default_voice_profile_id: '', brand_kit_id: '', platforms: [] }
  channelModal.value = { open: true, mode: 'create' }
}

function openEditChannel(channel) {
  channelForm.value = {
    id: channel.id,
    name: channel.name,
    description: channel.description || '',
    default_language: channel.default_language || 'en',
    default_voice_profile_id: channel.default_voice_profile_id || '',
    brand_kit_id: channel.brand_kit_id || '',
    platforms: normalizePlatformTargets(channel.platform_targets),
  }
  channelModal.value = { open: true, mode: 'edit' }
}

function closeChannelModal() {
  channelModal.value.open = false
}

// ── Account / preferences ─────────────────────────────────
const notificationsEnabled = ref(true)
const previewBeforeRender  = ref(true)
const autoMusic            = ref(true)
const watermarkEnabled     = ref(false)

const accountForm = ref({ name: '', timezone: 'UTC' })

function hydratePreferences(preferences = {}) {
  notificationsEnabled.value = preferences.auto_generate_captions ?? true
  previewBeforeRender.value = preferences.preview_before_render ?? true
  autoMusic.value = preferences.auto_music ?? true
  watermarkEnabled.value = preferences.watermark_enabled ?? false
}

function accountPayload() {
  return {
    ...accountForm.value,
    preferences: {
      auto_generate_captions: notificationsEnabled.value,
      preview_before_render: previewBeforeRender.value,
      auto_music: autoMusic.value,
      watermark_enabled: watermarkEnabled.value,
    },
  }
}

// ── Brand Kit ─────────────────────────────────────────────
const activeBrandKitId = ref(null)
const brandKitForm = ref({
  id: null,
  name: '',
  primary_color: '#ff6b35',
  secondary_color: '#1a1a3e',
  accent_color: '#ececf3',
  font_primary: 'DM Sans',
  font_secondary: 'Space Mono',
  default_voice_profile_id: '',
})

const activeBrandKit = computed(() =>
  brandKits.value.find((kit) => kit.id === activeBrandKitId.value) || brandKits.value[0] || null
)

// ── Active nav ────────────────────────────────────────────
const activeSection = ref('channels')

// ── Avatar gradients ──────────────────────────────────────
const avatarGradients = [
  'linear-gradient(135deg, #0f2027, #2c5364)',
  'linear-gradient(135deg, #1a0a2e, #3d1a6e)',
  'linear-gradient(135deg, #2a1a10, #4a3020)',
  'linear-gradient(135deg, #1a2a1a, #2a4a2a)',
  'linear-gradient(135deg, #2a1a20, #4a2a40)',
]
function channelGradient(i) { return avatarGradients[i % avatarGradients.length] }
function channelInitials(name) { return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() }

function hydrateBrandKitForm(brandKit) {
  brandKitForm.value = {
    id: brandKit?.id || null,
    name: brandKit?.name || '',
    primary_color: brandKit?.primary_color || '#ff6b35',
    secondary_color: brandKit?.secondary_color || '#1a1a3e',
    accent_color: brandKit?.accent_color || '#ececf3',
    font_primary: normalizeFont(brandKit?.font_primary, 'DM Sans'),
    font_secondary: normalizeFont(brandKit?.font_secondary, 'Space Mono'),
    default_voice_profile_id: brandKit?.default_voice_profile_id || '',
  }
}

function selectBrandKit(brandKit) {
  activeBrandKitId.value = brandKit.id
  hydrateBrandKitForm(brandKit)
}

function openNewBrandKit() {
  activeBrandKitId.value = null
  hydrateBrandKitForm(null)
}

// ── Usage bars ────────────────────────────────────────────
const usageProgress = computed(() => {
  if (!usage.value) return []
  const pct = (used, limit) => Math.min(100, limit ? Math.round((used / limit) * 100) : 0)
  return [
    { label: 'Renders',        used: usage.value.renders_used,       limit: usage.value.render_limit,          pct: pct(usage.value.renders_used, usage.value.render_limit),                color: '#34d399' },
    { label: 'Voice Minutes',  used: usage.value.voice_minutes_used,  limit: usage.value.voice_minutes_limit,   pct: pct(usage.value.voice_minutes_used, usage.value.voice_minutes_limit),    color: '#60a5fa' },
    { label: 'Dub Languages',  used: usage.value.dub_languages_used || 0, limit: usage.value.dub_languages_limit || 3, pct: pct(usage.value.dub_languages_used || 0, usage.value.dub_languages_limit || 3), color: '#a78bfa' },
    { label: 'Active Channels',used: usage.value.active_channels,     limit: usage.value.channel_limit,         pct: pct(usage.value.active_channels, usage.value.channel_limit),             color: '#fbbf24' },
  ]
})

const limitRows = computed(() => {
  const rows = usageProgress.value.filter((row) => row.pct >= 100)
  return rows.length > 0 ? rows : usageProgress.value
})

const deleteDialogTitle = computed(() =>
  deleteTarget.value?.kind === 'channel' ? 'Archive Channel?' : 'Delete Brand Kit?'
)

const deleteDialogMessage = computed(() => deleteTarget.value?.message || '')
const deleteDialogConfirmLabel = computed(() => deleteTarget.value?.confirmLabel || 'Confirm')
const limitModalTitle = computed(() =>
  limitModalContext.value === 'channels' ? 'Channel limit reached' : 'Usage is approaching your plan limits'
)
const limitModalSubtitle = computed(() =>
  limitModalContext.value === 'channels'
    ? 'This workspace is already at the maximum active channels for the current plan. Upgrade to add another lane.'
    : 'Upgrade before your next batch so channels, renders, and voice capacity do not block production.'
)

// ── API calls ─────────────────────────────────────────────
async function loadSettings() {
  loading.value = true
  saveError.value = ''
  try {
    const [meRes, channelsRes, brandKitsRes, voicesRes] = await Promise.all([
      api.get('/me'), api.get('/channels'), api.get('/brand-kits'), api.get('/voice-profiles'),
    ])
    mePayload.value = meRes.data.data.user
    usage.value     = meRes.data.data.usage
    channels.value  = channelsRes.data.data.channels || []
    brandKits.value = brandKitsRes.data.data.brand_kits || []
    voices.value    = voicesRes.data.data.voice_profiles || []
    accountForm.value = { name: mePayload.value?.name || '', timezone: mePayload.value?.timezone || 'UTC' }
    hydratePreferences(mePayload.value?.preferences)
    if (brandKits.value.length > 0) {
      if (!brandKits.value.some((kit) => kit.id === activeBrandKitId.value)) {
        activeBrandKitId.value = brandKits.value[0].id
      }
      hydrateBrandKitForm(brandKits.value.find((kit) => kit.id === activeBrandKitId.value) || brandKits.value[0])
    } else {
      openNewBrandKit()
    }
  } catch (err) {
    saveError.value = err?.response?.data?.error?.message || 'Could not load settings.'
  } finally {
    loading.value = false
  }
}

async function saveAccount() {
  saveState.value = 'saving'; saveError.value = ''
  try {
    const { data } = await api.patch('/me', accountPayload())
    mePayload.value = data.data.user
    usage.value = data.data.usage
    hydratePreferences(mePayload.value?.preferences)
    saveState.value = 'saved'
  } catch (err) {
    saveError.value = err?.response?.data?.error?.message || 'Could not save account settings.'
    saveState.value = 'error'
  }
}

async function saveChannel() {
  saveState.value = 'saving'; saveError.value = ''
  try {
    const payload = {
      name: channelForm.value.name,
      description: channelForm.value.description || null,
      default_language: channelForm.value.default_language || null,
      default_voice_profile_id: channelForm.value.default_voice_profile_id || null,
      brand_kit_id: channelForm.value.brand_kit_id || null,
      platform_targets: channelForm.value.platforms.length ? channelForm.value.platforms : null,
    }
    if (channelForm.value.id) {
      await api.patch(`/channels/${channelForm.value.id}`, payload)
    } else {
      await api.post('/channels', payload)
    }
    await loadSettings()
    closeChannelModal()
    saveState.value = 'saved'
  } catch (err) {
    if (err?.response?.data?.error?.code === 'channel_limit_reached') {
      limitModalContext.value = 'channels'
      limitModalOpen.value = true
    }
    saveError.value = err?.response?.data?.error?.message || 'Could not save channel.'
    saveState.value = 'error'
  }
}

async function saveBrandKit() {
  brandSavePending.value = true
  saveError.value = ''
  saveState.value = 'saving'

  try {
    const payload = {
      name: brandKitForm.value.name,
      primary_color: brandKitForm.value.primary_color || null,
      secondary_color: brandKitForm.value.secondary_color || null,
      accent_color: brandKitForm.value.accent_color || null,
      font_primary: brandKitForm.value.font_primary || null,
      font_secondary: brandKitForm.value.font_secondary || null,
      default_voice_profile_id: brandKitForm.value.default_voice_profile_id || null,
    }

    if (brandKitForm.value.id) {
      await api.patch(`/brand-kits/${brandKitForm.value.id}`, payload)
    } else {
      await api.post('/brand-kits', payload)
    }

    await loadSettings()
    saveState.value = 'saved'
  } catch (err) {
    saveError.value = err?.response?.data?.error?.message || 'Could not save brand kit.'
    saveState.value = 'error'
  } finally {
    brandSavePending.value = false
  }
}

function requestArchiveChannel() {
  if (!channelForm.value.id) return
  deleteTarget.value = {
    kind: 'channel',
    id: channelForm.value.id,
    message: `"${channelForm.value.name}" will be archived and removed from the active channel list.`,
    confirmLabel: 'Archive Channel',
  }
}

function requestDeleteBrandKit() {
  if (!brandKitForm.value.id) return
  deleteTarget.value = {
    kind: 'brand-kit',
    id: brandKitForm.value.id,
    message: `"${brandKitForm.value.name}" will be deleted from this workspace. Channels using it will need a new default kit.`,
    confirmLabel: 'Delete Brand Kit',
  }
}

function closeDeleteConfirm() {
  if (deletePending.value) return
  deleteTarget.value = null
}

async function confirmDeleteTarget() {
  if (!deleteTarget.value) return

  deletePending.value = true

  try {
    if (deleteTarget.value.kind === 'channel') {
      await api.delete(`/channels/${deleteTarget.value.id}`)
      closeChannelModal()
    }

    if (deleteTarget.value.kind === 'brand-kit') {
      await api.delete(`/brand-kits/${deleteTarget.value.id}`)
    }

    await loadSettings()
    deleteTarget.value = null
  } catch (err) {
    saveError.value = err?.response?.data?.error?.message || 'Could not complete this action.'
  } finally {
    deletePending.value = false
  }
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

onMounted(loadSettings)
</script>

<template>
  <div class="fc-shell">
    <AppSidebar :user="mePayload" active-page="settings" @logout="logout" />

    <div class="main">
      <div v-if="saveError" class="banner error">{{ saveError }}</div>
      <div v-if="saveState === 'saved'" class="banner success">Saved.</div>
      <div v-if="loading" class="page-state">Loading settings…</div>

      <div v-else class="settings-shell">

        <!-- ── Left nav ── -->
        <div class="surface-card settings-menu">
          <div class="settings-menu-title">Settings</div>
          <div class="settings-nav">
            <div :class="['settings-tab', activeSection === 'channels' ? 'active' : '']" @click="activeSection = 'channels'">Channels</div>
            <div :class="['settings-tab', activeSection === 'brand'    ? 'active' : '']" @click="activeSection = 'brand'">Brand Kits</div>
            <div :class="['settings-tab', activeSection === 'account'  ? 'active' : '']" @click="activeSection = 'account'">Account</div>
            <div :class="['settings-tab', activeSection === 'usage'    ? 'active' : '']" @click="activeSection = 'usage'">Usage and Billing</div>
          </div>
        </div>

        <!-- ── Right pane ── -->
        <div class="surface-card settings-content-card">

          <!-- Channels -->
          <div v-if="activeSection === 'channels'">
            <div class="section-title">Channels</div>
            <div class="settings-section-desc">This now reads as a proper management surface instead of a detached tab strip.</div>

            <div v-for="(channel, i) in channels" :key="channel.id" class="channel-card">
              <div class="channel-avatar" :style="{ background: channelGradient(i) }">{{ channelInitials(channel.name) }}</div>
              <div class="channel-info">
                <div class="channel-name-row">{{ channel.name }}</div>
                <div class="channel-detail">{{ channel.description || 'No description yet' }}</div>
                <div class="channel-detail">{{ channelPlatformLabels(channel) }} · {{ voiceName(channel.default_voice_profile_id) }} · {{ channel.default_language || 'en' }}</div>
              </div>
              <button class="btn btn-ghost btn-sm" type="button" @click="openEditChannel(channel)">Edit</button>
            </div>

            <button class="btn btn-ghost new-channel-btn" type="button" @click="openNewChannel">+ New Channel</button>
          </div>

          <!-- Brand Kits -->
          <div v-else-if="activeSection === 'brand'">
            <div class="section-title">Brand Kit Defaults</div>
            <div class="settings-section-desc">Make the global kit obvious, with clear form rows and less visual noise.</div>

            <div class="brand-kit-grid">
              <button
                v-for="brandKit in brandKits"
                :key="brandKit.id"
                :class="['brand-kit-card', activeBrandKit?.id === brandKit.id ? 'active' : '']"
                type="button"
                @click="selectBrandKit(brandKit)"
              >
                <span class="brand-kit-swatches">
                  <span class="brand-kit-swatch" :style="{ background: brandKit.primary_color || '#ff6b35' }"></span>
                  <span class="brand-kit-swatch" :style="{ background: brandKit.secondary_color || '#1a1a3e' }"></span>
                  <span class="brand-kit-swatch" :style="{ background: brandKit.accent_color || '#ececf3' }"></span>
                </span>
                <span class="brand-kit-name">{{ brandKit.name }}</span>
                <span class="brand-kit-meta">{{ brandKit.font_primary || 'No primary font' }} · {{ voiceName(brandKit.default_voice_profile_id) }}</span>
              </button>
              <button class="brand-kit-card brand-kit-card-add" type="button" @click="openNewBrandKit">
                <span class="brand-kit-plus">+</span>
                <span class="brand-kit-name">New Kit</span>
                <span class="brand-kit-meta">Create another reusable brand system</span>
              </button>
            </div>

            <div class="settings-row settings-row--first">
              <div class="settings-row-label">
                <div class="label-main">Brand Kit Name</div>
                <div class="label-hint">The preset applied across series and channels</div>
              </div>
              <div class="settings-row-control">
                <input v-model="brandKitForm.name" class="settings-input" type="text" placeholder="Finance Authority">
              </div>
            </div>

            <div class="settings-row">
              <div class="settings-row-label">
                <div class="label-main">Brand Colors</div>
                <div class="label-hint">Primary, secondary, accent</div>
              </div>
              <div class="settings-row-control">
                <div class="brand-color-grid">
                  <label class="field-inline">
                    <span>Primary</span>
                    <span class="color-field">
                      <input v-model="brandKitForm.primary_color" class="color-input" type="color">
                      <input v-model="brandKitForm.primary_color" class="settings-input" type="text">
                    </span>
                  </label>
                  <label class="field-inline">
                    <span>Secondary</span>
                    <span class="color-field">
                      <input v-model="brandKitForm.secondary_color" class="color-input" type="color">
                      <input v-model="brandKitForm.secondary_color" class="settings-input" type="text">
                    </span>
                  </label>
                  <label class="field-inline">
                    <span>Accent</span>
                    <span class="color-field">
                      <input v-model="brandKitForm.accent_color" class="color-input" type="color">
                      <input v-model="brandKitForm.accent_color" class="settings-input" type="text">
                    </span>
                  </label>
                </div>
                <div class="color-swatches">
                  <div class="color-swatch selected" :style="{ background: brandKitForm.primary_color || '#ff6b35' }"></div>
                  <div class="color-swatch" :style="{ background: brandKitForm.secondary_color || '#1a1a3e' }"></div>
                  <div class="color-swatch" :style="{ background: brandKitForm.accent_color || '#ececf3' }"></div>
                </div>
              </div>
            </div>

            <div class="settings-row">
              <div class="settings-row-label">
                <div class="label-main">Fonts</div>
                <div class="label-hint">Caption and overlay defaults</div>
              </div>
              <div class="settings-row-control">
                <div class="brand-font-grid">
                  <label class="field-inline">
                    <span>Primary</span>
                    <select v-model="brandKitForm.font_primary" class="settings-select">
                      <option v-for="font in fontOptions" :key="font" :value="font">{{ font }}</option>
                    </select>
                  </label>
                  <label class="field-inline">
                    <span>Secondary</span>
                    <select v-model="brandKitForm.font_secondary" class="settings-select">
                      <option v-for="font in fontOptions" :key="font" :value="font">{{ font }}</option>
                    </select>
                  </label>
                </div>
              </div>
            </div>

            <div class="settings-row">
              <div class="settings-row-label">
                <div class="label-main">Default Voice</div>
                <div class="label-hint">Applied to new scenes</div>
              </div>
              <div class="settings-row-control">
                <select v-model="brandKitForm.default_voice_profile_id" class="settings-select">
                  <option value="">No default</option>
                  <option v-for="voice in voices" :key="voice.id" :value="voice.id">{{ voice.name }}</option>
                </select>
              </div>
            </div>

            <div class="modal-actions no-border">
              <button v-if="brandKitForm.id" class="btn btn-ghost btn-danger" type="button" @click="requestDeleteBrandKit">Delete Brand Kit</button>
              <button class="btn btn-primary" type="button" @click="saveBrandKit">
                {{ brandSavePending ? 'Saving…' : brandKitForm.id ? 'Save Brand Kit' : 'Create Brand Kit' }}
              </button>
            </div>
          </div>

          <!-- Account -->
          <div v-else-if="activeSection === 'account'">
            <div class="section-title">Account</div>
            <div class="settings-section-desc">Personal defaults and workspace behavior.</div>

            <div class="settings-row settings-row--first">
              <div class="settings-row-label"><div class="label-main">Name</div></div>
              <div class="settings-row-control"><input v-model="accountForm.name" class="settings-input" type="text"></div>
            </div>
            <div class="settings-row">
              <div class="settings-row-label"><div class="label-main">Email</div></div>
              <div class="settings-row-control"><input :value="mePayload?.email || ''" class="settings-input" type="text" disabled></div>
            </div>
            <div class="settings-row">
              <div class="settings-row-label"><div class="label-main">Timezone</div></div>
              <div class="settings-row-control">
                <select v-model="accountForm.timezone" class="settings-select">
                  <option value="Africa/Lagos">Africa/Lagos (WAT)</option>
                  <option value="America/New_York">America/New_York</option>
                  <option value="Europe/London">Europe/London</option>
                  <option value="UTC">UTC</option>
                </select>
              </div>
            </div>

            <div style="margin-top:26px;">
              <div class="section-title" style="font-size:15px;">Preferences</div>
              <div class="settings-section-desc" style="margin-bottom:16px;">Defaults for new projects and exports.</div>

              <div class="toggle-row toggle-row--first">
                <div><div class="label-main">Auto-generate captions</div><div class="label-hint">Always add burned-in captions</div></div>
                <div :class="['toggle', notificationsEnabled ? 'on' : '']" @click="notificationsEnabled = !notificationsEnabled"></div>
              </div>
              <div class="toggle-row">
                <div><div class="label-main">Preview before render</div><div class="label-hint">Require manual approval before queue</div></div>
                <div :class="['toggle', previewBeforeRender ? 'on' : '']" @click="previewBeforeRender = !previewBeforeRender"></div>
              </div>
              <div class="toggle-row">
                <div><div class="label-main">Auto music</div><div class="label-hint">Select background music by tone</div></div>
                <div :class="['toggle', autoMusic ? 'on' : '']" @click="autoMusic = !autoMusic"></div>
              </div>
              <div class="toggle-row">
                <div><div class="label-main">Watermark on free exports</div><div class="label-hint">Show Framecast branding on free tier</div></div>
                <div :class="['toggle', watermarkEnabled ? 'on' : '']" @click="watermarkEnabled = !watermarkEnabled"></div>
              </div>
            </div>

            <div class="modal-actions">
              <button class="btn btn-primary" type="button" @click="saveAccount">
                {{ saveState === 'saving' ? 'Saving…' : 'Save Account' }}
              </button>
            </div>
          </div>

          <!-- Usage & Billing -->
          <div v-else>
            <div class="section-title">Usage and Billing</div>
            <div class="settings-section-desc">Billing status and plan usage stay readable inside the same card system.</div>

            <div v-if="usage">
              <div v-for="row in usageProgress" :key="row.label" class="usage-bar-container">
                <div class="usage-label-row">
                  <span class="usage-label">{{ row.label }}</span>
                  <span class="usage-count" :style="{ color: row.color }">{{ row.used }} / {{ row.limit }}</span>
                </div>
                <div class="usage-bar">
                  <div class="usage-fill" :style="{ width: row.pct + '%', background: row.color }"></div>
                </div>
              </div>
            </div>

            <div style="display:flex; gap:10px; margin:16px 0 22px;">
              <button class="btn btn-primary" type="button" @click="limitModalOpen = true">Upgrade to Agency</button>
              <button class="btn btn-ghost" type="button">Manage Billing</button>
            </div>

            <table class="table-clean">
              <thead><tr><th>Feature</th><th>Creator</th><th>Studio</th><th>Agency</th></tr></thead>
              <tbody>
                <tr><td>Renders / mo</td><td>60</td><td class="col-accent">200</td><td>600</td></tr>
                <tr><td>Voice min</td><td>30</td><td class="col-accent">120</td><td>400</td></tr>
                <tr><td>Channels</td><td>2</td><td class="col-accent">5</td><td>15</td></tr>
                <tr><td>Dub languages</td><td>-</td><td class="col-accent">3</td><td>10</td></tr>
                <tr><td>Voice cloning</td><td>-</td><td class="col-accent">2 voices</td><td>10 voices</td></tr>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </div>

    <!-- ── Channel Modal ── -->
    <div v-if="channelModal.open" class="modal-overlay" @click.self="closeChannelModal">
      <div class="modal">
        <div class="modal-title">{{ channelModal.mode === 'create' ? 'New Channel' : 'Edit Channel' }}</div>
        <div class="modal-subtitle">{{ channelModal.mode === 'create' ? 'Set up a new publishing lane' : channelForm.name }}</div>

        <div class="form-grid-2">
          <div class="input-group">
            <label class="input-label">Channel Name</label>
            <input v-model="channelForm.name" class="input-field" type="text" placeholder="Faceless Finance Tips">
          </div>
          <div class="input-group">
            <label class="input-label">Default Language</label>
            <select v-model="channelForm.default_language" class="input-field">
              <option value="en">English (en-US)</option>
              <option value="es">Spanish (es)</option>
              <option value="pt">Portuguese (pt-BR)</option>
              <option value="fr">French (fr)</option>
              <option value="de">German (de)</option>
            </select>
          </div>
        </div>

        <div class="input-group">
          <label class="input-label">Description</label>
          <input v-model="channelForm.description" class="input-field" type="text" placeholder="Personal finance tips for everyday people">
        </div>

        <div class="input-group">
          <label class="input-label">Platform Targets</label>
          <div class="platform-checks">
            <div
              v-for="platform in platformOptions"
              :key="platform.value"
              :class="['platform-check', channelForm.platforms.includes(platform.value) ? 'on' : '']"
              @click="togglePlatform(platform.value)"
            >{{ platform.label }}</div>
          </div>
        </div>

        <div class="form-grid-2">
          <div class="input-group">
            <label class="input-label">Default Voice Profile</label>
            <select v-model="channelForm.default_voice_profile_id" class="input-field">
              <option value="">No default</option>
              <option v-for="voice in voices" :key="voice.id" :value="voice.id">{{ voice.name }}</option>
            </select>
          </div>
          <div class="input-group">
            <label class="input-label">Brand Kit</label>
            <select v-model="channelForm.brand_kit_id" class="input-field">
              <option value="">No default</option>
              <option v-for="kit in brandKits" :key="kit.id" :value="kit.id">{{ kit.name }}</option>
            </select>
          </div>
        </div>

        <div class="channel-hint">Changing defaults does not update existing projects that use this channel.</div>

        <div v-if="channelModal.mode === 'edit'" class="danger-zone">
          <div class="danger-zone-label">Danger Zone</div>
          <button class="btn btn-ghost btn-sm btn-danger" type="button" @click="requestArchiveChannel">Archive Channel</button>
        </div>

        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="closeChannelModal">Cancel</button>
          <button class="btn btn-primary" type="button" @click="saveChannel">
            {{ saveState === 'saving' ? 'Saving…' : channelModal.mode === 'create' ? 'Create Channel' : 'Save Changes' }}
          </button>
        </div>
      </div>
    </div>

    <ConfirmDialog
      :open="Boolean(deleteTarget)"
      :title="deleteDialogTitle"
      :message="deleteDialogMessage"
      :confirm-label="deleteDialogConfirmLabel"
      :pending="deletePending"
      destructive
      @close="closeDeleteConfirm"
      @confirm="confirmDeleteTarget"
    />

    <LimitModal
      :open="limitModalOpen"
      :title="limitModalTitle"
      :subtitle="limitModalSubtitle"
      :rows="limitRows"
      @close="limitModalOpen = false"
    />

  </div>
</template>

<style scoped>
.fc-shell {
  min-height: 100vh;
  background:
    radial-gradient(circle at top right, rgba(255,107,53,0.09), transparent 28%),
    radial-gradient(circle at bottom left, rgba(96,165,250,0.08), transparent 24%),
    #0a0a0f;
  color: #ececf3;
  font-family: "DM Sans", sans-serif;
}

.main { margin-left: 220px; min-height: 100vh; padding: 24px; }

.page-state { margin-top: 24px; color: #6a6a7c; font-size: 13px; }

.banner { margin-top: 16px; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
.banner.error   { background: rgba(248,113,113,0.1); color: #fca5a5; border: 1px solid rgba(248,113,113,0.2); }
.banner.success { background: rgba(52,211,153,0.1);  color: #86efac; border: 1px solid rgba(52,211,153,0.18); }

/* ── Shell ── */
.settings-shell {
  display: grid;
  grid-template-columns: 230px 1fr;
  gap: 22px;
  align-items: start;
  margin-top: 24px;
}

.surface-card {
  background: linear-gradient(180deg, rgba(255,255,255,0.015), transparent 100%), #17171f;
  border: 1px solid #2a2a36;
  border-radius: 12px;
  box-shadow: 0 18px 40px rgba(0,0,0,0.35);
}

/* ── Left nav ── */
.settings-menu { padding: 18px; }

.settings-menu-title {
  font-size: 11px;
  color: #6a6a7c;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 500;
}

.settings-nav { display: grid; gap: 6px; margin-top: 14px; }

.settings-tab {
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid transparent;
  color: #a1a1b5;
  cursor: pointer;
  font-size: 13px;
  transition: 0.15s ease;
  user-select: none;
}
.settings-tab:hover { background: rgba(255,255,255,0.02); }
.settings-tab.active { color: #ff6b35; background: rgba(255,107,53,0.14); border-color: rgba(255,107,53,0.28); }

/* ── Content card ── */
.settings-content-card { padding: 18px; }

.section-title { font-size: 16px; font-weight: 600; }
.settings-section-desc { color: #6a6a7c; font-size: 13px; margin-top: 4px; margin-bottom: 22px; }

.brand-kit-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 10px;
  margin-bottom: 22px;
}

.brand-kit-card {
  display: grid;
  gap: 8px;
  min-height: 116px;
  padding: 14px;
  text-align: left;
  border-radius: 12px;
  border: 1px solid #2a2a36;
  background: #15151d;
  color: #a1a1b5;
  cursor: pointer;
  transition: 0.15s ease;
  font: inherit;
}

.brand-kit-card:hover {
  border-color: rgba(255,107,53,0.24);
  transform: translateY(-1px);
}

.brand-kit-card.active {
  border-color: rgba(255,107,53,0.4);
  background: rgba(255,107,53,0.14);
}

.brand-kit-card-add {
  border-style: dashed;
  align-content: center;
}

.brand-kit-swatches {
  display: flex;
  gap: 6px;
}

.brand-kit-swatch {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.15);
}

.brand-kit-name {
  color: #ececf3;
  font-size: 13px;
  font-weight: 600;
}

.brand-kit-meta {
  color: #6a6a7c;
  font-size: 11px;
  line-height: 1.4;
}

.brand-kit-plus {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  color: #ff6b35;
}

/* ── Channel list ── */
.channel-card {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px;
  margin-bottom: 10px;
  border-radius: 10px;
  border: 1px solid #2a2a36;
  background: #15151d;
}

.channel-avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
  color: #ececf3;
  flex-shrink: 0;
}

.channel-info { flex: 1; min-width: 0; }
.channel-name-row { font-size: 13px; font-weight: 600; }
.channel-detail { margin-top: 2px; font-size: 11px; color: #6a6a7c; }

.new-channel-btn { margin-top: 8px; }

/* ── Settings rows ── */
.settings-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 14px 0;
  border-top: 1px solid #2a2a36;
}
.settings-row--first { border-top: none; padding-top: 0; }

.settings-row-label { width: 180px; flex-shrink: 0; }
.settings-row-control { flex: 1; }

.label-main { font-size: 13px; font-weight: 500; }
.label-hint { margin-top: 3px; font-size: 11px; color: #6a6a7c; }

.settings-input,
.settings-select {
  width: 100%;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  background: #1d1d28;
  color: #ececf3;
  padding: 9px 12px;
  font-size: 13px;
  font: inherit;
}
.settings-input:focus,
.settings-select:focus { outline: none; border-color: rgba(255,107,53,0.45); }
.settings-input:disabled { opacity: 0.45; cursor: not-allowed; }

.field-inline {
  display: grid;
  gap: 5px;
  font-size: 12px;
  color: #a1a1b5;
}

.brand-color-grid,
.brand-font-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.brand-font-grid {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.color-field {
  display: grid;
  grid-template-columns: 42px 1fr;
  gap: 8px;
  align-items: center;
}

.color-input {
  width: 42px;
  height: 38px;
  padding: 2px;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  background: #1d1d28;
  cursor: pointer;
}

/* Color swatches */
.color-swatches { display: flex; gap: 8px; margin-top: 10px; }
.color-swatch {
  width: 32px; height: 32px;
  border-radius: 8px;
  border: 2px solid transparent;
  cursor: pointer;
}
.color-swatch.selected {
  border-color: #fff;
  box-shadow: 0 0 0 2px rgba(255,107,53,0.4);
}

/* ── Toggle rows ── */
.toggle-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  padding: 14px 0;
  border-top: 1px solid #2a2a36;
}
.toggle-row--first { border-top: none; padding-top: 0; }
.toggle-row > div:first-child { flex: 1; }

.toggle {
  width: 40px; height: 22px;
  border-radius: 999px;
  background: #2a2a36;
  position: relative;
  cursor: pointer;
  flex-shrink: 0;
  margin-top: 2px;
  transition: background 0.15s ease;
}
.toggle::after {
  content: "";
  position: absolute;
  top: 3px; left: 3px;
  width: 16px; height: 16px;
  border-radius: 50%;
  background: #fff;
  transition: transform 0.15s ease;
}
.toggle.on { background: #ff6b35; }
.toggle.on::after { transform: translateX(18px); }

/* ── Usage bars ── */
.usage-bar-container { margin-bottom: 14px; }
.usage-label-row { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 4px; }
.usage-label { color: #a1a1b5; }
.usage-count { font-family: "Space Mono", monospace; font-size: 11px; }
.usage-bar { height: 6px; border-radius: 999px; background: #1d1d28; overflow: hidden; }
.usage-fill { height: 100%; border-radius: 999px; }

/* ── Plan table ── */
.table-clean { width: 100%; border-collapse: collapse; font-size: 13px; }
.table-clean th,
.table-clean td { padding: 8px 10px; border-bottom: 1px solid #2a2a36; text-align: left; }
.table-clean th { font-size: 11px; color: #6a6a7c; }
.table-clean tbody tr:last-child td { border-bottom: none; }
.col-accent { color: #ff6b35; }

/* ── Buttons ── */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  padding: 8px 14px;
  cursor: pointer;
  background: transparent;
  color: #ececf3;
  font-size: 13px;
  font: inherit;
  transition: 0.15s;
}
.btn:hover { background: rgba(255,255,255,0.04); }
.btn-primary { background: #ff6b35; border-color: #ff6b35; color: #fff; }
.btn-primary:hover { background: #ff875a; }
.btn-ghost { background: transparent; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.btn-danger { border-color: rgba(248,113,113,0.3); color: #f87171; }

/* ── Modal ── */
.modal-overlay {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0,0,0,0.62);
  backdrop-filter: blur(4px);
  z-index: 200;
}

.modal {
  width: min(560px, calc(100vw - 32px));
  max-height: 82vh;
  overflow-y: auto;
  padding: 28px;
  border-radius: 16px;
  background: #111118;
  border: 1px solid #2a2a36;
  box-shadow: 0 30px 80px rgba(0,0,0,0.5);
}

.modal-title { font-size: 20px; font-weight: 700; }
.modal-subtitle { margin-top: 4px; margin-bottom: 20px; font-size: 13px; color: #6a6a7c; }

.input-group { margin-bottom: 16px; }
.input-label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 500; color: #a1a1b5; }

.input-field {
  width: 100%;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  background: #1d1d28;
  color: #ececf3;
  padding: 9px 12px;
  font-size: 13px;
  font: inherit;
}
.input-field:focus { outline: none; border-color: rgba(255,107,53,0.45); }

textarea.input-field { min-height: 86px; resize: vertical; }

.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
.form-grid-2 .input-group { margin: 0; }

/* Platform checks */
.platform-checks { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px; }
.platform-check {
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid #2a2a36;
  background: #1d1d28;
  cursor: pointer;
  font-size: 12px;
  color: #a1a1b5;
  transition: 0.15s;
  user-select: none;
}
.platform-check.on {
  border-color: rgba(255,107,53,0.4);
  background: rgba(255,107,53,0.14);
  color: #ff6b35;
}

/* Channel hint */
.channel-hint {
  font-size: 12px;
  color: #6a6a7c;
  padding: 10px 12px;
  background: #1d1d28;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  margin-bottom: 4px;
}

/* Danger zone */
.danger-zone { margin-top: 24px; padding-top: 16px; border-top: 1px solid #2a2a36; }
.danger-zone-label {
  font-size: 11px;
  color: #6a6a7c;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 10px;
}

/* Modal actions */
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 24px;
  padding-top: 18px;
  border-top: 1px solid #2a2a36;
}

.modal-actions.no-border {
  padding-top: 0;
  border-top: none;
}

@media (max-width: 1000px) {
  .settings-shell { grid-template-columns: 1fr; }
  .form-grid-2 { grid-template-columns: 1fr; }
  .brand-color-grid,
  .brand-font-grid { grid-template-columns: 1fr; }
}
</style>
