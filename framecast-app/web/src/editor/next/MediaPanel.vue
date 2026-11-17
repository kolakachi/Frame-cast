<script setup>
// Media: the scene's picture (classic "Visual Source" + "Motion"). This scene only.
// Tabs (owner, 2026-10-30): Image · Video · Audiogram · Uploads; Image and Video each split into Stock and AI.
// AI video is the AI picture plus animating it, so that is where Animate lives.
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import api from "../../services/api";
import UiSelect from "../../components/UiSelect.vue";
import { cr, useCore, usePrices } from "./core";

const core = useCore();
const {
  activeScene, activeSceneIndex, scenes, project, currentVisualUrl, activeSceneVisualIsVideo,
  selectedSwapVisualSource, stockVideoSubType, STOCK_VIDEO_SUBTYPE_OPTIONS, visualQueryDraft, swapVisual,
  visualSwapPending, visualSwapError,
  visualCandidates, visualCandidatesPending, visualCandidatesError, loadVisualCandidates, pickVisualCandidate,
  characters, activeCharacter, selectCharacter, characterPopoverOpen, createCharacterOpen,
  stylePickerRows, imageStylesByKey, visualStyleDraft, visualStyleSaveState, customVisualStyleDraft, customVisualStyleSaveState,
  availableImageModelsEditor, aiImageModelKey, activeSceneHasCharacter, aiImagePromptOverride,
  generateAIImage, aiImagePending, activeSceneAIImagePending, aiImageError, activeSceneVisualGenerationError,
  editInstruction, EDIT_SUGGESTIONS, editSceneImage, editPending, editError,
  canAnimateActiveScene, activeSceneAlreadyAnimated, activeSceneAnimationPending, activeSceneAnimationError, openAnimateModal,
  cancelAnimation, canRevertAnimation, revertAnimation, spokespersonOutdated, regenerateLipSync, lipSyncRegenerating,
  activeSceneAnimationHistory, activeSceneAnimationOriginal, useOriginalStill, useHistoryAnimation,
  bulkStyleOpen, openBulkStyle, bulkStyleLoading, bulkStyleError, bulkStylePreview, submitBulkStyle, bulkStyleSubmitting,
  activeSceneVisualIsFromLibrary, activeSceneVisualAsset, openMediaPicker,
  AUDIOGRAM_STYLES, AUDIOGRAM_COLORS, AUDIOGRAM_BACKGROUNDS, audiogramStyle, audiogramColor, audiogramBg, audiogramBgStyle,
  selectAudiogramStyle, selectAudiogramColor, selectAudiogramBg, queueAudiogramColorSave, saveAudiogramSettings, audiogramSaveState,
  activeSceneIsStillImage, MOTION_EFFECT_OPTIONS, MOTION_INTENSITY_OPTIONS, motionEffectDraft, motionIntensityDraft,
  motionSaveState, motionSaveError,
  ANIMATE_TIER_META, ANIMATE_TIER_ORDER, ANIMATE_TIER_COSTS_5S, animateTier, animateDuration, animateDurations, animateQuality,
  animateQualityOptions, animateCost, animateMotionPrompt, animateSubmitting, animateError, submitAnimate, clipCheck, makeClipAnyway, creditCosts, tierSlow,
  videoReference, videoReferencePicking, normalizeScenePayload, replaceSceneInCollection,
} = core;
const { imageModelCost, imageCost, imageEditCost, characterPriced } = usePrices(core);

const GROUPS = [
  { key: "image", label: "Image" },
  { key: "video", label: "Video" },
  { key: "audiogram", label: "Audiogram" },
  { key: "uploads", label: "Uploads" },
];
const isAi = computed(() => activeScene.value?.visual_type === "ai_image");
// AI image › Style and Character fold open and shut; closed, they show their pick.
const lookOpen = ref(false);
const charOpen = ref(false);
// The core keeps one source key; "AI Image" serves both AI image and AI video, told apart here.
const aiAsVideo = ref(false);
watch(() => activeScene.value?.id, () => { aiAsVideo.value = isAi.value && activeSceneVisualIsVideo.value; }, { immediate: true });
// Opened for a new AI video scene (AddSceneForm): the AI video tab, with the shot described there.
watch(() => [activeScene.value?.id, core.mediaIntent?.value], () => {
  const intent = core.mediaIntent?.value;
  if (intent?.kind !== "ai_video") return;
  core.mediaIntent.value = null;
  aiAsVideo.value = true;
  selectedSwapVisualSource.value = "AI Image";
  if (intent.shot) animateMotionPrompt.value = intent.shot;
}, { immediate: true });
const group = computed(() => {
  const src = selectedSwapVisualSource.value;
  if (src === "Stock Video") return "video";
  if (src === "Stock Image") return "image";
  if (src === "AI Image") return aiAsVideo.value ? "video" : "image";
  if (src === "Audiogram") return "audiogram";
  return "uploads";
});
const sub = computed(() => (selectedSwapVisualSource.value === "AI Image" ? "ai" : "stock"));
function pick(g, s) {
  if (g === "audiogram") selectedSwapVisualSource.value = "Audiogram";
  else if (g === "uploads") selectedSwapVisualSource.value = "My Assets";
  else {
    aiAsVideo.value = g === "video";
    selectedSwapVisualSource.value = s === "ai" ? "AI Image" : g === "video" ? "Stock Video" : "Stock Image";
  }
}
// Switching between Image and Video keeps Stock or AI; coming from Audiogram or Uploads, AI scenes land on AI.
function pickGroup(g) {
  pick(g, group.value === "image" || group.value === "video" ? sub.value : isAi.value ? "ai" : "stock");
}
const videoAi = computed(() => group.value === "video" && sub.value === "ai");
// ── AI video ────────────────────────────────────────────────────────────────
// Every video model but the talking one (that lip-syncs the scene's voice and stays in the Animate dialog).
const videoTiers = ANIMATE_TIER_ORDER.filter((k) => k !== "spokesperson");
watch(videoAi, (on) => { if (on && animateTier.value === "spokesperson") animateTier.value = "veo_fast"; }, { immediate: true });
// A model's cheapest short clip, as the server prices it.
function tierPrice(key) {
  const served = Object.values(creditCosts.value?.video_tiers?.[key]?.qualities || {});
  return served.length ? Math.min(...served) : ANIMATE_TIER_COSTS_5S[key];
}
const runningTier = computed(() => activeScene.value?.image_generation_settings?.animation_tier ?? null);
const qualityLabel = computed(() => animateQualityOptions.value.find((q) => q.value === animateQuality.value)?.label ?? animateQuality.value);
// The scene's own still: its picture now, or the still an earlier animation started from.
const sceneStillThumb = computed(() => {
  if (activeSceneIsStillImage.value) return currentVisualUrl.value || null;
  const o = activeSceneAnimationOriginal.value;
  return o ? o.thumbnail_url || o.image_url || null : null;
});
const referenceThumb = computed(() => videoReference.value?.thumb ?? sceneStillThumb.value);
const referenceLabel = computed(() => (videoReference.value ? videoReference.value.title : sceneStillThumb.value ? "This scene" : "None"));
const hasReference = computed(() => !!videoReference.value || !!sceneStillThumb.value);

// ── Images (owner, 2026-11-16): none (from words alone), a start frame, a start and an end frame, or reference images
// it follows (a person or look, not a frame). Each mode lists only the models that can do it (ReplicateI2VAdapter).
const IMAGE_MODES = [
  { key: "none", label: "None", hint: "Made from your words alone.",
    tip: "No picture. The video is made from your description alone. Works with Veo and Seedance." },
  { key: "start", label: "Start", hint: "The video begins on this picture.",
    tip: "The video's first frame is this picture, then it moves the way you describe. Use the scene's picture or upload one." },
  { key: "start_end", label: "Start + end", hint: "The video moves from the first picture to the second.",
    tip: "The video begins on the first picture and ends on the second, moving between them. Good for before and after. Works with Kling, Veo and Seedance." },
  { key: "reference", label: "Reference", hint: "Up to 4 pictures of a person, product or look to keep. Not used as frames.",
    tip: "Pictures for the video to stay true to: a person, a product, an outfit or a place. They guide what's in the video but aren't shown as frames. Up to 4; Seedance only." },
];
const TIPS = {
  start: "The first frame of the video. Hover the picture to swap or remove it.",
  end: "The last frame of the video. The model finds the motion between the start and this picture.",
  reference: "Each picture shows the model who or what to keep. Name them in your description if it helps: \"the woman in picture 1 holds the bottle in picture 2\".",
};
const MODE_TIERS = {
  none: ["veo_fast", "seedance_25", "seedance_lite", "seedance_pro"],
  start_end: ["premium", "veo_fast", "seedance_25", "seedance_lite", "seedance_pro"],
  reference: ["seedance_25", "seedance_lite"],
};
const imageMode = ref("start");
watch(() => activeScene.value?.id, () => { imageMode.value = sceneStillThumb.value || videoReference.value ? "start" : "none"; }, { immediate: true });
const videoEnd = core.videoEndRef;
const videoRefs = core.videoRefs;
const modeHint = computed(() => IMAGE_MODES.find((m) => m.key === imageMode.value)?.hint ?? "");
const canMakeVideo = computed(() => {
  if (animateSubmitting.value || activeSceneAnimationPending.value || generating.value) return false;
  if (imageMode.value === "none") return animateMotionPrompt.value.trim().length >= 3;
  if (imageMode.value === "start") return hasReference.value;
  if (imageMode.value === "start_end") return hasReference.value && !!videoEnd.value;
  return videoRefs.value.length > 0;
});
const makeBlocker = computed(() => {
  if (imageMode.value === "none" && animateMotionPrompt.value.trim().length < 3) return "Describe the shot first.";
  if ((imageMode.value === "start" || imageMode.value === "start_end") && !hasReference.value) return "Choose a start picture.";
  if (imageMode.value === "start_end" && !videoEnd.value) return "Choose an end picture.";
  if (imageMode.value === "reference" && !videoRefs.value.length) return "Add at least one picture to follow.";
  return "";
});
// One picker for every slot: the view routes the picked image to the slot asked for (onMediaPicked).
function pickImage(target) {
  menu.value = null;
  core.videoPickTarget.value = target;
  videoReferencePicking.value = true;
  openMediaPicker("visual");
}
const pickReference = () => pickImage("start");
function removeStart() {
  if (videoReference.value) videoReference.value = null;
  else imageMode.value = "none";
}
function makeVideo() {
  menu.value = null;
  const mode = imageMode.value;
  submitAnimate({
    sourceAssetId: mode === "start" || mode === "start_end" ? videoReference.value?.id ?? null : null,
    endAssetId: mode === "start_end" ? videoEnd.value?.id ?? null : null,
    imageMode: mode,
    referenceAssetIds: mode === "reference" ? videoRefs.value.map((r) => r.id) : [],
    check: true,
  });
}

// ── Storyboard first: the shot drawn as three frames (DrawStoryboardJob), then one clip from the first to the last ──
// (owner, 2026-10-31: the storyboard is the scene split in parts, not frames to pick from). Only models that take a
// last frame can make it; Wan 2.5 and Hailuo 2.3 stay with Straight to video.
// The storyboard step left the AI video tab (owner, 2026-11-16: no "How to make it"); its code stays for the classic editor.
const makeMode = ref("direct");
const END_FRAME_TIERS = MODE_TIERS.start_end;
const menuTiers = computed(() => (MODE_TIERS[imageMode.value] ? videoTiers.filter((k) => MODE_TIERS[imageMode.value].includes(k)) : videoTiers));
watch([menuTiers, videoAi], ([tiers, on]) => {
  if (on && tiers.length && !tiers.includes(animateTier.value)) animateTier.value = tiers.includes("veo_fast") ? "veo_fast" : tiers[0];
}, { immediate: true });
// Kling ends on a frame in pro mode only (the server charges pro too).
const qualityChoices = computed(() => (imageMode.value === "start_end" && animateTier.value === "premium"
  ? animateQualityOptions.value.filter((q) => q.value === "pro") : animateQualityOptions.value));
watch([imageMode, animateTier], () => {
  if (imageMode.value === "start_end" && animateTier.value === "premium" && animateQuality.value !== "pro") animateQuality.value = "pro";
});
const FRAME_MODELS = ["nano-banana", "nano-banana-pro", "gpt-image-2"];
const FRAME_LABELS = ["Start", "Middle", "End"];
const frameModel = ref("nano-banana");
const frameModelLabel = (key) => availableImageModelsEditor.value.find((m) => m.key === key)?.label
  ?? { "nano-banana": "Nano Banana", "nano-banana-pro": "Nano Banana Pro", "gpt-image-2": "GPT Image 2" }[key] ?? key;
const storyboardPrice = (key) => creditCosts.value?.storyboard?.[key]?.[hasReference.value ? "reference" : "no_reference"] ?? null;
const storyboard = computed(() => activeScene.value?.image_generation_settings?.storyboard ?? null);
const storyboardFrames = computed(() => storyboard.value?.frames ?? []);
const storyboardReady = computed(() => storyboard.value?.status === "ready" && storyboardFrames.value.length > 0);
const storyboardSubmitting = ref(false);
const storyboardError = ref("");
const canDrawStoryboard = computed(() => animateMotionPrompt.value.trim().length >= 3 && !storyboardSubmitting.value
  && storyboard.value?.status !== "drawing" && !activeSceneAnimationPending.value);
const canMakeFromStoryboard = computed(() => storyboardReady.value && storyboardFrames.value.length === 3
  && storyboardFrames.value.every((f) => f.asset_id) && END_FRAME_TIERS.includes(animateTier.value)
  && !animateSubmitting.value && !activeSceneAnimationPending.value);

async function refreshScene(sceneId) {
  const { data } = await api.get(`/scenes/${sceneId}/preview`);
  const scene = normalizeScenePayload(data?.data?.scene ?? null);
  if (scene) replaceSceneInCollection(scene);
}
async function drawStoryboard() {
  const scene = activeScene.value;
  if (!scene) return;
  menu.value = null;
  storyboardSubmitting.value = true;
  storyboardError.value = "";
  try {
    const { data } = await api.post(`/scenes/${scene.id}/storyboard`, {
      prompt: animateMotionPrompt.value.trim(),
      model: frameModel.value,
      seconds: clipSeconds.value,
      ...(videoReference.value?.id ? { source_asset_id: videoReference.value.id } : {}),
    });
    const updated = normalizeScenePayload(data?.data?.scene ?? null);
    if (updated) replaceSceneInCollection(updated);
  } catch (err) {
    storyboardError.value = err.response?.data?.error?.message ?? "The storyboard could not start.";
  } finally {
    storyboardSubmitting.value = false;
  }
}
// The picked length (each model's own, e.g. Veo 4 or 8 s), so the storyboard plans that much action.
const clipSeconds = computed(() => Number(animateDuration.value) || 5);
// One clip from the first frame to the last. While the description is still the one the storyboard was drawn from,
// the video is also told what happens on the way (StoryboardBeats); otherwise it gets the description as typed.
function makeVideoFromStoryboard() {
  menu.value = null;
  const typed = animateMotionPrompt.value.trim();
  const motion = typed === (storyboard.value?.prompt ?? "").trim() ? storyboard.value?.beats?.motion?.[0] : null;
  submitAnimate({
    sourceAssetId: storyboardFrames.value[0].asset_id,
    endAssetId: storyboardFrames.value[2].asset_id,
    motionPrompt: motion ? `${typed.slice(0, 880 - motion.length)}\n\nOne continuous shot, no cuts, the camera as in the first frame. From the first frame to the last: ${motion}` : null, // the API takes 1000
    check: true,
  });
}
// A frame picture that fails to load (a signed link gone stale, the API restarting) shows as loading, and the scene is
// asked for again a few seconds later for fresh links; up to four tries a frame.
const frameRetry = ref({});
const frameTries = {};
function frameFailed(assetId) {
  const sceneId = activeScene.value?.id;
  if (!assetId || !sceneId || frameRetry.value[assetId] || (frameTries[assetId] = (frameTries[assetId] ?? 0) + 1) > 4) return;
  frameRetry.value = { ...frameRetry.value, [assetId]: true };
  setTimeout(async () => {
    await refreshScene(sceneId).catch(() => {});
    const { [assetId]: _, ...rest } = frameRetry.value;
    frameRetry.value = rest;
  }, 3000 * frameTries[assetId]);
}
// While frames are being drawn, ask for the scene every few seconds; frames loaded without pictures (a project load
// carries ids only) are fetched once.
let storyboardTimer = null;
function pollStoryboard(sceneId, attempt = 0) {
  clearTimeout(storyboardTimer);
  const drawing = storyboard.value?.status === "drawing";
  const missing = storyboardFrames.value.some((f) => f.asset_id && !f.image_url);
  if (!sceneId || !videoAi.value || activeScene.value?.id !== sceneId || attempt > 90 || (!drawing && !missing) || (!drawing && attempt > 2)) return;
  storyboardTimer = setTimeout(async () => {
    await refreshScene(sceneId).catch(() => {});
    pollStoryboard(sceneId, attempt + 1);
  }, drawing ? 4000 : 1500);
}
watch(() => [activeScene.value?.id, storyboard.value?.status, videoAi.value], ([sceneId]) => pollStoryboard(sceneId), { immediate: true });
onBeforeUnmount(() => clearTimeout(storyboardTimer));
const generating = computed(() => aiImagePending.value || activeSceneAIImagePending.value);
// The look the next picture is drawn in: the one picked here, else the one it was last drawn in.
const lookKey = computed(() => visualStyleDraft.value ?? activeScene.value?.visual_style ?? activeScene.value?.image_generation_settings?.style ?? project.value?.ai_broll_style ?? null);
const lastLook = computed(() => activeScene.value?.image_generation_settings?.style ?? activeScene.value?.visual_style ?? project.value?.ai_broll_style ?? null);
const lookLabel = (key) => imageStylesByKey.value.get(key)?.label ?? key ?? "Default";
const lookChanged = computed(() => isAi.value && visualStyleDraft.value && lastLook.value && visualStyleDraft.value !== lastLook.value);
const current = computed(() => {
  const s = activeScene.value;
  if (!s) return "";
  if (s.visual_type === "ai_image") return `${activeSceneVisualIsVideo.value ? "Animated AI image" : "AI image"} · ${lookLabel(lastLook.value)}`;
  if (s.visual_type === "waveform") return "Audiogram";
  if (s.visual_type === "text_card") return "Text card";
  // Stock pulls are saved to the library too, tagged matched_visual; only the user's own uploads are "yours".
  const stock = (s.visual_asset?.tags || []).includes("matched_visual");
  if (activeSceneVisualIsFromLibrary.value && !stock) return `${activeSceneVisualIsVideo.value ? "Video" : "Image"} from your uploads`;
  if (s.visual_type === "background_loop") return "Background loop";
  return activeSceneVisualIsVideo.value ? "Stock video" : "Stock image";
});
const MOTION_SHORT = { zoom_in: "Zoom in", zoom_out: "Zoom out", pan_left: "Pan left", pan_right: "Pan right", pan_up: "Pan up", pan_down: "Pan down", pan_zoom: "Pan + zoom", static: "None" };
const motionOptions = computed(() => [
  ...MOTION_EFFECT_OPTIONS.filter((o) => o.value === "static"),
  ...MOTION_EFFECT_OPTIONS.filter((o) => o.value !== "static"),
]);
const motionSelectOptions = computed(() => motionOptions.value.map((o) => ({ value: o.value, label: MOTION_SHORT[o.value] || o.label })));

// The prompt box: one dropdown open at a time, closed by a click anywhere else.
const menu = ref(null);
const toggleMenu = (name) => { menu.value = menu.value === name ? null : name; };
const closeMenu = () => { menu.value = null; };
onMounted(() => document.addEventListener("click", closeMenu));
onBeforeUnmount(() => document.removeEventListener("click", closeMenu));
const lookRow = computed(() => stylePickerRows.value.find((r) => r.key === lookKey.value) ?? null);
const modelRow = computed(() => availableImageModelsEditor.value.find((m) => m.key === aiImageModelKey.value) ?? null);
// A scene with an AI picture can also be edited in place (the shot kept, one thing changed).
const aiMode = ref("new");
watch(() => activeScene.value?.id, () => { aiMode.value = "new"; menu.value = null; videoReference.value = null; storyboardError.value = ""; });
const canEdit = computed(() => isAi.value && !!activeScene.value?.visual_asset_id);
const editing = computed(() => canEdit.value && aiMode.value === "edit");
const sending = computed(() => (editing.value ? editPending.value : generating.value));
const sendPrice = computed(() => (editing.value ? imageEditCost.value : imageCost.value));
const sendLabel = computed(() => {
  if (sending.value) return editing.value ? "Applying…" : "Drawing…";
  const verb = editing.value ? "Apply edit" : isAi.value ? "Regenerate" : "Generate";
  return `${verb}${cr(sendPrice.value)}`;
});
const needsCustomLook = computed(() => !editing.value && lookKey.value === "custom");
const canSend = computed(() => (editing.value
  ? !editPending.value && !generating.value && editInstruction.value.trim().length >= 3
  : !generating.value && !activeSceneAnimationPending.value));
function send() {
  menu.value = null;
  if (editing.value) editSceneImage();
  else generateAIImage();
}
</script>

<template>
  <div class="nx-ph nx-row">
    <div>
      <h2>Scene {{ activeSceneIndex + 1 }} {{ activeSceneVisualIsVideo ? "video" : "picture" }}</h2>
      <p>{{ current }}</p>
    </div>
    <span class="nx-tag">This scene</span>
  </div>

  <div v-if="currentVisualUrl || generating" class="nx-current">
    <video v-if="currentVisualUrl && activeSceneVisualIsVideo" :src="currentVisualUrl" autoplay loop muted playsinline></video>
    <img v-else-if="currentVisualUrl" :src="currentVisualUrl" alt="" />
    <span v-if="generating" class="nx-current-note">Drawing…</span>
    <span v-else-if="activeSceneAnimationPending" class="nx-current-note">Animating…</span>
  </div>

  <div class="nx-seg nx-seg-full nx-seg-fit" role="tablist" aria-label="What the scene shows">
    <button v-for="g in GROUPS" :key="g.key" type="button" role="tab" :aria-selected="group === g.key"
      :class="group === g.key ? 'on' : ''" @click="pickGroup(g.key)">{{ g.label }}</button>
  </div>
  <div v-if="group === 'image' || group === 'video'" class="nx-subtabs" role="tablist" :aria-label="group === 'video' ? 'Kind of video' : 'Kind of image'">
    <button type="button" role="tab" :aria-selected="sub === 'stock'" :class="sub === 'stock' ? 'on' : ''" @click="pick(group, 'stock')">{{ group === "video" ? "Stock videos" : "Stock images" }}</button>
    <button type="button" role="tab" :aria-selected="sub === 'ai'" :class="sub === 'ai' ? 'on' : ''" @click="pick(group, 'ai')">{{ group === "video" ? "AI video" : "AI image" }}</button>
  </div>

  <!-- Stock video -->
  <template v-if="selectedSwapVisualSource === 'Stock Video'">
    <div class="nx-chips">
      <button v-for="o in STOCK_VIDEO_SUBTYPE_OPTIONS" :key="o.value" type="button" :class="['nx-chip', stockVideoSubType === o.value ? 'on' : '']" @click="stockVideoSubType = o.value">{{ o.value === 'background_loop' ? 'Background loop' : 'Clip' }}</button>
    </div>
    <label class="nx-label" for="nx-q-video">Search for</label>
    <textarea id="nx-q-video" v-model="visualQueryDraft" class="nx-input" rows="2" placeholder="city skyline at sunset. Leave empty to search from the script."></textarea>
    <button class="nx-btn nx-full" type="button" :disabled="visualCandidatesPending || visualSwapPending" @click="loadVisualCandidates(false)">{{ visualCandidatesPending ? "Searching…" : "Show clips" }}</button>
    <div v-if="visualCandidates.length" class="nx-cands" role="list" aria-label="Clips to choose from">
      <button v-for="c in visualCandidates" :key="c.id" type="button" class="nx-cand" role="listitem" :disabled="visualSwapPending"
        :aria-label="`Use this clip${c.duration_seconds ? `, ${Math.round(c.duration_seconds)} seconds` : ''}`"
        @mouseenter="($event.currentTarget.querySelector('video') || {}).play?.()?.catch?.(() => {})"
        @mouseleave="(v => v && (v.pause(), v.currentTime = 0))($event.currentTarget.querySelector('video'))"
        @click="pickVisualCandidate(c.index)">
        <img :src="c.thumbnail_url" alt="" loading="lazy" />
        <video v-if="c.asset_type === 'video'" :src="c.preview_url" muted loop playsinline preload="none"></video>
        <span v-if="c.duration_seconds" class="nx-cand-dur">{{ Math.round(c.duration_seconds) }}s</span>
      </button>
    </div>
    <button v-if="visualCandidates.length" class="nx-link" type="button" :disabled="visualCandidatesPending" @click="loadVisualCandidates(true)">Show other clips</button>
    <p class="nx-hint">{{ visualCandidates.length ? (visualSwapPending ? "Putting it in…" : "Pick one to use it in this scene. Undo brings the old one back.") : "Stock footage is free." }}</p>
    <p v-if="visualCandidatesError" class="nx-err">{{ visualCandidatesError }}</p>
    <p v-if="visualSwapError" class="nx-err">{{ visualSwapError }}</p>
  </template>

  <!-- Stock image -->
  <template v-else-if="selectedSwapVisualSource === 'Stock Image'">
    <label class="nx-label" for="nx-q-image">Search for</label>
    <textarea id="nx-q-image" v-model="visualQueryDraft" class="nx-input" rows="2" placeholder="dark forest with fog. Leave empty to search from the script."></textarea>
    <button class="nx-btn nx-full" type="button" :disabled="visualCandidatesPending || visualSwapPending" @click="loadVisualCandidates(false)">{{ visualCandidatesPending ? "Searching…" : "Show images" }}</button>
    <div v-if="visualCandidates.length" class="nx-cands" role="list" aria-label="Images to choose from">
      <button v-for="c in visualCandidates" :key="c.id" type="button" class="nx-cand" role="listitem" :disabled="visualSwapPending" aria-label="Use this image" @click="pickVisualCandidate(c.index)">
        <img :src="c.thumbnail_url" alt="" loading="lazy" />
      </button>
    </div>
    <button v-if="visualCandidates.length" class="nx-link" type="button" :disabled="visualCandidatesPending" @click="loadVisualCandidates(true)">Show other images</button>
    <p class="nx-hint">{{ visualCandidates.length ? (visualSwapPending ? "Putting it in…" : "Pick one to use it in this scene. Undo brings the old one back.") : "Stock photos are free. To bring one to life, use Video › AI video." }}</p>
    <p v-if="visualCandidatesError" class="nx-err">{{ visualCandidatesError }}</p>
    <p v-if="visualSwapError" class="nx-err">{{ visualSwapError }}</p>
  </template>

  <!-- AI video (owner, 2026-10-30): describe the shot, a reference image, any video model, a length; then either a
       3-frame storyboard to pick from first, or straight to video. -->
  <template v-else-if="selectedSwapVisualSource === 'AI Image' && videoAi">
    <div v-if="activeSceneAnimationPending" class="nx-quote">
      <div class="nx-row"><b>Animating with {{ ANIMATE_TIER_META[runningTier]?.name || "your model" }}</b><button class="nx-link" type="button" @click="cancelAnimation">Cancel</button></div>
      <p class="nx-hint">You can keep editing other scenes. The clip takes this scene's place when it's done.</p>
    </div>

    <span class="nx-label">Describe the shot</span>
    <div class="nx-pbox">
      <textarea v-model="animateMotionPrompt" class="nx-pbox-input" rows="3" maxlength="1000"
        aria-label="Describe the shot" placeholder="How it moves: slow push-in, steam rising, she looks up and smiles"></textarea>
    </div>

    <!-- Images (owner, 2026-11-16): none, a start frame, start + end, or references to follow. -->
    <span class="nx-label">Images</span>
    <div class="nx-seg nx-seg-grid" role="radiogroup" aria-label="How the video uses images">
      <button v-for="(m, i) in IMAGE_MODES" :key="m.key" type="button" role="radio" :aria-checked="imageMode === m.key" :aria-description="m.tip"
        :class="['nx-tipped', i % 2 ? 'tip-right' : 'tip-left', imageMode === m.key ? 'on' : '']" :data-tip="m.tip" @click="imageMode = m.key">{{ m.label }}</button>
    </div>
    <p class="nx-hint">{{ modeHint }}</p>

    <div v-if="imageMode === 'start' || imageMode === 'start_end'" :class="['nx-frames', imageMode === 'start_end' ? 'is-pair' : '']">
      <div class="nx-slot">
        <span class="nx-slot-k">Start <span class="nx-info nx-tipped tip-left" tabindex="0" role="note" :data-tip="TIPS.start" :aria-label="TIPS.start">i</span></span>
        <div class="nx-slot-box">
          <button type="button" class="nx-slot-img" :aria-label="referenceThumb ? 'Swap the start picture' : 'Choose a start picture'" @click="pickImage('start')">
            <img v-if="referenceThumb" :src="referenceThumb" alt="" />
            <span v-else class="nx-slot-add">+</span>
          </button>
          <!-- On hover: swap it, or remove it (an upload goes back to the scene's picture; the scene's own leaves no image). -->
          <div v-if="referenceThumb" class="nx-slot-acts">
            <button type="button" @click="pickImage('start')">Swap</button>
            <button type="button" @click="removeStart">Remove</button>
          </div>
        </div>
        <span class="nx-slot-name">{{ videoReference ? videoReference.title : sceneStillThumb ? "This scene's picture" : "Choose a picture" }}</span>
      </div>
      <template v-if="imageMode === 'start_end'">
        <span class="nx-frames-arrow" aria-hidden="true">→</span>
        <div class="nx-slot">
          <span class="nx-slot-k">End <span class="nx-info nx-tipped tip-right" tabindex="0" role="note" :data-tip="TIPS.end" :aria-label="TIPS.end">i</span></span>
          <div class="nx-slot-box">
            <button type="button" class="nx-slot-img" :aria-label="videoEnd ? 'Swap the end picture' : 'Choose an end picture'" @click="pickImage('end')">
              <img v-if="videoEnd" :src="videoEnd.thumb" alt="" />
              <span v-else class="nx-slot-add">+</span>
            </button>
            <div v-if="videoEnd" class="nx-slot-acts">
              <button type="button" @click="pickImage('end')">Swap</button>
              <button type="button" @click="videoEnd = null">Remove</button>
            </div>
          </div>
          <span class="nx-slot-name">{{ videoEnd ? videoEnd.title : "Choose a picture" }}</span>
        </div>
      </template>
    </div>

    <span v-if="imageMode === 'reference'" class="nx-slot-k">Pictures to follow <span class="nx-info nx-tipped tip-left" tabindex="0" role="note" :data-tip="TIPS.reference" :aria-label="TIPS.reference">i</span></span>
    <div v-if="imageMode === 'reference'" class="nx-refs">
      <div v-for="r in videoRefs" :key="r.id" class="nx-ref nx-slot-box">
        <img :src="r.thumb" :alt="r.title" />
        <div class="nx-slot-acts">
          <button type="button" :aria-label="`Swap ${r.title}`" @click="pickImage(`ref:${r.id}`)">Swap</button>
          <button type="button" :aria-label="`Remove ${r.title}`" @click="videoRefs = videoRefs.filter((x) => x.id !== r.id)">Remove</button>
        </div>
      </div>
      <button v-if="videoRefs.length < 4" type="button" class="nx-ref nx-ref-add" aria-label="Add a picture to follow" @click="pickImage('ref')">+</button>
    </div>

    <!-- Model and length, each with the server's price. -->
    <span class="nx-label">Model</span>
    <div class="nx-pop-wrap" @click.stop>
      <button type="button" class="nx-list-row nx-pick nx-model-pick" :aria-expanded="menu === 'vmodel'" @click="toggleMenu('vmodel')">
        <span class="nx-list-main"><b>{{ ANIMATE_TIER_META[animateTier]?.name || "Model" }}</b><span>{{ ANIMATE_TIER_META[animateTier]?.sub }}</span></span>
        <span class="nx-num">from {{ tierPrice(animateTier) }} cr</span>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
      </button>
      <div v-if="menu === 'vmodel'" class="nx-pop">
        <div class="nx-list">
          <button v-for="key in menuTiers" :key="key" type="button" :class="['nx-list-row', animateTier === key ? 'on' : '']" @click="animateTier = key; menu = null">
            <span class="nx-list-main">
              <b>{{ ANIMATE_TIER_META[key].name }}</b>
              <span>{{ ANIMATE_TIER_META[key].sub }} · {{ ANIMATE_TIER_META[key].render }}<template v-if="tierSlow(key)"> · <span class="nx-warn-text">slow now</span></template></span>
            </span>
            <span class="nx-num">from {{ tierPrice(key) }} cr</span>
          </button>
        </div>
        <p v-if="imageMode !== 'start'" class="nx-hint">Only the models that can {{ imageMode === "none" ? "work from words alone" : imageMode === "reference" ? "follow reference pictures" : "end on a chosen picture" }} are listed.</p>
      </div>
    </div>
    <span class="nx-label">Length and quality</span>
    <div class="nx-row nx-lenq">
      <div class="nx-seg" role="group" aria-label="Length">
        <button v-for="d in animateDurations" :key="d" type="button" :class="animateDuration === d ? 'on' : ''" @click="animateDuration = d">{{ d }} s</button>
      </div>
      <div class="nx-seg" role="group" aria-label="Quality">
        <button v-for="q in qualityChoices" :key="q.value" type="button" :class="animateQuality === q.value ? 'on' : ''" @click="animateQuality = q.value">{{ q.label }}</button>
      </div>
    </div>

    <template v-if="!activeSceneAnimationPending">
      <button class="nx-btn nx-primary nx-full" type="button" :disabled="!canMakeVideo" @click="makeVideo">
        {{ animateSubmitting ? "Starting…" : `Make the video${cr(animateCost)}` }}
      </button>
      <p class="nx-hint">{{ makeBlocker || `About ${(ANIMATE_TIER_META[animateTier]?.render || "a few minutes").replace("~", "")}. The clip replaces this scene's picture; the picture stays in Versions. A 10 second clip costs twice as much.` }}</p>
    </template>
    <!-- The look before a paid clip (ClipPrecheck): frame problems are shown before anything is charged. -->
    <div v-if="clipCheck && !clipCheck.note" class="nx-quote nx-quote-warn" role="alert">
      <b>Before you spend credits, this may not come out right:</b>
      <ul class="nx-issues"><li v-for="(issue, i) in clipCheck.frame_issues" :key="i">{{ issue }}</li></ul>
      <div class="nx-row nx-row-start">
        <button v-if="makeMode === 'storyboard'" class="nx-btn" type="button" :disabled="!canDrawStoryboard" @click="clipCheck = null; drawStoryboard()">Redraw the storyboard</button>
        <button v-else class="nx-btn" type="button" @click="clipCheck = null; pickReference()">Choose another picture</button>
        <button class="nx-btn nx-primary" type="button" :disabled="animateSubmitting" @click="makeClipAnyway">{{ `Make it anyway${cr(animateCost)}` }}</button>
      </div>
    </div>
    <p v-else-if="clipCheck?.note" class="nx-hint">We adjusted the description so the video model handles it better: {{ clipCheck.prompt_issues.join(" ") }}</p>
    <p v-if="animateError" class="nx-err">{{ animateError }}</p>

    <div v-if="spokespersonOutdated && !activeSceneAnimationPending" class="nx-callout">
      <span>The voice changed, so the lips no longer match.</span>
      <button class="nx-mini" type="button" :disabled="lipSyncRegenerating || aiImagePending" @click="regenerateLipSync">{{ lipSyncRegenerating ? "Syncing…" : "Sync the lips again" }}</button>
    </div>
    <section v-if="activeSceneAnimationHistory.length" class="nx-sec">
      <div class="nx-row">
        <span class="nx-label">Versions</span>
        <button v-if="canRevertAnimation && !activeSceneAnimationPending" class="nx-link" type="button" title="Back to the still picture" @click="revertAnimation">Use the still</button>
      </div>
      <div class="nx-versions">
        <button v-if="activeSceneAnimationOriginal" type="button" :class="['nx-version', activeScene?.visual_asset_id === activeSceneAnimationOriginal.asset_id ? 'on' : '']"
          aria-label="The still picture" @click="useOriginalStill">
          <img v-if="activeSceneAnimationOriginal.thumbnail_url || activeSceneAnimationOriginal.image_url" :src="activeSceneAnimationOriginal.thumbnail_url || activeSceneAnimationOriginal.image_url" alt="" />
          <span>Still</span>
        </button>
        <button v-for="h in activeSceneAnimationHistory" :key="h.asset_id" type="button" :class="['nx-version', activeScene?.visual_asset_id === h.asset_id ? 'on' : '']"
          :aria-label="`Clip, ${h.tier}, ${h.duration} seconds`" :title="h.motion_prompt || ''" @click="useHistoryAnimation(h.asset_id)">
          <video v-if="h.video_url" :src="h.video_url" muted playsinline preload="metadata"></video>
          <span>{{ h.duration }}s</span>
        </button>
      </div>
    </section>
  </template>

  <!-- AI image: one prompt box draws the picture; style, character and model are dropdowns under it (owner,
       2026-10-30). A scene with an AI picture can switch the box to editing it. -->
  <template v-else-if="selectedSwapVisualSource === 'AI Image'">
    <div v-if="canEdit" class="nx-seg nx-seg-full" role="group" aria-label="New picture or edit this one">
      <button type="button" :class="!editing ? 'on' : ''" :aria-pressed="!editing" @click="aiMode = 'new'">New picture</button>
      <button type="button" :class="editing ? 'on' : ''" :aria-pressed="editing" @click="aiMode = 'edit'">Edit this one</button>
    </div>

    <div v-if="editing" class="nx-chips">
      <button v-for="sg in EDIT_SUGGESTIONS" :key="sg" type="button" :class="['nx-chip', editInstruction === sg ? 'on' : '']" @click="editInstruction = sg">{{ sg }}</button>
    </div>

    <!-- The prompt on its own; the picture's settings below it as visual pickers (owner, 2026-11-16). -->
    <div class="nx-pbox">
      <textarea v-if="editing" v-model="editInstruction" class="nx-pbox-input" rows="3" maxlength="500"
        aria-label="What to change" placeholder="What to change: remove the text on the wall behind her"></textarea>
      <textarea v-else v-model="aiImagePromptOverride" class="nx-pbox-input" rows="3" maxlength="1000"
        aria-label="Describe the picture" placeholder="Describe the picture, or leave it empty to draw from the scene's script"></textarea>
    </div>

    <template v-if="!editing">
      <!-- Style and Character fold: closed, each shows its pick as an avatar and name (owner, 2026-11-16). -->
      <div class="nx-fold">
        <button type="button" class="nx-fold-head" :aria-expanded="lookOpen" @click="lookOpen = !lookOpen">
          <span class="nx-label">Style</span>
          <span class="nx-fold-pick">
            <span class="nx-fold-av"><img v-if="lookRow?.sample_url" :src="lookRow.sample_url" alt="" /><template v-else>✦</template></span>
            {{ lookLabel(lookKey) }}
          </span>
          <svg :class="['nx-fold-chev', lookOpen ? 'is-open' : '']" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <div v-if="lookOpen" class="nx-fold-body">
          <div class="nx-looks nx-looks-grid">
            <button v-for="st in stylePickerRows" :key="st.key" type="button" :class="['nx-look', lookKey === st.key ? 'on' : '']" :title="st.description || st.label" :aria-pressed="lookKey === st.key" @click="visualStyleDraft = st.key; lookOpen = false">
              <img v-if="st.sample_url" :src="st.sample_url" alt="" loading="lazy" />
              <span v-else class="nx-look-glyph">{{ st.icon ?? "✦" }}</span>
              <span class="nx-look-name">{{ st.label }}</span>
            </button>
          </div>
          <button v-if="isAi" class="nx-link nx-more" type="button" @click="lookOpen = false; openBulkStyle()">Use this style on all scenes</button>
        </div>
      </div>

      <div class="nx-fold">
        <button type="button" class="nx-fold-head" :aria-expanded="charOpen" @click="charOpen = !charOpen">
          <span class="nx-label">Character</span>
          <span class="nx-fold-pick">
            <span class="nx-fold-av is-round">
              <img v-if="activeCharacter?.reference_asset?.thumbnail_url || activeCharacter?.reference_asset?.storage_url" :src="activeCharacter.reference_asset.thumbnail_url || activeCharacter.reference_asset.storage_url" alt="" />
              <template v-else>{{ activeCharacter ? activeCharacter.name.charAt(0).toUpperCase() : "–" }}</template>
            </span>
            {{ activeCharacter?.name || "None" }}
          </span>
          <svg :class="['nx-fold-chev', charOpen ? 'is-open' : '']" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <div v-if="charOpen" class="nx-fold-body">
          <div class="nx-faces nx-faces-wrap">
            <button type="button" :class="['nx-face', !activeScene?.character_id ? 'on' : '']" :aria-pressed="!activeScene?.character_id" @click="selectCharacter(null); charOpen = false">
              <span class="nx-face-img">–</span><span>None</span>
            </button>
            <button v-for="c in characters" :key="c.id" type="button" :class="['nx-face', activeScene?.character_id === c.id ? 'on' : '']" :aria-pressed="activeScene?.character_id === c.id" @click="selectCharacter(c.id); charOpen = false">
              <span class="nx-face-img">
                <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url" :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
                <template v-else>{{ c.name.charAt(0).toUpperCase() }}</template>
              </span>
              <span>{{ c.name }}</span>
            </button>
            <button type="button" class="nx-face" @click="charOpen = false; createCharacterOpen = true"><span class="nx-face-img">+</span><span>New</span></button>
          </div>
        </div>
      </div>

      <!-- Model, with the server's price for each. -->
      <span class="nx-label">Model</span>
      <div class="nx-pop-wrap" @click.stop>
        <button type="button" class="nx-list-row nx-pick nx-model-pick" :aria-expanded="menu === 'model'" @click="toggleMenu('model')">
          <span class="nx-list-main"><b>{{ modelRow?.label || "Default" }}</b><span v-if="modelRow?.sub">{{ modelRow.sub }}</span></span>
          <span class="nx-num">{{ imageModelCost(aiImageModelKey) ?? "–" }} cr</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <div v-if="menu === 'model'" class="nx-pop">
          <div class="nx-list">
            <button v-for="m in availableImageModelsEditor" :key="m.key" type="button"
              :class="['nx-list-row', aiImageModelKey === m.key ? 'on' : '']"
              :disabled="m.requires_reference && !activeSceneHasCharacter"
              @click="aiImageModelKey = m.key; menu = null">
              <span class="nx-list-main"><b>{{ m.label }}</b><span>{{ m.sub }}{{ m.requires_reference && !activeSceneHasCharacter ? " · needs a character" : "" }}</span></span>
              <span class="nx-num">{{ imageModelCost(m.key) ?? "–" }} cr</span>
            </button>
          </div>
          <p v-if="characterPriced" class="nx-hint">With a character photo every model costs the same.</p>
        </div>
      </div>
    </template>

    <!-- Custom look, between the box and the button. Optional: the prompt can carry the look too (owner, 2026-10-30). -->
    <template v-if="needsCustomLook">
      <label class="nx-label" for="nx-custom-look">Describe the look <span class="nx-dim">optional{{ customVisualStyleSaveState === 'pending' ? ' · saving…' : customVisualStyleSaveState === 'saved' ? ' · saved' : '' }}</span></label>
      <textarea id="nx-custom-look" v-model="customVisualStyleDraft" class="nx-input" rows="2" maxlength="500" placeholder="moody film stills, neon alleys, 35mm grain. Or put it in the prompt above."></textarea>
    </template>
    <button class="nx-btn nx-primary nx-full nx-cta" type="button" :disabled="!canSend" @click="send">{{ sendLabel }}</button>
    <p v-if="generating" class="nx-hint">About 15 seconds; up to 3 minutes with a character.</p>
    <p v-if="lookChanged && !editing" class="nx-hint nx-hint-warn">Look changed. Draw again to use it.</p>
    <p v-if="aiImageError" class="nx-err">{{ aiImageError }}</p>
    <p v-if="editing && editError" class="nx-err">{{ editError }}</p>

    <div v-if="bulkStyleOpen" class="nx-quote">
      <div class="nx-row"><b>{{ lookLabel(lookKey) }} on all scenes</b><button class="nx-link" type="button" @click="bulkStyleOpen = false">Close</button></div>
      <p v-if="bulkStyleLoading" class="nx-hint">Working out the price…</p>
      <p v-else-if="bulkStyleError" class="nx-err">{{ bulkStyleError }}</p>
      <template v-else-if="bulkStylePreview">
        <p v-if="!bulkStylePreview.eligible_count" class="nx-hint">Every scene is already being drawn.</p>
        <template v-else>
          <div class="nx-row"><span>{{ bulkStylePreview.eligible_count }} scene{{ bulkStylePreview.eligible_count === 1 ? "" : "s" }}</span><b :class="{ 'nx-short': !bulkStylePreview.affordable }">{{ bulkStylePreview.total_cost }} credits</b></div>
          <div class="nx-row nx-dim"><span>Your balance</span><span>{{ bulkStylePreview.balance }} credits</span></div>
          <p class="nx-hint">Each scene is redrawn from its own description; only the look changes.<template v-if="bulkStylePreview.character_count"> Scenes with a character cost more.</template></p>
          <p v-if="!bulkStylePreview.affordable" class="nx-err">You're {{ bulkStylePreview.shortage }} credits short.</p>
          <ul v-if="bulkStylePreview.skipped.length" class="nx-skips">
            <li v-for="sk in bulkStylePreview.skipped" :key="sk.scene_id">Scene {{ sk.order }} skipped: {{ sk.reason }}</li>
          </ul>
          <button class="nx-btn nx-primary nx-full nx-cta" type="button" :disabled="bulkStyleSubmitting || !bulkStylePreview.affordable" @click="submitBulkStyle">
            {{ bulkStyleSubmitting ? "Starting…" : `Redraw ${bulkStylePreview.eligible_count} scenes · ${bulkStylePreview.total_cost} cr` }}
          </button>
        </template>
      </template>
    </div>
  </template>

  <!-- Uploads -->
  <template v-else-if="selectedSwapVisualSource === 'My Assets'">
    <template v-if="activeSceneVisualIsFromLibrary">
      <p class="nx-hint">{{ activeSceneVisualAsset?.title || "From your uploads" }}</p>
      <button class="nx-btn nx-full" type="button" :disabled="visualSwapPending" @click="openMediaPicker('visual')">Choose another</button>
      <button v-if="canAnimateActiveScene && !activeSceneAnimationPending && !activeSceneVisualIsVideo" class="nx-btn nx-outline nx-full" type="button" @click="openAnimateModal">
        {{ activeSceneAlreadyAnimated ? "Animate again" : "Animate this image" }}
      </button>
    </template>
    <template v-else>
      <p class="nx-hint">Use an image or video you've uploaded to your workspace.</p>
      <button class="nx-btn nx-full" type="button" @click="openMediaPicker('visual')">Open your uploads</button>
    </template>
    <p v-if="visualSwapError" class="nx-err">{{ visualSwapError }}</p>
  </template>

  <!-- Audiogram -->
  <template v-else-if="selectedSwapVisualSource === 'Audiogram'">
    <div class="nx-row"><span class="nx-label">Design</span><span v-if="audiogramSaveState === 'saved'" class="nx-num">Saved</span></div>
    <div class="nx-ag-grid">
      <button v-for="s in AUDIOGRAM_STYLES" :key="s.key" type="button" :class="['nx-ag', audiogramStyle === s.key ? 'on' : '']" @click="selectAudiogramStyle(s.key)">
        <span class="nx-ag-mini" :style="audiogramBgStyle">
          <template v-if="s.key === 'circle'">
            <svg viewBox="0 0 40 40" aria-hidden="true"><g transform="translate(20,20)"><line v-for="(h, i) in [0.5,0.8,0.6,0.9,0.55,0.75,0.65,0.85]" :key="i" :transform="`rotate(${i * 45})`" x1="0" y1="7" x2="0" :y2="7 + h * 10" :stroke="audiogramColor" stroke-width="2.5" /></g></svg>
          </template>
          <template v-else>
            <i v-for="(h, i) in [0.4,0.7,0.55,0.9,0.6,0.8,0.45]" :key="i" :class="s.key === 'mirror' ? 'is-mirror' : s.key === 'minimal' ? 'is-thin' : ''" :style="{ height: `${h * 100}%`, background: audiogramColor }"></i>
          </template>
        </span>
        <span>{{ s.label }}</span>
      </button>
    </div>
    <span class="nx-label">Color</span>
    <div class="nx-swatches">
      <button v-for="c in AUDIOGRAM_COLORS" :key="c" type="button" :class="['nx-swatch', audiogramColor === c ? 'on' : '']" :style="{ background: c }" :aria-label="`Color ${c}`" @click="selectAudiogramColor(c)"></button>
      <label class="nx-swatch nx-swatch-custom" aria-label="Your own color"><input type="color" :value="audiogramColor" @input="(e) => { audiogramColor = e.target.value; queueAudiogramColorSave(); }" />+</label>
    </div>
    <span class="nx-label">Background</span>
    <div class="nx-chips">
      <button v-for="bg in AUDIOGRAM_BACKGROUNDS" :key="bg.key" type="button" :class="['nx-chip', audiogramBg === bg.key ? 'on' : '']" @click="selectAudiogramBg(bg.key)">{{ bg.label }}</button>
    </div>
    <button v-if="activeScene?.visual_type !== 'waveform'" class="nx-btn nx-primary nx-full nx-cta" type="button" @click="saveAudiogramSettings({ apply: true })">Use an audiogram on this scene</button>
    <p v-else class="nx-hint">This scene shows an audiogram.</p>
  </template>

  <!-- Motion, for still pictures: the Ken Burns move rendered on export. Shown with the picture tabs only, not the
       video or audiogram tabs (owner, 2026-10-31). -->
  <!-- Motion moves a still picture (Ken Burns): only for a scene whose picture is a still image, on its Image or
       Uploads tab; a video already moves, and an audiogram or text card has no picture to move. -->
  <section v-if="activeSceneIsStillImage && group !== 'video' && group !== 'audiogram'" class="nx-sec nx-sec-top">
    <div class="nx-row nx-motion-row">
      <span class="nx-label" title="Slow camera movement over the still picture">Motion</span>
      <UiSelect v-model="motionEffectDraft" :options="motionSelectOptions" label="Motion" />
    </div>
    <div v-if="motionEffectDraft !== 'static'" class="nx-seg nx-seg-full" role="group" aria-label="Motion strength">
      <button v-for="o in MOTION_INTENSITY_OPTIONS" :key="o.value" type="button" :class="motionIntensityDraft === o.value ? 'on' : ''" @click="motionIntensityDraft = o.value">{{ o.label }}</button>
    </div>
    <p v-if="motionSaveState === 'error'" class="nx-err">{{ motionSaveError }}</p>
  </section>
</template>
