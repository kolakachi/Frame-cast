<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
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
const channels = ref([])
const brandKits = ref([])
const deletingProjectIds = ref([])
const deleteConfirmProject = ref(null)
const currentPage = ref(1)
const perPage = ref(8)
const totalProjects = ref(0)
const lastPage = ref(1)
const queuePage = ref(1)
const queuePerPage = ref(10)
const totalQueueRows = ref(0)
const queueLastPage = ref(1)

const perPageOptions = [4, 8, 12, 16, 24]
const queuePerPageOptions = [5, 10, 20]

// New video wizard (merged flow — single entry point)
const showWizardModal = ref(false)
const wizardStep = ref(1)
const niches = ref([])
const selectedNicheId = ref(null)
const wizardSourceType = ref('script')
const wizardCreateState = ref('idle')
const wizardCreateError = ref('')
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
const audioFile = ref(null)
const videoFile = ref(null)

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
const selectedNiche = computed(() =>
  niches.value.find((n) => n.id === selectedNicheId.value) ?? null
)

const sourceOptions = [
  { key: 'prompt',              icon: '✍️', label: 'Write a Prompt',      hint: 'AI generates the script' },
  { key: 'script',              icon: '📄', label: 'Paste a Script',       hint: 'Your script, broken into scenes' },
  { key: 'url',                 icon: '🔗', label: 'From URL / Article',   hint: 'Paste any article link' },
  { key: 'product_description', icon: '📦', label: 'Product Description',  hint: 'Name, features, audience' },
  { key: 'audio_upload',        icon: '🎙️', label: 'Upload Audio',         hint: 'Transcribe and structure' },
  { key: 'video_upload',        icon: '🎬', label: 'Upload Video',         hint: 'Extract and repurpose' },
  { key: 'csv_topic',           icon: '📋', label: 'CSV Batch',            hint: 'Multiple topics at once' },
]

function nicheTagsFor(niche) {
  const tags = []
  if (niche.default_visual_style) tags.push(niche.default_visual_style)
  if (niche.default_voice_tone)   tags.push(niche.default_voice_tone)
  if (niche.default_caption_preset_name) tags.push(niche.default_caption_preset_name)
  return tags
}

function buildSourceContentRaw() {
  if (wizardSourceType.value === 'script') return scriptText.value.trim()
  if (wizardSourceType.value === 'url') return urlText.value.trim()
  if (wizardSourceType.value === 'prompt') return promptText.value.trim()
  if (wizardSourceType.value === 'csv_topic') return csvText.value.trim()
  if (wizardSourceType.value === 'audio_upload') return audioPath.value.trim()
  if (wizardSourceType.value === 'video_upload') return videoPath.value.trim()

  return [
    `Product Name: ${productName.value.trim()}`,
    `Product Description: ${productDescription.value.trim()}`,
    productUrl.value.trim() ? `Product URL: ${productUrl.value.trim()}` : '',
    targetAudience.value.trim() ? `Target Audience: ${targetAudience.value.trim()}` : '',
  ].filter(Boolean).join('\n')
}

function selectedFile(event) {
  return event.target?.files?.[0] || null
}

async function uploadMediaSource(file, assetType) {
  const formData = new FormData()
  formData.append('title', file.name.replace(/\.[^.]+$/, '') || `${assetType} source`)
  formData.append('asset_type', assetType)
  formData.append('description', `Uploaded from the create video ${assetType} source flow.`)
  formData.append('asset_file', file)

  if (channelId.value) {
    formData.append('channel_id', String(channelId.value))
  }

  const response = await api.post('/assets', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })

  const asset = response.data?.data?.asset

  return [
    `asset_id:${asset?.id}`,
    `title:${asset?.title || file.name}`,
    `mime_type:${asset?.mime_type || file.type}`,
    `transcription_status:${asset?.transcription_status || 'queued'}`,
  ].join('\n')
}

async function resolveSourceContentRaw() {
  if (wizardSourceType.value === 'audio_upload' && audioFile.value) {
    return uploadMediaSource(audioFile.value, 'audio')
  }

  if (wizardSourceType.value === 'video_upload' && videoFile.value) {
    return uploadMediaSource(videoFile.value, 'video')
  }

  return buildSourceContentRaw()
}

watch(channelId, (nextChannelId, previousChannelId) => {
  const channel = channels.value.find((item) => String(item.id) === String(nextChannelId))

  if (!channel) return

  if (!brandKitId.value || String(brandKitId.value) === String(channels.value.find((item) => String(item.id) === String(previousChannelId))?.brand_kit_id || '')) {
    brandKitId.value = channel.brand_kit_id ? String(channel.brand_kit_id) : ''
  }

  if (channel.default_language && languageSelections.value.length === 1) {
    languageSelections.value = [channel.default_language]
  }

  if (Array.isArray(channel.platform_targets) && channel.platform_targets[0]) {
    platformTarget.value = channel.platform_targets[0]
  }
})

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
      channel: project.channel_id ? `Channel #${project.channel_id}` : 'No channel',
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

async function loadProjects() {
  try {
    const response = await api.get('/projects', {
      params: {
        page: currentPage.value,
        per_page: perPage.value,
      },
    })
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
    await Promise.all([loadProjects(), loadQueue(), loadChannels(), loadBrandKits(), loadNiches()])
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

async function loadBrandKits() {
  try {
    const response = await api.get('/brand-kits')
    brandKits.value = response.data?.data?.brand_kits ?? []
  } catch {
    brandKits.value = []
  }
}

async function loadNiches() {
  try {
    const response = await api.get('/niches')
    niches.value = response.data?.data?.niches ?? []
  } catch {
    niches.value = []
  }
}

function openWizard() {
  wizardStep.value = 1
  selectedNicheId.value = null
  wizardSourceType.value = 'script'
  wizardCreateState.value = 'idle'
  wizardCreateError.value = ''
  showWizardModal.value = true
}

function closeWizard() {
  if (wizardCreateState.value === 'loading') return
  showWizardModal.value = false
}

function wizardNext() {
  if (wizardStep.value === 1 && !selectedNicheId.value) return
  wizardStep.value = Math.min(3, wizardStep.value + 1)
}

function wizardBack() {
  wizardStep.value = Math.max(1, wizardStep.value - 1)
}

async function submitWizardProject() {
  wizardCreateState.value = 'loading'
  wizardCreateError.value = ''

  const sourceContentRaw = buildSourceContentRaw()
  const hasMediaFile = (wizardSourceType.value === 'audio_upload' && audioFile.value)
    || (wizardSourceType.value === 'video_upload' && videoFile.value)

  if (!sourceContentRaw && !hasMediaFile) {
    wizardCreateState.value = 'error'
    wizardCreateError.value = 'Source content is required.'
    return
  }

  try {
    const resolvedSource = await resolveSourceContentRaw()

    const response = await api.post('/projects', {
      source_type: wizardSourceType.value,
      source_content_raw: resolvedSource,
      languages: languageSelections.value,
      platform_target: platformTarget.value,
      aspect_ratio: aspectRatio.value,
      niche_id: selectedNicheId.value,
      ...(channelId.value ? { channel_id: Number(channelId.value) } : {}),
      ...(brandKitId.value ? { brand_kit_id: Number(brandKitId.value) } : {}),
      ...(contentGoal.value ? { content_goal: contentGoal.value } : {}),
      ...(title.value ? { title: title.value } : {}),
      ...(durationTargetSeconds.value ? { duration_target_seconds: Number(durationTargetSeconds.value) } : {}),
    })

    const projectId = response.data?.data?.project?.id
    showWizardModal.value = false
    wizardCreateState.value = 'success'

    if (projectId) {
      router.push({ name: 'generation-progress', params: { projectId } })
    }
  } catch (error) {
    wizardCreateState.value = 'error'
    wizardCreateError.value = error.response?.data?.error?.message ?? 'Project creation failed.'
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
        <button class="nav-item active" type="button" aria-current="page">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <rect x="3" y="3" width="7" height="7" rx="1"></rect>
            <rect x="14" y="3" width="7" height="7" rx="1"></rect>
            <rect x="3" y="14" width="7" height="7" rx="1"></rect>
            <rect x="14" y="14" width="7" height="7" rx="1"></rect>
          </svg>
          <span class="tooltip">Dashboard</span>
        </button>
        <button class="nav-item" type="button" @click="router.push({ name: 'asset-library' })">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"></path>
          </svg>
          <span class="tooltip">Asset Library</span>
        </button>
        <button class="nav-item" type="button" @click="router.push({ name: 'settings' })">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="3"></circle>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
          </svg>
          <span class="tooltip">Settings</span>
        </button>
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
          <div class="projects-toolbar">
            <label class="page-size-control">
              <span>Per page</span>
              <select class="field-input page-size-select" :value="perPage" @change="changePerPage($event.target.value)">
                <option v-for="option in perPageOptions" :key="option" :value="option">{{ option }}</option>
              </select>
            </label>
            <div class="projects-summary">
              Showing {{ pageFrom }}-{{ pageTo }} of {{ totalProjects }}
            </div>
          </div>
        </div>

        <div class="projects-grid">
          <button class="new-project-card" type="button" @click="openWizard">
            <div style="text-align:center;">
              <div style="font-size:30px; margin-bottom:8px;">+</div>
              <div style="font-size:13px; font-weight:600;">New Video</div>
            </div>
          </button>

          <article
            v-for="project in projects"
            :key="project.id"
            class="project-card"
            @click="openProject(project)"
            @keydown.enter="openProject(project)"
            @keydown.space.prevent="openProject(project)"
            tabindex="0"
            role="button"
          >
            <div class="project-thumb">
              <button
                class="project-delete-btn"
                type="button"
                :disabled="isDeletingProject(project.id)"
                title="Delete video"
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
                <span>{{ Number(project.variants_count || 0) }} variants</span>
              </div>
              <div :class="`project-status ${mapProjectStatus(project.status).className}`">{{ mapProjectStatus(project.status).label }}</div>
              <div class="project-actions">
                <button class="btn btn-ghost btn-sm" type="button" @click.stop="openVariants(project.id)">Variants</button>
                <button class="btn btn-ghost btn-sm" type="button" @click.stop="openProject(project)">Open</button>
              </div>
            </div>
          </article>
        </div>

        <div v-if="lastPage > 1" class="pagination-row">
          <button class="btn btn-ghost btn-sm" type="button" :disabled="currentPage <= 1" @click="goToPage(currentPage - 1)">Previous</button>
          <div class="pagination-copy">Page {{ currentPage }} of {{ lastPage }}</div>
          <button class="btn btn-ghost btn-sm" type="button" :disabled="currentPage >= lastPage" @click="goToPage(currentPage + 1)">Next</button>
        </div>

        <div v-if="projects.length === 0" class="empty-row">
          <p>No videos yet. Create your first project or import CSV topics.</p>
          <div class="empty-actions">
            <button class="btn btn-ghost btn-sm" type="button" @click="openWizard">Create New Video</button>
            <button class="btn btn-ghost btn-sm" type="button" @click="wizardSourceType = 'csv_topic'; openWizard()">Import CSV Topics</button>
          </div>
        </div>

        <div class="surface-card queue-wrap">
          <div class="section-header queue-header">
            <div class="section-title">Render Queue</div>
            <div class="projects-toolbar">
              <label class="page-size-control">
                <span>Per page</span>
                <select class="field-input page-size-select" :value="queuePerPage" @change="changeQueuePerPage($event.target.value)">
                  <option v-for="option in queuePerPageOptions" :key="option" :value="option">{{ option }}</option>
                </select>
              </label>
              <div class="projects-summary">
                Showing {{ queueFrom }}-{{ queueTo }} of {{ totalQueueRows }}
              </div>
            </div>
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
          <div v-if="queueLastPage > 1" class="pagination-row queue-pagination-row">
            <button class="btn btn-ghost btn-sm" type="button" :disabled="queuePage <= 1" @click="goToQueuePage(queuePage - 1)">Previous</button>
            <div class="pagination-copy">Page {{ queuePage }} of {{ queueLastPage }}</div>
            <button class="btn btn-ghost btn-sm" type="button" :disabled="queuePage >= queueLastPage" @click="goToQueuePage(queuePage + 1)">Next</button>
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

    <!-- New Video Wizard Modal -->
    <div v-if="showWizardModal" class="modal-overlay" @click.self="closeWizard">
      <div class="modal wizard-modal">

        <!-- Step indicator -->
        <div class="wizard-steps">
          <div :class="['wizard-step', wizardStep === 1 ? 'active' : wizardStep > 1 ? 'done' : '']">
            <div class="wizard-step-num">{{ wizardStep > 1 ? '✓' : '1' }}</div>
            <span>Pick Niche</span>
          </div>
          <div :class="['wizard-connector', wizardStep > 1 ? 'done' : '']"></div>
          <div :class="['wizard-step', wizardStep === 2 ? 'active' : wizardStep > 2 ? 'done' : '']">
            <div class="wizard-step-num">{{ wizardStep > 2 ? '✓' : '2' }}</div>
            <span>Source</span>
          </div>
          <div :class="['wizard-connector', wizardStep > 2 ? 'done' : '']"></div>
          <div :class="['wizard-step', wizardStep === 3 ? 'active' : '']">
            <div class="wizard-step-num">3</div>
            <span>Content</span>
          </div>
        </div>

        <!-- Step 1: Pick Niche -->
        <div v-if="wizardStep === 1">
          <div class="section-title">Pick your content niche</div>
          <div class="section-subtitle">Framecast pre-configures visuals, voice, captions, and music based on your selection.</div>
          <div class="niche-grid">
            <div
              v-for="niche in niches"
              :key="niche.id"
              :class="['niche-card', selectedNicheId === niche.id ? 'selected' : '']"
              role="button"
              tabindex="0"
              @click="selectedNicheId = niche.id"
              @keydown.enter="selectedNicheId = niche.id"
            >
              <div class="niche-selected-check">✓</div>
              <span class="niche-emoji">{{ niche.icon_emoji }}</span>
              <div class="niche-name">{{ niche.name }}</div>
              <div class="niche-desc">{{ niche.description }}</div>
              <div class="niche-tags">
                <span v-for="tag in nicheTagsFor(niche)" :key="tag" class="niche-tag">{{ tag }}</span>
              </div>
            </div>
          </div>
          <div class="modal-actions">
            <button class="btn btn-ghost" type="button" @click="closeWizard">Cancel</button>
            <button class="btn btn-primary" type="button" :disabled="!selectedNicheId" @click="wizardNext">Continue →</button>
          </div>
        </div>

        <!-- Step 2: Source Type -->
        <div v-if="wizardStep === 2">
          <div class="section-title">Choose your source</div>
          <div class="section-subtitle">How do you want to start this video?</div>
          <div v-if="selectedNiche" class="niche-preset-banner">
            <span>{{ selectedNiche.icon_emoji }}</span>
            <span>Loaded <strong>{{ selectedNiche.name }}</strong>
              <template v-if="selectedNiche.default_visual_style"> — {{ selectedNiche.default_visual_style }} visuals</template>
              <template v-if="selectedNiche.default_voice_tone">, {{ selectedNiche.default_voice_tone }} voice</template>
              <template v-if="selectedNiche.default_music_mood">, {{ selectedNiche.default_music_mood }} music</template>
            </span>
          </div>
          <div class="source-type-grid">
            <div
              v-for="opt in sourceOptions"
              :key="opt.key"
              :class="['source-type-opt', wizardSourceType === opt.key ? 'selected' : '']"
              role="button"
              tabindex="0"
              @click="wizardSourceType = opt.key"
              @keydown.enter="wizardSourceType = opt.key"
            >
              <span class="source-type-ico">{{ opt.icon }}</span>
              <div class="source-type-name">{{ opt.label }}</div>
              <div class="source-type-hint">{{ opt.hint }}</div>
            </div>
          </div>
          <div class="modal-actions">
            <button class="btn btn-ghost" type="button" @click="wizardBack">← Back</button>
            <button class="btn btn-primary" type="button" @click="wizardNext">Continue →</button>
          </div>
        </div>

        <!-- Step 3: Content Entry -->
        <div v-if="wizardStep === 3">
          <div class="section-title">Enter your content</div>
          <div class="section-subtitle">Almost done — fill in your content and confirm settings.</div>

          <!-- Niche preset summary pills -->
          <div v-if="selectedNiche" class="niche-preset-summary">
            <div v-if="selectedNiche.default_visual_style" class="preset-pill">
              <div class="preset-pill-label">Visual</div>
              <div class="preset-pill-val">{{ selectedNiche.default_visual_style }}</div>
            </div>
            <div v-if="selectedNiche.default_voice_tone" class="preset-pill">
              <div class="preset-pill-label">Voice</div>
              <div class="preset-pill-val">{{ selectedNiche.default_voice_tone }}</div>
            </div>
            <div v-if="selectedNiche.default_caption_preset_name" class="preset-pill">
              <div class="preset-pill-label">Captions</div>
              <div class="preset-pill-val">{{ selectedNiche.default_caption_preset_name }}</div>
            </div>
            <div v-if="selectedNiche.default_music_mood" class="preset-pill">
              <div class="preset-pill-label">Music</div>
              <div class="preset-pill-val">{{ selectedNiche.default_music_mood }}</div>
            </div>
          </div>

          <!-- Content input by source type -->
          <div v-if="wizardSourceType === 'prompt'" class="input-group">
            <label class="input-label">What's your video about?</label>
            <textarea v-model="promptText" class="field-input textarea" rows="5" placeholder="e.g. The mysterious disappearance of the Beaumont family in 1966…"></textarea>
          </div>
          <div v-else-if="wizardSourceType === 'script'" class="input-group">
            <label class="input-label">Paste your script</label>
            <textarea v-model="scriptText" class="field-input textarea" rows="6" placeholder="Each paragraph will become a scene…"></textarea>
          </div>
          <div v-else-if="wizardSourceType === 'url'" class="input-group">
            <label class="input-label">Article or page URL</label>
            <input v-model="urlText" type="url" class="field-input" placeholder="https://…" />
          </div>
          <div v-else-if="wizardSourceType === 'csv_topic'" class="input-group">
            <label class="input-label">CSV Topics</label>
            <textarea v-model="csvText" class="field-input textarea" rows="5" placeholder="topic,angle,hook"></textarea>
          </div>
          <div v-else-if="wizardSourceType === 'product_description'" class="input-group">
            <div class="form-grid">
              <label class="input-label-wrap"><span class="input-label">Product name</span><input v-model="productName" class="field-input" type="text"></label>
              <label class="input-label-wrap"><span class="input-label">Product URL</span><input v-model="productUrl" class="field-input" type="url"></label>
            </div>
            <label class="input-label-wrap mt"><span class="input-label">Description</span><textarea v-model="productDescription" class="field-input textarea" rows="3"></textarea></label>
            <label class="input-label-wrap mt"><span class="input-label">Target audience</span><input v-model="targetAudience" class="field-input" type="text"></label>
          </div>
          <div v-else-if="wizardSourceType === 'audio_upload'" class="input-group">
            <label class="input-label">Upload audio file</label>
            <label class="upload-zone upload-zone-input">
              <span>{{ audioFile ? audioFile.name : '🎙️  Drop your audio file here — MP3, WAV, M4A · max 500MB' }}</span>
              <input class="hidden-file-input" type="file" accept="audio/*" @change="audioFile = selectedFile($event)">
            </label>
          </div>
          <div v-else-if="wizardSourceType === 'video_upload'" class="input-group">
            <label class="input-label">Upload video file</label>
            <label class="upload-zone upload-zone-input">
              <span>{{ videoFile ? videoFile.name : '🎬  Drop your video file here — MP4, MOV · max 2GB' }}</span>
              <input class="hidden-file-input" type="file" accept="video/*" @change="videoFile = selectedFile($event)">
            </label>
          </div>

          <!-- Settings -->
          <div class="settings-2col mt">
            <label class="input-label-wrap">
              <span class="input-label">Channel</span>
              <select v-model="channelId" class="field-input">
                <option value="">No channel</option>
                <option v-for="channel in channels" :key="channel.id" :value="String(channel.id)">{{ channel.name }}</option>
              </select>
            </label>
            <label class="input-label-wrap">
              <span class="input-label">Language</span>
              <select v-model="languageSelections[0]" class="field-input">
                <option value="en">English (US)</option>
                <option value="es">Spanish</option>
                <option value="fr">French</option>
              </select>
            </label>
          </div>

          <div class="mt">
            <div class="input-label" style="margin-bottom:8px;">Format</div>
            <div class="format-chips">
              <div :class="['format-chip', aspectRatio === '9:16' ? 'active' : '']" @click="aspectRatio = '9:16'">9:16</div>
              <div :class="['format-chip', aspectRatio === '1:1'  ? 'active' : '']" @click="aspectRatio = '1:1'">1:1</div>
              <div :class="['format-chip', aspectRatio === '16:9' ? 'active' : '']" @click="aspectRatio = '16:9'">16:9</div>
            </div>
          </div>

          <label class="input-label-wrap mt"><span class="input-label">Title <span style="opacity:.5;font-weight:400;">(optional)</span></span><input v-model="title" class="field-input" type="text"></label>

          <div v-if="wizardCreateError" class="modal-error mt">{{ wizardCreateError }}</div>

          <div class="modal-actions">
            <button class="btn btn-ghost" type="button" @click="wizardBack">← Back</button>
            <button class="btn btn-primary" type="button" :disabled="wizardCreateState === 'loading'" @click="submitWizardProject">
              {{ wizardCreateState === 'loading' ? '✦ Generating…' : '✦ Generate Video' }}
            </button>
          </div>
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
.nav-item:disabled { opacity: 0.4; cursor: default; }
.nav-item:disabled:hover { color: var(--color-text-muted); background: transparent; }
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
.projects-toolbar { display: flex; align-items: center; gap: 12px; margin-left: auto; }
.page-size-control { display: inline-flex; align-items: center; gap: 8px; color: var(--color-text-muted); font-size: 12px; white-space: nowrap; }
.page-size-control span { white-space: nowrap; }
.page-size-select { min-width: 72px; padding: 6px 10px; }
.projects-summary { color: var(--color-text-muted); font-size: 12px; }
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
.project-actions { display: flex; gap: 8px; margin-top: 10px; }
.status-rendered { background: rgba(52,211,153,0.12); color: #34d399; }
.status-draft { background: rgba(251,191,36,0.12); color: #fbbf24; }
.status-rendering { background: rgba(96,165,250,0.12); color: #60a5fa; }
.status-failed { background: rgba(248,113,113,0.12); color: #f87171; }
.pagination-row { display: flex; align-items: center; justify-content: flex-end; gap: 10px; margin: 0 0 20px; }
.pagination-copy { min-width: 90px; text-align: center; color: var(--color-text-muted); font-size: 12px; }
.empty-row { margin-bottom: 24px; color: var(--color-text-muted); font-size: 13px; }
.empty-actions { margin-top: 8px; display: flex; gap: 8px; }
.queue-wrap { padding: 8px 0 0; }
.queue-header { padding: 16px 18px 0; }
.queue-pagination-row { padding: 14px 18px 18px; margin-bottom: 0; }
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
/* ── Modals ─────────────────────────────────────────────────────── */
.modal-overlay { position: fixed; inset: 0; z-index: 180; background: rgba(0,0,0,0.68); display: flex; align-items: center; justify-content: center; padding: 16px; }
.modal { width: min(900px,100%); max-height: 90vh; overflow-y: auto; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 24px; }
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
.modal-actions { margin-top: 20px; display: flex; justify-content: flex-end; gap: 8px; }
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

/* ── Wizard ──────────────────────────────────────────────────────── */
.wizard-modal { width: min(860px,calc(100vw - 32px)); }

/* Step indicator */
.wizard-steps { display: flex; align-items: center; margin-bottom: 24px; }
.wizard-step { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--color-text-muted); }
.wizard-step-num { width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--color-border); color: var(--color-text-muted); font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.2s; }
.wizard-step.active .wizard-step-num { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.wizard-step.active { color: var(--color-text-primary); }
.wizard-step.done .wizard-step-num { background: rgba(52,211,153,0.15); border-color: rgba(52,211,153,0.4); color: #34d399; }
.wizard-connector { flex: 1; height: 1px; background: var(--color-border); margin: 0 10px; }
.wizard-connector.done { background: rgba(52,211,153,0.35); }

/* Section headings (inside wizard steps) */
.section-title { font-size: 18px; font-weight: 600; color: var(--color-text-primary); }
.section-subtitle { margin-top: 4px; margin-bottom: 20px; font-size: 13px; color: var(--color-text-muted); }

/* Niche grid */
.niche-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 4px; }
.niche-card { position: relative; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; padding: 14px 12px; border-radius: 10px; border: 1.5px solid var(--color-border); background: rgba(255,255,255,0.025); cursor: pointer; text-align: left; transition: border-color 0.15s, background 0.15s; }
.niche-card:hover { border-color: rgba(255,107,53,0.4); background: rgba(255,107,53,0.04); transform: translateY(-1px); }
.niche-card.selected { border-color: var(--color-accent); background: rgba(255,107,53,0.08); }
.niche-selected-check { display: none; position: absolute; top: 8px; right: 8px; width: 18px; height: 18px; border-radius: 50%; background: var(--color-accent); color: #fff; font-size: 10px; font-weight: 700; align-items: center; justify-content: center; }
.niche-card.selected .niche-selected-check { display: flex; }
.niche-emoji { font-size: 24px; line-height: 1; margin-bottom: 6px; }
.niche-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); line-height: 1.2; }
.niche-desc { font-size: 11px; color: var(--color-text-muted); line-height: 1.45; }
.niche-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.niche-tag { padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 500; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: var(--color-text-muted); }

/* Niche preset banner (step 2) */
.niche-preset-banner { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 8px; background: rgba(255,107,53,0.06); border: 1px solid rgba(255,107,53,0.2); margin-bottom: 18px; font-size: 12px; color: var(--color-text-secondary); }
.niche-preset-banner strong { color: var(--color-text-primary); }

/* Source type grid (step 2) */
.source-type-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 4px; }
.source-type-opt { padding: 14px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; text-align: center; transition: 0.15s ease; }
.source-type-opt:hover { border-color: rgba(255,107,53,0.35); }
.source-type-opt.selected { border-color: var(--color-accent); background: rgba(255,107,53,0.08); }
.source-type-ico { font-size: 22px; margin-bottom: 6px; display: block; }
.source-type-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); }
.source-type-hint { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

/* Niche preset summary pills (step 3) */
.niche-preset-summary { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
.preset-pill { padding: 8px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); min-width: 80px; }
.preset-pill-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-text-muted); margin-bottom: 3px; }
.preset-pill-val { font-size: 12px; font-weight: 600; color: var(--color-text-primary); text-transform: capitalize; }

/* Format chips */
.format-chips { display: flex; gap: 8px; }
.format-chip { padding: 6px 14px; border-radius: 6px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-muted); font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.15s; }
.format-chip:hover { border-color: rgba(255,107,53,0.35); color: var(--color-text-secondary); }
.format-chip.active { border-color: var(--color-accent); background: rgba(255,107,53,0.1); color: var(--color-accent); }

@media (max-width: 680px) { .niche-grid { grid-template-columns: repeat(2, 1fr); } .source-type-grid { grid-template-columns: repeat(2, 1fr); } .settings-2col { grid-template-columns: 1fr; } }

@media (max-width: 980px) { .stats-row { grid-template-columns: 1fr 1fr; } .form-grid { grid-template-columns: 1fr; } .section-header { align-items: flex-start; flex-direction: column; } .projects-toolbar { margin-left: 0; flex-wrap: wrap; } .pagination-row { justify-content: space-between; } }
@media (max-width: 800px) { .sidebar { display: none; } .main { margin-left: 0; } .topbar { height: auto; padding: 12px; gap: 10px; align-items: flex-start; flex-direction: column; } .stats-row { grid-template-columns: 1fr; } .empty-actions { flex-direction: column; } .projects-toolbar { width: 100%; justify-content: space-between; } .projects-summary { width: 100%; } }
</style>
