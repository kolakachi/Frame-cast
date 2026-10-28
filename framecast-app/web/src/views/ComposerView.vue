<script setup>
// The Composer page (2026-10-28): the three ways into the composer on the page itself (each opens its own step of
// the New Video wizard), the user's recently edited composer videos, and the way into a daily Series.
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import NewVideoWizard from '../components/NewVideoWizard.vue'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const wizardRef = ref(null)
const channels = ref([])
const niches = ref([])
const recent = ref([])
const total = ref(0)
const loading = ref(true)
const cadence = ref('Daily')

const PATHS = [
  { key: 'scratch', eyebrow: 'Full control', title: 'Start from Scratch',
    text: 'Build every scene yourself. Pick a title and format, then drop straight into the editor.', tags: ['Manual scenes'] },
  { key: 'brief', eyebrow: 'Most faceless videos start here', title: 'Generate from a brief', recommended: true,
    text: "Tell us your topic and we'll write the script, break it into scenes and pre-fill visuals.", tags: ['Script + scenes', 'Niche presets', '~90 s setup'] },
  { key: 'oneshot', eyebrow: 'Instant demo', title: 'One-shot from a prompt',
    text: 'Type one line and get back a single scene with AI image, animation, voice and music. About 90 seconds.', tags: ['One scene', 'Full pipeline'] },
]
const CADENCES = ['Daily', '3× per week', 'Weekly']

const nicheName = computed(() => Object.fromEntries(niches.value.map(n => [String(n.id), n.name])))

function openPath(key) {
  wizardRef.value?.openPath(key)
}

function status(p) {
  if (p.status === 'failed') return { text: 'Failed', tone: 'bad' }
  if (p.status === 'generating' || p.generation_pending) return { text: 'Making…', tone: 'busy' }
  if (p.exported_at) return { text: 'Exported', tone: 'good' }
  if (p.source_type === 'blank' && !p.scenes_count) return { text: 'Draft · no scenes yet', tone: 'muted' }
  if (p.status === 'ready_for_review') return { text: 'Ready to export', tone: 'good' }
  return { text: `Draft · ${p.scenes_count || 0} scene${p.scenes_count === 1 ? '' : 's'}`, tone: 'muted' }
}

function meta(p) {
  const parts = []
  if (p.niche_id && nicheName.value[String(p.niche_id)]) parts.push(nicheName.value[String(p.niche_id)])
  if (p.duration_target_seconds) parts.push(`${p.duration_target_seconds} s`)
  return parts.join(' · ')
}

function edited(p) {
  const t = new Date(p.updated_at || p.created_at).getTime()
  if (!t) return ''
  const mins = Math.round((Date.now() - t) / 60000)
  if (mins < 2) return 'Edited just now'
  if (mins < 60) return `Edited ${mins} min ago`
  const hours = Math.round(mins / 60)
  if (hours < 24) return `Edited ${hours} h ago`
  const days = Math.round(hours / 24)
  if (days === 1) return 'Edited yesterday'
  if (days < 7) return `Edited ${days} days ago`
  return `Edited ${new Date(t).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}`
}

function openProject(p) {
  // The same routing as the video lists: still making → progress, otherwise the editor.
  if (p.status === 'generating' || p.generation_pending || (Number(p.id) >= 216 && p.status === 'ready_for_review' && p.source_type !== 'blank' && !p.visual_brief?.editor_opened_at)) {
    router.push({ name: 'generation-progress', params: { projectId: p.id } })
  } else {
    router.push({ name: 'project-editor', params: { projectId: p.id } })
  }
}

function setUpSeries() {
  router.push({ name: 'series-create', query: { cadence: cadence.value } })
}

function logout() {
  authStore.logout()
  router.push({ name: 'login' })
}

onMounted(async () => {
  const [list, ch, ni] = await Promise.allSettled([
    api.get('/projects', { params: { kind: 'composer', sort: 'updated', per_page: 8 } }),
    api.get('/channels'),
    api.get('/niches'),
  ])
  if (list.status === 'fulfilled') {
    recent.value = list.value.data?.data?.projects ?? []
    total.value = list.value.data?.meta?.pagination?.total ?? recent.value.length
  }
  if (ch.status === 'fulfilled') channels.value = ch.value.data?.data?.channels ?? ch.value.data?.data ?? []
  if (ni.status === 'fulfilled') niches.value = ni.value.data?.data?.niches ?? ni.value.data?.data ?? []
  loading.value = false
  // A link straight to one way in: /composer?start=brief
  if (['scratch', 'brief', 'oneshot'].includes(String(route.query.start || ''))) openPath(String(route.query.start))
})
</script>

<template>
  <div class="shell">
    <AppSidebar :user="authStore.user" active-page="composer" @logout="logout" />

    <main class="main">
      <div class="col">
        <header>
          <h1 class="page-title">Make a faceless video</h1>
          <p class="page-sub">Start a new one, or pick up where you left off.</p>
        </header>

        <section class="paths" aria-label="How do you want to start?">
          <button v-for="p in PATHS" :key="p.key" :class="['path', p.recommended ? 'rec' : '']" type="button" @click="openPath(p.key)">
            <span class="eyebrow">{{ p.eyebrow }}</span>
            <b>{{ p.title }}</b>
            <span class="text">{{ p.text }}</span>
            <span class="tags"><i v-for="t in p.tags" :key="t">{{ t }}</i></span>
          </button>
        </section>

        <section class="card" aria-labelledby="recent-h">
          <div class="card-head">
            <h2 id="recent-h">Recently updated</h2>
            <router-link v-if="total > recent.length" class="link" :to="{ name: 'videos' }">All videos</router-link>
          </div>
          <div v-if="loading" class="grid">
            <div v-for="n in 4" :key="n" class="tile skeleton"></div>
          </div>
          <template v-else-if="recent.length">
            <div class="grid">
              <button v-for="p in recent" :key="p.id" class="tile" type="button" @click="openProject(p)">
                <div class="thumb" :style="p.poster_url ? { backgroundImage: `url(${p.poster_url})` } : {}">
                  <span v-if="!p.poster_url" class="thumb-title">{{ p.title || 'Untitled' }}</span>
                </div>
                <div class="tile-meta">
                  <b class="tile-title">{{ p.title || 'Untitled' }}</b>
                  <span v-if="meta(p)">{{ meta(p) }}</span>
                  <span>{{ edited(p) }}</span>
                  <span :class="['st', status(p).tone]">{{ status(p).text }}</span>
                </div>
              </button>
            </div>
          </template>
          <div v-else class="empty">
            <b>No composer videos yet</b>
            <span>Your composer videos will show here as you make them. Most faceless videos start with
              <button class="inline-link" type="button" @click="openPath('brief')">Generate from a brief</button>.</span>
            <span class="muted">Videos you made in Create or UGC Ads are in <router-link class="link" :to="{ name: 'videos' }">All Videos</router-link>.</span>
          </div>
        </section>

        <section class="card series" aria-labelledby="series-h">
          <div>
            <h2 id="series-h">Post every day without starting from zero</h2>
            <p class="muted">Turn your channel idea into a series: a new episode on your schedule, each one remembering the last. You approve every episode before it goes anywhere.</p>
            <div class="chips" role="group" aria-label="How often">
              <button v-for="c in CADENCES" :key="c" type="button" :class="['chip', cadence === c ? 'on' : '']" :aria-pressed="cadence === c" @click="cadence = c">{{ c }}</button>
            </div>
          </div>
          <div class="series-actions">
            <button class="btn-primary" type="button" @click="setUpSeries">Set up a series</button>
            <router-link class="link" :to="{ name: 'series' }">Your series</router-link>
          </div>
        </section>
      </div>
    </main>

    <NewVideoWizard ref="wizardRef" :channels="channels" />
  </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; background: var(--color-bg-base); }
.main { margin-left: var(--sidebar-width, 220px); flex: 1; padding: 32px 36px 64px; min-width: 0; }
.col { max-width: 920px; margin: 0 auto; display: grid; gap: 22px; }
.page-title { font-size: 22px; font-weight: 700; color: var(--color-text-primary); margin: 0 0 4px; }
.page-sub { font-size: 13px; color: var(--color-text-muted); margin: 0; }
h2 { font-size: 15px; font-weight: 700; color: var(--color-text-primary); margin: 0; }
.muted { color: var(--color-text-muted); font-size: 13px; }
.paths { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
.path { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 16px; text-align: left; cursor: pointer; display: grid; gap: 6px; align-content: start; color: var(--color-text-primary); font: inherit; transition: border-color .15s, transform .15s; }
.path:hover { border-color: var(--color-accent); transform: translateY(-1px); }
.path:focus-visible, .tile:focus-visible, .chip:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }
.path.rec { border-color: color-mix(in srgb, var(--color-accent) 55%, var(--color-border)); }
.path b { font-size: 15px; }
.path .text { color: var(--color-text-muted); font-size: 13px; line-height: 1.5; }
.eyebrow { font-family: 'Space Mono', monospace; font-size: 10px; letter-spacing: .08em; text-transform: uppercase; color: var(--color-text-muted); }
.path.rec .eyebrow { color: var(--color-accent); }
.tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.tags i { font-style: normal; font-family: 'Space Mono', monospace; font-size: 10px; color: var(--color-text-muted); background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 99px; padding: 4px 8px; }
.card { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 18px; display: grid; gap: 14px; }
.card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.link { color: var(--color-accent); font-size: 13px; font-weight: 600; text-decoration: none; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
.tile { background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden; padding: 0; cursor: pointer; text-align: left; display: grid; font: inherit; color: var(--color-text-primary); }
.tile:hover { border-color: var(--color-border-active); }
.tile.skeleton { height: 290px; cursor: default; opacity: .5; }
.thumb { aspect-ratio: 9 / 12; max-width: 100%; background: linear-gradient(160deg, var(--color-bg-base), var(--color-bg-elevated)) center / cover no-repeat; display: grid; place-items: end start; padding: 10px; }
.thumb-title { font-weight: 700; font-size: 14px; line-height: 1.2; color: var(--color-text-secondary); }
.tile-meta { padding: 9px 10px 11px; display: grid; gap: 2px; font-size: 12px; color: var(--color-text-muted); min-width: 0; }
.tile-title { color: var(--color-text-primary); font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.st { justify-self: start; margin-top: 4px; font-family: 'Space Mono', monospace; font-size: 10px; border-radius: 99px; padding: 3px 7px; background: var(--color-bg-panel); }
.st.good { color: var(--color-success, #4ade80); }
.st.busy { color: var(--color-accent); }
.st.bad { color: var(--color-danger, #f87171); }
.empty { display: grid; gap: 6px; justify-items: start; padding: 18px; border: 1px dashed var(--color-border); border-radius: 10px; color: var(--color-text-muted); font-size: 13px; }
.empty b { color: var(--color-text-primary); }
.inline-link { background: none; border: 0; padding: 0; color: var(--color-accent); font: inherit; font-weight: 600; cursor: pointer; }
.series { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
.series p { margin: 6px 0 0; max-width: 62ch; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 12px; }
.chip { border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); border-radius: 99px; padding: 6px 12px; font-size: 12px; cursor: pointer; font: inherit; }
.chip.on { border-color: var(--color-accent); color: var(--color-accent); }
.series-actions { display: grid; gap: 8px; justify-items: center; }
.btn-primary { background: var(--color-accent); color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: 13px; font-weight: 600; cursor: pointer; }
@media (max-width: 760px) {
  .main { margin-left: 0; padding: 20px 16px 48px; }
  .series { grid-template-columns: minmax(0, 1fr); }
  .series-actions { justify-items: start; }
}
</style>
