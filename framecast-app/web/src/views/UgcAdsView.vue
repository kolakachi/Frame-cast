<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import MediaPickerModal from '../components/MediaPickerModal.vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { apiErrorMessage } from '../composables/apiError'

const router = useRouter()
const authStore = useAuthStore()

const MAX_SCRIPT = 1500
const MAX_CHARACTERS = 5

const script = ref('')
const product = ref('')
const context = ref('')
const format = ref('auto')
const duration = ref(30)
const language = ref('en')
const footageLabels = ref('')
const voiceKey = ref('')
const voices = ref([])
const voicePreviewUrl = ref('')
const loadingVoice = ref(false)
const reviewed = ref(false)
const consent = ref(false)
const quoting = ref(false)
const quotedFingerprint = ref('')
const footageShot = ref(null)
const formatOptions = [
  ['auto', 'Let the director choose'], ['direct_camera', 'Continuous talking take'],
  ['demo', 'Product / app demonstration'], ['story', 'Story with deliberate cuts'],
  ['reaction', 'Silent reaction + headline (5 or 10 seconds)'],
]
const selected = ref([])          // chosen characters
const aspectRatio = ref('9:16')
const plan = ref(null)            // { segments, reasoning, credits_per_character }
const planning = ref(false)
const generating = ref(false)
const takes = ref([])
const errorMessage = ref('')
const balance = ref(null)
let takesTimer = null
let disposed = false
async function loadTakes() {
  clearTimeout(takesTimer)
  try {
    const { data } = await api.get('/ugc/takes')
    if (!disposed) takes.value = data?.data?.takes ?? []
  } catch { /* Keep the existing cards; the editor exposes detailed job errors. */ }
  if (!disposed && takes.value.some(t => t.status === 'generating')) takesTimer = setTimeout(loadTakes, 10000)
}
onBeforeUnmount(() => { disposed = true; clearTimeout(takesTimer) })

// ── character picker ─────────────────────────────────────────────────
const pickerOpen = ref(false)
const characters = ref([])
const charsLoading = ref(false)
const filters = ref({ source: 'stock', gender: '', age_group: '', situation: '', q: '' })

const SITUATIONS = ['airport', 'beach', 'car', 'coffee shop', 'gym', 'kitchen', 'office', 'outdoors', 'snow']
const AGES = [['kid', 'Kid'], ['young_adult', 'Young adult'], ['adult', 'Adult'], ['senior', 'Senior']]

const scriptLength = computed(() => script.value.length)
const onCameraCount = computed(() =>
  (plan.value?.segments ?? []).filter((s) => s.kind === 'on_camera').length)
const perCharacter = computed(() => plan.value?.credits_per_character ?? 0)
const totalCredits = computed(() => perCharacter.value * selected.value.length)
const planFingerprint = computed(() => JSON.stringify([plan.value?.format, plan.value?.segments]))
const quoteCurrent = computed(() => !!plan.value && quotedFingerprint.value === planFingerprint.value)
const missingFootage = computed(() => (plan.value?.segments ?? []).some(s =>
  s.kind === 'b_roll' && s.source !== 'generate' && !s.asset_id))
const canGenerate = computed(() =>
  quoteCurrent.value && selected.value.length > 0 && reviewed.value && consent.value &&
  !missingFootage.value && !generating.value && !quoting.value && !planning.value)

let inputRevision = 0
watch([script, product, context, format, duration, language, footageLabels], () => {
  inputRevision++
  plan.value = null
  reviewed.value = false
}, { flush: 'sync' })
watch([planFingerprint, selected, aspectRatio, voiceKey], () => { reviewed.value = false }, { deep: true, flush: 'sync' })
watch(voiceKey, () => { voicePreviewUrl.value = '' })
watch(format, value => {
  if (value === 'reaction' && ![5, 10].includes(duration.value)) duration.value = 5
}, { flush: 'sync' })

async function loadBalance() {
  try {
    const { data } = await api.get('/me')
    balance.value = data?.data?.credits?.balance ?? null
  } catch {
    // A missing balance is cosmetic — the server rejects an unaffordable run.
  }
}

async function loadCharacters() {
  charsLoading.value = true
  try {
    const params = { include_stock: 1 }
    if (filters.value.gender) params.gender = filters.value.gender
    if (filters.value.age_group) params.age_group = filters.value.age_group
    if (filters.value.situation) params.situation = filters.value.situation
    if (filters.value.q) params.q = filters.value.q
    const { data } = await api.get('/characters', { params })
    let rows = data?.data?.characters ?? []
    if (filters.value.source === 'stock') rows = rows.filter((c) => c.is_stock)
    if (filters.value.source === 'mine') rows = rows.filter((c) => !c.is_stock)
    characters.value = rows
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, 'Could not load characters.')
  } finally {
    charsLoading.value = false
  }
}

function openPicker() {
  pickerOpen.value = true
  loadCharacters()
}

function toggleCharacter(c) {
  const i = selected.value.findIndex((x) => x.id === c.id)
  if (i >= 0) selected.value.splice(i, 1)
  else if (selected.value.length < MAX_CHARACTERS) selected.value.push(c)
  reviewed.value = false
}

const isSelected = (c) => selected.value.some((x) => x.id === c.id)

function setFilter(key, value) {
  filters.value[key] = filters.value[key] === value ? '' : value
  loadCharacters()
}

// ── planning + generation ────────────────────────────────────────────
async function makePlan() {
  if (!script.value.trim() && !context.value.trim()) return
  const revision = inputRevision
  planning.value = true
  errorMessage.value = ''
  reviewed.value = false
  try {
    const { data } = await api.post('/ugc/plan', {
      script: script.value,
      product: product.value, context: context.value, format: format.value,
      duration_seconds: Number(duration.value), language: language.value,
      available_footage: footageLabels.value.split('\n').map(s => s.trim()).filter(Boolean),
    })
    if (revision !== inputRevision) return
    plan.value = data?.data ?? null
    quotedFingerprint.value = planFingerprint.value
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, 'Could not plan the shots.')
  } finally {
    planning.value = false
  }
}

async function reprice() {
  const fingerprint = planFingerprint.value
  quoting.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.post('/ugc/quote', { format: plan.value.format, segments: plan.value.segments })
    if (fingerprint !== planFingerprint.value) return
    plan.value = { ...plan.value, ...data.data }
    quotedFingerprint.value = planFingerprint.value
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, 'Could not validate the edited plan.')
  } finally { quoting.value = false }
}

function selectFootage({ item }) {
  const seg = plan.value?.segments[footageShot.value]
  if (seg && item?.id && item._type === 'asset') seg.asset_id = item.id
  footageShot.value = null
}

async function previewVoice() {
  const key = voiceKey.value
  const profile = voices.value.find(v => v.provider_voice_key === key)
  if (!profile) return
  loadingVoice.value = true
  try {
    const { data } = await api.post('/voice-profiles/preview', { voice_profile_id: profile.id })
    if (voiceKey.value === key) voicePreviewUrl.value = data.data.preview_url
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, 'Could not load the voice sample.')
  } finally { loadingVoice.value = false }
}

async function generate() {
  if (!canGenerate.value) return
  generating.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.post('/ugc/generate', {
      script: plan.value.script,
      format: plan.value.format,
      character_ids: selected.value.map((c) => c.id),
      segments: plan.value.segments,
      aspect_ratio: aspectRatio.value,
      language: language.value, voice_key: voiceKey.value || null,
      consent: consent.value, reviewed: reviewed.value,
      credits_per_character: perCharacter.value,
    })
    takes.value = data?.data?.takes ?? []
    reviewed.value = false
    loadTakes()
    await loadBalance()
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, 'Could not start the run.')
  } finally {
    generating.value = false
  }
}

const openEditor = (id) => router.push({ name: 'project-editor', params: { projectId: id } })

onMounted(() => {
  if (!authStore.user?.is_internal) {
    router.replace({ name: 'dashboard' })
    return
  }
  loadBalance()
  loadTakes()
  api.get('/voice-profiles').then(({ data }) => {
    voices.value = (data?.data?.voice_profiles ?? []).filter(v => v.provider === 'google' && !v.is_cloned)
  }).catch(() => {})
})
</script>

<template>
  <div class="ugc-shell">
    <AppSidebar :user="authStore.user" active-page="ugc-ads" @logout="authStore.logout()" />

    <main class="ugc-main">
      <header class="ugc-top">
        <div class="ugc-crumb">My Workspace / <b>UGC Ads</b></div>
        <span class="ugc-beta">BETA · INTERNAL</span>
        <div v-if="balance !== null" class="ugc-credits">{{ balance.toLocaleString() }} credits</div>
      </header>

      <div v-if="errorMessage" class="ugc-error">{{ errorMessage }}</div>

      <div class="ugc-body">
        <!-- build column -->
        <section class="ugc-build">
          <div class="ugc-card ugc-fields">
            <h2 class="ugc-card-t">Creative brief</h2>
            <label>Format<select v-model="format" aria-label="Format"><option v-for="[key, label] in formatOptions" :key="key" :value="key">{{ label }}</option></select></label>
            <label>Product / app<input v-model="product" maxlength="200" placeholder="What are we showing?" /></label>
            <label>Audience, idea and desired reaction<textarea v-model="context" maxlength="1500" placeholder="e.g. A founder looks worried, then relieved. POV: you almost gave up on your app. Casual selfie, not a polished ad." /></label>
            <label v-if="format === 'reaction'">Target seconds<select v-model.number="duration" aria-label="Target seconds"><option :value="5">5 seconds</option><option :value="10">10 seconds</option></select></label>
            <label v-else>Target seconds<input v-model.number="duration" type="number" min="5" :max="format === 'direct_camera' ? 60 : 180" /></label>
            <label>Language<input v-model="language" maxlength="12" placeholder="en" /></label>
            <label>Available footage (one description per line)<textarea v-model="footageLabels" :placeholder="'My app screen recording\nProduct close-up'" /></label>
            <p class="ugc-hint">The director chooses shots only where needed. Reactions use action-directed animation without speech; talking takes use lip-sync. Actual actions still depend on the video model.</p>
          </div>
          <div class="ugc-card">
            <div class="ugc-card-h">
              <span class="ugc-card-t">Exact spoken script (optional)</span>
              <span class="ugc-card-c">{{ scriptLength }} / {{ MAX_SCRIPT }}</span>
            </div>
            <textarea
              v-model="script"
              class="ugc-script"
              :maxlength="MAX_SCRIPT"
              placeholder="Leave empty to write from your brief. For silent reactions, put the idea in the brief above, not here."
            ></textarea>
            <div class="ugc-card-f">
              <button class="ugc-btn" :disabled="(!script.trim() && !context.trim()) || planning || generating" @click="makePlan">
                {{ planning ? 'Planning…' : plan ? 'Re-plan shots' : 'Plan the shots' }}
              </button>
              <span v-if="plan" class="ugc-hint">
                {{ plan.segments.length }} segments · {{ onCameraCount }} on camera
              </span>
            </div>
          </div>

          <!-- the plan, as something to adjust rather than accept -->
          <div v-if="plan" class="ugc-card">
            <div class="ugc-card-h"><span class="ugc-card-t">Shot plan</span></div>
            <div v-if="plan.reasoning" class="ugc-reason">{{ plan.reasoning }}</div>
            <p class="ugc-reason">{{ plan.format }} · Edit the directions, then validate the estimate.</p>
            <ol class="ugc-segs">
              <li v-for="(seg, i) in plan.segments" :key="i" class="ugc-seg">
                <span :class="['ugc-kind', seg.kind === 'on_camera' ? 'on' : 'roll']">
                  {{ seg.kind === 'on_camera' ? 'On camera' : seg.kind === 'reaction' ? 'Silent reaction' : 'Cut-away' }} · {{ seg.seconds }}s
                </span>
                <div class="ugc-fields ugc-shot-fields">
                  <label v-if="seg.kind !== 'reaction'">Spoken words<textarea v-model="seg.script_text" maxlength="1500" /></label>
                  <label>Shot direction<textarea v-model="seg.visual_brief" maxlength="1000" /></label>
                  <label v-if="seg.kind === 'reaction'">Physical action<textarea v-model="seg.motion_prompt" maxlength="1000" /></label>
                  <label v-else>Voice delivery<textarea v-model="seg.voice_direction" maxlength="500" /></label>
                  <label>Headline (not spoken, stays for this shot)<textarea v-model="seg.headline" maxlength="180" /></label>
                  <label v-if="seg.kind === 'reaction'">Seconds<select v-model.number="seg.seconds" aria-label="Reaction seconds"><option :value="5">5</option><option :value="10">10</option></select></label>
                  <label v-else>Seconds<input v-model.number="seg.seconds" type="number" min="1" max="60" /></label>
                  <template v-if="seg.kind === 'b_roll'">
                    <label>Visual source<select v-model="seg.source" @change="seg.asset_id = null"><option value="upload">My footage</option><option value="stock">Imported stock footage</option><option value="generate">Generate illustrative still</option></select></label>
                    <button v-if="seg.source !== 'generate'" class="ugc-btn" @click="footageShot = i">{{ seg.asset_id ? `Change selected asset #${seg.asset_id}` : 'Select / upload required footage' }}</button>
                    <span v-else class="ugc-hint">An illustrative still, not a real product demonstration.</span>
                  </template>
                </div>
              </li>
            </ol>
            <div class="ugc-card-f"><button class="ugc-btn" :disabled="quoting || generating" @click="reprice">{{ quoting ? 'Checking…' : 'Validate & update estimate' }}</button><span v-if="!quoteCurrent" class="ugc-hint">Plan changed. Update estimate.</span></div>
            <p v-for="warning in plan.warnings" :key="warning" class="ugc-reason">{{ warning }}</p>
          </div>

          <div class="ugc-card">
            <div class="ugc-card-h">
              <span class="ugc-card-t">Characters</span>
              <span class="ugc-card-c">{{ selected.length ? `${selected.length} selected` : 'none' }}</span>
            </div>
            <div v-for="c in selected" :key="c.id" class="ugc-ch">
              <div class="ugc-ch-av">
                <img v-if="c.reference_asset?.thumbnail_url" :src="c.reference_asset.thumbnail_url" alt="" />
                <span v-else>☺</span>
              </div>
              <div class="ugc-ch-m">
                <div class="ugc-ch-n">{{ c.name }}</div>
                <div class="ugc-ch-s">{{ (c.situations || []).join(' · ') || c.age_group || '—' }}</div>
              </div>
              <button class="ugc-ch-x" type="button" @click="toggleCharacter(c)">✕</button>
            </div>
            <div class="ugc-card-f">
              <button class="ugc-btn" @click="openPicker">＋ Add characters</button>
            </div>
          </div>

          <div class="ugc-card">
            <div class="ugc-card-h"><span class="ugc-card-t">Output</span></div>
            <div class="ugc-out">
              <div v-if="plan?.format !== 'reaction'" class="ugc-fields">
                <label>Voice<select v-model="voiceKey" aria-label="Voice"><option value="">Automatic per character</option><option v-for="v in voices" :key="v.id" :value="v.provider_voice_key">{{ v.name }}</option></select></label>
                <button v-if="voiceKey" class="ugc-btn" :disabled="loadingVoice" @click="previewVoice">{{ loadingVoice ? 'Loading…' : 'Hear voice sample' }}</button>
                <audio v-if="voicePreviewUrl" :src="voicePreviewUrl" controls />
                <span class="ugc-hint">Voice samples are generic, not a rehearsal of this script. Automatic chooses a voice per character.</span>
              </div>
              <div class="ugc-seg-group">
                <button
                  v-for="r in ['9:16', '1:1', '16:9']"
                  :key="r"
                  :class="['ugc-seg-btn', aspectRatio === r ? 'on' : '']"
                  type="button"
                  @click="aspectRatio = r"
                >{{ r }}</button>
              </div>
            </div>

            <div class="ugc-gen">
              <span v-if="!plan || !selected.length" class="ugc-math">
                Add a brief, review the shot plan, and pick at least one character.
              </span>
              <span v-else class="ugc-math">
                {{ selected.length }} take{{ selected.length > 1 ? 's' : '' }} ×
                {{ perCharacter }} credits ≈ <b>{{ totalCredits }} credits estimated</b>
              </span>

              <div v-if="plan" class="ugc-fields">
                <label class="ugc-check"><input v-model="reviewed" type="checkbox" :disabled="!quoteCurrent" /> I reviewed the shots and estimate. Start with one character to check the performance.</label>
                <label class="ugc-check"><input v-model="consent" type="checkbox" /> I have permission to use these characters and footage for this generated video.</label>
                <span v-if="missingFootage" class="ugc-hint">Select the required footage before generating.</span>
              </div>

              <div class="ugc-gen-row">
                <button class="ugc-btn ugc-btn-primary" :disabled="!canGenerate" @click="generate">
                  {{ generating ? 'Starting…' : 'Generate takes' }}
                </button>
              </div>
            </div>
          </div>
        </section>

        <!-- takes -->
        <section class="ugc-stage">
          <div class="ugc-stage-h">
            <span class="ugc-card-t">Takes</span>
            <span class="ugc-card-c">{{ takes.length }}</span>
          </div>

          <div v-if="!takes.length" class="ugc-empty">
            <div class="ugc-empty-i">▢</div>
            <p>One take per character from the reviewed plan. Inspect motion, voice and text in the editor before exporting.</p>
          </div>

          <div v-else class="ugc-takes">
            <div v-for="t in takes" :key="t.id" class="ugc-take">
              <div class="ugc-take-b">
                <div class="ugc-ch-n">{{ t.character }}</div>
                <div class="ugc-take-m">{{ t.scenes }} scenes · {{ t.credits }} estimated credits · {{ t.status === 'ready_for_review' ? 'Ready to review' : t.status === 'needs_attention' ? 'Needs attention — open editor' : 'Generating…' }}</div>
                <div class="ugc-take-a">
                  <button class="ugc-btn ugc-btn-primary" @click="openEditor(t.id)">Open in editor →</button>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- character picker -->
      <MediaPickerModal mode="visual" :visible="footageShot !== null" @close="footageShot = null" @select="selectFootage" />
      <div v-if="pickerOpen" class="ugc-scrim" @click.self="pickerOpen = false">
        <div class="ugc-modal">
          <aside class="ugc-m-rail">
            <div class="ugc-m-t">Add characters</div>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Source</div>
              <div class="ugc-tags">
                <button
                  v-for="s in ['stock', 'mine']"
                  :key="s"
                  :class="['ugc-tag', filters.source === s ? 'on' : '']"
                  type="button"
                  @click="filters.source = s; loadCharacters()"
                >{{ s === 'stock' ? 'Stock' : 'Mine' }}</button>
              </div>
            </div>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Gender</div>
              <div class="ugc-tags">
                <button
                  v-for="g in ['female', 'male']"
                  :key="g"
                  :class="['ugc-tag', filters.gender === g ? 'on' : '']"
                  type="button"
                  @click="setFilter('gender', g)"
                >{{ g === 'female' ? 'Female' : 'Male' }}</button>
              </div>
            </div>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Age</div>
              <div class="ugc-tags">
                <button
                  v-for="[key, label] in AGES"
                  :key="key"
                  :class="['ugc-tag', filters.age_group === key ? 'on' : '']"
                  type="button"
                  @click="setFilter('age_group', key)"
                >{{ label }}</button>
              </div>
            </div>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Setting</div>
              <div class="ugc-tags">
                <button
                  v-for="s in SITUATIONS"
                  :key="s"
                  :class="['ugc-tag', filters.situation === s ? 'on' : '']"
                  type="button"
                  @click="setFilter('situation', s)"
                >{{ s }}</button>
              </div>
            </div>
          </aside>

          <div class="ugc-m-body">
            <div class="ugc-m-search">
              <input
                v-model="filters.q"
                class="ugc-m-in"
                placeholder="Search characters…"
                @keyup.enter="loadCharacters"
              />
              <button class="ugc-btn" @click="loadCharacters">Search</button>
            </div>

            <div class="ugc-m-grid">
              <div v-if="charsLoading" class="ugc-hint">Loading…</div>
              <div v-else-if="!characters.length" class="ugc-hint">
                No characters match. Stock actors are still being added — switch Source to “Mine”
                to use your own.
              </div>
              <button
                v-for="c in characters"
                :key="c.id"
                :class="['ugc-pc', isSelected(c) ? 'on' : '']"
                type="button"
                @click="toggleCharacter(c)"
              >
                <div class="ugc-pc-f">
                  <img v-if="c.reference_asset?.thumbnail_url" :src="c.reference_asset.thumbnail_url" alt="" />
                  <span v-else>☺</span>
                  <span v-if="isSelected(c)" class="ugc-pc-ck">✓</span>
                </div>
                <div class="ugc-pc-n">
                  {{ c.name }}
                  <i :class="['ugc-mini', c.is_stock ? 'stock' : '']">{{ c.is_stock ? 'STOCK' : 'MINE' }}</i>
                </div>
                <div class="ugc-pc-s">{{ (c.situations || []).join(' · ') || '—' }}</div>
              </button>
            </div>

            <div class="ugc-m-foot">
              <span class="ugc-hint">
                {{ selected.length }} selected · max {{ MAX_CHARACTERS }}
              </span>
              <button class="ugc-btn ugc-btn-primary" @click="pickerOpen = false">Done</button>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.ugc-fields { padding: 14px; display: flex; flex-direction: column; gap: 10px; }
.ugc-fields label { display: flex; flex-direction: column; gap: 5px; font-size: 12px; color: var(--color-text-secondary); }
.ugc-fields input, .ugc-fields textarea, .ugc-fields select { box-sizing: border-box; width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: 6px; background: var(--color-bg-base); color: var(--color-text-primary); font: inherit; }
.ugc-fields textarea { min-height: 64px; resize: vertical; }
.ugc-fields audio { width: 100%; }
.ugc-fields .ugc-check { flex-direction: row; align-items: flex-start; line-height: 1.5; }
.ugc-check input { width: auto; }
.ugc-shot-fields { padding: 8px 0; width: 100%; }
/* The sidebar is fixed-position, so the main column must be offset by its
   width or it renders underneath. --sidebar-width tracks the collapsed state. */
.ugc-shell { display: flex; min-height: 100vh; background: var(--color-bg-base); }
.ugc-main { margin-left: var(--sidebar-width, 220px); flex: 1; min-width: 0; display: flex; flex-direction: column; }

.ugc-top { display: flex; align-items: center; gap: 10px; padding: 14px 22px; border-bottom: 1px solid var(--color-border); }
.ugc-crumb { font-size: 13px; color: var(--color-text-muted); }
.ugc-crumb b { color: var(--color-text-primary); font-weight: 500; }
.ugc-beta { padding: 2px 7px; border-radius: 5px; background: rgba(255,107,53,.13); color: var(--color-accent); font: 10px var(--font-mono); letter-spacing: .4px; }
.ugc-credits { margin-left: auto; font: 12px var(--font-mono); color: var(--color-text-secondary); padding: 5px 11px; border: 1px solid var(--color-border); border-radius: 999px; }

.ugc-error { margin: 14px 22px 0; padding: 10px 13px; border-radius: 9px; background: rgba(240,112,112,.1); border: 1px solid rgba(240,112,112,.3); color: #f0a0a0; font-size: 12.5px; }

.ugc-body { display: flex; align-items: stretch; gap: 0; flex: 1; min-height: 0; }
.ugc-build { width: 480px; flex-shrink: 0; padding: 18px 20px; border-right: 1px solid var(--color-border); overflow-y: auto; }
.ugc-stage { flex: 1; padding: 18px 22px; min-width: 0; overflow-y: auto; }

.ugc-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; margin-bottom: 14px; }
.ugc-card-h { display: flex; align-items: center; gap: 8px; padding: 12px 14px 0; }
.ugc-card-t { font-size: 12.5px; font-weight: 500; color: var(--color-text-primary); }
.ugc-card-c { margin-left: auto; font: 10.5px var(--font-mono); color: var(--color-text-muted); }
.ugc-card-f { display: flex; align-items: center; gap: 10px; padding: 0 14px 13px; }

.ugc-script { width: 100%; background: none; border: none; color: var(--color-text-primary); resize: vertical; padding: 11px 14px; font-size: 13px; line-height: 1.65; outline: none; min-height: 96px; font-family: inherit; }
.ugc-script::placeholder { color: var(--color-text-muted); }

.ugc-reason { padding: 0 14px 8px; font-size: 11.5px; color: var(--color-text-muted); font-style: italic; }
.ugc-segs { list-style: none; margin: 0; padding: 4px 14px 12px; }
.ugc-seg { display: grid; grid-template-columns: 74px 1fr; gap: 8px; padding: 7px 0; border-top: 1px solid var(--color-border); align-items: start; }
.ugc-kind { font: 9.5px var(--font-mono); padding: 3px 6px; border-radius: 4px; text-align: center; }
.ugc-kind.on { background: rgba(255,107,53,.13); color: var(--color-accent); }
.ugc-kind.roll { background: var(--color-bg-elevated); color: var(--color-text-muted); }
.ugc-seg-text { font-size: 12px; color: var(--color-text-secondary); line-height: 1.5; }
.ugc-seg-brief { grid-column: 2; font-size: 11px; color: var(--color-text-muted); }

.ugc-ch { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-top: 1px solid var(--color-border); }
.ugc-ch-av { width: 36px; height: 36px; border-radius: 8px; overflow: hidden; background: var(--color-bg-elevated); display: grid; place-items: center; flex-shrink: 0; }
.ugc-ch-av img { width: 100%; height: 100%; object-fit: cover; }
.ugc-ch-n { font-size: 12.5px; font-weight: 500; color: var(--color-text-primary); }
.ugc-ch-s { font-size: 10.5px; color: var(--color-text-muted); margin-top: 1px; }
.ugc-ch-x { margin-left: auto; background: none; border: none; color: var(--color-text-muted); cursor: pointer; font-size: 13px; }

.ugc-out { display: flex; gap: 8px; padding: 12px 14px 14px; }
.ugc-seg-group { display: flex; border: 1px solid var(--color-border); border-radius: 8px; overflow: hidden; }
.ugc-seg-btn { padding: 6px 11px; font-size: 11.5px; background: var(--color-bg-elevated); color: var(--color-text-secondary); border: none; cursor: pointer; }
.ugc-seg-btn.on { background: rgba(255,107,53,.13); color: var(--color-accent); }

.ugc-gen { padding: 13px 14px; border-top: 1px solid var(--color-border); }
.ugc-math { display: block; font: 11px var(--font-mono); color: var(--color-text-secondary); margin-bottom: 10px; }
.ugc-math b { color: #e8b54a; }
.ugc-gate { display: flex; align-items: flex-start; gap: 10px; padding: 10px 11px; border-radius: 9px; background: rgba(232,181,74,.1); border: 1px solid rgba(232,181,74,.3); margin-bottom: 11px; }
.ugc-gate-i { color: #e8b54a; font-size: 13px; }
.ugc-gate-t { font-size: 11.5px; color: #f0d6a2; line-height: 1.5; }
.ugc-gate-t b { color: #e8b54a; }
.ugc-gate.ok { background: rgba(62,207,142,.1); border-color: rgba(62,207,142,.3); }
.ugc-gate.ok .ugc-gate-i { color: #3ecf8e; }
.ugc-gate.ok .ugc-gate-t { color: #a8e6c9; }
.ugc-gate.ok .ugc-gate-t b { color: #3ecf8e; }
.ugc-listen { margin-left: auto; flex-shrink: 0; padding: 6px 11px; border-radius: 7px; border: 1px solid rgba(232,181,74,.4); background: rgba(232,181,74,.1); color: #e8b54a; font-size: 11.5px; font-weight: 600; cursor: pointer; }
.ugc-gen-row { display: flex; align-items: center; gap: 10px; }

.ugc-btn { display: inline-flex; align-items: center; gap: 7px; padding: 7px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); font-size: 12px; cursor: pointer; font-family: inherit; }
.ugc-btn:hover:not(:disabled) { border-color: var(--color-border-active); }
.ugc-btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #1a0a04; font-weight: 700; }
.ugc-btn-primary:hover:not(:disabled) { background: var(--color-accent-hover); }
.ugc-btn:disabled { opacity: .45; cursor: not-allowed; }
.ugc-hint { font-size: 11px; color: var(--color-text-muted); }

.ugc-stage-h { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
.ugc-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; height: 280px; text-align: center; color: var(--color-text-muted); }
.ugc-empty-i { width: 44px; height: 44px; border-radius: 11px; background: var(--color-bg-card); border: 1px solid var(--color-border); display: grid; place-items: center; font-size: 18px; }
.ugc-empty p { font-size: 12.5px; line-height: 1.6; max-width: 260px; }
.ugc-takes { display: flex; flex-direction: column; gap: 11px; }
.ugc-take { padding: 12px; border-radius: 11px; background: var(--color-bg-card); border: 1px solid var(--color-border); }
.ugc-take-m { font: 10.5px var(--font-mono); color: var(--color-text-muted); margin-top: 3px; }
.ugc-take-a { margin-top: 10px; }

.ugc-scrim { position: fixed; inset: 0; background: rgba(5,5,9,.74); display: grid; place-items: center; padding: 32px; z-index: 60; }
.ugc-modal { width: 100%; max-width: 820px; height: 100%; max-height: 560px; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 14px; display: flex; overflow: hidden; }
.ugc-m-rail { width: 194px; flex-shrink: 0; border-right: 1px solid var(--color-border); padding: 15px 13px; overflow-y: auto; }
.ugc-m-t { font-size: 13px; font-weight: 500; margin-bottom: 13px; color: var(--color-text-primary); }
.ugc-facet { margin-bottom: 15px; }
.ugc-facet-h { font: 10px var(--font-mono); color: var(--color-text-muted); letter-spacing: .6px; text-transform: uppercase; margin-bottom: 7px; }
.ugc-tags { display: flex; flex-wrap: wrap; gap: 5px; }
.ugc-tag { padding: 4px 8px; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-secondary); font-size: 11px; cursor: pointer; font-family: inherit; }
.ugc-tag.on { background: rgba(255,107,53,.13); border-color: rgba(255,107,53,.42); color: var(--color-accent); }
.ugc-m-body { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.ugc-m-search { padding: 13px 15px; border-bottom: 1px solid var(--color-border); display: flex; gap: 9px; }
.ugc-m-in { flex: 1; background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 8px; padding: 7px 10px; color: var(--color-text-primary); font-size: 12.5px; outline: none; font-family: inherit; }
.ugc-m-grid { flex: 1; overflow-y: auto; padding: 15px; display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 12px; align-content: start; }
.ugc-pc { background: none; border: none; padding: 0; cursor: pointer; text-align: left; font-family: inherit; }
.ugc-pc-f { aspect-ratio: 9/16; border-radius: 9px; border: 1px solid var(--color-border); position: relative; background: var(--color-bg-elevated); display: grid; place-items: center; font-size: 28px; overflow: hidden; }
.ugc-pc-f img { width: 100%; height: 100%; object-fit: cover; }
.ugc-pc.on .ugc-pc-f { border-color: var(--color-accent); box-shadow: 0 0 0 2px rgba(255,107,53,.42); }
.ugc-pc-ck { position: absolute; top: 6px; right: 6px; width: 18px; height: 18px; border-radius: 50%; background: var(--color-accent); color: #1a0a04; font-size: 10px; font-weight: 700; display: grid; place-items: center; }
.ugc-pc-n { display: flex; align-items: center; gap: 5px; margin-top: 6px; font-size: 11.5px; font-weight: 500; color: var(--color-text-primary); }
.ugc-pc-s { font-size: 10px; color: var(--color-text-muted); }
.ugc-mini { padding: 1px 5px; border-radius: 4px; font: 8.5px var(--font-mono); background: var(--color-bg-elevated); color: var(--color-text-muted); font-style: normal; }
.ugc-mini.stock { background: rgba(62,207,142,.12); color: #3ecf8e; }
.ugc-m-foot { padding: 11px 15px; border-top: 1px solid var(--color-border); display: flex; align-items: center; gap: 9px; }
.ugc-m-foot .ugc-btn-primary { margin-left: auto; }
</style>
