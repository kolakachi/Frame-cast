<script setup>
// The Composer page (2026-10-28, redesigned 2026-10-30): a hero, the three ways into the composer (each opens its
// own step of the New Video wizard), the user's recently edited composer videos with search and an
// All / Exported / Drafts filter, and the way into a daily Series.
import { ref, computed, onMounted, watch } from 'vue'
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
const loadError = ref(false)
const cadence = ref('Daily')
const search = ref('')
const show = ref('all')

const PATHS = [
  { key: 'scratch', title: 'Start from Scratch', icon: 'plus',
    text: 'Build every scene yourself. Pick a title and format, then drop straight into the editor.', tags: ['Manual scenes', 'Full control'] },
  { key: 'brief', title: 'Generate from a brief', icon: 'bolt', recommended: true,
    text: "Tell us your topic and we'll write the script, break it into scenes and pre-fill visuals.", tags: ['Script + scenes', 'Niche presets', '~90 s setup'] },
  { key: 'oneshot', title: 'One-shot from a prompt', icon: 'spark',
    text: 'Type one line and get back a scene with AI image, animation, voice and music.', tags: ['One scene', 'Full pipeline', '~90 seconds'] },
]
const SHOW_OPTIONS = [
  { value: 'all', label: 'All videos' },
  { value: 'yes', label: 'Exported' },
  { value: 'no', label: 'Drafts' },
]
const CADENCES = ['Daily', '3× per week', 'Weekly']
// The waveform in the hero's editor drawing.
const WAVE = Array.from({ length: 64 }, (_, i) => 4 + Math.round(Math.abs(Math.sin(i * 0.7) * 10 + Math.sin(i * 1.9) * 5)))

const nicheName = computed(() => Object.fromEntries(niches.value.map(n => [String(n.id), n.name])))
const filtering = computed(() => search.value.trim() !== '' || show.value !== 'all')

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

// The length the video was set to (the list has no measured duration).
function length(p) {
  const s = Number(p.duration_target_seconds)
  if (!s) return ''
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
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

function meta(p) {
  return p.niche_id && nicheName.value[String(p.niche_id)] ? nicheName.value[String(p.niche_id)] : ''
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

let listSeq = 0
async function loadRecent() {
  const seq = ++listSeq
  loading.value = true
  loadError.value = false
  const params = { kind: 'composer', sort: 'updated', per_page: 8 }
  if (search.value.trim()) params.q = search.value.trim()
  if (show.value !== 'all') params.exported = show.value
  try {
    const res = await api.get('/projects', { params })
    if (seq !== listSeq) return
    recent.value = res.data?.data?.projects ?? []
    total.value = res.data?.meta?.pagination?.total ?? recent.value.length
  } catch {
    if (seq === listSeq) loadError.value = true
  } finally {
    if (seq === listSeq) loading.value = false
  }
}

let searchTimer = null
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(loadRecent, 300)
})
watch(show, loadRecent)

onMounted(async () => {
  const [, ch, ni] = await Promise.allSettled([loadRecent(), api.get('/channels'), api.get('/niches')])
  if (ch.status === 'fulfilled') channels.value = ch.value.data?.data?.channels ?? ch.value.data?.data ?? []
  if (ni.status === 'fulfilled') niches.value = ni.value.data?.data?.niches ?? ni.value.data?.data ?? []
  // A link straight to one way in: /composer?start=brief
  if (['scratch', 'brief', 'oneshot'].includes(String(route.query.start || ''))) openPath(String(route.query.start))
})
</script>

<template>
  <div class="shell">
    <AppSidebar :user="authStore.user" active-page="composer" @logout="logout" />

    <main class="main">
      <div class="col">
        <section class="hero">
          <div class="hero-copy">
            <span class="eyebrow">Create · Edit · Publish</span>
            <h1 class="hero-title">Make a faceless video in <span class="accent">minutes</span></h1>
            <p class="hero-sub">Turn any idea, link or script into a ready-to-share short video.</p>
          </div>

          <!-- The editor, drawn: a tilted screen with scene thumbnails, a waveform and the four kinds of media. -->
          <div class="art" aria-hidden="true">
            <div class="device">
              <div class="dev-side">
                <i class="dev-line"></i><i class="dev-item on"></i><i class="dev-item"></i><i class="dev-item"></i><i class="dev-item"></i>
              </div>
              <div class="dev-frame">
                <svg viewBox="0 0 320 150" preserveAspectRatio="xMidYMid slice">
                  <defs>
                    <linearGradient id="cx-sky" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f6b28a" /><stop offset=".55" stop-color="#e0703f" /><stop offset="1" stop-color="#5a2617" /></linearGradient>
                    <linearGradient id="cx-mtn" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#8a4a33" /><stop offset="1" stop-color="#2b130c" /></linearGradient>
                  </defs>
                  <rect width="320" height="150" fill="url(#cx-sky)" />
                  <circle cx="230" cy="52" r="16" fill="#ffe0c6" opacity=".85" />
                  <path d="M0 120 L60 70 L95 92 L150 40 L205 95 L250 66 L320 110 L320 150 L0 150Z" fill="url(#cx-mtn)" />
                  <path d="M0 150 L0 128 L70 104 L130 124 L200 100 L260 126 L320 118 L320 150Z" fill="#1f0d08" />
                </svg>
                <span class="dev-play"><svg viewBox="0 0 24 24"><path d="M8 5l11 7-11 7z" fill="#fff" /></svg></span>
              </div>
              <div class="dev-tl">
                <div class="dev-thumbs"><i></i><i></i><i></i><i></i><i class="dev-cur"></i></div>
                <div class="dev-wave"><i v-for="(h, i) in WAVE" :key="i" :style="{ height: `${h}px` }"></i></div>
              </div>
            </div>
            <span class="float f-text"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M5 6h14M12 6v13" /></svg></span>
            <span class="float f-image"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8"><path d="M4 5h16v14H4z" /><path d="M4 16l5-5 4 4 3-3 4 4" /><circle cx="15.5" cy="9" r="1.5" /></svg></span>
            <span class="float f-music"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M9 18V6l10-2v12" /><circle cx="6.5" cy="18" r="2.5" /><circle cx="16.5" cy="16" r="2.5" /></svg></span>
            <span class="float f-spark"><svg viewBox="0 0 24 24" fill="#fff"><path d="M12 3l1.9 5.6L19.5 10.5l-5.6 1.9L12 18l-1.9-5.6L4.5 10.5l5.6-1.9z" /></svg></span>
          </div>
        </section>

        <section class="paths" aria-label="How do you want to start?">
          <button v-for="p in PATHS" :key="p.key" :class="['path', p.recommended ? 'rec' : '']" type="button" @click="openPath(p.key)">
            <span v-if="p.recommended" class="badge">Most popular</span>
            <span :class="['ic', `ic-${p.icon}`]" aria-hidden="true">
              <svg v-if="p.icon === 'plus'" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="8" /><path d="M12 8v8M8 12h8" /></svg>
              <svg v-else-if="p.icon === 'bolt'" viewBox="0 0 24 24" fill="#fff"><path d="M13 2L5 14h6l-1 8 8-12h-6z" /></svg>
              <svg v-else viewBox="0 0 24 24" fill="#e9d5ff"><path d="M12 3l1.9 5.6L19.5 10.5l-5.6 1.9L12 18l-1.9-5.6L4.5 10.5l5.6-1.9z" /></svg>
            </span>
            <b class="path-title">{{ p.title }}</b>
            <svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
            <span class="path-text">{{ p.text }}</span>
            <span class="tags"><i v-for="t in p.tags" :key="t">{{ t }}</i></span>
          </button>
        </section>

        <section class="card" aria-labelledby="recent-h">
          <div class="card-head">
            <div class="card-title">
              <h2 id="recent-h">Recently updated</h2>
              <span class="muted">Your latest composer videos and drafts</span>
            </div>
            <div class="tools">
              <router-link class="link" :to="{ name: 'videos' }">View all ›</router-link>
              <label class="search">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path d="M20 20l-4-4" /></svg>
                <input v-model="search" type="search" placeholder="Search videos…" aria-label="Search composer videos" />
              </label>
              <select v-model="show" class="show" aria-label="Show">
                <option v-for="o in SHOW_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </div>
          </div>
          <div v-if="loading && !recent.length" class="grid">
            <div v-for="n in 4" :key="n" class="tile skeleton"></div>
          </div>
          <div v-else-if="loadError" class="empty">
            <b>Your videos didn't load</b>
            <button class="inline-link" type="button" @click="loadRecent">Try again</button>
          </div>
          <div v-else-if="recent.length" :class="['grid', loading ? 'is-loading' : '']">
            <button v-for="p in recent" :key="p.id" class="tile" type="button" @click="openProject(p)">
              <div class="thumb" :style="p.poster_url ? { backgroundImage: `url(${p.poster_url})` } : {}">
                <span v-if="!p.poster_url" class="thumb-title">{{ p.title || 'Untitled' }}</span>
                <span v-if="length(p)" class="dur" title="Length set for this video">{{ length(p) }}</span>
              </div>
              <div class="tile-meta">
                <b class="tile-title">{{ p.title || 'Untitled' }}</b>
                <span>{{ [meta(p), edited(p)].filter(Boolean).join(' · ') }}</span>
                <span :class="['st', status(p).tone]">{{ status(p).text }}</span>
              </div>
            </button>
          </div>
          <div v-else-if="filtering" class="empty">
            <b>No videos match</b>
            <span>Try another word, or show <button class="inline-link" type="button" @click="search = ''; show = 'all'">all videos</button>.</span>
          </div>
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
.main { margin-left: var(--sidebar-width, 220px); flex: 1; padding: 28px 32px 64px; min-width: 0; }
.col { max-width: 1240px; margin: 0 auto; display: grid; gap: 22px; }
h2 { font-size: 22px; font-weight: 700; color: var(--color-text-primary); margin: 0; }
.muted { color: var(--color-text-muted); font-size: 14px; }
.link { color: var(--color-accent-hover); font-size: 14px; font-weight: 600; text-decoration: none; }

/* Hero */
.hero { position: relative; overflow: hidden; border-radius: 22px; border: 1px solid #1f1a1a; padding: 44px 48px; display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr); gap: 24px; align-items: center; min-height: 300px;
  background: radial-gradient(70% 120% at 85% 30%, rgba(255,107,53,.30), rgba(255,107,53,0) 60%), radial-gradient(60% 90% at 10% 0%, rgba(255,107,53,.12), rgba(255,107,53,0) 60%), #0e0b0b; }
.hero-copy { position: relative; z-index: 1; display: grid; gap: 14px; }
.eyebrow { font-family: var(--font-mono, 'Space Mono', monospace); font-size: 12px; letter-spacing: .28em; text-transform: uppercase; color: var(--color-accent-hover); }
.hero-title { margin: 0; font-size: 50px; line-height: 1.06; letter-spacing: -.02em; font-weight: 700; color: var(--color-text-primary); text-wrap: balance; }
.accent { color: var(--color-accent); }
.hero-sub { margin: 0; font-size: 17px; line-height: 1.5; color: var(--color-text-secondary); max-width: 46ch; }

.art { position: relative; height: 260px; perspective: 1400px; }
.device { position: absolute; right: 30px; top: 6px; width: 440px; height: 250px; transform: rotateY(-20deg) rotateX(10deg) rotateZ(-5deg); border-radius: 18px; background: #14141c; border: 1px solid #3a2a24;
  box-shadow: 0 40px 80px rgba(0,0,0,.6), 0 0 0 6px #1b1b24, 0 0 60px rgba(255,107,53,.25); padding: 12px; display: grid; grid-template-columns: 92px minmax(0, 1fr); grid-template-rows: minmax(0, 1fr) 70px; gap: 10px; }
.dev-side { grid-row: 1 / 3; display: flex; flex-direction: column; gap: 7px; padding-top: 4px; }
.dev-line { height: 8px; width: 60%; border-radius: 4px; background: #2a2a36; }
.dev-item { height: 22px; border-radius: 6px; background: #1d1d28; }
.dev-item.on { background: rgba(255,107,53,.2); border: 1px solid rgba(255,107,53,.5); }
.dev-frame { position: relative; border-radius: 10px; overflow: hidden; }
.dev-frame svg { display: block; width: 100%; height: 100%; }
.dev-play { position: absolute; inset: 0; margin: auto; width: 40px; height: 40px; border-radius: 50%; background: var(--color-accent); display: grid; place-items: center; box-shadow: 0 6px 18px rgba(0,0,0,.4); }
.dev-play svg { width: 16px; height: 16px; }
.dev-tl { display: grid; grid-template-rows: 30px 26px; gap: 6px; }
.dev-thumbs { display: grid; grid-template-columns: repeat(4, 54px) minmax(0, 1fr); gap: 5px; }
.dev-thumbs i { border-radius: 5px; background: linear-gradient(135deg, #f6b28a, #7a3420); }
.dev-thumbs i:nth-child(2) { background: linear-gradient(135deg, #d58a63, #3c1a10); }
.dev-thumbs i:nth-child(3) { background: linear-gradient(135deg, #b7663f, #2a120b); }
.dev-thumbs i:nth-child(4) { background: linear-gradient(135deg, #8c4a2f, #1d0c07); }
.dev-thumbs .dev-cur { background: rgba(255,107,53,.18); border: 1px solid rgba(255,107,53,.4); }
.dev-wave { border-radius: 5px; background: #1d1d28; display: flex; align-items: center; gap: 2px; padding: 0 8px; overflow: hidden; }
.dev-wave i { flex: none; width: 2px; border-radius: 1px; background: var(--color-accent-hover); }
.float { position: absolute; width: 64px; height: 64px; border-radius: 16px; display: grid; place-items: center; background: linear-gradient(150deg, rgba(255,135,79,.9), rgba(194,65,12,.85)); border: 1px solid rgba(255,190,150,.45);
  box-shadow: 0 18px 40px rgba(0,0,0,.45), 0 0 30px rgba(255,107,53,.25), inset 0 1px 0 rgba(255,255,255,.35); }
.float svg { width: 30px; height: 30px; }
.f-text { right: 410px; top: 14px; transform: rotate(-10deg); }
.f-image { right: 452px; top: 124px; width: 54px; height: 54px; transform: rotate(-14deg); }
.f-music { right: -4px; top: -6px; transform: rotate(10deg); }
.f-spark { right: 6px; top: 150px; transform: rotate(8deg); }

/* Ways in */
.paths { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
.path { position: relative; display: grid; grid-template-columns: 56px minmax(0, 1fr) 20px; gap: 4px 16px; align-items: start; text-align: left; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 16px; padding: 22px 22px 20px; cursor: pointer; font: inherit; color: var(--color-text-primary); transition: border-color .15s, transform .15s; }
.path:hover { border-color: var(--color-border-active); transform: translateY(-2px); }
.path:focus-visible, .tile:focus-visible, .chip:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }
.path.rec { border-color: var(--color-accent); background: linear-gradient(180deg, rgba(255,107,53,.08), rgba(255,107,53,0) 60%), var(--color-bg-panel); }
.badge { position: absolute; top: 18px; right: 18px; font-family: var(--font-mono, 'Space Mono', monospace); font-size: 10.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #0a0a0f; background: var(--color-accent); border-radius: 6px; padding: 4px 8px; }
.ic { grid-row: 1 / 3; width: 56px; height: 56px; border-radius: 14px; display: grid; place-items: center; background: linear-gradient(145deg, #ff874f, #c2410c); box-shadow: inset 0 1px 0 rgba(255,255,255,.25), 0 8px 24px rgba(255,107,53,.25); }
.ic-spark { background: linear-gradient(145deg, #3b2a4f, #211a33); box-shadow: inset 0 1px 0 rgba(255,255,255,.12); }
.ic svg { width: 26px; height: 26px; }
.path-title { font-size: 19px; padding-top: 4px; }
.path.rec .path-title { padding-right: 112px; }
.chev { grid-row: 1 / 3; grid-column: 3; align-self: center; width: 20px; height: 20px; color: var(--color-text-secondary); }
.path-text { grid-column: 2; font-size: 14.5px; line-height: 1.5; color: var(--color-text-secondary); }
.tags { grid-column: 1 / 4; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.tags i { font-style: normal; font-size: 12.5px; color: var(--color-text-secondary); border: 1px solid var(--color-border); background: var(--color-bg-soft); border-radius: 99px; padding: 6px 12px; white-space: nowrap; }

/* Recently updated + Series */
.card { background: #0e0e14; border: 1px solid #1d1d28; border-radius: 18px; padding: 24px; display: grid; gap: 20px; }
.card-head { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-end; gap: 16px; }
.card-title { display: grid; gap: 4px; }
.tools { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.tools .link { margin-right: 8px; }
.search { display: flex; align-items: center; gap: 8px; border: 1px solid var(--color-border); background: var(--color-bg-panel); border-radius: 10px; padding: 0 12px; height: 42px; width: 280px; max-width: 100%; color: var(--color-text-muted); }
.search:focus-within { border-color: var(--color-accent); }
.search svg { width: 17px; height: 17px; flex: none; }
.search input { background: none; border: 0; outline: none; flex: 1; min-width: 0; font: inherit; font-size: 14px; color: var(--color-text-primary); }
.show { height: 42px; border: 1px solid var(--color-border); background: var(--color-bg-panel); border-radius: 10px; padding: 0 12px; font: inherit; font-size: 14px; font-weight: 600; color: var(--color-text-primary); cursor: pointer; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; transition: opacity .15s; }
.grid.is-loading { opacity: .55; }
.tile { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; padding: 0; cursor: pointer; text-align: left; display: grid; align-content: start; font: inherit; color: var(--color-text-primary); }
.tile:hover { border-color: var(--color-border-active); }
.tile.skeleton { height: 230px; cursor: default; opacity: .5; }
.thumb { position: relative; aspect-ratio: 16 / 10; max-width: 100%; background: linear-gradient(160deg, var(--color-bg-elevated), var(--color-bg-card)) center / cover no-repeat; display: grid; place-items: end start; padding: 10px; }
.thumb-title { font-weight: 700; font-size: 14px; line-height: 1.2; color: var(--color-text-secondary); padding-bottom: 26px; }
.dur { position: absolute; left: 10px; bottom: 10px; font-family: var(--font-mono, 'Space Mono', monospace); font-size: 12px; font-weight: 700; color: var(--color-text-primary); background: rgba(10,10,15,.82); border-radius: 6px; padding: 3px 7px; }
.tile-meta { padding: 12px 14px 14px; display: grid; gap: 6px; font-size: 13px; color: var(--color-text-muted); min-width: 0; }
.tile-title { color: var(--color-text-primary); font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.st { justify-self: start; font-size: 12px; font-weight: 600; border-radius: 99px; padding: 3px 10px; color: var(--color-text-secondary); background: var(--color-bg-soft); border: 1px solid var(--color-border); }
.st.good { color: #4ade80; background: rgba(74,222,128,.1); border-color: rgba(74,222,128,.35); }
.st.busy { color: var(--color-accent-hover); background: rgba(255,107,53,.1); border-color: rgba(255,107,53,.35); }
.st.bad { color: #f87171; background: rgba(248,113,113,.1); border-color: rgba(248,113,113,.35); }
.empty { display: grid; gap: 6px; justify-items: start; padding: 18px; border: 1px dashed var(--color-border); border-radius: 12px; color: var(--color-text-muted); font-size: 14px; }
.empty b { color: var(--color-text-primary); }
.inline-link { background: none; border: 0; padding: 0; color: var(--color-accent-hover); font: inherit; font-weight: 600; cursor: pointer; }
.series { grid-template-columns: minmax(0, 1fr) auto; align-items: center; }
.series h2 { font-size: 20px; }
.series p { margin: 8px 0 0; max-width: 64ch; line-height: 1.55; }
.chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.chip { border: 1px solid var(--color-border); background: var(--color-bg-soft); color: var(--color-text-secondary); border-radius: 99px; padding: 8px 14px; font: inherit; font-size: 13px; cursor: pointer; }
.chip.on { border-color: var(--color-accent); color: var(--color-accent-hover); }
.series-actions { display: grid; gap: 10px; justify-items: center; }
.btn-primary { background: var(--color-accent); color: #fff; border: none; border-radius: 10px; padding: 12px 22px; font: inherit; font-size: 14.5px; font-weight: 600; cursor: pointer; }

@media (max-width: 1240px) {
  .hero { grid-template-columns: minmax(0, 1fr); }
  .art { display: none; }
  .paths { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 760px) {
  .main { margin-left: 0; padding: 20px 16px 48px; }
  .hero { padding: 28px 22px; min-height: 0; }
  .hero-title { font-size: 34px; }
  .card { padding: 18px; }
  .search { width: 100%; }
  .series { grid-template-columns: minmax(0, 1fr); }
  .series-actions { justify-items: start; }
}
@media (prefers-reduced-motion: reduce) {
  .path, .grid { transition: none; }
  .path:hover { transform: none; }
}
</style>
