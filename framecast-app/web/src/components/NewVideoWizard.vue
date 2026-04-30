<script setup>
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'

const props = defineProps({
  channels: { type: Array, default: () => [] },
})

const emit = defineEmits(['created'])

const router = useRouter()

const show = ref(false)

const wizardStep = ref(1)
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
// 'stock_video' | 'stock_images' | 'ai_images' | 'waveform'
const globalVisualMode = ref('stock_video')
const brandKits = ref([])

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
  { key: 'blank',               icon: '✏️', label: 'Start from Scratch',  hint: 'Build every scene yourself' },
]

const durationOptions = [
  { label: '30s', value: '30' },
  { label: '60s', value: '60' },
  { label: '90s', value: '90' },
  { label: '3 min', value: '180' },
]

const visualTypeOptions = [
  { key: 'stock_video',  icon: '🎬', label: 'Stock Video',  hint: 'Real video clips per scene' },
  { key: 'stock_images', icon: '🖼️', label: 'Stock Images', hint: 'Stock photos per scene' },
  { key: 'ai_images',   icon: '✦',  label: 'AI Images',    hint: 'AI-generated art per scene' },
  { key: 'waveform',    icon: '🌊', label: 'Audio Wave',   hint: 'Animated waveform visual' },
]

const aiBrollStyleOptions = [
  { key: 'cinematic',      label: 'Cinematic',      hint: 'Dramatic film-style shots'    },
  { key: 'photorealistic', label: 'Photorealistic', hint: 'Cinematic real-world stills'  },
  { key: 'realistic',      label: 'Realistic',      hint: 'Natural people and places'    },
  { key: '3d_animated',    label: '3D Animated',    hint: 'Pixar-quality 3D renders'     },
  { key: 'cyberpunk_80s',  label: '80s Cyberpunk',  hint: 'Neon retro future'            },
  { key: 'anime_80s',      label: '80s Anime',      hint: 'Vintage cel animation'        },
  { key: 'anime_90s',      label: '90s Anime',      hint: 'Painted anime worlds'         },
  { key: 'anime',          label: 'Anime',          hint: 'Vibrant cel-shaded art'       },
  { key: 'dark_fantasy',   label: 'Dark Fantasy',   hint: 'Gothic and ethereal'          },
  { key: 'fantasy_retro',  label: 'Fantasy Retro',  hint: 'Painterly storybook magic'    },
  { key: 'comic',          label: 'Comic',          hint: 'Bold ink and action'          },
  { key: 'film_noir',      label: 'Film Noir',      hint: 'Black and white shadows'      },
  { key: 'dark',           label: 'Dark',           hint: 'Moody high-contrast noir'     },
  { key: 'line_drawing',   label: 'Line Drawing',   hint: 'Clean monochrome sketch'      },
  { key: 'watercolor',     label: 'Watercolor',     hint: 'Soft illustrated washes'      },
  { key: 'paper_cutout',   label: 'Paper Cutout',   hint: 'Layered paper collage'        },
  { key: 'cartoon',        label: 'Cartoon',        hint: 'Simple expressive art'        },
  { key: 'documentary',    label: 'Documentary',    hint: 'Natural light realism'        },
  { key: 'minimalist',     label: 'Minimalist',     hint: 'Clean muted composition'      },
  { key: 'vintage',        label: 'Vintage',        hint: 'Retro film grain aesthetic'   },
  { key: 'neon',           label: 'Neon',           hint: 'Glowing cyberpunk night'      },
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
}

function selectCustomNiche() {
  selectedNicheId.value = null
  customNicheSelected.value = true
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

function open(initialSourceType = 'prompt', presetChannelId = null) {
  wizardStep.value = 1
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
  globalVisualMode.value = 'stock_video'
  channelId.value = presetChannelId ? String(presetChannelId) : ''
  title.value = ''
  promptText.value = ''
  scriptText.value = ''
  urlText.value = ''
  audioFile.value = null
  videoFile.value = null
  revokeImagePreviewItems()
  show.value = true
  loadNiches()
  loadBrandKits()
}

function close() {
  if (wizardCreateState.value === 'loading') return
  show.value = false
}

function wizardNext() {
  if (wizardStep.value === 1 && !selectedNicheId.value && !customNicheSelected.value) return
  wizardStep.value = Math.min(3, wizardStep.value + 1)
}

function wizardBack() {
  wizardStep.value = Math.max(1, wizardStep.value - 1)
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
      ...(channelId.value ? { channel_id: Number(channelId.value) } : {}),
      ...(brandKitId.value ? { brand_kit_id: Number(brandKitId.value) } : {}),
      ...(contentGoal.value ? { content_goal: contentGoal.value } : {}),
      ...(customNicheVoiceTone.value ? { tone: customNicheVoiceTone.value } : {}),
      ...(title.value ? { title: title.value } : {}),
      ...(durationTargetSeconds.value ? { duration_target_seconds: Number(durationTargetSeconds.value) } : {}),
      ...(sourceType === 'images' && imageVisualMode.value === 'upload' ? { source_image_asset_ids: sourceImageAssetIds.value } : {}),
      ...(sourceType === 'images' && imageVisualMode.value === 'ai' ? { visual_generation_mode: 'ai_images', ai_broll_style: aiBrollStyle.value } : {}),
      ...(sourceType !== 'images' && sourceType !== 'blank' && globalVisualMode.value === 'ai_images' ? { visual_generation_mode: 'ai_images', ai_broll_style: aiBrollStyle.value } : {}),
      ...(sourceType !== 'images' && sourceType !== 'blank' && globalVisualMode.value === 'stock_images' ? { visual_generation_mode: 'stock_images' } : {}),
      ...(sourceType !== 'images' && sourceType !== 'blank' && globalVisualMode.value === 'waveform' ? { visual_generation_mode: 'waveform' } : {}),
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

      <!-- Step indicator -->
      <div class="wizard-steps">
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

      <!-- Step 1: Pick Niche -->
      <div v-if="wizardStep === 1">
        <div class="modal-title">Quick Start</div>
        <div class="modal-subtitle">Pick your content niche. Framecast pre-configures visuals, voice, captions, and music.</div>
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
          <button class="btn btn-ghost" type="button" @click="close">Cancel</button>
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
              <div class="custom-niche-copy">Describe the channel lane and the defaults Framecast should lean toward.</div>
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
              <span><strong>DALL-E B-roll</strong> generates a new image for each scene. Pick the visual style, then describe what the faceless video should be about.</span>
            </div>
            <div class="input-label" style="margin-bottom:8px;">Select the B-roll style</div>
            <div class="ai-broll-grid">
              <button
                v-for="style in aiBrollStyleOptions"
                :key="style.key"
                :class="['ai-broll-card', aiBrollStyle === style.key ? 'selected' : '']"
                type="button"
                @click="aiBrollStyle = style.key"
              >
                <span :class="['ai-style-art', `art-${style.key}`]"></span>
                <span class="ai-broll-label">{{ style.label }}</span>
                <span class="ai-broll-hint">{{ style.hint }}</span>
              </button>
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
          <div class="visual-type-cards">
            <button
              v-for="vt in visualTypeOptions"
              :key="vt.key"
              :class="['visual-type-card', globalVisualMode === vt.key ? 'selected' : '']"
              type="button"
              @click="globalVisualMode = vt.key"
            >
              <span class="vtc-art" :data-vt="vt.key"></span>
              <span class="vtc-icon">{{ vt.icon }}</span>
              <span class="vtc-label">{{ vt.label }}</span>
              <span class="vtc-hint">{{ vt.hint }}</span>
            </button>
          </div>
          <template v-if="globalVisualMode === 'ai_images'">
            <div class="input-label" style="margin:14px 0 8px;">Select AI style</div>
            <div class="ai-broll-grid">
              <button
                v-for="style in aiBrollStyleOptions"
                :key="style.key"
                :class="['ai-broll-card', aiBrollStyle === style.key ? 'selected' : '']"
                type="button"
                @click="aiBrollStyle = style.key"
              >
                <span :class="['ai-style-art', `art-${style.key}`]"></span>
                <span class="ai-broll-label">{{ style.label }}</span>
                <span class="ai-broll-hint">{{ style.hint }}</span>
              </button>
            </div>
          </template>
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

        <div v-if="wizardCreateError" class="modal-error mt">{{ wizardCreateError }}</div>

        <div class="modal-actions">
          <button class="btn btn-ghost" type="button" @click="wizardBack">← Back</button>
          <button class="btn btn-primary" type="button" :disabled="wizardCreateState === 'loading'" @click="submitWizardProject">
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

.upload-zone { border: 1px dashed var(--color-border); border-radius: 8px; padding: 20px 16px; color: var(--color-text-muted); font-size: 13px; text-align: center; background: var(--color-bg-card); }
.upload-zone-input { display: block; cursor: pointer; transition: 0.15s ease; }
.upload-zone-input:hover { border-color: rgba(255,107,53,0.35); color: var(--color-text-secondary); }
.hidden-file-input { display: none; }

.image-mode-toggle { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 12px; }
.image-mode-btn { padding: 9px 12px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-secondary); font-size: 12px; font-weight: 600; cursor: pointer; transition: 0.15s; }
.image-mode-btn:hover { border-color: rgba(255,107,53,0.35); }
.image-mode-btn.active { border-color: var(--color-accent); background: rgba(255,107,53,0.1); color: var(--color-accent); }
.image-ai-hint { display: flex; gap: 10px; padding: 10px 12px; margin-bottom: 12px; border-radius: 8px; border: 1px solid rgba(255,107,53,0.2); background: rgba(255,107,53,0.08); color: var(--color-text-secondary); font-size: 12px; line-height: 1.5; }
.image-ai-hint strong { color: var(--color-text-primary); }

.ai-broll-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
.ai-broll-card { min-height: 112px; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; text-align: left; padding: 0; overflow: hidden; transition: 0.15s; }
.ai-broll-card:hover { border-color: rgba(255,107,53,0.35); transform: translateY(-1px); }
.ai-broll-card.selected { border-color: var(--color-accent); box-shadow: inset 0 0 0 1px rgba(255,107,53,0.2); }
.ai-broll-art { display: block; height: 58px; background: radial-gradient(circle at 30% 20%, var(--style-tone), transparent 34%), linear-gradient(135deg, var(--style-tone), rgba(255,255,255,0.05)); border-bottom: 1px solid var(--color-border); }
.ai-broll-label { display: block; padding: 8px 8px 2px; color: var(--color-text-primary); font-size: 12px; font-weight: 700; }
.ai-broll-hint { display: block; padding: 0 8px 8px; color: var(--color-text-muted); font-size: 10px; line-height: 1.3; }

/* Visual type cards */
.visual-type-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
.visual-type-card { border-radius: 10px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); cursor: pointer; text-align: left; padding: 0; overflow: hidden; transition: 0.15s; display: flex; flex-direction: column; }
.visual-type-card:hover { border-color: rgba(255,107,53,0.4); transform: translateY(-1px); }
.visual-type-card.selected { border-color: var(--color-accent); box-shadow: 0 0 0 1px rgba(255,107,53,0.25); }
.vtc-art { display: block; height: 52px; }
.vtc-art[data-vt="stock_video"] { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 40%, #0f3460 70%, #533483 100%); }
.vtc-art[data-vt="stock_images"] { background: linear-gradient(135deg, #1e3a2f 0%, #2d6a4f 50%, #52b788 100%); }
.vtc-art[data-vt="ai_images"] { background: linear-gradient(135deg, #2d1b69 0%, #7c3aed 50%, #f59e0b 100%); }
.vtc-art[data-vt="waveform"] { background: linear-gradient(135deg, #0c1445 0%, #1e3a5f 40%, #0891b2 80%, #22d3ee 100%); }
.vtc-icon { display: block; padding: 8px 8px 1px; font-size: 14px; }
.vtc-label { display: block; padding: 0 8px 2px; font-size: 12px; font-weight: 700; color: var(--color-text-primary); }
.vtc-hint { display: block; padding: 0 8px 10px; font-size: 10px; color: var(--color-text-muted); line-height: 1.3; }

/* Per-style CSS art previews */
.ai-style-art { display: block; height: 58px; border-bottom: 1px solid var(--color-border); }
.art-cinematic      { background: linear-gradient(180deg, #0a0a0a 0%, #1a1208 40%, #3d2b00 70%, #6b4a00 100%); box-shadow: inset 0 0 20px rgba(255,180,0,0.15); }
.art-photorealistic { background: linear-gradient(160deg, #1a2a3a 0%, #2c4a6e 40%, #3d6b9e 70%, #5a8fc0 100%); }
.art-realistic      { background: linear-gradient(180deg, #87ceeb 0%, #b8d4e8 35%, #6b8e6b 60%, #4a7c4a 100%); }
.art-3d_animated    { background: linear-gradient(135deg, #0d9488 0%, #06b6d4 35%, #818cf8 65%, #c084fc 100%); }
.art-cyberpunk_80s  { background: linear-gradient(135deg, #0f0020 0%, #2d0057 35%, #7c00b4 60%, #ff00ff 85%, #00ffff 100%); }
.art-anime_80s      { background: linear-gradient(160deg, #fce4ec 0%, #f8bbd0 30%, #b2dfdb 60%, #e0f2f1 100%); }
.art-anime_90s      { background: linear-gradient(160deg, #ff8f00 0%, #ffa726 30%, #ffcc02 55%, #ff7043 80%, #d32f2f 100%); }
.art-anime          { background: linear-gradient(135deg, #ff6ec7 0%, #bf5af2 40%, #5e5ce6 70%, #30d0fb 100%); }
.art-dark_fantasy   { background: linear-gradient(180deg, #0d0015 0%, #1a003a 40%, #2d0057 65%, #6600cc 90%, rgba(180,100,255,0.3) 100%); }
.art-fantasy_retro  { background: linear-gradient(135deg, #2d1b00 0%, #7c4500 30%, #c47a2b 55%, #8b4cc8 80%, #4a1080 100%); }
.art-comic          { background: linear-gradient(135deg, #fff200 0%, #ff6b00 25%, #e8001a 50%, #1a1aff 75%, #000 100%); }
.art-film_noir      { background: linear-gradient(160deg, #000 0%, #1a1a1a 30%, #555 55%, #888 75%, #ccc 100%); }
.art-dark           { background: radial-gradient(circle at 30% 40%, #2a1a00 0%, #1a0a00 40%, #080808 100%); }
.art-line_drawing   { background: #f8f8f0; background-image: repeating-linear-gradient(0deg, transparent, transparent 9px, rgba(0,0,0,0.06) 9px, rgba(0,0,0,0.06) 10px), repeating-linear-gradient(90deg, transparent, transparent 9px, rgba(0,0,0,0.04) 9px, rgba(0,0,0,0.04) 10px); }
.art-watercolor     { background: linear-gradient(135deg, #e0f7fa 0%, #80deea 25%, #b2ebf2 50%, #c8e6c9 75%, #f8bbd0 100%); opacity: 0.95; }
.art-paper_cutout   { background: linear-gradient(160deg, #fdf5e0 0%, #f5d78e 30%, #e8a04a 55%, #c87920 80%, #8b4500 100%); }
.art-cartoon        { background: linear-gradient(135deg, #ff4444 0%, #ff8800 25%, #ffee00 50%, #44cc00 75%, #0088ff 100%); }
.art-documentary    { background: linear-gradient(160deg, #2d3a1a 0%, #4a6030 35%, #7a9458 60%, #c4b478 85%, #e8d8a0 100%); }
.art-minimalist     { background: linear-gradient(160deg, #f5f5f0 0%, #e8e8e2 50%, #d8d8d0 100%); }
.art-vintage        { background: linear-gradient(135deg, #3d2b00 0%, #7a5200 30%, #b8843f 55%, #d4a96a 75%, #e8c896 100%); filter: sepia(0.3); }
.art-neon           { background: linear-gradient(135deg, #0a0015 0%, #15003a 35%, #4b006e 60%, #9400d3 80%, #ff00ff 100%); box-shadow: inset 0 0 20px rgba(148,0,211,0.4); }

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
