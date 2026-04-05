<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import api from "../services/api";
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

const audioRef = ref(null);
const isAudioPlaying = ref(false);

const addScenePanelPosition = ref("");
const selectedSceneType = ref("Narration");
const selectedVisualSource = ref("Stock Clip");
const newSceneScript = ref("");
const rewriteToolsVisible = ref(false);
const rewritePreviewVisible = ref(false);
const rewritePreviewCopy = ref("");
const rewriteCustomInstruction = ref("");
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
const activeSceneAudioUrl = computed(
  () => activeScene.value?.audio_asset?.storage_url ?? null
);
const activeVoiceName = computed(() => {
  const voiceId = activeScene.value?.voice_settings?.voice_id;
  return voiceId ? voiceId.charAt(0).toUpperCase() + voiceId.slice(1) : "Default";
});
const activeVoiceSpeed = computed(
  () => activeScene.value?.voice_settings?.speed ?? 1.0
);
const activeSceneIndex = computed(() =>
  scenes.value.findIndex((scene) => scene.id === activeSceneId.value)
);
const projectTitle = computed(
  () => project.value?.title || `Project #${projectId.value}`
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

watch(
  activeScene,
  (scene) => {
    sceneScriptDraft.value = scene?.script_text || "";
    rewriteToolsVisible.value = false;
    rewritePreviewVisible.value = false;
    rewritePreviewCopy.value = "";
    rewriteCustomInstruction.value = "";
    if (audioRef.value) {
      audioRef.value.pause();
      isAudioPlaying.value = false;
    }
  },
  { immediate: true }
);

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

async function loadProject() {
  loading.value = true;
  error.value = "";

  try {
    const response = await api.get(`/projects/${projectId.value}`);
    project.value = response.data?.data?.project ?? null;
    scenes.value = response.data?.data?.scenes ?? [];
    hookOptions.value = response.data?.data?.hook_options ?? [];
    activeSceneId.value = scenes.value[0]?.id ?? null;
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
  } catch {
    mePayload.value = null;
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
  }
}

function submitRewrite(mode) {
  const source =
    sceneScriptDraft.value.trim() || activeScene.value?.script_text || "";
  if (!source) return;

  const transformations = {
    Shorten: source
      .split(/(?<=[.!?])\s+/)
      .slice(0, 1)
      .join(" "),
    Expand: `${source} The key is turning this into a repeatable system instead of relying on motivation.`,
    "Stronger hook": `Stop scrolling: ${source
      .charAt(0)
      .toLowerCase()}${source.slice(1)}`,
    "More punchy": source.replace(/\.\s+/g, ". ").replace(/,/g, " -"),
    "More educational": `${source} This works because consistent systems compound faster than one-off effort.`,
    Simplify: source
      .replace(/investments/gi, "assets")
      .replace(/automate/gi, "set up"),
  };

  rewritePreviewCopy.value = transformations[mode] || source;
  rewritePreviewVisible.value = true;
}

function submitRewriteCustom() {
  const source =
    sceneScriptDraft.value.trim() || activeScene.value?.script_text || "";
  if (!source) return;

  const suffix = rewriteCustomInstruction.value.trim();
  rewritePreviewCopy.value = suffix ? `${source} ${suffix}.` : source;
  rewritePreviewVisible.value = true;
}

function hideRewritePreview() {
  rewritePreviewVisible.value = false;
}

function acceptRewrite() {
  if (!activeScene.value) return;

  sceneScriptDraft.value = rewritePreviewCopy.value;
  activeScene.value.script_text = rewritePreviewCopy.value;
  rewritePreviewVisible.value = false;
}

function toggleAudioPlayback() {
  if (!audioRef.value || !activeSceneAudioUrl.value) return;
  if (isAudioPlaying.value) {
    audioRef.value.pause();
  } else {
    audioRef.value.currentTime = 0;
    audioRef.value.play().catch(() => {
      isAudioPlaying.value = false;
    });
  }
}

onMounted(() => {
  loadMe();
  loadProject();
});
</script>

<template>
  <main class="editor-page">
    <section v-if="loading" class="state-card">Loading project...</section>
    <section v-else-if="error" class="state-card error">{{ error }}</section>

    <template v-else>
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

          <button class="nav-item active" type="button">
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
            <span class="tooltip">Editor</span>
          </button>

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
              <path d="M12 20h9"></path>
              <path d="M16.5 3.5a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4Z"></path>
            </svg>
            <span class="tooltip">Variants</span>
          </button>

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
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <path d="M7 10l5 5 5-5"></path>
              <path d="M12 15V3"></path>
            </svg>
            <span class="tooltip">Assets</span>
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
            <button
              class="btn btn-ghost"
              type="button"
              @click="router.push({ name: 'dashboard' })"
            >
              + New Video
            </button>
            <button class="btn btn-primary" type="button">Export</button>
            <button class="notif-bell-btn" type="button">
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
              <span class="notif-badge">3</span>
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
                    Scene {{ scene.scene_order
                    }}<span v-if="index === 0">
                      · {{ sceneTypeLabel(index) }}</span
                    >
                    <span v-if="index === 0" class="inline-warn"
                      >Voice outdated</span
                    >
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
                  v-if="activeSceneVisualUrl"
                  :src="activeSceneVisualUrl"
                  class="preview-image"
                  alt=""
                />
                <div v-else class="preview-fallback"></div>
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
                  <span class="panel-badge warn">Voice outdated</span>
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
                    ✦ Rewrite with AI
                  </button>
                </div>
                <div v-if="rewriteToolsVisible" class="rewrite-tools">
                  <div class="chips chips-tight">
                    <div class="chip" @click="submitRewrite('Shorten')">
                      Shorten
                    </div>
                    <div class="chip" @click="submitRewrite('Expand')">
                      Expand
                    </div>
                    <div class="chip" @click="submitRewrite('Stronger hook')">
                      Stronger hook
                    </div>
                    <div class="chip" @click="submitRewrite('More punchy')">
                      More punchy
                    </div>
                    <div
                      class="chip"
                      @click="submitRewrite('More educational')"
                    >
                      More educational
                    </div>
                    <div class="chip" @click="submitRewrite('Simplify')">
                      Simplify
                    </div>
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
                      @click="submitRewriteCustom"
                    >
                      Apply
                    </button>
                  </div>
                  <div class="rewrite-note">
                    Applies to this scene only · Preserves locked facts
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
                      @click="acceptRewrite"
                    >
                      Accept Rewrite
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
                  <span class="panel-badge warn">Outdated</span>
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
                    {{ isAudioPlaying ? "⏸" : "▶" }}
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
                <div class="voice-warning-row">
                  <span class="voice-warning-copy"
                    >Script changed — voice outdated</span
                  >
                  <button class="regen-btn" type="button">Regenerate</button>
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
    </template>

    <audio
      v-if="activeSceneAudioUrl"
      ref="audioRef"
      :src="activeSceneAudioUrl"
      @ended="isAudioPlaying = false"
      @play="isAudioPlaying = true"
      @pause="isAudioPlaying = false"
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