<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../services/api";
import { getEcho } from "../services/echo";
import { useAuthStore } from "../stores/auth";

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
const activeSceneId = ref(null);
const showUserPopover = ref(false);
const notificationDrawerOpen = ref(false);
const notifications = ref([]);
const notificationToasts = ref([]);
const exportJobs = ref([]);
let workspaceChannelName = null;

const audioRef = ref(null);
const isAudioPlaying = ref(false);
const isAudioLoading = ref(false);
const currentVisualUrl = ref(null);
const visualLoadFailed = ref(false);
const mediaCache = ref({
  visual: {},
  audio: {},
});
const mediaPreloaders = new Map();

const addScenePanelPosition = ref("");
const selectedSceneType = ref("Narration");
const selectedAddSceneVisualSource = ref("Stock Clip");
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
const musicVolume = ref(30);
const musicDuckVolume = ref(8);
const musicFadeInMs = ref(500);
const musicLoop = ref(true);
const musicDuckDuringVoice = ref(true);
let musicSaveTimer = null;
const musicSaveState = ref("idle");
const musicSaveError = ref("");
const voiceProfileKey = ref("alloy");
const voiceSpeedDraft = ref("1.0");
const voiceStabilityDraft = ref("medium");
const voiceSaveState = ref("idle");
const voiceSaveError = ref("");
const visualQueryDraft = ref("");
const visualSwapPending = ref(false);
const visualSwapError = ref("");
// AI Image generation
const aiImageStyle = ref("cinematic");
const aiImagePromptOverride = ref("");
const aiImagePending = ref(false);
const aiImageError = ref("");
const aiImageStyles = ref([]);
const AI_IMAGE_STYLES = [
  { key: "cinematic",   label: "Cinematic" },
  { key: "dark",        label: "Dark" },
  { key: "anime",       label: "Anime" },
  { key: "documentary", label: "Documentary" },
  { key: "minimalist",  label: "Minimalist" },
  { key: "realistic",   label: "Realistic" },
  { key: "vintage",     label: "Vintage" },
  { key: "neon",        label: "Neon" },
];
const exportPending = ref(false);
const exportState = ref("idle");
const queuedExportJobId = ref(null);
const scriptSaveState = ref("idle");
const scriptSaveError = ref("");
const captionEnabledDraft = ref(true);
const captionStyleDraft = ref("impact");
const captionHighlightDraft = ref("keywords");
const captionPositionDraft = ref("bottom_third");
const captionFontDraft = ref("Poppins");
const captionSaveState = ref("idle");
const captionSaveError = ref("");
const CAPTION_FONT_GROUPS = [
  { label: "Display", fonts: ["Bebas Neue", "Days One", "Passion One", "Fredoka One", "Luckiest Guy", "New Rocker", "Aladin"] },
  { label: "Sans-serif", fonts: ["Montserrat", "Raleway", "Lato", "Nunito", "Quicksand", "Noto Sans", "Liberation Sans"] },
  { label: "Serif", fonts: ["Playfair Display", "Roboto Slab", "Libre Baskerville", "Liberation Serif"] },
  { label: "Script", fonts: ["Dancing Script", "Sacramento", "Satisfy", "Shadows Into Light"] },
  { label: "Monospace", fonts: ["Roboto Mono", "Source Code Pro", "Orbitron", "Liberation Mono"] },
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
const activeSceneVisualIsVideo = computed(() => {
  const asset = activeSceneVisualAsset.value;
  if (!asset) return false;
  return asset.asset_type === "video" || String(asset.mime_type || "").startsWith("video/");
});
const activeSceneAudioUrl = computed(
  () => activeScene.value?.audio_asset?.storage_url ?? null
);
const selectedProjectChannel = computed(() =>
  channels.value.find((channel) => String(channel.id) === String(projectChannelId.value)) || null
);
const activeMusicTrack = computed(() =>
  musicTracks.value.find((t) => t.id === selectedMusicTrackId.value) ?? null
);
const musicTrackListItems = computed(() => {
  const groups = {};
  for (const track of musicTracks.value) {
    const mood = track.tags?.find((tag) => tag !== "music") ?? "other";
    if (!groups[mood]) groups[mood] = [];
    groups[mood].push(track);
  }
  const items = [];
  for (const [mood, tracks] of Object.entries(groups)) {
    items.push({ type: "mood", key: `mood-${mood}`, mood });
    for (const track of tracks) {
      items.push({ type: "track", key: `track-${track.id}`, track });
    }
  }
  return items;
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
  () => !currentVisualUrl.value && activeSceneVisualType.value === "waveform"
);
const waveformBars = computed(() =>
  [0.28, 0.52, 0.34, 0.76, 0.42, 0.88, 0.48, 0.66, 0.31, 0.58, 0.4, 0.72]
);
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
const captionPreviewClass = computed(() => {
  if (!captionEnabledDraft.value) return "caption-hidden";
  if (captionStyleDraft.value === "editorial") return "caption-style-editorial";
  if (captionStyleDraft.value === "hacker") return "caption-style-hacker";
  return "caption-style-impact";
});
const captionPositionStyle = computed(() => {
  if (captionPositionDraft.value === "center")
    return { top: "50%", transform: "translateY(-50%)", bottom: "auto" };
  if (captionPositionDraft.value === "top_third")
    return { top: "80px", bottom: "auto" };
  return {};
});
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
const visualSourceOptions = [
  { label: "Stock Clip", icon: "🎬" },
  { label: "BG Loop", icon: "🔁" },
  { label: "AI Image", icon: "🖼" },
  { label: "Text Only", icon: "Aa" },
  { label: "Waveform", icon: "〰" },
];
const visualSourceTypeMap = {
  "Stock Clip": "stock_clip",
  "BG Loop": "background_loop",
  "AI Image": "ai_image",
  "Text Only": "text_card",
  Waveform: "waveform",
};
const visualTypeLabelMap = {
  image_montage: "Stock Clip",
  stock_clip: "Stock Clip",
  background_loop: "BG Loop",
  ai_image: "AI Image",
  text_card: "Text Only",
  waveform: "Waveform",
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
  (scene) => {
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
    selectedSwapVisualSource.value =
      visualTypeLabelMap[String(scene?.visual_type || "")] || "Stock Clip";
    const captionSettings = scene?.caption_settings || scene?.caption_settings_json || {};
    captionEnabledDraft.value = captionSettings.enabled !== false;
    captionStyleDraft.value = String(captionSettings.style_key || "impact");
    captionHighlightDraft.value = String(captionSettings.highlight_mode || "keywords");
    captionPositionDraft.value = String(captionSettings.position || "bottom_third");
    captionFontDraft.value = String(captionSettings.font || "Poppins");
    const motionSettings = scene?.motion_settings || scene?.motion_settings_json || {};
    motionEffectDraft.value = String(motionSettings.effect || "zoom_in");
    motionIntensityDraft.value = String(motionSettings.intensity || "moderate");
    visualStyleDraft.value = scene?.visual_style ?? null;
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
    if (audioRef.value) {
      audioRef.value.pause();
      audioRef.value.load();
      isAudioPlaying.value = false;
    }
    isAudioLoading.value = false;
    visualLoadFailed.value = false;
    syncActiveSceneMedia(scene);
  },
  { immediate: true }
);

watch(activeSceneVisualUrl, () => {
  visualLoadFailed.value = false;
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

watch([captionEnabledDraft, captionStyleDraft, captionHighlightDraft, captionPositionDraft, captionFontDraft], () => {
  const scene = activeScene.value;

  if (!scene) return;

  const savedCaptions = activeCaptionSettings.value || {};
  const nextSettings = {
    enabled: captionEnabledDraft.value,
    style_key: captionStyleDraft.value,
    highlight_mode: captionHighlightDraft.value,
    position: captionPositionDraft.value,
    font: captionFontDraft.value,
    highlight_color: savedCaptions.highlight_color || "#ff6b35",
    preset_id: savedCaptions.preset_id || null,
  };

  if (
    (savedCaptions.enabled !== false) === nextSettings.enabled &&
    String(savedCaptions.style_key || "impact") === nextSettings.style_key &&
    String(savedCaptions.highlight_mode || "keywords") === nextSettings.highlight_mode &&
    String(savedCaptions.position || "bottom_third") === nextSettings.position &&
    String(savedCaptions.font || "Poppins") === nextSettings.font
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
  if (scene?.visual_type === "waveform") return "waveform";
  if (scene?.visual_type === "ai_image") return "ai image";
  if (scene?.visual_type === "background_loop") return "bg loop";
  if (scene?.visual_type === "stock_clip") return "stock clip";
  if (scene?.visual_asset?.asset_type === "video") return "stock clip";
  if (scene?.visual_asset?.asset_type === "image") return "ai image";
  return "bg loop";
}

function selectedVisualType() {
  return visualSourceTypeMap[selectedSwapVisualSource.value] || "stock_clip";
}

function sceneVoiceOutdated(scene) {
  return Boolean(scene?.voice_settings?.is_outdated);
}

function normalizeScenePayload(scene) {
  if (!scene) return null;

  return {
    ...scene,
    voice_settings: scene.voice_settings ?? scene.voice_settings_json ?? null,
    caption_settings: scene.caption_settings ?? scene.caption_settings_json ?? null,
    locked_fields: scene.locked_fields ?? scene.locked_fields_json ?? null,
  };
}

function sortScenesByOrder(nextScenes) {
  return [...nextScenes].sort(
    (left, right) => Number(left.scene_order || 0) - Number(right.scene_order || 0)
  );
}

function replaceSceneInCollection(updatedScene) {
  scenes.value = sortScenesByOrder(
    scenes.value.map((scene) =>
      scene.id === updatedScene.id ? { ...scene, ...updatedScene } : scene
    )
  );
}

function buildSceneLabel(sceneType) {
  const label = humanizeSceneType(sceneType);
  return label === "Narration" ? `Scene ${scenes.value.length + 1}` : label;
}

function resetAddSceneDrafts() {
  selectedSceneType.value = "Narration";
  selectedAddSceneVisualSource.value = "Stock Clip";
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
  if (job.status === "failed") return job.failure_reason || "Export failed";
  if (job.status === "processing") return `Exporting ${job.progress_percent || 0}%`;
  if (job.status === "queued") return "Export queued";
  return `Export ${job.status}`;
}

function formatSceneDuration(value) {
  const amount = Number(value || 0);
  if (!amount) return "—";
  return `${amount.toFixed(1)}s`;
}

function previewWords(text) {
  const words = String(text || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);
  if (words.length === 0) return [];

  const highlightStart = Math.min(1, words.length - 1);
  const highlightEnd = Math.min(words.length, highlightStart + 2);

  return words.map((word, index) => ({
    text: `${word}${index === words.length - 1 ? "" : " "}`,
    highlighted: index >= highlightStart && index < highlightEnd,
  }));
}

function formatPreviewTime(value) {
  const whole = Math.max(0, Math.round(Number(value || 0)));
  const mins = Math.floor(whole / 60);
  const secs = whole % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

const previewTimer = computed(() => {
  const total = Number(
    project.value?.duration_target_seconds ||
      scenes.value.reduce(
        (sum, scene) => sum + Number(scene.duration_seconds || 0),
        0
      )
  );
  const elapsed = scenes.value
    .slice(0, Math.max(activeSceneIndex.value, 0))
    .reduce((sum, scene) => sum + Number(scene.duration_seconds || 0), 0);

  return {
    elapsed: formatPreviewTime(elapsed),
    total: formatPreviewTime(total),
  };
});

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
    project.value = response.data?.data?.project ?? null;
    projectChannelId.value = project.value?.channel_id ? String(project.value.channel_id) : "";
    projectBrandKitId.value = project.value?.brand_kit_id ? String(project.value.brand_kit_id) : "";
    selectedMusicTrackId.value = project.value?.music_asset_id ?? null;
    const ms = project.value?.music_settings_json ?? {};
    musicVolume.value = ms.volume ?? 30;
    musicDuckVolume.value = ms.duck_volume ?? 8;
    musicFadeInMs.value = ms.fade_in_ms ?? 500;
    musicLoop.value = ms.loop ?? true;
    musicDuckDuringVoice.value = ms.duck_during_voice ?? true;
    scenes.value = response.data?.data?.scenes ?? [];
    hookOptions.value = response.data?.data?.hook_options ?? [];
    activeSceneId.value = scenes.value[0]?.id ?? null;
    scenes.value.forEach((scene) => {
      preloadSceneVisual(scene);
      preloadSceneAudio(scene);
    });
    await loadExportJobs();
    subscribeProjectChannel();
  } catch (requestError) {
    error.value =
      requestError.response?.data?.error?.message ?? "Project load failed.";
  } finally {
    loading.value = false;
  }
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

  flushActiveSceneDrafts().then((flushed) => {
    if (flushed === false) return;
    activeSceneId.value = sceneId;
  });
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

    replaceSceneInCollection(updatedScene);
    activeSceneId.value = updatedScene.id;
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
  if (!activeScene.value || aiImagePending.value) return;

  aiImagePending.value = true;
  aiImageError.value = "";

  try {
    await api.post(`/scenes/${activeScene.value.id}/generate-image`, {
      style: aiImageStyle.value,
      prompt_override: aiImagePromptOverride.value || undefined,
    });
    // Result comes back via Reverb generation.progress event — no polling needed
  } catch (err) {
    aiImageError.value =
      err.response?.data?.error?.message ||
      err.message ||
      "Image generation failed.";
    aiImagePending.value = false;
  }
  // aiImagePending stays true until Reverb fires completed/failed
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
      aiImageError.value = payload.message || "Image generation failed.";
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
  const visualType =
    visualSourceTypeMap[selectedAddSceneVisualSource.value] || "stock_clip";
  const visualQuery = scriptText || buildSceneLabel(sceneType);

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
      visual_prompt: scriptText || null,
    });

    const createdScene = normalizeScenePayload(response.data?.data?.scene ?? null);
    if (!createdScene) {
      throw new Error("Scene create returned no payload.");
    }

    let nextScene = createdScene;

    if (!["text_card", "waveform"].includes(visualType)) {
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
      String(savedCaptions.font || "Poppins") !== nextCaptions.font
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

onBeforeUnmount(() => {
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
      <aside class="sidebar">
        <button
          class="sidebar-logo"
          type="button"
          @click="router.push({ name: 'dashboard' })"
        >
          F
        </button>

        <div class="sidebar-nav">
          <button
            class="nav-item"
            type="button"
            @click="router.push({ name: 'dashboard' })"
          >
            <svg
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              viewBox="0 0 24 24"
            >
              <rect x="3" y="3" width="7" height="7" rx="1"></rect>
              <rect x="14" y="3" width="7" height="7" rx="1"></rect>
              <rect x="3" y="14" width="7" height="7" rx="1"></rect>
              <rect x="14" y="14" width="7" height="7" rx="1"></rect>
            </svg>
            <span class="tooltip">Dashboard</span>
          </button>

          <button class="nav-item" type="button" @click="router.push({ name: 'asset-library' })">
            <svg
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              viewBox="0 0 24 24"
            >
              <path
                d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"
              ></path>
            </svg>
            <span class="tooltip">Asset Library</span>
          </button>

          <button
            class="nav-item"
            type="button"
            @click="router.push({ name: 'settings' })"
          >
            <svg
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              stroke-width="1.8"
              viewBox="0 0 24 24"
            >
              <circle cx="12" cy="12" r="3"></circle>
              <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            <span class="tooltip">Settings</span>
          </button>
        </div>

        <div class="sidebar-bottom">
          <button
            class="avatar"
            type="button"
            @click="showUserPopover = !showUserPopover"
          >
            {{ mePayload?.name?.[0] || "K" }}
          </button>

          <div v-if="showUserPopover" class="user-popover">
            <div class="user-popover-name">{{ mePayload?.name || "User" }}</div>
            <div class="user-popover-email">{{ mePayload?.email || "—" }}</div>
            <div class="user-popover-divider"></div>
            <button class="user-popover-action" type="button" @click="logout">
              Log out
            </button>
          </div>
        </div>
      </aside>

      <div class="main">
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
            <button class="btn btn-ghost" type="button" @click="router.push({ name: 'dashboard' })">+ New Video</button>
            <button class="btn btn-ghost" type="button" @click="router.push({ name: 'project-variants', params: { projectId } })">Variants</button>
            <button class="btn btn-primary" type="button" :disabled="exportPending" @click="queueExport">
              {{ exportPending ? "Exporting..." : "Export" }}
            </button>
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
                <div class="micro-label">Visual source</div>
                <div class="add-scene-visual-row">
                  <div
                    v-for="option in visualSourceOptions"
                    :key="option.label"
                    :class="`add-scene-visual-opt ${
                      selectedAddSceneVisualSource === option.label ? 'selected' : ''
                    }`"
                    @click="selectedAddSceneVisualSource = option.label"
                  >
                    <div class="ico">{{ option.icon }}</div>
                    {{ option.label }}
                  </div>
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
                  <div class="micro-label">Visual source</div>
                  <div class="add-scene-visual-row">
                    <div
                      v-for="option in visualSourceOptions"
                      :key="`${scene.id}-${option.label}`"
                      :class="`add-scene-visual-opt ${
                        selectedAddSceneVisualSource === option.label ? 'selected' : ''
                      }`"
                      @click="selectedAddSceneVisualSource = option.label"
                    >
                      <div class="ico">{{ option.icon }}</div>
                      {{ option.label }}
                    </div>
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
                  <div v-if="addSceneGenerateError" class="rewrite-error">
                    {{ addSceneGenerateError }}
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
                <div v-else-if="showWaveformPreview" class="preview-fallback preview-fallback-waveform">
                  <div class="waveform-shell">
                    <div class="waveform-label">WAVEFORM</div>
                    <div class="waveform-bars">
                      <span
                        v-for="(bar, index) in waveformBars"
                        :key="`wave-${index}`"
                        class="waveform-bar"
                        :style="{ height: `${Math.round(bar * 100)}%` }"
                      ></span>
                    </div>
                  </div>
                </div>
                <div v-else class="preview-fallback"></div>
                <div v-if="isVisualLoading" class="preview-loading">
                  Loading scene media...
                </div>
                <div v-else-if="visualLoadFailed" class="preview-loading error">
                  Media unavailable
                </div>
                <div v-if="activeMusicTrack" class="preview-music-indicator">
                  <span class="music-wave-icon">♫</span>
                  <span class="music-wave-title">{{ activeMusicTrack.title }}</span>
                </div>
                <div class="preview-watermark">FRAMECAST</div>
                <div class="preview-timer">
                  {{ previewTimer.elapsed }} / {{ previewTimer.total }}
                </div>
                <div
                  class="preview-caption"
                  :class="captionPreviewClass"
                  :style="captionPositionStyle"
                >
                  <span
                    v-for="(word, index) in previewWords(
                      sceneScriptDraft || activeScene?.script_text
                    )"
                    :key="`${index}-${word.text}`"
                    :class="`caption-word ${word.highlighted ? 'highlight' : 'normal'}`"
                  >
                    {{ word.text }}
                  </span>
                </div>
              </div>
            </div>

            <div class="playback-controls">
              <div class="time-display">{{ previewTimer.elapsed }}</div>
              <button class="play-btn" type="button">▶</button>
              <div class="time-display">{{ previewTimer.total }}</div>
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
                <!-- Visual style picker (applies to both stock search and AI image generation) -->
                <div style="margin-bottom:10px;">
                  <div class="micro-label" style="margin-bottom:5px;">
                    Visual Style
                    <span v-if="visualStyleSaveState === 'saved'" style="opacity:.5; font-weight:400; margin-left:4px;">saved</span>
                  </div>
                  <div class="visual-style-grid">
                    <button
                      v-for="s in AI_IMAGE_STYLES"
                      :key="s.key"
                      type="button"
                      :class="['visual-style-btn', visualStyleDraft === s.key ? 'active' : '']"
                      :title="s.key"
                      @click="visualStyleDraft = visualStyleDraft === s.key ? null : s.key"
                    >{{ s.label }}</button>
                  </div>
                  <div
                    v-if="visualStyleDraft && activeScene?.visual_type === 'ai_image'"
                    class="style-regen-hint"
                  >
                    Style changed — regenerate to apply.
                  </div>
                </div>

                <!-- Visual type tabs -->
                <div class="visual-type-tabs">
                  <button
                    type="button"
                    :class="['visual-type-tab', selectedSwapVisualSource !== 'AI Image' ? 'active' : '']"
                    @click="selectedSwapVisualSource = 'Stock Clip'"
                  >Stock</button>
                  <button
                    type="button"
                    :class="['visual-type-tab', selectedSwapVisualSource === 'AI Image' ? 'active ai' : '']"
                    @click="selectedSwapVisualSource = 'AI Image'"
                  >✦ AI Image</button>
                </div>

                <!-- Stock / BG Loop / Text / Waveform -->
                <template v-if="selectedSwapVisualSource !== 'AI Image'">
                  <div class="control-row" style="margin-top:10px;">
                    <span class="control-name">Type</span>
                    <select v-model="selectedSwapVisualSource" class="control-value">
                      <option value="Stock Clip">Stock Clip</option>
                      <option value="BG Loop">BG Loop</option>
                      <option value="Text Only">Text Only</option>
                      <option value="Waveform">Waveform</option>
                    </select>
                  </div>
                  <div class="control-row">
                    <span class="control-name">Query</span>
                    <input v-model="visualQueryDraft" class="control-value query-input" />
                  </div>
                  <button class="btn btn-ghost btn-sm panel-full-btn" type="button" @click="swapVisual">
                    ↻ Swap Visual
                  </button>
                  <div v-if="visualSwapError" class="panel-error-copy">{{ visualSwapError }}</div>
                </template>

                <!-- AI Image generation -->
                <template v-else>
                  <div style="margin-top:10px;">
                    <div class="micro-label" style="margin-bottom:6px;">Style</div>
                    <div class="ai-style-grid">
                      <button
                        v-for="s in AI_IMAGE_STYLES"
                        :key="s.key"
                        type="button"
                        :class="['ai-style-btn', aiImageStyle === s.key ? 'active' : '']"
                        @click="aiImageStyle = s.key"
                      >{{ s.label }}</button>
                    </div>
                  </div>
                  <div class="control-row" style="margin-top:10px; flex-direction:column; align-items:flex-start; gap:4px;">
                    <span class="control-name">Prompt override <span style="opacity:.5;">(optional)</span></span>
                    <textarea
                      v-model="aiImagePromptOverride"
                      class="control-value query-input"
                      rows="2"
                      placeholder="Leave blank to use scene script…"
                      style="width:100%;resize:vertical;"
                    ></textarea>
                  </div>
                  <button
                    class="btn btn-primary btn-sm panel-full-btn"
                    type="button"
                    :disabled="aiImagePending"
                    @click="generateAIImage"
                  >
                    {{ aiImagePending ? '✦ Generating…' : '✦ Generate Image' }}
                  </button>
                  <div v-if="aiImageError" class="panel-error-copy">{{ aiImageError }}</div>
                  <div v-if="aiImagePending" class="panel-hint-copy">Generating via DALL-E 3 — this takes ~15s</div>
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
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.captions ? 'collapsed' : ''}`"
            >
              <div
                class="panel-section-header"
                @click="togglePanel('captions')"
              >
                <div class="panel-label panel-label-tight">Captions</div>
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
                <div class="caption-style-grid">
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'impact' ? 'active' : '']"
                    @click="captionStyleDraft = 'impact'"
                  >
                    <div class="preview-text accent-text">BOLD</div>
                    <div class="style-name">Impact</div>
                  </div>
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'editorial' ? 'active' : '']"
                    @click="captionStyleDraft = 'editorial'"
                  >
                    <div class="preview-text serif-text">SERIF</div>
                    <div class="style-name">Editorial</div>
                  </div>
                  <div
                    :class="['caption-style-opt', captionStyleDraft === 'hacker' ? 'active' : '']"
                    @click="captionStyleDraft = 'hacker'"
                  >
                    <div class="preview-text mono-text">MONO</div>
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
                  <div
                    v-for="group in CAPTION_FONT_GROUPS"
                    :key="group.label"
                    class="font-group"
                  >
                    <div class="font-group-label">{{ group.label }}</div>
                    <div class="font-group-items">
                      <button
                        v-for="font in group.fonts"
                        :key="font"
                        type="button"
                        :class="['font-btn', captionFontDraft === font ? 'active' : '']"
                        :style="{ fontFamily: font }"
                        @click="captionFontDraft = font"
                      >{{ font }}</button>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div
              :class="`panel-section ${panelState.music ? 'collapsed' : ''}`"
            >
              <div class="panel-section-header" @click="togglePanel('music')">
                <div class="panel-label panel-label-tight">Background Music</div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="music-track-list">
                  <div
                    class="music-track-item"
                    :class="{ 'music-track-selected': selectedMusicTrackId === null }"
                    @click="selectedMusicTrackId = null; scheduleMusicSave()"
                  >
                    <span class="music-track-name">No music</span>
                  </div>
                  <div
                    v-for="item in musicTrackListItems"
                    :key="item.key"
                    :class="item.type === 'mood' ? 'music-mood-label' : ['music-track-item', { 'music-track-selected': selectedMusicTrackId === item.track?.id }]"
                    @click="item.type === 'track' && (selectedMusicTrackId = item.track.id) && scheduleMusicSave()"
                  >{{ item.type === 'mood' ? item.mood : item.track?.title }}</div>
                </div>

                <div v-if="selectedMusicTrackId" class="music-settings">
                  <div class="control-row">
                    <span class="control-name">Volume</span>
                    <div class="slider-wrap">
                      <input
                        v-model.number="musicVolume"
                        type="range"
                        min="0"
                        max="100"
                        class="slider"
                        @input="scheduleMusicSave"
                      />
                      <span class="slider-val">{{ musicVolume }}%</span>
                    </div>
                  </div>
                  <div class="control-row">
                    <span class="control-name">Duck level</span>
                    <div class="slider-wrap">
                      <input
                        v-model.number="musicDuckVolume"
                        type="range"
                        min="0"
                        max="50"
                        class="slider"
                        @input="scheduleMusicSave"
                      />
                      <span class="slider-val">{{ musicDuckVolume }}%</span>
                    </div>
                  </div>
                  <div class="control-row">
                    <span class="control-name">Fade in</span>
                    <div class="slider-wrap">
                      <input
                        v-model.number="musicFadeInMs"
                        type="range"
                        min="0"
                        max="3000"
                        step="100"
                        class="slider"
                        @input="scheduleMusicSave"
                      />
                      <span class="slider-val">{{ musicFadeInMs }}ms</span>
                    </div>
                  </div>
                  <div class="control-row">
                    <span class="control-name">Loop track</span>
                    <label class="toggle-wrap">
                      <input v-model="musicLoop" type="checkbox" class="toggle-input" @change="scheduleMusicSave" />
                      <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                  </div>
                  <div class="control-row">
                    <span class="control-name">Duck voice</span>
                    <label class="toggle-wrap">
                      <input v-model="musicDuckDuringVoice" type="checkbox" class="toggle-input" @change="scheduleMusicSave" />
                      <span class="toggle-track"><span class="toggle-thumb"></span></span>
                    </label>
                  </div>
                  <div v-if="musicSaveError" class="micro-error">{{ musicSaveError }}</div>
                  <div v-if="musicSaveState === 'saved'" class="micro-copy">Saved</div>
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
      preload="metadata"
      @loadstart="isAudioLoading = true"
      @canplay="isAudioLoading = false"
      @loadeddata="isAudioLoading = false"
      @ended="isAudioPlaying = false"
      @play="isAudioPlaying = true"
      @pause="isAudioPlaying = false"
      @error="isAudioLoading = false"
    ></audio>
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

.sidebar-logo,
.avatar,
.user-popover-action {
  cursor: pointer;
}

.sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: 72px;
  background: rgba(17, 17, 24, 0.96);
  border-right: 1px solid var(--border);
  backdrop-filter: blur(12px);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 16px 0;
  z-index: 100;
}

.sidebar-logo {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--accent), #ff9b72);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-family: "Space Mono", monospace;
  font-weight: 700;
  margin-bottom: 28px;
}

.sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
}

.nav-item {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  color: var(--text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  position: relative;
  transition: 0.2s ease;
}

.nav-item:hover {
  color: var(--text-secondary);
  background: var(--bg-card);
}

.nav-item.active {
  color: var(--accent);
  background: var(--accent-glow);
  box-shadow: inset 0 0 0 1px rgba(255, 107, 53, 0.18);
}

.tooltip {
  position: absolute;
  left: 58px;
  top: 50%;
  transform: translateY(-50%);
  opacity: 0;
  pointer-events: none;
  background: var(--bg-elevated);
  color: var(--text-primary);
  font-size: 12px;
  padding: 5px 10px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border);
  white-space: nowrap;
  transition: opacity 0.15s ease;
}

.nav-item:hover .tooltip {
  opacity: 1;
}

.sidebar-bottom {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: linear-gradient(135deg, #2a3a70, #7d3cff);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  color: #fff;
}

.user-popover {
  position: absolute;
  bottom: 52px;
  left: 12px;
  width: 200px;
  background: var(--bg-elevated);
  border: 1px solid var(--border-active);
  border-radius: 10px;
  padding: 12px;
  z-index: 200;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}

.user-popover-name {
  font-size: 13px;
  font-weight: 600;
}

.user-popover-email {
  font-size: 11px;
  color: var(--text-muted);
  margin-top: 2px;
}

.user-popover-divider {
  border-top: 1px solid var(--border);
  margin: 10px 0;
}

.user-popover-action {
  width: 100%;
  text-align: left;
  color: var(--red);
  font-size: 13px;
}

.main {
  margin-left: 72px;
  min-height: 100vh;
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

.editor {
  display: flex;
  height: calc(100vh - 64px);
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

/* Font picker */
.font-picker-block {
  margin-top: 10px;
  border-top: 1px solid var(--border);
  padding-top: 10px;
}

.font-group {
  margin-bottom: 8px;
}

.font-group-label {
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--text-muted);
  margin-bottom: 4px;
  opacity: 0.6;
}

.font-group-items {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.font-btn {
  padding: 5px 8px;
  border-radius: 5px;
  border: 1px solid transparent;
  background: transparent;
  color: var(--text);
  font-size: 13px;
  cursor: pointer;
  text-align: left;
  transition: all 0.12s;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.font-btn:hover {
  background: rgba(255,255,255,0.04);
  border-color: var(--border);
}

.font-btn.active {
  background: rgba(99,102,241,0.15);
  border-color: #6366f1;
  color: #a5b4fc;
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

.add-scene-visual-row {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 10px;
}

.add-scene-visual-opt {
  min-width: 0;
  min-height: 88px;
  padding: 8px 6px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  text-align: center;
  font-size: 11px;
  color: var(--text-secondary);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}

.add-scene-visual-opt.selected {
  border-color: var(--accent);
  background: var(--accent-glow);
  color: var(--accent);
}

.ico {
  font-size: 16px;
  margin-bottom: 2px;
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

.text-only-label,
.waveform-label {
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
  border-radius: 22px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  background:
    radial-gradient(circle at bottom right, rgba(85, 114, 255, 0.22), transparent 30%),
    linear-gradient(180deg, rgba(14, 15, 24, 0.96), rgba(20, 16, 38, 0.98));
  padding: 28px 24px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 28px;
}

.waveform-bars {
  height: 220px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  gap: 8px;
}

.waveform-bar {
  width: 18px;
  min-height: 18%;
  border-radius: 999px;
  background: linear-gradient(180deg, #ff6b35 0%, #ffbe55 100%);
  box-shadow: 0 0 18px rgba(255, 107, 53, 0.24);
}

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
  font-family: "Space Mono", monospace;
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
  align-items: center;
  gap: 16px;
  margin-top: 24px;
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
  width: 140px;
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

/* Music panel */
.music-track-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 180px;
  overflow-y: auto;
  margin-bottom: 10px;
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 4px;
}

.music-mood-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  padding: 4px 6px 2px;
}

.music-track-item {
  padding: 6px 8px;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.15s;
}

.music-track-item:hover {
  background: var(--surface-hover, rgba(255,255,255,0.05));
}

.music-track-item.music-track-selected {
  background: rgba(99, 102, 241, 0.18);
}

.music-track-name {
  font-size: 12px;
  color: var(--text);
}

.music-settings {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-top: 4px;
}

/* Canvas music wave indicator */
.preview-music-indicator {
  position: absolute;
  bottom: 36px;
  left: 8px;
  display: flex;
  align-items: center;
  gap: 4px;
  background: rgba(0,0,0,0.55);
  border-radius: 4px;
  padding: 3px 7px;
  pointer-events: none;
}

.music-wave-icon {
  font-size: 11px;
  color: #a5b4fc;
}

.music-wave-title {
  font-size: 10px;
  color: rgba(255,255,255,0.8);
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
  .sidebar {
    position: static;
    width: 100%;
    height: 72px;
    flex-direction: row;
    justify-content: space-between;
    padding: 0 16px;
  }

  .sidebar-nav,
  .sidebar-bottom {
    flex-direction: row;
    display: flex;
    align-items: center;
  }

  .sidebar-logo {
    margin-bottom: 0;
  }

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
