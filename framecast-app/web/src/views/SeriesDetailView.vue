<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import GridSkeleton from '../components/skeletons/GridSkeleton.vue'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()
const seriesId = Number(route.params.seriesId)

const series = ref(null)
const episodes = ref([])
const loading = ref(true)
const activeTab = ref('episodes')

const showEditBible = ref(false)
const bibleForm = ref({})
const savingBible = ref(false)


const showNewEpisode = ref(false)
const episodeForm = ref({ title: '', prompt: '' })
const creatingEpisode = ref(false)
const episodeError = ref('')

const TONE_OPTIONS = ['casual', 'educational', 'inspirational', 'entertaining']

const STEP_LABELS = {
  draft: 'Draft',
  generating: 'Generating',
  ready_for_review: 'Ready',
  published: 'Published',
  failed: 'Failed',
}


async function load() {
  loading.value = true
  try {
    const [serRes, epRes] = await Promise.all([
      api.get(`/series/${seriesId}`),
      api.get(`/series/${seriesId}/episodes`),
    ])
    series.value = serRes.data.data.series
    episodes.value = epRes.data.data.episodes || []
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

function openEditBible() {
  bibleForm.value = {
    concept_text: series.value?.concept_text || '',
    audience_text: series.value?.audience_text || '',
    tone: series.value?.tone || '',
    episode_format_template: series.value?.episode_format_template || '',
    always_include_tags: (series.value?.always_include_tags || []).join(', '),
    never_include_tags: (series.value?.never_include_tags || []).join(', '),
    memory_window: series.value?.memory_window ?? 3,
    auto_summarise: series.value?.auto_summarise ?? true,
  }
  showEditBible.value = true
}

async function saveBible() {
  savingBible.value = true
  try {
    const alwaysTags = bibleForm.value.always_include_tags
      .split(',').map(t => t.trim()).filter(Boolean)
    const neverTags = bibleForm.value.never_include_tags
      .split(',').map(t => t.trim()).filter(Boolean)

    const res = await api.patch(`/series/${seriesId}`, {
      concept_text: bibleForm.value.concept_text || null,
      audience_text: bibleForm.value.audience_text || null,
      tone: bibleForm.value.tone || null,
      episode_format_template: bibleForm.value.episode_format_template || null,
      always_include_tags: alwaysTags,
      never_include_tags: neverTags,
      memory_window: Number(bibleForm.value.memory_window),
      auto_summarise: bibleForm.value.auto_summarise,
    })
    series.value = { ...series.value, ...res.data.data.series }
    showEditBible.value = false
  } catch (e) {
    console.error(e)
  } finally {
    savingBible.value = false
  }
}


function statusClass(status) {
  if (status === 'ready_for_review') return 'status-ready'
  if (status === 'generating') return 'status-generating'
  if (status === 'failed') return 'status-failed'
  return 'status-default'
}

async function createEpisode() {
  if (!episodeForm.value.prompt.trim()) return
  creatingEpisode.value = true
  episodeError.value = ''
  try {
    const res = await api.post('/projects', {
      title: episodeForm.value.title.trim() || null,
      source_type: 'prompt',
      source_content_raw: episodeForm.value.prompt.trim(),
      series_id: seriesId,
    })
    const projectId = res.data.data.project.id
    router.push({ name: 'generation-progress', params: { projectId } })
  } catch (e) {
    episodeError.value = e?.response?.data?.error?.message || 'Could not create episode.'
    creatingEpisode.value = false
  }
}

function openNewEpisode() {
  episodeForm.value = { title: '', prompt: '' }
  episodeError.value = ''
  showNewEpisode.value = true
}

// ── Episode actions: rename / duplicate / delete ──────────────
const showEditEpisode = ref(false)
const editEpisodeForm = ref({ id: null, title: '' })
const savingEpisode = ref(false)
const busyEpId = ref(null)        // clone/delete in flight for a row
const confirmDeleteEp = ref(null) // the episode pending delete
const deletingEp = ref(false)

function openEditEpisode(ep) {
  editEpisodeForm.value = { id: ep.id, title: ep.title || '' }
  showEditEpisode.value = true
}

async function saveEpisodeTitle() {
  savingEpisode.value = true
  try {
    const title = editEpisodeForm.value.title.trim()
    await api.patch(`/projects/${editEpisodeForm.value.id}`, { title: title || null })
    episodes.value = episodes.value.map(e =>
      e.id === editEpisodeForm.value.id ? { ...e, title } : e,
    )
    showEditEpisode.value = false
  } catch (e) {
    console.error(e)
  } finally {
    savingEpisode.value = false
  }
}

async function cloneEpisode(ep) {
  if (busyEpId.value) return
  busyEpId.value = ep.id
  try {
    await api.post(`/projects/${ep.id}/duplicate`)
    await load()
  } catch (e) {
    console.error(e)
  } finally {
    busyEpId.value = null
  }
}

async function confirmDeleteEpisode() {
  if (!confirmDeleteEp.value) return
  deletingEp.value = true
  try {
    await api.delete(`/projects/${confirmDeleteEp.value.id}`)
    episodes.value = episodes.value.filter(e => e.id !== confirmDeleteEp.value.id)
    confirmDeleteEp.value = null
  } catch (e) {
    console.error(e)
  } finally {
    deletingEp.value = false
  }
}

// ── Delete (archive) the whole series ─────────────────────────
const showDeleteSeries = ref(false)
const deletingSeries = ref(false)

async function deleteSeries() {
  deletingSeries.value = true
  try {
    await api.delete(`/series/${seriesId}`)
    router.push({ name: 'series' })
  } catch (e) {
    console.error(e)
    deletingSeries.value = false
  }
}

function logout() {
  authStore.logout()
  router.push({ name: 'login' })
}

onMounted(load)
</script>

<template>
  <div class="shell">
    <AppSidebar :user="authStore.user" active-page="series" @logout="logout" />

    <main class="main">
      <div class="breadcrumb">
        <button class="breadcrumb-link" type="button" @click="router.push({ name: 'series' })">Series</button>
        <span class="breadcrumb-sep">›</span>
        <span class="breadcrumb-current">{{ series?.name || '…' }}</span>
      </div>

      <GridSkeleton v-if="loading" header :count="5" :min="220" :thumb-h="154" :lines="2" />

      <template v-else-if="series">
        <div class="series-hero">
          <div class="series-hero-left">
            <h1 class="page-title">{{ series.name }}</h1>
            <p v-if="series.description" class="page-sub">{{ series.description }}</p>
            <div class="hero-tags">
              <span v-if="series.tone" class="tag">{{ series.tone }}</span>
              <span class="tag">{{ series.memory_window }}-ep memory</span>
              <span v-if="series.auto_summarise" class="tag accent">Auto-summarise on</span>
            </div>
          </div>
          <div class="hero-stats">
            <div class="stat">
              <div class="stat-val">{{ episodes.length }}</div>
              <div class="stat-label">Episodes</div>
            </div>
          </div>
        </div>

        <div class="tabs">
          <button :class="['tab', activeTab === 'episodes' ? 'active' : '']" type="button" @click="activeTab = 'episodes'">Episodes</button>
          <button :class="['tab', activeTab === 'bible' ? 'active' : '']" type="button" @click="activeTab = 'bible'">Series Bible</button>
        </div>

        <div v-if="activeTab === 'episodes'" class="tab-content">
          <div class="tab-header">
            <span class="tab-count">{{ episodes.length }} episode{{ episodes.length !== 1 ? 's' : '' }}</span>
            <button class="btn-primary" type="button" @click="openNewEpisode">+ New Episode</button>
          </div>
          <div v-if="episodes.length === 0" class="empty-state">
            <p class="empty-title">No episodes yet</p>
            <p class="empty-sub">Hit "New Episode" to generate the first one. All episodes in this series share the same bible, visual style, and memory chain.</p>
          </div>
          <div v-else class="episodes-list">
            <div
              v-for="ep in episodes"
              :key="ep.id"
              class="episode-row"
              role="button"
              tabindex="0"
              @click="router.push({ name: 'project-editor', params: { projectId: ep.id } })"
            >
              <div class="ep-num">#{{ ep.episode_number ?? '—' }}</div>
              <div class="ep-info">
                <div class="ep-title">{{ ep.title || 'Untitled episode' }}</div>
                <div v-if="ep.has_summary" class="ep-summary-badge">Summary ready</div>
              </div>
              <span :class="['status-badge', statusClass(ep.status)]">{{ STEP_LABELS[ep.status] || ep.status }}</span>
              <div class="ep-actions" @click.stop>
                <button class="ep-action-btn" type="button" title="Rename" @click="openEditEpisode(ep)">✎</button>
                <button class="ep-action-btn" type="button" title="Duplicate" :disabled="busyEpId === ep.id" @click="cloneEpisode(ep)">{{ busyEpId === ep.id ? '…' : '⧉' }}</button>
                <button class="ep-action-btn ep-action-danger" type="button" title="Delete" @click="confirmDeleteEp = ep">✕</button>
              </div>
            </div>
          </div>
        </div>

        <div v-if="activeTab === 'bible'" class="tab-content">
          <div class="bible-view">
            <div class="bible-header">
              <h3 class="section-title">Series Bible</h3>
              <button class="btn-ghost-sm" type="button" @click="openEditBible">Edit</button>
            </div>

            <div class="bible-block">
              <div class="bible-label">Concept</div>
              <div class="bible-text">{{ series.concept_text || '—' }}</div>
            </div>
            <div class="bible-block">
              <div class="bible-label">Target Audience</div>
              <div class="bible-text">{{ series.audience_text || '—' }}</div>
            </div>
            <div class="bible-block">
              <div class="bible-label">Episode Format Template</div>
              <div class="bible-text bible-mono">{{ series.episode_format_template || '—' }}</div>
            </div>
            <div class="bible-row-2">
              <div class="bible-block">
                <div class="bible-label">Always include</div>
                <div class="bible-tags">
                  <span v-for="tag in (series.always_include_tags || [])" :key="tag" class="tag-green">{{ tag }}</span>
                  <span v-if="!series.always_include_tags?.length" class="bible-text">—</span>
                </div>
              </div>
              <div class="bible-block">
                <div class="bible-label">Never include</div>
                <div class="bible-tags">
                  <span v-for="tag in (series.never_include_tags || [])" :key="tag" class="tag-red">{{ tag }}</span>
                  <span v-if="!series.never_include_tags?.length" class="bible-text">—</span>
                </div>
              </div>
            </div>
            <div class="bible-block">
              <div class="bible-label">Episode Memory Window</div>
              <div class="bible-text">Last {{ series.memory_window }} episodes injected as context</div>
            </div>
          </div>

          <div class="danger-zone">
            <div class="danger-info">
              <div class="danger-title">Delete this series</div>
              <div class="danger-sub">Removes the series from your list. Existing episodes stay in All Videos.</div>
            </div>
            <button class="btn-danger" type="button" @click="showDeleteSeries = true">Delete series</button>
          </div>
        </div>

      </template>

      <div v-if="showEditBible" class="modal-overlay" @click.self="showEditBible = false">
        <div class="modal">
          <div class="modal-header">
            <h2 class="modal-title">Edit Series Bible</h2>
            <button class="modal-close" type="button" @click="showEditBible = false">✕</button>
          </div>
          <div class="modal-body">
            <div class="field">
              <label class="field-label">Concept</label>
              <textarea v-model="bibleForm.concept_text" class="field-textarea" rows="4" placeholder="What this show is about…"></textarea>
            </div>
            <div class="field">
              <label class="field-label">Target Audience</label>
              <input v-model="bibleForm.audience_text" class="field-input" type="text" placeholder="Who is this for?" />
            </div>
            <div class="field">
              <label class="field-label">Tone</label>
              <div class="tone-options">
                <button
                  v-for="t in TONE_OPTIONS"
                  :key="t"
                  :class="['tone-btn', bibleForm.tone === t ? 'active' : '']"
                  type="button"
                  @click="bibleForm.tone = bibleForm.tone === t ? '' : t"
                >{{ t }}</button>
              </div>
            </div>
            <div class="field">
              <label class="field-label">Episode Format Template</label>
              <textarea v-model="bibleForm.episode_format_template" class="field-textarea" rows="3" placeholder="Hook → Problem → Solution → CTA"></textarea>
            </div>
            <div class="field">
              <label class="field-label">Always include (comma-separated)</label>
              <input v-model="bibleForm.always_include_tags" class="field-input" type="text" placeholder="value proposition, brand name" />
            </div>
            <div class="field">
              <label class="field-label">Never include (comma-separated)</label>
              <input v-model="bibleForm.never_include_tags" class="field-input" type="text" placeholder="profanity, competitor names" />
            </div>
            <div class="field">
              <label class="field-label">Memory window (episodes)</label>
              <input v-model.number="bibleForm.memory_window" class="field-input" type="number" min="0" max="10" />
            </div>
            <div class="field field-row">
              <label class="field-label">Auto-summarise episodes</label>
              <input v-model="bibleForm.auto_summarise" type="checkbox" />
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn-ghost" type="button" @click="showEditBible = false">Cancel</button>
            <button class="btn-primary" type="button" :disabled="savingBible" @click="saveBible">{{ savingBible ? 'Saving…' : 'Save Bible' }}</button>
          </div>
        </div>
      </div>

      <div v-if="showNewEpisode" class="modal-overlay" @click.self="showNewEpisode = false">
        <div class="modal">
          <div class="modal-header">
            <h2 class="modal-title">New Episode</h2>
            <button class="modal-close" type="button" @click="showNewEpisode = false">✕</button>
          </div>
          <div class="modal-body">
            <div class="episode-series-badge">Series: {{ series?.name }}</div>
            <div class="field">
              <label class="field-label">Episode title <span class="field-hint">— optional</span></label>
              <input v-model="episodeForm.title" class="field-input" type="text" placeholder="e.g. Paystack — Nigeria's first fintech exit" />
            </div>
            <div class="field">
              <label class="field-label">What is this episode about? *</label>
              <textarea v-model="episodeForm.prompt" class="field-textarea" rows="4" placeholder="Describe the topic, subject, or angle. The AI will generate a full script using your series bible, format template, and episode memory."></textarea>
            </div>
            <div v-if="episodeError" class="episode-error">{{ episodeError }}</div>
            <div class="episode-meta-note">
              Tone, format, visual style, and episode number are inherited from the series automatically.
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn-ghost" type="button" @click="showNewEpisode = false">Cancel</button>
            <button class="btn-primary" type="button" :disabled="creatingEpisode || !episodeForm.prompt.trim()" @click="createEpisode">
              {{ creatingEpisode ? 'Creating…' : 'Generate Episode' }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="showEditEpisode" class="modal-overlay" @click.self="showEditEpisode = false">
        <div class="modal modal-sm">
          <div class="modal-header">
            <h2 class="modal-title">Rename episode</h2>
            <button class="modal-close" type="button" @click="showEditEpisode = false">✕</button>
          </div>
          <div class="modal-body">
            <div class="field">
              <label class="field-label">Episode title</label>
              <input v-model="editEpisodeForm.title" class="field-input" type="text" placeholder="Untitled episode" @keyup.enter="saveEpisodeTitle" />
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn-ghost" type="button" @click="showEditEpisode = false">Cancel</button>
            <button class="btn-primary" type="button" :disabled="savingEpisode" @click="saveEpisodeTitle">{{ savingEpisode ? 'Saving…' : 'Save' }}</button>
          </div>
        </div>
      </div>

      <div v-if="confirmDeleteEp" class="modal-overlay" @click.self="confirmDeleteEp = null">
        <div class="modal modal-sm">
          <div class="modal-header">
            <h2 class="modal-title">Delete episode?</h2>
            <button class="modal-close" type="button" @click="confirmDeleteEp = null">✕</button>
          </div>
          <div class="modal-body">
            <p class="confirm-text">Delete <strong>{{ confirmDeleteEp.title || 'Untitled episode' }}</strong>? This can't be undone.</p>
          </div>
          <div class="modal-footer">
            <button class="btn-ghost" type="button" @click="confirmDeleteEp = null">Cancel</button>
            <button class="btn-danger" type="button" :disabled="deletingEp" @click="confirmDeleteEpisode">{{ deletingEp ? 'Deleting…' : 'Delete episode' }}</button>
          </div>
        </div>
      </div>

      <div v-if="showDeleteSeries" class="modal-overlay" @click.self="showDeleteSeries = false">
        <div class="modal modal-sm">
          <div class="modal-header">
            <h2 class="modal-title">Delete series?</h2>
            <button class="modal-close" type="button" @click="showDeleteSeries = false">✕</button>
          </div>
          <div class="modal-body">
            <p class="confirm-text">Delete <strong>{{ series?.name }}</strong>? It's removed from your series list. Existing episodes stay in All Videos.</p>
          </div>
          <div class="modal-footer">
            <button class="btn-ghost" type="button" @click="showDeleteSeries = false">Cancel</button>
            <button class="btn-danger" type="button" :disabled="deletingSeries" @click="deleteSeries">{{ deletingSeries ? 'Deleting…' : 'Delete series' }}</button>
          </div>
        </div>
      </div>

    </main>
  </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; background: var(--color-bg-base); }
.main { margin-left: var(--sidebar-width, 220px); flex: 1; padding: 32px 36px; min-width: 0; }

.breadcrumb { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; font-size: 13px; color: var(--color-text-muted); }
.breadcrumb-link { background: transparent; border: none; color: var(--color-text-muted); font-size: 13px; cursor: pointer; padding: 0; }
.breadcrumb-link:hover { color: var(--color-text-primary); }
.breadcrumb-sep { color: var(--color-border-active); }
.breadcrumb-current { color: var(--color-text-secondary); }

.series-hero { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 28px; }
.page-title { font-size: 22px; font-weight: 700; color: var(--color-text-primary); margin: 0 0 4px; }
.page-sub { font-size: 13px; color: var(--color-text-muted); margin: 0 0 10px; }
.hero-tags { display: flex; gap: 6px; flex-wrap: wrap; }
.hero-stats { display: flex; gap: 24px; }
.stat { text-align: right; }
.stat-val { font-size: 28px; font-weight: 700; color: var(--color-text-primary); font-family: 'Space Mono', monospace; }
.stat-label { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

.tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--color-border); margin-bottom: 24px; }
.tab { padding: 10px 16px; font-size: 13px; font-weight: 500; color: var(--color-text-muted); background: transparent; border: none; border-bottom: 2px solid transparent; cursor: pointer; transition: 0.15s; margin-bottom: -1px; }
.tab:hover { color: var(--color-text-primary); }
.tab.active { color: var(--color-accent); border-bottom-color: var(--color-accent); }

.tab-content { min-height: 200px; }

.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 60px 0; color: var(--color-text-muted); text-align: center; }
.empty-state-inline { color: var(--color-text-muted); font-size: 13px; padding: 24px 0; }
.empty-title { font-size: 15px; font-weight: 600; color: var(--color-text-primary); margin: 0; }
.empty-sub { font-size: 13px; margin: 0; }
.spinner { width: 28px; height: 28px; border: 2px solid var(--color-border); border-top-color: var(--color-accent); border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.episodes-list { display: flex; flex-direction: column; gap: 6px; }
.episode-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 10px; cursor: pointer; transition: 0.15s; }
.episode-row:hover { border-color: var(--color-border-active); }
.ep-num { font-family: 'Space Mono', monospace; font-size: 12px; color: var(--color-text-muted); width: 36px; flex-shrink: 0; }
.ep-info { flex: 1; min-width: 0; }
.ep-title { font-size: 13px; font-weight: 500; color: var(--color-text-primary); }
.ep-summary-badge { font-size: 10px; color: #34d399; margin-top: 3px; font-family: 'Space Mono', monospace; }

.status-badge { padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 700; font-family: 'Space Mono', monospace; }
.status-ready { background: rgba(52,211,153,0.12); color: #34d399; }
.status-generating { background: rgba(251,191,36,0.12); color: #fbbf24; }
.status-failed { background: rgba(248,113,113,0.12); color: #f87171; }
.status-default { background: var(--color-bg-elevated); color: var(--color-text-muted); }

.bible-view { display: flex; flex-direction: column; gap: 16px; }
.bible-header { display: flex; align-items: center; justify-content: space-between; }
.section-title { font-size: 14px; font-weight: 700; color: var(--color-text-primary); margin: 0; }
.bible-block { display: flex; flex-direction: column; gap: 4px; }
.bible-label { font-size: 10px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Space Mono', monospace; }
.bible-text { font-size: 13px; color: var(--color-text-secondary); line-height: 1.6; white-space: pre-wrap; }
.bible-mono { font-family: 'Space Mono', monospace; font-size: 11px; }
.bible-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.bible-tags { display: flex; gap: 6px; flex-wrap: wrap; }
.tag-green { background: rgba(52,211,153,0.12); color: #34d399; border-radius: 999px; padding: 2px 9px; font-size: 10px; font-weight: 600; font-family: 'Space Mono', monospace; }
.tag-red { background: rgba(248,113,113,0.12); color: #f87171; border-radius: 999px; padding: 2px 9px; font-size: 10px; font-weight: 600; font-family: 'Space Mono', monospace; }

.tab-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.tab-count { font-size: 12px; color: var(--color-text-muted); font-family: 'Space Mono', monospace; }

.char-explainer { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
.char-explainer-col { display: flex; gap: 10px; padding: 13px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 10px; }
.char-explainer-icon { font-size: 14px; flex-shrink: 0; color: var(--color-accent); margin-top: 1px; }
.char-explainer-title { font-size: 12px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.char-explainer-text { font-size: 11px; color: var(--color-text-muted); line-height: 1.55; }

.chars-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.char-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.char-card { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 10px; padding: 14px; }
.char-header { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.char-avatar { width: 32px; height: 32px; border-radius: 999px; background: linear-gradient(135deg,#7c3aed,#db2777); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
.char-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); flex: 1; }
.char-remove { background: transparent; border: none; color: var(--color-text-muted); font-size: 12px; cursor: pointer; padding: 2px 5px; border-radius: 4px; }
.char-remove:hover { background: rgba(248,113,113,0.1); color: #f87171; }
.char-field { margin-top: 6px; }
.char-field-label { font-size: 9px; font-weight: 700; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.06em; font-family: 'Space Mono', monospace; margin-bottom: 2px; }
.char-field-value { font-size: 12px; color: var(--color-text-secondary); line-height: 1.5; }

.tag { background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 999px; padding: 2px 9px; font-size: 10px; font-weight: 600; color: var(--color-text-muted); font-family: 'Space Mono', monospace; }
.tag.accent { background: rgba(255,107,53,0.1); border-color: rgba(255,107,53,0.2); color: var(--color-accent); }

.btn-primary { background: var(--color-accent); color: #fff; border: none; border-radius: 8px; padding: 9px 18px; font-size: 13px; font-weight: 600; cursor: pointer; transition: 0.15s; }
.btn-primary:hover:not(:disabled) { opacity: 0.88; }
.btn-primary:disabled { opacity: 0.5; cursor: default; }
.btn-ghost { background: transparent; color: var(--color-text-secondary); border: 1px solid var(--color-border); border-radius: 8px; padding: 9px 18px; font-size: 13px; cursor: pointer; }
.btn-ghost:hover { background: var(--color-bg-elevated); }
.btn-ghost-sm { background: transparent; border: 1px solid var(--color-border); color: var(--color-text-muted); border-radius: 6px; padding: 5px 12px; font-size: 12px; cursor: pointer; }
.btn-ghost-sm:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal { background: var(--color-bg-panel); border: 1px solid var(--color-border-active); border-radius: 14px; width: 100%; max-width: 500px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 60px rgba(0,0,0,0.5); }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 20px 20px 0; position: sticky; top: 0; background: var(--color-bg-panel); }
.modal-title { font-size: 16px; font-weight: 700; color: var(--color-text-primary); margin: 0; }
.modal-close { background: transparent; border: none; color: var(--color-text-muted); font-size: 16px; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.modal-close:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 0 20px 20px; position: sticky; bottom: 0; background: var(--color-bg-panel); }

.field { display: flex; flex-direction: column; gap: 6px; }
.field-row { flex-direction: row; align-items: center; gap: 10px; }
.field-label { font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.04em; font-family: 'Space Mono', monospace; }
.field-input { background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; padding: 9px 12px; font-size: 13px; color: var(--color-text-primary); outline: none; width: 100%; box-sizing: border-box; }
.field-input:focus { border-color: var(--color-border-active); }
.field-textarea { background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; padding: 9px 12px; font-size: 13px; color: var(--color-text-primary); outline: none; width: 100%; box-sizing: border-box; resize: vertical; font-family: inherit; line-height: 1.5; }
.field-textarea:focus { border-color: var(--color-border-active); }
.tone-options { display: flex; gap: 8px; flex-wrap: wrap; }
.tone-btn { padding: 6px 14px; border-radius: 999px; border: 1px solid var(--color-border); background: transparent; font-size: 12px; color: var(--color-text-muted); cursor: pointer; transition: 0.15s; text-transform: capitalize; }
.tone-btn:hover { background: var(--color-bg-elevated); }
.tone-btn.active { background: rgba(255,107,53,0.12); border-color: rgba(255,107,53,0.4); color: var(--color-accent); }

.series-hero-left { flex: 1; }

.episode-series-badge { display: inline-flex; padding: 4px 10px; border-radius: 6px; background: rgba(255,107,53,0.08); border: 1px solid rgba(255,107,53,0.2); color: var(--color-accent); font-size: 11px; font-weight: 600; font-family: 'Space Mono', monospace; margin-bottom: 4px; }
.episode-meta-note { font-size: 11px; color: var(--color-text-muted); background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 7px; padding: 9px 12px; }
.episode-error { font-size: 12px; color: #f87171; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.25); border-radius: 7px; padding: 9px 12px; }
.field-hint { font-size: 10px; color: var(--color-text-muted); opacity: 0.7; font-weight: 400; }

/* Episode row actions */
.ep-actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.ep-action-btn { width: 28px; height: 28px; display: grid; place-items: center; background: transparent; border: 1px solid var(--color-border); border-radius: 7px; color: var(--color-text-muted); font-size: 13px; cursor: pointer; transition: 0.12s; }
.ep-action-btn:hover:not(:disabled) { border-color: var(--color-border-active); color: var(--color-text-primary); }
.ep-action-btn:disabled { opacity: 0.5; cursor: default; }
.ep-action-danger:hover:not(:disabled) { border-color: rgba(248,113,113,0.4); color: #f87171; }

/* Danger zone */
.danger-zone { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-top: 28px; padding: 16px 18px; border: 1px solid rgba(248,113,113,0.2); border-radius: 12px; background: rgba(248,113,113,0.05); }
.danger-title { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.danger-sub { font-size: 12px; color: var(--color-text-muted); margin-top: 2px; }
.btn-danger { background: #f87171; color: #fff; border: none; border-radius: 8px; padding: 9px 18px; font-size: 13px; font-weight: 600; cursor: pointer; transition: 0.15s; flex-shrink: 0; }
.btn-danger:hover:not(:disabled) { background: #ef4444; }
.btn-danger:disabled { opacity: 0.6; cursor: default; }

/* Confirm modal */
.modal-sm { max-width: 420px; }
.confirm-text { font-size: 14px; color: var(--color-text-secondary); line-height: 1.6; margin: 0; }
.confirm-text strong { color: var(--color-text-primary); }
</style>
