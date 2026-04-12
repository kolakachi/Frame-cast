<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'

const router = useRouter()
const authStore = useAuthStore()

const mePayload = ref(null)
const usagePayload = ref(null)
const loading = ref(true)
const error = ref('')
const assets = ref([])
const selectedAsset = ref(null)
const uploadPending = ref(false)
const uploadError = ref('')
const showUploadPanel = ref(false)
const archiveTarget = ref(null)
const archivePending = ref(false)

const searchQuery = ref('')
const activeFilter = ref('')
const uploadTitle = ref('')
const uploadType = ref('video')
const uploadTags = ref('')
const uploadFile = ref(null)
const currentPage = ref(1)
const perPage = ref(12)
const totalAssets = ref(0)
const lastPage = ref(1)

const perPageOptions = [6, 12, 18, 24]

const filterChips = [
  { value: '', label: 'All' },
  { value: 'video', label: 'Video' },
  { value: 'audio', label: 'Audio' },
  { value: 'image', label: 'Images' },
  { value: 'voice', label: 'Voices' },
  { value: 'template', label: 'Templates' },
]

const assetTypes = [
  { value: 'video', label: 'Video' },
  { value: 'audio', label: 'Audio' },
  { value: 'voice', label: 'Voice' },
  { value: 'image', label: 'Image' },
  { value: 'template', label: 'Template' },
  { value: 'scene_block', label: 'Scene Block' },
]

const typeConfig = {
  video: { icon: '🎬', gradient: 'linear-gradient(135deg, #0f2027, #2c5364)', badgeClass: 'badge-video' },
  audio: { icon: '🎵', gradient: 'linear-gradient(135deg, #2a1a30, #4a2a50)', badgeClass: 'badge-audio' },
  voice: { icon: '🎙', gradient: 'linear-gradient(135deg, #1a1a30, #3a2a50)', badgeClass: 'badge-voice' },
  image: { icon: '🖼', gradient: 'linear-gradient(135deg, #1a2a20, #2a4a30)', badgeClass: 'badge-image' },
  template: { icon: '📐', gradient: 'linear-gradient(135deg, #2a2a1a, #4a4a2a)', badgeClass: 'badge-template' },
  scene_block: { icon: '📐', gradient: 'linear-gradient(135deg, #2a2a1a, #4a4a2a)', badgeClass: 'badge-template' },
}

function getTypeConfig(type) {
  return typeConfig[type] || { icon: '📎', gradient: 'linear-gradient(135deg, #1a1a2a, #2a2a3a)', badgeClass: 'badge-template' }
}

const topUsed = computed(() =>
  [...assets.value].sort((a, b) => b.usage_count - a.usage_count).slice(0, 3)
)

const filteredAssets = computed(() => assets.value)
const pageFrom = computed(() => (assets.value.length === 0 ? 0 : ((currentPage.value - 1) * perPage.value) + 1))
const pageTo = computed(() => Math.min(totalAssets.value, ((currentPage.value - 1) * perPage.value) + assets.value.length))
const summaryCount = computed(() => Number(usagePayload.value?.assets || 0))
const archiveMessage = computed(() => {
  if (!archiveTarget.value) return ''
  return `"${archiveTarget.value.title}" will be removed from the active library and hidden from default selectors.`
})

function pickAsset(asset) {
  selectedAsset.value = asset
}

function closeAsset() {
  selectedAsset.value = null
}

async function fetchMe() {
  const { data } = await api.get('/me')
  mePayload.value = data.data.user
  usagePayload.value = data.data.usage || null
}

async function fetchAssets() {
  const params = {}
  if (activeFilter.value) params.asset_type = activeFilter.value
  if (searchQuery.value.trim()) params.q = searchQuery.value.trim()
  params.page = currentPage.value
  params.per_page = perPage.value
  const { data } = await api.get('/assets', { params })
  assets.value = data.data.assets || []
  totalAssets.value = Number(data.meta?.pagination?.total || 0)
  lastPage.value = Number(data.meta?.pagination?.last_page || 1)
  if (currentPage.value > lastPage.value && lastPage.value > 0) {
    currentPage.value = lastPage.value
  }
}

async function refreshAll() {
  loading.value = true
  error.value = ''
  try {
    const [meResult, assetsResult] = await Promise.allSettled([fetchMe(), fetchAssets()])

    if (meResult.status === 'rejected' && assetsResult.status === 'rejected') {
      error.value = 'Could not load the asset library.'
      return
    }

    if (meResult.status === 'rejected') {
      error.value = 'Could not load your workspace profile.'
    }

    if (assetsResult.status === 'rejected') {
      error.value = assetsResult.reason?.response?.data?.error?.message || 'Could not load the asset library.'
    }
  } catch (err) {
    error.value = err?.response?.data?.error?.message || 'Could not load the asset library.'
  } finally {
    loading.value = false
  }
}

async function uploadAsset() {
  if (!uploadFile.value) {
    uploadError.value = 'Choose a file to upload.'
    return
  }

  uploadPending.value = true
  uploadError.value = ''

  try {
    const formData = new FormData()
    formData.append('title', uploadTitle.value.trim() || uploadFile.value.name)
    formData.append('asset_type', uploadType.value)
    formData.append('asset_file', uploadFile.value)

    const tagList = uploadTags.value.split(',').map(t => t.trim()).filter(Boolean)
    tagList.forEach((tag, i) => formData.append(`tags[${i}]`, tag))

    await api.post('/assets', formData, { headers: { 'Content-Type': 'multipart/form-data' } })

    uploadTitle.value = ''
    uploadTags.value = ''
    uploadFile.value = null
    showUploadPanel.value = false
    uploadError.value = ''
    currentPage.value = 1

    await Promise.all([fetchMe(), fetchAssets()])
  } catch (err) {
    uploadError.value = err?.response?.data?.error?.message || 'Upload failed.'
  } finally {
    uploadPending.value = false
  }
}

function requestArchiveAsset(asset) {
  archiveTarget.value = asset
}

function closeArchiveConfirm() {
  if (archivePending.value) return
  archiveTarget.value = null
}

async function archiveAsset() {
  if (!archiveTarget.value) return

  archivePending.value = true

  try {
    await api.delete(`/assets/${archiveTarget.value.id}`)
    if (selectedAsset.value?.id === archiveTarget.value.id) selectedAsset.value = null
    if (assets.value.length === 1 && currentPage.value > 1) {
      currentPage.value -= 1
    }
    await Promise.all([fetchMe(), fetchAssets()])
    archiveTarget.value = null
  } catch (err) {
    error.value = err?.response?.data?.error?.message || 'Could not archive the asset.'
  } finally {
    archivePending.value = false
  }
}

function previousPage() {
  if (currentPage.value <= 1) return
  currentPage.value -= 1
}

function nextPage() {
  if (currentPage.value >= lastPage.value) return
  currentPage.value += 1
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

onMounted(refreshAll)

let searchTimer = null
watch([searchQuery, activeFilter], () => {
  if (loading.value) return

  if (searchTimer) {
    clearTimeout(searchTimer)
  }

  searchTimer = setTimeout(() => {
    currentPage.value = 1
    fetchAssets()
  }, 180)
})

watch([currentPage, perPage], () => {
  if (loading.value) return
  fetchAssets()
})
</script>

<template>
  <div class="fc-shell">
    <AppSidebar :user="mePayload" active-page="asset-library" @logout="logout" />

    <div class="main">
      <div v-if="error" class="banner error">{{ error }}</div>

      <div v-if="loading" class="page-state">Loading asset library...</div>

      <div v-else>
        <div class="asset-overview">
          <div class="surface-card asset-summary-card">
            <div class="summary-kicker">Asset Library</div>
            <div class="summary-big">{{ summaryCount }} assets</div>
            <div class="summary-copy">Rebuilt as a proper system view instead of just a big grid, so it reads as part of the product and not a detached page.</div>
          </div>

          <div class="surface-card asset-activity-card">
            <div class="section-title">What is used most</div>
            <div class="mini-metrics">
              <div v-if="topUsed.length" class="mini-metrics-grid">
                <div v-for="asset in topUsed" :key="asset.id" class="mini-metric">
                  <strong>{{ asset.usage_count }}</strong>
                  <span>{{ asset.title }}</span>
                </div>
              </div>
              <div v-else class="mini-metric">
                <strong>—</strong>
                <span>No usage data yet</span>
              </div>
            </div>
          </div>
        </div>

        <div class="section-header">
          <div>
            <div class="section-title">Library Contents</div>
            <div class="section-subtitle">Search, filter, and quick-scan cards share the same spacing and tone as the rest of the app.</div>
          </div>

          <div class="section-header-actions">
            <label class="per-page-inline">
              <span>Per page</span>
              <select v-model="perPage" class="field-select field-select-compact">
                <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
              </select>
            </label>

            <button class="btn btn-primary btn-sm" type="button" @click="showUploadPanel = !showUploadPanel">
              {{ showUploadPanel ? 'Cancel' : 'Upload' }}
            </button>
          </div>
        </div>

        <div v-if="showUploadPanel" class="upload-panel surface-card">
          <div class="upload-panel-title">Upload Asset</div>
          <div class="upload-form-row">
            <label class="field-label">
              <span>Title</span>
              <input v-model="uploadTitle" class="field-input" type="text" placeholder="Weekly intro loop" />
            </label>

            <label class="field-label">
              <span>Type</span>
              <select v-model="uploadType" class="field-select">
                <option v-for="type in assetTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
              </select>
            </label>

            <label class="field-label">
              <span>Tags</span>
              <input v-model="uploadTags" class="field-input" type="text" placeholder="finance, intro, evergreen" />
            </label>

            <label class="upload-zone">
              <input type="file" hidden @change="uploadFile = $event.target.files?.[0] || null" />
              <span>{{ uploadFile ? uploadFile.name : 'Choose a file' }}</span>
            </label>
          </div>

          <div v-if="uploadError" class="banner error">{{ uploadError }}</div>

          <div class="upload-form-actions">
            <button class="btn btn-ghost" type="button" @click="showUploadPanel = false">Cancel</button>
            <button class="btn btn-primary" type="button" :disabled="uploadPending" @click="uploadAsset">
              {{ uploadPending ? 'Uploading...' : 'Upload Asset' }}
            </button>
          </div>
        </div>

        <div class="asset-toolbar">
          <div class="search-wrap">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <circle cx="11" cy="11" r="8" />
              <path d="M21 21l-4.35-4.35" />
            </svg>
            <input v-model="searchQuery" class="search-box" placeholder="Search assets by name, tag, or type" />
          </div>

          <div class="filter-chips">
            <div
              v-for="chip in filterChips"
              :key="chip.value"
              :class="['filter-chip', activeFilter === chip.value ? 'active' : '']"
              @click="activeFilter = chip.value"
            >
              {{ chip.label }}
            </div>
          </div>
        </div>

        <div v-if="filteredAssets.length === 0" class="empty-state">
          <div class="empty-title">No assets yet</div>
          <div class="empty-copy">
            {{
              searchQuery || activeFilter
                ? 'No assets match this search or filter yet.'
                : 'Upload a video, audio file, image, or reusable block to start building your workspace library.'
            }}
          </div>
        </div>

        <div v-else class="asset-grid">
          <button v-for="asset in filteredAssets" :key="asset.id" class="asset-card" type="button" @click="pickAsset(asset)">
            <div class="asset-preview" :style="{ background: getTypeConfig(asset.asset_type).gradient }">
              <span :class="['asset-type-badge', getTypeConfig(asset.asset_type).badgeClass]">
                {{ asset.asset_type.replace('_', ' ') }}
              </span>
              <div class="asset-icon">{{ getTypeConfig(asset.asset_type).icon }}</div>
            </div>

            <div class="asset-details">
              <div class="asset-title">{{ asset.title }}</div>
              <div class="asset-meta-row">
                <span>{{ asset.mime_type || 'Unknown' }}</span>
                <span>{{ asset.usage_count }}×</span>
              </div>
              <div class="asset-tags">
                <span v-for="tag in asset.tags.slice(0, 3)" :key="tag" class="asset-tag">{{ tag }}</span>
              </div>
            </div>
          </button>

          <div class="asset-card asset-card-add" @click="showUploadPanel = true">
            <div class="add-card-inner">
              <div class="add-card-plus">+</div>
              <div class="add-card-label">Upload Asset</div>
            </div>
          </div>
        </div>

        <div v-if="totalAssets > 0" class="asset-pagination">
          <div class="asset-pagination-copy">Showing {{ pageFrom }}-{{ pageTo }} of {{ totalAssets }} assets</div>
          <div class="asset-pagination-actions">
            <button class="btn btn-ghost btn-sm" type="button" :disabled="currentPage <= 1" @click="previousPage">Previous</button>
            <button class="btn btn-ghost btn-sm" type="button" :disabled="currentPage >= lastPage" @click="nextPage">Next</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="selectedAsset" class="drawer-backdrop" @click="closeAsset"></div>

    <aside :class="['drawer', selectedAsset ? 'open' : '']">
      <div v-if="selectedAsset" class="drawer-inner">
        <div class="drawer-header">
          <div>
            <div class="drawer-title">{{ selectedAsset.title }}</div>
            <div class="drawer-subtitle">{{ selectedAsset.asset_type.replace('_', ' ') }} · {{ selectedAsset.mime_type || 'Unknown format' }}</div>
          </div>
          <button class="close-btn" type="button" @click="closeAsset">×</button>
        </div>

        <div class="drawer-section">
          <div class="drawer-section-label">Description</div>
          <div class="detail-copy">{{ selectedAsset.description || 'No description yet.' }}</div>
        </div>

        <div class="drawer-section">
          <div class="drawer-section-label">Tags</div>
          <div class="asset-tags">
            <span v-for="tag in selectedAsset.tags" :key="tag" class="asset-tag tag-accent">{{ tag }}</span>
            <span v-if="selectedAsset.tags.length === 0" class="detail-copy">No tags</span>
          </div>
        </div>

        <div class="drawer-section">
          <div class="drawer-section-label">Usage</div>
          <div class="detail-copy">
            Used {{ selectedAsset.usage_count }} time<span v-if="selectedAsset.usage_count !== 1">s</span> in this workspace.
          </div>
        </div>

        <div class="detail-actions">
          <a v-if="selectedAsset.storage_url" class="btn btn-ghost" :href="selectedAsset.storage_url" target="_blank" rel="noreferrer">Open Asset</a>
          <button class="btn btn-ghost btn-danger" type="button" @click="requestArchiveAsset(selectedAsset)">Archive</button>
        </div>
      </div>
    </aside>

    <ConfirmDialog
      :open="Boolean(archiveTarget)"
      title="Archive Asset?"
      :message="archiveMessage"
      confirm-label="Archive Asset"
      :pending="archivePending"
      destructive
      @close="closeArchiveConfirm"
      @confirm="archiveAsset"
    />
  </div>
</template>

<style scoped>
.fc-shell {
  min-height: 100vh;
  background: radial-gradient(circle at top right, rgba(255,107,53,0.09), transparent 28%),
              radial-gradient(circle at bottom left, rgba(96,165,250,0.08), transparent 24%),
              var(--color-bg-deep, #0a0a0f);
  color: var(--color-text-primary, #ececf3);
  font-family: "DM Sans", sans-serif;
}

/* Main content */
.main { margin-left: 72px; min-height: 100vh; padding: 24px; }

/* Overview */
.asset-overview {
  display: grid;
  grid-template-columns: 260px 1fr;
  gap: 20px;
  margin-bottom: 22px;
}
.surface-card {
  background: linear-gradient(180deg, rgba(255,255,255,0.015), transparent), #17171f;
  border: 1px solid #2a2a36;
  border-radius: 12px;
  box-shadow: 0 18px 40px rgba(0,0,0,0.35);
}
.asset-summary-card,
.asset-activity-card { padding: 18px; }
.summary-kicker {
  font-size: 11px;
  color: #6a6a7c;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.summary-big { margin-top: 10px; font-size: 28px; font-weight: 700; }
.summary-copy { margin-top: 6px; color: #a1a1b5; font-size: 13px; line-height: 1.55; }
.section-title { font-size: 16px; font-weight: 600; }
.section-subtitle { margin-top: 3px; font-size: 13px; color: #6a6a7c; }
.mini-metrics {
  display: grid;
  gap: 10px;
  margin-top: 14px;
}
.mini-metrics-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.mini-metric {
  padding: 12px;
  border-radius: 10px;
  border: 1px solid #2a2a36;
  background: rgba(255,255,255,0.02);
}
.mini-metric strong { display: block; font-size: 15px; }
.mini-metric span { display: block; margin-top: 4px; font-size: 11px; color: #6a6a7c; }

/* Section header */
.section-header {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}
.section-header-actions {
  display: flex;
  align-items: center;
  gap: 12px;
}
.per-page-inline {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: #a1a1b5;
  font-size: 12px;
  white-space: nowrap;
}
.field-select-compact {
  min-width: 82px;
  padding-right: 28px;
}

/* Upload panel */
.upload-panel {
  margin-bottom: 20px;
  padding: 18px;
}
.upload-panel-title { font-size: 14px; font-weight: 600; margin-bottom: 14px; }
.upload-form-row {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
  align-items: end;
}
.upload-form-actions { margin-top: 14px; display: flex; gap: 10px; justify-content: flex-end; }
.field-label { display: grid; gap: 5px; font-size: 12px; color: #a1a1b5; }
.field-input, .field-select {
  width: 100%;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  background: #1d1d28;
  color: #ececf3;
  padding: 9px 11px;
  font-size: 13px;
}
.upload-zone {
  min-height: 80px;
  border: 1px dashed #2a2a36;
  border-radius: 10px;
  display: grid;
  place-items: center;
  color: #6a6a7c;
  cursor: pointer;
  font-size: 12px;
  text-align: center;
  padding: 12px;
}
.upload-zone:hover { border-color: #494960; color: #a1a1b5; }

/* Toolbar */
.asset-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.search-wrap {
  position: relative;
  flex: 1;
  min-width: 220px;
}
.search-wrap svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #6a6a7c;
}
.search-box {
  width: 100%;
  padding: 9px 14px 9px 36px;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  background: #1d1d28;
  color: #ececf3;
  font-size: 13px;
}
.search-box:focus { outline: none; border-color: #494960; }
.filter-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.filter-chip {
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 12px;
  border: 1px solid #2a2a36;
  color: #6a6a7c;
  cursor: pointer;
  background: transparent;
  transition: 0.15s;
}
.filter-chip:hover { color: #a1a1b5; border-color: #494960; }
.filter-chip.active { border-color: rgba(255,107,53,0.4); color: #ff6b35; background: rgba(255,107,53,0.14); }

/* Asset grid */
.asset-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 14px;
}
.asset-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 18px;
}
.asset-pagination-copy {
  color: #6a6a7c;
  font-size: 12px;
}
.asset-pagination-actions {
  display: flex;
  gap: 8px;
}
.asset-card {
  background: #17171f;
  border: 1px solid #2a2a36;
  border-radius: 12px;
  overflow: hidden;
  transition: 0.22s ease;
  cursor: pointer;
  text-align: left;
  color: inherit;
}
.asset-card:hover { transform: translateY(-2px); border-color: #494960; }
.asset-preview {
  position: relative;
  height: 118px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.asset-type-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 3px 7px;
  border-radius: 4px;
  font-size: 9px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.badge-video { background: rgba(96,165,250,0.12); color: #60a5fa; }
.badge-audio { background: rgba(167,139,250,0.12); color: #a78bfa; }
.badge-voice { background: rgba(248,113,113,0.12); color: #f87171; }
.badge-image { background: rgba(52,211,153,0.12); color: #34d399; }
.badge-template { background: rgba(251,191,36,0.12); color: #fbbf24; }
.asset-icon { font-size: 28px; opacity: 0.6; }
.asset-details { padding: 12px; }
.asset-title { font-size: 12px; font-weight: 600; margin-bottom: 4px; }
.asset-meta-row {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font-size: 11px;
  color: #6a6a7c;
}
.asset-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-top: 8px;
}
.asset-tag {
  padding: 2px 6px;
  border-radius: 4px;
  background: #1d1d28;
  color: #6a6a7c;
  font-size: 10px;
}
.asset-tag.tag-accent {
  background: rgba(255,107,53,0.1);
  color: #ff9b72;
}
.asset-card-add {
  border-style: dashed;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 170px;
}
.add-card-inner { text-align: center; color: #6a6a7c; }
.add-card-plus { font-size: 24px; margin-bottom: 4px; }
.add-card-label { font-size: 12px; }

/* Buttons */
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
  transition: 0.15s;
}
.btn:hover { background: rgba(255,255,255,0.04); }
.btn-primary { background: #ff6b35; border-color: #ff6b35; color: white; }
.btn-primary:hover { background: #ff875a; }
.btn-sm { padding: 6px 12px; font-size: 12px; }
.btn-ghost { background: transparent; }
.btn-danger { color: #f87171; }

/* States */
.page-state { padding: 48px 12px; color: #6a6a7c; }
.empty-state { padding: 48px 12px; color: #6a6a7c; }
.empty-title { color: #ececf3; font-weight: 600; font-size: 16px; margin-bottom: 6px; }
.empty-copy { max-width: 420px; font-size: 13px; }
.banner { margin-top: 12px; border-radius: 8px; padding: 10px 12px; font-size: 13px; }
.banner.error { background: rgba(248,113,113,0.1); color: #fca5a5; border: 1px solid rgba(248,113,113,0.2); }

/* Drawer */
.drawer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 40; }
.drawer {
  position: fixed;
  top: 0;
  right: 0;
  width: min(400px, calc(100vw - 16px));
  height: 100vh;
  background: #111118;
  border-left: 1px solid #2a2a36;
  transform: translateX(100%);
  transition: transform 0.25s ease;
  z-index: 50;
}
.drawer.open { transform: translateX(0); }
.drawer-inner { padding: 22px; overflow-y: auto; height: 100%; }
.drawer-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
.drawer-title { font-size: 15px; font-weight: 600; }
.drawer-subtitle { margin-top: 3px; font-size: 12px; color: #6a6a7c; }
.close-btn {
  width: 30px;
  height: 30px;
  border-radius: 999px;
  border: 1px solid #2a2a36;
  background: transparent;
  color: #ececf3;
  cursor: pointer;
  display: grid;
  place-items: center;
  font-size: 16px;
  flex-shrink: 0;
}
.drawer-section { margin-bottom: 20px; }
.drawer-section-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #6a6a7c;
  margin-bottom: 8px;
  font-weight: 500;
}
.detail-copy { color: #a1a1b5; font-size: 13px; line-height: 1.5; }
.detail-actions { margin-top: 24px; display: flex; flex-wrap: wrap; gap: 10px; }

@media (max-width: 900px) {
  .asset-overview { grid-template-columns: 1fr; }
  .mini-metrics { grid-template-columns: repeat(2, 1fr); }
  .section-header,
  .asset-pagination {
    flex-direction: column;
    align-items: stretch;
  }
  .section-header-actions,
  .asset-pagination-actions {
    justify-content: space-between;
  }
}
</style>
