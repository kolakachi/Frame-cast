<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import api from "../services/api";
import { useAuthStore } from "../stores/auth";
import { apiErrorMessage } from "../composables/apiError";

// The generation screen from the wyvstudio-ugc-html mockups: every take in
// the run, scene by scene, with a retry on exactly the scene that failed.
// Charge-on-success billing makes the retry free until it works.

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const runId = computed(() => String(route.params.runId));
const takes = ref([]);
const loaded = ref(false);
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
    partial_failure: "Most of your video is ready",
    complete: takes.value.length > 1 ? "Your takes are ready" : "Your video is ready",
  })[runState.value]
);
const subhead = computed(() =>
  ({
    loading: "",
    in_progress: "Script and voice are locked in. Scenes are rendering now.",
    partial_failure: "A scene needs a retry. Everything else generated successfully and is kept.",
    complete: "Every stage completed. Take a look and request changes if anything's off.",
  })[runState.value]
);

async function load() {
  clearTimeout(timer);
  try {
    const { data } = await api.get("/ugc/takes", { params: { run: runId.value, detail: 1 } });
    if (disposed) return;
    takes.value = data?.data?.takes ?? [];
    loaded.value = true;
  } catch (err) {
    if (!loaded.value) errorMessage.value = apiErrorMessage(err, "Could not load this run.");
  }
  if (!disposed && (runState.value === "in_progress" || runState.value === "loading"))
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
  router.push({ name: "ugc-review", params: { projectId: take.id } });
}
function openEditor(take) {
  router.push({ name: "project-editor", params: { projectId: take.id } });
}

const elapsedLabel = computed(() => {
  const s = elapsed.value;
  return `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, "0")}s`;
});

onMounted(() => {
  if (!authStore.user?.is_internal) {
    router.replace({ name: "dashboard" });
    return;
  }
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
    <AppSidebar :user="authStore.user" active-page="ugc-ads" @logout="authStore.logout()" />

    <main class="run-main">
      <header class="run-top">
        <div class="run-crumb">
          <router-link :to="{ name: 'ugc-ads' }">UGC Ads</router-link> / <b>Generating</b>
        </div>
        <span class="run-note">You can leave this page — we'll keep working</span>
      </header>

      <div v-if="errorMessage" class="run-error">{{ errorMessage }}</div>

      <div class="run-head">
        <h1>{{ headline }}</h1>
        <p v-if="subhead" class="run-sub">{{ subhead }}</p>
      </div>

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
            <button class="run-btn run-btn-ghost" type="button" @click="openEditor(t)">Open in editor</button>
          </div>
        </section>

        <div v-if="loaded && !takes.length" class="run-empty">
          This run has no takes — it may have been removed.
          <router-link :to="{ name: 'ugc-ads' }">Back to UGC Ads</router-link>
        </div>

        <div class="run-foot">
          <span class="run-muted">Elapsed {{ elapsedLabel }}</span>
          <span class="run-muted">Scenes already finished are kept either way.</span>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.run-shell { display: flex; min-height: 100vh; background: var(--color-bg); }
.run-main {
  margin-left: var(--sidebar-width, 220px);
  flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 0 28px 40px;
}
.run-top {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 18px 0 14px; border-bottom: 1px solid var(--color-border); flex-wrap: wrap;
}
.run-crumb { font-size: 13.5px; color: var(--color-text-muted); }
.run-crumb b { color: var(--color-text); }
.run-note { font-size: 12px; color: var(--color-text-muted); }
.run-error {
  margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px;
  background: var(--color-danger-soft, rgba(179, 38, 30, 0.08)); color: var(--color-danger, #b3261e);
}
.run-head { padding: 22px 0 6px; }
.run-head h1 { margin: 0; font-size: 24px; }
.run-sub { margin: 6px 0 0; font-size: 13.5px; color: var(--color-text-muted); }
.run-body { display: flex; flex-direction: column; gap: 16px; padding-top: 14px; max-width: 860px; }
.run-take { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 16px 18px; }
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
.run-dot.active { border-color: var(--color-primary); color: var(--color-primary); }
.run-dot.failed { background: var(--color-danger, #b3261e); border-color: transparent; color: #fff; }
.run-stage-m { flex: 1; min-width: 0; }
.run-stage-t { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; font-size: 14px; font-weight: 600; }
.run-stage-s { font-size: 12px; flex: 0 0 auto; }
.run-stage-s.done { color: var(--color-success, #1f7a4d); }
.run-stage-s.active { color: var(--color-primary); }
.run-stage-s.failed { color: var(--color-danger, #b3261e); }
.run-line { margin: 4px 0 0; font-size: 12.5px; color: var(--color-text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.run-fail { margin: 4px 0 0; font-size: 12.5px; color: var(--color-danger, #b3261e); }
.run-muted { font-size: 12.5px; color: var(--color-text-muted); margin: 4px 0 0; }
.run-btn {
  border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text);
  border-radius: 9px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.run-btn:disabled { opacity: 0.55; cursor: default; }
.run-btn-primary { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
.run-btn-retry { margin-top: 8px; color: var(--color-danger, #b3261e); border-color: currentColor; }
.run-btn-ghost { border-color: transparent; color: var(--color-text-muted); }
.run-take-f { display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.run-empty { padding: 30px 0; font-size: 14px; color: var(--color-text-muted); }
.run-foot { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
@media (max-width: 860px) {
  .run-main { margin-left: 0; padding: 0 14px 90px; }
}
</style>
