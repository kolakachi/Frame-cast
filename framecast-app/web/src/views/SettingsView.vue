<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import LimitModal from '../components/LimitModal.vue'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const mePayload = ref(null)
const referral = ref(null)
const referralCopied = ref(false)
async function copyReferral() {
  if (!referral.value?.link) return
  try {
    await navigator.clipboard.writeText(referral.value.link)
    referralCopied.value = true
    setTimeout(() => { referralCopied.value = false }, 2000)
  } catch { /* clipboard blocked — user can select manually */ }
}
const usage    = ref(null)
const brandKits = ref([])
const voices    = ref([])
const loading   = ref(true)
const saveError = ref('')
const saveState = ref('idle')
const brandSavePending = ref(false)
const limitModalOpen = ref(false)
const deleteTarget = ref(null)
const deletePending = ref(false)

// ── Billing ───────────────────────────────────────────────
const billing = ref(null)
const billingPortalPending = ref(false)
const billingError = ref('')

// ── Credit history (E15-followup) ──────────────────────────
// Fetched lazily when the Usage tab is open; cursor-paginated so the
// user can drill deep without us shipping all of history up-front.
const creditHistory = ref(null)
const creditHistoryEntries = ref([]) // accumulated across pages
const creditHistoryLoading = ref(false)
const creditHistoryError = ref('')
const creditHistoryFilter = ref('all')         // all | debits | grants
const creditHistorySort   = ref('newest')      // newest | oldest | largest
const creditHistorySince  = ref(30)            // 7 | 30 | 90 | 0 (all-time)
const creditHistoryHasMore = ref(false)
const creditHistoryCursor = ref(null)
const creditHistorySpent = computed(() => {
  if (!creditHistory.value) return 0
  return creditHistory.value.summary
    .filter(r => !r.operation.startsWith('grant:'))
    .reduce((s, r) => s + Math.abs(r.credits), 0)
})
const creditHistoryGranted = computed(() => {
  if (!creditHistory.value) return 0
  return creditHistory.value.summary
    .filter(r => r.operation.startsWith('grant:'))
    .reduce((s, r) => s + Math.abs(r.credits), 0)
})

// Pretty operation names for the table + summary.
const OPERATION_LABELS = {
  script: 'Script',
  breakdown: 'Scene breakdown',
  stock_visual: 'Stock visual',
  'ai_image:character': 'AI image (character)',
  'ai_image:manual': 'AI image (regen)',
  'ai_image:initial': 'AI image (initial)',
  'character_preview:ref': 'Character preview (with ref)',
  'character_preview:noref': 'Character preview (text-only)',
  tts: 'Voice (TTS)',
  'animate:quick': 'Animate (Quick)',
  'animate:balanced': 'Animate (Balanced)',
  'animate:premium': 'Animate (Premium)',
  export: 'Export',
  'grant:registration': 'Welcome credits',
  'grant:admin_top_up': 'Top-up (admin)',
  'grant:unspecified': 'Top-up',
}
function formatOperation(op) {
  return OPERATION_LABELS[op] || op
}

async function loadCreditHistory({ append = false, reset = false } = {}) {
  if (creditHistoryLoading.value) return
  if (!append && !reset && creditHistory.value) return // already loaded; no params changed
  creditHistoryLoading.value = true
  creditHistoryError.value = ''
  if (reset) {
    creditHistoryEntries.value = []
    creditHistoryCursor.value = null
  }
  try {
    const { data } = await api.get('/me/credit-history', {
      params: {
        per_page: 25,
        since:    creditHistorySince.value,
        filter:   creditHistoryFilter.value,
        sort:     creditHistorySort.value,
        ...(append && creditHistoryCursor.value ? { cursor: creditHistoryCursor.value } : {}),
      },
    })
    creditHistory.value = data?.data ?? null
    const pageEntries = data?.data?.entries ?? []
    creditHistoryEntries.value = append
      ? [...creditHistoryEntries.value, ...pageEntries]
      : pageEntries
    creditHistoryHasMore.value = Boolean(data?.data?.next_cursor)
    creditHistoryCursor.value  = data?.data?.next_cursor ?? null
  } catch (e) {
    creditHistoryError.value = e?.response?.data?.error?.message ?? 'Could not load credit history.'
  } finally {
    creditHistoryLoading.value = false
  }
}

// Re-fetch from page 1 whenever a filter/sort/since changes.
function reloadCreditHistory() {
  loadCreditHistory({ reset: true })
}

const planLabel = computed(() => {
  const tier = billing.value?.plan_tier || 'free'
  return { free: 'Free', studio: 'Studio', scale: 'Scale', enterprise: 'Enterprise' }[tier] || tier
})

const planStatusLabel = computed(() => {
  const s = billing.value?.plan_status || 'active'
  return { active: 'Active', past_due: 'Past Due', paused: 'Paused', cancelled: 'Cancelled' }[s] || s
})

const planStatusColor = computed(() => {
  const s = billing.value?.plan_status || 'active'
  return { active: '#34d399', past_due: '#fbbf24', paused: '#a78bfa', cancelled: '#f87171' }[s] || '#a1a1b5'
})

const isFreePlan = computed(() => !billing.value?.plan_tier || billing.value.plan_tier === 'free')
const hasSubscription = computed(() => Boolean(billing.value?.has_subscription))

// Kelviq hosted checkout. selection = { plan: 'starter'|... } for a
// subscription, or { topup: 'small'|... } for a one-time pack.
const checkoutPending = ref(false)
async function startCheckout(selection) {
  if (checkoutPending.value) return
  checkoutPending.value = true
  billingError.value = ''
  try {
    const { data } = await api.post('/billing/kelviq/checkout', selection)
    if (data?.data?.url) window.location.href = data.data.url
    else billingError.value = 'Could not start checkout. Please try again.'
  } catch (e) {
    billingError.value = e.response?.data?.error?.message ?? 'Could not start checkout.'
  } finally {
    checkoutPending.value = false
  }
}

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

function voiceName(voiceId) {
  if (!voiceId) return 'No default voice'
  return voices.value.find((v) => String(v.id) === String(voiceId))?.name || 'Unknown voice'
}

function normalizeFont(font, fallback) {
  const normalized = String(font || '').replace(/\s+(bold|regular|medium|semibold)$/i, '').trim()
  return fontOptions.includes(normalized) ? normalized : fallback
}

// ── Account / preferences ─────────────────────────────────
const notificationsEnabled = ref(true)
const previewBeforeRender  = ref(true)
const autoMusic            = ref(true)
const watermarkEnabled     = ref(false)

const accountForm = ref({ name: '', timezone: 'UTC' })

// ── Password set / change ─────────────────────────────────
const passwordForm = ref({ current: '', next: '', confirm: '' })
const passwordSaveState = ref('idle') // 'idle' | 'saving' | 'saved'
const passwordError = ref('')

const canSavePassword = computed(() => {
  if (passwordForm.value.next.length < 8) return false
  if (passwordForm.value.next !== passwordForm.value.confirm) return false
  if (mePayload.value?.has_password && !passwordForm.value.current) return false
  return true
})

async function savePassword() {
  if (!canSavePassword.value || passwordSaveState.value === 'saving') return
  passwordSaveState.value = 'saving'
  passwordError.value = ''
  try {
    const body = { new_password: passwordForm.value.next }
    if (mePayload.value?.has_password) body.current_password = passwordForm.value.current
    await api.post('/auth/password/change', body)
    passwordSaveState.value = 'saved'
    passwordForm.value = { current: '', next: '', confirm: '' }
    // After a successful set, refresh /me so the panel header flips from
    // "Set a password" to "Change password" (has_password is now true).
    try {
      const me = await api.get('/me')
      mePayload.value = me.data.data.user
    } catch {}
    // Auto-clear the success state after a few seconds.
    setTimeout(() => { if (passwordSaveState.value === 'saved') passwordSaveState.value = 'idle' }, 4000)
  } catch (e) {
    passwordSaveState.value = 'idle'
    passwordError.value = e.response?.data?.error?.message ?? 'Could not update password.'
  }
}

// ── Danger zone: data export + account deletion (GDPR) ────
const exportPending = ref(false)
const deleteModalOpen = ref(false)
const deleteConfirmEmail = ref('')
const deleteAccountPending = ref(false)
const deleteError = ref('')

async function downloadDataExport() {
  if (exportPending.value) return
  exportPending.value = true
  try {
    const response = await api.get('/me/export', { responseType: 'blob' })
    const blob = new Blob([response.data], { type: 'application/json' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    // Pull filename from Content-Disposition if present, else fall back to a sane default.
    const disposition = response.headers['content-disposition'] || ''
    const match = disposition.match(/filename="?([^";]+)"?/)
    a.download = match ? match[1] : `wyvstudio-export-${Date.now()}.json`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch {
    /* silent — user will retry */
  } finally {
    exportPending.value = false
  }
}

function openDeleteModal() {
  deleteConfirmEmail.value = ''
  deleteError.value = ''
  deleteModalOpen.value = true
}

async function deleteAccount() {
  if (!deleteConfirmEmail.value.trim()) {
    deleteError.value = 'Please type your email to confirm.'
    return
  }
  deleteAccountPending.value = true
  deleteError.value = ''
  try {
    await api.delete('/me', { data: { confirm_email: deleteConfirmEmail.value.trim() } })
    // Wipe local session + bounce to login.
    authStore.clearSession()
    router.push({ name: 'login', query: { deleted: '1' } })
  } catch (err) {
    deleteError.value = err.response?.data?.error?.message
      || (err.response?.status === 422 ? 'Email does not match.' : 'Could not delete your account. Please try again.')
  } finally {
    deleteAccountPending.value = false
  }
}

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
const activeSection = ref('brand')

// ── Connected Accounts ────────────────────────────────────
const socialAccounts    = ref([])
const socialLoading     = ref(false)
const disconnecting     = ref(null)

const PLATFORMS = [
  { key: 'youtube',   label: 'YouTube',         icon: '▶', note: 'Upload and schedule YouTube Shorts & videos' },
  { key: 'tiktok',    label: 'TikTok',           icon: '♪', note: 'Post directly to your TikTok account' },
  { key: 'instagram', label: 'Instagram Reels',  icon: '◈', note: 'Publish Reels to your Instagram Business or Creator account' },
  { key: 'facebook',  label: 'Facebook Reels',   icon: 'f', note: 'Publish Reels to your Facebook Page' },
]

function accountForPlatform(platform) {
  return socialAccounts.value.find(a => a.platform === platform) ?? null
}

async function loadSocialAccounts() {
  socialLoading.value = true
  try {
    const res = await api.get('/social/accounts')
    socialAccounts.value = res.data?.data?.accounts ?? []
  } catch { /* ignore */ } finally {
    socialLoading.value = false
  }
}

async function connectPlatform(platform) {
  const res = await api.get(`/social/${platform}/connect`)
  const url = res.data?.data?.url
  if (!url) return

  localStorage.removeItem('framecastOAuth')
  const popup = window.open(url, '_blank')
  if (!popup) return

  // Listen via localStorage storage event (postMessage / popup inspection can
  // trigger COOP warnings while the popup is on the provider's domain).
  const storageHandler = (e) => {
    if (e.key !== 'framecastOAuth' || !e.newValue) return
    cleanup()
    localStorage.removeItem('framecastOAuth')
    loadSocialAccounts()
  }

  // Fallback: poll localStorage in case the storage event is missed.
  const poll = setInterval(() => {
    const stored = localStorage.getItem('framecastOAuth')
    if (stored) {
      cleanup()
      localStorage.removeItem('framecastOAuth')
      loadSocialAccounts()
    }
  }, 600)

  const timeout = setTimeout(() => {
    cleanup()
  }, 5 * 60 * 1000)

  function cleanup() {
    window.removeEventListener('storage', storageHandler)
    clearInterval(poll)
    clearTimeout(timeout)
  }

  window.addEventListener('storage', storageHandler)
}

const disconnectTarget = ref(null)

function confirmDisconnect(accountId) {
  disconnectTarget.value = accountId
}

async function executeDisconnect() {
  const accountId = disconnectTarget.value
  disconnectTarget.value = null
  disconnecting.value = accountId
  try {
    await api.delete(`/social/accounts/${accountId}`)
    socialAccounts.value = socialAccounts.value.filter(a => a.id !== accountId)
  } catch { /* ignore */ } finally {
    disconnecting.value = null
  }
}

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

const deleteDialogTitle = computed(() => 'Delete Brand Kit?')
const deleteDialogMessage = computed(() => deleteTarget.value?.message || '')
const deleteDialogConfirmLabel = computed(() => deleteTarget.value?.confirmLabel || 'Confirm')

// ── API calls ─────────────────────────────────────────────
async function loadBillingStatus() {
  try {
    const { data } = await api.get('/billing/status')
    billing.value = data.data.billing
  } catch {
    // non-fatal — billing section will show gracefully without data
  }
}

async function openBillingPortal() {
  billingPortalPending.value = true
  billingError.value = ''
  try {
    const { data } = await api.post('/billing/portal')
    window.open(data.data.url, '_blank')
  } catch (err) {
    billingError.value = err?.response?.data?.error?.message || 'Could not open billing portal.'
  } finally {
    billingPortalPending.value = false
  }
}

async function loadSettings() {
  loading.value = true
  saveError.value = ''
  try {
    const [meRes, brandKitsRes, voicesRes] = await Promise.all([
      api.get('/me'), api.get('/brand-kits'), api.get('/voice-profiles'),
    ])
    mePayload.value = meRes.data.data.user
    usage.value     = meRes.data.data.usage
    referral.value  = meRes.data.data.referral || null
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

function requestDeleteBrandKit() {
  if (!brandKitForm.value.id) return
  deleteTarget.value = {
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
    await api.delete(`/brand-kits/${deleteTarget.value.id}`)
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

onMounted(() => {
  if (route.query.section) {
    activeSection.value = route.query.section
    if (activeSection.value === 'usage') loadCreditHistory()
  }
  loadSettings()
  loadBillingStatus()
  loadSocialAccounts()
})
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
            <div :class="['settings-tab', activeSection === 'brand'    ? 'active' : '']" @click="activeSection = 'brand'">Brand Kits</div>
            <div :class="['settings-tab', activeSection === 'account'  ? 'active' : '']" @click="activeSection = 'account'">Account</div>
            <div :class="['settings-tab', activeSection === 'accounts' ? 'active' : '']" @click="activeSection = 'accounts'">Connected Accounts</div>
            <div :class="['settings-tab', activeSection === 'usage'    ? 'active' : '']" @click="activeSection = 'usage'; loadCreditHistory()">Usage and Billing</div>
          </div>
        </div>

        <!-- ── Right pane ── -->
        <div class="surface-card settings-content-card">

          <!-- Brand Kits -->
          <div v-if="activeSection === 'brand'">
            <div class="section-title">Brand Kit Defaults</div>
            <div class="settings-section-desc">Reusable color, font, and voice presets applied across channels and series.</div>

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
                <div><div class="label-main">Watermark on free exports</div><div class="label-hint">Show WyvStudio branding on free tier</div></div>
                <div :class="['toggle', watermarkEnabled ? 'on' : '']" @click="watermarkEnabled = !watermarkEnabled"></div>
              </div>
            </div>

            <div class="modal-actions">
              <button class="btn btn-primary" type="button" @click="saveAccount">
                {{ saveState === 'saving' ? 'Saving…' : 'Save Account' }}
              </button>
            </div>

            <!-- Password: set (if none yet) or change. We treat both flows
                 in one panel — the current-password field only shows when
                 the user already has a password. -->
            <div class="section-title" style="margin-top:48px;">{{ mePayload?.has_password ? 'Change password' : 'Set a password' }}</div>
            <div class="settings-section-desc">
              {{ mePayload?.has_password
                ? 'Update the password you use to sign in. Magic-link sign-in continues to work either way.'
                : 'Add a password so you can sign in without a magic link. Magic-link sign-in continues to work either way.' }}
            </div>

            <div v-if="passwordSaveState === 'saved'" class="settings-row" style="background:rgba(52,211,153,0.07);border:1px solid rgba(52,211,153,0.25);border-radius:8px;padding:10px 14px;margin-top:14px;color:#a7f3d0;font-size:13px;">
              ✓ {{ mePayload?.has_password ? 'Password updated.' : 'Password set.' }}
            </div>
            <div v-if="passwordError" class="settings-row" style="background:rgba(255,80,80,0.07);border:1px solid rgba(255,80,80,0.25);border-radius:8px;padding:10px 14px;margin-top:14px;color:#fca5a5;font-size:13px;">
              {{ passwordError }}
            </div>

            <div v-if="mePayload?.has_password" class="settings-row settings-row--first" style="margin-top:14px;">
              <div class="settings-row-label"><div class="label-main">Current password</div></div>
              <div class="settings-row-control">
                <input v-model="passwordForm.current" class="settings-input" type="password" autocomplete="current-password" placeholder="········">
              </div>
            </div>
            <div :class="['settings-row', !mePayload?.has_password ? 'settings-row--first' : '']" :style="!mePayload?.has_password ? 'margin-top:14px;' : ''">
              <div class="settings-row-label"><div class="label-main">New password</div></div>
              <div class="settings-row-control">
                <input v-model="passwordForm.next" class="settings-input" type="password" autocomplete="new-password" minlength="8" placeholder="At least 8 characters">
              </div>
            </div>
            <div class="settings-row">
              <div class="settings-row-label"><div class="label-main">Confirm new password</div></div>
              <div class="settings-row-control">
                <input v-model="passwordForm.confirm" class="settings-input" type="password" autocomplete="new-password" minlength="8" placeholder="Re-enter the same password">
                <div v-if="passwordForm.confirm && passwordForm.confirm !== passwordForm.next" style="font-size:12px;color:#fca5a5;margin-top:6px;">Passwords don't match.</div>
              </div>
            </div>
            <div class="modal-actions">
              <button class="btn btn-primary" type="button" :disabled="!canSavePassword || passwordSaveState === 'saving'" @click="savePassword">
                {{ passwordSaveState === 'saving' ? 'Saving…' : (mePayload?.has_password ? 'Update password' : 'Set password') }}
              </button>
            </div>

            <!-- Data & privacy: GDPR export + account deletion -->
            <div class="section-title" style="margin-top:48px;">Your data</div>
            <div class="settings-section-desc">Download a copy of everything we hold about you, or delete your account permanently.</div>

            <div class="settings-row" style="margin-top:14px;">
              <div>
                <div class="label-main">Download my data</div>
                <div class="label-hint">JSON export of your profile, workspace, projects, characters, scheduled posts, and connected accounts.</div>
              </div>
              <button class="btn btn-ghost btn-sm" type="button" :disabled="exportPending" @click="downloadDataExport">
                {{ exportPending ? 'Preparing…' : '⬇ Download JSON' }}
              </button>
            </div>

            <div class="settings-row danger-row" style="margin-top:14px;">
              <div>
                <div class="label-main" style="color:#ff8888;">Delete account</div>
                <div class="label-hint">Permanently removes your account, workspace, projects, characters, and all generated content. Billing records may be retained where required by Nigerian tax law. Cannot be undone.</div>
              </div>
              <button class="btn btn-sm" type="button" style="background:rgba(220,80,80,0.14);border:1px solid rgba(220,80,80,0.45);color:#ff8888;" @click="openDeleteModal">
                Delete account
              </button>
            </div>
          </div>

          <!-- Connected Accounts -->
          <div v-else-if="activeSection === 'accounts'">
            <div class="section-title">Connected Accounts</div>
            <div class="settings-section-desc">Connect your social accounts to schedule and publish videos directly from WyvStudio.</div>

            <div class="connect-grid">
              <div
                v-for="plat in PLATFORMS"
                :key="plat.key"
                :class="['connect-card', accountForPlatform(plat.key) ? 'connected' : '']"
              >
                <div :class="['plat-icon', `plat-${plat.key}`]">{{ plat.icon }}</div>
                <div class="connect-card-info">
                  <div class="connect-card-name">{{ plat.label }}</div>
                  <div class="connect-card-detail">
                    <template v-if="accountForPlatform(plat.key)">
                      {{ accountForPlatform(plat.key).platform_display_name || accountForPlatform(plat.key).platform_username }}
                      <span v-if="accountForPlatform(plat.key).status === 'expired'" style="color:#fbbf24"> · Token expired</span>
                    </template>
                    <template v-else>{{ plat.note }}</template>
                  </div>
                </div>
                <div class="connect-card-actions">
                  <template v-if="plat.comingSoon">
                    <span class="plan-status-badge" style="color:#5a5a68">Coming soon</span>
                  </template>
                  <template v-else-if="accountForPlatform(plat.key)">
                    <span class="plan-status-badge" style="color:#34d399">● Connected</span>
                    <button
                      class="settings-btn settings-btn-sm settings-btn-danger"
                      :disabled="disconnecting === accountForPlatform(plat.key).id"
                      @click="confirmDisconnect(accountForPlatform(plat.key).id)"
                    >Disconnect</button>
                  </template>
                  <template v-else>
                    <button class="settings-btn settings-btn-sm settings-btn-primary" @click="connectPlatform(plat.key)">Connect →</button>
                  </template>
                </div>
              </div>
            </div>

            <div class="settings-hint">
              WyvStudio only requests permissions to upload and post videos. We never read your messages, contacts, or follower list.
            </div>
          </div>

          <!-- Usage & Billing -->
          <div v-else>
            <div class="section-title">Usage and Billing</div>
            <div class="settings-section-desc">Current plan usage and upgrade options.</div>

            <!-- Current plan pill -->
            <div v-if="billing" class="plan-status-row">
              <div class="plan-pill">
                <span class="plan-name">{{ planLabel }}</span>
                <span class="plan-status-badge" :style="{ color: planStatusColor }">{{ planStatusLabel }}</span>
              </div>
              <div v-if="billing.plan_renews_at" class="plan-renews">
                Renews {{ new Date(billing.plan_renews_at).toLocaleDateString() }}
              </div>
            </div>

            <div v-if="billingError" class="banner error" style="margin-bottom:12px;">{{ billingError }}</div>

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

            <div style="display:flex; gap:10px; margin:16px 0 22px; flex-wrap:wrap;">
              <!-- Upgrade buttons — one per paid plan (Kelviq hosted checkout) -->
              <template v-if="isFreePlan && billing">
                <button class="btn btn-primary" type="button" :disabled="checkoutPending" @click="startCheckout({ plan: 'starter' })">Upgrade — Starter $19</button>
                <button class="btn btn-ghost" type="button" :disabled="checkoutPending" @click="startCheckout({ plan: 'creator' })">Creator $39</button>
                <button class="btn btn-ghost" type="button" :disabled="checkoutPending" @click="startCheckout({ plan: 'pro' })">Pro $79</button>
                <button class="btn btn-ghost" type="button" :disabled="checkoutPending" @click="startCheckout({ plan: 'agency' })">Agency $149</button>
              </template>
              <button
                v-if="hasSubscription"
                class="btn btn-ghost"
                type="button"
                :disabled="billingPortalPending"
                @click="openBillingPortal"
              >{{ billingPortalPending ? 'Opening…' : 'Manage Billing' }}</button>
            </div>

            <!-- Credit top-up packs -->
            <div v-if="billing?.topup_packs?.length" class="topup-section">
              <div class="section-title" style="font-size:13px;margin-bottom:6px">Buy Credit Top-Up</div>
              <div class="settings-section-desc" style="margin-bottom:12px">One-time purchase. Top-up credits never expire while you have an active paid plan.</div>
              <div class="topup-grid">
                <button
                  v-for="pack in billing.topup_packs" :key="pack.key"
                  class="topup-pack"
                  :disabled="checkoutPending"
                  @click="startCheckout({ topup: pack.key })"
                >
                  <div class="topup-credits">{{ pack.credits.toLocaleString() }}</div>
                  <div class="topup-credits-label">credits</div>
                  <div class="topup-price">${{ pack.price_usd }}</div>
                  <div class="topup-per-credit">${{ (pack.price_usd / pack.credits).toFixed(3) }}/credit</div>
                </button>
              </div>
            </div>

            <table class="table-clean">
              <thead><tr><th>Feature</th><th>Free</th><th>Creator</th><th>Pro</th></tr></thead>
              <tbody>
                <tr><td>Credits / mo</td><td>200 (one-off)</td><td class="col-accent">3,000</td><td>6,500</td></tr>
                <tr><td>Renders / mo</td><td>10</td><td class="col-accent">200</td><td>1,000</td></tr>
                <tr><td>Channels</td><td>1</td><td class="col-accent">3</td><td>10</td></tr>
                <tr><td>AI image quality</td><td>Medium</td><td class="col-accent">Med + High</td><td>Med + High</td></tr>
                <tr><td>Watermark</td><td>Yes</td><td class="col-accent">No</td><td>No</td></tr>
              </tbody>
            </table>

            <!-- ── Refer a friend ──────────────────────────────────────── -->
            <div v-if="referral?.link" class="section-title" style="margin-top:48px;">Refer a friend</div>
            <div v-if="referral?.link" class="settings-section-desc">
              Share your link. When someone you invite upgrades to a paid plan, you earn
              <strong>{{ referral.reward_credits }} credits</strong>.
            </div>
            <div v-if="referral?.link" class="referral-row">
              <input class="referral-link-input" type="text" :value="referral.link" readonly @focus="$event.target.select()" />
              <button class="btn-copy-referral" type="button" @click="copyReferral">
                {{ referralCopied ? 'Copied ✓' : 'Copy link' }}
              </button>
            </div>

            <!-- ── Credit history ──────────────────────────────────────── -->
            <div class="section-title" style="margin-top:48px;">Credit history</div>
            <div class="settings-section-desc">Every credit deducted or granted on this workspace.</div>

            <div v-if="creditHistoryLoading" class="banner" style="margin:8px 0 14px;">Loading credit history…</div>

            <div v-else-if="creditHistory">
              <!-- Per-operation roll-up + balance pills -->
              <div class="credit-summary-row">
                <div class="credit-summary-pill">
                  Balance now: <strong>{{ creditHistory.balance.toLocaleString() }}</strong>
                </div>
                <div class="credit-summary-pill muted">
                  Spent <strong>{{ creditHistorySpent.toLocaleString() }}</strong> · added <strong>+{{ creditHistoryGranted.toLocaleString() }}</strong> in this window
                </div>
              </div>

              <div v-if="creditHistory.summary.length" class="credit-summary-grid">
                <div v-for="row in creditHistory.summary" :key="row.operation" class="credit-summary-card">
                  <div class="credit-op-name">{{ formatOperation(row.operation) }}</div>
                  <div class="credit-op-row">
                    <span :class="['credit-op-credits', row.operation.startsWith('grant:') ? 'grant' : '']">
                      {{ row.operation.startsWith('grant:') ? '+' : '' }}{{ Math.abs(row.credits).toLocaleString() }} cr
                    </span>
                    <span class="credit-op-count">{{ row.ops }} {{ row.ops === 1 ? 'op' : 'ops' }}</span>
                  </div>
                </div>
              </div>

              <!-- Filter / sort / window controls -->
              <div class="credit-controls">
                <div class="credit-control-group">
                  <button
                    v-for="opt in [{key:'all',label:'All'},{key:'debits',label:'Spent'},{key:'grants',label:'Added'}]"
                    :key="opt.key"
                    :class="['credit-chip', creditHistoryFilter === opt.key ? 'active' : '']"
                    type="button"
                    @click="creditHistoryFilter = opt.key; reloadCreditHistory()"
                  >{{ opt.label }}</button>
                </div>

                <div class="credit-control-group">
                  <label class="credit-select-label">Window</label>
                  <select v-model="creditHistorySince" class="credit-select" @change="reloadCreditHistory">
                    <option :value="7">Last 7 days</option>
                    <option :value="30">Last 30 days</option>
                    <option :value="90">Last 90 days</option>
                    <option :value="0">All time</option>
                  </select>
                </div>

                <div class="credit-control-group">
                  <label class="credit-select-label">Sort</label>
                  <select v-model="creditHistorySort" class="credit-select" @change="reloadCreditHistory">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="largest">Largest first</option>
                  </select>
                </div>
              </div>

              <!-- Per-entry detail table -->
              <div v-if="creditHistoryEntries.length" class="credit-entries-wrap">
                <table class="credit-entries">
                  <thead>
                    <tr>
                      <th>When</th>
                      <th>Operation</th>
                      <th style="text-align:right">Credits</th>
                      <th style="text-align:right">Balance after</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="e in creditHistoryEntries" :key="e.id">
                      <td>{{ new Date(e.created_at).toLocaleString() }}</td>
                      <td>
                        <span :class="['op-tag', e.operation.startsWith('grant:') ? 'grant' : '']">{{ formatOperation(e.operation) }}</span>
                      </td>
                      <td style="text-align:right" :class="e.operation.startsWith('grant:') ? 'credit-grant' : 'credit-debit'">
                        {{ e.operation.startsWith('grant:') ? '+' : '−' }}{{ Math.abs(e.credits).toLocaleString() }}
                      </td>
                      <td style="text-align:right;font-family:'Space Mono',monospace;font-size:11px;opacity:.7">
                        {{ e.balance_after.toLocaleString() }}
                      </td>
                    </tr>
                  </tbody>
                </table>

                <div class="credit-pagination">
                  <span class="credit-page-info">{{ creditHistoryEntries.length }} {{ creditHistoryEntries.length === 1 ? 'entry' : 'entries' }} shown</span>
                  <button
                    v-if="creditHistoryHasMore && creditHistorySort !== 'largest'"
                    class="btn btn-ghost btn-sm"
                    type="button"
                    :disabled="creditHistoryLoading"
                    @click="loadCreditHistory({ append: true })"
                  >
                    {{ creditHistoryLoading ? 'Loading…' : 'Load more →' }}
                  </button>
                  <span v-else-if="creditHistorySort === 'largest'" class="credit-page-info muted">
                    Switch sort to Newest / Oldest to paginate further.
                  </span>
                  <span v-else class="credit-page-info muted">All caught up.</span>
                </div>
              </div>
              <div v-else class="credit-empty">No credit activity matches these filters.</div>
            </div>

            <div v-else-if="creditHistoryError" class="banner error" style="margin-top:12px;">{{ creditHistoryError }}</div>
          </div>

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
    <ConfirmDialog
      :open="Boolean(disconnectTarget)"
      title="Disconnect account?"
      message="This will remove the connection. Any scheduled posts using this account will be cancelled."
      confirm-label="Disconnect"
      destructive
      @close="disconnectTarget = null"
      @confirm="executeDisconnect"
    />

    <LimitModal
      :open="limitModalOpen"
      title="Usage is approaching your plan limits"
      subtitle="Upgrade before your next batch so channels, renders, and voice capacity do not block production."
      :rows="limitRows"
      @close="limitModalOpen = false"
      @upgrade="limitModalOpen = false; activeSection = 'usage'"
    />

    <!-- GDPR account deletion modal -->
    <Teleport to="body">
      <div v-if="deleteModalOpen" class="del-backdrop" @click.self="deleteAccountPending || (deleteModalOpen = false)">
        <div class="del-modal">
          <div class="del-title">Delete your account?</div>
          <div class="del-body">
            This will permanently delete:
            <ul style="margin:10px 0 12px 18px; line-height:1.6;">
              <li>Your account + workspace</li>
              <li>All projects, scenes, and generated assets</li>
              <li>Characters and animation history</li>
              <li>Connected social accounts and pending scheduled posts</li>
            </ul>
            This cannot be undone. To confirm, type your email below.
          </div>
          <input
            v-model="deleteConfirmEmail"
            class="del-input"
            :placeholder="$attrs['data-confirm-placeholder'] || 'your email'"
            autocomplete="off"
          />
          <div v-if="deleteError" class="del-error">{{ deleteError }}</div>
          <div class="del-foot">
            <button class="btn btn-ghost btn-sm" type="button" :disabled="deleteAccountPending" @click="deleteModalOpen = false">Cancel</button>
            <button class="btn btn-sm del-confirm" type="button" :disabled="deleteAccountPending" @click="deleteAccount">
              {{ deleteAccountPending ? 'Deleting…' : 'Delete account permanently' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

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

.main { margin-left: var(--sidebar-width, 220px); min-height: 100vh; padding: 24px; }

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

.brand-kit-swatches { display: flex; gap: 6px; }

.brand-kit-swatch {
  width: 28px;
  height: 28px;
  border-radius: 8px;
  border: 1px solid rgba(255,255,255,0.15);
}

.brand-kit-name { color: #ececf3; font-size: 13px; font-weight: 600; }
.brand-kit-meta { color: #6a6a7c; font-size: 11px; line-height: 1.4; }

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

.brand-font-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }

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

/* ── Credit history ── */
.credit-summary-row { display: flex; gap: 10px; flex-wrap: wrap; margin: 10px 0 18px; }
.credit-summary-pill { padding: 8px 14px; border-radius: 999px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); font-size: 12px; color: var(--color-text-primary); }
.credit-summary-pill.muted { color: var(--color-text-muted); }
.credit-summary-pill strong { color: var(--color-accent); margin-left: 4px; }
.credit-summary-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin-bottom: 22px; }
.credit-summary-card { padding: 12px 14px; border-radius: 8px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); }
.credit-op-name { font-size: 12px; font-weight: 500; color: var(--color-text-primary); margin-bottom: 6px; }
.credit-op-row { display: flex; align-items: baseline; justify-content: space-between; }
.credit-op-credits { font-size: 14px; font-weight: 600; color: var(--color-accent); font-family: "Space Mono", monospace; }
.credit-op-count { font-size: 11px; color: var(--color-text-muted); }
.credit-entries-wrap { margin-top: 14px; }
.credit-entries-head { display: flex; justify-content: space-between; font-size: 11px; color: var(--color-text-muted); font-family: "Space Mono", monospace; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 6px; }
.credit-entries { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.credit-entries th { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--color-border); color: var(--color-text-muted); font-weight: 500; background: var(--color-bg-elevated); }
.credit-entries td { padding: 10px; border-bottom: 1px solid var(--color-border); color: var(--color-text-secondary); }
.credit-entries tr:hover td { background: rgba(255,107,53,0.03); }
.op-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); font-size: 11px; color: var(--color-text-primary); font-family: "Space Mono", monospace; }
.op-tag.grant { color: #34d399; border-color: rgba(52,211,153,0.4); background: rgba(52,211,153,0.06); }
.credit-debit { color: var(--color-text-primary); font-family: "Space Mono", monospace; }
.credit-grant { color: #34d399; font-family: "Space Mono", monospace; }
.credit-empty { font-size: 13px; color: var(--color-text-muted); padding: 16px; text-align: center; border: 1px dashed var(--color-border); border-radius: 8px; }
.credit-controls { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; margin: 14px 0 12px; padding: 12px 14px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; }
.credit-control-group { display: flex; align-items: center; gap: 6px; }
.credit-chip { font-size: 12px; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-secondary); cursor: pointer; font-family: inherit; transition: 0.15s; }
.credit-chip:hover { border-color: rgba(255,107,53,0.4); color: var(--color-text-primary); }
.credit-chip.active { background: rgba(255,107,53,0.1); border-color: var(--color-accent); color: var(--color-accent); }
.credit-select-label { font-size: 11px; color: var(--color-text-muted); font-family: "Space Mono", monospace; letter-spacing: 0.05em; text-transform: uppercase; }
.credit-select { font-size: 12px; padding: 5px 24px 5px 10px; border-radius: 6px; border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary); font-family: inherit; cursor: pointer; }
.credit-pagination { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 4px 0; margin-top: 6px; }
.credit-page-info { font-size: 11px; color: var(--color-text-muted); font-family: "Space Mono", monospace; letter-spacing: 0.05em; }
.credit-page-info.muted { opacity: 0.5; }
.credit-op-credits.grant { color: #34d399; }

/* ── Plan table ── */
.topup-section { margin: 22px 0; padding: 18px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 10px; }
.referral-row { display: flex; gap: 10px; align-items: stretch; max-width: 560px; }
.referral-link-input { flex: 1; min-width: 0; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); font-family: "Space Mono", monospace; font-size: 13px; }
.btn-copy-referral { flex-shrink: 0; padding: 10px 18px; border-radius: 8px; border: none; background: var(--color-accent); color: #fff; font-weight: 600; cursor: pointer; transition: transform .15s; }
.btn-copy-referral:hover { transform: translateY(-1px); }
.topup-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
.topup-pack { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 10px; padding: 16px 12px; cursor: pointer; transition: .15s; text-align: center; color: var(--color-text-primary); font-family: inherit; }
.topup-pack:hover:not(:disabled) { border-color: var(--color-accent); transform: translateY(-1px); }
.topup-pack:disabled { opacity: .4; cursor: not-allowed; }
.topup-credits { font-size: 22px; font-weight: 700; color: var(--color-accent); }
.topup-credits-label { font-size: 11px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 8px; }
.topup-price { font-size: 16px; font-weight: 600; }
.topup-per-credit { font-size: 10px; color: var(--color-text-muted); margin-top: 2px; }
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

/* ── Billing section ── */
.plan-status-row {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 18px;
}

.plan-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  background: rgba(255,255,255,0.04);
  border: 1px solid #2a2a36;
  border-radius: 20px;
  padding: 6px 14px;
}

.plan-name {
  font-size: 13px;
  font-weight: 600;
  color: #ececf3;
}

.plan-status-badge {
  font-size: 11px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.plan-renews {
  font-size: 12px;
  color: #6a6a7c;
}

/* ── Connected Accounts ── */
.connect-grid { display: grid; gap: 10px; margin-bottom: 20px; }
.connect-card { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 10px; border: 1px solid var(--color-border); background: transparent; transition: .15s; }
.connect-card:hover { background: var(--color-bg-elevated); }
.connect-card.connected { border-color: rgba(52,211,153,.2); background: rgba(52,211,153,.03); }
.connect-card-info { flex: 1; min-width: 0; }
.connect-card-name { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.connect-card-detail { font-size: 11px; color: var(--color-text-muted); }
.connect-card-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.plat-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; flex-shrink: 0; }
.plat-youtube { background: rgba(255,0,0,.1); color: #ff4444; }
.plat-tiktok { background: rgba(255,255,255,.06); color: var(--color-text-primary); border: 1px solid var(--color-border); }
.plat-instagram { background: rgba(225,48,108,.12); color: #e1306c; }
.plat-facebook { background: rgba(24,119,242,.12); color: #1877f2; }
.settings-btn { cursor: pointer; }
.settings-btn-sm { padding: 5px 10px; font-size: 11px; }
.settings-btn-danger { color: #f87171; border-color: rgba(248,113,113,.25); }
.settings-btn-danger:hover { background: rgba(248,113,113,.1); }
.settings-hint { font-size: 11px; color: var(--color-text-muted); line-height: 1.55; padding: 10px 14px; background: var(--color-bg-elevated); border-radius: 8px; border: 1px solid var(--color-border); }

@media (max-width: 1000px) {
  .settings-shell { grid-template-columns: 1fr; }
  .brand-color-grid,
  .brand-font-grid { grid-template-columns: 1fr; }
}

/* GDPR delete modal + danger row */
.danger-row {
  border: 1px solid rgba(220, 80, 80, 0.25);
  border-radius: 10px;
  padding: 14px 16px;
  background: rgba(220, 80, 80, 0.05);
}
.del-backdrop {
  position: fixed; inset: 0; z-index: 10000;
  background: rgba(0,0,0,0.6); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.del-modal {
  width: 100%; max-width: 480px;
  background: #14141c;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 14px;
  padding: 22px 24px;
  box-shadow: 0 24px 60px rgba(0,0,0,0.6);
  color: #ececf3;
  font-family: 'DM Sans', sans-serif;
}
.del-title { font-size: 17px; font-weight: 700; margin-bottom: 12px; }
.del-body { font-size: 13.5px; line-height: 1.55; color: #cdcdd4; }
.del-input {
  width: 100%; margin-top: 6px;
  background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.12);
  border-radius: 8px; padding: 10px 12px;
  color: #ececf3; font-family: inherit; font-size: 13px; outline: none;
}
.del-input:focus { border-color: #ff6b35; }
.del-error { color: #ff8888; font-size: 12.5px; margin-top: 8px; }
.del-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; }
.del-confirm {
  background: rgba(220, 80, 80, 0.15);
  border: 1px solid rgba(220, 80, 80, 0.6);
  color: #ff8888;
}
.del-confirm:not(:disabled):hover { background: rgba(220,80,80,0.25); }
</style>
