<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'
import SchedulePostModal from '../components/SchedulePostModal.vue'

const router = useRouter()

// ── State ─────────────────────────────────────────────────
const viewMode      = ref('month') // month | week
const currentDate   = ref(new Date())
const posts         = ref([])
const loading       = ref(false)
const activePlatforms = ref(['youtube', 'tiktok'])

// Selected post popover
const selectedPost  = ref(null)
const popoverStyle  = ref({})

// Schedule modal
const scheduleOpen  = ref(false)
const scheduleExportId = ref(null)

// ── Date helpers ──────────────────────────────────────────
const monthLabel = computed(() => {
  return currentDate.value.toLocaleString('en', { month: 'long', year: 'numeric' })
})

const weekLabel = computed(() => {
  const start = weekStart(currentDate.value)
  const end   = new Date(start); end.setDate(end.getDate() + 6)
  return `${start.toLocaleString('en', { day: 'numeric', month: 'short' })} – ${end.toLocaleString('en', { day: 'numeric', month: 'short', year: 'numeric' })}`
})

function weekStart(d) {
  const date = new Date(d)
  date.setDate(date.getDate() - date.getDay())
  date.setHours(0, 0, 0, 0)
  return date
}

function prev() {
  const d = new Date(currentDate.value)
  if (viewMode.value === 'month') { d.setMonth(d.getMonth() - 1) }
  else { d.setDate(d.getDate() - 7) }
  currentDate.value = d
}

function next() {
  const d = new Date(currentDate.value)
  if (viewMode.value === 'month') { d.setMonth(d.getMonth() + 1) }
  else { d.setDate(d.getDate() + 7) }
  currentDate.value = d
}

function goToday() { currentDate.value = new Date() }

// ── Month grid ─────────────────────────────────────────────
const calendarDays = computed(() => {
  const year  = currentDate.value.getFullYear()
  const month = currentDate.value.getMonth()
  const first = new Date(year, month, 1)
  const last  = new Date(year, month + 1, 0)

  const days = []
  // Pad start
  for (let i = 0; i < first.getDay(); i++) {
    const d = new Date(year, month, 1 - (first.getDay() - i))
    days.push({ date: d, otherMonth: true })
  }
  // Current month
  for (let d = 1; d <= last.getDate(); d++) {
    days.push({ date: new Date(year, month, d), otherMonth: false })
  }
  // Pad end to fill 5 rows (35 cells min)
  while (days.length < 35) {
    const last = days[days.length - 1].date
    const next = new Date(last); next.setDate(next.getDate() + 1)
    days.push({ date: next, otherMonth: true })
  }
  return days
})

const weekDays = computed(() => {
  const start = weekStart(currentDate.value)
  return Array.from({ length: 7 }, (_, i) => {
    const d = new Date(start); d.setDate(d.getDate() + i)
    return d
  })
})

const HOURS = Array.from({ length: 16 }, (_, i) => i + 6) // 6am–10pm

function isToday(date) {
  const t = new Date()
  return date.getFullYear() === t.getFullYear() &&
    date.getMonth() === t.getMonth() &&
    date.getDate() === t.getDate()
}

function dateKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function postsForDate(date) {
  const key = dateKey(date)
  return posts.value.filter(p => {
    if (!p.scheduled_at) return false
    if (!activePlatforms.value.includes(p.platform)) return false
    return p.scheduled_at.startsWith(key)
  })
}

function postsForDateHour(date, hour) {
  return postsForDate(date).filter(p => {
    const h = new Date(p.scheduled_at).getHours()
    return h === hour
  })
}

// ── Stats ─────────────────────────────────────────────────
const stats = computed(() => {
  const monthPosts = posts.value.filter(p => {
    if (!p.scheduled_at) return false
    const d = new Date(p.scheduled_at)
    return d.getFullYear() === currentDate.value.getFullYear() &&
      d.getMonth() === currentDate.value.getMonth()
  })
  return {
    published: monthPosts.filter(p => p.status === 'published').length,
    scheduled: monthPosts.filter(p => p.status === 'scheduled').length,
    draft:     monthPosts.filter(p => p.status === 'draft').length,
    failed:    monthPosts.filter(p => p.status === 'failed').length,
  }
})

// ── Data loading ──────────────────────────────────────────
async function loadPosts() {
  loading.value = true
  try {
    const year  = currentDate.value.getFullYear()
    const month = currentDate.value.getMonth()
    const from  = new Date(year, month - 1, 1).toISOString()
    const to    = new Date(year, month + 2, 0).toISOString()
    const res   = await api.get('/scheduled-posts', { params: { from, to } })
    posts.value = res.data?.data?.posts ?? []
  } catch { /* ignore */ } finally {
    loading.value = false
  }
}

watch(currentDate, loadPosts)
onMounted(loadPosts)

// ── Post actions ──────────────────────────────────────────
function selectPost(post, event) {
  if (selectedPost.value?.id === post.id) { selectedPost.value = null; return }
  selectedPost.value = post
  const rect = event.currentTarget.getBoundingClientRect()
  popoverStyle.value = {
    top: `${rect.bottom + 8 + window.scrollY}px`,
    left: `${Math.min(rect.left, window.innerWidth - 300)}px`,
  }
}

async function retryPost(post) {
  await api.post(`/scheduled-posts/${post.id}/retry`)
  selectedPost.value = null
  loadPosts()
}

async function cancelPost(post) {
  if (!confirm('Cancel this scheduled post?')) return
  await api.delete(`/scheduled-posts/${post.id}`)
  selectedPost.value = null
  loadPosts()
}

function togglePlatform(key) {
  if (activePlatforms.value.includes(key)) {
    activePlatforms.value = activePlatforms.value.filter(k => k !== key)
  } else {
    activePlatforms.value = [...activePlatforms.value, key]
  }
}

function postClass(post) {
  return {
    scheduled: post.status === 'scheduled',
    published: post.status === 'published',
    failed:    post.status === 'failed',
    draft:     post.status === 'draft',
  }
}

function postPlatformIcon(platform) {
  return { youtube: '▶', tiktok: '♪', instagram: '◈', facebook: 'f' }[platform] ?? '○'
}

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleTimeString('en', { hour: 'numeric', minute: '2-digit', hour12: true })
}
</script>

<template>
  <div class="cal-layout">
    <AppSidebar active-page="calendar" />

    <div class="cal-main">
      <!-- Topbar -->
      <div class="cal-topbar">
        <div class="cal-topbar-title">Publishing Calendar</div>

        <div class="cal-nav">
          <button class="cal-nav-btn" @click="prev">‹</button>
          <div class="cal-period-label">{{ viewMode === 'month' ? monthLabel : weekLabel }}</div>
          <button class="cal-nav-btn" @click="next">›</button>
          <button class="cal-nav-btn cal-today-btn" @click="goToday">Today</button>
        </div>

        <div class="cal-view-toggle">
          <button :class="['vtog-btn', viewMode === 'month' ? 'active' : '']" @click="viewMode = 'month'">Month</button>
          <button :class="['vtog-btn', viewMode === 'week' ? 'active' : '']" @click="viewMode = 'week'">Week</button>
        </div>

        <div class="cal-platform-filter">
          <button
            v-for="p in [['youtube','▶'],['tiktok','♪']]" :key="p[0]"
            :class="['plat-filter-btn', activePlatforms.includes(p[0]) ? 'active' : '']"
            :title="p[0]"
            @click="togglePlatform(p[0])"
          >{{ p[1] }}</button>
        </div>

        <button class="btn btn-primary" style="font-size:12px" @click="scheduleOpen = true">+ Schedule Post</button>
      </div>

      <!-- Stats bar -->
      <div class="cal-stats-bar">
        <div class="cal-stat"><div class="cal-stat-dot" style="background:#34d399"></div><strong>{{ stats.published }}</strong> published</div>
        <div class="cal-stat"><div class="cal-stat-dot" style="background:#60a5fa"></div><strong>{{ stats.scheduled }}</strong> scheduled</div>
        <div class="cal-stat"><div class="cal-stat-dot" style="background:#6a6a7c"></div><strong>{{ stats.draft }}</strong> drafts</div>
        <div v-if="stats.failed" class="cal-stat"><div class="cal-stat-dot" style="background:#f87171"></div><strong>{{ stats.failed }}</strong> failed</div>
      </div>

      <!-- Month view -->
      <div v-if="viewMode === 'month'" class="cal-body">
        <div class="cal-day-headers">
          <div v-for="d in ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']" :key="d" class="cal-day-header">{{ d }}</div>
        </div>
        <div class="cal-grid">
          <div
            v-for="(day, i) in calendarDays" :key="i"
            :class="['cal-cell', day.otherMonth ? 'other-month' : '', isToday(day.date) ? 'today' : '']"
          >
            <div class="cal-date-num">{{ day.date.getDate() }}</div>
            <div class="cal-cell-posts">
              <button
                v-for="post in postsForDate(day.date)" :key="post.id"
                :class="['cal-post', postClass(post)]"
                @click.stop="selectPost(post, $event)"
              >
                <span class="cal-post-icon">{{ postPlatformIcon(post.platform) }}</span>
                <span class="cal-post-title">{{ post.project_title || 'Post' }}</span>
                <span class="cal-post-time">{{ formatTime(post.scheduled_at) }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Week view -->
      <div v-else class="cal-body cal-body-week">
        <div class="week-grid">
          <div class="week-time-col">
            <div class="week-day-header-spacer"></div>
            <div v-for="h in HOURS" :key="h" class="week-time-slot">
              {{ h === 12 ? '12 PM' : h > 12 ? `${h-12} PM` : `${h} AM` }}
            </div>
          </div>
          <div v-for="day in weekDays" :key="day.toISOString()" class="week-day-col">
            <div :class="['week-day-header', isToday(day) ? 'today' : '']">
              <div class="week-day-name">{{ day.toLocaleString('en', { weekday: 'short' }) }}</div>
              <div :class="['week-day-num', isToday(day) ? 'today-num' : '']">{{ day.getDate() }}</div>
            </div>
            <div v-for="h in HOURS" :key="h" class="week-slot">
              <button
                v-for="post in postsForDateHour(day, h)" :key="post.id"
                :class="['week-post-card', postClass(post)]"
                @click.stop="selectPost(post, $event)"
              >
                {{ postPlatformIcon(post.platform) }} {{ post.project_title || 'Post' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Post popover -->
    <Teleport to="body">
      <div v-if="selectedPost" class="cal-popover" :style="popoverStyle" @click.stop>
        <div class="cal-popover-head">
          <span>{{ postPlatformIcon(selectedPost.platform) }} {{ selectedPost.platform }}</span>
          <span :class="['tag', `tag-${selectedPost.status === 'published' ? 'green' : selectedPost.status === 'failed' ? 'red' : selectedPost.status === 'scheduled' ? 'blue' : 'gray'}`]">{{ selectedPost.status }}</span>
          <button class="popover-close" @click="selectedPost = null">×</button>
        </div>
        <div class="cal-popover-title">{{ selectedPost.project_title || 'Post' }}</div>
        <div class="cal-popover-meta">
          <span v-if="selectedPost.scheduled_at">📅 {{ new Date(selectedPost.scheduled_at).toLocaleString('en', { dateStyle: 'medium', timeStyle: 'short' }) }}</span>
          <span v-if="selectedPost.account">{{ selectedPost.account.display_name || selectedPost.account.username }}</span>
          <span v-if="selectedPost.failure_reason" style="color:#f87171">⚠ {{ selectedPost.failure_reason }}</span>
        </div>
        <div v-if="selectedPost.caption" class="cal-popover-caption">{{ selectedPost.caption }}</div>
        <div class="cal-popover-actions">
          <button v-if="selectedPost.status === 'failed'" class="btn btn-sm btn-primary" @click="retryPost(selectedPost)">↺ Retry</button>
          <button v-if="['scheduled','draft','failed'].includes(selectedPost.status)" class="btn btn-sm" @click="cancelPost(selectedPost)">✕ Cancel</button>
          <a v-if="selectedPost.platform_post_url" :href="selectedPost.platform_post_url" target="_blank" class="btn btn-sm">↗ View post</a>
        </div>
      </div>
      <div v-if="selectedPost" class="popover-overlay" @click="selectedPost = null"></div>
    </Teleport>

    <!-- Schedule post modal -->
    <SchedulePostModal v-if="scheduleOpen" @close="scheduleOpen = false" @scheduled="loadPosts(); scheduleOpen = false" />
  </div>
</template>

<style scoped>
.cal-layout { display: flex; min-height: 100vh; background: var(--color-bg-deep); }
.cal-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

/* Topbar */
.cal-topbar { display: flex; align-items: center; gap: 12px; padding: 14px 24px; background: var(--color-bg-card); border-bottom: 1px solid var(--color-border); flex-shrink: 0; flex-wrap: wrap; }
.cal-topbar-title { font-size: 15px; font-weight: 600; }
.cal-nav { display: flex; align-items: center; gap: 6px; margin-left: auto; }
.cal-nav-btn { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-primary); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 14px; transition: .15s; }
.cal-nav-btn:hover { background: var(--color-bg-elevated); }
.cal-today-btn { width: auto; padding: 0 10px; font-size: 11px; font-weight: 500; font-family: inherit; }
.cal-period-label { font-size: 13px; font-weight: 600; min-width: 160px; text-align: center; }
.cal-view-toggle { display: flex; border: 1px solid var(--color-border); border-radius: 7px; overflow: hidden; }
.vtog-btn { padding: 5px 12px; font-size: 11px; font-weight: 500; color: var(--color-text-muted); background: transparent; border: none; cursor: pointer; transition: .15s; font-family: inherit; }
.vtog-btn.active { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.cal-platform-filter { display: flex; gap: 4px; }
.plat-filter-btn { width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--color-border); display: flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; transition: .15s; background: transparent; color: var(--color-text-muted); font-family: inherit; }
.plat-filter-btn.active { color: var(--color-text-primary); background: var(--color-bg-elevated); border-color: var(--color-border-active); }

/* Stats */
.cal-stats-bar { display: flex; gap: 20px; padding: 10px 24px; background: var(--color-bg-card); border-bottom: 1px solid var(--color-border); flex-shrink: 0; }
.cal-stat { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--color-text-muted); }
.cal-stat-dot { width: 8px; height: 8px; border-radius: 50%; }
.cal-stat strong { color: var(--color-text-primary); font-weight: 600; }

/* Month grid */
.cal-body { flex: 1; overflow-y: auto; padding: 0; }
.cal-day-headers { display: grid; grid-template-columns: repeat(7, 1fr); background: var(--color-bg-card); border-bottom: 1px solid var(--color-border); position: sticky; top: 0; z-index: 2; }
.cal-day-header { padding: 8px; text-align: center; font-size: 10px; font-weight: 700; font-family: "Space Mono", monospace; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .06em; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--color-border); }
.cal-cell { background: var(--color-bg-card); min-height: 110px; padding: 8px; }
.cal-cell.other-month { background: var(--color-bg-deep); opacity: .6; }
.cal-cell.today { background: rgba(255,107,53,.04); }
.cal-date-num { font-size: 11px; font-weight: 600; color: var(--color-text-muted); width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; margin-bottom: 5px; }
.cal-cell.today .cal-date-num { color: var(--color-accent); background: var(--accent-soft, rgba(255,107,53,0.1)); border-radius: 50%; }
.cal-cell-posts { display: flex; flex-direction: column; gap: 3px; }
.cal-post { display: flex; align-items: center; gap: 4px; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 500; cursor: pointer; transition: .15s; border: 1px solid transparent; width: 100%; text-align: left; background: transparent; font-family: inherit; }
.cal-post.scheduled { background: rgba(96,165,250,.1); color: #60a5fa; border-color: rgba(96,165,250,.2); }
.cal-post.published { background: rgba(52,211,153,.1); color: #34d399; border-color: rgba(52,211,153,.2); }
.cal-post.failed { background: rgba(248,113,113,.1); color: #f87171; border-color: rgba(248,113,113,.2); }
.cal-post.draft { background: rgba(255,255,255,.04); color: var(--color-text-muted); border-color: var(--color-border); }
.cal-post-icon { font-size: 9px; flex-shrink: 0; }
.cal-post-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cal-post-time { opacity: .7; white-space: nowrap; font-size: 9px; }

/* Week grid */
.cal-body-week { overflow: auto; }
.week-grid { display: grid; grid-template-columns: 56px repeat(7, 1fr); min-width: 700px; }
.week-time-col { border-right: 1px solid var(--color-border); }
.week-day-header-spacer { height: 56px; border-bottom: 1px solid var(--color-border); }
.week-time-slot { height: 56px; padding: 4px 8px; font-size: 10px; color: var(--color-text-muted); font-family: "Space Mono", monospace; border-bottom: 1px solid var(--color-border); display: flex; align-items: flex-start; }
.week-day-col { border-right: 1px solid var(--color-border); }
.week-day-header { height: 56px; display: flex; flex-direction: column; align-items: center; justify-content: center; border-bottom: 1px solid var(--color-border); background: var(--color-bg-card); position: sticky; top: 0; z-index: 2; }
.week-day-header.today { background: rgba(255,107,53,.04); }
.week-day-name { font-size: 10px; font-weight: 700; font-family: "Space Mono", monospace; color: var(--color-text-muted); text-transform: uppercase; }
.week-day-num { font-size: 18px; font-weight: 600; color: var(--color-text-muted); }
.week-day-num.today-num { color: var(--color-accent); }
.week-slot { height: 56px; border-bottom: 1px solid var(--color-border); padding: 2px 4px; position: relative; }
.week-post-card { display: block; width: 100%; text-align: left; border-radius: 5px; padding: 3px 6px; font-size: 10px; font-weight: 500; cursor: pointer; border: none; font-family: inherit; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.week-post-card.scheduled { background: rgba(96,165,250,.15); color: #60a5fa; border-left: 2px solid #60a5fa; }
.week-post-card.published { background: rgba(52,211,153,.12); color: #34d399; border-left: 2px solid #34d399; }
.week-post-card.failed { background: rgba(248,113,113,.12); color: #f87171; border-left: 2px solid #f87171; }

/* Popover */
.cal-popover { position: fixed; background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 10px; padding: 14px 16px; width: 280px; box-shadow: 0 16px 40px rgba(0,0,0,.4); z-index: 200; }
.cal-popover-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 12px; font-weight: 500; }
.popover-close { margin-left: auto; background: none; border: none; color: var(--color-text-muted); cursor: pointer; font-size: 18px; line-height: 1; padding: 0; }
.cal-popover-title { font-size: 13px; font-weight: 600; margin-bottom: 6px; }
.cal-popover-meta { font-size: 11px; color: var(--color-text-muted); display: flex; flex-direction: column; gap: 3px; margin-bottom: 10px; }
.cal-popover-caption { font-size: 11px; color: var(--color-text-muted); background: var(--color-bg-elevated); border-radius: 6px; padding: 8px; margin-bottom: 10px; line-height: 1.45; max-height: 80px; overflow-y: auto; }
.cal-popover-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.popover-overlay { position: fixed; inset: 0; z-index: 199; }

/* Tags */
.tag { display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 999px; font-size: 10px; font-weight: 500; }
.tag-green { background: rgba(52,211,153,.1); color: #34d399; }
.tag-blue { background: rgba(96,165,250,.1); color: #60a5fa; }
.tag-red { background: rgba(248,113,113,.1); color: #f87171; }
.tag-gray { background: rgba(255,255,255,.05); color: var(--color-text-muted); }

/* Shared btn */
.btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; border-radius: 7px; font-size: 12px; font-weight: 500; cursor: pointer; transition: .15s; border: 1px solid var(--color-border); color: var(--color-text-primary); background: transparent; font-family: inherit; }
.btn:hover { background: var(--color-bg-elevated); }
.btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.btn-primary:hover { opacity: .9; }
.btn-sm { padding: 4px 10px; font-size: 11px; }
</style>
