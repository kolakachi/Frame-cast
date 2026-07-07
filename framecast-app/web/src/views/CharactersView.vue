<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";
import api from "../services/api";
import AppSidebar from "../components/AppSidebar.vue";
import GridSkeleton from "../components/skeletons/GridSkeleton.vue";
import NotifBell from "../components/NotifBell.vue";

const router = useRouter();
const authStore = useAuthStore();

const mePayload = ref(null);
const characters = ref([]);
const loading = ref(true);
const error = ref("");

// Modal state — same modal handles both Create and Edit.
const createOpen = ref(false);
const editingId = ref(null);              // when set, we're editing an existing character
const createName = ref("");
const createDescription = ref("");
const createIdentityStrength = ref("balanced"); // subtle | balanced | strong | locked
const consentChecked = ref(false); // likeness consent — required when a reference photo is used
const createFile = ref(null);             // newly-picked file (replaces existing — legacy single mode)
const createPreviewUrl = ref("");         // object URL for the newly-picked file
const existingThumbUrl = ref(null);       // existing primary reference image URL when editing
const removeExistingImage = ref(false);   // user clicked Remove on the primary existing image

// Multi-reference state. New files queued for upload + existing references kept on the character.
const createNewFiles = ref([]);           // array of File objects to upload
const createNewFilePreviews = ref([]);    // matching object URLs
const existingReferences = ref([]);       // [{id, storage_url, thumbnail_url}] preserved on save
// True when this character will carry a reference photo (any source) — must
// match the consent gate in submit so the checkbox is ALWAYS shown when
// consent is required (legacy single-file uploads were missing it).
const willHaveReference = computed(() =>
  createNewFilePreviews.value.length > 0
  || !!createFile.value
  || (existingReferences.value.length > 0 && !removeExistingImage.value)
);
const IDENTITY_STRENGTH_OPTIONS = [
  { key: "subtle",   label: "Subtle",   sub: "Looser drift" },
  { key: "balanced", label: "Balanced", sub: "Recommended" },
  { key: "strong",   label: "Strong",   sub: "Sticks closer" },
  { key: "locked",   label: "Locked",   sub: "Near-exact" },
];
const createSaving = ref(false);
const createError = ref("");

// Delete confirmation
const deleteTarget = ref(null);
const deletePending = ref(false);

// ── Generate-image modal ──────────────────────────────────────────────
// POST kicks off a queue job (returns 202) so the long OpenAI call doesn't
// hold the HTTP connection open past Cloudflare's request timeout. We then
// poll the status endpoint every 2s until succeeded|failed.
const genTarget = ref(null);                     // character being previewed
const genPrompt = ref("");
// Reference-image engines, in default order. nano-banana-pro leads — best
// identity/skin-tone fidelity; gpt-image-2 (/edits) is the legacy path.
// Costs mirror ImageAdapterFactory::referenceGenerationCost (backend is
// authoritative). Only shown for characters that already have a reference
// photo; no-reference work stays on the gpt-image-1 text path.
const REF_MODEL_OPTIONS = [
  { key: "nano-banana-pro", label: "Nano Banana Pro", sub: "best identity · ~35 cr", cost: 35 },
  { key: "nano-banana",     label: "Nano Banana",     sub: "fast & cheap · ~10 cr",  cost: 10 },
  { key: "gpt-image-2",     label: "GPT Image 2",     sub: "OpenAI edits · ~50 cr",  cost: 50 },
];
const genModelKey = ref("nano-banana-pro");
const genStyle = ref("photorealistic");
const genAspectRatio = ref("9:16");
const genQuality = ref("high");
const genState = ref("idle");                    // 'idle' | 'loading' | 'done' | 'error'
const genError = ref("");
const genResult = ref(null);                     // last result {asset_id, storage_url, ...}
const genSetAsReference = ref(false);
const genGenerationId = ref(null);
const genPollTimer = ref(null);
const genElapsedSec = ref(0);
const genElapsedTimer = ref(null);

const genModelLabel = computed(
  () => REF_MODEL_OPTIONS.find((o) => o.key === genModelKey.value)?.label ?? "Nano Banana Pro",
);

function openGenerate(character) {
  genTarget.value = character;
  genModelKey.value = "nano-banana-pro";
  genStyle.value = "photorealistic";
  genAspectRatio.value = "9:16";
  genQuality.value = character.reference_asset ? "high" : "medium";
  genError.value = "";
  genResult.value = null;
  genElapsedSec.value = 0;
  stopGenPolling();

  // If there's already a queued/processing generation for this character,
  // jump straight into the loading state and resume polling it instead of
  // showing the empty prompt form — the user is here to check on it.
  if (character.pending_generation) {
    const p = character.pending_generation;
    genGenerationId.value = p.id;
    genPrompt.value = p.prompt || "";
    genState.value = "loading";
    genSetAsReference.value = false; // we don't know what they originally picked; keep neutral
    // Estimate elapsed from server timestamps so the counter doesn't reset to 0.
    const startedIso = p.started_at || p.created_at;
    if (startedIso) {
      genElapsedSec.value = Math.max(0, Math.floor((Date.now() - new Date(startedIso).getTime()) / 1000));
    }
    if (genElapsedTimer.value) clearInterval(genElapsedTimer.value);
    genElapsedTimer.value = setInterval(() => { genElapsedSec.value += 1; }, 1000);
    genPollTimer.value = setTimeout(pollGenStatus, 1000);
  } else {
    // Fresh prompt entry.
    genPrompt.value = "";
    genState.value = "idle";
    genGenerationId.value = null;
    genSetAsReference.value = !character.reference_asset;
  }
}

// Card-level click: if a generation is in flight on this character, treat the
// click as "show me what's happening" → open the generate modal in loading
// state. Otherwise fall through to the existing edit modal (the prior behaviour).
function onCardClick(character) {
  if (character.pending_generation) {
    openGenerate(character);
    return;
  }
  openEdit(character);
}

function stopGenPolling() {
  if (genPollTimer.value) {
    clearTimeout(genPollTimer.value);
    genPollTimer.value = null;
  }
  if (genElapsedTimer.value) {
    clearInterval(genElapsedTimer.value);
    genElapsedTimer.value = null;
  }
}

function closeGenerate() {
  // Closing during 'loading' is fine — the worker keeps running, the page-level
  // poll will surface the pending badge on the card, and the user can re-open
  // the modal from there. We just have to stop the in-modal poll/elapsed
  // timers so they don't leak.
  stopGenPolling();
  genTarget.value = null;
}

async function pollGenStatus() {
  if (!genGenerationId.value) return;
  try {
    const res = await api.get(`/character-image-generations/${genGenerationId.value}`);
    const g = res.data?.data?.generation;
    if (!g) return;
    if (g.status === "succeeded") {
      // Backend adds `image` onto the generation payload only on success.
      genResult.value = g.image ?? null;
      genState.value = "done";
      stopGenPolling();
      await loadCharacters(); // refresh in case reference was promoted
      refreshPendingPoll();   // clears any lingering background poll for this character
    } else if (g.status === "failed") {
      genState.value = "error";
      genError.value = g.error_message || "Generation failed.";
      stopGenPolling();
      await loadCharacters();
      refreshPendingPoll();
    } else {
      // queued | processing — keep polling
      genPollTimer.value = setTimeout(pollGenStatus, 2000);
    }
  } catch (e) {
    // Transient network error — keep trying for a bit, but don't spam.
    genPollTimer.value = setTimeout(pollGenStatus, 4000);
  }
}

async function submitGenerate() {
  if (!genTarget.value) return;
  if (!genPrompt.value.trim()) {
    genError.value = "Describe the scene you want the character in.";
    return;
  }
  genState.value = "loading";
  genError.value = "";
  genResult.value = null;
  genElapsedSec.value = 0;
  if (genElapsedTimer.value) clearInterval(genElapsedTimer.value);
  genElapsedTimer.value = setInterval(() => { genElapsedSec.value += 1; }, 1000);

  try {
    const res = await api.post(`/characters/${genTarget.value.id}/generate-image`, {
      prompt: genPrompt.value.trim(),
      style: genStyle.value,
      aspect_ratio: genAspectRatio.value,
      quality: genQuality.value,
      set_as_reference: genSetAsReference.value,
      // model_key only applies to reference work; no-reference uses gpt-image-1.
      model_key: genTarget.value.reference_asset ? genModelKey.value : null,
    });
    genGenerationId.value = res.data?.data?.generation?.id ?? null;
    if (!genGenerationId.value) {
      throw new Error("No generation id returned.");
    }
    // Kick off the polling loop. First poll after 3s — gpt-image-2 minimum.
    genPollTimer.value = setTimeout(pollGenStatus, 3000);
    // Refresh characters once so the new pending_generation is attached to
    // the card, then ensure the page-level background poll is running. This
    // way the user can close the modal or navigate to another page and the
    // pending badge will still appear / update on return.
    loadCharacters().then(refreshPendingPoll);
  } catch (e) {
    genState.value = "error";
    genError.value = e?.response?.data?.error?.message ?? "Could not start generation.";
    stopGenPolling();
  }
}

const genCostEstimate = computed(() => {
  // Frontend-only estimate — backend is authoritative.
  if (!genTarget.value) return 0;
  if (!genTarget.value.reference_asset) return 15; // gpt-image-1 text path (AI_MEDIUM)
  return REF_MODEL_OPTIONS.find((o) => o.key === genModelKey.value)?.cost ?? 35;
});

onMounted(async () => {
  try {
    const me = await api.get("/me");
    mePayload.value = me.data?.data?.user ?? null;
  } catch {}
  await loadCharacters();
  loading.value = false;
  refreshPendingPoll();
});

// ── Page-level pending-generations poll ──────────────────────────────
// As long as ANY character has a queued/processing image, refresh the
// character list every 4s in the background so the "Generating…" badge
// disappears (and the new reference photo appears) without the user
// having to refresh the page manually. Stops itself the moment no
// pending generations remain.
const pendingPollTimer = ref(null);

function refreshPendingPoll() {
  const anyPending = characters.value.some((c) => !!c.pending_generation);
  if (pendingPollTimer.value) {
    clearTimeout(pendingPollTimer.value);
    pendingPollTimer.value = null;
  }
  if (!anyPending) return;
  pendingPollTimer.value = setTimeout(async () => {
    await loadCharacters();
    refreshPendingPoll();
  }, 4000);
}

onBeforeUnmount(() => {
  if (pendingPollTimer.value) clearTimeout(pendingPollTimer.value);
  stopGenPolling();
});

async function loadCharacters() {
  try {
    const response = await api.get("/characters");
    characters.value = response.data?.data?.characters ?? [];
  } catch (e) {
    error.value = "Could not load characters.";
  }
}

const withImage = computed(() => characters.value.filter((c) => c.reference_asset).length);

function logout() {
  authStore.clearSession();
  router.push({ name: "login" });
}

function thumbUrl(c) {
  return c?.reference_asset?.thumbnail_url || c?.reference_asset?.storage_url || null;
}

function initial(name) {
  return (name || "?").charAt(0).toUpperCase();
}

function openCreate() {
  editingId.value = null;
  createName.value = "";
  createDescription.value = "";
  createIdentityStrength.value = "balanced";
  clearFile();
  existingThumbUrl.value = null;
  removeExistingImage.value = false;
  existingReferences.value = [];
  clearNewFiles();
  consentChecked.value = false;
  createError.value = "";
  createOpen.value = true;
}

function openEdit(c) {
  editingId.value = c.id;
  createName.value = c.name;
  createDescription.value = c.description || "";
  createIdentityStrength.value = c.identity_strength || "balanced";
  clearFile();
  existingThumbUrl.value = thumbUrl(c);
  removeExistingImage.value = false;
  // Multi-reference: prefer the array, fall back to the single primary.
  existingReferences.value = Array.isArray(c.reference_assets) && c.reference_assets.length
    ? [...c.reference_assets]
    : (c.reference_asset ? [c.reference_asset] : []);
  clearNewFiles();
  // Editing a character that already has a reference → consent was given at
  // create; default it checked so edits aren't blocked.
  consentChecked.value = existingReferences.value.length > 0;
  createError.value = "";
  createOpen.value = true;
}

function pickMoreFiles(event) {
  const files = Array.from(event.target.files || []);
  for (const f of files) {
    if (!f.type.startsWith("image/")) continue;
    if (f.size > 10 * 1024 * 1024) continue;
    createNewFiles.value.push(f);
    createNewFilePreviews.value.push(URL.createObjectURL(f));
  }
  event.target.value = ""; // allow re-picking same file
}

function removeExistingRef(id) {
  existingReferences.value = existingReferences.value.filter((r) => r.id !== id);
}

function removeNewFile(index) {
  if (createNewFilePreviews.value[index]) URL.revokeObjectURL(createNewFilePreviews.value[index]);
  createNewFiles.value.splice(index, 1);
  createNewFilePreviews.value.splice(index, 1);
}

function clearNewFiles() {
  createNewFilePreviews.value.forEach((u) => URL.revokeObjectURL(u));
  createNewFiles.value = [];
  createNewFilePreviews.value = [];
}

function pickFile(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  if (!file.type.startsWith("image/")) {
    createError.value = "Please pick an image file (PNG or JPG).";
    return;
  }
  if (file.size > 10 * 1024 * 1024) {
    createError.value = "Image must be 10MB or less.";
    return;
  }
  createError.value = "";
  createFile.value = file;
  if (createPreviewUrl.value) URL.revokeObjectURL(createPreviewUrl.value);
  createPreviewUrl.value = URL.createObjectURL(file);
}

function clearFile() {
  if (createPreviewUrl.value) URL.revokeObjectURL(createPreviewUrl.value);
  createFile.value = null;
  createPreviewUrl.value = "";
}

// "Remove" on the existing image in edit mode — distinct from clearing a new pick.
function removeExisting() {
  removeExistingImage.value = true;
  existingThumbUrl.value = null;
  clearFile();
}

function closeCreate() {
  createOpen.value = false;
  editingId.value = null;
  createName.value = "";
  createDescription.value = "";
  consentChecked.value = false;
  clearFile();
  existingThumbUrl.value = null;
  removeExistingImage.value = false;
  existingReferences.value = [];
  clearNewFiles();
  createError.value = "";
}

async function saveCharacter() {
  const name = createName.value.trim();
  if (!name) {
    createError.value = "Name is required.";
    return;
  }
  createSaving.value = true;
  createError.value = "";
  try {
    // Decide what to do about the reference image:
    //   • new file picked  → upload, then send the new asset id
    //   • Remove clicked   → send null (clear it)
    //   • else (create)    → null
    //   • else (edit)      → don't touch the field
    const payload = {
      name,
      description: createDescription.value.trim() || null,
      identity_strength: createIdentityStrength.value,
    };

    // Upload any newly-picked images, in order.
    const newlyUploadedIds = [];
    for (const file of createNewFiles.value) {
      const fd = new FormData();
      fd.append("title", name);
      fd.append("asset_type", "image");
      fd.append("asset_file", file);
      const up = await api.post("/assets", fd, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      const id = up.data?.data?.asset?.id;
      if (id) newlyUploadedIds.push(id);
    }

    // Combine kept existing refs + new uploads, preserving order.
    const combined = [
      ...existingReferences.value.map((r) => r.id),
      ...newlyUploadedIds,
    ];

    // Legacy single-file fallback (drop zone) — appended if used.
    if (createFile.value) {
      const fd = new FormData();
      fd.append("title", name);
      fd.append("asset_type", "image");
      fd.append("asset_file", createFile.value);
      const upload = await api.post("/assets", fd, {
        headers: { "Content-Type": "multipart/form-data" },
      });
      const id = upload.data?.data?.asset?.id;
      if (id) combined.push(id);
    }

    if (combined.length) {
      payload.reference_asset_ids = combined;
      payload.reference_asset_id  = combined[0]; // first = primary
    } else if (removeExistingImage.value || !editingId.value) {
      payload.reference_asset_ids = [];
      payload.reference_asset_id  = null;
    }

    // Likeness consent — required whenever this character will carry a
    // reference photo (real face). Backend enforces it too. (combined is the
    // authoritative post-upload list; the checkbox visibility uses the
    // willHaveReference computed, which now covers the legacy single-file path.)
    const hasReference = combined.length > 0
      || (editingId.value && existingReferences.value.length > 0 && !removeExistingImage.value);
    if (hasReference) {
      if (!consentChecked.value) {
        createError.value = "Please confirm you have the rights and consent to use this person's likeness — tick the box below.";
        return;
      }
      payload.consent = true;
    }

    let saved;
    if (editingId.value) {
      const response = await api.patch(`/characters/${editingId.value}`, payload);
      saved = response.data?.data?.character;
      if (saved) {
        characters.value = characters.value.map((c) => (c.id === saved.id ? saved : c));
      }
    } else {
      const response = await api.post("/characters", payload);
      saved = response.data?.data?.character;
      if (saved) characters.value = [saved, ...characters.value];
    }
    closeCreate();
  } catch (e) {
    createError.value = e.response?.data?.error?.message ?? "Could not save character.";
  } finally {
    createSaving.value = false;
  }
}

async function confirmDelete() {
  if (!deleteTarget.value) return;
  deletePending.value = true;
  try {
    await api.delete(`/characters/${deleteTarget.value.id}`);
    characters.value = characters.value.filter((c) => c.id !== deleteTarget.value.id);
  } catch {} finally {
    deletePending.value = false;
    deleteTarget.value = null;
  }
}
</script>

<template>
  <div class="characters-shell">
    <AppSidebar :user="mePayload" active-page="characters" @logout="logout" />

    <main class="main">
      <div class="topbar">
        <div class="topbar-left">
          <span class="bc-ws">My Workspace</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">Characters</span>
        </div>
        <div class="topbar-right">
          <button class="btn btn-primary btn-sm" type="button" @click="openCreate">
            <span style="font-weight:700">＋</span> New Character
          </button>
          <NotifBell />
        </div>
      </div>

      <div class="content">
        <div v-if="error" class="banner error">{{ error }}</div>
        <GridSkeleton v-if="loading" :stats="3" header :count="8" :min="200" ratio="1 / 1" :lines="2" />

        <template v-else>
          <div class="stats-row">
            <article class="stat-card accent-stat">
              <div class="stat-label">Characters</div>
              <div class="stat-value">{{ characters.length }}</div>
              <div class="stat-change">{{ characters.length > 0 ? 'Reusable across scenes' : 'Create your first character' }}</div>
            </article>
            <article class="stat-card">
              <div class="stat-label">With reference image</div>
              <div class="stat-value">{{ withImage }}</div>
              <div class="stat-change">{{ withImage > 0 ? 'Visual reference ready for AI gen' : 'Upload images to anchor identity' }}</div>
            </article>
            <article class="stat-card">
              <div class="stat-label">Consistency method</div>
              <div class="stat-value">Quick</div>
              <div class="stat-change">Description-injected today · LoRA training coming</div>
            </article>
          </div>

          <section class="dash-section">
            <div class="section-hd">
              <div>
                <div class="eyebrow">Workspace library</div>
                <div class="section-title">All Characters</div>
              </div>
              <button class="btn btn-ghost btn-sm" type="button" @click="openCreate">＋ New character</button>
            </div>

            <div v-if="characters.length === 0" class="empty-hero">
              <div class="empty-icon">◐</div>
              <div class="empty-title">No characters yet</div>
              <div class="empty-body">
                Characters live across every project and series in this workspace.<br>
                Upload a reference photo and a short description — pick one when generating any AI image.
              </div>
              <button class="btn btn-primary" type="button" @click="openCreate">＋ Create your first character</button>
            </div>

            <div v-else class="char-grid">
              <article v-for="c in characters" :key="c.id" class="char-card" @click="onCardClick(c)">
                <div class="char-card-thumb">
                  <img v-if="thumbUrl(c)" :src="thumbUrl(c)" alt="" />
                  <span v-else>{{ initial(c.name) }}</span>
                  <!-- Pending-generation overlay: visible if the worker is still
                       running an image for this character, including when the user
                       closed the modal or navigated away. Clicking the card while
                       pending reopens the modal in its Working state. -->
                  <div v-if="c.pending_generation" class="char-card-pending" title="Image generation in progress — click to view">
                    <span class="char-card-pending-spinner"></span>
                    <span>Generating…</span>
                  </div>
                  <div class="char-card-actions">
                    <button class="char-card-act accent" type="button" title="Generate test image" @click.stop="openGenerate(c)">✦</button>
                    <button class="char-card-act" type="button" title="Edit" @click.stop="openEdit(c)">✎</button>
                    <button class="char-card-act danger" type="button" title="Delete" @click.stop="deleteTarget = c">✕</button>
                  </div>
                </div>
                <div class="char-card-body">
                  <div class="char-card-name">{{ c.name }}</div>
                  <div class="char-card-desc">{{ c.description || 'No description' }}</div>
                  <div class="char-card-meta">
                    {{ c.scenes_count > 0 ? `Used in ${c.scenes_count} ${c.scenes_count === 1 ? 'scene' : 'scenes'}` : 'Not used yet' }}
                  </div>
                </div>
              </article>
            </div>
          </section>
        </template>
      </div>
    </main>

    <!-- Create modal -->
    <Teleport to="body">
      <div v-if="createOpen" class="cv-backdrop" @click.self="closeCreate">
        <div class="cv-modal">
          <div class="cv-head">
            <div class="cv-title">{{ editingId ? '✎ Edit character' : '＋ New character' }}</div>
            <button class="cv-close" @click="closeCreate">×</button>
          </div>

          <div class="cv-field">
            <label class="cv-label">Reference image <span class="cv-opt">(optional)</span></label>

            <!-- Priority: newly-picked file preview → existing image → drop zone -->
            <div v-if="createPreviewUrl" class="cv-preview">
              <img :src="createPreviewUrl" alt="" />
              <button type="button" class="cv-preview-clear" @click="clearFile">✕ Remove</button>
            </div>
            <div v-else-if="existingThumbUrl && !removeExistingImage" class="cv-preview">
              <img :src="existingThumbUrl" alt="" />
              <label class="cv-preview-replace">
                <input type="file" accept="image/*" hidden @change="pickFile" />
                ↻ Replace
              </label>
              <button type="button" class="cv-preview-clear" @click="removeExisting">✕ Remove</button>
            </div>
            <label v-else class="cv-drop">
              <input type="file" accept="image/*" hidden @change="pickFile" />
              <div class="cv-drop-ico">⬆</div>
              <div class="cv-drop-copy">Drop or click to upload</div>
              <div class="cv-drop-sub">PNG or JPG · up to 10MB</div>
            </label>
          </div>

          <div class="cv-field">
            <label class="cv-label">Name</label>
            <input v-model="createName" class="cv-input" placeholder="e.g. Marcus the Detective" maxlength="120" />
          </div>

          <div class="cv-field">
            <label class="cv-label">Description <span class="cv-opt">(optional)</span></label>
            <textarea v-model="createDescription" class="cv-input" rows="4" placeholder="A weathered 50-year-old detective in a worn trench coat, sharp eyes…" maxlength="2000"></textarea>
            <div class="cv-hint">Used in scene prompts to keep this character consistent across episodes.</div>
          </div>

          <div class="cv-field">
            <label class="cv-label">More reference photos <span class="cv-opt">(better consistency)</span></label>
            <div class="cv-refs-grid">
              <div v-for="ref in existingReferences" :key="`ex-${ref.id}`" class="cv-ref-tile">
                <img :src="ref.thumbnail_url || ref.storage_url" alt="" />
                <button type="button" class="cv-ref-x" @click="removeExistingRef(ref.id)">✕</button>
                <span v-if="existingReferences[0]?.id === ref.id" class="cv-ref-primary">PRIMARY</span>
              </div>
              <div v-for="(url, i) in createNewFilePreviews" :key="`new-${i}`" class="cv-ref-tile">
                <img :src="url" alt="" />
                <button type="button" class="cv-ref-x" @click="removeNewFile(i)">✕</button>
                <span class="cv-ref-new">NEW</span>
              </div>
              <label class="cv-ref-add">
                <input type="file" accept="image/*" multiple hidden @change="pickMoreFiles" />
                <span class="cv-ref-plus">＋</span>
              </label>
            </div>
            <div class="cv-hint">First image is the primary reference used today. Extra photos improve future LoRA training. Max 8.</div>
          </div>

          <div v-if="willHaveReference" class="cv-field">
            <label class="cv-consent">
              <input type="checkbox" v-model="consentChecked" />
              <span>I confirm I have the rights and consent to use this person's likeness, and that I won't use it to create misleading, deceptive, or explicit content.</span>
            </label>
          </div>

          <div class="cv-field">
            <label class="cv-label">Identity strength</label>
            <div class="cv-strength-row">
              <button
                v-for="opt in IDENTITY_STRENGTH_OPTIONS"
                :key="opt.key"
                type="button"
                :class="['cv-strength', createIdentityStrength === opt.key ? 'active' : '']"
                @click="createIdentityStrength = opt.key"
              >
                <span class="cv-strength-label">{{ opt.label }}</span>
                <span class="cv-strength-sub">{{ opt.sub }}</span>
              </button>
            </div>
            <div class="cv-hint">Higher = generated faces stay closer to the reference photo. Locked can look plasticky on small references.</div>
          </div>

          <div v-if="createError" class="cv-error">{{ createError }}</div>

          <div class="cv-foot">
            <button class="btn btn-ghost btn-sm" type="button" @click="closeCreate">Cancel</button>
            <button class="btn btn-primary btn-sm" type="button" :disabled="createSaving" @click="saveCharacter">
              {{ createSaving ? (createFile ? 'Uploading…' : 'Saving…') : (editingId ? 'Save changes' : 'Create character') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Delete confirmation -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="cv-backdrop" @click.self="deleteTarget = null">
        <div class="cv-modal" style="max-width:420px">
          <div class="cv-head">
            <div class="cv-title">Delete character</div>
            <button class="cv-close" @click="deleteTarget = null">×</button>
          </div>
          <div style="padding:6px 0 14px;font-size:13px;opacity:.8;">
            Delete <strong>{{ deleteTarget.name }}</strong>?
            Scenes already using this character will keep their generated images, but new scenes won't be able to pick it.
          </div>
          <div class="cv-foot">
            <button class="btn btn-ghost btn-sm" type="button" @click="deleteTarget = null">Cancel</button>
            <button class="btn btn-primary btn-sm" type="button" :disabled="deletePending" @click="confirmDelete">
              {{ deletePending ? 'Deleting…' : 'Delete' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Generate test image -->
    <Teleport to="body">
      <div v-if="genTarget" class="cv-backdrop" @click.self="closeGenerate">
        <div class="cv-modal" style="max-width:640px">
          <div class="cv-head">
            <div class="cv-title">✦ Generate image — {{ genTarget.name }}</div>
            <button class="cv-close" @click="closeGenerate">×</button>
          </div>

          <div class="gen-mode-banner">
            <span v-if="genTarget.reference_asset">
              <strong>Reference photo on file.</strong> The result will be generated to match this character's face and identity using {{ genModelLabel }}.
            </span>
            <span v-else>
              <strong>No reference photo yet.</strong> The first image will be generated from scratch (text-only). Tick "Set as reference" and future generations of this character will preserve the same identity.
            </span>
          </div>

          <div v-if="genTarget.reference_asset" class="cv-field">
            <label class="cv-label">Image model</label>
            <select v-model="genModelKey" class="cv-input" :disabled="genState === 'loading'">
              <option v-for="m in REF_MODEL_OPTIONS" :key="m.key" :value="m.key">
                {{ m.label }} — {{ m.sub }}
              </option>
            </select>
          </div>

          <div class="cv-field">
            <label class="cv-label">What scene do you want them in?</label>
            <textarea
              v-model="genPrompt"
              class="cv-input"
              rows="3"
              placeholder="e.g. confident founder holding a supplement bottle in a bright modern kitchen, mid-morning natural light"
              :disabled="genState === 'loading'"
            ></textarea>
          </div>

          <div class="gen-controls-grid">
            <div class="cv-field">
              <label class="cv-label">Style</label>
              <select v-model="genStyle" class="cv-input" :disabled="genState === 'loading'">
                <option value="photorealistic">Photorealistic</option>
                <option value="cinematic">Cinematic</option>
                <option value="documentary">Documentary</option>
                <option value="anime">Anime</option>
                <option value="3d_animated">3D animated</option>
                <option value="comic">Comic</option>
                <option value="watercolor">Watercolor</option>
                <option value="dark_fantasy">Dark fantasy</option>
                <option value="cyberpunk_80s">Cyberpunk 80s</option>
              </select>
            </div>
            <div class="cv-field">
              <label class="cv-label">Aspect</label>
              <select v-model="genAspectRatio" class="cv-input" :disabled="genState === 'loading'">
                <option value="9:16">9:16 portrait</option>
                <option value="1:1">1:1 square</option>
                <option value="16:9">16:9 landscape</option>
              </select>
            </div>
            <div class="cv-field">
              <label class="cv-label">Quality</label>
              <select v-model="genQuality" class="cv-input" :disabled="genState === 'loading'">
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </select>
            </div>
          </div>

          <label class="gen-promote-row">
            <input type="checkbox" v-model="genSetAsReference" :disabled="genState === 'loading'" />
            <span>
              Make this the primary reference photo
              {{ genTarget.reference_asset ? '(replaces current primary)' : '(unlocks identity preservation on next generation)' }}
              <span class="gen-promote-hint">Generated images always join this character's reference list — the checkbox just controls which one is primary.</span>
            </span>
          </label>

          <div v-if="genState === 'loading'" class="gen-progress">
            <div class="gen-progress-spinner"></div>
            <div class="gen-progress-text">
              <div><strong>Generating…</strong> Hold on — the model takes ~30–60s.</div>
              <div class="gen-progress-elapsed">{{ genElapsedSec }}s elapsed</div>
            </div>
          </div>

          <div v-if="genResult" class="gen-result">
            <img :src="genResult.storage_url" :alt="`${genTarget.name} preview`" />
            <div class="gen-result-meta">
              <span>{{ genResult.with_reference ? `${genModelLabel} (reference)` : 'gpt-image-1 (text-only)' }}</span>
              <span v-if="genResult.set_as_reference">· Set as new reference ✓</span>
            </div>
          </div>

          <div v-if="genError" class="banner error" style="margin-top:12px">{{ genError }}</div>

          <div class="cv-foot">
            <span class="gen-cost">~{{ genCostEstimate }} credits</span>
            <button class="btn btn-ghost btn-sm" type="button" @click="closeGenerate">
              <template v-if="genState === 'loading'">Close — keep running in background</template>
              <template v-else-if="genState === 'done'">Close</template>
              <template v-else>Cancel</template>
            </button>
            <button
              class="btn btn-primary btn-sm"
              type="button"
              :disabled="genState === 'loading' || !genPrompt.trim()"
              @click="submitGenerate"
            >
              {{ genState === 'loading' ? 'Generating…' : (genState === 'done' ? 'Generate another' : 'Generate ✦') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.characters-shell {
  display: flex;
  min-height: 100vh;
  background: #0a0a0f;
  color: #ececf3;
  font-family: 'DM Sans', sans-serif;
}
.main {
  margin-left: var(--sidebar-width, 220px);
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  min-width: 0;
}
.topbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 28px;
  border-bottom: 1px solid rgba(255,255,255,0.06);
  background: rgba(10,10,15,0.6); backdrop-filter: blur(8px);
}
.topbar-left { display: flex; gap: 8px; font-size: 13px; }
.bc-ws { color: rgba(255,255,255,0.5); }
.bc-sep { color: rgba(255,255,255,0.25); }
.bc-page { color: #ececf3; font-weight: 600; }
.topbar-right { display: flex; gap: 12px; align-items: center; }
.content { padding: 28px; overflow-y: auto; }
.banner.error { background: rgba(255,80,80,0.12); border: 1px solid rgba(255,80,80,0.3); color: #ffaaaa; padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; }
.page-state { padding: 40px; text-align: center; opacity: 0.55; }

.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 14px; border-radius: 8px;
  font-family: inherit; font-size: 13px; cursor: pointer;
  border: 1px solid rgba(255,255,255,0.12);
  background: rgba(255,255,255,0.04); color: #ececf3;
  transition: 0.15s;
}
.btn:hover { background: rgba(255,255,255,0.08); }
.btn-sm { padding: 6px 12px; font-size: 12.5px; }
.btn-primary { background: #ff6b35; border-color: #ff6b35; color: #0a0a0f; font-weight: 600; }
.btn-primary:hover { background: #ff8055; border-color: #ff8055; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border-color: transparent; }
.btn-ghost:hover { background: rgba(255,255,255,0.06); }

.stats-row {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 14px; margin-bottom: 28px;
}
.stat-card {
  padding: 16px 18px;
  background: #14141c;
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 12px;
}
.stat-card.accent-stat {
  background: linear-gradient(135deg, rgba(255,107,53,0.12), rgba(255,107,53,0.04));
  border-color: rgba(255,107,53,0.35);
}
.stat-label { font-size: 11px; letter-spacing: 0.04em; text-transform: uppercase; opacity: 0.55; }
.stat-value { font-size: 26px; font-weight: 700; margin: 6px 0 4px; }
.stat-change { font-size: 11.5px; opacity: 0.6; }

.dash-section { background: #14141c; border: 1px solid rgba(255,255,255,0.06); border-radius: 14px; padding: 22px; }
.section-hd { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 18px; }
.eyebrow { font-size: 10.5px; letter-spacing: 0.1em; text-transform: uppercase; opacity: 0.5; font-weight: 600; }
.section-title { font-size: 18px; font-weight: 700; margin-top: 4px; }

.empty-hero { text-align: center; padding: 60px 20px; }
.empty-icon { font-size: 40px; opacity: 0.5; }
.empty-title { font-size: 18px; font-weight: 700; margin: 14px 0 8px; }
.empty-body { font-size: 13px; opacity: 0.65; line-height: 1.6; margin-bottom: 20px; }

.char-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
.char-card {
  background: #16161c; border: 1px solid rgba(255,255,255,0.07);
  border-radius: 12px; overflow: hidden; transition: 0.15s;
}
.char-card:hover { border-color: rgba(255,255,255,0.18); transform: translateY(-1px); }
.char-card-thumb {
  aspect-ratio: 1 / 1; position: relative;
  background: linear-gradient(135deg, #ff6b35 0%, #cf4f1d 100%);
  display: flex; align-items: center; justify-content: center;
  font-size: 36px; font-weight: 700; color: #0a0a0f; overflow: hidden;
}
.char-card-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.char-card { cursor: pointer; }
.char-card-actions {
  position: absolute; top: 8px; right: 8px;
  display: flex; gap: 6px; opacity: 0; transition: 0.15s;
}
.char-card:hover .char-card-actions { opacity: 1; }
.char-card-act {
  width: 26px; height: 26px; border-radius: 50%;
  background: rgba(0,0,0,0.55); color: #fff;
  border: none; cursor: pointer; font-size: 12px;
  font-family: inherit; transition: 0.15s;
  display: flex; align-items: center; justify-content: center;
}
.char-card-act:hover { background: rgba(0,0,0,0.8); }
.char-card-act.danger:hover { background: rgba(220,40,40,0.85); }
.char-card-act.accent { background: rgba(255,107,53,0.85); }
.char-card-act.accent:hover { background: rgba(255,107,53,1); }

/* ── "Generating…" pending overlay on the card thumb ─────────────── */
.char-card-pending {
  position: absolute; left: 8px; bottom: 8px;
  display: flex; align-items: center; gap: 8px;
  padding: 5px 11px 5px 7px; border-radius: 999px;
  background: rgba(0,0,0,0.7); backdrop-filter: blur(6px);
  border: 1px solid rgba(255,107,53,0.45);
  font-size: 11px; font-weight: 600; color: #fff;
  font-family: "Space Mono", monospace; letter-spacing: 0.05em;
  pointer-events: none;
  animation: char-pending-pulse 2.4s ease-in-out infinite;
}
.char-card-pending-spinner {
  width: 12px; height: 12px; border-radius: 50%;
  border: 2px solid rgba(255,107,53,0.25);
  border-top-color: var(--color-accent);
  animation: gen-spin 0.8s linear infinite;
}
@keyframes char-pending-pulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(255,107,53,0); }
  50% { box-shadow: 0 0 0 6px rgba(255,107,53,0.06); }
}

/* ── Generate-image modal ───────────────────────────────────────────── */
.gen-mode-banner {
  padding: 10px 14px; border-radius: 8px; margin: 6px 0 14px;
  background: rgba(255,107,53,0.06); border: 1px solid rgba(255,107,53,0.18);
  font-size: 12px; color: var(--color-text-secondary); line-height: 1.55;
}
.gen-mode-banner strong { color: var(--color-accent); }
.gen-controls-grid { display: grid; grid-template-columns: 1.2fr 1fr 1fr; gap: 10px; }
.gen-promote-row { display: flex; gap: 8px; align-items: flex-start; padding: 10px 0; font-size: 12px; color: var(--color-text-secondary); cursor: pointer; line-height: 1.55; }
.gen-promote-row input { margin-top: 3px; flex-shrink: 0; }
.gen-promote-hint { display: block; font-size: 11px; color: var(--color-text-muted); margin-top: 3px; }
.gen-result { margin-top: 14px; border-radius: 10px; overflow: hidden; border: 1px solid var(--color-border); background: var(--color-bg-elevated); }
.gen-result img { display: block; width: 100%; max-height: 420px; object-fit: contain; background: #000; }
.gen-result-meta { padding: 8px 12px; font-size: 11px; color: var(--color-text-muted); font-family: "Space Mono", monospace; letter-spacing: 0.05em; display: flex; gap: 10px; }
.gen-cost { font-size: 11px; color: var(--color-text-muted); margin-right: auto; font-family: "Space Mono", monospace; letter-spacing: 0.05em; }
.gen-progress { display: flex; align-items: center; gap: 14px; padding: 14px; margin-top: 12px; border-radius: 10px; background: rgba(255,107,53,0.05); border: 1px solid rgba(255,107,53,0.2); }
.gen-progress-spinner {
  width: 24px; height: 24px; border-radius: 50%;
  border: 2.5px solid rgba(255,107,53,0.25);
  border-top-color: var(--color-accent);
  animation: gen-spin 0.8s linear infinite; flex-shrink: 0;
}
@keyframes gen-spin { to { transform: rotate(360deg); } }
.gen-progress-text { font-size: 12.5px; color: var(--color-text-secondary); line-height: 1.5; }
.gen-progress-text strong { color: var(--color-text-primary); }
.gen-progress-elapsed { font-family: "Space Mono", monospace; font-size: 10px; color: var(--color-text-muted); letter-spacing: 0.05em; margin-top: 2px; }
@media (max-width: 600px) {
  .gen-controls-grid { grid-template-columns: 1fr; }
}

.cv-preview-replace {
  position: absolute; top: 8px; right: 90px;
  background: rgba(0,0,0,0.6); color: #fff;
  border: 1px solid rgba(255,255,255,0.15);
  padding: 5px 10px; border-radius: 6px;
  font-size: 11.5px; cursor: pointer; font-family: inherit;
}
.cv-preview-replace:hover { background: rgba(0,0,0,0.8); }
.char-card-body { padding: 12px 14px; }
.char-card-name { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
.char-card-desc { font-size: 11.5px; opacity: 0.55; line-height: 1.45;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
  overflow: hidden;
}
.char-card-meta {
  font-size: 10.5px; letter-spacing: 0.04em; opacity: 0.45;
  margin-top: 8px; padding-top: 8px;
  border-top: 1px solid rgba(255,255,255,0.05);
}

/* ── Create modal ── */
.cv-backdrop {
  position: fixed; inset: 0; z-index: 1000;
  background: rgba(0,0,0,0.55); backdrop-filter: blur(4px);
  display: flex; align-items: center; justify-content: center;
  padding: 20px;
}
.cv-modal {
  width: 100%; max-width: 480px;
  background: #14141c;
  border: 1px solid rgba(255,255,255,0.12);
  border-radius: 14px;
  padding: 22px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  max-height: 90vh;
  overflow-y: auto;
  /* Keep cv-foot inside this scroll container so position: sticky pins
     the action row to the bottom — submit never slides off the viewport
     after a reference photo + extras stack up. */
}
.cv-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.cv-title { font-size: 16px; font-weight: 700; }
.cv-close { background: none; border: none; color: rgba(255,255,255,0.45); font-size: 22px; cursor: pointer; line-height: 1; }
.cv-close:hover { color: #fff; }
.cv-field { margin-bottom: 16px; }
.cv-label { display: block; font-size: 12px; font-weight: 600; letter-spacing: 0.04em; opacity: 0.7; margin-bottom: 8px; }
.cv-opt { opacity: 0.5; font-weight: 400; }
.cv-input {
  width: 100%; background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.1); border-radius: 8px;
  padding: 10px 12px; color: #ececf3; font-family: inherit; font-size: 13px;
  outline: none; resize: vertical;
}
.cv-input:focus { border-color: #ff6b35; }
.cv-hint { font-size: 11px; opacity: 0.55; margin-top: 6px; }
.cv-consent { display: flex; gap: 10px; align-items: flex-start; font-size: 12px; line-height: 1.45; cursor: pointer; padding: 10px 12px; border: 1px solid var(--color-border, #2a2a35); border-radius: 8px; }
.cv-consent input { margin-top: 2px; flex-shrink: 0; }
.cv-error { font-size: 12.5px; color: #ff6b6b; margin: 8px 0 12px; }
.cv-foot {
  display: flex; justify-content: flex-end; gap: 8px; align-items: center;
  margin-top: 16px;
  position: sticky; bottom: -22px; /* offset by modal's padding so it sits flush */
  background: #14141c; padding: 12px 0 0;
  border-top: 1px solid rgba(255,255,255,0.06);
  z-index: 2;
}

.cv-drop {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  border: 1.5px dashed rgba(255,255,255,0.16); border-radius: 10px;
  padding: 28px 12px; text-align: center; cursor: pointer;
  background: rgba(255,255,255,0.02); transition: 0.15s;
}
.cv-drop:hover { border-color: rgba(255,107,53,0.45); background: rgba(255,107,53,0.05); }
.cv-drop-ico { font-size: 24px; opacity: 0.55; }
.cv-drop-copy { font-size: 13.5px; font-weight: 500; margin-top: 6px; }
.cv-drop-sub { font-size: 11px; opacity: 0.5; margin-top: 2px; }
/* Cap preview height so a tall reference image can't push the rest of the
   form (name, description, identity strength, footer) off-screen. */
.cv-preview { position: relative; width: 100%; max-width: 280px; aspect-ratio: 1 / 1; border-radius: 10px; overflow: hidden; background: #0a0a0f; margin: 0 auto; }
.cv-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cv-preview-clear {
  position: absolute; top: 8px; right: 8px;
  background: rgba(0,0,0,0.6); color: #fff;
  border: 1px solid rgba(255,255,255,0.15);
  padding: 5px 10px; border-radius: 6px;
  font-size: 11.5px; cursor: pointer; font-family: inherit;
}
.cv-preview-clear:hover { background: rgba(0,0,0,0.8); }

/* Identity strength picker */
.cv-strength-row { display: flex; gap: 6px; }
.cv-strength {
  flex: 1;
  background: rgba(255,255,255,0.03);
  border: 1px solid rgba(255,255,255,0.1);
  border-radius: 8px;
  padding: 9px 6px 8px;
  display: flex; flex-direction: column; align-items: center; gap: 2px;
  cursor: pointer; font-family: inherit; transition: 0.15s;
}
.cv-strength:hover { border-color: rgba(255,255,255,0.2); }
.cv-strength.active {
  background: rgba(255,107,53,0.14);
  border-color: rgba(255,107,53,0.55);
}
.cv-strength-label { font-size: 12px; font-weight: 600; color: #ececf3; }
.cv-strength.active .cv-strength-label { color: #ff8055; }
.cv-strength-sub { font-size: 10px; opacity: 0.5; }

/* Multi-reference grid */
.cv-refs-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.cv-ref-tile { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; background: #0a0a0f; border: 1px solid rgba(255,255,255,0.1); }
.cv-ref-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cv-ref-x {
  position: absolute; top: 4px; right: 4px;
  width: 20px; height: 20px; border-radius: 50%;
  background: rgba(0,0,0,0.65); color: #fff;
  border: none; cursor: pointer; font-size: 11px;
  display: flex; align-items: center; justify-content: center;
  font-family: inherit;
}
.cv-ref-x:hover { background: rgba(220,40,40,0.85); }
.cv-ref-primary, .cv-ref-new {
  position: absolute; bottom: 4px; left: 4px;
  background: #ff6b35; color: #0a0a0f;
  font-size: 8.5px; font-weight: 700; letter-spacing: 0.04em;
  padding: 2px 5px; border-radius: 3px;
}
.cv-ref-new { background: rgba(255,255,255,0.15); color: #fff; }
.cv-ref-add {
  aspect-ratio: 1;
  border: 1.5px dashed rgba(255,255,255,0.18);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  color: rgba(255,255,255,0.4); cursor: pointer;
  background: rgba(255,255,255,0.02);
  transition: 0.15s;
}
.cv-ref-add:hover { border-color: #ff6b35; color: #ff8055; background: rgba(255,107,53,0.05); }
.cv-ref-plus { font-size: 22px; }

@media (max-width: 768px) {
  .main { margin-left: 0; }
}
</style>
