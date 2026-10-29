<script setup>
// The redesigned editor (canvas: "WyvStudio Editor Redesign", 2026-10-29). Same brain as the classic editor
// (useEditorCore); this file is only the layout: a rail of tools, one panel per tool, the frame with a toolbar for
// whatever is selected, and a timeline. Staff only until it opens; classic stays one click away.
import { computed, nextTick, ref, watch } from "vue";
import SchedulePostModal from "../components/SchedulePostModal.vue";
import ExportUpdateModal from "../components/ExportUpdateModal.vue";
import UiSelect from "../components/UiSelect.vue";
import CaptionPreview from "../components/CaptionPreview.vue";
import UgcHeadline from "../components/UgcHeadline.vue";
import FinishedVideoPlayer from "../components/FinishedVideoPlayer.vue";
import EditorSkeleton from "../components/skeletons/EditorSkeleton.vue";
import MediaPickerModal from "../components/MediaPickerModal.vue";
import VoiceCloneModal from "../components/VoiceCloneModal.vue";
import { useEditorCore } from "../editor/useEditorCore";
import { chooseEditor } from "../editor/editorChoice";
import { cr, provideEditorCore, usePrices } from "../editor/next/core";
import MediaPanel from "../editor/next/MediaPanel.vue";
import VoicePanel from "../editor/next/VoicePanel.vue";
import CaptionsPanel from "../editor/next/CaptionsPanel.vue";
import AnimateDialog from "../editor/next/AnimateDialog.vue";
import VoicePickerDialog from "../editor/next/VoicePickerDialog.vue";
import CharacterDialog from "../editor/next/CharacterDialog.vue";
import CaptionStylesDialog from "../editor/next/CaptionStylesDialog.vue";

// One editor brain for the view and every panel (the panels inject it).
const core = useEditorCore();
provideEditorCore(core);
const { imageCost, voiceCost } = usePrices(core);

const {
  router, projectId, loading, error, project, scenes, projectTitle,
  generationFailed, generationFailureMessage, wholeVideoTake, leaveSaveDialog, chooseLeave,
  retrySourceEditable, retrySourceDraft, retryError, retryPending, retryGeneration, creditCosts,
  // toolbars
  imageStylesByKey, visualStyleDraft, canAnimateActiveScene, activeSceneAlreadyAnimated, openAnimateModal, generateAIImage,
  aiImagePending, openMediaPicker, openVoiceModal, voiceSpeedDraft, VOICE_SPEED_OPTIONS, voiceStabilityDraft, VOICE_STABILITY_OPTIONS,
  regenerateVoice, captionEffectModalOpen, captionSizeDraft, captionBasePx, captionPositionDraft, CAPTION_POSITION_OPTIONS,
  CAPTION_ANIMATIONS,
  // audiogram preview
  audiogramStyle, audiogramColor, audiogramBgStyle, waveformLive,
  // script: rewrite and a silent scene's length
  rewriteToolsVisible, toggleRewriteTools, REWRITE_EDIT_OPTIONS, REWRITE_TONE_OPTIONS, submitRewrite, rewriteCustomInstruction,
  submitRewriteCustom, rewritePending, rewriteError, rewritePreviewVisible, rewritePreviewCopy, hideRewritePreview, acceptRewrite,
  rewriteApplyPending, sceneDurationDraft, saveSceneDuration, sceneDurationSaving, sceneDurationError,
  // dialogs
  mediaPickerMode, mediaPickerVisible, musicTracks, selectedMusicTrackId, handleMediaPickerSelect, showCloneModal, onVoiceCloned,
  activeScene, activeSceneId, activeSceneIndex, selectScene, sceneTypeLabel, sceneDuration, totalVideoDuration,
  // frame
  previewContainerStyle, currentVisualUrl, activeSceneVisualIsVideo, previewVideoRef, previewVideoBackgroundRef,
  syncSceneVideoPreview, sceneVideoMetadataLoaded, activeMotionClass, showTextCardPreview, showWaveformPreview,
  activeSceneAIImagePending, activeSceneAnimationPending, activeSceneStillRendering, visualSwapPending,
  activeSceneAnimationError, activeSceneVisualGenerationError, isSceneLoading, visualLoadFailed, previewVideoShortfall,
  captionEnabledDraft, captionHighlightDraft, captionAnimationDraft, captionPreviewClass, captionPositionStyle,
  captionFontStyle, previewWords, captionHighlightColorDraft, captionColorDraft, captionsCanRender,
  captionPreviewTimedWords, captionClock, captionPreviewSettings,
  // playback
  previewMode, setPreviewMode, togglePreviewPlay, isPreviewPlaying, pendingScenePlay, playProgress, previewTimer,
  timelineSeek, skipToScene, activeMusicTrack, activeVoiceName,
  audioRef, activeSceneAudioUrl, mediaCrossOriginMode, isAudioLoading, syncSceneVoiceVolume, handleSceneAudioPause,
  handleSceneAudioPlay, musicAudioRef, activeMusicTrackUrl, musicLoop, syncPreviewMusicVolume, musicReady,
  soundAudioRef, activeSceneSoundUrl, syncSceneSoundVolume, auditionMusicTrack, musicAuditionRef, auditionMusicTrackId,
  // script
  sceneScriptDraft, scriptSaveState, voiceSaveState, captionSaveState, scriptSaveError, sceneVoiceOutdated,
  regenerateVoiceForScene, voiceRegeneratePending, hookOptions, useHook, regenerateHooks, hooksGenerating,
  hasScriptForHooks, flushActiveSceneDrafts,
  // scenes
  duplicateScene, promptDeleteScene, deleteSceneTarget, confirmDeleteScene, closeDeleteSceneModal, deleteScenePending,
  moveScene, sceneReorderPendingId, sceneTypeOptions, selectedSceneType, newSceneScript, createScene,
  generateNewSceneDraft, addSceneGeneratePending, addSceneGenerateError, closeAddScene,
  // top bar
  editHistory, historyStepping, stepHistory, latestExportJob, exportToolbar, activeExportJob, latestExportDownloadUrl,
  exportChecks, exportCheckNotes, exportsByRatio, selectedExportRatio, ASPECT_RATIO_OPTIONS, shareAction,
  shareTogglePending, shareCopiedToast, exportInProgress, exportBlockerMessage, exportAspectRatios, toggleExportRatio,
  queueExport, failedSceneCount, resumeFailedScenes, resumePending, resumeError, notificationToasts,
  scheduleModalOpen, deliveryExport, onPostScheduled, approvalModalOpen, closeApprovalModal, approvalCreated,
  approvalForm, approvalError, approvalSubmitting, submitApproval, copyApprovalUrl, APPROVAL_EXPIRES_OPTIONS,
  exportWarning, cancelExportAction, continueExportAction, updateExportAction,
} = core;

// ── What is selected and which tool is open ─────────────────────────────────
// Clicking a thing selects it and opens its tool: the caption opens Captions, the picture opens Media. The rail
// opens a tool directly. `selection` decides the toolbar above the frame.
const RAIL = [
  { id: "script", label: "Script", d: "M4 6h16M4 12h16M4 18h10" },
  { id: "media", label: "Media", d: "M4 5h16v14H4zM4 15l4-4 4 4 3-3 5 5" },
  { id: "captions", label: "Captions", d: "M3 6h18v12H3zM7 11h4M13 11h4M7 14h10" },
  { id: "voice", label: "Voice", d: "M12 4a3 3 0 0 1 3 3v5a3 3 0 0 1-6 0V7a3 3 0 0 1 3-3zM5 11a7 7 0 0 0 14 0M12 18v3" },
  { id: "audio", label: "Audio", d: "M9 18V6l10-2v12M9 18a3 3 0 1 1-6 0 3 3 0 0 1 6 0zM19 16a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" },
  { id: "brand", label: "Brand", d: "M12 3a9 9 0 1 0 0 18c1.5 0 2-1 2-2s-1-2 0-3 3 0 4-1 3-3 3-5c0-4-4-7-9-7zM7.5 11.5h.01M10 7.5h.01M14.5 7.5h.01" },
  { id: "assistant", label: "Assistant", d: "M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z" },
  { id: "project", label: "Project", d: "M4 7h16v12H4zM4 7l2-3h5l2 3" },
];
const SELECTION_FOR_PANEL = { captions: "caption", media: "image", voice: "voice" };
const panel = ref("script");
const selection = ref("scene");

function openPanel(id) {
  panel.value = id;
  if (SELECTION_FOR_PANEL[id]) selection.value = SELECTION_FOR_PANEL[id];
}
function select(kind, sceneId = null) {
  if (sceneId != null) selectScene(sceneId);
  selection.value = kind;
  if (kind === "caption") panel.value = "captions";
  else if (kind === "image") panel.value = "media";
  else if (kind === "voice") panel.value = "voice";
}

// ── Top bar ─────────────────────────────────────────────────────────────────
const saveLabel = computed(() => {
  const states = [scriptSaveState.value, voiceSaveState.value, captionSaveState.value];
  if (states.includes("error")) return scriptSaveError.value || "Some changes did not save";
  if (states.includes("saving")) return "Saving…";
  if (states.includes("pending")) return "Unsaved changes";
  return "Saved";
});
const exportStatus = computed(() => {
  const job = latestExportJob.value;
  if (!job) return null;
  if (job.status === "completed") return { tone: exportToolbar.value.stale ? "warn" : "ok", text: exportToolbar.value.stale ? "Export out of date" : "Last export ready" };
  if (job.status === "failed") return { tone: "bad", text: "Export failed", detail: job.failure_reason || "" };
  return { tone: "busy", text: exportToolbar.value.status };
});
const shareOpen = ref(false);
const exportOpen = ref(false);
function onShare(action) {
  shareAction(action);
  if (action !== "share") shareOpen.value = false;
}
async function runExport() {
  exportOpen.value = false;
  await queueExport();
}
async function backToClassic() {
  await flushActiveSceneDrafts();
  chooseEditor("classic");
}

// ── Script panel ────────────────────────────────────────────────────────────
// Every scene's script is on the panel; the one being typed in becomes the active scene, so its text goes through
// the same draft and autosave as the classic editor.
function scriptFor(scene) {
  return scene.id === activeSceneId.value ? sceneScriptDraft.value : scene.script_text || "";
}
// Selecting a scene waits for the previous scene's drafts to save, so the first keys typed into another scene's box
// can land before it is active; they are held and handed over once it is.
let heldTyping = null;
function onScriptInput(scene, event) {
  if (scene.id !== activeSceneId.value) {
    heldTyping = { id: scene.id, text: event.target.value };
    return;
  }
  sceneScriptDraft.value = event.target.value;
}
watch(activeSceneId, (id) => {
  // A scene picked on the timeline or the frame comes into view in the Script panel.
  nextTick(() => document.getElementById(`nx-script-${id}`)?.closest(".nx-scene")?.scrollIntoView({ block: "nearest", behavior: "smooth" }));
  if (!heldTyping) return;
  const held = heldTyping;
  heldTyping = null;
  if (held.id === id) nextTick(() => { sceneScriptDraft.value = held.text; });
});
const sortedHooks = computed(() =>
  [...(hookOptions.value || [])].sort((a, b) => (b.hook_score ?? -1) - (a.hook_score ?? -1)).slice(0, 4)
);
const hookSceneText = computed(() => {
  const s = scenes.value.find((x) => x.scene_type === "hook") ?? scenes.value[0];
  return (s?.id === activeSceneId.value ? sceneScriptDraft.value : s?.script_text || "").trim();
});
function scoreTone(score) {
  if (score == null) return "none";
  if (score >= 80) return "good";
  if (score >= 60) return "fair";
  return "weak";
}

// Add a scene after the selected one (the insert popover comes with the Audio/Brand slice).
const adding = ref(false);
function startAdd() {
  closeAddScene();
  adding.value = true;
}
async function finishAdd() {
  await createScene(activeSceneId.value);
  adding.value = false;
}

// ── Timeline ────────────────────────────────────────────────────────────────
function sceneStart(index) {
  let t = 0;
  for (let i = 0; i < index; i++) t += sceneDuration(scenes.value[i]);
  return t;
}
const playheadPct = computed(() => {
  const total = totalVideoDuration.value || 1;
  if (previewMode.value === "full") return playProgress.value;
  const i = Math.max(0, activeSceneIndex.value);
  return ((sceneStart(i) + (playProgress.value / 100) * sceneDuration(activeScene.value)) / total) * 100;
});
function trackSeek(event) {
  const rect = event.currentTarget.getBoundingClientRect();
  timelineSeek(((event.clientX - rect.left) / rect.width) * 100);
}
function thumbFor(scene) {
  const a = scene.visual_asset;
  if (!a) return null;
  if (a.thumbnail_url) return { type: "img", url: a.thumbnail_url };
  if (a.asset_type === "video" || String(a.mime_type || "").startsWith("video/")) return { type: "video", url: a.storage_url };
  return a.storage_url ? { type: "img", url: a.storage_url } : null;
}
function seconds(v) {
  return `${Number(v || 0).toFixed(1)}s`;
}
// A fixed waveform shape for the voice lane: it says "narration is here", not the real loudness.
const WAVE = (() => {
  let d = "";
  for (let x = 1; x < 100; x += 2.2) {
    const h = 2 + 7 * Math.abs(Math.sin(x * 0.41) * Math.cos(x * 0.13));
    d += `M${x.toFixed(1)} ${(10 - h).toFixed(1)}V${(10 + h).toFixed(1)} `;
  }
  return d;
})();

const toolbarLabel = computed(() => ({ caption: "Caption tools", image: "Picture tools", voice: "Voice tools" })[selection.value] || "Scene tools");
const PANEL_TITLES = { audio: "Audio", brand: "Brand kit", assistant: "Assistant", project: "Project" };

// ── Toolbars: shortcuts to the panel's own controls (same state, so both always agree) ──
const isAiScene = computed(() => activeScene.value?.visual_type === "ai_image");
const lookName = computed(() => {
  const key = visualStyleDraft.value ?? activeScene.value?.visual_style ?? activeScene.value?.image_generation_settings?.style ?? project.value?.ai_broll_style;
  return imageStylesByKey.value.get(key)?.label ?? key ?? "";
});
// "Edit image" opens Media with the edit box in view.
function focusImageEdit() {
  openPanel("media");
  nextTick(() => document.getElementById("nx-edit")?.focus());
}
const captionStyleName = computed(() => CAPTION_ANIMATIONS.find((a) => a.key === captionAnimationDraft.value)?.name || "Style");
const captionPx = computed(() => captionBasePx(captionSizeDraft.value));
function nudgeCaptionSize(step) {
  captionSizeDraft.value = String(Math.max(8, Math.min(48, Math.round(captionPx.value) + step)));
}
const POSITION_SHORT = { top_third: "Top", center: "Middle", bottom_third: "Bottom" };
function cyclePosition() {
  const order = ["bottom_third", "center", "top_third"].filter((v) => CAPTION_POSITION_OPTIONS.some((o) => o.value === v));
  captionPositionDraft.value = order[(order.indexOf(captionPositionDraft.value) + 1) % order.length];
}
// Refs are unwrapped in the template, so the cycling happens here.
// A saved speed reads "1" where the option is "1.0", so values compare as numbers when they are numbers.
function cycleOption(model, options) {
  const same = (a, b) => a === b || (a !== "" && b !== "" && !isNaN(a) && !isNaN(b) && Number(a) === Number(b));
  const i = options.findIndex((o) => same(o.value, model.value));
  model.value = options[(i + 1) % options.length].value;
}
const cycleSpeed = () => cycleOption(voiceSpeedDraft, VOICE_SPEED_OPTIONS);
const cycleDelivery = () => cycleOption(voiceStabilityDraft, VOICE_STABILITY_OPTIONS);
const DELIVERY = { low: "Expressive", medium: "Balanced", high: "Steady" };

// The generation-failed screen: fix the source, then try again (a scanned PDF can be read with AI, priced per section).
const pdfSource = computed(() => project.value?.source_type === "pdf_upload");
</script>

<template>
  <main class="nx">
    <EditorSkeleton v-if="loading" />
    <section v-else-if="error" class="nx-state">{{ error }}</section>
    <section v-else-if="generationFailed" class="nx-state">
      <h2>This video couldn't be made</h2>
      <p>{{ generationFailureMessage }}</p>
      <p class="nx-hint">Nothing was charged for the failed attempt.</p>
      <p v-if="pdfSource" class="nx-hint">Scanned or image-only PDFs can be read with AI: {{ creditCosts.pdf_vision_page ?? 1 }} credit per page-sized section, shown in your balance before anything runs.</p>
      <template v-if="retrySourceEditable">
        <label class="nx-label" for="nx-retry-source">{{ project?.source_type === "url" ? "The link: fix it or paste another" : "Your source: edit it before trying again" }}</label>
        <textarea id="nx-retry-source" v-model="retrySourceDraft" class="nx-input" :rows="project?.source_type === 'url' ? 2 : 5"
          :placeholder="project?.source_type === 'url' ? 'https://…' : 'What the video should be about'"></textarea>
        <p v-if="project?.source_type === 'url'" class="nx-hint">Instagram, TikTok and other login-only pages can't be read. Paste the text or an open article link instead.</p>
      </template>
      <p v-if="retryError" class="nx-err">{{ retryError }}</p>
      <div class="nx-actions">
        <button v-if="pdfSource" class="nx-btn nx-primary" type="button" :disabled="retryPending" @click="retryGeneration({ readScanned: true })">{{ retryPending ? "Trying again…" : "Read it with AI and try again" }}</button>
        <button v-else class="nx-btn nx-primary" type="button" :disabled="retryPending" @click="retryGeneration">{{ retryPending ? "Trying again…" : "Try again" }}</button>
        <button class="nx-btn" type="button" @click="router.push({ name: 'dashboard' })">Start a different video</button>
      </div>
    </section>
    <section v-else-if="wholeVideoTake" class="nx-state">
      <h2>{{ projectTitle }}</h2>
      <p>This take has the picture and dialogue baked in, so scenes, captions and other shapes can't be edited here. Change it in its take review.</p>
      <FinishedVideoPlayer v-if="currentVisualUrl" :src="currentVisualUrl" />
      <div class="nx-actions">
        <button class="nx-btn nx-primary" type="button" @click="router.push(`/ugc-ads/review/${project.id}`)">Open take review</button>
        <button class="nx-btn" type="button" @click="router.push({ name: 'dashboard' })">Back to dashboard</button>
      </div>
    </section>

    <div v-else class="nx-app">
      <!-- Top bar -->
      <header class="nx-top">
        <div class="nx-top-l">
          <button class="nx-ib" type="button" aria-label="Back to dashboard" @click="router.push({ name: 'dashboard' })">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
          </button>
          <span class="nx-title">{{ projectTitle }}</span>
          <span :class="['nx-saved', saveLabel === 'Saved' ? '' : 'is-busy']">{{ saveLabel }}</span>
        </div>
        <div class="nx-top-c" role="group" aria-label="Undo and redo">
          <button class="nx-ib" type="button" :disabled="!editHistory.can_undo || historyStepping"
            :title="editHistory.can_undo ? `Undo: ${editHistory.undo_label} (⌘Z)` : 'Nothing to undo'" aria-label="Undo" @click="stepHistory('undo')">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14L4 9l5-5" /><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11" /></svg>
          </button>
          <button class="nx-ib" type="button" :disabled="!editHistory.can_redo || historyStepping"
            :title="editHistory.can_redo ? `Redo: ${editHistory.redo_label} (⇧⌘Z)` : 'Nothing to redo'" aria-label="Redo" @click="stepHistory('redo')">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 14l5-5-5-5" /><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13" /></svg>
          </button>
        </div>
        <div class="nx-top-r">
          <span v-if="exportStatus" :class="['nx-export-status', `is-${exportStatus.tone}`]" :tabindex="exportStatus.detail ? 0 : undefined">
            <i></i>{{ exportStatus.text }}
            <span v-if="exportStatus.detail" class="nx-tip" role="tooltip">{{ exportStatus.detail }}</span>
          </span>
          <button class="nx-btn" type="button" @click="router.push({ name: 'project-variants', params: { projectId } })">Variants</button>
          <div v-if="activeExportJob?.status === 'completed' && latestExportDownloadUrl" class="nx-menu-wrap">
            <button class="nx-btn" type="button" aria-haspopup="menu" :aria-expanded="shareOpen" @click="shareOpen = !shareOpen; exportOpen = false">
              Share<span v-if="exportCheckNotes.length" class="nx-dot" :title="`${exportCheckNotes.length} to check`"></span>
            </button>
            <div v-if="shareOpen" class="nx-menu" role="menu">
              <template v-if="exportChecks.length">
                <div class="nx-menu-label">Checks</div>
                <div v-if="!exportCheckNotes.length" class="nx-check ok">✓ Sound, pictures, narration and length all checked</div>
                <div v-for="c in exportCheckNotes" :key="c.id" class="nx-check"><b>{{ c.label }}:</b> {{ c.detail }}</div>
              </template>
              <div class="nx-menu-label">Aspect ratio</div>
              <div class="nx-ratios">
                <button v-for="job in (exportsByRatio.length ? exportsByRatio : [activeExportJob])" :key="job.id" type="button"
                  :class="['nx-ratio', job.id === activeExportJob?.id ? 'on' : '']" @click="selectedExportRatio = job.aspect_ratio">{{ job.aspect_ratio }}</button>
              </div>
              <button class="nx-menu-item" type="button" @click="onShare('open')">Open in new tab</button>
              <button class="nx-menu-item" type="button" @click="onShare('download')">Download MP4</button>
              <button class="nx-menu-item" type="button" @click="onShare('schedule')">Schedule</button>
              <button class="nx-menu-item" type="button" @click="onShare('approval')">Send for approval</button>
              <button class="nx-menu-item" type="button" :disabled="shareTogglePending" @click="onShare('share')">
                {{ shareTogglePending ? "Creating link…" : shareCopiedToast === "Copied!" ? "Link copied" : "Copy share link" }}
              </button>
              <div v-if="shareCopiedToast && shareCopiedToast !== 'Copied!'" class="nx-menu-url">{{ shareCopiedToast }}</div>
            </div>
          </div>
          <div class="nx-menu-wrap">
            <button class="nx-btn nx-primary" type="button" :disabled="exportInProgress || !!exportBlockerMessage"
              :title="exportBlockerMessage || exportToolbar.hint" @click="exportOpen = !exportOpen; shareOpen = false">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11" /><path d="M7 10l5 5 5-5" /><path d="M5 20h14" /></svg>
              {{ exportInProgress ? "Finishing…" : exportToolbar.action }}
            </button>
            <div v-if="exportOpen" class="nx-menu" role="menu">
              <div class="nx-menu-label">Formats</div>
              <label v-for="opt in ASPECT_RATIO_OPTIONS" :key="opt.value" class="nx-format">
                <input type="checkbox" :checked="exportAspectRatios.includes(opt.value)" @change="toggleExportRatio(opt.value)" />
                <b>{{ opt.label }}</b><span>{{ opt.sub }}</span>
              </label>
              <p class="nx-menu-note">Each format is its own render of the same video. No extra credits.</p>
              <button class="nx-btn nx-primary nx-full" type="button" :disabled="!exportAspectRatios.length || exportInProgress" @click="runExport">
                Export {{ exportAspectRatios.length }} format{{ exportAspectRatios.length === 1 ? "" : "s" }}
              </button>
            </div>
          </div>
          <button class="nx-link" type="button" title="Open this video in the classic editor" @click="backToClassic">Classic editor</button>
        </div>
      </header>

      <div v-if="failedSceneCount > 0" class="nx-banner">
        <span><b>{{ failedSceneCount }} scene{{ failedSceneCount === 1 ? "" : "s" }} failed</b> to finish. {{ resumeError || "You can run them again." }}</span>
        <button class="nx-btn" type="button" :disabled="resumePending" @click="resumeFailedScenes">{{ resumePending ? "Resuming…" : "Resume failed" }}</button>
      </div>

      <div class="nx-body">
        <!-- Rail -->
        <nav class="nx-rail" aria-label="Editor tools">
          <button v-for="r in RAIL" :key="r.id" type="button" :class="['nx-rail-btn', panel === r.id ? 'on' : '']" :aria-pressed="panel === r.id" @click="openPanel(r.id)">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="r.d" /></svg>
            <span>{{ r.label }}</span>
          </button>
        </nav>

        <!-- Panel -->
        <aside class="nx-panel" aria-label="Properties">
          <template v-if="panel === 'script'">
            <div class="nx-ph">
              <h2>Script</h2>
              <p>{{ scenes.length }} scene{{ scenes.length === 1 ? "" : "s" }} · {{ seconds(totalVideoDuration) }}</p>
            </div>

            <section v-if="sortedHooks.length || hasScriptForHooks" class="nx-hooks">
              <div class="nx-row">
                <span class="nx-label">Opening line</span>
                <button class="nx-mini" type="button" :disabled="hooksGenerating || !hasScriptForHooks" @click="regenerateHooks">
                  {{ hooksGenerating ? "Writing…" : sortedHooks.length ? "New options" : "Suggest openings" }}
                </button>
              </div>
              <button v-for="h in sortedHooks" :key="h.id || h.hook_text" type="button"
                :class="['nx-hook', h.hook_text?.trim() === hookSceneText ? 'on' : '']" @click="useHook(h)">
                <span :class="['nx-score', `is-${scoreTone(h.hook_score)}`]">{{ h.hook_score ?? "–" }}</span>
                <span class="nx-hook-text">{{ h.hook_text }}</span>
              </button>
              <p v-if="sortedHooks.length" class="nx-hint">Scored for how well it stops the scroll. Picking one replaces the first scene's line; the voice re-records when you finish the video.</p>
            </section>

            <div v-for="(scene, i) in scenes" :key="scene.id" :class="['nx-scene', scene.id === activeSceneId ? 'on' : '']">
              <div class="nx-row">
                <label :for="`nx-script-${scene.id}`" class="nx-scene-name">Scene {{ i + 1 }} · {{ sceneTypeLabel(scene) }}</label>
                <span class="nx-num">{{ seconds(sceneDuration(scene)) }}</span>
              </div>
              <textarea :id="`nx-script-${scene.id}`" class="nx-script" :value="scriptFor(scene)" :rows="Math.min(8, Math.max(2, Math.ceil(scriptFor(scene).length / 42)))"
                @focus="select('scene', scene.id)" @input="onScriptInput(scene, $event)"></textarea>
              <div v-if="sceneVoiceOutdated(scene)" class="nx-row nx-warn">
                <span>The voice is from an older script.</span>
                <button class="nx-mini" type="button" :disabled="voiceRegeneratePending && scene.id === activeSceneId" @click="regenerateVoiceForScene(scene.id)">
                  {{ voiceRegeneratePending && scene.id === activeSceneId ? "Recording…" : "Re-record" }}
                </button>
              </div>

              <!-- The selected scene's tools: rewrite it with AI (free), and the length of a scene with no voice. -->
              <template v-if="scene.id === activeSceneId">
                <div class="nx-row">
                  <button class="nx-mini" type="button" :aria-expanded="rewriteToolsVisible" @click="toggleRewriteTools">Rewrite</button>
                  <label v-if="!scene.voice_settings?.audio_asset_id" class="nx-len">
                    <span>Length</span>
                    <input v-model="sceneDurationDraft" type="number" min="1" max="600" step="0.5" :disabled="sceneDurationSaving"
                      :placeholder="String(scene.duration_seconds ?? 12)" aria-label="Scene length in seconds" @change="saveSceneDuration" />s
                  </label>
                </div>
                <p v-if="sceneDurationError" class="nx-err">{{ sceneDurationError }}</p>
                <div v-if="rewriteToolsVisible" class="nx-rewrite">
                  <div class="nx-chips">
                    <button v-for="o in [...REWRITE_EDIT_OPTIONS, ...REWRITE_TONE_OPTIONS]" :key="o.mode" type="button" class="nx-chip" :disabled="rewritePending" @click="submitRewrite(o.mode)">{{ o.label }}</button>
                  </div>
                  <div class="nx-row">
                    <input v-model="rewriteCustomInstruction" class="nx-input nx-input-sm" placeholder="Or say how: make it about price" aria-label="How to rewrite this scene" @keyup.enter="submitRewriteCustom" />
                    <button class="nx-mini" type="button" :disabled="rewritePending || !rewriteCustomInstruction.trim()" @click="submitRewriteCustom">{{ rewritePending ? "Writing…" : "Go" }}</button>
                  </div>
                  <p class="nx-hint">This scene only. Locked facts stay as they are. No credits.</p>
                  <p v-if="rewriteError" class="nx-err">{{ rewriteError }}</p>
                </div>
                <div v-if="rewritePreviewVisible" class="nx-quote">
                  <span class="nx-label">Rewritten</span>
                  <p class="nx-rewrite-copy">{{ rewritePreviewCopy }}</p>
                  <div class="nx-row nx-end">
                    <button class="nx-link" type="button" @click="hideRewritePreview">Keep mine</button>
                    <button class="nx-btn nx-primary" type="button" :disabled="rewriteApplyPending" @click="acceptRewrite">{{ rewriteApplyPending ? "Using…" : "Use this" }}</button>
                  </div>
                </div>
              </template>
            </div>

            <div v-if="adding" class="nx-add">
              <span class="nx-label">New scene after scene {{ activeSceneIndex + 1 }}</span>
              <div class="nx-chips">
                <button v-for="t in sceneTypeOptions" :key="t" type="button" :class="['nx-chip', selectedSceneType === t ? 'on' : '']" @click="selectedSceneType = t">{{ t }}</button>
              </div>
              <textarea v-model="newSceneScript" class="nx-input" rows="3" placeholder="What this scene says, or draft it with AI"></textarea>
              <p v-if="addSceneGenerateError" class="nx-err">{{ addSceneGenerateError }}</p>
              <div class="nx-row nx-end">
                <button class="nx-link" type="button" @click="adding = false">Cancel</button>
                <button class="nx-btn" type="button" :disabled="addSceneGeneratePending" @click="generateNewSceneDraft">{{ addSceneGeneratePending ? "Drafting…" : "Draft with AI" }}</button>
                <button class="nx-btn nx-primary" type="button" @click="finishAdd">Add scene</button>
              </div>
            </div>
            <button v-else class="nx-btn nx-outline nx-full" type="button" @click="startAdd">+ Add scene</button>
          </template>

          <MediaPanel v-else-if="panel === 'media'" />
          <VoicePanel v-else-if="panel === 'voice'" />
          <CaptionsPanel v-else-if="panel === 'captions'" />

          <template v-else>
            <div class="nx-ph">
              <h2>{{ PANEL_TITLES[panel] }}</h2>
              <p>Coming next in the new editor.</p>
            </div>
            <p class="nx-hint">{{ PANEL_TITLES[panel] }} settings are being moved over. Until then they work in the classic editor, on this same video.</p>
            <button class="nx-btn nx-full" type="button" @click="backToClassic">Open in the classic editor</button>
          </template>
        </aside>

        <!-- Frame + timeline -->
        <div class="nx-main">
          <div class="nx-stage">
            <div class="nx-toolbar" role="toolbar" :aria-label="toolbarLabel">
              <template v-if="selection === 'caption'">
                <button class="nx-tb" type="button" @click="captionEffectModalOpen = true"><b class="nx-aa">Aa</b>{{ captionStyleName }}</button>
                <span class="nx-sep"></span>
                <button class="nx-tb nx-tb-icon" type="button" aria-label="Smaller captions" @click="nudgeCaptionSize(-2)">−</button>
                <span class="nx-num nx-tb-num" aria-live="polite">{{ Math.round(captionPx) }}</span>
                <button class="nx-tb nx-tb-icon" type="button" aria-label="Bigger captions" @click="nudgeCaptionSize(2)">+</button>
                <span class="nx-sep"></span>
                <label class="nx-tb" title="Highlight color">
                  <span class="nx-tb-swatch" :style="{ background: captionHighlightColorDraft }"></span>Highlight
                  <input v-model="captionHighlightColorDraft" type="color" class="nx-hidden-input" aria-label="Highlight color" />
                </label>
                <button class="nx-tb" type="button" aria-label="Caption position, click to change" @click="cyclePosition">{{ POSITION_SHORT[captionPositionDraft] || "Position" }}</button>
                <span class="nx-tag">This scene</span>
              </template>
              <template v-else-if="selection === 'image'">
                <template v-if="isAiScene">
                  <button class="nx-tb" type="button" :disabled="aiImagePending || activeSceneAIImagePending || activeSceneAnimationPending" @click="generateAIImage">
                    {{ aiImagePending || activeSceneAIImagePending ? "Drawing…" : "Regenerate" }}<span class="nx-dim">{{ cr(imageCost) }}</span>
                  </button>
                  <button class="nx-tb" type="button" @click="focusImageEdit">Edit image</button>
                  <button v-if="lookName" class="nx-tb" type="button" @click="openPanel('media')">Look: {{ lookName }}</button>
                </template>
                <button class="nx-tb" type="button" @click="openMediaPicker('visual')">Replace</button>
                <button v-if="canAnimateActiveScene && !activeSceneAnimationPending && (isAiScene || !activeSceneVisualIsVideo)" class="nx-tb" type="button" @click="openAnimateModal">{{ activeSceneAlreadyAnimated ? "Animate again" : "Animate" }}</button>
                <span class="nx-tag">This scene</span>
              </template>
              <template v-else-if="selection === 'voice'">
                <button class="nx-tb" type="button" @click="openVoiceModal">{{ activeVoiceName || "Voice" }}</button>
                <button class="nx-tb" type="button" aria-label="Voice speed, click to change" @click="cycleSpeed">Speed {{ voiceSpeedDraft }}×</button>
                <button class="nx-tb" type="button" aria-label="Delivery, click to change" @click="cycleDelivery">{{ DELIVERY[voiceStabilityDraft] || "Delivery" }}</button>
                <button class="nx-tb" type="button" :disabled="voiceRegeneratePending" @click="regenerateVoice">
                  {{ voiceRegeneratePending ? "Recording…" : "Re-record" }}<span class="nx-dim">{{ cr(voiceCost) }}</span>
                </button>
                <span class="nx-tag">This scene</span>
              </template>
              <template v-else>
                <span class="nx-tb-label">Scene {{ activeSceneIndex + 1 }} · {{ activeScene ? sceneTypeLabel(activeScene) : "" }}</span>
                <span class="nx-num">{{ seconds(sceneDuration(activeScene)) }}</span>
                <span class="nx-sep"></span>
                <button class="nx-tb" type="button" @click="openPanel('script')">Edit script</button>
                <button class="nx-tb" type="button" :disabled="activeSceneIndex <= 0 || sceneReorderPendingId !== null" aria-label="Move scene earlier" @click="moveScene(activeSceneId, 'up')">←</button>
                <button class="nx-tb" type="button" :disabled="activeSceneIndex >= scenes.length - 1 || sceneReorderPendingId !== null" aria-label="Move scene later" @click="moveScene(activeSceneId, 'down')">→</button>
                <button class="nx-tb" type="button" @click="duplicateScene(activeSceneId)">Duplicate</button>
                <button class="nx-tb" type="button" aria-label="Delete scene" @click="promptDeleteScene(activeSceneId)">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" /></svg>
                </button>
              </template>
            </div>

            <p v-if="previewVideoShortfall > 0.15" class="nx-note">This clip ends {{ previewVideoShortfall.toFixed(1) }}s before the narration; the last frame holds.</p>

            <div class="nx-frame-wrap">
              <div :class="['nx-frame', selection === 'image' ? 'is-selected' : '']" :style="previewContainerStyle">
                <button class="nx-frame-hit" type="button" aria-label="Select the scene picture" @click="select('image')">
                  <template v-if="currentVisualUrl && activeSceneVisualIsVideo">
                    <video :src="currentVisualUrl" class="nx-fit-bg" ref="previewVideoBackgroundRef" @loadedmetadata="syncSceneVideoPreview" muted playsinline></video>
                    <video :src="currentVisualUrl" class="nx-media nx-contain" ref="previewVideoRef" @loadedmetadata="sceneVideoMetadataLoaded" muted playsinline></video>
                  </template>
                  <img v-else-if="currentVisualUrl" :src="currentVisualUrl" :class="['nx-media', activeMotionClass]" alt="" />
                  <div v-else-if="showTextCardPreview" class="nx-textcard">{{ sceneScriptDraft || activeScene?.script_text }}</div>
                  <div v-else-if="showWaveformPreview" class="nx-wave" :style="audiogramBgStyle">
                    <svg v-if="audiogramStyle === 'circle'" viewBox="0 0 200 200" class="nx-wave-circle" aria-hidden="true">
                      <g transform="translate(100,100)">
                        <line v-for="(bar, i) in waveformLive" :key="i" :transform="`rotate(${i * (360 / waveformLive.length)})`" x1="0" y1="38" x2="0" :y2="38 + bar * 52"
                          :stroke="audiogramColor" stroke-width="6" stroke-linecap="round" :opacity="0.6 + bar * 0.4" />
                      </g>
                      <circle cx="100" cy="100" r="30" :fill="audiogramColor" opacity="0.15" />
                    </svg>
                    <div v-else :class="['nx-wave-bars', `is-${audiogramStyle}`]">
                      <i v-for="(bar, i) in (audiogramStyle === 'minimal' ? [...waveformLive, ...waveformLive.slice().reverse()] : waveformLive)" :key="i"
                        :style="{ height: `${Math.round(bar * (audiogramStyle === 'minimal' ? 80 : 100)) + (audiogramStyle === 'minimal' ? 8 : 0)}%`, background: audiogramColor }"></i>
                    </div>
                  </div>
                </button>
                <div v-if="activeSceneAIImagePending" class="nx-loading">Generating the picture…</div>
                <div v-else-if="activeSceneAnimationPending" class="nx-loading">{{ activeSceneStillRendering ? "The video model is busy, so this clip is taking longer. You can leave this page." : "Animating this scene…" }}</div>
                <div v-else-if="visualSwapPending" class="nx-loading">Swapping the picture…</div>
                <div v-else-if="activeSceneAnimationError" class="nx-loading is-bad">{{ activeSceneAnimationError }}</div>
                <div v-else-if="activeSceneVisualGenerationError" class="nx-loading is-bad">{{ activeSceneVisualGenerationError }}</div>
                <div v-else-if="isSceneLoading" class="nx-loading">Loading scene…</div>
                <div v-else-if="visualLoadFailed" class="nx-loading is-bad">Media unavailable</div>
                <span class="nx-watermark">WYVSTUDIO</span>
                <UgcHeadline :layout="activeScene?.caption_settings_json?.ugc_headline || {}" :aspect-ratio="project?.aspect_ratio || '9:16'" />
                <button
                  v-if="captionEnabledDraft && captionHighlightDraft !== 'none' && captionAnimationDraft === 'plain'"
                  type="button" :class="['preview-caption', 'nx-caption', captionPreviewClass, selection === 'caption' ? 'is-selected' : '']"
                  :style="[captionPositionStyle, captionFontStyle]" aria-label="Select captions" @click="select('caption')">
                  <span v-for="(word, index) in previewWords(sceneScriptDraft || activeScene?.script_text)" :key="index"
                    :class="`caption-word ${word.highlighted ? 'highlight' : 'normal'}`"
                    :style="{ color: word.highlighted ? captionHighlightColorDraft : captionColorDraft }">{{ word.text }} </span>
                </button>
                <button
                  v-else-if="captionEnabledDraft && captionHighlightDraft !== 'none' && captionsCanRender"
                  type="button" :class="['preview-caption', 'preview-caption-animated', 'nx-caption', selection === 'caption' ? 'is-selected' : '']"
                  :style="[captionPositionStyle, captionFontStyle]" aria-label="Select captions" @click="select('caption')">
                  <CaptionPreview :words="captionPreviewTimedWords" :seconds="captionClock" :settings="captionPreviewSettings" />
                </button>
              </div>
            </div>
            <span class="nx-frame-label">Scene {{ activeSceneIndex + 1 }} of {{ scenes.length }}</span>
          </div>

          <!-- Timeline -->
          <section class="nx-timeline" aria-label="Timeline">
            <div class="nx-tl-head">
              <button class="nx-play" type="button" :aria-label="isPreviewPlaying ? 'Pause' : 'Play'" @click="togglePreviewPlay">
                <span v-if="pendingScenePlay" class="nx-spin" aria-label="Loading"></span>
                <svg v-else-if="isPreviewPlaying" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" /></svg>
                <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
              </button>
              <button class="nx-ib" type="button" :disabled="activeSceneIndex <= 0" aria-label="Previous scene" @click="skipToScene(-1)">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
              </button>
              <button class="nx-ib" type="button" :disabled="activeSceneIndex >= scenes.length - 1" aria-label="Next scene" @click="skipToScene(1)">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6" /></svg>
              </button>
              <span class="nx-num">{{ previewTimer.elapsed }} <span class="nx-dim">/ {{ previewTimer.total }}</span></span>
              <span class="nx-grow"></span>
              <div class="nx-seg">
                <button type="button" :class="previewMode === 'scene' ? 'on' : ''" @click="setPreviewMode('scene')">Scene</button>
                <button type="button" :class="previewMode === 'full' ? 'on' : ''" @click="setPreviewMode('full')">Full video</button>
              </div>
            </div>

            <div class="nx-lanes">
              <div class="nx-lane-names">
                <span class="is-tall">Scenes</span><span>Voice</span><span>Captions</span><span>Music</span>
              </div>
              <div class="nx-tracks" @click.self="trackSeek">
                <div class="nx-lane is-tall">
                  <button v-for="(scene, i) in scenes" :key="scene.id" type="button"
                    :class="['nx-clip', scene.id === activeSceneId ? (selection === 'scene' || selection === 'image' ? 'is-selected' : 'is-current') : '']"
                    :style="{ flex: `${sceneDuration(scene)} 1 0` }" :aria-label="`Scene ${i + 1}, ${sceneTypeLabel(scene)}, ${seconds(sceneDuration(scene))}`"
                    @click="select('scene', scene.id)">
                    <img v-if="thumbFor(scene)?.type === 'img'" :src="thumbFor(scene).url" alt="" loading="lazy" />
                    <video v-else-if="thumbFor(scene)?.type === 'video'" :src="`${thumbFor(scene).url}#t=0.5`" muted preload="metadata" playsinline></video>
                    <span class="nx-clip-name">{{ i + 1 }} · {{ sceneTypeLabel(scene) }}</span>
                    <span class="nx-clip-dur">{{ seconds(sceneDuration(scene)) }}</span>
                  </button>
                </div>
                <div class="nx-lane">
                  <button v-for="(scene, i) in scenes" :key="scene.id" type="button"
                    :class="['nx-bar', selection === 'voice' && scene.id === activeSceneId ? 'is-selected' : '', sceneVoiceOutdated(scene) ? 'is-warn' : '']"
                    :style="{ flex: `${sceneDuration(scene)} 1 0` }" :aria-label="`Scene ${i + 1} voice`" @click="select('voice', scene.id)">
                    <svg viewBox="0 0 100 20" preserveAspectRatio="none" aria-hidden="true"><path :d="WAVE" /></svg>
                  </button>
                </div>
                <div class="nx-lane">
                  <button v-for="(scene, i) in scenes" :key="scene.id" type="button"
                    :class="['nx-bar', 'nx-bar-text', selection === 'caption' ? 'is-selected' : '']"
                    :style="{ flex: `${sceneDuration(scene)} 1 0` }" :aria-label="`Scene ${i + 1} caption`" @click="select('caption', scene.id)">{{ scene.script_text }}</button>
                </div>
                <div class="nx-lane">
                  <button type="button" :class="['nx-bar', 'nx-bar-text', 'nx-music', panel === 'audio' ? 'is-selected' : '']" @click="openPanel('audio')">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18V6l10-2v12" /><circle cx="6" cy="18" r="3" /><circle cx="16" cy="16" r="3" /></svg>
                    {{ activeMusicTrack?.title || "No music" }}
                  </button>
                </div>
                <div class="nx-playhead" :style="{ left: `${playheadPct}%` }" aria-hidden="true"></div>
              </div>
              <button class="nx-add-clip" type="button" aria-label="Add a scene" @click="openPanel('script'); startAdd()">+</button>
            </div>
          </section>
        </div>
      </div>
    </div>

    <!-- Delete a scene -->
    <div v-if="deleteSceneTarget" class="nx-modal-bg" @click.self="closeDeleteSceneModal">
      <div class="nx-modal" role="dialog" aria-modal="true" aria-labelledby="nx-del-title">
        <h2 id="nx-del-title">Delete scene {{ scenes.findIndex((s) => s.id === deleteSceneTarget.id) + 1 }}?</h2>
        <p>Its script, picture and voice go with it. Undo brings it back.</p>
        <div class="nx-row nx-end">
          <button class="nx-link" type="button" :disabled="deleteScenePending" @click="closeDeleteSceneModal">Cancel</button>
          <button class="nx-btn nx-danger" type="button" :disabled="deleteScenePending" @click="confirmDeleteScene">{{ deleteScenePending ? "Deleting…" : "Delete scene" }}</button>
        </div>
      </div>
    </div>

    <!-- Send for approval -->
    <div v-if="approvalModalOpen" class="nx-modal-bg" @click.self="closeApprovalModal">
      <div class="nx-modal" role="dialog" aria-modal="true" aria-labelledby="nx-ap-title">
        <h2 id="nx-ap-title">Send for approval</h2>
        <template v-if="!approvalCreated">
          <p>A review link is emailed to your reviewer. They don't need a WyvStudio account.</p>
          <label class="nx-label" for="nx-ap-email">Reviewer email</label>
          <input id="nx-ap-email" v-model="approvalForm.email" type="email" class="nx-input" placeholder="client@example.com" />
          <label class="nx-label" for="nx-ap-name">Reviewer name (optional)</label>
          <input id="nx-ap-name" v-model="approvalForm.name" type="text" class="nx-input" />
          <label class="nx-label" for="nx-ap-msg">Note for them (optional)</label>
          <textarea id="nx-ap-msg" v-model="approvalForm.message" rows="3" class="nx-input"></textarea>
          <span class="nx-label">Expires in</span>
          <UiSelect v-model="approvalForm.expires_in_days" :options="APPROVAL_EXPIRES_OPTIONS" align="left" />
          <p v-if="approvalError" class="nx-err">{{ approvalError }}</p>
          <div class="nx-row nx-end">
            <button class="nx-link" type="button" @click="closeApprovalModal">Cancel</button>
            <button class="nx-btn nx-primary" type="button" :disabled="approvalSubmitting" @click="submitApproval">{{ approvalSubmitting ? "Sending…" : "Send link" }}</button>
          </div>
        </template>
        <template v-else>
          <p v-if="approvalCreated.warning">{{ approvalCreated.warning }}</p>
          <p v-else>Sent to <b>{{ approvalCreated.approval?.reviewer_email }}</b>. You can also share the link yourself:</p>
          <div class="nx-row">
            <input :value="approvalCreated.public_url" class="nx-input" readonly />
            <button class="nx-btn" type="button" @click="copyApprovalUrl">Copy</button>
          </div>
          <div class="nx-row nx-end"><button class="nx-btn nx-primary" type="button" @click="closeApprovalModal">Done</button></div>
        </template>
      </div>
    </div>

    <!-- Leaving while a change could not be saved: the route waits for this answer. -->
    <div v-if="leaveSaveDialog" class="nx-modal-bg" @keydown.esc="chooseLeave(false)">
      <div class="nx-modal" role="dialog" aria-modal="true" aria-labelledby="nx-leave-title">
        <h2 id="nx-leave-title">Some changes could not be saved</h2>
        <p>Stay to try saving again, or leave and lose the unsaved changes.</p>
        <div class="nx-row nx-end">
          <button class="nx-link" type="button" @click="chooseLeave(true)">Leave without saving</button>
          <button class="nx-btn nx-primary" type="button" autofocus @click="chooseLeave(false)">Stay</button>
        </div>
      </div>
    </div>

    <AnimateDialog />
    <VoicePickerDialog />
    <CharacterDialog />
    <CaptionStylesDialog />
    <VoiceCloneModal v-if="showCloneModal" @close="showCloneModal = false" @created="onVoiceCloned" />
    <MediaPickerModal :mode="mediaPickerMode" :visible="mediaPickerVisible" :music-tracks="musicTracks" :selected-music-track-id="selectedMusicTrackId"
      :current-asset-id="mediaPickerMode === 'visual' ? (activeScene?.visual_asset_id ?? null) : null"
      @close="mediaPickerVisible = false" @select="handleMediaPickerSelect" />

    <SchedulePostModal v-if="scheduleModalOpen" :export-job-id="deliveryExport?.id ?? activeExportJob?.id ?? null" @close="scheduleModalOpen = false" @scheduled="onPostScheduled" />

    <div class="nx-toasts">
      <div v-for="toast in notificationToasts" :key="toast.id" class="nx-toast"><b>{{ toast.title }}</b> {{ toast.message }}</div>
    </div>

    <!-- Playback: the same elements and refs the classic editor drives. -->
    <audio ref="audioRef" :src="activeSceneAudioUrl || undefined" :crossorigin="mediaCrossOriginMode(activeSceneAudioUrl)" preload="metadata"
      @loadstart="isAudioLoading = true" @canplay="isAudioLoading = false" @loadeddata="isAudioLoading = false; syncSceneVoiceVolume()"
      @ended="handleSceneAudioPause" @play="handleSceneAudioPlay" @pause="handleSceneAudioPause" @error="isAudioLoading = false"></audio>
    <audio ref="musicAudioRef" :src="activeMusicTrackUrl || undefined" :crossorigin="mediaCrossOriginMode(activeMusicTrackUrl)" preload="metadata" :loop="musicLoop"
      @loadedmetadata="syncPreviewMusicVolume" @loadeddata="musicReady = true" @canplay="musicReady = true" @error="musicReady = true"></audio>
    <audio ref="soundAudioRef" :src="activeSceneSoundUrl || undefined" :crossorigin="mediaCrossOriginMode(activeSceneSoundUrl)" preload="auto" @loadeddata="syncSceneSoundVolume"></audio>
    <audio v-if="auditionMusicTrack?.storage_url" ref="musicAuditionRef" :src="auditionMusicTrack.storage_url" :crossorigin="mediaCrossOriginMode(auditionMusicTrack?.storage_url)"
      preload="metadata" @ended="auditionMusicTrackId = null" @pause="auditionMusicTrackId = null"></audio>
  </main>
  <ExportUpdateModal :warning="exportWarning" @cancel="cancelExportAction" @continue="continueExportAction" @update="updateExportAction" />
</template>

<style src="../editor/next.css"></style>
