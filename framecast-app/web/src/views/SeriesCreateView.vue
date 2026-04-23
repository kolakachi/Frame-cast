<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const router = useRouter()
const authStore = useAuthStore()

const currentStep = ref(1)
const completedSteps = ref(new Set())
const saving = ref(false)
const channels = ref([])
const voiceProfiles = ref([])
const stepErrors = ref({})

// ─── Form state ───────────────────────────────────────────
const form = ref({
  // Step 1 — Basics
  name: '',
  description: '',
  channel_id: null,
  platform_targets: [],
  aspect_ratio: '9:16',
  duration_target_seconds: 60,
  posting_cadence: null,
  // Step 2 — Series Bible
  concept_text: '',
  audience_text: '',
  tone: 'informative',
  tone_pace: 'balanced',
  episode_format_template: '',
  always_include_tags: [],
  never_include_tags: [],
  memory_window: 3,
  auto_summarise: true,
  // Step 3 — Visual Identity
  visual_mode: 'stock',
  visual_style: 'cinematic',
  visual_palette: 'Night Orange',
  visual_description: '',
  // Step 5 — Production Defaults
  default_voice_profile_id: null,
  default_music_setting: 'auto',
  default_music_volume: 20,
  default_language: 'en',
})

const alwaysTagInput = ref('')
const neverTagInput = ref('')

const PLATFORMS = [
  { id: 'tiktok', label: 'TikTok' },
  { id: 'instagram', label: 'Instagram' },
  { id: 'youtube_shorts', label: 'YouTube Shorts' },
  { id: 'linkedin', label: 'LinkedIn' },
  { id: 'x_twitter', label: 'X / Twitter' },
]

const ASPECT_RATIOS = [
  { id: '9:16', label: '9:16 Vertical' },
  { id: '16:9', label: '16:9 Wide' },
  { id: '1:1', label: '1:1 Square' },
]

const CADENCES = ['Daily', '3× per week', 'Weekly', 'Custom']

const TONES = ['Authoritative', 'Conversational', 'Informative', 'Entertaining', 'Inspirational']
const PACES = ['Fast-paced', 'Balanced', 'Measured', 'Intense']

const VISUAL_STYLES = [
  { id: 'cinematic', label: 'Cinematic', emoji: '🎬' },
  { id: 'editorial', label: 'Editorial', emoji: '📰' },
  { id: 'vivid', label: 'Vivid', emoji: '🎨' },
  { id: 'documentary', label: 'Documentary', emoji: '🌍' },
  { id: 'minimal', label: 'Minimal', emoji: '⬜' },
  { id: 'neon', label: 'Neon / Cyber', emoji: '🔮' },
  { id: 'warm', label: 'Warm / Earthy', emoji: '🌅' },
  { id: 'corporate', label: 'Corporate', emoji: '💼' },
]

const PALETTES = [
  { id: 'Night Orange', colors: ['#0a0a2a', '#1a1a4a', '#f97316', '#e8e8ea'] },
  { id: 'Deep Blue', colors: ['#071a2f', '#0c3a6b', '#3b82f6', '#e8f4fd'] },
  { id: 'Forest', colors: ['#0a1a0a', '#14532d', '#22c55e', '#f0fdf4'] },
  { id: 'Ember', colors: ['#1a0a00', '#7c2d12', '#ea580c', '#fff7ed'] },
  { id: 'Mono', colors: ['#18181b', '#3f3f46', '#d4d4d8', '#fafafa'] },
]

const MUSIC_OPTIONS = [
  { id: 'none', label: 'None' },
  { id: 'auto', label: 'Auto-match to tone' },
  { id: 'instrumental', label: 'Always instrumental' },
]

const STEPS = [
  { n: 1, name: 'Basics', desc: 'Name, channel & format' },
  { n: 2, name: 'Series Bible', desc: 'AI context for every episode' },
  { n: 3, name: 'Visual Identity', desc: 'Style, palette & mood board' },
  { n: 4, name: 'Characters', desc: 'Add after creation' },
  { n: 5, name: 'Production', desc: 'Voice, captions & music' },
]

// ─── Context pill state ───────────────────────────────────
const STEP_PILLS = {
  2: ['bible', 'format', 'memory'],
  3: ['visual'],
  5: ['prod'],
}

const resolvedPills = computed(() => {
  const r = new Set()
  for (let s = 1; s <= 5; s++) {
    if (completedSteps.value.has(s) || s === currentStep.value) {
      ;(STEP_PILLS[s] || []).forEach(p => r.add(p))
    }
  }
  return r
})

const CONSIST_BY_STEP = {
  2: ['Episode scene structure', 'Tone & voice style', 'Audience framing', 'Always / never rules', 'Episode memory chain'],
  3: ['Visual style & palette'],
  5: ['Voice & captions'],
}

const resolvedConsist = computed(() => {
  const r = new Set()
  for (let s = 1; s <= 5; s++) {
    if (completedSteps.value.has(s) || s === currentStep.value) {
      ;(CONSIST_BY_STEP[s] || []).forEach(c => r.add(c))
    }
  }
  return r
})

const allConsistRows = ['Episode scene structure', 'Tone & voice style', 'Audience framing', 'Always / never rules', 'Episode memory chain', 'Visual style & palette', 'Character descriptions', 'Voice & captions']

// ─── Validation ───────────────────────────────────────────
function validateStep(n) {
  if (n === 1 && !form.value.name.trim()) {
    stepErrors.value[1] = 'Series name is required to continue.'
    return false
  }
  if (n === 2 && !form.value.concept_text.trim()) {
    stepErrors.value[2] = 'Please describe what this series is about before continuing.'
    return false
  }
  stepErrors.value[n] = null
  return true
}

function canNavigateTo(n) {
  return n === currentStep.value || completedSteps.value.has(n) || n < currentStep.value
}

// ─── Navigation ───────────────────────────────────────────
function goStep(n) {
  if (n === currentStep.value) return
  if (n < currentStep.value) {
    currentStep.value = n
    return
  }
  if (!validateStep(currentStep.value)) return
  stepErrors.value[currentStep.value] = null
  completedSteps.value.add(currentStep.value)
  currentStep.value = n
}

function togglePlatform(id) {
  const idx = form.value.platform_targets.indexOf(id)
  if (idx === -1) form.value.platform_targets.push(id)
  else form.value.platform_targets.splice(idx, 1)
}

function addAlwaysTag(e) {
  if (e.key === 'Enter' || e.key === ',') {
    const val = alwaysTagInput.value.replace(',', '').trim()
    if (val && !form.value.always_include_tags.includes(val)) {
      form.value.always_include_tags.push(val)
    }
    alwaysTagInput.value = ''
    e.preventDefault()
  }
}

function addNeverTag(e) {
  if (e.key === 'Enter' || e.key === ',') {
    const val = neverTagInput.value.replace(',', '').trim()
    if (val && !form.value.never_include_tags.includes(val)) {
      form.value.never_include_tags.push(val)
    }
    neverTagInput.value = ''
    e.preventDefault()
  }
}

async function submit() {
  if (!form.value.name.trim()) { goStep(1); return }
  saving.value = true
  try {
    const payload = {
      ...form.value,
      name: form.value.name.trim(),
      concept_text: form.value.concept_text || null,
      audience_text: form.value.audience_text || null,
      episode_format_template: form.value.episode_format_template || null,
      visual_description: form.value.visual_description || null,
    }
    const res = await api.post('/series', payload)
    router.push({ name: 'series-detail', params: { seriesId: res.data.data.series.id } })
  } catch (e) {
    console.error(e)
  } finally {
    saving.value = false
  }
}

function logout() {
  authStore.logout()
  router.push({ name: 'login' })
}

onMounted(async () => {
  try {
    const [chRes, vpRes] = await Promise.all([
      api.get('/channels'),
      api.get('/voice-profiles'),
    ])
    channels.value = chRes.data.data.channels || []
    voiceProfiles.value = vpRes.data.data.voice_profiles || []
  } catch (e) {
    console.error(e)
  }
})
</script>

<template>
  <div class="shell">
    <AppSidebar :user="authStore.user" active-page="series" @logout="logout" />

    <div class="main">
      <!-- Topbar -->
      <div class="topbar">
        <div class="bc">
          <button class="bc-link" @click="router.push({ name: 'series' })">Series</button>
          <span class="bc-sep">/</span>
          <span class="bc-active">New Series</span>
        </div>
        <div class="topbar-right">
          <button class="btn btn-ghost btn-sm" @click="router.push({ name: 'series' })">Cancel</button>
          <button class="btn btn-primary btn-sm" :disabled="saving || !form.name.trim()" @click="submit">
            {{ saving ? 'Creating…' : 'Create Series' }}
          </button>
        </div>
      </div>

      <div class="page-body">

        <!-- Step rail -->
        <div class="step-rail">
          <div class="rail-title">Setup steps</div>
          <div
            v-for="s in STEPS"
            :key="s.n"
            :class="['step-item', currentStep === s.n ? 'active' : '', completedSteps.has(s.n) && currentStep !== s.n ? 'done' : '', !canNavigateTo(s.n) ? 'locked' : '']"
            @click="canNavigateTo(s.n) && goStep(s.n)"
          >
            <div class="step-num">
              <span v-if="completedSteps.has(s.n) && currentStep !== s.n">✓</span>
              <span v-else>{{ s.n }}</span>
            </div>
            <div class="step-info">
              <div class="step-name">{{ s.name }}</div>
              <div class="step-desc">{{ s.desc }}</div>
            </div>
          </div>
        </div>

        <!-- Form -->
        <div class="form-area">

          <!-- ── Step 1: Basics ── -->
          <div v-show="currentStep === 1" class="step-panel">
            <h2 class="step-title">Series Basics</h2>
            <p class="step-sub">Give your series an identity — name, channel and format.</p>

            <div class="field">
              <label class="field-label">Series name *</label>
              <input v-model="form.name" :class="['field-input', stepErrors[1] && !form.name.trim() ? 'field-input-error' : '']" type="text" placeholder="e.g. African Tech Startups, 60-Second Finance" @input="stepErrors[1] = null" />
            </div>

            <div class="field">
              <label class="field-label">Description</label>
              <textarea v-model="form.description" class="field-input" rows="2" placeholder="One line about what this series covers…"></textarea>
            </div>

            <div class="field">
              <label class="field-label">Channel</label>
              <select v-model="form.channel_id" class="field-input">
                <option :value="null">No channel — standalone series</option>
                <option v-for="ch in channels" :key="ch.id" :value="ch.id">{{ ch.name }}</option>
              </select>
            </div>

            <div class="section-divider"></div>
            <div class="section-label">Format &amp; platform</div>

            <div class="field">
              <label class="field-label">Target platforms</label>
              <div class="chip-row">
                <div
                  v-for="p in PLATFORMS"
                  :key="p.id"
                  :class="['chip', form.platform_targets.includes(p.id) ? 'selected' : '']"
                  @click="togglePlatform(p.id)"
                >{{ p.label }}</div>
              </div>
            </div>

            <div class="field-grid-2">
              <div class="field">
                <label class="field-label">Aspect ratio</label>
                <div class="chip-row">
                  <div
                    v-for="ar in ASPECT_RATIOS"
                    :key="ar.id"
                    :class="['chip', form.aspect_ratio === ar.id ? 'selected' : '']"
                    @click="form.aspect_ratio = ar.id"
                  >{{ ar.label }}</div>
                </div>
              </div>
              <div class="field">
                <label class="field-label">Episode duration target</label>
                <select v-model.number="form.duration_target_seconds" class="field-input">
                  <option :value="30">30 seconds</option>
                  <option :value="60">60 seconds</option>
                  <option :value="90">90 seconds</option>
                  <option :value="120">2 minutes</option>
                  <option :value="180">3 minutes</option>
                  <option :value="300">5+ minutes</option>
                </select>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Posting cadence <span class="field-hint">— optional</span></label>
              <div class="chip-row">
                <div
                  v-for="c in CADENCES"
                  :key="c"
                  :class="['chip', form.posting_cadence === c ? 'selected' : '']"
                  @click="form.posting_cadence = form.posting_cadence === c ? null : c"
                >{{ c }}</div>
              </div>
            </div>

            <div v-if="stepErrors[1]" class="step-error">{{ stepErrors[1] }}</div>
            <div class="form-actions">
              <span class="step-counter">Step 1 of 5</span>
              <button class="btn btn-primary" @click="goStep(2)">Continue → Series Bible</button>
            </div>
          </div>

          <!-- ── Step 2: Series Bible ── -->
          <div v-show="currentStep === 2" class="step-panel">
            <h2 class="step-title">Series Bible</h2>
            <p class="step-sub">The persistent context the AI receives every time it generates an episode. The more specific you are, the more consistent every episode will be.</p>

            <div class="callout callout-blue">
              <div class="callout-icon">⚡</div>
              <div>
                <div class="callout-title">How the AI uses this</div>
                <div class="callout-text">The bible is injected as a <strong>system prompt prefix</strong> before every episode. It combines with the rolling episode summary chain so the model always knows the show format, tone, audience, and rules — without you repeating it per episode.</div>
              </div>
            </div>

            <div class="field">
              <label class="field-label">What is this series about? <span class="field-hint">— one sharp sentence *</span></label>
              <textarea v-model="form.concept_text" :class="['field-input', stepErrors[2] && !form.concept_text.trim() ? 'field-input-error' : '']" rows="3" placeholder="e.g. A daily 60-second breakdown of one African tech startup — their founding story, problem, and what makes them different." @input="stepErrors[2] = null"></textarea>
            </div>

            <div class="field">
              <label class="field-label">Target audience</label>
              <input v-model="form.audience_text" class="field-input" type="text" placeholder="e.g. African professionals aged 25–40 interested in startups and tech culture" />
            </div>

            <div class="field-grid-2">
              <div class="field">
                <label class="field-label">Tone</label>
                <select v-model="form.tone" class="field-input">
                  <option v-for="t in TONES" :key="t" :value="t.toLowerCase()">{{ t }}</option>
                </select>
              </div>
              <div class="field">
                <label class="field-label">Pace</label>
                <select v-model="form.tone_pace" class="field-input">
                  <option v-for="p in PACES" :key="p" :value="p.toLowerCase()">{{ p }}</option>
                </select>
              </div>
            </div>

            <div class="section-divider"></div>
            <div class="section-label">Episode structure</div>

            <div class="callout callout-orange">
              <div class="callout-icon">◈</div>
              <div>
                <div class="callout-title">Recurring episode format</div>
                <div class="callout-text">Define the <strong>scene-by-scene structure</strong> the AI follows for every episode. This keeps pacing and content beats consistent across the series.</div>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Episode format template</label>
              <textarea v-model="form.episode_format_template" class="field-input tall" rows="6" placeholder="Scene 1 — Hook (5–8s): Open with a surprising stat or bold claim.&#10;Scene 2 — Founding story (10s): Who built it and why.&#10;Scene 3 — The problem they solve (10s): One sentence, tangible.&#10;Scene 4 — What makes them different (15s): Their edge or insight.&#10;Scene 5 — Traction or status (10s): Funding, users, or current state.&#10;Scene 6 — CTA (5s): 'Follow for tomorrow's startup.'"></textarea>
            </div>

            <div class="field-grid-2">
              <div class="field">
                <label class="field-label">Always include</label>
                <div class="tags-box">
                  <span v-for="tag in form.always_include_tags" :key="tag" class="tag tag-green">
                    {{ tag }} <span class="tag-x" @click="form.always_include_tags = form.always_include_tags.filter(t => t !== tag)">×</span>
                  </span>
                  <input v-model="alwaysTagInput" class="tag-input" placeholder="type + Enter…" @keydown="addAlwaysTag" />
                </div>
              </div>
              <div class="field">
                <label class="field-label">Never include</label>
                <div class="tags-box">
                  <span v-for="tag in form.never_include_tags" :key="tag" class="tag tag-red">
                    {{ tag }} <span class="tag-x" @click="form.never_include_tags = form.never_include_tags.filter(t => t !== tag)">×</span>
                  </span>
                  <input v-model="neverTagInput" class="tag-input" placeholder="type + Enter…" @keydown="addNeverTag" />
                </div>
              </div>
            </div>

            <div class="section-divider"></div>
            <div class="section-label">Episode memory &amp; continuity</div>

            <div class="callout callout-purple">
              <div class="callout-icon">⟳</div>
              <div>
                <div class="callout-title">How episode memory works</div>
                <div class="callout-text">After each episode is marked complete, Framecast <strong>auto-generates a ~150 word summary</strong>. When the next episode is created, the last <strong>N summaries</strong> are injected as context — so the AI knows what was covered and avoids repetition.</div>
              </div>
            </div>

            <!-- Context chain preview -->
            <div class="chain-box">
              <div class="chain-header">
                <span class="chain-label">Context chain — episode 4 sees this history</span>
                <span class="inject-tag">⚡ Series bible always injected</span>
              </div>
              <div class="chain-body">
                <div class="chain-node" v-for="(ep, i) in [{ep:'01',title:'Paystack — Nigeria\'s first global fintech exit',s:'Covered founding in Lagos 2015, Y Combinator run, and Stripe\'s $200M acquisition.'},{ep:'02',title:'Flutterwave — Africa\'s payment rails',s:'Covered $250M Series D, 34 countries. Second fintech episode.'},{ep:'03',title:'Andela — The talent export engine',s:'First non-fintech episode — diversifying the series intentionally.'}]" :key="i">
                  <div class="chain-dot-col"><div class="chain-dot accent"></div><div class="chain-connector"></div></div>
                  <div class="chain-card">
                    <div class="chain-ep-label">Ep {{ ep.ep }} · Auto-summary</div>
                    <div class="chain-ep-title">{{ ep.title }}</div>
                    <div class="chain-ep-body">{{ ep.s }}</div>
                  </div>
                </div>
                <div class="chain-node">
                  <div class="chain-dot-col"><div class="chain-dot green"></div></div>
                  <div class="chain-card chain-card-new">
                    <div class="chain-ep-label">Ep 04 · Generating now</div>
                    <div class="chain-ep-title">Your next episode</div>
                    <div class="chain-ep-body">AI receives the {{ form.memory_window }} summaries above + full series bible.</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Memory window <span class="field-hint">— how many past episodes the AI sees</span></label>
              <div class="range-row">
                <input v-model.number="form.memory_window" type="range" min="0" max="10" />
                <span class="range-val">{{ form.memory_window }} eps</span>
              </div>
            </div>

            <div class="toggle-row">
              <div :class="['toggle-track', form.auto_summarise ? '' : 'off']" @click="form.auto_summarise = !form.auto_summarise">
                <div class="toggle-thumb"></div>
              </div>
              <div class="toggle-info">
                <div class="toggle-label">Auto-summarise completed episodes</div>
                <div class="toggle-sub">When an episode moves to Ready for Review, a summary is generated and stored automatically.</div>
              </div>
            </div>

            <div v-if="stepErrors[2]" class="step-error">{{ stepErrors[2] }}</div>
            <div class="form-actions">
              <span class="step-counter">Step 2 of 5</span>
              <div class="actions-right">
                <button class="btn btn-ghost" @click="goStep(1)">← Back</button>
                <button class="btn btn-primary" @click="goStep(3)">Continue → Visual Identity</button>
              </div>
            </div>
          </div>

          <!-- ── Step 3: Visual Identity ── -->
          <div v-show="currentStep === 3" class="step-panel">
            <h2 class="step-title">Visual Identity</h2>
            <p class="step-sub">Define the visual language of the series. This feeds into AI image generation and stock clip matching so every episode looks consistent.</p>

            <div class="callout callout-green">
              <div class="callout-icon">◼</div>
              <div>
                <div class="callout-title">What this controls</div>
                <div class="callout-text">The visual style and description are injected into every scene's <strong>visual prompt</strong> as a style anchor. Both Pexels searches and AI image prompts draw from this to keep the look consistent across episodes.</div>
              </div>
            </div>

            <div class="section-label">Visual generation mode</div>
            <div class="field">
              <div class="chip-row">
                <div :class="['chip', form.visual_mode === 'stock' ? 'selected' : '']" @click="form.visual_mode = 'stock'">Stock clips (Pexels)</div>
                <div :class="['chip', form.visual_mode === 'ai' ? 'selected' : '']" @click="form.visual_mode = 'ai'">AI-generated images</div>
                <div :class="['chip', form.visual_mode === 'mixed' ? 'selected' : '']" @click="form.visual_mode = 'mixed'">Mixed — stocks first, AI for gaps</div>
              </div>
            </div>

            <div v-if="form.visual_mode !== 'stock'" class="section-divider"></div>
            <div v-if="form.visual_mode !== 'stock'" class="section-label">AI image style</div>
            <div v-if="form.visual_mode !== 'stock'" class="vs-grid">
              <div
                v-for="vs in VISUAL_STYLES"
                :key="vs.id"
                :class="['vs-card', form.visual_style === vs.id ? 'selected' : '']"
                @click="form.visual_style = vs.id"
              >
                <div :class="['vs-thumb', vs.id]">{{ vs.emoji }}</div>
                <div class="vs-label">{{ vs.label }}</div>
              </div>
            </div>

            <div class="section-divider"></div>
            <div class="section-label">Colour palette</div>
            <div class="palette-grid">
              <div
                v-for="p in PALETTES"
                :key="p.id"
                :class="['palette-card', form.visual_palette === p.id ? 'selected' : '']"
                @click="form.visual_palette = p.id"
              >
                <div class="palette-swatch">
                  <span v-for="c in p.colors" :key="c" :style="{ background: c }"></span>
                </div>
                <div class="palette-name">{{ p.id }}</div>
              </div>
            </div>

            <div class="field" style="margin-top:16px;">
              <label class="field-label">Visual style description <span class="field-hint">— used as prefix in all scene prompts</span></label>
              <textarea v-model="form.visual_description" class="field-input" rows="2" placeholder="e.g. Photorealistic, wide lens, dramatic side lighting, African urban settings, warm orange and blue tones, high contrast, slightly grainy…"></textarea>
            </div>

            <div class="form-actions">
              <span class="step-counter">Step 3 of 5</span>
              <div class="actions-right">
                <button class="btn btn-ghost" @click="goStep(2)">← Back</button>
                <button class="btn btn-primary" @click="goStep(4)">Continue → Characters</button>
              </div>
            </div>
          </div>

          <!-- ── Step 4: Characters (post-creation) ── -->
          <div v-show="currentStep === 4" class="step-panel">
            <h2 class="step-title">Characters</h2>
            <p class="step-sub">Characters are added after the series is created, on the series detail page. This keeps the wizard fast — you can define as many as you need once the series is live.</p>

            <div class="callout callout-purple">
              <div class="callout-icon">☻</div>
              <div>
                <div class="callout-title">How character descriptions are used</div>
                <div class="callout-text"><strong>Personality notes</strong> are injected into the script generation prompt. <strong>Visual descriptions</strong> are prepended to every AI image prompt involving this character — keeping faces, style, and presence consistent across episodes.</div>
              </div>
            </div>

            <div class="char-placeholder">
              <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
              <div class="char-placeholder-title">Characters are added on the series detail page</div>
              <div class="char-placeholder-sub">After you create the series, go to the Characters tab to define recurring personas, narrators, and visual archetypes.</div>
            </div>

            <div class="form-actions">
              <span class="step-counter">Step 4 of 5</span>
              <div class="actions-right">
                <button class="btn btn-ghost" @click="goStep(3)">← Back</button>
                <button class="btn btn-primary" @click="goStep(5)">Continue → Production</button>
              </div>
            </div>
          </div>

          <!-- ── Step 5: Production Defaults ── -->
          <div v-show="currentStep === 5" class="step-panel">
            <h2 class="step-title">Production Defaults</h2>
            <p class="step-sub">Set the default voice, music, and language for every episode. You can still override these per episode in the editor.</p>

            <div class="section-label">Voice</div>
            <div class="voice-list">
              <div
                v-for="vp in voiceProfiles.slice(0, 5)"
                :key="vp.id"
                :class="['voice-option', form.default_voice_profile_id === vp.id ? 'selected' : '']"
                @click="form.default_voice_profile_id = vp.id"
              >
                <div class="voice-avatar">{{ vp.name?.[0]?.toUpperCase() || 'V' }}</div>
                <div class="voice-info">
                  <div class="voice-name">{{ vp.name }}</div>
                  <div class="voice-meta">{{ vp.language }} · {{ vp.gender_label || vp.voice_type || 'Voice' }}</div>
                </div>
              </div>
              <div v-if="voiceProfiles.length === 0" class="voice-option" style="opacity:0.5;pointer-events:none;">
                <div class="voice-avatar">—</div>
                <div class="voice-info"><div class="voice-name">No voice profiles set up yet</div></div>
              </div>
            </div>

            <div class="section-divider"></div>
            <div class="section-label">Music</div>

            <div class="field">
              <div class="chip-row">
                <div
                  v-for="m in MUSIC_OPTIONS"
                  :key="m.id"
                  :class="['chip', form.default_music_setting === m.id ? 'selected' : '']"
                  @click="form.default_music_setting = m.id"
                >{{ m.label }}</div>
              </div>
            </div>

            <div class="field">
              <label class="field-label">Music volume</label>
              <div class="range-row">
                <input v-model.number="form.default_music_volume" type="range" min="0" max="100" />
                <span class="range-val">{{ form.default_music_volume }}%</span>
              </div>
            </div>

            <div class="section-divider"></div>
            <div class="section-label">Language</div>

            <div class="field">
              <label class="field-label">Primary language</label>
              <select v-model="form.default_language" class="field-input" style="max-width:260px;">
                <option value="en">English</option>
                <option value="fr">French</option>
                <option value="sw">Swahili</option>
                <option value="pt">Portuguese</option>
                <option value="ar">Arabic</option>
                <option value="es">Spanish</option>
              </select>
            </div>

            <div class="form-actions">
              <span class="step-counter">Step 5 of 5</span>
              <div class="actions-right">
                <button class="btn btn-ghost" @click="goStep(4)">← Back</button>
                <button class="btn btn-primary" :disabled="saving || !form.name.trim()" @click="submit">
                  {{ saving ? 'Creating…' : '✓ Create Series' }}
                </button>
              </div>
            </div>
          </div>

        </div>

        <!-- Preview panel -->
        <div class="preview-panel">
          <div class="preview-section">
            <div class="preview-label">Series preview</div>
            <div class="preview-card">
              <div class="preview-cover">
                <div class="preview-initial">{{ form.name?.[0]?.toUpperCase() || '?' }}</div>
                <div class="preview-badge">NEW</div>
              </div>
              <div class="preview-body">
                <div class="preview-name">{{ form.name || 'Untitled Series' }}</div>
                <div class="preview-meta">
                  <span v-if="form.platform_targets.length">{{ form.platform_targets[0] }} · {{ form.aspect_ratio }}</span>
                  <span v-else>{{ form.aspect_ratio }}</span>
                  <span>{{ form.duration_target_seconds }}s per episode</span>
                </div>
              </div>
            </div>
          </div>

          <div class="preview-section">
            <div class="preview-label">AI context layers</div>
            <div class="pill-list">
              <div :class="['pill', resolvedPills.has('bible') ? 'pill-ok' : 'pill-dim']">
                <div class="pill-icon">◼</div>
                <div class="pill-body"><strong>Series bible</strong><br>Step 2</div>
                <div class="pill-val">Always</div>
              </div>
              <div :class="['pill', resolvedPills.has('format') ? 'pill-ok' : 'pill-dim']">
                <div class="pill-icon">◈</div>
                <div class="pill-body"><strong>Episode format</strong><br>Step 2</div>
                <div class="pill-val">Always</div>
              </div>
              <div :class="['pill', resolvedPills.has('memory') ? 'pill-ok' : 'pill-dim']">
                <div class="pill-icon">⟳</div>
                <div class="pill-body"><strong>Episode memory</strong><br>Step 2</div>
                <div class="pill-val">Per ep</div>
              </div>
              <div :class="['pill', resolvedPills.has('visual') ? 'pill-ok' : 'pill-dim']">
                <div class="pill-icon">◼</div>
                <div class="pill-body"><strong>Visual identity</strong><br>Step 3</div>
                <div class="pill-val">Prompts</div>
              </div>
              <div class="pill pill-dim">
                <div class="pill-icon">☻</div>
                <div class="pill-body"><strong>Characters</strong><br>After creation</div>
                <div class="pill-val">Prompts</div>
              </div>
              <div :class="['pill', resolvedPills.has('prod') ? 'pill-ok' : 'pill-dim']">
                <div class="pill-icon">🔊</div>
                <div class="pill-body"><strong>Production</strong><br>Step 5</div>
                <div class="pill-val">Default</div>
              </div>
            </div>
          </div>

          <div class="preview-section">
            <div class="preview-label">Consistency locked by</div>
            <div class="consist-list">
              <div v-for="row in allConsistRows" :key="row" :class="['consist-row', resolvedConsist.has(row) ? 'done' : 'pending']">
                {{ resolvedConsist.has(row) ? '✓' : '◯' }} {{ row }}
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>
/* Layout */
.shell { display: flex; min-height: 100vh; background: var(--color-bg-base); }
.main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; min-width: 0; }
.topbar { height: 56px; border-bottom: 1px solid var(--color-border); padding: 0 24px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; background: var(--color-bg-base); position: sticky; top: 0; z-index: 80; }
.bc { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.bc-link { background: transparent; border: none; color: var(--color-text-muted); font-size: 13px; cursor: pointer; padding: 0; }
.bc-link:hover { color: var(--color-text-primary); }
.bc-sep { color: var(--color-text-muted); }
.bc-active { font-weight: 600; color: var(--color-text-primary); }
.topbar-right { display: flex; gap: 8px; }
.page-body { display: flex; flex: 1; overflow: hidden; }

/* Step rail */
.step-rail { width: 230px; flex-shrink: 0; padding: 24px 14px; border-right: 1px solid var(--color-border); display: flex; flex-direction: column; gap: 2px; overflow-y: auto; }
.rail-title { font-family: 'Space Mono', monospace; font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: 14px; padding-left: 2px; }
.step-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px; border-radius: 10px; cursor: pointer; border: 1px solid transparent; transition: 0.15s; }
.step-item:hover:not(.active) { background: var(--color-bg-elevated); }
.step-item.active { background: rgba(255,107,53,0.1); border-color: rgba(255,107,53,0.25); }
.step-item.done { opacity: 0.65; }
.step-item.locked { opacity: 0.35; cursor: not-allowed; pointer-events: none; }
.step-num { width: 22px; height: 22px; border-radius: 50%; border: 1px solid var(--color-border); display: flex; align-items: center; justify-content: center; font-family: 'Space Mono', monospace; font-size: 10px; font-weight: 700; color: var(--color-text-muted); flex-shrink: 0; margin-top: 1px; }
.step-item.active .step-num { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.step-item.done .step-num { background: rgba(52,211,153,0.15); border-color: rgba(52,211,153,0.35); color: #34d399; }
.step-info { flex: 1; }
.step-name { font-size: 13px; font-weight: 600; color: var(--color-text-secondary); }
.step-item.active .step-name { color: var(--color-accent); }
.step-desc { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

/* Form area */
.form-area { flex: 1; padding: 32px 40px 60px; overflow-y: auto; min-width: 0; }
.step-panel { max-width: 680px; }
.step-title { font-size: 22px; font-weight: 700; color: var(--color-text-primary); margin: 0 0 4px; }
.step-sub { font-size: 13px; color: var(--color-text-muted); margin: 0 0 24px; line-height: 1.55; }
.section-divider { border-top: 1px solid var(--color-border); margin: 24px 0; }
.section-label { font-family: 'Space Mono', monospace; font-size: 10px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--color-accent); margin-bottom: 14px; }

/* Fields */
.field { margin-bottom: 18px; }
.field-label { font-size: 11px; font-weight: 600; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Space Mono', monospace; display: block; margin-bottom: 7px; }
.field-hint { font-size: 10px; color: var(--color-text-muted); opacity: 0.7; font-weight: 400; }
.field-input { width: 100%; padding: 10px 12px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; color: var(--color-text-primary); font-family: inherit; font-size: 13px; outline: none; transition: border-color 0.15s; }
.field-input:focus { border-color: var(--color-border-active); }
.field-input-error { border-color: rgba(248,113,113,0.6) !important; background: rgba(248,113,113,0.04); }
.field-input::placeholder { color: var(--color-text-muted); opacity: 0.7; }
textarea.field-input { resize: vertical; line-height: 1.6; }
textarea.tall { min-height: 120px; }
select.field-input { appearance: none; cursor: pointer; }
.field-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 18px; }
.field-grid-2 .field { margin-bottom: 0; }

/* Chips */
.chip-row { display: flex; flex-wrap: wrap; gap: 8px; }
.chip { padding: 7px 14px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); font-size: 13px; font-weight: 500; color: var(--color-text-muted); cursor: pointer; transition: 0.15s; user-select: none; }
.chip:hover { color: var(--color-text-primary); }
.chip.selected { background: rgba(255,107,53,0.1); border-color: rgba(255,107,53,0.35); color: var(--color-accent); }

/* Callouts */
.callout { border: 1px solid var(--color-border); border-radius: 10px; padding: 13px 15px; display: flex; gap: 11px; margin-bottom: 20px; }
.callout-blue { background: rgba(59,130,246,0.06); border-color: rgba(59,130,246,0.2); }
.callout-orange { background: rgba(255,107,53,0.06); border-color: rgba(255,107,53,0.2); }
.callout-purple { background: rgba(168,85,247,0.06); border-color: rgba(168,85,247,0.2); }
.callout-green { background: rgba(52,211,153,0.06); border-color: rgba(52,211,153,0.2); }
.callout-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
.callout-title { font-size: 12px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.callout-text { font-size: 12px; color: var(--color-text-muted); line-height: 1.55; }
.callout-text strong { color: var(--color-text-secondary); }

/* Tags box */
.tags-box { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; min-height: 42px; }
.tag { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 12px; }
.tag-green { background: rgba(52,211,153,0.12); border: 1px solid rgba(52,211,153,0.3); color: #34d399; }
.tag-red { background: rgba(248,113,113,0.12); border: 1px solid rgba(248,113,113,0.3); color: #f87171; }
.tag-x { cursor: pointer; opacity: 0.6; font-size: 13px; }
.tag-x:hover { opacity: 1; }
.tag-input { border: none; background: transparent; outline: none; font-size: 12px; color: var(--color-text-primary); font-family: inherit; flex: 1; min-width: 80px; }
.tag-input::placeholder { color: var(--color-text-muted); }

/* Range */
input[type=range] { width: 100%; accent-color: var(--color-accent); }
.range-row { display: flex; align-items: center; gap: 12px; }
.range-val { font-family: 'Space Mono', monospace; font-size: 12px; color: var(--color-text-muted); min-width: 48px; text-align: right; }

/* Toggle */
.toggle-row { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; margin-bottom: 18px; }
.toggle-track { width: 36px; height: 20px; border-radius: 999px; background: var(--color-accent); position: relative; flex-shrink: 0; cursor: pointer; transition: 0.2s; }
.toggle-track.off { background: var(--color-border); }
.toggle-thumb { width: 16px; height: 16px; border-radius: 50%; background: #fff; position: absolute; top: 2px; right: 2px; transition: 0.2s; }
.toggle-track.off .toggle-thumb { right: auto; left: 2px; }
.toggle-label { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.toggle-sub { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

/* Context chain */
.chain-box { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; margin-bottom: 20px; }
.chain-header { padding: 11px 15px; border-bottom: 1px solid var(--color-border); display: flex; align-items: center; justify-content: space-between; }
.chain-label { font-family: 'Space Mono', monospace; font-size: 9px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-text-muted); }
.inject-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 7px; border-radius: 4px; background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.25); color: #3b82f6; font-family: 'Space Mono', monospace; font-size: 9px; font-weight: 700; }
.chain-body { padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.chain-node { display: flex; gap: 11px; align-items: flex-start; }
.chain-dot-col { display: flex; flex-direction: column; align-items: center; gap: 4px; }
.chain-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-border); flex-shrink: 0; margin-top: 5px; }
.chain-dot.accent { background: var(--color-accent); }
.chain-dot.green { background: #34d399; box-shadow: 0 0 0 3px rgba(52,211,153,0.15); }
.chain-connector { width: 1px; flex: 1; min-height: 16px; background: var(--color-border); }
.chain-card { flex: 1; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; padding: 9px 11px; }
.chain-ep-label { font-family: 'Space Mono', monospace; font-size: 9px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: 2px; }
.chain-ep-title { font-size: 12px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 3px; }
.chain-ep-body { font-size: 11px; color: var(--color-text-muted); line-height: 1.5; }
.chain-card-new { border-style: dashed; border-color: rgba(255,107,53,0.4); background: rgba(255,107,53,0.06); }
.chain-card-new .chain-ep-label { color: var(--color-accent); }
.chain-card-new .chain-ep-title { color: var(--color-accent); }

/* Visual style grid */
.vs-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 6px; }
.vs-card { border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden; cursor: pointer; transition: 0.15s; }
.vs-card:hover { border-color: var(--color-border-active); }
.vs-card.selected { border-color: var(--color-accent); box-shadow: 0 0 0 3px rgba(255,107,53,0.1); }
.vs-thumb { height: 56px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.vs-thumb.cinematic { background: linear-gradient(135deg,#0a0a1a,#1a1a3a); }
.vs-thumb.editorial { background: linear-gradient(135deg,#1a1a1a,#3a3a3a); }
.vs-thumb.vivid { background: linear-gradient(135deg,#1a0020,#3a0040); }
.vs-thumb.documentary { background: linear-gradient(135deg,#0a1a0a,#1a3a1a); }
.vs-thumb.minimal { background: linear-gradient(135deg,#18181b,#27272a); }
.vs-thumb.neon { background: linear-gradient(135deg,#0a001a,#1a003a); }
.vs-thumb.warm { background: linear-gradient(135deg,#1a0a00,#3a1a00); }
.vs-thumb.corporate { background: linear-gradient(135deg,#00101a,#00203a); }
.vs-label { padding: 6px 8px; font-size: 11px; font-weight: 600; text-align: center; background: var(--color-bg-elevated); }

/* Palette */
.palette-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; }
.palette-card { border: 2px solid transparent; border-radius: 8px; overflow: hidden; cursor: pointer; transition: 0.15s; }
.palette-card.selected { border-color: var(--color-accent); }
.palette-swatch { height: 38px; display: flex; }
.palette-swatch span { flex: 1; }
.palette-name { font-size: 10px; text-align: center; padding: 4px 2px; color: var(--color-text-muted); background: var(--color-bg-elevated); }

/* Characters placeholder */
.char-placeholder { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 10px; padding: 48px 24px; background: var(--color-bg-elevated); border: 1px dashed var(--color-border); border-radius: 12px; color: var(--color-text-muted); margin-bottom: 24px; }
.char-placeholder-title { font-size: 14px; font-weight: 600; color: var(--color-text-secondary); }
.char-placeholder-sub { font-size: 12px; max-width: 400px; line-height: 1.6; }

/* Voice */
.voice-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 8px; }
.voice-option { display: flex; align-items: center; gap: 12px; padding: 11px 13px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 8px; cursor: pointer; transition: 0.15s; }
.voice-option.selected { border-color: rgba(255,107,53,0.4); background: rgba(255,107,53,0.06); }
.voice-avatar { width: 34px; height: 34px; border-radius: 999px; background: linear-gradient(135deg,#1d4ed8,#7c3aed); display: flex; align-items: center; justify-content: center; font-family: 'Space Mono', monospace; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }
.voice-info { flex: 1; }
.voice-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.voice-meta { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

/* Actions */
.form-actions { display: flex; align-items: center; justify-content: space-between; padding-top: 24px; margin-top: 24px; border-top: 1px solid var(--color-border); }
.actions-right { display: flex; gap: 8px; }
.step-counter { font-family: 'Space Mono', monospace; font-size: 11px; color: var(--color-text-muted); }
.step-error { font-size: 12px; color: #f87171; background: rgba(248,113,113,0.08); border: 1px solid rgba(248,113,113,0.25); border-radius: 7px; padding: 9px 12px; margin-bottom: 4px; }
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid transparent; transition: 0.15s; font-family: inherit; }
.btn-primary { background: var(--color-accent); color: #fff; border-color: var(--color-accent); }
.btn-primary:hover:not(:disabled) { opacity: 0.88; }
.btn-primary:disabled { opacity: 0.5; cursor: default; }
.btn-ghost { background: transparent; border-color: var(--color-border); color: var(--color-text-muted); }
.btn-ghost:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.btn-sm { padding: 6px 13px; font-size: 12px; }

/* Preview panel */
.preview-panel { width: 256px; flex-shrink: 0; border-left: 1px solid var(--color-border); padding: 22px 16px; display: flex; flex-direction: column; gap: 20px; overflow-y: auto; }
.preview-section { display: flex; flex-direction: column; }
.preview-label { font-family: 'Space Mono', monospace; font-size: 9px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--color-text-muted); margin-bottom: 10px; }
.preview-card { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 10px; overflow: hidden; }
.preview-cover { height: 80px; background: linear-gradient(135deg,#0a0a2a,#1a0a3a); display: flex; align-items: center; justify-content: center; position: relative; }
.preview-initial { font-family: 'Space Mono', monospace; font-size: 30px; font-weight: 700; color: rgba(255,255,255,0.12); }
.preview-badge { position: absolute; top: 7px; right: 7px; padding: 2px 7px; border-radius: 4px; background: rgba(255,107,53,0.15); border: 1px solid rgba(255,107,53,0.35); color: var(--color-accent); font-family: 'Space Mono', monospace; font-size: 9px; font-weight: 700; }
.preview-body { padding: 10px 11px; }
.preview-name { font-size: 13px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.preview-meta { font-size: 11px; color: var(--color-text-muted); display: flex; flex-direction: column; gap: 2px; }

.pill-list { display: flex; flex-direction: column; gap: 5px; }
.pill { display: flex; align-items: center; gap: 7px; padding: 7px 9px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 7px; font-size: 11px; transition: 0.2s; }
.pill-dim { opacity: 0.4; }
.pill-ok { opacity: 1; border-color: rgba(52,211,153,0.25); background: rgba(52,211,153,0.05); }
.pill-icon { font-size: 12px; flex-shrink: 0; }
.pill-body { flex: 1; color: var(--color-text-muted); line-height: 1.4; }
.pill-body strong { color: var(--color-text-primary); display: block; font-size: 12px; }
.pill-val { font-family: 'Space Mono', monospace; font-size: 9px; color: var(--color-text-muted); flex-shrink: 0; }

.consist-list { display: flex; flex-direction: column; gap: 5px; font-size: 12px; }
.consist-row { display: flex; align-items: flex-start; gap: 7px; line-height: 1.4; }
.consist-row.done { color: var(--color-text-secondary); }
.consist-row.pending { color: var(--color-text-muted); opacity: 0.5; }
</style>
