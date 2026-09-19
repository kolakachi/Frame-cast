<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch, nextTick } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import MediaPickerModal from "../components/MediaPickerModal.vue";
import api from "../services/api";
import { useAuthStore } from "../stores/auth";
import { apiErrorMessage } from "../composables/apiError";

// The My Footage flow, per the wyvstudio-myfootage-html mockups: bring a
// video, see what we read off it, correct anything we got wrong, review the
// plan for your version, approve the cost, produce. Production progress uses
// the shared run screen; revisions use the shared review screen (D.2).

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const SCREENS = ["intake", "analysis", "plan", "approve", "compare"];
const screen = ref("intake");
const session = ref(null);
const corrected = ref(null); // read + corrections applied
const plan = ref(null);
const errorMessage = ref("");
const busy = ref(""); // which long call is running

// ── intake state ─────────────────────────────────────────────────────────
const intakeMode = ref("upload");
const sourceAsset = ref(null); // { id, title, thumbnail_url, duration_seconds }
const sourcePicker = ref(false);
const sourceUrl = ref(null);
const sourceVideo = ref(null);
const sourcePreviewError = ref('');
const producedTake = ref(null);
const producedPreview = ref(null);
const producedScene = ref(null);
let compareTimer = null;
let disposed = false;
let previewRequest = 0;
watch(() => sourceAsset.value?.id, async (id) => {
  sourceUrl.value = null;
  sourcePreviewError.value = '';
  if (!id) return;
  try {
    const { data } = await api.get(`/assets/${id}`);
    if (sourceAsset.value?.id === id) sourceUrl.value = data?.data?.asset?.storage_url ?? null;
  } catch { sourcePreviewError.value = 'Source preview could not load. Your analysis is still available.'; }
});
function seekSource() {
  const row = passages.value.find(p => p.id === activePassage.value);
  if (sourceVideo.value && row) sourceVideo.value.currentTime = Number(row.start || 0);
}


const linkUrl = ref("");
const fetchingLink = ref(false);
const brief = ref("");
const rights = ref("reference");

// ── analysis state ───────────────────────────────────────────────────────
const activePassage = ref(null);
const editingTranscript = ref(false);
const editedTranscript = ref("");
const corrections = ref({ passages: {}, speakers: {} });
const renamingSpeaker = ref(null);
const speakerName = ref("");

// ── approve state ────────────────────────────────────────────────────────
const consentReference = ref(false);
const consentPresenter = ref(false);
const presenter = ref(null); // { id, name, thumbnail }
const presenterPicker = ref(false);
const presenters = ref([]);
const answers = ref({});
const producing = ref(false);

// ── restyle (video-to-video) ─────────────────────────────────────────────
// Own footage only: the clip itself goes through the video model with the
// brief as the instruction; motion and timing stay, subjects change.
const RESTYLE_PER_SECOND = 8; // mirrors CreditService::VIDEO_RESTYLE_PER_SECOND via /credit-costs
const restyleMode = ref("flex_1");
const restyling = ref(false);
const restyleCredits = computed(() => Math.ceil(sourceAsset.value?.duration_seconds ?? 0) * RESTYLE_PER_SECOND);
const canRestyle = computed(
  () => rights.value === "reuse" && (sourceAsset.value?.duration_seconds ?? 0) > 0 && (sourceAsset.value?.duration_seconds ?? 31) <= 30
);
async function restyle() {
  if (restyling.value) return;
  restyling.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post(`/ugc/footage/${session.value.id}/restyle`, {
      consent_owner: true,
      mode: restyleMode.value,
      credits: restyleCredits.value,
    });
    const runId = data?.data?.run_id;
    if (runId) router.push({ name: "ugc-run", params: { runId } });
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not start the restyle.");
  } finally {
    restyling.value = false;
  }
}

const passages = computed(() => corrected.value?.passages ?? []);
const activeRow = computed(() => passages.value.find((p) => p.id === activePassage.value));
const unclearCount = computed(() => passages.value.filter((p) => p.kind === "unclear" && !p.dropped).length);
const needsPresenter = computed(() => (plan.value?.passages ?? []).some((p) => p.segment.kind !== "b_roll"));
const textNeeds = computed(() => (plan.value?.needs ?? []).filter((n) => n.type === "text"));
const canProduce = computed(
  () =>
    consentReference.value &&
    consentPresenter.value &&
    (!needsPresenter.value || presenter.value) &&
    !producing.value
);
watch(activePassage, async () => {
  editingTranscript.value = false;
  await nextTick();
  seekSource();
  if (screen.value === 'compare') loadComparison();
});
watch(screen, async value => {
  clearTimeout(compareTimer);
  if (value === 'compare') await loadComparison();
});
async function loadComparison() {
  clearTimeout(compareTimer);
  if (!session.value?.run_id || disposed || screen.value !== 'compare') return;
  const request = ++previewRequest;
  const passageId = activePassage.value;
  producedPreview.value = null;
  try {
    const { data } = await api.get('/ugc/takes', { params: { run: session.value.run_id, detail: 1 } });
    if (request !== previewRequest || disposed) return;
    producedTake.value = data?.data?.takes?.[0] ?? null;
    const index = (plan.value?.passages ?? []).findIndex(p => p.id === passageId);
    const row = producedTake.value?.scene_rows?.[index];
    producedScene.value = row ?? null;
    if (row?.visual === 'done') {
      const { data: preview } = await api.get(`/scenes/${row.id}/preview`);
      if (request === previewRequest && activePassage.value === passageId) producedPreview.value = { ...preview?.data?.preview, visual_type: preview?.data?.scene?.visual_asset?.asset_type };
    }
  } catch { errorMessage.value = 'Could not load the generated passage. Try opening production progress.'; }
  if (!disposed && request === previewRequest && (producedTake.value?.working || producedTake.value?.status === 'generating')) compareTimer = setTimeout(loadComparison, 6000);
}
onBeforeUnmount(() => { disposed = true; clearTimeout(compareTimer); });
const FLOW_STEPS = [
  { key: 'intake', label: 'Source' }, { key: 'analysis', label: 'Understand' },
  { key: 'plan', label: 'Plan' }, { key: 'approve', label: 'Approve' }, { key: 'compare', label: 'Compare' },
];
function canVisit(key) {
  if (key === screen.value) return true;
  if (session.value?.run_id) return key === 'compare';
  return key === 'intake' || (key === 'analysis' && corrected.value) || (['plan', 'approve'].includes(key) && plan.value);
}
const kindBadge = { observed: "Observed", inferred: "Inferred", unclear: "Needs you" };
const treatmentBadge = { new: "New performance", rebuilt: "Rebuilt", reused: "Reused" };

// ── intake actions ───────────────────────────────────────────────────────
function selectSource({ item }) {
  if (item?.id && item._type === "asset") {
    if (item.asset_type !== 'video' && !String(item.mime_type || '').startsWith('video/')) {
      errorMessage.value = 'Choose a video for My Footage. Images can be added as product assets in UGC.';
      sourcePicker.value = false;
      return;
    }
    errorMessage.value = '';
    sourceAsset.value = {
      id: item.id,
      title: item.title || "Untitled",
      thumbnail_url: item.thumbnail_url || null,
      duration_seconds: item.duration_seconds,
    };
  }
  sourcePicker.value = false;
}

async function fetchLink() {
  if (!linkUrl.value.trim() || fetchingLink.value) return;
  fetchingLink.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post("/ugc/fetch-video", { url: linkUrl.value.trim() });
    const a = data?.data?.asset;
    sourceAsset.value = { id: a.id, title: a.title, thumbnail_url: a.thumbnail_url, duration_seconds: a.duration_seconds };
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "That link could not be fetched. Upload the file instead.");
  } finally {
    fetchingLink.value = false;
  }
}

async function understandSource() {
  if (!sourceAsset.value || busy.value) return;
  busy.value = "reading";
  errorMessage.value = "";
  try {
    if (!session.value) {
      const { data } = await api.post("/ugc/footage", {
        asset_id: sourceAsset.value.id,
        brief: brief.value,
        rights: rights.value,
      });
      session.value = data?.data?.session ?? null;
      router.replace({ name: "footage-flow", params: { sessionId: session.value.id } });
    } else {
      await api.patch(`/ugc/footage/${session.value.id}`, { brief: brief.value, rights: rights.value });
    }
    const { data } = await api.post(`/ugc/footage/${session.value.id}/read`);
    session.value = data?.data?.session ?? session.value;
    corrected.value = data?.data?.corrected ?? null;
    if (passages.value.length) activePassage.value = passages.value[0].id;
    screen.value = "analysis";
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not read that video. Try again.");
  } finally {
    busy.value = "";
  }
}

// ── analysis actions ─────────────────────────────────────────────────────
function markPassage(id, patch) {
  corrections.value.passages[id] = { ...(corrections.value.passages[id] ?? {}), ...patch };
  // Reflect immediately; the server re-derives on save.
  const row = passages.value.find((p) => p.id === id);
  if (row) {
    if (patch.drop !== undefined) row.dropped = patch.drop;
    if (patch.important !== undefined) row.important = patch.important;
    if (patch.transcript) {
      row.transcript = patch.transcript;
      row.kind = "observed";
    }
  }
}

function startEditTranscript() {
  editedTranscript.value = activeRow.value?.transcript ?? "";
  editingTranscript.value = true;
}
function saveTranscript() {
  const text = editedTranscript.value.trim();
  if (text) markPassage(activePassage.value, { transcript: text });
  editingTranscript.value = false;
}

function renameSpeaker(sp) {
  renamingSpeaker.value = sp.id;
  speakerName.value = sp.label;
}
function saveSpeaker() {
  const label = speakerName.value.trim();
  if (label) {
    corrections.value.speakers[renamingSpeaker.value] = { label };
    const sp = (corrected.value?.speakers ?? []).find((s) => s.id === renamingSpeaker.value);
    if (sp) sp.label = label;
  }
  renamingSpeaker.value = null;
}

async function planVersion() {
  if (busy.value) return;
  busy.value = "planning";
  errorMessage.value = "";
  try {
    await api.patch(`/ugc/footage/${session.value.id}`, { corrections: corrections.value });
    const { data } = await api.post(`/ugc/footage/${session.value.id}/plan`);
    plan.value = data?.data?.plan ?? null;
    session.value = data?.data?.session ?? session.value;
    if (plan.value?.passages?.length) activePassage.value = plan.value.passages[0].id;
    screen.value = "plan";
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not plan your version. Try again.");
  } finally {
    busy.value = "";
  }
}

const activePlanRow = computed(() => (plan.value?.passages ?? []).find((p) => p.id === activePassage.value));

// ── approve actions ──────────────────────────────────────────────────────
async function openPresenterPicker() {
  presenterPicker.value = true;
  try {
    const { data } = await api.get("/characters", { params: { include_stock: 1 } });
    presenters.value = data?.data?.characters ?? [];
  } catch {
    presenters.value = [];
  }
}
function pickPresenter(c) {
  presenter.value = { id: c.id, name: c.name, thumbnail: c.reference_asset?.thumbnail_url };
  presenterPicker.value = false;
}

async function produce() {
  if (!canProduce.value) return;
  producing.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post(`/ugc/footage/${session.value.id}/produce`, {
      consent_reference: consentReference.value,
      consent_presenter: consentPresenter.value,
      character_id: presenter.value?.id ?? null,
      credits: plan.value?.credits ?? 0,
      answers: answers.value,
    });
    const runId = data?.data?.run_id;
    if (runId) router.push({ name: "ugc-run", params: { runId }, query: { footage: session.value.id } });
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not start production.");
  } finally {
    producing.value = false;
  }
}

// ── resume an existing session at the right screen ───────────────────────
async function resume(id) {
  try {
    const { data } = await api.get(`/ugc/footage/${id}`);
    session.value = data?.data?.session ?? null;
    corrected.value = data?.data?.corrected ?? null;
    if (!session.value) return;
    brief.value = session.value.brief ?? "";
    rights.value = session.value.rights ?? "reference";
    plan.value = session.value.plan_json ?? null;
    const a = session.value.source_asset;
    if (a) sourceAsset.value = { id: a.id, title: a.title, thumbnail_url: a.thumbnail_url, duration_seconds: a.duration_seconds };
    if (route.name === "footage-compare" && plan.value) screen.value = "compare";
    else if (session.value.status === "planned" && plan.value) screen.value = "plan";
    else if (session.value.status === "producing") screen.value = "compare";
    else if (session.value.status === "read") screen.value = "analysis";
    if (passages.value.length) activePassage.value = passages.value[0].id;
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not load that session.");
  }
}

const sourceById = computed(() => {
  const map = {};
  for (const p of passages.value) map[p.id] = p;
  return map;
});

onMounted(() => {
  if (!authStore.user?.is_internal) {
    router.replace({ name: "dashboard" });
    return;
  }
  if (route.params.sessionId) resume(Number(route.params.sessionId));
});
</script>

<template>
  <div class="ff-shell">
    <AppSidebar :user="authStore.user" active-page="from-my-footage" @logout="authStore.logout()" />

    <main class="ff-main">
      <header class="ff-top">
        <div class="ff-crumb">
          My Footage /
          <b>{{ { intake: "Bring a reference", analysis: "Understanding the source", plan: "Plan your version", approve: "Approve & produce", compare: "Compare" }[screen] }}</b>
        </div>
        <span v-if="screen === 'intake'" class="ff-note">
          Just want the vibe, not a recreation? <router-link :to="{ name: 'ugc-ads' }">Use UGC instead →</router-link>
        </span>
        <span v-else-if="sourceAsset" class="ff-note">{{ sourceAsset.title }}</span>
      </header>

      <nav class="ff-steps" aria-label="My Footage steps">
        <button v-for="(item, i) in FLOW_STEPS" :key="item.key" type="button" :disabled="!canVisit(item.key) || Boolean(busy) || producing" :aria-current="screen === item.key ? 'step' : undefined" :class="{ on: screen === item.key }" @click="screen = item.key"><span>{{ i + 1 }}</span>{{ item.label }}</button>
      </nav>
      <div v-if="errorMessage" class="ff-error">{{ errorMessage }}</div>

      <!-- ── 1 · Intake ─────────────────────────────────────────────────── -->
      <div v-if="screen === 'intake'" class="ff-body ff-cols">
        <div class="ff-col-main">
          <h1>Bring a video to work from</h1>
          <p class="ff-sub">Upload or link a video whose structure you want to keep or adapt. We'll watch it before asking anything else.</p>

          <div class="ff-tabs">
            <button type="button" :class="['ff-tab', intakeMode === 'upload' ? 'on' : '']" @click="intakeMode = 'upload'">Upload a file</button>
            <button type="button" :class="['ff-tab', intakeMode === 'link' ? 'on' : '']" @click="intakeMode = 'link'">Paste a link</button>
          </div>

          <div class="ff-card">
            <template v-if="intakeMode === 'upload'">
              <div v-if="sourceAsset" class="ff-source">
                <video v-if="sourceUrl" class="ff-source-video" :src="sourceUrl" controls playsinline preload="metadata" @error="sourcePreviewError = 'This browser could not play the preview. You can still try analysing the video.'" />
                <span v-else class="ff-video-placeholder">Video selected</span>
                <div>
                  <b>{{ sourceAsset.title }}</b>
                  <span v-if="sourceAsset.duration_seconds" class="ff-muted">{{ Math.round(sourceAsset.duration_seconds) }}s</span>
                </div>
                <button class="ff-btn" type="button" @click="sourcePicker = true">Choose another</button>
              </div>
              <button v-else class="ff-drop" type="button" @click="sourcePicker = true">
                Pick a video from your assets — or upload one there first
              </button>
            </template>
            <template v-else>
              <label class="ff-label">Direct link to a video file</label>
              <div class="ff-row">
                <input v-model="linkUrl" type="url" maxlength="2000" placeholder="https://example.com/video.mp4" />
                <button class="ff-btn" type="button" :disabled="!linkUrl.trim() || fetchingLink" @click="fetchLink">
                  {{ fetchingLink ? "Fetching…" : "Fetch it" }}
                </button>
              </div>
              <p class="ff-muted">We download it server-side into your assets. Page links (YouTube, TikTok) can't be fetched — use the file itself.</p>
              <div v-if="sourceAsset && intakeMode === 'link'" class="ff-source" style="margin-top: 10px">
                <video v-if="sourceUrl" class="ff-source-video" :src="sourceUrl" controls playsinline preload="metadata" @error="sourcePreviewError = 'This browser could not play the preview. You can still try analysing the video.'" />
                <span v-else class="ff-video-placeholder">Video selected</span>
                <div><b>{{ sourceAsset.title }}</b> <span class="ff-muted">fetched ✓</span></div>
              </div>
            </template>
          </div>

          <p v-if="sourceAsset && sourcePreviewError" class="ff-muted" role="status">{{ sourcePreviewError }}</p>
          <div class="ff-card">
            <label class="ff-label" for="ff-brief">What do you want to change?</label>
            <textarea id="ff-brief" v-model="brief" rows="4" maxlength="2000"
              placeholder="Keep the interview format and questions, but make it about my product instead — new host, new product."></textarea>
            <p class="ff-muted">A sentence is enough to start. We'll turn this into a reviewable plan once we understand the source.</p>
          </div>

          <div class="ff-card">
            <h3>Is this footage yours to reuse?</h3>
            <div class="ff-rights">
              <button type="button" :class="['ff-right', rights === 'reuse' ? 'on' : '']" @click="rights = 'reuse'">
                <b>Reuse directly</b>
                <span>I own it or have rights — keep the actual clip and sound where possible.</span>
              </button>
              <button type="button" :class="['ff-right', rights === 'reference' ? 'on' : '']" @click="rights = 'reference'">
                <b>Reference only</b>
                <span>Build something new inspired by its structure — don't reuse this footage, faces, or brand.</span>
              </button>
            </div>
          </div>
        </div>

        <aside class="ff-col-side">
          <div class="ff-card">
            <h3>What happens next</h3>
            <p class="ff-muted">We'll watch the video, note who's speaking, what's shown, and how it's structured.</p>
            <p class="ff-muted">Reading the source is included; you approve any production cost separately, before it starts.</p>
          </div>
          <button class="ff-btn ff-btn-primary" type="button" :disabled="!sourceAsset || busy === 'reading'" @click="understandSource">
            {{ busy === "reading" ? "Watching your video…" : "Understand this source →" }}
          </button>
        </aside>
      </div>

      <!-- ── 2 · Analysis ───────────────────────────────────────────────── -->
      <div v-else-if="screen === 'analysis'" class="ff-body">
        <h1>Here's what we found</h1>
        <p class="ff-sub">A concise read of the source, not a full report. Correct anything that's off before we plan your version — most of this you can leave as-is.</p>

        <div class="ff-cols" style="margin-top: 18px">
          <div class="ff-col-list">
            <video v-if="sourceUrl" ref="sourceVideo" class="ff-video" :src="sourceUrl" controls playsinline preload="metadata" @loadedmetadata="seekSource" />
            <p v-if="sourcePreviewError" class="ff-muted">{{ sourcePreviewError }}</p>
            <span class="ff-eyebrow">Passages</span>
            <button
              v-for="p in passages"
              :key="p.id"
              type="button"
              :class="['ff-passage', p.id === activePassage ? 'on' : '', p.dropped ? 'dropped' : '']"
              @click="activePassage = p.id; editingTranscript = false"
            >
              <span class="ff-passage-t">
                <b>{{ p.start }}s · {{ p.title }}</b>
                <span :class="['ff-badge', p.kind]">{{ kindBadge[p.kind] }}</span>
              </span>
              <span class="ff-muted">{{ p.dropped ? "Won't be preserved" : p.summary }}</span>
              <span v-if="p.important" class="ff-important">★ the important part</span>
            </button>

            <div class="ff-card" style="margin-top: 10px">
              <span class="ff-eyebrow">Speakers</span>
              <div v-for="sp in corrected?.speakers ?? []" :key="sp.id" class="ff-speaker">
                <template v-if="renamingSpeaker === sp.id">
                  <input v-model="speakerName" aria-label="Speaker name" placeholder="e.g. Host or customer" maxlength="60" @keyup.enter="saveSpeaker" />
                  <button class="ff-link" type="button" @click="saveSpeaker">Save</button>
                </template>
                <template v-else>
                  <span>{{ sp.label }} <span v-if="!sp.on_camera" class="ff-muted">(off-camera)</span></span>
                  <button class="ff-link" type="button" @click="renameSpeaker(sp)">Rename</button>
                </template>
              </div>
            </div>
          </div>

          <div v-if="activeRow" class="ff-card ff-col-detail">
            <div class="ff-detail-h">
              <h2>{{ activeRow.title }}</h2>
              <span :class="['ff-badge', activeRow.kind]">{{ kindBadge[activeRow.kind] }}</span>
            </div>
            <span class="ff-eyebrow">Transcript / observation</span>
            <template v-if="editingTranscript">
              <textarea v-model="editedTranscript" aria-label="Corrected transcript" placeholder="Write what is actually spoken or shown in this passage" rows="4" maxlength="1000"></textarea>
              <div class="ff-row" style="margin-top: 8px">
                <button class="ff-btn" type="button" @click="saveTranscript">Save correction</button>
                <button class="ff-link" type="button" @click="editingTranscript = false">Cancel</button>
              </div>
            </template>
            <div v-else class="ff-quote">{{ activeRow.transcript || "(nothing heard or read here)" }}</div>
            <span class="ff-eyebrow" style="margin-top: 12px">Why we read it this way</span>
            <div class="ff-quote ff-quote-plain">{{ activeRow.note || "Directly observed." }}</div>
            <div class="ff-detail-actions">
              <button class="ff-btn" type="button" @click="startEditTranscript">Correct this</button>
              <button class="ff-btn" type="button" @click="markPassage(activeRow.id, { important: !activeRow.important })">
                {{ activeRow.important ? "★ Marked important" : "This is the important part" }}
              </button>
              <button class="ff-btn ff-btn-quiet" type="button" @click="markPassage(activeRow.id, { drop: !activeRow.dropped })">
                {{ activeRow.dropped ? "Preserve it after all" : "Don't preserve this" }}
              </button>
            </div>
          </div>
        </div>

        <div class="ff-footer">
          <button class="ff-link" type="button" @click="screen = 'intake'">← Back to source</button>
          <div class="ff-row">
            <span v-if="unclearCount" class="ff-warn">
              {{ unclearCount }} passage{{ unclearCount === 1 ? "" : "s" }} need{{ unclearCount === 1 ? "s" : "" }} your input
            </span>
            <button class="ff-btn ff-btn-primary" type="button" :disabled="busy === 'planning'" @click="planVersion">
              {{ busy === "planning" ? "Planning…" : "Plan my version →" }}
            </button>
          </div>
        </div>
      </div>

      <!-- ── 3 · Target plan ────────────────────────────────────────────── -->
      <div v-else-if="screen === 'plan'" class="ff-body">
        <h1>Plan your version</h1>
        <div v-if="canRestyle" class="ff-card ff-restyle">
          <div class="ff-restyle-m">
            <b>Restyle the video itself</b>
            <p class="ff-muted">Your clip goes through the video model with your instruction — "{{ session?.brief || 'your brief' }}" — keeping the original motion, timing and soundtrack. No passages, no presenter.</p>
            <label class="ff-label" style="margin-top:8px">How closely to follow the source</label>
            <div class="ff-pills">
              <button v-for="m in ['adhere_1', 'flex_1', 'reimagine_1']" :key="m" type="button"
                :class="['ff-pill', 'ff-pill-btn', restyleMode === m ? 'on' : '']" @click="restyleMode = m">
                {{ { adhere_1: "Very close", flex_1: "Balanced", reimagine_1: "Loose" }[m] }}
              </button>
            </div>
          </div>
          <div class="ff-restyle-a">
            <b>{{ restyleCredits }} credits</b>
            <button class="ff-btn ff-btn-primary" type="button" :disabled="restyling" @click="restyle">
              {{ restyling ? "Starting…" : "Restyle it →" }}
            </button>
            <span class="ff-muted">Charged only if it succeeds. Some content is declined by the model.</span>
          </div>
        </div>
        <p class="ff-sub">Here's what stays, what's rebuilt, and what's new — based on "{{ session?.brief || 'your brief' }}".</p>
        <div class="ff-pills">
          <span v-if="plan?.summary?.performed" class="ff-pill">{{ plan.summary.performed }} newly performed</span>
          <span v-if="plan?.summary?.rebuilt" class="ff-pill">{{ plan.summary.rebuilt }} rebuilt</span>
          <span v-if="plan?.summary?.reused" class="ff-pill">{{ plan.summary.reused }} reused directly</span>
          <span v-if="rights === 'reference'" class="ff-pill ff-pill-warn">Original footage not reused</span>
        </div>

        <div class="ff-cols" style="margin-top: 18px">
          <div class="ff-col-list">
            <span class="ff-eyebrow">Passages</span>
            <button
              v-for="p in plan?.passages ?? []"
              :key="p.id"
              type="button"
              :class="['ff-passage', p.id === activePassage ? 'on' : '']"
              @click="activePassage = p.id"
            >
              <span class="ff-passage-t">
                <b>{{ p.title }}</b>
                <span :class="['ff-badge', p.treatment]">{{ treatmentBadge[p.treatment] }}</span>
              </span>
            </button>
          </div>

          <div v-if="activePlanRow" class="ff-card ff-col-detail">
            <div class="ff-detail-h">
              <h2>{{ activePlanRow.title }}</h2>
              <span :class="['ff-badge', activePlanRow.treatment]">{{ treatmentBadge[activePlanRow.treatment] }}</span>
            </div>
            <div class="ff-compare">
              <div>
                <span class="ff-eyebrow">Source</span>
                <div class="ff-quote ff-quote-plain">{{ activePlanRow.source }}</div>
              </div>
              <div>
                <span class="ff-eyebrow ff-eyebrow-brand">Your version</span>
                <div class="ff-quote ff-quote-brand">{{ activePlanRow.target }}</div>
              </div>
            </div>
            <span class="ff-eyebrow" style="margin-top: 12px">Why this treatment</span>
            <div class="ff-quote ff-quote-plain">{{ activePlanRow.reason }}</div>
            <p v-if="activePlanRow.segment.script_text" class="ff-muted" style="margin-top: 10px">
              Spoken in your version: "{{ activePlanRow.segment.script_text }}"
            </p>
          </div>

          <aside class="ff-col-side">
            <span class="ff-eyebrow">What we need from you</span>
            <div v-if="needsPresenter" class="ff-card">
              <b class="ff-need-t">Presenter</b>
              <p class="ff-muted">For the new performance — needed, since the source performance can't be reused.</p>
              <div v-if="presenter" class="ff-source">
                <img v-if="presenter.thumbnail" :src="presenter.thumbnail" alt="" />
                <b>{{ presenter.name }}</b>
                <button class="ff-link" type="button" @click="openPresenterPicker">Change</button>
              </div>
              <button v-else class="ff-drop" type="button" @click="openPresenterPicker">Pick a saved character</button>
            </div>
            <div v-for="n in textNeeds" :key="n.key" class="ff-card">
              <b class="ff-need-t">{{ n.label }}</b>
              <p class="ff-muted">{{ n.why }}</p>
              <input v-model="answers[n.key]" maxlength="500" placeholder="Type it exactly as it should appear" />
            </div>
          </aside>
        </div>

        <div class="ff-footer">
          <button class="ff-link" type="button" @click="screen = 'analysis'">← Back to source analysis</button>
          <button class="ff-btn ff-btn-primary" type="button" @click="screen = 'approve'">Review cost →</button>
        </div>
      </div>

      <!-- ── 4 · Approve & produce ──────────────────────────────────────── -->
      <div v-else-if="screen === 'approve'" class="ff-body ff-cols">
        <div class="ff-col-main" style="max-width: 520px">
          <h1>Ready to produce</h1>
          <p class="ff-sub">This is what you're approving. Nothing outside this scope will be generated without a new cost decision.</p>
          <div class="ff-card ff-summary">
            <div><span>Source</span><b>{{ sourceAsset?.title }} — {{ rights === "reuse" ? "reusable" : "reference only" }}</b></div>
            <div><span>Target</span><b>{{ session?.brief ? session.brief.slice(0, 60) : "your version" }}</b></div>
            <div><span>Passages reused directly</span><b>{{ plan?.summary?.reused ?? 0 }} of {{ plan?.summary?.total ?? 0 }}</b></div>
            <div><span>Passages newly performed</span><b>{{ plan?.summary?.performed ?? 0 }} of {{ plan?.summary?.total ?? 0 }}</b></div>
            <div><span>Passages rebuilt</span><b>{{ plan?.summary?.rebuilt ?? 0 }} of {{ plan?.summary?.total ?? 0 }}</b></div>
            <div v-if="presenter"><span>Presenter</span><b>{{ presenter.name }}</b></div>
          </div>
        </div>

        <div class="ff-col-main">
          <div class="ff-card ff-summary">
            <h3>Cost</h3>
            <div><span>Understanding the source</span><b>included</b></div>
            <div><span>Producing your version</span><b>{{ plan?.credits ?? 0 }} credits</b></div>
            <div class="ff-total"><span>Total to approve</span><b>{{ plan?.credits ?? 0 }} credits</b></div>
            <p class="ff-muted">This estimate covers the approved passages. New performances and generated visuals contribute to the cost; reused footage reduces generation work.</p>
          </div>

          <div class="ff-card">
            <h3>Before we start</h3>
            <label class="ff-check">
              <input v-model="consentReference" type="checkbox" />
              I confirm I have permission to use this video as a reference for a new production.
            </label>
            <label class="ff-check">
              <input v-model="consentPresenter" type="checkbox" />
              I confirm I have the rights to use {{ needsPresenter ? (presenter ? `${presenter.name}'s likeness and voice` : "the selected presenter’s likeness and voice") : "the footage and assets in this version" }}.
            </label>
            <p v-if="needsPresenter && !presenter" class="ff-warn">Pick the presenter on the plan before starting.</p>
            <div class="ff-row" style="justify-content: flex-end; margin-top: 10px">
              <button class="ff-btn ff-btn-primary" type="button" :disabled="!canProduce" @click="produce">
                {{ producing ? "Starting…" : "Start production" }}
              </button>
            </div>
          </div>
          <div class="ff-footer" style="border: none; padding: 0">
            <button class="ff-link" type="button" @click="screen = 'plan'">← Back to plan</button>
          </div>
        </div>
      </div>

      <!-- ── 5 · Compare (after production) ─────────────────────────────── -->
      <div v-else-if="screen === 'compare'" class="ff-body">
        <h1>Compare with the source</h1>
        <p class="ff-sub">The original stays untouched — your version is a new, separate, editable project.</p>
        <div class="ff-pills" style="margin: 10px 0 16px">
          <button
            v-for="p in plan?.passages ?? []"
            :key="p.id"
            type="button"
            :class="['ff-pill', 'ff-pill-btn', p.id === activePassage ? 'on' : '']"
            @click="activePassage = p.id"
          >{{ p.title }}</button>
        </div>
        <div v-if="activePlanRow" class="ff-compare ff-compare-wide">
          <div>
            <span class="ff-eyebrow">Source</span>
            <video v-if="sourceUrl" ref="sourceVideo" class="ff-video ff-comparison-video" :src="sourceUrl" controls playsinline preload="metadata" @loadedmetadata="seekSource" />
            <p v-else class="ff-muted">{{ sourcePreviewError || 'Loading source preview…' }}</p>
            <div class="ff-panel">{{ sourceById[activePlanRow.id]?.transcript || activePlanRow.source }}</div>
          </div>
          <div>
            <span class="ff-eyebrow ff-eyebrow-brand">Your version</span>
            <video v-if="producedPreview?.visual_url && producedPreview.visual_type === 'video'" class="ff-video ff-comparison-video" :src="producedPreview.visual_url" controls playsinline />
            <img v-else-if="producedPreview?.visual_url" class="ff-video ff-comparison-video" :src="producedPreview.visual_url" alt="Generated passage" />
            <div v-else class="ff-video ff-preview-empty">{{ producedScene?.error ? 'This passage needs attention. Open production to retry.' : 'This passage preview is not ready yet.' }}</div>
            <audio v-if="producedPreview?.audio_url" :src="producedPreview.audio_url" controls class="ff-audio" />
            <div class="ff-panel ff-panel-brand">{{ activePlanRow.segment.script_text || activePlanRow.target }}</div>
          </div>
        </div>
        <p v-if="activePlanRow" class="ff-muted" style="margin-top: 10px">{{ activePlanRow.reason }}</p>
        <div class="ff-footer">
          <span class="ff-muted">Passage previews are separate from the final export. Review, revise and download your version when production finishes.</span>
          <button v-if="producedTake?.status === 'ready_for_review'" type="button" class="ff-btn ff-btn-primary" @click="router.push({ name: 'ugc-review', params: { projectId: producedTake.id }, query: { footage: session.id } })">Review & revise →</button>
          <button
            v-if="session?.run_id"
            class="ff-btn ff-btn-primary"
            type="button"
            @click="router.push({ name: 'ugc-run', params: { runId: session.run_id }, query: { footage: session.id } })"
          >Open production →</button>
        </div>
      </div>

      <MediaPickerModal mode="visual" :visible="sourcePicker" @close="sourcePicker = false" @select="selectSource" />

      <div v-if="presenterPicker" class="ff-scrim" @click.self="presenterPicker = false">
        <div class="ff-modal">
          <h3>Pick a presenter</h3>
          <div class="ff-presenters">
            <button v-for="c in presenters" :key="c.id" type="button" class="ff-presenter" @click="pickPresenter(c)">
              <img v-if="c.reference_asset?.thumbnail_url" :src="c.reference_asset.thumbnail_url" alt="" />
              <span v-else class="ff-presenter-blank">☺</span>
              <b>{{ c.name }}</b>
            </button>
            <p v-if="!presenters.length" class="ff-muted">No characters yet — create one under Characters first.</p>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.ff-shell { display: flex; min-height: 100vh; background: var(--color-bg-deep); }
.ff-main {
  margin-left: var(--sidebar-width, 220px);
  flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 0 28px 48px;
}
.ff-top {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 18px 0 14px; border-bottom: 1px solid var(--color-border); flex-wrap: wrap;
}
.ff-crumb { font-size: 13.5px; color: var(--color-text-muted); }
.ff-crumb b { color: var(--color-text-primary); }
.ff-note { font-size: 12.5px; color: var(--color-text-muted); }
.ff-error {
  margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px;
  background: var(--color-danger-soft, rgba(179, 38, 30, 0.08)); color: var(--color-danger, #b3261e);
}
.ff-body { padding-top: 22px; max-width: 1200px; }
.ff-body h1 { margin: 0; font-size: 26px; }
.ff-sub { margin: 6px 0 0; font-size: 13.5px; color: var(--color-text-muted); line-height: 1.5; max-width: 760px; }
.ff-cols { display: flex; gap: 22px; align-items: flex-start; }
.ff-col-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.ff-col-side { width: 300px; flex: 0 0 auto; display: flex; flex-direction: column; gap: 12px; }
.ff-col-list { width: 360px; flex: 0 0 auto; display: flex; flex-direction: column; gap: 8px; }
.ff-col-detail { flex: 1; min-width: 0; }
.ff-card {
  background: var(--color-bg-card); border: 1px solid var(--color-border);
  border-radius: 14px; padding: 16px 18px;
}
.ff-card h3 { margin: 0 0 10px; font-size: 15px; }
.ff-tabs { display: flex; gap: 8px; margin: 16px 0 12px; }
.ff-tab {
  border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary);
  border-radius: 999px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.ff-tab.on { border-color: var(--color-accent); color: var(--color-accent); }
.ff-label { display: block; font-size: 12.5px; font-weight: 600; color: var(--color-text-muted); margin-bottom: 6px; }
.ff-card textarea, .ff-card input, .ff-col-detail textarea {
  width: 100%; border: 1px solid var(--color-border); border-radius: 10px; padding: 11px 12px;
  font-size: 13.5px; font-family: inherit; background: var(--color-bg-deep); color: var(--color-text-primary);
}
.ff-card textarea { resize: vertical; }
.ff-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ff-muted { font-size: 12.5px; color: var(--color-text-muted); margin: 6px 0 0; line-height: 1.5; }
.ff-btn {
  border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-primary);
  border-radius: 9px; padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.ff-btn:disabled { opacity: 0.55; cursor: default; }
.ff-btn-primary { background: var(--color-accent); border-color: var(--color-accent); color: #fff; }
.ff-btn-quiet { color: var(--color-text-muted); }
.ff-link { border: none; background: none; color: var(--color-accent); font-size: 13px; font-weight: 600; cursor: pointer; padding: 0; }
.ff-drop {
  width: 100%; border: 1.5px dashed var(--color-border); border-radius: 12px; background: none;
  color: var(--color-text-muted); padding: 20px 14px; font-size: 13px; cursor: pointer;
}
.ff-source { display: flex; align-items: center; gap: 12px; }
.ff-source img { width: 92px; height: 54px; object-fit: cover; border-radius: 8px; }
.ff-rights { display: flex; gap: 10px; }
.ff-right {
  flex: 1; text-align: left; border: 1.5px solid var(--color-border); border-radius: 12px;
  background: none; color: var(--color-text-primary); padding: 14px; cursor: pointer;
  display: flex; flex-direction: column; gap: 4px;
}
.ff-right span { font-size: 12.5px; color: var(--color-text-muted); line-height: 1.45; }
.ff-right.on { border-color: var(--color-accent); }
.ff-right.on b { color: var(--color-accent); }
.ff-eyebrow {
  display: block; font-size: 11px; font-weight: 700; letter-spacing: 0.05em;
  text-transform: uppercase; color: var(--color-text-muted); margin-bottom: 8px;
}
.ff-eyebrow-brand { color: var(--color-accent); }
.ff-passage {
  text-align: left; width: 100%; border: 1px solid var(--color-border); border-radius: 12px;
  background: var(--color-bg-card); color: var(--color-text-primary); padding: 12px 14px; cursor: pointer;
  display: flex; flex-direction: column; gap: 4px;
}
.ff-passage.on { border-color: var(--color-accent); }
.ff-passage.dropped { opacity: 0.55; }
.ff-passage-t { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13.5px; }
.ff-badge {
  flex: 0 0 auto; font-size: 10.5px; font-weight: 700; border-radius: 999px; padding: 3px 8px;
  background: var(--color-border); color: var(--color-text-muted); text-transform: uppercase; letter-spacing: 0.03em;
}
.ff-badge.observed, .ff-badge.reused { background: var(--color-success-soft, rgba(31, 122, 77, 0.12)); color: var(--color-success, #1f7a4d); }
.ff-badge.inferred, .ff-badge.rebuilt { background: var(--color-info-soft, rgba(59, 100, 160, 0.14)); color: var(--color-info, #3b64a0); }
.ff-badge.unclear { background: var(--color-warning-soft, rgba(180, 116, 14, 0.14)); color: var(--color-warning-strong, #efb968); }
.ff-badge.new { background: var(--color-primary-soft, rgba(20, 99, 86, 0.1)); color: var(--color-accent); }
.ff-important { font-size: 11.5px; color: var(--color-warning-strong, #b4740e); font-weight: 600; }
.ff-speaker { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 7px 0; font-size: 13.5px; }
.ff-detail-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.ff-detail-h h2 { margin: 0; font-size: 18px; }
.ff-quote {
  border: 1px solid var(--color-border); border-radius: 12px; padding: 13px 15px;
  font-size: 14.5px; line-height: 1.55; background: var(--color-bg-deep);
}
.ff-quote-plain { font-size: 13px; color: var(--color-text-muted); }
.ff-quote-brand { border-color: var(--color-accent); }
.ff-detail-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
.ff-footer {
  display: flex; align-items: center; justify-content: space-between; gap: 14px;
  border-top: 1px solid var(--color-border); margin-top: 22px; padding-top: 16px; flex-wrap: wrap;
}
.ff-warn { font-size: 13px; color: var(--color-warning-strong, #b4740e); font-weight: 600; }
.ff-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.ff-pill {
  font-size: 12px; font-weight: 600; border-radius: 999px; padding: 6px 12px;
  background: var(--color-primary-soft, rgba(255, 107, 53, 0.08)); color: var(--color-accent); border: none;
}
.ff-pill-warn { background: var(--color-danger-soft, rgba(179, 38, 30, 0.08)); color: var(--color-danger, #b3261e); }
.ff-pill-btn { cursor: pointer; background: var(--color-bg-card); color: var(--color-text-primary); border: 1px solid var(--color-border); }
.ff-pill-btn.on { border-color: var(--color-accent); color: var(--color-accent); }
.ff-compare { display: flex; gap: 14px; }
.ff-compare > div { flex: 1; min-width: 0; }
.ff-compare-wide .ff-panel { min-height: 120px; }
.ff-panel {
  border: 1px solid var(--color-border); border-radius: 12px; padding: 14px;
  font-size: 14px; line-height: 1.55; background: var(--color-bg-deep); color: var(--color-text-muted);
}
.ff-panel-brand { border: 1.5px solid var(--color-accent); color: var(--color-text-primary); }
.ff-summary { display: flex; flex-direction: column; gap: 0; }
.ff-summary > div {
  display: flex; align-items: baseline; justify-content: space-between; gap: 14px;
  padding: 9px 0; border-bottom: 1px solid var(--color-border); font-size: 13.5px;
}
.ff-summary > div > span { color: var(--color-text-muted); }
.ff-summary > div:last-of-type { border-bottom: none; }
.ff-summary .ff-total {
  background: var(--color-primary-soft, rgba(255, 107, 53, 0.08)); border-radius: 10px;
  padding: 12px 14px; margin-top: 6px; border-bottom: none; font-size: 14.5px;
}
.ff-check { display: flex; gap: 10px; align-items: flex-start; font-size: 13.5px; line-height: 1.5; padding: 7px 0; cursor: pointer; }
.ff-check input { width: auto; margin-top: 2px; }
.ff-need-t { font-size: 13.5px; }
.ff-scrim {
  position: fixed; inset: 0; background: rgba(10, 10, 12, 0.55); z-index: 60;
  display: flex; align-items: center; justify-content: center; padding: 20px;
}
.ff-modal {
  background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 16px;
  padding: 20px; width: min(680px, 100%); max-height: 80vh; overflow: auto;
}
.ff-modal h3 { margin: 0 0 14px; font-size: 16px; }
.ff-presenters { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
.ff-presenter {
  border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-bg-deep);
  color: var(--color-text-primary); padding: 10px; cursor: pointer; display: flex; flex-direction: column; gap: 8px; align-items: center;
}
.ff-presenter img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 9px; }
.ff-presenter-blank { font-size: 40px; padding: 20px 0; }
.ff-restyle { display: flex; gap: 18px; align-items: flex-start; margin-top: 14px; border-color: var(--color-primary); }
.ff-restyle-m { flex: 1; min-width: 0; }
.ff-restyle-a { width: 200px; flex: 0 0 auto; display: flex; flex-direction: column; gap: 8px; align-items: flex-end; text-align: right; }
@media (max-width: 900px) {
  .ff-restyle { flex-direction: column; }
  .ff-restyle-a { width: 100%; align-items: flex-start; text-align: left; }
  .ff-main { margin-left: 0; padding: 0 14px 90px; }
  .ff-cols { flex-direction: column; }
  .ff-col-side, .ff-col-list { width: 100%; }
  .ff-compare { flex-direction: column; }
  .ff-rights { flex-direction: column; }
}

.ff-steps { display: flex; gap: 8px; padding: 20px 0 0; overflow-x: auto; }
.ff-steps button { display: flex; align-items: center; gap: 8px; flex: 1; white-space: nowrap; border: 1px solid var(--color-border); background: var(--color-bg-card); color: var(--color-text-muted); border-radius: 10px; padding: 12px; cursor: pointer; font: inherit; font-size: 13px; }
.ff-steps button.on { color: var(--color-accent); border-color: var(--color-accent); }
.ff-steps button:disabled { opacity: .5; cursor: default; }
.ff-steps button span { font-weight: 700; }
.ff-video { width: 100%; max-height: 320px; background: #08080b; border: 1px solid var(--color-border); border-radius: 12px; object-fit: contain; margin-bottom: 16px; }
.ff-comparison-video { height: 420px; max-height: 55vh; }
.ff-preview-empty { min-height: 240px; display: grid; place-items: center; padding: 20px; color: var(--color-text-muted); text-align: center; }
.ff-audio { width: 100%; margin-bottom: 12px; }
.ff-cols { flex-wrap: wrap; }
.ff-col-list { flex: 1 1 260px; min-width: 0; }
.ff-col-detail { flex: 2 1 360px; min-width: 0; }
.ff-col-side { flex: 1 1 230px; min-width: 0; }
@media (max-width: 760px) { .ff-cols { display: flex; flex-direction: column; } .ff-col-list, .ff-col-detail, .ff-col-side { width: 100%; flex: auto; } .ff-compare { grid-template-columns: 1fr; } }

.ff-source { flex-wrap: wrap; min-width: 0; }
.ff-source > div { flex: 1 1 160px; min-width: 0; }
.ff-source b { overflow-wrap: anywhere; }
.ff-source > button { flex-shrink: 0; }
.ff-source-video { flex: 0 0 100%; width: 100%; max-height: 260px; border-radius: 10px; background: #08080b; object-fit: contain; }
.ff-video-placeholder { flex: 0 0 100%; padding: 20px; border-radius: 10px; background: var(--color-bg-deep); color: var(--color-text-muted); font-size: 13px; }
</style>
