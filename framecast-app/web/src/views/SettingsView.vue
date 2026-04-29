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
const hasPaddleSubscription = computed(() => Boolean(billing.value?.paddle_customer_id))

function openPaddleCheckout(priceId) {
  if (!priceId || !window.Paddle) return
  const paddleCfg = billing.value?.paddle_sandbox ? { environment: 'sandbox' } : {}
  window.Paddle.Setup({ ...paddleCfg, token: billing.value?.paddle_client_token })
  window.Paddle.Checkout.open({
    items: [{ priceId, quantity: 1 }],
  })
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
  if (route.query.section) activeSection.value = route.query.section
  loadSettings()
  loadBillingStatus()
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
            <div :class="['settings-tab', activeSection === 'brand'   ? 'active' : '']" @click="activeSection = 'brand'">Brand Kits</div>
            <div :class="['settings-tab', activeSection === 'account' ? 'active' : '']" @click="activeSection = 'account'">Account</div>
            <div :class="['settings-tab', activeSection === 'usage'   ? 'active' : '']" @click="activeSection = 'usage'">Usage and Billing</div>
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
              <!-- Upgrade buttons — one per paid plan -->
              <template v-if="isFreePlan && billing">
                <button
                  v-if="billing.price_ids?.studio"
                  class="btn btn-primary"
                  type="button"
                  @click="openPaddleCheckout(billing.price_ids.studio)"
                >Upgrade to Studio</button>
                <button
                  v-if="billing.price_ids?.scale"
                  class="btn btn-ghost"
                  type="button"
                  @click="openPaddleCheckout(billing.price_ids.scale)"
                >Upgrade to Scale</button>
              </template>
              <button
                v-if="hasPaddleSubscription"
                class="btn btn-ghost"
                type="button"
                :disabled="billingPortalPending"
                @click="openBillingPortal"
              >{{ billingPortalPending ? 'Opening…' : 'Manage Billing' }}</button>
              <button v-if="isFreePlan && !billing?.price_ids?.studio" class="btn btn-primary" type="button" @click="limitModalOpen = true">Upgrade</button>
            </div>

            <table class="table-clean">
              <thead><tr><th>Feature</th><th>Free</th><th>Studio</th><th>Scale</th></tr></thead>
              <tbody>
                <tr><td>Renders / mo</td><td>10</td><td class="col-accent">200</td><td>1,000</td></tr>
                <tr><td>Voice min</td><td>20</td><td class="col-accent">120</td><td>600</td></tr>
                <tr><td>Channels</td><td>1</td><td class="col-accent">5</td><td>25</td></tr>
                <tr><td>Dub languages</td><td>1</td><td class="col-accent">3</td><td>12</td></tr>
                <tr><td>Voice cloning</td><td>-</td><td class="col-accent">2 voices</td><td>10 voices</td></tr>
              </tbody>
            </table>
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

    <LimitModal
      :open="limitModalOpen"
      title="Usage is approaching your plan limits"
      subtitle="Upgrade before your next batch so channels, renders, and voice capacity do not block production."
      :rows="limitRows"
      @close="limitModalOpen = false"
      @upgrade="limitModalOpen = false; activeSection = 'usage'"
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

@media (max-width: 1000px) {
  .settings-shell { grid-template-columns: 1fr; }
  .brand-color-grid,
  .brand-font-grid { grid-template-columns: 1fr; }
}
</style>
