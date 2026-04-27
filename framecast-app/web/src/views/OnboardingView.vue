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

        <div class="ob-field">
          <div class="ob-label">Visual Style</div>
          <div class="ob-style-grid">
            <button
              v-for="s in styles"
              :key="s.key"
              :class="['ob-style-card', selectedStyle === s.key ? 'selected' : '']"
              type="button"
              @click="selectedStyle = s.key"
            >{{ s.label }}</button>
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
            <span class="ob-summary-val">{{ selectedNiche?.name || 'General' }}</span>
          </div>
          <div class="ob-summary-row">
            <span class="ob-summary-key">Source</span>
            <span class="ob-summary-val">{{ sourceTypes.find(s => s.key === sourceType)?.label }}</span>
          </div>
          <div class="ob-summary-row">
            <span class="ob-summary-key">Format</span>
            <span class="ob-summary-val">{{ aspectRatio }} · {{ styles.find(s => s.key === selectedStyle)?.label }}</span>
          </div>
          <div class="ob-summary-row">
            <span class="ob-summary-key">Voice</span>
            <span class="ob-summary-val">{{ voices.find(v => v.provider_voice_key === selectedVoiceKey)?.name || 'Default' }}</span>
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
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.ob-style-card {
  background: rgba(255,255,255,0.02);
  border: 1px solid #2a2a36;
  border-radius: 8px;
  padding: 8px 14px;
  font-size: 12px;
  font-family: inherit;
  color: #a1a1b5;
  cursor: pointer;
  transition: 0.15s;
}
.ob-style-card:hover { border-color: #3a3a4a; color: #ececf3; }
.ob-style-card.selected { border-color: #ff6b35; background: rgba(255,107,53,0.08); color: #ff6b35; }

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
}
</style>
