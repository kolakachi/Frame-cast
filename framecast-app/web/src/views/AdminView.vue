<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
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

// ── Navigation ────────────────────────────────────────────────────────────────
function navigate(view) {
  activeView.value = view
  if (view === 'dashboard' && !dashData.value) { loadDashboard(); loadSpendChart() }
  if (view === 'users') loadUsers()
  if (view === 'workspaces') loadWorkspaces()
  if (view === 'videos') loadVideos()
  if (view === 'jobs') loadJobs()
  if (view === 'audit') loadAudit()
  if (view === 'failures') loadFailures()
  if (view === 'billing') { loadBillingChart(); loadAudit() }
  if (view === 'storage') loadStorage()
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
})
</script>

<template>
  <div class="gm-shell">

    <!-- Sidebar -->
    <aside class="gm-sidebar">
      <div class="gm-logo">
        <span class="gm-logo-text">Framecast</span>
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
          <button v-for="tab in ['overview', 'spend', 'projects', 'providers']" :key="tab" :class="['panel-tab', panelTab === tab ? 'active' : '']" @click="panelTab = tab">
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
