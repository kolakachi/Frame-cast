<script setup>
import FinishedVideoPlayer from "../components/FinishedVideoPlayer.vue";
import AppSidebar from "../components/AppSidebar.vue";
import EditorSkeleton from "../components/skeletons/EditorSkeleton.vue";
import EditorTimeline from "../components/EditorTimeline.vue";
import MediaPickerModal from "../components/MediaPickerModal.vue";
import ExportUpdateModal from "../components/ExportUpdateModal.vue";
import SchedulePostModal from "../components/SchedulePostModal.vue";
import UiSelect from "../components/UiSelect.vue";
import VoiceCloneModal from "../components/VoiceCloneModal.vue";
import CaptionPreview from "../components/CaptionPreview.vue";
import UgcHeadline from "../components/UgcHeadline.vue";
import NotifBell from "../components/NotifBell.vue";
import { useEditorCore } from "../editor/useEditorCore";
import { chooseEditor } from "../editor/editorChoice";
import { useAuthStore } from "../stores/auth";

const {
  workspaceStore,
  router,
  projectId,
  loading,
  error,
  project,
  scenes,
  cruiseTab,
  cruiseAssistantPending,
  cruiseMessages,
  cruiseInputText,
  cruiseResolving,
  cruiseApplying,
  cruiseToast,
  cruiseAutoApply,
  cruisePrefs,
  cruisePrefsOpen,
  cruiseBrief,
  cruiseBriefLocked,
  cruiseBriefOpen,
  cruiseBriefBusy,
  cruiseResetConfirmOpen,
  cruiseResetting,
  CRUISE_QUICK_PROMPTS,
  cruiseUserBalance,
  cruiseScopeValue,
  cruiseActionIcon,
  cruiseActionTitle,
  cruiseSubmitIntent,
  cruiseSkipAction,
  cruiseSkipAll,
  cruiseApplyAction,
  cruiseApplyAll,
  cruiseProposedCount,
  cruiseTotalCost,
  cruiseCanApplyAll,
  cruiseStageLabel,
  cruiseToggleBrief,
  cruiseTogglePrefs,
  cruiseSaveBrief,
  cruiseRefreshBrief,
  cruiseResetConversation,
  cruiseConfirmReset,
  cruiseCanUndo,
  cruiseUndoAction,
  cruiseFriendlyError,
  cruiseRetryAction,
  cruisePulseClass,
  mePayload,
  creditCosts,
  aiMusicCost,
  isAdmin,
  activeSceneId,
  notificationDrawerOpen,
  notifications,
  notificationToasts,
  scheduleModalOpen,
  deliveryExport,
  approvalModalOpen,
  approvalForm,
  approvalSubmitting,
  approvalError,
  approvalCreated,
  submitApproval,
  closeApprovalModal,
  copyApprovalUrl,
  isAudioPlaying,
  isAudioLoading,
  musicReady,
  pendingScenePlay,
  auditionMusicTrackId,
  currentVisualUrl,
  visualLoadFailed,
  addScenePanelPosition,
  selectedSceneType,
  addSceneVisualMode,
  addSceneStockSubType,
  addSceneVisualStyle,
  addSceneCustomVisualStyle,
  addSceneVisualQuery,
  addScenePickedAsset,
  addSceneCharacterId,
  addSceneCharPopoverOpen,
  selectedSwapVisualSource,
  newSceneScript,
  rewriteToolsVisible,
  rewritePreviewVisible,
  rewritePreviewCopy,
  rewriteCustomInstruction,
  rewritePending,
  rewriteApplyPending,
  rewriteError,
  sceneDurationDraft,
  sceneDurationSaving,
  sceneDurationError,
  captionPresets,
  captionPresetSaveOpen,
  captionPresetSaveName,
  captionPresetSaving,
  captionPresetDeleting,
  voiceProfileSaveOpen,
  voiceProfileSaveName,
  voiceProfileSaving,
  voiceRegeneratePending,
  addSceneGeneratePending,
  addSceneGenerateError,
  sceneReorderPendingId,
  deleteScenePending,
  deleteSceneTarget,
  projectChannelId,
  projectBrandKitId,
  projectDefaultsSaveState,
  projectDefaultsSaveError,
  musicTracks,
  selectedMusicTrackId,
  customAudioAssets,
  customAudioSearch,
  customAudioLoading,
  voiceTab,
  voiceUploadStatus,
  voicePreviewUrl,
  voicePreviewName,
  voicePreviewPlaying,
  voicePreviewCurrent,
  voicePreviewDuration,
  voiceUploadAsset,
  voiceUploadError,
  voiceRecording,
  voiceRecordSeconds,
  mediaPickerVisible,
  mediaPickerMode,
  musicPanelTab,
  aiMusicMood,
  aiMusicPending,
  aiMusicError,
  AI_MUSIC_MOODS,
  regenerateAIMusic,
  musicVolume,
  musicDuckVolume,
  musicFadeInMs,
  musicLoop,
  musicDuckDuringVoice,
  musicSaveState,
  musicSaveError,
  sceneVoiceVolume,
  sceneSoundVolume,
  audiogramStyle,
  audiogramColor,
  audiogramBg,
  audiogramSaveState,
  AUDIOGRAM_STYLES,
  AUDIOGRAM_COLORS,
  AUDIOGRAM_BACKGROUNDS,
  voiceProfileKey,
  voiceSpeedDraft,
  voiceStabilityDraft,
  voiceDirectionDraft,
  voiceSaveState,
  visualQueryDraft,
  visualSwapPending,
  visualSwapError,
  aiImagePromptOverride,
  aiImagePending,
  aiImageModelKey,
  availableImageModelsEditor,
  activeImageModelMeta,
  activeSceneHasCharacter,
  aiImageError,
  animateModalOpen,
  animateModelOpen,
  animateTier,
  animateDuration,
  animateMotionPrompt,
  animateSubmitting,
  animateError,
  ANIMATE_TIER_COSTS_5S,
  animateQuality,
  lipsyncEngines,
  lipsyncEngine,
  ANIMATE_TIER_DURATIONS,
  activeSceneHasVoice,
  spokespersonOutdated,
  spokespersonConsent,
  needsSpokespersonConsent,
  animateDurations,
  animateQualityOptions,
  animateCost,
  ANIMATE_TIER_META,
  ANIMATE_TIER_ORDER,
  ANIMATE_PROMPT_SUGGESTIONS,
  characters,
  characterPopoverOpen,
  createCharacterOpen,
  createCharacterName,
  createCharacterDescription,
  createCharacterFile,
  createCharacterPreviewUrl,
  createCharacterSaving,
  createCharacterError,
  AI_IMAGE_STYLES,
  imageStylesByKey,
  stylePickerRows,
  stylePickerOpen,
  topAddStylePickerOpen,
  perSceneAddStyleOpenId,
  exportMenuOpen,
  exportAspectRatios,
  ASPECT_RATIO_OPTIONS,
  openExportMenu,
  toggleExportRatio,
  shareTogglePending,
  shareMenuOpen,
  shareAction,
  shareCopiedToast,
  timelineOpen,
  previewMode,
  stockVideoSubType,
  playProgress,
  isPreviewPlaying,
  musicMoodFilter,
  scriptSaveState,
  captionEnabledDraft,
  captionStyleDraft,
  captionHighlightDraft,
  captionPositionDraft,
  captionFontDraft,
  captionColorDraft,
  captionSizeDraft,
  captionHighlightColorDraft,
  captionAnimationDraft,
  captionHighlightStyleDraft,
  captionPanelColorDraft,
  captionEffectModalOpen,
  CAPTION_COLOR_SWATCHES,
  CAPTION_PANEL_SWATCHES,
  captionPanelRow,
  captionBackdropChecked,
  selectCaptionAnimation,
  captionPreviewSettings,
  captionPreviewTimedWords,
  captionClock,
  leaveSaveDialog,
  chooseLeave,
  captionBasePx,
  fontDropdownOpen,
  captionSaveState,
  CAPTION_FONT_GROUPS,
  motionEffectDraft,
  motionIntensityDraft,
  MOTION_EFFECT_OPTIONS,
  MOTION_INTENSITY_OPTIONS,
  VOICE_SPEED_OPTIONS,
  VOICE_STABILITY_OPTIONS,
  activeVoiceIsGemini,
  VOICE_PERSONAS,
  showVoiceModal,
  previewLoadingKey,
  previewPlayingKey,
  clonedVoices,
  geminiVoices,
  otherVoices,
  openVoiceModal,
  closeVoiceModal,
  previewVoice,
  selectVoice,
  applyPersona,
  showCloneModal,
  openCloneInEditor,
  onVoiceCloned,
  ADD_SCENE_STOCK_OPTIONS,
  STOCK_VIDEO_SUBTYPE_OPTIONS,
  CAPTION_HIGHLIGHT_OPTIONS,
  CAPTION_HIGHLIGHT_STYLE_OPTIONS,
  CAPTION_POSITION_OPTIONS,
  CRUISE_IMAGE_MODEL_OPTIONS,
  tierSlow,
  CRUISE_ANIMATION_TIER_OPTIONS,
  CRUISE_VISUAL_SOURCE_OPTIONS,
  APPROVAL_EXPIRES_OPTIONS,
  channelOptions,
  brandKitOptions,
  cruiseScopeOptions,
  captionPresetOptions,
  onApplyCaptionPreset,
  motionSaveState,
  motionSaveError,
  visualStyleDraft,
  visualStyleSaveState,
  customVisualStyleDraft,
  customVisualStyleSaveState,
  panelState,
  sceneScriptDraft,
  activeScene,
  activeSceneVisualAsset,
  activeSceneAIImagePending,
  activeSceneAnimationPending,
  activeSceneStillRendering,
  activeSceneAnimationError,
  canAnimateActiveScene,
  activeSceneAlreadyAnimated,
  activeSceneVisualGenerationError,
  activeSceneVisualIsVideo,
  previewVideoShortfall,
  activeSceneVisualIsFromLibrary,
  activeSceneAudioUrl,
  activeSceneSoundAsset,
  activeSceneSoundUrl,
  selectedProjectChannel,
  activeMusicTrack,
  activeMusicTrackUrl,
  auditionMusicTrack,
  customAudioAssetsFiltered,
  musicTrackGroups,
  isSceneLoading,
  showTextCardPreview,
  showWaveformPreview,
  waveformLive,
  mediaCrossOriginMode,
  audiogramBgStyle,
  activeVoiceName,
  activeVoiceSpeed,
  activeVoiceOutdated,
  sortedHookOptions,
  hooksGenerating,
  hasScriptForHooks,
  regenerateHooks,
  activeSceneIsStillImage,
  activeMotionClass,
  activeSceneIndex,
  mobileSheet,
  mobilePanelOpen,
  openPanel,
  toggleSheet,
  isPhone,
  previewContainerStyle,
  projectTitle,
  mobileStatus,
  editingTitle,
  titleDraft,
  startEditTitle,
  commitTitle,
  latestExportJob,
  exportsByRatio,
  selectedExportRatio,
  activeExportJob,
  exportFbOpen,
  exportFbRating,
  exportFbOptions,
  exportFbComment,
  exportFbSending,
  exportFbDone,
  exportFbChips,
  exportFbToggle,
  exportFbSubmit,
  exportChecks,
  exportCheckNotes,
  latestExportDownloadUrl,
  exportToolbar,
  exportWarning,
  requestExportAction,
  cancelExportAction,
  continueExportAction,
  updateExportAction,
  failedSceneCount,
  resumePending,
  resumeError,
  resumeFailedScenes,
  wholeVideoTake,
  exportBlockerMessage,
  activeSceneVisualError,
  activeSceneVoiceError,
  activeSceneScriptError,
  captionPreviewClass,
  captionsCanRender,
  captionPositionStyle,
  selectedCaptionFont,
  captionFontStyle,
  captionFamilyStyle,
  captionSizeIsCustom,
  sceneTypeOptions,
  REWRITE_EDIT_OPTIONS,
  REWRITE_TONE_OPTIONS,
  exportInProgress,
  sceneTypeLabel,
  sceneVisualLabel,
  sceneVoiceOutdated,
  pickAssetForNewScene,
  generateNewSceneDraft,
  scriptSaveCopy,
  voiceSaveCopy,
  formatSceneDuration,
  previewWords,
  fontFamilyValue,
  selectCaptionFont,
  totalVideoDuration,
  sceneBoundaryPcts,
  previewTimer,
  formatNotifTime,
  modelPickerOpen,
  activeImageModelLabel,
  generationFailed,
  generationFailureMessage,
  retrySourceDraft,
  retryPending,
  retryError,
  retrySourceEditable,
  retryGeneration,
  editHistory,
  historyStepping,
  stepHistory,
  setCruiseAutoApply,
  setCruisePref,
  cruiseUseQuickPrompt,
  handleVoiceFileUpload,
  confirmUploadFromPreview,
  applyVoiceUpload,
  toggleVoicePreviewPlay,
  onVoicePreviewTime,
  onVoicePreviewSeek,
  fmtVoicePreviewTime,
  cancelVoiceUpload,
  startVoiceRecording,
  stopVoiceRecording,
  loadCustomAudioAssets,
  assignCustomAudio,
  openMediaPicker,
  syncSceneVoiceVolume,
  scheduleSceneVoiceVolumeSave,
  scheduleSceneSoundVolumeSave,
  saveAudiogramSettings,
  selectAudiogramStyle,
  selectAudiogramColor,
  queueAudiogramColorSave,
  selectAudiogramBg,
  handleMediaPickerSelect,
  assignSceneSound,
  scheduleMusicSave,
  saveProjectDefaults,
  markNotificationRead,
  markAllRead,
  onPostScheduled,
  logout,
  selectScene,
  skipToScene,
  setPreviewMode,
  togglePreviewPlay,
  timelineSeek,
  timelineReorder,
  scrubberSeek,
  syncSceneVideoPreview,
  sceneVideoMetadataLoaded,
  syncPreviewMusicVolume,
  handleSceneAudioPlay,
  handleSceneAudioPause,
  moodGradient,
  moodEmoji,
  trackMoodLabel,
  selectMusicTrack,
  toggleMusicAudition,
  moodLabel,
  formatTrackDuration,
  togglePanel,
  toggleAddScene,
  closeAddScene,
  selectAddSceneVisualMode,
  applyCaptionPreset,
  saveCaptionPreset,
  deleteCaptionPreset,
  saveVoiceProfile,
  saveSceneDuration,
  toggleRewriteTools,
  submitRewrite,
  submitRewriteCustom,
  hideRewritePreview,
  acceptRewrite,
  regenerateVoiceForScene,
  regenerateVoice,
  applyVoiceAllState,
  applyVoiceAllError,
  bulkVoiceOpen,
  bulkVoicePreview,
  bulkVoiceLoading,
  bulkVoiceSubmitting,
  bulkVoiceError,
  openBulkVoice,
  submitBulkVoice,
  applyVoiceToAllScenes,
  saveUgcHeadline,
  applyAllState,
  applyCaptionsToAllScenes,
  activeCharacter,
  addSceneCharacter,
  selectAddSceneCharacter,
  selectCharacter,
  pickCharacterFile,
  clearCharacterFile,
  closeCharacterModal,
  createCharacter,
  hookScoreClass,
  useHook,
  swapVisual,
  bulkStyleOpen,
  bulkStylePreview,
  bulkStyleLoading,
  bulkStyleSubmitting,
  bulkStyleError,
  openBulkStyle,
  submitBulkStyle,
  editInstruction,
  editPending,
  editError,
  EDIT_SUGGESTIONS,
  editSceneImage,
  generateAIImage,
  openAnimateModal,
  closeAnimateModal,
  canRevertAnimation,
  activeSceneAnimationHistory,
  activeSceneAnimationOriginal,
  useOriginalStill,
  useHistoryAnimation,
  cancelAnimation,
  revertAnimation,
  bulkAnimateMode,
  bulkAnimatePreview,
  bulkAnimateLoading,
  bulkAnimateSelection,
  bulkAnimateSourceId,
  bulkAnimateRows,
  bulkAnimateFirstLoad,
  bulkAnimateSelectableCount,
  bulkAnimateReplacedCount,
  toggleBulkScene,
  bulkAnimateSelectAll,
  toggleBulkAnimate,
  submitBulkAnimate,
  submitAnimate,
  lipSyncRegenerating,
  regenerateLipSync,
  queueExport,
  createScene,
  duplicateScene,
  promptDeleteScene,
  closeDeleteSceneModal,
  confirmDeleteScene,
  moveScene,
  toggleAudioPlayback,
  syncSceneSoundVolume,
  voiceDescription,
  CAPTION_ANIMATIONS,
  flushActiveSceneDrafts,
} = useEditorCore();

const authStore = useAuthStore();
async function tryNewEditor() {
  if ((await flushActiveSceneDrafts()) === false) return;
  chooseEditor("new");
}
</script>

<template>
  <main class="editor-page">
    <div v-if="leaveSaveDialog" class="leave-save-overlay" @keydown.esc="chooseLeave(false)" role="dialog" aria-modal="true" aria-labelledby="leave-save-title">
      <div class="leave-save-card">
        <h2 id="leave-save-title">Some changes could not be saved</h2>
        <p>Stay here to retry saving, or leave and discard any unsaved changes.</p>
        <button ref="stayInEditorButton" class="btn btn-primary" @click="chooseLeave(false)">Stay in editor</button>
        <button class="btn btn-ghost" @click="chooseLeave(true)">Leave without saving</button>
      </div>
    </div>
    <EditorSkeleton v-if="loading" />
    <section v-else-if="error" class="state-card error">{{ error }}</section>

    <!-- Failed before any scenes existed: recovery, not a dead editor. -->
    <section v-else-if="generationFailed" class="state-card gen-failed">
      <div class="gf-icon">⚠️</div>
      <h2 class="gf-title">This video couldn't be generated</h2>
      <p class="gf-reason">{{ generationFailureMessage }}</p>
      <p class="gf-note">Nothing was charged for the failed attempt.</p>
      <p v-if="project?.source_type === 'pdf_upload'" class="gf-note">
        Scanned or image-only PDFs can be read with AI — {{ creditCosts.pdf_vision_page ?? 1 }} credit per page-sized
        section, shown in your balance before anything runs.
      </p>

      <div v-if="retrySourceEditable" class="gf-source">
        <label class="gf-label">
          {{ project?.source_type === 'url' ? 'Source link — fix it or paste a different one' : 'Your source — edit it before retrying' }}
        </label>
        <textarea
          v-model="retrySourceDraft"
          class="gf-input"
          :rows="project?.source_type === 'url' ? 2 : 5"
          :placeholder="project?.source_type === 'url' ? 'https://…' : 'Describe what the video should be about'"
        ></textarea>
        <div v-if="project?.source_type === 'url'" class="gf-hint">
          Instagram, TikTok and other login-only pages can't be read automatically —
          paste the text or an open article link instead.
        </div>
      </div>

      <div v-if="retryError" class="gf-error">{{ retryError }}</div>

      <div class="gf-actions">
        <!-- Scanned-PDF rescue: the most common PDF failure is an image-only
             document, and the fix is one consent away. Priced per A4-sized
             section, capped by plan, refunded per failed section. -->
        <button
          v-if="project?.source_type === 'pdf_upload'"
          class="btn btn-primary" type="button" :disabled="retryPending"
          @click="retryGeneration({ readScanned: true })"
        >
          {{ retryPending ? 'Retrying…' : '📖 Read it with AI & retry' }}
        </button>
        <button v-else class="btn btn-primary" type="button" :disabled="retryPending" @click="retryGeneration">
          {{ retryPending ? 'Retrying…' : '↻ Try again' }}
        </button>
        <router-link class="btn btn-ghost" :to="{ name: 'dashboard' }">Start a different video</router-link>
      </div>
    </section>

    <section v-else-if="wholeVideoTake" class="state-card" style="max-width: 800px; margin: 40px auto;">
      <h1>{{ projectTitle }}</h1>
      <p>This take contains baked-in picture and dialogue. Scene edits, captions and alternate export ratios are not supported here. Return to the creation flow to revise the take.</p>
      <FinishedVideoPlayer v-if="currentVisualUrl" :src="currentVisualUrl" />
      <button class="btn btn-primary" @click="router.push(`/ugc-ads/review/${project.id}`)">Open take review</button>
      <button class="btn btn-ghost" @click="router.push({ name: 'dashboard' })">Back to dashboard</button>
    </section>
    <div v-else class="editor-shell" :inert="leaveSaveDialog">
      <AppSidebar :user="mePayload" active-page="editor" @logout="logout" />

      <div :class="['main', timelineOpen ? 'sidebar-collapsed' : '']">
        <!-- Phone bar, from mobile-editor-v3.html: back, project, status,
             credits, and the ⋮ that opens everything this screen can't fit.
             The app's generic mobile bar stands down on these routes. -->
        <header v-if="isPhone" class="ed-bar">
          <button class="ed-ib" type="button" aria-label="Back to dashboard" @click="router.push({ name: 'dashboard' })">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7">
              <path d="M12.5 4L6.5 10l6 6" />
            </svg>
          </button>
          <span class="ed-bar-title">{{ projectTitle }}</span>
          <span v-if="mobileStatus" :class="['ed-bar-status', `is-${mobileStatus.tone}`]">{{ mobileStatus.text }}</span>
          <span class="ed-bar-sp"></span>
          <span v-if="workspaceStore.usage?.credits_balance != null" class="ed-bar-credits">
            {{ workspaceStore.usage.credits_balance.toLocaleString() }}
          </span>
          <button
            :class="['ed-ib', mobileSheet === 'project' ? 'is-on' : '']"
            type="button"
            aria-label="Project actions"
            @click="toggleSheet('project')"
          >
            <svg viewBox="0 0 20 20" fill="currentColor">
              <circle cx="10" cy="4.4" r="1.5" /><circle cx="10" cy="10" r="1.5" /><circle cx="10" cy="15.6" r="1.5" />
            </svg>
          </button>
        </header>

        <header class="topbar">
          <div class="topbar-left">
            <div class="topbar-title">Editor</div>
          </div>

          <div class="topbar-right">
            <div
              v-if="latestExportJob"
              :class="['export-pill', `export-pill-${latestExportJob.status}`, exportToolbar.stale ? 'export-pill-stale' : '']"
            >
              {{ exportToolbar.status }}
              <span
                v-if="latestExportJob.status === 'failed' && latestExportJob.failure_reason"
                class="export-fail-info"
                tabindex="0"
              >
                <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-8-5a1 1 0 0 0-1 1v4a1 1 0 1 0 2 0V6a1 1 0 0 0-1-1Zm0 8a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd"/></svg>
                <span class="export-fail-tooltip">{{ latestExportJob.failure_reason }}</span>
              </span>
            </div>
            <!-- Undo / redo (2026-10-10): the project's shared edit history. -->
            <div class="history-btns" role="group" aria-label="Undo and redo">
              <button type="button" class="btn btn-ghost history-btn" :disabled="!editHistory.can_undo || historyStepping"
                :title="editHistory.can_undo ? `Undo: ${editHistory.undo_label} (⌘Z)` : (editHistory.busy ? 'Wait for generation to finish' : 'Nothing to undo')"
                aria-label="Undo" @click="stepHistory('undo')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg>
              </button>
              <button type="button" class="btn btn-ghost history-btn" :disabled="!editHistory.can_redo || historyStepping"
                :title="editHistory.can_redo ? `Redo: ${editHistory.redo_label} (⇧⌘Z)` : 'Nothing to redo'"
                aria-label="Redo" @click="stepHistory('redo')">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/></svg>
              </button>
            </div>
            <!-- Share (owner, 2026-10-10): one button; its menu picks the aspect ratio, then Open, Download, Schedule,
                 Send for approval and Copy share link act on that ratio's file. -->
            <div v-if="activeExportJob?.status === 'completed' && latestExportDownloadUrl" ref="shareWrap" class="share-wrap">
              <button :class="['btn btn-ghost share-btn', shareMenuOpen ? 'active' : '']" type="button" aria-haspopup="menu" :aria-expanded="shareMenuOpen" @click="shareMenuOpen = !shareMenuOpen">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M16 6l-4-4-4 4"/><path d="M12 2v13"/></svg>
                Share <span class="share-caret">▾</span>
                <span v-if="exportCheckNotes.length" class="export-check-dot" :title="`${exportCheckNotes.length} thing${exportCheckNotes.length === 1 ? '' : 's'} to check`"></span>
              </button>
              <div v-if="shareMenuOpen" class="share-menu" role="menu">
                <template v-if="exportChecks.length">
                  <div class="share-menu-label">Checks</div>
                  <div v-if="!exportCheckNotes.length" class="export-check ok">✓ Sound, pictures, narration and length all checked</div>
                  <div v-for="c in exportCheckNotes" :key="c.id" class="export-check note"><b>{{ c.label }}:</b> {{ c.detail }}</div>
                  <div class="share-menu-sep"></div>
                </template>
                <div class="share-menu-label">Aspect ratio</div>
                <div class="share-ratios">
                  <button
                    v-for="job in (exportsByRatio.length ? exportsByRatio : [activeExportJob])" :key="job.id" type="button" role="menuitemradio"
                    :aria-checked="job.id === activeExportJob?.id"
                    :class="['share-ratio', job.id === activeExportJob?.id ? 'on' : '']"
                    @click="selectedExportRatio = job.aspect_ratio"
                  >
                    <b>{{ job.aspect_ratio }}</b>
                    <span>{{ ASPECT_RATIO_OPTIONS.find((o) => o.value === job.aspect_ratio)?.sub || '' }}</span>
                    <i v-if="job.id === activeExportJob?.id">✓</i>
                  </button>
                </div>
                <div class="share-menu-sep"></div>
                <button type="button" role="menuitem" class="share-item" @click="shareAction('open')"><span>↗</span>Open in new tab</button>
                <button type="button" role="menuitem" class="share-item" @click="shareAction('download')"><span>↓</span>Download MP4</button>
                <button type="button" role="menuitem" class="share-item" @click="shareAction('schedule')"><span>📅</span>Schedule</button>
                <button type="button" role="menuitem" class="share-item" @click="shareAction('approval')"><span>📝</span>Send for approval</button>
                <button type="button" role="menuitem" class="share-item" :disabled="shareTogglePending" @click="shareAction('share')">
                  <span>🔗</span>{{ shareTogglePending ? 'Creating link…' : shareCopiedToast === 'Copied!' ? 'Link copied' : 'Copy share link' }}
                </button>
                <div v-if="shareCopiedToast && shareCopiedToast !== 'Copied!'" class="share-menu-url">{{ shareCopiedToast }}</div>
              </div>
            </div>
            <!-- Phone only: the way into the settings sheet. Desktop has the
                 panel on screen permanently and needs no button. -->
            <!-- Hidden (owner, 2026-10-10): the timeline editor stays in the code; the button is off for now. -->
            <button v-if="false" :class="['btn btn-ghost btn-timeline-toggle', timelineOpen ? 'active' : '']" type="button" @click="timelineOpen = !timelineOpen">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="4" rx="1"/><rect x="3" y="10" width="11" height="4" rx="1"/><rect x="3" y="17" width="15" height="4" rx="1"/></svg>
              Timeline
            </button>
            <!-- The redesigned editor, staff only until it opens (2026-10-29). -->
            <button v-if="authStore.user?.editor_new_available" class="btn btn-ghost btn-back" type="button" @click="tryNewEditor">
              Try the new editor
            </button>
            <button class="btn btn-ghost btn-back" type="button" @click="router.push({ name: 'project-variants', params: { projectId: projectId } })">
              Variants
            </button>

            <div class="export-btn-wrap" style="position:relative">
              <button
                :class="['btn', exportToolbar.primary ? 'btn-primary' : 'btn-ghost']"
                type="button"
                :disabled="exportInProgress || !!exportBlockerMessage"
                :title="exportToolbar.hint"
                @click="openExportMenu"
              >
                {{ exportInProgress ? "Finishing…" : exportToolbar.action }}
              </button>
              <div v-if="exportMenuOpen" class="export-format-menu" style="position:absolute;right:0;top:calc(100% + 8px);z-index:60;width:264px;background:var(--surface,#15151b);border:1px solid var(--border,#2a2a33);border-radius:12px;box-shadow:0 24px 64px rgba(0,0,0,.55);padding:14px;text-align:left">
                <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim,#9a9aa5);margin-bottom:10px">Export formats</div>
                <label v-for="opt in ASPECT_RATIO_OPTIONS" :key="opt.value" style="display:flex;align-items:center;gap:10px;padding:7px 2px;cursor:pointer;font-size:14px">
                  <input type="checkbox" :checked="exportAspectRatios.includes(opt.value)" @change="toggleExportRatio(opt.value)" style="accent-color:var(--accent,#ff6b35);width:15px;height:15px" />
                  <span style="font-weight:600;min-width:38px">{{ opt.label }}</span>
                  <span style="color:var(--text-dim,#9a9aa5);font-size:12px">{{ opt.sub }}</span>
                </label>
                <div style="font-size:11.5px;color:var(--text-faint,#6b6b75);margin:8px 2px 10px;line-height:1.4">Each format is a separate render of the same video — no extra credits.</div>
                <button class="btn btn-primary btn-full" type="button" :disabled="!exportAspectRatios.length || exportInProgress" @click="queueExport">
                  Export {{ exportAspectRatios.length }} format{{ exportAspectRatios.length === 1 ? '' : 's' }}
                </button>
              </div>
            </div>
            <button class="btn btn-ghost btn-back" type="button" @click="router.push({ name: 'dashboard' })">
              Back to Dashboard
            </button>
            <NotifBell />
          </div>
        </header>

        <!-- Resume-failed banner — only when 1+ scenes are in a broken state.
             Shows count + click-to-retry; surfaces credit shortage inline. -->
        <div v-if="failedSceneCount > 0" class="resume-failed-banner">
          <div class="resume-failed-msg">
            <span class="resume-failed-icon">⚠</span>
            <div>
              <strong>{{ failedSceneCount }} scene{{ failedSceneCount === 1 ? '' : 's' }} failed</strong> to finish.
              {{ resumeError || 'You can re-run them in one click. Costs ~' + (failedSceneCount * 75) + ' credits at Quick tier.' }}
            </div>
          </div>
          <button
            class="btn btn-primary btn-sm"
            type="button"
            :disabled="resumePending"
            @click="resumeFailedScenes"
          >
            {{ resumePending ? 'Resuming…' : '↻ Resume failed' }}
          </button>
        </div>

        <div class="editor-body">
        <div class="editor active">
          <div :class="['editor-sidebar', mobileSheet === 'scenes' ? 'sheet-open' : '']">
            <div class="editor-sidebar-header">
              <div class="editor-sidebar-title">Scenes</div>
              <!-- The sheet covers the dock while open, so it carries its own
                   way out rather than relying on the scrim alone. -->
              <button
                v-if="isPhone"
                class="editor-sheet-done"
                type="button"
                @click="mobileSheet = null"
              >Done</button>
              <button
                class="btn btn-ghost btn-sm"
                type="button"
                @click="toggleAddScene('top')"
              >
                + Add
              </button>
            </div>

            <div class="scene-list">
              <div
                :class="`add-scene-panel ${
                  addScenePanelPosition === 'top' ? 'open' : ''
                }`"
              >
                <div class="panel-title">
                  Add New Scene
                  <span class="close-x" @click="closeAddScene">×</span>
                </div>
                <div class="micro-label">Scene type</div>
                <div class="scene-type-chips">
                  <div
                    v-for="option in sceneTypeOptions"
                    :key="option"
                    :class="`scene-type-chip ${
                      selectedSceneType === option ? 'selected' : ''
                    }`"
                    @click="selectedSceneType = option"
                  >
                    {{ option }}
                  </div>
                </div>
                <textarea
                  v-model="newSceneScript"
                  class="add-scene-textarea"
                  placeholder="Write your scene script, or click 'AI Generate' to create one..."
                ></textarea>
                <div class="add-scene-visual-source">
                  <div class="visual-type-tabs add-scene-tabs">
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_video' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_video')">Video</button>
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_image' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_image')">Image</button>
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'ai_image' ? 'active ai' : '']" @click="selectAddSceneVisualMode('ai_image')">✦ AI</button>
                    <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'assets' ? 'active' : '']" @click="selectAddSceneVisualMode('assets')">Assets</button>
                  </div>

                  <template v-if="addSceneVisualMode === 'stock_video'">
                    <div class="control-row add-scene-control-row">
                      <span class="control-name">Type</span>
                      <UiSelect v-model="addSceneStockSubType" :options="ADD_SCENE_STOCK_OPTIONS" />
                    </div>
                    <div class="scene-query-label">Search query</div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'abandoned mansion at night' — leave blank to use scene script"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'stock_image'">
                    <div class="scene-query-label">Search query</div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'ai_image'">
                    <!-- Reference character — mirrors the chip+thumbnail-grid
                         picker on the right-hand scene panel so the UX is
                         consistent across "edit a scene" and "add a scene".
                         Sits above Style because picking a character is the
                         first decision when a project has saved cast. -->
                    <div class="char-chip-wrap" @click.stop>
                      <div class="char-chip" @click="addSceneCharPopoverOpen = !addSceneCharPopoverOpen">
                        <div class="char-chip-thumb">
                          <img v-if="addSceneCharacter?.reference_asset?.thumbnail_url || addSceneCharacter?.reference_asset?.storage_url"
                               :src="addSceneCharacter.reference_asset.thumbnail_url || addSceneCharacter.reference_asset.storage_url" alt="" />
                          <span v-else-if="addSceneCharacter">{{ addSceneCharacter.name.charAt(0).toUpperCase() }}</span>
                          <span v-else style="opacity:.5;">◐</span>
                        </div>
                        <div class="char-chip-text">
                          <div class="char-chip-name">{{ addSceneCharacter?.name || 'No character' }}</div>
                          <div class="char-chip-trail">{{ addSceneCharacter ? 'Bound to this scene' : 'Tap to bind a character' }}</div>
                        </div>
                        <span class="char-chip-chev">▾</span>
                      </div>
                      <div v-if="addSceneCharPopoverOpen" class="char-popover">
                        <div v-if="characters.length === 0" class="char-popover-empty">
                          No characters yet. Create your first one.
                        </div>
                        <div v-else class="char-popover-grid">
                          <div
                            v-for="c in characters"
                            :key="`top-add-char-${c.id}`"
                            :class="['char-popover-item', addSceneCharacterId === c.id ? 'selected' : '']"
                            @click="selectAddSceneCharacter(c.id)"
                          >
                            <div class="char-popover-thumb">
                              <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url"
                                   :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
                              <span v-else>{{ c.name.charAt(0).toUpperCase() }}</span>
                            </div>
                            <div class="char-popover-name">{{ c.name }}</div>
                            <div class="char-popover-trail">{{ c.scenes_count > 0 ? `${c.scenes_count}${c.scenes_count === 1 ? ' scene' : ' scenes'}` : 'new' }}</div>
                          </div>
                        </div>
                        <div class="char-popover-foot">
                          <button v-if="addSceneCharacterId" class="char-popover-none" type="button" @click="selectAddSceneCharacter(null)">Remove</button>
                          <span v-else></span>
                          <button class="char-popover-new" type="button" @click="addSceneCharPopoverOpen = false; createCharacterOpen = true;">＋ New character</button>
                        </div>
                      </div>
                    </div>

                    <div class="micro-label" style="margin:10px 0 6px;">Style</div>
                    <!-- Custom row-style dropdown, matches the main editor's
                         style picker so the editor reads as one consistent UI. -->
                    <div class="picker-wrap">
                      <button type="button" class="picker-trigger" @click="topAddStylePickerOpen = !topAddStylePickerOpen">
                        <img v-if="imageStylesByKey.get(addSceneVisualStyle)?.sample_url" :src="imageStylesByKey.get(addSceneVisualStyle).sample_url" class="picker-trigger-thumb" alt="" />
                        <span v-else class="picker-trigger-glyph">{{ imageStylesByKey.get(addSceneVisualStyle)?.icon ?? '✦' }}</span>
                        <span class="picker-trigger-label">{{ imageStylesByKey.get(addSceneVisualStyle)?.label ?? 'Pick a style' }}</span>
                        <span class="picker-trigger-caret">▾</span>
                      </button>
                      <div v-if="topAddStylePickerOpen" class="picker-panel">
                        <div v-for="s in stylePickerRows" :key="`top-add-style-${s.key}`"
                          :class="['picker-row', addSceneVisualStyle === s.key ? 'selected' : '']"
                          @click="addSceneVisualStyle = s.key; topAddStylePickerOpen = false">
                          <img v-if="s.sample_url" :src="s.sample_url" :alt="s.label" class="picker-row-thumb" />
                          <span v-else class="picker-row-glyph">{{ s.icon ?? '✦' }}</span>
                          <div class="picker-row-text">
                            <div class="picker-row-name">{{ s.label }}</div>
                            <div v-if="s.description" class="picker-row-desc">{{ s.description }}</div>
                          </div>
                          <span v-if="addSceneVisualStyle === s.key" class="picker-row-check">✓</span>
                        </div>
                      </div>
                    </div>
                    <div v-if="addSceneVisualStyle === 'custom'" class="custom-style-panel">
                      <div class="micro-label" style="margin-bottom:4px;">Custom style descriptor</div>
                      <textarea
                        v-model="addSceneCustomVisualStyle"
                        class="scene-query-input"
                        rows="2"
                        maxlength="500"
                        placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                      ></textarea>
                      <div style="font-size:11px;opacity:.55;margin-top:4px;line-height:1.5;">
                        Leave blank to inherit from the project default.
                      </div>
                    </div>

                    <div class="scene-query-label" style="margin-top:10px;">Prompt override <span style="opacity:.5;font-weight:400;">(optional)</span></div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" maxlength="1000" placeholder="Leave blank to use scene script as the generation prompt"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'assets'">
                    <div v-if="addScenePickedAsset" class="add-scene-asset-preview">
                      <img v-if="addScenePickedAsset.thumbnail_url || (addScenePickedAsset.asset_type === 'image' && addScenePickedAsset.storage_url)" :src="addScenePickedAsset.thumbnail_url || addScenePickedAsset.storage_url" :alt="addScenePickedAsset.title" />
                      <div class="add-scene-asset-meta">
                        <div class="add-scene-asset-title">{{ addScenePickedAsset.title || 'Selected asset' }}</div>
                        <div class="add-scene-asset-type">{{ addScenePickedAsset.asset_type || 'asset' }}</div>
                      </div>
                      <button class="btn btn-ghost btn-sm" type="button" @click="pickAssetForNewScene">Replace</button>
                    </div>
                    <button v-else class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="pickAssetForNewScene">📁 Pick from your library</button>
                  </template>
                </div>
                <div class="add-scene-actions">
                  <button
                    class="btn btn-ghost btn-sm"
                    type="button"
                    @click="closeAddScene"
                  >
                    Cancel
                  </button>
                  <button class="btn btn-ghost btn-sm purple-btn" type="button" :disabled="addSceneGeneratePending" @click="generateNewSceneDraft">
                    {{ addSceneGeneratePending ? "Generating..." : "✦ AI Generate" }}
                  </button>
                  <button
                    class="btn btn-primary btn-sm"
                    type="button"
                    @click="createScene()"
                  >
                    Add Scene
                  </button>
                </div>
              </div>

              <!-- eslint-disable-next-line vue/no-v-for-template-key -->
              <template v-for="(scene, index) in scenes" :key="scene.id">
                <div
                  :id="`scene-item-${scene.id}`"
                  :class="`scene-item ${
                    activeSceneId === scene.id ? 'active' : ''
                  }`"
                  role="button"
                  tabindex="0"
                  @click="selectScene(scene.id)"
                  @keydown.enter.prevent="selectScene(scene.id)"
                  @keydown.space.prevent="selectScene(scene.id)"
                >
                  <div class="scene-number">
                    Scene {{ scene.scene_order }}
                    <span> · {{ sceneTypeLabel(scene) }}</span>
                    <span :class="sceneVoiceOutdated(scene) ? 'inline-warn' : 'inline-warn state-hidden'">
                      Voice outdated
                    </span>
                  </div>
                  <div class="scene-text">{{ scene.script_text }}</div>
                  <div v-if="sceneVoiceOutdated(scene)" class="scene-regen-row" @click.stop>
                    <button
                      class="scene-regen-btn"
                      type="button"
                      :disabled="voiceRegeneratePending && activeSceneId === scene.id"
                      @click="regenerateVoiceForScene(scene.id)"
                    >
                      {{ voiceRegeneratePending && activeSceneId === scene.id ? '↺ Regenerating…' : '↺ Regenerate voice' }}
                    </button>
                  </div>
                  <div class="scene-meta">
                    <span class="scene-tag">{{ sceneVisualLabel(scene) }}</span>
                    <span v-if="scene.visual_style" class="scene-style-badge">{{ scene.visual_style }}</span>
                    <span>{{
                      formatSceneDuration(scene.duration_seconds)
                    }}</span>
                  </div>
                  <div class="scene-actions" @click.stop>
                    <button
                      class="scene-action-btn"
                      type="button"
                      :disabled="index === 0 || sceneReorderPendingId !== null"
                      @click="moveScene(scene.id, 'up')"
                    >
                      {{ sceneReorderPendingId === scene.id ? "…" : "↑" }}
                    </button>
                    <button
                      class="scene-action-btn"
                      type="button"
                      :disabled="index === scenes.length - 1 || sceneReorderPendingId !== null"
                      @click="moveScene(scene.id, 'down')"
                    >
                      {{ sceneReorderPendingId === scene.id ? "…" : "↓" }}
                    </button>
                    <button
                      class="scene-action-btn"
                      type="button"
                      @click="duplicateScene(scene.id)"
                    >
                      Duplicate
                    </button>
                    <button
                      class="scene-action-btn danger"
                      type="button"
                      :disabled="sceneReorderPendingId !== null || deleteScenePending"
                      @click="promptDeleteScene(scene.id)"
                    >
                      Delete
                    </button>
                  </div>
                </div>

                <div
                  :class="`add-scene-divider ${
                    index === scenes.length - 1 ? 'always-visible' : ''
                  }`"
                >
                  <div
                    class="add-scene-trigger"
                    @click="toggleAddScene(`after-${scene.id}`)"
                  >
                    <svg
                      width="12"
                      height="12"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      viewBox="0 0 24 24"
                    >
                      <path d="M12 5v14M5 12h14"></path>
                    </svg>
                    Insert
                  </div>
                </div>

                <div
                  :class="`add-scene-panel ${
                    addScenePanelPosition === `after-${scene.id}` ? 'open' : ''
                  }`"
                >
                  <div class="panel-title">
                    Add New Scene
                    <span class="close-x" @click="closeAddScene">×</span>
                  </div>
                  <div class="micro-label">Scene type</div>
                  <div class="scene-type-chips">
                    <div
                      v-for="option in sceneTypeOptions"
                      :key="`${scene.id}-${option}`"
                      :class="`scene-type-chip ${
                        selectedSceneType === option ? 'selected' : ''
                      }`"
                      @click="selectedSceneType = option"
                    >
                      {{ option }}
                    </div>
                  </div>
                  <textarea
                    v-model="newSceneScript"
                    class="add-scene-textarea"
                    placeholder="Write your scene script, or click 'AI Generate' to create one..."
                  ></textarea>
                  <div class="add-scene-visual-source">
                    <div class="visual-type-tabs add-scene-tabs">
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_video' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_video')">Video</button>
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'stock_image' ? 'active' : '']" @click="selectAddSceneVisualMode('stock_image')">Image</button>
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'ai_image' ? 'active ai' : '']" @click="selectAddSceneVisualMode('ai_image')">✦ AI</button>
                      <button type="button" :class="['visual-type-tab', addSceneVisualMode === 'assets' ? 'active' : '']" @click="selectAddSceneVisualMode('assets')">Assets</button>
                    </div>

                    <template v-if="addSceneVisualMode === 'stock_video'">
                      <div class="control-row add-scene-control-row">
                        <span class="control-name">Type</span>
                        <UiSelect v-model="addSceneStockSubType" :options="ADD_SCENE_STOCK_OPTIONS" />
                      </div>
                      <div class="scene-query-label">Search query</div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'abandoned mansion at night' — leave blank to use scene script"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'stock_image'">
                      <div class="scene-query-label">Search query</div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'ai_image'">
                      <!-- Reference character — chip + thumbnail grid, identical
                           to the right-hand scene panel and the top add-scene
                           popover. Sits above Style on purpose. -->
                      <div class="char-chip-wrap" @click.stop>
                        <div class="char-chip" @click="addSceneCharPopoverOpen = !addSceneCharPopoverOpen">
                          <div class="char-chip-thumb">
                            <img v-if="addSceneCharacter?.reference_asset?.thumbnail_url || addSceneCharacter?.reference_asset?.storage_url"
                                 :src="addSceneCharacter.reference_asset.thumbnail_url || addSceneCharacter.reference_asset.storage_url" alt="" />
                            <span v-else-if="addSceneCharacter">{{ addSceneCharacter.name.charAt(0).toUpperCase() }}</span>
                            <span v-else style="opacity:.5;">◐</span>
                          </div>
                          <div class="char-chip-text">
                            <div class="char-chip-name">{{ addSceneCharacter?.name || 'No character' }}</div>
                            <div class="char-chip-trail">{{ addSceneCharacter ? 'Bound to this scene' : 'Tap to bind a character' }}</div>
                          </div>
                          <span class="char-chip-chev">▾</span>
                        </div>
                        <div v-if="addSceneCharPopoverOpen" class="char-popover">
                          <div v-if="characters.length === 0" class="char-popover-empty">
                            No characters yet. Create your first one.
                          </div>
                          <div v-else class="char-popover-grid">
                            <div
                              v-for="c in characters"
                              :key="`add-char-${scene.id}-${c.id}`"
                              :class="['char-popover-item', addSceneCharacterId === c.id ? 'selected' : '']"
                              @click="selectAddSceneCharacter(c.id)"
                            >
                              <div class="char-popover-thumb">
                                <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url"
                                     :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
                                <span v-else>{{ c.name.charAt(0).toUpperCase() }}</span>
                              </div>
                              <div class="char-popover-name">{{ c.name }}</div>
                              <div class="char-popover-trail">{{ c.scenes_count > 0 ? `${c.scenes_count}${c.scenes_count === 1 ? ' scene' : ' scenes'}` : 'new' }}</div>
                            </div>
                          </div>
                          <div class="char-popover-foot">
                            <button v-if="addSceneCharacterId" class="char-popover-none" type="button" @click="selectAddSceneCharacter(null)">Remove</button>
                            <span v-else></span>
                            <button class="char-popover-new" type="button" @click="addSceneCharPopoverOpen = false; createCharacterOpen = true;">＋ New character</button>
                          </div>
                        </div>
                      </div>

                      <div class="micro-label" style="margin:10px 0 6px;">Style</div>
                      <!-- Dropdown variant — same as the main editor picker. -->
                      <div class="picker-wrap">
                        <button type="button" class="picker-trigger" @click="perSceneAddStyleOpenId = perSceneAddStyleOpenId === scene.id ? null : scene.id">
                          <img v-if="imageStylesByKey.get(addSceneVisualStyle)?.sample_url" :src="imageStylesByKey.get(addSceneVisualStyle).sample_url" class="picker-trigger-thumb" alt="" />
                          <span v-else class="picker-trigger-glyph">{{ imageStylesByKey.get(addSceneVisualStyle)?.icon ?? '✦' }}</span>
                          <span class="picker-trigger-label">{{ imageStylesByKey.get(addSceneVisualStyle)?.label ?? 'Pick a style' }}</span>
                          <span class="picker-trigger-caret">▾</span>
                        </button>
                        <div v-if="perSceneAddStyleOpenId === scene.id" class="picker-panel">
                          <div v-for="s in stylePickerRows" :key="`add-style-${scene.id}-${s.key}`"
                            :class="['picker-row', addSceneVisualStyle === s.key ? 'selected' : '']"
                            @click="addSceneVisualStyle = s.key; perSceneAddStyleOpenId = null">
                            <img v-if="s.sample_url" :src="s.sample_url" :alt="s.label" class="picker-row-thumb" />
                            <span v-else class="picker-row-glyph">{{ s.icon ?? '✦' }}</span>
                            <div class="picker-row-text">
                              <div class="picker-row-name">{{ s.label }}</div>
                              <div v-if="s.description" class="picker-row-desc">{{ s.description }}</div>
                            </div>
                            <span v-if="addSceneVisualStyle === s.key" class="picker-row-check">✓</span>
                          </div>
                        </div>
                      </div>
                      <div v-if="addSceneVisualStyle === 'custom'" class="custom-style-panel">
                        <div class="micro-label" style="margin-bottom:4px;">Custom style descriptor</div>
                        <textarea
                          v-model="addSceneCustomVisualStyle"
                          class="scene-query-input"
                          rows="2"
                          maxlength="500"
                          placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                        ></textarea>
                        <div style="font-size:11px;opacity:.55;margin-top:4px;line-height:1.5;">
                          Leave blank to inherit from the project default.
                        </div>
                      </div>

                      <div class="scene-query-label" style="margin-top:10px;">Prompt override <span style="opacity:.5;font-weight:400;">(optional)</span></div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" maxlength="1000" placeholder="Leave blank to use scene script as the generation prompt"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'assets'">
                      <div v-if="addScenePickedAsset" class="add-scene-asset-preview">
                        <img v-if="addScenePickedAsset.thumbnail_url || (addScenePickedAsset.asset_type === 'image' && addScenePickedAsset.storage_url)" :src="addScenePickedAsset.thumbnail_url || addScenePickedAsset.storage_url" :alt="addScenePickedAsset.title" />
                        <div class="add-scene-asset-meta">
                          <div class="add-scene-asset-title">{{ addScenePickedAsset.title || 'Selected asset' }}</div>
                          <div class="add-scene-asset-type">{{ addScenePickedAsset.asset_type || 'asset' }}</div>
                        </div>
                        <button class="btn btn-ghost btn-sm" type="button" @click="pickAssetForNewScene">Replace</button>
                      </div>
                      <button v-else class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="pickAssetForNewScene">📁 Pick from your library</button>
                    </template>
                  </div>
                  <div v-if="addSceneGenerateError" class="rewrite-error">
                    {{ addSceneGenerateError }}
                  </div>
                  <div class="add-scene-actions">
                    <button
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="closeAddScene"
                    >
                      Cancel
                    </button>
                    <button
                      class="btn btn-ghost btn-sm purple-btn"
                      type="button"
                      :disabled="addSceneGeneratePending"
                      @click="generateNewSceneDraft"
                    >
                      {{ addSceneGeneratePending ? "Generating..." : "✦ AI Generate" }}
                    </button>
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      @click="createScene(scene.id)"
                    >
                      Add Scene
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </div>

          <div class="editor-canvas">
            <p v-if="previewVideoShortfall > 0.15" class="muted" role="status" style="padding: 8px 12px; font-size: 12px;">
              This clip ends {{ previewVideoShortfall.toFixed(1) }}s before the narration. Preview and export hold its last frame. Use a longer clip or split the scene for continuous motion.
            </p>
            <div class="preview-container" :style="previewContainerStyle">
              <div class="preview-video-bg">
                <template v-if="currentVisualUrl && activeSceneVisualIsVideo">
                  <video
                    :src="currentVisualUrl"
                    class="preview-fit-bg"
                    ref="previewVideoBackgroundRef"
                    @loadedmetadata="syncSceneVideoPreview"
                    muted
                    playsinline
                  ></video>
                  <video
                    :src="currentVisualUrl"
                    class="preview-image preview-video-contain"
                    ref="previewVideoRef"
                    @loadedmetadata="sceneVideoMetadataLoaded"
                    muted
                    playsinline
                  ></video>
                </template>
                <img
                  v-else-if="currentVisualUrl"
                  :src="currentVisualUrl"
                  :class="['preview-image', activeMotionClass]"
                  alt=""
                />
                <div v-else-if="showTextCardPreview" class="preview-fallback preview-fallback-text">
                  <div class="text-only-card">
                    <div class="text-only-label">TEXT CARD</div>
                    <div class="text-only-copy">
                      {{ sceneScriptDraft || activeScene?.script_text || activeScene?.label }}
                    </div>
                  </div>
                </div>
                <div v-else-if="showWaveformPreview" class="preview-fallback preview-fallback-waveform" :style="audiogramBgStyle">
                  <div class="waveform-shell">
                    <!-- Classic bars: upward from bottom -->
                    <div v-if="audiogramStyle === 'bars'" class="ag-bars">
                      <span
                        v-for="(bar, i) in waveformLive" :key="`bar-${i}`"
                        class="ag-bar"
                        :style="{ height: `${Math.round(bar * 100)}%`, background: `linear-gradient(to top, ${audiogramColor}99, ${audiogramColor})`, boxShadow: `0 0 12px ${audiogramColor}44` }"
                      ></span>
                    </div>

                    <!-- Mirror wave: grows from center up and down -->
                    <div v-else-if="audiogramStyle === 'mirror'" class="ag-mirror">
                      <span
                        v-for="(bar, i) in waveformLive" :key="`mir-${i}`"
                        class="ag-mirror-bar"
                        :style="{ height: `${Math.round(bar * 100)}%`, background: audiogramColor, boxShadow: `0 0 10px ${audiogramColor}55` }"
                      ></span>
                    </div>

                    <!-- Radial / circle: SVG bars radiating from center -->
                    <div v-else-if="audiogramStyle === 'circle'" class="ag-circle-wrap">
                      <svg viewBox="0 0 200 200" width="200" height="200">
                        <g transform="translate(100,100)">
                          <line
                            v-for="(bar, i) in waveformLive" :key="`rad-${i}`"
                            :transform="`rotate(${i * (360 / waveformLive.length)})`"
                            x1="0" :y1="38"
                            x2="0" :y2="`${38 + bar * 52}`"
                            :stroke="audiogramColor"
                            stroke-width="6"
                            stroke-linecap="round"
                            :opacity="0.6 + bar * 0.4"
                          />
                        </g>
                        <circle cx="100" cy="100" r="30" :fill="audiogramColor" opacity="0.15" />
                        <circle cx="100" cy="100" r="20" :fill="audiogramColor" opacity="0.25" />
                      </svg>
                    </div>

                    <!-- Minimal: thin compact bars -->
                    <div v-else-if="audiogramStyle === 'minimal'" class="ag-minimal">
                      <span
                        v-for="(bar, i) in [...waveformLive, ...waveformLive.slice().reverse()]" :key="`min-${i}`"
                        class="ag-minimal-bar"
                        :style="{ height: `${Math.round(bar * 80) + 8}%`, background: audiogramColor, opacity: 0.5 + bar * 0.5 }"
                      ></span>
                    </div>
                  </div>
                </div>
                <div v-else class="preview-fallback"></div>
                <div v-if="activeSceneAIImagePending" class="preview-loading">
                  Generating AI image...
                </div>
                <!-- Animating and swapping used to show nothing at all. Both
                     replace the visual, so the frame went blank or broke for
                     as long as the work took, with no way to tell a slow job
                     from a failed one. animation_in_progress was already
                     tracked and read here; it simply never reached the
                     template. A swap is worse: it nulls currentVisualUrl, so
                     isSceneLoading below evaluates false and no overlay fired
                     at all. -->
                <div v-else-if="activeSceneAnimationPending" class="preview-loading">
                  {{ activeSceneStillRendering ? 'The video model is busy, so this clip is taking longer. It will appear here when it is done; you can leave this page.' : 'Animating this scene…' }}
                </div>
                <div v-else-if="visualSwapPending" class="preview-loading">
                  Swapping the visual…
                </div>
                <div v-else-if="activeSceneAnimationError" class="preview-loading error">
                  {{ activeSceneAnimationError }}
                </div>
                <div v-else-if="activeSceneVisualGenerationError" class="preview-loading error">
                  {{ activeSceneVisualGenerationError }}
                </div>
                <div v-else-if="isSceneLoading" class="preview-loading">
                  Loading scene…
                </div>
                <div v-else-if="visualLoadFailed" class="preview-loading error">
                  Media unavailable
                </div>
                <div v-if="activeMusicTrack" class="preview-music-indicator">
                  <div class="preview-music-waves">
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                    <div :class="['music-wave', isPreviewPlaying ? 'playing' : '']"></div>
                  </div>
                  <span class="preview-music-name">{{ activeMusicTrack.title }}</span>
                </div>
                <div class="preview-watermark">WYVSTUDIO</div>
                <div class="preview-timer">{{ previewTimer.elapsed }}</div>
                <UgcHeadline :layout="activeScene?.caption_settings_json?.ugc_headline || {}" :aspect-ratio="project?.aspect_ratio || '9:16'" />
                <div
                  v-if="captionEnabledDraft && captionHighlightDraft !== 'none' && captionAnimationDraft === 'plain'"
                  class="preview-caption"
                  :class="captionPreviewClass"
                  :style="[captionPositionStyle, captionFontStyle]"
                >
                  <!-- Keyed by POSITION only. Including word.text made every
                       keystroke change the keys, so Vue tore down and rebuilt
                       every caption span instead of patching text in place —
                       that was the flicker while typing in the script box. -->
                  <span
                    v-for="(word, index) in previewWords(
                      sceneScriptDraft || activeScene?.script_text
                    )"
                    :key="index"
                    :class="`caption-word ${word.highlighted ? 'highlight' : 'normal'}`"
                    :style="{ color: word.highlighted ? captionHighlightColorDraft : captionColorDraft }"
                  >
                    {{ word.text }}
                  </span>
                </div>
                <div
                  v-else-if="captionEnabledDraft && captionHighlightDraft !== 'none' && captionsCanRender"
                  class="preview-caption preview-caption-animated"
                  :style="[captionPositionStyle, captionFontStyle]"
                >
                  <CaptionPreview
                    :words="captionPreviewTimedWords"
                    :seconds="captionClock"
                    :settings="captionPreviewSettings"
                  />
                </div>
              </div>
            </div>

            <div class="playback-controls">
              <div class="preview-mode-toggle">
                <button :class="['preview-mode-btn', previewMode === 'scene' ? 'active' : '']" type="button" @click="setPreviewMode('scene')">Scene</button>
                <button :class="['preview-mode-btn', previewMode === 'full' ? 'active' : '']" type="button" @click="setPreviewMode('full')">Full Video</button>
              </div>
              <div class="preview-scrubber" @click="scrubberSeek">
                <div class="scrubber-track">
                  <div class="scrubber-fill" :style="{ width: playProgress + '%' }"></div>
                  <template v-if="previewMode === 'full'">
                    <span v-for="pct in sceneBoundaryPcts" :key="pct" class="scrubber-marker" :style="{ left: pct + '%' }"></span>
                  </template>
                  <div class="scrubber-thumb" :style="{ left: playProgress + '%' }"></div>
                </div>
              </div>
              <div class="playback-btns">
                <button class="play-skip-btn" type="button" :disabled="activeSceneIndex <= 0" @click="skipToScene(-1)" title="Previous scene">⏮</button>
                <button class="play-btn" type="button" @click="togglePreviewPlay" :title="pendingScenePlay ? 'Loading scene… click to cancel' : ''">
                  <span v-if="pendingScenePlay" class="play-btn-spinner" aria-label="Loading scene"></span>
                  <template v-else>{{ isPreviewPlaying ? '⏸' : '▶' }}</template>
                </button>
                <button class="play-skip-btn" type="button" :disabled="activeSceneIndex >= scenes.length - 1" @click="skipToScene(1)" title="Next scene">⏭</button>
              </div>
              <div class="play-time-row">
                <span class="time-display">{{ previewTimer.elapsed }}</span>
                <span class="time-display" style="opacity:.35;">/</span>
                <span class="time-display">{{ previewTimer.total }}</span>
                <span class="play-scene-label">Scene {{ String(activeSceneIndex + 1).padStart(2, '0') }}</span>
              </div>
            </div>
          </div>

          <!-- ── Editor dock (phones) ──────────────────────────────────
               mobile-editor-v3.html gives the editor its own four-button
               dock instead of the app's global tabs. That removes a whole
               bar: on a real phone the export bar, the tab bar and Safari's
               own chrome were stacking into three rows of furniture. -->
          <nav v-if="isPhone" class="ed-dock">
            <button
              :class="['ed-dbtn', mobileSheet === 'scenes' ? 'is-on' : '']"
              type="button"
              @click="toggleSheet('scenes')"
            >
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="2.4" y="4" width="6.4" height="12" rx="1.6" />
                <rect x="11.2" y="4" width="6.4" height="12" rx="1.6" />
              </svg>
              Scenes
            </button>
            <button
              :class="['ed-dbtn', mobileSheet === 'config' && cruiseTab === 'config' ? 'is-on' : '']"
              type="button"
              @click="openPanel('config')"
            >
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M3.5 6h13M3.5 10h13M3.5 14h13" />
              </svg>
              Config
            </button>
            <button
              :class="['ed-dbtn', mobileSheet === 'config' && cruiseTab === 'assistant' ? 'is-on' : '']"
              type="button"
              @click="openPanel('assistant')"
            >
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M7.4 2.6l1.1 3.1 3.1 1.1-3.1 1.1-1.1 3.1-1.1-3.1L3.2 6.8l3.1-1.1z" />
              </svg>
              Assistant
            </button>
            <button class="ed-dbtn ed-dbtn-export" type="button" @click="queueExport">
              <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M10 13V4M6 7.6L10 3.6l4 4M3.6 13.4v2.2a1.4 1.4 0 001.4 1.4h10a1.4 1.4 0 001.4-1.4v-2.2" />
              </svg>
              {{ latestExportJob ? "Update video" : "Finish video" }}
            </button>
          </nav>


          <!-- Project sheet — the ⋮ from the bar. Everything the desktop
               topbar carries that a 430px bar can't: the export's own links,
               scheduling, approval, sharing, variants, and the timeline that
               genuinely needs a wider screen. -->
          <div v-if="isPhone" :class="['ed-projsheet', mobileSheet === 'project' ? 'sheet-open' : '']">
            <div class="editor-sheet-head">
              <span class="editor-sheet-grab"></span>
              <b>Project</b>
              <button type="button" @click="mobileSheet = null">Done</button>
            </div>
            <div class="ed-projsheet-body">
              <template v-if="latestExportDownloadUrl && exportCheckNotes.length">
                <div v-for="c in exportCheckNotes" :key="'chk-' + c.id" class="export-check note"><b>{{ c.label }}:</b> {{ c.detail }}</div>
              </template>
              <template v-if="latestExportDownloadUrl">
                <div v-if="exportsByRatio.length > 1" class="export-ratio-chips export-ratio-chips-sheet" role="group" aria-label="Which export">
                  <button
                    v-for="job in exportsByRatio" :key="job.id" type="button"
                    :class="['export-ratio-chip', job.id === activeExportJob?.id ? 'on' : '']"
                    @click.prevent="selectedExportRatio = job.aspect_ratio"
                  >{{ job.aspect_ratio }}</button>
                </div>
                <button type="button"
                  class="ed-prow"
                  @click.prevent="requestExportAction('open')"
                >
                  <span class="ed-prow-ic">↗</span>
                  <span class="ed-prow-b"><b>Open in new tab</b></span>
                  <span class="ed-prow-rt">›</span>
                </button>
                <button type="button"
                  class="ed-prow"
                  @click.prevent="requestExportAction('download')"
                >
                  <span class="ed-prow-ic">↓</span>
                  <span class="ed-prow-b"><b>Download</b><span>MP4 · {{ activeExportJob?.file_name || '1080p' }}</span></span>
                  <span class="ed-prow-rt">›</span>
                </button>
                <button class="ed-prow" type="button" @click="requestExportAction('schedule')">
                  <span class="ed-prow-ic">🗓</span>
                  <span class="ed-prow-b"><b>Schedule</b><span>Post to a connected channel</span></span>
                  <span class="ed-prow-rt">›</span>
                </button>
                <button class="ed-prow" type="button" @click="requestExportAction('approval')">
                  <span class="ed-prow-ic">✎</span>
                  <span class="ed-prow-b"><b>Send for approval</b></span>
                  <span class="ed-prow-rt">›</span>
                </button>
                <button class="ed-prow" type="button" :disabled="shareTogglePending" @click="requestExportAction('share')">
                  <span class="ed-prow-ic">🔗</span>
                  <span class="ed-prow-b">
                    <b>{{ project?.is_shared ? 'Copy share link' : 'Share publicly' }}</b>
                    <span v-if="shareCopiedToast">{{ shareCopiedToast }}</span>
                  </span>
                  <span class="ed-prow-rt">{{ shareTogglePending ? '…' : '›' }}</span>
                </button>
              </template>
              <!-- Nothing to open, schedule or share until there is a render. -->
              <div v-else class="ed-prow-empty">
                Export the video to open, download, schedule, share or send it for approval.
              </div>

              <div class="ed-prow-lbl">Manage</div>
              <button
                class="ed-prow"
                type="button"
                @click="mobileSheet = null; router.push({ name: 'project-variants', params: { projectId: projectId } })"
              >
                <span class="ed-prow-ic">⧉</span>
                <span class="ed-prow-b"><b>Variants</b><span>Other cuts of this project</span></span>
                <span class="ed-prow-rt">›</span>
              </button>
              <div v-if="false" class="ed-prow is-disabled">
                <span class="ed-prow-ic">◨</span>
                <span class="ed-prow-b"><b>Timeline</b><span>Needs a wider screen</span></span>
                <span class="ed-prow-rt">desktop</span>
              </div>
            </div>
          </div>

          <button
            v-if="mobileSheet"
            class="editor-sheet-scrim"
            type="button"
            aria-label="Close panel"
            @click="mobileSheet = null"
          ></button>
          <div :class="['editor-right', mobilePanelOpen ? 'sheet-open' : '']">
            <!-- Sheet chrome, phone only: a grab handle and a way out. -->
            <div class="editor-sheet-head">
              <span class="editor-sheet-grab"></span>
              <b>{{ cruiseTab === 'assistant' ? 'Assistant' : 'Settings' }}</b>
              <button type="button" @click="mobileSheet = null">Done</button>
            </div>
            <!-- Cruise Control rail toggle. Flips between Config (the
                 existing accordion) and Assistant (chat-driven editing,
                 Phase 1B). Brand orange accent — see spec/CRUISE_CONTROL_PLAN.md. -->
            <div class="cruise-toggle-bar">
              <button
                type="button"
                :class="['cruise-toggle-pill', cruiseTab === 'config' ? 'active' : '']"
                @click="cruiseTab = 'config'"
              >Config</button>
              <button
                type="button"
                :class="['cruise-toggle-pill', cruiseTab === 'assistant' ? 'active' : '']"
                @click="cruiseTab = 'assistant'"
              >
                Assistant
                <span v-if="cruiseAssistantPending" class="cruise-toggle-dot"></span>
              </button>
            </div>

            <div v-show="cruiseTab === 'config'" class="cruise-config-view">

            <!-- Cruise apply toast — fires after an Assistant action succeeds -->
            <div v-if="cruiseToast" class="cruise-toast">{{ cruiseToast }}</div>

            <!-- Project metadata — first section -->
            <div :class="`panel-section ${panelState.project ? 'collapsed' : ''}`">
              <div class="panel-section-header" @click="togglePanel('project')">
                <div class="panel-label panel-label-tight">Project Settings</div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="control-row">
                  <span class="control-name">Name</span>
                  <input
                    v-if="editingTitle"
                    id="editor-title-input-panel"
                    v-model="titleDraft"
                    class="control-value-input"
                    type="text"
                    @blur="commitTitle"
                    @keydown.enter.prevent="commitTitle"
                    @keydown.esc.prevent="editingTitle = false"
                  />
                  <span
                    v-else
                    class="control-value control-value-editable"
                    @click="startEditTitle"
                  >{{ projectTitle }}</span>
                </div>
                <div class="control-row">
                  <span class="control-name">Aspect Ratio</span>
                  <span class="control-value">{{ project?.aspect_ratio || '9:16' }}</span>
                </div>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.script ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('script')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Scene Script</div>
                  <span :class="activeVoiceOutdated ? 'panel-badge warn' : 'panel-badge warn state-hidden'">
                    Voice outdated
                  </span>
                  <span v-if="activeSceneScriptError" class="section-error-icon" :data-tip="activeSceneScriptError" @click.stop>ⓘ</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <textarea
                  v-model="sceneScriptDraft"
                  class="add-scene-textarea script-textarea"
                ></textarea>
                <div class="ss-caption">
                  Spoken by the voice and rendered as captions.
                </div>
                <div class="ss-duration-row">
                  <span class="ss-duration-label">Duration</span>
                  <div class="ss-duration-field">
                    <input
                      v-model="sceneDurationDraft"
                      :disabled="!!activeScene?.voice_settings?.audio_asset_id || sceneDurationSaving"
                      type="number"
                      min="1"
                      max="600"
                      step="0.5"
                      :placeholder="String(activeScene?.duration_seconds ?? 12)"
                      @change="saveSceneDuration"
                    />
                    <span class="ss-duration-unit">s</span>
                  </div>
                </div>
                <div class="ss-duration-caption">
                  <span v-if="sceneDurationSaving">saving…</span>
                  <span v-else-if="sceneDurationError" role="alert">{{ sceneDurationError }}</span>
                  <span v-else-if="activeScene?.voice_settings?.audio_asset_id">Length follows narration. Edit and re-record the voice to change it.</span>
                  <span v-else>Duration of this silent scene.</span>
                </div>
                <div v-if="scriptSaveCopy()" :class="scriptSaveState === 'error' ? 'script-save-copy error' : 'script-save-copy'">
                  {{ scriptSaveCopy() }}
                </div>

                <div class="ss-divider"></div>

                <!-- Rewrite: collapsed trigger by default -->
                <button
                  v-if="!rewriteToolsVisible"
                  class="ss-rewrite-trigger"
                  type="button"
                  @click.stop="toggleRewriteTools"
                >
                  <span class="ss-spark">✦</span>
                  <span>Rewrite with AI</span>
                  <span class="ss-chev">▾</span>
                </button>

                <!-- Rewrite: expanded -->
                <template v-else>
                  <div class="ss-rewrite-head" @click="toggleRewriteTools">
                    <span class="ss-spark">✦</span>
                    <span class="ss-rewrite-label">Rewrite with AI</span>
                    <span class="ss-rule"></span>
                    <span class="ss-chev ss-chev-open">▾</span>
                  </div>
                  <div class="ss-rewrite-body">
                    <div class="ss-group">
                      <div class="ss-group-label">Edit</div>
                      <div class="ss-chips">
                        <button
                          v-for="o in REWRITE_EDIT_OPTIONS"
                          :key="o.mode"
                          class="ss-chip"
                          :class="{ disabled: rewritePending }"
                          type="button"
                          @click="submitRewrite(o.mode)"
                        >
                          {{ o.label }}
                        </button>
                      </div>
                    </div>
                    <div class="ss-group">
                      <div class="ss-group-label">Tone</div>
                      <div class="ss-chips">
                        <button
                          v-for="o in REWRITE_TONE_OPTIONS"
                          :key="o.mode"
                          class="ss-chip"
                          :class="{ disabled: rewritePending }"
                          type="button"
                          @click="submitRewrite(o.mode)"
                        >
                          {{ o.label }}
                        </button>
                      </div>
                    </div>
                    <div class="ss-custom-row">
                      <span class="ss-custom-icon">✦</span>
                      <input
                        v-model="rewriteCustomInstruction"
                        class="ss-custom-input"
                        placeholder="Custom instruction…"
                        @keyup.enter="submitRewriteCustom"
                      />
                      <button
                        class="ss-apply-btn"
                        type="button"
                        :disabled="rewritePending"
                        title="Apply"
                        @click="submitRewriteCustom"
                      >
                        ✦
                      </button>
                    </div>
                    <div class="ss-footnote">
                      This scene only<span class="ss-dot">·</span>Locked facts preserved
                    </div>
                    <div v-if="rewriteError" class="rewrite-error">
                      {{ rewriteError }}
                    </div>
                  </div>
                </template>
                <div v-if="rewritePreviewVisible" class="rewrite-preview">
                  <div class="rewrite-preview-title">AI Rewrite Candidate</div>
                  <div class="rewrite-preview-copy">
                    {{ rewritePreviewCopy }}
                  </div>
                  <div class="rewrite-preview-actions">
                    <button
                      class="btn btn-ghost btn-sm"
                      type="button"
                      @click="hideRewritePreview"
                    >
                      Reject
                    </button>
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      :disabled="rewriteApplyPending"
                      @click="acceptRewrite"
                    >
                      {{ rewriteApplyPending ? "Applying..." : "Accept Rewrite" }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <div
              :class="['panel-section', panelState.visual ? 'collapsed' : '', cruisePulseClass('visual')]"
            >
              <div class="panel-section-header" @click="togglePanel('visual')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Visual Source</div>
                  <span v-if="activeScene?.visual_type === 'ai_image'" class="panel-badge new">AI</span>
                  <span v-if="activeSceneVisualError" class="section-error-icon" :data-tip="activeSceneVisualError" @click.stop>ⓘ</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Visual type tabs -->
                <div class="visual-type-tabs">
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'Stock Video' ? 'active' : '']" @click="selectedSwapVisualSource = 'Stock Video'">Video</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'Stock Image' ? 'active' : '']" @click="selectedSwapVisualSource = 'Stock Image'">Image</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'AI Image' ? 'active ai' : '']" @click="selectedSwapVisualSource = 'AI Image'">✦ AI</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'My Assets' ? 'active' : '']" @click="selectedSwapVisualSource = 'My Assets'">Assets</button>
                  <button type="button" :class="['visual-type-tab', selectedSwapVisualSource === 'Audiogram' ? 'active' : '']" @click="selectedSwapVisualSource = 'Audiogram'">Audio</button>
                </div>

                <!-- Stock Video -->
                <template v-if="selectedSwapVisualSource === 'Stock Video'">
                  <div class="control-row" style="margin-top:10px;">
                    <span class="control-name">Type</span>
                    <UiSelect v-model="stockVideoSubType" :options="STOCK_VIDEO_SUBTYPE_OPTIONS" />
                  </div>
                  <div class="scene-query-label" style="margin-top:10px;">Search query</div>
                  <textarea v-model="visualQueryDraft" class="scene-query-input" rows="2" placeholder="e.g. 'city skyline at sunset' — leave blank to use scene script"></textarea>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" :disabled="visualSwapPending" @click="swapVisual">
                    {{ visualSwapPending ? 'Swapping…' : '↻ Swap Visual' }}
                  </button>
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- Stock Image -->
                <template v-else-if="selectedSwapVisualSource === 'Stock Image'">
                  <div class="scene-query-label" style="margin-top:10px;">Search query</div>
                  <textarea v-model="visualQueryDraft" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" :disabled="visualSwapPending" @click="swapVisual">
                    {{ visualSwapPending ? 'Swapping…' : '↻ Swap Image' }}
                  </button>
                  <!-- Animate works on any image asset, including stock ones. -->
                  <button
                    v-if="canAnimateActiveScene && !activeSceneAnimationPending && !activeSceneVisualIsVideo"
                    class="btn btn-sm animate-btn panel-full-btn"
                    style="margin-top:8px;"
                    type="button"
                    @click="openAnimateModal"
                  >
                    {{ activeSceneAlreadyAnimated ? '⚡ Re-animate' : '⚡ Animate this image' }}
                  </button>
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- AI Image generation -->
                <template v-else-if="selectedSwapVisualSource === 'AI Image'">
                  <!-- Character chip — pick a workspace character to bind to this scene -->
                  <div class="char-chip-wrap" @click.stop>
                    <div class="char-chip" @click="characterPopoverOpen = !characterPopoverOpen">
                      <div class="char-chip-thumb">
                        <img v-if="activeCharacter?.reference_asset?.thumbnail_url || activeCharacter?.reference_asset?.storage_url"
                             :src="activeCharacter.reference_asset.thumbnail_url || activeCharacter.reference_asset.storage_url" alt="" />
                        <span v-else-if="activeCharacter">{{ activeCharacter.name.charAt(0).toUpperCase() }}</span>
                        <span v-else style="opacity:.5;">◐</span>
                      </div>
                      <div class="char-chip-text">
                        <div class="char-chip-name">{{ activeCharacter?.name || 'No character' }}</div>
                        <div class="char-chip-trail">{{ activeCharacter ? 'Bound to this scene' : 'Tap to bind a character' }}</div>
                      </div>
                      <span class="char-chip-chev">▾</span>
                    </div>
                    <div v-if="characterPopoverOpen" class="char-popover">
                      <div v-if="characters.length === 0" class="char-popover-empty">
                        No characters yet. Create your first one.
                      </div>
                      <div v-else class="char-popover-grid">
                        <div
                          v-for="c in characters"
                          :key="c.id"
                          :class="['char-popover-item', activeScene?.character_id === c.id ? 'selected' : '']"
                          @click="selectCharacter(c.id)"
                        >
                          <div class="char-popover-thumb">
                            <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url"
                                 :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
                            <span v-else>{{ c.name.charAt(0).toUpperCase() }}</span>
                          </div>
                          <div class="char-popover-name">{{ c.name }}</div>
                          <div class="char-popover-trail">{{ c.scenes_count > 0 ? `${c.scenes_count}${c.scenes_count === 1 ? ' scene' : ' scenes'}` : 'new' }}</div>
                        </div>
                      </div>
                      <div class="char-popover-foot">
                        <button v-if="activeScene?.character_id" class="char-popover-none" type="button" @click="selectCharacter(null)">Remove</button>
                        <span v-else></span>
                        <button class="char-popover-new" type="button" @click="characterPopoverOpen = false; createCharacterOpen = true;">＋ New character</button>
                      </div>
                    </div>
                  </div>

                  <!-- Current result preview -->
                  <div v-if="activeScene?.visual_type === 'ai_image'" class="ai-image-result">
                    <div class="ai-image-preview">
                      <div class="ai-image-overlay-badge">{{ activeSceneVisualIsVideo ? '⚡ AI Animated' : '✦ AI Generated' }}</div>
                      <video
                        v-if="currentVisualUrl && activeSceneVisualIsVideo"
                        :src="currentVisualUrl"
                        style="width:100%;height:100%;object-fit:cover;"
                        autoplay loop muted playsinline
                      ></video>
                      <img v-else-if="currentVisualUrl" :src="currentVisualUrl" style="width:100%;height:100%;object-fit:cover;" alt="" />
                      <div v-else class="ai-image-placeholder">
                        <div class="ai-image-ico">{{ activeSceneAIImagePending ? '⏳' : '🖼️' }}</div>
                        <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:6px;">
                          {{ activeSceneAIImagePending ? 'Generating…' : 'No image yet' }}
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Style picker grid -->
                  <div class="micro-label" style="margin-bottom:6px;margin-top:10px;">
                    Style
                    <span v-if="visualStyleSaveState === 'saved'" style="opacity:.5; font-weight:400; margin-left:4px;">saved</span>
                  </div>
                  <div v-if="activeScene?.visual_type === 'ai_image' && (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style)" class="current-style-note">
                    Last generated with <strong>{{ AI_IMAGE_STYLES.find(s => s.key === (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style))?.label ?? (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style) }}</strong>
                  </div>
                  <!-- Custom row-style style picker — collapsed button that
                       opens a panel of thumbnail rows. Replaces the old emoji
                       grid; thumbnails come from /image-styles (B2-hosted
                       samples rendered by generate:style-samples). -->
                  <div class="picker-wrap">
                    <button
                      type="button"
                      class="picker-trigger"
                      @click="stylePickerOpen = !stylePickerOpen"
                    >
                      <template v-if="(() => { const k = visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style; const s = imageStylesByKey.get(k); return s && s.sample_url; })()">
                        <img
                          :src="imageStylesByKey.get(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style)?.sample_url"
                          alt=""
                          class="picker-trigger-thumb"
                        />
                      </template>
                      <span v-else class="picker-trigger-glyph">{{ imageStylesByKey.get(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style)?.icon ?? '✦' }}</span>
                      <span class="picker-trigger-label">
                        {{ imageStylesByKey.get(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style)?.label ?? 'Pick a style' }}
                      </span>
                      <span class="picker-trigger-caret">▾</span>
                    </button>
                    <div v-if="stylePickerOpen" class="picker-panel">
                      <div
                        v-for="s in stylePickerRows"
                        :key="s.key"
                        :class="['picker-row', (visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style) === s.key ? 'selected' : '']"
                        @click="visualStyleDraft = s.key; stylePickerOpen = false;"
                      >
                        <img v-if="s.sample_url" :src="s.sample_url" :alt="s.label" class="picker-row-thumb" />
                        <span v-else class="picker-row-glyph">{{ s.icon ?? '✦' }}</span>
                        <div class="picker-row-text">
                          <div class="picker-row-name">{{ s.label }}</div>
                          <div v-if="s.description" class="picker-row-desc">{{ s.description }}</div>
                        </div>
                        <span v-if="(visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style) === s.key" class="picker-row-check">✓</span>
                      </div>
                    </div>
                  </div>

                  <!-- Custom style descriptor — appears when 'custom' is picked. -->
                  <div v-if="visualStyleDraft === 'custom'" class="custom-style-panel">
                    <div class="micro-label" style="margin-bottom:4px;">
                      Custom style descriptor
                      <span style="font-weight:400;opacity:.5;">
                        — {{ customVisualStyleSaveState === 'pending' ? 'saving…' : customVisualStyleSaveState === 'saved' ? 'saved ✓' : 'auto-saved' }}
                      </span>
                    </div>
                    <textarea
                      v-model="customVisualStyleDraft"
                      class="ai-prompt-area"
                      rows="2"
                      maxlength="500"
                      placeholder="e.g. moody Wong Kar-wai film stills, neon-drenched alleys, slow shutter, 35mm grain"
                    ></textarea>
                    <div style="font-size:11px;opacity:.55;margin-top:4px;line-height:1.5;">
                      Appended to this scene's image prompt instead of a preset. Be concrete — name the director, film stock, mood, color grade. {{ customVisualStyleDraft.length }}/500.
                    </div>
                  </div>

                  <!-- Image model picker — same row-list pattern as styles
                       but text-only (no thumbnail needed). Right rail shows
                       cost so users can compare price/quality at a glance. -->
                  <div class="micro-label" style="margin-bottom:4px;">
                    Model <span style="font-weight:400;opacity:.5;">— {{ activeImageModelMeta?.cost ?? 15 }} cr · {{ activeImageModelMeta?.render ?? '~20s' }}</span>
                  </div>
                  <div class="picker-wrap">
                    <button type="button" class="picker-trigger" @click="modelPickerOpen = !modelPickerOpen">
                      <span class="picker-trigger-label">{{ activeImageModelLabel }}</span>
                      <span class="picker-trigger-sub">{{ activeImageModelMeta?.sub ?? '' }}</span>
                      <span class="picker-trigger-cost">{{ activeImageModelMeta?.cost ?? 15 }} cr</span>
                      <span class="picker-trigger-caret">▾</span>
                    </button>
                    <div v-if="modelPickerOpen" class="picker-panel">
                      <div
                        v-for="m in availableImageModelsEditor"
                        :key="m.key"
                        :class="['picker-row', aiImageModelKey === m.key ? 'selected' : '', (m.requires_reference && !activeSceneHasCharacter) ? 'disabled' : '']"
                        :title="m.requires_reference && !activeSceneHasCharacter ? 'Needs a character reference on this scene' : ''"
                        @click="!(m.requires_reference && !activeSceneHasCharacter) && (aiImageModelKey = m.key, modelPickerOpen = false)"
                      >
                        <div class="picker-row-text picker-row-text-flex">
                          <div class="picker-row-name">{{ m.label }}</div>
                          <div class="picker-row-desc">{{ m.sub }}{{ m.requires_reference && !activeSceneHasCharacter ? ' (needs character)' : '' }}</div>
                        </div>
                        <span class="picker-row-cost">{{ m.cost }} cr · {{ m.render }}</span>
                        <span v-if="aiImageModelKey === m.key" class="picker-row-check">✓</span>
                      </div>
                    </div>
                  </div>

                  <!-- Prompt override -->
                  <div class="micro-label" style="margin-bottom:4px;margin-top:8px;">
                    Prompt override <span style="font-weight:400;opacity:.5;">(optional)</span>
                  </div>
                  <textarea
                    v-model="aiImagePromptOverride"
                    class="ai-prompt-area"
                    rows="2"
                    maxlength="1000"
                    placeholder="Leave blank to use scene script as the generation prompt…"
                  ></textarea>

                  <div class="ai-gen-footer">
                    <button class="btn btn-primary btn-sm" type="button" :disabled="aiImagePending || activeSceneAIImagePending" @click="generateAIImage">
                      {{ (aiImagePending || activeSceneAIImagePending) ? '✦ Generating…' : '✦ Generate' }}
                    </button>
                  </div>
                  <div v-if="activeScene?.visual_type === 'ai_image' && activeScene?.visual_asset_id" class="edit-image-box">
                    <div class="micro-label" style="margin-bottom:4px;">
                      Edit this image <span style="font-weight:400;opacity:.5;">(keeps the shot, changes what you name)</span>
                    </div>
                    <textarea
                      v-model="editInstruction"
                      class="ai-prompt-area"
                      rows="2"
                      maxlength="500"
                      placeholder="Remove the text on the wall behind her…"
                    ></textarea>
                    <div class="edit-chips">
                      <button v-for="s in EDIT_SUGGESTIONS" :key="s" class="edit-chip" type="button" @click="editInstruction = s">{{ s }}</button>
                    </div>
                    <button
                      class="btn btn-primary btn-sm"
                      style="width:100%;margin-top:8px;"
                      type="button"
                      :disabled="editPending || activeSceneAIImagePending || editInstruction.trim().length < 3"
                      @click="editSceneImage"
                    >
                      {{ editPending ? 'Applying…' : 'Apply edit' }}
                    </button>
                    <div v-if="editError" class="ai-image-error">{{ editError }}</div>
                  </div>
                  <div v-if="activeScene?.visual_type === 'ai_image'" class="ai-image-actions">
                    <button class="btn btn-ghost btn-sm" style="flex:1;" type="button" :disabled="aiImagePending || activeSceneAIImagePending || activeSceneAnimationPending" @click="generateAIImage">
                      {{ (aiImagePending || activeSceneAIImagePending) ? 'Generating…' : 'Regenerate' }}
                    </button>
                    <button v-if="canAnimateActiveScene && !activeSceneAnimationPending" class="btn btn-sm animate-btn" type="button" :disabled="aiImagePending" @click="openAnimateModal">
                      {{ activeSceneAlreadyAnimated ? '⚡ Re-animate' : '⚡ Animate' }}
                    </button>
                    <button v-else-if="activeSceneAnimationPending" class="btn btn-ghost btn-sm anim-cancel-btn" type="button" @click="cancelAnimation">
                      ✕ Cancel animation
                    </button>
                    <button v-if="canRevertAnimation && !activeSceneAnimationPending" class="btn btn-ghost btn-sm" type="button" :disabled="aiImagePending" @click="revertAnimation" title="Restore original still">↺</button>
                  </div>
                  <div v-if="activeSceneAnimationError" class="panel-error-copy">{{ activeSceneAnimationError }}</div>

                  <!-- Lip-sync went stale (voice changed) — regenerate right here,
                       no need to open the animate modal. -->
                  <div v-if="spokespersonOutdated && !activeSceneAnimationPending" class="lipsync-stale-row">
                    <span class="lipsync-stale-copy">⚠ Voice changed — the talking lip-sync no longer matches the audio.</span>
                    <button class="btn btn-primary btn-sm" type="button" :disabled="lipSyncRegenerating || aiImagePending" @click="regenerateLipSync">
                      {{ lipSyncRegenerating ? 'Regenerating…' : '⟳ Regenerate lip-sync' }}
                    </button>
                  </div>

                  <!-- Versions — every animation listed regardless of count,
                       with the original still on top when it's available.
                       Click any to switch the scene's visual. Nothing is deleted. -->
                  <div v-if="activeSceneAnimationHistory.length >= 1" class="anim-history-row">
                    <div class="anim-history-label">Versions</div>
                    <div class="anim-history-strip">
                      <!-- Original still (only when we have it preserved) -->
                      <div
                        v-if="activeSceneAnimationOriginal"
                        :class="['anim-history-item', activeScene?.visual_asset_id === activeSceneAnimationOriginal.asset_id ? 'current' : '']"
                        @click="useOriginalStill"
                        title="Original still image"
                      >
                        <img
                          v-if="activeSceneAnimationOriginal.thumbnail_url || activeSceneAnimationOriginal.image_url"
                          :src="activeSceneAnimationOriginal.thumbnail_url || activeSceneAnimationOriginal.image_url"
                          alt="Original still"
                        />
                        <span class="anim-history-tier">IMG</span>
                      </div>
                      <!-- Animation clips -->
                      <div
                        v-for="h in activeSceneAnimationHistory"
                        :key="h.asset_id"
                        :class="['anim-history-item', activeScene?.visual_asset_id === h.asset_id ? 'current' : '']"
                        @click="useHistoryAnimation(h.asset_id)"
                        :title="`${h.tier} · ${h.duration}s${h.motion_prompt ? ' · ' + h.motion_prompt : ''}`"
                      >
                        <video v-if="h.video_url" :src="h.video_url" muted playsinline></video>
                        <span class="anim-history-tier">{{ h.tier?.[0]?.toUpperCase() }}</span>
                      </div>
                    </div>
                  </div>
                  <div v-if="aiImageError || activeSceneVisualGenerationError" class="panel-error-copy">{{ aiImageError || activeSceneVisualGenerationError }}</div>
                  <div v-if="aiImagePending || activeSceneAIImagePending" class="panel-hint-copy">This takes ~15s (PuLID can take up to 3 min on character scenes)</div>
                  <div v-if="visualStyleDraft && activeScene?.visual_type === 'ai_image' && !(aiImagePending || activeSceneAIImagePending) && visualStyleDraft !== (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style ?? visualStyleDraft)" class="style-regen-hint">
                    Style changed — regenerate to apply.
                  </div>

                  <!-- Apply this look to the whole project. -->
                  <div v-if="activeScene?.visual_type === 'ai_image'" class="bulk-style">
                    <button type="button" class="bulk-style-cta" @click="bulkStyleOpen ? (bulkStyleOpen = false) : openBulkStyle()">
                      🎨 {{ bulkStyleOpen ? 'Hide' : 'Apply this style to all scenes' }}
                    </button>

                    <div v-if="bulkStyleOpen" class="bulk-style-body">
                      <div v-if="bulkStyleLoading" class="bulk-style-muted">Working out the cost…</div>
                      <div v-else-if="bulkStyleError" class="bulk-style-short">{{ bulkStyleError }}</div>

                      <template v-else-if="bulkStylePreview">
                        <div v-if="!bulkStylePreview.eligible_count" class="bulk-style-muted">
                          Every scene is already generating an image.
                        </div>

                        <template v-else>
                          <div class="bulk-style-row">
                            <span>{{ bulkStylePreview.eligible_count }} scene{{ bulkStylePreview.eligible_count === 1 ? '' : 's' }}</span>
                            <strong :class="{ short: !bulkStylePreview.affordable }">{{ bulkStylePreview.total_cost }} credits</strong>
                          </div>
                          <div class="bulk-style-row sub">
                            <span>Your balance</span>
                            <span>{{ bulkStylePreview.balance }} credits</span>
                          </div>

                          <!-- The reassurance that matters most here: this is a
                               restyle, not an overwrite. -->
                          <div class="bulk-style-muted" style="margin-top:8px;">
                            Each scene is redrawn from its own description — only the look changes.
                          </div>
                          <div v-if="bulkStylePreview.character_count" class="bulk-style-muted">
                            {{ bulkStylePreview.character_count }} character scene{{ bulkStylePreview.character_count === 1 ? '' : 's' }}
                            cost more — they redraw against the reference photo.
                          </div>

                          <div v-if="!bulkStylePreview.affordable" class="bulk-style-short">
                            You're {{ bulkStylePreview.shortage }} credits short.
                          </div>
                          <ul v-if="bulkStylePreview.skipped.length" class="bulk-style-skips">
                            <li v-for="s in bulkStylePreview.skipped" :key="s.scene_id">
                              Scene {{ s.order }} skipped — {{ s.reason }}
                            </li>
                          </ul>

                          <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            style="width:100%;margin-top:10px;"
                            :disabled="bulkStyleSubmitting || !bulkStylePreview.affordable"
                            @click="submitBulkStyle"
                          >
                            {{ bulkStyleSubmitting ? 'Starting…' : `Restyle all ${bulkStylePreview.eligible_count} scenes` }}
                          </button>
                        </template>
                      </template>
                    </div>
                  </div>
                </template>

                <!-- My Assets -->
                <template v-else-if="selectedSwapVisualSource === 'My Assets'">
                  <!-- Has a visual asset assigned from library -->
                  <template v-if="activeSceneVisualIsFromLibrary">
                    <div class="asset-current-preview">
                      <div class="asset-current-thumb">
                        <!-- Videos: thumbnail (SVG placeholder) only — never load full video in img tag -->
                        <!-- Images: thumbnail preferred, fall back to actual image URL -->
                        <img
                          v-if="activeSceneVisualAsset.thumbnail_url || (!activeSceneVisualIsVideo && currentVisualUrl)"
                          :src="activeSceneVisualAsset.thumbnail_url || currentVisualUrl"
                          alt=""
                        />
                        <div v-else class="asset-current-placeholder">
                          <span style="font-size:20px;">{{ activeSceneVisualIsVideo ? '🎥' : '🖼️' }}</span>
                        </div>
                        <div class="asset-current-badge">{{ activeSceneVisualIsVideo ? 'VIDEO' : 'IMG' }}</div>
                      </div>
                      <div class="asset-current-title">{{ activeSceneVisualAsset.title || 'Asset' }}</div>
                      <div class="asset-current-type">{{ activeSceneVisualIsVideo ? 'Video' : 'Image' }} · From library</div>
                    </div>
                    <button class="btn btn-ghost btn-sm panel-full-btn" type="button" :disabled="visualSwapPending" @click="openMediaPicker('visual')">
                      Change Visual
                    </button>
                    <!-- Animate works on any image asset (uploaded photos,
                         stock images, AI-generated). Hidden for video assets
                         (which are already moving). -->
                    <button
                      v-if="canAnimateActiveScene && !activeSceneAnimationPending && !activeSceneVisualIsVideo"
                      class="btn btn-sm animate-btn panel-full-btn"
                      style="margin-top:8px;"
                      type="button"
                      @click="openAnimateModal"
                    >
                      {{ activeSceneAlreadyAnimated ? '⚡ Re-animate' : '⚡ Animate this image' }}
                    </button>
                  </template>
                  <!-- No asset from library yet -->
                  <template v-else>
                    <div class="asset-empty-state">
                      <div class="asset-empty-icon">🗂️</div>
                      <div class="asset-empty-title">Pick from your library</div>
                      <div class="asset-empty-sub">Browse and assign images or videos you've uploaded to your workspace</div>
                    </div>
                    <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="openMediaPicker('visual')">
                      Open Library
                    </button>
                  </template>
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- Audiogram -->
                <template v-else-if="selectedSwapVisualSource === 'Audiogram'">
                    <!-- Design picker -->
                    <div class="micro-label" style="margin-top:10px;margin-bottom:6px;">
                      Design
                      <span v-if="audiogramSaveState === 'saved'" style="opacity:.45;font-weight:400;margin-left:4px;">saved</span>
                    </div>
                    <div class="ag-style-grid">
                      <button
                        v-for="s in AUDIOGRAM_STYLES" :key="s.key"
                        :class="['ag-style-opt', audiogramStyle === s.key ? 'selected' : '']"
                        type="button"
                        @click="selectAudiogramStyle(s.key)"
                      >
                        <!-- Mini preview of each style -->
                        <div class="ag-style-mini" :style="audiogramBgStyle">
                          <!-- bars -->
                          <template v-if="s.key === 'bars'">
                            <span v-for="(h, i) in [0.4,0.7,0.55,0.9,0.6,0.8,0.45]" :key="i" class="ag-mini-bar" :style="{ height: `${h*100}%`, background: audiogramColor }"></span>
                          </template>
                          <!-- mirror -->
                          <template v-else-if="s.key === 'mirror'">
                            <span v-for="(h, i) in [0.4,0.7,0.55,0.9,0.6,0.8,0.45]" :key="i" class="ag-mini-bar ag-mini-mirror" :style="{ height: `${h*100}%`, background: audiogramColor }"></span>
                          </template>
                          <!-- circle -->
                          <template v-else-if="s.key === 'circle'">
                            <svg viewBox="0 0 40 40" width="38" height="38" style="overflow:visible">
                              <g transform="translate(20,20)">
                                <line v-for="(h, i) in [0.5,0.8,0.6,0.9,0.55,0.75,0.65,0.85]" :key="i" :transform="`rotate(${i*45})`" x1="0" y1="7" :x2="0" :y2="`${7+h*10}`" :stroke="audiogramColor" stroke-width="2.5" stroke-linecap="round" />
                              </g>
                            </svg>
                          </template>
                          <!-- minimal -->
                          <template v-else-if="s.key === 'minimal'">
                            <span v-for="(h, i) in [0.3,0.5,0.4,0.7,0.5,0.6,0.35,0.55,0.45,0.65]" :key="i" class="ag-mini-minimal" :style="{ height: `${h*100}%`, background: audiogramColor }"></span>
                          </template>
                        </div>
                        <div class="ag-style-label">{{ s.label }}</div>
                      </button>
                    </div>

                    <!-- Color presets + custom -->
                    <div class="micro-label" style="margin-top:12px;margin-bottom:6px;">Color</div>
                    <div class="ag-colors">
                      <button
                        v-for="c in AUDIOGRAM_COLORS" :key="c"
                        :class="['ag-color-swatch', audiogramColor === c ? 'selected' : '']"
                        :style="{ background: c }"
                        type="button"
                        @click="selectAudiogramColor(c)"
                        :title="c"
                      ></button>
                      <label class="ag-color-custom" title="Custom color">
                        <input type="color" :value="audiogramColor" @input="e => { audiogramColor = e.target.value; queueAudiogramColorSave(); }" />
                        <span class="ag-color-custom-icon">＋</span>
                      </label>
                    </div>

                    <!-- Background -->
                    <div class="micro-label" style="margin-top:12px;margin-bottom:6px;">Background</div>
                    <div class="ag-bg-row">
                      <button
                        v-for="bg in AUDIOGRAM_BACKGROUNDS" :key="bg.key"
                        :class="['ag-bg-opt', audiogramBg === bg.key ? 'selected' : '']"
                        :style="{ background: bg.css }"
                        type="button"
                        @click="selectAudiogramBg(bg.key)"
                      >{{ bg.label }}</button>
                    </div>

                    <!-- Apply audiogram to this scene -->
                    <button
                      v-if="activeScene?.visual_type !== 'waveform'"
                      class="btn btn-primary btn-sm panel-full-btn"
                      style="margin-top:14px;"
                      type="button"
                      @click="saveAudiogramSettings({ apply: true })"
                    >Apply Audiogram to Scene</button>
                    <div v-else class="micro-label" style="margin-top:12px;opacity:.7;text-align:center;">
                      Audiogram is active on this scene
                    </div>
                </template>

              </div>
            </div>

            <!-- Motion panel — visible only when scene visual is a still image -->
            <div
              v-if="activeSceneIsStillImage"
              :class="['panel-section', panelState.motion ? 'collapsed' : '', cruisePulseClass('motion')]"
            >
              <div class="panel-section-header" @click="togglePanel('motion')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Motion</div>
                  <span v-if="motionEffectDraft !== 'static'" class="panel-badge new">KB</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="control-row" style="margin-top:4px;">
                  <span class="control-name">Effect</span>
                  <UiSelect v-model="motionEffectDraft" :options="MOTION_EFFECT_OPTIONS" />
                </div>
                <div v-if="motionEffectDraft !== 'static'" class="control-row">
                  <span class="control-name">Intensity</span>
                  <UiSelect v-model="motionIntensityDraft" :options="MOTION_INTENSITY_OPTIONS" />
                </div>
                <div v-if="motionSaveState === 'error'" class="panel-error-copy">{{ motionSaveError }}</div>
              </div>
            </div>

            <div
              :class="['panel-section', panelState.voice ? 'collapsed' : '', cruisePulseClass('voice')]"
            >
              <div class="panel-section-header" @click="togglePanel('voice')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Voice</div>
                  <span :class="activeVoiceOutdated ? 'panel-badge warn' : 'panel-badge warn state-hidden'">
                    Outdated
                  </span>
                  <span v-if="activeSceneVoiceError" class="section-error-icon" :data-tip="activeSceneVoiceError" @click.stop>ⓘ</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Voice tabs -->
                <div class="voice-tabs">
                  <button
                    type="button"
                    :class="['voice-tab', voiceTab === 'default' ? 'active' : '']"
                    @click="voiceTab = 'default'"
                  >Default</button>
                  <button
                    type="button"
                    :class="['voice-tab', voiceTab === 'custom' ? 'active' : '']"
                    @click="voiceTab = 'custom'"
                  >Custom</button>
                </div>

                <template v-if="voiceTab === 'default'">
                <div class="voice-preview">
                  <div class="voice-avatar">🎙</div>
                  <div class="voice-info">
                    <div class="voice-name">{{ activeVoiceName }}</div>
                    <div class="voice-desc">
                      {{ activeScene?.voice_settings?.language?.toUpperCase() || "EN" }} ·
                      {{ activeVoiceSpeed }}x
                    </div>
                  </div>
                  <div
                    class="voice-play"
                    :class="{ disabled: !activeSceneAudioUrl }"
                    :title="activeSceneAudioUrl ? '' : 'No audio generated'"
                    @click="toggleAudioPlayback"
                  >
                    {{ isAudioLoading ? "…" : isAudioPlaying ? "⏸" : "▶" }}
                  </div>
                </div>
                <div class="control-row top-space">
                  <span class="control-name">Volume</span>
                  <div class="volume-slider-wrap">
                    <input v-model.number="sceneVoiceVolume" type="range" class="music-slider" min="0" max="200" @input="scheduleSceneVoiceVolumeSave" />
                    <span class="music-slider-val">{{ sceneVoiceVolume }}%</span>
                  </div>
                </div>
                <div class="control-row">
                  <span class="control-name">Speed</span>
                  <UiSelect v-model="voiceSpeedDraft" :options="VOICE_SPEED_OPTIONS" />
                </div>
                <div class="control-row">
                  <span class="control-name">Stability</span>
                  <UiSelect v-model="voiceStabilityDraft" :options="VOICE_STABILITY_OPTIONS" />
                </div>
                <div class="control-row">
                  <span class="control-name">Voice</span>
                  <button type="button" class="voice-select-btn" @click="openVoiceModal">
                    <span class="vsb-name">{{ activeVoiceName }}</span>
                    <span class="vsb-change">Change ▾</span>
                  </button>
                </div>
                <div v-if="activeVoiceIsGemini" class="voice-direction-block">
                  <label class="voice-direction-label">Voice direction <span class="vd-opt">optional</span></label>
                  <textarea
                    v-model="voiceDirectionDraft"
                    class="voice-direction-input"
                    rows="2"
                    placeholder="e.g. Warm and upbeat, like welcoming a friend"
                  ></textarea>
                  <div class="vd-hint">
                    Add performance cues inline in the script too —
                    <code>[laughs]</code>, <code>[whispering]</code>, <code>[excited]</code>, <code>[sighs]</code>.
                  </div>
                </div>
                <!-- Push this scene's voice (including the direction) to every
                     other scene. Marks them outdated so the audio actually
                     re-synthesises rather than keeping the old take. -->
                <div v-if="scenes.length > 1" class="apply-all-row">
                  <button
                    class="apply-all-btn"
                    type="button"
                    :disabled="applyVoiceAllState === 'saving'"
                    @click="applyVoiceToAllScenes"
                  >
                    {{ applyVoiceAllState === 'saving'
                        ? 'Applying to all scenes…'
                        : `Apply this voice to all ${scenes.length} scenes` }}
                  </button>
                  <div v-if="applyVoiceAllState === 'saved'" class="apply-all-note">
                    Applied. Voices will re-generate on the next render.
                  </div>
                  <div v-else-if="applyVoiceAllState === 'error'" class="apply-all-note error">
                    {{ applyVoiceAllError }}
                  </div>
                  <div v-else class="apply-all-note muted">
                    Copies the voice, speed, stability{{ activeVoiceIsGemini ? ' and direction' : '' }}.
                  </div>
                </div>

                <!-- Actually re-runs TTS for the whole project. Separate from
                     the copy-settings action above because this one bills. -->
                <div v-if="scenes.length > 1" class="apply-all-row">
                  <button
                    class="apply-all-btn"
                    type="button"
                    @click="bulkVoiceOpen ? (bulkVoiceOpen = false) : openBulkVoice()"
                  >
                    🎙 {{ bulkVoiceOpen ? 'Hide' : 'Re-record all scenes' }}
                  </button>

                  <div v-if="bulkVoiceOpen" class="bulk-voice-body">
                    <div v-if="bulkVoiceLoading" class="bulk-voice-muted">Working out the cost…</div>
                    <div v-else-if="bulkVoiceError" class="bulk-voice-short">{{ bulkVoiceError }}</div>

                    <template v-else-if="bulkVoicePreview">
                      <div v-if="!bulkVoicePreview.eligible_count" class="bulk-voice-muted">
                        No scene has a script to record yet.
                      </div>

                      <template v-else>
                        <div class="bulk-voice-row">
                          <span>{{ bulkVoicePreview.eligible_count }} scene{{ bulkVoicePreview.eligible_count === 1 ? '' : 's' }}</span>
                          <strong :class="{ short: !bulkVoicePreview.affordable }">{{ bulkVoicePreview.total_cost }} credits</strong>
                        </div>
                        <div class="bulk-voice-row sub">
                          <span>Your balance</span>
                          <span>{{ bulkVoicePreview.balance }} credits</span>
                        </div>
                        <div class="bulk-voice-muted">
                          Each scene re-records its own script with its own voice.
                        </div>
                        <div v-if="!bulkVoicePreview.affordable" class="bulk-voice-short">
                          You're {{ bulkVoicePreview.shortage }} credits short.
                        </div>
                        <ul v-if="bulkVoicePreview.skipped.length" class="bulk-voice-skips">
                          <li v-for="sk in bulkVoicePreview.skipped" :key="sk.scene_id">
                            Scene {{ sk.order }} skipped — {{ sk.reason }}
                          </li>
                        </ul>
                        <button
                          type="button"
                          class="btn btn-primary btn-sm"
                          style="width:100%;margin-top:10px;"
                          :disabled="bulkVoiceSubmitting || !bulkVoicePreview.affordable"
                          @click="submitBulkVoice"
                        >
                          {{ bulkVoiceSubmitting ? 'Starting…' : `Re-record all ${bulkVoicePreview.eligible_count} scenes` }}
                        </button>
                      </template>
                    </template>
                  </div>
                </div>

                <div :class="activeVoiceOutdated ? 'voice-warning-row' : 'voice-warning-row state-hidden'">
                  <span class="voice-warning-copy">Script changed — voice outdated</span>
                  <button class="regen-btn" type="button" :disabled="voiceRegeneratePending" @click="regenerateVoice">
                    {{ voiceRegeneratePending ? "Regenerating..." : "Regenerate" }}
                  </button>
                </div>
                <div v-if="voiceSaveCopy()" :class="voiceSaveState === 'error' ? 'script-save-copy error' : 'script-save-copy'">
                  {{ voiceSaveCopy() }}
                </div>
                <div v-if="isAudioLoading" class="voice-loading-copy">
                  Loading audio...
                </div>
                </template>

                <!-- ── Custom voice tab ── -->
                <template v-else-if="voiceTab === 'custom'">
                <div class="custom-voice-intro">
                  Upload an audio file or record your own voice. Captions are generated from the audio.
                </div>

                <!-- Upload + record buttons -->
                <div v-if="voiceUploadStatus === 'idle'" class="voice-action-row">
                  <label class="voice-upload-btn">
                    <input type="file" accept="audio/*" hidden @change="handleVoiceFileUpload">
                    ⬆ Upload
                  </label>
                  <button
                    type="button"
                    :class="['voice-record-btn', voiceRecording ? 'recording' : '']"
                    @click="voiceRecording ? stopVoiceRecording() : startVoiceRecording()"
                  >
                    <template v-if="voiceRecording">■ {{ Math.floor(voiceRecordSeconds/60) }}:{{ String(voiceRecordSeconds%60).padStart(2,'0') }}</template>
                    <template v-else>● Record</template>
                  </button>
                </div>

                <!-- Local preview before uploading -->
                <div v-else-if="voiceUploadStatus === 'previewing'" class="voice-preview-panel">
                  <div class="voice-preview-head">
                    <span class="voice-preview-icon">🎧</span>
                    <span class="voice-preview-title">Preview</span>
                    <span class="voice-preview-name">{{ voicePreviewName }}</span>
                  </div>

                  <!-- Custom audio player -->
                  <audio
                    ref="voicePreviewRef"
                    :src="voicePreviewUrl"
                    preload="metadata"
                    @timeupdate="onVoicePreviewTime"
                    @loadedmetadata="onVoicePreviewTime"
                    @play="voicePreviewPlaying = true"
                    @pause="voicePreviewPlaying = false"
                    @ended="voicePreviewPlaying = false"
                  ></audio>
                  <div class="vp-player">
                    <button type="button" class="vp-play-btn" @click="toggleVoicePreviewPlay">
                      <span v-if="voicePreviewPlaying">⏸</span>
                      <span v-else>▶</span>
                    </button>
                    <div class="vp-track" @click="onVoicePreviewSeek">
                      <div class="vp-fill" :style="{ width: (voicePreviewDuration ? (voicePreviewCurrent / voicePreviewDuration) * 100 : 0) + '%' }"></div>
                    </div>
                    <span class="vp-time">{{ fmtVoicePreviewTime(voicePreviewCurrent) }} / {{ fmtVoicePreviewTime(voicePreviewDuration) }}</span>
                  </div>

                  <div class="voice-preview-actions">
                    <button class="btn btn-primary btn-sm vp-action" @click="confirmUploadFromPreview">
                      ✓ Use this audio
                    </button>
                    <button class="btn btn-ghost btn-sm vp-action" @click="cancelVoiceUpload">
                      ↻ Redo
                    </button>
                  </div>
                  <div class="voice-preview-hint">
                    Captions and transcript are generated automatically after you confirm.
                  </div>
                </div>

                <!-- Status banners during upload/transcription -->
                <div v-else-if="voiceUploadStatus === 'uploading'" class="voice-status">
                  ⏳ Uploading…
                </div>
                <div v-else-if="voiceUploadStatus === 'transcribing'" class="voice-status">
                  <span class="voice-status-spin">◐</span> Transcribing for captions…
                  <button class="voice-status-cancel" @click="cancelVoiceUpload">Cancel</button>
                </div>
                <div v-else-if="voiceUploadStatus === 'ready'" class="voice-ready-panel">
                  <div class="voice-ready-head">
                    <span>✓ Ready</span>
                    <button class="voice-status-cancel" @click="cancelVoiceUpload">Discard</button>
                  </div>
                  <div v-if="voiceUploadAsset?.transcript_text" class="voice-transcript-preview">
                    <div class="voice-transcript-label">Transcript</div>
                    <div class="voice-transcript-text">{{ voiceUploadAsset.transcript_text.slice(0, 220) }}{{ voiceUploadAsset.transcript_text.length > 220 ? '…' : '' }}</div>
                  </div>
                  <div class="voice-ready-actions">
                    <button class="btn btn-primary btn-sm" @click="applyVoiceUpload({ updateScript: true })">
                      Use voice + update script
                    </button>
                    <button class="btn btn-ghost btn-sm" @click="applyVoiceUpload({ updateScript: false })">
                      Use voice only
                    </button>
                  </div>
                </div>
                <div v-else-if="voiceUploadStatus === 'error'" class="voice-error">
                  <div>⚠ {{ voiceUploadError }}</div>
                  <button class="voice-status-cancel" @click="cancelVoiceUpload">Dismiss</button>
                </div>

                <!-- Browse previously uploaded audio -->
                <div class="micro-label" style="margin-top:14px;margin-bottom:6px;">
                  Previously uploaded
                </div>
                <button
                  v-if="customAudioAssets.length === 0 && !customAudioLoading"
                  class="btn btn-ghost btn-sm panel-full-btn"
                  type="button"
                  @click="loadCustomAudioAssets"
                >Browse audio assets</button>
                <template v-else>
                  <input v-model="customAudioSearch" class="asset-search-input" placeholder="Search audio…" style="margin-bottom:6px;" />
                  <div v-if="customAudioLoading" class="asset-loading">Loading...</div>
                  <div v-else-if="customAudioAssetsFiltered.length === 0" class="asset-empty">No audio assets found.</div>
                  <div v-else class="custom-audio-list">
                    <div
                      v-for="asset in customAudioAssetsFiltered"
                      :key="asset.id"
                      :class="['custom-audio-row', activeScene?.voice_settings?.audio_asset_id === asset.id && activeScene?.voice_settings?.custom_audio ? 'active' : '']"
                      @click="assignCustomAudio(asset)"
                    >
                      <span class="custom-audio-icon">🎵</span>
                      <span class="custom-audio-name">{{ asset.title || 'Untitled' }}</span>
                      <span v-if="activeScene?.voice_settings?.audio_asset_id === asset.id && activeScene?.voice_settings?.custom_audio" class="custom-audio-check">✓</span>
                    </div>
                  </div>
                </template>
                </template>

                <!-- Save as voice profile (default tab only) -->
                <template v-if="voiceTab === 'default'">
                <div class="preset-save-row" style="margin-top:12px;">
                  <template v-if="!voiceProfileSaveOpen">
                    <button class="btn btn-ghost btn-sm" type="button" @click="voiceProfileSaveOpen = true">
                      + Save voice as profile
                    </button>
                  </template>
                  <template v-else>
                    <input
                      v-model="voiceProfileSaveName"
                      class="preset-name-input"
                      placeholder="Profile name…"
                      maxlength="80"
                      @keydown.enter="saveVoiceProfile"
                      @keydown.escape="voiceProfileSaveOpen = false; voiceProfileSaveName = ''"
                    />
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      :disabled="voiceProfileSaving || !voiceProfileSaveName.trim()"
                      @click="saveVoiceProfile"
                    >{{ voiceProfileSaving ? "Saving…" : "Save" }}</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="voiceProfileSaveOpen = false; voiceProfileSaveName = ''">Cancel</button>
                  </template>
                </div>
                </template>
              </div>
            </div>

            <!-- Sounds panel — per-scene SFX -->
            <div :class="`panel-section ${panelState.sounds ? 'collapsed' : ''}`">
              <div class="panel-section-header" @click="togglePanel('sounds')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Sounds</div>
                  <span v-if="activeSceneSoundAsset" class="panel-badge new">Set</span>
                  <span class="panel-scope-hint">Per scene</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Currently assigned sound -->
                <template v-if="activeSceneSoundAsset">
                  <div class="sound-current-row">
                    <span class="sound-current-icon">🔊</span>
                    <div class="sound-current-info">
                      <div class="sound-current-name">{{ activeSceneSoundAsset.title }}</div>
                      <div class="sound-current-meta">Sound effect · This scene</div>
                    </div>
                    <button class="sound-remove-btn" type="button" title="Remove sound" @click="assignSceneSound(null)">✕</button>
                  </div>
                  <div class="control-row top-space">
                    <span class="control-name">Volume</span>
                    <div class="volume-slider-wrap">
                      <input v-model.number="sceneSoundVolume" type="range" class="music-slider" min="0" max="200" @input="syncSceneSoundVolume(); scheduleSceneSoundVolumeSave()" />
                      <span class="music-slider-val">{{ sceneSoundVolume }}%</span>
                    </div>
                  </div>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="openMediaPicker('sound')">
                    Change Sound
                  </button>
                </template>
                <template v-else>
                  <div class="asset-empty-state">
                    <div class="asset-empty-icon">🔊</div>
                    <div class="asset-empty-title">No sound effect</div>
                    <div class="asset-empty-sub">Add a sound that plays alongside the voice for this scene</div>
                  </div>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="openMediaPicker('sound')">
                    Pick Sound
                  </button>
                </template>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.captions ? 'collapsed' : ''}`"
            >
              <div
                class="panel-section-header"
                @click="togglePanel('captions')"
              >
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Captions</div>
                  <span class="panel-scope-hint">All scenes</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Preset selector -->
                <label v-if="activeScene?.caption_settings_json?.ugc_headline" class="control-name" style="display:block;margin-bottom:12px;">
                  UGC headline · this scene only
                  <textarea :key="activeScene.id" :data-scene-id="activeScene.id" class="scene-query-input"
                    :value="activeScene.caption_settings_json.ugc_headline.text" maxlength="180"
                    :disabled="captionSaveState === 'saving'" @change="saveUgcHeadline"
                    style="width:100%;min-height:70px;margin-top:6px;" aria-label="UGC headline" />
                  <span class="panel-scope-hint">Not spoken. Saves when you leave the field; no media regeneration.</span>
                </label>
                <div v-if="captionPresets.length > 0" class="preset-row">
                  <UiSelect :modelValue="''" :options="captionPresetOptions" placeholder="Apply a preset…" @update:modelValue="onApplyCaptionPreset" />
                  <button
                    v-for="p in captionPresets"
                    :key="'del-'+p.id"
                    v-show="false"
                  ></button>
                </div>
                <!-- Preset management: delete chips -->
                <div v-if="captionPresets.length > 0" class="preset-chips">
                  <div v-for="p in captionPresets" :key="p.id" class="preset-chip">
                    <span class="preset-chip-name" @click="applyCaptionPreset(p)">{{ p.name }}</span>
                    <button
                      class="preset-chip-del"
                      type="button"
                      :disabled="captionPresetDeleting === p.id"
                      @click.stop="deleteCaptionPreset(p.id)"
                    >✕</button>
                  </div>
                </div>
                <!-- Save as preset -->
                <div class="preset-save-row">
                  <template v-if="!captionPresetSaveOpen">
                    <button class="btn btn-ghost btn-sm" type="button" @click="captionPresetSaveOpen = true">
                      + Save as preset
                    </button>
                  </template>
                  <template v-else>
                    <input
                      v-model="captionPresetSaveName"
                      class="preset-name-input"
                      placeholder="Preset name…"
                      maxlength="80"
                      @keydown.enter="saveCaptionPreset"
                      @keydown.escape="captionPresetSaveOpen = false; captionPresetSaveName = ''"
                    />
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      :disabled="captionPresetSaving || !captionPresetSaveName.trim()"
                      @click="saveCaptionPreset"
                    >{{ captionPresetSaving ? "Saving…" : "Save" }}</button>
                    <button class="btn btn-ghost btn-sm" type="button" @click="captionPresetSaveOpen = false; captionPresetSaveName = ''">Cancel</button>
                  </template>
                </div>
                <div class="caption-toggle-row">
                  <span></span>
                  <label class="caption-toggle">
                    <input v-model="captionEnabledDraft" type="checkbox" />
                    <span>On</span>
                  </label>
                </div>
                <div class="micro-label" style="margin-bottom:6px;">Caption effect</div>
                <div class="caption-anim-row">
                  <button
                    v-for="anim in captionPanelRow"
                    :key="anim.key"
                    type="button"
                    :class="['caption-anim-tile', captionAnimationDraft === anim.key ? 'active' : '']"
                    :title="anim.desc"
                    @click="selectCaptionAnimation(anim.key)"
                  >
                    <span class="caption-anim-sample">
                      <CaptionPreview :demo="true" :settings="{ animation: anim.key, highlight_color: captionHighlightColorDraft }" />
                    </span>
                    <span class="caption-anim-name">{{ anim.name }}</span>
                  </button>
                  <button type="button" class="caption-anim-tile caption-anim-more" @click="captionEffectModalOpen = true">
                    <span class="caption-anim-sample caption-anim-more-icon">⊞</span>
                    <span class="caption-anim-name">View all</span>
                  </button>
                </div>
                <div class="control-row top-space">
                  <span class="control-name">Highlight style</span>
                  <UiSelect v-model="captionHighlightStyleDraft" :options="CAPTION_HIGHLIGHT_STYLE_OPTIONS" />
                </div>
                <div class="caption-toggle-row">
                  <span class="control-name">Backdrop panel</span>
                  <label class="caption-toggle">
                    <input v-model="captionBackdropChecked" type="checkbox" />
                    <span>{{ captionBackdropChecked ? "On" : "Off" }}</span>
                  </label>
                </div>
                <div v-if="captionBackdropChecked">
                  <div class="micro-label" style="margin:8px 0 6px;">Panel color</div>
                  <div class="caption-color-row">
                    <div
                      v-for="swatch in CAPTION_PANEL_SWATCHES"
                      :key="'pc-' + (swatch || 'auto')"
                      :class="['color-swatch', (captionPanelColorDraft || '') === swatch ? 'active' : '', swatch === '' ? 'swatch-auto' : '']"
                      :style="swatch ? { background: swatch, borderColor: swatch === '#ffffff' ? '#555' : swatch } : {}"
                      :title="swatch || 'Auto (preset default)'"
                      @click="captionPanelColorDraft = swatch"
                    ></div>
                  </div>
                </div>
                <div class="micro-label" style="margin:10px 0 6px;">Base style</div>
                <div class="caption-style-grid">
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'impact' ? 'active' : '']"
                    @click="captionStyleDraft = 'impact'"
                  >
                    <div class="preview-text accent-text">Impact</div>
                    <div class="style-name">Impact</div>
                  </div>
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'editorial' ? 'active' : '']"
                    @click="captionStyleDraft = 'editorial'"
                  >
                    <div class="preview-text serif-text">Editorial</div>
                    <div class="style-name">Editorial</div>
                  </div>
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'hacker' ? 'active' : '']"
                    @click="captionStyleDraft = 'hacker'"
                  >
                    <div class="preview-text mono-text">Hacker</div>
                    <div class="style-name">Hacker</div>
                  </div>
                </div>
                <div class="control-row top-space">
                  <span class="control-name">Highlight</span>
                  <UiSelect v-model="captionHighlightDraft" :options="CAPTION_HIGHLIGHT_OPTIONS" />
                </div>
                <div class="control-row">
                  <span class="control-name">Position</span>
                  <UiSelect v-model="captionPositionDraft" :options="CAPTION_POSITION_OPTIONS" />
                </div>
                <!-- Font picker -->
                <div class="font-picker-block">
                  <div class="micro-label" style="margin-bottom:6px;">Font</div>
                  <div class="font-dropdown">
                    <button
                      type="button"
                      class="font-dropdown-trigger"
                      @click="fontDropdownOpen = !fontDropdownOpen"
                    >
                      <span class="font-trigger-copy">
                        <span class="font-trigger-name" :style="captionFamilyStyle">
                          {{ selectedCaptionFont.font }}
                        </span>
                        <span class="font-trigger-group">{{ selectedCaptionFont.group }}</span>
                      </span>
                      <span class="font-trigger-chevron">▾</span>
                    </button>
                    <div v-if="fontDropdownOpen" class="font-dropdown-menu">
                      <div
                        v-for="group in CAPTION_FONT_GROUPS"
                        :key="group.label"
                        class="font-group"
                      >
                        <div class="font-group-label">{{ group.label }}</div>
                        <button
                          v-for="font in group.fonts"
                          :key="font"
                          type="button"
                          :class="['font-option', captionFontDraft === font ? 'active' : '']"
                          @click="selectCaptionFont(font)"
                        >
                          <span class="font-option-preview" :style="{ fontFamily: fontFamilyValue(font) }">
                            {{ font }}
                          </span>
                          <span class="font-option-name">{{ font }}</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Caption text color -->
                <div class="micro-label" style="margin-top:12px;margin-bottom:6px;">Text color</div>
                <div class="caption-color-row">
                  <div
                    v-for="swatch in CAPTION_COLOR_SWATCHES"
                    :key="'tc-'+swatch"
                    :class="['color-swatch', captionColorDraft === swatch ? 'active' : '']"
                    :style="{ background: swatch, borderColor: swatch === '#ffffff' ? '#555' : swatch }"
                    @click="captionColorDraft = swatch"
                  ></div>
                  <label class="color-custom-wrap" title="Custom color">
                    <span class="color-custom-preview color-custom-square" :style="{ background: captionColorDraft }"></span>
                    <input type="color" v-model="captionColorDraft" class="color-custom-input" />
                  </label>
                </div>

                <!-- Highlight color -->
                <div class="micro-label" style="margin-top:10px;margin-bottom:6px;">Highlight color</div>
                <div class="caption-color-row">
                  <div
                    v-for="swatch in CAPTION_COLOR_SWATCHES"
                    :key="'hc-'+swatch"
                    :class="['color-swatch', captionHighlightColorDraft === swatch ? 'active' : '']"
                    :style="{ background: swatch, borderColor: swatch === '#ffffff' ? '#555' : swatch }"
                    @click="captionHighlightColorDraft = swatch"
                  ></div>
                  <label class="color-custom-wrap" title="Custom highlight color">
                    <span class="color-custom-preview color-custom-square" :style="{ background: captionHighlightColorDraft }"></span>
                    <input type="color" v-model="captionHighlightColorDraft" class="color-custom-input" />
                  </label>
                </div>

                <!-- Caption size -->
                <div class="micro-label" style="margin-top:10px;margin-bottom:6px;">Text size</div>
                <div class="caption-size-row">
                  <button
                    v-for="sz in [['small','S'],['medium','M'],['large','L'],['xlarge','XL']]"
                    :key="sz[0]"
                    type="button"
                    :class="['size-opt', captionSizeDraft === sz[0] ? 'active' : '']"
                    @click="captionSizeDraft = sz[0]"
                  >{{ sz[1] }}</button>
                </div>
                <!-- A custom size between the presets (S 13, M 17, L 23, XL 30); the preview and export follow it. -->
                <div class="caption-size-custom">
                  <input
                    type="range" min="8" max="48" step="1" aria-label="Custom caption size"
                    :value="captionBasePx(captionSizeDraft)"
                    @input="captionSizeDraft = String($event.target.value)"
                  />
                  <span :class="['caption-size-value', captionSizeIsCustom ? 'active' : '']">{{ captionSizeIsCustom ? `Custom · ${captionSizeDraft}` : captionBasePx(captionSizeDraft) }}</span>
                </div>

                <!-- Apply the current caption settings to every scene at once -->
                <button
                  type="button"
                  class="caption-apply-all"
                  :disabled="applyAllState === 'applying'"
                  @click="applyCaptionsToAllScenes"
                >{{ applyAllState === 'applying' ? 'Applying…' : applyAllState === 'done' ? '✓ Applied to all scenes' : applyAllState === 'error' ? 'Failed — retry' : 'Apply to all scenes' }}</button>

              </div>
            </div>

            <div
              :class="['panel-section', panelState.music ? 'collapsed' : '', cruisePulseClass('music')]"
            >
              <div class="panel-section-header" @click="togglePanel('music')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Music</div>
                  <span class="panel-scope-hint">All scenes</span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <!-- Selected track summary -->
                <div class="music-selected-summary">
                  <div class="music-selected-copy">
                    <span class="music-selected-label">Now</span>
                    <strong>{{ activeMusicTrack ? activeMusicTrack.title : "No music" }}</strong>
                  </div>
                  <span>{{ activeMusicTrack ? trackMoodLabel(activeMusicTrack) : "Silence" }}</span>
                </div>

                <!-- Music panel tabs -->
                <div class="music-panel-tabs">
                  <button type="button"
                    :class="['music-panel-tab', musicPanelTab === 'library' ? 'active' : '']"
                    @click="musicPanelTab = 'library'"
                  >Library</button>
                  <button type="button"
                    :class="['music-panel-tab', musicPanelTab === 'uploads' ? 'active' : '']"
                    @click="musicPanelTab = 'uploads'; openMediaPicker('music')"
                  >My Uploads</button>
                  <button type="button"
                    :class="['music-panel-tab', musicPanelTab === 'ai' ? 'active' : '']"
                    @click="musicPanelTab = 'ai'"
                  >✦ AI Generate</button>
                </div>

                <!-- AI Music tab -->
                <template v-if="musicPanelTab === 'ai'">
                  <div class="micro-label" style="margin:8px 0 6px;">Mood / genre</div>
                  <div class="ai-music-moods">
                    <button v-for="m in AI_MUSIC_MOODS" :key="m"
                      type="button"
                      :class="['ai-music-mood-chip', aiMusicMood === m ? 'selected' : '']"
                      @click="aiMusicMood = m"
                    >{{ m }}</button>
                  </div>
                  <input
                    v-model="aiMusicMood"
                    class="scene-query-input"
                    style="margin-top:8px;"
                    maxlength="100"
                    placeholder="…or type your own: e.g. tense neon synth wave"
                  />
                  <div style="font-size:11px;color:var(--text-muted);margin:6px 0 10px;line-height:1.5;">
                    Replaces the current background music.<template v-if="aiMusicCost"> Costs {{ aiMusicCost }} credit{{ aiMusicCost === 1 ? '' : 's' }}.</template> Generation takes ~30s; you'll see it appear when ready.
                  </div>
                  <button class="btn btn-primary btn-sm panel-full-btn" type="button"
                    :disabled="aiMusicPending || !aiMusicMood.trim()"
                    @click="regenerateAIMusic"
                  >
                    {{ aiMusicPending ? '✦ Generating…' : (aiMusicCost ? `✦ Generate music (${aiMusicCost} cr)` : '✦ Generate music') }}
                  </button>
                  <div v-if="aiMusicError" class="panel-error-copy" style="margin-top:8px;">{{ aiMusicError }}</div>
                </template>

                <!-- Library tab -->
                <template v-if="musicPanelTab === 'library'">
                  <!-- Mood filter chips -->
                  <div class="music-filter-row">
                    <div v-for="mood in ['all','dark','upbeat','calm','epic']" :key="mood"
                      :class="['filter-chip', musicMoodFilter === mood ? 'active' : '']"
                      @click="musicMoodFilter = mood"
                    >{{ mood.charAt(0).toUpperCase() + mood.slice(1) }}</div>
                  </div>

                  <!-- Track list -->
                  <div class="music-track-scroll">
                    <!-- No music option -->
                    <div :class="['music-track', selectedMusicTrackId === null ? 'selected' : '']"
                      @click="selectMusicTrack(null)">
                      <div class="music-track-thumb" style="background:var(--bg-elevated);">🚫</div>
                      <div class="music-track-info">
                        <div class="music-track-name">No music</div>
                        <div class="music-track-meta">Silence</div>
                      </div>
                      <button class="music-play-btn" type="button" disabled @click.stop>▶</button>
                    </div>
                    <div v-for="group in musicTrackGroups" :key="group.mood" class="music-category">
                      <div class="music-category-title">
                        <span>{{ moodEmoji(group.mood) }}</span>
                        {{ moodLabel(group.mood) }}
                      </div>
                      <div class="music-track-list">
                        <div v-for="track in group.tracks" :key="track.id"
                          :class="['music-track', selectedMusicTrackId === track.id ? 'selected' : '']"
                          @click="selectMusicTrack(track.id)">
                          <div class="music-track-thumb" :style="{ background: moodGradient((track.tags ?? []).find(t => t !== 'music')) }">{{ moodEmoji((track.tags ?? []).find(t => t !== 'music')) }}</div>
                          <div class="music-track-info">
                            <div class="music-track-name">{{ track.title }}</div>
                            <div class="music-track-meta">{{ trackMoodLabel(track) }}</div>
                          </div>
                          <div class="music-track-duration">{{ formatTrackDuration(track.duration_seconds) }}</div>
                          <button class="music-play-btn" type="button" @click.stop="toggleMusicAudition(track)">
                            {{ auditionMusicTrackId === track.id ? "⏸" : "▶" }}
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>

                <!-- Music controls -->
                <div class="music-controls">
                  <div class="music-control-row">
                    <span class="music-control-label">Volume</span>
                    <input v-model.number="musicVolume" type="range" class="music-slider" min="0" max="100" @input="scheduleMusicSave(); syncPreviewMusicVolume()" />
                    <span class="music-slider-val">{{ musicVolume }}%</span>
                  </div>
                  <div class="music-control-row">
                    <span class="music-control-label">Duck vol.</span>
                    <input v-model.number="musicDuckVolume" type="range" class="music-slider" min="0" max="50" @input="scheduleMusicSave(); syncPreviewMusicVolume()" />
                    <span class="music-slider-val">{{ musicDuckVolume }}%</span>
                  </div>
                  <div class="music-control-row">
                    <span class="music-control-label">Fade in</span>
                    <input v-model.number="musicFadeInMs" type="range" class="music-slider" min="0" max="2000" step="100" @input="scheduleMusicSave" />
                    <span class="music-slider-val">{{ (musicFadeInMs / 1000).toFixed(1) }}s</span>
                  </div>
                  <div class="music-control-row" style="margin-top:6px;">
                    <span class="music-control-label">Loop track</span>
                    <div style="flex:1;"></div>
                    <label class="toggle-wrap">
                      <input v-model="musicLoop" type="checkbox" class="toggle-input" @change="scheduleMusicSave" />
                      <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                  </div>
                  <div class="music-control-row">
                    <span class="music-control-label">Duck voice</span>
                    <div style="flex:1;"></div>
                    <label class="toggle-wrap">
                      <input v-model="musicDuckDuringVoice" type="checkbox" class="toggle-input" @change="scheduleMusicSave" />
                      <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                  </div>
                  <div v-if="musicSaveError" class="micro-error">{{ musicSaveError }}</div>
                  <div v-if="musicSaveState === 'saved'" class="micro-copy" style="margin-top:4px;">Saved</div>
                </div>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.brand ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('brand')">
                <div class="panel-label panel-label-tight">Brand Kit</div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="control-row">
                  <span class="control-name">Channel</span>
                  <UiSelect v-model="projectChannelId" :options="channelOptions" />
                </div>
                <div class="control-row">
                  <span class="control-name">Brand Kit</span>
                  <UiSelect v-model="projectBrandKitId" :options="brandKitOptions" />
                </div>
                <div v-if="selectedProjectChannel" class="micro-copy">
                  Channel defaults can prefill brand kit for future changes. Existing scenes are not rewritten automatically.
                </div>
                <div v-if="projectDefaultsSaveError" class="micro-error">{{ projectDefaultsSaveError }}</div>
                <button class="btn btn-ghost btn-full" type="button" @click="saveProjectDefaults">
                  {{ projectDefaultsSaveState === "saving" ? "Saving…" : projectDefaultsSaveState === "saved" ? "Saved" : "Save Defaults" }}
                </button>
                <div v-if="hasScriptForHooks" class="hooks-block">
                  <div class="hooks-title-row" style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                    <span class="micro-label hooks-title">Hook options{{ sortedHookOptions.length ? ' — sorted by score' : '' }}</span>
                    <button class="btn btn-ghost btn-sm" type="button" :disabled="hooksGenerating" @click="regenerateHooks" :title="'Write and score fresh hooks from your script — no credits'">
                      {{ hooksGenerating ? "Generating…" : sortedHookOptions.length ? "↻ Regenerate" : "✨ Generate hooks" }}
                    </button>
                  </div>
                  <div v-if="!sortedHookOptions.length && !hooksGenerating" class="hook-card-reason" style="margin-top:6px">
                    Generate 3–10 ranked hook options, then spin the best into variants.
                  </div>
                  <div
                    v-for="option in sortedHookOptions"
                    :key="option.id"
                    class="hook-card"
                  >
                    <div class="hook-card-top">
                      <span v-if="option.hook_score != null" :class="['hook-score-badge', hookScoreClass(option.hook_score)]">{{ option.hook_score }}</span>
                      <span v-else class="hook-score-badge score-none">—</span>
                      <span class="hook-card-text">{{ option.hook_text }}</span>
                    </div>
                    <div v-if="option.hook_score_reason" class="hook-card-reason">{{ option.hook_score_reason }}</div>
                    <button class="btn btn-ghost btn-sm hook-use-btn" type="button" @click="useHook(option)">Use Hook</button>
                  </div>
                </div>
              </div>
            </div>

            </div>
            <!-- /cruise-config-view -->

            <!-- Cruise Control Assistant — Phase 1B chat UI.
                 Scope bar → message list → input. Action cards inline
                 with each assistant turn that proposes one. -->
            <div v-show="cruiseTab === 'assistant'" class="cruise-assistant-view">
              <div class="cruise-scope-bar">
                <span class="cruise-scope-label">Editing</span>
                <div class="cruise-scope-select-wrap">
                  <UiSelect v-model="cruiseScopeValue" :options="cruiseScopeOptions" align="left" />
                  <span class="cruise-scope-caret">▾</span>
                </div>
                <span class="cruise-scope-spacer"></span>
                <button v-if="cruiseMessages.length" class="cruise-scope-action" type="button" @click="cruiseResetConversation" title="Start a new chat (your video changes stay)">↺ New chat</button>
              </div>

              <div class="cruise-assistant-body" ref="cruiseChatScrollRef">
                <!-- Empty state until the user types something -->
                <div v-if="cruiseMessages.length === 0" class="cruise-coming-soon">
                  <div class="cruise-coming-icon">✦</div>
                  <div class="cruise-coming-title">What should I change?</div>
                  <div class="cruise-coming-body">
                    Try: <em>"swap voice on scene 2 to onyx"</em>,
                    <em>"change music to upbeat indie pop"</em>,
                    or <em>"use my logo asset on scene 1"</em>.
                  </div>
                </div>

                <div v-for="m in cruiseMessages" :key="m.id" :class="['cruise-msg', `cruise-msg-${m.role}`]">
                  <div class="cruise-msg-avatar">{{ m.role === 'user' ? (mePayload?.name?.[0] ?? 'U') : '✦' }}</div>
                  <div class="cruise-msg-body">
                    <div class="cruise-msg-text">{{ m.text }}</div>

                    <!-- Action cards: one per entry in m.actions[]. Stacked
                         vertically. Multi-action turns get an "Apply all"
                         master footer that runs them sequentially. -->
                    <div v-if="(m.actions || []).length" class="cruise-actions-stack">
                      <div v-if="m.actions.length > 1" class="cruise-actions-meta">
                        Plan: {{ m.actions.length }} actions · ~{{ cruiseTotalCost(m) }} cr total
                      </div>
                      <div v-for="(card, idx) in m.actions" :key="idx" class="cruise-action">
                        <div class="cruise-action-head">
                          <span class="cruise-action-icon">{{ cruiseActionIcon(card.affected_section) }}</span>
                          <span class="cruise-action-step" v-if="m.actions.length > 1">{{ idx + 1 }}.</span>
                          <span class="cruise-action-title">{{ cruiseActionTitle(card.tool) }}</span>
                          <span class="cruise-action-cost">~{{ card.estimated_cost }} cr</span>
                        </div>
                        <div class="cruise-action-body">
                          <div v-for="(line, i) in card.diff_lines" :key="i">{{ line }}</div>
                        </div>
                        <div v-if="card.notice && card.status === 'proposed'" class="cruise-action-body cruise-action-shortfall">{{ card.notice }}</div>
                        <div v-if="card.status === 'running'" class="cruise-action-status running">
                          <ul v-if="(card.expected_stages || []).length > 1" class="cruise-action-checklist">
                            <li v-for="stage in card.expected_stages" :key="stage"
                                :class="['cruise-checklist-item', (card.completed_stages || []).includes(stage) ? 'done' : 'pending']">
                              <span class="cruise-checklist-icon">
                                <span v-if="(card.completed_stages || []).includes(stage)">✓</span>
                                <span v-else class="cruise-action-spinner"></span>
                              </span>
                              <span class="cruise-checklist-label">{{ cruiseStageLabel(stage, (card.completed_stages || []).includes(stage)) }}</span>
                            </li>
                          </ul>
                          <template v-else>
                            <span class="cruise-action-spinner"></span>
                            <span class="cruise-action-running-text">{{ card.progress_text || 'Working…' }}<span class="cruise-action-dots"><span>.</span><span>.</span><span>.</span></span></span>
                          </template>
                        </div>
                        <div v-else-if="card.status === 'applied'" class="cruise-action-status applied">
                          <span class="cruise-action-status-text">✓ {{ card.progress_text || 'Applied' }} · spent {{ card.credits || card.estimated_cost }} cr</span>
                          <button v-if="cruiseCanUndo(card)" class="cruise-action-undo" type="button" :disabled="card._undoing" @click="cruiseUndoAction(m, idx)">
                            {{ card._undoing ? 'Undoing…' : '↩ Undo' }}
                          </button>
                        </div>
                        <div v-else-if="card.status === 'undone'" class="cruise-action-status undone">
                          ↩ Undone
                        </div>
                        <!-- We stopped watching; the server may not have stopped
                             working. No Retry button here on purpose — that is
                             the button that pays for the same output twice. -->
                        <div v-else-if="card.status === 'unknown'" class="cruise-action-status unknown">
                          <span class="cruise-action-fail-msg">⏱ {{ card.error }}</span>
                        </div>
                        <div v-else-if="card.status === 'failed'" class="cruise-action-status failed">
                          <div class="cruise-action-fail-row">
                            <span class="cruise-action-fail-msg">✕ {{ cruiseFriendlyError(card.error) }}</span>
                            <button class="cruise-action-undo" type="button" :disabled="card._retrying || cruiseApplying" @click="cruiseRetryAction(m, idx)">
                              {{ card._retrying ? 'Retrying…' : '↻ Retry' }}
                            </button>
                          </div>
                          <!-- Raw provider error — admins only. -->
                          <div v-if="isAdmin && card.error" class="cruise-action-fail-detail">{{ card.error }}</div>
                        </div>
                        <div v-else-if="card.status === 'skipped'" class="cruise-action-status skipped">
                          ⏭ Skipped
                        </div>
                        <div v-else-if="cruiseUserBalance < (card.estimated_cost ?? 0)" class="cruise-action-foot">
                          <span class="cruise-action-shortfall">Need {{ card.estimated_cost }} cr · you have {{ cruiseUserBalance }}</span>
                          <button class="cruise-action-btn cruise-action-btn-primary" type="button" @click="router.push({ name: 'settings', query: { section: 'billing' } })">
                            Top up →
                          </button>
                        </div>
                        <div v-else class="cruise-action-foot">
                          <button class="cruise-action-btn cruise-action-btn-ghost" @click="cruiseSkipAction(m, idx)">Skip</button>
                          <button class="cruise-action-btn cruise-action-btn-primary" :disabled="cruiseApplying" @click="cruiseApplyAction(m, idx)">
                            {{ cruiseApplying === `${m.id}:${idx}` ? 'Applying…' : 'Apply' }}
                          </button>
                        </div>
                      </div>
                      <!-- Apply all master footer: only when >1 still-proposed -->
                      <div v-if="cruiseProposedCount(m) > 1 && cruiseCanApplyAll(m)" class="cruise-actions-master-foot">
                        <button class="cruise-action-btn cruise-action-btn-ghost" :disabled="cruiseApplying" @click="cruiseSkipAll(m)">Skip all</button>
                        <button class="cruise-action-btn cruise-action-btn-primary" :disabled="cruiseApplying" @click="cruiseApplyAll(m)">
                          Apply all ({{ cruiseProposedCount(m) }}) →
                        </button>
                      </div>
                    </div>
                  </div>
                </div>

                <div v-if="cruiseResolving" class="cruise-msg cruise-msg-ai">
                  <div class="cruise-msg-avatar">✦</div>
                  <div class="cruise-msg-body">
                    <div class="cruise-msg-text cruise-msg-thinking">Thinking…</div>
                  </div>
                </div>
              </div>

              <!-- Quick-prompt chips: only render when the chat is empty so
                   they fight blank-box paralysis without nagging on every turn. -->
              <div v-if="cruiseMessages.length === 0" class="cruise-quick-row">
                <span v-for="qp in CRUISE_QUICK_PROMPTS" :key="qp" class="cruise-quick-chip" @click="cruiseUseQuickPrompt(qp)">
                  {{ qp }}
                </span>
              </div>

              <div class="cruise-input-wrap">
                <div class="cruise-input-box">
                  <textarea
                    v-model="cruiseInputText"
                    class="cruise-input"
                    rows="2"
                    placeholder="Describe what you want to change…"
                    @keydown.meta.enter.prevent="cruiseSubmitIntent"
                    @keydown.ctrl.enter.prevent="cruiseSubmitIntent"
                  ></textarea>
                  <div class="cruise-input-row">
                    <button class="cruise-input-icon" type="button" :class="{ active: cruiseBriefOpen }" @click="cruiseToggleBrief" title="Theme & creative brief">
                      🎯<span v-if="cruiseBriefLocked" class="cruise-icon-dot" title="Brief locked"></span>
                    </button>
                    <button class="cruise-input-icon" type="button" :class="{ active: cruisePrefsOpen }" @click="cruiseTogglePrefs" title="Assistant settings">⚙</button>
                    <button class="cruise-input-send" :disabled="!cruiseInputText.trim() || cruiseResolving" @click="cruiseSubmitIntent">Send ⌘⏎</button>
                  </div>
                </div>
              </div>

              <!-- Footer: auto-apply pref + balance hint -->
              <div class="cruise-assistant-foot">
                <label class="cruise-auto-toggle" :title="cruiseAutoApply ? 'Cheap, reversible edits apply immediately. Paid/structural changes still prompt.' : 'Every action shows an Apply button before running.'">
                  <input type="checkbox" :checked="cruiseAutoApply" @change="setCruiseAutoApply($event.target.checked)" />
                  <span>Auto-apply low-risk</span>
                </label>
                <span class="cruise-balance-hint">{{ cruiseUserBalance }} cr</span>
              </div>

              <!-- Theme / creative-brief popover — opened by the 🎯 icon on
                   the input row. Editing locks it; refresh re-derives it. -->
              <div v-if="cruiseBriefOpen && cruiseBrief" class="cruise-prefs-panel">
                <div class="cruise-prefs-head">
                  <span>Theme &amp; brief<span v-if="cruiseBriefLocked" class="cruise-brief-lock" title="Locked — you edited it"> 🔒</span></span>
                  <button class="cruise-prefs-close" type="button" @click="cruiseBriefOpen = false">✕</button>
                </div>
                <div class="cruise-brief-sub">Keeps new scenes &amp; regenerations on-theme unless you override it in a message.</div>
                <label class="cruise-brief-field"><span>Theme</span>
                  <input type="text" v-model="cruiseBrief.theme" placeholder="e.g. hand-drawn doodle explainer" maxlength="200" /></label>
                <label class="cruise-brief-field"><span>Topic</span>
                  <input type="text" v-model="cruiseBrief.topic" placeholder="what the video is about" maxlength="200" /></label>
                <label class="cruise-brief-field"><span>Visual style</span>
                  <input type="text" v-model="cruiseBrief.visual_style" placeholder="e.g. doodle / marker on whiteboard" maxlength="200" /></label>
                <label class="cruise-brief-field"><span>Tone</span>
                  <input type="text" v-model="cruiseBrief.tone" placeholder="e.g. friendly, educational" maxlength="200" /></label>
                <label class="cruise-brief-field"><span>Recurring subject</span>
                  <input type="text" v-model="cruiseBrief.recurring_subject" placeholder="e.g. a hand drawing on a whiteboard" maxlength="200" /></label>
                <div class="cruise-brief-foot">
                  <button class="cruise-action-btn cruise-action-btn-ghost" type="button" :disabled="cruiseBriefBusy" @click="cruiseRefreshBrief">↻ Refresh from scenes</button>
                  <button class="cruise-action-btn cruise-action-btn-primary" type="button" :disabled="cruiseBriefBusy" @click="cruiseSaveBrief">Save &amp; lock</button>
                </div>
              </div>

              <!-- Preferences popover — defaults the LLM biases towards
                   when the user doesn't name a model/tier/source.
                   "Hint" semantics: explicit user words still win. -->
              <div v-if="cruisePrefsOpen" class="cruise-prefs-panel">
                <div class="cruise-prefs-head">
                  <span>Assistant defaults</span>
                  <button class="cruise-prefs-close" type="button" @click="cruisePrefsOpen = false">✕</button>
                </div>
                <div class="cruise-prefs-row">
                  <label>Image model</label>
                  <UiSelect :modelValue="cruisePrefs.image_model || ''" :options="CRUISE_IMAGE_MODEL_OPTIONS" @update:modelValue="v => setCruisePref('image_model', v || null)" />
                </div>
                <div class="cruise-prefs-row">
                  <label>Animation tier</label>
                  <UiSelect :modelValue="cruisePrefs.animation_tier || ''" :options="CRUISE_ANIMATION_TIER_OPTIONS" @update:modelValue="v => setCruisePref('animation_tier', v || null)" />
                </div>
                <div class="cruise-prefs-row">
                  <label>Visual source</label>
                  <UiSelect :modelValue="cruisePrefs.visual_source || 'auto'" :options="CRUISE_VISUAL_SOURCE_OPTIONS" drop="up" @update:modelValue="v => setCruisePref('visual_source', v)" />
                </div>
                <div class="cruise-prefs-hint">
                  These are hints — if you say "use Kling for this one" the assistant still does.
                </div>
              </div>
            </div>

          </div>
        </div>
        <EditorTimeline
          v-if="timelineOpen"
          style="height: 210px;"
          :scenes="scenes"
          :active-scene-id="activeSceneId"
          :play-progress="playProgress"
          :total-duration="totalVideoDuration"
          :is-playing="isPreviewPlaying"
          :music-track="activeMusicTrack"
          :preview-mode="previewMode"
          @scene-select="selectScene"
          @seek="timelineSeek"
          @reorder="timelineReorder"
          @pick-sound="openMediaPicker('sound')"
          @close="timelineOpen = false"
        />
        </div><!-- end editor-body -->
      </div>
    </div>

    <div :class="`drawer-backdrop ${notificationDrawerOpen ? 'open' : ''}`" @click="notificationDrawerOpen = false"></div>
    <aside :class="`drawer drawer-notif ${notificationDrawerOpen ? 'open' : ''}`">
      <div class="drawer-header">
        <div class="drawer-title">Notifications</div>
        <button class="mark-read-btn" type="button" @click="markAllRead">Mark all read</button>
      </div>
      <div v-if="notifications.length === 0" class="notif-empty">No notifications yet</div>
      <article
        v-for="item in notifications"
        :key="item.id"
        :class="`notif-item ${item.is_read ? '' : 'unread'}`"
        @click="!item.is_read && markNotificationRead(item.id)"
      >
        <div :class="`notif-icon-wrap ${item.type === 'success' ? 'success' : item.type === 'error' ? 'error' : 'warning'}`">
          {{ item.type === 'success' ? '✓' : item.type === 'error' ? '✕' : '•' }}
        </div>
        <div class="notif-body">
          <div class="notif-msg">{{ item.title }}</div>
          <div class="notif-time">{{ formatNotifTime(item.created_at) }}</div>
          <div class="notif-detail">{{ item.message }}</div>
        </div>
        <div v-if="!item.is_read" class="notif-unread-dot"></div>
      </article>
    </aside>

    <div class="toast-container">
      <div v-for="toast in notificationToasts" :key="toast.id" class="toast">
        <div class="toast-dot"></div>
        <div class="toast-content">
          <div class="toast-msg"><strong>{{ toast.title }}</strong> — {{ toast.message }}</div>
        </div>
      </div>
    </div>

    <div
      :class="`modal-backdrop ${deleteSceneTarget ? 'open' : ''}`"
      @click="closeDeleteSceneModal"
    ></div>
    <div v-if="deleteSceneTarget" class="modal-shell" role="dialog" aria-modal="true">
      <div class="confirm-modal">
        <div class="confirm-modal-title">Delete this scene?</div>
        <div class="confirm-modal-copy">
          This removes
          <strong>{{ deleteSceneTarget.label || `Scene ${deleteSceneTarget.scene_order}` }}</strong>
          from the project timeline.
        </div>
        <div class="confirm-modal-actions">
          <button class="btn btn-ghost" type="button" :disabled="deleteScenePending" @click="closeDeleteSceneModal">
            Cancel
          </button>
          <button class="btn btn-primary danger-btn" type="button" :disabled="deleteScenePending" @click="confirmDeleteScene">
            {{ deleteScenePending ? "Deleting..." : "Delete Scene" }}
          </button>
        </div>
      </div>
    </div>

    <!-- Caption effect gallery -->
    <div
      :class="`modal-backdrop ${captionEffectModalOpen ? 'open' : ''}`"
      @click="captionEffectModalOpen = false"
    ></div>
    <div v-if="captionEffectModalOpen" class="modal-shell" role="dialog" aria-modal="true">
      <div class="confirm-modal caption-effect-modal">
        <div class="confirm-modal-title">Caption effects</div>
        <div class="confirm-modal-copy">
          Words animate on your voiceover's real timing. Your font, colors and
          position apply on top of any effect.
        </div>
        <div class="caption-effect-grid">
          <button
            v-for="anim in CAPTION_ANIMATIONS"
            :key="'m-' + anim.key"
            type="button"
            :class="['caption-anim-tile', 'caption-anim-tile-lg', captionAnimationDraft === anim.key ? 'active' : '']"
            @click="selectCaptionAnimation(anim.key)"
          >
            <span class="caption-anim-sample">
              <CaptionPreview :demo="true" :settings="{ animation: anim.key, highlight_color: captionHighlightColorDraft, panel_color: captionPanelColorDraft || null }" />
            </span>
            <span class="caption-anim-name">{{ anim.name }}</span>
            <span v-if="anim.badge" class="caption-anim-badge">{{ anim.badge }}</span>
          </button>
        </div>
        <div class="confirm-modal-actions">
          <button class="btn btn-ghost" type="button" @click="captionEffectModalOpen = false">Close</button>
        </div>
      </div>
    </div>

    <!-- New-chat confirmation (replaces the browser confirm) -->
    <div v-if="cruiseResetConfirmOpen" class="modal-shell" role="dialog" aria-modal="true">
      <div class="confirm-modal">
        <div class="confirm-modal-title">Start a new chat?</div>
        <div class="confirm-modal-copy">
          This clears the assistant conversation. Your video changes stay —
          use <strong>Undo</strong> on a card to revert one.
        </div>
        <div class="confirm-modal-actions">
          <button class="btn btn-ghost" type="button" :disabled="cruiseResetting" @click="cruiseResetConfirmOpen = false">
            Cancel
          </button>
          <button class="btn btn-primary" type="button" :disabled="cruiseResetting" @click="cruiseConfirmReset">
            {{ cruiseResetting ? 'Clearing…' : 'New chat' }}
          </button>
        </div>
      </div>
    </div>

    <audio
      ref="audioRef"
      :src="activeSceneAudioUrl || undefined"
      :crossorigin="mediaCrossOriginMode(activeSceneAudioUrl)"
      preload="metadata"
      @loadstart="isAudioLoading = true"
      @canplay="isAudioLoading = false"
      @loadeddata="isAudioLoading = false; syncSceneVoiceVolume()"
      @ended="handleSceneAudioPause"
      @play="handleSceneAudioPlay"
      @pause="handleSceneAudioPause"
      @error="isAudioLoading = false"
    ></audio>
    <audio
      ref="musicAudioRef"
      :src="activeMusicTrackUrl || undefined"
      :crossorigin="mediaCrossOriginMode(activeMusicTrackUrl)"
      preload="metadata"
      :loop="musicLoop"
      @loadedmetadata="syncPreviewMusicVolume"
      @loadeddata="musicReady = true"
      @canplay="musicReady = true"
      @error="musicReady = true"
    ></audio>
    <audio
      ref="soundAudioRef"
      :src="activeSceneSoundUrl || undefined"
      :crossorigin="mediaCrossOriginMode(activeSceneSoundUrl)"
      preload="auto"
      @loadeddata="syncSceneSoundVolume"
    ></audio>
    <audio
      v-if="auditionMusicTrack?.storage_url"
      ref="musicAuditionRef"
      :src="auditionMusicTrack.storage_url"
      :crossorigin="mediaCrossOriginMode(auditionMusicTrack?.storage_url)"
      preload="metadata"
      @ended="auditionMusicTrackId = null"
      @pause="auditionMusicTrackId = null"
    ></audio>

    <MediaPickerModal
      :mode="mediaPickerMode"
      :visible="mediaPickerVisible"
      :music-tracks="musicTracks"
      :selected-music-track-id="selectedMusicTrackId"
      :current-asset-id="mediaPickerMode === 'visual' ? (activeScene?.visual_asset_id ?? null) : null"
      @close="mediaPickerVisible = false"
      @select="handleMediaPickerSelect"
    />
    <SchedulePostModal
      v-if="scheduleModalOpen"
      :export-job-id="deliveryExport?.id ?? activeExportJob?.id ?? null"
      @close="scheduleModalOpen = false"
      @scheduled="onPostScheduled"
    />

    <!-- Approval link modal -->
    <Teleport to="body">
      <div v-if="approvalModalOpen" class="ap-backdrop" @click.self="closeApprovalModal">
        <div class="ap-modal">
          <div class="ap-head">
            <div class="ap-title">Send for approval</div>
            <button class="ap-close" @click="closeApprovalModal">×</button>
          </div>

          <template v-if="!approvalCreated">
            <div class="ap-meta">A unique review link will be emailed to your reviewer. They don't need a WyvStudio account.</div>
            <div class="ap-field">
              <label class="ap-label">Reviewer email *</label>
              <input v-model="approvalForm.email" type="email" class="ap-input" placeholder="client@example.com" />
            </div>
            <div class="ap-field">
              <label class="ap-label">Reviewer name (optional)</label>
              <input v-model="approvalForm.name" type="text" class="ap-input" placeholder="Their name" />
            </div>
            <div class="ap-field">
              <label class="ap-label">Note for them (optional)</label>
              <textarea v-model="approvalForm.message" rows="3" class="ap-input" placeholder="Anything specific you want them to look at"></textarea>
            </div>
            <div class="ap-field">
              <label class="ap-label">Expires in</label>
              <UiSelect v-model="approvalForm.expires_in_days" :options="APPROVAL_EXPIRES_OPTIONS" align="left" />
            </div>
            <div v-if="approvalError" class="ap-error">{{ approvalError }}</div>
            <div class="ap-footer">
              <button class="btn btn-ghost btn-sm" @click="closeApprovalModal">Cancel</button>
              <button class="btn btn-primary btn-sm" :disabled="approvalSubmitting" @click="submitApproval">
                {{ approvalSubmitting ? 'Sending…' : 'Send link' }}
              </button>
            </div>
          </template>

          <template v-else>
            <div class="ap-success">
              <div class="ap-success-icon">✓</div>
              <div class="ap-success-title">Approval link sent</div>
              <div class="ap-success-sub" v-if="approvalCreated.warning">
                ⚠ {{ approvalCreated.warning }}
              </div>
              <div class="ap-success-sub" v-else>
                We sent the link to <strong>{{ approvalCreated.approval?.reviewer_email }}</strong>. You can also share it directly:
              </div>
              <div class="ap-link-box">
                <input :value="approvalCreated.public_url" class="ap-input" readonly />
                <button class="btn btn-ghost btn-sm" @click="copyApprovalUrl">Copy</button>
              </div>
              <div class="ap-footer">
                <button class="btn btn-primary btn-sm" @click="closeApprovalModal">Done</button>
              </div>
            </div>
          </template>
        </div>
      </div>
    </Teleport>

    <!-- Create-character modal — opened from the character chip popover -->
    <Teleport to="body">
      <div v-if="createCharacterOpen" class="ap-backdrop" @click.self="closeCharacterModal">
        <div class="ap-modal" style="max-width:480px;">
          <div class="ap-head">
            <div class="ap-title">＋ New character</div>
            <button class="ap-close" @click="closeCharacterModal">×</button>
          </div>

          <div class="ap-field">
            <label class="ap-label">Reference image <span style="opacity:.5;font-weight:400">(optional)</span></label>
            <label v-if="!createCharacterPreviewUrl" class="char-upload-drop">
              <input type="file" accept="image/*" hidden @change="pickCharacterFile" />
              <div class="char-upload-ico">⬆</div>
              <div class="char-upload-copy">Drop or click to upload</div>
              <div class="char-upload-sub">PNG or JPG · up to 10MB</div>
            </label>
            <div v-else class="char-upload-preview">
              <img :src="createCharacterPreviewUrl" alt="" />
              <button type="button" class="char-upload-clear" @click="clearCharacterFile">✕ Remove</button>
            </div>
            <div class="ap-hint">Shown as the character's thumbnail. Will guide visual consistency when IP-Adapter is wired.</div>
          </div>

          <div class="ap-field">
            <label class="ap-label">Name</label>
            <input v-model="createCharacterName" class="ap-input" placeholder="e.g. Marcus the Detective" maxlength="120" />
          </div>
          <div class="ap-field">
            <label class="ap-label">Description <span style="opacity:.5;font-weight:400">(optional)</span></label>
            <textarea v-model="createCharacterDescription" class="ap-input" rows="3" placeholder="A weathered 50-year-old detective in a worn trench coat, sharp eyes…" maxlength="2000"></textarea>
            <div class="ap-hint">Used in scene prompts to keep the character consistent across episodes.</div>
          </div>
          <div v-if="createCharacterError" class="ap-error">{{ createCharacterError }}</div>
          <div class="ap-foot">
            <button class="btn btn-ghost btn-sm" type="button" @click="closeCharacterModal">Cancel</button>
            <button class="btn btn-primary btn-sm" type="button" :disabled="createCharacterSaving" @click="createCharacter">
              {{ createCharacterSaving ? (createCharacterFile ? 'Uploading…' : 'Saving…') : 'Create' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Animate scene modal — opened from the "⚡ Animate" button on AI image scenes -->
    <Teleport to="body">
      <div v-if="animateModalOpen" class="ap-backdrop" @click.self="closeAnimateModal">
        <div class="ap-modal" style="max-width:560px;">
          <div class="ap-head">
            <div class="ap-title">⚡ Animate scene</div>
            <button class="ap-close" @click="closeAnimateModal">×</button>
          </div>

          <div v-if="spokespersonOutdated" class="ap-spokesperson-warn">
            ⚠ The voiceover changed — this talking video is still lip-synced to the old voice.
            Re-render it with <strong>Spokesperson</strong> so the lips match the new audio.
          </div>

          <div class="ap-field">
            <label class="ap-label">Model</label>
            <div class="anim-model-dd">
              <button type="button" class="anim-model-trigger" :class="{ open: animateModelOpen }" @click="animateModelOpen = !animateModelOpen">
                <span class="anim-model-trigger-main">
                  <span class="anim-model-trigger-name">{{ ANIMATE_TIER_META[animateTier].name }}</span>
                  <span class="anim-model-trigger-sub">{{ ANIMATE_TIER_META[animateTier].sub }} · {{ ANIMATE_TIER_META[animateTier].quality }} · {{ ANIMATE_TIER_META[animateTier].render }}</span>
                </span>
                <span class="anim-model-trigger-cost">{{ ANIMATE_TIER_COSTS_5S[animateTier] }} cr</span>
                <span class="anim-model-caret">▾</span>
              </button>
              <div v-if="animateModelOpen" class="anim-model-menu">
                <button
                  v-for="key in ANIMATE_TIER_ORDER"
                  :key="key"
                  type="button"
                  :class="['anim-model-option', animateTier === key ? 'selected' : '']"
                  @click="animateTier = key; animateModelOpen = false"
                >
                  <span class="anim-model-opt-left">
                    <span class="anim-model-opt-name">
                      {{ ANIMATE_TIER_META[key].name }}
                      <span v-if="key === 'balanced'" class="anim-tier-pill">RECOMMENDED</span>
                    </span>
                    <span class="anim-model-opt-sub">{{ ANIMATE_TIER_META[key].sub }} · {{ ANIMATE_TIER_META[key].quality }} · {{ ANIMATE_TIER_META[key].render }}<template v-if="key !== 'spokesperson'"> · {{ ANIMATE_TIER_DURATIONS[key].join('/') }} s</template><template v-if="tierSlow(key)"> · <span class="anim-slow-tag">slow now, ~{{ Math.round(creditCosts.video_tiers[key].recent_minutes) }} min</span></template></span>
                  </span>
                  <span class="anim-model-opt-right">
                    <span class="anim-model-opt-cost">{{ ANIMATE_TIER_COSTS_5S[key] }} cr</span>
                    <span v-if="animateTier === key" class="anim-model-opt-check">✓</span>
                  </span>
                </button>
              </div>
            </div>
            <p v-if="tierSlow(animateTier)" class="anim-slow" role="status">{{ ANIMATE_TIER_META[animateTier].name }} is slow right now: recent clips took about {{ Math.round(creditCosts.video_tiers[animateTier].recent_minutes) }} min. It will finish and appear here on its own, or pick a faster model.</p>
          </div>

          <!-- Spokesperson has no quality picker; it has an engine choice,
               which changes both the output size and the price. -->
          <div v-if="animateTier === 'spokesperson' && lipsyncEngines.length > 1" class="ap-field">
            <label class="ap-label">Lip-sync model</label>
            <div class="anim-duration-row">
              <button
                v-for="e in lipsyncEngines"
                :key="e.key"
                type="button"
                :class="['anim-duration-btn', lipsyncEngine === e.key ? 'active' : '']"
                @click="lipsyncEngine = e.key"
              >
                {{ e.label }}
                <span class="anim-engine-meta">{{ e.output }} · {{ e.credits_per_second }} cr/s</span>
              </button>
            </div>
          </div>

          <div v-if="animateTier !== 'spokesperson' && animateQualityOptions.length" class="ap-field">
            <label class="ap-label">Quality</label>
            <div class="anim-duration-row">
              <button
                v-for="q in animateQualityOptions"
                :key="q.value"
                type="button"
                :class="['anim-dpill', animateQuality === q.value ? 'active' : '']"
                @click="animateQuality = q.value"
              >{{ q.label }} · {{ q.cr }} cr</button>
            </div>
            <div class="ap-hint">Higher resolution = sharper video for more credits. Drives the price below.</div>
          </div>
          <div v-if="animateTier !== 'spokesperson'" class="ap-field">
            <label class="ap-label">Duration</label>
            <div class="anim-duration-row">
              <button
                v-for="d in animateDurations"
                :key="d"
                type="button"
                :class="['anim-dpill', animateDuration === d ? 'active' : '']"
                @click="animateDuration = d"
              >{{ d }} s</button>
            </div>
            <div class="ap-hint">
              Long clip costs 2×. The
              <strong>{{ ANIMATE_TIER_META[animateTier].name }}</strong>
              tier renders in {{ animateDurations.join(' s or ') }} s chunks.
            </div>
          </div>
          <div v-else class="ap-hint" style="margin-top:4px;">
            The character lip-syncs to this scene's voiceover — length follows the audio,
            and the synced talking video is what renders into your final export.
            <strong v-if="!activeSceneHasVoice"> Generate the voiceover first.</strong>
          </div>

          <div class="ap-field">
            <label class="ap-label">Motion prompt <span style="opacity:.5;font-weight:400">(optional)</span></label>
            <div class="anim-prompt-chips">
              <button
                v-for="s in ANIMATE_PROMPT_SUGGESTIONS"
                :key="s.label"
                type="button"
                class="anim-prompt-chip"
                @click="animateMotionPrompt = s.text"
              >{{ s.label }}</button>
            </div>
            <textarea v-model="animateMotionPrompt" class="ap-input" rows="2" maxlength="1000" placeholder="e.g. slow camera push-in, subtle hair movement, drifting fog"></textarea>
            <div class="ap-hint">Pick a quick start above or type your own. Leave blank for a sensible default.</div>
          </div>

          <!-- Apply this same animation to other scenes, priced by the server. -->
          <div class="bulk-anim" :class="{ on: bulkAnimateMode }">
            <button type="button" class="bulk-anim-toggle" :class="{ on: bulkAnimateMode }" @click="toggleBulkAnimate">
              <span class="bulk-anim-check" :class="{ on: bulkAnimateMode }">{{ bulkAnimateMode ? '✓' : '' }}</span>
              <span class="bulk-anim-toggle-text">
                <span class="bulk-anim-toggle-title">Apply to multiple scenes</span>
                <span class="bulk-anim-toggle-sub">Animate the whole project at once, or pick scenes</span>
              </span>
            </button>

            <div v-if="bulkAnimateMode" class="bulk-anim-body">
              <div v-if="bulkAnimateFirstLoad" class="bulk-anim-loading">Working out the cost…</div>

              <template v-else-if="bulkAnimatePreview">
                <!-- Says plainly what is per-scene and what is shared. -->
                <div class="bulk-anim-scope">
                  Every scene animates its own image{{ animateTier === 'spokesperson' ? ', lip-synced to its own voiceover' : '' }}.
                  <template v-if="animateMotionPrompt.trim()">The motion direction above is applied to all of them.</template>
                  <template v-else>With no motion direction, each scene moves naturally from its own image.</template>
                </div>

                <!-- Applying the action to every scene applies the IMAGE too.
                     Say so plainly — a scene silently losing its visual is the
                     kind of thing users should agree to, not discover. -->
                <div v-if="bulkAnimateSourceId" class="bulk-anim-source">
                  <strong>Every selected scene will use this scene's image.</strong>
                  <span>
                    Each keeps its own script and voiceover — only the visual is shared.
                    <template v-if="bulkAnimateReplacedCount">
                      {{ bulkAnimateReplacedCount }} scene{{ bulkAnimateReplacedCount === 1 ? '' : 's' }}
                      already {{ bulkAnimateReplacedCount === 1 ? 'has' : 'have' }} an image of
                      {{ bulkAnimateReplacedCount === 1 ? 'its' : 'their' }} own and will be replaced —
                      you can revert any scene afterwards.
                    </template>
                  </span>
                </div>

                <div v-if="!bulkAnimateSelectableCount" class="bulk-anim-none">
                  None of these scenes can be animated — see the reasons below.
                </div>

                <!-- Every scene, included or not, with its reason. A count of
                     "6 of 13" is only trustworthy if the other 7 are visible. -->
                <div class="bulk-anim-list">
                  <button
                    v-for="row in bulkAnimateRows"
                    :key="row.scene_id"
                    type="button"
                    class="bulk-anim-scene"
                    :class="{ off: !row.included, blocked: row.blocked }"
                    :disabled="row.blocked"
                    @click="toggleBulkScene(row)"
                  >
                    <span class="bulk-anim-box" :class="{ on: row.included, blocked: row.blocked }">
                      {{ row.included ? '✓' : (row.blocked ? '–' : '') }}
                    </span>
                    <span class="bulk-anim-scene-label">Scene {{ row.order }}</span>
                    <span v-if="row.blocked" class="bulk-anim-scene-why">{{ row.reason }}</span>
                    <span v-else-if="row.replaces_own" class="bulk-anim-scene-replace">
                      {{ row.shares_with ? 'image replaced · free' : `image replaced · ${row.cost} cr` }}
                    </span>
                    <span v-else-if="row.shares_with" class="bulk-anim-scene-share">
                      shares scene {{ row.shares_with }}'s clip · free
                    </span>
                    <span v-else class="bulk-anim-scene-cost">{{ row.cost }} cr</span>
                  </button>
                </div>

                <button
                  v-if="bulkAnimateSelection !== null"
                  type="button"
                  class="bulk-anim-selectall"
                  @click="bulkAnimateSelectAll"
                >
                  Select all {{ bulkAnimateSelectableCount }} animatable scenes
                </button>

                <template v-if="bulkAnimatePreview.eligible_count">
                  <div class="bulk-anim-row">
                    <span>{{ bulkAnimatePreview.eligible_count }} of {{ bulkAnimateRows.length }} scenes</span>
                    <strong v-if="bulkAnimateLoading" class="repricing">updating…</strong>
                    <strong v-else :class="{ short: !bulkAnimatePreview.affordable }">{{ bulkAnimatePreview.total_cost }} credits</strong>
                  </div>
                  <!-- The whole point of the shared-clip path: one render
                       covering several scenes, and what that saved. -->
                  <div v-if="bulkAnimatePreview.saved > 0" class="bulk-anim-saving">
                    {{ bulkAnimatePreview.render_count }}
                    {{ bulkAnimatePreview.render_count === 1 ? 'render' : 'renders' }} —
                    scenes built on the same image share one clip, saving
                    {{ bulkAnimatePreview.saved }} credits.
                  </div>
                  <div class="bulk-anim-row sub">
                    <span>Your balance</span>
                    <span>{{ bulkAnimatePreview.balance }} credits</span>
                  </div>
                  <div v-if="!bulkAnimatePreview.affordable" class="bulk-anim-short">
                    You're {{ bulkAnimatePreview.shortage }} credits short. Untick a few scenes, or top up.
                  </div>
                </template>
              </template>
            </div>
          </div>

          <label v-if="needsSpokespersonConsent || (bulkAnimateMode && animateTier === 'spokesperson')" class="anim-consent">
            <input type="checkbox" v-model="spokespersonConsent">
            <span>
              I have the rights and consent to make this person appear to speak.
              <a href="/synthetic-media" target="_blank" rel="noopener">Synthetic media policy</a>
            </span>
          </label>

          <div v-if="animateError" class="ap-error">{{ animateError }}</div>

          <div class="anim-foot">
            <div class="anim-total">
              <span class="anim-total-credits">
                {{ bulkAnimateMode && bulkAnimatePreview ? bulkAnimatePreview.total_cost : animateCost }} credits
              </span>
              <span class="anim-total-sub">
                {{ bulkAnimateMode ? 'across every eligible scene' : `${animateDuration} s clip · regenerate if uncanny` }}
              </span>
            </div>
            <div style="display:flex;gap:8px;">
              <button class="btn btn-ghost btn-sm" type="button" :disabled="animateSubmitting" @click="closeAnimateModal">Cancel</button>
              <button
                v-if="bulkAnimateMode"
                class="btn btn-primary btn-sm"
                type="button"
                :disabled="animateSubmitting || bulkAnimateLoading || !bulkAnimatePreview?.eligible_count || !bulkAnimatePreview?.affordable || (animateTier === 'spokesperson' && !spokespersonConsent)"
                @click="submitBulkAnimate"
              >
                {{ animateSubmitting ? 'Starting…' : `⚡ Animate all ${bulkAnimatePreview?.eligible_count ?? ''}` }}
              </button>
              <button v-else class="btn btn-primary btn-sm" type="button" :disabled="animateSubmitting || (needsSpokespersonConsent && !spokespersonConsent)" @click="submitAnimate">
                {{ animateSubmitting ? 'Starting…' : '⚡ Animate' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Voice picker modal -->
    <Teleport to="body">
      <div v-if="showVoiceModal" class="vp-backdrop" @click.self="closeVoiceModal">
        <div class="vp-modal">
          <div class="vp-head">
            <div class="vp-title">Select a voice</div>
            <button class="vp-close" type="button" @click="closeVoiceModal">×</button>
          </div>

          <div class="vp-scroll">
            <!-- Personas (Gemini delivery presets) -->
            <div class="vp-section-label">Personalities</div>
            <div class="vp-persona-grid">
              <button v-for="persona in VOICE_PERSONAS" :key="persona.key" type="button" class="vp-persona" @click="applyPersona(persona)">
                <span class="vp-persona-emoji">{{ persona.emoji }}</span>
                <span class="vp-persona-label">{{ persona.label }}</span>
                <span class="vp-persona-desc">{{ persona.desc }}</span>
              </button>
            </div>

            <!-- Your cloned voices (always shown so the path is discoverable) -->
            <div class="vp-section-label">Your voices</div>
            <div
              v-for="v in clonedVoices"
              :key="v.id"
              :class="['vp-voice', voiceProfileKey === v.provider_voice_key ? 'active' : '']"
            >
              <button class="vp-preview" type="button" :title="previewPlayingKey === v.provider_voice_key ? 'Stop' : 'Preview'" @click="previewVoice(v)">
                <span v-if="previewLoadingKey === v.provider_voice_key">…</span>
                <span v-else>{{ previewPlayingKey === v.provider_voice_key ? '⏸' : '▶' }}</span>
              </button>
              <div class="vp-voice-info" @click="selectVoice(v)">
                <div class="vp-voice-name">{{ v.name }} <span class="vp-cloned-tag">Cloned</span></div>
                <div class="vp-voice-desc">Your cloned voice</div>
              </div>
              <button class="vp-pick" type="button" @click="selectVoice(v)">{{ voiceProfileKey === v.provider_voice_key ? '✓' : 'Use' }}</button>
            </div>
            <button type="button" class="vp-clone-cta" @click="openCloneInEditor">
              ＋ Clone a voice — record (with a 3-2-1 countdown) or upload a sample
            </button>

            <!-- Expressive (Gemini) -->
            <div class="vp-section-label">Expressive voices</div>
            <div
              v-for="v in geminiVoices"
              :key="v.id"
              :class="['vp-voice', voiceProfileKey === v.provider_voice_key ? 'active' : '']"
            >
              <button class="vp-preview" type="button" :title="previewPlayingKey === v.provider_voice_key ? 'Stop' : 'Preview'" @click="previewVoice(v)">
                <span v-if="previewLoadingKey === v.provider_voice_key">…</span>
                <span v-else>{{ previewPlayingKey === v.provider_voice_key ? '⏸' : '▶' }}</span>
              </button>
              <div class="vp-voice-info" @click="selectVoice(v)">
                <div class="vp-voice-name">{{ v.name }}</div>
                <div class="vp-voice-desc">{{ voiceDescription(v) }}</div>
              </div>
              <button class="vp-pick" type="button" @click="selectVoice(v)">{{ voiceProfileKey === v.provider_voice_key ? '✓' : 'Use' }}</button>
            </div>

            <!-- Standard (OpenAI), only if present in the catalog -->
            <template v-if="otherVoices.length">
              <div class="vp-section-label">Standard voices</div>
              <div
                v-for="v in otherVoices"
                :key="v.id"
                :class="['vp-voice', voiceProfileKey === v.provider_voice_key ? 'active' : '']"
              >
                <button class="vp-preview" type="button" @click="previewVoice(v)">
                  <span v-if="previewLoadingKey === v.provider_voice_key">…</span>
                  <span v-else>{{ previewPlayingKey === v.provider_voice_key ? '⏸' : '▶' }}</span>
                </button>
                <div class="vp-voice-info" @click="selectVoice(v)">
                  <div class="vp-voice-name">{{ v.name }}</div>
                  <div class="vp-voice-desc">{{ voiceDescription(v) }}</div>
                </div>
                <button class="vp-pick" type="button" @click="selectVoice(v)">{{ voiceProfileKey === v.provider_voice_key ? '✓' : 'Use' }}</button>
              </div>
            </template>
          </div>

          <div class="vp-foot">
            <span class="vp-foot-hint">Personalities are a best-effort delivery shift on Gemini's fixed voices, not a re-pitch.</span>
            <button class="btn btn-ghost btn-sm" type="button" @click="closeVoiceModal">Done</button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Post-export feedback: once per user, optional, two steps in one card -->
    <Teleport to="body">
      <div v-if="exportFbOpen" class="xfb-backdrop" @click.self="exportFbSubmit(true)">
        <div class="xfb-card">
          <template v-if="exportFbDone">
            <div class="xfb-thanks">Thank you! 🙏</div>
            <div class="xfb-sub">This goes straight to the founders.</div>
          </template>
          <template v-else>
            <button class="xfb-close" type="button" @click="exportFbSubmit(true)">×</button>
            <div class="xfb-title">Your video is ready — how was making it?</div>

            <div class="xfb-stars">
              <button v-for="n in 5" :key="n" type="button"
                :class="['xfb-star', n <= exportFbRating ? 'on' : '']"
                @click="exportFbRating = n; exportFbOptions = []">★</button>
            </div>

            <template v-if="exportFbRating > 0">
              <div class="xfb-sub">{{ exportFbRating >= 4 ? 'What stood out?' : 'What fell short?' }} <span style="opacity:.6">(pick any)</span></div>
              <div class="xfb-chips">
                <button v-for="c in exportFbChips" :key="c" type="button"
                  :class="['xfb-chip', exportFbOptions.includes(c) ? 'on' : '']"
                  @click="exportFbToggle(c)">{{ c }}</button>
              </div>
              <textarea v-model="exportFbComment" class="xfb-input" rows="2" maxlength="2000"
                placeholder="Anything else? (optional)"></textarea>
              <button class="xfb-send" type="button" :disabled="exportFbSending" @click="exportFbSubmit(false)">
                {{ exportFbSending ? 'Sending…' : 'Send feedback' }}
              </button>
            </template>
            <div v-else class="xfb-sub" style="text-align:center;opacity:.7">We only ask once, promise.</div>
          </template>
        </div>
      </div>
    </Teleport>

    <!-- Clone a voice without leaving the editor -->
    <VoiceCloneModal v-if="showCloneModal" @close="showCloneModal = false" @created="onVoiceCloned" />
  </main>
  <ExportUpdateModal :warning="exportWarning" @cancel="cancelExportAction" @continue="continueExportAction" @update="updateExportAction" />
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Space+Mono:wght@400;700&display=swap");
/* Caption fonts. The animated presets need the heavy cuts (Montserrat
   700/800/900 + italics, Nunito 800, Passion One 700, Orbitron 700,
   Roboto Mono 500) — without them the browser fakes bold/italic and the
   presets lose their punch. */
@import url("https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat:ital,wght@0,400;0,700;0,800;0,900;1,800;1,900&family=Raleway&family=Nunito:wght@400;800&family=Lato&family=Roboto+Mono:wght@400;500&family=Roboto+Slab&family=Libre+Baskerville&family=Playfair+Display&family=Dancing+Script&family=Fredoka+One&family=Sacramento&family=Luckiest+Guy&family=Orbitron:wght@400;700&family=Satisfy&family=Permanent+Marker&family=Noto+Sans&family=Amatic+SC&family=Days+One&family=Rock+Salt&family=New+Rocker&family=Passion+One:wght@400;700&family=Indie+Flower&family=Quicksand&family=Shadows+Into+Light&family=Source+Code+Pro&family=Aladin&family=Calligraffitti&display=swap");

.editor-page {
  --bg-deep: #0a0a0f;
  --bg-panel: #111118;
  --bg-card: #17171f;
  --bg-elevated: #1d1d28;
  --bg-soft: #15151d;
  --border: #2a2a36;
  --border-active: #494960;
  --text-primary: #ececf3;
  --text-secondary: #a1a1b5;
  --text-muted: #6a6a7c;
  --accent: #ff6b35;
  --accent-hover: #ff875a;
  --accent-glow: rgba(255, 107, 53, 0.14);
  --green: #34d399;
  --green-bg: rgba(52, 211, 153, 0.12);
  --blue: #60a5fa;
  --blue-bg: rgba(96, 165, 250, 0.12);
  --yellow: #fbbf24;
  --yellow-bg: rgba(251, 191, 36, 0.12);
  --purple: #a78bfa;
  --purple-bg: rgba(167, 139, 250, 0.12);
  --red: #f87171;
  --red-bg: rgba(248, 113, 113, 0.12);
  --radius-sm: 6px;
  --radius: 12px;
  --radius-lg: 16px;
  --shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
  min-height: 100vh;
  font-family: "DM Sans", sans-serif;
  overflow-x: hidden;
  background: radial-gradient(
      circle at top right,
      rgba(255, 107, 53, 0.09),
      transparent 28%
    ),
    radial-gradient(
      circle at bottom left,
      rgba(96, 165, 250, 0.08),
      transparent 24%
    ),
    var(--bg-deep);
  color: var(--text-primary);
}

.state-card {
  margin: 24px;
  padding: 20px;
  border: 1px solid var(--border);
  border-radius: var(--radius);
  background: var(--bg-card);
  color: var(--text-secondary);
}

.state-card.error {
  border-color: rgba(248, 113, 113, 0.2);
  background: rgba(248, 113, 113, 0.08);
  color: #fca5a5;
}

button,
input,
select,
textarea {
  font: inherit;
}

button {
  background: none;
  border: none;
}

.main {
  margin-left: var(--sidebar-width, 220px);
  min-height: 100vh;
  transition: margin-left 0.2s ease;
}
.main.sidebar-collapsed {
  margin-left: 56px;
}

.topbar {
  position: sticky;
  top: 0;
  z-index: 90;
  height: 64px;
  background: rgba(17, 17, 24, 0.88);
  border-bottom: 1px solid var(--border);
  backdrop-filter: blur(14px);
  padding: 0 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.topbar-left,
.topbar-right {
  display: flex;
  align-items: center;
}

.topbar-left {
  gap: 18px;
}

.topbar-right {
  gap: 10px;
}

/* A finished export that no longer matches the project. Reads as something
   to act on rather than the green "done" the completed pill otherwise gets. */
.export-pill-stale {
  border-color: rgba(255, 107, 53, 0.45);
  background: rgba(255, 107, 53, 0.10);
  color: var(--accent, #ff6b35);
}
.export-pill {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  min-height: 36px;
  padding: 0 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: rgba(19, 19, 28, 0.92);
  color: var(--text-secondary);
  font-size: 12px;
  white-space: nowrap;
}

.export-pill-queued,
.export-pill-processing {
  color: #f6c453;
  border-color: rgba(246, 196, 83, 0.25);
}

.export-pill-completed {
  color: #9fe3b5;
  border-color: rgba(52, 211, 153, 0.3);
}

.export-pill-failed {
  color: #ff8f8f;
  border-color: rgba(255, 107, 107, 0.3);
}

.export-btn-wrap {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.export-blocker-tip {
  position: absolute;
  top: calc(100% + 6px);
  right: 0;
  background: #2a1010;
  border: 1px solid rgba(255, 107, 107, 0.35);
  color: #ff9999;
  font-size: 11px;
  line-height: 1.4;
  padding: 6px 10px;
  border-radius: 6px;
  white-space: nowrap;
  max-width: 320px;
  white-space: normal;
  z-index: 50;
  pointer-events: none;
}

.export-fail-info {
  position: relative;
  display: inline-flex;
  align-items: center;
  cursor: default;
  opacity: 0.8;
  margin-left: 4px;
}
.export-fail-info:hover .export-fail-tooltip,
.export-fail-info:focus .export-fail-tooltip {
  display: block;
}
.export-fail-tooltip {
  display: none;
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  background: #1e1e2e;
  border: 1px solid rgba(255, 107, 107, 0.35);
  color: #ff9999;
  font-size: 12px;
  line-height: 1.45;
  padding: 8px 12px;
  border-radius: 7px;
  white-space: normal;
  width: 240px;
  z-index: 100;
  pointer-events: none;
}

.export-pill-sep {
  color: var(--border);
}

.history-btns { display: inline-flex; gap: 4px; }
.history-btn { width: 34px; padding: 0; justify-content: center; align-items: center; }
.history-btn svg { flex: 0 0 15px; width: 15px; height: 15px; }
.history-btn:disabled { opacity: .35; cursor: default; }
.share-wrap { position: relative; }
.share-btn { display: inline-flex; align-items: center; gap: 6px; }
.share-btn.active { border-color: var(--accent, #ff6b35); color: var(--accent, #ff6b35); }
.share-caret { font-size: 10px; opacity: .7; }
.share-menu {
  position: absolute; right: 0; top: calc(100% + 8px); z-index: 60; width: 272px; padding: 10px;
  background: var(--surface, #15151b); border: 1px solid var(--border, #2a2a36); border-radius: 12px;
  box-shadow: 0 18px 48px rgba(0,0,0,.5);
}
.share-menu-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--text-dim, #9a9aa5); margin: 2px 4px 8px; }
.share-ratios { display: flex; flex-direction: column; gap: 2px; }
.share-ratio {
  display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border-radius: 8px; border: 1px solid transparent;
  background: transparent; color: var(--text-primary, #ececf3); cursor: pointer; text-align: left; font-size: 13px;
}
.share-ratio b { min-width: 38px; font-family: var(--font-mono, monospace); font-size: 12px; }
.share-ratio span { color: var(--text-dim, #9a9aa5); font-size: 12px; flex: 1; }
.share-ratio i { font-style: normal; color: var(--accent, #ff6b35); font-weight: 700; }
.share-ratio:hover { background: rgba(255,255,255,.04); }
.share-ratio.on { border-color: rgba(255,107,53,.45); background: rgba(255,107,53,.08); }
.share-menu-sep { height: 1px; background: var(--border, #2a2a36); margin: 10px 2px; }
.share-item {
  display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border: 0; border-radius: 8px;
  background: transparent; color: var(--text-primary, #ececf3); font-size: 13.5px; text-align: left; cursor: pointer;
}
.share-item span { width: 18px; text-align: center; }
.share-item:hover { background: rgba(255,255,255,.05); }
.share-item:disabled { opacity: .5; cursor: default; }
.share-menu-url { margin: 4px 10px 2px; font-size: 11.5px; color: var(--text-dim, #9a9aa5); word-break: break-all; user-select: all; }
.export-ratio-chips { display: inline-flex; gap: 3px; margin-right: 6px; vertical-align: middle; }
.export-ratio-chips-sheet { display: flex; gap: 6px; margin: 4px 0 8px; }
.export-ratio-chip {
  border: 1px solid var(--color-border, #2a2a36); background: transparent; color: var(--text-secondary, #a1a1b5);
  border-radius: 999px; padding: 1px 8px; font: 600 11px var(--font-mono, monospace); cursor: pointer;
}
.export-ratio-chips-sheet .export-ratio-chip { padding: 5px 12px; font-size: 12px; }
.export-ratio-chip.on { border-color: var(--accent, #ff6b35); color: var(--accent, #ff6b35); }
.export-pill-link {
  color: var(--text-primary);
  text-decoration: none;
  font-weight: 600;
}

.export-pill-link:hover {
  color: var(--accent);
}
.export-pill-link:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Copied-link feedback chip — small inline confirmation after Share. */
.export-share-copied {
  color: #34d399;
  font-weight: 600;
  font-size: 11px;
  font-family: "Space Mono", monospace;
}

/* Resume-failed banner — sits between header and editor body. Soft red
   so it reads as "needs attention" without being alarming. */
.resume-failed-banner {
  display: flex; align-items: center; justify-content: space-between;
  gap: 14px; margin: 8px 14px 0; padding: 11px 14px;
  border-radius: 8px; border: 1px solid rgba(248,113,113,0.32);
  background: rgba(248,113,113,0.06);
  font-size: 13px;
}
.resume-failed-msg { display: flex; align-items: center; gap: 10px; line-height: 1.45; color: var(--color-text-primary); }
.resume-failed-icon { color: #f87171; font-size: 18px; }
.resume-failed-msg strong { color: #f87171; }

.export-pill-schedule {
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-size: inherit;
  padding: 0;
}

.btn-back {
  white-space: nowrap;
}

.btn-timeline-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.btn-timeline-toggle.active {
  background: rgba(255, 107, 53, 0.08);
  border-color: rgba(255, 107, 53, 0.3);
  color: var(--accent);
}

.topbar-title {
  font-size: 16px;
  font-weight: 600;
}

.topbar-breadcrumb {
  color: var(--text-muted);
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.topbar-project-name {
  color: var(--text-secondary);
  cursor: text;
}
.topbar-project-name:hover { color: var(--color-accent); }

.topbar-sep { color: var(--text-muted); }

.topbar-title-input {
  font-size: 13px;
  font-family: inherit;
  color: var(--text-primary);
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-accent);
  border-radius: 4px;
  padding: 1px 8px;
  outline: none;
  min-width: 160px;
}

.topbar-ratio-select {
  font-size: 11px;
  font-family: "Space Mono", monospace;
  font-weight: 600;
  color: var(--text-muted);
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 5px;
  padding: 2px 6px;
  cursor: pointer;
  outline: none;
}
.topbar-ratio-select:hover { border-color: var(--color-border-active); color: var(--text-secondary); }

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 7px 16px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: 0.2s ease;
  font-size: 13px;
  font-weight: 500;
}

.btn-primary {
  background: var(--accent);
  color: #fff;
}

.btn-primary:hover {
  background: var(--accent-hover);
  box-shadow: 0 0 24px var(--accent-glow);
}

.btn-ghost {
  color: var(--text-secondary);
  background: transparent;
  border: 1px solid var(--border);
}

.btn-ghost:hover {
  color: var(--text-primary);
  border-color: var(--border-active);
}

.btn-sm {
  padding: 5px 10px;
  font-size: 12px;
}

.notif-bell-btn {
  position: relative;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: 0.15s;
}

.notif-bell-btn:hover {
  color: var(--text-primary);
  border-color: var(--border-active);
}

.drawer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(4, 5, 10, 0.45);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  z-index: 180;
}

.drawer-backdrop.open {
  opacity: 1;
  pointer-events: auto;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(4, 5, 10, 0.6);
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.2s ease;
  z-index: 210;
}

.modal-backdrop.open {
  opacity: 1;
  pointer-events: auto;
}

.modal-shell {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  z-index: 211;
}

.confirm-modal {
  width: min(420px, 100%);
  border: 1px solid var(--border-active);
  border-radius: 16px;
  background: rgba(17, 17, 24, 0.98);
  box-shadow: var(--shadow);
  padding: 20px;
}

.confirm-modal-title {
  font-size: 18px;
  font-weight: 700;
}

.confirm-modal-copy {
  margin-top: 10px;
  color: var(--text-secondary);
  font-size: 14px;
  line-height: 1.5;
}

.confirm-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 18px;
}

.danger-btn {
  background: #e15c5c;
}

.danger-btn:hover {
  background: #f06c6c;
  box-shadow: none;
}

.drawer {
  position: fixed;
  top: 0;
  right: 0;
  width: 360px;
  max-width: calc(100vw - 24px);
  height: 100vh;
  background: rgba(17, 17, 24, 0.98);
  border-left: 1px solid var(--border);
  transform: translateX(100%);
  transition: transform 0.24s ease;
  z-index: 190;
  padding: 20px;
  overflow-y: auto;
}

.drawer.open {
  transform: translateX(0);
}

.drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
}

.drawer-title {
  font-size: 16px;
  font-weight: 700;
}

.mark-read-btn {
  color: var(--accent);
  font-size: 12px;
  cursor: pointer;
}

.notif-empty {
  color: var(--text-muted);
  font-size: 13px;
}

.notif-item {
  display: grid;
  grid-template-columns: 28px 1fr auto;
  gap: 12px;
  padding: 12px 0;
  border-top: 1px solid rgba(255, 255, 255, 0.04);
  cursor: pointer;
}

.notif-item:first-of-type {
  border-top: 0;
}

.notif-item.unread .notif-msg {
  color: var(--text-primary);
}

.notif-icon-wrap {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 700;
}

.notif-icon-wrap.success {
  background: rgba(52, 211, 153, 0.12);
  color: #34d399;
}

.notif-icon-wrap.error {
  background: rgba(248, 113, 113, 0.12);
  color: #f87171;
}

.notif-icon-wrap.warning {
  background: rgba(251, 191, 36, 0.12);
  color: #fbbf24;
}

.notif-body {
  min-width: 0;
}

.notif-msg {
  font-size: 13px;
  font-weight: 600;
  color: var(--text-secondary);
}

.notif-time,
.notif-detail {
  font-size: 12px;
  color: var(--text-muted);
}

.notif-detail {
  margin-top: 3px;
  line-height: 1.45;
}

.notif-unread-dot {
  width: 8px;
  height: 8px;
  margin-top: 6px;
  border-radius: 999px;
  background: var(--accent);
}

.toast-container {
  position: fixed;
  right: 20px;
  top: 20px;
  display: grid;
  gap: 12px;
  z-index: 220;
}

.toast {
  display: flex;
  gap: 10px;
  min-width: 280px;
  max-width: 360px;
  padding: 12px 14px;
  border: 1px solid var(--border);
  border-radius: 14px;
  background: rgba(17, 17, 24, 0.96);
  box-shadow: var(--shadow);
}

.toast-dot {
  width: 10px;
  height: 10px;
  margin-top: 4px;
  border-radius: 999px;
  background: var(--accent);
}

.toast-msg {
  font-size: 13px;
  line-height: 1.45;
  color: var(--text-secondary);
}

/* ── Cruise Control rail toggle + Assistant placeholder (Phase 1A) ─── */
.cruise-toggle-bar {
  display: flex;
  gap: 4px;
  padding: 10px 10px;
  border-bottom: 1px solid var(--color-border);
  position: sticky;
  top: 0;
  background: var(--color-bg-panel, var(--color-bg-elevated));
  z-index: 5;
}
.cruise-toggle-pill {
  flex: 1;
  padding: 7px 0;
  border: 0;
  border-radius: 6px;
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  background: transparent;
  color: var(--color-text-muted);
  position: relative;
  transition: background 0.18s, color 0.18s;
}
.cruise-toggle-pill:hover:not(.active) { color: var(--color-text-primary); }
.cruise-toggle-pill.active {
  background: var(--color-accent);
  color: #fff;
}
.cruise-toggle-dot {
  position: absolute;
  top: 6px; right: 12px;
  width: 7px; height: 7px;
  border-radius: 50%;
  background: var(--color-accent);
  box-shadow: 0 0 0 2px var(--color-bg-panel);
}

/* .cruise-config-view is the wrapper around the existing panel-sections
   — no styling needed, the panel-section rules already apply. */

.cruise-assistant-view { display: flex; flex-direction: column; min-height: 0; flex: 1; }
.editor-right > .cruise-assistant-view { flex: 1; min-height: 0; }
.cruise-assistant-view > .cruise-scope-bar,
.cruise-assistant-view > .cruise-quick-row,
.cruise-assistant-view > .cruise-input-wrap,
.cruise-assistant-view > .cruise-assistant-foot,
.cruise-assistant-view > .cruise-prefs-panel { flex: 0 0 auto; }
.cruise-scope-bar {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--color-border);
  font-size: 11.5px;
}
.cruise-scope-label { color: var(--color-text-muted); }
.cruise-scope-pill {
  background: rgba(255, 107, 53, 0.12);
  color: var(--color-accent);
  padding: 2px 8px;
  border-radius: 999px;
  font-family: "Space Mono", monospace;
  font-weight: 600;
}
.cruise-scope-flip {
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 11px;
  cursor: pointer;
}
.cruise-scope-flip:hover { color: var(--color-text-primary); }
.cruise-scope-spacer { flex: 1; }
.cruise-scope-action {
  background: none; border: 1px solid var(--color-border); color: var(--color-text-muted);
  font-size: 11px; padding: 3px 9px; border-radius: 999px; cursor: pointer; white-space: nowrap;
}
.cruise-scope-action:hover { color: var(--color-text-primary); border-color: var(--color-border-active); }
.cruise-scope-action.active { color: var(--color-accent); border-color: var(--color-accent); }
.cruise-brief-lock { margin-left: 3px; }

/* Context dropdown — replaces the old pill + flip link. */
.cruise-scope-select-wrap { position: relative; display: inline-flex; align-items: center; }
.cruise-scope-select {
  appearance: none; -webkit-appearance: none;
  background: rgba(255,107,53,0.10); color: var(--color-accent);
  border: 1px solid rgba(255,107,53,0.25); border-radius: 8px;
  padding: 5px 26px 5px 12px; font-size: 12px; font-weight: 600;
  font-family: "Space Mono", monospace; cursor: pointer; outline: none;
}
.cruise-scope-select:hover { border-color: var(--color-accent); }
.cruise-scope-caret { position: absolute; right: 9px; color: var(--color-accent); font-size: 10px; pointer-events: none; }

.cruise-brief-panel {
  display: flex; flex-direction: column; gap: 8px;
  padding: 12px 14px; border-bottom: 1px solid var(--color-border);
  background: var(--color-bg-card);
}
.cruise-brief-sub { font-size: 11px; color: var(--color-text-muted); }
.cruise-brief-field { display: flex; flex-direction: column; gap: 3px; font-size: 11px; color: var(--color-text-muted); }
.cruise-brief-field input {
  background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 6px;
  padding: 5px 8px; font-size: 12px; color: var(--color-text-primary); outline: none; font-family: inherit;
}
.cruise-brief-field input:focus { border-color: var(--color-accent); }
.cruise-brief-foot { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }

.cruise-assistant-body {
  flex: 1; overflow-y: auto;
  padding: 24px 18px;
  display: flex; flex-direction: column; gap: 14px;
}
.cruise-coming-soon {
  margin: auto;
  text-align: center;
  max-width: 240px;
}
.cruise-coming-icon {
  font-size: 28px;
  color: var(--color-accent);
  opacity: 0.8;
  margin-bottom: 8px;
}
.cruise-coming-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-text-primary);
  margin-bottom: 6px;
}
.cruise-coming-body {
  font-size: 12px;
  color: var(--color-text-muted);
  line-height: 1.55;
}
.cruise-coming-body em { color: var(--color-text-secondary); font-style: normal; background: rgba(255,255,255,0.03); padding: 1px 4px; border-radius: 3px; }

.cruise-input-wrap {
  padding: 10px 14px;
  border-top: 1px solid var(--color-border);
}
.cruise-input-box {
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 9px;
  padding: 8px 10px;
}
.cruise-input-disabled { opacity: 0.55; }
.cruise-input {
  width: 100%;
  background: transparent;
  border: 0;
  outline: 0;
  color: var(--color-text-primary);
  font-size: 12.5px;
  font-family: inherit;
  resize: none;
}
.cruise-input::placeholder { color: var(--color-text-muted); }
.cruise-input-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.cruise-input-mode { padding: 2px 8px; border-radius: 999px; background: rgba(255,107,53,0.12); color: var(--color-accent); font-size: 10.5px; font-weight: 600; font-family: "Space Mono", monospace; }
/* Icon-only tools on the input row (theme + settings), tooltip on hover. */
.cruise-input-icon {
  position: relative; display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border: 1px solid var(--color-border); border-radius: 8px;
  background: none; color: var(--color-text-muted); font-size: 14px; cursor: pointer;
}
.cruise-input-icon:hover { color: var(--color-text-primary); border-color: var(--color-border-active); }
.cruise-input-icon.active { color: var(--color-accent); border-color: var(--color-accent); background: rgba(255,107,53,0.08); }
.cruise-icon-dot { position: absolute; top: 3px; right: 3px; width: 5px; height: 5px; border-radius: 50%; background: var(--color-accent); }
.cruise-input-send { margin-left: auto; background: var(--color-accent); color: #fff; border: 0; border-radius: 6px; padding: 5px 12px; font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: inherit; }
.cruise-input-send:disabled { opacity: 0.45; cursor: not-allowed; }

/* Chat messages */
.cruise-msg { display: flex; gap: 9px; }
.cruise-msg-avatar { width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; font-weight: 600; }
.cruise-msg-user .cruise-msg-avatar { background: rgba(255,255,255,0.06); color: var(--color-text-primary); }
.cruise-msg-ai .cruise-msg-avatar { background: rgba(255,107,53,0.14); color: var(--color-accent); }
.cruise-msg-body { flex: 1; min-width: 0; font-size: 12.5px; line-height: 1.55; }
.cruise-msg-text { color: var(--color-text-primary); }
.cruise-msg-thinking { color: var(--color-text-muted); font-style: italic; }

/* Multi-action stack — wraps the per-card cruise-action blocks plus
   the Plan: header and the Apply-all master footer. */
.cruise-actions-stack { display: flex; flex-direction: column; gap: 0; margin-top: 6px; }
.cruise-actions-meta { font-size: 10.5px; color: var(--color-text-muted); padding: 4px 2px 2px; letter-spacing: 0.02em; }
.cruise-action-step { font-weight: 700; color: var(--color-text-muted); font-size: 11px; font-family: "Space Mono", monospace; }
.cruise-actions-master-foot {
  display: flex; gap: 6px; justify-content: flex-end;
  padding: 8px 0 2px; margin-top: 4px;
}

/* Action card */
.cruise-action { margin-top: 8px; border: 1px solid var(--color-border); border-radius: 9px; overflow: hidden; background: var(--color-bg-card); }
.cruise-action-head { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }
.cruise-action-icon { color: var(--color-accent); }
.cruise-action-title { font-weight: 600; font-size: 12px; flex: 1; color: var(--color-text-primary); }
.cruise-action-cost { font-family: "Space Mono", monospace; color: var(--color-text-muted); font-size: 10.5px; }
.cruise-action-body { padding: 10px 12px; font-size: 11.5px; color: var(--color-text-muted); line-height: 1.7; }
.cruise-action-foot { padding: 8px 10px; display: flex; gap: 6px; border-top: 1px solid var(--color-border); }
.cruise-action-btn { padding: 5px 11px; border-radius: 6px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-primary); font-size: 11.5px; cursor: pointer; font-family: inherit; }
.cruise-action-btn:hover:not(:disabled) { border-color: var(--color-border-active, rgba(255,255,255,0.18)); }
.cruise-action-btn:disabled { opacity: 0.55; cursor: not-allowed; }
.cruise-action-btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; font-weight: 600; }
.cruise-action-btn-ghost { color: var(--color-text-muted); }
.cruise-action-status { padding: 8px 12px; border-top: 1px solid var(--color-border); font-size: 11.5px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
.cruise-action-status.applied { color: #34d399; background: rgba(52,211,153,0.06); }
.cruise-action-status.undone { color: var(--color-text-muted); background: var(--color-bg-elevated); }
.cruise-action-status-text { flex: 1; }
.cruise-action-undo {
  background: none; border: 1px solid var(--color-border); color: var(--color-text-muted);
  font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 999px; cursor: pointer; white-space: nowrap;
}
.cruise-action-undo:hover:not(:disabled) { color: var(--color-text-primary); border-color: var(--color-border-active); }
.cruise-action-undo:disabled { opacity: 0.5; cursor: default; }
.cruise-action-status.unknown {
  color: var(--color-warning, #fbbf24);
  background: rgba(251, 191, 36, 0.08);
  border: 1px solid rgba(251, 191, 36, 0.22);
  border-radius: 8px;
  padding: 8px 10px;
  font-size: 12px;
  line-height: 1.5;
}
.cruise-action-status.failed { color: #f87171; background: rgba(248,113,113,0.06); flex-direction: column; align-items: stretch; gap: 6px; }
.cruise-action-fail-row { display: flex; align-items: center; gap: 8px; }
.cruise-action-fail-msg { flex: 1; }
.cruise-action-fail-detail { font-family: "Space Mono", monospace; font-size: 10px; font-weight: 400; color: var(--color-text-muted); background: var(--color-bg-elevated); border-radius: 6px; padding: 6px 8px; word-break: break-word; white-space: pre-wrap; }
.cruise-action-status.running { color: var(--color-accent, #ff6b35); background: rgba(255,107,53,0.06); }
.cruise-action-status.skipped { color: var(--color-text-muted, #94a3b8); background: rgba(148,163,184,0.06); }
.cruise-action-spinner { width: 12px; height: 12px; border: 2px solid rgba(255,107,53,0.25); border-top-color: var(--color-accent, #ff6b35); border-radius: 50%; animation: cruise-spinner-rot 0.8s linear infinite; flex-shrink: 0; }
@keyframes cruise-spinner-rot { to { transform: rotate(360deg); } }
.cruise-action-running-text { display: inline-flex; align-items: baseline; }
.cruise-action-checklist { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; width: 100%; }
.cruise-checklist-item { display: flex; align-items: center; gap: 8px; font-size: 11.5px; transition: color 0.2s, opacity 0.2s; }
.cruise-checklist-item.pending { color: var(--color-accent, #ff6b35); }
.cruise-checklist-item.done { color: var(--color-text-muted, #94a3b8); opacity: 0.7; }
.cruise-checklist-item.done .cruise-checklist-label { text-decoration: line-through; }
.cruise-checklist-icon { display: inline-flex; align-items: center; justify-content: center; width: 14px; height: 14px; flex-shrink: 0; }
.cruise-checklist-icon > span { font-weight: 700; color: #34d399; }
.cruise-action-dots { display: inline-flex; margin-left: 1px; }
.cruise-action-dots > span { opacity: 0.2; animation: cruise-dots-blink 1.4s infinite both; }
.cruise-action-dots > span:nth-child(2) { animation-delay: 0.2s; }
.cruise-action-dots > span:nth-child(3) { animation-delay: 0.4s; }
@keyframes cruise-dots-blink { 0%, 80%, 100% { opacity: 0.2; } 40% { opacity: 1; } }
.cruise-action-shortfall { font-size: 11.5px; color: var(--color-text-muted); margin-right: auto; padding: 6px 0; }

/* Quick prompt chips — only shown when conversation is empty */
.cruise-quick-row {
  display: flex; flex-wrap: wrap; gap: 5px;
  padding: 8px 14px;
  border-top: 1px solid var(--color-border);
}
.cruise-quick-chip {
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 10.5px;
  color: var(--color-text-muted);
  cursor: pointer;
  transition: 0.15s;
}
.cruise-quick-chip:hover { border-color: rgba(255,107,53,0.4); color: var(--color-text-primary); }

.cruise-prefs-trigger {
  background: transparent; border: 1px solid var(--color-border);
  color: var(--color-text-muted); cursor: pointer; padding: 2px 7px;
  border-radius: 6px; font-size: 13px; font-family: inherit;
}
.cruise-prefs-trigger:hover { color: var(--color-accent); border-color: var(--color-accent); }
.cruise-prefs-panel {
  border-top: 1px solid var(--color-border);
  padding: 12px 14px; background: var(--color-bg-card);
  display: flex; flex-direction: column; gap: 10px;
  flex: 0 0 auto;
}
.cruise-prefs-head {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 11.5px; font-weight: 600; color: var(--color-text-primary);
}
.cruise-prefs-close {
  background: transparent; border: 0; color: var(--color-text-muted);
  cursor: pointer; font-size: 12px; padding: 2px 4px;
}
.cruise-prefs-close:hover { color: var(--color-text-primary); }
.cruise-prefs-row { display: flex; flex-direction: column; gap: 4px; }
.cruise-prefs-row label { font-size: 10.5px; color: var(--color-text-muted); letter-spacing: 0.02em; }
.cruise-prefs-row select {
  background: var(--color-bg-card); border: 1px solid var(--color-border);
  border-radius: 6px; padding: 6px 8px; font-size: 11.5px;
  color: var(--color-text-primary); font-family: inherit;
}
.cruise-prefs-row select:focus { outline: none; border-color: var(--color-accent); }
.cruise-prefs-hint { font-size: 10.5px; color: var(--color-text-muted); line-height: 1.5; }

/* Assistant footer — auto-apply pref + balance */
.cruise-assistant-foot {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px;
  padding: 8px 14px;
  border-top: 1px solid var(--color-border);
  font-size: 10.5px;
  font-family: "Space Mono", monospace;
  color: var(--color-text-muted);
}
.cruise-auto-toggle {
  display: flex; align-items: center; gap: 6px;
  cursor: pointer;
}
.cruise-auto-toggle input[type="checkbox"] { accent-color: var(--color-accent); cursor: pointer; }
.cruise-balance-hint { white-space: nowrap; margin-left: auto; font-family: "Space Mono", monospace; }

/* Apply toast at top of Config view */
.cruise-toast {
  margin: 8px 10px;
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(52,211,153,0.10);
  border: 1px solid rgba(52,211,153,0.32);
  color: #34d399;
  font-size: 12px;
  font-weight: 600;
  animation: cruise-toast-in 0.25s ease-out;
}
@keyframes cruise-toast-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

/* Pulse animation on Config sections after a Cruise apply lands */
.panel-section.cruise-pulse {
  animation: cruise-section-pulse 1.6s ease-out;
}
@keyframes cruise-section-pulse {
  0%   { box-shadow: inset 0 0 0 2px rgba(52,211,153,0.55); background-color: rgba(52,211,153,0.10); }
  60%  { box-shadow: inset 0 0 0 1px rgba(52,211,153,0.30); background-color: rgba(52,211,153,0.05); }
  100% { box-shadow: inset 0 0 0 0 transparent; background-color: transparent; }
}

.notif-badge {
  position: absolute;
  top: -5px;
  right: -5px;
  width: 17px;
  height: 17px;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-size: 9px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid var(--bg-deep);
}

.editor-body {
  display: flex;
  flex-direction: column;
  height: calc(100vh - 64px);
  overflow: hidden;
}

.editor {
  display: flex;
  flex: 1;
  min-height: 0;
}

.editor-sidebar,
.editor-right {
  background: var(--bg-panel);
}

.editor-sidebar {
  width: 320px;
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
}

.editor-sidebar-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.editor-sidebar-title {
  font-size: 14px;
  font-weight: 600;
}

.scene-list {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
}

.scene-item {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 12px;
  margin-bottom: 4px;
  cursor: pointer;
  transition: 0.2s;
}

.scene-item:hover {
  border-color: var(--border-active);
}

.scene-item.active {
  border-color: var(--accent);
  background: var(--accent-glow);
}

.scene-number {
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 6px;
}

.inline-warn {
  font-size: 9px;
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--yellow-bg);
  color: var(--yellow);
  font-weight: 600;
  margin-left: 4px;
}

.state-hidden {
  display: none !important;
}

.scene-regen-row {
  margin-top: 6px;
}

.scene-regen-btn {
  font-size: 10px;
  font-family: inherit;
  padding: 3px 8px;
  border-radius: 5px;
  border: 1px solid rgba(251, 191, 36, 0.35);
  background: rgba(251, 191, 36, 0.08);
  color: #fbbf24;
  cursor: pointer;
  transition: 0.15s;
}

.scene-regen-btn:hover:not(:disabled) {
  background: rgba(251, 191, 36, 0.16);
}

.scene-regen-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.scene-text {
  font-size: 13px;
  line-height: 1.5;
  color: var(--text-secondary);
}

.scene-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
  font-size: 11px;
  color: var(--text-muted);
}

.scene-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.scene-action-btn {
  padding: 4px 8px;
  border: 1px solid var(--border);
  border-radius: 6px;
  background: rgba(10, 10, 15, 0.55);
  color: var(--text-secondary);
  font-size: 11px;
  line-height: 1;
  cursor: pointer;
}

.scene-action-btn:hover:not(:disabled) {
  border-color: var(--border-active);
  color: var(--text-primary);
}

.scene-action-btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.scene-action-btn.danger {
  color: #ff9d9d;
}

/* ── Custom row-list pickers (style + model) ─────────────────────────
   One rounded trigger button that opens a panel of stacked rows. Each
   row: [thumbnail | label / desc | cost? | check]. Visually richer than
   a native <select> while still being a single concentrated control. */
.picker-wrap { position: relative; }
.picker-trigger {
  display: flex; align-items: center; gap: 10px; width: 100%;
  padding: 8px 12px; border-radius: 8px;
  border: 1px solid var(--color-border); background: var(--color-bg-elevated);
  color: var(--color-text-primary); cursor: pointer;
  text-align: left; font-family: inherit; font-size: 13px;
  transition: border-color 0.15s;
}
.picker-trigger:hover { border-color: rgba(255,107,53,0.4); }
.picker-trigger-thumb { width: 28px; height: 28px; border-radius: 6px; object-fit: cover; flex-shrink: 0; }
.picker-trigger-glyph { width: 28px; height: 28px; border-radius: 6px; background: var(--color-bg-card); display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.picker-trigger-label { font-weight: 600; flex-shrink: 0; }
.picker-trigger-sub { font-size: 11.5px; color: var(--color-text-muted); margin-left: 4px; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.picker-trigger-cost { font-family: "Space Mono", monospace; font-size: 11px; color: var(--color-accent); font-weight: 600; }
.picker-trigger-caret { font-size: 9px; opacity: 0.55; margin-left: 4px; }

.picker-panel {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0;
  background: var(--color-bg-panel); border: 1px solid var(--color-border);
  border-radius: 10px; box-shadow: 0 10px 28px rgba(0,0,0,0.5);
  max-height: 360px; overflow-y: auto; padding: 4px; z-index: 30;
}
.picker-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 7px; cursor: pointer;
  transition: background 0.12s;
}
.picker-row:hover:not(.disabled) { background: var(--color-bg-elevated); }
.picker-row.selected { background: rgba(255,107,53,0.08); }
.picker-row.disabled { opacity: 0.4; cursor: not-allowed; }
.picker-row-thumb { width: 44px; height: 44px; border-radius: 6px; object-fit: cover; flex-shrink: 0; background: var(--color-bg-card); }
.picker-row-glyph { width: 44px; height: 44px; border-radius: 6px; background: var(--color-bg-card); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.picker-row-text { flex: 1; min-width: 0; }
.picker-row-text-flex { display: flex; flex-direction: column; gap: 1px; }
.picker-row-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.picker-row-desc { font-size: 11px; color: var(--color-text-muted); margin-top: 1px; line-height: 1.4; }
.picker-row-cost { font-size: 10.5px; color: var(--color-text-muted); font-family: "Space Mono", monospace; flex-shrink: 0; }
.picker-row.selected .picker-row-cost { color: var(--color-accent); }
.picker-row-check { color: var(--color-accent); font-size: 14px; font-weight: 700; flex-shrink: 0; margin-left: 4px; }

.scene-tag {
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--bg-elevated);
  font-size: 10px;
}

.scene-style-badge {
  padding: 2px 5px;
  border-radius: 3px;
  font-size: 9px;
  font-weight: 500;
  background: rgba(99,102,241,0.15);
  color: #a5b4fc;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

/* Visual style picker */
.visual-style-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
}

.visual-style-btn {
  padding: 5px 0;
  border-radius: 5px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  font-size: 10px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
  text-align: center;
}

.visual-style-btn:hover {
  border-color: rgba(99,102,241,0.4);
  color: var(--text);
}

.visual-style-btn.active {
  border-color: #6366f1;
  background: rgba(99,102,241,0.15);
  color: #a5b4fc;
}

.style-regen-hint {
  margin-top: 6px;
  font-size: 11px;
  color: #fbbf24;
  opacity: 0.85;
}
.current-style-note {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-bottom: 6px;
}
.current-style-note strong {
  color: var(--color-text-secondary);
}

/* Font picker */
.font-picker-block {
  margin-top: 10px;
  border-top: 1px solid var(--border);
  padding-top: 10px;
}

.font-dropdown {
  position: relative;
}

.font-dropdown-trigger {
  width: 100%;
  min-height: 52px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-card);
  color: var(--text);
  cursor: pointer;
  text-align: left;
}

.font-trigger-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.font-trigger-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 18px;
  line-height: 1.15;
  color: var(--text-primary);
}

.font-trigger-group {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
}

.font-trigger-chevron {
  color: var(--text-muted);
  flex: 0 0 auto;
}

.font-dropdown-menu {
  position: absolute;
  z-index: 20;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  max-height: 320px;
  overflow-y: auto;
  padding: 8px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #111118;
  box-shadow: 0 18px 40px rgba(0, 0, 0, 0.35);
}

.font-group {
  margin-bottom: 10px;
}

.font-group:last-child {
  margin-bottom: 0;
}

.font-group-label {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
  margin-bottom: 4px;
  opacity: 0.6;
}

.font-option {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 7px 8px;
  border-radius: 6px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--text);
  cursor: pointer;
  text-align: left;
  transition: all 0.12s;
}

.font-option:hover {
  background: rgba(255,255,255,0.04);
  border-color: var(--border);
}

.font-option.active {
  background: rgba(99,102,241,0.15);
  border-color: #6366f1;
  color: #a5b4fc;
}

.font-option-preview {
  min-width: 0;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 18px;
  line-height: 1.2;
}

.font-option-name {
  flex: 0 0 auto;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 10px;
  color: var(--text-muted);
}

.add-scene-divider {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 18px;
  opacity: 0;
  transition: opacity 0.15s;
}

.scene-item:hover + .add-scene-divider,
.add-scene-divider:hover {
  opacity: 1;
}

.add-scene-divider.always-visible {
  opacity: 0.5;
}

.add-scene-trigger {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--text-muted);
  cursor: pointer;
  padding: 2px 10px;
  border-radius: 10px;
  transition: 0.15s;
  border: 1px dashed transparent;
}

.add-scene-trigger:hover {
  color: var(--accent);
  border-color: var(--accent);
  background: var(--accent-glow);
}

.add-scene-panel {
  background: var(--bg-card);
  border: 1px solid var(--accent);
  border-radius: var(--radius);
  padding: 14px;
  margin-bottom: 6px;
  display: none;
  animation: slideDown 0.2s ease;
}

.add-scene-panel.open {
  display: block;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-6px);
  }

  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.panel-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--accent);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.close-x {
  cursor: pointer;
  color: var(--text-muted);
  font-size: 16px;
  line-height: 1;
  padding: 2px;
}

.micro-label {
  font-size: 11px;
  color: var(--text-muted);
  margin-bottom: 8px;
  margin-top: 14px;
}
.micro-label:first-child { margin-top: 0; }

/* Slightly taller picker trigger in panel so labels don't crowd the
   caret / cost chip. Adds breathing room when stacked under labels. */
.picker-wrap + .micro-label,
.picker-wrap + .edit-image-box { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--color-border); }
.edit-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.edit-chip {
  background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 99px;
  padding: 4px 10px; font-size: 11.5px; color: var(--color-text-secondary);
  cursor: pointer; font-family: inherit;
}
.edit-chip:hover { border-color: var(--color-border-active); color: var(--color-text-primary); }
.ai-image-actions { margin-top: 16px; }

/* Style picker grid (used by "Add scene" inline) — slightly more
   spacing so the small thumbnails breathe. */
.style-picker-grid { row-gap: 8px; }
.style-opt { padding: 8px 6px; }
.style-opt-thumb {
  width: 36px; height: 36px; border-radius: 6px;
  object-fit: cover; display: block; margin: 0 auto 6px;
}
/* Custom-style descriptor panel — give it room above so it doesn't
   touch the cards / hint text. */
.custom-style-panel { margin-top: 12px; }
.current-style-note { margin: 2px 0 12px; }

.scene-type-chips,
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.chips-tight {
  gap: 6px;
  margin-top: 0;
}

.chips-tight .chip {
  border-radius: var(--radius-sm);
}

.scene-type-chip,
.chip {
  cursor: pointer;
  transition: 0.15s ease;
}

.scene-type-chip {
  padding: 4px 10px;
  border-radius: 4px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  font-size: 11px;
  color: var(--text-secondary);
}

.scene-type-chip.selected {
  border-color: var(--accent);
  background: var(--accent-glow);
  color: var(--accent);
}

.chip {
  padding: 6px 10px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-secondary);
  font-size: 12px;
}

.chip:hover,
.scene-type-chip:hover,
.add-scene-visual-opt:hover {
  border-color: var(--border-active);
}

.chip.disabled {
  opacity: 0.45;
  cursor: wait;
  pointer-events: none;
}

.add-scene-textarea,
.rewrite-custom-input,
.control-value {
  width: 100%;
  border-radius: 8px;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-primary);
  font-family: inherit;
}

.control-value-editable {
  cursor: text;
  padding: 6px 10px;
  font-size: 12px;
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.control-value-editable:hover { border-color: var(--color-accent); color: var(--color-accent); }

.control-value-input {
  width: 100%;
  border-radius: 8px;
  border: 1px solid var(--color-accent);
  background: var(--bg-elevated);
  color: var(--text-primary);
  font-family: inherit;
  font-size: 12px;
  padding: 6px 10px;
  outline: none;
}

.control-value {
  background: var(--bg-card);
  font-size: 12px;
  padding: 4px 10px;
}

.add-scene-textarea {
  padding: 8px 10px;
  font-size: 12px;
  resize: vertical;
  min-height: 52px;
  margin-bottom: 8px;
}

.script-textarea {
  min-height: 72px;
  margin-bottom: 0;
}

/* ── Scene Script section restyle ─────────────────────────────────────── */
.ss-caption {
  font-size: 11.5px; line-height: 1.45; color: var(--color-text-muted); margin-top: 7px;
}
.ss-duration-row {
  display: flex; align-items: center; justify-content: space-between; margin-top: 14px;
}
.ss-duration-label { font-size: 13px; color: var(--color-text-secondary); }
.ss-duration-field {
  display: flex; align-items: center; background: var(--color-bg-elevated);
  border: 1px solid var(--color-border); border-radius: 8px; padding: 0 10px 0 0;
  transition: border-color 0.15s;
}
.ss-duration-field:focus-within { border-color: rgba(255, 107, 53, 0.45); }
.ss-duration-field input {
  width: 52px; background: transparent; border: none; outline: none;
  color: var(--color-text-primary); font-family: "Space Mono", monospace; font-size: 13px;
  padding: 7px 4px 7px 10px; text-align: right; -moz-appearance: textfield;
}
.ss-duration-field input::-webkit-outer-spin-button,
.ss-duration-field input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.ss-duration-field .ss-duration-unit {
  font-family: "Space Mono", monospace; font-size: 11px; color: var(--color-text-muted);
}
.ss-duration-caption {
  font-size: 11px; color: var(--color-text-muted); margin-top: 6px; text-align: right;
}
.ss-divider { height: 1px; background: var(--color-border); margin: 16px -16px; opacity: 0.6; }

.ss-rewrite-trigger {
  display: flex; align-items: center; gap: 8px; width: 100%;
  background: var(--color-bg-elevated); border: 1px solid var(--color-border);
  border-radius: 10px; padding: 9px 13px; color: var(--color-text-secondary);
  font-family: inherit; font-size: 13px; font-weight: 500; cursor: pointer;
  transition: border-color 0.15s, color 0.15s;
}
.ss-rewrite-trigger:hover { border-color: rgba(255, 107, 53, 0.45); color: var(--color-text-primary); }
.ss-rewrite-trigger .ss-spark { color: var(--color-accent); }
.ss-rewrite-trigger .ss-chev { margin-left: auto; opacity: 0.7; }

.ss-rewrite-head {
  display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none;
}
.ss-rewrite-head .ss-spark { color: var(--color-accent); }
.ss-rewrite-label {
  font-family: "Space Mono", monospace; font-size: 10.5px; font-weight: 700;
  letter-spacing: 0.16em; text-transform: uppercase; color: var(--color-text-secondary);
}
.ss-rule { flex: 1; height: 1px; background: var(--color-border); opacity: 0.6; }
.ss-chev-open { transform: rotate(180deg); }

.ss-rewrite-body { margin-top: 14px; }
.ss-group { margin-bottom: 13px; }
.ss-group-label {
  font-family: "Space Mono", monospace; font-size: 9.5px; letter-spacing: 0.16em;
  text-transform: uppercase; color: var(--color-text-muted); margin-bottom: 7px;
}
.ss-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.ss-chip {
  font-family: inherit; font-size: 12px; font-weight: 500; color: var(--color-text-secondary);
  background: var(--color-bg-elevated); border: 1px solid var(--color-border);
  border-radius: 999px; padding: 5px 12px; cursor: pointer;
  transition: border-color 0.15s, color 0.15s, background 0.15s;
}
.ss-chip:hover { border-color: rgba(255, 107, 53, 0.45); color: var(--color-text-primary); }
.ss-chip:active { background: rgba(255, 107, 53, 0.12); border-color: rgba(255, 107, 53, 0.45); color: var(--color-accent); }
.ss-chip.disabled { opacity: 0.5; pointer-events: none; }

.ss-custom-row { position: relative; margin-top: 14px; }
.ss-custom-input {
  width: 100%; background: var(--color-bg-elevated); border: 1px solid var(--color-border);
  border-radius: 10px; padding: 9px 42px 9px 32px; color: var(--color-text-primary);
  font-family: inherit; font-size: 13px; outline: none; transition: border-color 0.15s;
}
.ss-custom-input::placeholder { color: var(--color-text-muted); }
.ss-custom-input:focus { border-color: rgba(255, 107, 53, 0.45); }
.ss-custom-icon {
  position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
  color: var(--color-text-muted); font-size: 12px; pointer-events: none;
}
.ss-custom-input:focus ~ .ss-custom-icon { color: var(--color-accent); }
.ss-apply-btn {
  position: absolute; right: 5px; top: 50%; transform: translateY(-50%);
  width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;
  background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 8px;
  color: var(--color-text-muted); cursor: pointer; transition: all 0.15s; font-size: 13px;
}
.ss-apply-btn:hover:not(:disabled) { background: var(--color-accent); border-color: var(--color-accent); color: #0a0a0b; }
.ss-apply-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.ss-footnote {
  font-size: 10.5px; color: var(--color-text-muted); margin-top: 10px; text-align: center;
}
.ss-dot { margin: 0 5px; opacity: 0.6; }

.add-scene-textarea:focus,
.rewrite-custom-input:focus,
.control-value:focus {
  outline: none;
  border-color: rgba(255, 107, 53, 0.45);
}

.add-scene-visual-source {
  margin-top: 12px;
  margin-bottom: 10px;
}

.add-scene-tabs {
  margin-bottom: 10px;
}

.add-scene-control-row {
  margin-top: 8px;
}

/* Prominent query input used in both add-scene and edit visual panels */
.scene-query-label {
  font-size: 11px;
  color: var(--text-muted);
  font-weight: 500;
  letter-spacing: 0.04em;
  margin-bottom: 5px;
}

.scene-query-input {
  display: block;
  width: 100%;
  box-sizing: border-box;
  padding: 10px 12px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text-primary);
  font-family: inherit;
  font-size: 12px;
  line-height: 1.5;
  resize: none;
  transition: border-color 0.15s;
  margin-bottom: 2px;
}

.scene-query-input::placeholder {
  color: var(--text-muted);
  opacity: 0.7;
}

.scene-query-input:focus {
  outline: none;
  border-color: rgba(255, 107, 53, 0.45);
  background: var(--bg-card);
}

.add-scene-actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}

.purple-btn {
  color: var(--purple);
  border-color: var(--purple);
}

.editor-sheet-head,
.editor-sheet-scrim,
.ed-projsheet,
.btn-mobile-config { display: none; }

.editor-canvas {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  background: var(--bg-deep);
  position: relative;
}

.preview-container {
  /* width/height come from :style binding (previewContainerStyle) to match the project aspect ratio */
  /* Captions size in cqh against this frame's real height, as the export sizes them against the video's. */
  container-type: size;
  width: 270px;
  height: 480px;
  background: #000;
  border-radius: 16px;
  overflow: hidden;
  position: relative;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}

.preview-video-bg {
  width: 100%;
  height: 100%;
  position: relative;
  overflow: hidden;
  background: linear-gradient(180deg, #1a1a3e 0%, #0d0d2b 40%, #1a0a2e 100%);
}

.preview-image,
.preview-fallback {
  width: 100%;
  height: 100%;
}

.preview-image {
  object-fit: cover;
  transform-origin: center center;
}

/* ── Character chip + popover (inside Visual panel AI Image section) ─── */
.char-chip-wrap { position: relative; margin-bottom: 10px; }
.char-chip {
  display: flex; align-items: center; gap: 9px;
  padding: 7px 9px;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.char-chip:hover { border-color: rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); }
.char-chip-thumb {
  width: 30px; height: 30px; border-radius: 6px;
  background: linear-gradient(135deg, #ff6b35 0%, #cf4f1d 100%);
  flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 13px; color: #0a0a0f;
  overflow: hidden;
}
.char-chip-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.char-chip-text { flex: 1; min-width: 0; }
.char-chip-name { font-size: 12.5px; font-weight: 600; line-height: 1.2; }
.char-chip-trail { font-size: 10.5px; opacity: 0.55; margin-top: 1px; }
.char-chip-chev { font-size: 11px; opacity: 0.55; }
.char-popover {
  position: absolute; top: calc(100% + 6px); left: 0; right: 0;
  background: #14141c;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 10px;
  box-shadow: 0 14px 40px rgba(0,0,0,0.55);
  z-index: 30;
  padding: 10px;
}
.char-popover-empty { font-size: 12px; opacity: 0.55; text-align: center; padding: 18px 6px; }
.char-popover-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; max-height: 200px; overflow-y: auto; }
.char-popover-item {
  border-radius: 7px;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.08);
  padding: 7px 5px;
  display: flex; flex-direction: column; align-items: center; gap: 5px;
  cursor: pointer; text-align: center;
  transition: border-color 0.15s, background 0.15s;
}
.char-popover-item:hover { border-color: rgba(255,255,255,0.18); background: rgba(255,255,255,0.06); }
.char-popover-item.selected { border-color: rgba(255,107,53,0.55); background: rgba(255,107,53,0.12); }
.char-popover-thumb {
  width: 36px; height: 36px; border-radius: 6px;
  background: linear-gradient(135deg, #ff6b35 0%, #cf4f1d 100%);
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 14px; color: #0a0a0f;
  overflow: hidden;
}
.char-popover-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }

/* Upload zone in the create-character modal */
.char-upload-drop {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  border: 1.5px dashed rgba(255,255,255,0.16); border-radius: 10px;
  padding: 22px 12px; text-align: center; cursor: pointer;
  background: rgba(255,255,255,0.02); transition: 0.15s;
}
.char-upload-drop:hover { border-color: rgba(255,107,53,0.45); background: rgba(255,107,53,0.05); }
.char-upload-ico { font-size: 22px; opacity: 0.55; }
.char-upload-copy { font-size: 13px; font-weight: 500; margin-top: 4px; }
.char-upload-sub { font-size: 11px; opacity: 0.5; margin-top: 2px; }
.char-upload-preview { position: relative; width: 100%; aspect-ratio: 1; border-radius: 10px; overflow: hidden; background: #0a0a0f; }
.char-upload-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
.char-upload-clear {
  position: absolute; top: 8px; right: 8px;
  background: rgba(0,0,0,0.55); color: #fff;
  border: 1px solid rgba(255,255,255,0.15);
  font-size: 11px; padding: 4px 8px; border-radius: 6px;
  cursor: pointer; font-family: inherit;
}
.char-upload-clear:hover { background: rgba(0,0,0,0.75); }
.char-popover-name { font-size: 10.5px; font-weight: 600; line-height: 1.15; word-break: break-word; }
.char-popover-trail { font-size: 9.5px; opacity: 0.45; margin-top: 2px; }
.char-popover-foot {
  display: flex; justify-content: space-between; align-items: center;
  border-top: 1px solid rgba(255,255,255,0.08);
  padding-top: 8px; margin-top: 8px;
}
.char-popover-none {
  background: transparent; border: none; color: rgba(255,255,255,0.5);
  font-size: 11px; cursor: pointer; font-family: inherit;
}
.char-popover-none:hover { color: #fff; }
.char-popover-new {
  background: transparent; border: none; color: #ff6b35;
  font-size: 11px; font-weight: 600; cursor: pointer; font-family: inherit;
}
.char-popover-new:hover { color: #ff8055; }

/* Hooks for the create-character modal — reuses .ap-* styles */
.ap-hint { font-size: 11px; opacity: 0.55; margin-top: 4px; }
.ap-spokesperson-warn { background: rgba(255,107,53,0.10); border: 1px solid rgba(255,107,53,0.3); color: var(--color-text-primary); border-radius: 8px; padding: 9px 11px; font-size: 12px; line-height: 1.4; margin: 4px 0 14px; }
.lipsync-stale-row { display: flex; flex-direction: column; gap: 8px; background: rgba(255,107,53,0.10); border: 1px solid rgba(255,107,53,0.35); border-radius: 8px; padding: 10px 11px; margin: 8px 0; }
.lipsync-stale-copy { font-size: 12px; line-height: 1.4; color: var(--color-text-primary); }
.ap-error { font-size: 12px; color: #ff6b6b; margin: 10px 0; }


.preview-fit-bg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  filter: blur(18px) saturate(1.05);
  transform: scale(1.1);
}

.preview-video-contain {
  object-fit: contain;
  position: relative;
  z-index: 1;
}

/* Ken Burns canvas preview — approximate visual (real motion rendered by FFmpeg) */
@keyframes kb-zoom-in {
  from { transform: scale(1); }
  to   { transform: scale(1.25); }
}
@keyframes kb-zoom-out {
  from { transform: scale(1.25); }
  to   { transform: scale(1); }
}
@keyframes kb-pan-left {
  from { transform: scale(1.15) translateX(8%); }
  to   { transform: scale(1.15) translateX(-8%); }
}
@keyframes kb-pan-right {
  from { transform: scale(1.15) translateX(-8%); }
  to   { transform: scale(1.15) translateX(8%); }
}
@keyframes kb-pan-up {
  from { transform: scale(1.15) translateY(8%); }
  to   { transform: scale(1.15) translateY(-8%); }
}
@keyframes kb-pan-down {
  from { transform: scale(1.15) translateY(-8%); }
  to   { transform: scale(1.15) translateY(8%); }
}
@keyframes kb-pan-zoom {
  from { transform: scale(1) translateX(-5%); }
  to   { transform: scale(1.25) translateX(5%); }
}

.kb-zoom-in  { animation: kb-zoom-in  6s ease-in-out infinite alternate; }
.kb-zoom-out { animation: kb-zoom-out 6s ease-in-out infinite alternate; }
.kb-pan-left { animation: kb-pan-left 6s ease-in-out infinite alternate; }
.kb-pan-right { animation: kb-pan-right 6s ease-in-out infinite alternate; }
.kb-pan-up   { animation: kb-pan-up   6s ease-in-out infinite alternate; }
.kb-pan-down { animation: kb-pan-down 6s ease-in-out infinite alternate; }
.kb-pan-zoom { animation: kb-pan-zoom 6s ease-in-out infinite alternate; }

.preview-fallback {
  background: linear-gradient(180deg, #1a1a3e 0%, #0d0d2b 40%, #1a0a2e 100%);
}

.preview-fallback-text,
.preview-fallback-waveform {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 32px;
}

.text-only-card {
  width: 100%;
  height: 100%;
  border-radius: 22px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background:
    radial-gradient(circle at top left, rgba(255, 107, 53, 0.22), transparent 35%),
    linear-gradient(180deg, rgba(30, 30, 44, 0.96), rgba(12, 12, 21, 0.96));
  padding: 28px 24px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 18px;
}

.text-only-label {
  font-family: "Space Mono", monospace;
  font-size: 11px;
  letter-spacing: 0.18em;
  color: rgba(255, 255, 255, 0.45);
}

.text-only-copy {
  font-size: 28px;
  line-height: 1.28;
  font-weight: 700;
  color: #fff;
  text-align: center;
}

.waveform-shell {
  width: 100%;
  height: 100%;
  padding: 28px 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 24px;
}

/* ── Classic bars ── */
.ag-bars {
  height: 200px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 6px;
  width: 100%;
}
.ag-bar {
  flex: 1;
  max-width: 18px;
  min-height: 14%;
  border-radius: 4px 4px 0 0;
  animation: ag-bounce 1.5s ease-in-out infinite alternate;
}

/* ── Mirror wave ── */
.ag-mirror {
  height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  width: 100%;
}
.ag-mirror-bar {
  flex: 1;
  max-width: 16px;
  border-radius: 999px;
  animation: ag-bounce 1.5s ease-in-out infinite alternate;
}

/* ── Radial / Circle ── */
.ag-circle-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
}

/* ── Minimal ── */
.ag-minimal {
  height: 120px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 3px;
  width: 100%;
}
.ag-minimal-bar {
  flex: 1;
  max-width: 8px;
  min-height: 8%;
  border-radius: 2px 2px 0 0;
  animation: ag-bounce 1.5s ease-in-out infinite alternate;
}

/* ── Animations ── */
@keyframes ag-bounce {
  0%   { transform: scaleY(0.55); }
  100% { transform: scaleY(1); }
}
@keyframes ag-rad-pulse {
  0%   { transform: scaleY(0.5); opacity: 0.6; }
  100% { transform: scaleY(1); opacity: 1; }
}

/* ── Panel: audiogram settings ── */
.ag-style-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 6px;
}
.ag-style-opt {
  background: var(--color-bg-elevated);
  border: 1.5px solid var(--color-border);
  border-radius: 8px;
  padding: 8px 6px 6px;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  transition: border-color 0.15s;
}
.ag-style-opt:hover { border-color: var(--color-border-active); }
.ag-style-opt.selected { border-color: var(--color-accent); }
.ag-style-mini {
  width: 100%;
  height: 44px;
  border-radius: 5px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 2px;
  overflow: hidden;
  padding: 4px 4px 3px;
}
.ag-mini-bar {
  flex: 1;
  border-radius: 2px 2px 0 0;
  opacity: 0.9;
}
.ag-mini-mirror {
  border-radius: 999px;
  align-self: center;
}
.ag-mini-minimal {
  flex: 1;
  max-width: 4px;
  border-radius: 1px 1px 0 0;
  opacity: 0.85;
}
.ag-style-label {
  font-size: 10px;
  color: var(--color-text-muted);
  font-weight: 500;
}

/* Color swatches */
.ag-colors {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.ag-color-swatch {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid transparent;
  cursor: pointer;
  transition: border-color 0.12s, transform 0.12s;
  padding: 0;
}
.ag-color-swatch:hover { transform: scale(1.15); }
.ag-color-swatch.selected { border-color: #fff; box-shadow: 0 0 0 1px rgba(255,255,255,.25); }
.ag-color-custom {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 1.5px dashed var(--color-border-active);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
.ag-color-custom input[type="color"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}
.ag-color-custom-icon {
  font-size: 13px;
  color: var(--color-text-muted);
  pointer-events: none;
  line-height: 1;
}

/* Background row */
.ag-bg-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 5px;
}
.ag-bg-opt {
  height: 32px;
  border-radius: 6px;
  border: 1.5px solid transparent;
  cursor: pointer;
  font-size: 9px;
  font-weight: 600;
  color: rgba(255,255,255,.7);
  letter-spacing: .04em;
  transition: border-color 0.12s;
}
.ag-bg-opt:hover { border-color: var(--color-border-active); }
.ag-bg-opt.selected { border-color: #fff; }

.preview-loading {
  position: absolute;
  inset: 0;
  z-index: 4;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(10, 10, 15, 0.46);
  color: rgba(255, 255, 255, 0.82);
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.02em;
}

.preview-loading.error {
  color: #fca5a5;
}

.preview-caption {
  position: absolute;
  /* % of the preview box height so the position holds across aspect ratios
     (fixed px landed wrong on 16:9/1:1/4:5). Mirrors the export's ~20% margin. */
  bottom: 20%;
  left: 16px;
  right: 16px;
  text-align: center;
  /* Above the foreground video (.preview-video-contain is z-index:1) —
     without this the captions render BEHIND the video on animated scenes. */
  z-index: 3;
}

.caption-word {
  /* Inherit the parent .preview-caption font-size so the live size control
     (captionFontStyle → captionSizeDraft) actually changes the preview. A
     hard-coded size here silently overrode it. */
  font-size: inherit;
  font-weight: 700;
  display: inline;
  line-height: 1.4;
}

.caption-word.highlight {
  color: var(--accent);
}

.caption-word.normal {
  color: #fff;
}

.caption-hidden {
  display: none !important;
}

/* Editorial style — italic, serif highlight */
.caption-style-editorial .caption-word {
  font-style: italic;
  font-weight: 400;
}
.caption-style-editorial .caption-word.highlight {
  color: #fff;
  font-style: italic;
  text-decoration: underline;
  text-underline-offset: 3px;
}
.caption-style-editorial .caption-word.normal {
  color: rgba(255, 255, 255, 0.75);
}

/* Hacker style — monospace, yellow highlight */
.caption-style-hacker .caption-word {
  /* Keep the size picker authoritative, as it is for every other style and
     in the export renderer. */
  font-size: inherit;
  font-weight: 400;
}
.caption-style-hacker .caption-word.highlight {
  color: var(--yellow);
}
.caption-style-hacker .caption-word.normal {
  color: rgba(255, 255, 255, 0.9);
}

.preview-watermark {
  position: absolute;
  top: 16px;
  left: 16px;
  z-index: 2;
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: rgba(255, 255, 255, 0.3);
}

.preview-timer {
  position: absolute;
  top: 16px;
  right: 16px;
  z-index: 2;
  font-family: "Space Mono", monospace;
  font-size: 11px;
  color: rgba(255, 255, 255, 0.5);
  background: rgba(0, 0, 0, 0.4);
  padding: 3px 8px;
  border-radius: 4px;
}

.playback-controls {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin-top: 20px;
}

.play-btn {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--accent);
  color: #fff;
  font-size: 18px;
  transition: 0.2s;
}

.play-btn:hover {
  transform: scale(1.05);
  box-shadow: 0 0 24px var(--accent-glow);
}
.play-btn-spinner {
  display: inline-block;
  width: 18px;
  height: 18px;
  border: 2px solid rgba(255, 255, 255, 0.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: play-btn-spin 0.7s linear infinite;
}
@keyframes play-btn-spin { to { transform: rotate(360deg); } }

.time-display {
  font-family: "Space Mono", monospace;
  font-size: 12px;
  color: var(--text-muted);
}

.editor-right {
  width: 300px;
  border-left: 1px solid var(--border);
  /* Flex column so the active view (Config or Assistant) can claim the
     remaining height after the toggle bar and handle its own scrolling.
     Was overflow-y:auto on the rail itself — that pushed the Assistant
     input + footer off the bottom when the chat grew tall. */
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}
.editor-right > .cruise-toggle-bar { flex: 0 0 auto; }
.editor-right > .cruise-config-view {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
}

.panel-section {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
}

.panel-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  cursor: pointer;
}

.panel-label {
  font-size: 11px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 10px;
  font-weight: 500;
}

.panel-label-tight {
  margin-bottom: 0;
}

.panel-label-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.panel-badge {
  padding: 2px 6px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 600;
}

.panel-badge.warn {
  background: var(--yellow-bg);
  color: var(--yellow);
}

.panel-badge.new {
  background: rgba(167, 139, 250, 0.15);
  color: #a78bfa;
}

/* ── Section error info icon + tooltip ── */
.section-error-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: rgba(251, 191, 36, 0.15);
  color: #fbbf24;
  font-size: 11px;
  cursor: default;
  position: relative;
  flex-shrink: 0;
  line-height: 1;
}

.section-error-icon::after {
  content: attr(data-tip);
  position: absolute;
  left: 50%;
  bottom: calc(100% + 8px);
  transform: translateX(-50%);
  background: #1e1e2a;
  border: 1px solid rgba(251, 191, 36, 0.3);
  color: #fbbf24;
  font-size: 12px;
  font-weight: 400;
  line-height: 1.5;
  padding: 8px 12px;
  border-radius: 8px;
  white-space: normal;
  width: 220px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.15s;
  z-index: 200;
}

.section-error-icon:hover::after {
  opacity: 1;
}

.panel-scope-hint {
  font-size: 9px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--color-text-muted);
  background: rgba(255,255,255,.04);
  border: 1px solid var(--color-border);
  border-radius: 999px;
  padding: 2px 7px;
}

.visual-type-tabs {
  display: flex;
  gap: 4px;
  background: var(--bg-deep);
  border-radius: 8px;
  padding: 3px;
}

.visual-type-tab {
  flex: 1;
  padding: 5px 8px;
  border-radius: 6px;
  border: none;
  background: transparent;
  color: var(--text-muted);
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
}

.visual-type-tab.active {
  background: var(--bg-card);
  color: var(--text-primary);
}

.visual-type-tab.active.ai {
  background: rgba(167, 139, 250, 0.15);
  color: #a78bfa;
}

.ai-style-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
}

.ai-style-btn {
  padding: 5px 4px;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: var(--bg-deep);
  color: var(--text-muted);
  font-size: 10px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.15s;
  text-align: center;
}

.ai-style-btn:hover {
  border-color: var(--border-active);
  color: var(--text-primary);
}

.ai-style-btn.active {
  border-color: #a78bfa;
  background: rgba(167, 139, 250, 0.12);
  color: #a78bfa;
}

.panel-error-copy {
  margin-top: 6px;
  font-size: 11px;
  color: var(--red);
}

.panel-hint-copy {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
  font-style: italic;
}

.panel-chevron {
  color: var(--text-muted);
  font-size: 14px;
  transition: transform 0.2s ease;
}

.panel-section.collapsed .panel-chevron {
  transform: rotate(-90deg);
}

.panel-section-body {
  margin-top: 12px;
}

.panel-section.collapsed .panel-section-body {
  display: none;
}

.helper-copy,
.rewrite-note {
  font-size: 11px;
  color: var(--text-muted);
}

.script-save-copy {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
}

.script-save-copy.error {
  color: #fca5a5;
}

.rewrite-error {
  margin-top: 8px;
  color: #fca5a5;
  font-size: 12px;
}

.helper-copy {
  margin-top: 6px;
}

.duration-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  flex-wrap: wrap;
}

.duration-label {
  font-size: 11px;
  color: var(--text-muted, #888);
  white-space: nowrap;
}

.duration-input {
  width: 72px;
  padding: 4px 6px;
  border: 1px solid var(--border, #333);
  border-radius: 4px;
  background: var(--input-bg, #1a1a1a);
  color: var(--text, #eee);
  font-size: 12px;
}

.duration-saving {
  font-size: 11px;
  color: var(--text-muted, #888);
}

.duration-hint {
  font-size: 11px;
  color: var(--text-muted, #888);
  opacity: 0.6;
  flex-basis: 100%;
}

/* Caption / Voice presets */
.preset-row {
  margin-bottom: 8px;
}

.preset-select {
  width: 100%;
  padding: 5px 8px;
  border: 1px solid var(--border, #333);
  border-radius: 5px;
  background: var(--input-bg, #1a1a1a);
  color: var(--text, #eee);
  font-size: 12px;
}

.preset-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-bottom: 8px;
}

.preset-chip {
  display: flex;
  align-items: center;
  gap: 3px;
  padding: 3px 7px;
  border-radius: 12px;
  background: var(--bg-elevated, #222);
  border: 1px solid var(--border, #333);
  font-size: 11px;
}

.preset-chip-name {
  cursor: pointer;
  color: var(--text, #eee);
}

.preset-chip-name:hover {
  color: var(--accent, #ff6b35);
}

.preset-chip-del {
  background: none;
  border: none;
  color: var(--text-muted, #888);
  cursor: pointer;
  font-size: 10px;
  padding: 0 1px;
  line-height: 1;
}

.preset-chip-del:hover {
  color: var(--danger, #e05);
}

.preset-save-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.preset-name-input {
  flex: 1;
  min-width: 100px;
  padding: 4px 8px;
  border: 1px solid var(--border, #333);
  border-radius: 4px;
  background: var(--input-bg, #1a1a1a);
  color: var(--text, #eee);
  font-size: 12px;
}

.panel-inline-actions {
  display: flex;
  gap: 8px;
  margin-top: 10px;
  flex-wrap: wrap;
}

.rewrite-tools {
  margin-top: 10px;
}

.rewrite-custom {
  display: flex;
  gap: 6px;
  margin-top: 8px;
}

.rewrite-custom-input {
  flex: 1;
  padding: 5px 8px;
  border-radius: var(--radius-sm);
  font-size: 12px;
}

.rewrite-preview {
  margin-top: 10px;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid rgba(167, 139, 250, 0.35);
  background: rgba(167, 139, 250, 0.08);
}

.rewrite-preview-title {
  font-size: 11px;
  color: var(--purple);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 6px;
}

.rewrite-preview-copy {
  font-size: 12px;
  line-height: 1.5;
  color: var(--text-primary);
}

.rewrite-preview-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}

.control-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  gap: 10px;
}

.control-row:last-child {
  margin-bottom: 0;
}

.top-space {
  margin-top: 10px;
}

.control-name {
  font-size: 13px;
  color: var(--text-secondary);
}

.apply-all-row { margin: 10px 0 4px; }
.apply-all-btn { width: 100%; padding: 8px 10px; border-radius: 7px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-secondary); font-family: inherit; font-size: 12px; cursor: pointer; transition: .15s; }
.apply-all-btn:hover:not(:disabled) { border-color: var(--color-accent); color: var(--color-text-primary); }
.apply-all-btn:disabled { opacity: .6; cursor: default; }
.apply-all-note { margin-top: 5px; font-size: 11px; color: var(--color-text-muted); }
.apply-all-note.error { color: #f87171; }
.apply-all-note.muted { opacity: .75; }

.voice-direction-block {
  margin-top: 10px;
}
.voice-direction-label {
  display: block;
  font-size: 13px;
  color: var(--text-secondary);
  margin-bottom: 6px;
}
.vd-opt {
  font-size: 11px;
  color: var(--text-tertiary, #6b7280);
}
.voice-direction-input {
  width: 100%;
  box-sizing: border-box;
  resize: vertical;
  background: var(--bg-card);
  border: 1px solid var(--border-color, rgba(255, 255, 255, 0.12));
  border-radius: 8px;
  color: var(--text-primary, #fff);
  font-size: 13px;
  line-height: 1.4;
  padding: 8px 10px;
}
.voice-direction-input:focus {
  outline: none;
  border-color: rgba(255, 107, 53, 0.5);
}
.vd-hint {
  margin-top: 6px;
  font-size: 11px;
  line-height: 1.5;
  color: var(--text-tertiary, #6b7280);
}
.vd-hint code {
  background: rgba(255, 255, 255, 0.06);
  border-radius: 4px;
  padding: 1px 5px;
  font-size: 10.5px;
}

/* Voice select button (replaces the old dropdown) */
.voice-select-btn {
  display: flex; align-items: center; gap: 8px; justify-content: space-between;
  min-width: 150px; padding: 7px 11px; border-radius: 8px;
  border: 1px solid var(--border-color, rgba(255,255,255,0.14));
  background: var(--bg-card, #14141c); color: var(--text-primary, #fff);
  font: inherit; font-size: 13px; cursor: pointer;
}
.voice-select-btn:hover { border-color: rgba(255,107,53,0.5); }
.vsb-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vsb-change { font-size: 11px; color: var(--text-secondary, #9a9aab); flex-shrink: 0; }

/* Voice picker modal */
.vp-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.62); display: flex; align-items: center; justify-content: center; z-index: 1100; padding: 20px; }
.vp-modal { width: 100%; max-width: 520px; max-height: 82vh; display: flex; flex-direction: column; background: #14141c; border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; overflow: hidden; }
.vp-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid rgba(255,255,255,0.08); }
.vp-title { font-size: 16px; font-weight: 700; color: #fff; }
.vp-close { background: transparent; border: none; color: #8a8a9a; font-size: 22px; line-height: 1; cursor: pointer; }
.vp-scroll { overflow-y: auto; padding: 14px 18px; }
.vp-section-label { font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #7a7a8a; margin: 14px 0 8px; }
.vp-section-label:first-child { margin-top: 0; }
.vp-persona-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.vp-persona { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 10px 6px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.03); cursor: pointer; color: #e8e8ee; font: inherit; }
.vp-persona:hover { border-color: rgba(255,107,53,0.5); background: rgba(255,107,53,0.06); }
.vp-persona-emoji { font-size: 20px; }
.vp-persona-label { font-size: 12px; font-weight: 600; }
.vp-persona-desc { font-size: 10px; color: #8a8a9a; text-align: center; }
.vp-voice { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 10px; border: 1px solid transparent; }
.vp-voice:hover { background: rgba(255,255,255,0.04); }
.vp-voice.active { border-color: rgba(255,107,53,0.45); background: rgba(255,107,53,0.07); }
.vp-preview { width: 32px; height: 32px; flex-shrink: 0; border-radius: 50%; border: 1px solid rgba(255,255,255,0.16); background: rgba(255,255,255,0.05); color: #e8e8ee; cursor: pointer; font-size: 12px; }
.vp-preview:hover { border-color: rgba(255,107,53,0.5); color: #ff8055; }
.vp-voice-info { flex: 1; min-width: 0; cursor: pointer; }
.vp-voice-name { font-size: 13.5px; font-weight: 600; color: #fff; }
.vp-cloned-tag { font-size: 9.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; background: rgba(255,107,53,0.18); color: #ff8055; border-radius: 4px; padding: 1px 5px; margin-left: 4px; }
.vp-voice-desc { font-size: 11.5px; color: #8a8a9a; margin-top: 1px; }
.vp-pick { flex-shrink: 0; padding: 5px 12px; border-radius: 7px; border: 1px solid rgba(255,255,255,0.16); background: rgba(255,255,255,0.05); color: #e8e8ee; font: inherit; font-size: 12px; cursor: pointer; }
.vp-pick:hover { background: rgba(255,107,53,0.15); border-color: rgba(255,107,53,0.5); }
.vp-clone-cta { display: block; width: 100%; text-align: left; padding: 11px 12px; border-radius: 10px; border: 1px dashed rgba(255,107,53,0.4); background: rgba(255,107,53,0.05); color: #ff8055; font: inherit; font-size: 12.5px; cursor: pointer; }
.vp-clone-cta:hover { background: rgba(255,107,53,0.1); border-color: rgba(255,107,53,0.6); }
.vp-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 18px; border-top: 1px solid rgba(255,255,255,0.08); }
.vp-foot-hint { font-size: 10.5px; color: #6b6b7a; line-height: 1.4; }

select.control-value {
  appearance: none;
  cursor: pointer;
  padding-right: 24px;
  background-color: var(--bg-card);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238b8b9e'%3E%3Cpath d='M2 4l4 4 4-4'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 8px center;
}

/* Unify the remaining editor selects so none render with the native OS
   control. ap-input / preset-select set `background:` (shorthand, which
   wipes background-image) — `select.<class>` outranks the plain class so the
   custom chevron sticks while their own background-color stays. The open
   option list is still browser-native (a <select> limitation shared by all
   the styled selects above). */
select.ap-input,
select.preset-select {
  appearance: none;
  -webkit-appearance: none;
  -moz-appearance: none;
  cursor: pointer;
  padding-right: 26px;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238b8b9e'%3E%3Cpath d='M2 4l4 4 4-4'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 9px center;
}

.query-input {
  width: 100%;
  box-sizing: border-box;
}

.panel-full-btn {
  margin-top: 8px;
  width: 100%;
  justify-content: center;
}

.btn-full {
  width: 100%;
  justify-content: center;
  margin-top: 8px;
}

.micro-copy,
.micro-error {
  margin-top: 8px;
  font-size: 11px;
  line-height: 1.45;
}

.micro-copy {
  color: var(--text-muted);
}

.micro-error {
  color: var(--red);
}

.voice-preview {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 10px;
}

.voice-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--blue), var(--purple));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.voice-info {
  flex: 1;
}

.voice-name {
  font-size: 13px;
  font-weight: 500;
}

.voice-desc {
  font-size: 11px;
  color: var(--text-muted);
}

.voice-play {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  color: var(--text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  cursor: pointer;
  transition: 0.15s;
  flex-shrink: 0;
}

.voice-play:hover {
  background: var(--bg-card);
  color: var(--text-primary);
}

.voice-play.disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.voice-warning-row {
  margin-top: 10px;
  padding: 8px 10px;
  background: var(--yellow-bg);
  border: 1px solid rgba(251, 191, 36, 0.2);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.voice-warning-copy {
  font-size: 11px;
  color: var(--yellow);
}

.voice-loading-copy {
  margin-top: 10px;
  color: var(--text-secondary);
  font-size: 12px;
}

.regen-btn {
  background: var(--yellow);
  color: #000;
  font-weight: 600;
  padding: 4px 10px;
  font-size: 11px;
  border-radius: 6px;
  cursor: pointer;
}

.regen-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.caption-toggle-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.caption-toggle {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  color: var(--text-muted);
  cursor: pointer;
}

.caption-toggle input {
  accent-color: var(--accent);
}

.caption-style-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
}

.caption-style-opt {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  padding: 10px;
  text-align: center;
  cursor: pointer;
  transition: 0.2s;
}

.caption-style-opt.active {
  border-color: var(--accent);
  background: var(--accent-glow);
}

.preview-text {
  font-size: 11px;
  font-weight: 700;
  line-height: 1.3;
  margin-bottom: 4px;
}

.accent-text {
  color: var(--accent);
}

.serif-text {
  color: #fff;
  font-style: italic;
}

.mono-text {
  color: var(--yellow);
  font-family: "Space Mono", monospace;
}

.style-name {
  font-size: 9px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

/* --- Caption animation presets --- */
.caption-anim-row {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 6px;
  scrollbar-width: thin;
}

.caption-anim-tile {
  position: relative;
  flex: 0 0 72px;
  width: 72px;
  height: 64px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  overflow: hidden;
  transition: 0.2s;
  color: var(--text);
  font-family: inherit;
}

.caption-anim-tile:hover {
  border-color: var(--text-muted);
}

.caption-anim-tile.active {
  border-color: var(--accent);
  background: var(--accent-glow);
}

.caption-anim-sample {
  font-size: 9px;
  height: 30px;
  display: flex;
  align-items: center;
  justify-content: center;
  max-width: 100%;
  overflow: hidden;
  pointer-events: none;
  white-space: nowrap;
}

.caption-anim-name {
  font-size: 9px;
  color: var(--text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.caption-anim-more-icon {
  font-size: 18px;
  color: var(--accent);
}

.caption-anim-badge {
  position: absolute;
  top: 4px;
  right: 4px;
  font-size: 8px;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 8px;
  background: var(--accent);
  color: #fff;
  text-transform: uppercase;
}

.caption-effect-modal {
  width: min(680px, 92vw);
  max-height: 84vh;
  overflow-y: auto;
}

.caption-effect-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
  gap: 10px;
  margin: 14px 0;
}

.caption-anim-tile-lg {
  flex: none;
  width: 100%;
  height: 92px;
}

.caption-anim-tile-lg .caption-anim-sample {
  font-size: 12px;
  height: 52px;
}

.color-swatch.swatch-auto {
  background: linear-gradient(135deg, #333 50%, #fff 50%);
  border-color: #555;
}

.preview-caption-animated {
  display: flex;
  justify-content: center;
  text-align: center;
}

.hooks-block {
  margin-top: 12px;
}

.hooks-title {
  margin-bottom: 6px;
}

.hook-card {
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg-card);
  font-size: 12px;
  line-height: 1.5;
  color: var(--text-secondary);
  margin-top: 8px;
}

.hook-card-top {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}

.hook-score-badge {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 20px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  padding: 0 4px;
}

.score-green  { background: #14532d; color: #4ade80; }
.score-yellow { background: #713f12; color: #fbbf24; }
.score-red    { background: #450a0a; color: #f87171; }
.score-none   { background: var(--bg-deep); color: var(--text-muted); }

.hook-card-text {
  flex: 1;
  line-height: 1.5;
}

.hook-card-reason {
  margin-top: 6px;
  font-size: 11px;
  color: var(--text-muted);
  font-style: italic;
  line-height: 1.4;
}

.hook-use-btn {
  margin-top: 8px;
  width: 100%;
}

/* Hint box */
.hint-box {
  font-size: 11px;
  color: var(--text-muted);
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 8px 10px;
  margin-bottom: 10px;
  line-height: 1.5;
}

/* Asset current preview (My Assets tab) */
.asset-current-preview {
  margin-top: 10px;
  margin-bottom: 10px;
}
.asset-current-thumb {
  width: 100%;
  height: 160px;
  border-radius: 8px;
  overflow: hidden;
  background: var(--color-bg-elevated);
  position: relative;
  margin-bottom: 8px;
}
.asset-current-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.asset-current-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--color-bg-card);
}
.asset-current-badge {
  position: absolute;
  top: 6px;
  left: 6px;
  background: rgba(0,0,0,.7);
  border-radius: 4px;
  padding: 2px 6px;
  font-size: 9px;
  font-weight: 700;
  font-family: var(--font-mono, monospace);
  color: var(--color-text-muted);
}
.asset-current-title {
  font-size: 12px;
  font-weight: 600;
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.asset-current-type {
  font-size: 11px;
  color: var(--color-text-muted);
  margin-top: 2px;
}

/* Asset empty state */
.asset-empty-state {
  text-align: center;
  padding: 20px 8px 14px;
}
.asset-empty-icon { font-size: 24px; margin-bottom: 8px; }
.asset-empty-title { font-size: 13px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
.asset-empty-sub { font-size: 11px; color: var(--color-text-muted); line-height: 1.5; }

/* Sounds panel */
.sound-current-row {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 10px 10px;
  margin-top: 6px;
  margin-bottom: 8px;
}
.sound-current-icon { font-size: 16px; flex-shrink: 0; }
.sound-current-info { flex: 1; min-width: 0; }
.sound-current-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sound-current-meta { font-size: 11px; color: var(--color-text-muted); margin-top: 1px; }
.sound-remove-btn {
  width: 22px; height: 22px; border-radius: 50%; border: none;
  background: rgba(255,255,255,.06); color: var(--color-text-muted);
  cursor: pointer; font-size: 11px; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; transition: .15s;
}
.sound-remove-btn:hover { background: rgba(255,60,60,.15); color: #ef4444; }

/* Music panel tabs */
.music-panel-tabs {
  display: flex;
  background: var(--color-bg-deep, var(--bg-deep));
  border-radius: 8px;
  padding: 3px;
  gap: 2px;
  margin-bottom: 10px;
}
.music-panel-tab {
  flex: 1;
  padding: 6px 8px;
  border-radius: 5px;
  font-family: inherit;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  color: var(--color-text-muted, var(--text-muted));
  background: transparent;
  border: none;
  transition: .15s;
}
.music-panel-tab.active {
  background: var(--color-bg-panel, var(--bg-panel));
  color: var(--color-text-primary, var(--text-primary));
}
.music-panel-tab:hover:not(.active) {
  color: var(--color-text-primary, var(--text-primary));
}

/* Mood filter chips */
.filter-chip {
  padding: 6px 12px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-muted);
  cursor: pointer;
  font-size: 12px;
  transition: 0.15s;
}

.filter-chip:hover {
  border-color: rgba(255,255,255,0.2);
}

.filter-chip.active {
  background: rgba(255,107,53,0.12);
  border-color: rgba(255,107,53,0.35);
  color: var(--accent);
}

/* Music panel */
.music-selected-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 9px 10px;
  margin-bottom: 10px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid rgba(255,107,53,0.35);
  background: rgba(255,107,53,0.08);
  color: var(--text-muted);
  font-size: 10px;
}

.music-selected-copy {
  min-width: 0;
}

.music-selected-label {
  display: block;
  margin-bottom: 2px;
  color: var(--text-muted);
  font-family: "Space Mono", monospace;
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.music-selected-copy strong {
  display: block;
  color: var(--text-primary);
  font-size: 12px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ai-music-moods { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 4px; }
.ai-music-mood-chip {
  font-size: 11.5px;
  padding: 5px 10px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--border);
  color: var(--text-secondary);
  cursor: pointer;
  transition: 0.15s;
  font-family: inherit;
}
.ai-music-mood-chip:hover { color: var(--text-primary); border-color: var(--border-active); }
.ai-music-mood-chip.selected {
  background: rgba(255,107,53,0.12);
  border-color: rgba(255,107,53,0.55);
  color: var(--accent);
}

.music-filter-row {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.music-filter-row .filter-chip {
  padding: 4px 9px;
  font-size: 11px;
}

.music-track-scroll {
  max-height: 330px;
  overflow-y: auto;
  display: grid;
  gap: 10px;
  padding-right: 4px;
  margin-bottom: 14px;
}

.music-track-scroll::-webkit-scrollbar {
  width: 6px;
}

.music-track-scroll::-webkit-scrollbar-track {
  background: transparent;
}

.music-track-scroll::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,0.18);
  border-radius: 999px;
}

.music-category {
  display: grid;
  gap: 6px;
}

.music-category-title {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--text-muted);
  font-family: "Space Mono", monospace;
  font-size: 10px;
  text-transform: uppercase;
}

.music-track-list {
  display: grid;
  gap: 6px;
}

.music-track {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid var(--border);
  background: var(--bg-card);
  cursor: pointer;
  transition: 0.15s;
}

.music-track:hover {
  border-color: rgba(255,255,255,0.2);
}

.music-track.selected {
  border-color: var(--accent);
  background: rgba(255,107,53,0.08);
}

.music-track-thumb {
  width: 36px;
  height: 36px;
  border-radius: 6px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.music-track-info {
  flex: 1;
  min-width: 0;
}

.music-track-name {
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--text-primary);
}

.music-track-meta {
  font-size: 10px;
  color: var(--text-muted);
  margin-top: 1px;
}

.music-track-duration {
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: var(--text-muted);
  flex-shrink: 0;
}

.music-play-btn {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: var(--bg-elevated);
  color: var(--text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  flex-shrink: 0;
  cursor: pointer;
}

.music-track.selected .music-play-btn {
  border-color: var(--accent);
  color: var(--accent);
}

.music-controls {
  padding: 12px;
  border-radius: var(--radius-sm, 6px);
  background: var(--bg-elevated);
  border: 1px solid var(--border);
}

.music-control-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.music-control-row:last-child {
  margin-bottom: 0;
}

.music-control-label {
  font-size: 11px;
  color: var(--text-muted);
  width: 70px;
  flex-shrink: 0;
}

.volume-slider-wrap { display: flex; align-items: center; gap: 8px; flex: 1; }
.volume-slider-wrap .music-slider { flex: 1; }
.music-slider {
  flex: 1;
  appearance: none;
  height: 4px;
  border-radius: 999px;
  background: var(--border);
  outline: none;
  cursor: pointer;
}

.music-slider::-webkit-slider-thumb {
  appearance: none;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: var(--accent);
  cursor: pointer;
}

.music-slider-val {
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: var(--text-muted);
  width: 28px;
  text-align: right;
}

/* Canvas music wave indicator */
.preview-music-indicator {
  position: absolute;
  bottom: 16px;
  left: 16px;
  right: 16px;
  z-index: 2;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 10px;
  border-radius: 6px;
  background: rgba(0,0,0,0.5);
  backdrop-filter: blur(4px);
  pointer-events: none;
}

.preview-music-waves {
  display: flex;
  align-items: center;
  gap: 2px;
}

.music-wave {
  width: 2px;
  border-radius: 999px;
  background: var(--accent);
  animation: wave 0.6s ease-in-out infinite alternate;
  animation-play-state: paused;
}

.music-wave.playing {
  animation-play-state: running;
}

.music-wave:nth-child(2) { animation-delay: 0.1s; }
.music-wave:nth-child(3) { animation-delay: 0.2s; }
.music-wave:nth-child(4) { animation-delay: 0.3s; }

@keyframes wave {
  from { height: 4px; opacity: 0.5; }
  to   { height: 12px; opacity: 1; }
}

.preview-music-name {
  font-size: 10px;
  color: rgba(255,255,255,0.7);
  font-family: "Space Mono", monospace;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Playback controls */
.preview-mode-toggle {
  display: flex;
  gap: 3px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 999px;
  padding: 3px;
}

.preview-mode-btn {
  padding: 4px 14px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 500;
  border: none;
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  transition: all 0.2s;
}

.preview-mode-btn.active {
  background: var(--bg-elevated);
  color: var(--text-primary);
}

.preview-scrubber {
  width: 270px;
  position: relative;
  padding: 6px 0;
  cursor: pointer;
}

.scrubber-track {
  height: 3px;
  border-radius: 999px;
  background: var(--border);
  position: relative;
}

.scrubber-fill {
  height: 100%;
  border-radius: 999px;
  background: var(--accent);
  width: 0%;
  pointer-events: none;
}

.scrubber-thumb {
  position: absolute;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 11px;
  height: 11px;
  border-radius: 50%;
  background: var(--accent);
  left: 0%;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.15s;
}

.preview-scrubber:hover .scrubber-thumb {
  opacity: 1;
}

.playback-btns {
  display: flex;
  align-items: center;
  gap: 14px;
}

.play-skip-btn {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s;
  flex-shrink: 0;
}

.play-skip-btn:hover:not(:disabled) {
  border-color: var(--border-active, rgba(255,255,255,0.2));
  color: var(--text-primary);
}

.play-skip-btn:disabled {
  opacity: 0.3;
  cursor: default;
}

.play-time-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.play-scene-label {
  font-size: 10px;
  color: var(--text-muted);
  margin-left: 6px;
}

/* Visual Source panel — style picker grid */
.style-picker-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 6px;
  margin-bottom: 12px;
}

.style-opt {
  padding: 7px 4px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid var(--border);
  background: var(--bg-card);
  text-align: center;
  cursor: pointer;
  transition: 0.15s;
}

.style-opt:hover {
  border-color: rgba(167,139,250,.4);
}

.style-opt.selected {
  border-color: #a78bfa;
  background: rgba(167,139,250,.1);
}

.style-opt-ico {
  font-size: 14px;
  display: block;
  margin-bottom: 2px;
}

.style-opt-name {
  font-size: 9px;
  color: var(--text-muted);
}

.style-opt.selected .style-opt-name {
  color: #a78bfa;
}

.style-opt.accent {
  border-color: rgba(255,107,53,.35);
  background: rgba(255,107,53,.04);
}
.style-opt.accent:hover {
  border-color: rgba(255,107,53,.5);
}
.style-opt.accent.selected {
  border-color: #ff6b35;
  background: rgba(255,107,53,.12);
}
.style-opt.accent.selected .style-opt-name {
  color: #ff6b35;
}

.custom-style-panel {
  padding: 10px 12px;
  border-radius: var(--radius-sm, 6px);
  border: 1px solid rgba(255,107,53,.2);
  background: rgba(255,107,53,.04);
  margin: 8px 0 12px;
}

/* Picked-asset preview card inside the add-scene Assets tab. */
.add-scene-asset-preview {
  display: flex; align-items: center; gap: 10px;
  padding: 10px; border-radius: 8px;
  border: 1px solid rgba(255,107,53,.25);
  background: rgba(255,107,53,.04);
  margin-top: 8px;
}
.add-scene-asset-preview img {
  width: 56px; height: 56px; object-fit: cover; border-radius: 6px; flex-shrink: 0;
}
.add-scene-asset-meta { flex: 1; min-width: 0; }
.add-scene-asset-title {
  font-size: 12px; font-weight: 500; color: var(--text);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.add-scene-asset-type {
  font-size: 10px; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .05em;
  font-family: "Space Mono", monospace; margin-top: 2px;
}

/* Asset image browser */
.asset-search-input {
  width: 100%; box-sizing: border-box; height: 32px; border-radius: 6px;
  border: 1px solid var(--color-border); background: var(--color-bg-elevated);
  color: var(--color-text-primary); padding: 0 8px; font-size: 12px;
}
.asset-search-input:focus { outline: none; border-color: var(--color-accent); }
.asset-loading { font-size: 12px; color: var(--color-text-muted); padding: 12px 0; text-align: center; }
.asset-empty { font-size: 12px; color: var(--color-text-muted); padding: 20px 0; text-align: center; }
.asset-image-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 5px; margin-top: 8px; max-height: 240px; overflow-y: auto;
}
.asset-thumb {
  position: relative; aspect-ratio: 1; border-radius: 5px; overflow: hidden;
  border: 2px solid transparent; cursor: pointer; transition: 0.12s;
}
.asset-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.asset-thumb:hover { border-color: rgba(167,139,250,.5); }
.asset-thumb.active { border-color: #a78bfa; }
.asset-thumb-badge {
  position: absolute; top: 3px; right: 3px; width: 16px; height: 16px;
  border-radius: 50%; background: #a78bfa; color: #fff; font-size: 9px;
  display: flex; align-items: center; justify-content: center; font-weight: 700;
}

/* Custom audio list */
/* Approval link modal */
.ap-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.6); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 300; }
.ap-modal { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 22px; width: min(460px, calc(100vw - 32px)); max-height: 90vh; overflow-y: auto; }
.ap-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.ap-title { font-size: 15px; font-weight: 600; }
.ap-close { background: none; border: none; color: var(--color-text-muted); font-size: 22px; cursor: pointer; line-height: 1; padding: 0; }
.ap-meta { font-size: 12px; color: var(--color-text-muted); line-height: 1.5; margin-bottom: 14px; }
.ap-field { margin-bottom: 12px; }
.ap-label { display: block; font-size: 11px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 5px; font-weight: 500; }
.ap-input { width: 100%; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 7px; color: var(--color-text-primary); padding: 9px 11px; font-size: 13px; font-family: inherit; outline: none; }
.ap-input:focus { border-color: rgba(255,107,53,.5); }
.ap-error { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.2); color: #fca5a5; border-radius: 7px; padding: 8px 11px; font-size: 12px; margin-bottom: 12px; }
.bulk-voice-body { margin-top: 8px; padding: 10px 12px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 8px; }
.bulk-voice-row { display: flex; justify-content: space-between; align-items: baseline; font-size: 12.5px; color: var(--text-primary); }
.bulk-voice-row.sub { margin-top: 3px; font-size: 11.5px; color: var(--text-secondary); }
.bulk-voice-row strong.short { color: #f87171; }
.bulk-voice-muted { font-size: 11px; color: var(--text-secondary); line-height: 1.5; margin-top: 6px; }
.bulk-voice-short { margin-top: 8px; font-size: 11.5px; color: #f87171; line-height: 1.45; }
.bulk-voice-skips { margin: 8px 0 0; padding-left: 16px; font-size: 11px; color: var(--text-secondary); line-height: 1.6; }
.bulk-style { margin-top: 10px; }
.bulk-style-cta { width: 100%; padding: 7px 10px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 7px; color: var(--text-secondary); font-family: inherit; font-size: 11.5px; cursor: pointer; transition: .15s; }
.bulk-style-cta:hover { color: var(--text-primary); border-color: var(--border-active); }
.bulk-style-body { margin-top: 8px; padding: 10px 12px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 8px; }
.bulk-style-row { display: flex; justify-content: space-between; align-items: baseline; font-size: 12.5px; color: var(--text-primary); }
.bulk-style-row.sub { margin-top: 3px; font-size: 11.5px; color: var(--text-secondary); }
.bulk-style-row strong.short { color: #f87171; }
.bulk-style-muted { font-size: 11px; color: var(--text-secondary); line-height: 1.5; margin-top: 4px; }
.bulk-style-short { margin-top: 8px; font-size: 11.5px; color: #f87171; line-height: 1.45; }
.bulk-style-skips { margin: 8px 0 0; padding-left: 16px; font-size: 11px; color: var(--text-secondary); line-height: 1.6; }
.state-card.gen-failed { max-width: 560px; margin: 80px auto; padding: 36px 40px; text-align: center; display: flex; flex-direction: column; gap: 12px; align-items: center; }
.gf-icon { font-size: 34px; }
.gf-title { font-size: 20px; font-weight: 700; margin: 0; }
.gf-reason { font-size: 13.5px; line-height: 1.55; color: var(--text-secondary); margin: 0; }
.gf-note { font-size: 12px; color: var(--text-secondary); opacity: .75; margin: 0; }
.gf-source { width: 100%; text-align: left; margin-top: 6px; }
.gf-label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; }
.gf-input { width: 100%; padding: 10px 12px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); font-family: inherit; font-size: 13px; resize: vertical; }
.gf-hint { margin-top: 6px; font-size: 11.5px; color: var(--text-secondary); line-height: 1.45; }
.gf-error { font-size: 12.5px; color: #f87171; }
.gf-actions { display: flex; gap: 10px; margin-top: 8px; }
.bulk-anim { border-top: 1px solid var(--border); margin-top: 12px; padding-top: 10px; }
.bulk-anim-toggle { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); background: var(--bg-soft); color: var(--text-secondary); font-family: inherit; cursor: pointer; transition: .15s; text-align: left; }
.bulk-anim-toggle:hover { border-color: var(--border-active); color: var(--text-primary); }
.bulk-anim-toggle.on { border-color: var(--accent, #ff6b35); background: rgba(255,107,53,.07); color: var(--text-primary); }
.bulk-anim-check { width: 18px; height: 18px; flex-shrink: 0; border: 1.5px solid rgba(255,255,255,.6); background: rgba(255,255,255,.08); border-radius: 5px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; line-height: 1; }
.bulk-anim-check.on { background: var(--accent, #ff6b35); border-color: var(--accent, #ff6b35); color: #fff; }
.bulk-anim-toggle-text { display: flex; flex-direction: column; gap: 2px; }
.bulk-anim-toggle-title { font-size: 12.5px; font-weight: 600; }
.bulk-anim-toggle-sub { font-size: 11px; color: var(--text-secondary); }
.bulk-anim-body { margin-top: 10px; padding: 12px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 8px; }
.bulk-anim-loading, .bulk-anim-none { font-size: 12px; color: var(--text-secondary); }
.bulk-anim-scope { font-size: 11px; color: var(--text-secondary); line-height: 1.5; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
.bulk-anim-list { display: flex; flex-direction: column; gap: 2px; max-height: 210px; overflow-y: auto; margin-bottom: 10px; }
.bulk-anim-scene { display: flex; align-items: center; gap: 9px; width: 100%; padding: 6px 7px; border-radius: 6px; border: none; background: transparent; color: var(--text-primary); font-family: inherit; font-size: 12px; cursor: pointer; text-align: left; transition: .12s; }
.bulk-anim-scene:hover:not(:disabled) { background: var(--bg-elevated); }
.bulk-anim-scene.off { color: var(--text-secondary); }
.bulk-anim-scene.blocked { cursor: default; opacity: .6; }
.bulk-anim-box { width: 16px; height: 16px; flex-shrink: 0; border: 1.5px solid rgba(255,255,255,.6); background: rgba(255,255,255,.08); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; line-height: 1; }
.bulk-anim-box.on { background: var(--accent, #ff6b35); border-color: var(--accent, #ff6b35); color: #fff; }
.bulk-anim-box.blocked { border-style: dashed; border-color: var(--border-active); background: transparent; color: var(--text-secondary); }
.bulk-anim-row strong.repricing { color: var(--text-secondary); font-weight: 500; }
.bulk-anim-scene-label { flex: 1; }
.bulk-anim-scene-cost { font-size: 11px; color: var(--text-secondary); font-variant-numeric: tabular-nums; }
.bulk-anim-source strong { color: var(--text-primary); font-size: 11.5px; }
.bulk-anim-source { display: flex; flex-direction: column; gap: 5px; margin-bottom: 10px; padding: 9px 10px; background: var(--bg-elevated); border: 1px dashed var(--border-active); border-radius: 7px; font-size: 11px; color: var(--text-secondary); line-height: 1.45; }
.bulk-anim-scene-replace { font-size: 10.5px; color: #fbbf24; text-align: right; line-height: 1.35; }
.bulk-anim-scene-share { font-size: 10.5px; color: #34d399; text-align: right; line-height: 1.35; }
.bulk-anim-saving { margin-top: 6px; padding: 7px 9px; background: rgba(52,211,153,.1); border-radius: 6px; font-size: 11px; color: #34d399; line-height: 1.45; }
.bulk-anim-scene-why { font-size: 10.5px; color: var(--text-secondary); text-align: right; max-width: 62%; line-height: 1.35; }
.bulk-anim-selectall { width: 100%; padding: 6px; margin-bottom: 10px; background: none; border: 1px dashed var(--border-active); border-radius: 6px; color: var(--text-secondary); font-family: inherit; font-size: 11px; cursor: pointer; }
.bulk-anim-selectall:hover { color: var(--text-primary); }
.bulk-anim-row { display: flex; justify-content: space-between; align-items: baseline; font-size: 12.5px; color: var(--text-primary); }
.bulk-anim-row.sub { margin-top: 3px; font-size: 11.5px; color: var(--text-secondary); }
.bulk-anim-row strong.short { color: #f87171; }
.bulk-anim-short { margin-top: 8px; font-size: 11.5px; color: #f87171; line-height: 1.45; }
.bulk-style { margin-top: 10px; }
.bulk-style-cta { width: 100%; padding: 7px 10px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 7px; color: var(--text-secondary); font-family: inherit; font-size: 11.5px; cursor: pointer; transition: .15s; }
.bulk-style-cta:hover { color: var(--text-primary); border-color: var(--border-active); }
.bulk-style-body { margin-top: 8px; padding: 10px 12px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 8px; }
.bulk-style-row { display: flex; justify-content: space-between; align-items: baseline; font-size: 12.5px; color: var(--text-primary); }
.bulk-style-row.sub { margin-top: 3px; font-size: 11.5px; color: var(--text-secondary); }
.bulk-style-row strong.short { color: #f87171; }
.bulk-style-muted { font-size: 11px; color: var(--text-secondary); line-height: 1.5; margin-top: 4px; }
.bulk-style-short { margin-top: 8px; font-size: 11.5px; color: #f87171; line-height: 1.45; }
.bulk-style-skips { margin: 8px 0 0; padding-left: 16px; font-size: 11px; color: var(--text-secondary); line-height: 1.6; }
.bulk-anim { border-top: 1px solid var(--border); margin-top: 12px; padding-top: 10px; }
.bulk-anim-toggle { display: flex; align-items: center; gap: 8px; width: 100%; padding: 6px 2px; background: none; border: none; color: var(--text-secondary); font-family: inherit; font-size: 12px; cursor: pointer; text-align: left; }
.bulk-anim-toggle:hover { color: var(--text-primary); }
.bulk-anim-toggle.on { color: var(--text-primary); }
.bulk-anim-check { width: 15px; height: 15px; flex-shrink: 0; border: 1px solid var(--border-active); border-radius: 4px; display: flex; align-items: center; justify-content: center; font-size: 10px; line-height: 1; }
.bulk-anim-toggle.on .bulk-anim-check { background: var(--accent, #ff6b35); border-color: var(--accent, #ff6b35); color: #fff; }
.bulk-anim-body { margin-top: 8px; padding: 10px 12px; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 8px; }
.bulk-anim-scope { font-size: 11px; color: var(--text-secondary); line-height: 1.5; margin-bottom: 8px; padding-bottom: 8px; border-bottom: 1px solid var(--border); }
.bulk-anim-loading, .bulk-anim-none { font-size: 12px; color: var(--text-secondary); }
.bulk-anim-row { display: flex; justify-content: space-between; align-items: baseline; font-size: 12.5px; color: var(--text-primary); }
.bulk-anim-row.sub { margin-top: 3px; font-size: 11.5px; color: var(--text-secondary); }
.bulk-anim-row strong.short { color: #f87171; }
.bulk-anim-short { margin-top: 8px; font-size: 11.5px; color: #f87171; line-height: 1.45; }
.bulk-anim-skiptoggle { margin-top: 8px; padding: 0; background: none; border: none; color: var(--text-secondary); font-family: inherit; font-size: 11.5px; cursor: pointer; }
.bulk-anim-skiptoggle:hover { color: var(--text-primary); }
.bulk-anim-skips { margin: 6px 0 0; padding-left: 16px; font-size: 11.5px; color: var(--text-secondary); line-height: 1.6; }
.ap-footer { display: flex; justify-content: flex-end; gap: 8px; padding-top: 14px; border-top: 1px solid var(--color-border); margin-top: 10px; }
.ap-success { text-align: center; padding: 8px 0; }
.ap-success-icon { width: 48px; height: 48px; border-radius: 50%; background: rgba(52,211,153,.15); color: #34d399; font-size: 22px; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.ap-success-title { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
.ap-success-sub { font-size: 12px; color: var(--color-text-muted); line-height: 1.55; margin-bottom: 14px; }
.ap-link-box { display: flex; gap: 8px; margin-bottom: 14px; }

/* Voice tabs */
.voice-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--color-border); margin-bottom: 12px; }
.voice-tab { flex: 1; padding: 6px 12px; font-size: 12px; font-weight: 500; color: var(--color-text-muted); cursor: pointer; border: none; border-bottom: 2px solid transparent; background: transparent; font-family: inherit; transition: .15s; }
.voice-tab:hover { color: var(--color-text-primary); }
.voice-tab.active { color: var(--color-accent); border-bottom-color: var(--color-accent); }
.custom-voice-intro { font-size: 11px; color: var(--color-text-muted); line-height: 1.45; margin-bottom: 10px; }

/* Voice preview panel (before upload) */
.voice-preview-panel { background: rgba(96,165,250,.04); border: 1px solid rgba(96,165,250,.2); border-radius: 10px; padding: 12px; margin-bottom: 6px; }
.voice-preview-head { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 12px; }
.voice-preview-icon { font-size: 13px; }
.voice-preview-title { color: #60a5fa; font-weight: 500; }
.voice-preview-name { color: var(--color-text-muted); margin-left: auto; max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 11px; }

/* Custom audio player */
.vp-player { display: flex; align-items: center; gap: 10px; padding: 8px 10px; background: rgba(0,0,0,.25); border: 1px solid rgba(255,255,255,.04); border-radius: 8px; margin-bottom: 10px; }
.vp-play-btn { width: 30px; height: 30px; border-radius: 50%; background: var(--color-accent); border: none; color: #fff; font-size: 11px; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; flex-shrink: 0; transition: .15s; font-family: inherit; }
.vp-play-btn:hover { transform: scale(1.06); }
.vp-track { flex: 1; height: 4px; background: rgba(255,255,255,.08); border-radius: 999px; cursor: pointer; overflow: hidden; position: relative; }
.vp-fill { height: 100%; background: var(--color-accent); border-radius: 999px; transition: width .1s linear; }
.vp-time { font-size: 10px; color: var(--color-text-muted); font-family: "Space Mono", monospace; flex-shrink: 0; white-space: nowrap; }

.voice-preview-actions { display: flex; gap: 6px; margin-bottom: 8px; }
.vp-action { flex: 1; justify-content: center; white-space: nowrap; }
.voice-preview-hint { font-size: 10px; color: var(--color-text-muted); opacity: .75; line-height: 1.4; }

/* Custom voice upload/record */
.voice-action-row { display: flex; gap: 6px; margin-bottom: 8px; }
.voice-upload-btn, .voice-record-btn { flex: 1; padding: 8px 10px; border-radius: 7px; border: 1px solid var(--color-border); background: var(--color-bg-elevated); color: var(--color-text-primary); font-size: 12px; font-weight: 500; cursor: pointer; text-align: center; transition: .15s; font-family: inherit; display: flex; align-items: center; justify-content: center; gap: 4px; }
.voice-upload-btn:hover, .voice-record-btn:hover { background: var(--color-bg-card); border-color: var(--color-border-active); }
.voice-record-btn.recording { background: #b91c1c; border-color: #b91c1c; color: #fff; animation: pulse-record 1.4s ease-in-out infinite; }
@keyframes pulse-record { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }
.voice-status { display: flex; align-items: center; gap: 8px; padding: 9px 12px; border-radius: 7px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); font-size: 12px; color: var(--color-text-muted); margin-bottom: 6px; }
.voice-status-spin { display: inline-block; animation: spin 1s linear infinite; color: var(--color-accent); }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
.voice-status-cancel { margin-left: auto; background: transparent; border: none; color: var(--color-text-muted); font-size: 11px; cursor: pointer; padding: 2px 6px; border-radius: 4px; }
.voice-status-cancel:hover { color: var(--color-text-primary); background: rgba(255,255,255,.04); }
.voice-ready-panel { background: rgba(52,211,153,.06); border: 1px solid rgba(52,211,153,.25); border-radius: 8px; padding: 10px 12px; margin-bottom: 6px; }
.voice-ready-head { display: flex; align-items: center; justify-content: space-between; font-size: 12px; font-weight: 500; color: #34d399; margin-bottom: 8px; }
.voice-transcript-preview { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; }
.voice-transcript-label { font-size: 10px; color: var(--color-text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.voice-transcript-text { font-size: 12px; line-height: 1.5; color: var(--color-text-primary); max-height: 90px; overflow-y: auto; }
.voice-ready-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.voice-error { background: rgba(248,113,113,.08); border: 1px solid rgba(248,113,113,.25); color: #fca5a5; border-radius: 7px; padding: 8px 12px; font-size: 12px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }

.custom-audio-list { max-height: 160px; overflow-y: auto; display: flex; flex-direction: column; gap: 3px; }
.custom-audio-row {
  display: flex; align-items: center; gap: 8px; padding: 7px 8px; border-radius: 6px;
  border: 1px solid var(--color-border); cursor: pointer; transition: 0.12s;
  font-size: 12px; color: var(--color-text-secondary);
}
.custom-audio-row:hover { border-color: rgba(167,139,250,.4); }
.custom-audio-row.active { border-color: #a78bfa; background: rgba(167,139,250,.08); color: var(--color-text-primary); }
.custom-audio-icon { flex-shrink: 0; }
.custom-audio-name { flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.custom-audio-check { flex-shrink: 0; color: #a78bfa; font-weight: 700; }

/* Caption color + size */
.caption-color-row { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
.color-swatch {
  width: 22px; height: 22px; border-radius: 50%; cursor: pointer;
  border: 2px solid transparent; flex-shrink: 0; transition: 0.12s;
}
.color-swatch:hover { transform: scale(1.15); }
.color-swatch.active { outline: 2px solid var(--color-accent); outline-offset: 2px; }
.color-custom-wrap {
  position: relative; width: 22px; height: 22px; cursor: pointer; flex-shrink: 0;
}
.color-custom-preview {
  display: block; width: 22px; height: 22px; border-radius: 50%;
  border: 2px solid var(--color-border); pointer-events: none;
}
.color-custom-square {
  border-radius: 4px;
}
.color-custom-input {
  position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; cursor: pointer; padding: 0;
}
.caption-size-row { display: flex; gap: 5px; }
.caption-size-custom { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
.caption-size-custom input { flex: 1; accent-color: #a78bfa; }
.caption-size-value { min-width: 72px; text-align: right; font: 11px var(--font-mono); color: var(--color-text-muted); }
.caption-size-value.active { color: #a78bfa; }
.size-opt {
  flex: 1; height: 28px; border-radius: 6px; border: 1px solid var(--color-border);
  background: var(--color-bg-elevated); color: var(--color-text-muted);
  font-size: 11px; font-family: var(--font-mono); cursor: pointer; transition: 0.12s;
}
.size-opt:hover { border-color: rgba(167,139,250,.4); }
.size-opt.active { border-color: #a78bfa; background: rgba(167,139,250,.1); color: #a78bfa; }

.caption-apply-all {
  width: 100%; margin-top: 12px; height: 32px; border-radius: 6px;
  border: 1px solid var(--color-border); background: var(--color-bg-elevated);
  color: var(--color-text); font-size: 12px; font-weight: 600; cursor: pointer;
  transition: 0.12s;
}
.caption-apply-all:hover:not(:disabled) { border-color: #a78bfa; color: #a78bfa; }
.caption-apply-all:disabled { opacity: 0.6; cursor: default; }

/* AI image result preview */
.ai-image-result {
  border-radius: var(--radius-sm, 6px);
  overflow: hidden;
  position: relative;
  margin-top: 10px;
  margin-bottom: 8px;
}

.ai-image-preview {
  width: 100%;
  height: 120px;
  background: linear-gradient(160deg, #1a1a3e 0%, #0d1a2e 40%, #1a0d2e 70%, #2e1a0d 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.ai-image-overlay-badge {
  position: absolute;
  top: 8px;
  left: 8px;
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 10px;
  font-weight: 700;
  background: rgba(167,139,250,.25);
  color: #a78bfa;
  border: 1px solid rgba(167,139,250,.3);
  backdrop-filter: blur(4px);
  z-index: 1;
}

.ai-image-placeholder {
  text-align: center;
}

.ai-image-ico {
  font-size: 36px;
  opacity: 0.35;
}

.ai-image-actions {
  display: flex;
  gap: 6px;
  margin-top: 6px;
}

/* ── Animate (i2v rung 4) — button + modal ─────────────────────────────── */
.animate-btn {
  flex: 1;
  background: rgba(255, 107, 53, 0.12);
  border: 1px solid rgba(255, 107, 53, 0.4);
  color: #ff8055;
  font-weight: 600;
}
.animate-btn:hover:not(:disabled) {
  background: rgba(255, 107, 53, 0.22);
  border-color: rgba(255, 107, 53, 0.7);
}
.anim-cancel-btn {
  flex: 1;
  border: 1px solid rgba(220, 80, 80, 0.45);
  color: #ff8888;
}
.anim-cancel-btn:hover { background: rgba(220, 80, 80, 0.12); border-color: rgba(220, 80, 80, 0.7); }

.anim-history-row { margin-top: 8px; }
.anim-history-label {
  font-size: 10.5px; opacity: 0.5; letter-spacing: 0.04em;
  text-transform: uppercase; margin-bottom: 5px;
}
.anim-history-strip { display: flex; gap: 6px; }
.anim-history-item {
  position: relative;
  width: 54px; aspect-ratio: 9/16;
  border-radius: 6px; overflow: hidden;
  border: 1.5px solid rgba(255,255,255,0.1);
  cursor: pointer; transition: 0.15s;
  background: #000;
}
.anim-history-item:hover { border-color: rgba(255,255,255,0.3); }
.anim-history-item.current { border-color: #ff6b35; box-shadow: 0 0 0 1px rgba(255,107,53,0.4); }
.anim-history-item video,
.anim-history-item img {
  width: 100%; height: 100%; object-fit: cover; display: block;
}
.anim-history-tier {
  position: absolute; top: 3px; left: 4px;
  background: rgba(0,0,0,0.65); color: #ff8055;
  font-size: 9px; font-weight: 700;
  padding: 1px 4px; border-radius: 3px;
  pointer-events: none;
}

.anim-tier-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 8px;
}
.anim-tier {
  background: rgba(255,255,255,0.03);
  border: 1.5px solid rgba(255,255,255,0.08);
  border-radius: 10px;
  padding: 12px 10px;
  text-align: left;
  cursor: pointer;
  font-family: inherit;
  color: inherit;
  position: relative;
  transition: 0.15s;
}
.anim-tier:hover { border-color: rgba(255,255,255,0.2); }
.anim-tier.selected {
  border-color: #ff6b35;
  background: rgba(255,107,53,0.1);
  box-shadow: inset 0 0 0 1px rgba(255,107,53,0.4);
}
.anim-tier-head {
  display: flex; align-items: center; justify-content: space-between; gap: 6px;
}
.anim-tier-name { font-size: 13px; font-weight: 700; }
.anim-tier-pill {
  background: #ff6b35; color: #0a0a0f;
  font-size: 8.5px; font-weight: 700; letter-spacing: 0.06em;
  padding: 2px 6px; border-radius: 4px;
}
.anim-tier-sub {
  font-size: 10px; opacity: 0.55;
  letter-spacing: 0.04em; text-transform: uppercase;
  margin-top: 2px; margin-bottom: 10px;
}
.anim-tier-row {
  display: flex; justify-content: space-between;
  font-size: 11px; margin-top: 3px; color: rgba(255,255,255,0.7);
}
.anim-tier-row span:first-child { opacity: 0.55; }
.anim-tier-cost { color: #ff6b35; font-weight: 700; }

/* Model dropdown */
.anim-model-dd { position: relative; }
.anim-model-trigger {
  width: 100%; display: flex; align-items: center; gap: 10px;
  padding: 10px 12px; border-radius: 10px;
  border: 1px solid var(--color-border); background: var(--color-bg-elevated);
  color: var(--color-text-primary); cursor: pointer; text-align: left;
  transition: border-color 0.15s;
}
.anim-model-trigger:hover, .anim-model-trigger.open { border-color: rgba(255,107,53,0.5); }
.anim-model-trigger-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.anim-model-trigger-name { font-size: 13px; font-weight: 600; }
.anim-model-trigger-sub { font-size: 10.5px; color: var(--color-text-muted); }
.anim-model-trigger-cost { font-size: 12px; font-weight: 700; color: #ff6b35; white-space: nowrap; }
.anim-model-caret { color: var(--color-text-muted); font-size: 11px; }
.anim-model-trigger.open .anim-model-caret { color: #ff6b35; }

.anim-model-menu {
  position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 30;
  background: var(--color-bg-card, #141416); border: 1px solid var(--color-border);
  border-radius: 10px; padding: 4px; box-shadow: 0 16px 40px rgba(0,0,0,0.5);
  max-height: 300px; overflow-y: auto;
}
.anim-model-option {
  width: 100%; display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border: none; background: none; border-radius: 8px;
  color: var(--color-text-primary); cursor: pointer; text-align: left;
}
.anim-model-option:hover { background: var(--color-bg-elevated); }
.anim-model-option.selected { background: rgba(255,107,53,0.1); }
.anim-model-opt-left { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.anim-model-opt-name { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
.anim-model-opt-sub { font-size: 10px; color: var(--color-text-muted); }
.anim-model-opt-right { display: flex; align-items: center; gap: 8px; white-space: nowrap; }
.anim-model-opt-cost { font-size: 12px; font-weight: 700; color: #ff6b35; }
.anim-model-opt-check { color: #ff6b35; font-size: 13px; }

.anim-engine-meta { display: block; font-size: 10px; opacity: .7; margin-top: 2px; }

.anim-duration-row { display: flex; gap: 6px; }
.anim-dpill {
  flex: 1;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 7px;
  padding: 8px 0;
  font-family: inherit; font-size: 12.5px; color: rgba(255,255,255,0.75);
  cursor: pointer;
  transition: 0.15s;
}
.anim-dpill:hover { border-color: rgba(255,255,255,0.2); }
.anim-dpill.active {
  background: rgba(255,107,53,0.15);
  border-color: rgba(255,107,53,0.55);
  color: #ff8055;
  font-weight: 600;
}

.anim-consent {
  display: flex; align-items: flex-start; gap: 9px;
  margin-top: 12px; padding: 10px 12px; border-radius: 8px;
  background: rgba(255,107,53,0.07); border: 1px solid rgba(255,107,53,0.22);
  font-size: 12px; line-height: 1.45; color: var(--color-text-secondary);
  cursor: pointer;
}
.anim-consent input { margin-top: 2px; flex: none; cursor: pointer; accent-color: #ff6b35; }
.anim-consent a { color: #ff6b35; }
.anim-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-top: 1px solid rgba(255,255,255,0.08);
  padding-top: 14px;
  margin-top: 10px;
}
.anim-total-credits {
  color: #ff6b35; font-weight: 700; font-size: 15px; margin-right: 6px;
}
.anim-total-sub { font-size: 11px; opacity: 0.55; }

.anim-prompt-chips {
  display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px;
}
.anim-prompt-chip {
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 999px;
  padding: 5px 11px;
  font-family: inherit; font-size: 11px;
  color: rgba(255,255,255,0.7);
  cursor: pointer; transition: 0.15s;
}
.anim-prompt-chip:hover {
  background: rgba(255,107,53,0.1);
  border-color: rgba(255,107,53,0.4);
  color: #ff8055;
}

/* AI prompt area */
.ai-prompt-area {
  width: 100%;
  min-height: 54px;
  resize: none;
  padding: 8px 10px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm, 6px);
  color: var(--text-primary);
  font-family: inherit;
  font-size: 12px;
  margin-bottom: 8px;
  box-sizing: border-box;
}

.ai-prompt-area:focus {
  outline: none;
  border-color: rgba(167,139,250,.45);
}

.ai-gen-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}

.ai-gen-meta {
  font-size: 11px;
  color: var(--text-muted);
}

.ai-gen-meta span {
  color: #a78bfa;
}

@media (max-width: 1180px) {
  .editor {
    display: grid;
    grid-template-columns: 320px 1fr;
    height: auto;
    min-height: calc(100vh - 64px);
  }

  .editor-canvas {
    min-height: 560px;
  }

  .editor-right {
    width: auto;
    grid-column: 1 / -1;
    border-left: 0;
    border-top: 1px solid var(--border);
  }
}

@media (max-width: 860px) {
  .main {
    margin-left: 0;
  }

  .topbar {
    padding: 12px 16px;
    height: auto;
    align-items: flex-start;
    flex-direction: column;
    gap: 12px;
  }

  .topbar-left,
  .topbar-right {
    width: 100%;
    justify-content: space-between;
    flex-wrap: wrap;
  }

  /* ── Editor shell, from mobile-editor-v3.html ───────────────────────
     A fixed-height app rather than a scrolling page: bar / canvas / dock,
     with the frame growing to fill whatever the canvas has left. The
     scrolling version put the player in a sticky box of a computed pixel
     height and pushed the scene list below it — which is why the frame was
     small and the page grew a bar for every job. */
  /* A definite height so flex can hand the frame exactly the room that's
     left, but overflow stays visible and the document stays unlocked: if the
     height ever over-reports — which is the iOS bug this started as — the
     content below the fold is still reachable by scrolling, and the dock is
     fixed to the viewport so it can't go missing either way. */
  .main {
    height: var(--wyv-app-h, 100dvh);
    min-height: var(--wyv-app-h, 100dvh);
    display: flex;
    flex-direction: column;
    overflow: visible;
    /* Room for the fixed dock, so the last of the content clears it. */
    padding-bottom: calc(67px + env(safe-area-inset-bottom)) !important;
  }

  /* .editor-body sits between .main and .editor; without this it kept its
     content height and left a strip of bare page under the dock. */
  .editor-body {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
  }

  .editor {
    display: flex !important;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    grid-template-columns: none;
  }

  .editor-canvas {
    order: -1;
    flex: 1 1 auto;
    min-height: 0 !important;
    align-self: stretch;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 4px 12px 0;
    position: static;
    background: var(--color-bg, #0a0a0f);
  }

  /* The frame keeps 9:16 and takes the room the canvas has left. */
  .preview-container {
    flex: 1 1 auto;
    min-height: 0;
    width: auto !important;
    height: auto !important;
    aspect-ratio: 9 / 16;
    max-width: 100%;
    margin: 0 auto;
  }

  .playback-controls { flex: 0 0 auto; width: 100%; }

  /* Scenes move into a sheet; the dock opens them. */
  .editor-sidebar {
    position: fixed;
    inset: auto 0 0 0;
    z-index: 125;
    width: auto;
    height: 62vh;
    border-right: none;
    border-top: 1px solid var(--color-border-active, #34343f);
    border-radius: 16px 16px 0 0;
    background: var(--color-bg-panel, #111117);
    padding-bottom: env(safe-area-inset-bottom);
    overflow-y: auto;
    display: none;
  }
  .editor-sidebar.sheet-open { display: flex; flex-direction: column; }

  /* Config + Assistant ride the same sheet, switched by the rail. */
  .editor-right {
    position: fixed;
    inset: auto 0 0 0;
    z-index: 125;
    width: auto;
    grid-column: auto;
    height: 62vh;
    border-left: 0;
    border-top: 1px solid var(--color-border-active, #34343f);
    border-radius: 16px 16px 0 0;
    background: var(--color-bg-panel, #111117);
    display: none;
    flex-direction: column;
    overflow: hidden;
  }
  .editor-right.sheet-open { display: flex; }
  .editor-right > .cruise-toggle-bar { display: none; }

  .editor-sidebar-header { flex: 0 0 auto; position: relative; }
  .editor-sidebar-header::before {
    content: '';
    position: absolute;
    top: -6px;
    left: 50%;
    transform: translateX(-50%);
    width: 36px;
    height: 4px;
    border-radius: 2px;
    background: var(--color-border-active, #34343f);
  }
  .editor-sheet-done {
    margin-left: auto;
    border: 0;
    background: none;
    color: var(--color-accent, #ff6b35);
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    padding: 4px 2px;
  }
  .scene-list { flex: 1 1 auto; min-height: 0; overflow-y: auto; }

  .editor-sheet-head {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 0 auto;
    padding: 10px 14px 8px;
    border-bottom: 1px solid var(--color-border);
    position: relative;
  }
  .editor-sheet-head b { font-size: 14px; }
  .editor-sheet-head button {
    margin-left: auto;
    border: 0;
    background: none;
    color: var(--color-accent, #ff6b35);
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    padding: 4px 2px;
  }
  .editor-sheet-grab {
    position: absolute;
    top: 5px;
    left: 50%;
    transform: translateX(-50%);
    width: 36px;
    height: 4px;
    border-radius: 2px;
    background: var(--color-border-active, #34343f);
  }
  .editor-sheet-scrim {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 120;
    border: 0;
    padding: 0;
    background: rgba(0, 0, 0, .5);
  }

  /* ── Bar ─────────────────────────────────────────────────────────── */
  .ed-bar {
    position: sticky;
    top: 0;
    z-index: 110;
    flex: 0 0 auto;
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 3px 6px;
    padding-top: calc(3px + env(safe-area-inset-top));
    background: var(--color-bg-panel, #111117);
    border-bottom: 1px solid var(--color-border);
  }
  .ed-ib {
    width: 44px;
    height: 44px;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 11px;
    background: none;
    color: var(--color-text-secondary, #a8a9b4);
    cursor: pointer;
    flex: 0 0 auto;
  }
  .ed-ib svg { width: 19px; height: 19px; }
  .ed-ib:active,
  .ed-ib.is-on { background: var(--color-bg-card, #17171f); color: var(--color-text-primary, #f2f2f5); }

  .ed-bar-title {
    font-size: 14px;
    font-weight: 600;
    letter-spacing: -.01em;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .ed-bar-status {
    flex: 0 0 auto;
    margin-left: 8px;
    padding: 2px 7px;
    border-radius: 5px;
    font-family: var(--font-mono, ui-monospace, Menlo, monospace);
    font-size: 10px;
  }
  .ed-bar-status.is-ok { color: var(--color-success, #34d399); background: var(--color-success-soft, rgba(52,211,153,.12)); }
  .ed-bar-status.is-bad { color: var(--color-danger, #f87171); background: var(--color-danger-soft, rgba(248,113,113,.12)); }
  .ed-bar-status.is-busy { color: var(--color-accent, #ff6b35); background: rgba(255,107,53,.12); }
  .ed-bar-status.is-idle { color: var(--color-text-muted, #7c7d89); background: var(--color-bg-card, #17171f); }
  .ed-bar-sp { flex: 1; }
  .ed-bar-credits {
    font-family: var(--font-mono, ui-monospace, Menlo, monospace);
    font-size: 11.5px;
    color: var(--color-text-muted, #7c7d89);
    padding-right: 4px;
  }

  /* The shell bar is gone here, so nothing needs reserving at the top. */
  .main { padding-top: 0 !important; }

  /* The resume-failed banner is a sibling of .editor-body, so it lands above
     the bar unless told otherwise. It reads as a notice under the project
     name, not as the first thing on the screen. */
  .resume-failed-banner {
    flex: 0 0 auto;
    margin: 0;
    border-radius: 0;
    border-left: 0;
    border-right: 0;
    padding: 8px 12px;
    font-size: 12.5px;
  }

  /* ── Project sheet ──────────────────────────────────────────────────── */
  .ed-projsheet {
    position: fixed;
    inset: auto 0 0 0;
    z-index: 125;
    height: 62vh;
    border-top: 1px solid var(--color-border-active, #34343f);
    border-radius: 16px 16px 0 0;
    background: var(--color-bg-panel, #111117);
    display: none;
    flex-direction: column;
    overflow: hidden;
  }
  .ed-projsheet.sheet-open { display: flex; }
  .ed-projsheet-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 10px 12px calc(16px + env(safe-area-inset-bottom));
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
  .ed-prow {
    display: flex;
    align-items: center;
    gap: 12px;
    width: 100%;
    min-height: 58px;
    padding: 10px 12px;
    border: 1px solid var(--color-border);
    border-radius: 12px;
    background: var(--color-bg-card, #17171f);
    color: inherit;
    font: inherit;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
  }
  .ed-prow:active { border-color: var(--color-border-active, #34343f); }
  .ed-prow.is-disabled,
  .ed-prow:disabled { opacity: .5; cursor: default; }
  .ed-prow-ic {
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border-radius: 9px;
    background: var(--color-bg-panel, #111117);
    font-size: 15px;
  }
  .ed-prow-b { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
  .ed-prow-b b { font-size: 15px; font-weight: 600; }
  .ed-prow-b span {
    font-size: 12px;
    color: var(--color-text-muted, #7c7d89);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .ed-prow-rt {
    flex: 0 0 auto;
    font-size: 13px;
    color: var(--color-text-muted, #7c7d89);
  }
  .ed-prow-lbl {
    font-family: var(--font-mono, ui-monospace, Menlo, monospace);
    font-size: 10px;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--color-text-muted, #7c7d89);
    padding: 8px 2px 0;
  }
  .ed-prow-empty {
    padding: 14px 12px;
    border: 1px dashed var(--color-border);
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.5;
    color: var(--color-text-muted, #7c7d89);
  }

  /* ── Dock ───────────────────────────────────────────────────────────
     Four buttons, Export carrying the accent. This replaces the app's
     global tab bar inside the editor — the mockup has no global tabs here,
     and on a real phone the export bar plus tab bar plus Safari's chrome
     came to three stacked rows. */
  .ed-dock {
    position: fixed;
    inset: auto 0 0 0;
    z-index: 115;
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 10px calc(8px + env(safe-area-inset-bottom));
    border-top: 1px solid var(--color-border);
    background: var(--color-bg-panel, #111117);
  }
  .ed-dbtn {
    flex: 1;
    min-height: 50px;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3px;
    border: 1px solid transparent;
    background: none;
    cursor: pointer;
    color: var(--color-text-secondary, #a8a9b4);
    font: inherit;
    font-size: 10.5px;
  }
  .ed-dbtn svg { width: 20px; height: 20px; }
  .ed-dbtn.is-on {
    color: var(--color-accent, #ff6b35);
    border-color: var(--color-accent-border, rgba(255,107,53,.32));
    background: rgba(255, 107, 53, .1);
  }
  .ed-dbtn-export {
    flex: 0 0 94px;
    flex-direction: row;
    gap: 7px;
    font-size: 14px;
    font-weight: 600;
    background: var(--color-accent, #ff6b35);
    color: #fff;
  }

  /* The reused topbar is no longer a bottom bar — the dock is. */
  .topbar {
    position: static;
    inset: auto;
    flex: 0 0 auto;
    border-top: none;
    border-bottom: 1px solid var(--color-border);
    padding: 6px 10px;
  }
}
.xfb-backdrop { position: fixed; inset: 0; z-index: 300; background: rgba(5,5,10,.6); display: flex; align-items: center; justify-content: center; padding: 20px; }
.xfb-card { position: relative; width: 100%; max-width: 400px; background: var(--bg-card, #17171f); border: 1px solid var(--border, #2a2a36); border-radius: 14px; padding: 24px 26px; display: flex; flex-direction: column; gap: 12px; }
.xfb-close { position: absolute; top: 10px; right: 14px; background: none; border: none; color: var(--text-secondary, #a1a1b5); font-size: 18px; cursor: pointer; }
.xfb-title { font-size: 15px; font-weight: 700; padding-right: 18px; }
.xfb-stars { display: flex; gap: 6px; justify-content: center; }
.xfb-star { background: none; border: none; font-size: 30px; color: #3a3a48; cursor: pointer; transition: color .12s, transform .12s; padding: 2px; }
.xfb-star:hover { transform: scale(1.15); }
.xfb-star.on { color: #fbbf24; }
.xfb-sub { font-size: 12.5px; color: var(--text-secondary, #a1a1b5); }
.xfb-chips { display: flex; flex-wrap: wrap; gap: 7px; }
.xfb-chip { padding: 6px 12px; background: var(--bg-soft, #15151d); border: 1px solid var(--border, #2a2a36); border-radius: 999px; color: var(--text-primary, #ececf3); font-family: inherit; font-size: 12px; cursor: pointer; transition: border-color .12s; }
.xfb-chip.on { border-color: var(--accent, #ff6b35); background: rgba(255,107,53,.1); }
.xfb-input { width: 100%; padding: 9px 11px; background: var(--bg-soft, #15151d); border: 1px solid var(--border, #2a2a36); border-radius: 8px; color: var(--text-primary, #ececf3); font-family: inherit; font-size: 12.5px; resize: none; }
.xfb-send { align-self: flex-end; background: var(--accent, #ff6b35); color: #fff; border: none; border-radius: 8px; padding: 9px 20px; font-size: 13px; font-weight: 700; font-family: inherit; cursor: pointer; }
.xfb-send:disabled { opacity: .5; }
.xfb-thanks { font-size: 17px; font-weight: 700; text-align: center; }
.leave-save-overlay { position: fixed; inset: 0; z-index: 10000; display: grid; place-items: center; background: #0009; padding: 24px; }
.leave-save-card { width: min(480px, 100%); padding: 24px; border-radius: 16px; border: 1px solid var(--color-border); background: var(--color-bg-elevated, #181820); }
.leave-save-card p { margin: 16px 0; }
.leave-save-card .btn { margin-right: 8px; }

.anim-slow{margin:6px 0 0;font-size:12px;color:var(--color-warning, #b45309)}
.anim-slow-tag{color:var(--color-warning, #b45309)}
.export-check { font-size: 12px; line-height: 1.45; padding: 6px 12px; color: var(--color-text-secondary); max-width: 320px; }
.export-check.ok { color: var(--color-success, #4ade80); }
.export-check.note b { color: var(--color-warning, #fbbf24); }
.export-check-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--color-warning, #fbbf24); margin-left: 6px; vertical-align: middle; }
</style>
