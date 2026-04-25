<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import { getEcho } from '../services/echo'
import AppSidebar from '../components/AppSidebar.vue'
import NewVideoWizard from '../components/NewVideoWizard.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const mePayload = ref(null)
const isAdmin = computed(() => ['super_admin', 'platform_admin'].includes(mePayload.value?.role ?? authStore.user?.role))

const notificationDrawerOpen = ref(false)
const notifications = ref([])
const notificationToasts = ref([])
let workspaceChannelName = null
let dashboardPollTimer = null

const projects = ref([])
const queueRows = ref([])
const channels = ref([])
const seriesPreview = ref([])

const CH_DOT_COLORS = ['#7c1a1a', '#c8860a', '#0a6b6b', '#1a3d7c', '#6b0a6b']
const deletingProjectIds = ref([])
const deleteConfirmProject = ref(null)
const currentPage = ref(1)
const perPage = ref(8)
const totalProjects = ref(0)
const lastPage = ref(1)
const filterChannelId = ref(null)
const queuePage = ref(1)
const queuePerPage = ref(10)
const totalQueueRows = ref(0)
const queueLastPage = ref(1)

const perPageOptions = [4, 8, 12, 16, 24]
const queuePerPageOptions = [5, 10, 20]

const wizardRef = ref(null)

const unreadCount = computed(() => notifications.value.filter((item) => !item.is_read).length)
const videosThisMonth = computed(() => totalProjects.value)
const queuedRenders = computed(() => queueRows.value.filter((row) => row.status === 'queued' || row.status === 'rendering').length)
const pageFrom = computed(() => (projects.value.length === 0 ? 0 : ((currentPage.value - 1) * perPage.value) + 1))
const pageTo = computed(() => Math.min(totalProjects.value, ((currentPage.value - 1) * perPage.value) + projects.value.length))
const queueFrom = computed(() => (queueRows.value.length === 0 ? 0 : ((queuePage.value - 1) * queuePerPage.value) + 1))
const queueTo = computed(() => Math.min(totalQueueRows.value, ((queuePage.value - 1) * queuePerPage.value) + queueRows.value.length))
const activeChannels = computed(() => {
  const ids = new Set(projects.value.map((project) => project.channel_id).filter(Boolean))
  return ids.size
})
const selectedChannel = computed(() =>
  channels.value.find((channel) => String(channel.id) === String(channelId.value)) || null
)
function openWizard(initialSourceType = 'prompt', presetChannelId = null) {
  wizardRef.value?.open(initialSourceType, presetChannelId)
}

function formatNotifTime(value) {
  if (!value) return 'now'
  const ts = new Date(value).getTime()
  const delta = Math.floor((Date.now() - ts) / 60000)
  if (delta < 1) return 'now'
  if (delta < 60) return `${delta} min ago`
  const hrs = Math.floor(delta / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

function mapProjectStatus(status) {
  if (status === 'ready_for_review') {
    return { className: 'status-rendered', label: '● Rendered' }
  }

  if (status === 'failed') {
    return { className: 'status-failed', label: '✕ Failed' }
  }

  if (status === 'generating') {
    return { className: 'status-rendering', label: '◌ Generating' }
  }

  return { className: 'status-draft', label: '◯ Draft' }
}

function formatDurationLabel(seconds) {
  const value = Number(seconds || 0)

  if (!value || Number.isNaN(value)) {
    return '—'
  }

  const mins = Math.floor(value / 60)
  const secs = Math.floor(value % 60)
  return `${mins}:${String(secs).padStart(2, '0')}`
}

function channelName(channelId) {
  if (!channelId) return null
  const ch = channels.value.find((c) => String(c.id) === String(channelId))
  return ch?.name ?? null
}

function channelNameById(id) {
  return channelName(id)
}

function openProject(project) {
  if (!project?.id) return

  if (project.status === 'generating') {
    router.push({ name: 'generation-progress', params: { projectId: project.id } })
    return
  }

  router.push({ name: 'project-editor', params: { projectId: project.id } })
}

function openVariants(projectId) {
  if (!projectId) return
  router.push({ name: 'project-variants', params: { projectId } })
}

function queueRowsFromProjects(projectList) {
  return projectList.map((project) => {
    let status = 'queued'
    let progress = 0
    let statusLabel = '◯ Queued'

    if (project.status === 'generating') {
      status = 'rendering'
      progress = Math.min(95, Math.max(10, (project.scenes_count || 0) * 12))
      statusLabel = '◌ Generating'
    } else if (project.status === 'ready_for_review') {
      status = 'rendered'
      progress = 100
      statusLabel = '● Done'
    } else if (project.status === 'failed') {
      status = 'failed'
      progress = 100
      statusLabel = '✕ Failed'
    }

    return {
      id: project.id,
      project: project.title || `Project #${project.id}`,
      channel: channelName(project.channel_id) || 'No channel',
      variants: Number(project.variants_count || 0),
      status,
      statusLabel,
      progress,
      projectStatus: project.status,
    }
  })
}

function isDeletingProject(projectId) {
  return deletingProjectIds.value.includes(projectId)
}

function requestDeleteProject(projectId) {
  const project = projects.value.find((item) => item.id === projectId)

  deleteConfirmProject.value = project
    ? { id: project.id, title: project.title || `Project #${project.id}` }
    : { id: projectId, title: `Project #${projectId}` }
}

function closeDeleteConfirm() {
  if (deleteConfirmProject.value && !isDeletingProject(deleteConfirmProject.value.id)) {
    deleteConfirmProject.value = null
  }
}

async function confirmDeleteProject() {
  const projectId = deleteConfirmProject.value?.id

  if (!projectId || isDeletingProject(projectId)) return

  deletingProjectIds.value = [...deletingProjectIds.value, projectId]

  try {
    await api.delete(`/projects/${projectId}`)
    deleteConfirmProject.value = null
    if (projects.value.length === 1 && currentPage.value > 1) {
      currentPage.value -= 1
    }
    if (queueRows.value.length === 1 && queuePage.value > 1) {
      queuePage.value -= 1
    }
    await Promise.all([loadProjects(), loadQueue()])
  } catch {
    // no-op
  } finally {
    deletingProjectIds.value = deletingProjectIds.value.filter((id) => id !== projectId)
  }
}

function setChannelFilter(channelId) {
  filterChannelId.value = channelId
  currentPage.value = 1
  loadProjects()
}

async function loadProjects() {
  try {
    const params = { page: currentPage.value, per_page: perPage.value }
    if (filterChannelId.value) params.channel_id = filterChannelId.value
    const response = await api.get('/projects', { params })
    const items = response.data?.data?.projects ?? []
    const pagination = response.data?.meta?.pagination ?? {}
    projects.value = items
    totalProjects.value = Number(pagination.total || items.length || 0)
    lastPage.value = Math.max(1, Number(pagination.last_page || 1))
    currentPage.value = Math.min(Math.max(1, Number(pagination.current_page || currentPage.value)), lastPage.value)
    perPage.value = Number(pagination.per_page || perPage.value)
  } catch {
    projects.value = []
    totalProjects.value = 0
    lastPage.value = 1
  }
}

async function loadQueue() {
  try {
    const response = await api.get('/projects/queue', {
      params: {
        page: queuePage.value,
        per_page: queuePerPage.value,
      },
    })
    const items = response.data?.data?.queue_rows ?? []
    const pagination = response.data?.meta?.pagination ?? {}
    queueRows.value = queueRowsFromProjects(items)
    totalQueueRows.value = Number(pagination.total || items.length || 0)
    queueLastPage.value = Math.max(1, Number(pagination.last_page || 1))
    queuePage.value = Math.min(Math.max(1, Number(pagination.current_page || queuePage.value)), queueLastPage.value)
    queuePerPage.value = Number(pagination.per_page || queuePerPage.value)
  } catch {
    queueRows.value = []
    totalQueueRows.value = 0
    queueLastPage.value = 1
  }
}

function changePerPage(nextValue) {
  perPage.value = Number(nextValue)
  currentPage.value = 1
  loadProjects()
}

function goToPage(nextPage) {
  if (nextPage < 1 || nextPage > lastPage.value || nextPage === currentPage.value) return
  currentPage.value = nextPage
  loadProjects()
}

function changeQueuePerPage(nextValue) {
  queuePerPage.value = Number(nextValue)
  queuePage.value = 1
  loadQueue()
}

function goToQueuePage(nextPage) {
  if (nextPage < 1 || nextPage > queueLastPage.value || nextPage === queuePage.value) return
  queuePage.value = nextPage
  loadQueue()
}

function startDashboardPolling() {
  if (dashboardPollTimer) {
    window.clearInterval(dashboardPollTimer)
  }

  dashboardPollTimer = window.setInterval(() => {
    loadProjects()
    loadQueue()
  }, 5000)
}

function stopDashboardPolling() {
  if (dashboardPollTimer) {
    window.clearInterval(dashboardPollTimer)
    dashboardPollTimer = null
  }
}

async function loadMe() {
  try {
    const response = await api.get('/me')
    mePayload.value = response.data?.data?.user ?? null
    await Promise.all([loadProjects(), loadQueue(), loadChannels(), loadSeriesPreview()])
    await loadNotifications()
    subscribeWorkspaceNotifications()
    startDashboardPolling()
  } catch {
    mePayload.value = null
  }
}

async function loadChannels() {
  try {
    const response = await api.get('/channels')
    channels.value = response.data?.data?.channels ?? []
  } catch {
    channels.value = []
  }
}

async function loadSeriesPreview() {
  try {
    const response = await api.get('/series')
    seriesPreview.value = (response.data?.data?.series ?? []).slice(0, 3)
  } catch {
    seriesPreview.value = []
  }
}

function maybeOpenWizardFromRoute() {
  if (route.query.new_video !== '1') return
  const sourceType = typeof route.query.source === 'string' && route.query.source ? route.query.source : 'prompt'
  const presetChannelId = typeof route.query.channel_id === 'string' && route.query.channel_id ? route.query.channel_id : null
  openWizard(sourceType, presetChannelId)
  router.replace({ name: 'dashboard' })
}

async function loadNotifications() {
  try {
    const response = await api.get('/notifications')
    notifications.value = response.data?.data?.notifications ?? []
  } catch {
    notifications.value = []
  }
}

async function markNotificationRead(notificationId) {
  try {
    await api.post(`/notifications/${notificationId}/read`)
    notifications.value = notifications.value.map((item) => (
      item.id === notificationId ? { ...item, is_read: true } : item
    ))
  } catch {
    // no-op
  }
}

async function markAllRead() {
  const unread = notifications.value.filter((item) => !item.is_read)
  await Promise.all(unread.map((item) => markNotificationRead(item.id)))
}

function pushToast(notification) {
  notificationToasts.value = [notification, ...notificationToasts.value].slice(0, 3)
  window.setTimeout(() => {
    notificationToasts.value = notificationToasts.value.filter((toast) => toast.id !== notification.id)
  }, 5000)
}

function subscribeWorkspaceNotifications() {
  const echo = getEcho()
  const workspaceId = mePayload.value?.workspace_id

  if (!echo || !workspaceId) return

  if (workspaceChannelName) {
    echo.leave(workspaceChannelName)
  }

  workspaceChannelName = `workspace.${workspaceId}`

  echo.private(workspaceChannelName).listen('.notification.created', (payload) => {
    const normalized = {
      id: payload.id,
      type: payload.type,
      title: payload.title,
      message: payload.message,
      payload: payload.payload,
      is_read: payload.is_read,
      created_at: payload.created_at,
    }

    notifications.value = [normalized, ...notifications.value].slice(0, 50)
    pushToast(normalized)
  })
}

function unsubscribeWorkspaceNotifications() {
  const echo = getEcho()
  if (echo && workspaceChannelName) {
    echo.leave(workspaceChannelName)
  }
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

watch(
  () => `${route.query.new_video ?? ''}|${route.query.channel_id ?? ''}|${route.query.source ?? ''}`,
  () => {
    maybeOpenWizardFromRoute()
  },
)

onMounted(async () => {
  await loadMe()
  maybeOpenWizardFromRoute()
})

onBeforeUnmount(() => {
  unsubscribeWorkspaceNotifications()
  stopDashboardPolling()
})
</script>

<template>
  <main class="fc-shell">
    <AppSidebar :user="mePayload" active-page="dashboard" :channel-count="channels.length" @logout="logout" />

    <div class="main">
      <div class="topbar">
        <div class="topbar-left">
          <span class="bc-ws">My Workspace</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">Dashboard</span>
        </div>
        <div class="topbar-right">
          <button class="btn btn-primary btn-sm" type="button" @click="openWizard">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>
            New Video
          </button>
          <button class="notif-bell-btn" type="button" title="Notifications" @click="notificationDrawerOpen = !notificationDrawerOpen">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <span v-if="unreadCount > 0" class="notif-badge">{{ unreadCount }}</span>
          </button>
        </div>
      </div>

      <div class="dashboard">

        <!-- Quick actions -->
        <div class="quick-actions">
          <button class="quick-action primary" type="button" @click="openWizard">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
            New Video
          </button>
          <button class="quick-action" type="button" @click="router.push({ name: 'series-create' })">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            New Series
          </button>
          <button class="quick-action" type="button" @click="router.push({ name: 'channels' })">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            New Channel
          </button>
          <button class="quick-action" type="button" @click="router.push({ name: 'asset-library' })">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Upload Asset
          </button>
        </div>

        <!-- Stats -->
        <div class="stats-row">
          <div class="stat-card accent-stat">
            <div class="stat-label">Videos This Month</div>
            <div class="stat-value">{{ videosThisMonth }}</div>
            <div class="stat-change">{{ videosThisMonth > 0 ? 'Pipeline active' : 'Create your first video' }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Active Channels</div>
            <div class="stat-value">{{ channels.length }}</div>
            <div class="stat-change">{{ channels.length > 0 ? 'Content lanes set up' : 'Create a channel' }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">In Queue</div>
            <div class="stat-value">{{ queuedRenders }}</div>
            <div class="stat-change">{{ queuedRenders > 0 ? 'Generation in progress' : 'Queue is empty' }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Render Minutes</div>
            <div class="stat-value">0</div>
            <div class="stat-change">of 600 min (Studio plan)</div>
          </div>
        </div>

        <!-- Continue editing strip -->
        <div v-if="totalProjects > 0" class="dash-section">
          <div class="section-hd">
            <div class="section-hd-left">
              <div class="eyebrow">In progress</div>
              <div class="section-title">Continue editing</div>
            </div>
            <button class="btn btn-ghost btn-sm" type="button" @click="router.push({ name: 'videos' })">View all →</button>
          </div>

          <div class="continue-strip">
            <article
              v-for="project in projects"
              :key="project.id"
              class="continue-card"
              tabindex="0"
              role="button"
              @click="openProject(project)"
              @keydown.enter="openProject(project)"
            >
              <div class="continue-thumb">
                <button
                  class="project-delete-btn"
                  type="button"
                  :disabled="isDeletingProject(project.id)"
                  title="Delete video"
                  @click.stop="requestDeleteProject(project.id)"
                >
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6M14 11v6"></path>
                  </svg>
                </button>
                <div class="continue-thumb-inner">
                  <div class="phone-frame">
                    <div class="phone-line"></div>
                    <div class="phone-line accent"></div>
                    <div class="phone-line"></div>
                    <div class="phone-line"></div>
                  </div>
                </div>
                <div class="continue-overlay"></div>
                <span :class="['continue-badge', mapProjectStatus(project.status).className]">{{ mapProjectStatus(project.status).label }}</span>
                <span class="aspect-badge">{{ project.aspect_ratio || '9:16' }}</span>
              </div>
              <div class="continue-body">
                <div class="continue-title">{{ project.title || `Project #${project.id}` }}</div>
                <div class="continue-meta">
                  <span v-if="channelName(project.channel_id)" class="continue-channel">{{ channelName(project.channel_id) }}</span>
                  <span v-else class="continue-meta-muted">No channel</span>
                  <span class="continue-meta-muted">{{ formatDurationLabel(project.duration_target_seconds) }}</span>
                </div>
              </div>
            </article>
          </div>

        </div>

        <!-- Empty state — only when workspace has no videos at all -->
        <div v-if="totalProjects === 0" class="empty-hero">
          <div class="empty-hero-icon">✦</div>
          <div class="empty-hero-title">Create your first video</div>
          <div class="empty-hero-text">Generate AI-powered faceless videos from a prompt, script, URL, or audio.</div>
          <div class="empty-actions">
            <button class="btn btn-primary" type="button" @click="openWizard">Generate Video</button>
            <button class="btn btn-ghost" type="button" @click="openWizard('blank')">Start from Scratch</button>
          </div>
        </div>

        <!-- Channels section -->
        <div class="dash-section">
          <div class="section-hd">
            <div class="section-hd-left">
              <div class="eyebrow">Content lanes</div>
              <div class="section-title">Channels</div>
            </div>
            <button class="btn btn-ghost btn-sm" type="button" @click="router.push({ name: 'channels' })">Manage channels →</button>
          </div>

          <div v-if="channels.length === 0" class="empty-section">
            <div class="empty-section-text">No channels yet. Channels let you organise videos by topic, brand, or platform.</div>
            <button class="btn btn-ghost btn-sm" type="button" @click="router.push({ name: 'channels' })">Create Channel →</button>
          </div>

          <template v-else>
            <div v-if="channels.length > 1" class="adaptive-note">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              <div>You have <strong>{{ channels.length }} channels</strong>. Click a channel to see its videos, series, and brand defaults.</div>
            </div>
            <div class="channel-grid">
              <div
                v-for="(ch, i) in channels"
                :key="ch.id"
                class="channel-card"
                @click="router.push({ name: 'videos', query: { channel_id: ch.id } })"
              >
                <div :class="['channel-cover', `ch-grad-${(ch.id % 5) + 1}`]">
                  <span class="channel-icon">{{ ch.name?.[0]?.toUpperCase() || '?' }}</span>
                </div>
                <div class="channel-body">
                  <div class="channel-name">{{ ch.name }}</div>
                  <div v-if="ch.description" class="channel-desc">{{ ch.description }}</div>
                  <div class="channel-stats">
                    <div class="ch-stat">
                      <div class="ch-stat-val">{{ ch.platform_targets?.[0] || 'tiktok' }}</div>
                      <div class="ch-stat-label">Platform</div>
                    </div>
                    <div class="ch-stat">
                      <div class="ch-stat-val">{{ ch.default_language || 'en' }}</div>
                      <div class="ch-stat-label">Lang</div>
                    </div>
                    <div class="ch-stat">
                      <div class="ch-stat-val">{{ ch.aspect_ratio || '9:16' }}</div>
                      <div class="ch-stat-label">Format</div>
                    </div>
                  </div>
                  <div class="channel-footer">
                    <div class="channel-kit">
                      <div class="kit-dot" :style="{ background: CH_DOT_COLORS[i % CH_DOT_COLORS.length] }"></div>
                      {{ ch.brand_kit?.name || 'Default Kit' }}
                    </div>
                    <span class="channel-action">Open →</span>
                  </div>
                </div>
              </div>
              <div class="channel-card-new" @click="router.push({ name: 'channels' })">
                <div class="channel-new-icon">+</div>
                <div>New Channel</div>
              </div>
            </div>
          </template>
        </div>

        <!-- Series section -->
        <div class="dash-section">
          <div class="section-hd">
            <div class="section-hd-left">
              <div class="eyebrow">Repeatable formats</div>
              <div class="section-title">Series</div>
            </div>
            <button class="btn btn-ghost btn-sm" type="button" @click="router.push({ name: 'series' })">View all →</button>
          </div>

          <div v-if="seriesPreview.length === 0" class="empty-section">
            <div class="empty-section-text">No series yet. Series let you build recurring content formats with shared voice, visuals, and characters.</div>
            <button class="btn btn-ghost btn-sm" type="button" @click="router.push({ name: 'series-create' })">Create Series →</button>
          </div>

          <div v-else class="series-grid">
            <div
              v-for="(s, i) in seriesPreview"
              :key="s.id"
              class="series-card"
              @click="router.push({ name: 'series-detail', params: { seriesId: s.id } })"
            >
              <div class="series-num">{{ String(i + 1).padStart(2, '0') }}</div>
              <div class="series-info">
                <div class="series-name">{{ s.name }}</div>
                <div v-if="channelNameById(s.channel_id)" class="series-channel">{{ channelNameById(s.channel_id) }}</div>
                <div class="series-meta">
                  <span class="series-pill">{{ s.episodes_count || 0 }} ep{{ s.episodes_count !== 1 ? 's' : '' }}</span>
                  <span v-if="s.tone" class="series-pill">{{ s.tone }}</span>
                  <span v-if="s.duration_target_seconds" class="series-pill">~{{ Math.round(s.duration_target_seconds / 60) }}min</span>
                </div>
              </div>
              <div class="series-actions">
                <span class="series-eps">ep {{ (s.episodes_count || 0) + 1 }} due</span>
                <button
                  class="btn btn-primary btn-sm"
                  type="button"
                  @click.stop="router.push({ name: 'series-detail', params: { seriesId: s.id } })"
                >+ Episode</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Render queue -->
        <div class="dash-section">
          <div class="section-hd">
            <div class="section-hd-left">
              <div class="eyebrow">Background work</div>
              <div class="section-title">Render Queue</div>
            </div>
            <div class="projects-toolbar">
              <label class="page-size-control">
                <span>Per page</span>
                <select class="field-input page-size-select" :value="queuePerPage" @change="changeQueuePerPage($event.target.value)">
                  <option v-for="option in queuePerPageOptions" :key="option" :value="option">{{ option }}</option>
                </select>
              </label>
              <div class="projects-summary">{{ queueFrom }}–{{ queueTo }} of {{ totalQueueRows }}</div>
            </div>
          </div>
          <div class="surface-card queue-wrap">
            <table v-if="queueRows.length > 0" class="queue-table">
              <thead>
                <tr>
                  <th>Project</th>
                  <th>Channel</th>
                  <th>Variants</th>
                  <th>Status</th>
                  <th>Progress</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in queueRows"
                  :key="row.id"
                  class="queue-row"
                  @click="openProject({ id: row.id, status: row.projectStatus })"
                >
                  <td class="queue-primary">{{ row.project }}</td>
                  <td class="queue-muted">{{ row.channel }}</td>
                  <td>{{ row.variants }}</td>
                  <td><span :class="`project-status status-${row.status} queue-status`">{{ row.statusLabel }}</span></td>
                  <td>
                    <div class="queue-progress-cell">
                      <div class="progress-bar">
                        <div :class="`progress-fill status-${row.status}`" :style="{ width: `${row.progress}%` }"></div>
                      </div>
                      <button
                        v-if="row.projectStatus === 'failed'"
                        class="queue-delete-btn"
                        type="button"
                        :disabled="isDeletingProject(row.id)"
                        title="Delete failed video"
                        @click.stop="requestDeleteProject(row.id)"
                      >
                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                          <path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6M14 11v6"></path>
                        </svg>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
            <div v-else class="queue-empty">No jobs in queue.</div>
            <div v-if="queueLastPage > 1" class="pagination-row queue-pagination-row">
              <button class="btn btn-ghost btn-sm" type="button" :disabled="queuePage <= 1" @click="goToQueuePage(queuePage - 1)">Previous</button>
              <div class="pagination-copy">Page {{ queuePage }} of {{ queueLastPage }}</div>
              <button class="btn btn-ghost btn-sm" type="button" :disabled="queuePage >= queueLastPage" @click="goToQueuePage(queuePage + 1)">Next</button>
            </div>
          </div>
        </div>

      </div>
    </div>

    <div :class="`drawer-backdrop ${notificationDrawerOpen ? 'open' : ''}`" @click="notificationDrawerOpen = false"></div>
    <aside :class="`drawer drawer-notif ${notificationDrawerOpen ? 'open' : ''}`">
      <div class="drawer-header">
        <div class="drawer-title">Notifications</div>
        <button class="mark-read-btn" type="button" @click="markAllRead">Mark all read</button>
      </div>
      <div v-if="notifications.length === 0" class="notif-empty">No notifications yet</div>
      <article
        v-for="item in notifications"
        :key="item.id"
        :class="`notif-item ${item.is_read ? '' : 'unread'}`"
        @click="!item.is_read && markNotificationRead(item.id)"
      >
        <div :class="`notif-icon-wrap ${item.type === 'success' ? 'success' : item.type === 'error' ? 'error' : 'warning'}`">
          {{ item.type === 'success' ? '✓' : item.type === 'error' ? '✕' : '•' }}
        </div>
        <div class="notif-body">
          <div class="notif-msg">{{ item.title }}</div>
          <div class="notif-time">{{ formatNotifTime(item.created_at) }}</div>
          <div class="notif-detail">{{ item.message }}</div>
        </div>
        <div v-if="!item.is_read" class="notif-unread-dot"></div>
      </article>
    </aside>

    <div class="toast-container">
      <div v-for="toast in notificationToasts" :key="toast.id" class="toast">
        <div class="toast-dot"></div>
        <div class="toast-content">
          <div class="toast-msg"><strong>{{ toast.title }}</strong> — {{ toast.message }}</div>
        </div>
      </div>
    </div>

    <NewVideoWizard ref="wizardRef" :channels="channels" />

    <div v-if="deleteConfirmProject" class="modal-overlay delete-modal-overlay" @click.self="closeDeleteConfirm">
      <div class="delete-modal">
        <div class="delete-modal-icon">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
            <path d="M12 9v4"></path>
            <path d="M12 17h.01"></path>
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
          </svg>
        </div>
        <div class="delete-modal-title">Delete Failed Video?</div>
        <div class="delete-modal-text">
          <strong>{{ deleteConfirmProject.title }}</strong> will be removed from the dashboard and queue. This action cannot be undone.
        </div>
        <div class="delete-modal-actions">
          <button class="btn btn-ghost" type="button" @click="closeDeleteConfirm">Cancel</button>
          <button
            class="btn delete-btn"
            type="button"
            :disabled="isDeletingProject(deleteConfirmProject.id)"
            @click="confirmDeleteProject"
          >
            {{ isDeletingProject(deleteConfirmProject.id) ? 'Deleting...' : 'Delete Video' }}
          </button>
        </div>
      </div>
    </div>
  </main>
</template>

<style scoped>
/* ── SHELL ─────────────────────────────────────────────── */
.fc-shell { min-height: 100vh; background: var(--color-bg-deep); color: var(--color-text-primary); font-family: "DM Sans", sans-serif; display: flex; }

/* ── MAIN ──────────────────────────────────────────────── */
.main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
.topbar { position: sticky; top: 0; z-index: 90; height: 58px; background: rgba(10,10,15,0.88); border-bottom: 1px solid var(--color-border); backdrop-filter: blur(14px); padding: 0 28px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.topbar-left { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.bc-ws { color: var(--color-text-muted); }
.bc-sep { color: var(--color-text-muted); }
.bc-page { font-weight: 600; color: var(--color-text-primary); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; }
.btn-primary { background: var(--color-accent); color: #fff; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.btn svg, .notif-bell-btn svg { display: block; }
.notif-bell-btn { position: relative; width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--color-border); color: var(--color-text-secondary); display: inline-flex; align-items: center; justify-content: center; }
.notif-badge { position: absolute; top: -5px; right: -5px; min-width: 16px; height: 16px; border-radius: 999px; background: var(--color-accent); color: #fff; font-size: 10px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; font-family: "Space Mono", monospace; }

/* ── DASHBOARD ─────────────────────────────────────────── */
.dashboard { padding: 28px; display: flex; flex-direction: column; gap: 32px; max-width: 1280px; width: 100%; flex: 1; }
.dash-section { display: flex; flex-direction: column; gap: 14px; }
.section-hd { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.section-hd-left { display: flex; flex-direction: column; gap: 2px; }
.section-hd-right { display: flex; align-items: center; gap: 12px; }
.eyebrow { font-family: "Space Mono", monospace; font-size: 10px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-accent); }
.section-title { font-size: 17px; font-weight: 700; color: var(--color-text-primary); }

/* Quick actions */
.quick-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.quick-action { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-bg-card); font-size: 13px; font-weight: 500; color: var(--color-text-secondary); cursor: pointer; transition: 0.15s; }
.quick-action:hover { border-color: var(--color-border-active); color: var(--color-text-primary); transform: translateY(-1px); }
.quick-action.primary { background: rgba(255,107,53,0.1); border-color: rgba(255,107,53,0.25); color: var(--color-accent); font-weight: 600; }
.quick-action.primary:hover { background: rgba(255,107,53,0.18); }
.quick-action:disabled { opacity: 0.45; cursor: default; transform: none; }

/* Stats */
.stats-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.stat-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 18px 20px; }
.stat-card.accent-stat { background: rgba(255,107,53,0.06); border-color: rgba(255,107,53,0.2); }
.stat-card.accent-stat .stat-value { color: var(--color-accent); }
.stat-label { font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-text-muted); font-family: "Space Mono", monospace; margin-bottom: 8px; }
.stat-value { font-family: "Space Mono", monospace; font-size: 28px; font-weight: 700; }
.stat-change { margin-top: 4px; font-size: 12px; color: var(--color-text-secondary); }

/* Continue editing strip */
.continue-strip { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 4px; padding-top: 6px; margin-top: -6px; }
.continue-card { flex-shrink: 0; width: 190px; background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; cursor: pointer; transition: 0.2s; text-align: left; }
.continue-card:hover { border-color: var(--color-border-active); transform: translateY(-2px); }
.new-continue-card { display: flex; align-items: center; justify-content: center; border-style: dashed; color: var(--color-text-muted); min-height: 168px; }
.new-continue-card:hover { border-color: rgba(255,107,53,0.4); color: var(--color-accent); }
.new-continue-inner { text-align: center; }
.new-continue-plus { font-size: 28px; margin-bottom: 6px; }
.new-continue-label { font-size: 12px; font-weight: 600; }
.continue-thumb { height: 108px; position: relative; overflow: hidden; background: linear-gradient(135deg, #141729, #1a223d); }
.continue-thumb-inner { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
.continue-overlay { position: absolute; inset: auto 0 0; height: 50%; background: linear-gradient(180deg, transparent, rgba(0,0,0,0.45)); }
.continue-badge { position: absolute; bottom: 8px; left: 8px; padding: 2px 7px; border-radius: 4px; font-size: 9px; font-weight: 700; font-family: "Space Mono", monospace; }
.continue-body { padding: 10px 12px; }
.continue-title { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.continue-meta { font-size: 10px; color: var(--color-text-muted); margin-top: 3px; display: flex; gap: 6px; align-items: center; }
.continue-channel { color: var(--color-accent); font-weight: 500; }
.continue-meta-muted { color: var(--color-text-muted); }

/* Delete button (shared by continue strip and queue) */
.project-delete-btn, .queue-delete-btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(248,113,113,0.28); color: #fca5a5; background: rgba(10,10,16,0.74); transition: 0.18s ease; opacity: 0; pointer-events: none; }
.project-delete-btn:hover, .queue-delete-btn:hover { border-color: rgba(248,113,113,0.5); color: #f87171; background: rgba(32,10,14,0.92); }
.project-delete-btn:disabled, .queue-delete-btn:disabled { opacity: 0.5; cursor: default; }
.project-delete-btn { position: absolute; top: 8px; left: 8px; z-index: 2; width: 26px; height: 26px; border-radius: 7px; }
.continue-card:hover .project-delete-btn,
.continue-card:focus-within .project-delete-btn,
.queue-table tr:hover .queue-delete-btn,
.queue-table tr:focus-within .queue-delete-btn { opacity: 1; pointer-events: auto; }

/* Aspect / phone badges */
.aspect-badge { position: absolute; top: 8px; right: 8px; z-index: 1; padding: 3px 7px; border-radius: 4px; background: rgba(0,0,0,0.55); color: var(--color-text-primary); font-family: "Space Mono", monospace; font-size: 9px; }
.phone-frame { width: 52px; height: 94px; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); border: 2px solid rgba(255,255,255,0.14); border-radius: 10px; display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 8px; }
.phone-line { height: 3px; border-radius: 999px; background: rgba(255,255,255,0.12); }
.phone-line.accent { background: var(--color-accent); opacity: 0.65; }

/* Status badges (reused on continue strip) */
.project-status { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: 700; font-family: "Space Mono", monospace; }
.status-rendered { background: rgba(52,211,153,0.12); color: #34d399; }
.status-draft { background: rgba(251,191,36,0.12); color: #fbbf24; }
.status-rendering { background: rgba(96,165,250,0.12); color: #60a5fa; }
.status-failed { background: rgba(248,113,113,0.12); color: #f87171; }

/* Channel filter tabs */
.channel-filter-bar { display: flex; gap: 6px; flex-wrap: wrap; }
.channel-filter-tab { padding: 5px 14px; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.15s ease; white-space: nowrap; }
.channel-filter-tab:hover { border-color: rgba(255,107,53,0.35); color: var(--color-text-primary); }
.channel-filter-tab.active { border-color: var(--color-accent); background: rgba(255,107,53,0.12); color: var(--color-accent); font-weight: 600; }

/* Channel cards */
.adaptive-note { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; background: rgba(255,107,53,0.05); border: 1px solid rgba(255,107,53,0.15); border-radius: 8px; font-size: 12px; color: var(--color-text-secondary); margin-bottom: 14px; }
.adaptive-note svg { flex-shrink: 0; margin-top: 1px; color: var(--color-accent); }
.adaptive-note strong { color: var(--color-text-primary); }
.channel-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
.channel-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 14px; overflow: hidden; cursor: pointer; transition: 0.2s; display: flex; flex-direction: column; }
.channel-card:hover { border-color: var(--color-border-active); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,0.3); }
.channel-cover { height: 80px; position: relative; display: flex; align-items: flex-end; padding: 12px; }
.ch-grad-1 { background: linear-gradient(135deg, #1a0005, #3d0010); }
.ch-grad-2 { background: linear-gradient(135deg, #0a1a3d, #1a0a2e); }
.ch-grad-3 { background: linear-gradient(135deg, #0a1a0a, #1a2e1a); }
.ch-grad-4 { background: linear-gradient(135deg, #1a1400, #2e2400); }
.ch-grad-5 { background: linear-gradient(135deg, #0a0e1a, #1a2030); }
.channel-icon { font-size: 22px; font-weight: 700; color: rgba(255,255,255,0.3); font-family: "Space Mono", monospace; position: relative; z-index: 1; }
.channel-body { padding: 14px 16px 16px; flex: 1; display: flex; flex-direction: column; gap: 10px; }
.channel-name { font-size: 15px; font-weight: 700; color: var(--color-text-primary); }
.channel-desc { font-size: 12px; color: var(--color-text-secondary); line-height: 1.5; flex: 1; }
.channel-stats { display: flex; gap: 12px; }
.ch-stat { display: flex; flex-direction: column; gap: 2px; }
.ch-stat-val { font-size: 12px; font-weight: 700; color: var(--color-text-primary); font-family: "Space Mono", monospace; text-transform: uppercase; }
.ch-stat-label { font-size: 9px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.06em; font-family: "Space Mono", monospace; }
.channel-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 10px; border-top: 1px solid var(--color-border); }
.channel-kit { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--color-text-muted); min-width: 0; }
.kit-dot { width: 8px; height: 8px; border-radius: 999px; flex-shrink: 0; }
.channel-action { font-size: 12px; font-weight: 600; color: var(--color-accent); flex-shrink: 0; }
.channel-card-new { background: transparent; border: 1px dashed var(--color-border); border-radius: 14px; min-height: 200px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; cursor: pointer; transition: 0.15s; color: var(--color-text-muted); font-size: 13px; font-weight: 500; }
.channel-card-new:hover { border-color: rgba(255,107,53,0.4); color: var(--color-accent); }
.channel-new-icon { font-size: 24px; }

/* Empty states */
.empty-hero { text-align: center; padding: 56px 24px; background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 16px; }
.empty-hero-icon { font-size: 36px; color: var(--color-accent); margin-bottom: 14px; }
.empty-hero-title { font-size: 20px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 8px; }
.empty-hero-text { font-size: 13px; color: var(--color-text-muted); max-width: 420px; margin: 0 auto 20px; line-height: 1.6; }
.empty-actions { display: flex; gap: 8px; justify-content: center; }
.empty-section { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 20px 22px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.empty-section-text { font-size: 13px; color: var(--color-text-muted); flex: 1; }

/* Series cards */
.series-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
.series-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 16px 18px; cursor: pointer; transition: 0.2s; display: flex; gap: 14px; align-items: flex-start; }
.series-card:hover { border-color: var(--color-border-active); transform: translateY(-1px); }
.series-num { font-family: "Space Mono", monospace; font-size: 22px; font-weight: 700; color: var(--color-border-active); flex-shrink: 0; width: 36px; text-align: center; padding-top: 2px; }
.series-info { flex: 1; min-width: 0; }
.series-name { font-size: 14px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.series-channel { font-size: 11px; color: var(--color-accent); font-weight: 500; margin-bottom: 6px; }
.series-meta { display: flex; gap: 8px; flex-wrap: wrap; }
.series-pill { font-size: 10px; font-family: "Space Mono", monospace; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 4px; padding: 2px 7px; color: var(--color-text-muted); }
.series-actions { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; flex-shrink: 0; }
.series-eps { font-size: 11px; color: var(--color-text-muted); font-family: "Space Mono", monospace; white-space: nowrap; }

/* Projects / queue toolbar */
.projects-toolbar { display: flex; align-items: center; gap: 12px; }
.page-size-control { display: inline-flex; align-items: center; gap: 8px; color: var(--color-text-muted); font-size: 12px; white-space: nowrap; }
.page-size-select { min-width: 72px; padding: 6px 10px; }
.projects-summary { color: var(--color-text-muted); font-size: 12px; }

/* Queue */
.surface-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; }
.queue-wrap { overflow: hidden; }
.queue-table { width: 100%; border-collapse: collapse; }
.queue-table th, .queue-table td { padding: 11px 16px; text-align: left; border-bottom: 1px solid var(--color-border); font-size: 13px; }
.queue-table th { font-size: 10px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.08em; font-family: "Space Mono", monospace; }
.queue-table tbody tr:last-child td { border-bottom: none; }
.queue-table tr:hover td { background: rgba(255,255,255,0.01); }
.queue-primary { font-weight: 500; }
.queue-muted { color: var(--color-text-muted); }
.queue-status { margin-top: 0; }
.progress-bar { width: 84px; height: 4px; background: var(--color-bg-elevated); border-radius: 999px; overflow: hidden; }
.queue-progress-cell { display: inline-flex; align-items: center; gap: 10px; }
.queue-delete-btn { width: 24px; height: 24px; border-radius: 7px; }
.progress-fill { height: 100%; }
.progress-fill.status-rendering { background: #60a5fa; }
.progress-fill.status-rendered { background: #34d399; }
.progress-fill.status-failed { background: #f87171; }
.progress-fill.status-queued { background: var(--color-bg-elevated); }
.queue-empty { padding: 18px; color: var(--color-text-muted); font-size: 13px; }

/* Pagination */
.pagination-row { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
.pagination-copy { min-width: 90px; text-align: center; color: var(--color-text-muted); font-size: 12px; }

/* Badge shared */
.channel-badge { display: inline-block; padding: 2px 7px; border-radius: 4px; font-size: 10px; font-weight: 600; background: rgba(255,107,53,0.12); color: var(--color-accent); border: 1px solid rgba(255,107,53,0.2); }
.drawer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); opacity: 0; pointer-events: none; transition: opacity 0.2s ease; z-index: 140; }
.drawer-backdrop.open { opacity: 1; pointer-events: auto; }
.drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 380px; max-width: calc(100vw - 20px); background: var(--color-bg-panel); border-left: 1px solid var(--color-border); transform: translateX(100%); transition: transform 0.2s ease; z-index: 150; overflow-y: auto; }
.drawer.open { transform: translateX(0); }
.drawer-header { padding: 16px; border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center; }
.drawer-title { font-size: 16px; font-weight: 600; }
.mark-read-btn { font-size: 12px; color: var(--color-accent); }
.notif-empty { padding: 18px 16px; color: var(--color-text-muted); font-size: 13px; }
.notif-item { padding: 14px 16px; border-bottom: 1px solid var(--color-border); display: flex; gap: 10px; align-items: flex-start; cursor: pointer; }
.notif-item.unread { background: rgba(255,255,255,0.01); }
.notif-icon-wrap { width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
.notif-icon-wrap.success { background: rgba(52, 211, 153, 0.12); color: #34d399; }
.notif-icon-wrap.warning { background: rgba(251, 191, 36, 0.12); color: #fbbf24; }
.notif-icon-wrap.error { background: rgba(248, 113, 113, 0.12); color: #f87171; }
.notif-body { flex: 1; }
.notif-msg { font-size: 13px; color: var(--color-text-primary); }
.notif-time { margin-top: 4px; font-size: 11px; color: var(--color-text-muted); }
.notif-detail { margin-top: 4px; font-size: 12px; color: var(--color-text-secondary); }
.notif-unread-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--color-accent); margin-top: 6px; }
.toast-container { position: fixed; right: 16px; bottom: 16px; display: grid; gap: 10px; z-index: 170; }
.toast { min-width: 300px; max-width: 420px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-left: 3px solid var(--color-accent); border-radius: 10px; padding: 10px 12px; display: flex; gap: 10px; box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35); }
.toast-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-accent); margin-top: 6px; }
.toast-content { font-size: 12px; color: var(--color-text-secondary); }
.toast-msg strong { color: var(--color-text-primary); }
/* ── Modals ─────────────────────────────────────────────────────── */
.modal-overlay { position: fixed; inset: 0; z-index: 180; background: rgba(0,0,0,0.68); display: flex; align-items: center; justify-content: center; padding: 16px; }
.modal { width: min(680px,calc(100vw - 32px)); max-height: 86vh; overflow-y: auto; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 28px; box-shadow: 0 30px 80px rgba(0,0,0,0.5); }
.modal-title { font-size: 20px; font-weight: 700; color: var(--color-text-primary); }
.modal-subtitle { margin-top: 4px; margin-bottom: 22px; font-size: 13px; color: var(--color-text-muted); }
.delete-modal-overlay { z-index: 190; }
.delete-modal { width: min(420px, 100%); background: linear-gradient(180deg, rgba(255,255,255,0.018), transparent 100%), var(--color-bg-panel); border: 1px solid rgba(248,113,113,0.18); border-radius: 16px; padding: 22px; box-shadow: 0 24px 48px rgba(0, 0, 0, 0.42); }
.delete-modal-icon { width: 42px; height: 42px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; background: rgba(248,113,113,0.12); color: #f87171; border: 1px solid rgba(248,113,113,0.18); }
.delete-modal-title { margin-top: 16px; font-size: 18px; font-weight: 600; }
.delete-modal-text { margin-top: 10px; font-size: 13px; line-height: 1.6; color: var(--color-text-secondary); }
.delete-modal-text strong { color: var(--color-text-primary); font-weight: 600; }
.delete-modal-actions { margin-top: 22px; display: flex; justify-content: flex-end; gap: 8px; }
.delete-btn { background: #ef4444; color: #fff; }
.delete-btn:disabled { opacity: 0.6; cursor: default; }
.modal-error { padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(248,113,113,0.25); color: #f87171; font-size: 12px; background: rgba(248,113,113,0.1); }
.modal-actions { margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: 10px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.settings-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field-input { width: 100%; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); padding: 9px 12px; font-size: 13px; }
.textarea { min-height: 90px; resize: vertical; }
.upload-zone { border: 1px dashed var(--color-border); border-radius: 8px; padding: 20px 16px; color: var(--color-text-muted); font-size: 13px; text-align: center; background: var(--color-bg-card); }
.upload-zone-input { display: block; cursor: pointer; transition: 0.15s ease; }
.upload-zone-input:hover { border-color: rgba(255,107,53,0.35); color: var(--color-text-secondary); }
.hidden-file-input { display: none; }
.input-group { margin-top: 16px; }
.input-label { font-size: 12px; color: var(--color-text-secondary); margin-bottom: 6px; display: block; }
.input-label-wrap { display: grid; gap: 6px; }
.mt { margin-top: 14px; }


@media (max-width: 980px) { .stats-row { grid-template-columns: 1fr 1fr; } .form-grid { grid-template-columns: 1fr; } .section-header { align-items: flex-start; flex-direction: column; } .projects-toolbar { margin-left: 0; flex-wrap: wrap; } .pagination-row { justify-content: space-between; } }
@media (max-width: 800px) { .sidebar { display: none; } .main { margin-left: 0; } .topbar { height: auto; padding: 12px; gap: 10px; align-items: flex-start; flex-direction: column; } .stats-row { grid-template-columns: 1fr; } .empty-actions { flex-direction: column; } .projects-toolbar { width: 100%; justify-content: space-between; } .projects-summary { width: 100%; } }
</style>
