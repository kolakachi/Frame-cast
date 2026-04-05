<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import { getEcho } from '../services/echo'

const router = useRouter()
const authStore = useAuthStore()
const mePayload = ref(null)
const showUserPopover = ref(false)

const notificationDrawerOpen = ref(false)
const notifications = ref([])
const notificationToasts = ref([])
let workspaceChannelName = null
let dashboardPollTimer = null

const projects = ref([])
const queueRows = ref([])
const deletingProjectIds = ref([])
const deleteConfirmProject = ref(null)

const showCreateModal = ref(false)
const createState = ref('idle')
const createError = ref('')
const activeSourceType = ref('script')
const languageSelections = ref(['en'])
const platformTarget = ref('tiktok')
const aspectRatio = ref('9:16')
const channelId = ref('')
const brandKitId = ref('')
const templateId = ref('')
const tone = ref('')
const contentGoal = ref('')
const title = ref('')
const durationTargetSeconds = ref('')

const promptText = ref('')
const scriptText = ref('')
const urlText = ref('')
const csvText = ref('')
const productName = ref('')
const productDescription = ref('')
const productUrl = ref('')
const targetAudience = ref('')
const audioPath = ref('')
const videoPath = ref('')

const unreadCount = computed(() => notifications.value.filter((item) => !item.is_read).length)
const videosThisMonth = computed(() => projects.value.length)
const queuedRenders = computed(() => queueRows.value.filter((row) => row.status === 'queued' || row.status === 'rendering').length)
const activeChannels = computed(() => {
  const ids = new Set(projects.value.map((project) => project.channel_id).filter(Boolean))
  return ids.size
})

const sourceChips = [
  { key: 'script', label: 'Script' },
  { key: 'url', label: 'URL/Article' },
  { key: 'prompt', label: 'Prompt' },
  { key: 'csv_topic', label: 'CSV Topics' },
  { key: 'product_description', label: 'Product Description' },
  { key: 'audio_upload', label: 'Existing Audio' },
  { key: 'video_upload', label: 'Existing Video' },
]

function toggleLanguage(language) {
  if (languageSelections.value.includes(language)) {
    languageSelections.value = languageSelections.value.filter((item) => item !== language)
    return
  }

  languageSelections.value = [...languageSelections.value, language]
}

function resetCreateModal() {
  createState.value = 'idle'
  createError.value = ''
}

function openCreateModal() {
  showCreateModal.value = true
  resetCreateModal()
}

function closeCreateModal() {
  if (createState.value === 'loading') return
  showCreateModal.value = false
}

function buildSourceContentRaw() {
  if (activeSourceType.value === 'script') return scriptText.value.trim()
  if (activeSourceType.value === 'url') return urlText.value.trim()
  if (activeSourceType.value === 'prompt') return promptText.value.trim()
  if (activeSourceType.value === 'csv_topic') return csvText.value.trim()
  if (activeSourceType.value === 'audio_upload') return audioPath.value.trim()
  if (activeSourceType.value === 'video_upload') return videoPath.value.trim()

  return [
    `Product Name: ${productName.value.trim()}`,
    `Product Description: ${productDescription.value.trim()}`,
    productUrl.value.trim() ? `Product URL: ${productUrl.value.trim()}` : '',
    targetAudience.value.trim() ? `Target Audience: ${targetAudience.value.trim()}` : '',
  ].filter(Boolean).join('\n')
}

async function submitProject() {
  createState.value = 'loading'
  createError.value = ''

  const sourceContentRaw = buildSourceContentRaw()

  if (!sourceContentRaw) {
    createState.value = 'error'
    createError.value = 'Source content is required.'
    return
  }

  try {
    const response = await api.post('/projects', {
      source_type: activeSourceType.value,
      source_content_raw: sourceContentRaw,
      languages: languageSelections.value,
      platform_target: platformTarget.value,
      aspect_ratio: aspectRatio.value,
      ...(channelId.value ? { channel_id: Number(channelId.value) } : {}),
      ...(templateId.value ? { template_id: Number(templateId.value) } : {}),
      ...(brandKitId.value ? { brand_kit_id: Number(brandKitId.value) } : {}),
      ...(contentGoal.value ? { content_goal: contentGoal.value } : {}),
      ...(tone.value ? { tone: tone.value } : {}),
      ...(title.value ? { title: title.value } : {}),
      ...(durationTargetSeconds.value ? { duration_target_seconds: Number(durationTargetSeconds.value) } : {}),
    })

    const projectId = response.data?.data?.project?.id
    showCreateModal.value = false
    createState.value = 'success'

    if (projectId) {
      router.push({ name: 'generation-progress', params: { projectId } })
    }
  } catch (error) {
    createState.value = 'error'
    createError.value = error.response?.data?.error?.message ?? 'Project creation failed.'
  }
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

function queueRowsFromProjects(projectList) {
  return projectList
    .filter((project) => ['generating', 'ready_for_review', 'failed'].includes(project.status))
    .slice(0, 6)
    .map((project) => {
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
        channel: project.channel_id ? `Channel #${project.channel_id}` : 'No channel',
        variants: project.primary_language ? 1 : 0,
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
    await loadProjects()
  } catch {
    // no-op
  } finally {
    deletingProjectIds.value = deletingProjectIds.value.filter((id) => id !== projectId)
  }
}

async function loadProjects() {
  try {
    const response = await api.get('/projects')
    const items = response.data?.data?.projects ?? []
    projects.value = items
    queueRows.value = queueRowsFromProjects(items)
  } catch {
    projects.value = []
    queueRows.value = []
  }
}

function startDashboardPolling() {
  if (dashboardPollTimer) {
    window.clearInterval(dashboardPollTimer)
  }

  dashboardPollTimer = window.setInterval(() => {
    loadProjects()
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
    await loadProjects()
    await loadNotifications()
    subscribeWorkspaceNotifications()
    startDashboardPolling()
  } catch {
    mePayload.value = null
  }
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

onMounted(() => {
  loadMe()
})

onBeforeUnmount(() => {
  unsubscribeWorkspaceNotifications()
  stopDashboardPolling()
})
</script>

<template>
  <main class="fc-shell">
    <nav class="sidebar">
      <div class="sidebar-logo">F</div>
      <div class="sidebar-nav">
        <div class="nav-item active">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <rect x="3" y="3" width="7" height="7" rx="1"></rect>
            <rect x="14" y="3" width="7" height="7" rx="1"></rect>
            <rect x="3" y="14" width="7" height="7" rx="1"></rect>
            <rect x="14" y="14" width="7" height="7" rx="1"></rect>
          </svg>
          <span class="tooltip">Dashboard</span>
        </div>
        <div class="nav-item">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"></path>
          </svg>
          <span class="tooltip">Asset Library</span>
        </div>
        <div class="nav-item">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          </svg>
          <span class="tooltip">Settings</span>
        </div>
      </div>
      <div class="sidebar-bottom">
        <button class="avatar" type="button" @click="showUserPopover = !showUserPopover">
          {{ mePayload?.name?.[0] || 'U' }}
        </button>
        <div v-if="showUserPopover" class="user-popover">
          <div class="user-popover-name">{{ mePayload?.name || 'User' }}</div>
          <div class="user-popover-email">{{ mePayload?.email || '—' }}</div>
          <div class="user-popover-divider"></div>
          <button class="user-popover-action" type="button" @click="logout">Log out</button>
        </div>
      </div>
    </nav>

    <div class="main">
      <div class="topbar">
        <div class="topbar-left">
          <div class="topbar-title">Dashboard</div>
          <div class="topbar-breadcrumb"><span>Workspace</span> · {{ videosThisMonth }} videos</div>
        </div>
        <div class="topbar-right">
          <button class="btn btn-ghost" type="button" @click="openCreateModal">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M12 5v14M5 12h14"></path>
            </svg>
            New Video
          </button>
          <button class="btn btn-primary" type="button">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"></path>
            </svg>
            Export
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
        <div class="stats-row">
          <div class="stat-card">
            <div class="stat-label">Videos This Month</div>
            <div class="stat-value">{{ videosThisMonth }}</div>
            <div class="stat-change">{{ videosThisMonth > 0 ? 'Recent pipeline activity detected' : 'No videos yet' }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Render Minutes Used</div>
            <div class="stat-value">0</div>
            <div class="stat-change">of 600 min (Studio plan)</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Active Channels</div>
            <div class="stat-value">{{ activeChannels }}</div>
            <div class="stat-change">{{ activeChannels > 0 ? 'Used by existing projects' : 'Create your first channel' }}</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Queued Renders</div>
            <div class="stat-value">{{ queuedRenders }}</div>
            <div class="stat-change">{{ queuedRenders > 0 ? 'Generation in progress' : 'Queue is empty' }}</div>
          </div>
        </div>

        <div class="section-header">
          <div class="section-title">Recent Projects</div>
        </div>

        <div class="projects-grid">
          <button class="new-project-card" type="button" @click="openCreateModal">
            <div style="text-align:center;">
              <div style="font-size:30px; margin-bottom:8px;">+</div>
              <div style="font-size:13px; font-weight:600;">New Video</div>
            </div>
          </button>

          <article
            v-for="project in projects.slice(0, 4)"
            :key="project.id"
            class="project-card"
            @click="router.push({ name: 'project-editor', params: { projectId: project.id } })"
            @keydown.enter="router.push({ name: 'project-editor', params: { projectId: project.id } })"
            @keydown.space.prevent="router.push({ name: 'project-editor', params: { projectId: project.id } })"
            tabindex="0"
            role="button"
          >
            <div class="project-thumb">
              <button
                v-if="project.status === 'failed'"
                class="project-delete-btn"
                type="button"
                :disabled="isDeletingProject(project.id)"
                title="Delete failed video"
                @click.stop="requestDeleteProject(project.id)"
              >
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                  <path d="M3 6h18"></path>
                  <path d="M8 6V4h8v2"></path>
                  <path d="M19 6l-1 14H6L5 6"></path>
                  <path d="M10 11v6M14 11v6"></path>
                </svg>
              </button>
              <div class="phone-frame">
                <div class="phone-line"></div>
                <div class="phone-line accent"></div>
                <div class="phone-line"></div>
                <div class="phone-line"></div>
              </div>
              <span class="aspect-badge">{{ project.aspect_ratio || '9:16' }}</span>
              <span class="duration-badge">{{ formatDurationLabel(project.duration_target_seconds) }}</span>
            </div>
            <div class="project-info">
              <div class="project-name">{{ project.title || `Project #${project.id}` }}</div>
              <div class="project-meta">
                <span>{{ project.channel_id ? `Channel #${project.channel_id}` : 'No channel' }}</span>
                <span>{{ project.primary_language || 'en' }}</span>
              </div>
              <div :class="`project-status ${mapProjectStatus(project.status).className}`">{{ mapProjectStatus(project.status).label }}</div>
            </div>
          </article>
        </div>

        <div v-if="projects.length === 0" class="empty-row">
          <p>No videos yet. Create your first project or import CSV topics.</p>
          <div class="empty-actions">
            <button class="btn btn-ghost btn-sm" type="button" @click="openCreateModal">Create New Video</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="activeSourceType = 'csv_topic'; openCreateModal()">Import CSV Topics</button>
          </div>
        </div>

        <div class="surface-card queue-wrap">
          <div class="section-header queue-header">
            <div class="section-title">Render Queue</div>
          </div>
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
              <tr v-for="row in queueRows" :key="row.id">
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
                        <path d="M3 6h18"></path>
                        <path d="M8 6V4h8v2"></path>
                        <path d="M19 6l-1 14H6L5 6"></path>
                        <path d="M10 11v6M14 11v6"></path>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-else class="queue-empty">No jobs in queue.</div>
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

    <div v-if="showCreateModal" class="modal-overlay" @click.self="closeCreateModal">
      <div class="modal">
        <div class="modal-title">Create New Video</div>
        <div class="modal-subtitle">Choose source type, then generate.</div>

        <div class="source-type-chips">
          <button
            v-for="chip in sourceChips"
            :key="chip.key"
            :class="`chip ${activeSourceType === chip.key ? 'selected' : ''}`"
            type="button"
            @click="activeSourceType = chip.key"
          >
            {{ chip.label }}
          </button>
        </div>

        <div class="source-panel" :class="activeSourceType === 'script' ? 'active' : ''">
          <label><span>Script</span><textarea v-model="scriptText" class="field-input textarea"></textarea></label>
        </div>

        <div class="source-panel" :class="activeSourceType === 'url' ? 'active' : ''">
          <label><span>Article URL</span><input v-model="urlText" class="field-input" type="text"></label>
        </div>

        <div class="source-panel" :class="activeSourceType === 'prompt' ? 'active' : ''">
          <label><span>Prompt</span><textarea v-model="promptText" class="field-input textarea"></textarea></label>
        </div>

        <div class="source-panel" :class="activeSourceType === 'csv_topic' ? 'active' : ''">
          <label><span>CSV Topics</span><textarea v-model="csvText" class="field-input textarea" placeholder="topic,angle,hook"></textarea></label>
        </div>

        <div class="source-panel" :class="activeSourceType === 'product_description' ? 'active' : ''">
          <div class="form-grid">
            <label><span>Product name</span><input v-model="productName" class="field-input" type="text"></label>
            <label><span>Product URL</span><input v-model="productUrl" class="field-input" type="text"></label>
          </div>
          <label class="mt"><span>Product description</span><textarea v-model="productDescription" class="field-input textarea"></textarea></label>
          <label class="mt"><span>Target audience</span><input v-model="targetAudience" class="field-input" type="text"></label>
        </div>

        <div class="source-panel" :class="activeSourceType === 'audio_upload' ? 'active' : ''">
          <div class="upload-zone">Upload audio file (UI shell)</div>
          <label class="mt"><span>Storage path / URL</span><input v-model="audioPath" class="field-input" type="text"></label>
        </div>

        <div class="source-panel" :class="activeSourceType === 'video_upload' ? 'active' : ''">
          <div class="upload-zone">Upload video file (UI shell)</div>
          <label class="mt"><span>Storage path / URL</span><input v-model="videoPath" class="field-input" type="text"></label>
        </div>

        <div class="form-grid mt">
          <label><span>Aspect ratio</span>
            <select v-model="aspectRatio" class="field-input"><option value="9:16">9:16</option><option value="1:1">1:1</option><option value="16:9">16:9</option></select>
          </label>
          <label><span>Platform</span>
            <select v-model="platformTarget" class="field-input"><option value="tiktok">TikTok</option><option value="reels">Instagram Reels</option><option value="shorts">YouTube Shorts</option></select>
          </label>
        </div>

        <div class="form-grid mt">
          <label><span>Channel ID (optional for now)</span><input v-model="channelId" class="field-input" type="text"></label>
          <label><span>Brand Kit ID</span><input v-model="brandKitId" class="field-input" type="text"></label>
        </div>

        <div class="form-grid mt">
          <label><span>Template ID</span><input v-model="templateId" class="field-input" type="text"></label>
          <label><span>Duration target (sec)</span><input v-model="durationTargetSeconds" class="field-input" type="text"></label>
        </div>

        <div class="form-grid mt">
          <label><span>Tone</span><input v-model="tone" class="field-input" type="text"></label>
          <label><span>Content goal</span><input v-model="contentGoal" class="field-input" type="text"></label>
        </div>

        <label class="mt"><span>Title</span><input v-model="title" class="field-input" type="text"></label>

        <div class="langs mt">
          <span>Languages</span>
          <div class="lang-row">
            <button :class="`chip ${languageSelections.includes('en') ? 'selected' : ''}`" type="button" @click="toggleLanguage('en')">EN</button>
            <button :class="`chip ${languageSelections.includes('es') ? 'selected' : ''}`" type="button" @click="toggleLanguage('es')">ES</button>
            <button :class="`chip ${languageSelections.includes('fr') ? 'selected' : ''}`" type="button" @click="toggleLanguage('fr')">FR</button>
          </div>
        </div>

        <div v-if="createError" class="modal-error mt">{{ createError }}</div>

        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="closeCreateModal">Cancel</button>
          <button class="btn btn-primary" :disabled="createState === 'loading'" type="button" @click="submitProject">
            {{ createState === 'loading' ? 'Generating...' : 'Generate' }}
          </button>
        </div>
      </div>
    </div>

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
.fc-shell { min-height: 100vh; background: radial-gradient(circle at top right, rgba(255, 107, 53, 0.09), transparent 28%), radial-gradient(circle at bottom left, rgba(96, 165, 250, 0.08), transparent 24%), var(--color-bg-deep); color: var(--color-text-primary); font-family: "DM Sans", sans-serif; }
.sidebar { position: fixed; inset: 0 auto 0 0; width: 72px; background: rgba(17, 17, 24, 0.96); border-right: 1px solid var(--color-border); backdrop-filter: blur(12px); display: flex; flex-direction: column; align-items: center; padding: 16px 0; z-index: 100; }
.sidebar-logo { width: 40px; height: 40px; border-radius: 10px; background: linear-gradient(135deg, var(--color-accent), #ff9b72); display: flex; align-items: center; justify-content: center; color: #fff; font-family: "Space Mono", monospace; font-weight: 700; margin-bottom: 28px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 8px; flex: 1; }
.nav-item { width: 44px; height: 44px; border-radius: 10px; color: var(--color-text-muted); display: flex; align-items: center; justify-content: center; cursor: pointer; position: relative; transition: 0.2s ease; }
.nav-item:hover { color: var(--color-text-secondary); background: var(--color-bg-card); }
.nav-item.active { color: var(--color-accent); background: rgba(255, 107, 53, 0.14); box-shadow: inset 0 0 0 1px rgba(255, 107, 53, 0.18); }
.tooltip { position: absolute; left: 58px; top: 50%; transform: translateY(-50%); opacity: 0; pointer-events: none; background: var(--color-bg-elevated); color: var(--color-text-primary); font-size: 12px; padding: 5px 10px; border-radius: 6px; border: 1px solid var(--color-border); white-space: nowrap; transition: opacity 0.15s ease; }
.nav-item:hover .tooltip { opacity: 1; }
.btn svg,
.nav-item svg,
.notif-bell-btn svg {
  display: block;
}
.sidebar-bottom { position: relative; }
.avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #2a3a70, #7d3cff); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; cursor: pointer; }
.user-popover { position: absolute; bottom: 52px; left: 12px; width: 200px; background: var(--color-bg-elevated); border: 1px solid var(--color-border-active); border-radius: 10px; padding: 12px; z-index: 200; box-shadow: 0 8px 24px rgba(0,0,0,0.4); }
.user-popover-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.user-popover-email { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.user-popover-divider { border-top: 1px solid var(--color-border); margin: 10px 0; }
.user-popover-action { width: 100%; text-align: left; color: #f87171; font-size: 13px; cursor: pointer; }
.main { margin-left: 72px; min-height: 100vh; }
.topbar { position: sticky; top: 0; z-index: 90; height: 64px; background: rgba(17, 17, 24, 0.88); border-bottom: 1px solid var(--color-border); backdrop-filter: blur(14px); padding: 0 24px; display: flex; align-items: center; justify-content: space-between; }
.topbar-left { display: flex; align-items: center; gap: 18px; }
.topbar-title { font-size: 16px; font-weight: 600; }
.topbar-breadcrumb { color: var(--color-text-muted); font-size: 13px; }
.topbar-breadcrumb span { color: var(--color-text-secondary); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; }
.btn-primary { background: var(--color-accent); color: #fff; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.notif-bell-btn { position: relative; width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--color-border); color: var(--color-text-secondary); display: inline-flex; align-items: center; justify-content: center; }
.notif-badge { position: absolute; top: -5px; right: -5px; min-width: 16px; height: 16px; border-radius: 999px; background: var(--color-accent); color: #fff; font-size: 10px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; font-family: "Space Mono", monospace; }
.dashboard { padding: 24px; }
.stats-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card, .surface-card { background: linear-gradient(180deg, rgba(255, 255, 255, 0.015), transparent 100%), var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35); }
.stat-card { padding: 20px; }
.stat-label { margin-bottom: 8px; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-text-muted); }
.stat-value { font-family: "Space Mono", monospace; font-size: 28px; font-weight: 700; }
.stat-change { margin-top: 4px; font-size: 12px; color: var(--color-text-secondary); }
.section-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.section-title { font-size: 16px; font-weight: 600; }
.projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; margin-bottom: 16px; }
.new-project-card { min-height: 222px; border: 1px dashed var(--color-border); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--color-text-muted); cursor: pointer; background: var(--color-bg-card); }
.project-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; transition: 0.22s ease; text-align: left; }
.project-card:hover { transform: translateY(-2px); border-color: var(--color-border-active); }
.project-thumb { height: 154px; position: relative; overflow: hidden; background: linear-gradient(135deg, #141729, #1a223d); }
.project-thumb::after { content: ""; position: absolute; inset: auto 0 0; height: 50%; background: linear-gradient(180deg, transparent, rgba(0,0,0,0.35)); }
.project-delete-btn,.queue-delete-btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid rgba(248,113,113,0.28); color: #fca5a5; background: rgba(10, 10, 16, 0.74); transition: 0.18s ease; opacity: 0; pointer-events: none; }
.project-delete-btn:hover,.queue-delete-btn:hover { border-color: rgba(248,113,113,0.5); color: #f87171; background: rgba(32, 10, 14, 0.92); }
.project-delete-btn:disabled,.queue-delete-btn:disabled { opacity: 0.5; cursor: default; }
.project-delete-btn { position: absolute; top: 10px; left: 10px; z-index: 2; width: 28px; height: 28px; border-radius: 8px; }
.project-card:hover .project-delete-btn,
.project-card:focus-within .project-delete-btn,
.queue-table tr:hover .queue-delete-btn,
.queue-table tr:focus-within .queue-delete-btn { opacity: 1; pointer-events: auto; }
.aspect-badge,.duration-badge { position: absolute; z-index: 1; padding: 4px 8px; border-radius: 4px; background: rgba(0,0,0,0.5); color: var(--color-text-primary); font-family: "Space Mono", monospace; font-size: 10px; }
.aspect-badge { top: 10px; right: 10px; }
.duration-badge { right: 10px; bottom: 10px; }
.phone-frame { width: 62px; height: 112px; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); border: 2px solid rgba(255,255,255,0.14); border-radius: 11px; display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 10px; }
.phone-line { height: 3px; border-radius: 999px; background: rgba(255,255,255,0.12); }
.phone-line.accent { background: var(--color-accent); opacity: 0.65; }
.project-info { padding: 14px; }
.project-name { margin-bottom: 4px; font-size: 14px; font-weight: 600; }
.project-meta { display: flex; gap: 12px; color: var(--color-text-muted); font-size: 12px; }
.project-status { display: inline-flex; align-items: center; gap: 4px; margin-top: 8px; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
.status-rendered { background: rgba(52,211,153,0.12); color: #34d399; }
.status-draft { background: rgba(251,191,36,0.12); color: #fbbf24; }
.status-rendering { background: rgba(96,165,250,0.12); color: #60a5fa; }
.status-failed { background: rgba(248,113,113,0.12); color: #f87171; }
.empty-row { margin-bottom: 24px; color: var(--color-text-muted); font-size: 13px; }
.empty-actions { margin-top: 8px; display: flex; gap: 8px; }
.queue-wrap { padding: 8px 0 0; }
.queue-header { padding: 16px 18px 0; }
.queue-table { width: 100%; border-collapse: collapse; overflow: hidden; }
.queue-table th,.queue-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--color-border); font-size: 13px; }
.queue-table th { font-size: 11px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.08em; }
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
.queue-empty { padding: 14px 18px 18px; color: var(--color-text-muted); font-size: 13px; }
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
.modal-overlay { position: fixed; inset: 0; z-index: 180; background: rgba(0,0,0,0.68); display: flex; align-items: center; justify-content: center; padding: 16px; }
.modal { width: min(900px,100%); max-height: 90vh; overflow-y: auto; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 18px; }
.delete-modal-overlay { z-index: 190; }
.delete-modal { width: min(420px, 100%); background: linear-gradient(180deg, rgba(255,255,255,0.018), transparent 100%), var(--color-bg-panel); border: 1px solid rgba(248,113,113,0.18); border-radius: 16px; padding: 22px; box-shadow: 0 24px 48px rgba(0, 0, 0, 0.42); }
.delete-modal-icon { width: 42px; height: 42px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; background: rgba(248,113,113,0.12); color: #f87171; border: 1px solid rgba(248,113,113,0.18); }
.delete-modal-title { margin-top: 16px; font-size: 18px; font-weight: 600; }
.delete-modal-text { margin-top: 10px; font-size: 13px; line-height: 1.6; color: var(--color-text-secondary); }
.delete-modal-text strong { color: var(--color-text-primary); font-weight: 600; }
.delete-modal-actions { margin-top: 22px; display: flex; justify-content: flex-end; gap: 8px; }
.delete-btn { background: #ef4444; color: #fff; }
.delete-btn:disabled { opacity: 0.6; cursor: default; }
.modal-title { font-size: 18px; font-weight: 600; }
.modal-subtitle { margin-top: 4px; font-size: 13px; color: var(--color-text-muted); }
.source-type-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.chip { padding: 6px 10px; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); cursor: pointer; font-size: 12px; }
.chip.selected { background: rgba(255,107,53,0.14); border-color: rgba(255,107,53,0.35); color: var(--color-accent); }
.source-panel { display: none; margin-top: 12px; }
.source-panel.active { display: block; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field-input { width: 100%; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); padding: 9px 12px; font-size: 13px; }
.textarea { min-height: 90px; resize: vertical; }
.upload-zone { margin-top: 8px; border: 1px dashed var(--color-border); border-radius: 8px; padding: 16px; color: var(--color-text-muted); font-size: 13px; text-align: center; background: var(--color-bg-card); }
label { display: grid; gap: 6px; font-size: 12px; color: var(--color-text-secondary); }
.mt { margin-top: 12px; }
.langs > span { font-size: 12px; color: var(--color-text-secondary); }
.lang-row { margin-top: 8px; display: flex; gap: 8px; }
.modal-error { padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(248,113,113,0.25); color: #f87171; font-size: 12px; background: rgba(248,113,113,0.1); }
.modal-actions { margin-top: 16px; display: flex; justify-content: flex-end; gap: 8px; }
@media (max-width: 980px) { .stats-row { grid-template-columns: 1fr 1fr; } .form-grid { grid-template-columns: 1fr; } }
@media (max-width: 800px) { .sidebar { display: none; } .main { margin-left: 0; } .topbar { height: auto; padding: 12px; gap: 10px; align-items: flex-start; flex-direction: column; } .stats-row { grid-template-columns: 1fr; } .empty-actions { flex-direction: column; } }
</style>
