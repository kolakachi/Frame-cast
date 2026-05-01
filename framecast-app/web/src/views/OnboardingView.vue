<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const router = useRouter()
const authStore = useAuthStore()

const STORAGE_KEY = 'fc_wizard'
const TOTAL_STEPS = 4

// ── State ────────────────────────────────────────────────
const step       = ref(1)
const niches     = ref([])
const voices     = ref([])
const styles     = ref([])
const loading    = ref(true)
const submitting = ref(false)
const error      = ref('')

// Step 1
const selectedNicheId = ref(null)

// Step 2
const sourceType    = ref('prompt')
const sourceContent = ref('')

// Step 3
const aspectRatio      = ref('9:16')
const selectedVoiceKey = ref('')
const selectedStyle    = ref('cinematic')
const visualType       = ref('stock_video')

// Audiogram settings (stored locally; applied per-scene in the editor)
const audiogramStyle = ref('bars')
const audiogramColor = ref('#ff6b35')
const audiogramBg    = ref('dark')

// ── Constants ────────────────────────────────────────────
const stepMeta = [
  { title: "What's your content niche?",     subtitle: 'This helps us tailor scripts and visuals to your audience.' },
  { title: "What's your first video about?", subtitle: 'Pick how you want to start — a topic, a script, a link, or a product.' },
  { title: 'Customize your style',           subtitle: 'Set the look, format, and voice. You can change these later.' },
  { title: 'Ready to create',                subtitle: "Review your setup and we'll get to work." },
]

const sourceTypes = [
  {
    key: 'prompt',
    label: 'Topic / Idea',
    hint: "We'll write the script for you",
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.663 17h4.673M12 3v1M5.6 5.6l.7.7M3 12h1M20 12h1M18.4 5.6l-.7.7M8 14a4 4 0 1 1 8 0c0 1.5-1 2.5-1.5 3.5h-5C9 16.5 8 15.5 8 14z"/></svg>`,
  },
  {
    key: 'script',
    label: 'Full Script',
    hint: 'Paste your own script',
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg>`,
  },
  {
    key: 'url',
    label: 'Article / URL',
    hint: "We'll summarise the link",
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>`,
  },
  {
    key: 'product_description',
    label: 'Product',
    hint: 'For a review or promo video',
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/></svg>`,
  },
]

const visualTypes = [
  {
    key: 'stock_video',
    label: 'Stock Video',
    hint: 'Real footage matched to script',
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>`,
  },
  {
    key: 'stock_images',
    label: 'Stock Images',
    hint: 'Editorial stills per scene',
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`,
  },
  {
    key: 'ai_images',
    label: 'AI Images',
    hint: 'Generated frames in your style',
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="3"/></svg>`,
  },
  {
    key: 'waveform',
    label: 'Audiogram',
    hint: 'Audio-reactive bars for podcasts',
    svg: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="10" x2="6" y2="14"/><line x1="10" y1="6" x2="10" y2="18"/><line x1="14" y1="8" x2="14" y2="16"/><line x1="18" y1="11" x2="18" y2="13"/><line x1="2" y1="12" x2="2" y2="12"/><line x1="22" y1="12" x2="22" y2="12"/></svg>`,
  },
]

const aspectRatios = [
  { value: '9:16', label: '9:16', hint: 'Shorts / TikTok', shapeClass: 'r-9-16' },
  { value: '1:1',  label: '1:1',  hint: 'Square',          shapeClass: 'r-1-1' },
  { value: '16:9', label: '16:9', hint: 'YouTube',          shapeClass: 'r-16-9' },
]

const audiogramStyles = [
  { key: 'bars',    label: 'Bars' },
  { key: 'mirror',  label: 'Mirror' },
  { key: 'circle',  label: 'Circle' },
  { key: 'minimal', label: 'Minimal' },
]

const audiogramColorPresets = ['#ff6b35', '#60a5fa', '#34d399', '#a78bfa', '#f472b6', '#fbbf24', '#ffffff']

const audiogramBgs = [
  { key: 'dark',   label: 'Dark',   style: 'linear-gradient(180deg, #0d0d1a 0%, #0a0a14 100%)' },
  { key: 'black',  label: 'Black',  style: '#000' },
  { key: 'purple', label: 'Purple', style: 'linear-gradient(135deg, #1a0a2e 0%, #0d0d2b 50%, #14102a 100%)' },
  { key: 'ocean',  label: 'Ocean',  style: 'linear-gradient(135deg, #0a1628 0%, #0d1f3c 50%, #0a0e1a 100%)' },
]

const STYLE_PREVIEW_META = {
  cinematic:      { gradient: 'linear-gradient(135deg, #22130a, #f59e0b)' },
  dark:           { gradient: 'linear-gradient(135deg, #06070b, #3f3f46)' },
  documentary:    { gradient: 'linear-gradient(135deg, #0d1b1e, #7dd3c7)' },
  anime:          { gradient: 'linear-gradient(135deg, #43155b, #f59e0b)' },
  minimalist:     { gradient: 'linear-gradient(135deg, #dbe4ee, #7c8796)' },
  realistic:      { gradient: 'linear-gradient(135deg, #132235, #7dd3fc)' },
  vintage:        { gradient: 'linear-gradient(135deg, #3b1f14, #fbbf24)' },
  neon:           { gradient: 'linear-gradient(135deg, #19083d, #06b6d4)' },
  photorealistic: { gradient: 'linear-gradient(135deg, #0f172a, #cbd5e1)' },
  cyberpunk_80s:  { gradient: 'linear-gradient(135deg, #1e0b4b, #22d3ee)' },
  anime_80s:      { gradient: 'linear-gradient(135deg, #16312b, #fde68a)' },
  anime_90s:      { gradient: 'linear-gradient(135deg, #10235e, #f472b6)' },
  dark_fantasy:   { gradient: 'linear-gradient(135deg, #120b17, #64748b)' },
  fantasy_retro:  { gradient: 'linear-gradient(135deg, #31214f, #f59e0b)' },
  comic:          { gradient: 'linear-gradient(135deg, #3b0b0b, #facc15)' },
  film_noir:      { gradient: 'linear-gradient(135deg, #050505, #d4d4d8)' },
  line_drawing:   { gradient: 'linear-gradient(135deg, #ffffff, #71717a)' },
  watercolor:     { gradient: 'linear-gradient(135deg, #0f3d4c, #f0abfc)' },
  paper_cutout:   { gradient: 'linear-gradient(135deg, #4a1f10, #fed7aa)' },
  cartoon:        { gradient: 'linear-gradient(135deg, #7c2d12, #fde68a)' },
  '3d_animated':  { gradient: 'linear-gradient(135deg, #0e2033, #f59e0b)' },
}

const STYLE_ORDER = [
  'cinematic', 'photorealistic', 'realistic', '3d_animated', 'cyberpunk_80s',
  'anime_80s', 'anime_90s', 'anime', 'dark_fantasy', 'fantasy_retro',
  'comic', 'film_noir', 'dark', 'line_drawing', 'watercolor', 'paper_cutout',
  'cartoon', 'documentary', 'minimalist', 'vintage', 'neon',
]

// ── Computed ─────────────────────────────────────────────
const selectedNiche = computed(() => niches.value.find((n) => n.id === selectedNicheId.value))
const selectedSourceTypeOption = computed(() => sourceTypes.find((s) => s.key === sourceType.value) ?? null)
const selectedVisualTypeOption = computed(() => visualTypes.find((v) => v.key === visualType.value) ?? visualTypes[0])
const selectedAspectRatioOption = computed(() => aspectRatios.find((a) => a.value === aspectRatio.value) ?? aspectRatios[0])
const selectedVoiceOption = computed(() => voices.value.find((v) => v.provider_voice_key === selectedVoiceKey.value) ?? null)

const availableStyles = computed(() => {
  const list = Array.isArray(styles.value) ? styles.value : []
  return [...list]
    .map((s) => ({
      ...s,
      gradient: STYLE_PREVIEW_META[s.key]?.gradient ?? 'linear-gradient(135deg, #1f2937, #9ca3af)',
    }))
    .sort((a, b) => {
      const ai = STYLE_ORDER.indexOf(a.key)
      const bi = STYLE_ORDER.indexOf(b.key)
      return (ai === -1 ? 999 : ai) - (bi === -1 ? 999 : bi)
    })
})

const selectedStyleOption = computed(() => availableStyles.value.find((s) => s.key === selectedStyle.value) ?? availableStyles.value[0] ?? null)

const contentPlaceholder = computed(() => {
  const map = {
    prompt:              'e.g. "The psychology behind why people procrastinate"',
    script:              'Paste your full script here…',
    url:                 'https://example.com/article',
    product_description: 'Describe the product, its benefits, and target audience…',
  }
  return map[sourceType.value] || ''
})

// ── Persistence ──────────────────────────────────────────
function saveState() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({
      step: step.value,
      selectedNicheId: selectedNicheId.value,
      sourceType: sourceType.value,
      sourceContent: sourceContent.value,
      aspectRatio: aspectRatio.value,
      selectedVoiceKey: selectedVoiceKey.value,
      selectedStyle: selectedStyle.value,
      visualType: visualType.value,
      audiogramStyle: audiogramStyle.value,
      audiogramColor: audiogramColor.value,
      audiogramBg: audiogramBg.value,
    }))
  } catch { /* ignore */ }
}

function restoreState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return
    const s = JSON.parse(raw)
    step.value             = s.step ?? 1
    selectedNicheId.value  = s.selectedNicheId ?? null
    sourceType.value       = s.sourceType ?? 'prompt'
    sourceContent.value    = s.sourceContent ?? ''
    aspectRatio.value      = s.aspectRatio ?? '9:16'
    selectedVoiceKey.value = s.selectedVoiceKey ?? ''
    selectedStyle.value    = s.selectedStyle ?? 'cinematic'
    visualType.value       = s.visualType ?? 'stock_video'
    audiogramStyle.value   = s.audiogramStyle ?? 'bars'
    audiogramColor.value   = s.audiogramColor ?? '#ff6b35'
    audiogramBg.value      = s.audiogramBg ?? 'dark'
  } catch { /* ignore */ }
}

function clearState() {
  try { localStorage.removeItem(STORAGE_KEY) } catch { /* ignore */ }
}

function selectedProjectVisualType() {
  if (visualType.value === 'ai_images') return 'ai_image'
  if (visualType.value === 'stock_images') return 'stock_image'
  if (visualType.value === 'waveform') return 'waveform'
  return 'stock_clip'
}

// ── Navigation ───────────────────────────────────────────
function next() {
  if (step.value < TOTAL_STEPS) { step.value++; saveState() }
}

function back() {
  if (step.value > 1) { step.value--; saveState() }
}

async function skip() {
  await markOnboarded()
  clearState()
  router.replace({ name: 'dashboard' })
}

async function markOnboarded() {
  try {
    await api.patch('/me', { preferences: { onboarded: true } })
    authStore.markOnboarded()
  } catch { /* non-fatal */ }
}

// ── Launch ───────────────────────────────────────────────
async function launch() {
  if (submitting.value) return
  submitting.value = true
  error.value = ''

  try {
    const payload = {
      title: selectedNiche.value ? `${selectedNiche.value.name} — First Video` : 'My First Video',
      source_type: sourceType.value,
      source_content_raw: sourceContent.value || null,
      niche_id: selectedNicheId.value || null,
      aspect_ratio: aspectRatio.value,
      visual_type: selectedProjectVisualType(),
      voice_settings_json: selectedVoiceKey.value ? { voice_id: selectedVoiceKey.value } : null,
      visual_style: selectedStyle.value,
      ai_broll_style: visualType.value === 'ai_images' ? selectedStyle.value : null,
      image_generation_settings_json: visualType.value === 'waveform'
        ? {
            audiogram_style: audiogramStyle.value,
            audiogram_color: audiogramColor.value,
            audiogram_bg: audiogramBg.value,
          }
        : null,
    }

    const { data } = await api.post('/projects', payload)
    const projectId = data.data.project.id

    await markOnboarded()
    clearState()
    router.replace({ name: 'generation-progress', params: { projectId } })
  } catch (err) {
    error.value = err?.response?.data?.error?.message || 'Something went wrong. Please try again.'
    submitting.value = false
  }
}

// ── Load ─────────────────────────────────────────────────
onMounted(async () => {
  restoreState()
  try {
    const [nichesRes, voicesRes, stylesRes] = await Promise.all([
      api.get('/niches'),
      api.get('/voice-profiles'),
      api.get('/visual-styles'),
    ])
    niches.value = nichesRes.data.data.niches || nichesRes.data.data || []
    voices.value = voicesRes.data.data.voice_profiles || []
    styles.value = stylesRes.data.data || []
    if (!selectedStyleOption.value && styles.value.length > 0) {
      selectedStyle.value = styles.value[0].key
    }
    if (!selectedVoiceKey.value && voices.value.length > 0) {
      selectedVoiceKey.value = voices.value[0].provider_voice_key
    }
  } catch { /* non-fatal */ } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="ob-shell">
    <button class="ob-skip" type="button" @click="skip">Skip setup</button>

    <div class="ob-card">

      <!-- Header -->
      <div class="ob-header">
        <div class="ob-dots">
          <span
            v-for="n in TOTAL_STEPS"
            :key="n"
            :class="['ob-dot', n === step ? 'active' : n < step ? 'done' : '']"
          ></span>
        </div>
        <div class="ob-step-label">Step {{ step }} of {{ TOTAL_STEPS }}</div>
        <h1 class="ob-title">{{ stepMeta[step - 1].title }}</h1>
        <p class="ob-subtitle">{{ stepMeta[step - 1].subtitle }}</p>
      </div>

      <div v-if="loading" class="ob-loading">Loading…</div>
      <div v-else>
        <!-- ── Step 1: Niche ── -->
        <div v-if="step === 1">
          <div class="ob-niche-grid">
            <button
              v-for="niche in niches"
              :key="niche.id"
              :class="['ob-niche-card', selectedNicheId === niche.id ? 'selected' : '']"
              type="button"
              @click="selectedNicheId = niche.id"
            >
              <div class="ob-niche-name">{{ niche.name }}</div>
              <div class="ob-niche-desc">{{ niche.description }}</div>
            </button>
            <button
              :class="['ob-niche-card ob-niche-card--other', selectedNicheId === null ? 'selected' : '']"
              type="button"
              @click="selectedNicheId = null"
            >
              <div class="ob-niche-name">Other / General</div>
              <div class="ob-niche-desc">I'll decide later</div>
            </button>
          </div>
          <div class="ob-actions">
            <button class="ob-btn ob-btn-ghost" type="button" @click="skip">Skip setup</button>
            <div class="ob-actions-right">
              <button class="ob-btn ob-btn-primary" type="button" @click="next">Continue</button>
            </div>
          </div>
        </div>

        <!-- ── Step 2: Content ── -->
        <div v-else-if="step === 2">
          <div class="ob-source-grid">
            <button
              v-for="st in sourceTypes"
              :key="st.key"
              :class="['ob-source-card', sourceType === st.key ? 'selected' : '']"
              type="button"
              @click="sourceType = st.key"
            >
              <span class="ob-source-icon" v-html="st.svg"></span>
              <div class="ob-source-body">
                <div class="ob-source-label">{{ st.label }}</div>
                <div class="ob-source-hint">{{ st.hint }}</div>
              </div>
            </button>
          </div>
          <textarea
            v-model="sourceContent"
            class="ob-textarea"
            :placeholder="contentPlaceholder"
            rows="4"
          ></textarea>
          <div class="ob-actions">
            <button class="ob-btn ob-btn-ghost" type="button" @click="back">Back</button>
            <div class="ob-actions-right">
              <button class="ob-btn ob-btn-ghost" type="button" @click="next">Skip</button>
              <button class="ob-btn ob-btn-primary" type="button" :disabled="!sourceContent.trim()" @click="next">Continue</button>
            </div>
          </div>
        </div>

        <!-- ── Step 3: Style ── -->
        <div v-else-if="step === 3">

        <div class="ob-field">
          <label class="ob-label">Visual type</label>
          <div class="ob-visual-grid">
            <button
              v-for="vt in visualTypes"
              :key="vt.key"
              :class="['ob-visual-card', visualType === vt.key ? 'selected' : '']"
              type="button"
              @click="visualType = vt.key"
            >
              <span class="ob-visual-icon" v-html="vt.svg"></span>
              <div class="ob-visual-body">
                <div class="ob-visual-label">{{ vt.label }}</div>
                <div class="ob-visual-hint">{{ vt.hint }}</div>
              </div>
            </button>
          </div>
        </div>

        <!-- AI image style — only when AI Images selected -->
        <div v-if="visualType === 'ai_images'" class="ob-field">
          <label class="ob-label">AI image style</label>
          <div class="ob-style-grid">
            <button
              v-for="s in availableStyles"
              :key="s.key"
              :class="['ob-style-card', selectedStyle === s.key ? 'selected' : '']"
              type="button"
              @click="selectedStyle = s.key"
            >
              <span class="ob-style-swatch" :style="{ background: s.gradient }"></span>
              <span class="ob-style-name">{{ s.label }}</span>
            </button>
          </div>
        </div>

        <!-- Audiogram settings — only when Audiogram selected -->
        <div v-if="visualType === 'waveform'" class="ob-field">
          <label class="ob-label">Audiogram design</label>
          <div class="ob-ag-grid">
            <button
              v-for="ws in audiogramStyles"
              :key="ws.key"
              :class="['ob-ag-card', audiogramStyle === ws.key ? 'selected' : '']"
              type="button"
              @click="audiogramStyle = ws.key"
            >
              <div class="ob-ag-preview">
                <div v-if="ws.key === 'bars'">
                  <span class="ob-ag-bar" style="height:40%"></span>
                  <span class="ob-ag-bar" style="height:70%"></span>
                  <span class="ob-ag-bar" style="height:55%"></span>
                  <span class="ob-ag-bar" style="height:90%"></span>
                  <span class="ob-ag-bar" style="height:60%"></span>
                  <span class="ob-ag-bar" style="height:80%"></span>
                  <span class="ob-ag-bar" style="height:45%"></span>
                </div>
                <div v-else-if="ws.key === 'mirror'">
                  <span class="ob-ag-bar-mirror" style="height:40%"></span>
                  <span class="ob-ag-bar-mirror" style="height:70%"></span>
                  <span class="ob-ag-bar-mirror" style="height:55%"></span>
                  <span class="ob-ag-bar-mirror" style="height:90%"></span>
                  <span class="ob-ag-bar-mirror" style="height:60%"></span>
                  <span class="ob-ag-bar-mirror" style="height:80%"></span>
                  <span class="ob-ag-bar-mirror" style="height:45%"></span>
                </div>
                <div v-else-if="ws.key === 'circle'">
                  <svg viewBox="0 0 40 40" width="36" height="36" style="overflow:visible">
                    <g transform="translate(20,20)">
                      <line
                        v-for="(angle, i) in [0, 45, 90, 135, 180, 225, 270, 315]"
                        :key="i"
                        :transform="`rotate(${angle})`"
                        x1="0" y1="7" x2="0"
                        :y2="[12, 14, 13, 15, 12, 14, 13, 15][i]"
                        stroke="#ff6b35" stroke-width="2" stroke-linecap="round"
                      />
                    </g>
                  </svg>
                </div>
                <div v-else>
                  <span
                    v-for="(h, i) in [30, 50, 40, 70, 50, 60, 35, 55, 45, 65]"
                    :key="i"
                    class="ob-ag-bar-min"
                    :style="`height:${h}%`"
                  ></span>
                </div>
              </div>
              <div class="ob-ag-name">{{ ws.label }}</div>
            </button>
          </div>

          <span class="ob-ag-sublabel">Bar color</span>
          <div class="ob-ag-colors">
            <button
              v-for="color in audiogramColorPresets"
              :key="color"
              :class="['ob-ag-color', audiogramColor === color ? 'selected' : '']"
              type="button"
              :style="{ background: color }"
              :title="color"
              @click="audiogramColor = color"
            ></button>
            <label class="ob-ag-color-custom" title="Custom color">
              <input type="color" :value="audiogramColor" @input="audiogramColor = $event.target.value">
              <span>＋</span>
            </label>
          </div>

          <span class="ob-ag-sublabel">Background</span>
          <div class="ob-ag-bg-row">
            <button
              v-for="bg in audiogramBgs"
              :key="bg.key"
              :class="['ob-ag-bg', audiogramBg === bg.key ? 'selected' : '']"
              type="button"
              :style="{ background: bg.style }"
              @click="audiogramBg = bg.key"
            >{{ bg.label }}</button>
          </div>
        </div>

        <div class="ob-field">
          <label class="ob-label">Aspect ratio</label>
          <div class="ob-ratio-grid">
            <button
              v-for="ar in aspectRatios"
              :key="ar.value"
              :class="['ob-ratio-card', aspectRatio === ar.value ? 'selected' : '']"
              type="button"
              @click="aspectRatio = ar.value"
            >
              <span :class="['ob-ratio-shape', ar.shapeClass]"></span>
              <div class="ob-ratio-label">{{ ar.label }}</div>
              <div class="ob-ratio-hint">{{ ar.hint }}</div>
            </button>
          </div>
        </div>

        <div class="ob-field">
          <label class="ob-label">Voice</label>
          <div class="ob-voice-wrap">
            <select v-model="selectedVoiceKey" class="ob-select">
              <option v-for="v in voices" :key="v.provider_voice_key" :value="v.provider_voice_key">
                {{ v.name }} — {{ v.gender_label }}
              </option>
            </select>
            <span class="ob-voice-chevron">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
          </div>
        </div>

          <div class="ob-actions">
            <button class="ob-btn ob-btn-ghost" type="button" @click="back">Back</button>
            <div class="ob-actions-right">
              <button class="ob-btn ob-btn-primary" type="button" @click="next">Continue</button>
            </div>
          </div>
        </div>

        <!-- ── Step 4: Launch ── -->
        <div v-else>
          <div class="ob-summary">
            <div class="ob-summary-row">
              <span class="ob-summary-key">Niche</span>
              <span class="ob-summary-val">{{ selectedNiche ? selectedNiche.name : 'General' }}</span>
            </div>
            <div class="ob-summary-row">
              <span class="ob-summary-key">Source</span>
              <span class="ob-summary-val">{{ selectedSourceTypeOption ? selectedSourceTypeOption.label : sourceType }}</span>
            </div>
            <div class="ob-summary-row">
              <span class="ob-summary-key">Visuals</span>
              <span class="ob-summary-val">{{ selectedVisualTypeOption.label }}</span>
            </div>
            <div v-if="visualType === 'ai_images'" class="ob-summary-row">
              <span class="ob-summary-key">AI Style</span>
              <span class="ob-summary-val">{{ selectedStyleOption ? selectedStyleOption.label : selectedStyle }}</span>
            </div>
            <div class="ob-summary-row">
              <span class="ob-summary-key">Format</span>
              <span class="ob-summary-val">{{ aspectRatio }} — {{ selectedAspectRatioOption.hint }}</span>
            </div>
            <div class="ob-summary-row">
              <span class="ob-summary-key">Voice</span>
              <span class="ob-summary-val">{{ selectedVoiceOption ? selectedVoiceOption.name : 'Default' }}</span>
            </div>
          </div>

          <p class="ob-launch-copy">
            We'll generate your script, voice, and visuals automatically.<br>
            You can edit everything in the Editor before exporting.
          </p>

          <div v-if="error" class="ob-error">{{ error }}</div>

          <div class="ob-actions">
            <button class="ob-btn ob-btn-ghost" type="button" @click="back">Back</button>
            <div class="ob-actions-right">
              <button
                class="ob-btn ob-btn-primary ob-btn-launch"
                type="button"
                :disabled="submitting"
                @click="launch"
              >{{ submitting ? 'Creating…' : 'Create my first video' }}</button>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>
/* ── Design tokens ── */
.ob-shell {
  --bg: #0a0a0f;
  --surface: #14141c;
  --surface-2: #1a1a24;
  --border: #25252f;
  --border-strong: #34343f;
  --text: #ececf3;
  --text-dim: #8b8b9a;
  --text-faint: #5a5a68;
  --accent: #ff6b35;
  --accent-soft: rgba(255,107,53,0.10);
  --accent-border: rgba(255,107,53,0.45);

  min-height: 100vh;
  background:
    radial-gradient(ellipse 60% 50% at 80% 0%, rgba(255,107,53,0.08), transparent 60%),
    radial-gradient(ellipse 50% 50% at 0% 100%, rgba(96,165,250,0.05), transparent 60%),
    var(--bg);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px 24px;
  position: relative;
  font-family: "DM Sans", sans-serif;
  color: var(--text);
  -webkit-font-smoothing: antialiased;
}

.ob-skip {
  position: absolute;
  top: 24px;
  right: 28px;
  background: transparent;
  border: none;
  color: var(--text-faint);
  font-size: 13px;
  cursor: pointer;
  font-family: inherit;
  padding: 6px 10px;
  border-radius: 6px;
  transition: color 0.15s;
}
.ob-skip:hover { color: var(--text-dim); }

.ob-card {
  width: min(640px, 100%);
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 18px;
  padding: 36px 36px 28px;
  box-shadow: 0 30px 80px rgba(0,0,0,0.45);
}

/* ── Header ── */
.ob-header { margin-bottom: 28px; }

.ob-dots {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 22px;
}
.ob-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--border);
  transition: 0.25s ease;
}
.ob-dot.done { background: var(--accent-border); }
.ob-dot.active { background: var(--accent); width: 26px; border-radius: 999px; }

.ob-step-label {
  font-size: 11px;
  color: var(--text-faint);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 6px;
  font-weight: 500;
}
.ob-title { font-size: 22px; font-weight: 600; letter-spacing: -0.01em; color: var(--text); }
.ob-subtitle { font-size: 13px; color: var(--text-dim); margin-top: 6px; line-height: 1.5; }
.ob-loading { color: var(--text-faint); font-size: 13px; padding: 24px 0; }

/* ── Niche grid ── */
.ob-niche-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 28px;
}
.ob-niche-card {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 14px;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s;
  font-family: inherit;
  color: var(--text);
}
.ob-niche-card:hover { border-color: var(--border-strong); background: var(--surface-2); }
.ob-niche-card.selected { border-color: var(--accent); background: var(--accent-soft); }
.ob-niche-name { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.ob-niche-desc { font-size: 11px; color: var(--text-faint); line-height: 1.45; }
.ob-niche-card.selected .ob-niche-desc { color: var(--text-dim); }
.ob-niche-card--other { border-style: dashed; }

/* ── Source grid ── */
.ob-source-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
  margin-bottom: 18px;
}
.ob-source-card {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 14px;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s;
  font-family: inherit;
  color: var(--text);
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.ob-source-card:hover { border-color: var(--border-strong); background: var(--surface-2); }
.ob-source-card.selected { border-color: var(--accent); background: var(--accent-soft); }
.ob-source-icon {
  width: 28px;
  height: 28px;
  border-radius: 7px;
  background: var(--surface-2);
  display: grid;
  place-items: center;
  flex-shrink: 0;
  color: var(--text-dim);
  transition: all 0.15s;
}
.ob-source-card.selected .ob-source-icon { background: rgba(255,107,53,0.15); color: var(--accent); }
.ob-source-icon :deep(svg) { width: 14px; height: 14px; }
.ob-source-body { flex: 1; min-width: 0; }
.ob-source-label { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.ob-source-hint { font-size: 11px; color: var(--text-faint); line-height: 1.45; }

/* ── Textarea ── */
.ob-textarea {
  width: 100%;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-family: inherit;
  font-size: 13px;
  padding: 12px 14px;
  resize: vertical;
  margin-bottom: 28px;
  outline: none;
  transition: border-color 0.15s;
  line-height: 1.5;
  box-sizing: border-box;
}
.ob-textarea:focus { border-color: var(--accent-border); }
.ob-textarea::placeholder { color: var(--text-faint); }

/* ── Fields ── */
.ob-field { margin-bottom: 22px; }
.ob-label {
  font-size: 12px;
  color: var(--text-dim);
  font-weight: 500;
  margin-bottom: 10px;
  display: block;
}

/* ── Visual type ── */
.ob-visual-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
}
.ob-visual-card {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 14px;
  text-align: left;
  cursor: pointer;
  transition: all 0.15s;
  font-family: inherit;
  color: var(--text);
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.ob-visual-card:hover { border-color: var(--border-strong); background: var(--surface-2); }
.ob-visual-card.selected { border-color: var(--accent); background: var(--accent-soft); }
.ob-visual-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: var(--surface-2);
  display: grid;
  place-items: center;
  flex-shrink: 0;
  color: var(--text-dim);
  transition: all 0.15s;
}
.ob-visual-card.selected .ob-visual-icon { background: rgba(255,107,53,0.15); color: var(--accent); }
.ob-visual-icon :deep(svg) { width: 16px; height: 16px; }
.ob-visual-body { flex: 1; min-width: 0; }
.ob-visual-label { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.ob-visual-hint { font-size: 11px; color: var(--text-faint); line-height: 1.45; }

/* ── AI style grid ── */
.ob-style-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  max-height: 320px;
  overflow-y: auto;
  padding-right: 4px;
}
.ob-style-grid::-webkit-scrollbar { width: 4px; }
.ob-style-grid::-webkit-scrollbar-track { background: transparent; }
.ob-style-grid::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
.ob-style-card {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 10px;
  font-family: inherit;
  color: var(--text);
  cursor: pointer;
  transition: all 0.15s;
  text-align: left;
  display: flex;
  align-items: center;
  gap: 10px;
}
.ob-style-card:hover { border-color: var(--border-strong); background: var(--surface-2); }
.ob-style-card.selected { border-color: var(--accent); background: var(--accent-soft); }
.ob-style-swatch { width: 28px; height: 28px; border-radius: 6px; flex-shrink: 0; border: 1px solid rgba(255,255,255,0.06); }
.ob-style-name { font-size: 12px; font-weight: 500; }

/* ── Audiogram ── */
.ob-ag-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  margin-bottom: 14px;
}
.ob-ag-card {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 8px;
  cursor: pointer;
  transition: all 0.15s;
  font-family: inherit;
  color: var(--text);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}
.ob-ag-card:hover { border-color: var(--border-strong); background: var(--surface-2); }
.ob-ag-card.selected { border-color: var(--accent); background: var(--accent-soft); }
.ob-ag-preview {
  width: 100%;
  height: 44px;
  border-radius: 6px;
  background: linear-gradient(180deg, #0d0d1a 0%, #0a0a14 100%);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 2px;
  padding: 4px;
  overflow: hidden;
}
.ob-ag-bar { width: 3px; background: var(--accent); border-radius: 1px; }
.ob-ag-bar-mirror { width: 3px; background: var(--accent); border-radius: 1px; align-self: center; }
.ob-ag-bar-min { width: 2px; background: var(--accent); border-radius: 1px; opacity: 0.85; }
.ob-ag-name { font-size: 11px; font-weight: 500; }
.ob-ag-sublabel {
  font-size: 11px;
  color: var(--text-dim);
  font-weight: 500;
  margin-bottom: 6px;
  margin-top: 12px;
  display: block;
}
.ob-ag-colors { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.ob-ag-color {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px solid rgba(255,255,255,0.08);
  cursor: pointer;
  transition: all 0.15s;
  padding: 0;
}
.ob-ag-color:hover { transform: scale(1.08); }
.ob-ag-color.selected { box-shadow: 0 0 0 2px var(--bg), 0 0 0 4px var(--accent); }
.ob-ag-color-custom {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px dashed var(--border-strong);
  background: transparent;
  display: grid;
  place-items: center;
  cursor: pointer;
  color: var(--text-faint);
  font-size: 14px;
  position: relative;
  overflow: hidden;
}
.ob-ag-color-custom input {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}
.ob-ag-bg-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
}
.ob-ag-bg {
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--border);
  cursor: pointer;
  font-family: inherit;
  font-size: 11px;
  font-weight: 500;
  color: var(--text);
  transition: all 0.15s;
}
.ob-ag-bg:hover { border-color: var(--border-strong); }
.ob-ag-bg.selected { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent); }

/* ── Ratio ── */
.ob-ratio-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.ob-ratio-card {
  background: transparent;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 8px;
  text-align: center;
  cursor: pointer;
  font-family: inherit;
  color: var(--text);
  transition: all 0.15s;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}
.ob-ratio-card:hover { border-color: var(--border-strong); background: var(--surface-2); }
.ob-ratio-card.selected { border-color: var(--accent); background: var(--accent-soft); }
.ob-ratio-shape { background: var(--text-faint); border-radius: 2px; transition: background 0.15s; }
.ob-ratio-card.selected .ob-ratio-shape { background: var(--accent); }
.r-9-16 { width: 12px; height: 20px; }
.r-1-1  { width: 18px; height: 18px; }
.r-16-9 { width: 24px; height: 14px; }
.ob-ratio-label { font-size: 13px; font-weight: 500; }
.ob-ratio-hint { font-size: 11px; color: var(--text-faint); }

/* ── Voice ── */
.ob-voice-wrap { position: relative; }
.ob-select {
  width: 100%;
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-family: inherit;
  font-size: 13px;
  padding: 12px 38px 12px 14px;
  appearance: none;
  outline: none;
  cursor: pointer;
  transition: border-color 0.15s;
}
.ob-select:focus { border-color: var(--accent-border); }
.ob-voice-chevron {
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  color: var(--text-faint);
  display: flex;
  align-items: center;
}

/* ── Summary ── */
.ob-summary {
  background: var(--surface-2);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 6px 18px;
  margin-bottom: 20px;
}
.ob-summary-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  padding: 12px 0;
  border-bottom: 1px solid var(--border);
}
.ob-summary-row:last-child { border-bottom: none; }
.ob-summary-key { color: var(--text-dim); }
.ob-summary-val { font-weight: 500; color: var(--text); }

.ob-launch-copy {
  font-size: 13px;
  color: var(--text-dim);
  line-height: 1.6;
  margin-bottom: 24px;
  text-align: center;
}

.ob-error {
  background: rgba(248,113,113,0.1);
  border: 1px solid rgba(248,113,113,0.2);
  color: #fca5a5;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 13px;
  margin-bottom: 16px;
}

/* ── Actions ── */
.ob-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  margin-top: 8px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}
.ob-actions-right { display: flex; gap: 8px; }
.ob-btn {
  padding: 10px 18px;
  border-radius: 8px;
  border: 1px solid var(--border);
  font-family: inherit;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
  color: var(--text);
  background: transparent;
}
.ob-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ob-btn-ghost:hover { background: var(--surface-2); border-color: var(--border-strong); }
.ob-btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.ob-btn-primary:hover:not(:disabled) { background: #ff7a47; border-color: #ff7a47; }
.ob-btn-launch { padding: 11px 24px; }

@media (max-width: 600px) {
  .ob-card { padding: 26px 20px 20px; border-radius: 14px; }
  .ob-niche-grid { grid-template-columns: 1fr; }
  .ob-source-grid { grid-template-columns: 1fr; }
  .ob-visual-grid { grid-template-columns: 1fr; }
  .ob-style-grid { grid-template-columns: repeat(2, 1fr); }
  .ob-ag-grid { grid-template-columns: repeat(2, 1fr); }
  .ob-ag-bg-row { grid-template-columns: repeat(2, 1fr); }
  .ob-title { font-size: 19px; }
}
</style>
