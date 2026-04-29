<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'
import NewVideoWizard from '../components/NewVideoWizard.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const mePayload = ref(null)
const projects = ref([])
const channels = ref([])
const loading = ref(true)
const error = ref('')
const deletingProjectIds = ref([])
const deleteConfirmProject = ref(null)
const wizardRef = ref(null)

const filterChannelId = ref(route.query.channel_id ? String(route.query.channel_id) : '')
const filterStatus = ref(route.query.status ? String(route.query.status) : '')

const page = ref(Number(route.query.page) || 1)
const lastPage = ref(1)
const total = ref(0)
const PER_PAGE = 24

const STATUS_OPTIONS = [
  { value: '', label: 'All statuses' },
  { value: 'draft', label: 'Draft' },
  { value: 'generating', label: 'Generating' },
  { value: 'ready_for_review', label: 'Rendered' },
  { value: 'published', label: 'Published' },
  { value: 'failed', label: 'Failed' },
]

const SOURCE_LABELS = {
  prompt: 'Prompt',
  script: 'Script',
  images: 'Images',
  product_description: 'Product',
  blank: 'Blank',
  video_upload: 'Video',
  url: 'URL',
  csv_topic: 'CSV',
}

const activeChannelName = computed(() => {
  if (!filterChannelId.value) return null
  const ch = channels.value.find((c) => String(c.id) === filterChannelId.value)
  return ch?.name || null
})

function channelName(channelId) {
  if (!channelId) return null
  return channels.value.find((c) => String(c.id) === String(channelId))?.name || null
}

function sourceLabel(sourceType) {
  return SOURCE_LABELS[sourceType] || sourceType || 'Manual'
}

function mapStatus(status) {
  const map = {
    draft:           { label: 'Draft',      cls: 'status-draft' },
    generating:      { label: 'Generating', cls: 'status-generating' },
    ready_for_review:{ label: 'Rendered',   cls: 'status-ready' },
    ready:           { label: 'Rendered',   cls: 'status-ready' },
    published:       { label: 'Published',  cls: 'status-published' },
    failed:          { label: 'Failed',     cls: 'status-failed' },
  }
  return map[status] || { label: 'Draft', cls: 'status-draft' }
}

function formatDuration(seconds) {
  if (!seconds) return null
  if (seconds < 60) return `${seconds}s`
  return `${Math.floor(seconds / 60)}m${seconds % 60 ? `${seconds % 60}s` : ''}`
}

function syncQuery() {
  const query = {}
  if (filterChannelId.value) query.channel_id = filterChannelId.value
  if (filterStatus.value) query.status = filterStatus.value
  if (page.value > 1) query.page = page.value
  router.replace({ name: 'videos', query })
}

async function loadProjects() {
  loading.value = true
  error.value = ''
  try {
    const params = { per_page: PER_PAGE, page: page.value }
    if (filterChannelId.value) params.channel_id = filterChannelId.value
    if (filterStatus.value) params.status = filterStatus.value

    const res = await api.get('/projects', { params })
    projects.value = res.data?.data?.projects ?? []
    lastPage.value = res.data?.meta?.pagination?.last_page ?? 1
    total.value = res.data?.meta?.pagination?.total ?? 0
  } catch (err) {
    error.value = err?.response?.data?.error?.message || 'Could not load videos.'
  } finally {
    loading.value = false
  }
}

async function loadChannels() {
  try {
    const res = await api.get('/channels')
    channels.value = res.data?.data?.channels ?? []
  } catch { /* non-fatal */ }
}

async function loadMe() {
  try {
    const res = await api.get('/me')
    mePayload.value = res.data?.data?.user ?? null
  } catch { /* non-fatal */ }
}

function applyFilters() {
  page.value = 1
  syncQuery()
  loadProjects()
}

function goToPage(p) {
  page.value = p
  syncQuery()
  loadProjects()
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// ── Inline rename ─────────────────────────────────────────
const renamingId = ref(null)
const renameDraft = ref('')

async function startRename(project, event) {
  event.stopPropagation()
  renamingId.value = project.id
  renameDraft.value = project.title || ''
  await nextTick()
  document.getElementById(`rename-${project.id}`)?.select()
}

async function commitRename(project) {
  const title = renameDraft.value.trim()
  renamingId.value = null
  if (!title || title === project.title) return
  try {
    await api.patch(`/projects/${project.id}`, { title })
    project.title = title
  } catch { /* revert silently */ }
}

function cancelRename() {
  renamingId.value = null
}

function openProject(project) {
  if (project.status === 'generating') {
    router.push({ name: 'generation-progress', params: { projectId: project.id } })
  } else {
    router.push({ name: 'project-editor', params: { projectId: project.id } })
  }
}

function openVariants(projectId) {
  router.push({ name: 'project-variants', params: { projectId } })
}

function openChannel(channelId) {
  router.push({ name: 'channel-detail', params: { channelId } })
}

function isDeletingProject(id) {
  return deletingProjectIds.value.includes(id)
}

function requestDeleteProject(project) {
  deleteConfirmProject.value = { id: project.id, title: project.title || `Project #${project.id}` }
}

function closeDeleteConfirm() {
  if (!isDeletingProject(deleteConfirmProject.value?.id)) deleteConfirmProject.value = null
}

async function confirmDeleteProject() {
  const id = deleteConfirmProject.value?.id
  if (!id || isDeletingProject(id)) return
  deletingProjectIds.value = [...deletingProjectIds.value, id]
  try {
    await api.delete(`/projects/${id}`)
    deleteConfirmProject.value = null
    if (projects.value.length === 1 && page.value > 1) page.value -= 1
    await loadProjects()
  } catch { /* no-op */ } finally {
    deletingProjectIds.value = deletingProjectIds.value.filter((x) => x !== id)
  }
}

function openNewVideo() {
  wizardRef.value?.open('prompt', filterChannelId.value || null)
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

onMounted(async () => {
  await Promise.all([loadMe(), loadChannels()])
  await loadProjects()
})
</script>

<template>
  <div class="shell">
    <AppSidebar :user="mePayload" active-page="videos" :channel-count="channels.length" @logout="logout" />

    <main class="main">
      <!-- Topbar -->
      <div class="topbar">
        <div class="topbar-left">
          <span class="bc-ws">My Workspace</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">
            All Videos<span v-if="activeChannelName"> — {{ activeChannelName }}</span>
          </span>
        </div>
        <div class="topbar-right">
          <button class="btn btn-primary btn-sm" type="button" @click="openNewVideo">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>
            New Video
          </button>
          <span class="total-label">{{ total }} video{{ total !== 1 ? 's' : '' }}</span>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters-bar">
        <select v-model="filterChannelId" class="filter-select" @change="applyFilters">
          <option value="">All channels</option>
          <option v-for="ch in channels" :key="ch.id" :value="String(ch.id)">{{ ch.name }}</option>
        </select>
        <select v-model="filterStatus" class="filter-select" @change="applyFilters">
          <option v-for="opt in STATUS_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
        </select>
        <button
          v-if="filterChannelId || filterStatus"
          class="clear-btn"
          type="button"
          @click="filterChannelId = ''; filterStatus = ''; applyFilters()"
        >
          Clear filters
        </button>
      </div>

      <div class="content">
        <div v-if="error" class="banner error">{{ error }}</div>

        <div v-if="loading" class="page-state">Loading videos…</div>

        <template v-else>
          <div v-if="projects.length === 0" class="empty-hero">
            <div class="empty-icon">🎬</div>
            <div class="empty-title">No videos found</div>
            <div class="empty-body">
              <span v-if="filterChannelId || filterStatus">No videos match the current filters.</span>
              <span v-else>Create your first video.</span>
            </div>
            <div class="empty-actions">
              <button class="btn btn-primary" type="button" @click="openNewVideo">New Video</button>
              <button v-if="filterChannelId || filterStatus" class="btn btn-ghost" type="button" @click="filterChannelId = ''; filterStatus = ''; applyFilters()">Clear filters</button>
            </div>
          </div>

          <div v-else class="projects-grid">
            <article
              v-for="project in projects"
              :key="project.id"
              class="project-card"
              tabindex="0"
              role="button"
              @click="openProject(project)"
              @keydown.enter="openProject(project)"
            >
              <div class="project-thumb">
                <button
                  class="project-delete-btn"
                  type="button"
                  :disabled="isDeletingProject(project.id)"
                  title="Delete video"
                  @click.stop="requestDeleteProject(project)"
                >
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                    <path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>
                  </svg>
                </button>
                <div class="phone-frame">
                  <div class="phone-line"></div>
                  <div class="phone-line accent"></div>
                  <div class="phone-line"></div>
                  <div class="phone-line"></div>
                </div>
                <span class="aspect-badge">{{ project.aspect_ratio || '9:16' }}</span>
                <span v-if="formatDuration(project.duration_target_seconds)" class="duration-badge">
                  {{ formatDuration(project.duration_target_seconds) }}
                </span>
              </div>

              <div class="project-info">
                <input
                  v-if="renamingId === project.id"
                  :id="`rename-${project.id}`"
                  v-model="renameDraft"
                  class="project-name-input"
                  type="text"
                  @click.stop
                  @blur="commitRename(project)"
                  @keydown.enter.prevent="commitRename(project)"
                  @keydown.esc.prevent="cancelRename"
                />
                <div
                  v-else
                  class="project-name"
                  title="Click to rename"
                  @click.stop="startRename(project, $event)"
                >{{ project.title || `Project #${project.id}` }}</div>

                <div class="project-meta">
                  <span v-if="channelName(project.channel_id)" class="channel-badge" @click.stop="openChannel(project.channel_id)">
                    {{ channelName(project.channel_id) }}
                  </span>
                  <span v-else class="channel-badge channel-badge-none">No channel</span>
                  <span class="source-badge">{{ sourceLabel(project.source_type) }}</span>
                  <span>{{ Number(project.variants_count || 0) }} variants</span>
                </div>

                <div :class="['project-status', mapStatus(project.status).cls]">
                  {{ mapStatus(project.status).label }}
                </div>

                <div class="project-actions" @click.stop>
                  <button class="btn btn-ghost btn-sm" type="button" @click="openVariants(project.id)">Variants</button>
                  <button class="btn btn-ghost btn-sm" type="button" @click="openProject(project)">Open</button>
                </div>
              </div>
            </article>
          </div>

          <!-- Pagination -->
          <div v-if="lastPage > 1" class="pagination">
            <button class="page-btn" type="button" :disabled="page <= 1" @click="goToPage(page - 1)">← Prev</button>
            <div class="page-numbers">
              <button
                v-for="p in lastPage"
                :key="p"
                :class="['page-num', page === p ? 'active' : '']"
                type="button"
                @click="goToPage(p)"
              >
                {{ p }}
              </button>
            </div>
            <button class="page-btn" type="button" :disabled="page >= lastPage" @click="goToPage(page + 1)">Next →</button>
          </div>
        </template>
      </div>
      <NewVideoWizard ref="wizardRef" :channels="channels" @created="loadProjects" />

      <!-- Delete confirm modal -->
      <div v-if="deleteConfirmProject" class="modal-overlay" @click.self="closeDeleteConfirm">
        <div class="delete-modal">
          <div class="delete-modal-icon">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24">
              <path d="M12 9v4"/><path d="M12 17h.01"/>
              <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            </svg>
          </div>
          <div class="delete-modal-title">Delete Video?</div>
          <div class="delete-modal-text">
            <strong>{{ deleteConfirmProject.title }}</strong> will be permanently removed. This cannot be undone.
          </div>
          <div class="delete-modal-actions">
            <button class="btn btn-ghost" type="button" @click="closeDeleteConfirm">Cancel</button>
            <button
              class="btn delete-btn"
              type="button"
              :disabled="isDeletingProject(deleteConfirmProject.id)"
              @click="confirmDeleteProject"
            >
              {{ isDeletingProject(deleteConfirmProject.id) ? 'Deleting…' : 'Delete Video' }}
            </button>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.shell { min-height: 100vh; background: var(--color-bg-deep); color: var(--color-text-primary); font-family: "DM Sans", sans-serif; display: flex; }
.main { margin-left: var(--sidebar-width, 220px); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* Topbar */
.topbar { position: sticky; top: 0; z-index: 90; height: 58px; background: rgba(10,10,15,0.88); border-bottom: 1px solid var(--color-border); backdrop-filter: blur(14px); padding: 0 28px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.topbar-left { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.bc-ws { color: var(--color-text-muted); }
.bc-sep { color: var(--color-text-muted); }
.bc-page { font-weight: 600; color: var(--color-text-primary); }
.total-label { font-size: 12px; color: var(--color-text-muted); font-family: "Space Mono", monospace; }

/* Filters */
.filters-bar { display: flex; align-items: center; gap: 10px; padding: 14px 28px; border-bottom: 1px solid var(--color-border); background: var(--color-bg-deep); flex-shrink: 0; }
.filter-select { height: 34px; padding: 0 10px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); font-size: 13px; cursor: pointer; outline: none; transition: border-color 0.15s; }
.filter-select:focus { border-color: var(--color-accent); }
.clear-btn { font-size: 12px; color: var(--color-text-muted); background: transparent; border: none; cursor: pointer; padding: 0 4px; text-decoration: underline; }
.clear-btn:hover { color: var(--color-text-primary); }

/* Content */
.content { padding: 28px; flex: 1; }
.empty-actions { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }

/* Grid — matches dashboard projects-grid */
.projects-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; }

/* Card */
.project-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; transition: 0.22s ease; text-align: left; cursor: pointer; }
.project-card:hover { transform: translateY(-2px); border-color: var(--color-border-active); }

/* Thumb */
.project-thumb { height: 154px; position: relative; overflow: hidden; background: linear-gradient(135deg, #141729, #1a223d); }
.project-thumb::after { content: ""; position: absolute; inset: auto 0 0; height: 50%; background: linear-gradient(180deg, transparent, rgba(0,0,0,0.35)); }
.phone-frame { width: 62px; height: 112px; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); border: 2px solid rgba(255,255,255,0.14); border-radius: 11px; display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 10px; }
.phone-line { height: 3px; border-radius: 999px; background: rgba(255,255,255,0.12); }
.phone-line.accent { background: var(--color-accent); opacity: 0.65; }
.aspect-badge, .duration-badge { position: absolute; z-index: 1; padding: 4px 8px; border-radius: 4px; background: rgba(0,0,0,0.5); color: var(--color-text-primary); font-family: "Space Mono", monospace; font-size: 10px; }
.aspect-badge { top: 10px; right: 10px; }
.duration-badge { right: 10px; bottom: 10px; }

/* Info */
.project-info { padding: 12px 14px 14px; }
.project-name { font-size: 13px; font-weight: 700; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 7px; cursor: text; }
.project-name:hover { color: var(--color-accent); }
.project-name-input { width: 100%; font-size: 13px; font-weight: 700; font-family: inherit; color: var(--color-text-primary); background: var(--color-bg-elevated); border: 1px solid var(--color-accent); border-radius: 4px; padding: 1px 6px; margin-bottom: 7px; outline: none; }
.project-meta { display: flex; align-items: center; gap: 7px; font-size: 11px; color: var(--color-text-muted); flex-wrap: wrap; margin-bottom: 8px; }
.channel-badge { display: inline-block; padding: 1px 8px; background: rgba(255,107,53,0.1); border: 1px solid rgba(255,107,53,0.25); color: var(--color-accent); border-radius: 999px; font-size: 10px; font-weight: 600; cursor: pointer; transition: 0.15s; white-space: nowrap; }
.channel-badge:hover { background: rgba(255,107,53,0.18); }
.channel-badge-none { background: transparent; border-color: var(--color-border); color: var(--color-text-muted); cursor: default; }
.channel-badge-none:hover { background: transparent; }
.source-badge { display: inline-block; padding: 1px 7px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 999px; font-size: 10px; font-weight: 600; color: var(--color-text-secondary); white-space: nowrap; font-family: "Space Mono", monospace; }

/* Status */
.project-status { font-size: 11px; font-weight: 600; margin-bottom: 10px; padding: 2px 0; }
.status-draft { color: var(--color-text-muted); }
.status-generating { color: #fbbf24; }
.status-ready, .status-published { color: #34d399; }
.status-failed { color: #f87171; }

/* Actions */
.project-actions { display: flex; gap: 8px; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; border: 1px solid transparent; transition: 0.15s; }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; font-weight: 600; }
.btn-primary:hover { opacity: 0.88; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-ghost:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.btn-sm { padding: 5px 12px; font-size: 12px; }

/* Delete button on thumb */
.project-delete-btn { position: absolute; top: 8px; left: 8px; z-index: 2; width: 28px; height: 28px; border-radius: 7px; border: 1px solid rgba(248,113,113,0.25); background: rgba(248,113,113,0.1); color: #f87171; display: flex; align-items: center; justify-content: center; cursor: pointer; opacity: 0; transition: opacity 0.15s, background 0.15s; }
.project-card:hover .project-delete-btn { opacity: 1; }
.project-delete-btn:hover { background: rgba(248,113,113,0.22); }
.project-delete-btn:disabled { opacity: 0.4; cursor: default; }

/* Delete modal */
.modal-overlay { position: fixed; inset: 0; z-index: 200; background: rgba(0,0,0,0.68); display: flex; align-items: center; justify-content: center; padding: 16px; }
.delete-modal { width: min(420px, 100%); background: var(--color-bg-panel); border: 1px solid rgba(248,113,113,0.18); border-radius: 16px; padding: 24px; box-shadow: 0 24px 48px rgba(0,0,0,0.42); display: flex; flex-direction: column; gap: 14px; }
.delete-modal-icon { width: 42px; height: 42px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; background: rgba(248,113,113,0.12); color: #f87171; border: 1px solid rgba(248,113,113,0.18); }
.delete-modal-title { font-size: 16px; font-weight: 700; color: var(--color-text-primary); }
.delete-modal-text { font-size: 13px; color: var(--color-text-muted); line-height: 1.6; }
.delete-modal-actions { display: flex; gap: 8px; justify-content: flex-end; padding-top: 4px; }
.delete-btn { background: rgba(248,113,113,0.1); border-color: rgba(248,113,113,0.3); color: #f87171; padding: 7px 16px; }
.delete-btn:hover:not(:disabled) { background: rgba(248,113,113,0.2); }
.delete-btn:disabled { opacity: 0.5; cursor: default; }

/* Pagination */
.pagination { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 32px; padding-top: 24px; border-top: 1px solid var(--color-border); }
.page-btn { padding: 6px 16px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); font-size: 13px; cursor: pointer; transition: 0.15s; }
.page-btn:disabled { opacity: 0.4; cursor: default; }
.page-btn:not(:disabled):hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.page-numbers { display: flex; gap: 4px; }
.page-num { width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); font-size: 13px; cursor: pointer; transition: 0.15s; display: flex; align-items: center; justify-content: center; }
.page-num:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.page-num.active { border-color: var(--color-accent); background: rgba(255,107,53,0.1); color: var(--color-accent); }

/* Empty */
.empty-hero { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 80px 24px; gap: 14px; border: 1px dashed var(--color-border); border-radius: 16px; }
.empty-icon { font-size: 44px; }
.empty-title { font-size: 20px; font-weight: 700; color: var(--color-text-primary); }
.empty-body { font-size: 14px; color: var(--color-text-muted); line-height: 1.6; }

/* Misc */
.banner { border-radius: 8px; padding: 12px 14px; font-size: 13px; border: 1px solid; margin-bottom: 16px; }
.banner.error { border-color: rgba(248,113,113,0.35); background: rgba(248,113,113,0.08); color: #fca5a5; }
.page-state { padding: 60px 24px; color: var(--color-text-muted); font-size: 14px; text-align: center; }

@media (max-width: 760px) {
  .main { margin-left: 0; }
  .topbar { height: auto; min-height: 58px; padding: 14px 16px; align-items: flex-start; flex-direction: column; gap: 10px; }
  .topbar-right { width: 100%; justify-content: space-between; }
  .content { padding: 16px; }
  .projects-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
  .filters-bar { padding: 10px 16px; flex-wrap: wrap; }
  .page-numbers { display: none; }
}
</style>
