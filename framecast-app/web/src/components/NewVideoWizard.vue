<script setup>
import { computed, ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const props = defineProps({
  channels: { type: Array, default: () => [] },
})

const emit = defineEmits(['created'])

const router = useRouter()

const show = ref(false)

// 0 = path picker (Start from Scratch vs Generate from brief — new step
//     that fronts the wizard so blank-project users don't slog through niche
//     selection)
// 1 = niche pick (only in Generate path)
// 2 = source type
// 3 = content (or, in the blank path, the minimal title/aspect/channel form)
const wizardStep = ref(0)
const niches = ref([])
const selectedNicheId = ref(null)
const customNicheSelected = ref(false)
const customNicheName = ref('')
const customNicheContext = ref('')
const customNicheVisualStyle = ref('')
const customNicheVoiceTone = ref('')
const customNicheMusicMood = ref('')
const wizardSourceType = ref('prompt')
const wizardCreateState = ref('idle')
const wizardCreateError = ref('')
const languageSelections = ref(['en'])
const platformTarget = ref('tiktok')
const aspectRatio = ref('9:16')
const channelId = ref('')
const brandKitId = ref('')
const title = ref('')
const durationTargetSeconds = ref('60')
const contentGoal = ref('')
const promptText = ref('')
const scriptText = ref('')
const urlText = ref('')
const csvText = ref('')
const productName = ref('')
const productDescription = ref('')
const productUrl = ref('')
const targetAudience = ref('')
const audioFile = ref(null)
const videoFile = ref(null)
const imageFiles = ref([])
const imagePreviewItems = ref([])
const imageContext = ref('')
const sourceImageAssetIds = ref([])
const imageVisualMode = ref('upload')
const aiBrollStyle = ref('photorealistic')
// Free-text descriptor used when the user picks the 'custom' chip. Sent to
// the backend as project.custom_visual_style and is substituted into every
// scene's image prompt in place of a preset style descriptor.
const customVisualStyle = ref('')
const globalVisualMode = ref('stock_video') // 'stock_video' | 'stock_images' | 'ai_images' | 'waveform'
const brandKits = ref([])
// Recurring character (optional) — picked at wizard time, stamped onto every
// scene this project generates. Empty string = "no character".
const characters = ref([])
const selectedCharacterId = ref('')
const selectedCharacter = computed(() => characters.value.find((c) => String(c.id) === String(selectedCharacterId.value)) ?? null)

const selectedNiche = computed(() => niches.value.find((n) => n.id === selectedNicheId.value) ?? null)
const customNicheSummary = computed(() => {
  return [
    customNicheName.value.trim(),
    customNicheVisualStyle.value.trim() ? `${customNicheVisualStyle.value.trim()} visuals` : '',
    customNicheVoiceTone.value.trim() ? `${customNicheVoiceTone.value.trim()} voice` : '',
    customNicheMusicMood.value.trim() ? `${customNicheMusicMood.value.trim()} music` : '',
  ].filter(Boolean).join(', ')
})

const sourceOptions = [
  { key: 'prompt',              icon: '✍️', label: 'Write a Prompt',     hint: 'AI generates the script' },
  { key: 'script',              icon: '📄', label: 'Paste a Script',      hint: 'Your script, broken into scenes' },
  { key: 'url',                 icon: '🔗', label: 'From URL / Article',  hint: 'Paste any article link' },
  { key: 'images',              icon: '🖼️', label: 'Upload Images',       hint: 'AI generates matching visuals' },
  { key: 'product_description', icon: '📦', label: 'Product Description', hint: 'Name, features, audience' },
  { key: 'audio_upload',        icon: '🎙️', label: 'Upload Audio',        hint: 'Transcribe and structure' },
  { key: 'video_upload',        icon: '🎬', label: 'Upload Video',        hint: 'Extract and repurpose' },
  { key: 'csv_topic',           icon: '📋', label: 'CSV Batch',           hint: 'Multiple topics at once' },
  // 'blank' / 'Start from Scratch' used to live here; promoted to the new
  // step-0 path picker so blank users don't have to scroll past 8 other
  // options to find it.
]

const durationOptions = [
  { label: '30s', value: '30' },
  { label: '60s', value: '60' },
  { label: '90s', value: '90' },
  { label: '3 min', value: '180' },
]

const visualTypeOptions = [
  { key: 'stock_video', label: 'Stock Video', hint: 'Real clips matched to each scene' },
  { key: 'stock_images', label: 'Stock Images', hint: 'Editorial stills and image montages' },
  { key: 'ai_images', label: 'AI Images', hint: 'Generated frames in your chosen style' },
  { key: 'waveform', label: 'Audiogram', hint: 'Audio-reactive bars for podcasts and narration' },
]

function projectVisualTypeForMode(mode) {
  if (mode === 'ai_images') return 'ai_image'
  if (mode === 'stock_images') return 'stock_image'
  if (mode === 'waveform') return 'waveform'
  return 'stock_clip'
}

const aiBrollStyleOptions = [
  { key: 'cinematic',      label: 'Cinematic',      hint: 'Dramatic film-style shots',    tone: 'rgba(251,191,36,0.22)' },
  { key: 'photorealistic', label: 'Photorealistic', hint: 'Cinematic real-world stills',  tone: 'rgba(167,139,250,0.24)' },
  { key: 'realistic',      label: 'Realistic',      hint: 'Natural people and places',    tone: 'rgba(96,165,250,0.2)' },
  { key: '3d_animated',    label: '3D Animated',    hint: 'Pixar-quality 3D renders',     tone: 'rgba(34,211,238,0.22)' },
  { key: 'cyberpunk_80s',  label: '80s Cyberpunk',  hint: 'Neon retro future',            tone: 'rgba(236,72,153,0.22)' },
  { key: 'anime_80s',      label: '80s Anime',      hint: 'Vintage cel animation',        tone: 'rgba(52,211,153,0.18)' },
  { key: 'anime_90s',      label: '90s Anime',      hint: 'Painted anime worlds',         tone: 'rgba(251,191,36,0.2)' },
  { key: 'anime',          label: 'Anime',          hint: 'Vibrant cel-shaded art',       tone: 'rgba(244,114,182,0.22)' },
  { key: 'dark_fantasy',   label: 'Dark Fantasy',   hint: 'Gothic and ethereal',          tone: 'rgba(148,163,184,0.24)' },
  { key: 'fantasy_retro',  label: 'Fantasy Retro',  hint: 'Painterly storybook magic',    tone: 'rgba(129,140,248,0.2)' },
  { key: 'comic',          label: 'Comic',          hint: 'Bold ink and action',          tone: 'rgba(248,113,113,0.22)' },
  { key: 'film_noir',      label: 'Film Noir',      hint: 'Black and white shadows',      tone: 'rgba(255,255,255,0.16)' },
  { key: 'dark',           label: 'Dark',           hint: 'Moody high-contrast noir',     tone: 'rgba(30,30,50,0.8)' },
  { key: 'line_drawing',   label: 'Line Drawing',   hint: 'Clean monochrome sketch',      tone: 'rgba(255,255,255,0.26)' },
  { key: 'watercolor',     label: 'Watercolor',     hint: 'Soft illustrated washes',      tone: 'rgba(45,212,191,0.2)' },
  { key: 'paper_cutout',   label: 'Paper Cutout',   hint: 'Layered paper collage',        tone: 'rgba(251,146,60,0.18)' },
  { key: 'cartoon',        label: 'Cartoon',        hint: 'Simple expressive art',        tone: 'rgba(251,146,60,0.22)' },
  { key: 'documentary',    label: 'Documentary',    hint: 'Natural light realism',        tone: 'rgba(74,222,128,0.18)' },
  { key: 'minimalist',     label: 'Minimalist',     hint: 'Clean muted composition',      tone: 'rgba(148,163,184,0.18)' },
  { key: 'vintage',        label: 'Vintage',        hint: 'Retro film grain aesthetic',   tone: 'rgba(217,119,6,0.22)' },
  { key: 'neon',           label: 'Neon',           hint: 'Glowing cyberpunk night',      tone: 'rgba(139,92,246,0.26)' },
  // "custom" — last chip so it doesn't shift the grid the user has memorised.
  // When picked, a textarea unfolds below the grid for the user to write the
  // style descriptor that gets appended to every scene's image prompt.
  { key: 'custom',         label: '✦ Custom',       hint: 'Write your own style',         tone: 'rgba(255,107,53,0.22)' },
]

watch(channelId, (next, prev) => {
  const channel = props.channels.find((c) => String(c.id) === String(next))
  if (!channel) return
  if (!brandKitId.value || String(brandKitId.value) === String(props.channels.find((c) => String(c.id) === String(prev))?.brand_kit_id || '')) {
    brandKitId.value = channel.brand_kit_id ? String(channel.brand_kit_id) : ''
  }
  if (channel.default_language && languageSelections.value.length === 1) {
    languageSelections.value = [channel.default_language]
  }
  if (Array.isArray(channel.platform_targets) && channel.platform_targets[0]) {
    platformTarget.value = channel.platform_targets[0]
  }
})

function nicheTagsFor(niche) {
  const tags = []
  if (niche.default_visual_style) tags.push(niche.default_visual_style)
  if (niche.default_voice_tone) tags.push(niche.default_voice_tone)
  if (niche.default_caption_preset_name) tags.push(niche.default_caption_preset_name)
  return tags
}

function selectSeededNiche(nicheId) {
  selectedNicheId.value = nicheId
  customNicheSelected.value = false
  scrollToActions()
}

function selectCustomNiche() {
  selectedNicheId.value = null
  customNicheSelected.value = true
  scrollToActions()
}

// After a niche pick the Continue button is below the fold on small screens.
// Smoothly bring it into view so the user doesn't have to hunt for it.
function scrollToActions() {
  // Defer one frame so the niche-selected styling has rendered first.
  requestAnimationFrame(() => {
    const target = document.querySelector('.wizard-modal-body .modal-actions')
    if (target) {
      target.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  })
}

function setWizardSourceType(sourceType) {
  wizardSourceType.value = sourceType
  globalVisualMode.value = 'stock_video'
  if (sourceType !== 'images') {
    sourceImageAssetIds.value = []
    imageFiles.value = []
    revokeImagePreviewItems()
  }
}

function trimString(value) { return String(value ?? '').trim() }

function customNichePromptBlock() {
  if (!customNicheSelected.value) return ''
  return [
    customNicheName.value.trim() ? `Custom niche: ${customNicheName.value.trim()}` : 'Custom niche selected',
    customNicheContext.value.trim() ? `Custom niche context: ${customNicheContext.value.trim()}` : '',
    customNicheVisualStyle.value.trim() ? `Preferred visual style: ${customNicheVisualStyle.value.trim()}` : '',
    customNicheVoiceTone.value.trim() ? `Preferred voice tone: ${customNicheVoiceTone.value.trim()}` : '',
    customNicheMusicMood.value.trim() ? `Preferred music mood: ${customNicheMusicMood.value.trim()}` : '',
  ].filter(Boolean).join('\n')
}

function withCustomNicheContext(source) {
  const customBlock = customNichePromptBlock()
  if (!customBlock) return source
  return [customBlock, source].filter((p) => trimString(p) !== '').join('\n\n')
}

function buildSourceContentRaw(sourceType = wizardSourceType.value) {
  if (sourceType === 'script') return withCustomNicheContext(scriptText.value.trim())
  if (sourceType === 'url') return withCustomNicheContext(urlText.value.trim())
  if (sourceType === 'prompt') return withCustomNicheContext(promptText.value.trim())
  if (sourceType === 'csv_topic') return withCustomNicheContext(csvText.value.trim())
  if (sourceType === 'images') return withCustomNicheContext(imageContext.value.trim())
  return withCustomNicheContext([
    `Product Name: ${productName.value.trim()}`,
    `Product Description: ${productDescription.value.trim()}`,
    productUrl.value.trim() ? `Product URL: ${productUrl.value.trim()}` : '',
    targetAudience.value.trim() ? `Target Audience: ${targetAudience.value.trim()}` : '',
  ].filter(Boolean).join('\n'))
}

function selectedFile(event) { return event.target?.files?.[0] || null }
function selectedFiles(event) { return Array.from(event.target?.files || []).slice(0, 15) }

function revokeImagePreviewItems() {
  imagePreviewItems.value.forEach((item) => URL.revokeObjectURL(item.url))
  imagePreviewItems.value = []
}

function setImageFiles(files) {
  revokeImagePreviewItems()
  imageFiles.value = files.slice(0, 15)
  imagePreviewItems.value = imageFiles.value.map((file) => ({
    key: `${file.name}-${file.size}-${file.lastModified}`,
    name: file.name,
    url: URL.createObjectURL(file),
  }))
}

function appendImageFiles(files) {
  setImageFiles([...imageFiles.value, ...files].slice(0, 15))
}

async function uploadAssetSource(file, assetType) {
  const formData = new FormData()
  formData.append('title', file.name.replace(/\.[^.]+$/, '') || `${assetType} source`)
  formData.append('asset_type', assetType)
  formData.append('description', `Uploaded from the create video ${assetType} source flow.`)
  formData.append('asset_file', file)
  if (channelId.value) formData.append('channel_id', String(channelId.value))
  const res = await api.post('/assets', formData, { headers: { 'Content-Type': 'multipart/form-data' } })
  return res.data?.data?.asset
}

function assetSummary(asset, file) {
  return [
    `asset_id:${asset?.id}`,
    `title:${asset?.title || file.name}`,
    `mime_type:${asset?.mime_type || file.type}`,
    `transcription_status:${asset?.transcription_status || 'queued'}`,
  ].join('\n')
}

async function uploadMediaSource(file, assetType) {
  const asset = await uploadAssetSource(file, assetType)
  return assetSummary(asset, file)
}

async function uploadImageSources() {
  const uploaded = await Promise.all(imageFiles.value.map((f) => uploadAssetSource(f, 'image')))
  sourceImageAssetIds.value = uploaded.map((a) => Number(a?.id)).filter(Boolean)
  return [
    `image_asset_count:${uploaded.length}`,
    ...uploaded.map((a, i) => `Image ${i + 1}\n${assetSummary(a, imageFiles.value[i])}`),
    imageContext.value.trim() ? `context:${imageContext.value.trim()}` : '',
  ].filter(Boolean).join('\n\n')
}

async function resolveSourceContentRaw(sourceType = wizardSourceType.value) {
  if (sourceType === 'audio_upload' && audioFile.value) return uploadMediaSource(audioFile.value, 'audio')
  if (sourceType === 'video_upload' && videoFile.value) return uploadMediaSource(videoFile.value, 'video')
  if (sourceType === 'images' && imageFiles.value.length > 0) return uploadImageSources()
  return buildSourceContentRaw(sourceType)
}

async function loadNiches() {
  try {
    const res = await api.get('/niches')
    niches.value = res.data?.data?.niches ?? []
  } catch { niches.value = [] }
}

async function loadBrandKits() {
  try {
    const res = await api.get('/brand-kits')
    brandKits.value = res.data?.data?.brand_kits ?? []
  } catch { brandKits.value = [] }
}

async function loadCharacters() {
  try {
    const res = await api.get('/characters')
    characters.value = res.data?.data?.characters ?? []
  } catch { characters.value = [] }
}

function open(initialSourceType = 'prompt', presetChannelId = null) {
  // If the caller passed initialSourceType='blank' (legacy path from the
  // dashboard's empty-state button), jump straight to the blank-project
  // mini-form. Otherwise default to the new step-0 path picker.
  wizardStep.value = initialSourceType === 'blank' ? 3 : 0
  selectedNicheId.value = null
  customNicheSelected.value = false
  customNicheName.value = ''
  customNicheContext.value = ''
  customNicheVisualStyle.value = ''
  customNicheVoiceTone.value = ''
  customNicheMusicMood.value = ''
  wizardSourceType.value = initialSourceType
  wizardCreateState.value = 'idle'
  wizardCreateError.value = ''
  durationTargetSeconds.value = '60'
  sourceImageAssetIds.value = []
  imageVisualMode.value = 'upload'
  aiBrollStyle.value = 'photorealistic'
  customVisualStyle.value = ''
  globalVisualMode.value = 'stock_video'
  channelId.value = presetChannelId ? String(presetChannelId) : ''
  title.value = ''
  promptText.value = ''
  scriptText.value = ''
  urlText.value = ''
  audioFile.value = null
  videoFile.value = null
  revokeImagePreviewItems()
  selectedCharacterId.value = ''
  // One-shot defaults reset on every open so a prior session's references
  // and menu state don't bleed into the new project.
  oneShotPrompt.value = ''
  oneShotAnimate.value = true
  oneShotAnimateTier.value = 'quick'
  oneShotScenesCount.value = 3
  oneShotReferences.value = []
  oneShotUploadError.value = ''
  oneShotAddMenuOpen.value = false
  oneShotAspectOpen.value = false
  oneShotAnimMenuOpen.value = false
  oneShotScenesOpen.value = false
  oneShotShowCharacters.value = false
  show.value = true
  loadNiches()
  loadBrandKits()
  loadCharacters()
  loadOneShotCatalogs()
}

// ── Credit estimate ───────────────────────────────────────
const creditBalance = ref(null)

onMounted(async () => {
  const res = await api.get('/me').catch(() => null)
  creditBalance.value = res?.data?.data?.credits?.balance ?? null
})

const creditEstimate = computed(() => {
  const SCRIPT = 2, BREAKDOWN = 1, TTS = 2, EXPORT = 5, STOCK = 1, AI_MED = 15
  const mode = globalVisualMode.value
  const visualPerScene = ['ai_images','ai_broll'].includes(mode) ? AI_MED : STOCK
  const fixed = SCRIPT + BREAKDOWN + EXPORT

  // Scene estimate based on source type and content length
  const rawContent = wizardSourceType.value === 'prompt' ? promptText.value
    : wizardSourceType.value === 'script' ? scriptText.value
    : wizardSourceType.value === 'product_description' ? productDescription.value
    : ''
  const words = rawContent.trim().split(/\s+/).filter(Boolean).length
  const sourceType = wizardSourceType.value
  let scenesMin, scenesMax
  if (sourceType === 'script') {
    scenesMin = Math.max(8, Math.round(words / 14))
    scenesMax = Math.max(10, Math.round(words / 10))
  } else if (sourceType === 'blank') {
    return null
  } else {
    scenesMin = words > 10 ? Math.max(6, Math.round(words / 15)) : 8
    scenesMax = words > 10 ? Math.max(8, Math.round(words / 11)) : 12
  }

  const min = fixed + scenesMin * (visualPerScene + TTS)
  const max = fixed + scenesMax * (visualPerScene + TTS)
  return { min, max, visualPerScene }
})

const canAffordEstimate = computed(() => {
  if (creditBalance.value === null || !creditEstimate.value) return true
  return creditBalance.value >= creditEstimate.value.min
})

function close() {
  if (wizardCreateState.value === 'loading') return
  show.value = false
}

function wizardNext() {
  if (wizardStep.value === 1 && !selectedNicheId.value && !customNicheSelected.value) return
  wizardStep.value = Math.min(3, wizardStep.value + 1)
}

function wizardBack() {
  // Blank-path users (step 3 with sourceType='blank') go back to the path
  // picker, not the niche / source-type steps they never saw.
  if (wizardStep.value === 3 && wizardSourceType.value === 'blank') {
    wizardSourceType.value = 'prompt'
    wizardStep.value = 0
    return
  }
  // One-shot users (step 4) go back to the path picker too.
  if (wizardStep.value === 4) {
    wizardSourceType.value = 'prompt'
    wizardStep.value = 0
    return
  }
  wizardStep.value = Math.max(0, wizardStep.value - 1)
}

// Step-0 handlers — picking a path.
function pickStartFromScratch() {
  wizardSourceType.value = 'blank'
  wizardStep.value = 3
}
function pickGenerateFromBrief() {
  // Reset back to prompt as the default within Generate so the source-type
  // step doesn't carry 'blank' selected from a previous open.
  if (['blank', 'one_shot_prompt'].includes(wizardSourceType.value)) wizardSourceType.value = 'prompt'
  wizardStep.value = 1
}
function pickOneShotPrompt() {
  wizardSourceType.value = 'one_shot_prompt'
  wizardStep.value = 4   // one-shot form is its own step so it never gets the niche/source UI
}

// One-shot prompt state.
const oneShotPrompt = ref('')
const oneShotAnimate = ref(true)
const oneShotAnimateTier = ref('quick')
// Number of scenes. 1 = instant demo, 3 = DTC ad (default), 5 = narrative,
// 8 = full Reel. Backend caps at 8 (parser quality + cost + concurrency).
const oneShotScenesCount = ref(3)
const ONE_SHOT_SCENE_OPTIONS = [
  { count: 1, label: '1 scene',  hint: 'fastest demo' },
  { count: 3, label: '3 scenes', hint: 'DTC ad shape' },
  { count: 5, label: '5 scenes', hint: 'narrative' },
  { count: 8, label: '8 scenes', hint: 'full Reel ~40s' },
]
const oneShotScenesOpen = ref(false)
// Unified references list — both uploaded images and selected characters
// live here. Backend flattens to source_image_asset_ids + character_ids.
// Each entry: { uid, kind: 'upload'|'character', asset_id?, character_id?,
// thumb?, label }. uid is local-only so we can remove by identity even when
// the same asset/character is added twice (we de-dupe before submit anyway).
const oneShotReferences = ref([])
const oneShotUploadingPhoto = ref(false)
const oneShotUploadError = ref('')

// Local UI flags for the composer's pop-overs / expandable sections.
const oneShotAddMenuOpen     = ref(false)
const oneShotAspectOpen      = ref(false)
const oneShotAnimMenuOpen    = ref(false)
const oneShotShowCharacters  = ref(false)

const availableCharacters = ref([])
const ONE_SHOT_ANIM_TIERS = [
  { key: 'quick',         label: 'Wan 2.5',       sub: 'Fast · cheap',      cost: 60,  render: '~30s' },
  { key: 'seedance_lite', label: 'Seedance Lite', sub: 'ByteDance · cheap', cost: 100, render: '~45s' },
  { key: 'balanced',      label: 'Hailuo 2.3',    sub: 'Best for most',     cost: 120, render: '~90s' },
  { key: 'seedance_pro',  label: 'Seedance Pro',  sub: 'ByteDance · sharp', cost: 200, render: '~2 min' },
  { key: 'premium',       label: 'Kling 2.1',     sub: 'Cinematic',         cost: 240, render: '~3 min' },
]

function animateTierLabel(key) {
  return (ONE_SHOT_ANIM_TIERS.find((m) => m.key === key) || ONE_SHOT_ANIM_TIERS[0]).label
}

async function loadOneShotCatalogs() {
  try {
    const chars = await api.get('/characters').catch(() => null)
    availableCharacters.value = chars?.data?.data?.characters ?? []
  } catch { /* keep empty */ }
}

function closeAddMenu() { oneShotAddMenuOpen.value = false }

function removeOneShotReference(uid) {
  oneShotReferences.value = oneShotReferences.value.filter((r) => r.uid !== uid)
  oneShotUploadError.value = ''
}

function oneShotHasCharacter(charId) {
  return oneShotReferences.value.some((r) => r.kind === 'character' && r.character_id === charId)
}

function toggleOneShotCharacter(c) {
  if (oneShotHasCharacter(c.id)) {
    oneShotReferences.value = oneShotReferences.value.filter(
      (r) => !(r.kind === 'character' && r.character_id === c.id),
    )
    return
  }
  if (oneShotReferences.value.length >= 4) {
    oneShotUploadError.value = 'Up to 4 references — remove one to add another.'
    return
  }
  oneShotReferences.value.push({
    uid: `c-${c.id}-${Date.now()}`,
    kind: 'character',
    character_id: c.id,
    thumb: c.reference_asset?.thumbnail_url ?? null,
    label: c.name,
  })
}

// Multi-file upload. Iterates files, queues them as upload references.
// Skips files >8 MB. Bumps the 4-reference cap as a soft limit.
async function onOneShotPhotoChange(event) {
  const files = Array.from(event.target?.files ?? [])
  if (!files.length) return
  oneShotUploadingPhoto.value = true
  oneShotUploadError.value = ''
  closeAddMenu()
  for (const file of files) {
    if (oneShotReferences.value.length >= 4) {
      oneShotUploadError.value = 'Reached the 4-reference cap — skipped remaining files.'
      break
    }
    if (file.size > 8 * 1024 * 1024) {
      oneShotUploadError.value = `${file.name} is over 8 MB — skipped.`
      continue
    }
    try {
      const fd = new FormData()
      fd.append('asset_file', file)
      fd.append('asset_type', 'image')
      fd.append('title', file.name.replace(/\.[^.]+$/, ''))
      const res = await api.post('/assets', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
      const asset = res.data?.data?.asset
      if (asset?.id) {
        oneShotReferences.value.push({
          uid: `u-${asset.id}-${Date.now()}`,
          kind: 'upload',
          asset_id: asset.id,
          thumb: asset.thumbnail_url || asset.storage_url || null,
          label: asset.title || file.name,
        })
      }
    } catch (e) {
      oneShotUploadError.value = e.response?.data?.error?.message ?? `Upload of ${file.name} failed.`
    }
  }
  oneShotUploadingPhoto.value = false
  event.target.value = ''   // allow re-picking the same file
}

// Submit gate: prompt is the only hard requirement now. References are
// optional hints to the model — empty is fine (text-to-image).
const canSubmitOneShot = computed(() => !!oneShotPrompt.value.trim())

async function submitOneShot() {
  if (!canSubmitOneShot.value || wizardCreateState.value === 'loading') return
  wizardCreateState.value = 'loading'
  wizardCreateError.value = ''
  try {
    const sourceAssetIds = oneShotReferences.value.filter((r) => r.kind === 'upload').map((r) => r.asset_id)
    const characterIds   = oneShotReferences.value.filter((r) => r.kind === 'character').map((r) => r.character_id)
    const payload = {
      prompt: oneShotPrompt.value.trim(),
      title: title.value || undefined,
      aspect_ratio: aspectRatio.value,
      animate: oneShotAnimate.value,
      animation_tier: oneShotAnimateTier.value,
      scenes_count: oneShotScenesCount.value,
      channel_id: channelId.value ? Number(channelId.value) : undefined,
      ...(sourceAssetIds.length ? { source_image_asset_ids: sourceAssetIds } : {}),
      ...(characterIds.length   ? { character_ids: characterIds }            : {}),
    }
    const res = await api.post('/projects/one-shot', payload)
    const projectId = res.data?.data?.project?.id
    if (projectId) {
      show.value = false
      router.push({
        name: 'generation-progress',
        params: { projectId },
        query: { animate: oneShotAnimate.value ? '1' : '0' },
      })
    }
  } catch (err) {
    wizardCreateState.value = 'error'
    wizardCreateError.value = err.response?.data?.error?.message ?? 'One-shot generation failed.'
  }
}

async function submitWizardProject() {
  wizardCreateState.value = 'loading'
  wizardCreateError.value = ''

  const sourceType = wizardSourceType.value
  const rawContent = buildSourceContentRaw(sourceType)
  const hasMedia = (sourceType === 'audio_upload' && audioFile.value)
    || (sourceType === 'video_upload' && videoFile.value)
    || (sourceType === 'images' && imageFiles.value.length > 0)

  if (sourceType !== 'blank' && !rawContent && !hasMedia) {
    wizardCreateState.value = 'error'
    wizardCreateError.value = 'Source content is required.'
    return
  }

  try {
    const resolvedSource = await resolveSourceContentRaw(sourceType)
    const res = await api.post('/projects', {
      source_type: sourceType,
      source_content_raw: resolvedSource,
      languages: languageSelections.value,
      platform_target: platformTarget.value,
      aspect_ratio: aspectRatio.value,
      ...(selectedNicheId.value ? { niche_id: selectedNicheId.value } : {}),
      ...(selectedCharacterId.value ? { character_id: Number(selectedCharacterId.value) } : {}),
      ...(channelId.value ? { channel_id: Number(channelId.value) } : {}),
      ...(brandKitId.value ? { brand_kit_id: Number(brandKitId.value) } : {}),
      ...(contentGoal.value ? { content_goal: contentGoal.value } : {}),
      ...(customNicheVoiceTone.value ? { tone: customNicheVoiceTone.value } : {}),
      ...(title.value ? { title: title.value } : {}),
      ...(durationTargetSeconds.value ? { duration_target_seconds: Number(durationTargetSeconds.value) } : {}),
      ...(aiBrollStyle.value === 'custom' && customVisualStyle.value.trim()
        ? { custom_visual_style: customVisualStyle.value.trim() }
        : {}),
      ...(sourceType === 'images' && imageVisualMode.value === 'upload' ? { source_image_asset_ids: sourceImageAssetIds.value } : {}),
      ...(sourceType === 'images' && imageVisualMode.value === 'ai'
        ? { visual_type: projectVisualTypeForMode('ai_images'), ai_broll_style: aiBrollStyle.value }
        : {}),
      ...(sourceType !== 'images' && sourceType !== 'blank' && globalVisualMode.value === 'ai_images'
        ? { visual_type: projectVisualTypeForMode('ai_images'), ai_broll_style: aiBrollStyle.value }
        : {}),
      ...(sourceType !== 'images' && sourceType !== 'blank' && globalVisualMode.value === 'stock_images'
        ? { visual_type: projectVisualTypeForMode('stock_images') }
        : {}),
      ...(sourceType !== 'images' && sourceType !== 'blank' && globalVisualMode.value === 'waveform'
        ? { visual_type: projectVisualTypeForMode('waveform') }
        : {}),
    })

    const projectId = res.data?.data?.project?.id
    show.value = false
    wizardCreateState.value = 'success'
    emit('created', { projectId, sourceType })

    if (projectId) {
      if (sourceType === 'blank') {
        router.push({ name: 'project-editor', params: { projectId } })
      } else {
        router.push({ name: 'generation-progress', params: { projectId } })
      }
    }
  } catch (err) {
    wizardCreateState.value = 'error'
    wizardCreateError.value = err.response?.data?.error?.message ?? 'Project creation failed.'
  }
}

defineExpose({ open })
</script>

<template>
  <div v-if="show" class="modal-overlay" @click.self="close">
    <div class="modal wizard-modal">

      <!-- Step indicator — hidden on the path picker (step 0) and on the
           blank single-step form (since they're one-screen flows). -->
      <div v-if="wizardStep >= 1 && wizardSourceType !== 'blank'" class="wizard-steps">
        <div :class="['wizard-step', wizardStep === 1 ? 'active' : wizardStep > 1 ? 'done' : '']">
          <div class="wizard-step-num">{{ wizardStep > 1 ? '✓' : '1' }}</div>
          <span>Pick Niche</span>
        </div>
        <div :class="['wizard-connector', wizardStep > 1 ? 'done' : '']"></div>
        <div :class="['wizard-step', wizardStep === 2 ? 'active' : wizardStep > 2 ? 'done' : '']">
          <div class="wizard-step-num">{{ wizardStep > 2 ? '✓' : '2' }}</div>
          <span>Source</span>
        </div>
        <div :class="['wizard-connector', wizardStep > 2 ? 'done' : '']"></div>
        <div :class="['wizard-step', wizardStep === 3 ? 'active' : '']">
          <div class="wizard-step-num">3</div>
          <span>Content</span>
        </div>
      </div>

      <!-- Step 0: Path Picker -->
      <div v-if="wizardStep === 0">
        <div class="modal-title">New Video</div>
        <div class="modal-subtitle">How do you want to start?</div>

        <div class="path-picker-grid path-picker-grid-3">
          <div class="path-card" role="button" tabindex="0"
               @click="pickStartFromScratch"
               @keydown.enter="pickStartFromScratch">
            <div class="path-card-icon">🎬</div>
            <div class="path-card-title">Start from Scratch</div>
            <div class="path-card-desc">Build every scene yourself. Pick a title and format, then drop straight into the editor.</div>
            <div class="path-card-tags">
              <span class="path-card-tag">Full control</span>
              <span class="path-card-tag">Manual scenes</span>
            </div>
          </div>

          <div class="path-card path-card-featured" role="button" tabindex="0"
               @click="pickGenerateFromBrief"
               @keydown.enter="pickGenerateFromBrief">
            <div class="path-card-badge">Most popular</div>
            <div class="path-card-icon">✦</div>
            <div class="path-card-title">Generate from a brief</div>
            <div class="path-card-desc">Tell us your topic and we'll write the script, break it into scenes, and pre-fill visuals.</div>
            <div class="path-card-tags">
              <span class="path-card-tag">Script + scenes</span>
              <span class="path-card-tag">Niche presets</span>
              <span class="path-card-tag">~90s setup</span>
            </div>
          </div>

          <div class="path-card" role="button" tabindex="0"
               @click="pickOneShotPrompt"
               @keydown.enter="pickOneShotPrompt">
            <div class="path-card-icon">⚡</div>
            <div class="path-card-title">One-shot from a prompt</div>
            <div class="path-card-desc">Type one line and get back a single scene with AI image, animation, voice, and music. ~90 seconds end-to-end.</div>
            <div class="path-card-tags">
              <span class="path-card-tag">Instant demo</span>
              <span class="path-card-tag">One scene</span>
              <span class="path-card-tag">Full pipeline</span>
            </div>
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="close">Cancel</button>
        </div>
      </div>

      <!-- Step 4: One-shot prompt — Claude-input-box style composer.
           One prompt box. References (uploads + characters) live as chips
           inside it. Settings collapse below. -->
      <div v-else-if="wizardStep === 4">
        <div class="section-title">⚡ One-shot</div>
        <div class="section-subtitle">Describe what you want. Add reference photos or characters if you have them.</div>

        <!-- Composer: textarea + chip strip + footer toolbar. Mirrors the
             Claude prompt-box pattern: one rounded container, chips at the
             top, prompt body, action row at the bottom. -->
        <div class="composer">
          <!-- Reference chips (only when any present) -->
          <div v-if="oneShotReferences.length" class="composer-chips">
            <div v-for="ref in oneShotReferences" :key="ref.uid" class="ref-chip" :title="ref.label">
              <img v-if="ref.thumb" :src="ref.thumb" :alt="ref.label" class="ref-chip-thumb" />
              <span v-else class="ref-chip-glyph">{{ ref.kind === 'character' ? '👤' : '📷' }}</span>
              <span class="ref-chip-label">{{ ref.label }}</span>
              <button type="button" class="ref-chip-remove" @click="removeOneShotReference(ref.uid)" :title="`Remove ${ref.label}`">×</button>
            </div>
          </div>

          <textarea
            v-model="oneShotPrompt"
            class="composer-textarea"
            rows="4"
            maxlength="1000"
            placeholder="e.g. a calm founder explaining her morning ritual in a sunlit kitchen, warm tones, slow camera push-in"
            @keydown.meta.enter="submitOneShot"
            @keydown.ctrl.enter="submitOneShot"
          ></textarea>

          <!-- Toolbar: + Add (popover), aspect ratio chip, animate model chip, char count -->
          <div class="composer-toolbar">
            <div class="composer-tools-left">
              <!-- + Add references — popover menu -->
              <div class="composer-add">
                <button type="button" class="composer-add-btn" @click="oneShotAddMenuOpen = !oneShotAddMenuOpen" :disabled="oneShotReferences.length >= 4" :title="oneShotReferences.length >= 4 ? 'Up to 4 references' : 'Add reference image or character'">+</button>
                <div v-if="oneShotAddMenuOpen" class="composer-add-menu">
                  <label class="composer-add-item">
                    <span>📷</span><span>Upload image{{ oneShotUploadingPhoto ? '…' : '' }}</span>
                    <input type="file" accept="image/*" multiple class="hidden-file-input" @change="onOneShotPhotoChange" :disabled="oneShotUploadingPhoto" />
                  </label>
                  <button v-if="availableCharacters.length" type="button" class="composer-add-item" @click="oneShotShowCharacters = !oneShotShowCharacters">
                    <span>👤</span><span>{{ oneShotShowCharacters ? 'Hide characters' : 'Pick character' }}</span>
                  </button>
                  <div v-else class="composer-add-item composer-add-item--empty">
                    <span>👤</span><span>No characters saved yet</span>
                  </div>
                </div>
              </div>

              <!-- Aspect ratio dropdown — compact -->
              <div class="composer-pill-wrap">
                <button type="button" class="composer-pill" @click="oneShotAspectOpen = !oneShotAspectOpen">
                  <span>📐 {{ aspectRatio }}</span><span class="composer-pill-caret">▾</span>
                </button>
                <div v-if="oneShotAspectOpen" class="composer-pill-menu">
                  <button v-for="r in ['9:16','1:1','4:5','16:9']" :key="r" type="button" :class="['composer-pill-option', aspectRatio === r ? 'selected' : '']" @click="aspectRatio = r; oneShotAspectOpen = false">{{ r }}</button>
                </div>
              </div>

              <!-- Scenes dropdown -->
              <div class="composer-pill-wrap">
                <button type="button" class="composer-pill" @click="oneShotScenesOpen = !oneShotScenesOpen">
                  <span>🎞 {{ oneShotScenesCount }} scene{{ oneShotScenesCount > 1 ? 's' : '' }}</span><span class="composer-pill-caret">▾</span>
                </button>
                <div v-if="oneShotScenesOpen" class="composer-pill-menu composer-pill-menu--wide">
                  <button v-for="o in ONE_SHOT_SCENE_OPTIONS" :key="o.count" type="button" :class="['composer-pill-option', oneShotScenesCount === o.count ? 'selected' : '']" @click="oneShotScenesCount = o.count; oneShotScenesOpen = false">
                    <span>{{ o.label }}</span><span class="composer-pill-option-sub">{{ o.hint }}</span>
                  </button>
                </div>
              </div>

              <!-- Animate dropdown — model + on/off in one control -->
              <div class="composer-pill-wrap">
                <button type="button" class="composer-pill" @click="oneShotAnimMenuOpen = !oneShotAnimMenuOpen">
                  <span>🎬 {{ oneShotAnimate ? animateTierLabel(oneShotAnimateTier) : 'No animation' }}</span><span class="composer-pill-caret">▾</span>
                </button>
                <div v-if="oneShotAnimMenuOpen" class="composer-pill-menu composer-pill-menu--wide">
                  <button type="button" :class="['composer-pill-option', !oneShotAnimate ? 'selected' : '']" @click="oneShotAnimate = false; oneShotAnimMenuOpen = false">
                    <span>No animation</span><span class="composer-pill-option-sub">image only</span>
                  </button>
                  <button v-for="m in ONE_SHOT_ANIM_TIERS" :key="m.key" type="button" :class="['composer-pill-option', oneShotAnimate && oneShotAnimateTier === m.key ? 'selected' : '']" @click="oneShotAnimate = true; oneShotAnimateTier = m.key; oneShotAnimMenuOpen = false">
                    <span>{{ m.label }}</span><span class="composer-pill-option-sub">{{ m.cost }} cr · {{ m.render }}</span>
                  </button>
                </div>
              </div>
            </div>

            <div class="composer-tools-right">
              <span class="composer-count">{{ oneShotPrompt.length }}/1000</span>
            </div>
          </div>
        </div>

        <!-- Inline upload error (sits below composer) -->
        <div v-if="oneShotUploadError" class="modal-error" style="margin-top:10px;">{{ oneShotUploadError }}</div>

        <!-- Character picker — only when user expanded it from the + menu.
             Multi-select; clicking a card toggles it in/out of refs. -->
        <div v-if="oneShotShowCharacters && availableCharacters.length" style="margin-top:14px;">
          <div class="micro-section-label">Pick characters as references</div>
          <div class="character-pick-grid">
            <div
              v-for="c in availableCharacters"
              :key="c.id"
              :class="['character-pick-card', oneShotHasCharacter(c.id) ? 'selected' : '', !c.reference_asset?.thumbnail_url ? 'no-thumb' : '']"
              @click="toggleOneShotCharacter(c)"
              :title="c.description || c.name"
            >
              <div class="character-pick-thumb">
                <img v-if="c.reference_asset?.thumbnail_url" :src="c.reference_asset.thumbnail_url" :alt="c.name" />
                <span v-else>👤</span>
                <div v-if="oneShotHasCharacter(c.id)" class="character-pick-check">✓</div>
              </div>
              <div class="character-pick-name">{{ c.name }}</div>
            </div>
          </div>
        </div>

        <div v-if="wizardCreateError" class="modal-error" style="margin-top:14px;">{{ wizardCreateError }}</div>

        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="wizardStep = 0">← Back</button>
          <button class="btn btn-primary" type="button" :disabled="!canSubmitOneShot || wizardCreateState === 'loading'" @click="submitOneShot">
            {{ wizardCreateState === 'loading' ? 'Generating…' : '⚡ Generate one-shot' }}
          </button>
        </div>
      </div>

      <!-- Step 1: Pick Niche -->
      <div v-else-if="wizardStep === 1">
        <div class="modal-title">Quick Start</div>
        <div class="modal-subtitle">Pick your content niche. WyvStudio pre-configures visuals, voice, captions, and music.</div>
        <div class="niche-grid">
          <div
            v-for="niche in niches"
            :key="niche.id"
            :class="['niche-card', selectedNicheId === niche.id ? 'selected' : '']"
            role="button"
            tabindex="0"
            @click="selectSeededNiche(niche.id)"
            @keydown.enter="selectSeededNiche(niche.id)"
          >
            <div class="niche-selected-check">✓</div>
            <span class="niche-emoji">{{ niche.icon_emoji }}</span>
            <div class="niche-name">{{ niche.name }}</div>
            <div class="niche-desc">{{ niche.description }}</div>
            <div class="niche-tags">
              <span v-for="tag in nicheTagsFor(niche)" :key="tag" class="niche-tag">{{ tag }}</span>
            </div>
          </div>
          <div
            :class="['niche-card custom-niche-card', customNicheSelected ? 'selected' : '']"
            role="button"
            tabindex="0"
            @click="selectCustomNiche"
            @keydown.enter="selectCustomNiche"
          >
            <div class="niche-selected-check">✓</div>
            <span class="niche-emoji">✨</span>
            <div class="niche-name">Other / Custom</div>
            <div class="niche-desc">Use your own niche and describe the style.</div>
            <div class="niche-tags">
              <span class="niche-tag">Your topic</span>
              <span class="niche-tag">Custom defaults</span>
            </div>
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="wizardStep = 0">← Back</button>
          <button class="btn btn-primary" type="button" :disabled="!selectedNicheId && !customNicheSelected" @click="wizardNext">Continue →</button>
        </div>
      </div>

      <!-- Step 2: Source Type -->
      <div v-if="wizardStep === 2">
        <div class="section-title">Choose your source</div>
        <div class="section-subtitle">How do you want to start this video?</div>
        <div v-if="selectedNiche" class="niche-preset-banner">
          <span>{{ selectedNiche.icon_emoji }}</span>
          <span>Loaded <strong>{{ selectedNiche.name }}</strong>
            <template v-if="selectedNiche.default_visual_style"> — {{ selectedNiche.default_visual_style }} visuals</template>
            <template v-if="selectedNiche.default_voice_tone">, {{ selectedNiche.default_voice_tone }} voice</template>
            <template v-if="selectedNiche.default_music_mood">, {{ selectedNiche.default_music_mood }} music</template>
          </span>
        </div>
        <div v-else-if="customNicheSelected" class="niche-preset-banner">
          <span>✨</span>
          <span>Loaded <strong>Custom niche</strong>
            <template v-if="customNicheSummary"> — {{ customNicheSummary }}</template>
            <template v-else> — describe it on the next step</template>
          </span>
        </div>
        <div class="source-type-grid">
          <div
            v-for="opt in sourceOptions"
            :key="opt.key"
            :class="['source-type-opt', wizardSourceType === opt.key ? 'selected' : '']"
            role="button"
            tabindex="0"
            @click="setWizardSourceType(opt.key)"
            @keydown.enter="setWizardSourceType(opt.key)"
          >
            <span class="source-type-ico">{{ opt.icon }}</span>
            <div class="source-type-name">{{ opt.label }}</div>
            <div class="source-type-hint">{{ opt.hint }}</div>
          </div>
        </div>
        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="wizardBack">← Back</button>
          <button class="btn btn-primary" type="button" @click="wizardNext">Continue →</button>
        </div>
      </div>

      <!-- Step 3: Content Entry -->
      <div v-if="wizardStep === 3">
        <div class="section-title">{{ wizardSourceType === 'blank' ? 'Set up your project' : 'Enter your content' }}</div>
        <div class="section-subtitle">{{ wizardSourceType === 'blank' ? "Pick your format and channel — you'll build scenes in the editor." : 'Almost done — fill in your content and confirm settings.' }}</div>

        <!-- Niche preset summary -->
        <div v-if="selectedNiche" class="niche-preset-summary">
          <div v-if="selectedNiche.default_visual_style" class="preset-pill">
            <div class="preset-pill-label">Visual</div>
            <div class="preset-pill-val">{{ selectedNiche.default_visual_style }}</div>
          </div>
          <div v-if="selectedNiche.default_voice_tone" class="preset-pill">
            <div class="preset-pill-label">Voice</div>
            <div class="preset-pill-val">{{ selectedNiche.default_voice_tone }}</div>
          </div>
          <div v-if="selectedNiche.default_caption_preset_name" class="preset-pill">
            <div class="preset-pill-label">Captions</div>
            <div class="preset-pill-val">{{ selectedNiche.default_caption_preset_name }}</div>
          </div>
          <div v-if="selectedNiche.default_music_mood" class="preset-pill">
            <div class="preset-pill-label">Music</div>
            <div class="preset-pill-val">{{ selectedNiche.default_music_mood }}</div>
          </div>
        </div>
        <div v-else-if="customNicheSelected" class="custom-niche-panel">
          <div class="custom-niche-header">
            <span>✨</span>
            <div>
              <div class="custom-niche-title">Custom niche</div>
              <div class="custom-niche-copy">Describe the channel lane and the defaults WyvStudio should lean toward.</div>
            </div>
          </div>
          <div class="settings-2col mt">
            <label class="input-label-wrap">
              <span class="input-label">Niche name</span>
              <input v-model="customNicheName" class="field-input" type="text" placeholder="e.g. Luxury real estate tips" />
            </label>
            <label class="input-label-wrap">
              <span class="input-label">Visual style</span>
              <input v-model="customNicheVisualStyle" class="field-input" type="text" placeholder="e.g. cinematic, clean, premium" />
            </label>
          </div>
          <div class="settings-2col mt">
            <label class="input-label-wrap">
              <span class="input-label">Voice tone</span>
              <input v-model="customNicheVoiceTone" class="field-input" type="text" placeholder="e.g. confident, warm, expert" />
            </label>
            <label class="input-label-wrap">
              <span class="input-label">Music mood</span>
              <input v-model="customNicheMusicMood" class="field-input" type="text" placeholder="e.g. calm luxury, upbeat, dark" />
            </label>
          </div>
          <label class="input-label-wrap mt">
            <span class="input-label">Describe your niche</span>
            <textarea v-model="customNicheContext" class="field-input textarea" rows="3" placeholder="e.g. Short practical advice for first-time investors buying luxury apartments in Dubai."></textarea>
          </label>
        </div>

        <!-- Recurring character (optional) — applies to every scene generated by this project. -->
        <div class="character-picker">
          <div class="character-picker-header">
            <span class="character-picker-label">Recurring character <span class="character-picker-hint">optional</span></span>
            <a href="/characters" target="_blank" rel="noopener" class="character-picker-manage">Manage in Characters →</a>
          </div>
          <div v-if="characters.length === 0" class="character-picker-empty">
            <span>No characters yet.</span>
            <a href="/characters" target="_blank" rel="noopener">Create one</a>
            <span>to have a recurring on-screen spokesperson, narrator, or host across every video you generate.</span>
          </div>
          <div v-else class="character-chip-row">
            <button
              type="button"
              :class="['character-chip', selectedCharacterId === '' ? 'selected' : '']"
              @click="selectedCharacterId = ''"
            >
              <span class="character-chip-none">∅</span>
              <span class="character-chip-name">No character</span>
            </button>
            <button
              v-for="c in characters"
              :key="c.id"
              type="button"
              :class="['character-chip', String(selectedCharacterId) === String(c.id) ? 'selected' : '']"
              @click="selectedCharacterId = c.id"
            >
              <img v-if="c.reference_asset?.thumbnail_url" :src="c.reference_asset.thumbnail_url" :alt="c.name" class="character-chip-thumb" />
              <span v-else class="character-chip-none">👤</span>
              <span class="character-chip-name">{{ c.name }}</span>
            </button>
          </div>
          <div v-if="selectedCharacter" class="character-picker-active">
            <strong>{{ selectedCharacter.name }}</strong>
            will appear as the recurring on-screen character in every scene this project generates.
          </div>
        </div>

        <!-- Content input by source type -->
        <div v-if="wizardSourceType === 'blank'" class="blank-canvas-notice">
          <div class="blank-canvas-icon">✏️</div>
          <div class="blank-canvas-body">
            <div class="blank-canvas-title">Blank canvas</div>
            <div class="blank-canvas-text">Add scenes in the editor, write your script, pick visuals from your library or generate with AI, and record voice per scene.</div>
          </div>
        </div>
        <div v-else-if="wizardSourceType === 'prompt'" class="input-group">
          <label class="input-label">What's your video about?</label>
          <textarea v-model="promptText" class="field-input textarea" rows="5" placeholder="e.g. The mysterious disappearance of the Beaumont family in 1966…"></textarea>
        </div>
        <div v-else-if="wizardSourceType === 'script'" class="input-group">
          <label class="input-label">Paste your script</label>
          <textarea v-model="scriptText" class="field-input textarea" rows="6" placeholder="Each paragraph will become a scene…"></textarea>
        </div>
        <div v-else-if="wizardSourceType === 'url'" class="input-group">
          <label class="input-label">Article or page URL</label>
          <input v-model="urlText" type="url" class="field-input" placeholder="https://…" />
          <div class="hint-box">AI will extract the main body text and structure it into scenes matching your niche template.</div>
        </div>
        <div v-else-if="wizardSourceType === 'images'" class="input-group">
          <div class="image-mode-toggle">
            <button :class="['image-mode-btn', imageVisualMode === 'upload' ? 'active' : '']" type="button" @click="imageVisualMode = 'upload'">Upload reference images</button>
            <button :class="['image-mode-btn', imageVisualMode === 'ai' ? 'active' : '']" type="button" @click="imageVisualMode = 'ai'">Generate AI B-roll</button>
          </div>

          <template v-if="imageVisualMode === 'upload'">
            <div class="image-ai-hint">
              <span>✦</span>
              <span><strong>Reference images</strong> — AI analyses your photos to match the look, character, and aesthetic when generating visuals for each scene. Upload 1–15 images.</span>
            </div>
            <div v-if="imagePreviewItems.length > 0" class="image-preview-grid">
              <div v-for="item in imagePreviewItems" :key="item.key" class="image-preview-thumb">
                <img :src="item.url" :alt="item.name" />
                <div class="image-preview-name">{{ item.name }}</div>
              </div>
              <label v-if="imageFiles.length < 15" class="image-preview-add">
                +
                <input class="hidden-file-input" type="file" accept="image/*" multiple @change="appendImageFiles(selectedFiles($event))" />
              </label>
            </div>
            <label class="image-upload-zone">
              <div class="image-upload-zone-ico">🖼️</div>
              <div class="image-upload-zone-title">Drop images here or click to browse</div>
              <div class="image-upload-zone-hint">JPG, PNG, WEBP · max 10MB each · up to 15 images</div>
              <input class="hidden-file-input" type="file" accept="image/*" multiple @change="setImageFiles(selectedFiles($event))" />
            </label>
          </template>

          <template v-else>
            <div class="image-ai-hint">
              <span>✦</span>
              <span><strong>AI B-roll</strong> generates a new image for every scene in the video. Pick a visual style, then describe what the short video should be about — AI writes the script and renders the scenes from there. If you've picked a Recurring Character above, they'll appear in every generated scene.</span>
            </div>
            <div class="input-label" style="margin-bottom:8px;">Select the B-roll style</div>
            <div class="ai-broll-grid">
              <button
                v-for="style in aiBrollStyleOptions"
                :key="style.key"
                :class="['ai-broll-card', aiBrollStyle === style.key ? 'selected' : '']"
                :style="{ '--style-tone': style.tone }"
                type="button"
                @click="aiBrollStyle = style.key"
              >
                <span class="ai-broll-art"></span>
                <span class="ai-broll-label">{{ style.label }}</span>
                <span class="ai-broll-hint">{{ style.hint }}</span>
              </button>
            </div>
            <div v-if="aiBrollStyle === 'custom'" class="custom-style-panel">
              <label class="input-label-wrap">
                <span class="input-label">Custom visual style</span>
                <textarea
                  v-model="customVisualStyle"
                  class="field-input textarea"
                  rows="2"
                  maxlength="500"
                  placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                ></textarea>
                <div class="hint-box">This text is appended to every scene's image prompt in place of a preset descriptor. Be concrete — name the director, film stock, mood, color grade.</div>
              </label>
            </div>
          </template>

          <label class="input-label-wrap">
            <span class="input-label">{{ imageVisualMode === 'ai' ? 'What should the video be about?' : 'What is the video about?' }}</span>
            <textarea v-model="imageContext" class="field-input textarea" rows="3" :placeholder="imageVisualMode === 'ai' ? 'e.g. 7 strange facts about abandoned castles in Europe…' : 'e.g. A productivity guide for remote workers…'"></textarea>
          </label>
        </div>
        <div v-else-if="wizardSourceType === 'csv_topic'" class="input-group">
          <label class="input-label">CSV Topics</label>
          <textarea v-model="csvText" class="field-input textarea" rows="5" placeholder="topic,angle,hook"></textarea>
        </div>
        <div v-else-if="wizardSourceType === 'product_description'" class="input-group">
          <div class="form-grid">
            <label class="input-label-wrap"><span class="input-label">Product name</span><input v-model="productName" class="field-input" type="text" /></label>
            <label class="input-label-wrap"><span class="input-label">Product URL</span><input v-model="productUrl" class="field-input" type="url" /></label>
          </div>
          <label class="input-label-wrap mt"><span class="input-label">Description</span><textarea v-model="productDescription" class="field-input textarea" rows="3"></textarea></label>
          <label class="input-label-wrap mt"><span class="input-label">Target audience</span><input v-model="targetAudience" class="field-input" type="text" /></label>
        </div>
        <div v-else-if="wizardSourceType === 'audio_upload'" class="input-group">
          <label class="input-label">Upload audio file</label>
          <label class="upload-zone upload-zone-input">
            <span>{{ audioFile ? audioFile.name : '🎙️  Drop your audio file here — MP3, WAV, M4A · max 500MB' }}</span>
            <input class="hidden-file-input" type="file" accept="audio/*" @change="audioFile = selectedFile($event)" />
          </label>
        </div>
        <div v-else-if="wizardSourceType === 'video_upload'" class="input-group">
          <label class="input-label">Upload video file</label>
          <label class="upload-zone upload-zone-input">
            <span>{{ videoFile ? videoFile.name : '🎬  Drop your video file here — MP4, MOV · max 2GB' }}</span>
            <input class="hidden-file-input" type="file" accept="video/*" @change="videoFile = selectedFile($event)" />
          </label>
        </div>

        <!-- Visual type picker — shown for all text/media source types except images (which has its own) -->
        <div v-if="wizardSourceType && wizardSourceType !== 'images' && wizardSourceType !== 'blank'" class="input-group mt">
          <div class="input-label" style="margin-bottom:8px;">Visuals</div>
          <div class="wizard-visual-tabs">
            <button
              v-for="visual in visualTypeOptions"
              :key="visual.key"
              :class="['wizard-visual-tab', globalVisualMode === visual.key ? 'active' : '']"
              type="button"
              @click="globalVisualMode = visual.key"
            >
              {{ visual.label }}
            </button>
          </div>
          <div class="wizard-visual-hint">
            {{ visualTypeOptions.find((item) => item.key === globalVisualMode)?.hint }}
          </div>
          <div v-if="globalVisualMode === 'ai_images'">
            <div class="image-ai-hint" style="margin-top:10px;">
              <span>✦</span>
              <span>AI generates a custom image for every scene. Pick the visual style below.</span>
            </div>
            <div class="ai-broll-grid" style="margin-top:10px;">
              <button
                v-for="style in aiBrollStyleOptions"
                :key="style.key"
                :class="['ai-broll-card', aiBrollStyle === style.key ? 'selected' : '']"
                :style="{ '--style-tone': style.tone }"
                type="button"
                @click="aiBrollStyle = style.key"
              >
                <span class="ai-broll-art"></span>
                <span class="ai-broll-label">{{ style.label }}</span>
                <span class="ai-broll-hint">{{ style.hint }}</span>
              </button>
            </div>
            <div v-if="aiBrollStyle === 'custom'" class="custom-style-panel">
              <label class="input-label-wrap">
                <span class="input-label">Custom visual style</span>
                <textarea
                  v-model="customVisualStyle"
                  class="field-input textarea"
                  rows="2"
                  maxlength="500"
                  placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                ></textarea>
                <div class="hint-box">This text is appended to every scene's image prompt in place of a preset descriptor. Be concrete — name the director, film stock, mood, color grade.</div>
              </label>
            </div>
          </div>
          <div v-else-if="globalVisualMode === 'waveform'" class="image-ai-hint" style="margin-top:10px;">
            <span>🌊</span>
            <span>The project will start as an audiogram. The bar style, color, and background can be refined in the editor.</span>
          </div>
          <div v-else-if="globalVisualMode === 'stock_images'" class="image-ai-hint" style="margin-top:10px;">
            <span>🖼️</span>
            <span>WyvStudio will source still-image visuals scene by scene instead of video clips.</span>
          </div>
        </div>

        <!-- Settings -->
        <div class="settings-2col mt">
          <label class="input-label-wrap">
            <span class="input-label">Channel</span>
            <select v-model="channelId" class="field-input">
              <option value="">No channel</option>
              <option v-for="ch in channels" :key="ch.id" :value="String(ch.id)">{{ ch.name }}</option>
            </select>
          </label>
          <label class="input-label-wrap">
            <span class="input-label">Language</span>
            <select v-model="languageSelections[0]" class="field-input">
              <option value="en">English (US)</option>
              <option value="es">Spanish</option>
              <option value="fr">French</option>
            </select>
          </label>
        </div>

        <div class="mt">
          <div class="input-label" style="margin-bottom:8px;">Format</div>
          <div class="format-chips">
            <div :class="['format-chip', aspectRatio === '9:16' ? 'active' : '']" @click="aspectRatio = '9:16'">9:16</div>
            <div :class="['format-chip', aspectRatio === '1:1'  ? 'active' : '']" @click="aspectRatio = '1:1'">1:1</div>
            <div :class="['format-chip', aspectRatio === '16:9' ? 'active' : '']" @click="aspectRatio = '16:9'">16:9</div>
          </div>
        </div>

        <div class="mt">
          <div class="input-label" style="margin-bottom:8px;">Target length</div>
          <div class="format-chips">
            <div
              v-for="option in durationOptions"
              :key="option.value"
              :class="['format-chip', durationTargetSeconds === option.value ? 'active' : '']"
              @click="durationTargetSeconds = option.value"
            >
              {{ option.label }}
            </div>
          </div>
        </div>

        <label class="input-label-wrap mt">
          <span class="input-label">Title <span style="opacity:.5;font-weight:400;">(optional)</span></span>
          <input v-model="title" class="field-input" type="text" />
        </label>

        <!-- Credit estimate -->
        <div v-if="creditEstimate" :class="['credit-estimate', !canAffordEstimate ? 'credit-estimate-warn' : '']">
          <span class="credit-est-label">Estimated cost</span>
          <span class="credit-est-range">{{ creditEstimate.min }}–{{ creditEstimate.max }} credits</span>
          <span v-if="creditBalance !== null" class="credit-est-balance">
            · Balance: <strong>{{ creditBalance.toLocaleString() }}</strong>
            <span v-if="!canAffordEstimate" style="color:#f87171"> — not enough</span>
          </span>
        </div>

        <div v-if="wizardCreateError" class="modal-error mt">{{ wizardCreateError }}</div>

        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="wizardBack">← Back</button>
          <button
            v-if="!canAffordEstimate && wizardSourceType !== 'blank'"
            class="btn btn-primary"
            type="button"
            @click="router.push({ name: 'settings', query: { section: 'billing' } }); close()"
          >Top up credits →</button>
          <button
            v-else
            class="btn btn-primary"
            type="button"
            :disabled="wizardCreateState === 'loading'"
            @click="submitWizardProject"
          >
            {{ wizardCreateState === 'loading'
              ? (wizardSourceType === 'blank' ? 'Creating…' : '✦ Generating…')
              : (wizardSourceType === 'blank' ? 'Create Project →' : '✦ Generate Video') }}
          </button>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>
.modal-overlay { position: fixed; inset: 0; z-index: 180; background: rgba(0,0,0,0.68); display: flex; align-items: center; justify-content: center; padding: 16px; }
.modal { width: min(680px,calc(100vw - 32px)); max-height: 86vh; overflow-y: auto; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 28px; box-shadow: 0 30px 80px rgba(0,0,0,0.5); }
.wizard-modal { width: min(860px,calc(100vw - 32px)); }
.modal-title { font-size: 20px; font-weight: 700; color: var(--color-text-primary); }
.modal-subtitle { margin-top: 4px; margin-bottom: 22px; font-size: 13px; color: var(--color-text-muted); }
.modal-actions { margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--color-border); display: flex; justify-content: flex-end; gap: 10px; }
.modal-error { padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(248,113,113,0.25); color: #f87171; font-size: 12px; background: rgba(248,113,113,0.1); }
.credit-estimate { display: flex; align-items: center; gap: 8px; margin-top: 14px; padding: 9px 12px; border-radius: 7px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); font-size: 12px; flex-wrap: wrap; }
.credit-estimate-warn { border-color: rgba(248,113,113,.25); background: rgba(248,113,113,.06); }
.credit-est-label { color: var(--color-text-muted); }
.credit-est-range { font-weight: 600; color: var(--color-text-primary); }
.credit-est-balance { color: var(--color-text-muted); }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; }
.btn-primary { background: var(--color-accent); color: #fff; }
.btn-primary:disabled { opacity: 0.6; cursor: default; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-ghost:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }

.wizard-steps { display: flex; align-items: center; gap: 0; margin-bottom: 28px; }
.wizard-step { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--color-text-muted); }
.wizard-step-num { width: 24px; height: 24px; border-radius: 50%; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-muted); font-size: 11px; font-weight: 700; font-family: "Space Mono", monospace; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.2s; }
.wizard-step.active .wizard-step-num { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.wizard-step.active { color: var(--color-text-primary); }
.wizard-step.done .wizard-step-num { background: rgba(52,211,153,0.15); border-color: rgba(52,211,153,0.4); color: #34d399; }
.wizard-connector { width: 32px; height: 1px; background: var(--color-border); margin: 0 4px; flex-shrink: 0; }
.wizard-connector.done { background: rgba(52,211,153,0.35); }

.section-title { font-size: 18px; font-weight: 600; color: var(--color-text-primary); }
.section-subtitle { margin-top: 4px; margin-bottom: 20px; font-size: 13px; color: var(--color-text-muted); }

/* Step 0 — path picker. Two big cards, one orange-accented for the
   recommended Generate path. */
.path-picker-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin: 20px 0 8px; }
.path-picker-grid-3 { grid-template-columns: repeat(3, 1fr); }
@media(max-width: 900px) { .path-picker-grid-3 { grid-template-columns: repeat(2, 1fr); } }
.path-card { position: relative; display: flex; flex-direction: column; padding: 26px 22px; border-radius: 12px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; transition: border-color 0.18s, background 0.18s, transform 0.18s; min-height: 220px; }
.path-card:hover { border-color: rgba(255,107,53,0.45); transform: translateY(-2px); }
.path-card-featured { border-color: rgba(255,107,53,0.35); background: linear-gradient(180deg, rgba(255,107,53,0.06), transparent 60%); }
.path-card-featured:hover { border-color: rgba(255,107,53,0.7); }
.path-card-badge { position: absolute; top: 12px; right: 12px; font-size: 10px; font-weight: 600; padding: 3px 9px; border-radius: 999px; background: var(--color-accent); color: #fff; text-transform: uppercase; letter-spacing: 0.4px; }
.path-card-icon { font-size: 30px; line-height: 1; margin-bottom: 14px; }
.path-card-title { font-size: 18px; font-weight: 700; color: var(--color-text); margin-bottom: 8px; letter-spacing: -0.3px; }
.path-card-desc { font-size: 13px; color: var(--color-text-muted); line-height: 1.6; flex: 1; }
.path-card-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 16px; }
.path-card-tag { font-size: 10.5px; color: var(--color-text-muted); background: rgba(255,255,255,0.04); border: 1px solid var(--color-border); padding: 3px 9px; border-radius: 999px; font-family: "Space Mono", monospace; letter-spacing: 0.3px; }
@media(max-width: 640px) { .path-picker-grid { grid-template-columns: 1fr; } .path-card { min-height: auto; } }

.niche-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 8px; }
.niche-card { position: relative; display: flex; flex-direction: column; align-items: flex-start; padding: 16px 14px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; text-align: left; transition: border-color 0.2s, background 0.2s, transform 0.2s; overflow: hidden; }
.niche-card::before { content: ""; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,107,53,0.08), transparent 70%); opacity: 0; transition: opacity 0.2s; }
.niche-card:hover { border-color: rgba(255,107,53,0.4); transform: translateY(-1px); }
.niche-card:hover::before { opacity: 1; }
.niche-card.selected { border-color: var(--color-accent); background: rgba(255,107,53,0.08); }
.custom-niche-card { border-style: dashed; }
.niche-selected-check { display: none; position: absolute; top: 10px; right: 10px; width: 18px; height: 18px; border-radius: 50%; background: var(--color-accent); color: #fff; font-size: 10px; font-weight: 700; align-items: center; justify-content: center; z-index: 1; }
.niche-card.selected .niche-selected-check { display: flex; }
.niche-emoji { position: relative; z-index: 1; font-size: 26px; line-height: 1; margin-bottom: 10px; }
.niche-name { position: relative; z-index: 1; font-size: 13px; font-weight: 600; color: var(--color-text-primary); line-height: 1.2; margin-bottom: 3px; }
.niche-desc { position: relative; z-index: 1; font-size: 11px; color: var(--color-text-muted); line-height: 1.45; }
.niche-tags { position: relative; z-index: 1; display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
.niche-tag { padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 500; background: var(--color-bg-card); border: 1px solid var(--color-border); color: var(--color-text-muted); }

.niche-preset-banner { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 8px; background: rgba(255,107,53,0.06); border: 1px solid rgba(255,107,53,0.2); margin-bottom: 18px; font-size: 12px; color: var(--color-text-secondary); }
.niche-preset-banner strong { color: var(--color-text-primary); }

/* ── Recurring character picker (Step 3) ──────────────────────────────── */
.character-picker { margin-bottom: 18px; padding: 14px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); }
.character-picker-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.character-picker-label { font-size: 12px; font-weight: 600; color: var(--color-text-primary); }
.character-picker-hint { font-size: 10px; font-weight: 500; color: var(--color-text-muted); margin-left: 6px; font-family: "Space Mono", monospace; letter-spacing: 0.05em; }
.character-picker-manage { font-size: 11px; color: var(--color-accent); text-decoration: none; }
.character-picker-manage:hover { text-decoration: underline; }
.character-picker-empty { font-size: 12px; color: var(--color-text-muted); line-height: 1.55; }
.character-picker-empty a { color: var(--color-accent); text-decoration: none; margin: 0 4px; font-weight: 600; }
.character-picker-empty a:hover { text-decoration: underline; }
.character-chip-row { display: flex; flex-wrap: wrap; gap: 8px; }
.character-chip { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px 6px 6px; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-bg-card); cursor: pointer; transition: 0.15s ease; font-family: inherit; }
.character-chip:hover { border-color: rgba(255,107,53,0.35); }
.character-chip.selected { border-color: var(--color-accent); background: rgba(255,107,53,0.1); }
.character-chip-thumb { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.character-chip-none { width: 28px; height: 28px; border-radius: 50%; background: var(--color-bg-elevated); display: flex; align-items: center; justify-content: center; font-size: 13px; color: var(--color-text-muted); flex-shrink: 0; }
.character-chip-name { font-size: 12px; font-weight: 500; color: var(--color-text-primary); }
.character-picker-active { margin-top: 10px; padding: 8px 12px; border-radius: 6px; background: rgba(255,107,53,0.06); border: 1px solid rgba(255,107,53,0.2); font-size: 11px; color: var(--color-text-secondary); line-height: 1.55; }
.character-picker-active strong { color: var(--color-accent); }

.source-type-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 4px; }
.source-type-opt { padding: 14px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; text-align: center; transition: 0.15s ease; }
.source-type-opt:hover { border-color: rgba(255,107,53,0.35); }
.source-type-opt.selected { border-color: var(--color-accent); background: rgba(255,107,53,0.08); }
.source-type-ico { font-size: 22px; margin-bottom: 6px; display: block; }
.source-type-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); }
.source-type-hint { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }

.niche-preset-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px; }
.preset-pill { padding: 8px 10px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); min-width: 0; text-align: center; }
.preset-pill-label { font-size: 10px; color: var(--color-text-muted); margin-bottom: 2px; }
.preset-pill-val { font-size: 11px; font-weight: 500; color: var(--color-text-primary); text-transform: capitalize; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.custom-niche-panel { padding: 14px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); margin-bottom: 18px; }
.custom-niche-header { display: flex; gap: 10px; align-items: flex-start; }
.custom-niche-title { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.custom-niche-copy { margin-top: 2px; font-size: 12px; color: var(--color-text-muted); line-height: 1.5; }

.blank-canvas-notice { display: flex; align-items: flex-start; gap: 14px; padding: 18px 16px; border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); margin-bottom: 4px; }
.blank-canvas-icon { font-size: 24px; flex-shrink: 0; margin-top: 1px; }
.blank-canvas-title { font-size: 14px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 4px; }
.blank-canvas-text { font-size: 12px; color: var(--color-text-muted); line-height: 1.55; }

.settings-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field-input { width: 100%; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); padding: 9px 12px; font-size: 13px; }
.textarea { min-height: 90px; resize: vertical; }
.input-group { margin-top: 16px; }
.input-label { font-size: 12px; color: var(--color-text-secondary); margin-bottom: 6px; display: block; }
.input-label-wrap { display: grid; gap: 6px; }
.hint-box { margin-top: 8px; padding: 9px 10px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-muted); font-size: 12px; line-height: 1.5; }
.mt { margin-top: 14px; }

.format-chips { display: flex; gap: 8px; }
.format-chip { padding: 6px 14px; border-radius: 6px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-muted); font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.15s; }
.format-chip:hover { border-color: rgba(255,107,53,0.35); color: var(--color-text-secondary); }
.format-chip.active { border-color: var(--color-accent); background: rgba(255,107,53,0.1); color: var(--color-accent); }

/* Toggle row used in step-4 one-shot form (animate switch). Label left, pill right. */
.toggle-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 14px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; }
.toggle-row > div:first-child { flex: 1; min-width: 0; }
.label-main { font-size: 13px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 3px; }
.label-hint { font-size: 11.5px; color: var(--color-text-muted); line-height: 1.5; }
.toggle { flex-shrink: 0; width: 38px; height: 22px; border-radius: 999px; background: var(--color-bg-card); border: 1px solid var(--color-border); position: relative; transition: background 0.18s, border-color 0.18s; }
.toggle::after { content: ""; position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; border-radius: 50%; background: var(--color-text-muted); transition: transform 0.18s, background 0.18s; }
.toggle.on { background: rgba(255,107,53,0.25); border-color: var(--color-accent); }
.toggle.on::after { transform: translateX(16px); background: var(--color-accent); }

.upload-zone { border: 1px dashed var(--color-border); border-radius: 8px; padding: 20px 16px; color: var(--color-text-muted); font-size: 13px; text-align: center; background: var(--color-bg-card); }
.upload-zone-input { display: block; cursor: pointer; transition: 0.15s ease; }
.upload-zone-input:hover { border-color: rgba(255,107,53,0.35); color: var(--color-text-secondary); }
.hidden-file-input { display: none; }

/* Model picker grid — image-gen + animation tiers in one-shot. */
.model-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
.model-card { padding: 11px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; transition: 0.15s; }
.model-card:hover:not(.disabled) { border-color: rgba(255,107,53,0.35); transform: translateY(-1px); }
.model-card.selected { border-color: var(--color-accent); background: rgba(255,107,53,0.08); }
.model-card.disabled { opacity: 0.45; cursor: not-allowed; }
.model-card-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 2px; }
.model-card-sub { font-size: 10.5px; color: var(--color-text-muted); line-height: 1.4; min-height: 28px; }
.model-card-foot { display: flex; justify-content: space-between; margin-top: 6px; font-size: 10px; font-family: "Space Mono", monospace; }
.model-card-cost { color: var(--color-accent); font-weight: 600; }
.model-card-render { color: var(--color-text-muted); }

/* ── Claude-style composer for one-shot step ───────────────────────
   Single rounded container with: optional reference-chip strip,
   textarea body, and footer toolbar with + (add refs), aspect, animate,
   submit. Tries to feel like a chat input — one thing to focus on. */
.composer { border: 1px solid var(--color-border); border-radius: 14px; background: var(--color-bg-elevated); padding: 10px 12px 8px; transition: border-color 0.18s; }
.composer:focus-within { border-color: rgba(255,107,53,0.45); }

.composer-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.ref-chip { display: inline-flex; align-items: center; gap: 6px; padding: 3px 4px 3px 4px; border-radius: 999px; border: 1px solid var(--color-border); background: var(--color-bg-card); font-size: 11.5px; max-width: 180px; }
.ref-chip-thumb { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.ref-chip-glyph { width: 20px; height: 20px; border-radius: 50%; background: var(--color-bg-elevated); display: flex; align-items: center; justify-content: center; font-size: 11px; flex-shrink: 0; }
.ref-chip-label { color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ref-chip-remove { background: none; border: none; color: var(--color-text-muted); cursor: pointer; padding: 0 4px; font-size: 14px; line-height: 1; }
.ref-chip-remove:hover { color: var(--color-text-primary); }

.composer-textarea { width: 100%; border: none; background: transparent; color: var(--color-text-primary); font-size: 14px; line-height: 1.5; resize: none; outline: none; padding: 4px 2px; font-family: inherit; }
.composer-textarea::placeholder { color: var(--color-text-muted); }

.composer-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 6px; padding-top: 6px; border-top: 1px solid rgba(255,255,255,0.04); }
.composer-tools-left { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.composer-tools-right { display: flex; align-items: center; gap: 8px; }
.composer-count { font-size: 11px; color: var(--color-text-muted); font-family: "Space Mono", monospace; }

.composer-add { position: relative; }
.composer-add-btn { width: 28px; height: 28px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary); font-size: 16px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.15s; }
.composer-add-btn:hover:not(:disabled) { border-color: rgba(255,107,53,0.4); }
.composer-add-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.composer-add-menu { position: absolute; bottom: calc(100% + 6px); left: 0; min-width: 180px; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 8px; padding: 4px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); z-index: 10; }
.composer-add-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border: none; background: transparent; color: var(--color-text-primary); font-size: 12.5px; cursor: pointer; border-radius: 6px; width: 100%; text-align: left; font-family: inherit; }
.composer-add-item:hover { background: var(--color-bg-elevated); }
.composer-add-item--empty { color: var(--color-text-muted); cursor: default; }
.composer-add-item--empty:hover { background: transparent; }

.composer-pill-wrap { position: relative; }
.composer-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-secondary); font-size: 11.5px; cursor: pointer; transition: 0.15s; font-family: inherit; }
.composer-pill:hover { border-color: rgba(255,107,53,0.4); color: var(--color-text-primary); }
.composer-pill-caret { font-size: 9px; opacity: 0.6; }
.composer-pill-menu { position: absolute; bottom: calc(100% + 6px); left: 0; min-width: 140px; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 8px; padding: 4px; box-shadow: 0 8px 24px rgba(0,0,0,0.4); z-index: 10; }
.composer-pill-menu--wide { min-width: 220px; }
.composer-pill-option { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 10px; border: none; background: transparent; color: var(--color-text-primary); font-size: 12.5px; cursor: pointer; border-radius: 6px; width: 100%; text-align: left; font-family: inherit; }
.composer-pill-option:hover { background: var(--color-bg-elevated); }
.composer-pill-option.selected { background: rgba(255,107,53,0.08); color: var(--color-accent); }
.composer-pill-option-sub { font-size: 11px; color: var(--color-text-muted); font-family: "Space Mono", monospace; }

.composer-submit { width: 30px; height: 30px; border-radius: 8px; border: none; background: var(--color-accent); color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.15s; }
.composer-submit:hover:not(:disabled) { transform: translateY(-1px); }
.composer-submit:disabled { opacity: 0.35; cursor: not-allowed; background: var(--color-bg-card); color: var(--color-text-muted); }

.micro-section-label { font-size: 11px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 8px; font-family: "Space Mono", monospace; }

/* Character thumbnail picker — one-shot character source mode. */
.character-pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; }
.character-pick-card { cursor: pointer; text-align: center; transition: transform 0.15s; }
.character-pick-card:hover { transform: translateY(-2px); }
.character-pick-thumb { position: relative; aspect-ratio: 1/1; border-radius: 8px; overflow: hidden; border: 2px solid var(--color-border); background: var(--color-bg-elevated); display: flex; align-items: center; justify-content: center; transition: border-color 0.15s; font-size: 28px; color: var(--color-text-muted); }
.character-pick-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.character-pick-card.selected .character-pick-thumb { border-color: var(--color-accent); box-shadow: 0 0 0 3px rgba(255,107,53,0.18); }
.character-pick-card.no-thumb .character-pick-thumb { background: var(--color-bg-card); }
.character-pick-check { position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; background: var(--color-accent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
.character-pick-name { margin-top: 6px; font-size: 11.5px; color: var(--color-text-primary); line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.image-mode-toggle { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 12px; }
.image-mode-btn { padding: 9px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); font-size: 12px; font-weight: 600; cursor: pointer; transition: 0.15s; }
.image-mode-btn:hover { border-color: rgba(255,107,53,0.35); }
.image-mode-btn.active { border-color: var(--color-accent); background: rgba(255,107,53,0.1); color: var(--color-accent); }
.image-ai-hint { display: flex; gap: 10px; padding: 10px 12px; margin-bottom: 12px; border-radius: 8px; border: 1px solid rgba(255,107,53,0.2); background: rgba(255,107,53,0.08); color: var(--color-text-secondary); font-size: 12px; line-height: 1.5; }
.image-ai-hint strong { color: var(--color-text-primary); }

.ai-broll-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
.custom-style-panel { padding: 12px; border-radius: 8px; background: rgba(255,107,53,0.04); border: 1px solid rgba(255,107,53,0.2); margin: -6px 0 14px; }
.ai-broll-card { min-height: 112px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; text-align: left; padding: 0; overflow: hidden; transition: 0.15s; }
.ai-broll-card:hover { border-color: rgba(255,107,53,0.35); transform: translateY(-1px); }
.ai-broll-card.selected { border-color: var(--color-accent); box-shadow: inset 0 0 0 1px rgba(255,107,53,0.2); }
.ai-broll-art { display: block; height: 58px; background: radial-gradient(circle at 30% 20%, var(--style-tone), transparent 34%), linear-gradient(135deg, var(--style-tone), rgba(255,255,255,0.05)); border-bottom: 1px solid var(--color-border); }
.ai-broll-label { display: block; padding: 8px 8px 2px; color: var(--color-text-primary); font-size: 12px; font-weight: 700; }
.ai-broll-hint { display: block; padding: 0 8px 8px; color: var(--color-text-muted); font-size: 10px; line-height: 1.3; }

.wizard-visual-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.wizard-visual-tab {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 8px 12px;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: rgba(255,255,255,0.02);
  color: var(--color-text-secondary);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: 0.2s ease;
}
.wizard-visual-tab:hover {
  border-color: rgba(255,107,53,0.35);
  color: var(--color-text-primary);
}
.wizard-visual-tab.active {
  background: rgba(255,107,53,0.12);
  border-color: var(--color-accent);
  color: var(--color-accent);
  box-shadow: inset 0 0 0 1px rgba(255,107,53,0.18);
}
.wizard-visual-hint {
  margin-top: 10px;
  color: var(--color-text-muted);
  font-size: 11px;
  line-height: 1.45;
}

.image-upload-zone { display: block; border: 1.5px dashed var(--color-border); border-radius: 8px; padding: 24px; text-align: center; cursor: pointer; transition: 0.2s; background: var(--color-bg-elevated); margin-bottom: 14px; }
.image-upload-zone:hover { border-color: var(--color-accent); background: rgba(255,107,53,0.08); }
.image-upload-zone-ico { font-size: 32px; margin-bottom: 8px; }
.image-upload-zone-title { font-size: 13px; font-weight: 600; margin-bottom: 3px; color: var(--color-text-primary); }
.image-upload-zone-hint { font-size: 11px; color: var(--color-text-muted); }
.image-preview-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 12px; }
.image-preview-thumb { aspect-ratio: 1; border-radius: 8px; overflow: hidden; position: relative; border: 1px solid var(--color-border); background: var(--color-bg-elevated); }
.image-preview-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.image-preview-name { position: absolute; inset: auto 0 0; padding: 14px 6px 6px; color: #fff; font-size: 10px; line-height: 1.25; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; word-break: break-word; background: linear-gradient(180deg, transparent, rgba(0,0,0,0.72)); }
.image-preview-add { aspect-ratio: 1; border-radius: 8px; border: 1.5px dashed var(--color-border); display: flex; align-items: center; justify-content: center; color: var(--color-text-muted); background: var(--color-bg-elevated); cursor: pointer; font-size: 24px; }
.image-preview-add:hover { border-color: var(--color-accent); color: var(--color-accent); }

@media (max-width: 680px) {
  .niche-grid { grid-template-columns: repeat(2, 1fr); }
  .source-type-grid { grid-template-columns: repeat(2, 1fr); }
  .settings-2col { grid-template-columns: 1fr; }
  .niche-preset-summary { grid-template-columns: repeat(2, 1fr); }
  .ai-broll-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
