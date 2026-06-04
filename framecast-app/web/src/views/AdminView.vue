<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import NotifBell from '../components/NotifBell.vue'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const authStore = useAuthStore()

// ── Confirm / Alert modal ─────────────────────────────────────────────────────
const modal = ref({ open: false, type: 'confirm', title: '', message: '', confirmLabel: 'Confirm', danger: false, resolve: null })

function showConfirm(message, { title = 'Are you sure?', confirmLabel = 'Confirm', danger = false } = {}) {
  return new Promise((resolve) => {
    modal.value = { open: true, type: 'confirm', title, message, confirmLabel, danger, resolve }
  })
}

function showAlert(message, { title = 'Error' } = {}) {
  return new Promise((resolve) => {
    modal.value = { open: true, type: 'alert', title, message, confirmLabel: 'OK', danger: false, resolve }
  })
}

function modalConfirm() { const r = modal.value.resolve; modal.value.open = false; r?.(true) }
function modalCancel()  { const r = modal.value.resolve; modal.value.open = false; r?.(false) }

// ── Navigation ────────────────────────────────────────────────────────────────
const activeView = ref('dashboard')
const topbarTitles = {
  dashboard: 'Platform Overview', users: 'Users',
  workspaces: 'Workspaces', videos: 'All Videos',
  jobs: 'Queue & Jobs', billing: 'Billing & Spend', audit: 'Audit Log',
  failures: 'Job Failures', storage: 'Storage',
  moderation: 'Trust & Safety',
}

// ── Trust & Safety (moderation events) ────────────────────────────────────────
const modLoading = ref(false)
const modEvents = ref([])
const modCounters = ref({ total_24h: 0, unreviewed: 0, high_severity: 0 })
const modUnreviewedCount = ref(0)
const modPage = ref(1)
const modPerPage = ref(50)
const modTotal = ref(0)
const modLastPage = ref(1)
const modFilterSource = ref('')
const modFilterSeverity = ref('')
const modFilterUnreviewed = ref(true)
const modSelectedEvent = ref(null)
const modActionDraft = ref({ action_taken: 'no_action', action_notes: '' })

async function loadModeration() {
  modLoading.value = true
  try {
    const params = {
      page: modPage.value,
      per_page: modPerPage.value,
    }
    if (modFilterSource.value) params.source = modFilterSource.value
    if (modFilterSeverity.value) params.severity = modFilterSeverity.value
    if (modFilterUnreviewed.value) params.unreviewed = '1'
    const res = await api.get('/admin/moderation/events', { params })
    modEvents.value = res.data?.data?.events ?? []
    modCounters.value = res.data?.data?.counters ?? { total_24h: 0, unreviewed: 0, high_severity: 0 }
    modUnreviewedCount.value = modCounters.value.unreviewed
    const p = res.data?.meta?.pagination ?? {}
    modTotal.value = p.total ?? 0
    modLastPage.value = p.last_page ?? 1
  } catch (e) {
    modEvents.value = []
  } finally {
    modLoading.value = false
  }
}

async function openModEvent(id) {
  try {
    const res = await api.get(`/admin/moderation/events/${id}`)
    modSelectedEvent.value = res.data?.data ?? null
    modActionDraft.value = {
      action_taken: modSelectedEvent.value?.event?.action_taken || 'no_action',
      action_notes: modSelectedEvent.value?.event?.action_notes || '',
    }
  } catch {}
}

async function submitModReview() {
  if (!modSelectedEvent.value?.event?.id) return
  try {
    const res = await api.patch(`/admin/moderation/events/${modSelectedEvent.value.event.id}`, modActionDraft.value)
    modSelectedEvent.value = { ...modSelectedEvent.value, event: res.data?.data?.event }
    await loadModeration()
  } catch {}
}

function modFilter() { modPage.value = 1; loadModeration() }
function modSeverityClass(sev) {
  return {
    info: 'sev-info', low: 'sev-low', medium: 'sev-med', high: 'sev-high', critical: 'sev-crit'
  }[sev] || 'sev-low'
}
function modSourceLabel(src) {
  return ({
    generation_rejection: 'Provider rejection',
    user_report:          'User report',
    pattern_alert:        'Pattern alert',
    admin_action:         'Admin action',
  })[src] || src
}

// Refresh unreviewed counter every 60s so the sidebar badge stays current
// without forcing the admin to navigate into the tab.
let modBadgePoll = null
async function refreshModBadge() {
  try {
    const res = await api.get('/admin/moderation/events', { params: { unreviewed: '1', per_page: 1 } })
    modUnreviewedCount.value = res.data?.data?.counters?.unreviewed ?? 0
  } catch {}
}

// ── Dashboard ─────────────────────────────────────────────────────────────────
const dashLoading = ref(false)
const dashData = ref(null)
const spendRange = ref(14)
const spendChart = ref([])
const spendChartLoading = ref(false)

async function loadDashboard() {
  dashLoading.value = true
  try {
    const res = await api.get('/admin/overview')
    dashData.value = res.data.data
  } finally {
    dashLoading.value = false
  }
}

async function loadSpendChart() {
  spendChartLoading.value = true
  try {
    const res = await api.get('/admin/spend-chart', { params: { days: spendRange.value } })
    // API returns { data: { chart: [{day, spend}] } }
    spendChart.value = res.data.data?.chart ?? []
  } finally {
    spendChartLoading.value = false
  }
}

watch(spendRange, loadSpendChart)

const spendChartMax = computed(() => {
  const vals = spendChart.value.map(d => Number(d.spend || 0))
  return Math.max(...vals, 0.01)
})

const PER_PAGE_OPTIONS = [10, 20, 50, 100]

// ── Users ─────────────────────────────────────────────────────────────────────
const usersLoading = ref(false)
const usersData = ref([])
const usersPagination = ref({})
const usersSearch = ref('')
const usersStatus = ref('')
const usersPlan = ref('')
const usersPage = ref(1)
const usersPerPage = ref(20)

async function loadUsers() {
  usersLoading.value = true
  try {
    const res = await api.get('/admin/users', {
      params: { search: usersSearch.value || undefined, status: usersStatus.value || undefined, plan: usersPlan.value || undefined, page: usersPage.value, per_page: usersPerPage.value },
    })
    usersData.value = res.data.data?.users ?? []
    usersPagination.value = res.data.meta?.pagination ?? {}
  } finally {
    usersLoading.value = false
  }
}

function usersFilter() { usersPage.value = 1; loadUsers() }
function usersPerPageChange() { usersPage.value = 1; loadUsers() }

// ── User Detail Panel ─────────────────────────────────────────────────────────
const panelOpen = ref(false)
const panelLoading = ref(false)
const panelData = ref(null)
const panelTab = ref('overview')
const impersonating = ref(false)
const suspending = ref(false)

async function openPanel(userId) {
  panelOpen.value = true
  panelTab.value = 'overview'
  panelLoading.value = true
  panelData.value = null
  try {
    const res = await api.get(`/admin/users/${userId}`)
    // API returns { data: { user, workspace, spend_month_usd, spend_total_usd, spend_by_day, provider_breakdown, recent_projects, recent_exports } }
    panelData.value = res.data.data
  } finally {
    panelLoading.value = false
  }
}

function closePanel() { panelOpen.value = false }

async function impersonate(userId) {
  const ok = await showConfirm('Start an impersonation session as this user? A new tab will open logged in as them.', { title: 'Impersonate user', confirmLabel: 'Impersonate', danger: true })
  if (!ok) return
  impersonating.value = true
  try {
    const res = await api.post(`/admin/users/${userId}/impersonate`)
    const token = res.data.data?.token
    if (token) {
      window.open(`/?impersonate=${token}`, '_blank')
    }
  } catch (e) {
    await showAlert(e?.response?.data?.error?.message || 'Impersonation failed.')
  } finally {
    impersonating.value = false
  }
}

async function suspendWorkspace(workspaceId, currentStatus) {
  const newStatus = currentStatus === 'active' ? 'suspended' : 'active'
  const label = newStatus === 'suspended' ? 'suspend' : 'unsuspend'
  const ok = await showConfirm(`This will ${label} the workspace and affect all users in it.`, { title: `${label.charAt(0).toUpperCase() + label.slice(1)} workspace?`, confirmLabel: label.charAt(0).toUpperCase() + label.slice(1), danger: newStatus === 'suspended' })
  if (!ok) return
  suspending.value = true
  try {
    await api.patch(`/admin/workspaces/${workspaceId}/status`, { status: newStatus })
    await loadUsers()
    if (panelData.value) {
      const res = await api.get(`/admin/users/${panelData.value.user.id}`)
      panelData.value = res.data.data
    }
  } catch (e) {
    await showAlert(e?.response?.data?.error?.message || 'Could not update workspace status.')
  } finally {
    suspending.value = false
  }
}

// Panel spend chart max
const panelSpendMax = computed(() => {
  const vals = (panelData.value?.spend_by_day ?? []).map(d => Number(d.spend || 0))
  return Math.max(...vals, 0.01)
})

// ── Workspaces ────────────────────────────────────────────────────────────────
const wsLoading = ref(false)
const wsData = ref([])
const wsPagination = ref({})
const wsSearch = ref('')
const wsStatus = ref('')
const wsPlan = ref('')
const wsPage = ref(1)
const wsPerPage = ref(20)
const wsSaving = ref(null)

async function loadWorkspaces() {
  wsLoading.value = true
  try {
    const res = await api.get('/admin/workspaces', {
      params: { search: wsSearch.value || undefined, status: wsStatus.value || undefined, plan: wsPlan.value || undefined, page: wsPage.value, per_page: wsPerPage.value },
    })
    wsData.value = res.data.data?.workspaces ?? []
    wsPagination.value = res.data.meta?.pagination ?? {}
  } finally {
    wsLoading.value = false
  }
}

function wsFilter() { wsPage.value = 1; loadWorkspaces() }
function wsPerPageChange() { wsPage.value = 1; loadWorkspaces() }

async function updateWsPlan(ws, newPlan) {
  wsSaving.value = ws.id
  try {
    await api.patch(`/admin/workspaces/${ws.id}/plan`, { plan_tier: newPlan })
    await loadWorkspaces()
  } catch (e) {
    await showAlert(e?.response?.data?.error?.message || 'Could not update plan.')
  } finally {
    wsSaving.value = null
  }
}

async function toggleWsStatus(ws) {
  const newStatus = ws.status === 'active' ? 'suspended' : 'active'
  const label = newStatus === 'suspended' ? 'suspend' : 'unsuspend'
  const ok = await showConfirm(`This will ${label} "${ws.name}" and affect all its users.`, { title: `${label.charAt(0).toUpperCase() + label.slice(1)} workspace?`, confirmLabel: label.charAt(0).toUpperCase() + label.slice(1), danger: newStatus === 'suspended' })
  if (!ok) return
  wsSaving.value = ws.id
  try {
    await api.patch(`/admin/workspaces/${ws.id}/status`, { status: newStatus })
    await loadWorkspaces()
  } catch (e) {
    await showAlert(e?.response?.data?.error?.message || 'Could not update status.')
  } finally {
    wsSaving.value = null
  }
}

// ── Jobs ──────────────────────────────────────────────────────────────────────
const jobsLoading = ref(false)
const jobsData = ref(null)
const jobsPage = ref(1)
const jobsPerPage = ref(20)
const jobsPagination = ref({})

async function loadJobs() {
  jobsLoading.value = true
  try {
    const res = await api.get('/admin/jobs', { params: { page: jobsPage.value, per_page: jobsPerPage.value } })
    jobsData.value = res.data.data
    jobsPagination.value = res.data.meta?.pagination ?? {}
  } finally {
    jobsLoading.value = false
  }
}
function jobsPerPageChange() { jobsPage.value = 1; loadJobs() }

// ── Failure Traces ────────────────────────────────────────────────────────────
const failuresLoading = ref(false)
const failuresData = ref([])
const failuresPagination = ref({})
const failuresPage = ref(1)
const failuresPerPage = ref(50)
const failuresEntityType = ref('')
const failuresExpandedId = ref(null)

async function loadFailures() {
  failuresLoading.value = true
  try {
    const params = { page: failuresPage.value, per_page: failuresPerPage.value }
    if (failuresEntityType.value) params.entity_type = failuresEntityType.value
    const res = await api.get('/admin/failure-traces', { params })
    failuresData.value = res.data.data?.traces ?? []
    failuresPagination.value = res.data.meta?.pagination ?? {}
  } finally {
    failuresLoading.value = false
  }
}
function failuresFilter() { failuresPage.value = 1; loadFailures() }
function toggleTrace(id) { failuresExpandedId.value = failuresExpandedId.value === id ? null : id }

// ── Audit Log ─────────────────────────────────────────────────────────────────
const auditLoading = ref(false)
const auditData = ref([])
const auditPagination = ref({})
const auditPage = ref(1)
const auditPerPage = ref(20)

async function loadAudit() {
  auditLoading.value = true
  try {
    const res = await api.get('/admin/audit-log', { params: { page: auditPage.value, per_page: auditPerPage.value } })
    auditData.value = res.data.data?.logs ?? []
    auditPagination.value = res.data.meta?.pagination ?? {}
  } finally {
    auditLoading.value = false
  }
}
function auditPerPageChange() { auditPage.value = 1; loadAudit() }

// ── Videos ───────────────────────────────────────────────────────────────────
const videosLoading = ref(false)
const videosData = ref([])
const videosPagination = ref({})
const videosStatusCounts = ref({})
const videosSearch = ref('')
const videosStatus = ref('')
const videosPage = ref(1)
const videosPerPage = ref(20)

async function loadVideos() {
  videosLoading.value = true
  try {
    const res = await api.get('/admin/videos', {
      params: {
        search: videosSearch.value || undefined,
        status: videosStatus.value || undefined,
        page: videosPage.value,
        per_page: videosPerPage.value,
      },
    })
    videosData.value = res.data.data?.videos ?? []
    videosPagination.value = res.data.meta?.pagination ?? {}
    videosStatusCounts.value = res.data.data?.status_counts ?? {}
  } finally {
    videosLoading.value = false
  }
}

function videosFilter() { videosPage.value = 1; loadVideos() }
function videosPerPageChange() { videosPage.value = 1; loadVideos() }

const STATUS_LABELS = {
  draft: 'Draft', generating: 'Generating',
  ready_for_review: 'Ready', published: 'Published', failed: 'Failed',
}
const STATUS_CHIPS = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'generating', label: 'Generating' },
  { value: 'ready_for_review', label: 'Ready' },
  { value: 'published', label: 'Published' },
  { value: 'failed', label: 'Failed' },
]

// ── Storage ───────────────────────────────────────────────────────────────────
const storageData = ref(null)
const storageLoading = ref(false)
const storagePage = ref(1)
const storagePerPage = ref(20)
const storageSearch = ref('')
const storagePlan = ref('')
const storagePagination = ref({})

async function loadStorage() {
  storageLoading.value = true
  try {
    const res = await api.get('/admin/storage', {
      params: {
        search: storageSearch.value || undefined,
        plan: storagePlan.value || undefined,
        page: storagePage.value,
        per_page: storagePerPage.value,
      },
    })
    storageData.value = res.data.data
    storagePagination.value = res.data.meta?.pagination ?? {}
  } finally {
    storageLoading.value = false
  }
}

function storageFilter() { storagePage.value = 1; loadStorage() }
function storagePerPageChange() { storagePage.value = 1; loadStorage() }

function storageBarWidth(bytes) {
  const max = storageData.value?.by_workspace?.[0]?.total_bytes ?? 1
  return max === 0 ? 0 : Math.round((bytes / max) * 100)
}

// ── Billing (reuses spend chart + audit log data) ─────────────────────────────
const billingRange = ref(30)
const billingChart = ref([])
const billingChartLoading = ref(false)

async function loadBillingChart() {
  billingChartLoading.value = true
  try {
    const res = await api.get('/admin/spend-chart', { params: { days: billingRange.value } })
    billingChart.value = res.data.data?.chart ?? []
  } finally {
    billingChartLoading.value = false
  }
}

watch(billingRange, loadBillingChart)

const billingChartMax = computed(() => {
  const vals = billingChart.value.map(d => Number(d.spend || 0))
  return Math.max(...vals, 0.01)
})

const billingTotal = computed(() =>
  billingChart.value.reduce((sum, d) => sum + Number(d.spend || 0), 0)
)

// ── Suspend from users table ──────────────────────────────────────────────────
const suspendingUsersWs = ref(null)

async function suspendUserWorkspace(user) {
  const newStatus = user.workspace_status === 'active' ? 'suspended' : 'active'
  const label = newStatus === 'suspended' ? 'suspend' : 'unsuspend'
  const ok = await showConfirm(`This will ${label} the workspace for ${user.name || user.email}.`, { title: `${label.charAt(0).toUpperCase() + label.slice(1)} workspace?`, confirmLabel: label.charAt(0).toUpperCase() + label.slice(1), danger: newStatus === 'suspended' })
  if (!ok) return
  suspendingUsersWs.value = user.workspace_id
  try {
    await api.patch(`/admin/workspaces/${user.workspace_id}/status`, { status: newStatus })
    await loadUsers()
  } catch (e) {
    await showAlert(e?.response?.data?.error?.message || 'Could not update status.')
  } finally {
    suspendingUsersWs.value = null
  }
}

// ── Plans & Credits ──────────────────────────────────────────────────────────
const creditCosts = { SCRIPT: 2, BREAKDOWN: 1, STOCK: 1, TTS: 2, AI_MEDIUM: 15, AI_HIGH: 40, EXPORT: 5 }

const plansData = [
  { key: 'free',       name: 'Free',       credits_monthly: 0,     channel_limit: 1,   render_limit: 10,    watermark: true,  ai_image_quality: ['medium'] },
  { key: 'starter',    name: 'Starter',    credits_monthly: 500,   channel_limit: 1,   render_limit: 50,    watermark: false, ai_image_quality: ['medium'] },
  { key: 'creator',    name: 'Creator',    credits_monthly: 1500,  channel_limit: 3,   render_limit: 200,   watermark: false, ai_image_quality: ['medium', 'high'] },
  { key: 'pro',        name: 'Pro',        credits_monthly: 4000,  channel_limit: 10,  render_limit: 1000,  watermark: false, ai_image_quality: ['medium', 'high'] },
  { key: 'agency',     name: 'Agency',     credits_monthly: 10000, channel_limit: 999, render_limit: 10000, watermark: false, ai_image_quality: ['medium', 'high'] },
  { key: 'enterprise', name: 'Enterprise', credits_monthly: 50000, channel_limit: 9999,render_limit: 99999, watermark: false, ai_image_quality: ['medium', 'high'] },
]

const projectExamples = [
  { label: 'Stock video (10 scenes + export)',        scenes: 10, credits: 2+1+10*1+10*2+5 },
  { label: 'Stock images (10 scenes + export)',       scenes: 10, credits: 2+1+10*1+10*2+5 },
  { label: 'Audiogram (10 scenes + export)',          scenes: 10, credits: 2+1+10*1+10*2+5 },
  { label: 'AI images medium (10 scenes + export)',   scenes: 10, credits: 2+1+10*15+10*2+5 },
  { label: 'AI images high (10 scenes + export)',     scenes: 10, credits: 2+1+10*40+10*2+5 },
]

// ── SFX Library admin ────────────────────────────────────────────────────────
const sfxList = ref([])
const sfxListLoading = ref(false)
const sfxCategories = ref([
  { key: 'transition',   label: 'Transitions' },
  { key: 'ui',           label: 'UI / Clicks' },
  { key: 'notification', label: 'Notifications' },
  { key: 'impact',       label: 'Impacts' },
  { key: 'ambient',      label: 'Ambient' },
  { key: 'fx',           label: 'FX' },
  { key: 'music',        label: 'Music / Stingers' },
])
const sfxFilters = ref({ q: '', category: '', status: 'all' })
const sfxUploadForm = ref({ name: '', category: null, source: 'pixabay' })
const sfxQueuedFiles = ref([])
const sfxUploading = ref(false)
const sfxUploadIndex = ref(0)
const sfxUploadError = ref('')
const sfxFileInput = ref(null)
const sfxPlayingId = ref(null)
let sfxPreviewAudio = null
let sfxSearchTimer = null

async function loadSfxList() {
  sfxListLoading.value = true
  try {
    const params = {}
    if (sfxFilters.value.q) params.q = sfxFilters.value.q
    if (sfxFilters.value.category) params.category = sfxFilters.value.category
    if (sfxFilters.value.status) params.status = sfxFilters.value.status
    const res = await api.get('/admin/sfx', { params })
    sfxList.value = res.data?.data?.sounds ?? []
  } catch {
    sfxList.value = []
  } finally {
    sfxListLoading.value = false
  }
}

function debouncedSfxSearch() {
  clearTimeout(sfxSearchTimer)
  sfxSearchTimer = setTimeout(loadSfxList, 350)
}

function onSfxFiles(e) {
  sfxQueuedFiles.value = Array.from(e.target.files || [])
  sfxUploadError.value = ''
}

async function uploadSfx() {
  if (!sfxQueuedFiles.value.length || sfxUploading.value) return
  sfxUploading.value = true
  sfxUploadError.value = ''
  sfxUploadIndex.value = 0
  try {
    for (let i = 0; i < sfxQueuedFiles.value.length; i++) {
      sfxUploadIndex.value = i
      const file = sfxQueuedFiles.value[i]
      const fd = new FormData()
      const name = sfxQueuedFiles.value.length === 1 && sfxUploadForm.value.name
        ? sfxUploadForm.value.name
        : file.name.replace(/\.[^.]+$/, '').slice(0, 60)
      fd.append('name', name)
      if (sfxUploadForm.value.category) fd.append('category', sfxUploadForm.value.category)
      if (sfxUploadForm.value.source) fd.append('source', sfxUploadForm.value.source)
      fd.append('file', file)
      await api.post('/admin/sfx', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    }
    sfxQueuedFiles.value = []
    if (sfxFileInput.value) sfxFileInput.value.value = ''
    sfxUploadForm.value.name = ''
    await loadSfxList()
  } catch (e) {
    sfxUploadError.value = e?.response?.data?.error?.message || 'Upload failed.'
  } finally {
    sfxUploading.value = false
    sfxUploadIndex.value = 0
  }
}

async function saveSfxEdit(sound) {
  try {
    await api.patch(`/admin/sfx/${sound.id}`, {
      name: sound.name,
      category: sound.category,
      status: sound.status,
    })
  } catch { /* ignore */ }
}

async function deleteSfx(sound) {
  if (!confirm(`Delete "${sound.name}"? This cannot be undone.`)) return
  try {
    await api.delete(`/admin/sfx/${sound.id}`)
    sfxList.value = sfxList.value.filter(s => s.id !== sound.id)
  } catch (e) { /* ignore */ }
}

function toggleSfxPreview(sound) {
  if (sfxPreviewAudio) {
    sfxPreviewAudio.pause()
    sfxPreviewAudio = null
  }
  if (sfxPlayingId.value === sound.id) { sfxPlayingId.value = null; return }
  sfxPreviewAudio = new Audio(sound.preview_url)
  sfxPreviewAudio.addEventListener('ended', () => { if (sfxPlayingId.value === sound.id) sfxPlayingId.value = null })
  sfxPreviewAudio.play().catch(() => { sfxPlayingId.value = null })
  sfxPlayingId.value = sound.id
}

// ── Navigation ────────────────────────────────────────────────────────────────
function navigate(view) {
  activeView.value = view
  if (view === 'sfx') loadSfxList()
  if (view === 'dashboard' && !dashData.value) { loadDashboard(); loadSpendChart() }
  if (view === 'users') loadUsers()
  if (view === 'workspaces') loadWorkspaces()
  if (view === 'videos') loadVideos()
  if (view === 'jobs') loadJobs()
  if (view === 'audit') loadAudit()
  if (view === 'failures') loadFailures()
  if (view === 'billing') { loadBillingChart(); loadAudit() }
  if (view === 'storage') loadStorage()
  if (view === 'moderation') loadModeration()
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function money(v) { return `$${Number(v || 0).toFixed(2)}` }
function fmtDate(v) {
  if (!v) return '-'
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(v))
}
function initials(name, email) {
  const src = name || email || '?'
  return src.split(/\s+/).map(w => w[0]).join('').toUpperCase().slice(0, 2)
}
function planBadgeClass(plan) {
  return { free: 'badge-gray', starter: 'badge-gray', studio: 'badge-purple', scale: 'badge-blue', enterprise: 'badge-yellow' }[plan] ?? 'badge-gray'
}
function videoStatusBadge(status) {
  return { draft: 'badge-gray', generating: 'badge-blue', ready_for_review: 'badge-green', published: 'badge-purple', failed: 'badge-red' }[status] ?? 'badge-gray'
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

onMounted(() => {
  loadDashboard()
  loadSpendChart()
  // Poll the Trust & Safety badge so the sidebar count stays current
  // without making the admin navigate into the tab to refresh.
  refreshModBadge()
  modBadgePoll = setInterval(refreshModBadge, 60_000)
})
</script>

<template>
  <div class="gm-shell">

    <!-- Sidebar -->
    <aside class="gm-sidebar">
      <div class="gm-logo">
        <span class="gm-logo-text">WyvStudio</span>
        <span class="god-badge">GOD MODE</span>
      </div>
      <nav class="gm-nav">
        <div class="nav-section-label">Overview</div>
        <button :class="['nav-item', activeView === 'dashboard' ? 'active' : '']" @click="navigate('dashboard')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
          Dashboard
        </button>
        <div class="nav-section-label">People</div>
        <button :class="['nav-item', activeView === 'users' ? 'active' : '']" @click="navigate('users')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
          Users
        </button>
        <button :class="['nav-item', activeView === 'workspaces' ? 'active' : '']" @click="navigate('workspaces')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
          Workspaces
        </button>
        <div class="nav-section-label">Content</div>
        <button :class="['nav-item', activeView === 'videos' ? 'active' : '']" @click="navigate('videos')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.069A1 1 0 0121 8.867v6.266a1 1 0 01-1.447.902L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
          Videos
        </button>
        <div class="nav-section-label">Monetisation</div>
        <button :class="['nav-item', activeView === 'plans' ? 'active' : '']" @click="navigate('plans')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
          Plans &amp; Credits
        </button>
        <button :class="['nav-item', activeView === 'sfx' ? 'active' : '']" @click="navigate('sfx')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
          SFX Library
        </button>
        <div class="nav-section-label">System</div>
        <button :class="['nav-item', activeView === 'jobs' ? 'active' : '']" @click="navigate('jobs')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
          Jobs
          <span v-if="(jobsData?.counts?.failed_today ?? 0) > 0" class="nav-badge">{{ jobsData.counts.failed_today }}</span>
        </button>
        <button :class="['nav-item', activeView === 'billing' ? 'active' : '']" @click="navigate('billing')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          Billing
        </button>
        <button :class="['nav-item', activeView === 'audit' ? 'active' : '']" @click="navigate('audit')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
          Audit Log
        </button>
        <button :class="['nav-item', activeView === 'failures' ? 'active' : '']" @click="navigate('failures')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          Job Failures
        </button>
        <button :class="['nav-item', activeView === 'storage' ? 'active' : '']" @click="navigate('storage')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
          Storage
        </button>
        <button :class="['nav-item', activeView === 'moderation' ? 'active' : '']" @click="navigate('moderation')">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          Trust &amp; Safety
          <span v-if="modUnreviewedCount > 0" class="nav-badge">{{ modUnreviewedCount }}</span>
        </button>
      </nav>
      <div class="gm-sidebar-foot">
        <div class="gm-user-row">
          <div class="gm-avatar" style="background:#7c3aed33;color:#7c3aed">{{ initials(authStore.user?.name, authStore.user?.email) }}</div>
          <div>
            <div class="gm-user-name">{{ authStore.user?.name || authStore.user?.email }}</div>
            <div class="gm-user-role">Super Admin</div>
          </div>
          <button class="gm-logout" title="Logout" @click="logout">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          </button>
        </div>
      </div>
    </aside>

    <!-- Main -->
    <main class="gm-main">
      <div class="gm-topbar">
        <button class="btn-back" @click="router.push({ name: 'dashboard' })">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
          Dashboard
        </button>
        <span class="gm-topbar-title">{{ topbarTitles[activeView] }}</span>
        <div class="gm-topbar-right">
          <span class="topbar-meta">{{ authStore.user?.email }}</span>
          <span class="badge badge-purple">Super Admin</span>
                  <NotifBell />
        </div>
      </div>

      <div class="gm-content">

        <!-- ═══ DASHBOARD ═══ -->
        <template v-if="activeView === 'dashboard'">
          <div v-if="dashLoading" class="gm-spinner-wrap">Loading overview...</div>
          <template v-else-if="dashData">
            <!-- API: summary.total_users, total_workspaces, total_projects, exports_today/month, api_spend_* -->
            <div class="metrics-grid">
              <div class="metric-card">
                <div class="metric-label">Total Users</div>
                <div class="metric-value">{{ dashData.summary?.total_users ?? 0 }}</div>
                <div class="metric-sub">{{ dashData.summary?.active_users ?? 0 }} active</div>
              </div>
              <div class="metric-card blue">
                <div class="metric-label">Workspaces</div>
                <div class="metric-value">{{ dashData.summary?.total_workspaces ?? 0 }}</div>
                <div class="metric-sub">{{ dashData.summary?.total_projects ?? 0 }} total projects</div>
              </div>
              <div class="metric-card green">
                <div class="metric-label">Exports Today</div>
                <div class="metric-value">{{ dashData.summary?.exports_today ?? 0 }}</div>
                <div class="metric-sub">{{ dashData.summary?.exports_month ?? 0 }} this month</div>
              </div>
              <div class="metric-card purple">
                <div class="metric-label">AI Spend (Month)</div>
                <div class="metric-value">{{ money(dashData.summary?.api_spend_month_usd) }}</div>
                <div class="metric-sub">{{ money(dashData.summary?.api_spend_today_usd) }} today</div>
              </div>
              <div class="metric-card yellow">
                <div class="metric-label">Total Tracked</div>
                <div class="metric-value">{{ money(dashData.summary?.api_spend_total_usd) }}</div>
                <div class="metric-sub">All-time AI spend</div>
              </div>
              <div class="metric-card red">
                <div class="metric-label">Failed Jobs</div>
                <div class="metric-value">{{ dashData.summary?.failed_jobs_today ?? 0 }}</div>
                <div class="metric-sub">Today</div>
              </div>
            </div>

            <div class="dash-grid">
              <!-- Spend Chart -->
              <div class="section">
                <div class="section-header">
                  <div class="section-title">AI Spend</div>
                  <div class="section-actions">
                    <div class="spend-toggle">
                      <button v-for="d in [7, 14, 30]" :key="d" :class="['spend-toggle-btn', spendRange === d ? 'active' : '']" @click="spendRange = d">{{ d }}d</button>
                    </div>
                  </div>
                </div>
                <div style="padding:16px 20px 20px;">
                  <div v-if="spendChartLoading" class="gm-spinner-wrap" style="height:120px;">Loading...</div>
                  <template v-else>
                    <div class="chart-bars">
                      <!-- API: each day has { day: "2026-04-01", spend: 1.2345 } -->
                      <div
                        v-for="day in spendChart"
                        :key="day.day"
                        class="chart-bar-wrap"
                        :title="`${day.day}: ${money(day.spend)}`"
                      >
                        <div class="chart-bar" :style="{ height: `${Math.round((Number(day.spend) / spendChartMax) * 100)}%` }"></div>
                      </div>
                      <div v-if="spendChart.length === 0" class="gm-spinner-wrap" style="height:80px;">No data yet.</div>
                    </div>
                    <div v-if="spendChart.length" class="chart-labels">
                      <span>{{ spendChart[0]?.day }}</span>
                      <span>{{ spendChart[spendChart.length - 1]?.day }}</span>
                    </div>
                  </template>
                </div>
              </div>

              <!-- Plan Distribution -->
              <!-- API: plan_distribution is [{plan, count, pct}] array -->
              <div class="section">
                <div class="section-header"><div class="section-title">Plan Distribution</div></div>
                <div style="padding:16px 20px;">
                  <div
                    v-for="item in (dashData.plan_distribution ?? [])"
                    :key="item.plan"
                    style="margin-bottom:14px;"
                  >
                    <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                      <span style="font-size:12px;font-weight:600;text-transform:capitalize;">{{ item.plan }}</span>
                      <span style="font-size:12px;color:var(--gm-muted)">{{ item.count }} · {{ item.pct }}%</span>
                    </div>
                    <div class="spend-bar"><div class="spend-fill" :style="{ width: `${Math.min(100, item.pct * 3)}%` }"></div></div>
                  </div>
                  <div v-if="!(dashData.plan_distribution?.length)" style="color:var(--gm-muted);font-size:13px;">No workspace data.</div>
                </div>
              </div>
            </div>

            <!-- Top Spenders — API returns workspace-level data: name, plan_tier, spend_month_usd, owner_email -->
            <div class="section">
              <div class="section-header">
                <div class="section-title">Top Spenders This Month</div>
                <div class="section-actions">
                  <button class="btn btn-ghost btn-sm" @click="navigate('workspaces')">View All Workspaces</button>
                </div>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Workspace</th><th>Plan</th><th>Owner</th><th>AI Spend</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="ws in (dashData.top_spenders ?? [])" :key="ws.id">
                      <td>
                        <div class="cell-name">{{ ws.name }}</div>
                      </td>
                      <td><span :class="['badge', planBadgeClass(ws.plan_tier)]">{{ ws.plan_tier }}</span></td>
                      <td class="cell-muted">{{ ws.owner_email }}</td>
                      <td class="cell-spend">{{ money(ws.spend_month_usd) }}</td>
                    </tr>
                    <tr v-if="!(dashData.top_spenders?.length)">
                      <td colspan="4" class="empty-cell">No spend data yet.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </template>
        </template>

        <!-- ═══ USERS ═══ -->
        <template v-if="activeView === 'users'">
          <div class="section">
            <div class="section-header">
              <div class="section-title">All Users</div>
              <div class="section-actions">
                <span class="meta-count">{{ usersPagination.total ?? 0 }} users</span>
              </div>
            </div>
            <div class="filters">
              <input v-model="usersSearch" class="search-input" placeholder="Search name or email…" @input="usersFilter" />
              <select v-model="usersStatus" class="filter-select" @change="usersFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
              </select>
              <select v-model="usersPlan" class="filter-select" @change="usersFilter">
                <option value="">All Plans</option>
                <option value="free">Free</option>
                <option value="studio">Studio</option>
                <option value="scale">Scale</option>
                <option value="enterprise">Enterprise</option>
              </select>
            </div>
            <div class="table-wrap">
              <div v-if="usersLoading" class="gm-spinner-wrap">Loading...</div>
              <table v-else>
                <thead>
                  <tr>
                    <th>User</th><th>Plan</th><th>Workspace</th>
                    <th>Projects</th><th>Exports</th><th>Spend / Mo</th><th>Last Active</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="u in usersData" :key="u.id">
                    <td>
                      <div class="user-cell">
                        <div class="avatar" style="background:#7c3aed22;color:#7c3aed">{{ initials(u.name, u.email) }}</div>
                        <div>
                          <div class="cell-name">{{ u.name || u.email }}</div>
                          <div class="cell-sub">{{ u.email }}</div>
                        </div>
                      </div>
                    </td>
                    <td><span :class="['badge', planBadgeClass(u.plan_tier)]">{{ u.plan_tier || '-' }}</span></td>
                    <td>
                      <div class="cell-name">{{ u.workspace_name || '-' }}</div>
                      <div v-if="u.workspace_status" :class="['cell-sub', u.workspace_status !== 'active' ? 'text-red' : '']">{{ u.workspace_status }}</div>
                    </td>
                    <td>{{ u.projects }}</td>
                    <td>{{ u.exports }}</td>
                    <td class="cell-spend">{{ money(u.spend_month_usd) }}</td>
                    <td class="cell-muted">{{ fmtDate(u.last_login_at) }}</td>
                    <td>
                      <div style="display:flex;gap:5px;flex-wrap:wrap;">
                        <button class="btn btn-ghost btn-sm" @click="openPanel(u.id)">View</button>
                        <button class="btn btn-impersonate btn-sm" title="Impersonate" @click="impersonate(u.id)">🎭</button>
                        <button
                          v-if="u.workspace_id"
                          :class="['btn btn-sm', u.workspace_status === 'active' ? 'btn-danger' : 'btn-ghost']"
                          :disabled="suspendingUsersWs === u.workspace_id"
                          @click="suspendUserWorkspace(u)"
                        >{{ u.workspace_status === 'active' ? 'Suspend' : 'Unsuspend' }}</button>
                      </div>
                    </td>
                  </tr>
                  <tr v-if="!usersLoading && usersData.length === 0">
                    <td colspan="8" class="empty-cell">No users found.</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="gm-pagination">
              <span class="pg-label">Rows per page</span>
              <select v-model="usersPerPage" class="pg-per-page" @change="usersPerPageChange">
                <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
              </select>
              <span class="pg-info">{{ usersPagination.from ?? 0 }}–{{ usersPagination.to ?? 0 }} of {{ usersPagination.total ?? 0 }}</span>
              <div class="pg-controls">
                <button :disabled="usersPage <= 1" @click="usersPage--; loadUsers()">‹</button>
                <span>{{ usersPage }} / {{ usersPagination.last_page ?? 1 }}</span>
                <button :disabled="usersPage >= (usersPagination.last_page ?? 1)" @click="usersPage++; loadUsers()">›</button>
              </div>
            </div>
          </div>
        </template>

        <!-- ═══ WORKSPACES ═══ -->
        <template v-if="activeView === 'workspaces'">
          <div class="section">
            <div class="section-header">
              <div class="section-title">All Workspaces</div>
              <div class="section-actions">
                <span class="meta-count">{{ wsPagination.total ?? 0 }} workspaces</span>
              </div>
            </div>
            <div class="filters">
              <input v-model="wsSearch" class="search-input" placeholder="Search workspace…" @input="wsFilter" />
              <select v-model="wsPlan" class="filter-select" @change="wsFilter">
                <option value="">All Plans</option>
                <option value="free">Free</option>
                <option value="studio">Studio</option>
                <option value="scale">Scale</option>
                <option value="enterprise">Enterprise</option>
              </select>
              <select v-model="wsStatus" class="filter-select" @change="wsFilter">
                <option value="">All Status</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
            <div class="table-wrap">
              <div v-if="wsLoading" class="gm-spinner-wrap">Loading...</div>
              <table v-else>
                <thead>
                  <tr>
                    <th>Workspace</th><th>Plan</th><th>Status</th><th>Members</th>
                    <th>Projects</th><th>Exports</th><th>Spend / Mo</th><th>Budget %</th><th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <!-- API workspace fields: id, name, plan_tier, status, users_count, projects_count, exports_count, spend_month_usd, budget_pct -->
                  <tr v-for="ws in wsData" :key="ws.id">
                    <td><strong>{{ ws.name }}</strong></td>
                    <td>
                      <select :value="ws.plan_tier" class="inline-select" :disabled="wsSaving === ws.id" @change="updateWsPlan(ws, $event.target.value)">
                        <option value="free">Free</option>
                        <option value="studio">Studio</option>
                        <option value="scale">Scale</option>
                        <option value="enterprise">Enterprise</option>
                      </select>
                    </td>
                    <td>
                      <span :class="['badge', ws.status === 'active' ? 'badge-green' : 'badge-red']">{{ ws.status }}</span>
                    </td>
                    <td>{{ ws.users_count }}</td>
                    <td>{{ ws.projects_count }}</td>
                    <td>{{ ws.exports_count }}</td>
                    <td class="cell-spend">{{ money(ws.spend_month_usd) }}</td>
                    <td>
                      <div style="display:flex;align-items:center;gap:8px;">
                        <div class="spend-bar" style="width:70px;">
                          <div class="spend-fill" :style="{ width: `${ws.budget_pct ?? 0}%`, background: (ws.budget_pct ?? 0) >= 90 ? '#ef4444' : undefined }"></div>
                        </div>
                        <span style="font-size:11px;color:#6b7280">{{ ws.budget_pct ?? 0 }}%</span>
                      </div>
                    </td>
                    <td>
                      <button
                        :class="['btn btn-sm', ws.status === 'active' ? 'btn-danger' : 'btn-ghost']"
                        :disabled="wsSaving === ws.id"
                        @click="toggleWsStatus(ws)"
                      >{{ ws.status === 'active' ? 'Suspend' : 'Unsuspend' }}</button>
                    </td>
                  </tr>
                  <tr v-if="!wsLoading && wsData.length === 0">
                    <td colspan="9" class="empty-cell">No workspaces found.</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="gm-pagination">
              <span class="pg-label">Rows per page</span>
              <select v-model="wsPerPage" class="pg-per-page" @change="wsPerPageChange">
                <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
              </select>
              <span class="pg-info">{{ wsPagination.from ?? 0 }}–{{ wsPagination.to ?? 0 }} of {{ wsPagination.total ?? 0 }}</span>
              <div class="pg-controls">
                <button :disabled="wsPage <= 1" @click="wsPage--; loadWorkspaces()">‹</button>
                <span>{{ wsPage }} / {{ wsPagination.last_page ?? 1 }}</span>
                <button :disabled="wsPage >= (wsPagination.last_page ?? 1)" @click="wsPage++; loadWorkspaces()">›</button>
              </div>
            </div>
          </div>
        </template>

        <!-- ═══ JOBS ═══ -->
        <template v-if="activeView === 'jobs'">
          <div v-if="jobsLoading" class="gm-spinner-wrap">Loading...</div>
          <template v-else-if="jobsData">
            <!-- API counts: { queued, processing, completed_today, failed_today } -->
            <div class="metrics-grid metrics-grid-4">
              <div class="metric-card blue">
                <div class="metric-label">Processing</div>
                <div class="metric-value">{{ jobsData.counts?.processing ?? 0 }}</div>
              </div>
              <div class="metric-card yellow">
                <div class="metric-label">Queued</div>
                <div class="metric-value">{{ jobsData.counts?.queued ?? 0 }}</div>
              </div>
              <div class="metric-card green">
                <div class="metric-label">Completed Today</div>
                <div class="metric-value">{{ jobsData.counts?.completed_today ?? 0 }}</div>
              </div>
              <div class="metric-card red">
                <div class="metric-label">Failed Today</div>
                <div class="metric-value">{{ jobsData.counts?.failed_today ?? 0 }}</div>
              </div>
            </div>
            <div class="section">
              <div class="section-header">
                <div class="section-title">Recent Export Jobs</div>
                <div class="section-actions">
                  <button class="btn btn-ghost btn-sm" @click="loadJobs()">Refresh</button>
                </div>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th>Project</th><th>Workspace</th><th>Status</th><th>Aspect</th><th>Duration</th><th>Cost</th><th>Queued</th><th>Completed</th></tr>
                  </thead>
                  <tbody>
                    <tr v-for="job in (jobsData.jobs ?? [])" :key="job.id">
                      <td>
                        <div class="cell-name">{{ job.project_title || `Project #${job.project_id}` }}</div>
                        <div v-if="job.failure_reason" class="cell-sub cell-error" :title="job.failure_reason">{{ job.failure_reason }}</div>
                      </td>
                      <td class="cell-muted">{{ job.workspace_name || `WS #${job.workspace_id}` }}</td>
                      <td>
                        <span :class="['badge', job.status === 'completed' ? 'badge-green' : job.status === 'failed' ? 'badge-red' : job.status === 'processing' ? 'badge-blue' : 'badge-gray']">
                          {{ job.status }}{{ job.status === 'processing' && job.progress_percent != null ? ` ${job.progress_percent}%` : '' }}
                        </span>
                      </td>
                      <td class="cell-muted">{{ job.aspect_ratio || '-' }}</td>
                      <td class="cell-muted">{{ job.render_seconds != null ? `${Math.round(job.render_seconds)}s` : '-' }}</td>
                      <td class="cell-muted">{{ job.render_cost_usd != null ? `$${Number(job.render_cost_usd).toFixed(4)}` : '-' }}</td>
                      <td class="cell-muted">{{ fmtDate(job.queued_at) }}</td>
                      <td class="cell-muted">{{ fmtDate(job.completed_at) }}</td>
                    </tr>
                    <tr v-if="!(jobsData.jobs?.length)">
                      <td colspan="8" class="empty-cell">No recent jobs.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="gm-pagination">
                <span class="pg-label">Rows per page</span>
                <select v-model="jobsPerPage" class="pg-per-page" @change="jobsPerPageChange">
                  <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
                </select>
                <span class="pg-info">{{ jobsPagination.from ?? 0 }}–{{ jobsPagination.to ?? 0 }} of {{ jobsPagination.total ?? 0 }}</span>
                <div class="pg-controls">
                  <button :disabled="jobsPage <= 1" @click="jobsPage--; loadJobs()">‹</button>
                  <span>{{ jobsPage }} / {{ jobsPagination.last_page ?? 1 }}</span>
                  <button :disabled="jobsPage >= (jobsPagination.last_page ?? 1)" @click="jobsPage++; loadJobs()">›</button>
                </div>
              </div>
            </div>
          </template>
        </template>

        <!-- ═══ AUDIT LOG ═══ -->
        <template v-if="activeView === 'audit'">
          <div class="section">
            <div class="section-header"><div class="section-title">Admin Audit Log</div></div>
            <div class="table-wrap">
              <div v-if="auditLoading" class="gm-spinner-wrap">Loading...</div>
              <table v-else>
                <thead>
                  <tr><th>When</th><th>Admin ID</th><th>Action</th><th>Target</th><th>IP</th></tr>
                </thead>
                <tbody>
                  <!-- API log fields: id, admin_user_id, action, target_type, target_id, ip_address, payload_json, created_at -->
                  <tr v-for="entry in auditData" :key="entry.id">
                    <td class="cell-muted">{{ fmtDate(entry.created_at) }}</td>
                    <td class="cell-muted">#{{ entry.admin_user_id }}</td>
                    <td><span class="badge badge-purple">{{ entry.action }}</span></td>
                    <td class="cell-muted">{{ entry.target_type }} #{{ entry.target_id }}</td>
                    <td class="cell-muted">{{ entry.ip_address }}</td>
                  </tr>
                  <tr v-if="!auditLoading && auditData.length === 0">
                    <td colspan="5" class="empty-cell">No audit events yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="gm-pagination">
              <span class="pg-label">Rows per page</span>
              <select v-model="auditPerPage" class="pg-per-page" @change="auditPerPageChange">
                <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
              </select>
              <span class="pg-info">{{ auditPagination.from ?? 0 }}–{{ auditPagination.to ?? 0 }} of {{ auditPagination.total ?? 0 }}</span>
              <div class="pg-controls">
                <button :disabled="auditPage <= 1" @click="auditPage--; loadAudit()">‹</button>
                <span>{{ auditPage }} / {{ auditPagination.last_page ?? 1 }}</span>
                <button :disabled="auditPage >= (auditPagination.last_page ?? 1)" @click="auditPage++; loadAudit()">›</button>
              </div>
            </div>
          </div>
        </template>

        <!-- ═══ JOB FAILURES ═══ -->
        <template v-if="activeView === 'failures'">
          <div class="section">
            <div class="section-header">
              <div class="section-title">Job Failure Traces</div>
              <div class="section-actions">
                <select v-model="failuresEntityType" class="pg-per-page" style="width:140px;" @change="failuresFilter">
                  <option value="">All types</option>
                  <option value="export">export</option>
                  <option value="project">project</option>
                  <option value="scene">scene</option>
                  <option value="variant">variant</option>
                  <option value="variant_set">variant_set</option>
                  <option value="asset">asset</option>
                  <option value="workspace">workspace</option>
                  <option value="localization_link">localization_link</option>
                </select>
              </div>
            </div>
            <div class="table-wrap">
              <div v-if="failuresLoading" class="gm-spinner-wrap">Loading...</div>
              <table v-else>
                <thead>
                  <tr><th>When</th><th>Job</th><th>Entity</th><th>Exception</th><th>Message</th><th>Trace</th></tr>
                </thead>
                <tbody v-for="t in failuresData" :key="t.id">
                  <tr>
                    <td class="cell-muted" style="white-space:nowrap">{{ fmtDate(t.failed_at) }}</td>
                    <td><span class="badge badge-red">{{ t.job_label }}</span></td>
                    <td class="cell-muted">
                      <span v-if="t.entity_type">{{ t.entity_type }} #{{ t.entity_id }}</span>
                      <span v-else>—</span>
                    </td>
                    <td class="cell-muted" style="font-size:11px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" :title="t.exception_class">{{ t.exception_class }}</td>
                    <td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;" :title="t.exception_message">{{ t.exception_message }}</td>
                    <td>
                      <button class="btn-link" @click="toggleTrace(t.id)">
                        {{ failuresExpandedId === t.id ? 'hide' : 'show' }}
                      </button>
                    </td>
                  </tr>
                  <tr v-if="failuresExpandedId === t.id">
                    <td colspan="6" style="padding:0">
                      <pre style="margin:0;padding:12px 16px;background:#0d0d0d;color:#f87171;font-size:11px;line-height:1.5;overflow-x:auto;max-height:360px;white-space:pre-wrap;word-break:break-all">{{ t.exception_trace }}</pre>
                    </td>
                  </tr>
                </tbody>
                <tbody v-if="!failuresLoading && failuresData.length === 0">
                  <tr><td colspan="6" class="empty-cell">No job failures recorded.</td></tr>
                </tbody>
              </table>
            </div>
            <div class="gm-pagination">
              <span class="pg-label">Rows per page</span>
              <select v-model="failuresPerPage" class="pg-per-page" @change="failuresFilter">
                <option v-for="n in [20, 50, 100]" :key="n" :value="n">{{ n }}</option>
              </select>
              <span class="pg-info">{{ failuresPagination.current_page ?? 1 }} / {{ failuresPagination.last_page ?? 1 }} · {{ failuresPagination.total ?? 0 }} total</span>
              <div class="pg-controls">
                <button :disabled="failuresPage <= 1" @click="failuresPage--; loadFailures()">‹</button>
                <span>{{ failuresPage }}</span>
                <button :disabled="failuresPage >= (failuresPagination.last_page ?? 1)" @click="failuresPage++; loadFailures()">›</button>
              </div>
            </div>
          </div>
        </template>

        <!-- ═══ VIDEOS ═══ -->
        <template v-if="activeView === 'videos'">
          <!-- Status chips -->
          <div class="status-chips">
            <button
              v-for="chip in STATUS_CHIPS"
              :key="chip.value"
              :class="['status-chip', videosStatus === chip.value ? 'active' : '']"
              @click="videosStatus = chip.value; videosFilter()"
            >
              {{ chip.label }}
              <span class="chip-count">{{ chip.value === '' ? (videosStatusCounts.all ?? 0) : (videosStatusCounts[chip.value] ?? 0) }}</span>
            </button>
          </div>

          <div class="section">
            <div class="section-header">
              <div class="section-title">All Videos</div>
              <div class="section-actions">
                <input
                  v-model="videosSearch"
                  class="search-input"
                  style="width:200px;"
                  placeholder="Search title…"
                  @input="videosFilter"
                />
              </div>
            </div>

            <div class="table-wrap">
              <div v-if="videosLoading" class="gm-spinner-wrap" style="height:200px;">Loading...</div>
              <table v-else>
                <thead>
                  <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Workspace</th>
                    <th>Platform</th>
                    <th>Ratio</th>
                    <th>Scenes</th>
                    <th>Cost (Mo)</th>
                    <th>Created</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="v in videosData" :key="v.id">
                    <td>
                      <div class="cell-name">{{ v.title }}</div>
                      <div class="cell-sub">{{ v.source_type }}{{ v.primary_language ? ` · ${v.primary_language}` : '' }}</div>
                    </td>
                    <td>
                      <span :class="['badge', videoStatusBadge(v.status)]">{{ STATUS_LABELS[v.status] ?? v.status }}</span>
                    </td>
                    <td>
                      <div class="cell-name">{{ v.workspace_name }}</div>
                      <div class="cell-sub"><span :class="['badge', planBadgeClass(v.plan_tier)]" style="font-size:10px;padding:2px 5px;">{{ v.plan_tier }}</span></div>
                    </td>
                    <td class="cell-muted">{{ v.platform_target || '-' }}</td>
                    <td class="cell-muted">{{ v.aspect_ratio || '-' }}</td>
                    <td class="cell-muted">{{ v.scenes_count }}</td>
                    <td class="cell-spend">{{ money(v.cost_usd) }}</td>
                    <td class="cell-muted">{{ fmtDate(v.created_at) }}</td>
                  </tr>
                  <tr v-if="!videosLoading && videosData.length === 0">
                    <td colspan="8" class="empty-cell">No videos found.</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="gm-pagination">
              <span class="pg-label">Rows per page</span>
              <select v-model="videosPerPage" class="pg-per-page" @change="videosPerPageChange">
                <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
              </select>
              <span class="pg-info">{{ videosPagination.from ?? 0 }}–{{ videosPagination.to ?? 0 }} of {{ videosPagination.total ?? 0 }}</span>
              <div class="pg-controls">
                <button :disabled="videosPage <= 1" @click="videosPage--; loadVideos()">‹</button>
                <span>{{ videosPage }} / {{ videosPagination.last_page ?? 1 }}</span>
                <button :disabled="videosPage >= (videosPagination.last_page ?? 1)" @click="videosPage++; loadVideos()">›</button>
              </div>
            </div>
          </div>
        </template>

        <!-- ═══ BILLING ═══ -->
        <template v-if="activeView === 'billing'">
          <!-- Spend overview -->
          <div v-if="dashData?.summary" class="metrics-grid metrics-grid-4">
            <div class="metric-card purple">
              <div class="metric-label">Spend Today</div>
              <div class="metric-value">{{ money(dashData.summary.api_spend_today_usd) }}</div>
            </div>
            <div class="metric-card blue">
              <div class="metric-label">Spend This Month</div>
              <div class="metric-value">{{ money(dashData.summary.api_spend_month_usd) }}</div>
            </div>
            <div class="metric-card yellow">
              <div class="metric-label">Total Tracked</div>
              <div class="metric-value">{{ money(dashData.summary.api_spend_total_usd) }}</div>
            </div>
            <div class="metric-card green">
              <div class="metric-label">Period Total</div>
              <div class="metric-value">{{ money(billingTotal) }}</div>
              <div class="metric-sub">Last {{ billingRange }} days</div>
            </div>
          </div>

          <!-- Spend chart -->
          <div class="section">
            <div class="section-header">
              <div class="section-title">AI Spend Over Time</div>
              <div class="section-actions">
                <div class="spend-toggle">
                  <button v-for="d in [7, 14, 30, 90]" :key="d" :class="['spend-toggle-btn', billingRange === d ? 'active' : '']" @click="billingRange = d">{{ d }}d</button>
                </div>
              </div>
            </div>
            <div style="padding:20px;">
              <div v-if="billingChartLoading" class="gm-spinner-wrap" style="height:140px;">Loading...</div>
              <template v-else>
                <div class="chart-bars" style="height:140px;">
                  <div
                    v-for="day in billingChart"
                    :key="day.day"
                    class="chart-bar-wrap"
                    :title="`${day.day}: ${money(day.spend)}`"
                  >
                    <div class="chart-bar" :style="{ height: `${Math.round((Number(day.spend) / billingChartMax) * 100)}%` }"></div>
                  </div>
                  <div v-if="billingChart.length === 0" class="gm-spinner-wrap" style="flex:1;">No data yet.</div>
                </div>
                <div v-if="billingChart.length" class="chart-labels">
                  <span>{{ billingChart[0]?.day }}</span>
                  <span>{{ billingChart[billingChart.length - 1]?.day }}</span>
                </div>
              </template>
            </div>
          </div>

          <!-- Top spenders table -->
          <div class="section">
            <div class="section-header"><div class="section-title">Top Spending Workspaces</div></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Workspace</th><th>Plan</th><th>Owner</th><th>AI Spend (Month)</th></tr></thead>
                <tbody>
                  <tr v-for="ws in (dashData?.top_spenders ?? [])" :key="ws.id">
                    <td><strong>{{ ws.name }}</strong></td>
                    <td><span :class="['badge', planBadgeClass(ws.plan_tier)]">{{ ws.plan_tier }}</span></td>
                    <td class="cell-muted">{{ ws.owner_email }}</td>
                    <td class="cell-spend">{{ money(ws.spend_month_usd) }}</td>
                  </tr>
                  <tr v-if="!(dashData?.top_spenders?.length)">
                    <td colspan="4" class="empty-cell">No spend data yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Audit log (reuse same data) -->
          <div class="section">
            <div class="section-header"><div class="section-title">Recent Admin Actions</div></div>
            <div class="table-wrap">
              <table>
                <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>IP</th></tr></thead>
                <tbody>
                  <tr v-for="entry in auditData.slice(0, 10)" :key="entry.id">
                    <td class="cell-muted">{{ fmtDate(entry.created_at) }}</td>
                    <td class="cell-muted">#{{ entry.admin_user_id }}</td>
                    <td><span class="badge badge-purple">{{ entry.action }}</span></td>
                    <td class="cell-muted">{{ entry.target_type }} #{{ entry.target_id }}</td>
                    <td class="cell-muted">{{ entry.ip_address }}</td>
                  </tr>
                  <tr v-if="!auditData.length">
                    <td colspan="5" class="empty-cell">No admin actions yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </template>

        <!-- ═══ STORAGE ═══ -->
        <template v-if="activeView === 'storage'">
          <div v-if="storageLoading && !storageData" class="gm-spinner-wrap">Loading storage data...</div>
          <template v-else>
            <!-- Summary metric cards -->
            <div class="metrics-grid metrics-grid-4">
              <div class="metric-card">
                <div class="metric-label">Total Storage Used</div>
                <div class="metric-value">{{ storageData?.summary?.total_human ?? '—' }}</div>
                <div class="metric-sub">across all workspaces</div>
              </div>
              <div class="metric-card blue">
                <div class="metric-label">Total Assets</div>
                <div class="metric-value">{{ (storageData?.summary?.total_assets ?? 0).toLocaleString() }}</div>
                <div class="metric-sub">{{ (storageData?.summary?.workspace_count ?? 0) }} workspaces</div>
              </div>
              <div class="metric-card green">
                <div class="metric-label">Videos</div>
                <div class="metric-value">{{ (storageData?.summary?.video_count ?? 0).toLocaleString() }}</div>
                <div class="metric-sub">{{ (storageData?.summary?.audio_count ?? 0).toLocaleString() }} audio files</div>
              </div>
              <div class="metric-card purple">
                <div class="metric-label">Images</div>
                <div class="metric-value">{{ (storageData?.summary?.image_count ?? 0).toLocaleString() }}</div>
                <div class="metric-sub">image assets</div>
              </div>
            </div>

            <!-- Per-workspace breakdown -->
            <div class="section">
              <div class="section-header">
                <div class="section-title">Storage by Workspace</div>
                <div class="section-actions">
                  <span class="meta-count">{{ storagePagination.total ?? 0 }} workspaces</span>
                  <button class="btn btn-ghost btn-sm" :disabled="storageLoading" @click="loadStorage">Refresh</button>
                </div>
              </div>

              <div class="filters">
                <input v-model="storageSearch" class="search-input" placeholder="Search workspace…" @input="storageFilter" />
                <select v-model="storagePlan" class="filter-select" @change="storageFilter">
                  <option value="">All Plans</option>
                  <option value="free">Free</option>
                  <option value="studio">Studio</option>
                  <option value="scale">Scale</option>
                  <option value="enterprise">Enterprise</option>
                </select>
              </div>

              <div class="table-wrap">
                <div v-if="storageLoading" class="gm-spinner-wrap">Loading...</div>
                <table v-else>
                  <thead>
                    <tr>
                      <th>Workspace</th>
                      <th>Plan</th>
                      <th>Storage Used</th>
                      <th>Assets</th>
                      <th>Videos</th>
                      <th>Audio</th>
                      <th>Images</th>
                      <th style="width:160px;">Relative Usage</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-if="!storageData?.by_workspace?.length">
                      <td colspan="8" class="empty-cell">No storage data.</td>
                    </tr>
                    <tr v-for="row in storageData?.by_workspace" :key="row.workspace_id">
                      <td class="cell-name">{{ row.workspace_name }}</td>
                      <td><span :class="['badge', planBadgeClass(row.plan_tier)]">{{ row.plan_tier }}</span></td>
                      <td style="font-weight:600;">{{ row.total_human }}</td>
                      <td>{{ row.asset_count.toLocaleString() }}</td>
                      <td class="cell-muted">{{ row.video_count.toLocaleString() }}</td>
                      <td class="cell-muted">{{ row.audio_count.toLocaleString() }}</td>
                      <td class="cell-muted">{{ row.image_count.toLocaleString() }}</td>
                      <td>
                        <div class="storage-bar-wrap">
                          <div class="storage-bar-fill" :style="{ width: storageBarWidth(row.total_bytes) + '%' }"></div>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="gm-pagination">
                <span class="pg-label">Rows per page</span>
                <select v-model="storagePerPage" class="pg-per-page" @change="storagePerPageChange">
                  <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
                </select>
                <span class="pg-info">{{ storagePagination.from ?? 0 }}–{{ storagePagination.to ?? 0 }} of {{ storagePagination.total ?? 0 }}</span>
                <div class="pg-controls">
                  <button :disabled="storagePage <= 1" @click="storagePage--; loadStorage()">‹</button>
                  <span>{{ storagePage }} / {{ storagePagination.last_page ?? 1 }}</span>
                  <button :disabled="storagePage >= (storagePagination.last_page ?? 1)" @click="storagePage++; loadStorage()">›</button>
                </div>
              </div>
            </div>
          </template>
        </template>

        <!-- ── Trust & Safety (Moderation Events) ─────────────────────────── -->
        <template v-if="activeView === 'moderation'">
          <div class="moderation-page">
            <div class="gm-section-title">Trust &amp; Safety</div>
            <p style="font-size:12px;color:var(--gm-muted);margin-bottom:20px">Provider rejections, user reports, and pattern alerts. Click any row to triage.</p>

            <div class="mod-counters">
              <div class="mod-counter">
                <div class="mod-counter-label">Events (24h)</div>
                <div class="mod-counter-value">{{ modCounters.total_24h }}</div>
              </div>
              <div class="mod-counter">
                <div class="mod-counter-label">Unreviewed</div>
                <div class="mod-counter-value">{{ modCounters.unreviewed }}</div>
              </div>
              <div class="mod-counter mod-counter-warn">
                <div class="mod-counter-label">Unreviewed high / critical</div>
                <div class="mod-counter-value">{{ modCounters.high_severity }}</div>
              </div>
            </div>

            <div class="mod-filters">
              <select v-model="modFilterSource" @change="modFilter" class="mod-select">
                <option value="">All sources</option>
                <option value="generation_rejection">Provider rejection</option>
                <option value="user_report">User report</option>
                <option value="pattern_alert">Pattern alert</option>
                <option value="admin_action">Admin action</option>
              </select>
              <select v-model="modFilterSeverity" @change="modFilter" class="mod-select">
                <option value="">All severities</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
                <option value="info">Info</option>
              </select>
              <label class="mod-toggle">
                <input type="checkbox" v-model="modFilterUnreviewed" @change="modFilter" />
                Unreviewed only
              </label>
              <button class="btn btn-ghost btn-sm" type="button" @click="loadModeration">↻ Refresh</button>
            </div>

            <div v-if="modLoading" style="padding:24px;color:var(--gm-muted);font-size:13px;">Loading…</div>
            <table v-else class="gm-table">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Source</th>
                  <th>Severity</th>
                  <th>Workspace / User</th>
                  <th>Operation</th>
                  <th>Reason</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="modEvents.length === 0">
                  <td colspan="7" style="text-align:center;padding:32px;color:var(--gm-muted);font-size:13px;">No events match the current filter. Quiet days are good news.</td>
                </tr>
                <tr v-for="e in modEvents" :key="e.id" class="gm-row-click" @click="openModEvent(e.id)">
                  <td class="cell-muted">{{ fmtDate(e.created_at) }}</td>
                  <td>{{ modSourceLabel(e.source) }}</td>
                  <td><span :class="['mod-sev', modSeverityClass(e.severity)]">{{ e.severity }}</span></td>
                  <td>
                    <div style="font-weight:500">{{ e.workspace?.name || '—' }} #{{ e.workspace?.id }}</div>
                    <div class="cell-sub">{{ e.user?.email || '—' }}</div>
                  </td>
                  <td class="cell-muted">{{ e.operation || '—' }}</td>
                  <td style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ e.reason }}</td>
                  <td>
                    <span v-if="e.reviewed_at" class="mod-pill mod-pill-done">Reviewed</span>
                    <span v-else class="mod-pill mod-pill-open">Open</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Event detail modal -->
          <div v-if="modSelectedEvent" class="gm-modal-overlay" @click.self="modSelectedEvent = null">
            <div class="gm-modal mod-detail">
              <div class="gm-modal-head">
                <div>
                  <div class="gm-modal-title">Event #{{ modSelectedEvent.event.id }}</div>
                  <div style="font-size:12px;color:var(--gm-muted);margin-top:2px;">{{ modSourceLabel(modSelectedEvent.event.source) }} · {{ fmtDate(modSelectedEvent.event.created_at) }}</div>
                </div>
                <button class="gm-modal-close" @click="modSelectedEvent = null">✕</button>
              </div>
              <div class="gm-modal-body">
                <div class="mod-detail-row"><span>Severity</span><span :class="['mod-sev', modSeverityClass(modSelectedEvent.event.severity)]">{{ modSelectedEvent.event.severity }}</span></div>
                <div class="mod-detail-row"><span>Workspace</span><span>{{ modSelectedEvent.event.workspace?.name || '—' }} #{{ modSelectedEvent.event.workspace?.id }}</span></div>
                <div class="mod-detail-row"><span>User</span><span>{{ modSelectedEvent.event.user?.email || '—' }}</span></div>
                <div class="mod-detail-row"><span>Operation</span><span>{{ modSelectedEvent.event.operation || '—' }}</span></div>
                <div v-if="modSelectedEvent.event.project_id" class="mod-detail-row"><span>Project</span><span>#{{ modSelectedEvent.event.project_id }} · scene #{{ modSelectedEvent.event.scene_id || '—' }}</span></div>
                <div class="mod-detail-row"><span>Reason</span><span style="white-space:pre-wrap;">{{ modSelectedEvent.event.reason }}</span></div>
                <div v-if="modSelectedEvent.event.prompt" class="mod-detail-row"><span>Prompt</span><span style="white-space:pre-wrap;font-family:monospace;font-size:12px;">{{ modSelectedEvent.event.prompt }}</span></div>
                <div v-if="modSelectedEvent.event.report_url" class="mod-detail-row"><span>Reported URL</span><span><a :href="modSelectedEvent.event.report_url" target="_blank" rel="noopener">{{ modSelectedEvent.event.report_url }}</a></span></div>
                <div v-if="modSelectedEvent.event.report_message" class="mod-detail-row"><span>Reporter message</span><span style="white-space:pre-wrap;">{{ modSelectedEvent.event.report_message }}</span></div>
                <div v-if="modSelectedEvent.event.report_email" class="mod-detail-row"><span>Reporter email</span><span>{{ modSelectedEvent.event.report_email }}</span></div>

                <h4 style="margin:24px 0 10px;font-size:13px;font-weight:600;">Review &amp; Action</h4>
                <div v-if="modSelectedEvent.event.reviewed_at" style="font-size:12px;color:var(--gm-muted);margin-bottom:10px;">
                  Reviewed {{ fmtDate(modSelectedEvent.event.reviewed_at) }} by {{ modSelectedEvent.event.reviewer?.email || '—' }} — action: <strong>{{ modSelectedEvent.event.action_taken }}</strong>
                </div>
                <select v-model="modActionDraft.action_taken" class="mod-select" style="width:100%;margin-bottom:10px;">
                  <option value="no_action">No action — record only</option>
                  <option value="warning_sent">Warning sent</option>
                  <option value="content_removed">Content removed</option>
                  <option value="feature_suspended">Feature suspended</option>
                  <option value="account_suspended">Account suspended</option>
                  <option value="workspace_terminated">Workspace terminated</option>
                  <option value="reported_to_authorities">Reported to authorities</option>
                </select>
                <textarea v-model="modActionDraft.action_notes" placeholder="Notes (optional) — what you did and why" rows="3" style="width:100%;background:var(--gm-bg-card);border:1px solid var(--gm-border);border-radius:6px;padding:9px 11px;font-family:inherit;font-size:13px;color:var(--gm-text);resize:vertical;"></textarea>
                <button class="btn btn-primary btn-sm" type="button" @click="submitModReview" style="margin-top:12px;">Save review</button>

                <h4 v-if="modSelectedEvent.related_events?.length" style="margin:24px 0 10px;font-size:13px;font-weight:600;">Other events for this workspace</h4>
                <div v-if="modSelectedEvent.related_events?.length" style="display:flex;flex-direction:column;gap:6px;">
                  <div v-for="r in modSelectedEvent.related_events" :key="r.id" class="mod-related-row" @click="openModEvent(r.id)">
                    <span :class="['mod-sev', modSeverityClass(r.severity)]" style="margin-right:8px;">{{ r.severity }}</span>
                    <span style="flex:1;color:var(--gm-text);">{{ modSourceLabel(r.source) }} — {{ r.operation || '—' }}</span>
                    <span class="cell-muted" style="font-size:11px;">{{ fmtDate(r.created_at) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>

        <!-- ── Plans & Credits ─────────────────────────── -->
        <template v-if="activeView === 'plans'">
          <div class="plans-page">
          <div class="gm-section-title">Plans &amp; Credits</div>
          <p style="font-size:12px;color:var(--gm-muted);margin-bottom:20px">All available plan tiers, their monthly credit allocations, and feature gates.</p>
          <div class="plans-grid">
            <div v-for="plan in plansData" :key="plan.key" :class="['plan-card', plan.key === 'free' ? 'plan-free' : '']">
              <div class="plan-header">
                <div class="plan-name">{{ plan.name }}</div>
                <div class="plan-key">{{ plan.key }}</div>
              </div>
              <div class="plan-credits">
                <div class="plan-credits-num">{{ plan.credits_monthly === 0 ? '200*' : plan.credits_monthly.toLocaleString() }}</div>
                <div class="plan-credits-label">{{ plan.key === 'free' ? 'one-time grant' : 'credits / month' }}</div>
              </div>
              <div class="plan-features">
                <div class="plan-feature"><span class="pf-label">Channels</span><span class="pf-val">{{ plan.channel_limit >= 999 ? '∞' : plan.channel_limit }}</span></div>
                <div class="plan-feature"><span class="pf-label">AI image quality</span><span class="pf-val">{{ (plan.ai_image_quality || ['medium']).join(' + ') }}</span></div>
                <div class="plan-feature"><span class="pf-label">Watermark</span><span :class="['pf-val', plan.watermark ? 'pf-yes-bad' : 'pf-no']">{{ plan.watermark ? 'Yes' : 'No' }}</span></div>
                <div class="plan-feature"><span class="pf-label">Renders / mo</span><span class="pf-val">{{ plan.render_limit >= 9999 ? '∞' : plan.render_limit }}</span></div>
              </div>
              <div class="plan-cost-row">
                <div class="plan-cost-item"><span>Script generation</span><span>{{ creditCosts.SCRIPT }} cr</span></div>
                <div class="plan-cost-item"><span>Script rewrite</span><span>{{ creditCosts.SCRIPT }} cr</span></div>
                <div class="plan-cost-item"><span>Scene breakdown</span><span>{{ creditCosts.BREAKDOWN }} cr</span></div>
                <div class="plan-cost-item"><span>Stock visual</span><span>{{ creditCosts.STOCK }} cr / scene</span></div>
                <div class="plan-cost-item"><span>AI image (med)</span><span>{{ creditCosts.AI_MEDIUM }} cr / scene</span></div>
                <div class="plan-cost-item"><span>AI image (high)</span><span>{{ creditCosts.AI_HIGH }} cr / scene</span></div>
                <div class="plan-cost-item"><span>TTS voice</span><span>{{ creditCosts.TTS }} cr / scene</span></div>
                <div class="plan-cost-item"><span>TTS regen</span><span>{{ creditCosts.TTS }} cr / scene</span></div>
                <div class="plan-cost-item"><span>Export</span><span>{{ creditCosts.EXPORT }} cr</span></div>
              </div>
            </div>
          </div>
          <div class="gm-section-title" style="margin-top:32px">Typical Project Cost</div>
          <div class="cost-table">
            <div class="cost-row cost-header"><div>Project type</div><div>Scenes</div><div>Credits</div><div>$ equiv</div></div>
            <div v-for="ex in projectExamples" :key="ex.label" class="cost-row">
              <div>{{ ex.label }}</div><div>{{ ex.scenes }}</div><div><strong>{{ ex.credits }}</strong></div><div style="color:var(--gm-muted)">${{ (ex.credits * 0.01).toFixed(2) }}</div>
            </div>
          </div>
          </div><!-- end plans-page -->
        </template>

        <!-- ── SFX Library ─────────────────────────────── -->
        <template v-if="activeView === 'sfx'">
          <div class="sfx-page">
            <div class="gm-section-title">SFX Library</div>
            <p style="font-size:12px;color:var(--gm-muted);margin-bottom:16px">Upload royalty-free sound effects users can browse in the editor. Category is optional.</p>

            <!-- Upload form -->
            <div class="sfx-admin-upload">
              <div class="sfx-admin-row">
                <label class="sfx-admin-field" style="flex:2">
                  <span class="sfx-admin-label">Name</span>
                  <input v-model="sfxUploadForm.name" class="sfx-admin-input" placeholder="e.g. Whoosh Light" />
                </label>
                <label class="sfx-admin-field" style="flex:1">
                  <span class="sfx-admin-label">Category (optional)</span>
                  <select v-model="sfxUploadForm.category" class="sfx-admin-input">
                    <option :value="null">— None —</option>
                    <option v-for="c in sfxCategories" :key="c.key" :value="c.key">{{ c.label }}</option>
                  </select>
                </label>
                <label class="sfx-admin-field" style="flex:1">
                  <span class="sfx-admin-label">Source (optional)</span>
                  <input v-model="sfxUploadForm.source" class="sfx-admin-input" placeholder="pixabay, mixkit…" />
                </label>
              </div>
              <div class="sfx-admin-row">
                <input ref="sfxFileInput" type="file" accept="audio/*" multiple class="sfx-admin-file" @change="onSfxFiles" />
                <button class="btn btn-primary btn-sm" :disabled="sfxUploading || !sfxQueuedFiles.length" @click="uploadSfx">
                  {{ sfxUploading ? `Uploading ${sfxUploadIndex + 1} / ${sfxQueuedFiles.length}…` : `Upload ${sfxQueuedFiles.length || ''} file${sfxQueuedFiles.length === 1 ? '' : 's'}` }}
                </button>
              </div>
              <div v-if="sfxUploadError" class="sfx-admin-error">{{ sfxUploadError }}</div>
              <div v-if="sfxQueuedFiles.length" class="sfx-admin-hint">
                Each file will be uploaded with the name above (multiple files: filename is used per file, truncated to 60 chars).
              </div>
            </div>

            <!-- Filters -->
            <div class="sfx-admin-filters">
              <input v-model="sfxFilters.q" class="sfx-admin-input" placeholder="Search…" style="max-width:280px" @input="debouncedSfxSearch" />
              <select v-model="sfxFilters.category" class="sfx-admin-input" style="max-width:200px" @change="loadSfxList">
                <option value="">All categories</option>
                <option v-for="c in sfxCategories" :key="c.key" :value="c.key">{{ c.label }}</option>
              </select>
              <select v-model="sfxFilters.status" class="sfx-admin-input" style="max-width:160px" @change="loadSfxList">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="archived">Archived</option>
              </select>
              <span style="color:var(--gm-muted);font-size:12px;margin-left:auto">{{ sfxList.length }} sound{{ sfxList.length === 1 ? '' : 's' }}</span>
            </div>

            <!-- Table -->
            <div v-if="sfxListLoading" class="gm-spinner-wrap">Loading…</div>
            <div v-else-if="!sfxList.length" class="empty-cell" style="padding:32px">No sounds yet. Upload your first one above.</div>
            <div v-else class="sfx-admin-table">
              <div class="sfx-admin-thead">
                <div></div>
                <div>Name</div>
                <div>Category</div>
                <div>Duration</div>
                <div>Source</div>
                <div>Status</div>
                <div></div>
              </div>
              <div v-for="sound in sfxList" :key="sound.id" class="sfx-admin-tr">
                <button class="sfx-admin-play" :title="sfxPlayingId === sound.id ? 'Stop' : 'Preview'" @click="toggleSfxPreview(sound)">
                  {{ sfxPlayingId === sound.id ? '■' : '▶' }}
                </button>
                <input
                  v-model="sound.name"
                  class="sfx-admin-input sfx-inline"
                  @blur="saveSfxEdit(sound)"
                  @keydown.enter="$event.target.blur()"
                />
                <select v-model="sound.category" class="sfx-admin-input sfx-inline" @change="saveSfxEdit(sound)">
                  <option :value="null">—</option>
                  <option v-for="c in sfxCategories" :key="c.key" :value="c.key">{{ c.label }}</option>
                </select>
                <div style="color:var(--gm-muted);font-size:12px">{{ sound.duration_seconds ? sound.duration_seconds.toFixed(1)+'s' : '—' }}</div>
                <div style="color:var(--gm-muted);font-size:12px">{{ sound.source || '—' }}</div>
                <select v-model="sound.status" class="sfx-admin-input sfx-inline" @change="saveSfxEdit(sound)">
                  <option value="active">Active</option>
                  <option value="archived">Archived</option>
                </select>
                <button class="sfx-admin-delete" title="Delete" @click="deleteSfx(sound)">✕</button>
              </div>
            </div>
          </div>
        </template>

      </div>
    </main>

    <!-- User Detail Panel -->
    <div :class="['panel-overlay', panelOpen ? 'open' : '']" @click="closePanel"></div>
    <div :class="['user-panel', panelOpen ? 'open' : '']">
      <div v-if="panelLoading" class="gm-spinner-wrap" style="height:200px;">Loading user...</div>
      <template v-else-if="panelData">
        <!-- API: { user: {id,name,email,role,status,...}, workspace: {...}, spend_month_usd, spend_total_usd, spend_by_day, provider_breakdown, recent_projects } -->
        <div class="panel-header">
          <div class="panel-avatar" style="background:#7c3aed33;color:#7c3aed;">
            {{ initials(panelData.user?.name, panelData.user?.email) }}
          </div>
          <div class="panel-user-info">
            <div class="panel-user-name">{{ panelData.user?.name || panelData.user?.email }}</div>
            <div class="panel-user-email">{{ panelData.user?.email }}</div>
            <div class="panel-badges">
              <span :class="['badge', planBadgeClass(panelData.workspace?.plan_tier)]">{{ panelData.workspace?.plan_tier ?? 'no plan' }}</span>
              <span :class="['badge', panelData.user?.status === 'active' ? 'badge-green' : 'badge-red']">{{ panelData.user?.status }}</span>
            </div>
          </div>
          <div class="panel-actions">
            <button class="btn btn-impersonate btn-sm" :disabled="impersonating" @click="impersonate(panelData.user.id)">
              🎭 {{ impersonating ? 'Loading...' : 'Impersonate' }}
            </button>
            <button
              v-if="panelData.workspace"
              :class="['btn btn-sm', panelData.workspace.status === 'active' ? 'btn-danger' : 'btn-ghost']"
              :disabled="suspending"
              @click="suspendWorkspace(panelData.workspace.id, panelData.workspace.status)"
            >
              {{ suspending ? 'Saving...' : panelData.workspace.status === 'active' ? 'Suspend' : 'Unsuspend' }}
            </button>
            <button class="panel-close" @click="closePanel">×</button>
          </div>
        </div>

        <div class="panel-tabs">
          <button v-for="tab in ['overview', 'spend', 'credits', 'projects', 'providers']" :key="tab" :class="['panel-tab', panelTab === tab ? 'active' : '']" @click="panelTab = tab">
            {{ tab.charAt(0).toUpperCase() + tab.slice(1) }}
          </button>
        </div>

        <div class="panel-body">

          <!-- Overview tab -->
          <template v-if="panelTab === 'overview'">
            <div class="mini-stats mini-stats-3">
              <div class="mini-stat">
                <div class="mini-stat-label">Spend (Month)</div>
                <div class="mini-stat-value" style="color:#7c3aed">{{ money(panelData.spend_month_usd) }}</div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Total Spend</div>
                <div class="mini-stat-value">{{ money(panelData.spend_total_usd) }}</div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Projects</div>
                <div class="mini-stat-value">{{ panelData.recent_projects?.length ?? 0 }}+</div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Storage Used</div>
                <div class="mini-stat-value" style="color:#10b981;font-size:16px;margin-top:4px;">{{ panelData.storage?.total_human ?? '—' }}</div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Assets</div>
                <div class="mini-stat-value">{{ (panelData.storage?.asset_count ?? 0).toLocaleString() }}</div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Role</div>
                <div class="mini-stat-value" style="font-size:14px;margin-top:6px;">{{ panelData.user?.role }}</div>
              </div>
              <div class="mini-stat" style="grid-column: span 2; background: rgba(255,107,53,.06); border-color: rgba(255,107,53,.25);">
                <div class="mini-stat-label">Credit balance</div>
                <div class="mini-stat-value" style="color:#ff6b35;">{{ (panelData.credits?.balance ?? 0).toLocaleString() }}</div>
                <div style="font-size:10px;color:var(--color-text-muted);margin-top:2px;font-family:'Space Mono',monospace;">
                  monthly {{ (panelData.credits?.monthly_balance ?? 0).toLocaleString() }} · topup {{ (panelData.credits?.topup_balance ?? 0).toLocaleString() }}
                </div>
              </div>
            </div>
            <div v-if="panelData.workspace" class="ws-card">
              <div class="ws-header">
                <strong>{{ panelData.workspace.name }}</strong>
                <span :class="['badge', panelData.workspace.status === 'active' ? 'badge-green' : 'badge-red']">{{ panelData.workspace.status }}</span>
                <span :class="['badge', planBadgeClass(panelData.workspace.plan_tier)]" style="margin-left:4px">{{ panelData.workspace.plan_tier }}</span>
              </div>
              <div class="ws-stats">
                <div class="ws-stat">
                  <div class="ws-stat-val">{{ panelData.usage?.renders_used ?? 0 }}</div>
                  <div class="ws-stat-label">Renders Used</div>
                </div>
                <div class="ws-stat">
                  <div class="ws-stat-val">{{ panelData.usage?.active_channels ?? 0 }}</div>
                  <div class="ws-stat-label">Channels</div>
                </div>
                <div class="ws-stat">
                  <div class="ws-stat-val">{{ money(panelData.spend_month_usd) }}</div>
                  <div class="ws-stat-label">Spend/Mo</div>
                </div>
                <div class="ws-stat">
                  <div class="ws-stat-val">{{ panelData.usage?.api_budget_usd ? Math.round(panelData.spend_month_usd / panelData.usage.api_budget_usd * 100) : 0 }}%</div>
                  <div class="ws-stat-label">Budget</div>
                </div>
              </div>
            </div>
          </template>

          <!-- Spend tab -->
          <template v-if="panelTab === 'spend'">
            <!-- API: spend_by_day is [{day, spend}] -->
            <div style="margin-bottom:20px;">
              <div class="chart-bars" style="height:100px;">
                <div
                  v-for="day in (panelData.spend_by_day ?? [])"
                  :key="day.day"
                  class="chart-bar-wrap"
                  :title="`${day.day}: ${money(day.spend)}`"
                >
                  <div class="chart-bar" :style="{ height: `${Math.round((Number(day.spend) / panelSpendMax) * 100)}%` }"></div>
                </div>
                <div v-if="!(panelData.spend_by_day?.length)" style="display:flex;align-items:center;justify-content:center;flex:1;color:#6b7280;font-size:12px;">No spend data.</div>
              </div>
            </div>
            <!-- Provider breakdown as array of {provider, service, calls, cost_usd} -->
            <div v-if="panelData.provider_breakdown?.length">
              <div style="font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;">Provider Breakdown</div>
              <div v-for="row in panelData.provider_breakdown" :key="`${row.provider}-${row.service}`" class="provider-row">
                <div class="provider-name">{{ row.provider }}</div>
                <div class="provider-service">{{ row.service }}</div>
                <div class="provider-calls">{{ row.calls }} calls</div>
                <div class="provider-cost">{{ money(row.cost_usd) }}</div>
              </div>
            </div>
            <div v-else style="color:#6b7280;font-size:13px;padding:20px 0;">No provider data yet.</div>
          </template>

          <!-- Credits tab — balance + per-operation roll-up + recent ledger entries -->
          <template v-if="panelTab === 'credits'">
            <div class="mini-stats mini-stats-3">
              <div class="mini-stat" style="background: rgba(255,107,53,.06); border-color: rgba(255,107,53,.25);">
                <div class="mini-stat-label">Balance</div>
                <div class="mini-stat-value" style="color:#ff6b35;">{{ (panelData.credits?.balance ?? 0).toLocaleString() }}</div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Monthly</div>
                <div class="mini-stat-value">{{ (panelData.credits?.monthly_balance ?? 0).toLocaleString() }}</div>
              </div>
              <div class="mini-stat">
                <div class="mini-stat-label">Top-up</div>
                <div class="mini-stat-value">{{ (panelData.credits?.topup_balance ?? 0).toLocaleString() }}</div>
              </div>
            </div>

            <div v-if="panelData.ledger_summary?.length" style="margin-top:18px;">
              <div class="panel-section-title">Last 30 days · by operation</div>
              <div class="admin-ledger-summary">
                <div v-for="row in panelData.ledger_summary" :key="row.operation" class="admin-ledger-summary-row">
                  <span class="admin-op-tag">{{ row.operation }}</span>
                  <span class="admin-op-credits">{{ Math.abs(row.credits).toLocaleString() }} cr</span>
                  <span class="admin-op-count">{{ row.ops }} {{ row.ops === 1 ? 'op' : 'ops' }}</span>
                </div>
              </div>
            </div>

            <div v-if="panelData.ledger_recent?.length" style="margin-top:18px;">
              <div class="panel-section-title">Last {{ panelData.ledger_recent.length }} entries</div>
              <table class="admin-ledger-table">
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Operation</th>
                    <th>Scope</th>
                    <th style="text-align:right">Credits</th>
                    <th style="text-align:right">Balance after</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="e in panelData.ledger_recent" :key="e.id">
                    <td>{{ new Date(e.created_at).toLocaleString() }}</td>
                    <td><span :class="['admin-op-tag', e.operation.startsWith('grant:') ? 'grant' : '']">{{ e.operation }}</span></td>
                    <td>
                      <template v-if="e.project_id">project #{{ e.project_id }}<template v-if="e.scene_id"> · scene #{{ e.scene_id }}</template></template>
                      <template v-else-if="e.scene_id">scene #{{ e.scene_id }}</template>
                      <template v-else>—</template>
                    </td>
                    <td style="text-align:right" :class="e.operation.startsWith('grant:') ? 'credit-grant' : 'credit-debit'">
                      {{ e.operation.startsWith('grant:') ? '+' : '−' }}{{ Math.abs(e.credits).toLocaleString() }}
                    </td>
                    <td style="text-align:right;font-family:'Space Mono',monospace;font-size:11px;opacity:.65">
                      {{ e.balance_after.toLocaleString() }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-else class="banner" style="margin-top:18px;">No credit activity yet.</div>
          </template>

          <!-- Projects tab -->
          <template v-if="panelTab === 'projects'">
            <table>
              <thead><tr><th>Title</th><th>Status</th><th>Scenes</th><th>Created</th></tr></thead>
              <tbody>
                <tr v-for="p in (panelData.recent_projects ?? [])" :key="p.id">
                  <td><div class="cell-name">{{ p.title }}</div></td>
                  <td>
                    <span :class="['badge', ['ready_for_review','published'].includes(p.status) ? 'badge-green' : p.status === 'failed' ? 'badge-red' : 'badge-gray']">{{ p.status }}</span>
                  </td>
                  <td class="cell-muted">{{ p.scenes_count }}</td>
                  <td class="cell-muted">{{ fmtDate(p.created_at) }}</td>
                </tr>
                <tr v-if="!(panelData.recent_projects?.length)">
                  <td colspan="4" class="empty-cell">No projects.</td>
                </tr>
              </tbody>
            </table>
          </template>

          <!-- Providers tab -->
          <template v-if="panelTab === 'providers'">
            <div v-if="panelData.provider_breakdown?.length">
              <div v-for="row in panelData.provider_breakdown" :key="`${row.provider}-${row.service}`" class="provider-row">
                <div class="provider-name">{{ row.provider }}</div>
                <div class="provider-service">{{ row.service }}</div>
                <div class="provider-calls">{{ row.calls }} calls</div>
                <div class="provider-cost">{{ money(row.cost_usd) }}</div>
              </div>
            </div>
            <div v-else class="empty-cell">No provider data.</div>
          </template>

        </div>

      </template>
    </div>

  </div>

  <!-- ── Confirm / Alert modal ── -->
  <Teleport to="body">
    <div v-if="modal.open" class="adm-modal-backdrop" @click.self="modalCancel">
      <div class="adm-modal">
        <div class="adm-modal-header">
          <span class="adm-modal-title">{{ modal.title }}</span>
        </div>
        <p class="adm-modal-body">{{ modal.message }}</p>
        <div class="adm-modal-actions">
          <button v-if="modal.type === 'confirm'" class="adm-modal-btn adm-modal-btn-ghost" @click="modalCancel">Cancel</button>
          <button :class="['adm-modal-btn', modal.danger ? 'adm-modal-btn-danger' : 'adm-modal-btn-primary']" @click="modalConfirm">{{ modal.confirmLabel }}</button>
        </div>
      </div>
    </div>
  </Teleport>

</template>

<style scoped>
/* Trust & Safety / moderation tab */
.gm-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.gm-modal { background: #14141c; border: 1px solid #2a2a36; border-radius: 12px; max-width: 640px; width: 90vw; max-height: 86vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
.gm-modal-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 22px; border-bottom: 1px solid #2a2a36; }
.gm-modal-title { font-size: 15px; font-weight: 600; color: #ececf3; }
.gm-modal-close { background: transparent; border: none; color: #6b7280; cursor: pointer; font-size: 18px; padding: 4px 8px; border-radius: 6px; }
.gm-modal-close:hover { color: #ececf3; background: #1d1d28; }
.gm-modal-body { padding: 18px 22px; overflow-y: auto; }
.moderation-page { padding: 4px 2px 24px; }
.mod-counters { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 18px; }
.mod-counter { background: #1a1d24; border: 1px solid #2a2d38; border-radius: 8px; padding: 14px 16px; }
.mod-counter-label { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; }
.mod-counter-value { font-size: 22px; font-weight: 700; color: #ececf3; margin-top: 4px; }
.mod-counter-warn { border-color: rgba(255,80,80,0.35); }
.mod-counter-warn .mod-counter-value { color: #fca5a5; }
.mod-filters { display: flex; gap: 10px; align-items: center; margin-bottom: 14px; flex-wrap: wrap; }
.mod-select { background: #1a1d24; border: 1px solid #2a2d38; border-radius: 6px; padding: 7px 10px; color: #ececf3; font-size: 12.5px; font-family: inherit; outline: none; }
.mod-select:focus { border-color: #ff6b35; }
.mod-toggle { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: #c9cad4; cursor: pointer; }
.mod-sev { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
.sev-info { background: rgba(120,120,140,0.18); color: #b0b3bf; }
.sev-low { background: rgba(96,165,250,0.16); color: #93c5fd; }
.sev-med { background: rgba(251,191,36,0.16); color: #fcd34d; }
.sev-high { background: rgba(251,113,53,0.20); color: #fdba74; }
.sev-crit { background: rgba(239,68,68,0.22); color: #fca5a5; }
.mod-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 600; }
.mod-pill-open { background: rgba(255,107,53,0.16); color: #fdba74; }
.mod-pill-done { background: rgba(52,211,153,0.16); color: #86efac; }
.mod-detail { max-width: 720px; width: 92vw; max-height: 86vh; overflow: auto; }
.mod-detail-row { display: grid; grid-template-columns: 140px 1fr; gap: 16px; padding: 8px 0; font-size: 13px; border-bottom: 1px solid #1f2128; }
.mod-detail-row > span:first-child { color: #6b7280; font-size: 12px; }
.mod-related-row { display: flex; align-items: center; gap: 8px; padding: 7px 9px; background: #1a1d24; border: 1px solid #2a2d38; border-radius: 6px; font-size: 12.5px; cursor: pointer; }
.mod-related-row:hover { border-color: #494960; background: #1d1d28; }
.gm-row-click { cursor: pointer; }
.gm-row-click:hover { background: #1a1d24; }

.gm-shell {
  display: flex;
  height: 100vh;
  overflow: hidden;
  background: #0d0f14;
  color: #e4e6ef;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  font-size: 14px;
}

/* Sidebar */
.gm-sidebar {
  width: 220px;
  background: #161920;
  border-right: 1px solid #2a2d38;
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
}
.gm-logo {
  padding: 18px 16px;
  border-bottom: 1px solid #2a2d38;
  display: flex;
  align-items: center;
  gap: 10px;
}
.gm-logo-text { font-weight: 700; font-size: 15px; }
.god-badge {
  background: #7c3aed; color: #fff;
  font-size: 9px; font-weight: 700;
  padding: 2px 6px; border-radius: 4px; letter-spacing: .5px;
}
.gm-nav { padding: 10px 8px; flex: 1; overflow-y: auto; }
.nav-section-label {
  font-size: 10px; font-weight: 600; color: #6b7280;
  letter-spacing: .8px; text-transform: uppercase;
  padding: 12px 8px 5px;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 7px;
  cursor: pointer; color: #6b7280;
  transition: all .15s; font-weight: 500;
  width: 100%; text-align: left;
  border: none; background: none; font-size: 13px;
}
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.nav-item:hover { background: #1e2129; color: #e4e6ef; }
.nav-item.active { background: #7c3aed22; color: #7c3aed; }
.nav-badge {
  margin-left: auto; background: #ef4444;
  color: #fff; font-size: 10px; font-weight: 700;
  padding: 1px 6px; border-radius: 10px;
}

.gm-sidebar-foot { border-top: 1px solid #2a2d38; padding: 10px; }
.gm-user-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 8px; border-radius: 7px;
}
.gm-avatar {
  width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 12px; flex-shrink: 0;
}
.gm-user-name { font-size: 12px; font-weight: 600; color: #e4e6ef; }
.gm-user-role { font-size: 10px; color: #6b7280; }
.gm-logout {
  margin-left: auto; background: none; border: none;
  color: #6b7280; cursor: pointer; padding: 4px;
  border-radius: 5px; display: flex;
}
.gm-logout:hover { color: #e4e6ef; background: #1e2129; }

/* Main */
.gm-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
.gm-topbar {
  background: #161920; border-bottom: 1px solid #2a2d38;
  padding: 0 24px; height: 56px;
  display: flex; align-items: center; gap: 16px; flex-shrink: 0;
}
.gm-topbar-title { font-weight: 700; font-size: 16px; }
.gm-topbar-right { margin-left: auto; display: flex; align-items: center; gap: 12px; }
.topbar-meta { font-size: 12px; color: #6b7280; }
.btn-back {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 5px 10px; border-radius: 6px;
  font-size: 12px; font-weight: 600; cursor: pointer;
  border: 1px solid #2a2d38; background: transparent; color: #6b7280;
  transition: all .15s; white-space: nowrap; flex-shrink: 0;
}
.btn-back:hover { color: #e4e6ef; border-color: #6b7280; background: #1e2129; }

.gm-content { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }

/* Metrics */
.metrics-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; }
.metrics-grid-4 { grid-template-columns: repeat(4, 1fr); }
/* Plans page */
.gm-section-title { font-size: 14px; font-weight: 700; color: #c9cad4; margin-bottom: 4px; }
.plans-page { display: flex; flex-direction: column; gap: 20px; }

/* SFX admin page */
.sfx-page { display: flex; flex-direction: column; gap: 16px; }
.sfx-admin-upload { background: #161920; border: 1px solid #2a2d38; border-radius: 10px; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.sfx-admin-row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.sfx-admin-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.sfx-admin-label { font-size: 11px; color: var(--gm-muted); text-transform: uppercase; letter-spacing: .05em; }
.sfx-admin-input { background: #0e1015; border: 1px solid #2a2d38; border-radius: 6px; padding: 7px 10px; color: var(--gm-fg, #ececf3); font-size: 13px; font-family: inherit; outline: none; min-width: 0; }
.sfx-admin-input:focus { border-color: var(--gm-accent, #ff6b35); }
.sfx-admin-file { color: var(--gm-muted); font-size: 12px; flex: 1; min-width: 220px; }
.sfx-admin-error { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.25); color: #f87171; border-radius: 7px; padding: 8px 12px; font-size: 12px; }
.sfx-admin-hint { font-size: 11px; color: var(--gm-muted); }
.sfx-admin-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.sfx-admin-table { background: #161920; border: 1px solid #2a2d38; border-radius: 10px; overflow: hidden; }
.sfx-admin-thead, .sfx-admin-tr { display: grid; grid-template-columns: 40px 2fr 1fr 80px 1fr 110px 40px; gap: 10px; align-items: center; padding: 10px 14px; }
.sfx-admin-thead { font-size: 10px; text-transform: uppercase; color: var(--gm-muted); letter-spacing: .06em; border-bottom: 1px solid #2a2d38; background: #1a1d24; }
.sfx-admin-tr + .sfx-admin-tr { border-top: 1px solid #2a2d38; }
.sfx-admin-tr:hover { background: rgba(255,255,255,.02); }
.sfx-admin-play { width: 32px; height: 32px; border-radius: 50%; background: #0e1015; border: 1px solid #2a2d38; color: var(--gm-accent, #ff6b35); cursor: pointer; font-size: 11px; display: flex; align-items: center; justify-content: center; padding: 0; }
.sfx-admin-play:hover { background: var(--gm-accent, #ff6b35); color: #fff; border-color: var(--gm-accent, #ff6b35); }
.sfx-inline { font-size: 12px; padding: 5px 8px; }
.sfx-admin-delete { background: transparent; border: none; color: #f87171; cursor: pointer; font-size: 14px; padding: 4px 8px; border-radius: 4px; }
.sfx-admin-delete:hover { background: rgba(248,113,113,.1); }
.plans-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap: 16px; }
.plan-card { background: #161920; border: 1px solid #2a2d38; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; gap: 14px; }
.plan-free { border-color: rgba(251,191,36,.2); }
.plan-header { display: flex; align-items: center; justify-content: space-between; }
.plan-name { font-size: 15px; font-weight: 700; }
.plan-key { font-size: 10px; background: #2a2d38; padding: 2px 7px; border-radius: 4px; color: #6b7280; font-family: monospace; }
.plan-credits { text-align: center; padding: 10px 0; border-top: 1px solid #2a2d38; border-bottom: 1px solid #2a2d38; }
.plan-credits-num { font-size: 28px; font-weight: 700; color: #ff6b35; }
.plan-credits-label { font-size: 11px; color: #6b7280; margin-top: 2px; }
.plan-features { display: flex; flex-direction: column; gap: 5px; }
.plan-feature { display: flex; justify-content: space-between; font-size: 12px; }
.pf-label { color: #6b7280; }
.pf-val { font-weight: 500; }
.pf-yes-bad { color: #fbbf24; }
.pf-no { color: #34d399; }
.plan-cost-row { border-top: 1px solid #2a2d38; padding-top: 10px; display: flex; flex-direction: column; gap: 4px; }
.plan-cost-item { display: flex; justify-content: space-between; font-size: 11px; color: #6b7280; }
.plan-cost-item span:last-child { color: #c9cad4; font-weight: 500; font-family: monospace; }
.cost-table { border: 1px solid #2a2d38; border-radius: 8px; }
.cost-row { display: grid; grid-template-columns: 2fr 80px 100px 80px; gap: 0; padding: 9px 14px; font-size: 12px; border-bottom: 1px solid #2a2d38; }
.cost-row:last-child { border-bottom: none; }
.cost-header { background: #161920; font-size: 10px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.metric-card {
  background: #161920; border: 1px solid #2a2d38;
  border-radius: 10px; padding: 16px;
}
.metric-label { font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 8px; }
.metric-value { font-size: 24px; font-weight: 700; line-height: 1; }
.metric-sub { font-size: 11px; color: #6b7280; margin-top: 4px; }
.metric-card.green .metric-value { color: #10b981; }
.metric-card.red .metric-value { color: #ef4444; }
.metric-card.yellow .metric-value { color: #f59e0b; }
.metric-card.blue .metric-value { color: #3b82f6; }
.metric-card.purple .metric-value { color: #7c3aed; }

/* Dashboard grid */
.dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

/* Section */
.section { background: #161920; border: 1px solid #2a2d38; border-radius: 10px; }
.section-header {
  padding: 16px 20px; border-bottom: 1px solid #2a2d38;
  display: flex; align-items: center; gap: 12px;
}
.section-title { font-weight: 700; font-size: 14px; }
.section-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }

/* Filters */
.filters {
  display: flex; align-items: center; gap: 10px;
  padding: 14px 20px; border-bottom: 1px solid #2a2d38; flex-wrap: wrap;
}
.search-input {
  background: #1e2129; border: 1px solid #2a2d38;
  color: #e4e6ef; padding: 7px 12px; border-radius: 7px;
  font-size: 13px; width: 220px; outline: none;
}
.search-input:focus { border-color: #7c3aed; }
.filter-select {
  background: #1e2129; border: 1px solid #2a2d38;
  color: #e4e6ef; padding: 7px 10px; border-radius: 7px;
  font-size: 13px; outline: none; cursor: pointer;
}
.meta-count { font-size: 12px; color: #6b7280; }

/* Table */
.table-wrap { overflow: auto; }
table { width: 100%; border-collapse: collapse; }
th {
  text-align: left; padding: 11px 16px;
  font-size: 11px; font-weight: 600; color: #6b7280;
  text-transform: uppercase; letter-spacing: .5px;
  border-bottom: 1px solid #2a2d38; white-space: nowrap;
}
td { padding: 12px 16px; border-bottom: 1px solid #2a2d38; font-size: 13px; vertical-align: middle; }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #1e2129; }

.user-cell { display: flex; align-items: center; gap: 10px; }
.avatar {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 12px; flex-shrink: 0;
}
.cell-name { font-weight: 600; }
.cell-sub { font-size: 11px; color: #6b7280; }
.cell-muted { color: #6b7280; font-size: 12px; }
.cell-spend { color: #7c3aed; font-weight: 600; }
.empty-cell { text-align: center; padding: 40px; color: #6b7280; }
.storage-bar-wrap { height: 8px; background: rgba(255,255,255,0.08); border-radius: 4px; overflow: hidden; }
.storage-bar-fill { height: 100%; background: linear-gradient(90deg, #7c3aed, #a78bfa); border-radius: 4px; min-width: 2px; transition: width 0.4s; }

/* Inline select */
.inline-select {
  background: #1e2129; border: 1px solid #2a2d38;
  color: #e4e6ef; padding: 4px 8px; border-radius: 6px;
  font-size: 12px; outline: none; cursor: pointer;
}

/* Badges */
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 5px;
  font-size: 11px; font-weight: 600; white-space: nowrap;
}
.badge-green { background: #10b98120; color: #10b981; }
.badge-red { background: #ef444420; color: #ef4444; }
.badge-yellow { background: #f59e0b20; color: #f59e0b; }
.badge-blue { background: #3b82f620; color: #3b82f6; }
.badge-purple { background: #7c3aed22; color: #7c3aed; }
.badge-gray { background: #ffffff12; color: #6b7280; }

.dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
.dot-green { background: #10b981; }
.dot-red { background: #ef4444; }

/* Buttons */
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: 7px;
  font-size: 12px; font-weight: 600; cursor: pointer;
  border: none; transition: all .15s;
}
.btn:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; color: #6b7280; border: 1px solid #2a2d38; }
.btn-ghost:hover:not(:disabled) { color: #e4e6ef; border-color: #e4e6ef; background: #1e2129; }
.btn-danger { background: #ef444420; color: #ef4444; border: 1px solid #ef444430; }
.btn-danger:hover:not(:disabled) { background: #ef4444; color: #fff; }
.btn-sm { padding: 4px 10px; font-size: 11px; }
.btn-impersonate { background: #f59e0b20; color: #f59e0b; border: 1px solid #f59e0b30; }
.btn-impersonate:hover:not(:disabled) { background: #f59e0b; color: #000; }

/* Chart */
.chart-bars {
  display: flex; align-items: flex-end; gap: 3px;
  height: 120px; background: #1e2129;
  border: 1px solid #2a2d38; border-radius: 8px;
  padding: 12px 12px 0; overflow: hidden;
}
.chart-bar-wrap { flex: 1; display: flex; align-items: flex-end; min-width: 0; }
.chart-bar {
  width: 100%; border-radius: 3px 3px 0 0;
  background: #7c3aed; opacity: .75; transition: opacity .15s;
  min-height: 2px;
}
.chart-bar:hover { opacity: 1; }
.chart-labels {
  display: flex; justify-content: space-between;
  margin-top: 6px; font-size: 11px; color: #6b7280;
}

/* Spend bar */
.spend-bar { height: 4px; background: #2a2d38; border-radius: 2px; overflow: hidden; }
.spend-fill { height: 100%; border-radius: 2px; background: #7c3aed; }

/* Spend toggle */
.spend-toggle { display: flex; background: #1e2129; border-radius: 7px; padding: 3px; gap: 2px; }
.spend-toggle-btn {
  padding: 4px 12px; border-radius: 5px;
  font-size: 11px; font-weight: 600; cursor: pointer;
  border: none; background: none; color: #6b7280; transition: all .15s;
}
.spend-toggle-btn.active { background: #7c3aed; color: #fff; }

/* Pagination */
.gm-pagination {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 20px; border-top: 1px solid #2a2d38;
  font-size: 12px; color: #6b7280; flex-wrap: wrap;
}
.pg-label { color: #6b7280; white-space: nowrap; }
.pg-per-page {
  background: #1e2129; border: 1px solid #2a2d38;
  color: #e4e6ef; padding: 4px 8px; border-radius: 6px;
  font-size: 12px; outline: none; cursor: pointer;
}
.pg-info { color: #6b7280; white-space: nowrap; }
.pg-controls { margin-left: auto; display: flex; align-items: center; gap: 6px; }
.pg-controls button {
  background: #1e2129; border: 1px solid #2a2d38;
  color: #e4e6ef; padding: 4px 10px; border-radius: 6px;
  cursor: pointer; font-size: 13px; line-height: 1;
}
.pg-controls button:disabled { opacity: 0.35; cursor: default; }
.pg-controls span { font-size: 12px; color: #6b7280; white-space: nowrap; }

/* Spinner */
.gm-spinner-wrap {
  display: flex; align-items: center; justify-content: center;
  padding: 32px; color: #6b7280; font-size: 13px;
}

/* User detail panel */
.panel-overlay { position: fixed; inset: 0; background: #00000060; z-index: 100; display: none; }
.panel-overlay.open { display: block; }
.user-panel {
  position: fixed; top: 0; right: 0;
  width: 720px; height: 100vh;
  background: #161920; border-left: 1px solid #2a2d38;
  z-index: 101; display: flex; flex-direction: column;
  transform: translateX(100%); transition: transform .25s ease;
  overflow: hidden;
}
.user-panel.open { transform: translateX(0); }

.panel-header {
  padding: 20px 24px; border-bottom: 1px solid #2a2d38;
  display: flex; align-items: flex-start; gap: 14px; flex-shrink: 0;
}
.panel-avatar {
  width: 46px; height: 46px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 16px; flex-shrink: 0;
}
.panel-user-info { flex: 1; }
.panel-user-name { font-size: 17px; font-weight: 700; }
.panel-user-email { color: #6b7280; margin-top: 2px; font-size: 13px; }
.panel-badges { display: flex; gap: 6px; margin-top: 8px; }
.panel-actions { display: flex; gap: 8px; align-items: flex-start; flex-shrink: 0; }
.panel-close {
  background: none; border: none; color: #6b7280;
  cursor: pointer; padding: 4px; border-radius: 5px;
  font-size: 20px; line-height: 1;
}
.panel-close:hover { color: #e4e6ef; background: #1e2129; }

.panel-tabs {
  display: flex; border-bottom: 1px solid #2a2d38;
  padding: 0 24px; flex-shrink: 0;
}
.panel-tab {
  padding: 12px 16px; font-size: 13px; font-weight: 600;
  color: #6b7280; cursor: pointer;
  border: none; background: none;
  border-bottom: 2px solid transparent; margin-bottom: -1px;
  transition: all .15s; white-space: nowrap;
}
.panel-tab.active { color: #7c3aed; border-bottom-color: #7c3aed; }
.panel-tab:hover:not(.active) { color: #e4e6ef; }

.panel-body { flex: 1; overflow-y: auto; padding: 24px; }

/* Mini stats */
.mini-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px; }
.mini-stats.mini-stats-3 { grid-template-columns: repeat(3, 1fr); }
.mini-stat { background: #1e2129; border: 1px solid #2a2d38; border-radius: 8px; padding: 14px; }
.mini-stat-label { font-size: 11px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
.mini-stat-value { font-size: 20px; font-weight: 700; margin-top: 4px; }

/* Admin credit-ledger panel */
.panel-section-title { font-size: 12px; font-weight: 600; color: #a1a5b1; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; }
.admin-ledger-summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 6px; }
.admin-ledger-summary-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid #2a2d38; border-radius: 6px; background: #1a1d24; font-size: 12px; }
.admin-op-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; background: #2a2d38; color: #ececf3; font-size: 11px; font-family: "Space Mono", monospace; }
.admin-op-tag.grant { color: #34d399; background: rgba(52,211,153,.1); border: 1px solid rgba(52,211,153,.3); }
.admin-op-credits { font-family: "Space Mono", monospace; color: #ff6b35; font-weight: 600; margin-left: auto; }
.admin-op-count { font-size: 11px; color: #6b7280; min-width: 56px; text-align: right; }
.admin-ledger-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.admin-ledger-table th { text-align: left; padding: 8px 10px; border-bottom: 1px solid #2a2d38; color: #6b7280; font-weight: 500; background: #1a1d24; }
.admin-ledger-table td { padding: 9px 10px; border-bottom: 1px solid #2a2d38; color: #c8cad1; }
.credit-debit { color: #ececf3; font-family: "Space Mono", monospace; }
.credit-grant { color: #34d399; font-family: "Space Mono", monospace; }

/* Workspace card */
.ws-card { background: #1e2129; border: 1px solid #2a2d38; border-radius: 8px; padding: 16px; }
.ws-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; font-size: 14px; }
.ws-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.ws-stat { text-align: center; }
.ws-stat-val { font-size: 18px; font-weight: 700; }
.ws-stat-label { font-size: 10px; color: #6b7280; text-transform: uppercase; }

/* Provider row */
.provider-row {
  display: flex; align-items: center; gap: 14px;
  padding: 10px 0; border-bottom: 1px solid #2a2d38;
}
.provider-row:last-child { border-bottom: none; }
.provider-name { font-weight: 600; width: 90px; flex-shrink: 0; font-size: 13px; }
.provider-service { flex: 1; font-size: 12px; color: #6b7280; }
.provider-calls { font-size: 12px; color: #6b7280; width: 70px; text-align: right; }
.provider-cost { font-size: 13px; font-weight: 600; width: 70px; text-align: right; color: #7c3aed; }

/* Status chips (videos filter bar) */
.status-chips {
  display: flex; gap: 8px; flex-wrap: wrap;
}
.status-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 20px;
  font-size: 12px; font-weight: 600; cursor: pointer;
  border: 1px solid #2a2d38; background: #161920; color: #6b7280;
  transition: all .15s;
}
.status-chip:hover { border-color: #7c3aed40; color: #e4e6ef; }
.status-chip.active { background: #7c3aed22; border-color: #7c3aed; color: #7c3aed; }
.chip-count {
  background: #2a2d38; border-radius: 10px;
  padding: 1px 7px; font-size: 10px; font-weight: 700;
}
.status-chip.active .chip-count { background: #7c3aed40; }

.text-red { color: #ef4444; }

/* Scrollbar */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #2a2d38; border-radius: 3px; }

@media (max-width: 1100px) {
  .metrics-grid { grid-template-columns: repeat(3, 1fr); }
  .dash-grid { grid-template-columns: 1fr; }
}
@media (max-width: 900px) {
  .user-panel { width: 100vw; }
  .metrics-grid { grid-template-columns: repeat(2, 1fr); }
  .mini-stats { grid-template-columns: repeat(2, 1fr); }
}

/* ── Modal ── */
.adm-modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  backdrop-filter: blur(2px);
}
.adm-modal {
  background: #1a1a24;
  border: 1px solid #2a2a36;
  border-radius: 12px;
  padding: 24px;
  width: min(420px, calc(100vw - 32px));
  box-shadow: 0 24px 60px rgba(0,0,0,0.5);
}
.adm-modal-header { margin-bottom: 10px; }
.adm-modal-title { font-size: 15px; font-weight: 600; color: #ececf3; }
.adm-modal-body { font-size: 13px; color: #8b8b9a; line-height: 1.55; margin-bottom: 22px; }
.adm-modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
.adm-modal-btn {
  padding: 8px 16px;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  border: 1px solid transparent;
  font-family: inherit;
  transition: 0.15s;
}
.adm-modal-btn-ghost { background: transparent; border-color: #2a2a36; color: #8b8b9a; }
.adm-modal-btn-ghost:hover { background: #25252f; color: #ececf3; }
.adm-modal-btn-primary { background: #6366f1; border-color: #6366f1; color: #fff; }
.adm-modal-btn-primary:hover { background: #4f52d3; }
.adm-modal-btn-danger { background: #ef4444; border-color: #ef4444; color: #fff; }
.adm-modal-btn-danger:hover { background: #dc2626; }
</style>
