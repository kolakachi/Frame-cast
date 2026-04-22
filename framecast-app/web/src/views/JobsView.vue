<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'

const router = useRouter()

const mePayload = ref(null)
const jobs = ref([])
const channels = ref([])
const loading = ref(false)
const searchQuery = ref('')
const activeFilter = ref('all')
const sortOrder = ref('newest')       // 'newest' | 'oldest'
const filterChannelId = ref(null)
const filterOpen = ref(false)

// Server-side pagination state
const currentPage = ref(1)
const perPage = ref(10)
const totalJobs = ref(0)
const lastPage = ref(1)
const pageFrom = computed(() => totalJobs.value === 0 ? 0 : ((currentPage.value - 1) * perPage.value) + 1)
const pageTo = computed(() => Math.min(totalJobs.value, currentPage.value * perPage.value))

let pollTimer = null

// ── Status / step helpers ─────────────────────────────────

const STATUS_MAP = {
  generating:       { cls: 'generating', label: 'Generating', pulsing: true,  progressCls: 'orange' },
  ready_for_review: { cls: 'completed',  label: 'Completed',  pulsing: false, progressCls: 'green'  },
  failed:           { cls: 'failed',     label: 'Failed',     pulsing: false, progressCls: 'red'    },
}

const STEP_LABELS = {
  script:          'Writing script',
  scene_breakdown: 'Breaking into scenes',
  visual_match:    'Matching visuals',
  ai_image:        'Generating AI images',
  tts:             'Generating voiceover',
  hooks:           'Generating hooks',
  hooks_scoring:   'Scoring hooks',
}

const STEP_PROGRESS = {
  script: 10, scene_breakdown: 25, visual_match: 45, ai_image: 55, tts: 75, hooks: 88, hooks_scoring: 95,
}

function statusInfo(status) {
  return STATUS_MAP[status] ?? { cls: 'queued', label: 'Queued', pulsing: false, progressCls: 'slate' }
}

function stepLabel(job) {
  if (job.status !== 'generating') return null
  const step = job.generation_status_json?.step
  return step ? (STEP_LABELS[step] ?? null) : null
}

function jobProgress(job) {
  if (job.status === 'ready_for_review') return 100
  if (job.status === 'failed') return 100
  if (job.status === 'generating') {
    const step = job.generation_status_json?.step
    return step ? (STEP_PROGRESS[step] ?? 10) : Math.min(95, Math.max(5, (job.scenes_count || 0) * 12))
  }
  return 0
}

// ── Filter counts (from full loaded page) ─────────────────

const filterTabs = computed(() => [
  { key: 'all',        label: 'All',        count: jobs.value.length },
  { key: 'generating', label: 'Generating', count: jobs.value.filter(j => j.status === 'generating').length },
  { key: 'completed',  label: 'Completed',  count: jobs.value.filter(j => j.status === 'ready_for_review').length },
  { key: 'failed',     label: 'Failed',     count: jobs.value.filter(j => j.status === 'failed').length },
])

// ── Visible jobs: filter → search → sort ─────────────────

const visibleJobs = computed(() => {
  let list = jobs.value

  if (activeFilter.value === 'generating') list = list.filter(j => j.status === 'generating')
  if (activeFilter.value === 'completed')  list = list.filter(j => j.status === 'ready_for_review')
  if (activeFilter.value === 'failed')     list = list.filter(j => j.status === 'failed')

  if (filterChannelId.value) list = list.filter(j => String(j.channel_id) === String(filterChannelId.value))

  const q = searchQuery.value.trim().toLowerCase()
  if (q) list = list.filter(j => (j.title || '').toLowerCase().includes(q) || String(j.id).includes(q))

  if (sortOrder.value === 'oldest') list = [...list].reverse()

  return list
})

const sortLabel = computed(() => sortOrder.value === 'newest' ? 'Sort: Newest' : 'Sort: Oldest')

function toggleSort() {
  sortOrder.value = sortOrder.value === 'newest' ? 'oldest' : 'newest'
}

function setChannelFilter(id) {
  filterChannelId.value = id
  filterOpen.value = false
  currentPage.value = 1
}

function clearFilters() {
  activeFilter.value = 'all'
  filterChannelId.value = null
  searchQuery.value = ''
  sortOrder.value = 'newest'
  filterOpen.value = false
}

const hasActiveFilters = computed(() =>
  activeFilter.value !== 'all' || filterChannelId.value || searchQuery.value.trim()
)

// ── Formatters ────────────────────────────────────────────

function channelName(channelId) {
  if (!channelId) return null
  return channels.value.find(c => String(c.id) === String(channelId))?.name ?? null
}

function relativeTime(iso) {
  if (!iso) return '—'
  const mins = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
  if (mins < 1) return 'now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

function fmtDuration(seconds) {
  const v = Number(seconds || 0)
  if (!v) return '—'
  return `${Math.floor(v / 60)}:${String(Math.floor(v % 60)).padStart(2, '0')}`
}

function thumbGrad(id) {
  const grads = [
    'linear-gradient(135deg,#1e3a8a,#7c3aed 50%,#db2777)',
    'linear-gradient(135deg,#065f46,#0891b2)',
    'linear-gradient(135deg,#7c2d12,#f97316 50%,#facc15)',
    'linear-gradient(135deg,#312e81,#6366f1)',
    'linear-gradient(135deg,#0c4a6e,#0284c7 50%,#06b6d4)',
    'linear-gradient(135deg,#4c1d95,#a855f7)',
    'linear-gradient(135deg,#134e4a,#14b8a6)',
  ]
  return grads[Number(id) % grads.length]
}

// ── Pagination ────────────────────────────────────────────

function goToPage(page) {
  if (page < 1 || page > lastPage.value || page === currentPage.value) return
  currentPage.value = page
  loadJobs()
}

const pageNumbers = computed(() => {
  const total = lastPage.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const cur = currentPage.value
  const pages = new Set([1, total, cur])
  if (cur > 1) pages.add(cur - 1)
  if (cur < total) pages.add(cur + 1)
  const sorted = [...pages].sort((a, b) => a - b)
  const result = []
  for (let i = 0; i < sorted.length; i++) {
    if (i > 0 && sorted[i] - sorted[i - 1] > 1) result.push('…')
    result.push(sorted[i])
  }
  return result
})

// ── Actions ───────────────────────────────────────────────

function openJob(job) {
  if (!job?.id) return
  if (job.status === 'generating') {
    router.push({ name: 'generation-progress', params: { projectId: job.id } })
  } else {
    router.push({ name: 'project-editor', params: { projectId: job.id } })
  }
}

// ── Data loading ──────────────────────────────────────────

async function loadJobs() {
  try {
    const response = await api.get('/projects/queue', {
      params: { page: currentPage.value, per_page: perPage.value },
    })
    const pagination = response.data?.meta?.pagination ?? {}
    jobs.value = response.data?.data?.queue_rows ?? []
    totalJobs.value = Number(pagination.total || jobs.value.length)
    lastPage.value = Math.max(1, Number(pagination.last_page || 1))
    currentPage.value = Math.min(Math.max(1, Number(pagination.current_page || currentPage.value)), lastPage.value)
  } catch {
    jobs.value = []
    totalJobs.value = 0
    lastPage.value = 1
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

async function loadMe() {
  try {
    loading.value = true
    const response = await api.get('/me')
    mePayload.value = response.data?.data?.user ?? null
    await Promise.all([loadJobs(), loadChannels()])
    pollTimer = window.setInterval(loadJobs, 5000)
  } catch {
    mePayload.value = null
  } finally {
    loading.value = false
  }
}

onMounted(loadMe)
onBeforeUnmount(() => { if (pollTimer) window.clearInterval(pollTimer) })
</script>

<template>
  <main class="fc-shell" @click="filterOpen = false">
    <AppSidebar :user="mePayload" active-page="jobs" :channel-count="channels.length" />

    <div class="main">
      <div class="topbar">
        <div class="topbar-left">
          <span class="bc-ws">My Workspace</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">Jobs</span>
        </div>
        <div class="topbar-right">
          <span class="topbar-hint">// real-time render queue</span>
        </div>
      </div>

      <div class="jobs-page">

        <!-- Filter pills -->
        <div class="filters">
          <button
            v-for="tab in filterTabs"
            :key="tab.key"
            :class="['pill', activeFilter === tab.key ? 'active' : '']"
            type="button"
            @click="activeFilter = tab.key; currentPage = 1"
          >
            {{ tab.label }}
            <span class="pill-count">{{ tab.count }}</span>
          </button>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
          <div class="search-wrap">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="search-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input
              v-model="searchQuery"
              class="search-input"
              type="text"
              placeholder="Search by title or ID…"
            />
          </div>

          <div class="toolbar-right">
            <!-- Clear filters -->
            <button v-if="hasActiveFilters" class="btn btn-ghost btn-sm" type="button" @click="clearFilters">
              Clear
            </button>

            <!-- Channel filter dropdown -->
            <div class="dropdown-wrap" @click.stop>
              <button
                :class="['btn btn-ghost btn-sm', filterChannelId ? 'btn-active' : '']"
                type="button"
                @click="filterOpen = !filterOpen"
              >
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                {{ filterChannelId ? channelName(filterChannelId) : 'Filter' }}
              </button>
              <div v-if="filterOpen" class="dropdown">
                <button
                  :class="['dropdown-item', !filterChannelId ? 'active' : '']"
                  type="button"
                  @click="setChannelFilter(null)"
                >All channels</button>
                <div class="dropdown-divider"></div>
                <button
                  v-for="ch in channels"
                  :key="ch.id"
                  :class="['dropdown-item', String(filterChannelId) === String(ch.id) ? 'active' : '']"
                  type="button"
                  @click="setChannelFilter(ch.id)"
                >{{ ch.name }}</button>
              </div>
            </div>

            <!-- Sort toggle -->
            <button class="btn btn-ghost btn-sm" type="button" @click="toggleSort">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              {{ sortLabel }}
            </button>
          </div>
        </div>

        <!-- Table -->
        <div class="table-wrap">
          <table v-if="visibleJobs.length > 0" class="jobs-table">
            <thead>
              <tr>
                <th>Job</th>
                <th>Status</th>
                <th class="hide-md">Progress</th>
                <th class="hide-md">Duration</th>
                <th class="hide-md">Channel</th>
                <th class="hide-md sortable" @click="toggleSort">
                  Created
                  <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 15l5 5 5-5M7 9l5-5 5 5"/></svg>
                </th>
                <th style="width:40px"></th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="job in visibleJobs"
                :key="job.id"
                class="job-row"
                @click="openJob(job)"
              >
                <!-- Job -->
                <td>
                  <div class="job-cell">
                    <div class="job-thumb" :style="{ background: thumbGrad(job.id) }">
                      <div class="job-thumb-gloss"></div>
                    </div>
                    <div class="job-info">
                      <div class="job-title">{{ job.title || `Project #${job.id}` }}</div>
                      <div class="job-id">#{{ job.id }}</div>
                    </div>
                  </div>
                </td>

                <!-- Status -->
                <td>
                  <div class="status-cell">
                    <span :class="['status-badge', statusInfo(job.status).cls]">
                      <span :class="['status-dot', statusInfo(job.status).pulsing ? 'pulsing' : '']"></span>
                      {{ statusInfo(job.status).label }}
                    </span>
                    <span v-if="stepLabel(job)" class="step-label">{{ stepLabel(job) }}</span>
                  </div>
                </td>

                <!-- Progress -->
                <td class="hide-md">
                  <div class="progress-cell">
                    <div class="progress-track">
                      <div
                        :class="['progress-fill', statusInfo(job.status).progressCls, job.status === 'generating' ? 'animated' : '']"
                        :style="{ width: `${jobProgress(job)}%` }"
                      ></div>
                    </div>
                    <span class="progress-value">{{ jobProgress(job) }}%</span>
                  </div>
                </td>

                <!-- Duration -->
                <td class="hide-md">
                  <span class="mono-cell">{{ fmtDuration(job.duration_target_seconds) }}</span>
                </td>

                <!-- Channel -->
                <td class="hide-md">
                  <span class="dim-cell">{{ channelName(job.channel_id) || '—' }}</span>
                </td>

                <!-- Created -->
                <td class="hide-md">
                  <span class="dim-cell">{{ relativeTime(job.created_at) }}</span>
                </td>

                <!-- Action -->
                <td>
                  <button class="row-btn" type="button" title="Open" @click.stop="openJob(job)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                      <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                      <polyline points="15 3 21 3 21 9"/>
                      <line x1="10" y1="14" x2="21" y2="3"/>
                    </svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Empty state -->
          <div v-else-if="!loading" class="empty-state">
            <div class="empty-icon">◌</div>
            <div class="empty-title">No jobs found</div>
            <div class="empty-text">
              {{ hasActiveFilters ? 'Try clearing your filters.' : 'Start generating a video to see jobs appear here.' }}
            </div>
          </div>
          <div v-else class="empty-state">
            <div class="empty-title">Loading…</div>
          </div>

          <!-- Table footer: count + pagination -->
          <div class="table-footer">
            <span class="dim-cell">
              {{ totalJobs === 0 ? 'No jobs' : `Showing ${pageFrom}–${pageTo} of ${totalJobs} jobs` }}
            </span>
            <div v-if="lastPage > 1" class="pagination">
              <button
                class="page-btn"
                type="button"
                :disabled="currentPage <= 1"
                aria-label="Previous"
                @click="goToPage(currentPage - 1)"
              >‹</button>
              <template v-for="p in pageNumbers" :key="p">
                <span v-if="p === '…'" class="page-ellipsis">…</span>
                <button
                  v-else
                  :class="['page-btn', p === currentPage ? 'active' : '']"
                  type="button"
                  @click="goToPage(p)"
                >{{ p }}</button>
              </template>
              <button
                class="page-btn"
                type="button"
                :disabled="currentPage >= lastPage"
                aria-label="Next"
                @click="goToPage(currentPage + 1)"
              >›</button>
            </div>
          </div>
        </div>

      </div>
    </div>
  </main>
</template>

<style scoped>
/* ── Shell ───────────────────────────────────────────────── */
.fc-shell { min-height: 100vh; background: var(--color-bg-deep); color: var(--color-text-primary); font-family: "DM Sans", sans-serif; display: flex; }
.main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ── Topbar ──────────────────────────────────────────────── */
.topbar { position: sticky; top: 0; z-index: 90; height: 58px; background: rgba(10,10,15,0.88); border-bottom: 1px solid var(--color-border); backdrop-filter: blur(14px); padding: 0 28px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.topbar-left { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.bc-ws { color: var(--color-text-muted); }
.bc-sep { color: var(--color-text-muted); }
.bc-page { font-weight: 600; color: var(--color-text-primary); }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.topbar-hint { font-family: "Space Mono", monospace; font-size: 11px; color: var(--color-text-muted); letter-spacing: 0.02em; }

/* ── Page layout ─────────────────────────────────────────── */
.jobs-page { padding: 28px; display: flex; flex-direction: column; gap: 16px; max-width: 1280px; width: 100%; }

/* ── Buttons ─────────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.15s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; font-family: "DM Sans", sans-serif; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-ghost:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.btn-active { border-color: rgba(255,107,53,0.4) !important; color: var(--color-accent) !important; background: rgba(255,107,53,0.08) !important; }
.btn-sm { padding: 7px 12px; font-size: 12px; }

/* ── Filter pills ────────────────────────────────────────── */
.filters { display: flex; gap: 8px; flex-wrap: wrap; }
.pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 18px; border-radius: 999px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-muted); font-size: 13px; font-weight: 500; cursor: pointer; transition: 0.18s ease; font-family: "DM Sans", sans-serif; }
.pill:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.pill.active { background: rgba(255,107,53,0.1); border-color: rgba(255,107,53,0.4); color: var(--color-accent); box-shadow: 0 0 0 3px rgba(255,107,53,0.06); }
.pill-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 18px; padding: 0 6px; border-radius: 999px; background: rgba(255,255,255,0.06); font-size: 10px; font-weight: 600; color: var(--color-text-muted); font-family: "Space Mono", monospace; }
.pill.active .pill-count { background: rgba(255,107,53,0.15); color: var(--color-accent); }

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.search-wrap { flex: 1; max-width: 360px; position: relative; }
.search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--color-text-muted); pointer-events: none; }
.search-input { width: 100%; padding: 9px 14px 9px 36px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); font-family: "DM Sans", sans-serif; font-size: 13px; outline: none; transition: border-color 0.15s ease; }
.search-input::placeholder { color: var(--color-text-muted); }
.search-input:focus { border-color: var(--color-border-active); }
.toolbar-right { display: flex; align-items: center; gap: 8px; }

/* ── Dropdown ────────────────────────────────────────────── */
.dropdown-wrap { position: relative; }
.dropdown { position: absolute; top: calc(100% + 6px); right: 0; min-width: 180px; background: var(--color-bg-elevated); border: 1px solid var(--color-border-active); border-radius: 10px; padding: 6px; z-index: 200; box-shadow: 0 12px 32px rgba(0,0,0,0.4); }
.dropdown-item { width: 100%; text-align: left; padding: 8px 10px; border-radius: 6px; font-size: 13px; color: var(--color-text-secondary); background: transparent; border: none; cursor: pointer; font-family: "DM Sans", sans-serif; transition: 0.12s ease; display: block; }
.dropdown-item:hover { background: rgba(255,255,255,0.05); color: var(--color-text-primary); }
.dropdown-item.active { color: var(--color-accent); background: rgba(255,107,53,0.08); }
.dropdown-divider { border-top: 1px solid var(--color-border); margin: 5px 0; }

/* ── Table wrap ──────────────────────────────────────────── */
.table-wrap { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 14px; overflow: hidden; }
.jobs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead { background: rgba(255,255,255,0.015); }
th { text-align: left; padding: 13px 20px; font-family: "Space Mono", monospace; font-size: 10px; font-weight: 400; text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-text-muted); border-bottom: 1px solid var(--color-border); white-space: nowrap; }
th.sortable { cursor: pointer; user-select: none; display: table-cell; align-items: center; gap: 5px; }
th.sortable:hover { color: var(--color-text-secondary); }
th.sortable svg { vertical-align: middle; margin-left: 4px; }
td { padding: 15px 20px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
.job-row:last-child td { border-bottom: none; }
.job-row { cursor: pointer; transition: background 0.12s ease; }
.job-row:hover td { background: rgba(255,255,255,0.013); }

/* ── Job cell ────────────────────────────────────────────── */
.job-cell { display: flex; align-items: center; gap: 12px; min-width: 240px; }
.job-thumb { width: 42px; height: 42px; border-radius: 8px; flex-shrink: 0; position: relative; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); }
.job-thumb-gloss { position: absolute; inset: 0; background: radial-gradient(circle at 30% 30%, rgba(255,255,255,0.14), transparent 55%); }
.job-info { min-width: 0; }
.job-title { font-weight: 500; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 280px; margin-bottom: 2px; }
.job-id { font-family: "Space Mono", monospace; font-size: 10px; color: var(--color-text-muted); }

/* ── Status ──────────────────────────────────────────────── */
.status-cell { display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.step-label { font-family: "Space Mono", monospace; font-size: 10px; color: var(--color-text-muted); letter-spacing: 0.02em; white-space: nowrap; }
.status-badge { display: inline-flex; align-items: center; gap: 7px; padding: 4px 10px 4px 8px; border-radius: 999px; font-size: 12px; font-weight: 500; border: 1px solid; white-space: nowrap; }
.status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.status-dot.pulsing { animation: dot-pulse 1.6s ease-in-out infinite; }
@keyframes dot-pulse {
  0%, 100% { opacity: 1; box-shadow: 0 0 0 0 currentColor; }
  50% { opacity: 0.6; box-shadow: 0 0 0 3px transparent; }
}
.status-badge.generating { background: rgba(249,115,22,0.1); border-color: rgba(249,115,22,0.3); color: #f97316; }
.status-badge.generating .status-dot { background: #f97316; color: #f97316; }
.status-badge.completed { background: rgba(34,197,94,0.1); border-color: rgba(34,197,94,0.3); color: #22c55e; }
.status-badge.completed .status-dot { background: #22c55e; }
.status-badge.failed { background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.3); color: #ef4444; }
.status-badge.failed .status-dot { background: #ef4444; }
.status-badge.queued { background: rgba(100,116,139,0.1); border-color: rgba(100,116,139,0.3); color: #64748b; }
.status-badge.queued .status-dot { background: #64748b; }

/* ── Progress ────────────────────────────────────────────── */
.progress-cell { display: flex; align-items: center; gap: 10px; min-width: 130px; }
.progress-track { flex: 1; height: 4px; background: rgba(255,255,255,0.06); border-radius: 999px; overflow: hidden; }
.progress-fill { height: 100%; border-radius: 999px; transition: width 0.4s ease; position: relative; }
.progress-fill.orange { background: #f97316; }
.progress-fill.green  { background: #22c55e; }
.progress-fill.red    { background: #ef4444; }
.progress-fill.slate  { background: #64748b; }
.progress-fill.animated::after { content: ""; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.28), transparent); animation: shimmer 1.6s linear infinite; }
@keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
.progress-value { font-family: "Space Mono", monospace; font-size: 11px; color: var(--color-text-muted); min-width: 32px; text-align: right; }

/* ── Misc cells ──────────────────────────────────────────── */
.mono-cell { font-family: "Space Mono", monospace; font-size: 12px; color: var(--color-text-primary); }
.dim-cell { font-family: "Space Mono", monospace; font-size: 12px; color: var(--color-text-muted); }

/* ── Row action ──────────────────────────────────────────── */
.row-btn { width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px solid transparent; background: transparent; color: var(--color-text-muted); cursor: pointer; transition: 0.12s ease; }
.row-btn:hover { background: rgba(255,255,255,0.04); color: var(--color-text-primary); border-color: var(--color-border); }

/* ── Table footer ────────────────────────────────────────── */
.table-footer { display: flex; align-items: center; justify-content: space-between; padding: 13px 20px; background: rgba(255,255,255,0.01); border-top: 1px solid var(--color-border); }

/* ── Pagination ──────────────────────────────────────────── */
.pagination { display: flex; gap: 4px; align-items: center; }
.page-btn { width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-muted); font-family: "DM Sans", sans-serif; font-size: 13px; cursor: pointer; transition: 0.12s ease; }
.page-btn:hover:not(:disabled) { border-color: var(--color-border-active); color: var(--color-text-primary); }
.page-btn:disabled { opacity: 0.35; cursor: default; }
.page-btn.active { background: rgba(255,107,53,0.1); border-color: rgba(255,107,53,0.4); color: var(--color-accent); }
.page-ellipsis { width: 28px; text-align: center; font-size: 13px; color: var(--color-text-muted); }

/* ── Empty state ─────────────────────────────────────────── */
.empty-state { padding: 56px 24px; text-align: center; }
.empty-icon { font-size: 32px; color: var(--color-text-muted); margin-bottom: 12px; }
.empty-title { font-size: 16px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 6px; }
.empty-text { font-size: 13px; color: var(--color-text-muted); max-width: 360px; margin: 0 auto; line-height: 1.6; }

@media (max-width: 900px) { .hide-md { display: none !important; } td, th { padding: 12px 14px; } }
@media (max-width: 800px) { .main { margin-left: 0; } }
</style>
