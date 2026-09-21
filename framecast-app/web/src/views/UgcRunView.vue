<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import api from "../services/api";
import { useAuthStore } from "../stores/auth";
import { apiErrorMessage } from "../composables/apiError";

// The generation screen from the wyvstudio-ugc-html mockups: every take in
// the run, scene by scene, with a retry on exactly the scene that failed.

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const runId = computed(() => String(route.params.runId));
const takes = ref([]);
const loaded = ref(false);
const preview = ref(null);
const previewScene = ref(null);
async function loadPreview() {
  const rows = takes.value.flatMap(t => t.scene_rows || []);
  const row = [...rows].reverse().find(s => s.visual === 'done');
  if (!row || previewScene.value?.id === row.id) return;
  try {
    const { data } = await api.get(`/scenes/${row.id}/preview`);
    if (disposed) return;
    preview.value = { ...data?.data?.preview, visual_type: data?.data?.scene?.visual_asset?.asset_type };
    previewScene.value = row;
  } catch { /* Progress remains usable when a preview is unavailable. */ }
}
const errorMessage = ref("");
const retrying = ref(null); // scene id being retried
const startedAt = Date.now();
const elapsed = ref(0);
let timer = null;
let clock = null;
let disposed = false;

const runState = computed(() => {
  if (!takes.value.length) return "loading";
  if (takes.value.some((t) => t.status === "needs_attention")) return "partial_failure";
  if (takes.value.some((t) => t.status === "generating")) return "in_progress";
  return "complete";
});

const headline = computed(() =>
  ({
    loading: "Finding your run…",
    in_progress: takes.value.length > 1 ? "Generating your takes" : "Generating your video",
    partial_failure: "A take needs your attention",
    complete: takes.value.length > 1 ? "Your takes are ready" : "Your video is ready",
  })[runState.value]
);
const subhead = computed(() =>
  ({
    loading: "",
    in_progress: "Script and voice are locked in. Scenes are rendering now.",
    partial_failure: "Retry the failed scene. Completed work is kept, and other scenes can continue generating.",
    complete: "Your passages are ready to review. Export the finished video once you’re happy with them.",
  })[runState.value]
);

async function load() {
  clearTimeout(timer);
  try {
    const { data } = await api.get("/ugc/takes", { params: { run: runId.value, detail: 1 } });
    if (disposed) return;
    takes.value = data?.data?.takes ?? [];
    loadPreview();
    loaded.value = true;
    errorMessage.value = takes.value.length ? "" : "This run was not found in the current workspace.";
  } catch (err) {
    if (!loaded.value) errorMessage.value = apiErrorMessage(err, "Could not load this run.");
  }
  if (!disposed && (takes.value.some(t => t.working || t.status === "generating") || (!takes.value.length && !loaded.value)))
    timer = setTimeout(load, 6000);
}

async function retryScene(scene) {
  if (retrying.value) return;
  retrying.value = scene.id;
  errorMessage.value = "";
  try {
    await api.post(`/ugc/scenes/${scene.id}/retry`);
    // The retried leg reports as working on the next poll.
    scene.visual = scene.visual === "failed" ? "working" : scene.visual;
    scene.error = null;
    load();
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not retry that scene.");
  } finally {
    retrying.value = null;
  }
}

function sceneState(row) {
  if (row.error) return { cls: "failed", label: "Failed", mark: "!" };
  if (row.visual === "done" && row.voice !== "working") return { cls: "done", label: "Done", mark: "✓" };
  return { cls: "active", label: "In progress", mark: "•" };
}

function kindLabel(kind) {
  return { on_camera: "On camera", b_roll: "Cut-away", reaction: "Reaction" }[kind] ?? kind;
}

function openReview(take) {
  router.push({ name: "ugc-review", params: { projectId: take.id }, query: route.query.footage ? { footage: route.query.footage } : {} });
}
async function openEditor(take) {
  try {
    if (Number(take.id) >= 216) await api.post(`/projects/${take.id}/editor-opened`);
    await router.push({ name: "project-editor", params: { projectId: take.id } });
  } catch { errorMessage.value = "Could not open the editor. Please try again."; }
}

const elapsedLabel = computed(() => {
  const s = elapsed.value;
  return `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, "0")}s`;
});

onMounted(() => {
  load();
  clock = setInterval(() => {
    elapsed.value = Math.floor((Date.now() - startedAt) / 1000);
  }, 1000);
});
onBeforeUnmount(() => {
  disposed = true;
  clearTimeout(timer);
  clearInterval(clock);
});
</script>

<template>
  <div class="run-shell">
    <AppSidebar :user="authStore.user" :active-page="route.query.footage ? 'from-my-footage' : 'ugc-ads'" @logout="authStore.logout()" />

    <main class="run-main">
      <header class="run-top">
        <div class="run-crumb">
          <router-link v-if="route.query.footage" :to="{ name: 'footage-compare', params: { sessionId: route.query.footage } }">My Footage</router-link>
          <router-link v-else :to="{ name: 'ugc-ads' }">UGC Ads</router-link> / <b>Generating</b>
        </div>
        <span class="run-note">You can leave this page — we'll keep working</span>
      </header>

      <div v-if="errorMessage" class="run-error">{{ errorMessage }}</div>

      <div class="run-head">
        <h1>{{ headline }}</h1>
        <p v-if="subhead" class="run-sub">{{ subhead }}</p>
      </div>

      <div class="run-workspace">
      <div class="run-body">
        <section v-for="t in takes" :key="t.id" class="run-take">
          <div class="run-take-h">
            <div>
              <b>{{ t.character }}</b>
              <span v-if="t.variant" class="run-variant">· {{ t.variant }}</span>
            </div>
            <span
              :class="[
                'run-status',
                t.status === 'ready_for_review' ? 'ok' : t.status === 'needs_attention' ? 'bad' : '',
              ]"
            >
              {{
                t.status === "ready_for_review"
                  ? "Ready for review"
                  : t.status === "needs_attention"
                  ? "Needs a retry"
                  : "Generating…"
              }}
            </span>
          </div>

          <div class="run-stages">
            <div v-for="row in t.scene_rows" :key="row.id" class="run-stage">
              <div :class="['run-dot', sceneState(row).cls]">{{ sceneState(row).mark }}</div>
              <div class="run-stage-m">
                <div class="run-stage-t">
                  <span>{{ kindLabel(row.kind) }} <span class="run-muted">— {{ row.label }}</span></span>
                  <span :class="['run-stage-s', sceneState(row).cls]">{{ sceneState(row).label }}</span>
                </div>
                <p v-if="row.script_text" class="run-line">"{{ row.script_text }}"</p>
                <p v-if="row.error" class="run-fail">{{ row.error }}</p>
                <p v-else-if="row.voice === 'working' && row.visual === 'done'" class="run-muted">Recording the voice for this line.</p>
                <button
                  v-if="row.error"
                  class="run-btn run-btn-retry"
                  type="button"
                  :disabled="retrying === row.id"
                  @click="retryScene(row)"
                >
                  {{ retrying === row.id ? "Retrying…" : "Retry this scene" }}
                </button>
              </div>
            </div>
          </div>

          <div class="run-take-f">
            <button
              class="run-btn"
              :class="{ 'run-btn-primary': t.status === 'ready_for_review' }"
              type="button"
              :disabled="t.status === 'generating'"
              @click="openReview(t)"
            >
              {{ t.status === "generating" ? "Review opens when it's ready" : "Go to review →" }}
            </button>
            <button
              v-if="!['one_shot', 'restyle'].includes(t.format)"
              class="run-btn run-btn-ghost" type="button" @click="openEditor(t)"
            >Open in editor</button>
          </div>
        </section>

        <div v-if="loaded && !takes.length" class="run-empty">
          This run has no takes — it may have been removed.
          <router-link :to="{ name: 'ugc-ads' }">Back to UGC Ads</router-link>
        </div>

        <div class="run-foot">
          <span class="run-muted">Time on this page {{ elapsedLabel }}</span>
          <span class="run-muted">Scenes already finished are kept either way.</span>
        </div>
      </div>
      <aside class="run-preview">
        <h3>Latest completed passage</h3>
        <video v-if="preview?.visual_url && preview.visual_type === 'video'" :src="preview.visual_url" controls playsinline />
        <img v-else-if="preview?.visual_url" :src="preview.visual_url" alt="Latest generated passage" />
        <div v-else class="run-preview-empty">Your first completed passage will appear here.</div>
        <audio v-if="preview?.audio_url" :src="preview.audio_url" controls />
        <p v-if="previewScene" class="run-muted">{{ previewScene.label }} · {{ previewScene.script_text }}</p>
        <p class="run-muted">Scene preview. Review all passages before exporting the finished video.</p>
        <router-link v-if="route.query.footage" :to="{ name: 'footage-compare', params: { sessionId: route.query.footage } }" class="run-btn">Compare with source →</router-link>
      </aside>
      </div>
    </main>
  </div>
</template>

<style scoped>
.run-shell { display: flex; min-height: 100vh; background: var(--color-bg-deep); }
.run-main {
  margin-left: var(--sidebar-width, 220px);
  flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 0 28px 40px;
}
.run-top {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 18px 0 14px; border-bottom: 1px solid var(--color-border); flex-wrap: wrap;
}
.run-crumb { font-size: 13.5px; color: var(--color-text-muted); }
.run-crumb b { color: var(--color-text-primary); }
.run-note { font-size: 12px; color: var(--color-text-muted); }
.run-error {
  margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px;
  background: var(--color-danger-soft, rgba(179, 38, 30, 0.08)); color: var(--color-danger, #b3261e);
}
.run-head { padding: 22px 0 6px; }
.run-head h1 { margin: 0; font-size: 24px; }
.run-sub { margin: 6px 0 0; font-size: 13.5px; color: var(--color-text-muted); }
.run-body { display: flex; flex-direction: column; gap: 16px; padding-top: 14px; max-width: 860px; }
.run-take { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 14px; padding: 16px 18px; }
.run-take-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 14.5px; flex-wrap: wrap; }
.run-variant { color: var(--color-text-muted); font-size: 13px; }
.run-status { font-size: 12px; font-weight: 600; color: var(--color-text-muted); }
.run-status.ok { color: var(--color-success, #1f7a4d); }
.run-status.bad { color: var(--color-danger, #b3261e); }
.run-stages { margin-top: 8px; }
.run-stage { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--color-border); }
.run-stage:last-child { border-bottom: none; }
.run-dot {
  width: 26px; height: 26px; border-radius: 50%; flex: 0 0 auto; font-size: 13px;
  line-height: 24px; text-align: center; border: 1.5px solid var(--color-border); color: var(--color-text-muted);
}
.run-dot.done { background: var(--color-success, #1f7a4d); border-color: transparent; color: #fff; }
.run-dot.active { border-color: var(--color-accent); color: var(--color-accent); }
.run-dot.failed { background: var(--color-danger, #b3261e); border-color: transparent; color: #fff; }
.run-stage-m { flex: 1; min-width: 0; }
.run-stage-t { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; font-size: 14px; font-weight: 600; }
.run-stage-s { font-size: 12px; flex: 0 0 auto; }
.run-stage-s.done { color: var(--color-success, #1f7a4d); }
.run-stage-s.active { color: var(--color-accent); }
.run-stage-s.failed { color: var(--color-danger, #b3261e); }
.run-line { margin: 4px 0 0; font-size: 12.5px; color: var(--color-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.run-fail { margin: 4px 0 0; font-size: 12.5px; color: var(--color-danger, #b3261e); }
.run-muted { font-size: 12.5px; color: var(--color-text-muted); margin: 4px 0 0; }
.run-btn {
  border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary);
  border-radius: 9px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.run-btn:disabled { opacity: 0.55; cursor: default; }
.run-btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.run-btn-retry { margin-top: 8px; color: var(--color-danger, #b3261e); border-color: currentColor; }
.run-btn-ghost { border-color: transparent; color: var(--color-text-muted); }
.run-take-f { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.run-empty { padding: 30px 0; font-size: 14px; color: var(--color-text-muted); }
.run-foot { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
@media (max-width: 860px) {
  .run-main { margin-left: 0; padding: 0 14px 90px; }
}

.run-workspace { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(260px, .85fr); align-items: start; gap: 24px; }
.run-body { max-width: none; }
.run-preview { position: sticky; top: 24px; min-width: 0; margin-top: 14px; background: var(--color-bg-card); padding: 18px; border: 1px solid var(--color-border); border-radius: 14px; }
.run-preview h3 { margin: 0 0 14px; font-size: 15px; }
.run-preview video, .run-preview img, .run-preview-empty { width: 100%; height: 400px; max-height: 50vh; object-fit: contain; border-radius: 10px; background: #08080b; }
.run-preview audio { width: 100%; margin-top: 10px; }
.run-preview-empty { display: grid; place-items: center; padding: 24px; text-align: center; color: var(--color-text-muted); font-size: 13px; }
.run-preview .run-btn { display: inline-block; margin-top: 14px; }
@media (max-width: 1000px) { .run-workspace { grid-template-columns: 1fr; } .run-preview { position: static; } }
</style>
