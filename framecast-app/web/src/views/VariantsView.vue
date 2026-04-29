<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import AppSidebar from '../components/AppSidebar.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

const projectId = computed(() => Number(route.params.projectId))
const mePayload = ref(null)

const loading = ref(true)
const loadError = ref('')
const project = ref(null)
const hookOptions = ref([])
const voiceProfiles = ref([])
const variantSets = ref([])
const localizationGroups = ref([])

const drawerOpen = ref(false)
const createPending = ref(false)
const createError = ref('')
const localizationPending = ref(false)
const localizationError = ref('')
const retryLocalizationPendingId = ref(null)
const exportPending = ref(false)
const exportError = ref('')
const retryPending = ref(false)
const retryError = ref('')
const retryConfirmOpen = ref(false)
const deletePending = ref(false)
const deleteError = ref('')
const deleteVariantTarget = ref(null)
const failedDetailTarget = ref(null)
const queueDetailOpen = ref(false)

const varyHook = ref(true)
const hookCount = ref(3)
const varyVoice = ref(false)
const selectedVoiceIds = ref([])
const varyVisual = ref(false)
const varyFormat = ref(false)
const selectedFormats = ref(['9:16'])
const lockSceneText = ref(false)
const lockCaptions = ref(false)
const selectedLocalizationLanguages = ref(['es', 'fr', 'de'])

const selectedVariantIds = ref([])
let pollTimer = null

const localizationLanguageOptions = [
  { value: 'es', label: 'Spanish' },
  { value: 'fr', label: 'French' },
  { value: 'de', label: 'German' },
  { value: 'pt', label: 'Portuguese' },
  { value: 'it', label: 'Italian' },
  { value: 'hi', label: 'Hindi' },
  { value: 'ja', label: 'Japanese' },
  { value: 'ar', label: 'Arabic' },
]

const availableHooks = computed(() => hookOptions.value.slice(0, 5))
const availableHookCount = computed(() => availableHooks.value.length)
const hookCountOptions = computed(() => {
  if (availableHookCount.value < 2) {
    return []
  }

  return Array.from({ length: Math.min(5, availableHookCount.value) - 1 }, (_, index) => index + 2)
})

const variantCards = computed(() =>
  variantSets.value.flatMap((variantSet) =>
    (variantSet.variants || []).map((variant) => ({
      ...variant,
      variant_set_status: variantSet.status,
      variant_set_id: variantSet.id,
    })),
  ),
)

const localizationCards = computed(() =>
  localizationGroups.value.flatMap((group) =>
    (group.links || []).map((link) => ({
      ...link,
      group_status: group.status,
      source_language: group.source_language,
    })),
  ),
)

const exportableVariantIds = computed(() =>
  variantCards.value
    .filter((variant) => variant.derived_project_id && ['ready_for_review', 'rendered'].includes(variant.status))
    .map((variant) => variant.id),
)

const selectedExportableCount = computed(() =>
  selectedVariantIds.value.filter((id) => exportableVariantIds.value.includes(id)).length,
)

const failedVariantSetIds = computed(() =>
  Array.from(new Set(
    variantCards.value
      .filter((variant) => variant.status === 'failed')
      .map((variant) => variant.variant_set_id),
  )),
)

const partialSuccessSets = computed(() =>
  variantSets.value.filter((variantSet) => variantSet.status === 'partial_success'),
)

const activeBatchJobs = computed(() =>
  variantSets.value
    .map((variantSet) => ({
      variant_set_id: variantSet.id,
      status: variantSet.status,
      batch_job: variantSet.latest_batch_job ?? null,
    }))
    .filter((row) => row.batch_job),
)

const queueDetailRows = computed(() =>
  [...variantCards.value]
    .sort((left, right) => {
      const order = { failed: 0, generating: 1, queued: 2, pending: 3, ready_for_review: 4, rendered: 5 }
      return (order[left.status] ?? 99) - (order[right.status] ?? 99)
    })
    .map((variant) => {
      const exportJob = variant.latest_export_job
      const batchJob = variantSets.value.find((item) => item.id === variant.variant_set_id)?.latest_batch_job ?? null

      return {
        id: variant.id,
        label: variant.variant_label,
        changed: changedDimensionText(variant),
        status: displayVariantStatus(variant),
        statusCopy: variantStatusCopy(displayVariantStatus(variant)),
        progress: exportJob?.progress_percent ?? (['rendered', 'ready_for_review'].includes(displayVariantStatus(variant)) ? 100 : ['pending', 'queued', 'generating', 'processing'].includes(displayVariantStatus(variant)) ? 24 : 0),
        exportStatus: exportJob?.status ?? null,
        exportFailureReason: exportJob?.failure_reason ?? null,
        exportFailureSummary: summarizeFailureReason(exportJob?.failure_reason ?? null),
        batchStatus: batchJob?.status ?? null,
        batchFailureSummary: batchJob?.failure_summary ?? null,
        variant,
      }
    }),
)

const stats = computed(() => {
  const cards = variantCards.value

  return {
    total: cards.length,
    rendered: cards.filter((card) => displayVariantStatus(card) === 'rendered').length,
    queued: cards.filter((card) => ['pending', 'generating', 'queued', 'processing'].includes(displayVariantStatus(card))).length,
    failed: cards.filter((card) => displayVariantStatus(card) === 'failed').length,
  }
})

const localizationStats = computed(() => {
  const cards = localizationCards.value

  return {
    total: cards.length,
    completed: cards.filter((card) => card.status === 'completed').length,
    active: cards.filter((card) => ['pending', 'translating', 'dub_generating'].includes(card.status)).length,
    failed: cards.filter((card) => card.status === 'failed').length,
  }
})

const projectTitle = computed(() => project.value?.title || `Project #${projectId.value}`)

const exportSelectedLabel = computed(() => {
  if (exportPending.value) {
    return 'Exporting...'
  }

  return selectedExportableCount.value > 0 ? `Export ${selectedExportableCount.value}` : 'Export'
})

const retryFailedLabel = computed(() => {
  if (retryPending.value) {
    return 'Retrying...'
  }

  return stats.value.failed > 0 ? `Retry Failed (${stats.value.failed})` : 'Retry Failed'
})

const partialSuccessCopy = computed(() => {
  if (partialSuccessSets.value.length === 0) return ''
  if (partialSuccessSets.value.length === 1) return 'One variant batch completed with partial success. Review failed items before export.'
  return `${partialSuccessSets.value.length} variant batches completed with partial success. Review failed items before export.`
})

const baseProjectMeta = computed(() => {
  const language = project.value?.primary_language || 'en'
  const aspectRatio = project.value?.aspect_ratio || '9:16'
  const channel = project.value?.channel_id ? `Channel #${project.value.channel_id}` : 'No channel'

  return `${language} · ${aspectRatio} · ${channel}`
})

const computedVariantCount = computed(() => {
  const factors = []

  if (varyHook.value) {
    if (hookCountOptions.value.length === 0) return 0
    factors.push(hookCount.value)
  }

  if (varyVoice.value) {
    if (selectedVoiceIds.value.length === 0) return 0
    factors.push(selectedVoiceIds.value.length)
  }

  if (varyVisual.value) {
    factors.push(1)
  }

  if (varyFormat.value) {
    if (selectedFormats.value.length === 0) return 0
    factors.push(selectedFormats.value.length)
  }

  if (factors.length === 0) return 0

  return factors.reduce((total, count) => total * count, 1)
})

const batchSummary = computed(() => {
  const pieces = []

  if (varyHook.value) pieces.push(`${hookCount.value} hooks`)
  if (varyVoice.value) pieces.push(`${selectedVoiceIds.value.length} voices`)
  if (varyVisual.value) pieces.push('1 visual pass')
  if (varyFormat.value) pieces.push(`${selectedFormats.value.length} formats`)

  if (pieces.length === 0 || computedVariantCount.value === 0) {
    return 'Choose at least one dimension to generate variants.'
  }

  return `${pieces.join(' × ')} = ${computedVariantCount.value} variants`
})

function backToDashboard() {
  router.push({ name: 'dashboard' })
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

function openEditor(projectTargetId) {
  router.push({ name: 'project-editor', params: { projectId: projectTargetId } })
}

function downloadVariant(variant) {
  const url = variant?.latest_export_job?.output_asset?.storage_url

  if (!url) {
    return
  }

  window.open(url, '_blank', 'noopener')
}

function openDrawer() {
  createError.value = ''
  lockSceneText.value = false
  lockCaptions.value = false
  varyHook.value = availableHookCount.value >= 2
  drawerOpen.value = true
}

function closeDrawer() {
  if (createPending.value) return
  drawerOpen.value = false
}

function openQueueDetail() {
  queueDetailOpen.value = true
}

function closeQueueDetail() {
  queueDetailOpen.value = false
}

function openFailedDetail(variant) {
  failedDetailTarget.value = variant
}

function closeFailedDetail() {
  failedDetailTarget.value = null
}

function openRetryConfirm() {
  if (failedVariantSetIds.value.length === 0 || retryPending.value) {
    return
  }

  retryError.value = ''
  retryConfirmOpen.value = true
}

function closeRetryConfirm() {
  if (retryPending.value) return
  retryConfirmOpen.value = false
}

function promptDeleteVariant(variant) {
  if (['pending', 'generating', 'queued'].includes(variant.status)) {
    return
  }

  deleteError.value = ''
  deleteVariantTarget.value = variant
}

function closeDeleteModal() {
  if (deletePending.value) return
  deleteVariantTarget.value = null
}

function variantStatusCopy(status) {
  if (status === 'rendered') return 'Rendered'
  if (status === 'ready_for_review') return 'Ready'
  if (status === 'processing') return 'Processing'
  if (status === 'completed') return 'Completed'
  if (status === 'translating') return 'Translating'
  if (status === 'dub_generating') return 'Generating voice'
  if (status === 'queued') return 'Queued'
  if (status === 'generating') return 'Generating'
  if (status === 'failed') return 'Failed'
  return 'Pending'
}

function variantStatusClass(status) {
  return `status-${status}`
}

function displayVariantStatus(variant) {
  const exportStatus = variant.latest_export_job?.status

  if (exportStatus === 'processing') return 'processing'
  if (exportStatus === 'queued') return 'queued'
  if (exportStatus === 'completed') return 'rendered'
  if (exportStatus === 'failed') return 'failed'

  return variant.status
}

function batchStatusCopy(status) {
  if (status === 'partial_success') return 'Partial Success'
  if (status === 'processing') return 'Processing'
  if (status === 'completed') return 'Completed'
  if (status === 'failed') return 'Failed'
  return 'Queued'
}

function normalizeFailureReason(reason) {
  return String(reason || '')
    .replace(/\s+/g, ' ')
    .trim()
}

function summarizeFailureReason(reason) {
  const normalized = normalizeFailureReason(reason)

  if (!normalized) {
    return 'Export failed before a file could be produced.'
  }

  if (normalized.includes('Could not render one or more scene segments')) {
    return 'One or more scene segments could not be rendered into the final video.'
  }

  if (normalized.includes('No such file or directory')) {
    return 'A required media file could not be found during export.'
  }

  if (normalized.includes('Invalid data found when processing input')) {
    return 'One of the generated media files is invalid or unreadable.'
  }

  if (normalized.includes('Input/output error')) {
    return 'Export failed while reading or writing media files.'
  }

  if (normalized.includes('Conversion failed')) {
    return 'FFmpeg could not combine the scene media into a final export.'
  }

  return normalized.length > 180 ? `${normalized.slice(0, 177)}...` : normalized
}

function variantCardTone(variant) {
  const seed = (variant.id % 6) + 1
  const tones = [
    'tone-a',
    'tone-b',
    'tone-c',
    'tone-d',
    'tone-e',
    'tone-f',
  ]

  return tones[seed - 1]
}

function changedDimensionText(variant) {
  const changed = variant.changed_dimensions || {}
  const pieces = []

  if (changed.hook) pieces.push('Alt hook')
  if (changed.voice) pieces.push(changed.voice.name ? `${changed.voice.name} voice` : 'Voice swap')
  if (changed.visual) pieces.push('Visual refresh')
  if (changed.format) pieces.push(changed.format.aspect_ratio)

  if (pieces.length === 0) {
    return 'Base variant'
  }

  return pieces.join(' · ')
}

function languageLabel(language) {
  return localizationLanguageOptions.find((option) => option.value === language)?.label || language
}

function toggleLocalizationLanguage(language) {
  if (selectedLocalizationLanguages.value.includes(language)) {
    selectedLocalizationLanguages.value = selectedLocalizationLanguages.value.filter((value) => value !== language)
    return
  }

  selectedLocalizationLanguages.value = [...selectedLocalizationLanguages.value, language]
}

function voiceSelectionKey(voice) {
  return String(voice.provider_voice_key || voice.id)
}

function toggleVoiceSelection(voiceId) {
  if (selectedVoiceIds.value.includes(voiceId)) {
    selectedVoiceIds.value = selectedVoiceIds.value.filter((id) => id !== voiceId)
    return
  }

  selectedVoiceIds.value = [...selectedVoiceIds.value, voiceId]
}

function toggleFormatSelection(format) {
  if (selectedFormats.value.includes(format)) {
    selectedFormats.value = selectedFormats.value.filter((value) => value !== format)
    return
  }

  selectedFormats.value = [...selectedFormats.value, format]
}

function toggleVariantSelection(variantId) {
  if (selectedVariantIds.value.includes(variantId)) {
    selectedVariantIds.value = selectedVariantIds.value.filter((id) => id !== variantId)
    return
  }

  selectedVariantIds.value = [...selectedVariantIds.value, variantId]
}

function toggleSelectAll() {
  if (selectedExportableCount.value === exportableVariantIds.value.length) {
    selectedVariantIds.value = []
    return
  }

  selectedVariantIds.value = [...exportableVariantIds.value]
}

async function loadData({ silent = false } = {}) {
  if (!silent) {
    loading.value = true
    loadError.value = ''
  }

  try {
    const [projectResponse, variantsResponse, voicesResponse, meResponse] = await Promise.all([
      api.get(`/projects/${projectId.value}`),
      api.get(`/projects/${projectId.value}/variants`),
      api.get('/voice-profiles'),
      api.get('/me'),
    ])

    mePayload.value = meResponse.data?.data?.user ?? null

    const localizationResponse = await api.get(`/projects/${projectId.value}/localizations`)

    project.value = projectResponse.data?.data?.project ?? null
    hookOptions.value = projectResponse.data?.data?.hook_options ?? []
    variantSets.value = variantsResponse.data?.data?.variant_sets ?? []
    localizationGroups.value = localizationResponse.data?.data?.localization_groups ?? []
    voiceProfiles.value = voicesResponse.data?.data?.voice_profiles ?? []

    if (hookCountOptions.value.length > 0 && !hookCountOptions.value.includes(hookCount.value)) {
      hookCount.value = hookCountOptions.value[hookCountOptions.value.length - 1]
    }

    if (availableHookCount.value < 2) {
      varyHook.value = false
    }

    selectedVariantIds.value = selectedVariantIds.value.filter((id) =>
      exportableVariantIds.value.includes(id),
    )
  } catch (error) {
    loadError.value = error.response?.data?.error?.message ?? 'Could not load variants.'
  } finally {
    loading.value = false
  }
}

function startPolling() {
  stopPolling()

  pollTimer = window.setInterval(() => {
    const hasActiveWork = variantCards.value.some((variant) =>
      ['pending', 'generating', 'queued'].includes(variant.status),
    ) || localizationCards.value.some((link) =>
      ['pending', 'translating', 'dub_generating'].includes(link.status),
    )

    if (!hasActiveWork && !exportPending.value && !createPending.value) {
      return
    }

    loadData({ silent: true })
  }, 4000)
}

async function generateLocalizations() {
  localizationError.value = ''

  if (selectedLocalizationLanguages.value.length === 0) {
    localizationError.value = 'Select at least one target language.'
    return
  }

  localizationPending.value = true

  try {
    await api.post(`/projects/${projectId.value}/localizations`, {
      target_languages: selectedLocalizationLanguages.value,
    })

    await loadData({ silent: true })
  } catch (error) {
    localizationError.value = error.response?.data?.error?.message ?? 'Could not generate localizations.'
  } finally {
    localizationPending.value = false
  }
}

async function retryLocalization(link) {
  if (!link || link.status !== 'failed') return

  localizationError.value = ''
  retryLocalizationPendingId.value = link.id

  try {
    await api.post(`/localization-links/${link.id}/retry`)
    await loadData({ silent: true })
  } catch (error) {
    localizationError.value = error.response?.data?.error?.message ?? 'Could not retry localization.'
  } finally {
    retryLocalizationPendingId.value = null
  }
}

function stopPolling() {
  if (!pollTimer) return
  window.clearInterval(pollTimer)
  pollTimer = null
}

async function generateVariants() {
  createError.value = ''

  const generationDimensions = {}

  if (varyHook.value && availableHookCount.value >= 2) {
    generationDimensions.hook = { count: hookCount.value }
  }

  if (varyVoice.value) {
    generationDimensions.voice = { provider_voice_keys: selectedVoiceIds.value }
  }

  if (varyVisual.value) {
    generationDimensions.visual = { enabled: true }
  }

  if (varyFormat.value) {
    generationDimensions.format = { aspect_ratios: selectedFormats.value }
  }

  if (Object.keys(generationDimensions).length === 0) {
    createError.value = 'Select at least one dimension to vary.'
    return
  }

  createPending.value = true

  try {
    await api.post(`/projects/${projectId.value}/variants`, {
      generation_dimensions: generationDimensions,
      lock_rules_json: {
        scene_text: lockSceneText.value,
        captions: lockCaptions.value,
      },
    })

    drawerOpen.value = false
    await loadData({ silent: true })
  } catch (error) {
    createError.value = error.response?.data?.error?.message ?? 'Could not generate variants.'
  } finally {
    createPending.value = false
  }
}

async function exportSelected() {
  exportError.value = ''

  if (selectedExportableCount.value === 0) {
    exportError.value = 'Select at least one ready variant to export.'
    return
  }

  exportPending.value = true

  try {
    const groupedVariantIds = selectedVariantIds.value.reduce((groups, variantId) => {
      const card = variantCards.value.find((variant) => variant.id === variantId)

      if (!card || !exportableVariantIds.value.includes(variantId)) {
        return groups
      }

      groups[card.variant_set_id] ??= []
      groups[card.variant_set_id].push(variantId)
      return groups
    }, {})

    for (const [variantSetId, variantIds] of Object.entries(groupedVariantIds)) {
      await api.post(`/variant-sets/${variantSetId}/export`, {
        variant_ids: variantIds,
      })
    }

    await loadData({ silent: true })
  } catch (error) {
    exportError.value = error.response?.data?.error?.message ?? 'Could not export selected variants.'
  } finally {
    exportPending.value = false
  }
}

async function retryFailedVariants() {
  retryError.value = ''

  if (failedVariantSetIds.value.length === 0) {
    return
  }

  retryPending.value = true

  try {
    for (const variantSetId of failedVariantSetIds.value) {
      await api.post(`/variant-sets/${variantSetId}/retry-failed`)
    }

    retryConfirmOpen.value = false
    await loadData({ silent: true })
  } catch (error) {
    retryError.value = error.response?.data?.error?.message ?? 'Could not retry failed variants.'
  } finally {
    retryPending.value = false
  }
}

async function deleteVariant() {
  if (!deleteVariantTarget.value) {
    return
  }

  const variantId = deleteVariantTarget.value.id
  deleteError.value = ''
  deletePending.value = true

  try {
    await api.delete(`/variants/${variantId}`)
    selectedVariantIds.value = selectedVariantIds.value.filter((id) => id !== variantId)
    deleteVariantTarget.value = null
    await loadData({ silent: true })
  } catch (error) {
    deleteError.value = error.response?.data?.error?.message ?? 'Could not delete variant.'
  } finally {
    deletePending.value = false
  }
}

watch(varyHook, (enabled) => {
  if (!enabled) return
  if (hookCountOptions.value.length === 0) {
    varyHook.value = false
  }
})

watch(varyVoice, (enabled) => {
  if (!enabled) {
    selectedVoiceIds.value = []
  }
})

watch(varyFormat, (enabled) => {
  if (!enabled) {
    selectedFormats.value = ['9:16']
  }
})

onMounted(async () => {
  await loadData()
  startPolling()
})

onBeforeUnmount(() => {
  stopPolling()
})
</script>

<template>
  <main class="variants-shell">
    <AppSidebar :user="mePayload" active-page="variants" @logout="logout" />

    <section class="main">
      <header class="topbar">
        <div>
          <div class="topbar-title">Variants</div>
          <div class="topbar-subtitle">
            {{ projectTitle }} · Compare and export alternate cuts from one base project
          </div>
        </div>
        <div class="topbar-actions">
          <button class="btn btn-ghost" type="button" @click="openEditor(projectId)">Back to Editor</button>
          <button class="btn btn-ghost" type="button" @click="backToDashboard">Dashboard</button>
        </div>
      </header>

      <div v-if="loading" class="page-state">Loading variants…</div>
      <div v-else-if="loadError" class="page-state error">{{ loadError }}</div>
      <div v-else class="variants-page">
        <div class="page-grid">
          <div class="surface-card variants-surface">
            <div class="section-header">
              <div>
                <div class="section-title">Variant Factory</div>
                <div class="section-subtitle">Generate hooks, voices, visuals, and formats from one finished project.</div>
              </div>
              <div class="header-actions">
                <button class="btn btn-ghost btn-sm" type="button" @click="toggleSelectAll">
                  {{ selectedExportableCount === exportableVariantIds.length && exportableVariantIds.length > 0 ? 'Clear All' : 'Select All' }}
                </button>
                <button class="btn btn-primary btn-sm" type="button" :disabled="exportPending || selectedExportableCount === 0" @click="exportSelected">
                  {{ exportSelectedLabel }}
                </button>
              </div>
            </div>

            <div v-if="exportError" class="banner error">{{ exportError }}</div>
            <div v-if="retryError" class="banner error">{{ retryError }}</div>
            <div v-if="deleteError" class="banner error">{{ deleteError }}</div>
            <div v-if="localizationError" class="banner error">{{ localizationError }}</div>
            <div v-if="partialSuccessSets.length > 0" class="banner warning">{{ partialSuccessCopy }}</div>

            <div v-if="variantCards.length > 0" class="variant-grid">
              <article v-for="variant in variantCards" :key="variant.id" class="variant-card">
                <div :class="['variant-thumb', variantCardTone(variant)]">
                  <div class="variant-mini-phone"></div>
                  <span :class="['variant-status-pill', variantStatusClass(variant.status)]">{{ variantStatusCopy(variant.status) }}</span>
                </div>
                <div class="variant-info">
                  <div class="variant-label">{{ variant.variant_label }}</div>
                  <div class="variant-diff">{{ changedDimensionText(variant) }}</div>
                  <div class="variant-footer">
                    <button
                      :class="['checkbox', selectedVariantIds.includes(variant.id) ? 'checked' : '']"
                      type="button"
                      :disabled="!exportableVariantIds.includes(variant.id)"
                      @click="toggleVariantSelection(variant.id)"
                    >
                      {{ selectedVariantIds.includes(variant.id) ? '✓' : '' }}
                    </button>
                    <span class="variant-footer-copy">
                      {{ exportableVariantIds.includes(variant.id) ? 'Selected for batch export' : displayVariantStatus(variant) === 'failed' ? 'Retry from batch controls' : displayVariantStatus(variant) === 'processing' ? 'Export in progress' : 'Not exportable yet' }}
                    </span>
                  </div>
                  <div class="variant-actions">
                    <button
                      v-if="variant.derived_project_id"
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="openEditor(variant.derived_project_id)"
                    >
                      Open
                    </button>
                    <button
                      v-if="variant.latest_export_job?.output_asset?.storage_url"
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="downloadVariant(variant)"
                    >
                      Download
                    </button>
                    <button
                      class="btn btn-ghost btn-sm"
                      type="button"
                      :disabled="['pending', 'generating', 'queued', 'processing'].includes(displayVariantStatus(variant))"
                      @click="promptDeleteVariant(variant)"
                    >
                      Delete
                    </button>
                    <button
                      v-if="variant.status === 'failed'"
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="openFailedDetail(variant)"
                    >
                      Details
                    </button>
                  </div>
                  <div v-if="variant.latest_export_job" class="variant-export-meta">
                    <span class="variant-export-copy">
                      {{ variantStatusCopy(displayVariantStatus(variant)) }} · {{ variant.latest_export_job.aspect_ratio }}
                    </span>
                  </div>
                </div>
              </article>
            </div>
            <div v-else class="empty-state">
              <div class="empty-title">No variants yet</div>
              <div class="empty-copy">Generate hook, voice, visual, or format variants from this project.</div>
              <button class="btn btn-primary" type="button" @click="openDrawer">Generate Variants</button>
            </div>

            <div class="localization-section">
              <div class="section-header compact-header">
                <div>
                  <div class="section-title">Localized Versions</div>
                  <div class="section-subtitle">Translate scene scripts and regenerate voice for each target language.</div>
                </div>
              </div>

              <div v-if="localizationCards.length > 0" class="localization-grid">
                <article v-for="link in localizationCards" :key="link.id" class="localization-card">
                  <div>
                    <div class="localization-label">{{ languageLabel(link.target_language) }}</div>
                    <div class="localization-meta">
                      {{ link.source_language }} → {{ link.target_language }}
                      <span v-if="link.voice_profile_name"> · {{ link.voice_profile_name }}</span>
                    </div>
                  </div>
                  <span :class="['variant-status-pill inline-pill', variantStatusClass(link.status)]">{{ variantStatusCopy(link.status) }}</span>
                  <div class="localization-actions">
                    <button
                      v-if="link.localized_project_id"
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="openEditor(link.localized_project_id)"
                    >
                      Open
                    </button>
                    <button
                      v-if="link.status === 'failed'"
                      class="btn btn-ghost btn-sm"
                      type="button"
                      :disabled="retryLocalizationPendingId === link.id"
                      @click="retryLocalization(link)"
                    >
                      {{ retryLocalizationPendingId === link.id ? 'Retrying...' : 'Retry' }}
                    </button>
                  </div>
                </article>
              </div>
              <div v-else class="localization-empty">No localized versions yet.</div>
            </div>
          </div>

          <aside class="surface-card control-surface">
            <div class="section-title">Batch Controls</div>
            <div class="section-subtitle">Create and export variants without leaving the project context.</div>

            <button class="btn btn-primary full-width" type="button" @click="openDrawer">Generate Variants</button>
            <button class="btn btn-ghost full-width" type="button" :disabled="exportPending || selectedExportableCount === 0" @click="exportSelected">
              {{ exportPending ? 'Exporting...' : 'Export Selected' }}
            </button>
            <button class="btn btn-ghost full-width" type="button" :disabled="retryPending || stats.failed === 0" @click="openRetryConfirm">
              {{ retryFailedLabel }}
            </button>
            <button class="btn btn-ghost full-width" type="button" :disabled="queueDetailRows.length === 0" @click="openQueueDetail">
              Queue Detail
            </button>

            <div class="side-section">
              <div class="panel-label">Localization</div>
              <div class="language-chip-wrap">
                <button
                  v-for="language in localizationLanguageOptions"
                  :key="language.value"
                  :class="['chip', selectedLocalizationLanguages.includes(language.value) ? 'selected' : '']"
                  type="button"
                  @click="toggleLocalizationLanguage(language.value)"
                >
                  {{ language.label }}
                </button>
              </div>
              <button class="btn btn-ghost full-width" type="button" :disabled="localizationPending || selectedLocalizationLanguages.length === 0" @click="generateLocalizations">
                {{ localizationPending ? 'Localizing...' : `Generate ${selectedLocalizationLanguages.length} Language${selectedLocalizationLanguages.length === 1 ? '' : 's'}` }}
              </button>
            </div>

            <div class="side-section">
              <div class="panel-label">Base Project</div>
              <div class="detail-card">
                <div class="detail-title">{{ projectTitle }}</div>
                <div class="detail-meta">{{ baseProjectMeta }}</div>
              </div>
            </div>

            <div class="side-section">
              <div class="panel-label">Generation Stats</div>
              <div class="stats-list">
                <div class="job-row"><span>Total variants</span><span>{{ stats.total }}</span></div>
                <div class="job-row"><span>Rendered</span><span class="good">{{ stats.rendered }}</span></div>
                <div class="job-row"><span>Queued</span><span class="info">{{ stats.queued }}</span></div>
                <div class="job-row"><span>Failed</span><span class="bad">{{ stats.failed }}</span></div>
              </div>
            </div>

            <div class="side-section">
              <div class="panel-label">Localization Stats</div>
              <div class="stats-list">
                <div class="job-row"><span>Total languages</span><span>{{ localizationStats.total }}</span></div>
                <div class="job-row"><span>Completed</span><span class="good">{{ localizationStats.completed }}</span></div>
                <div class="job-row"><span>Active</span><span class="info">{{ localizationStats.active }}</span></div>
                <div class="job-row"><span>Failed</span><span class="bad">{{ localizationStats.failed }}</span></div>
              </div>
            </div>

            <div v-if="activeBatchJobs.length > 0" class="side-section">
              <div class="panel-label">Batch Status</div>
              <div class="stats-list">
                <div v-for="entry in activeBatchJobs" :key="entry.variant_set_id" class="job-row">
                  <span>Set #{{ entry.variant_set_id }}</span>
                  <span :class="entry.status === 'partial_success' ? 'warn' : entry.status === 'failed' ? 'bad' : 'info'">
                    {{ batchStatusCopy(entry.batch_job.status || entry.status) }}
                  </span>
                </div>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </section>

    <div :class="['drawer-backdrop', drawerOpen ? 'open' : '']" @click="closeDrawer"></div>
    <aside :class="['drawer', drawerOpen ? 'open' : '']">
      <div class="drawer-header">
        <div class="drawer-title">Generate Variants</div>
        <button class="drawer-close" type="button" @click="closeDrawer">×</button>
      </div>

      <div class="drawer-body">
        <div class="drawer-section">
          <div class="drawer-section-label">What to vary</div>

          <div class="dim-row">
            <label class="dim-main">
              <input v-model="varyHook" :disabled="availableHookCount < 2" type="checkbox" />
              <div>
                <div class="dim-label">Hook</div>
                <div class="dim-desc">Generate alternate opening hooks</div>
              </div>
            </label>
            <div v-if="varyHook" class="dim-sub">
              <div class="sub-copy">Number of hooks</div>
              <select v-model="hookCount" class="drawer-select">
                <option v-for="count in hookCountOptions" :key="count" :value="count">{{ count }}</option>
              </select>
            </div>
            <div v-else-if="availableHookCount < 2" class="dim-disabled-copy">Need at least 2 generated hook options first.</div>
          </div>

          <div class="dim-row">
            <label class="dim-main">
              <input v-model="varyVoice" type="checkbox" />
              <div>
                <div class="dim-label">Voice</div>
                <div class="dim-desc">Swap narrator voice</div>
              </div>
            </label>
            <div v-if="varyVoice" class="chip-wrap">
              <button
                v-for="voice in voiceProfiles"
                :key="voiceSelectionKey(voice)"
                :class="['chip', selectedVoiceIds.includes(voiceSelectionKey(voice)) ? 'selected' : '']"
                type="button"
                @click.stop="toggleVoiceSelection(voiceSelectionKey(voice))"
              >
                {{ voice.name }}
              </button>
            </div>
          </div>

          <div class="dim-row">
            <label class="dim-main">
              <input v-model="varyVisual" type="checkbox" />
              <div>
                <div class="dim-label">Visual</div>
                <div class="dim-desc">Refresh scene visuals with new matches</div>
              </div>
            </label>
          </div>

          <div class="dim-row">
            <label class="dim-main">
              <input v-model="varyFormat" type="checkbox" />
              <div>
                <div class="dim-label">Format</div>
                <div class="dim-desc">Change aspect ratio</div>
              </div>
            </label>
            <div v-if="varyFormat" class="chip-wrap">
              <button
                v-for="format in ['9:16', '1:1', '16:9']"
                :key="format"
                :class="['chip', selectedFormats.includes(format) ? 'selected' : '']"
                type="button"
                @click="toggleFormatSelection(format)"
              >
                {{ format }}
              </button>
            </div>
          </div>
        </div>

        <div class="drawer-section">
          <div class="drawer-section-label">What stays fixed</div>
          <div class="lock-row"><span>Brand kit</span><span class="lock-tag locked">Locked</span></div>
          <div class="lock-row"><span>Template</span><span class="lock-tag locked">Locked</span></div>
          <div class="lock-row">
            <span>Scene text</span>
            <button :class="['lock-tag', lockSceneText ? 'locked' : '']" type="button" @click="lockSceneText = !lockSceneText">
              {{ lockSceneText ? 'Locked' : 'Unlocked' }}
            </button>
          </div>
          <div class="lock-row">
            <span>Captions</span>
            <button :class="['lock-tag', lockCaptions ? 'locked' : '']" type="button" @click="lockCaptions = !lockCaptions">
              {{ lockCaptions ? 'Locked' : 'Unlocked' }}
            </button>
          </div>
        </div>

        <div :class="['batch-summary-box', computedVariantCount === 0 ? 'warning' : '']">
          {{ batchSummary }}
        </div>

        <div v-if="createError" class="banner error">{{ createError }}</div>
      </div>

      <div class="drawer-footer">
        <button class="btn btn-ghost" type="button" @click="closeDrawer">Cancel</button>
        <button class="btn btn-primary grow" type="button" :disabled="createPending || computedVariantCount === 0" @click="generateVariants">
          {{ createPending ? 'Generating…' : 'Generate Variants' }}
        </button>
      </div>
    </aside>

    <div v-if="deleteVariantTarget" class="drawer-backdrop open" @click="closeDeleteModal"></div>
    <div v-if="deleteVariantTarget" class="confirm-modal">
      <div class="confirm-title">Delete Variant?</div>
      <div class="confirm-copy">
        This removes <strong>{{ deleteVariantTarget.variant_label }}</strong> and its derived project from this variant set.
      </div>
      <div v-if="deleteError" class="banner error">{{ deleteError }}</div>
      <div class="confirm-actions">
        <button class="btn btn-ghost" type="button" :disabled="deletePending" @click="closeDeleteModal">Cancel</button>
        <button class="btn btn-primary" type="button" :disabled="deletePending" @click="deleteVariant">
          {{ deletePending ? 'Deleting...' : 'Delete Variant' }}
        </button>
      </div>
    </div>

    <div v-if="retryConfirmOpen" class="drawer-backdrop open" @click="closeRetryConfirm"></div>
    <div v-if="retryConfirmOpen" class="confirm-modal">
      <div class="confirm-title">Retry Failed Variants?</div>
      <div class="confirm-copy">
        This will retry {{ stats.failed }} failed variant{{ stats.failed === 1 ? '' : 's' }} and keep all successful variants unchanged.
      </div>
      <div v-if="retryError" class="banner error">{{ retryError }}</div>
      <div class="confirm-actions">
        <button class="btn btn-ghost" type="button" :disabled="retryPending" @click="closeRetryConfirm">Cancel</button>
        <button class="btn btn-primary" type="button" :disabled="retryPending" @click="retryFailedVariants">
          {{ retryPending ? 'Retrying...' : 'Retry Failed' }}
        </button>
      </div>
    </div>

    <div v-if="failedDetailTarget" class="drawer-backdrop open" @click="closeFailedDetail"></div>
    <div v-if="failedDetailTarget" class="confirm-modal detail-modal">
      <div class="confirm-title">Failed Variant Detail</div>
      <div class="confirm-copy">
        <strong>{{ failedDetailTarget.variant_label }}</strong> failed during generation or export.
      </div>
      <div class="detail-grid">
        <div class="detail-line"><span>Status</span><strong class="bad">{{ variantStatusCopy(failedDetailTarget.status) }}</strong></div>
        <div class="detail-line"><span>Changed</span><strong>{{ changedDimensionText(failedDetailTarget) }}</strong></div>
        <div class="detail-line" v-if="failedDetailTarget.latest_export_job?.failure_reason">
          <span>Export error</span><strong>{{ summarizeFailureReason(failedDetailTarget.latest_export_job.failure_reason) }}</strong>
        </div>
        <div class="detail-line" v-else>
          <span>Failure reason</span><strong>Generation failed before a render artifact was produced.</strong>
        </div>
      </div>
      <div class="confirm-actions">
        <button class="btn btn-ghost" type="button" @click="closeFailedDetail">Close</button>
      </div>
    </div>

    <div v-if="queueDetailOpen" class="drawer-backdrop open" @click="closeQueueDetail"></div>
    <div v-if="queueDetailOpen" class="queue-detail-modal">
      <div class="drawer-header">
        <div class="drawer-title">Queue Detail</div>
        <button class="drawer-close" type="button" @click="closeQueueDetail">×</button>
      </div>
      <div class="queue-detail-body">
        <div v-for="row in queueDetailRows" :key="row.id" class="queue-detail-row">
          <div class="queue-detail-head">
            <div>
              <div class="detail-title">{{ row.label }}</div>
              <div class="detail-meta">{{ row.changed }}</div>
            </div>
            <span :class="['variant-status-pill', variantStatusClass(row.status)]">{{ row.statusCopy }}</span>
          </div>
          <div class="progress-track">
            <div class="progress-fill-bar" :style="{ width: `${row.progress}%` }"></div>
          </div>
          <div class="detail-grid compact">
            <div class="detail-line"><span>Batch</span><strong>{{ row.batchStatus ? batchStatusCopy(row.batchStatus) : 'Not started' }}</strong></div>
            <div class="detail-line"><span>Export</span><strong>{{ row.exportStatus || 'No export yet' }}</strong></div>
            <div v-if="row.exportFailureReason" class="detail-line full"><span>Failure</span><strong class="bad">{{ row.exportFailureSummary }}</strong></div>
          </div>
        </div>
      </div>
    </div>
  </main>
</template>

<style scoped>
.variants-shell { min-height: 100vh; background: radial-gradient(circle at top right, rgba(255, 107, 53, 0.09), transparent 28%), radial-gradient(circle at bottom left, rgba(96, 165, 250, 0.08), transparent 24%), var(--color-bg-deep); color: var(--color-text-primary); font-family: "DM Sans", sans-serif; }
.main { margin-left: var(--sidebar-width, 220px); min-height: 100vh; }
.topbar { position: sticky; top: 0; z-index: 90; min-height: 64px; background: rgba(17, 17, 24, 0.88); border-bottom: 1px solid var(--color-border); backdrop-filter: blur(14px); padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.topbar-title { font-size: 16px; font-weight: 600; }
.topbar-subtitle { margin-top: 4px; color: var(--color-text-muted); font-size: 13px; }
.topbar-actions { display: flex; align-items: center; gap: 10px; }
.variants-page { padding: 24px; }
.page-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 18px; align-items: start; }
.surface-card { background: linear-gradient(180deg, rgba(255, 255, 255, 0.015), transparent 100%), var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35); }
.variants-surface { padding: 18px; }
.control-surface { padding: 18px; position: sticky; top: 88px; }
.section-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.section-title { font-size: 16px; font-weight: 600; }
.section-subtitle { margin-top: 4px; font-size: 13px; color: var(--color-text-muted); }
.header-actions { display: flex; align-items: center; gap: 8px; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s ease; font-size: 13px; font-weight: 500; border: 1px solid transparent; }
.btn-primary { background: var(--color-accent); color: #fff; }
.btn-primary:disabled, .btn-ghost:disabled { opacity: 0.55; cursor: default; }
.btn-ghost { color: var(--color-text-secondary); background: transparent; border-color: var(--color-border); }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.full-width { width: 100%; margin-top: 12px; }
.page-state { padding: 48px 24px; color: var(--color-text-muted); font-size: 14px; }
.page-state.error, .banner.error { color: #fca5a5; }
.banner.warning { color: #fbbf24; border-color: rgba(251,191,36,0.22); background: rgba(251,191,36,0.08); }
.banner { margin-bottom: 12px; padding: 10px 12px; border-radius: 8px; border: 1px solid rgba(248,113,113,0.2); background: rgba(248,113,113,0.08); font-size: 12px; }
.variant-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(214px, 1fr)); gap: 14px; }
.variant-card { border: 1px solid var(--color-border); border-radius: 14px; overflow: hidden; background: rgba(14, 16, 24, 0.86); }
.variant-thumb { position: relative; min-height: 134px; display: flex; align-items: center; justify-content: center; }
.tone-a { background: linear-gradient(135deg, #0f2027, #2c5364); }
.tone-b { background: linear-gradient(135deg, #1a0a2e, #3d1a6e); }
.tone-c { background: linear-gradient(135deg, #2d1810, #4a2520); }
.tone-d { background: linear-gradient(135deg, #0a2818, #1a5c3a); }
.tone-e { background: linear-gradient(135deg, #28200a, #5c4a1a); }
.tone-f { background: linear-gradient(135deg, #1a1a3e, #3a1a5e); }
.variant-mini-phone { width: 64px; height: 112px; border: 2px solid rgba(255,255,255,0.15); border-radius: 12px; position: relative; }
.variant-mini-phone::before,
.variant-mini-phone::after { content: ""; position: absolute; left: 10px; right: 10px; border-radius: 999px; height: 4px; background: rgba(255,255,255,0.18); }
.variant-mini-phone::before { top: 36px; }
.variant-mini-phone::after { top: 48px; background: rgba(255, 107, 53, 0.7); }
.variant-status-pill { position: absolute; top: 10px; right: 10px; padding: 4px 8px; border-radius: 999px; font-size: 10px; font-family: "Space Mono", monospace; background: rgba(10, 10, 16, 0.45); border: 1px solid rgba(255,255,255,0.14); }
.variant-info { padding: 14px; }
.variant-label { font-size: 14px; font-weight: 600; }
.variant-diff { margin-top: 4px; font-size: 12px; color: var(--color-text-muted); min-height: 32px; }
.variant-footer { margin-top: 12px; display: flex; align-items: center; gap: 8px; }
.checkbox { width: 22px; height: 22px; border-radius: 6px; border: 1px solid var(--color-border-active); background: transparent; color: transparent; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
.checkbox.checked { background: rgba(255,107,53,0.14); color: var(--color-accent); border-color: rgba(255,107,53,0.35); }
.variant-footer-copy { font-size: 11px; color: var(--color-text-muted); }
.variant-actions { margin-top: 10px; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.variant-export-meta { margin-top: 8px; }
.variant-export-copy { display: inline-block; font-size: 11px; color: var(--color-text-muted); }
.localization-section { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--color-border); }
.compact-header { margin-bottom: 12px; }
.localization-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
.localization-card { position: relative; padding: 14px; border: 1px solid var(--color-border); border-radius: 12px; background: rgba(255,255,255,0.018); }
.localization-label { font-size: 14px; font-weight: 600; }
.localization-meta { margin-top: 4px; font-size: 11px; color: var(--color-text-muted); }
.localization-actions { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 8px; }
.localization-empty { color: var(--color-text-muted); font-size: 13px; padding: 10px 0; }
.inline-pill { position: static; display: inline-flex; margin-top: 12px; }
.status-rendered { color: #34d399; }
.status-ready_for_review { color: #60a5fa; }
.status-completed { color: #34d399; }
.status-queued, .status-generating, .status-pending, .status-translating, .status-dub_generating { color: #fbbf24; }
.status-failed { color: #f87171; }
.empty-state { padding: 56px 20px; text-align: center; }
.empty-title { font-size: 18px; font-weight: 600; }
.empty-copy { margin: 10px auto 18px; max-width: 360px; font-size: 13px; color: var(--color-text-muted); }
.side-section { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--color-border); }
.panel-label { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-text-muted); }
.detail-card { margin-top: 8px; padding: 12px; border-radius: 10px; background: rgba(255,255,255,0.02); border: 1px solid var(--color-border); }
.detail-title { font-size: 13px; font-weight: 600; }
.detail-meta { margin-top: 4px; font-size: 11px; color: var(--color-text-muted); }
.stats-list { margin-top: 10px; display: grid; gap: 8px; }
.language-chip-wrap { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.job-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12px; color: var(--color-text-secondary); }
.good { color: #34d399; }
.info { color: #60a5fa; }
.bad { color: #f87171; }
.warn { color: #fbbf24; }
.drawer-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.45); opacity: 0; pointer-events: none; transition: opacity 0.2s ease; z-index: 140; }
.drawer-backdrop.open { opacity: 1; pointer-events: auto; }
.drawer { position: fixed; top: 0; right: 0; height: 100vh; width: 380px; max-width: calc(100vw - 20px); background: var(--color-bg-panel); border-left: 1px solid var(--color-border); transform: translateX(100%); transition: transform 0.2s ease; z-index: 150; overflow-y: auto; }
.drawer.open { transform: translateX(0); }
.confirm-modal { position: fixed; inset: 50% auto auto 50%; transform: translate(-50%, -50%); width: min(420px, calc(100vw - 32px)); max-height: min(78vh, 760px); overflow: auto; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,0.4); padding: 18px; z-index: 170; }
.detail-modal { width: min(520px, calc(100vw - 32px)); max-height: min(78vh, 760px); }
.queue-detail-modal { position: fixed; inset: 50% auto auto 50%; transform: translate(-50%, -50%); width: min(780px, calc(100vw - 32px)); max-height: min(82vh, 900px); overflow: hidden; background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,0.4); z-index: 170; }
.confirm-title { font-size: 16px; font-weight: 600; }
.confirm-copy { margin-top: 10px; font-size: 13px; color: var(--color-text-muted); line-height: 1.5; }
.confirm-actions { margin-top: 16px; display: flex; justify-content: flex-end; gap: 10px; }
.detail-grid { margin-top: 14px; display: grid; gap: 10px; }
.detail-grid.compact { margin-top: 10px; }
.detail-line { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; font-size: 12px; color: var(--color-text-secondary); }
.detail-line span { color: var(--color-text-muted); }
.detail-line strong { color: var(--color-text-primary); text-align: right; max-width: 70%; line-height: 1.45; overflow-wrap: anywhere; }
.detail-line.full { display: grid; gap: 6px; }
.detail-line.full strong { text-align: left; }
.drawer-header, .drawer-footer { padding: 16px; border-bottom: 1px solid var(--color-border); display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.drawer-footer { border-bottom: none; border-top: 1px solid var(--color-border); position: sticky; bottom: 0; background: var(--color-bg-panel); }
.drawer-title { font-size: 16px; font-weight: 600; }
.drawer-close { font-size: 22px; color: var(--color-text-muted); line-height: 1; }
.drawer-body { padding: 16px; display: grid; gap: 16px; }
.queue-detail-body { padding: 16px; display: grid; gap: 12px; max-height: calc(82vh - 68px); overflow: auto; }
.queue-detail-row { padding: 14px; border-radius: 12px; border: 1px solid var(--color-border); background: rgba(255,255,255,0.02); }
.queue-detail-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.progress-track { margin-top: 10px; width: 100%; height: 6px; border-radius: 999px; background: rgba(255,255,255,0.08); overflow: hidden; }
.progress-fill-bar { height: 100%; border-radius: 999px; background: linear-gradient(90deg, #ff6b35, #34d399); }
.drawer-section { display: grid; gap: 10px; }
.drawer-section-label { font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--color-text-muted); }
.dim-row { padding: 12px; border-radius: 10px; border: 1px solid var(--color-border); background: rgba(255,255,255,0.02); }
.dim-main { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
.dim-label { font-size: 13px; font-weight: 600; }
.dim-desc { margin-top: 2px; font-size: 12px; color: var(--color-text-muted); }
.dim-sub, .chip-wrap, .dim-disabled-copy { margin-top: 10px; }
.sub-copy, .dim-disabled-copy { font-size: 11px; color: var(--color-text-muted); margin-bottom: 6px; }
.chip-wrap { display: flex; flex-wrap: wrap; gap: 6px; }
.chip {
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid rgba(126, 132, 158, 0.24);
  background: rgba(34, 36, 50, 0.72);
  color: rgba(201, 205, 226, 0.82);
  cursor: pointer;
  font-size: 12px;
  transition: 0.18s ease;
}
.chip:hover {
  border-color: rgba(162, 168, 197, 0.38);
  color: var(--color-text-primary);
}
.chip.selected {
  background: rgba(255, 107, 53, 0.16);
  border-color: rgba(255, 107, 53, 0.5);
  box-shadow: inset 0 0 0 1px rgba(255, 107, 53, 0.18);
  color: var(--color-accent);
}
.drawer-select { width: 100%; border-radius: 8px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); padding: 9px 12px; font-size: 13px; }
.lock-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 0; font-size: 13px; }
.lock-tag { padding: 4px 10px; border-radius: 999px; border: 1px solid var(--color-border); font-size: 11px; color: var(--color-text-muted); background: rgba(255,255,255,0.02); }
.lock-tag.locked { color: var(--color-accent); border-color: rgba(255,107,53,0.35); background: rgba(255,107,53,0.12); }
.batch-summary-box { padding: 12px 14px; border-radius: 10px; border: 1px solid rgba(255,107,53,0.18); background: rgba(255,107,53,0.08); font-size: 13px; color: var(--color-text-secondary); }
.batch-summary-box.warning { border-color: rgba(251,191,36,0.2); background: rgba(251,191,36,0.08); color: #fbbf24; }
.grow { flex: 1; }
@media (max-width: 1080px) {
  .page-grid { grid-template-columns: 1fr; }
  .control-surface { position: static; }
}
@media (max-width: 800px) {
  .main { margin-left: 0; }
  .topbar { flex-direction: column; align-items: flex-start; }
  .variants-page { padding: 16px; }
  .queue-detail-modal { width: calc(100vw - 20px); }
}
</style>
