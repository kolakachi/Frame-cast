<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import SchedulePostModal from "../components/SchedulePostModal.vue";
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
  { id: "script", label: "Script wording", impact: "Describe the line to change. The proposal will show any recording or visual work needed." },
  { id: "voice", label: "Voice delivery", impact: "Tell us how the delivery should sound. Review any regeneration costs before applying." },
  { id: "footage", label: "Footage", impact: "Describe what should appear in this passage. Review the proposed replacement and its cost." },
  { id: "captions", label: "Captions", impact: "Describe the caption style or wording you want changed." },
  { id: "timing", label: "Timing", impact: "Describe the pacing change. The proposal will explain which parts need updating." },
];
const target = ref("footage");
const revisionText = ref("");
const sending = ref(false);
const proposal = ref(null);
const applying = ref(false);
const take = ref(null);
const statusKnown = ref(false);
const revisionRunning = ref(false);
const revisionAt = ref(null);
const revisionExportId = ref(null);
const proposalCost = computed(() => proposal.value?.actions.reduce((sum, a) => sum + Number(a.estimated_cost || 0), 0) ?? 0);
const workPending = computed(() => !statusKnown.value || revisionRunning.value || Boolean(take.value?.status === 'generating' || take.value?.working));
const canExport = computed(() => !workPending.value && take.value?.status === 'ready_for_review' && !applying.value);
watch([revisionText, target, activeScene], () => { if (!applying.value) proposal.value = null; });
const history = ref([]); // { text, status, detail }

// ── export / download ────────────────────────────────────────────────────
const exporting = ref(false);
const exportJob = ref(null);
let exportTimer = null;
let revisionTimer = null;
let disposed = false;

const impact = computed(() => TARGETS.find((t) => t.id === target.value)?.impact ?? "");
const downloadUrl = computed(() => canExport.value && exportJob.value?.status === "completed" ? exportJob.value?.output_asset?.storage_url : null);
// A one-shot or restyled take is a finished video; the scene editor would
// re-compose it (and mute the baked-in voice). Its doors stay shut here.
const wholeVideo = computed(() => ["one_shot", "restyle"].includes(project.value?.visual_brief?.ugc_format));

// ── post-export actions: schedule, approval, share ──────────────────────
const scheduleOpen = ref(false);
const approvalOpen = ref(false);
const approvalForm = ref({ email: "", name: "", message: "" });
const approvalSubmitting = ref(false);
const approvalResult = ref(null);
const approvalError = ref("");
const sharePending = ref(false);
const shareToast = ref("");

async function submitApproval() {
  if (approvalSubmitting.value) return;
  if (!approvalForm.value.email.trim()) {
    approvalError.value = "Reviewer email is required.";
    return;
  }
  approvalSubmitting.value = true;
  approvalError.value = "";
  try {
    const res = await api.post("/approvals", {
      project_id: projectId.value,
      export_job_id: exportJob.value?.id ?? null,
      reviewer_email: approvalForm.value.email.trim(),
      reviewer_name: approvalForm.value.name.trim() || null,
      comment: approvalForm.value.message.trim() || null,
      expires_in_days: 7,
    });
    approvalResult.value = res.data?.data?.public_url ?? "sent";
  } catch (err) {
    approvalError.value = apiErrorMessage(err, "Could not send the approval link.");
  } finally {
    approvalSubmitting.value = false;
  }
}

async function copyShareLink() {
  if (sharePending.value) return;
  sharePending.value = true;
  try {
    const res = await api.post(`/projects/${projectId.value}/share`, { enabled: true });
    const url = res.data?.data?.share_url;
    if (url) {
      try {
        await navigator.clipboard.writeText(url);
        shareToast.value = "Copied!";
      } catch {
        shareToast.value = url; // clipboard blocked — show it to copy by hand
      }
      setTimeout(() => (shareToast.value = ""), 4000);
    }
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not create the share link.");
  } finally {
    sharePending.value = false;
  }
}
const activePreview = computed(() => (activeScene.value ? previews.value[activeScene.value] : null));
const activeSceneRow = computed(() => scenes.value.find((s) => s.id === activeScene.value));

async function load() {
  try {
    const { data } = await api.get(`/projects/${projectId.value}`);
    // Scenes ride beside the project in this payload, not inside it.
    project.value = data?.data?.project ?? null;
    scenes.value = (data?.data?.scenes ?? []).slice().sort((a, b) => (a.scene_order ?? 0) - (b.scene_order ?? 0));
    if (scenes.value.length && !activeScene.value) selectScene(scenes.value[0].id);
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not load this take.");
  }
  await refreshStatus();
  await loadExports();
}

async function selectScene(id) {
  activeScene.value = id;
  if (previews.value[id]) return;
  try {
    const { data } = await api.get(`/scenes/${id}/preview`);
    previews.value = { ...previews.value, [id]: { ...data?.data?.preview, visual_type: data?.data?.scene?.visual_asset?.asset_type } };
  } catch {
    previews.value = { ...previews.value, [id]: {} };
  }
}

// Resolve is a proposal only. Applying is a separate, explicit cost decision.
async function sendRevision() {
  const text = revisionText.value.trim();
  if (!text || sending.value || applying.value || workPending.value) return;
  sending.value = true;
  errorMessage.value = "";
  notice.value = "";
  proposal.value = null;
  const label = TARGETS.find(t => t.id === target.value)?.label ?? target.value;
  const sceneId = activeScene.value;
  const requestedTarget = target.value;
  try {
    const { data } = await api.post("/cruise/resolve", {
      project_id: projectId.value,
      intent: `[${label} change] ${text}`,
      ...(sceneId ? { scope_scene_id: sceneId } : {}),
    });
    const reply = data?.data ?? {};
    if (revisionText.value.trim() !== text || activeScene.value !== sceneId || target.value !== requestedTarget) return;
    const actions = reply.actions?.length ? reply.actions : reply.action ? [reply.action] : [];
    if (actions.length) proposal.value = { ...reply, actions };
    else notice.value = reply.reply_to_user || "No change was proposed.";
    await loadHistory();
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not prepare a revision proposal.");
  } finally { sending.value = false; }
}

async function applyRevision() {
  if (!proposal.value || applying.value || workPending.value) return;
  const approved = proposal.value;
  applying.value = true;
  errorMessage.value = "";
  clearTimeout(exportTimer);
  exportJob.value = null;
  exporting.value = false;
  try {
    for (const [i, action] of approved.actions.entries()) {
      await api.post("/cruise/apply", {
        project_id: projectId.value, tool: action.tool, params: action.params ?? {},
        message_id: approved.assistant_message_id ?? null, action_index: i,
        expected_credits: Number(action.estimated_cost || 0),
      });
      revisionAt.value = new Date().toISOString();
    }
    notice.value = "Revision submitted. We’ll track the changes here.";
    revisionText.value = "";
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "The revision was not fully applied. Check its history before requesting another change.");
  } finally {
    // Never replay an uncertain or partially successful batch from this button.
    proposal.value = null;
    applying.value = false;
    previews.value = {};
    await load();
    if (activeScene.value) await selectScene(activeScene.value);
  }
}

async function refreshStatus() {
  clearTimeout(revisionTimer);
  const wasPending = workPending.value;
  try {
    const { data } = await api.get("/ugc/takes", { params: { project_id: projectId.value, detail: 1 } });
    take.value = data?.data?.takes?.[0] ?? null;
    statusKnown.value = Boolean(take.value);
    revisionAt.value = take.value?.revision_at ?? revisionAt.value;
    revisionExportId.value = take.value?.revision_export_id ?? null;
    if (!await loadHistory()) statusKnown.value = false;
    if (workPending.value) exportJob.value = null;
    if (wasPending && !workPending.value) {
      previews.value = {};
      const { data: projectData } = await api.get(`/projects/${projectId.value}`);
      scenes.value = (projectData?.data?.scenes ?? []).slice().sort((a,b) => a.scene_order - b.scene_order);
      if (activeScene.value) await selectScene(activeScene.value);
    }
  } catch {
    statusKnown.value = false;
  }
  if (!disposed && (workPending.value || applying.value)) revisionTimer = setTimeout(refreshStatus, 5000);
}

// Older requests, so a reopened take shows what was already asked of it.
async function loadHistory() {
  try {
    const { data } = await api.get(`/cruise/conversation/${projectId.value}`);
    const messages = data?.data?.messages ?? [];
    const past = [];
    revisionRunning.value = messages.some(m => m.action_status === "running" || m.actions?.some(a => a.status === "running"));
    for (const m of messages) {
      if (m.role === "user") past.push({ text: m.text, status: "sent", detail: "" });
      else if (past.length && m.role === "assistant") {
        const last = past[past.length - 1];
        if (last.status === "sent") {
          const statuses = m.actions?.map(a => a.status) ?? [m.action_status];
          last.status = statuses.includes('running') ? 'working'
            : statuses.includes('failed') ? 'failed'
            : statuses.some(s => s === 'applied' || s === 'completed') ? 'applied'
            : statuses.includes('proposed') ? 'proposed' : 'answered';
          last.detail = m.text ?? "";
        }
      }
    }
    // Keep anything from this session (unshifted above) in front.
    history.value = past.reverse();
    return true;
  } catch {
    return false; // Do not export while revision state is unknown.
  }
}

async function queueExport() {
  if (exporting.value || !canExport.value) return;
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
    const latest = jobs.find(job => revisionExportId.value !== null
      ? Number(job.id) > Number(revisionExportId.value)
      : !revisionAt.value || (job.queued_at && Date.parse(job.queued_at) >= Date.parse(revisionAt.value))) ?? null;
    if (!canExport.value) return;
    exportJob.value = null;
    if (latest && ["completed", "queued", "processing"].includes(latest.status)) {
      exportJob.value = latest;
      if (latest.status !== "completed") { exporting.value = true; pollExport(); }
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
  clearTimeout(revisionTimer);
});
</script>

<template>
  <div class="rev-shell">
    <AppSidebar :user="authStore.user" :active-page="route.query.footage ? 'from-my-footage' : 'ugc-ads'" @logout="authStore.logout()" />

    <main class="rev-main">
      <header class="rev-top">
        <div class="rev-crumb">
          <router-link v-if="route.query.footage" :to="{ name: 'footage-compare', params: { sessionId: route.query.footage } }">← Source comparison</router-link>
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
              v-if="activePreview?.visual_url && (activePreview?.visual_type === 'video' || String(activePreview.visual_url).match(/\.(mp4|webm|mov)(\?|$)/i))"
              :src="activePreview.visual_url"
              controls
              playsinline
            />
            <img v-else-if="activePreview?.visual_url" :src="activePreview.visual_url" alt="" />
            <div v-else class="rev-frame-empty">Preview loads per scene — pick one below</div>

          </div>
          <p v-if="activeSceneRow?.script_text" class="rev-muted">{{ activeSceneRow.script_text }}</p>
          <audio v-if="activePreview?.audio_url" class="rev-audio" :src="activePreview.audio_url" controls />
          <p class="rev-muted">Scene preview · Captions and music are composed in the exported video.</p>
          <div v-if="scenes.length > 1" class="rev-scenes">
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
              <button class="rev-btn rev-btn-primary" type="button" :disabled="!revisionText.trim() || sending || applying || workPending" @click="sendRevision">
                {{ sending ? "Preparing…" : "Preview changes & cost" }}
              </button>
            </div>
          </div>

          <div v-if="proposal" class="rev-card rev-proposal" aria-live="polite">
            <h3>Review this revision</h3>
            <p class="rev-muted">{{ proposal.reply_to_user }}</p>
            <div v-for="(action, i) in proposal.actions" :key="i" class="rev-proposed-action">
              <b>{{ action.tool.replaceAll('_', ' ') }} · {{ action.estimated_cost ?? 0 }} credits</b>
              <ul><li v-for="(line, j) in action.diff_lines || []" :key="j">{{ line }}</li></ul>
            </div>
            <div class="rev-card-f">
              <b>Estimated total: {{ proposalCost }} credits</b>
              <button type="button" class="rev-btn" :disabled="applying" @click="proposal = null">Cancel</button>
              <button type="button" class="rev-btn rev-btn-primary" :disabled="applying || workPending" @click="applyRevision">{{ applying ? 'Applying…' : `Approve ${proposalCost} credits & apply` }}</button>
            </div>
          </div>
          <div v-if="workPending" class="rev-card" role="status">{{ statusKnown ? 'Changes are still processing. Preview and export will update when they finish.' : 'Checking this take’s current status…' }}</div>
          <div v-else-if="take?.status === 'needs_attention'" class="rev-card">A scene needs attention. Open the editor to repair it before exporting.</div>
          <div v-if="history.length" class="rev-card">
            <h3>Revision history</h3>
            <div v-for="(h, i) in history" :key="i" class="rev-hist">
              <span
                :class="[
                  'rev-pill',
                  h.status === 'applied' ? 'ok' : h.status === 'failed' ? 'bad' : h.status === 'working' ? 'warn' : '',
                ]"
              >
                {{ { applied: "Applied", failed: "Failed", working: "Working…", answered: "Answered", sent: "Sent", proposed: "Proposed" }[h.status] }}
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
              <button v-if="!wholeVideo" class="rev-btn" type="button" @click="openEditor">Continue editing project</button>
              <template v-if="downloadUrl">
                <span class="rev-pillrow">
                  <b>Export ready</b>
                  <span class="rev-dot">·</span>
                  <a :href="downloadUrl" target="_blank" rel="noopener">Open ↗</a>
                  <a :href="downloadUrl" :download="exportJob?.file_name || 'video.mp4'">Download ↓</a>
                  <span class="rev-dot">·</span>
                  <button type="button" @click="scheduleOpen = true">📅 Schedule</button>
                  <span class="rev-dot">·</span>
                  <button type="button" @click="approvalOpen = true">📝 Send for approval</button>
                  <span class="rev-dot">·</span>
                  <button type="button" :disabled="sharePending" @click="copyShareLink">
                    {{ sharePending ? "…" : "🔗 Copy share link" }}
                  </button>
                  <span v-if="shareToast" class="rev-share-toast">{{ shareToast }}</span>
                </span>
              </template>
              <button
                v-else
                class="rev-btn rev-btn-primary"
                type="button"
                :disabled="!canExport || exporting || (exportJob && exportJob.status !== 'failed')"
                @click="queueExport"
              >
                {{ exportJob && exportJob.status !== "failed" ? "Exporting…" : "Export & download" }}
              </button>
            </div>
          </div>
        </section>
      </div>
      <SchedulePostModal
        v-if="scheduleOpen"
        :export-job-id="exportJob?.id ?? null"
        @close="scheduleOpen = false"
        @scheduled="scheduleOpen = false"
      />

      <div v-if="approvalOpen" class="rev-scrim" @click.self="approvalOpen = false">
        <div class="rev-modal">
          <h3>Send for approval</h3>
          <template v-if="!approvalResult">
            <p class="rev-muted">A unique review link is emailed to your reviewer — no WyvStudio account needed.</p>
            <label>Reviewer email *<input v-model="approvalForm.email" type="email" placeholder="client@example.com" /></label>
            <label>Reviewer name (optional)<input v-model="approvalForm.name" type="text" /></label>
            <label>Note (optional)<textarea v-model="approvalForm.message" rows="3"></textarea></label>
            <p v-if="approvalError" class="rev-error" style="margin:0">{{ approvalError }}</p>
            <div class="rev-modal-f">
              <button class="rev-btn" type="button" @click="approvalOpen = false">Cancel</button>
              <button class="rev-btn rev-btn-primary" type="button" :disabled="approvalSubmitting" @click="submitApproval">
                {{ approvalSubmitting ? "Sending…" : "Send link" }}
              </button>
            </div>
          </template>
          <template v-else>
            <p>Approval link sent{{ approvalResult !== "sent" ? " — you can also copy it:" : "." }}</p>
            <input v-if="approvalResult !== 'sent'" :value="approvalResult" readonly @focus="$event.target.select()" />
            <div class="rev-modal-f">
              <button class="rev-btn rev-btn-primary" type="button" @click="approvalOpen = false; approvalResult = null">Done</button>
            </div>
          </template>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.rev-proposal { border-color: var(--color-accent); }
.rev-proposed-action { margin-top: 16px; font-size: 13px; line-height: 1.6; }
.rev-shell { display: flex; min-height: 100vh; background: var(--color-bg-deep); }
.rev-main {
  margin-left: var(--sidebar-width, 220px);
  flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 0 28px 40px;
}
.rev-top {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 18px 0 14px; border-bottom: 1px solid var(--color-border); flex-wrap: wrap;
}
.rev-crumb { font-size: 13.5px; color: var(--color-text-muted); }
.rev-crumb b { color: var(--color-text-primary); }
.rev-note { font-size: 12.5px; color: var(--color-text-muted); }
.rev-error {
  margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px;
  background: var(--color-danger-soft, rgba(179, 38, 30, 0.08)); color: var(--color-danger, #b3261e);
}
.rev-notice { margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px; background: var(--color-primary-soft, rgba(255, 107, 53, 0.08)); }
.rev-body { display: flex; gap: 24px; padding-top: 20px; align-items: flex-start; }
.rev-preview { width: min(42%, 480px); flex: 0 0 auto; display: flex; flex-direction: column; gap: 10px; }
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
  border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary);
  border-radius: 999px; padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer;
}
.rev-scene-chip.on { border-color: var(--color-accent); color: var(--color-accent); }
.rev-panel { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.rev-card { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 14px; padding: 16px 18px; }
.rev-card h3 { margin: 0 0 10px; font-size: 15px; }
.rev-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.rev-chip {
  border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary);
  border-radius: 999px; padding: 7px 13px; font-size: 12.5px; font-weight: 600; cursor: pointer;
}
.rev-chip.on { border-color: var(--color-accent); background: var(--color-primary-soft, rgba(255, 107, 53, 0.08)); color: var(--color-accent); }
.rev-card textarea {
  width: 100%; resize: none; border: 1px solid var(--color-border); border-radius: 10px;
  padding: 12px; font-size: 13.5px; font-family: inherit; background: var(--color-bg-deep); color: var(--color-text-primary);
}
.rev-impact {
  margin-top: 10px; background: var(--color-warning-soft, rgba(180, 116, 14, 0.1)); border-radius: 10px;
  padding: 11px 13px; font-size: 12.5px; line-height: 1.5; color: var(--color-warning-strong, #efb968);
}
.rev-card-f { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; flex-wrap: wrap; }
.rev-btn {
  border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary);
  border-radius: 9px; padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;
}
.rev-btn:disabled { opacity: 0.55; cursor: default; }
.rev-btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.rev-hist { display: flex; gap: 12px; align-items: baseline; padding: 9px 0; border-bottom: 1px solid var(--color-border); }
.rev-hist:last-child { border-bottom: none; }
.rev-pill {
  flex: 0 0 auto; font-size: 11px; font-weight: 700; border-radius: 999px; padding: 3px 9px;
  background: var(--color-border); color: var(--color-text-muted);
}
.rev-pill.ok { background: var(--color-success-soft, rgba(31, 122, 77, 0.12)); color: var(--color-success, #1f7a4d); }
.rev-pill.bad { background: var(--color-danger-soft, rgba(179, 38, 30, 0.1)); color: var(--color-danger, #b3261e); }
.rev-pill.warn { background: var(--color-warning-soft, rgba(180, 116, 14, 0.12)); color: var(--color-warning-strong, #efb968); }
.rev-hist-t { font-size: 13px; }
.rev-muted { font-size: 12.5px; color: var(--color-text-muted); margin: 4px 0 0; }
.rev-done { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.rev-done-a { display: flex; gap: 10px; flex-wrap: wrap; }
.rev-pillrow {
  display: flex; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 13px;
}
.rev-pillrow a, .rev-pillrow button {
  border: none; background: none; color: var(--color-primary); font-size: 13px;
  font-weight: 600; cursor: pointer; text-decoration: none; padding: 0;
}
.rev-pillrow b { color: var(--color-success, #1f7a4d); }
.rev-dot { color: var(--color-text-muted); }
.rev-share-toast { font-size: 12px; color: var(--color-text-muted); word-break: break-all; }
.rev-modal {
  background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 16px;
  padding: 20px; width: min(460px, 100%); display: flex; flex-direction: column; gap: 12px;
}
.rev-modal h3 { margin: 0; font-size: 16px; }
.rev-modal label { display: flex; flex-direction: column; gap: 5px; font-size: 12.5px; color: var(--color-text-muted); }
.rev-modal input, .rev-modal textarea {
  border: 1px solid var(--color-border); border-radius: 9px; padding: 10px 12px;
  font-size: 13.5px; font-family: inherit; background: var(--color-bg); color: var(--color-text);
}
.rev-modal-f { display: flex; justify-content: flex-end; gap: 10px; }
@media (max-width: 860px) {
  .rev-main { margin-left: 0; }
  .rev-body { flex-direction: column; }
  .rev-preview { width: 100%; max-width: 380px; }
  .rev-main { padding: 0 14px 90px; }
}
</style>
