<script setup>
// Media: the scene's picture (classic "Visual Source" + "Motion"). This scene only.
// Tabs (owner, 2026-10-30): Image · Video · Audiogram · Uploads; Image and Video each split into Stock and AI.
// AI video is the AI picture plus animating it, so that is where Animate lives.
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { cr, useCore, usePrices } from "./core";

const core = useCore();
const {
  activeScene, activeSceneIndex, scenes, project, currentVisualUrl, activeSceneVisualIsVideo,
  selectedSwapVisualSource, stockVideoSubType, STOCK_VIDEO_SUBTYPE_OPTIONS, visualQueryDraft, swapVisual,
  visualSwapPending, visualSwapError,
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
  animateQualityOptions, animateCost, animateMotionPrompt, animateSubmitting, animateError, submitAnimate, creditCosts, tierSlow,
  videoReference, videoReferencePicking,
} = core;
const { imageModelCost, imageCost, imageEditCost, characterPriced } = usePrices(core);

const GROUPS = [
  { key: "image", label: "Image" },
  { key: "video", label: "Video" },
  { key: "audiogram", label: "Audiogram" },
  { key: "uploads", label: "Uploads" },
];
const isAi = computed(() => activeScene.value?.visual_type === "ai_image");
// The core keeps one source key; "AI Image" serves both AI image and AI video, told apart here.
const aiAsVideo = ref(false);
watch(() => activeScene.value?.id, () => { aiAsVideo.value = isAi.value && activeSceneVisualIsVideo.value; }, { immediate: true });
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
const canMakeVideo = computed(() => hasReference.value && !animateSubmitting.value && !activeSceneAnimationPending.value && !generating.value);
function pickReference() {
  menu.value = null;
  videoReferencePicking.value = true;
  openMediaPicker("visual");
}
function makeVideo() {
  menu.value = null;
  submitAnimate({ sourceAssetId: videoReference.value?.id ?? null });
}
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
watch(() => activeScene.value?.id, () => { aiMode.value = "new"; menu.value = null; videoReference.value = null; });
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
      <h2>Scene {{ activeSceneIndex + 1 }} picture</h2>
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
    <button class="nx-btn nx-full" type="button" :disabled="visualSwapPending" @click="swapVisual">{{ visualSwapPending ? "Finding…" : "Find a new clip" }}</button>
    <p class="nx-hint">Stock footage is free.</p>
    <p v-if="visualSwapError" class="nx-err">{{ visualSwapError }}</p>
  </template>

  <!-- Stock image -->
  <template v-else-if="selectedSwapVisualSource === 'Stock Image'">
    <label class="nx-label" for="nx-q-image">Search for</label>
    <textarea id="nx-q-image" v-model="visualQueryDraft" class="nx-input" rows="2" placeholder="dark forest with fog. Leave empty to search from the script."></textarea>
    <button class="nx-btn nx-full" type="button" :disabled="visualSwapPending" @click="swapVisual">{{ visualSwapPending ? "Finding…" : "Find a new image" }}</button>
    <p class="nx-hint">Stock photos are free. To bring one to life, use Video › AI video.</p>
    <p v-if="visualSwapError" class="nx-err">{{ visualSwapError }}</p>
  </template>

  <!-- AI video (owner, 2026-10-30): describe the shot, start from a reference image, any video model, a length.
       This push is "straight to video"; storyboard-first and no-reference come next. -->
  <template v-else-if="selectedSwapVisualSource === 'AI Image' && videoAi">
    <div v-if="activeSceneAnimationPending" class="nx-quote">
      <div class="nx-row"><b>Animating with {{ ANIMATE_TIER_META[runningTier]?.name || "your model" }}</b><button class="nx-link" type="button" @click="cancelAnimation">Cancel</button></div>
      <p class="nx-hint">You can keep editing other scenes. The clip takes this scene's place when it's done.</p>
    </div>

    <span class="nx-label">Describe the shot</span>
    <div class="nx-pbox" @click.stop>
      <textarea v-model="animateMotionPrompt" class="nx-pbox-input" rows="3" maxlength="1000"
        aria-label="Describe the shot" placeholder="How it moves: slow push-in, steam rising, she looks up and smiles"></textarea>
      <div class="nx-pbox-bar">
        <button type="button" class="nx-pdrop" :aria-expanded="menu === 'vref'" @click="toggleMenu('vref')">
          <span class="nx-pdrop-k">Reference</span>
          <img v-if="referenceThumb" :src="referenceThumb" alt="" />
          <span>{{ referenceLabel }}</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <button type="button" class="nx-pdrop" :aria-expanded="menu === 'vmodel'" @click="toggleMenu('vmodel')">
          <span class="nx-pdrop-k">Model</span>
          <span>{{ ANIMATE_TIER_META[animateTier]?.name || "Model" }}</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <button type="button" class="nx-pdrop" :aria-expanded="menu === 'vlen'" @click="toggleMenu('vlen')">
          <span class="nx-pdrop-k">Length</span>
          <span>{{ animateDuration }} s · {{ qualityLabel }}</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
      </div>

      <!-- Reference: the scene's own picture, or any image from the workspace (the picker also uploads). -->
      <div v-if="menu === 'vref'" class="nx-pop">
        <div class="nx-list">
          <button v-if="sceneStillThumb" type="button" :class="['nx-list-row', !videoReference ? 'on' : '']" @click="videoReference = null; menu = null">
            <img class="nx-ref-thumb" :src="sceneStillThumb" alt="" />
            <span class="nx-list-main"><b>This scene's picture</b><span>The video starts from it</span></span>
          </button>
          <button v-if="videoReference" type="button" class="nx-list-row on" @click="menu = null">
            <img class="nx-ref-thumb" :src="videoReference.thumb" alt="" />
            <span class="nx-list-main"><b>{{ videoReference.title }}</b><span>From your uploads</span></span>
          </button>
          <button type="button" class="nx-list-row" @click="pickReference">
            <span class="nx-ref-thumb nx-ref-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 16v4h16v-4" /></svg></span>
            <span class="nx-list-main"><b>{{ videoReference ? "Choose another image" : "Upload or choose an image" }}</b><span>From your uploads or your computer</span></span>
          </button>
        </div>
      </div>

      <!-- Model: every video model, with the server's price for its cheapest short clip. -->
      <div v-else-if="menu === 'vmodel'" class="nx-pop">
        <div class="nx-list">
          <button v-for="key in videoTiers" :key="key" type="button" :class="['nx-list-row', animateTier === key ? 'on' : '']" @click="animateTier = key; menu = null">
            <span class="nx-list-main">
              <b>{{ ANIMATE_TIER_META[key].name }}</b>
              <span>{{ ANIMATE_TIER_META[key].sub }} · {{ ANIMATE_TIER_META[key].render }}<template v-if="tierSlow(key)"> · <span class="nx-warn-text">slow now</span></template></span>
            </span>
            <span class="nx-num">from {{ tierPrice(key) }} cr</span>
          </button>
        </div>
      </div>

      <!-- Length and quality for the chosen model; each quality shows its price. -->
      <div v-else-if="menu === 'vlen'" class="nx-pop">
        <span class="nx-label">Length</span>
        <div class="nx-seg nx-seg-full" role="group" aria-label="Length">
          <button v-for="d in animateDurations" :key="d" type="button" :class="animateDuration === d ? 'on' : ''" @click="animateDuration = d">{{ d }} s</button>
        </div>
        <span class="nx-label">Quality</span>
        <div class="nx-seg nx-seg-full" role="group" aria-label="Quality">
          <button v-for="q in animateQualityOptions" :key="q.value" type="button" :class="animateQuality === q.value ? 'on' : ''" @click="animateQuality = q.value; menu = null">{{ q.label }}</button>
        </div>
        <p v-if="animateDurations.some((d) => d >= 10)" class="nx-hint">A 10 second clip costs twice as much.</p>
      </div>
    </div>

    <p v-if="!hasReference" class="nx-hint">This scene has no picture to start from. Choose an image above, or draw one in Image › AI image.</p>
    <button class="nx-btn nx-primary nx-full" type="button" :disabled="!canMakeVideo" @click="makeVideo">
      {{ animateSubmitting ? "Starting…" : `Make the video${cr(animateCost)}` }}
    </button>
    <p v-if="hasReference && !activeSceneAnimationPending" class="nx-hint">About {{ (ANIMATE_TIER_META[animateTier]?.render || "").replace("~", "") }}. The clip replaces this scene's picture; the picture stays in Versions.</p>
    <p v-if="animateError" class="nx-err">{{ animateError }}</p>
    <p v-if="activeSceneAnimationError" class="nx-err">{{ activeSceneAnimationError }}</p>

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

    <div class="nx-pbox" @click.stop>
      <textarea v-if="editing" v-model="editInstruction" class="nx-pbox-input" rows="3" maxlength="500"
        aria-label="What to change" placeholder="What to change: remove the text on the wall behind her"></textarea>
      <textarea v-else v-model="aiImagePromptOverride" class="nx-pbox-input" rows="3" maxlength="1000"
        aria-label="Describe the picture" placeholder="Describe the picture, or leave it empty to draw from the scene's script"></textarea>
      <div class="nx-pbox-bar">
        <template v-if="!editing">
          <button type="button" class="nx-pdrop" :aria-expanded="menu === 'style'" @click="toggleMenu('style')">
            <span class="nx-pdrop-k">Style</span>
            <img v-if="lookRow?.sample_url" :src="lookRow.sample_url" alt="" />
            <span>{{ lookLabel(lookKey) }}</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
          </button>
          <button type="button" class="nx-pdrop" :aria-expanded="menu === 'character'" @click="toggleMenu('character')">
            <span class="nx-pdrop-k">Character</span>
            <span v-if="activeCharacter" class="nx-pdrop-face">
              <img v-if="activeCharacter.reference_asset?.thumbnail_url || activeCharacter.reference_asset?.storage_url" :src="activeCharacter.reference_asset.thumbnail_url || activeCharacter.reference_asset.storage_url" alt="" />
              <template v-else>{{ activeCharacter.name.charAt(0).toUpperCase() }}</template>
            </span>
            <span>{{ activeCharacter?.name || "None" }}</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
          </button>
          <button type="button" class="nx-pdrop" :aria-expanded="menu === 'model'" @click="toggleMenu('model')">
            <span class="nx-pdrop-k">Model</span>
            <span>{{ modelRow?.label || "Default" }}</span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
          </button>
        </template>
      </div>

      <!-- Style: a preview and the name of each look. -->
      <div v-if="menu === 'style'" class="nx-pop">
        <div class="nx-looks">
          <button v-for="st in stylePickerRows" :key="st.key" type="button" :class="['nx-look', lookKey === st.key ? 'on' : '']" :title="st.description || ''" @click="visualStyleDraft = st.key; menu = null">
            <img v-if="st.sample_url" :src="st.sample_url" alt="" loading="lazy" />
            <span v-else class="nx-look-glyph">{{ st.icon ?? "✦" }}</span>
            <span class="nx-look-name">{{ st.label }}</span>
          </button>
        </div>
        <button v-if="isAi" class="nx-btn nx-outline nx-full" type="button" @click="menu = null; openBulkStyle()">Use this look on all scenes</button>
      </div>

      <!-- Character: the same person in every picture of this scene. -->
      <div v-else-if="menu === 'character'" class="nx-pop">
        <p v-if="!characters.length" class="nx-hint">No characters yet.</p>
        <div v-else class="nx-char-grid">
          <button v-for="c in characters" :key="c.id" type="button" :class="['nx-char-pick', activeScene?.character_id === c.id ? 'on' : '']" @click="selectCharacter(c.id); menu = null">
            <span class="nx-char-face">
              <img v-if="c.reference_asset?.thumbnail_url || c.reference_asset?.storage_url" :src="c.reference_asset.thumbnail_url || c.reference_asset.storage_url" alt="" />
              <template v-else>{{ c.name.charAt(0).toUpperCase() }}</template>
            </span>
            <span>{{ c.name }}</span>
          </button>
        </div>
        <div class="nx-row">
          <button v-if="activeScene?.character_id" class="nx-link" type="button" @click="selectCharacter(null); menu = null">No character</button>
          <span v-else></span>
          <button class="nx-mini" type="button" @click="menu = null; createCharacterOpen = true">+ New character</button>
        </div>
      </div>

      <!-- Model, with the server's price for each. -->
      <div v-else-if="menu === 'model'" class="nx-pop">
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

    <!-- Custom look, between the box and the button. Optional: the prompt can carry the look too (owner, 2026-10-30). -->
    <template v-if="needsCustomLook">
      <label class="nx-label" for="nx-custom-look">Describe the look <span class="nx-dim">optional{{ customVisualStyleSaveState === 'pending' ? ' · saving…' : customVisualStyleSaveState === 'saved' ? ' · saved' : '' }}</span></label>
      <textarea id="nx-custom-look" v-model="customVisualStyleDraft" class="nx-input" rows="2" maxlength="500" placeholder="moody film stills, neon alleys, 35mm grain. Or put it in the prompt above."></textarea>
    </template>
    <button class="nx-btn nx-primary nx-full" type="button" :disabled="!canSend" @click="send">{{ sendLabel }}</button>
    <p v-if="generating" class="nx-hint">About 15 seconds; up to 3 minutes with a character.</p>
    <p v-if="lookChanged && !editing" class="nx-hint nx-hint-warn">Look changed. Draw again to use it.</p>
    <p v-if="aiImageError || activeSceneVisualGenerationError" class="nx-err">{{ aiImageError || activeSceneVisualGenerationError }}</p>
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
          <button class="nx-btn nx-primary nx-full" type="button" :disabled="bulkStyleSubmitting || !bulkStylePreview.affordable" @click="submitBulkStyle">
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
    <button v-if="activeScene?.visual_type !== 'waveform'" class="nx-btn nx-primary nx-full" type="button" @click="saveAudiogramSettings({ apply: true })">Use an audiogram on this scene</button>
    <p v-else class="nx-hint">This scene shows an audiogram.</p>
  </template>

  <!-- Motion, for still pictures: the Ken Burns move rendered on export. -->
  <section v-if="activeSceneIsStillImage" class="nx-sec nx-sec-top">
    <span class="nx-label">Motion</span>
    <div class="nx-chips">
      <button v-for="o in motionOptions" :key="o.value" type="button" :class="['nx-chip', motionEffectDraft === o.value ? 'on' : '']" @click="motionEffectDraft = o.value">{{ MOTION_SHORT[o.value] || o.label }}</button>
    </div>
    <div v-if="motionEffectDraft !== 'static'" class="nx-seg nx-seg-full" role="group" aria-label="Motion strength">
      <button v-for="o in MOTION_INTENSITY_OPTIONS" :key="o.value" type="button" :class="motionIntensityDraft === o.value ? 'on' : ''" @click="motionIntensityDraft = o.value">{{ o.label }}</button>
    </div>
    <p v-if="motionSaveState === 'error'" class="nx-err">{{ motionSaveError }}</p>
  </section>
</template>
