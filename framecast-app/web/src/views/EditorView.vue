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
const selectedVisualSource = ref("Stock Clip");
const newSceneScript = ref("");
const rewriteToolsVisible = ref(false);
const rewritePreviewVisible = ref(false);
const rewritePreviewCopy = ref("");
const rewriteCustomInstruction = ref("");
const rewriteMode = ref("");
const rewritePending = ref(false);
const rewriteApplyPending = ref(false);
const rewriteError = ref("");
const panelState = ref({
  script: false,
  visual: false,
  voice: false,
  captions: false,
  brand: false,
});
const sceneScriptDraft = ref("");

const activeScene = computed(
  () => scenes.value.find((scene) => scene.id === activeSceneId.value) ?? null
);
const activeSceneVisualUrl = computed(
  () => activeScene.value?.visual_asset?.storage_url ?? null
);
const activeSceneVisualAsset = computed(() => activeScene.value?.visual_asset ?? null);
const activeSceneVisualIsVideo = computed(() => {
  const asset = activeSceneVisualAsset.value;
  if (!asset) return false;
  return asset.asset_type === "video" || String(asset.mime_type || "").startsWith("video/");
});
const activeSceneAudioUrl = computed(
  () => activeScene.value?.audio_asset?.storage_url ?? null
);
const activeSceneVisualLoaded = computed(() => {
  const sceneId = activeSceneId.value;
  if (!sceneId || !activeSceneVisualUrl.value) return false;
  return Boolean(mediaCache.value.visual[sceneId]?.loaded);
});
const isVisualLoading = computed(() => {
  if (!activeSceneVisualUrl.value) return false;
  return !activeSceneVisualLoaded.value && !visualLoadFailed.value;
});
const activeVoiceName = computed(() => {
  const voiceId = activeScene.value?.voice_settings?.voice_id;
  return voiceId ? voiceId.charAt(0).toUpperCase() + voiceId.slice(1) : "Default";
});
const activeVoiceSpeed = computed(
  () => activeScene.value?.voice_settings?.speed ?? 1.0
);
const activeVoiceOutdated = computed(
  () => Boolean(activeScene.value?.voice_settings?.is_outdated)
);
const activeSceneIndex = computed(() =>
  scenes.value.findIndex((scene) => scene.id === activeSceneId.value)
);
const projectTitle = computed(
  () => project.value?.title || `Project #${projectId.value}`
);
const unreadCount = computed(() =>
  notifications.value.filter((item) => !item.is_read).length
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
];
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
    sceneScriptDraft.value = scene?.script_text || "";
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

function sceneTypeLabel(index) {
  if (index === 0) return "Hook";
  if (index === scenes.value.length - 1) return "CTA";
  return "Narration";
}

function sceneVisualLabel(scene) {
  if (scene?.visual_type === "text_card") return "text card";
  if (scene?.visual_type === "ai_image") return "ai image";
  if (scene?.visual_asset?.asset_type === "video") return "stock clip";
  if (scene?.visual_asset?.asset_type === "image") return "ai image";
  return "bg loop";
}

function sceneVoiceOutdated(scene) {
  return Boolean(scene?.voice_settings?.is_outdated);
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
    scenes.value = response.data?.data?.scenes ?? [];
    hookOptions.value = response.data?.data?.hook_options ?? [];
    activeSceneId.value = scenes.value[0]?.id ?? null;
    scenes.value.forEach((scene) => {
      preloadSceneVisual(scene);
      preloadSceneAudio(scene);
    });
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
    await loadNotifications();
    subscribeWorkspaceNotifications();
  } catch {
    mePayload.value = null;
  }
}

async function loadNotifications() {
  try {
    const response = await api.get("/notifications");
    notifications.value = response.data?.data?.notifications ?? [];
  } catch {
    notifications.value = [];
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
  activeSceneId.value = sceneId;
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
        scenes.value = scenes.value.map((scene) =>
          scene.id === updatedScene.id ? { ...scene, ...updatedScene } : scene
        );
        activeSceneId.value = updatedScene.id;
        sceneScriptDraft.value = updatedScene.script_text || "";
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
  activeScene.value.script_text = rewritePreviewCopy.value;
  activeScene.value.voice_settings = {
    ...(activeScene.value.voice_settings || {}),
    is_outdated: true,
  };
  rewritePreviewVisible.value = false;
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
  loadMe();
  loadProject();
});

onBeforeUnmount(() => {
  mediaPreloaders.forEach((media) => {
    media.onload = null;
    media.onerror = null;
    media.onloadeddata = null;
    media.oncanplaythrough = null;
  });
  mediaPreloaders.clear();
  unsubscribeWorkspaceNotifications();
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

          <button class="nav-item" type="button">
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
            class="nav-item active"
            type="button"
            aria-current="page"
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
            <button class="btn btn-ghost" type="button" @click="router.push({ name: 'dashboard' })">+ New Video</button>
            <button class="btn btn-primary" type="button">Export</button>
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
                      selectedVisualSource === option.label ? 'selected' : ''
                    }`"
                    @click="selectedVisualSource = option.label"
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
                  <button class="btn btn-ghost btn-sm purple-btn" type="button">
                    ✦ AI Generate
                  </button>
                  <button
                    class="btn btn-primary btn-sm"
                    type="button"
                    @click="closeAddScene"
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
                    <span v-if="index === 0"> · {{ sceneTypeLabel(index) }}</span>
                    <span :class="sceneVoiceOutdated(scene) ? 'inline-warn' : 'inline-warn state-hidden'">
                      Voice outdated
                    </span>
                  </div>
                  <div class="scene-text">{{ scene.script_text }}</div>
                  <div class="scene-meta">
                    <span class="scene-tag">{{ sceneVisualLabel(scene) }}</span>
                    <span>{{
                      formatSceneDuration(scene.duration_seconds)
                    }}</span>
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
                        selectedVisualSource === option.label ? 'selected' : ''
                      }`"
                      @click="selectedVisualSource = option.label"
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
                    <button
                      class="btn btn-ghost btn-sm purple-btn"
                      type="button"
                    >
                      ✦ AI Generate
                    </button>
                    <button
                      class="btn btn-primary btn-sm"
                      type="button"
                      @click="closeAddScene"
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
                <img
                  v-if="currentVisualUrl"
                  :src="currentVisualUrl"
                  class="preview-image"
                  alt=""
                />
                <div v-else class="preview-fallback"></div>
                <div v-if="isVisualLoading" class="preview-loading">
                  Loading scene media...
                </div>
                <div v-else-if="visualLoadFailed" class="preview-loading error">
                  Media unavailable
                </div>
                <div class="preview-watermark">FRAMECAST</div>
                <div class="preview-timer">
                  {{ previewTimer.elapsed }} / {{ previewTimer.total }}
                </div>
                <div class="preview-caption">
                  <span
                    v-for="(word, index) in previewWords(
                      sceneScriptDraft || activeScene?.script_text
                    )"
                    :key="`${index}-${word.text}`"
                    :class="`caption-word ${
                      word.highlighted ? 'highlight' : 'normal'
                    }`"
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
                <div class="panel-label panel-label-tight">Visual Source</div>
                <div class="panel-chevron">▾</div>
              </div>
              <div class="panel-section-body">
                <div class="control-row">
                  <span class="control-name">Type</span>
                  <select class="control-value">
                    <option>Stock Clip</option>
                    <option>Background Loop</option>
                    <option>AI Image</option>
                    <option>Text Card</option>
                    <option>Waveform</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Query</span>
                  <input
                    class="control-value query-input"
                    :value="
                      activeScene?.visual_prompt || 'city night timelapse'
                    "
                  />
                </div>
                <button
                  class="btn btn-ghost btn-sm panel-full-btn"
                  type="button"
                >
                  ↻ Swap Visual
                </button>
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
                  <select class="control-value">
                    <option>0.8x</option>
                    <option selected>1.0x</option>
                    <option>1.1x</option>
                    <option>1.2x</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Stability</span>
                  <select class="control-value">
                    <option>Low</option>
                    <option selected>Medium</option>
                    <option>High</option>
                  </select>
                </div>
                <div :class="activeVoiceOutdated ? 'voice-warning-row' : 'voice-warning-row state-hidden'">
                  <span class="voice-warning-copy">Script changed — voice outdated</span>
                  <button class="regen-btn" type="button">Regenerate</button>
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
                    <input checked type="checkbox" />
                    <span>On</span>
                  </label>
                </div>
                <div class="caption-style-grid">
                  <div class="caption-style-opt active">
                    <div class="preview-text accent-text">BOLD</div>
                    <div class="style-name">Impact</div>
                  </div>
                  <div class="caption-style-opt">
                    <div class="preview-text serif-text">SERIF</div>
                    <div class="style-name">Editorial</div>
                  </div>
                  <div class="caption-style-opt">
                    <div class="preview-text mono-text">MONO</div>
                    <div class="style-name">Hacker</div>
                  </div>
                </div>
                <div class="control-row top-space">
                  <span class="control-name">Highlight</span>
                  <select class="control-value">
                    <option selected>Keywords</option>
                    <option>Word-by-word</option>
                    <option>Line-by-line</option>
                    <option>None</option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Position</span>
                  <select class="control-value">
                    <option selected>Bottom third</option>
                    <option>Center</option>
                    <option>Top third</option>
                  </select>
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
                  <select class="control-value">
                    <option>
                      {{ project?.channel?.name || "Finance Tips" }}
                    </option>
                  </select>
                </div>
                <div class="control-row">
                  <span class="control-name">Template</span>
                  <select class="control-value">
                    <option>
                      {{ project?.template?.name || "Explainer — 60s" }}
                    </option>
                  </select>
                </div>
                <div v-if="hookOptions.length" class="hooks-block">
                  <div class="micro-label hooks-title">Generated hooks</div>
                  <div
                    v-for="option in hookOptions.slice(0, 3)"
                    :key="option.id"
                    class="hook-card"
                  >
                    {{ option.hook_text }}
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

.scene-tag {
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--bg-elevated);
  font-size: 10px;
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
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
}

.add-scene-visual-opt {
  flex: 1;
  padding: 8px;
  background: var(--bg-elevated);
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  text-align: center;
  font-size: 11px;
  color: var(--text-secondary);
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
  background: linear-gradient(180deg, #1a1a3e 0%, #0d0d2b 40%, #1a0a2e 100%);
}

.preview-image,
.preview-fallback {
  width: 100%;
  height: 100%;
}

.preview-image {
  object-fit: cover;
}

.preview-fallback {
  background: linear-gradient(180deg, #1a1a3e 0%, #0d0d2b 40%, #1a0a2e 100%);
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
