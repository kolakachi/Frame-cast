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
const step        = ref(1)
const niches      = ref([])
const voices      = ref([])
const styles      = ref([])
const loading     = ref(true)
const submitting  = ref(false)
const error       = ref('')

// Step 1
const selectedNicheId   = ref(null)

// Step 2
const sourceType    = ref('prompt')
const sourceContent = ref('')

// Step 3
const aspectRatio       = ref('9:16')
const selectedVoiceKey  = ref('')
const selectedStyle     = ref('cinematic')
const visualType        = ref('stock_video') // 'stock_video' | 'stock_images' | 'ai_images' | 'waveform'

const visualTypes = [
  { key: 'stock_video', label: 'Stock Video',  hint: 'Real footage matched to your script' },
  { key: 'stock_images', label: 'Stock Images', hint: 'Editorial stills and stock photos per scene' },
  { key: 'ai_images',   label: 'AI Images',    hint: 'AI-generated frames in the style you choose' },
  { key: 'waveform',    label: 'Audiogram',    hint: 'Audio-reactive bars for voice-led or podcast clips' },
]

const STYLE_PREVIEW_META = {
  cinematic: { preview: 'Golden-hour drama', gradient: 'linear-gradient(135deg, #22130a 0%, #6b3418 45%, #f59e0b 100%)', accent: '#fbbf24' },
  dark: { preview: 'Shadow-heavy noir', gradient: 'linear-gradient(135deg, #06070b 0%, #191b28 45%, #3f3f46 100%)', accent: '#a1a1aa' },
  documentary: { preview: 'Natural real world', gradient: 'linear-gradient(135deg, #0d1b1e 0%, #224e54 50%, #7dd3c7 100%)', accent: '#99f6e4' },
  anime: { preview: 'Vibrant cel shading', gradient: 'linear-gradient(135deg, #43155b 0%, #db2777 50%, #f59e0b 100%)', accent: '#f9a8d4' },
  minimalist: { preview: 'Clean editorial frames', gradient: 'linear-gradient(135deg, #dbe4ee 0%, #b9c5d3 45%, #7c8796 100%)', accent: '#e2e8f0' },
  realistic: { preview: 'Natural faces and places', gradient: 'linear-gradient(135deg, #132235 0%, #2563eb 50%, #7dd3fc 100%)', accent: '#93c5fd' },
  vintage: { preview: 'Retro grain and warmth', gradient: 'linear-gradient(135deg, #3b1f14 0%, #9a3412 45%, #fbbf24 100%)', accent: '#fdba74' },
  neon: { preview: 'Glowing night scenes', gradient: 'linear-gradient(135deg, #19083d 0%, #7c3aed 45%, #06b6d4 100%)', accent: '#c4b5fd' },
  photorealistic: { preview: 'Glossy studio realism', gradient: 'linear-gradient(135deg, #0f172a 0%, #475569 45%, #cbd5e1 100%)', accent: '#e2e8f0' },
  cyberpunk_80s: { preview: 'Retro-future neon city', gradient: 'linear-gradient(135deg, #1e0b4b 0%, #ec4899 50%, #22d3ee 100%)', accent: '#f0abfc' },
  anime_80s: { preview: 'Vintage cel anime', gradient: 'linear-gradient(135deg, #16312b 0%, #059669 45%, #fde68a 100%)', accent: '#bbf7d0' },
  anime_90s: { preview: 'Painted anime worlds', gradient: 'linear-gradient(135deg, #10235e 0%, #2563eb 45%, #f472b6 100%)', accent: '#bfdbfe' },
  dark_fantasy: { preview: 'Gothic mythic worlds', gradient: 'linear-gradient(135deg, #120b17 0%, #3f1d52 45%, #64748b 100%)', accent: '#cbd5e1' },
  fantasy_retro: { preview: 'Storybook adventure', gradient: 'linear-gradient(135deg, #31214f 0%, #6366f1 45%, #f59e0b 100%)', accent: '#c7d2fe' },
  comic: { preview: 'Bold graphic panels', gradient: 'linear-gradient(135deg, #3b0b0b 0%, #ef4444 45%, #facc15 100%)', accent: '#fecaca' },
  film_noir: { preview: 'Monochrome suspense', gradient: 'linear-gradient(135deg, #050505 0%, #2f2f2f 45%, #d4d4d8 100%)', accent: '#f4f4f5' },
  line_drawing: { preview: 'Monochrome sketch', gradient: 'linear-gradient(135deg, #ffffff 0%, #d4d4d8 45%, #71717a 100%)', accent: '#111827' },
  watercolor: { preview: 'Soft painterly wash', gradient: 'linear-gradient(135deg, #0f3d4c 0%, #2dd4bf 45%, #f0abfc 100%)', accent: '#99f6e4' },
  paper_cutout: { preview: 'Layered paper collage', gradient: 'linear-gradient(135deg, #4a1f10 0%, #f97316 45%, #fed7aa 100%)', accent: '#fdba74' },
  cartoon: { preview: 'Playful expressive art', gradient: 'linear-gradient(135deg, #7c2d12 0%, #fb923c 45%, #fde68a 100%)', accent: '#fdba74' },
  '3d_animated': { preview: 'Stylized 3D animation', gradient: 'linear-gradient(135deg, #0e2033 0%, #0ea5e9 45%, #f59e0b 100%)', accent: '#7dd3fc' },
}

const STYLE_ORDER = [
  'cinematic', 'photorealistic', 'realistic', '3d_animated', 'cyberpunk_80s',
  'anime_80s', 'anime_90s', 'anime', 'dark_fantasy', 'fantasy_retro',
  'comic', 'film_noir', 'dark', 'line_drawing', 'watercolor', 'paper_cutout',
  'cartoon', 'documentary', 'minimalist', 'vintage', 'neon',
]

const sourceTypes = [
  { key: 'prompt',              label: 'Topic / Idea',          hint: "Describe a topic and we'll write the script" },
  { key: 'script',              label: 'Full Script',           hint: 'Paste your own script' },
  { key: 'url',                 label: 'Article / URL',         hint: 'Paste a link and we\'ll summarise it' },
  { key: 'product_description', label: 'Product',               hint: 'Describe a product for a review video' },
]

const aspectRatios = [
  { value: '9:16', label: '9:16', hint: 'Shorts / TikTok' },
  { value: '1:1',  label: '1:1',  hint: 'Square' },
  { value: '16:9', label: '16:9', hint: 'YouTube' },
]

const selectedNiche = computed(() => niches.value.find((n) => n.id === selectedNicheId.value))
const selectedVisualTypeOption = computed(() => visualTypes.find((item) => item.key === visualType.value) ?? visualTypes[0])
const selectedSourceTypeOption = computed(() => sourceTypes.find((item) => item.key === sourceType.value) ?? null)
const selectedVoiceOption = computed(() => voices.value.find((voice) => voice.provider_voice_key === selectedVoiceKey.value) ?? null)
const availableStyles = computed(() => {
  const list = Array.isArray(styles.value) ? styles.value : []
  return [...list]
    .map((style) => ({
      ...style,
      preview: STYLE_PREVIEW_META[style.key]?.preview ?? style.description ?? style.label,
      gradient: STYLE_PREVIEW_META[style.key]?.gradient ?? 'linear-gradient(135deg, #1f2937 0%, #4b5563 50%, #9ca3af 100%)',
      accent: STYLE_PREVIEW_META[style.key]?.accent ?? '#e5e7eb',
    }))
    .sort((a, b) => {
      const aIndex = STYLE_ORDER.indexOf(a.key)
      const bIndex = STYLE_ORDER.indexOf(b.key)
      return (aIndex === -1 ? 999 : aIndex) - (bIndex === -1 ? 999 : bIndex)
    })
})
const selectedStyleOption = computed(() => availableStyles.value.find((style) => style.key === selectedStyle.value) ?? availableStyles.value[0] ?? null)

const contentPlaceholder = computed(() => {
  const map = {
    prompt:              'e.g. "The psychology behind why people procrastinate"',
    script:              'Paste your full script here…',
    url:                 'https://example.com/article',
    product_description: 'Describe the product, its benefits, and target audience…',
  }
  return map[sourceType.value] || ''
})

const stepTitles = [
  "What's your content niche?",
  "What's your first video about?",
  'Customize your style',
  'Ready to create',
]

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
    }))
  } catch { /* ignore */ }
}

function restoreState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return
    const s = JSON.parse(raw)
    step.value            = s.step ?? 1
    selectedNicheId.value = s.selectedNicheId ?? null
    sourceType.value      = s.sourceType ?? 'prompt'
    sourceContent.value   = s.sourceContent ?? ''
    aspectRatio.value     = s.aspectRatio ?? '9:16'
    selectedVoiceKey.value = s.selectedVoiceKey ?? ''
    selectedStyle.value   = s.selectedStyle ?? 'cinematic'
    visualType.value      = s.visualType ?? 'stock_video'
  } catch { /* ignore */ }
}

function clearState() {
  try { localStorage.removeItem(STORAGE_KEY) } catch { /* ignore */ }
}

// ── Navigation ───────────────────────────────────────────
function next() {
  if (step.value < TOTAL_STEPS) {
    step.value++
    saveState()
  }
}

function back() {
  if (step.value > 1) {
    step.value--
    saveState()
  }
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
      voice_settings_json: selectedVoiceKey.value ? { voice_id: selectedVoiceKey.value } : null,
      visual_style: selectedStyle.value,
      visual_generation_mode:
        visualType.value === 'ai_images' ? 'ai_images'
          : visualType.value === 'stock_images' ? 'stock_images'
            : visualType.value === 'waveform' ? 'waveform'
              : 'stock',
      ai_broll_style: visualType.value === 'ai_images' ? selectedStyle.value : null,
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

    <!-- Skip always visible -->
    <button class="ob-skip" type="button" @click="skip">Skip setup</button>

    <div class="ob-card">

      <!-- Step dots -->
      <div class="ob-dots">
        <span
          v-for="n in TOTAL_STEPS"
          :key="n"
          :class="['ob-dot', n === step ? 'active' : n < step ? 'done' : '']"
        ></span>
      </div>

      <div class="ob-step-label">Step {{ step }} of {{ TOTAL_STEPS }}</div>
      <div class="ob-title">{{ stepTitles[step - 1] }}</div>

      <div v-if="loading" class="ob-loading">Loading…</div>

      <!-- ── Step 1: Niche ── -->
      <template v-else-if="step === 1">
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
            <div class="ob-niche-desc">I'll decide later or my niche isn't listed</div>
          </button>
        </div>
        <div class="ob-actions">
          <button class="ob-btn ob-btn-ghost" type="button" @click="skip">Skip</button>
          <button class="ob-btn ob-btn-primary" type="button" @click="next">Continue</button>
        </div>
      </template>

      <!-- ── Step 2: Content ── -->
      <template v-else-if="step === 2">
        <div class="ob-source-grid">
          <button
            v-for="st in sourceTypes"
            :key="st.key"
            :class="['ob-source-card', sourceType === st.key ? 'selected' : '']"
            type="button"
            @click="sourceType = st.key"
          >
            <div class="ob-source-label">{{ st.label }}</div>
            <div class="ob-source-hint">{{ st.hint }}</div>
          </button>
        </div>
        <textarea
          v-model="sourceContent"
          class="ob-textarea"
          :placeholder="contentPlaceholder"
          rows="5"
        ></textarea>
        <div class="ob-actions">
          <button class="ob-btn ob-btn-ghost" type="button" @click="back">Back</button>
          <button class="ob-btn ob-btn-ghost" type="button" @click="next">Skip</button>
          <button class="ob-btn ob-btn-primary" type="button" :disabled="!sourceContent.trim()" @click="next">Continue</button>
        </div>
      </template>

      <!-- ── Step 3: Style ── -->
      <template v-else-if="step === 3">
        <div class="ob-field">
          <div class="ob-label">Visual Type</div>
          <div class="ob-visual-grid">
            <button
              v-for="vt in visualTypes"
              :key="vt.key"
              :class="['ob-visual-card', visualType === vt.key ? 'selected' : '']"
              type="button"
              @click="visualType = vt.key"
            >
              <div class="ob-visual-art" :data-vt="vt.key">
                <template v-if="vt.key === 'stock_video'">
                  <span class="ob-reel-frame"></span>
                  <span class="ob-reel-strip left"></span>
                  <span class="ob-reel-strip right"></span>
                </template>
                <template v-else-if="vt.key === 'stock_images'">
                  <span class="ob-photo-stack back"></span>
                  <span class="ob-photo-stack front"></span>
                </template>
                <template v-else-if="vt.key === 'ai_images'">
                  <span class="ob-ai-orb"></span>
                  <span class="ob-ai-spark one"></span>
                  <span class="ob-ai-spark two"></span>
                  <span class="ob-ai-spark three"></span>
                </template>
                <template v-else>
                  <span v-for="bar in 9" :key="bar" class="ob-wave-bar" :style="{ height: `${24 + ((bar * 13) % 42)}px` }"></span>
                </template>
              </div>
              <div class="ob-visual-copy">
                <div class="ob-source-label">{{ vt.label }}</div>
                <div class="ob-source-hint">{{ vt.hint }}</div>
              </div>
            </button>
          </div>
        </div>

        <div class="ob-field">
          <div class="ob-label">Aspect Ratio</div>
          <div class="ob-ratio-grid">
            <button
              v-for="ar in aspectRatios"
              :key="ar.value"
              :class="['ob-ratio-card', aspectRatio === ar.value ? 'selected' : '']"
              type="button"
              @click="aspectRatio = ar.value"
            >
              <div class="ob-ratio-label">{{ ar.label }}</div>
              <div class="ob-ratio-hint">{{ ar.hint }}</div>
            </button>
          </div>
        </div>

        <div class="ob-field">
          <div class="ob-label">Voice</div>
          <select v-model="selectedVoiceKey" class="ob-select">
            <option v-for="v in voices" :key="v.provider_voice_key" :value="v.provider_voice_key">
              {{ v.name }} — {{ v.gender_label }}
            </option>
          </select>
        </div>

        <div v-if="visualType === 'ai_images'" class="ob-field">
          <div class="ob-label">AI Image Style</div>
          <div class="ob-style-subtitle">Choose the visual language. Each card previews the kind of look the generated frames will lean toward.</div>
          <div class="ob-style-grid">
            <button
              v-for="s in availableStyles"
              :key="s.key"
              :class="['ob-style-card', selectedStyle === s.key ? 'selected' : '']"
              type="button"
              @click="selectedStyle = s.key"
            >
              <div class="ob-style-preview" :style="{ '--style-gradient': s.gradient, '--style-accent': s.accent }">
                <span class="ob-style-preview-glow"></span>
                <span class="ob-style-preview-panel main"></span>
                <span class="ob-style-preview-panel side"></span>
                <span class="ob-style-preview-caption">{{ s.preview }}</span>
              </div>
              <div class="ob-style-card-body">
                <div class="ob-style-card-title">{{ s.label }}</div>
                <div class="ob-style-card-copy">{{ s.description }}</div>
              </div>
            </button>
          </div>
        </div>

        <div class="ob-actions">
          <button class="ob-btn ob-btn-ghost" type="button" @click="back">Back</button>
          <button class="ob-btn ob-btn-primary" type="button" @click="next">Continue</button>
        </div>
      </template>

      <!-- ── Step 4: Launch ── -->
      <template v-else>
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
            <span class="ob-summary-val">{{ aspectRatio }}</span>
          </div>
          <div class="ob-summary-row">
            <span class="ob-summary-key">Voice</span>
            <span class="ob-summary-val">{{ selectedVoiceOption ? selectedVoiceOption.name : 'Default' }}</span>
          </div>
        </div>

        <p class="ob-launch-copy">
          We'll generate your script, voice, and visuals automatically.
          You can edit everything in the Editor before exporting.
        </p>

        <div v-if="error" class="ob-error">{{ error }}</div>

        <div class="ob-actions">
          <button class="ob-btn ob-btn-ghost" type="button" @click="back">Back</button>
          <button
            class="ob-btn ob-btn-primary ob-btn-launch"
            type="button"
            :disabled="submitting"
            @click="launch"
          >{{ submitting ? 'Creating…' : 'Create My First Video' }}</button>
        </div>
      </template>

    </div>
  </div>
</template>

<style scoped>
.ob-shell {
  min-height: 100vh;
  background:
    radial-gradient(circle at top right, rgba(255,107,53,0.12), transparent 30%),
    radial-gradient(circle at bottom left, rgba(96,165,250,0.08), transparent 25%),
    #0a0a0f;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  font-family: "DM Sans", sans-serif;
  color: #ececf3;
  position: relative;
}

.ob-skip {
  position: absolute;
  top: 20px;
  right: 24px;
  background: transparent;
  border: none;
  color: #6a6a7c;
  font-size: 13px;
  cursor: pointer;
  font-family: inherit;
  padding: 6px 10px;
  border-radius: 6px;
  transition: color 0.15s;
}
.ob-skip:hover { color: #a1a1b5; }

.ob-card {
  width: min(680px, 100%);
  background: linear-gradient(180deg, rgba(255,255,255,0.015), transparent), #17171f;
  border: 1px solid #2a2a36;
  border-radius: 16px;
  padding: 36px;
  box-shadow: 0 30px 80px rgba(0,0,0,0.5);
}

/* ── Progress dots ── */
.ob-dots {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
}
.ob-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #2a2a36;
  transition: 0.2s;
}
.ob-dot.done { background: rgba(255,107,53,0.4); }
.ob-dot.active { background: #ff6b35; width: 24px; border-radius: 4px; }

.ob-step-label { font-size: 11px; color: #6a6a7c; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 8px; }
.ob-title { font-size: 22px; font-weight: 700; margin-bottom: 24px; }
.ob-loading { color: #6a6a7c; font-size: 13px; padding: 24px 0; }

/* ── Niche grid ── */
.ob-niche-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 10px;
  margin-bottom: 28px;
}
.ob-niche-card {
  background: rgba(255,255,255,0.02);
  border: 1px solid #2a2a36;
  border-radius: 10px;
  padding: 14px;
  text-align: left;
  cursor: pointer;
  transition: 0.15s;
  font-family: inherit;
  color: #ececf3;
}
.ob-niche-card:hover { border-color: #3a3a4a; background: rgba(255,255,255,0.04); }
.ob-niche-card.selected { border-color: #ff6b35; background: rgba(255,107,53,0.08); }
.ob-niche-name { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.ob-niche-desc { font-size: 11px; color: #6a6a7c; line-height: 1.5; }
.ob-niche-card--other { border-style: dashed; }

/* ── Source type grid ── */
.ob-source-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-bottom: 16px;
}
.ob-source-card {
  background: rgba(255,255,255,0.02);
  border: 1px solid #2a2a36;
  border-radius: 10px;
  padding: 14px;
  text-align: left;
  cursor: pointer;
  transition: 0.15s;
  font-family: inherit;
  color: #ececf3;
}
.ob-source-card:hover { border-color: #3a3a4a; }
.ob-source-card.selected { border-color: #ff6b35; background: rgba(255,107,53,0.08); }
.ob-source-label { font-size: 13px; font-weight: 600; margin-bottom: 3px; }
.ob-source-hint { font-size: 11px; color: #6a6a7c; }

.ob-visual-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.ob-visual-card {
  background: rgba(255,255,255,0.02);
  border: 1px solid #2a2a36;
  border-radius: 14px;
  padding: 14px;
  text-align: left;
  cursor: pointer;
  transition: 0.15s;
  font-family: inherit;
  color: #ececf3;
}
.ob-visual-card:hover { border-color: #3a3a4a; transform: translateY(-1px); }
.ob-visual-card.selected { border-color: #ff6b35; background: rgba(255,107,53,0.08); box-shadow: 0 0 0 1px rgba(255,107,53,0.16) inset; }
.ob-visual-art {
  height: 110px;
  border-radius: 12px;
  margin-bottom: 12px;
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(255,255,255,0.06);
}
.ob-visual-art[data-vt="stock_video"] { background: linear-gradient(135deg, #101426 0%, #1f3a5f 45%, #4f46e5 100%); }
.ob-visual-art[data-vt="stock_images"] { background: linear-gradient(135deg, #10261d 0%, #1f6f52 45%, #6ee7b7 100%); }
.ob-visual-art[data-vt="ai_images"] { background: linear-gradient(135deg, #28124b 0%, #7c3aed 48%, #f59e0b 100%); }
.ob-visual-art[data-vt="waveform"] { background: linear-gradient(135deg, #091a3a 0%, #164e63 50%, #22d3ee 100%); display: flex; align-items: end; justify-content: center; gap: 4px; padding: 14px; }
.ob-reel-frame {
  position: absolute;
  inset: 16px 18px;
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255,255,255,0.22), rgba(255,255,255,0.08));
  box-shadow: 0 20px 40px rgba(0,0,0,0.24);
}
.ob-reel-strip {
  position: absolute;
  top: 16px;
  bottom: 16px;
  width: 14px;
  border-radius: 10px;
  background:
    repeating-linear-gradient(
      to bottom,
      rgba(6,10,20,0.86) 0 10px,
      rgba(255,255,255,0.95) 10px 15px
    );
}
.ob-reel-strip.left { left: 8px; }
.ob-reel-strip.right { right: 8px; }
.ob-photo-stack {
  position: absolute;
  width: 58px;
  height: 76px;
  border-radius: 12px;
  background: linear-gradient(180deg, rgba(255,255,255,0.92), rgba(236,253,245,0.9));
  box-shadow: 0 18px 34px rgba(0,0,0,0.22);
}
.ob-photo-stack.back { transform: rotate(-10deg); left: 22px; top: 18px; opacity: 0.8; }
.ob-photo-stack.front { transform: rotate(7deg); right: 22px; bottom: 14px; }
.ob-ai-orb {
  position: absolute;
  width: 84px;
  height: 84px;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  border-radius: 999px;
  background: radial-gradient(circle at 35% 35%, rgba(255,255,255,0.86), rgba(255,255,255,0.12) 45%, rgba(245,158,11,0.42) 72%, rgba(124,58,237,0.08) 100%);
  box-shadow: 0 0 35px rgba(245,158,11,0.28);
}
.ob-ai-spark {
  position: absolute;
  width: 12px;
  height: 12px;
  background: rgba(255,255,255,0.95);
  clip-path: polygon(50% 0, 61% 39%, 100% 50%, 61% 61%, 50% 100%, 39% 61%, 0 50%, 39% 39%);
}
.ob-ai-spark.one { top: 18px; left: 22px; }
.ob-ai-spark.two { top: 24px; right: 24px; width: 10px; height: 10px; }
.ob-ai-spark.three { bottom: 18px; right: 38px; width: 14px; height: 14px; }
.ob-wave-bar {
  width: 9px;
  border-radius: 999px;
  background: linear-gradient(180deg, rgba(255,255,255,0.88), rgba(255,255,255,0.2));
  box-shadow: 0 0 14px rgba(34,211,238,0.22);
}
.ob-visual-copy { display: grid; gap: 4px; }

.ob-textarea {
  width: 100%;
  background: rgba(255,255,255,0.03);
  border: 1px solid #2a2a36;
  border-radius: 8px;
  color: #ececf3;
  font-family: inherit;
  font-size: 13px;
  padding: 12px;
  resize: vertical;
  box-sizing: border-box;
  margin-bottom: 24px;
  outline: none;
  transition: border-color 0.15s;
}
.ob-textarea:focus { border-color: #ff6b35; }
.ob-textarea::placeholder { color: #3a3a4a; }

/* ── Style step ── */
.ob-field { margin-bottom: 22px; }
.ob-label { font-size: 12px; color: #a1a1b5; font-weight: 500; margin-bottom: 10px; }

.ob-ratio-grid {
  display: flex;
  gap: 10px;
}
.ob-ratio-card {
  flex: 1;
  background: rgba(255,255,255,0.02);
  border: 1px solid #2a2a36;
  border-radius: 10px;
  padding: 12px;
  text-align: center;
  cursor: pointer;
  font-family: inherit;
  color: #ececf3;
  transition: 0.15s;
}
.ob-ratio-card:hover { border-color: #3a3a4a; }
.ob-ratio-card.selected { border-color: #ff6b35; background: rgba(255,107,53,0.08); }
.ob-ratio-label { font-size: 14px; font-weight: 700; }
.ob-ratio-hint { font-size: 11px; color: #6a6a7c; margin-top: 3px; }

.ob-select {
  width: 100%;
  background: rgba(255,255,255,0.03);
  border: 1px solid #2a2a36;
  border-radius: 8px;
  color: #ececf3;
  font-family: inherit;
  font-size: 13px;
  padding: 10px 12px;
  appearance: none;
  outline: none;
}

.ob-style-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.ob-style-subtitle {
  color: #6a6a7c;
  font-size: 12px;
  line-height: 1.5;
  margin-bottom: 12px;
}
.ob-style-card {
  background: rgba(255,255,255,0.02);
  border: 1px solid #2a2a36;
  border-radius: 12px;
  padding: 10px;
  font-family: inherit;
  color: #a1a1b5;
  cursor: pointer;
  transition: 0.15s;
  text-align: left;
}
.ob-style-card:hover { border-color: #3a3a4a; color: #ececf3; transform: translateY(-1px); }
.ob-style-card.selected { border-color: #ff6b35; background: rgba(255,107,53,0.08); color: #ff6b35; box-shadow: 0 0 0 1px rgba(255,107,53,0.16) inset; }
.ob-style-preview {
  height: 112px;
  border-radius: 10px;
  background: var(--style-gradient);
  position: relative;
  overflow: hidden;
  margin-bottom: 10px;
}
.ob-style-preview-glow {
  position: absolute;
  inset: auto auto 18px 18px;
  width: 52px;
  height: 52px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--style-accent) 60%, transparent);
  filter: blur(16px);
}
.ob-style-preview-panel {
  position: absolute;
  border-radius: 12px;
  background: linear-gradient(180deg, rgba(255,255,255,0.38), rgba(255,255,255,0.08));
  border: 1px solid rgba(255,255,255,0.16);
}
.ob-style-preview-panel.main {
  width: 58px;
  height: 82px;
  right: 18px;
  top: 14px;
}
.ob-style-preview-panel.side {
  width: 40px;
  height: 58px;
  right: 54px;
  bottom: 12px;
  opacity: 0.72;
}
.ob-style-preview-caption {
  position: absolute;
  left: 14px;
  right: 14px;
  bottom: 12px;
  font-size: 11px;
  line-height: 1.35;
  color: rgba(255,255,255,0.94);
  font-weight: 600;
  text-shadow: 0 1px 2px rgba(0,0,0,0.28);
}
.ob-style-card-body { display: grid; gap: 4px; }
.ob-style-card-title { font-size: 13px; font-weight: 700; color: #ececf3; }
.ob-style-card-copy { font-size: 11px; color: #8f90a6; line-height: 1.45; }

/* ── Summary ── */
.ob-summary {
  background: rgba(255,255,255,0.02);
  border: 1px solid #2a2a36;
  border-radius: 10px;
  padding: 18px;
  margin-bottom: 18px;
  display: grid;
  gap: 12px;
}
.ob-summary-row { display: flex; justify-content: space-between; font-size: 13px; }
.ob-summary-key { color: #6a6a7c; }
.ob-summary-val { font-weight: 500; }

.ob-launch-copy {
  font-size: 13px;
  color: #6a6a7c;
  line-height: 1.6;
  margin-bottom: 24px;
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
  justify-content: flex-end;
  gap: 10px;
}
.ob-btn {
  padding: 10px 18px;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  font-family: inherit;
  font-size: 13px;
  cursor: pointer;
  transition: 0.15s;
  color: #ececf3;
  background: transparent;
}
.ob-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ob-btn-ghost:hover { background: rgba(255,255,255,0.04); }
.ob-btn-primary { background: #ff6b35; border-color: #ff6b35; color: #fff; }
.ob-btn-primary:hover:not(:disabled) { background: #ff875a; }
.ob-btn-launch { padding: 12px 28px; font-size: 14px; font-weight: 600; }

@media (max-width: 600px) {
  .ob-card { padding: 24px 18px; }
  .ob-niche-grid { grid-template-columns: 1fr; }
  .ob-source-grid { grid-template-columns: 1fr; }
  .ob-visual-grid { grid-template-columns: 1fr; }
  .ob-style-grid { grid-template-columns: 1fr; }
}
</style>
