<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../services/api";
import { getEcho } from "../services/echo";
import { useAuthStore } from "../stores/auth";
import AppSidebar from "../components/AppSidebar.vue";
import EditorTimeline from "../components/EditorTimeline.vue";
import MediaPickerModal from "../components/MediaPickerModal.vue";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const projectId = computed(() => route.params.projectId);
const loading = ref(true);
const error = ref("");
const project = ref(null);
const scenes = ref([]);
const hookOptions = ref([]);
const mePayload = ref(null);
const isAdmin = computed(() => ["super_admin", "platform_admin"].includes(mePayload.value?.role ?? authStore.user?.role));
const activeSceneId = ref(null);
const notificationDrawerOpen = ref(false);
const notifications = ref([]);
const notificationToasts = ref([]);
const exportJobs = ref([]);
let workspaceChannelName = null;

const audioRef = ref(null);
const musicAudioRef = ref(null);
const musicAuditionRef = ref(null);
const isAudioPlaying = ref(false);
const isAudioLoading = ref(false);
const auditionMusicTrackId = ref(null);
const currentVisualUrl = ref(null);
const visualLoadFailed = ref(false);
const mediaCache = ref({
  visual: {},
  audio: {},
});
const mediaPreloaders = new Map();
const MEDIA_ELEMENT_CROSS_ORIGIN = "anonymous";
const API_ORIGIN = new URL(api.defaults.baseURL, window.location.origin).origin;

const addScenePanelPosition = ref("");
const selectedSceneType = ref("Narration");
const selectedAddSceneVisualSource = ref("Stock Clip");
const addSceneVisualMode = ref("stock_video");
const addSceneStockSubType = ref("stock_clip");
const addSceneVisualStyle = ref(null);
const addSceneVisualQuery = ref("");
const selectedSwapVisualSource = ref("Stock Clip");
const newSceneScript = ref("");
const rewriteToolsVisible = ref(false);
const rewritePreviewVisible = ref(false);
const rewritePreviewCopy = ref("");
const rewriteCustomInstruction = ref("");
const rewriteMode = ref("");
const rewritePending = ref(false);
const rewriteApplyPending = ref(false);
const rewriteError = ref("");
const voiceRegeneratePending = ref(false);
const voiceRegenerateError = ref("");
const addSceneGeneratePending = ref(false);
const addSceneGenerateError = ref("");
const sceneReorderPendingId = ref(null);
const deleteScenePending = ref(false);
const deleteSceneTarget = ref(null);
const voiceProfiles = ref([]);
const channels = ref([]);
const brandKits = ref([]);
const projectChannelId = ref("");
const projectBrandKitId = ref("");
const projectDefaultsSaveState = ref("idle");
const projectDefaultsSaveError = ref("");
const musicTracks = ref([]);
const selectedMusicTrackId = ref(null);
// My Asset image browser
const myImageAssets = ref([]);
const myImageSearch = ref('');
const myImageLoading = ref(false);
// Custom audio picker
const customAudioAssets = ref([]);
const customAudioSearch = ref('');
const customAudioLoading = ref(false);
const mediaPickerVisible = ref(false);
const mediaPickerMode = ref("visual"); // 'visual' | 'music' | 'sound'
const musicPanelTab = ref("library"); // 'library' | 'uploads'
const musicVolume = ref(30);
const musicDuckVolume = ref(8);
const musicFadeInMs = ref(500);
const musicLoop = ref(true);
const musicDuckDuringVoice = ref(true);
let musicSaveTimer = null;
const musicSaveState = ref("idle");
const musicSaveError = ref("");
// Audiogram settings
const audiogramStyle = ref("bars");
const audiogramColor = ref("#ff6b35");
const audiogramBg = ref("dark");
let audiogramSaveTimer = null;
const audiogramSaveState = ref("idle");

const AUDIOGRAM_STYLES = [
  { key: "bars",    label: "Classic" },
  { key: "mirror",  label: "Mirror"  },
  { key: "circle",  label: "Radial"  },
  { key: "minimal", label: "Minimal" },
];
const AUDIOGRAM_COLORS = [
  "#ff6b35", "#60a5fa", "#34d399", "#a78bfa", "#f472b6", "#fbbf24", "#ffffff",
];
const AUDIOGRAM_BACKGROUNDS = [
  { key: "dark",   label: "Dark",   css: "linear-gradient(180deg,#0d0d1a 0%,#0a0a14 100%)" },
  { key: "black",  label: "Black",  css: "#000" },
  { key: "purple", label: "Purple", css: "linear-gradient(135deg,#1a0a2e 0%,#0d0d2b 50%,#14102a 100%)" },
  { key: "ocean",  label: "Ocean",  css: "linear-gradient(135deg,#0a1628 0%,#0d1f3c 50%,#0a0e1a 100%)" },
];
const voiceProfileKey = ref("alloy");
const voiceSpeedDraft = ref("1.0");
const voiceStabilityDraft = ref("medium");
const voiceSaveState = ref("idle");
const voiceSaveError = ref("");
const visualQueryDraft = ref("");
const visualSwapPending = ref(false);
const visualSwapError = ref("");
// AI Image generation
const aiImagePromptOverride = ref("");
const aiImagePending = ref(false);
const aiImageError = ref("");
const AI_IMAGE_STYLES = [
  { key: "cinematic",     label: "Cinematic",     icon: "🎬" },
  { key: "dark",          label: "Dark",           icon: "🌑" },
  { key: "documentary",   label: "Documentary",    icon: "📽️" },
  { key: "anime",         label: "Anime",          icon: "🌸" },
  { key: "minimalist",    label: "Minimalist",     icon: "◽" },
  { key: "realistic",     label: "Realistic",      icon: "📸" },
  { key: "vintage",       label: "Vintage",        icon: "🌅" },
  { key: "neon",          label: "Neon",           icon: "⚡" },
  { key: "photorealistic",label: "Photorealistic", icon: "📷" },
  { key: "cyberpunk_80s", label: "Cyberpunk 80s",  icon: "🕹️" },
  { key: "anime_80s",     label: "Anime 80s",      icon: "🌟" },
  { key: "anime_90s",     label: "Anime 90s",      icon: "💫" },
  { key: "dark_fantasy",  label: "Dark Fantasy",   icon: "🐉" },
  { key: "fantasy_retro", label: "Fantasy Retro",  icon: "🧙" },
  { key: "comic",         label: "Comic",          icon: "💥" },
  { key: "film_noir",     label: "Film Noir",      icon: "🎩" },
  { key: "line_drawing",  label: "Line Drawing",   icon: "✏️" },
  { key: "watercolor",    label: "Watercolor",     icon: "🎨" },
  { key: "paper_cutout",  label: "Paper Cutout",   icon: "✂️" },
  { key: "cartoon",       label: "Cartoon",        icon: "🎭" },
];
const exportPending = ref(false);
const exportState = ref("idle");
const timelineOpen = ref(false);
const previewMode = ref("scene");
const stockVideoSubType = ref("stock_clip");
const playProgress = ref(0);
const isPreviewPlaying = ref(false);
let previewPlayTimer = null;
let pendingPreviewAudioOffset = 0;
const musicMoodFilter = ref("all");
const queuedExportJobId = ref(null);
let exportPollTimer = null;
const scriptSaveState = ref("idle");
const scriptSaveError = ref("");
const captionEnabledDraft = ref(true);
const captionStyleDraft = ref("impact");
const captionHighlightDraft = ref("keywords");
const captionPositionDraft = ref("bottom_third");
const DEFAULT_CAPTION_FONT = "Bebas Neue";
const DEFAULT_CAPTION_SETTINGS = Object.freeze({
  enabled: true,
  style_key: "impact",
  highlight_mode: "keywords",
  position: "bottom_third",
  font: DEFAULT_CAPTION_FONT,
  highlight_color: "#ff6b35",
  color: "#ffffff",
  size: "medium",
  preset_id: null,
});
const captionFontDraft = ref(DEFAULT_CAPTION_FONT);
const captionColorDraft = ref("#ffffff");
const captionSizeDraft = ref("medium");
const captionHighlightColorDraft = ref("#ff6b35");
const CAPTION_COLOR_SWATCHES = ["#ffffff","#ffff00","#ff6b35","#ff4444","#44ff88","#44aaff","#cc88ff","#000000"];
const CAPTION_SIZE_MAP = { small: "13px", medium: "17px", large: "23px", xlarge: "30px" };
const fontDropdownOpen = ref(false);
const captionSaveState = ref("idle");
const captionSaveError = ref("");
const CAPTION_FONT_GROUPS = [
  { label: "Bold Display", fonts: ["Bebas Neue", "Days One", "Passion One", "Fredoka One", "Luckiest Guy", "New Rocker", "Aladin"] },
  { label: "Sans-serif", fonts: ["Montserrat", "Raleway", "Lato", "Nunito", "Quicksand", "Noto Sans", "Liberation Sans", "Nimbus Sans"] },
  { label: "Serif", fonts: ["Playfair Display", "Roboto Slab", "Libre Baskerville", "Liberation Serif", "Nimbus Roman", "Century Schoolbook L"] },
  { label: "Script", fonts: ["Dancing Script", "Sacramento", "Satisfy", "Shadows Into Light"] },
  { label: "Mono", fonts: ["Roboto Mono", "Source Code Pro", "Orbitron", "Liberation Mono", "Nimbus Mono PS"] },
  { label: "Handwritten", fonts: ["Permanent Marker", "Amatic SC", "Indie Flower", "Rock Salt", "Calligraffitti"] },
];
const motionEffectDraft = ref("zoom_in");
const motionIntensityDraft = ref("moderate");
const motionSaveState = ref("idle");
const motionSaveError = ref("");
const visualStyleDraft = ref(null);
let visualStyleSaveTimer = null;
const visualStyleSaveState = ref("idle");
const panelState = ref({
  script: false,
  visual: false,
  motion: false,
  voice: false,
  sounds: false,
  captions: false,
  music: false,
  brand: false,
});
const sceneScriptDraft = ref("");
let scriptSaveTimer = null;
let voiceSaveTimer = null;
let captionSaveTimer = null;
let motionSaveTimer = null;
let beforeUnloadHandler = null;

const activeScene = computed(
  () => scenes.value.find((scene) => scene.id === activeSceneId.value) ?? null
);
const activeSceneVisualUrl = computed(
  () => activeScene.value?.visual_asset?.storage_url ?? null
);
const activeSceneVisualAsset = computed(() => activeScene.value?.visual_asset ?? null);
const activeSceneVisualType = computed(
  () => String(activeScene.value?.visual_type || "")
);
const activeSceneAIImagePending = computed(() => {
  const scene = activeScene.value;
  if (!scene) return false;
  const settings = scene.image_generation_settings ?? {};
  // in_progress is set by both the pipeline job and the manual endpoint
  if (settings.in_progress) return true;
  if (String(scene.visual_type || "") !== "ai_image") return false;
  if (scene.visual_asset) return false;
  return !settings.needs_visual;
});
const activeSceneVisualGenerationError = computed(() => {
  const settings = activeScene.value?.image_generation_settings;
  if (!settings?.needs_visual) return "";
  return (
    settings.last_error ||
    "Image generation failed. Please revise the prompt and try again."
  );
});
const activeSceneVisualIsVideo = computed(() => {
  const asset = activeSceneVisualAsset.value;
  if (!asset) return false;
  return asset.asset_type === "video" || String(asset.mime_type || "").startsWith("video/");
});
// True only when the scene visual is a user library asset (not AI broll, not stock/matched)
const activeSceneVisualIsFromLibrary = computed(() => {
  const asset = activeSceneVisualAsset.value;
  if (!asset) return false;
  if (activeScene.value?.visual_type === "ai_image") return false;
  const tags = Array.isArray(asset.tags) ? asset.tags : [];
  return !tags.includes("matched_visual");
});
const activeSceneAudioUrl = computed(
  () => activeScene.value?.audio_asset?.storage_url ?? null
);
const activeSceneSoundAsset = computed(() => activeScene.value?.sound_asset ?? null);
const selectedProjectChannel = computed(() =>
  channels.value.find((channel) => String(channel.id) === String(projectChannelId.value)) || null
);
const activeMusicTrack = computed(() =>
  musicTracks.value.find((t) => t.id === selectedMusicTrackId.value) ?? null
);
const activeMusicTrackUrl = computed(() => activeMusicTrack.value?.storage_url ?? null);
const auditionMusicTrack = computed(() =>
  musicTracks.value.find((t) => t.id === auditionMusicTrackId.value) ?? null
);
const previewMusicVolume = computed(() => {
  const rawVolume =
    musicDuckDuringVoice.value && activeSceneAudioUrl.value && isPreviewPlaying.value && isAudioPlaying.value
      ? musicDuckVolume.value
      : musicVolume.value;

  return Math.max(0, Math.min(1, Number(rawVolume || 0) / 100));
});
const filteredMusicTracks = computed(() => {
  if (musicMoodFilter.value === "all") return musicTracks.value;
  return musicTracks.value.filter((t) =>
    (t.tags ?? []).some((tag) => tag.toLowerCase() === musicMoodFilter.value)
  );
});
const myImageAssetsFiltered = computed(() => {
  const q = myImageSearch.value.toLowerCase();
  if (!q) return myImageAssets.value;
  return myImageAssets.value.filter(a => String(a.title ?? '').toLowerCase().includes(q));
});

const customAudioAssetsFiltered = computed(() => {
  const q = customAudioSearch.value.toLowerCase();
  if (!q) return customAudioAssets.value;
  return customAudioAssets.value.filter(a => String(a.title ?? '').toLowerCase().includes(q));
});

const musicTrackGroups = computed(() => {
  const groups = {};
  for (const track of filteredMusicTracks.value) {
    const mood = track.tags?.find((tag) => tag !== "music") ?? "other";
    if (!groups[mood]) groups[mood] = [];
    groups[mood].push(track);
  }

  return Object.entries(groups).map(([mood, tracks]) => ({ mood, tracks }));
});
const activeSceneVisualLoaded = computed(() => {
  const sceneId = activeSceneId.value;
  if (!sceneId || !activeSceneVisualUrl.value) return false;
  return Boolean(mediaCache.value.visual[sceneId]?.loaded);
});
const isVisualLoading = computed(() => {
  if (!activeSceneVisualUrl.value) return false;
  return !activeSceneVisualLoaded.value && !visualLoadFailed.value;
});
const showTextCardPreview = computed(
  () => !currentVisualUrl.value && activeSceneVisualType.value === "text_card"
);
const showWaveformPreview = computed(
  () => activeSceneVisualType.value === "waveform"
);
const waveformLive = ref([0.28, 0.52, 0.34, 0.76, 0.42, 0.88, 0.48, 0.66, 0.31, 0.58, 0.40, 0.72, 0.35, 0.65]);
let waveformRafId = null;

// Web Audio API state — read the live mix from narration and music elements
let waveformAudioCtx = null;
let waveformAnalyser = null;
let waveformConnectedCount = 0;
let waveformUserActivated = false;
const waveformConnectedEls = new WeakMap();

// Simulation fallback state (speech-like energy distribution)
let waveformSimBands = new Array(14).fill(0.05);
let waveformSimLastMs = 0;

function waveformEnsureContext() {
  if (waveformAudioCtx && waveformAnalyser) return true;

  try {
    waveformAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
    waveformAnalyser = waveformAudioCtx.createAnalyser();
    waveformAnalyser.fftSize = 128;
    waveformAnalyser.smoothingTimeConstant = 0.82;
    waveformAnalyser.connect(waveformAudioCtx.destination);
    return true;
  } catch {
    waveformAudioCtx = null;
    waveformAnalyser = null;
    return false;
  }
}

function mediaCrossOriginMode(url) {
  if (!url) return null;

  try {
    const resolved = new URL(url, window.location.origin);
    return resolved.origin === window.location.origin || resolved.origin === API_ORIGIN
      ? MEDIA_ELEMENT_CROSS_ORIGIN
      : null;
  } catch {
    return null;
  }
}

function unlockWaveformAudio() {
  waveformUserActivated = true;
  if (!waveformEnsureContext()) return false;

  if (waveformAudioCtx?.state === "suspended") {
    waveformAudioCtx.resume().catch(() => {});
  }

  waveformTryConnect();
  return true;
}

function waveformTryConnectElement(el) {
  if (!el || waveformConnectedEls.has(el)) return Boolean(el && waveformConnectedEls.has(el));
  if (!waveformUserActivated || !waveformAudioCtx || !waveformAnalyser) return false;
  if (!mediaCrossOriginMode(el.currentSrc || el.src)) return false;

  try {
    const src = waveformAudioCtx.createMediaElementSource(el);
    src.connect(waveformAnalyser);
    waveformConnectedEls.set(el, src);
    waveformConnectedCount += 1;
    return true;
  } catch {
    // SecurityError (CORS) or already connected elsewhere — fall through to simulation
    return false;
  }
}

function waveformTryConnect() {
  return [audioRef.value, musicAudioRef.value].some((el) => waveformTryConnectElement(el));
}

function waveformHasActiveSource() {
  const narrationPlaying = Boolean(audioRef.value && !audioRef.value.paused && activeSceneAudioUrl.value);
  const musicPlaying = Boolean(musicAudioRef.value && !musicAudioRef.value.paused && activeMusicTrackUrl.value);
  return narrationPlaying || musicPlaying;
}

function tickWaveform(ts) {
  const playing = isPreviewPlaying.value;
  const audioActive = playing && waveformHasActiveSource();

  if (audioActive && waveformConnectedCount > 0 && waveformAnalyser) {
    // Real frequency data from the active audio mix
    waveformTryConnect();
    if (waveformAudioCtx?.state === "suspended") waveformAudioCtx.resume().catch(() => {});
    const data = new Uint8Array(waveformAnalyser.frequencyBinCount);
    waveformAnalyser.getByteFrequencyData(data);
    const count = waveformLive.value.length;
    const usableBins = Math.max(count, Math.floor(data.length * 0.8));
    const binsPerBar = Math.max(1, Math.floor(usableBins / count));

    // Average small frequency ranges so voice and music both produce stable motion.
    waveformLive.value = Array.from({ length: count }, (_, i) => {
      const start = i * binsPerBar;
      const end = i === count - 1 ? usableBins : Math.min(usableBins, start + binsPerBar);
      let total = 0;

      for (let bin = start; bin < end; bin += 1) {
        total += data[bin] ?? 0;
      }

      const avg = total / Math.max(1, end - start);
      return Math.max(0.04, Math.min(1, avg / 180));
    });
  } else if (playing) {
    // Fallback: speech-like pseudo-reactive simulation
    // Refresh target band heights every ~80 ms with a realistic speech energy envelope
    if (!waveformSimLastMs || ts - waveformSimLastMs > 80) {
      waveformSimLastMs = ts;
      const energy = 0.3 + Math.random() * 0.7;
      const n = waveformSimBands.length;
      waveformSimBands = waveformSimBands.map((_, i) => {
        const pos = i / n;
        // Energy peaks in low-mid range (speech formants), tapers at extremes
        const envelope = Math.max(0, 1 - Math.pow(pos * 2.5 - 0.5, 2) * 2.2);
        return Math.max(0.06, Math.min(1, energy * envelope * (0.3 + Math.random() * 0.7)));
      });
    }
    // Lerp smoothly toward target heights
    waveformLive.value = waveformLive.value.map((cur, i) => cur + (waveformSimBands[i] - cur) * 0.28);
  } else {
    // Paused or stopped: decay to near-zero idle dots
    waveformLive.value = waveformLive.value.map(v => Math.max(0.04, v * 0.75));
  }

  waveformRafId = requestAnimationFrame(tickWaveform);
}

function startWaveformAnimation() {
  if (!waveformRafId) {
    waveformRafId = requestAnimationFrame(tickWaveform);
  }
}

function stopWaveformAnimation() {
  if (waveformRafId) { cancelAnimationFrame(waveformRafId); waveformRafId = null; }
}
const audiogramBgStyle = computed(() => {
  const bg = AUDIOGRAM_BACKGROUNDS.find(b => b.key === audiogramBg.value);
  return { background: bg ? bg.css : AUDIOGRAM_BACKGROUNDS[0].css };
});
const activeVoiceName = computed(() => {
  const voiceId = activeScene.value?.voice_settings?.voice_id;
  const match = voiceProfiles.value.find(
    (profile) => profile.provider_voice_key === voiceId
  );
  if (match?.name) return match.name;
  return voiceId ? voiceId.charAt(0).toUpperCase() + voiceId.slice(1) : "Default";
});
const activeVoiceSpeed = computed(
  () => activeScene.value?.voice_settings?.speed ?? 1.0
);
const activeVoiceOutdated = computed(
  () => Boolean(activeScene.value?.voice_settings?.is_outdated)
);
// Hook options sorted by score descending; unscored hooks go last.
const sortedHookOptions = computed(() =>
  [...hookOptions.value].sort((a, b) => {
    const sa = a.hook_score ?? -1;
    const sb = b.hook_score ?? -1;
    return sb - sa;
  })
);
// True when the active scene's visual is a still image (not video, text card, or waveform).
// Ken Burns motion controls only appear in this state.
const activeSceneIsStillImage = computed(() => {
  if (!activeScene.value) return false;
  const vtype = String(activeScene.value.visual_type || "");
  if (vtype === "text_card" || vtype === "waveform") return false;
  if (!activeScene.value.visual_asset) return false;
  return !activeSceneVisualIsVideo.value;
});
const activeMotionClass = computed(() => {
  if (!activeSceneIsStillImage.value) return "";
  const effect = motionEffectDraft.value;
  if (effect === "static") return "";
  return "kb-" + effect.replace(/_/g, "-");
});
const activeSceneIndex = computed(() =>
  scenes.value.findIndex((scene) => scene.id === activeSceneId.value)
);
const projectTitle = computed(
  () => project.value?.title || `Project #${projectId.value}`
);
const unreadCount = computed(() =>
  notifications.value.filter((item) => !item.is_read).length
);
const latestExportJob = computed(() => exportJobs.value[0] ?? null);
const latestExportDownloadUrl = computed(
  () => latestExportJob.value?.output_asset?.storage_url ?? null
);
const exportBlockerMessage = computed(() => {
  const VISUAL_OPTIONAL = ["text_card", "waveform"];
  for (const scene of scenes.value) {
    const label = scene.label || `Scene ${scene.scene_order ?? ""}`;
    if (!String(scene.script_text || "").trim()) {
      return `"${label}" has no script — add copy before exporting.`;
    }
    if (!scene.visual_asset_id && !VISUAL_OPTIONAL.includes(String(scene.visual_type || ""))) {
      return `"${label}" is missing a visual — pick a clip or image first.`;
    }
    if (!scene.voice_settings?.audio_asset_id) {
      return `"${label}" has no generated voice — wait for TTS or regenerate.`;
    }
  }
  return null;
});
const captionPreviewClass = computed(() => {
  if (!captionEnabledDraft.value || !captionsCanRender.value) return "caption-hidden";
  if (captionStyleDraft.value === "editorial") return "caption-style-editorial";
  if (captionStyleDraft.value === "hacker") return "caption-style-hacker";
  return "caption-style-impact";
});
const captionsCanRender = computed(() => {
  if (
    activeSceneAIImagePending.value ||
    activeSceneVisualGenerationError.value ||
    visualLoadFailed.value
  ) return false;
  if (activeSceneVisualUrl.value) return activeSceneVisualLoaded.value;
  return showTextCardPreview.value || showWaveformPreview.value;
});
const captionPositionStyle = computed(() => {
  if (captionPositionDraft.value === "center")
    return { top: "50%", transform: "translateY(-50%)", bottom: "auto" };
  if (captionPositionDraft.value === "top_third")
    return { top: "80px", bottom: "auto" };
  return {};
});
const flattenedCaptionFonts = computed(() =>
  CAPTION_FONT_GROUPS.flatMap((group) =>
    group.fonts.map((font) => ({ font, group: group.label }))
  )
);
const selectedCaptionFont = computed(
  () =>
    flattenedCaptionFonts.value.find((item) => item.font === captionFontDraft.value) || {
      font: captionFontDraft.value || DEFAULT_CAPTION_FONT,
      group: "Custom",
    }
);
const captionFontStyle = computed(() => ({
  fontFamily: fontFamilyValue(captionFontDraft.value || DEFAULT_CAPTION_FONT),
  fontSize: CAPTION_SIZE_MAP[captionSizeDraft.value] || "17px",
}));
const activeCaptionSettings = computed(
  () => activeScene.value?.caption_settings ?? activeScene.value?.caption_settings_json ?? {}
);

const sceneTypeOptions = [
  "Narration",
  "Hook",
  "Transition",
  "Text Card",
  "Quote",
];
const addSceneStockTypeOptions = ["Stock Clip", "BG Loop", "Text Only", "Audiogram"];
const visualSourceTypeMap = {
  "Stock Video": "stock_clip",
  "Stock Image": "image_montage",
  "Stock Clip": "stock_clip",
  "BG Loop": "background_loop",
  "AI Image": "ai_image",
  "Text Only": "text_card",
  Audiogram: "waveform",
};
const visualTypeLabelMap = {
  image_montage: "Stock Clip",
  stock_clip: "Stock Clip",
  background_loop: "BG Loop",
  ai_image: "AI Image",
  text_card: "Text Only",
  waveform: "Audiogram",
};
const rewriteOptions = [
  "Shorten",
  "Expand",
  "Stronger hook",
  "More punchy",
  "More educational",
  "Simplify",
];
const rewriteModeMap = {
  Shorten: "shorten",
  Expand: "expand",
  "Stronger hook": "stronger_hook",
  "More punchy": "more_punchy",
  "More educational": "more_educational",
  Simplify: "simplify",
};

watch(
  activeScene,
  (scene, prevScene) => {
    const sceneChanged = scene?.id !== prevScene?.id;

    if (sceneChanged) {
      // Scene switch — cancel pending saves and reset all draft state
      if (scriptSaveTimer) {
        window.clearTimeout(scriptSaveTimer);
        scriptSaveTimer = null;
      }
      if (voiceSaveTimer) {
        window.clearTimeout(voiceSaveTimer);
        voiceSaveTimer = null;
      }
      if (captionSaveTimer) {
        window.clearTimeout(captionSaveTimer);
        captionSaveTimer = null;
      }
      sceneScriptDraft.value = scene?.script_text || "";
      voiceProfileKey.value = scene?.voice_settings?.voice_id || "alloy";
      voiceSpeedDraft.value = String(scene?.voice_settings?.speed ?? 1.0);
      voiceStabilityDraft.value = String(scene?.voice_settings?.stability ?? "medium");
      visualQueryDraft.value = scene?.visual_prompt || "";
      const rawType = String(scene?.visual_type || "");
      const assetTags = Array.isArray(scene?.visual_asset?.tags) ? scene.visual_asset.tags : [];
      const isLibraryAsset = scene?.visual_asset && !assetTags.includes("matched_visual") && rawType !== "ai_image";
      if (rawType === "ai_image") selectedSwapVisualSource.value = "AI Image";
      else if (rawType === "waveform") selectedSwapVisualSource.value = "Audiogram";
      else if (isLibraryAsset) selectedSwapVisualSource.value = "My Assets";
      else if (rawType === "image_montage") selectedSwapVisualSource.value = "Stock Image";
      else if (rawType === "background_loop") selectedSwapVisualSource.value = "Stock Video";
      else selectedSwapVisualSource.value = "Stock Video";
      // Restore audiogram settings
      const imgSettings = scene?.image_generation_settings ?? scene?.image_generation_settings_json ?? {};
      audiogramStyle.value = imgSettings.audiogram_style ?? "bars";
      audiogramColor.value = imgSettings.audiogram_color ?? "#ff6b35";
      audiogramBg.value = imgSettings.audiogram_bg ?? "dark";
      const captionSettings = normalizeCaptionSettings(
        scene?.caption_settings || scene?.caption_settings_json
      );
      captionEnabledDraft.value = captionSettings.enabled !== false;
      captionStyleDraft.value = String(captionSettings.style_key);
      captionHighlightDraft.value = String(captionSettings.highlight_mode);
      captionPositionDraft.value = String(captionSettings.position);
      captionFontDraft.value = String(captionSettings.font);
      captionColorDraft.value = captionSettings.color || "#ffffff";
      captionSizeDraft.value = captionSettings.size || "medium";
      captionHighlightColorDraft.value = captionSettings.highlight_color || "#ff6b35";
      fontDropdownOpen.value = false;
      const motionSettings = scene?.motion_settings || scene?.motion_settings_json || {};
      motionEffectDraft.value = String(motionSettings.effect || "zoom_in");
      motionIntensityDraft.value = String(motionSettings.intensity || "moderate");
      visualStyleDraft.value = scene?.visual_style ?? scene?.image_generation_settings?.style ?? project.value?.ai_broll_style ?? null;
      visualStyleSaveState.value = "idle";
      visualSwapPending.value = false;
      visualSwapError.value = "";
      voiceSaveState.value = "idle";
      voiceSaveError.value = "";
      scriptSaveState.value = "idle";
      scriptSaveError.value = "";
      captionSaveState.value = "idle";
      captionSaveError.value = "";
      rewriteToolsVisible.value = false;
      rewritePreviewVisible.value = false;
      rewritePreviewCopy.value = "";
      rewriteCustomInstruction.value = "";
      rewriteMode.value = "";
      rewriteError.value = "";
      // Reset and reload both visual and audio
      if (audioRef.value) {
        audioRef.value.pause();
        audioRef.value.load();
        isAudioPlaying.value = false;
      }
      isAudioLoading.value = false;
      visualLoadFailed.value = false;
      syncActiveSceneMedia(scene);
      if (isPreviewPlaying.value) {
        nextTick(() => playActiveSceneAudio(pendingPreviewAudioOffset));
      }
      return;
    }

    // Same scene updated — selectively reload only what changed
    const prevVisualUrl = prevScene?.visual_asset?.storage_url ?? null;
    const nextVisualUrl = scene?.visual_asset?.storage_url ?? null;
    const visualChanged =
      scene?.visual_asset_id !== prevScene?.visual_asset_id ||
      nextVisualUrl !== prevVisualUrl;

    const prevAudioUrl = prevScene?.audio_asset?.storage_url ?? null;
    const nextAudioUrl = scene?.audio_asset?.storage_url ?? null;
    const audioChanged = nextAudioUrl !== prevAudioUrl;

    if (visualChanged) {
      visualLoadFailed.value = false;
      syncActiveSceneMedia(scene);
    }

    if (audioChanged) {
      if (audioRef.value) {
        audioRef.value.pause();
        audioRef.value.load();
        isAudioPlaying.value = false;
      }
      isAudioLoading.value = false;
      if (scene?.audio_asset?.storage_url) {
        preloadSceneAudio(scene);
      }
      if (isPreviewPlaying.value) {
        nextTick(() => playActiveSceneAudio(pendingPreviewAudioOffset));
      }
    }
  },
  { immediate: true }
);

watch(activeMusicTrackUrl, () => {
  if (!musicAudioRef.value) return;
  musicAudioRef.value.pause();
  musicAudioRef.value.load();
  if (isPreviewPlaying.value) {
    nextTick(() => playPreviewMusic());
  }
});

watch(previewMusicVolume, () => {
  syncPreviewMusicVolume();
});

watch([voiceProfileKey, voiceSpeedDraft, voiceStabilityDraft], () => {
  const scene = activeScene.value;

  if (!scene) return;

  const savedVoice = scene.voice_settings || {};
  const nextSettings = {
    voice_id: voiceProfileKey.value,
    speed: Number(voiceSpeedDraft.value || 1),
    stability: voiceStabilityDraft.value,
  };

  if (
    String(savedVoice.voice_id || "alloy") === nextSettings.voice_id &&
    Number(savedVoice.speed ?? 1) === nextSettings.speed &&
    String(savedVoice.stability || "medium") === nextSettings.stability
  ) {
    if (voiceSaveTimer) {
      window.clearTimeout(voiceSaveTimer);
      voiceSaveTimer = null;
    }
    if (voiceSaveState.value !== "saved") {
      voiceSaveState.value = "idle";
    }
    voiceSaveError.value = "";
    return;
  }

  if (voiceSaveTimer) {
    window.clearTimeout(voiceSaveTimer);
  }

  voiceSaveState.value = "pending";
  voiceSaveError.value = "";
  voiceSaveTimer = window.setTimeout(() => {
    persistVoiceSettings(scene.id, nextSettings);
  }, 500);
});

watch(sceneScriptDraft, (draft) => {
  const scene = activeScene.value;

  if (!scene) return;

  const savedScript = scene.script_text || "";

  if (draft === savedScript) {
    if (scriptSaveTimer) {
      window.clearTimeout(scriptSaveTimer);
      scriptSaveTimer = null;
    }
    if (scriptSaveState.value !== "saved") {
      scriptSaveState.value = "idle";
    }
    scriptSaveError.value = "";
    return;
  }

  if (scriptSaveTimer) {
    window.clearTimeout(scriptSaveTimer);
  }

  scriptSaveState.value = "pending";
  scriptSaveError.value = "";

  scriptSaveTimer = window.setTimeout(() => {
    persistSceneScript(scene.id, draft);
  }, 700);
});

watch([captionEnabledDraft, captionStyleDraft, captionHighlightDraft, captionPositionDraft, captionFontDraft, captionColorDraft, captionSizeDraft, captionHighlightColorDraft], () => {
  const scene = activeScene.value;

  if (!scene) return;

  const savedCaptions = activeCaptionSettings.value || {};
  const nextSettings = {
    enabled: captionEnabledDraft.value,
    style_key: captionStyleDraft.value,
    highlight_mode: captionHighlightDraft.value,
    position: captionPositionDraft.value,
    font: captionFontDraft.value,
    highlight_color: captionHighlightColorDraft.value,
    color: captionColorDraft.value,
    size: captionSizeDraft.value,
    preset_id: savedCaptions.preset_id || null,
  };

  if (
    (savedCaptions.enabled !== false) === nextSettings.enabled &&
    String(savedCaptions.style_key || "impact") === nextSettings.style_key &&
    String(savedCaptions.highlight_mode || "keywords") === nextSettings.highlight_mode &&
    String(savedCaptions.position || "bottom_third") === nextSettings.position &&
    String(savedCaptions.font || DEFAULT_CAPTION_FONT) === nextSettings.font &&
    String(savedCaptions.color || "#ffffff") === nextSettings.color &&
    String(savedCaptions.size || "medium") === nextSettings.size &&
    String(savedCaptions.highlight_color || "#ff6b35") === nextSettings.highlight_color
  ) {
    if (captionSaveTimer) {
      window.clearTimeout(captionSaveTimer);
      captionSaveTimer = null;
    }
    if (captionSaveState.value !== "saved") {
      captionSaveState.value = "idle";
    }
    captionSaveError.value = "";
    return;
  }

  if (captionSaveTimer) {
    window.clearTimeout(captionSaveTimer);
  }

  patchSceneCaptionSettings(scene.id, nextSettings);
  captionSaveState.value = "pending";
  captionSaveError.value = "";
  captionSaveTimer = window.setTimeout(() => {
    persistCaptionSettings(scene.id, nextSettings);
  }, 500);
});

watch([motionEffectDraft, motionIntensityDraft], () => {
  const scene = activeScene.value;
  if (!scene) return;

  const savedMotion = scene.motion_settings || scene.motion_settings_json || {};
  const nextSettings = {
    effect: motionEffectDraft.value,
    intensity: motionIntensityDraft.value,
  };

  if (
    String(savedMotion.effect || "zoom_in") === nextSettings.effect &&
    String(savedMotion.intensity || "moderate") === nextSettings.intensity
  ) {
    if (motionSaveTimer) {
      window.clearTimeout(motionSaveTimer);
      motionSaveTimer = null;
    }
    if (motionSaveState.value !== "saved") {
      motionSaveState.value = "idle";
    }
    motionSaveError.value = "";
    return;
  }

  if (motionSaveTimer) {
    window.clearTimeout(motionSaveTimer);
  }

  motionSaveState.value = "pending";
  motionSaveError.value = "";
  motionSaveTimer = window.setTimeout(() => {
    persistMotionSettings(scene.id, nextSettings);
  }, 500);
});

watch(visualStyleDraft, (nextStyle) => {
  const scene = activeScene.value;
  if (!scene) return;
  if ((nextStyle ?? null) === (scene.visual_style ?? null)) return;

  visualStyleSaveState.value = "pending";
  clearTimeout(visualStyleSaveTimer);
  visualStyleSaveTimer = setTimeout(async () => {
    try {
      const response = await api.patch(`/scenes/${scene.id}`, { visual_style: nextStyle });
      const updated = response.data?.data?.scene;
      if (updated) {
        scenes.value = scenes.value.map((s) => (s.id === updated.id ? { ...s, ...updated } : s));
      }
      visualStyleSaveState.value = "saved";
    } catch {
      visualStyleSaveState.value = "idle";
    }
  }, 500);
});

watch(
  [exportJobs, queuedExportJobId],
  ([jobs, pendingJobId]) => {
    if (!pendingJobId) return;

    const matchingJob = jobs.find((job) => job.id === pendingJobId);

    if (!matchingJob) return;

    if (
      matchingJob.status === "completed" &&
      matchingJob.output_asset?.storage_url
    ) {
      pushToast({
        id: `export-complete-${matchingJob.id}`,
        title: "Export ready",
        message: matchingJob.file_name || "Your rendered MP4 is ready.",
        created_at: new Date().toISOString(),
      });
      window.open(
        matchingJob.output_asset.storage_url,
        "_blank",
        "noopener,noreferrer"
      );
      queuedExportJobId.value = null;
      exportState.value = "idle";
      return;
    }

    if (matchingJob.status === "failed") {
      pushToast({
        id: `export-failed-${matchingJob.id}`,
        title: "Export failed",
        message: matchingJob.failure_reason || "Export failed.",
        created_at: new Date().toISOString(),
      });
      queuedExportJobId.value = null;
      exportState.value = "error";
    }
  },
  { deep: true }
);

// Poll export status while a job is pending — WebSocket is best-effort,
// polling ensures the UI always catches completed/failed state.
watch(queuedExportJobId, (id) => {
  if (exportPollTimer) { clearInterval(exportPollTimer); exportPollTimer = null; }
  if (id) {
    exportPollTimer = setInterval(() => loadExportJobs(), 6000);
  }
}, { immediate: true });

function humanizeSceneType(sceneType) {
  return String(sceneType || "narration")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

function sceneTypeLabel(scene) {
  return humanizeSceneType(scene?.scene_type);
}

function sceneVisualLabel(scene) {
  if (scene?.visual_type === "text_card") return "text card";
  if (scene?.visual_type === "waveform") return "audiogram";
  if (scene?.visual_type === "ai_image") return "ai image";
  if (scene?.visual_type === "background_loop") return "bg loop";
  if (scene?.visual_type === "stock_clip") return "stock clip";
  if (scene?.visual_asset?.asset_type === "video") return "stock clip";
  if (scene?.visual_asset?.asset_type === "image") return "ai image";
  return "bg loop";
}

function selectedVisualType() {
  if (selectedSwapVisualSource.value === "Stock Video") {
    return stockVideoSubType.value || "stock_clip";
  }
  return visualSourceTypeMap[selectedSwapVisualSource.value] || "stock_clip";
}

function sceneVoiceOutdated(scene) {
  return Boolean(scene?.voice_settings?.is_outdated);
}

function normalizeScenePayload(scene) {
  if (!scene) return null;

  const captionSettings = normalizeCaptionSettings(
    scene.caption_settings ?? scene.caption_settings_json
  );

  return {
    ...scene,
    voice_settings: scene.voice_settings ?? scene.voice_settings_json ?? null,
    caption_settings: captionSettings,
    caption_settings_json: captionSettings,
    image_generation_settings:
      scene.image_generation_settings ?? scene.image_generation_settings_json ?? null,
    locked_fields: scene.locked_fields ?? scene.locked_fields_json ?? null,
  };
}

function normalizeCaptionSettings(settings, fallback = {}) {
  const source = settings && typeof settings === "object" ? settings : {};
  const fallbackSource = fallback && typeof fallback === "object" ? fallback : {};

  return {
    ...DEFAULT_CAPTION_SETTINGS,
    ...fallbackSource,
    ...source,
    enabled: source.enabled ?? fallbackSource.enabled ?? DEFAULT_CAPTION_SETTINGS.enabled,
    style_key: source.style_key || fallbackSource.style_key || DEFAULT_CAPTION_SETTINGS.style_key,
    highlight_mode:
      source.highlight_mode ||
      fallbackSource.highlight_mode ||
      DEFAULT_CAPTION_SETTINGS.highlight_mode,
    position: source.position || fallbackSource.position || DEFAULT_CAPTION_SETTINGS.position,
    font: source.font || fallbackSource.font || DEFAULT_CAPTION_SETTINGS.font,
    highlight_color:
      source.highlight_color ||
      fallbackSource.highlight_color ||
      DEFAULT_CAPTION_SETTINGS.highlight_color,
    color: source.color || fallbackSource.color || DEFAULT_CAPTION_SETTINGS.color,
    size: source.size || fallbackSource.size || DEFAULT_CAPTION_SETTINGS.size,
    preset_id: source.preset_id ?? fallbackSource.preset_id ?? DEFAULT_CAPTION_SETTINGS.preset_id,
  };
}

function sortScenesByOrder(nextScenes) {
  return [...nextScenes].sort(
    (left, right) => Number(left.scene_order || 0) - Number(right.scene_order || 0)
  );
}

function replaceSceneInCollection(updatedScene) {
  scenes.value = sortScenesByOrder(
    scenes.value.map((scene) => {
      if (scene.id !== updatedScene.id) return scene;

      const captionSettings = normalizeCaptionSettings(
        updatedScene.caption_settings ?? updatedScene.caption_settings_json,
        scene.caption_settings ?? scene.caption_settings_json
      );

      return {
        ...scene,
        ...updatedScene,
        caption_settings: captionSettings,
        caption_settings_json: captionSettings,
      };
    })
  );
}

function patchSceneCaptionSettings(sceneId, captionSettings) {
  const scene = scenes.value.find((item) => item.id === sceneId);

  if (!scene) return;

  const normalizedSettings = normalizeCaptionSettings(captionSettings);

  scene.caption_settings = normalizedSettings;
  scene.caption_settings_json = normalizedSettings;
}

function buildSceneLabel(sceneType) {
  const label = humanizeSceneType(sceneType);
  return label === "Narration" ? `Scene ${scenes.value.length + 1}` : label;
}

function resetAddSceneDrafts() {
  selectedSceneType.value = "Narration";
  selectedAddSceneVisualSource.value = "Stock Clip";
  addSceneVisualMode.value = "stock_video";
  addSceneStockSubType.value = "stock_clip";
  addSceneVisualStyle.value = null;
  addSceneVisualQuery.value = "";
  newSceneScript.value = "";
}

async function generateNewSceneDraft() {
  if (!project.value || addSceneGeneratePending.value) return;

  addSceneGeneratePending.value = true;
  addSceneGenerateError.value = "";

  let insertAfterSceneId = null;
  if (addScenePanelPosition.value.startsWith("after-")) {
    insertAfterSceneId = Number(addScenePanelPosition.value.replace("after-", "")) || null;
  }

  try {
    const response = await api.post("/scenes/generate-draft", {
      project_id: project.value.id,
      insert_after_scene_id: insertAfterSceneId,
      scene_type: String(selectedSceneType.value || "Narration").toLowerCase().replace(/\s+/g, "_"),
      current_text: newSceneScript.value.trim(),
    });

    newSceneScript.value = response.data?.data?.draft?.candidate || newSceneScript.value;
  } catch (requestError) {
    addSceneGenerateError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      "AI draft failed.";
  } finally {
    addSceneGeneratePending.value = false;
  }
}

function scriptSaveCopy() {
  if (scriptSaveState.value === "pending") return "Unsaved changes";
  if (scriptSaveState.value === "saving") return "Saving...";
  if (scriptSaveState.value === "saved") return "Saved";
  if (scriptSaveState.value === "error") return scriptSaveError.value || "Save failed";
  return "";
}

function voiceSaveCopy() {
  if (voiceSaveState.value === "pending") return "Unsaved voice changes";
  if (voiceSaveState.value === "saving") return "Saving voice...";
  if (voiceSaveState.value === "saved") return "Voice saved";
  if (voiceSaveState.value === "error") return voiceSaveError.value || "Voice save failed";
  return "";
}

function captionSaveCopy() {
  if (captionSaveState.value === "pending") return "Unsaved captions";
  if (captionSaveState.value === "saving") return "Saving captions...";
  if (captionSaveState.value === "saved") return "Captions saved";
  if (captionSaveState.value === "error") return captionSaveError.value || "Caption save failed";
  return "";
}

function exportStatusCopy(job) {
  if (!job) return "";
  if (job.status === "completed") return "Export ready";
  if (job.status === "failed") return "Export failed";
  if (job.status === "processing") return `Exporting ${job.progress_percent || 0}%`;
  if (job.status === "queued") return "Export queued";
  return `Export ${job.status}`;
}

function formatSceneDuration(value) {
  const amount = Number(value || 0);
  if (!amount) return "—";
  return `${amount.toFixed(1)}s`;
}

function previewWords(text, highlightMode, progress) {
  const mode = highlightMode || captionHighlightDraft.value || "keywords";
  const pct = Math.min(1, Math.max(0, (progress ?? playProgress.value) / 100));

  if (mode === "none") return [];

  const timedWords = captionTimingWords(activeScene.value);
  if (timedWords.length > 0 && (mode === "word_by_word" || mode === "line_by_line")) {
    return previewTimedWords(timedWords, mode);
  }

  const words = String(text || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (words.length === 0) return [];

  if (mode === "word_by_word") {
    const idx = Math.min(Math.floor(pct * words.length), words.length - 1);
    return [{ text: words[idx], highlighted: true }];
  }

  if (mode === "line_by_line") {
    const wordsPerLine = 4;
    const lines = [];
    for (let i = 0; i < words.length; i += wordsPerLine) {
      lines.push(words.slice(i, i + wordsPerLine));
    }
    const wordIdx = Math.min(Math.floor(pct * words.length), words.length - 1);
    const lineIdx = Math.min(Math.floor(wordIdx / wordsPerLine), lines.length - 1);
    const lineWords = lines[lineIdx];
    const hStart = Math.min(1, lineWords.length - 1);
    const hEnd = Math.min(lineWords.length, hStart + 2);
    return lineWords.map((word, i) => ({
      text: i === lineWords.length - 1 ? word : word + " ",
      highlighted: i >= hStart && i < hEnd,
    }));
  }

  // keywords: full text with 2nd–3rd words highlighted
  const highlightStart = Math.min(1, words.length - 1);
  const highlightEnd = Math.min(words.length, highlightStart + 2);
  return words.map((word, index) => ({
    text: `${word}${index === words.length - 1 ? "" : " "}`,
    highlighted: index >= highlightStart && index < highlightEnd,
  }));
}

function captionTimingWords(scene) {
  const words = scene?.audio_asset?.metadata_json?.caption_timing?.words;
  if (!Array.isArray(words)) return [];

  return words
    .map((word) => ({
      text: String(word?.text || word?.word || "").trim(),
      start: Number(word?.start),
      end: Number(word?.end),
    }))
    .filter((word) => word.text && Number.isFinite(word.start) && Number.isFinite(word.end))
    .sort((a, b) => a.start - b.start);
}

function previewTimedWords(timedWords, mode) {
  const currentSeconds = currentCaptionSeconds();
  const wordIndex = timedWords.findIndex(
    (word) => currentSeconds >= word.start && currentSeconds < word.end
  );
  const activeIndex =
    wordIndex >= 0
      ? wordIndex
      : Math.max(0, Math.min(timedWords.length - 1, timedWords.findLastIndex((word) => word.start <= currentSeconds)));

  if (mode === "word_by_word") {
    return [{ text: timedWords[activeIndex]?.text || "", highlighted: true }];
  }

  const wordsPerLine = 4;
  const lineStart = Math.floor(activeIndex / wordsPerLine) * wordsPerLine;
  return timedWords.slice(lineStart, lineStart + wordsPerLine).map((word, index, lineWords) => ({
    text: `${word.text}${index === lineWords.length - 1 ? "" : " "}`,
    highlighted: lineStart + index === activeIndex,
  }));
}

function currentCaptionSeconds() {
  const audio = audioRef.value;
  if (audio && Number.isFinite(audio.currentTime)) {
    return Math.max(0, audio.currentTime);
  }

  return currentSceneAudioOffset();
}

function fontFamilyValue(font) {
  return `"${font}", sans-serif`;
}

function selectCaptionFont(font) {
  captionFontDraft.value = font;
  fontDropdownOpen.value = false;
}

function formatPreviewTime(value) {
  const whole = Math.max(0, Math.round(Number(value || 0)));
  const mins = Math.floor(whole / 60);
  const secs = whole % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

const totalVideoDuration = computed(() =>
  scenes.value.reduce((sum, s) => sum + sceneDuration(s), 0)
);

function sceneDuration(scene) {
  const audioDuration = Number(scene?.audio_asset?.duration_seconds || 0);
  if (Number.isFinite(audioDuration) && audioDuration > 0) {
    return Math.max(0.1, audioDuration);
  }

  return Math.max(0.1, Number(scene?.duration_seconds || 12));
}

function sceneAtFullElapsed(elapsedSeconds) {
  if (!scenes.value.length) return { scene: null, index: -1, start: 0, duration: 0 };

  const clamped = Math.max(0, Math.min(elapsedSeconds, totalVideoDuration.value || 0));
  let start = 0;

  for (let index = 0; index < scenes.value.length; index += 1) {
    const scene = scenes.value[index];
    const duration = sceneDuration(scene);
    if (clamped < start + duration || index === scenes.value.length - 1) {
      return { scene, index, start, duration };
    }
    start += duration;
  }

  return { scene: scenes.value[0], index: 0, start: 0, duration: sceneDuration(scenes.value[0]) };
}

// Cumulative scene start percentages for scrubber boundary markers (full video mode)
const sceneBoundaryPcts = computed(() => {
  if (scenes.value.length < 2) return [];
  const total = totalVideoDuration.value || 1;
  const pcts = [];
  let cum = 0;
  for (let i = 0; i < scenes.value.length - 1; i++) {
    cum += sceneDuration(scenes.value[i]);
    pcts.push((cum / total) * 100);
  }
  return pcts;
});

const previewContextDuration = computed(() =>
  previewMode.value === "full"
    ? totalVideoDuration.value
    : sceneDuration(activeScene.value)
);

const previewElapsedSecs = computed(() =>
  (playProgress.value / 100) * previewContextDuration.value
);

const previewTimer = computed(() => ({
  elapsed: formatPreviewTime(previewElapsedSecs.value),
  total: formatPreviewTime(previewContextDuration.value),
}));

function formatNotifTime(value) {
  if (!value) return "now";
  const ts = new Date(value).getTime();
  const delta = Math.floor((Date.now() - ts) / 60000);
  if (delta < 1) return "now";
  if (delta < 60) return `${delta} min ago`;
  const hrs = Math.floor(delta / 60);
  if (hrs < 24) return `${hrs}h ago`;
  return `${Math.floor(hrs / 24)}d ago`;
}

function updateVisualCache(sceneId, value) {
  mediaCache.value = {
    ...mediaCache.value,
    visual: {
      ...mediaCache.value.visual,
      [sceneId]: {
        ...(mediaCache.value.visual[sceneId] || {}),
        ...value,
      },
    },
  };
}

function updateAudioCache(sceneId, value) {
  mediaCache.value = {
    ...mediaCache.value,
    audio: {
      ...mediaCache.value.audio,
      [sceneId]: {
        ...(mediaCache.value.audio[sceneId] || {}),
        ...value,
      },
    },
  };
}

function syncActiveSceneMedia(scene) {
  const sceneId = scene?.id;
  if (!sceneId) {
    currentVisualUrl.value = null;
    return;
  }

  const visualUrl = scene.visual_asset?.storage_url ?? null;
  const cachedVisual = mediaCache.value.visual[sceneId];

  if (!visualUrl) {
    currentVisualUrl.value = null;
  } else if (cachedVisual?.loaded) {
    currentVisualUrl.value = visualUrl;
  } else {
    currentVisualUrl.value = null;
  }

  if (visualUrl) {
    preloadSceneVisual(scene);
  }

  if (scene.audio_asset?.storage_url) {
    preloadSceneAudio(scene);
  }
}

function preloadSceneVisual(scene) {
  const sceneId = scene?.id;
  const asset = scene?.visual_asset;
  const url = asset?.storage_url;

  if (!sceneId || !url) return;

  const cached = mediaCache.value.visual[sceneId];
  if (cached?.loaded && cached.url === url) {
    if (activeSceneId.value === sceneId) {
      currentVisualUrl.value = url;
    }
    return;
  }

  const preloadKey = `visual:${sceneId}:${url}`;
  if (mediaPreloaders.has(preloadKey)) return;

  updateVisualCache(sceneId, { url, loaded: false, failed: false });

  const isVideo =
    asset?.asset_type === "video" ||
    String(asset?.mime_type || "").startsWith("video/");

  if (isVideo) {
    const video = document.createElement("video");
    video.preload = "metadata";
    video.muted = true;
    video.playsInline = true;
    const clear = () => mediaPreloaders.delete(preloadKey);

    video.onloadeddata = () => {
      updateVisualCache(sceneId, { url, loaded: true, failed: false });
      if (activeSceneId.value === sceneId) {
        currentVisualUrl.value = url;
      }
      clear();
    };
    video.onerror = () => {
      updateVisualCache(sceneId, { url, loaded: false, failed: true });
      if (activeSceneId.value === sceneId) {
        visualLoadFailed.value = true;
        currentVisualUrl.value = null;
      }
      clear();
    };

    mediaPreloaders.set(preloadKey, video);
    video.src = url;
    video.load();
    return;
  }

  const image = new Image();
  image.onload = () => {
    updateVisualCache(sceneId, { url, loaded: true, failed: false });
    if (activeSceneId.value === sceneId) {
      currentVisualUrl.value = url;
    }
    mediaPreloaders.delete(preloadKey);
  };
  image.onerror = () => {
    updateVisualCache(sceneId, { url, loaded: false, failed: true });
    if (activeSceneId.value === sceneId) {
      visualLoadFailed.value = true;
      currentVisualUrl.value = null;
    }
    mediaPreloaders.delete(preloadKey);
  };

  mediaPreloaders.set(preloadKey, image);
  image.src = url;
}

function preloadSceneAudio(scene) {
  const sceneId = scene?.id;
  const url = scene?.audio_asset?.storage_url;

  if (!sceneId || !url) return;

  const cached = mediaCache.value.audio[sceneId];
  if (cached?.loaded && cached.url === url) return;

  const preloadKey = `audio:${sceneId}:${url}`;
  if (mediaPreloaders.has(preloadKey)) return;

  updateAudioCache(sceneId, { url, loaded: false, failed: false });

  const audio = new Audio();
  audio.crossOrigin = MEDIA_ELEMENT_CROSS_ORIGIN;
  audio.preload = "metadata";
  const clear = () => mediaPreloaders.delete(preloadKey);

  audio.onloadeddata = () => {
    updateAudioCache(sceneId, { url, loaded: true, failed: false });
    if (activeSceneId.value === sceneId) {
      isAudioLoading.value = false;
    }
    clear();
  };
  audio.oncanplaythrough = () => {
    updateAudioCache(sceneId, { url, loaded: true, failed: false });
    if (activeSceneId.value === sceneId) {
      isAudioLoading.value = false;
    }
    clear();
  };
  audio.onerror = () => {
    updateAudioCache(sceneId, { url, loaded: false, failed: true });
    if (activeSceneId.value === sceneId) {
      isAudioLoading.value = false;
    }
    clear();
  };

  mediaPreloaders.set(preloadKey, audio);
  audio.src = url;
  audio.load();
}

async function loadProject() {
  loading.value = true;
  error.value = "";

  try {
    const response = await api.get(`/projects/${projectId.value}`);
    applyProjectPayload(response.data?.data, { preserveActiveScene: false });
    await loadExportJobs();
    subscribeProjectChannel();
  } catch (requestError) {
    error.value =
      requestError.response?.data?.error?.message ?? "Project load failed.";
  } finally {
    loading.value = false;
  }
}

function applyProjectPayload(data, { preserveActiveScene = true } = {}) {
  const previousActiveSceneId = activeSceneId.value;

  project.value = data?.project ?? null;
  projectChannelId.value = project.value?.channel_id ? String(project.value.channel_id) : "";
  projectBrandKitId.value = project.value?.brand_kit_id ? String(project.value.brand_kit_id) : "";
  selectedMusicTrackId.value = project.value?.music_asset_id ?? null;
  const ms = project.value?.music_settings_json ?? {};
  musicVolume.value = ms.volume ?? 30;
  musicDuckVolume.value = ms.duck_volume ?? 8;
  musicFadeInMs.value = ms.fade_in_ms ?? 500;
  musicLoop.value = ms.loop ?? true;
  musicDuckDuringVoice.value = ms.duck_during_voice ?? true;
  scenes.value = (data?.scenes ?? []).map((scene) => normalizeScenePayload(scene));
  hookOptions.value = data?.hook_options ?? [];

  if (preserveActiveScene && scenes.value.some((scene) => scene.id === previousActiveSceneId)) {
    activeSceneId.value = previousActiveSceneId;
  } else {
    activeSceneId.value = scenes.value[0]?.id ?? null;
  }

  scenes.value.forEach((scene) => {
    preloadSceneVisual(scene);
    preloadSceneAudio(scene);
  });
}

async function refreshProjectPayload() {
  const response = await api.get(`/projects/${projectId.value}`);
  applyProjectPayload(response.data?.data, { preserveActiveScene: true });
}

async function loadMe() {
  try {
    const response = await api.get("/me");
    mePayload.value = response.data?.data?.user ?? null;
    await Promise.all([loadVoiceProfiles(), loadChannels(), loadBrandKits(), loadMusicTracks()]);
    await loadNotifications();
    subscribeWorkspaceNotifications();
  } catch {
    mePayload.value = null;
    voiceProfiles.value = [];
    channels.value = [];
    brandKits.value = [];
  }
}

async function loadVoiceProfiles() {
  try {
    const response = await api.get("/voice-profiles");
    voiceProfiles.value = response.data?.data?.voice_profiles ?? [];
  } catch {
    voiceProfiles.value = [];
  }
}

async function loadChannels() {
  try {
    const response = await api.get("/channels");
    channels.value = response.data?.data?.channels ?? [];
  } catch {
    channels.value = [];
  }
}

async function loadBrandKits() {
  try {
    const response = await api.get("/brand-kits");
    brandKits.value = response.data?.data?.brand_kits ?? [];
  } catch {
    brandKits.value = [];
  }
}

async function loadMusicTracks() {
  try {
    const response = await api.get("/assets", { params: { asset_type: "music", per_page: 50 } });
    musicTracks.value = response.data?.data?.assets ?? [];
  } catch {
    musicTracks.value = [];
  }
}

async function loadMyImageAssets() {
  if (myImageLoading.value) return;
  myImageLoading.value = true;
  try {
    const response = await api.get("/assets", { params: { asset_type: "image", per_page: 60 } });
    myImageAssets.value = response.data?.data?.assets ?? [];
  } catch {
    myImageAssets.value = [];
  } finally {
    myImageLoading.value = false;
  }
}

async function loadCustomAudioAssets() {
  if (customAudioLoading.value) return;
  customAudioLoading.value = true;
  try {
    const response = await api.get("/assets", { params: { asset_type: "audio", per_page: 60 } });
    customAudioAssets.value = response.data?.data?.assets ?? [];
  } catch {
    customAudioAssets.value = [];
  } finally {
    customAudioLoading.value = false;
  }
}

async function assignAssetImage(asset) {
  if (!activeScene.value || visualSwapPending.value) return;
  visualSwapPending.value = true;
  visualSwapError.value = "";
  visualLoadFailed.value = false;
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      visual_asset_id: asset.id,
      visual_type: "image_montage",
      visual_prompt: asset.title || "",
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) {
      replaceSceneInCollection(updatedScene);
      preloadSceneVisual(updatedScene);
    }
  } catch (err) {
    visualSwapError.value = err?.response?.data?.error?.message || "Failed to assign image.";
  } finally {
    visualSwapPending.value = false;
  }
}

async function assignCustomAudio(asset) {
  if (!activeScene.value) return;
  const currentVoiceSettings = activeScene.value.voice_settings || {};
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      voice_settings_json: {
        ...currentVoiceSettings,
        audio_asset_id: asset.id,
        is_outdated: false,
        custom_audio: true,
      },
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) replaceSceneInCollection(updatedScene);
  } catch (err) {
    console.error("Failed to assign custom audio", err);
  }
}

function openMediaPicker(mode) {
  mediaPickerMode.value = mode;
  mediaPickerVisible.value = true;
}

async function saveAudiogramSettings({ apply = false } = {}) {
  if (!activeScene.value) return;
  clearTimeout(audiogramSaveTimer);
  audiogramSaveState.value = "saving";
  const existing = activeScene.value.image_generation_settings ?? activeScene.value.image_generation_settings_json ?? {};
  const payload = {
    image_generation_settings_json: {
      ...existing,
      audiogram_style: audiogramStyle.value,
      audiogram_color: audiogramColor.value,
      audiogram_bg: audiogramBg.value,
    },
  };
  if (apply) {
    payload.visual_type = "waveform";
    payload.visual_asset_id = null;
  }
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, payload);
    const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updated) replaceSceneInCollection(updated);
    audiogramSaveState.value = "saved";
    setTimeout(() => { audiogramSaveState.value = "idle"; }, 1400);
  } catch {
    audiogramSaveState.value = "idle";
  }
}

function selectAudiogramStyle(key) {
  audiogramStyle.value = key;
  saveAudiogramSettings();
}
function selectAudiogramColor(color) {
  audiogramColor.value = color;
  saveAudiogramSettings();
}
function queueAudiogramColorSave() {
  clearTimeout(audiogramSaveTimer);
  audiogramSaveTimer = setTimeout(() => saveAudiogramSettings(), 600);
}
function selectAudiogramBg(key) {
  audiogramBg.value = key;
  saveAudiogramSettings();
}

function handleMediaPickerSelect({ mode, item }) {
  if (mode === "visual") {
    if (item._type === "asset") {
      const isVid = item.asset_type === "video" || String(item.mime_type ?? "").startsWith("video/");
      assignAssetVisual(item, isVid ? "background_loop" : "image_montage");
    }
  } else if (mode === "music") {
    if (item._type === "no-music") {
      selectMusicTrack(null);
    } else if (item._type === "track" || item._type === "asset") {
      selectMusicTrack(item.id);
    }
  } else if (mode === "sound") {
    if (item._type === "asset") assignSceneSound(item);
  }
}

async function assignSceneSound(asset) {
  if (!activeScene.value) return;
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      sound_asset_id: asset ? asset.id : null,
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) replaceSceneInCollection(updatedScene);
  } catch (err) {
    console.error("Failed to assign sound", err);
  }
}

async function assignAssetVisual(asset, visualType) {
  if (!activeScene.value || visualSwapPending.value) return;
  visualSwapPending.value = true;
  visualSwapError.value = "";
  visualLoadFailed.value = false;
  try {
    const response = await api.patch(`/scenes/${activeScene.value.id}`, {
      visual_asset_id: asset.id,
      visual_type: visualType,
      visual_prompt: asset.title || "",
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updatedScene) {
      replaceSceneInCollection(updatedScene);
      preloadSceneVisual(updatedScene);
    }
  } catch (err) {
    visualSwapError.value = err?.response?.data?.error?.message || "Failed to assign visual.";
  } finally {
    visualSwapPending.value = false;
  }
}

async function persistMusicSettings() {
  if (!project.value) return;
  musicSaveState.value = "saving";
  musicSaveError.value = "";
  try {
    const response = await api.patch(`/projects/${project.value.id}`, {
      music_asset_id: selectedMusicTrackId.value ?? null,
      music_settings_json: {
        volume: musicVolume.value,
        duck_volume: musicDuckVolume.value,
        fade_in_ms: musicFadeInMs.value,
        loop: musicLoop.value,
        duck_during_voice: musicDuckDuringVoice.value,
      },
    });
    project.value = response.data?.data?.project ?? project.value;
    musicSaveState.value = "saved";
  } catch (err) {
    musicSaveError.value = err?.response?.data?.error?.message ?? "Failed to save music settings.";
    musicSaveState.value = "idle";
  }
}

function scheduleMusicSave() {
  musicSaveState.value = "idle";
  clearTimeout(musicSaveTimer);
  musicSaveTimer = setTimeout(persistMusicSettings, 500);
}

async function saveProjectDefaults() {
  if (!project.value || projectDefaultsSaveState.value === "saving") return;

  projectDefaultsSaveState.value = "saving";
  projectDefaultsSaveError.value = "";

  try {
    const response = await api.patch(`/projects/${project.value.id}`, {
      channel_id: projectChannelId.value ? Number(projectChannelId.value) : null,
      brand_kit_id: projectBrandKitId.value ? Number(projectBrandKitId.value) : null,
    });

    project.value = response.data?.data?.project ?? project.value;
    projectChannelId.value = project.value?.channel_id ? String(project.value.channel_id) : "";
    projectBrandKitId.value = project.value?.brand_kit_id ? String(project.value.brand_kit_id) : "";
    projectDefaultsSaveState.value = "saved";
  } catch (requestError) {
    projectDefaultsSaveError.value =
      requestError.response?.data?.error?.message ?? "Could not save project defaults.";
    projectDefaultsSaveState.value = "error";
  }
}

watch(projectChannelId, (nextChannelId, previousChannelId) => {
  const channel = channels.value.find((item) => String(item.id) === String(nextChannelId));
  const previousChannel = channels.value.find((item) => String(item.id) === String(previousChannelId));

  if (!channel) return;

  if (!projectBrandKitId.value || String(projectBrandKitId.value) === String(previousChannel?.brand_kit_id || "")) {
    projectBrandKitId.value = channel.brand_kit_id ? String(channel.brand_kit_id) : "";
  }
});

async function loadNotifications() {
  try {
    const response = await api.get("/notifications");
    notifications.value = response.data?.data?.notifications ?? [];
  } catch {
    notifications.value = [];
  }
}

async function loadExportJobs() {
  if (!projectId.value) return;

  try {
    const response = await api.get(`/projects/${projectId.value}/exports`);
    exportJobs.value = response.data?.data?.export_jobs ?? [];
  } catch {
    exportJobs.value = [];
  }
}

async function markNotificationRead(notificationId) {
  try {
    await api.post(`/notifications/${notificationId}/read`);
    notifications.value = notifications.value.map((item) =>
      item.id === notificationId ? { ...item, is_read: true } : item
    );
  } catch {
    // no-op
  }
}

async function markAllRead() {
  const unread = notifications.value.filter((item) => !item.is_read);
  await Promise.all(unread.map((item) => markNotificationRead(item.id)));
}

function pushToast(notification) {
  notificationToasts.value = [notification, ...notificationToasts.value].slice(0, 3);
  window.setTimeout(() => {
    notificationToasts.value = notificationToasts.value.filter((toast) => toast.id !== notification.id);
  }, 5000);
}

function subscribeWorkspaceNotifications() {
  const echo = getEcho();
  const workspaceId = mePayload.value?.workspace_id;

  if (!echo || !workspaceId) return;

  if (workspaceChannelName) {
    echo.leave(workspaceChannelName);
  }

  workspaceChannelName = `workspace.${workspaceId}`;

  echo.private(workspaceChannelName).listen(".notification.created", (payload) => {
    const normalized = {
      id: payload.id,
      type: payload.type,
      title: payload.title,
      message: payload.message,
      payload: payload.payload,
      is_read: payload.is_read,
      created_at: payload.created_at,
    };

    notifications.value = [normalized, ...notifications.value].slice(0, 50);
    pushToast(normalized);
  });
}

function unsubscribeWorkspaceNotifications() {
  const echo = getEcho();

  if (echo && workspaceChannelName) {
    echo.leave(workspaceChannelName);
  }
}

async function logout() {
  await authStore.logout();
  router.push({ name: "login" });
}

function selectScene(sceneId) {
  if (sceneId === activeSceneId.value) return;

  stopPreviewPlay();
  playProgress.value = 0;
  flushActiveSceneDrafts().then((flushed) => {
    if (flushed === false) return;
    activeSceneId.value = sceneId;
  });
}

function skipToScene(direction) {
  stopPreviewPlay();
  const idx = activeSceneIndex.value;
  const next = scenes.value[idx + direction];
  if (next) {
    flushActiveSceneDrafts().then((flushed) => {
      if (flushed === false) return;
      activeSceneId.value = next.id;
    });
  }
}

async function setPreviewMode(mode) {
  if (previewMode.value === mode) return;

  stopPreviewPlay();

  if (mode === "full" && scenes.value[0]?.id && activeSceneId.value !== scenes.value[0].id) {
    const flushed = await flushActiveSceneDrafts();
    if (flushed === false) return;
    activeSceneId.value = scenes.value[0].id;
  }

  previewMode.value = mode;
  playProgress.value = 0;
}

function togglePreviewPlay() {
  unlockWaveformAudio();
  isPreviewPlaying.value ? stopPreviewPlay() : startPreviewPlay();
}

async function startPreviewPlay() {
  if (!scenes.value.length) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  if (previewMode.value === "full") {
    syncFullPreviewScene();
  }

  stopMusicAudition();
  isPreviewPlaying.value = true;
  nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
  nextTick(() => playPreviewMusic());
  const TICK = 50;
  previewPlayTimer = window.setInterval(() => {
    const dur = previewContextDuration.value || 1;

    if (previewMode.value === "scene") {
      // Drive progress from actual audio position so captions stay in sync
      const audio = audioRef.value;
      if (
        audio &&
        !audio.paused &&
        Number.isFinite(audio.duration) &&
        audio.duration > 0
      ) {
        const newProgress = (audio.currentTime / audio.duration) * 100;
        if (audio.ended || newProgress >= 100) {
          playProgress.value = 0;
          playActiveSceneAudio(0);
        } else {
          playProgress.value = newProgress;
        }
      } else {
        // Audio not ready yet — advance timer as fallback
        playProgress.value += (100 / dur) * (TICK / 1000);
        if (playProgress.value >= 100) {
          playProgress.value = 0;
          playActiveSceneAudio(0);
        }
      }
    } else {
      const audioDrivenProgress = fullPreviewAudioProgress();
      playProgress.value =
        audioDrivenProgress ??
        playProgress.value + (100 / dur) * (TICK / 1000);

      if (playProgress.value >= 100) {
        playProgress.value = 100;
        stopPreviewPlay();
      } else {
        syncFullPreviewScene();
      }
    }
  }, TICK);
}

function stopPreviewPlay() {
  isPreviewPlaying.value = false;
  if (previewPlayTimer) {
    window.clearInterval(previewPlayTimer);
    previewPlayTimer = null;
  }
  if (audioRef.value) {
    audioRef.value.pause();
  }
  stopPreviewMusic();
}

async function timelineSeek(pct) {
  if (previewMode.value !== "full") await setPreviewMode("full");
  playProgress.value = Math.max(0, Math.min(100, pct));
  syncFullPreviewScene();
  if (isPreviewPlaying.value) {
    nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
    nextTick(() => playPreviewMusic());
  } else if (audioRef.value) {
    audioRef.value.currentTime = currentSceneAudioOffset();
  }
}

async function timelineReorder(newSceneIds) {
  const previousScenes = [...scenes.value];
  const reordered = newSceneIds.map((id, orderIndex) => {
    const sc = scenes.value.find(s => s.id === id);
    return { ...sc, scene_order: orderIndex + 1 };
  }).filter(Boolean);
  scenes.value = reordered;

  try {
    const response = await api.patch("/scenes/reorder", {
      project_id: project.value.id,
      scene_ids: newSceneIds,
    });
    scenes.value = sortScenesByOrder(
      (response.data?.data?.scenes ?? []).map(s => normalizeScenePayload(s)).filter(Boolean)
    );
  } catch {
    scenes.value = previousScenes;
  }
}

function scrubberSeek(event) {
  const track = event.currentTarget.querySelector(".scrubber-track");
  if (!track) return;
  const rect = track.getBoundingClientRect();
  playProgress.value = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100));
  if (previewMode.value === "full") {
    syncFullPreviewScene();
  }
  if (isPreviewPlaying.value) {
    nextTick(() => playActiveSceneAudio(currentSceneAudioOffset()));
    nextTick(() => playPreviewMusic());
  } else if (audioRef.value) {
    audioRef.value.currentTime = currentSceneAudioOffset();
  }
}

function currentSceneAudioOffset() {
  if (previewMode.value === "full") {
    const elapsed = previewElapsedSecs.value;
    const match = sceneAtFullElapsed(elapsed);
    return Math.max(0, elapsed - match.start);
  }

  return previewElapsedSecs.value;
}

function fullPreviewAudioProgress() {
  if (previewMode.value !== "full") return null;

  const audio = audioRef.value;
  if (
    !audio ||
    audio.paused ||
    !Number.isFinite(audio.duration) ||
    audio.duration <= 0 ||
    !activeScene.value
  ) {
    return null;
  }

  const currentSceneIndex = scenes.value.findIndex(
    (scene) => scene.id === activeScene.value.id
  );
  if (currentSceneIndex < 0) return null;

  let sceneStart = 0;
  for (let i = 0; i < currentSceneIndex; i += 1) {
    sceneStart += sceneDuration(scenes.value[i]);
  }

  const activeDuration = sceneDuration(activeScene.value);
  const sceneElapsed = Math.min(audio.currentTime, activeDuration);
  const total = totalVideoDuration.value || 1;

  return Math.max(0, Math.min(100, ((sceneStart + sceneElapsed) / total) * 100));
}

function syncFullPreviewScene() {
  if (previewMode.value !== "full") return;

  const elapsed = previewElapsedSecs.value;
  const match = sceneAtFullElapsed(elapsed);
  if (!match.scene) return;

  pendingPreviewAudioOffset = Math.max(0, elapsed - match.start);
  if (activeSceneId.value !== match.scene.id) {
    activeSceneId.value = match.scene.id;
  }
}

function playActiveSceneAudio(offsetSeconds = 0) {
  if (!audioRef.value || !activeSceneAudioUrl.value || !isPreviewPlaying.value) return;

  isAudioLoading.value = true;
  preloadSceneAudio(activeScene.value);

  const duration = Number.isFinite(audioRef.value.duration) ? audioRef.value.duration : null;
  const boundedOffset = duration
    ? Math.max(0, Math.min(offsetSeconds, Math.max(0, duration - 0.05)))
    : Math.max(0, offsetSeconds);

  try {
    audioRef.value.currentTime = boundedOffset;
  } catch {
    // Some browsers reject currentTime before metadata loads; playback can still begin.
  }

  audioRef.value.play().catch(() => {
    isAudioPlaying.value = false;
    isAudioLoading.value = false;
    syncPreviewMusicVolume();
  });
}

function previewMusicOffset() {
  if (previewMode.value === "full") {
    return previewElapsedSecs.value;
  }

  return 0;
}

function syncPreviewMusicVolume() {
  if (!musicAudioRef.value) return;
  musicAudioRef.value.volume = previewMusicVolume.value;
}

function playPreviewMusic() {
  if (!musicAudioRef.value || !activeMusicTrackUrl.value || !isPreviewPlaying.value) return;

  syncPreviewMusicVolume();

  const offset = previewMusicOffset();
  const duration = Number.isFinite(musicAudioRef.value.duration) ? musicAudioRef.value.duration : null;
  if (!musicLoop.value && duration && offset >= duration) {
    musicAudioRef.value.pause();
    return;
  }

  const nextTime = duration && duration > 0
    ? (musicLoop.value ? offset % duration : offset)
    : offset;

  try {
    musicAudioRef.value.currentTime = Math.max(0, nextTime);
  } catch {
    // Metadata may not be available yet; the browser will start from the beginning.
  }

  musicAudioRef.value.play().catch(() => {});
}

function stopPreviewMusic() {
  if (musicAudioRef.value) {
    musicAudioRef.value.pause();
  }
}

function handleSceneAudioPlay() {
  isAudioPlaying.value = true;
  syncPreviewMusicVolume();
}

function handleSceneAudioPause() {
  isAudioPlaying.value = false;
  syncPreviewMusicVolume();
}

function moodGradient(mood) {
  const map = {
    dark: "linear-gradient(135deg,#1a0a2e,#0d0d2b)",
    upbeat: "linear-gradient(135deg,#0d2e1a,#1a2e0d)",
    calm: "linear-gradient(135deg,#0d1a2e,#1a1a0d)",
    epic: "linear-gradient(135deg,#2e0d0d,#1a1a2e)",
    corporate: "linear-gradient(135deg,#0d2e2e,#1a2e1a)",
  };
  return map[mood?.toLowerCase()] ?? "linear-gradient(135deg,#1a1a2e,#2e1a1a)";
}

function moodEmoji(mood) {
  const map = { dark: "🌑", upbeat: "🎵", calm: "🌊", epic: "⚡", corporate: "💼" };
  return map[mood?.toLowerCase()] ?? "🎵";
}

function trackMoodLabel(track) {
  const mood = (track.tags ?? []).find((t) => t !== "music") ?? "music";
  return mood.charAt(0).toUpperCase() + mood.slice(1) + " · Royalty-free";
}

function selectMusicTrack(trackId) {
  selectedMusicTrackId.value = trackId;
  scheduleMusicSave();

  if (isPreviewPlaying.value) {
    nextTick(() => playPreviewMusic());
  }
}

function toggleMusicAudition(track) {
  if (!track?.storage_url || !musicAuditionRef.value) return;

  unlockWaveformAudio();
  stopPreviewPlay();

  if (auditionMusicTrackId.value === track.id && !musicAuditionRef.value.paused) {
    musicAuditionRef.value.pause();
    auditionMusicTrackId.value = null;
    return;
  }

  auditionMusicTrackId.value = track.id;
  nextTick(() => {
    if (!musicAuditionRef.value) return;
    musicAuditionRef.value.volume = Math.max(0, Math.min(1, Number(musicVolume.value || 0) / 100));
    musicAuditionRef.value.currentTime = 0;
    musicAuditionRef.value.play().catch(() => {
      auditionMusicTrackId.value = null;
    });
  });
}

function stopMusicAudition() {
  if (musicAuditionRef.value) {
    musicAuditionRef.value.pause();
  }
  auditionMusicTrackId.value = null;
}

function moodLabel(mood) {
  const value = String(mood || "other");
  return value.charAt(0).toUpperCase() + value.slice(1);
}

function formatTrackDuration(secs) {
  if (!secs) return "—";
  const m = Math.floor(Number(secs) / 60);
  const s = Math.round(Number(secs) % 60);
  return `${m}:${String(s).padStart(2, "0")}`;
}

function togglePanel(name) {
  panelState.value[name] = !panelState.value[name];
}

function toggleAddScene(position) {
  addScenePanelPosition.value =
    addScenePanelPosition.value === position ? "" : position;
}

function closeAddScene() {
  addScenePanelPosition.value = "";
  resetAddSceneDrafts();
  addSceneGenerateError.value = "";
}

function selectAddSceneVisualMode(mode) {
  addSceneVisualMode.value = mode;
}

function toggleRewriteTools() {
  rewriteToolsVisible.value = !rewriteToolsVisible.value;
  if (!rewriteToolsVisible.value) {
    rewritePreviewVisible.value = false;
    rewritePreviewCopy.value = "";
    rewriteMode.value = "";
    rewriteError.value = "";
  }
}

async function submitRewrite(modeLabel) {
  if (!activeScene.value) return;

  const mode = rewriteModeMap[modeLabel];
  if (!mode) return;

  rewritePending.value = true;
  rewriteError.value = "";
  rewriteMode.value = mode;

  try {
    const response = await api.post(`/scenes/${activeScene.value.id}/rewrite`, {
      mode,
      apply: false,
    });

    rewritePreviewCopy.value =
      response.data?.data?.rewrite?.candidate || "";
    rewritePreviewVisible.value = Boolean(rewritePreviewCopy.value);
  } catch (requestError) {
    rewriteError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      "Rewrite failed.";
    rewritePreviewVisible.value = false;
  } finally {
    rewritePending.value = false;
  }
}

function submitRewriteCustom() {
  const source =
    sceneScriptDraft.value.trim() || activeScene.value?.script_text || "";
  if (!source) return;

  const suffix = rewriteCustomInstruction.value.trim();
  rewriteMode.value = "custom";
  rewriteError.value = "";
  rewritePreviewCopy.value = suffix ? `${source} ${suffix}.` : source;
  rewritePreviewVisible.value = true;
}

function hideRewritePreview() {
  rewritePreviewVisible.value = false;
  rewriteError.value = "";
}

async function acceptRewrite() {
  if (!activeScene.value) return;

  if (rewriteMode.value && rewriteMode.value !== "custom") {
    rewriteApplyPending.value = true;
    rewriteError.value = "";

    try {
      const response = await api.post(`/scenes/${activeScene.value.id}/rewrite`, {
        mode: rewriteMode.value,
        apply: true,
      });

      const updatedScene = response.data?.data?.scene ?? null;
      if (updatedScene) {
        const normalizedScene = normalizeScenePayload(updatedScene);
        scenes.value = scenes.value.map((scene) =>
          scene.id === normalizedScene.id ? { ...scene, ...normalizedScene } : scene
        );
        activeSceneId.value = normalizedScene.id;
        sceneScriptDraft.value = normalizedScene.script_text || "";
      }

      rewritePreviewVisible.value = false;
      return;
    } catch (requestError) {
      rewriteError.value =
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Failed to apply rewrite.";
      return;
    } finally {
      rewriteApplyPending.value = false;
    }
  }

  sceneScriptDraft.value = rewritePreviewCopy.value;
  activeScene.value.voice_settings = {
    ...(activeScene.value.voice_settings || {}),
    is_outdated: true,
  };
  rewritePreviewVisible.value = false;
}

async function regenerateVoice() {
  if (!activeScene.value || voiceRegeneratePending.value) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  voiceRegeneratePending.value = true;
  voiceRegenerateError.value = "";
  isAudioPlaying.value = false;
  isAudioLoading.value = true;

  try {
    const response = await api.post(`/scenes/${activeScene.value.id}/regenerate-voice`);
    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Voice regeneration returned no scene payload.");
    }

    updatedScene.voice_settings = {
      ...(updatedScene.voice_settings || {}),
      is_outdated: false,
    };
    updatedScene.voice_settings_json = updatedScene.voice_settings;

    replaceSceneInCollection(updatedScene);
    activeSceneId.value = updatedScene.id;
    sceneScriptDraft.value = updatedScene.script_text || "";
    voiceProfileKey.value = updatedScene.voice_settings?.voice_id || "alloy";
    voiceSpeedDraft.value = String(updatedScene.voice_settings?.speed ?? 1.0);
    voiceStabilityDraft.value = String(updatedScene.voice_settings?.stability ?? "medium");
    voiceSaveState.value = "saved";
    scriptSaveState.value = "idle";
    preloadSceneAudio(updatedScene);
  } catch (requestError) {
    voiceRegenerateError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Voice regeneration failed.";
    isAudioLoading.value = false;
  } finally {
    voiceRegeneratePending.value = false;
  }
}

async function persistSceneScript(sceneId, scriptText) {
  scriptSaveTimer = null;
  scriptSaveState.value = "saving";
  scriptSaveError.value = "";

  const currentScene = scenes.value.find((scene) => scene.id === sceneId);
  const voiceSettings = {
    ...((currentScene?.voice_settings || currentScene?.voice_settings_json || {}) ?? {}),
    is_outdated: true,
  };

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      script_text: scriptText,
      status: "edited",
      voice_settings_json: voiceSettings,
    });

    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Scene save returned no payload.");
    }

    replaceSceneInCollection(updatedScene);

    if (activeSceneId.value === updatedScene.id) {
      sceneScriptDraft.value = updatedScene.script_text || "";
    }

    scriptSaveState.value = "saved";
    window.setTimeout(() => {
      if (scriptSaveState.value === "saved") {
        scriptSaveState.value = "idle";
      }
    }, 1200);
  } catch (requestError) {
    scriptSaveState.value = "error";
    scriptSaveError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Save failed.";
  }
}

async function persistVoiceSettings(sceneId, nextSettings) {
  voiceSaveTimer = null;
  voiceSaveState.value = "saving";
  voiceSaveError.value = "";

  const currentScene = scenes.value.find((scene) => scene.id === sceneId);
  const currentVoice = currentScene?.voice_settings || {};
  const mergedSettings = {
    ...currentVoice,
    voice_id: nextSettings.voice_id,
    speed: nextSettings.speed,
    stability: nextSettings.stability,
    is_outdated: true,
  };

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      voice_settings_json: mergedSettings,
      status: "edited",
    });

    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Voice save returned no payload.");
    }

    scenes.value = scenes.value.map((scene) =>
      scene.id === updatedScene.id ? { ...scene, ...updatedScene } : scene
    );

    voiceSaveState.value = "saved";
    window.setTimeout(() => {
      if (voiceSaveState.value === "saved") {
        voiceSaveState.value = "idle";
      }
    }, 1200);
  } catch (requestError) {
    voiceSaveState.value = "error";
    voiceSaveError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Voice save failed.";
  }
}

async function persistCaptionSettings(sceneId, nextSettings) {
  captionSaveTimer = null;
  captionSaveState.value = "saving";
  captionSaveError.value = "";

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      caption_settings_json: nextSettings,
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene);

    if (!updatedScene) {
      throw new Error("Caption update returned no scene payload.");
    }

    replaceSceneInCollection(updatedScene);
    captionSaveState.value = "saved";
    window.setTimeout(() => {
      if (captionSaveState.value === "saved") {
        captionSaveState.value = "idle";
      }
    }, 1200);
  } catch (requestError) {
    captionSaveState.value = "error";
    captionSaveError.value =
      requestError.response?.data?.error?.message ?? "Caption save failed";
  }
}

async function persistMotionSettings(sceneId, nextSettings) {
  motionSaveTimer = null;
  motionSaveState.value = "saving";
  motionSaveError.value = "";

  try {
    const response = await api.patch(`/scenes/${sceneId}`, {
      motion_settings_json: nextSettings,
    });
    const updatedScene = normalizeScenePayload(response.data?.data?.scene);
    if (updatedScene) replaceSceneInCollection(updatedScene);
    motionSaveState.value = "saved";
    window.setTimeout(() => {
      if (motionSaveState.value === "saved") motionSaveState.value = "idle";
    }, 1200);
  } catch (requestError) {
    motionSaveState.value = "error";
    motionSaveError.value =
      requestError.response?.data?.error?.message ?? "Motion save failed";
  }
}

function hookScoreClass(score) {
  if (score == null) return "score-none";
  if (score >= 80) return "score-green";
  if (score >= 60) return "score-yellow";
  return "score-red";
}

async function useHook(option) {
  // Apply hook text to the first hook-type scene (or the first scene if none).
  const hookScene =
    scenes.value.find((s) => s.scene_type === "hook") ?? scenes.value[0] ?? null;
  if (!hookScene) return;

  try {
    const response = await api.patch(`/scenes/${hookScene.id}`, {
      script_text: option.hook_text,
    });
    const updated = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (updated) {
      replaceSceneInCollection(updated);
      if (activeSceneId.value === hookScene.id) {
        sceneScriptDraft.value = option.hook_text;
      }
    }
  } catch {
    // silent — hook text can be copied manually if PATCH fails
  }
}

async function swapVisual() {
  if (!activeScene.value || visualSwapPending.value) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  visualSwapPending.value = true;
  visualSwapError.value = "";
  visualLoadFailed.value = false;
  currentVisualUrl.value = null;

  try {
    const response = await api.post(`/scenes/${activeScene.value.id}/swap-visual`, {
      query: visualQueryDraft.value || activeScene.value.visual_prompt || "",
      visual_type: selectedVisualType(),
    });

    const updatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!updatedScene) {
      throw new Error("Visual swap returned no payload.");
    }

    replaceSceneInCollection(updatedScene);

    visualQueryDraft.value = updatedScene.visual_prompt || "";
    preloadSceneVisual(updatedScene);
  } catch (requestError) {
    visualSwapError.value =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      requestError.message ||
      "Visual swap failed.";
    visualLoadFailed.value = true;
  } finally {
    visualSwapPending.value = false;
  }
}

async function generateAIImage() {
  if (!activeScene.value || aiImagePending.value || activeSceneAIImagePending.value) return;

  aiImagePending.value = true;
  aiImageError.value = "";

  try {
    await api.post(`/scenes/${activeScene.value.id}/generate-image`, {
      style: visualStyleDraft.value ?? activeScene.value?.visual_style ?? activeScene.value?.image_generation_settings?.style ?? "cinematic",
      prompt_override: aiImagePromptOverride.value || undefined,
    });
    // Reverb fires the result — polling is a safety net if the socket drops
    pollSceneUntilVisual(activeScene.value.id)
  } catch (err) {
    // 409 means the backend already has a generation in progress — treat as pending, not an error
    if (err.response?.status === 409) {
      aiImageError.value = "";
      // Stay pending; Reverb will fire when it completes
      return;
    }
    aiImageError.value =
      err.response?.data?.error?.message ||
      err.message ||
      "Image generation failed.";
    aiImagePending.value = false;
  }
  // aiImagePending stays true until Reverb fires completed/failed
}

async function pollSceneUntilVisual(sceneId, attempt = 0) {
  if (attempt >= 24) {
    aiImagePending.value = false;
    return;
  }

  window.setTimeout(async () => {
    try {
      const response = await api.get(`/scenes/${sceneId}/preview`);
      const refreshed = normalizeScenePayload(response.data?.data?.scene ?? null);

      if (refreshed) {
        replaceSceneInCollection(refreshed);
        if (refreshed.visual_asset) {
          aiImagePending.value = false;
          aiImageError.value = "";
          return;
        }
        if (refreshed.image_generation_settings?.needs_visual) {
          aiImagePending.value = false;
          aiImageError.value =
            refreshed.image_generation_settings?.last_error ||
            "Image generation failed. Please revise the prompt and try again.";
          return;
        }
      }
    } catch {
      // Realtime updates may still arrive; keep polling briefly as a fallback.
    }

    pollSceneUntilVisual(sceneId, attempt + 1);
  }, attempt < 3 ? 2500 : 5000);
}

async function queueExport() {
  if (!project.value || exportPending.value) return;

  const flushed = await flushActiveSceneDrafts();
  if (flushed === false) return;

  exportPending.value = true;
  exportState.value = "saving";

  try {
    const response = await api.post(`/projects/${project.value.id}/export`, {
      aspect_ratio: project.value.aspect_ratio || "9:16",
      language: project.value.primary_language || "en",
      watermark_enabled: false,
    });

    const exportJob = response.data?.data?.export_job ?? null;
    queuedExportJobId.value = exportJob?.id ?? null;
    if (exportJob) {
      exportJobs.value = [
        exportJob,
        ...exportJobs.value.filter((job) => job.id !== exportJob.id),
      ];
    }
    exportState.value = "saved";
    pushToast({
      id: `export-${exportJob?.id || Date.now()}`,
      title: "Export queued",
      message: exportJob?.file_name || "Your export job has been queued.",
      created_at: new Date().toISOString(),
    });
    window.setTimeout(() => {
      if (exportState.value === "saved") {
        exportState.value = "idle";
      }
    }, 2500);
  } catch (requestError) {
    exportState.value = "error";
    const message =
      requestError.response?.data?.error?.message ||
      requestError.response?.data?.message ||
      "Export failed.";
    pushToast({
      id: `export-error-${Date.now()}`,
      title: "Export failed",
      message,
      created_at: new Date().toISOString(),
    });
  } finally {
    exportPending.value = false;
  }
}

let projectChannelName = null;

function subscribeProjectChannel() {
  const echo = getEcho();
  if (!echo || !projectId.value) return;

  projectChannelName = `project.${projectId.value}`;

  echo.private(projectChannelName).listen(".generation.progress", (payload) => {
    if (payload.stage === "tts" && ["completed", "failed"].includes(String(payload.status || ""))) {
      refreshProjectPayload().catch(() => {});
      return;
    }

    if (payload.stage !== "ai_image") return;

    if (payload.status === "completed" && payload.scene_id) {
      // Refresh the scene so the new visual_asset appears in the editor
      api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
        const refreshed = res.data?.data?.scene ?? null;
        if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
      }).catch(() => {});
      aiImagePending.value = false;
      aiImageError.value = "";
    } else if (payload.status === "failed") {
      aiImagePending.value = false;
      aiImageError.value =
        payload.message || "Image generation failed. Please revise the prompt and try again.";
      if (payload.scene_id) {
        api.get(`/scenes/${payload.scene_id}/preview`).then((res) => {
          const refreshed = res.data?.data?.scene ?? null;
          if (refreshed) replaceSceneInCollection(normalizeScenePayload(refreshed));
        }).catch(() => {});
      }
    }
  });

  echo.private(projectChannelName).listen(".export.progress", (payload) => {
    const jobId = Number(payload.export_job_id);
    const idx = exportJobs.value.findIndex((j) => j.id === jobId);
    const nextJob = {
      ...(idx >= 0 ? exportJobs.value[idx] : {}),
      id: jobId,
      status: payload.status,
      progress_percent: payload.progress_percent,
      failure_reason: payload.failure_reason ?? payload.message ?? null,
      file_name: payload.file_name ?? (idx >= 0 ? exportJobs.value[idx]?.file_name : null),
    };

    if (idx >= 0) {
      exportJobs.value = exportJobs.value.map((job) =>
        job.id === jobId
          ? nextJob
          : job
      );
    } else {
      exportJobs.value = [nextJob, ...exportJobs.value];
    }

    if (["completed", "failed"].includes(String(payload.status || ""))) {
      loadExportJobs();
    }
  });
}

function unsubscribeProjectChannel() {
  const echo = getEcho();
  if (echo && projectChannelName) {
    echo.leave(projectChannelName);
    projectChannelName = null;
  }
}

async function createScene(insertAfterSceneId = null) {
  if (!project.value) return;

  const scriptText = newSceneScript.value.trim();
  const sceneType = String(selectedSceneType.value || "Narration")
    .toLowerCase()
    .replace(/\s+/g, "_");
  const addSceneModeTypeMap = {
    stock_video: addSceneStockSubType.value || "stock_clip",
    stock_image: "image_montage",
    ai_image: "ai_image",
    assets: "stock_clip",
  };
  const visualType = addSceneModeTypeMap[addSceneVisualMode.value] ?? "stock_clip";
  const visualQuery = addSceneVisualQuery.value.trim() || scriptText || buildSceneLabel(sceneType);

  try {
    const response = await api.post("/scenes", {
      project_id: project.value.id,
      insert_after_scene_id: insertAfterSceneId,
      scene_type: sceneType,
      label: buildSceneLabel(sceneType),
      script_text: scriptText,
      duration_seconds: scriptText
        ? Math.max(
            3,
            Math.min(12, Math.ceil(scriptText.split(/\s+/).filter(Boolean).length / 3))
          )
        : 3,
      visual_type: visualType,
      visual_prompt: visualQuery || null,
      visual_style: addSceneVisualStyle.value,
    });

    const createdScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (!createdScene) {
      throw new Error("Scene create returned no payload.");
    }

    let nextScene = createdScene;

    if (visualType === "ai_image") {
      aiImagePending.value = true;
      aiImageError.value = "";
      await api.post(`/scenes/${createdScene.id}/generate-image`, {
        style: addSceneVisualStyle.value || "cinematic",
        prompt_override: visualQuery || undefined,
      });
      nextScene = {
        ...createdScene,
        visual_type: "ai_image",
        visual_prompt: visualQuery,
        visual_style: addSceneVisualStyle.value,
      };
    } else if (!["text_card", "waveform"].includes(visualType)) {
      const visualResponse = await api.post(`/scenes/${createdScene.id}/swap-visual`, {
        query: visualQuery,
        visual_type: visualType,
      });
      nextScene = normalizeScenePayload(visualResponse.data?.data?.scene ?? null) || createdScene;
    }

    scenes.value = sortScenesByOrder([
      ...scenes.value.filter((scene) => scene.id !== nextScene.id),
      nextScene,
    ]);
    preloadSceneVisual(nextScene);
    closeAddScene();
    activeSceneId.value = nextScene.id;
    if (visualType === "ai_image") {
      pollSceneUntilVisual(nextScene.id);
    }
  } catch (requestError) {
    pushToast({
      id: `scene-create-error-${Date.now()}`,
      title: "Could not add scene",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        requestError.message ||
        "Scene create failed.",
      created_at: new Date().toISOString(),
    });
  }
}

async function duplicateScene(sceneId) {
  try {
    const response = await api.post(`/scenes/${sceneId}/duplicate`);
    const duplicatedScene = normalizeScenePayload(response.data?.data?.scene ?? null);

    if (!duplicatedScene) {
      throw new Error("Scene duplicate returned no payload.");
    }

    scenes.value = sortScenesByOrder([...scenes.value, duplicatedScene]);
    activeSceneId.value = duplicatedScene.id;
  } catch (requestError) {
    pushToast({
      id: `scene-duplicate-error-${sceneId}`,
      title: "Could not duplicate scene",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Scene duplicate failed.",
      created_at: new Date().toISOString(),
    });
  }
}

function promptDeleteScene(sceneId) {
  if (scenes.value.length <= 1) {
    pushToast({
      id: `scene-delete-blocked-${sceneId}`,
      title: "Cannot delete scene",
      message: "Projects must keep at least one scene.",
      created_at: new Date().toISOString(),
    });
    return;
  }

  deleteSceneTarget.value = scenes.value.find((scene) => scene.id === sceneId) ?? null;
}

function closeDeleteSceneModal() {
  if (deleteScenePending.value) return;
  deleteSceneTarget.value = null;
}

async function confirmDeleteScene() {
  const sceneId = deleteSceneTarget.value?.id;
  if (!sceneId || deleteScenePending.value) return;

  deleteScenePending.value = true;

  try {
    await api.delete(`/scenes/${sceneId}`);
    const remaining = scenes.value
      .filter((scene) => scene.id !== sceneId)
      .map((scene, index) => ({ ...scene, scene_order: index + 1 }));
    scenes.value = remaining;

    if (activeSceneId.value === sceneId) {
      activeSceneId.value = remaining[0]?.id ?? null;
    }
    deleteSceneTarget.value = null;
  } catch (requestError) {
    pushToast({
      id: `scene-delete-error-${sceneId}`,
      title: "Could not delete scene",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Scene delete failed.",
      created_at: new Date().toISOString(),
    });
  } finally {
    deleteScenePending.value = false;
  }
}

async function moveScene(sceneId, direction) {
  if (sceneReorderPendingId.value !== null) return;

  const index = scenes.value.findIndex((scene) => scene.id === sceneId);
  if (index < 0) return;

  const targetIndex = direction === "up" ? index - 1 : index + 1;
  if (targetIndex < 0 || targetIndex >= scenes.value.length) return;

  const previousScenes = [...scenes.value];
  const reordered = [...scenes.value];
  const [movedScene] = reordered.splice(index, 1);
  reordered.splice(targetIndex, 0, movedScene);
  scenes.value = reordered.map((scene, orderIndex) => ({
    ...scene,
    scene_order: orderIndex + 1,
  }));
  sceneReorderPendingId.value = sceneId;

  try {
    const response = await api.patch("/scenes/reorder", {
      project_id: project.value.id,
      scene_ids: reordered.map((scene) => scene.id),
    });

    scenes.value = sortScenesByOrder(
      (response.data?.data?.scenes ?? [])
        .map((scene) => normalizeScenePayload(scene))
        .filter(Boolean)
    );
  } catch (requestError) {
    scenes.value = previousScenes;
    pushToast({
      id: `scene-reorder-error-${sceneId}`,
      title: "Could not reorder scenes",
      message:
        requestError.response?.data?.error?.message ||
        requestError.response?.data?.message ||
        "Scene reorder failed.",
      created_at: new Date().toISOString(),
    });
  } finally {
    sceneReorderPendingId.value = null;
  }
}

async function flushActiveSceneDrafts() {
  const scene = activeScene.value;
  if (!scene) return true;

  try {
    if (scriptSaveTimer || scriptSaveState.value === "pending") {
      if (scriptSaveTimer) {
        window.clearTimeout(scriptSaveTimer);
        scriptSaveTimer = null;
      }

      if (sceneScriptDraft.value !== (scene.script_text || "")) {
        await persistSceneScript(scene.id, sceneScriptDraft.value);
      }
    }

    const savedVoice = scene.voice_settings || {};
    const nextVoice = {
      voice_id: voiceProfileKey.value,
      speed: Number(voiceSpeedDraft.value || 1),
      stability: voiceStabilityDraft.value,
    };

    if (
      voiceSaveTimer ||
      String(savedVoice.voice_id || "alloy") !== nextVoice.voice_id ||
      Number(savedVoice.speed ?? 1) !== nextVoice.speed ||
      String(savedVoice.stability || "medium") !== nextVoice.stability
    ) {
      if (voiceSaveTimer) {
        window.clearTimeout(voiceSaveTimer);
        voiceSaveTimer = null;
      }

      await persistVoiceSettings(scene.id, nextVoice);
    }

    const savedCaptions = activeCaptionSettings.value || {};
    const nextCaptions = {
      enabled: captionEnabledDraft.value,
      style_key: captionStyleDraft.value,
      highlight_mode: captionHighlightDraft.value,
      position: captionPositionDraft.value,
      font: captionFontDraft.value,
      highlight_color: savedCaptions.highlight_color || "#ff6b35",
      preset_id: savedCaptions.preset_id || null,
    };

    if (
      captionSaveTimer ||
      (savedCaptions.enabled !== false) !== nextCaptions.enabled ||
      String(savedCaptions.style_key || "impact") !== nextCaptions.style_key ||
      String(savedCaptions.highlight_mode || "keywords") !== nextCaptions.highlight_mode ||
      String(savedCaptions.position || "bottom_third") !== nextCaptions.position ||
      String(savedCaptions.font || DEFAULT_CAPTION_FONT) !== nextCaptions.font
    ) {
      if (captionSaveTimer) {
        window.clearTimeout(captionSaveTimer);
        captionSaveTimer = null;
      }

      await persistCaptionSettings(scene.id, nextCaptions);
    }
  } catch {
    return false;
  }

  return (
    scriptSaveState.value !== "error" &&
    voiceSaveState.value !== "error" &&
    captionSaveState.value !== "error"
  );
}

function toggleAudioPlayback() {
  if (!audioRef.value || !activeSceneAudioUrl.value) return;
  unlockWaveformAudio();
  isAudioLoading.value = true;
  preloadSceneAudio(activeScene.value);
  if (isAudioPlaying.value) {
    audioRef.value.pause();
    isAudioLoading.value = false;
  } else {
    audioRef.value.currentTime = 0;
    audioRef.value.play().catch(() => {
      isAudioPlaying.value = false;
      isAudioLoading.value = false;
    });
  }
}

onMounted(() => {
  beforeUnloadHandler = (event) => {
    if (
      scriptSaveState.value === "pending" ||
      scriptSaveState.value === "saving" ||
      voiceSaveState.value === "pending" ||
      voiceSaveState.value === "saving" ||
      captionSaveState.value === "pending" ||
      captionSaveState.value === "saving"
    ) {
      event.preventDefault();
      event.returnValue = "";
    }
  };
  window.addEventListener("beforeunload", beforeUnloadHandler);
  loadMe();
  loadProject();
});

watch(showWaveformPreview, (active) => {
  if (active) startWaveformAnimation();
  else stopWaveformAnimation();
}, { immediate: true });

// Reconnect Web Audio when the narration audio element is replaced (scene change)
watch(audioRef, (el) => {
  if (el && showWaveformPreview.value && waveformUserActivated) waveformTryConnectElement(el);
});

watch(musicAudioRef, (el) => {
  if (el && showWaveformPreview.value && waveformUserActivated) waveformTryConnectElement(el);
});

onBeforeUnmount(() => {
  if (exportPollTimer) { clearInterval(exportPollTimer); exportPollTimer = null; }
  stopWaveformAnimation();
  if (waveformAudioCtx) {
    try { waveformAudioCtx.close(); } catch {}
    waveformAudioCtx = null;
    waveformAnalyser = null;
    waveformConnectedCount = 0;
    waveformUserActivated = false;
  }
  if (scriptSaveTimer) {
    window.clearTimeout(scriptSaveTimer);
  }
  if (voiceSaveTimer) {
    window.clearTimeout(voiceSaveTimer);
  }
  if (captionSaveTimer) {
    window.clearTimeout(captionSaveTimer);
  }
  mediaPreloaders.forEach((media) => {
    media.onload = null;
    media.onerror = null;
    media.onloadeddata = null;
    media.oncanplaythrough = null;
  });
  mediaPreloaders.clear();
  unsubscribeWorkspaceNotifications();
  unsubscribeProjectChannel();
  if (beforeUnloadHandler) {
    window.removeEventListener("beforeunload", beforeUnloadHandler);
  }
});
</script>

<template>
  <main class="editor-page">
    <section v-if="loading" class="state-card">Loading project...</section>
    <section v-else-if="error" class="state-card error">{{ error }}</section>

    <div v-else class="editor-shell">
      <AppSidebar :user="mePayload" active-page="editor" :collapsed="timelineOpen" @logout="logout" />

      <div :class="['main', timelineOpen ? 'sidebar-collapsed' : '']">
        <header class="topbar">
          <div class="topbar-left">
            <div class="topbar-title">Editor</div>
            <div class="topbar-breadcrumb">
              <span>{{ projectTitle }}</span> · Scenes, preview, and brand
              controls
            </div>
          </div>

          <div class="topbar-right">
            <div
              v-if="latestExportJob"
              :class="['export-pill', `export-pill-${latestExportJob.status}`]"
            >
              {{ exportStatusCopy(latestExportJob) }}
              <span
                v-if="latestExportJob.status === 'failed' && latestExportJob.failure_reason"
                class="export-fail-info"
                tabindex="0"
              >
                <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-8-5a1 1 0 0 0-1 1v4a1 1 0 1 0 2 0V6a1 1 0 0 0-1-1Zm0 8a1 1 0 1 0 0 2 1 1 0 0 0 0-2Z" clip-rule="evenodd"/></svg>
                <span class="export-fail-tooltip">{{ latestExportJob.failure_reason }}</span>
              </span>
              <template v-if="latestExportJob.status === 'completed' && latestExportDownloadUrl">
                <span class="export-pill-sep">·</span>
                <a
                  :href="latestExportDownloadUrl"
                  target="_blank"
                  rel="noopener"
                  class="export-pill-link"
                >Open ↗</a>
                <a
                  :href="latestExportDownloadUrl"
                  :download="latestExportJob.file_name || 'export.mp4'"
                  class="export-pill-link"
                >Download ↓</a>
              </template>
            </div>
            <button :class="['btn btn-ghost btn-timeline-toggle', timelineOpen ? 'active' : '']" type="button" @click="timelineOpen = !timelineOpen">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="4" rx="1"/><rect x="3" y="10" width="11" height="4" rx="1"/><rect x="3" y="17" width="15" height="4" rx="1"/></svg>
              Timeline
            </button>

            <div class="export-btn-wrap">
              <button
                class="btn btn-primary"
                type="button"
                :disabled="exportPending || !!exportBlockerMessage"
                :title="exportBlockerMessage || ''"
                @click="queueExport"
              >
                {{ exportPending ? "Exporting..." : "Export" }}
              </button>
              <div v-if="exportBlockerMessage" class="export-blocker-tip">
                {{ exportBlockerMessage }}
              </div>
            </div>
            <button class="btn btn-ghost btn-back" type="button" @click="router.push({ name: 'dashboard' })">
              Back to Dashboard
            </button>
            <button class="notif-bell-btn" type="button" title="Notifications" @click="notificationDrawerOpen = !notificationDrawerOpen">
              <svg
                width="16"
                height="16"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                viewBox="0 0 24 24"
              >
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
              </svg>
              <span v-if="unreadCount > 0" class="notif-badge">{{ unreadCount }}</span>
            </button>
          </div>
        </header>

        <div class="editor-body">
        <div class="editor active">
          <div class="editor-sidebar">
            <div class="editor-sidebar-header">
              <div class="editor-sidebar-title">Scenes</div>
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
                      <select v-model="addSceneStockSubType" class="control-value">
                        <option value="stock_clip">Clip</option>
                        <option value="background_loop">BG Loop</option>
                        <option value="text_card">Text Only</option>
                        <option value="waveform">Audiogram</option>
                      </select>
                    </div>
                    <div class="scene-query-label">Search query</div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'abandoned mansion at night' — leave blank to use scene script"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'stock_image'">
                    <div class="scene-query-label">Search query</div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'ai_image'">
                    <div class="micro-label" style="margin:8px 0 6px;">Style</div>
                    <div class="style-picker-grid">
                      <div v-for="s in AI_IMAGE_STYLES" :key="`top-add-style-${s.key}`"
                        :class="['style-opt', addSceneVisualStyle === s.key ? 'selected' : '']"
                        @click="addSceneVisualStyle = addSceneVisualStyle === s.key ? null : s.key">
                        <span class="style-opt-ico">{{ s.icon }}</span>
                        <div class="style-opt-name">{{ s.label }}</div>
                      </div>
                    </div>
                    <div class="scene-query-label">Prompt override <span style="opacity:.5;font-weight:400;">(optional)</span></div>
                    <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="Leave blank to use scene script as the generation prompt"></textarea>
                  </template>

                  <template v-else-if="addSceneVisualMode === 'assets'">
                    <div class="panel-hint-copy" style="text-align:center;padding:16px 0;">
                      <div style="font-size:18px;margin-bottom:6px;">📁</div>
                      Asset picker coming soon.
                    </div>
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

              <template v-for="(scene, index) in scenes" :key="scene.id">
                <div
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
                        <select v-model="addSceneStockSubType" class="control-value">
                          <option value="stock_clip">Clip</option>
                          <option value="background_loop">BG Loop</option>
                          <option value="text_card">Text Only</option>
                          <option value="waveform">Audiogram</option>
                        </select>
                      </div>
                      <div class="scene-query-label">Search query</div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'abandoned mansion at night' — leave blank to use scene script"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'stock_image'">
                      <div class="scene-query-label">Search query</div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="e.g. 'dark forest with fog' — leave blank to use scene script"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'ai_image'">
                      <div class="micro-label" style="margin:8px 0 6px;">Style</div>
                      <div class="style-picker-grid">
                        <div v-for="s in AI_IMAGE_STYLES" :key="`add-style-${s.key}`"
                          :class="['style-opt', addSceneVisualStyle === s.key ? 'selected' : '']"
                          @click="addSceneVisualStyle = addSceneVisualStyle === s.key ? null : s.key">
                          <span class="style-opt-ico">{{ s.icon }}</span>
                          <div class="style-opt-name">{{ s.label }}</div>
                        </div>
                      </div>
                      <div class="scene-query-label">Prompt override <span style="opacity:.5;font-weight:400;">(optional)</span></div>
                      <textarea v-model="addSceneVisualQuery" class="scene-query-input" rows="2" placeholder="Leave blank to use scene script as the generation prompt"></textarea>
                    </template>

                    <template v-else-if="addSceneVisualMode === 'assets'">
                      <div class="panel-hint-copy" style="text-align:center;padding:16px 0;">
                        <div style="font-size:18px;margin-bottom:6px;">📁</div>
                        Asset picker coming soon.
                      </div>
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
            <div class="preview-container">
              <div class="preview-video-bg">
                <video
                  v-if="currentVisualUrl && activeSceneVisualIsVideo"
                  :src="currentVisualUrl"
                  class="preview-image"
                  autoplay
                  loop
                  muted
                  playsinline
                ></video>
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
                <div v-else-if="activeSceneVisualGenerationError" class="preview-loading error">
                  {{ activeSceneVisualGenerationError }}
                </div>
                <div v-else-if="isVisualLoading" class="preview-loading">
                  Loading scene media...
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
                <div class="preview-watermark">FRAMECAST</div>
                <div class="preview-timer">{{ previewTimer.elapsed }}</div>
                <div
                  v-if="captionEnabledDraft && captionHighlightDraft !== 'none'"
                  class="preview-caption"
                  :class="captionPreviewClass"
                  :style="[captionPositionStyle, captionFontStyle]"
                >
                  <span
                    v-for="(word, index) in previewWords(
                      sceneScriptDraft || activeScene?.script_text
                    )"
                    :key="`${index}-${word.text}`"
                    :class="`caption-word ${word.highlighted ? 'highlight' : 'normal'}`"
                    :style="{ color: word.highlighted ? captionHighlightColorDraft : captionColorDraft }"
                  >
                    {{ word.text }}
                  </span>
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
                <button class="play-btn" type="button" @click="togglePreviewPlay">{{ isPreviewPlaying ? '⏸' : '▶' }}</button>
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

          <div class="editor-right">
            <div
              :class="`panel-section ${panelState.script ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('script')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Scene Script</div>
                  <span :class="activeVoiceOutdated ? 'panel-badge warn' : 'panel-badge warn state-hidden'">
                    Voice outdated
                  </span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <textarea
                  v-model="sceneScriptDraft"
                  class="add-scene-textarea script-textarea"
                ></textarea>
                <div class="helper-copy">
                  This text is spoken by the voice and rendered as captions.
                </div>
                <div v-if="scriptSaveCopy()" :class="scriptSaveState === 'error' ? 'script-save-copy error' : 'script-save-copy'">
                  {{ scriptSaveCopy() }}
                </div>
                <div class="panel-inline-actions">
                  <button
                    class="btn btn-ghost btn-sm rewrite-trigger"
                    type="button"
                    @click.stop="toggleRewriteTools"
                  >
                    {{ rewritePending ? "Working..." : "✦ Rewrite with AI" }}
                  </button>
                </div>
                <div v-if="rewriteToolsVisible" class="rewrite-tools">
                  <div class="chips chips-tight">
                    <button
                      v-for="option in rewriteOptions"
                      :key="option"
                      class="chip"
                      :class="{ disabled: rewritePending }"
                      type="button"
                      @click="submitRewrite(option)"
                    >
                      {{ option }}
                    </button>
                  </div>
                  <div class="rewrite-custom">
                    <input
                      v-model="rewriteCustomInstruction"
                      class="rewrite-custom-input"
                      placeholder="Custom instruction..."
                    />
                    <button
                      class="btn btn-ghost btn-sm"
                      type="button"
                      :disabled="rewritePending"
                      @click="submitRewriteCustom"
                    >
                      Apply
                    </button>
                  </div>
                  <div class="rewrite-note">
                    Applies to this scene only · Preserves locked facts
                  </div>
                  <div v-if="rewriteError" class="rewrite-error">
                    {{ rewriteError }}
                  </div>
                </div>
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
              :class="`panel-section ${panelState.visual ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('visual')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Visual Source</div>
                  <span v-if="activeScene?.visual_type === 'ai_image'" class="panel-badge new">AI</span>
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
                    <select v-model="stockVideoSubType" class="control-value">
                      <option value="stock_clip">Clip</option>
                      <option value="background_loop">BG Loop</option>
                    </select>
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
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- AI Image generation -->
                <template v-else-if="selectedSwapVisualSource === 'AI Image'">
                  <!-- Current result preview -->
                  <div v-if="activeScene?.visual_type === 'ai_image'" class="ai-image-result">
                    <div class="ai-image-preview">
                      <div class="ai-image-overlay-badge">✦ AI Generated</div>
                      <img v-if="currentVisualUrl" :src="currentVisualUrl" style="width:100%;height:100%;object-fit:cover;" alt="" />
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
                  <div class="style-picker-grid">
                    <div
                      v-for="s in AI_IMAGE_STYLES"
                      :key="s.key"
                      :class="['style-opt', (visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style) === s.key ? 'selected' : '']"
                      @click="visualStyleDraft = (visualStyleDraft ?? activeScene?.visual_style ?? activeScene?.image_generation_settings?.style) === s.key ? null : s.key"
                    >
                      <span class="style-opt-ico">{{ s.icon }}</span>
                      <div class="style-opt-name">{{ s.label }}</div>
                    </div>
                  </div>

                  <!-- Prompt override -->
                  <div class="micro-label" style="margin-bottom:4px;">
                    Prompt override <span style="font-weight:400;opacity:.5;">(optional)</span>
                  </div>
                  <textarea
                    v-model="aiImagePromptOverride"
                    class="ai-prompt-area"
                    rows="2"
                    placeholder="Leave blank to use scene script as the generation prompt…"
                  ></textarea>

                  <div class="ai-gen-footer">
                    <div class="ai-gen-meta">Provider: <span>DALL-E 3</span> · ~$0.04</div>
                    <button class="btn btn-primary btn-sm" type="button" :disabled="aiImagePending" @click="generateAIImage">
                      {{ aiImagePending ? '✦ Generating…' : '✦ Generate' }}
                    </button>
                  </div>
                  <div v-if="activeScene?.visual_type === 'ai_image'" class="ai-image-actions">
                    <button class="btn btn-ghost btn-sm" style="flex:1;" type="button" :disabled="aiImagePending" @click="generateAIImage">Regenerate</button>
                  </div>
                  <div v-if="aiImageError" class="panel-error-copy">{{ aiImageError }}</div>
                  <div v-if="aiImagePending" class="panel-hint-copy">This takes ~15s</div>
                  <div v-if="visualStyleDraft && activeScene?.visual_type === 'ai_image' && !aiImagePending && visualStyleDraft !== (activeScene?.image_generation_settings?.style ?? activeScene?.visual_style ?? project?.ai_broll_style ?? visualStyleDraft)" class="style-regen-hint">
                    Style changed — regenerate to apply.
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
                  <!-- Apply button if not yet set -->
                  <div v-if="activeScene?.visual_type !== 'waveform'" class="audiogram-apply-state">
                    <div class="asset-empty-state">
                      <div class="asset-empty-icon">🎙️</div>
                      <div class="asset-empty-title">Audiogram</div>
                      <div class="asset-empty-sub">Animated waveform visualization — great for podcast clips and voice-led scenes</div>
                    </div>
                    <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="saveAudiogramSettings({ apply: true })">Apply Audiogram</button>
                  </div>

                  <template v-else>
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

                    <!-- Reset to stock -->
                    <button class="btn btn-ghost btn-sm panel-full-btn" style="margin-top:12px;opacity:.5;" type="button" @click="selectedSwapVisualSource = 'Stock Video'; swapVisual()">
                      Switch to Video instead
                    </button>
                  </template>
                </template>
              </div>
            </div>

            <!-- Motion panel — visible only when scene visual is a still image -->
            <div
              v-if="activeSceneIsStillImage"
              :class="`panel-section ${panelState.motion ? 'collapsed' : ''}`"
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
                  <select v-model="motionEffectDraft" class="control-value">
                    <option value="zoom_in">Zoom In</option>
                    <option value="zoom_out">Zoom Out</option>
                    <option value="pan_left">Pan Left</option>
                    <option value="pan_right">Pan Right</option>
                    <option value="pan_up">Pan Up</option>
                    <option value="pan_down">Pan Down</option>
                    <option value="pan_zoom">Pan + Zoom</option>
                    <option value="static">Static (no motion)</option>
                  </select>
                </div>
                <div v-if="motionEffectDraft !== 'static'" class="control-row">
                  <span class="control-name">Intensity</span>
                  <select v-model="motionIntensityDraft" class="control-value">
                    <option value="subtle">Subtle</option>
                    <option value="moderate">Moderate</option>
                    <option value="dramatic">Dramatic</option>
                  </select>
                </div>
                <div v-if="motionSaveState === 'error'" class="panel-error-copy">{{ motionSaveError }}</div>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.voice ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('voice')">
                <div class="panel-label-row">
                  <div class="panel-label panel-label-tight">Voice</div>
                  <span :class="activeVoiceOutdated ? 'panel-badge warn' : 'panel-badge warn state-hidden'">
                    Outdated
                  </span>
                </div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
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
                  <span class="control-name">Speed</span>
                  <select v-model="voiceSpeedDraft" class="control-value">
                    <option value="0.8">0.8x</option>
                    <option value="1.0">1.0x</option>
                    <option value="1.1">1.1x</option>
                    <option value="1.2">1.2x</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Stability</span>
                  <select v-model="voiceStabilityDraft" class="control-value">
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Voice</span>
                  <select v-model="voiceProfileKey" class="control-value">
                    <option v-for="profile in voiceProfiles" :key="profile.id" :value="profile.provider_voice_key">
                      {{ profile.name }}
                    </option>
                  </select>
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

                <!-- Custom audio from assets -->
                <div class="micro-label" style="margin-top:12px;margin-bottom:6px;">
                  Custom audio
                  <span style="font-weight:400;opacity:.5;"> (replaces generated voice)</span>
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
                <div class="caption-toggle-row">
                  <span></span>
                  <label class="caption-toggle">
                    <input v-model="captionEnabledDraft" type="checkbox" />
                    <span>On</span>
                  </label>
                </div>
                <div class="micro-label" style="margin-bottom:6px;">Caption effect</div>
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
                  <select v-model="captionHighlightDraft" class="control-value">
                    <option value="keywords">Keywords</option>
                    <option value="word_by_word">Word-by-word</option>
                    <option value="line_by_line">Line-by-line</option>
                    <option value="none">None</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Position</span>
                  <select v-model="captionPositionDraft" class="control-value">
                    <option value="bottom_third">Bottom third</option>
                    <option value="center">Center</option>
                    <option value="top_third">Top third</option>
                  </select>
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
                        <span class="font-trigger-name" :style="captionFontStyle">
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

              </div>
            </div>

            <div
              :class="`panel-section ${panelState.music ? 'collapsed' : ''}`"
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
                </div>

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
                  <select v-model="projectChannelId" class="control-value">
                    <option value="">No channel</option>
                    <option v-for="channel in channels" :key="channel.id" :value="String(channel.id)">
                      {{ channel.name }}
                    </option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Brand Kit</span>
                  <select v-model="projectBrandKitId" class="control-value">
                    <option value="">No brand kit</option>
                    <option v-for="brandKit in brandKits" :key="brandKit.id" :value="String(brandKit.id)">
                      {{ brandKit.name }}
                    </option>
                  </select>
                </div>
                <div v-if="selectedProjectChannel" class="micro-copy">
                  Channel defaults can prefill brand kit for future changes. Existing scenes are not rewritten automatically.
                </div>
                <div v-if="projectDefaultsSaveError" class="micro-error">{{ projectDefaultsSaveError }}</div>
                <button class="btn btn-ghost btn-full" type="button" @click="saveProjectDefaults">
                  {{ projectDefaultsSaveState === "saving" ? "Saving…" : projectDefaultsSaveState === "saved" ? "Saved" : "Save Defaults" }}
                </button>
                <div v-if="sortedHookOptions.length" class="hooks-block">
                  <div class="micro-label hooks-title">Hook options — sorted by score</div>
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

    <audio
      v-if="activeSceneAudioUrl"
      ref="audioRef"
      :src="activeSceneAudioUrl"
      :crossorigin="mediaCrossOriginMode(activeSceneAudioUrl)"
      preload="metadata"
      @loadstart="isAudioLoading = true"
      @canplay="isAudioLoading = false"
      @loadeddata="isAudioLoading = false"
      @ended="handleSceneAudioPause"
      @play="handleSceneAudioPlay"
      @pause="handleSceneAudioPause"
      @error="isAudioLoading = false"
    ></audio>
    <audio
      v-if="activeMusicTrackUrl"
      ref="musicAudioRef"
      :src="activeMusicTrackUrl"
      :crossorigin="mediaCrossOriginMode(activeMusicTrackUrl)"
      preload="metadata"
      :loop="musicLoop"
      @loadedmetadata="syncPreviewMusicVolume"
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
  </main>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Space+Mono:wght@400;700&display=swap");
@import url("https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Montserrat&family=Raleway&family=Nunito&family=Lato&family=Roboto+Mono&family=Roboto+Slab&family=Libre+Baskerville&family=Playfair+Display&family=Dancing+Script&family=Fredoka+One&family=Sacramento&family=Luckiest+Guy&family=Orbitron&family=Satisfy&family=Permanent+Marker&family=Noto+Sans&family=Amatic+SC&family=Days+One&family=Rock+Salt&family=New+Rocker&family=Passion+One&family=Indie+Flower&family=Quicksand&family=Shadows+Into+Light&family=Source+Code+Pro&family=Aladin&family=Calligraffitti&display=swap");

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
  margin-left: 220px;
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
  bottom: calc(100% + 8px);
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

.export-pill-link {
  color: var(--text-primary);
  text-decoration: none;
  font-weight: 600;
}

.export-pill-link:hover {
  color: var(--accent);
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
}

.topbar-breadcrumb span {
  color: var(--text-secondary);
}

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
  bottom: 20px;
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
}

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
  bottom: 100px;
  left: 16px;
  right: 16px;
  text-align: center;
}

.caption-word {
  font-size: 22px;
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
  font-size: 16px;
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
  font-family: "Space Mono", monospace;
  font-size: 10px;
  color: rgba(255, 255, 255, 0.3);
}

.preview-timer {
  position: absolute;
  top: 16px;
  right: 16px;
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

.time-display {
  font-family: "Space Mono", monospace;
  font-size: 12px;
  color: var(--text-muted);
}

.editor-right {
  width: 300px;
  border-left: 1px solid var(--border);
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

select.control-value {
  appearance: none;
  cursor: pointer;
  padding-right: 24px;
  background-color: var(--bg-card);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%238b8b9e'%3E%3Cpath d='M2 4l4 4 4-4'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 8px center;
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
.size-opt {
  flex: 1; height: 28px; border-radius: 6px; border: 1px solid var(--color-border);
  background: var(--color-bg-elevated); color: var(--color-text-muted);
  font-size: 11px; font-family: var(--font-mono); cursor: pointer; transition: 0.12s;
}
.size-opt:hover { border-color: rgba(167,139,250,.4); }
.size-opt.active { border-color: #a78bfa; background: rgba(167,139,250,.1); color: #a78bfa; }

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

  .editor {
    grid-template-columns: 1fr;
  }

  .editor-sidebar {
    width: auto;
  }

  .editor-canvas {
    min-height: 520px;
    padding: 24px 16px;
  }
}
</style>
