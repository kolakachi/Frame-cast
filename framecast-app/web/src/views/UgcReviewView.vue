<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import api from "../services/api";
import { useAuthStore } from "../stores/auth";
import { apiErrorMessage } from "../composables/apiError";

// The review screen from the wyvstudio-ugc-html mockups. Revision requests
// are categorised so the impact is stated before sending, and routed to
// Cruise (decision D.2) — the same resolver the editor's assistant uses, so
// a request here and a chat there behave identically.

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const projectId = computed(() => Number(route.params.projectId));
const project = ref(null);
const scenes = ref([]);
const previews = ref({}); // scene id -> { visual_url, audio_url }
const activeScene = ref(null);
const errorMessage = ref("");
const notice = ref("");

// What each kind of change touches — and, as importantly, what it leaves
// alone. Shown before sending so the cost of a revision is never a surprise.
const TARGETS = [
  { id: "script", label: "Script wording", impact: "Changing wording re-records that line in the presenter's voice and re-syncs their delivery. Captions and music continue unchanged." },
  { id: "voice", label: "Voice delivery", impact: "Adjusting delivery re-records the line. Footage, captions, and music are unaffected." },
  { id: "footage", label: "Footage", impact: "Replacing footage only re-composes that shot. The spoken line, voice, and captions stay exactly as approved." },
  { id: "captions", label: "Captions", impact: "Caption edits are composition-only — no re-recording or re-rendering of performance." },
  { id: "timing", label: "Timing", impact: "Timing changes re-time the affected cut. Wording and voice recordings are reused, not regenerated." },
];
const target = ref("footage");
const revisionText = ref("");
const sending = ref(false);
const history = ref([]); // { text, status, detail }

// ── export / download ────────────────────────────────────────────────────
const exporting = ref(false);
const exportJob = ref(null);
let exportTimer = null;
let disposed = false;

const impact = computed(() => TARGETS.find((t) => t.id === target.value)?.impact ?? "");
const downloadUrl = computed(() => exportJob.value?.output_asset?.storage_url ?? null);
const activePreview = computed(() => (activeScene.value ? previews.value[activeScene.value] : null));
const activeSceneRow = computed(() => scenes.value.find((s) => s.id === activeScene.value));

async function load() {
  try {
    const { data } = await api.get(`/projects/${projectId.value}`);
    project.value = data?.data?.project ?? data?.data ?? null;
    scenes.value = (project.value?.scenes ?? []).slice().sort((a, b) => (a.scene_order ?? 0) - (b.scene_order ?? 0));
    if (scenes.value.length && !activeScene.value) selectScene(scenes.value[0].id);
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not load this take.");
  }
  loadExports();
  loadHistory();
}

async function selectScene(id) {
  activeScene.value = id;
  if (previews.value[id]) return;
  try {
    const { data } = await api.get(`/scenes/${id}/preview`);
    previews.value = { ...previews.value, [id]: data?.data?.preview ?? {} };
  } catch {
    previews.value = { ...previews.value, [id]: {} };
  }
}

// Send the request through Cruise: resolve proposes concrete actions, apply
// executes each one. The category rides in front of the words so the
// resolver knows which lane the change belongs to.
async function sendRevision() {
  const text = revisionText.value.trim();
  if (!text || sending.value) return;
  sending.value = true;
  errorMessage.value = "";
  notice.value = "";
  const label = TARGETS.find((t) => t.id === target.value)?.label ?? target.value;
  const entry = { text, status: "working", detail: label };
  history.value.unshift(entry);
  try {
    const { data } = await api.post("/cruise/resolve", {
      project_id: projectId.value,
      intent: `[${label} change] ${text}`,
      ...(activeScene.value ? { scope_scene_id: activeScene.value } : {}),
    });
    const reply = data?.data ?? {};
    const actions = reply.actions?.length ? reply.actions : reply.action ? [reply.action] : [];
    if (!actions.length) {
      entry.status = "answered";
      entry.detail = reply.reply_to_user || "No change was needed for that request.";
      return;
    }
    for (const [i, action] of actions.entries()) {
      await api.post("/cruise/apply", {
        project_id: projectId.value,
        tool: action.tool,
        params: action.params ?? {},
        message_id: reply.message_id ?? null,
        action_index: i,
      });
    }
    entry.status = "applied";
    entry.detail = reply.reply_to_user || `${label} — applied`;
    // An applied change can invalidate a finished export.
    exportJob.value = null;
    previews.value = {};
    await load();
    if (activeScene.value) selectScene(activeScene.value);
  } catch (err) {
    entry.status = "failed";
    entry.detail = apiErrorMessage(err, "The request could not be applied.");
  } finally {
    sending.value = false;
    revisionText.value = "";
  }
}

// Older requests, so a reopened take shows what was already asked of it.
async function loadHistory() {
  try {
    const { data } = await api.get(`/cruise/conversation/${projectId.value}`);
    const messages = data?.data?.messages ?? [];
    const past = [];
    for (const m of messages) {
      if (m.role === "user") past.push({ text: m.text, status: "sent", detail: "" });
      else if (past.length && m.role === "assistant") {
        const last = past[past.length - 1];
        if (last.status === "sent") {
          last.status = m.applied || m.actions?.some((a) => a.applied) ? "applied" : "answered";
          last.detail = m.text ?? "";
        }
      }
    }
    // Keep anything from this session (unshifted above) in front.
    const current = history.value.filter((h) => h.status === "working");
    history.value = [...current, ...past.reverse()];
  } catch {
    /* history is a convenience; the request box works without it */
  }
}

async function queueExport() {
  if (exporting.value) return;
  exporting.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post(`/projects/${projectId.value}/export`, {
      aspect_ratio: project.value?.aspect_ratio || "9:16",
    });
    exportJob.value = data?.data?.export_job ?? null;
    pollExport();
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not start the export.");
    exporting.value = false;
  }
}

async function loadExports() {
  try {
    const { data } = await api.get(`/projects/${projectId.value}/exports`);
    const jobs = data?.data?.export_jobs ?? [];
    const latest = jobs[0] ?? null;
    if (latest && ["completed", "queued", "processing"].includes(latest.status)) {
      exportJob.value = latest;
      if (latest.status !== "completed") pollExport();
    }
  } catch {
    /* absence of a past export is normal */
  }
}

function pollExport() {
  clearTimeout(exportTimer);
  exportTimer = setTimeout(async () => {
    if (disposed) return;
    try {
      const { data } = await api.get(`/projects/${projectId.value}/exports`);
      const jobs = data?.data?.export_jobs ?? [];
      const mine = exportJob.value ? jobs.find((j) => j.id === exportJob.value.id) : jobs[0];
      if (mine) exportJob.value = mine;
      if (mine && mine.status !== "completed" && mine.status !== "failed") pollExport();
      else exporting.value = false;
      if (mine?.status === "failed") errorMessage.value = mine.failure_reason || "The export failed. Try again.";
    } catch {
      pollExport();
    }
  }, 5000);
}

function openEditor() {
  router.push({ name: "project-editor", params: { projectId: projectId.value } });
}

onMounted(() => {
  if (!authStore.user?.is_internal) {
    router.replace({ name: "dashboard" });
    return;
  }
  load();
});
onBeforeUnmount(() => {
  disposed = true;
  clearTimeout(exportTimer);
});
</script>

<template>
  <div class="rev-shell">
    <AppSidebar :user="authStore.user" active-page="ugc-ads" @logout="authStore.logout()" />

    <main class="rev-main">
      <header class="rev-top">
        <div class="rev-crumb">
          <router-link :to="{ name: 'ugc-ads' }">UGC Ads</router-link> / <b>Review &amp; revise</b>
        </div>
        <span class="rev-note">{{ project?.title }}</span>
      </header>

      <div v-if="errorMessage" class="rev-error">{{ errorMessage }}</div>
      <div v-if="notice" class="rev-notice">{{ notice }}</div>

      <div class="rev-body">
        <section class="rev-preview">
          <div class="rev-frame">
            <video
              v-if="activePreview?.visual_url && String(activePreview.visual_url).match(/\.(mp4|webm|mov)(\?|$)/i)"
              :src="activePreview.visual_url"
              controls
              playsinline
            />
            <img v-else-if="activePreview?.visual_url" :src="activePreview.visual_url" alt="" />
            <div v-else class="rev-frame-empty">Preview loads per scene — pick one below</div>
            <div v-if="activeSceneRow?.script_text" class="rev-caption">{{ activeSceneRow.script_text }}</div>
          </div>
          <audio v-if="activePreview?.audio_url" class="rev-audio" :src="activePreview.audio_url" controls />
          <div class="rev-scenes">
            <button
              v-for="s in scenes"
              :key="s.id"
              type="button"
              :class="['rev-scene-chip', s.id === activeScene ? 'on' : '']"
              @click="selectScene(s.id)"
            >
              {{ s.label || `Scene ${s.scene_order}` }}
            </button>
          </div>
        </section>

        <section class="rev-panel">
          <div class="rev-card">
            <h3>Request a change</h3>
            <div class="rev-chips">
              <button
                v-for="t in TARGETS"
                :key="t.id"
                type="button"
                :class="['rev-chip', target === t.id ? 'on' : '']"
                @click="target = t.id"
              >
                {{ t.label }}
              </button>
            </div>
            <textarea
              v-model="revisionText"
              rows="3"
              maxlength="1000"
              placeholder="Say what you'd like changed, in plain language."
            ></textarea>
            <div class="rev-impact">{{ impact }}</div>
            <div class="rev-card-f">
              <span class="rev-muted">Applies to {{ activeSceneRow ? `"${activeSceneRow.label || 'this scene'}"` : "the whole take" }}</span>
              <button class="rev-btn rev-btn-primary" type="button" :disabled="!revisionText.trim() || sending" @click="sendRevision">
                {{ sending ? "Sending…" : "Send revision request" }}
              </button>
            </div>
          </div>

          <div v-if="history.length" class="rev-card">
            <h3>Revision history</h3>
            <div v-for="(h, i) in history" :key="i" class="rev-hist">
              <span
                :class="[
                  'rev-pill',
                  h.status === 'applied' ? 'ok' : h.status === 'failed' ? 'bad' : h.status === 'working' ? 'warn' : '',
                ]"
              >
                {{ { applied: "Applied", failed: "Failed", working: "Working…", answered: "Answered", sent: "Sent" }[h.status] }}
              </span>
              <span class="rev-hist-t">
                "{{ h.text }}"
                <span v-if="h.detail" class="rev-muted"> — {{ h.detail }}</span>
              </span>
            </div>
          </div>

          <div class="rev-card rev-done">
            <div>
              <h3>Happy with it?</h3>
              <p class="rev-muted">Download the file or keep editing the project. Sharing and scheduling become available after export.</p>
              <p v-if="exportJob && exportJob.status !== 'completed'" class="rev-muted">
                Export {{ exportJob.status }} — {{ exportJob.progress_percent ?? 0 }}%
              </p>
            </div>
            <div class="rev-done-a">
              <button class="rev-btn" type="button" @click="openEditor">Continue editing project</button>
              <a
                v-if="downloadUrl"
                class="rev-btn rev-btn-primary"
                :href="downloadUrl"
                :download="exportJob?.file_name || 'video.mp4'"
              >Download video</a>
              <button
                v-else
                class="rev-btn rev-btn-primary"
                type="button"
                :disabled="exporting || (exportJob && exportJob.status !== 'failed')"
                @click="queueExport"
              >
                {{ exportJob && exportJob.status !== "failed" ? "Exporting…" : "Export & download" }}
              </button>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<style scoped>
.rev-shell { display: flex; min-height: 100vh; background: var(--color-bg); }
.rev-main { flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 0 28px 40px; }
.rev-top {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 18px 0 14px; border-bottom: 1px solid var(--color-border); flex-wrap: wrap;
}
.rev-crumb { font-size: 13.5px; color: var(--color-text-muted); }
.rev-crumb b { color: var(--color-text); }
.rev-note { font-size: 12.5px; color: var(--color-text-muted); }
.rev-error {
  margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px;
  background: var(--color-danger-soft, rgba(179, 38, 30, 0.08)); color: var(--color-danger, #b3261e);
}
.rev-notice { margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px; background: var(--color-primary-soft, rgba(20, 99, 86, 0.08)); }
.rev-body { display: flex; gap: 24px; padding-top: 20px; align-items: flex-start; }
.rev-preview { width: 340px; flex: 0 0 auto; display: flex; flex-direction: column; gap: 10px; }
.rev-frame {
  position: relative; width: 100%; aspect-ratio: 9 / 16; border-radius: 16px;
  background: #141311; overflow: hidden;
}
.rev-frame video, .rev-frame img { width: 100%; height: 100%; object-fit: contain; display: block; }
.rev-frame-empty { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #847f70; font-size: 13px; padding: 0 20px; text-align: center; }
.rev-caption {
  position: absolute; left: 16px; right: 16px; bottom: 20px; color: #fff; font-size: 14px;
  font-weight: 600; text-shadow: 0 1px 3px rgba(0, 0, 0, 0.5); pointer-events: none;
}
.rev-audio { width: 100%; }
.rev-scenes { display: flex; gap: 6px; flex-wrap: wrap; }
.rev-scene-chip {
  border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text);
  border-radius: 999px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer;
}
.rev-scene-chip.on { border-color: var(--color-primary); color: var(--color-primary); }
.rev-panel { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.rev-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 14px; padding: 16px 18px; }
.rev-card h3 { margin: 0 0 10px; font-size: 15px; }
.rev-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.rev-chip {
  border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text);
  border-radius: 999px; padding: 7px 13px; font-size: 12.5px; font-weight: 600; cursor: pointer;
}
.rev-chip.on { border-color: var(--color-primary); background: var(--color-primary-soft, rgba(20, 99, 86, 0.08)); color: var(--color-primary); }
.rev-card textarea {
  width: 100%; resize: none; border: 1px solid var(--color-border); border-radius: 10px;
  padding: 12px; font-size: 13.5px; font-family: inherit; background: var(--color-bg); color: var(--color-text);
}
.rev-impact {
  margin-top: 10px; background: var(--color-warning-soft, rgba(180, 116, 14, 0.1)); border-radius: 10px;
  padding: 11px 13px; font-size: 12.5px; line-height: 1.5; color: var(--color-warning-strong, #7a5008);
}
.rev-card-f { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; flex-wrap: wrap; }
.rev-btn {
  border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text);
  border-radius: 9px; padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;
}
.rev-btn:disabled { opacity: 0.55; cursor: default; }
.rev-btn-primary { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
.rev-hist { display: flex; gap: 12px; align-items: baseline; padding: 9px 0; border-bottom: 1px solid var(--color-border); }
.rev-hist:last-child { border-bottom: none; }
.rev-pill {
  flex: 0 0 auto; font-size: 11px; font-weight: 700; border-radius: 999px; padding: 3px 9px;
  background: var(--color-border); color: var(--color-text-muted);
}
.rev-pill.ok { background: var(--color-success-soft, rgba(31, 122, 77, 0.12)); color: var(--color-success, #1f7a4d); }
.rev-pill.bad { background: var(--color-danger-soft, rgba(179, 38, 30, 0.1)); color: var(--color-danger, #b3261e); }
.rev-pill.warn { background: var(--color-warning-soft, rgba(180, 116, 14, 0.12)); color: var(--color-warning-strong, #7a5008); }
.rev-hist-t { font-size: 13px; }
.rev-muted { font-size: 12.5px; color: var(--color-text-muted); margin: 4px 0 0; }
.rev-done { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.rev-done-a { display: flex; gap: 10px; flex-wrap: wrap; }
@media (max-width: 860px) {
  .rev-body { flex-direction: column; }
  .rev-preview { width: 100%; max-width: 380px; }
  .rev-main { padding: 0 14px 90px; }
}
</style>
