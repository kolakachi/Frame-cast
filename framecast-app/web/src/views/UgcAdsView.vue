<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import UiSelect from "../components/UiSelect.vue";
import MediaPickerModal from "../components/MediaPickerModal.vue";
import api from "../services/api";
import { useAuthStore } from "../stores/auth";
import { apiErrorMessage } from "../composables/apiError";

const router = useRouter();
const route = useRoute();
const authStore = useAuthStore();

// ── Wizard ──────────────────────────────────────────────────────────────
// Three screens, per the wyvstudio-ugc-html mockups: say what you're making,
// review the plan as passages, then approve the cost and generate. Script,
// shots and cast are no longer separate stops — the script rides the brief
// (as a tab) and cast sits beside the plan it presents.
const STEPS = [
  { key: "brief", label: "Brief", hint: "What are we making" },
  { key: "plan", label: "Plan", hint: "Passages, cast and voice" },
  { key: "approve", label: "Approve & generate", hint: "Cost and consent" },
];
const step = ref(0);
const selectedPassage = ref(0);

const MAX_SCRIPT = 1500;
const MAX_CHARACTERS = 5;

const script = ref("");
const product = ref("");
const context = ref("");
// How the brief starts: their own words, an exact script, or a product link
// we read for them. The tab only decides which field leads — all three feed
// the same plan.
const briefMode = ref("idea");
const linkUrl = ref("");
const readingLink = ref(false);
const linkFound = ref(null); // { product, context, must_include, source_url }
const mustInclude = ref("");
const avoid = ref("");
const format = ref("auto");
const duration = ref(30);
const language = ref("en");
const footageLabels = ref("");
// Keyed by character id. A run-wide voice made three presenters sound like
// one person dubbed over three faces, which is the opposite of why you cast
// more than one.
const voiceByCharacter = ref({});
const voices = ref([]);
const voicePreviewUrl = ref({}); // character id -> sample url
const suggesting = ref(null); // which brief field is being written
const loadingVoice = ref(null); // character id currently loading a sample
const reviewed = ref(false);
// Two separate confirmations, per the mockup: rights to the presenter's
// likeness and voice, and accuracy of the product facts. One combined
// checkbox let people attest to something they had not read.
const consentLikeness = ref(false);
const consentFacts = ref(false);
const quoting = ref(false);
const quotedFingerprint = ref("");
const footageShot = ref(null);
// ── Starting point ──────────────────────────────────────────────────────
// From the mockup's first step. The choice decides what we are allowed to do
// with the source: an ad someone else made gives us its shape and nothing
// else, while footage they own can be used outright.
const START_POINTS = [
  { key: "scratch", label: "From scratch", hint: "Describe the product and we plan the ad" },
  { key: "found", label: "From an ad I like", hint: "We copy the structure only — never its footage or words" },
  { key: "owned", label: "From my own footage", hint: "Your clips, arranged into an ad" },
];
// Entering at /from-my-footage means they arrived with material in hand, so
// that is where they start. The picker stays — arriving by the wrong door
// should be a change of mind, not a dead end.
const startPoint = ref(route.meta?.ugcStart === "owned" ? "owned" : "scratch");
const ownedEntry = computed(() => route.meta?.ugcStart === "owned");
const pageName = computed(() => (ownedEntry.value ? "From My Footage" : "UGC Ads"));

// The reference we read a shape off, and what we understood from it.
const referencePicker = ref(false);
const referenceAsset = ref(null);
const referenceShape = ref(null);   // { shape, beats: [...] }
const readingReference = ref(false);
const referenceError = ref("");

// Their own clips, offered to the director by id.
const footagePicker = ref(false);
const footageAssets = ref([]);      // [{ id, title, thumbnail_url }]

async function readReference() {
  if (!referenceAsset.value || readingReference.value) return;
  readingReference.value = true;
  referenceError.value = "";
  try {
    const res = await api.post("/ugc/reference", { asset_id: referenceAsset.value.id });
    referenceShape.value = res.data?.data?.reference ?? null;
  } catch (e) {
    referenceError.value =
      e?.response?.data?.errors?.reference?.[0] ||
      e?.response?.data?.error?.message ||
      "Could not read that video. Try another, or plan from a brief.";
    referenceShape.value = null;
  } finally {
    readingReference.value = false;
  }
}

function selectReference({ item }) {
  if (item?.id && item._type === "asset") {
    referenceAsset.value = { id: item.id, title: item.title, thumbnail_url: item.thumbnail_url || item.storage_url };
    referenceShape.value = null;
    readReference();
  }
  referencePicker.value = false;
}

function selectFootageAsset({ item }) {
  if (item?.id && item._type === "asset" && !footageAssets.value.some((a) => a.id === item.id)) {
    footageAssets.value.push({
      id: item.id,
      title: item.title || "Untitled",
      thumbnail_url: item.thumbnail_url || item.storage_url,
    });
  }
  footagePicker.value = false;
}
function removeFootage(id) {
  footageAssets.value = footageAssets.value.filter((a) => a.id !== id);
}

// ── Shots the script moved out from under ───────────────────────────────
const staleCount = computed(
  () => (plan.value?.segments ?? []).filter((s) => s.stale).length
);
const redirecting = ref(false);
async function redirectStale() {
  if (!plan.value || redirecting.value) return;
  redirecting.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post("/ugc/reanchor", {
      format: plan.value.format,
      segments: plan.value.segments,
    });
    plan.value = { ...plan.value, ...data.data };
    clearVariants();
    reviewed.value = false;
  } catch (e) {
    errorMessage.value =
      e?.response?.data?.errors?.segments?.[0] ||
      e?.response?.data?.error?.message ||
      "Could not re-direct those shots.";
  } finally {
    redirecting.value = false;
  }
}

// ── Alternative openings ────────────────────────────────────────────────
const variants = ref([]);        // [{ label, segments, script, credits_per_character }]
const chosenVariants = ref([]);  // labels the user wants to run alongside
const variantCount = ref(3);
const writingVariants = ref(false);

async function writeVariants() {
  if (!plan.value || writingVariants.value) return;
  writingVariants.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post("/ugc/variants", {
      format: plan.value.format,
      segments: plan.value.segments,
      count: Number(variantCount.value),
      product: product.value,
      context: context.value,
    });
    variants.value = data.data.variants ?? [];
    chosenVariants.value = [];
  } catch (e) {
    errorMessage.value =
      e?.response?.data?.errors?.variants?.[0] ||
      e?.response?.data?.errors?.segments?.[0] ||
      e?.response?.data?.error?.message ||
      "Could not write alternative openings.";
  } finally {
    writingVariants.value = false;
  }
}

// Openings carry a full copy of the body they were written for. Any change
// to that body must take them with it, or generation submits the new base
// alongside stale copies of the old one.
function clearVariants() {
  variants.value = [];
  chosenVariants.value = [];
}

function toggleVariant(label) {
  chosenVariants.value = chosenVariants.value.includes(label)
    ? chosenVariants.value.filter((l) => l !== label)
    : [...chosenVariants.value, label];
  // A different number of takes is a different price and a different run.
  reviewed.value = false;
}

const selectedVariants = computed(() =>
  variants.value.filter((v) => chosenVariants.value.includes(v.label))
);

const productPicker = ref(false);
const productAsset = ref(null);   // first photo — composed path + back-compat
// Several angles teach the model the product's geometry; up to 5.
const productAssets = ref([]);    // [{ id, thumbnail_url, title }]
const formatOptions = [
  ["auto", "Let the director choose"],
  ["direct_camera", "Continuous talking take"],
  ["demo", "Product / app demonstration"],
  ["story", "Story with deliberate cuts"],
  ["reaction", "Silent reaction + headline (5 or 10 seconds)"],
  // Cheapest by an order of magnitude: stills, a camera move and captions,
  // with no video model running at all.
  ["text_led", "Text-led cards — no presenter, no video model"],
];
const selected = ref([]); // chosen characters
// Model-first: the user picks the engine, and that decides whether a real
// character is available. Veo carries a real cast face into any creative
// scene (google/veo-3.1, face in reference_images, 32 cr/s); Seedance
// invents a fitting presenter with no character (castless, 18 cr/s).
const castEngine = ref("seedance"); // 'veo' | 'seedance'
const aspectRatio = ref("9:16");
const plan = ref(null); // { segments, reasoning, credits_per_character }
// A still-only plan has no presenter, so the cast step neither gates nor
// multiplies anything.
const noCast = computed(() => plan.value?.format === "text_led");
watch(() => plan.value?.segments, () => { selectedPassage.value = 0; });
const planning = ref(false);
const generating = ref(false);
const takes = ref([]);
const errorMessage = ref("");
const balance = ref(null);
let takesTimer = null;
let disposed = false;
async function loadTakes() {
  clearTimeout(takesTimer);
  try {
    const { data } = await api.get("/ugc/takes");
    if (!disposed) takes.value = data?.data?.takes ?? [];
  } catch {
    /* Keep the existing cards; the editor exposes detailed job errors. */
  }
  if (!disposed && takes.value.some((t) => t.status === "generating"))
    takesTimer = setTimeout(loadTakes, 10000);
}
onBeforeUnmount(() => {
  disposed = true;
  clearTimeout(takesTimer);
});

// ── character picker ─────────────────────────────────────────────────
const pickerOpen = ref(false);
const characters = ref([]);
const charsLoading = ref(false);
const filters = ref({
  source: "stock",
  gender: "",
  age_group: "",
  situation: "",
  q: "",
});

const SITUATIONS = [
  "beauty",
  "car",
  "coffee shop",
  "fashion",
  "fitness",
  "grooming",
  "gym",
  "kitchen",
  "living room",
  "luxury",
  "office",
  "outdoors",
  "restaurant",
  "salon",
  "travel",
  "bathroom",
];
// No child bracket. Stock actors exist to be animated into endorsing a
// product, and children presenting advertising is restricted by the CAP
// code, the FTC and most EU regimes, as well as by Meta's and TikTok's ad
// policies — so a stock child would mostly produce ads that get refused.
// A customer who needs their own child on camera still can: they create a
// character from their own photo, under the likeness attestation they
// already give.
const AGES = [
  ["young_adult", "Young adult"],
  ["adult", "Adult"],
  ["senior", "Senior"],
];

const scriptLength = computed(() => script.value.length);
const onCameraCount = computed(
  () =>
    (plan.value?.segments ?? []).filter((s) => s.kind === "on_camera").length
);
const perCharacter = computed(() => plan.value?.credits_per_character ?? 0);
// ── One-full-video lane ──────────────────────────────────────────────────
// Spoken plans up to 30s generate as ONE fluid video on Seedance 2.5 —
// no scenes, the presenter speaks natively. Mirrors the server's quote:
// max(4, ceil(total seconds)) × the engine rate.
// Engine follows the cast: a photoreal character reference is declined by
// Seedance's moderation, so character-cast takes generate on Veo (12 cr/s,
// identity via start frame); castless takes use Seedance (18 cr/s).
const ONESHOT_RATES = { seedance25: 18, veo: 12, veo_hq: 32 };
const planSeconds = computed(() =>
  (plan.value?.segments ?? []).reduce((t, x) => t + Math.max(1, Number(x.seconds || 0)), 0)
);
const oneShotEligible = computed(
  () =>
    !!plan.value &&
    plan.value.format !== "text_led" &&
    planSeconds.value <= 30 &&
    (plan.value.segments ?? []).some((x) => (x.script_text || "").trim() !== "")
);
const oneShotSeconds = computed(() => Math.max(4, Math.ceil(planSeconds.value)));
const oneShotRate = computed(() =>
  castEngine.value === "veo" && selected.value.length ? ONESHOT_RATES.veo_hq : ONESHOT_RATES.seedance25
);
const oneShotCredits = computed(() => oneShotSeconds.value * oneShotRate.value);
// The run is the base plan plus every ticked opening, each a full take per
// presenter. Base x characters alone showed a number smaller than the charge
// — the same shape of bug a customer was refunded for on music.
const variantCreditsPerCast = computed(() =>
  selectedVariants.value.reduce((sum, v) => sum + (v.credits_per_character ?? 0), 0)
);
const castCount = computed(() => (noCast.value || castEngine.value === "seedance" ? 1 : selected.value.length));
const totalCredits = computed(
  () => (perCharacter.value + variantCreditsPerCast.value) * castCount.value
);
// One take per opening per cast member — the arithmetic the server caps at ten.
const takeCount = computed(
  () => (1 + selectedVariants.value.length) * castCount.value
);
const planFingerprint = computed(() =>
  JSON.stringify([plan.value?.format, plan.value?.segments])
);
const quoteCurrent = computed(
  () => !!plan.value && quotedFingerprint.value === planFingerprint.value
);
const missingFootage = computed(() =>
  (plan.value?.segments ?? []).some(
    (s) => s.kind === "b_roll" && s.source !== "generate" && !s.asset_id
  )
);
// What each step needs before the next one means anything. A step you cannot
// complete is still reachable backwards — this gates forward motion, it does
// not lock you out of your own brief.
const stepReady = computed(() => [
  Boolean(product.value.trim() || context.value.trim() || script.value.trim()),
  Boolean(plan.value?.segments?.length) && (noCast.value || castEngine.value === "seedance" || selected.value.length > 0),
  canGenerate.value,
]);
const furthestStep = computed(() => {
  let i = 0;
  while (i < STEPS.length - 1 && stepReady.value[i]) i += 1;
  return i;
});
function canGoStep(i) {
  return i <= furthestStep.value;
}
function goStep(i) {
  if (!canGoStep(i)) return;
  step.value = i;
  // The rail is above the fold on a phone; the fields are not.
  nextTick(() => document.querySelector(".ugc-step-body")?.scrollIntoView({ block: "start", behavior: "smooth" }));
}
function prevStep() { goStep(Math.max(step.value - 1, 0)); }

const canGenerate = computed(
  () =>
    quoteCurrent.value &&
    (noCast.value || castEngine.value === "seedance" || selected.value.length > 0) &&
    reviewed.value &&
    consentLikeness.value &&
    consentFacts.value &&
    !missingFootage.value &&
    !generating.value &&
    !quoting.value &&
    !planning.value
);

let inputRevision = 0;
watch(
  [script, product, context, mustInclude, avoid, format, duration, language, footageLabels],
  () => {
    inputRevision++;
    plan.value = null;
    reviewed.value = false;
    clearVariants();
  },
  { flush: "sync" }
);
// Where the material comes from is part of what the plan means. Before this,
// switching starting point or swapping the reference kept the old plan on
// screen — and a reference once read stayed in the payload even after
// returning to "From scratch".
watch(
  [startPoint, referenceShape, footageAssets],
  () => {
    plan.value = null;
    reviewed.value = false;
    clearVariants();
  },
  { deep: true, flush: "sync" }
);
watch(
  // "I reviewed the plan and the estimate" attests to the plan and its
  // price. Invalidate on what moves either: plan content, who is cast
  // (membership, not object internals), exact-vs-variant, aspect. A voice
  // pick changes the sound, not the estimate — it must not silently clear
  // the tick (that was the "box sometimes unticks" bug).
  [planFingerprint, () => selected.value.map((c) => c.id).join(","), castEngine, aspectRatio],
  () => {
    reviewed.value = false;
  },
  { flush: "sync" }
);
watch(
  format,
  (value) => {
    if (value === "reaction" && ![5, 10].includes(duration.value))
      duration.value = 5;
  },
  { flush: "sync" }
);

async function loadBalance() {
  try {
    const { data } = await api.get("/me");
    balance.value = data?.data?.credits?.balance ?? null;
  } catch {
    // A missing balance is cosmetic — the server rejects an unaffordable run.
  }
}

async function loadCharacters() {
  charsLoading.value = true;
  try {
    const params = { include_stock: 1 };
    if (filters.value.gender) params.gender = filters.value.gender;
    if (filters.value.age_group) params.age_group = filters.value.age_group;
    if (filters.value.situation) params.situation = filters.value.situation;
    if (filters.value.q) params.q = filters.value.q;
    const { data } = await api.get("/characters", { params });
    let rows = data?.data?.characters ?? [];
    if (filters.value.source === "stock") rows = rows.filter((c) => c.is_stock);
    if (filters.value.source === "mine") rows = rows.filter((c) => !c.is_stock);
    characters.value = rows;
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not load characters.");
  } finally {
    charsLoading.value = false;
  }
}

function openPicker() {
  pickerOpen.value = true;
  loadCharacters();
}

function toggleCharacter(c) {
  const i = selected.value.findIndex((x) => x.id === c.id);
  if (i >= 0) selected.value.splice(i, 1);
  // A one-take ad has a single presenter and only the first selection ever
  // generates — stacking a second silently ignored it (the "didn't respect
  // my avatar" bug). Picking another character now replaces the cast.
  else if (oneShotEligible.value) selected.value = [c];
  else if (selected.value.length < MAX_CHARACTERS) selected.value.push(c);
  reviewed.value = false;
}

const isSelected = (c) => selected.value.some((x) => x.id === c.id);


function setFilter(key, value) {
  filters.value[key] = filters.value[key] === value ? "" : value;
  loadCharacters();
}

// Read a product page into the brief. What we found is shown back and
// editable — never silently assumed correct.
async function readLink() {
  if (!linkUrl.value.trim() || readingLink.value) return;
  readingLink.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post("/ugc/read-link", { url: linkUrl.value.trim() });
    const found = data?.data ?? null;
    linkFound.value = found;
    if (found?.product) product.value = found.product;
    if (found?.context) context.value = found.context;
    if (found?.must_include) mustInclude.value = found.must_include;
  } catch (err) {
    linkFound.value = null;
    errorMessage.value = apiErrorMessage(err, "Could not read that page. Paste the product details instead.");
  } finally {
    readingLink.value = false;
  }
}

// "See the proposed plan →": plan from the brief, then move only if a plan
// actually arrived — a failed plan keeps you on the brief with the error.
async function advanceFromBrief() {
  if (!stepReady.value[0] || planning.value) return;
  if (!plan.value) await makePlan();
  if (plan.value) goStep(1);
}

// "Approve plan → see cost": revalidate the (possibly edited) plan so the
// cost screen always prices what is actually on the table.
async function advanceFromPlan() {
  if (!stepReady.value[1] || quoting.value) return;
  if (!quoteCurrent.value) await reprice();
  if (quoteCurrent.value) goStep(2);
}

// ── planning + generation ────────────────────────────────────────────
async function makePlan() {
  if (!script.value.trim() && !context.value.trim()) return;
  const revision = inputRevision;
  planning.value = true;
  errorMessage.value = "";
  reviewed.value = false;
  try {
    const { data } = await api.post("/ugc/plan", {
      script: script.value,
      product: product.value,
      context:
        context.value +
        (mustInclude.value.trim() ? `\nMust include: ${mustInclude.value.trim()}` : "") +
        (avoid.value.trim() ? `\nAvoid: ${avoid.value.trim()}` : ""),
      format: format.value,
      duration_seconds: Number(duration.value),
      language: language.value,
      available_footage: footageLabels.value
        .split("\n")
        .map((s) => s.trim())
        .filter(Boolean),
      // The shape read off a reference, and the clips they own. Either may be
      // absent; the director plans from the brief alone when both are.
      ...(startPoint.value === "found" && referenceShape.value
        ? { reference: referenceShape.value }
        : {}),
      ...(startPoint.value !== "scratch" && footageAssets.value.length
        ? { footage_asset_ids: footageAssets.value.map((a) => a.id) }
        : {}),
    });
    if (revision !== inputRevision) return;
    plan.value = data?.data ?? null;
    clearVariants();
    quotedFingerprint.value = planFingerprint.value;
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not plan the shots.");
  } finally {
    planning.value = false;
  }
}

async function reprice() {
  const fingerprint = planFingerprint.value;
  quoting.value = true;
  errorMessage.value = "";
  try {
    const { data } = await api.post("/ugc/quote", {
      format: plan.value.format,
      segments: plan.value.segments,
    });
    if (fingerprint !== planFingerprint.value) return;
    plan.value = { ...plan.value, ...data.data };
    clearVariants();
    quotedFingerprint.value = planFingerprint.value;
  } catch (err) {
    errorMessage.value = apiErrorMessage(
      err,
      "Could not validate the edited plan."
    );
  } finally {
    quoting.value = false;
  }
}

function selectFootage({ item }) {
  const seg = plan.value?.segments[footageShot.value];
  if (seg && item?.id && item._type === "asset") seg.asset_id = item.id;
  footageShot.value = null;
}

function selectProduct({ item }) {
  if (item?.asset_type === "video" || (item?.mime_type || "").startsWith("video/")) {
    errorMessage.value =
      "Product references are photos — a clip can't ride a one-take generation. Pick stills of the product (front, back, held in hand).";
    productPicker.value = false;
    return;
  }
  if (item?.id && item._type === "asset" && !productAssets.value.some((a) => a.id === item.id)) {
    const entry = { id: item.id, thumbnail_url: item.thumbnail_url || item.storage_url, title: item.title };
    productAssets.value = [...productAssets.value, entry].slice(0, 5);
    productAsset.value = productAssets.value[0];
    // The actor image is rebuilt with the product in it, so a plan approved
    // before this no longer matches what will be generated.
    reviewed.value = false;
  }
  productPicker.value = false;
}

function removeProduct(id) {
  productAssets.value = productAssets.value.filter((a) => a.id !== id);
  productAsset.value = productAssets.value[0] ?? null;
  reviewed.value = false;
}

async function previewVoice(character) {
  const key = voiceByCharacter.value[character.id];
  const profile = voices.value.find((v) => v.provider_voice_key === key);
  if (!profile) return;
  loadingVoice.value = character.id;
  try {
    const { data } = await api.post("/voice-profiles/preview", {
      voice_profile_id: profile.id,
    });
    // Guard against a slow response landing after the choice moved on.
    if (voiceByCharacter.value[character.id] === key) {
      voicePreviewUrl.value = {
        ...voicePreviewUrl.value,
        [character.id]: data.data.preview_url,
      };
    }
  } catch (err) {
    errorMessage.value = apiErrorMessage(
      err,
      "Could not load that voice sample."
    );
  } finally {
    loadingVoice.value = null;
  }
}

/**
 * Fill one of the two brief fields. Everything downstream is planned for the
 * user, so this is the only blank page left — and it is where people stall.
 * Writes into the field rather than showing a suggestion to copy, because the
 * point is to leave them with something to edit, not another decision.
 */
async function suggest(field, seg = null, key = null) {
  // A per-shot suggestion is keyed by field AND shot, so two shots can be
  // filled independently without both spinners lighting up.
  const token = seg ? `${field}:${plan.value.segments.indexOf(seg)}` : field;
  suggesting.value = token;
  errorMessage.value = "";
  try {
    const { data } = await api.post("/ugc/suggest", {
      field,
      product: product.value,
      context: context.value,
      available_footage: footageLabels.value,
      format: plan.value?.format ?? format.value,
      // Describe the shot so the answer fits it rather than the ad at large.
      shot: seg
        ? `${seg.kind}, ${seg.seconds}s` +
          (seg.script_text ? `, says: ${seg.script_text}` : ", silent") +
          (seg.visual_brief ? `, shows: ${seg.visual_brief}` : "")
        : "",
    });
    const text = data?.data?.suggestion ?? "";
    if (!text) return;
    if (seg) seg[key] = text;
    else if (field === "product") product.value = text;
    else if (field === "context") context.value = text;
    else if (field === "available_footage") footageLabels.value = text;
    else if (field === "script") script.value = text;
  } catch (err) {
    errorMessage.value = apiErrorMessage(
      err,
      "Could not think of one just now — try again."
    );
  } finally {
    suggesting.value = null;
  }
}

// One reusable label row so every suggestible field looks the same.
const suggestToken = (field, seg) =>
  seg ? `${field}:${plan.value.segments.indexOf(seg)}` : field;

function setVoice(characterId, key) {
  voiceByCharacter.value = { ...voiceByCharacter.value, [characterId]: key };
  const { [characterId]: _dropped, ...rest } = voicePreviewUrl.value;
  voicePreviewUrl.value = rest;
}

let submission = null;
function requestKey(payload) {
  const fingerprint = JSON.stringify(payload);
  if (!submission || submission.fingerprint !== fingerprint) submission = { fingerprint, id: crypto.randomUUID() };
  return submission.id;
}
async function generate() {
  if (!canGenerate.value) return;
  generating.value = true;
  errorMessage.value = "";
  try {
    // The one-video lane: spoken plans become a single fluid generation.
    if (oneShotEligible.value) {
      // Veo carries the chosen character; Seedance is castless and takes the
      // director's written presenter instead.
      const presenter = castEngine.value === "veo" ? selected.value[0] : null;
      const oneShotPayload = {
        format: plan.value.format,
        segments: plan.value.segments,
        script: plan.value.script,
        presenter_description: presenter
          ? [presenter.name, presenter.description].filter(Boolean).join(" — ")
          : (plan.value.presenter || ""),
        character_id: presenter?.id ?? null,
        engine: castEngine.value === "veo" ? "veo" : "seedance25",
        product_asset_id: productAsset.value?.id ?? null,
        product_asset_ids: productAssets.value.map((a) => a.id),
        product: product.value,
        tone: context.value.slice(0, 200),
        language: language.value,
        consent: true,
        reviewed: true,
        credits: oneShotCredits.value,
      };
      const { data } = await api.post("/ugc/generate-one-shot", {
        ...oneShotPayload,
        request_id: requestKey(oneShotPayload),
      });
      reviewed.value = false;
      loadTakes();
      await loadBalance();
      const runId = data?.data?.run_id;
      if (runId) router.push({ name: "ugc-run", params: { runId } });
      return;
    }
    const payload = {
      script: plan.value.script,
      format: plan.value.format,
      // A still-only run casts nobody; sending an empty list would fail the
      // conditional requirement rather than express it.
      ...(noCast.value && !selected.value.length
        ? {}
        : { character_ids: selected.value.map((c) => c.id) }),
      segments: plan.value.segments,
      // The chosen openings run alongside the reviewed plan, one take each
      // per character. The server caps the product of the two.
      ...(selectedVariants.value.length
        ? { variants: selectedVariants.value.map((v) => ({ label: v.label, segments: v.segments })) }
        : {}),
      aspect_ratio: aspectRatio.value,
      language: language.value,
      voices: voiceByCharacter.value,
      product_asset_id: productAsset.value?.id ?? null,
      consent: consentLikeness.value && consentFacts.value,
      reviewed: reviewed.value,
      credits_per_character: perCharacter.value,
    };
    const { data } = await api.post("/ugc/generate", { ...payload, request_id: requestKey(payload) });
    takes.value = data?.data?.takes ?? [];
    reviewed.value = false;
    loadTakes();
    await loadBalance();
    // The run has its own screen: per-scene progress, retries, and the door
    // to review — this page's job ended when the run started.
    const runId = data?.data?.run_id;
    if (runId) router.push({ name: "ugc-run", params: { runId } });
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not start the run.");
  } finally {
    generating.value = false;
  }
}

// A take that is still rendering has scenes without visuals, voice or
// lip-sync, so the editor shows a half-built project and invites edits that
// the running jobs will overwrite. Send it to the progress view instead —
// the same rule VideosView applies to any generating project.
function openTake(take) {
  router.push(take.status === 'ready_for_review'
    ? { name: 'ugc-review', params: { projectId: take.id } }
    : take.run_id ? { name: 'ugc-run', params: { runId: take.run_id } }
    : { name: 'project-editor', params: { projectId: take.id } });
}

onMounted(() => {
  if (!authStore.user?.is_internal) {
    router.replace({ name: "dashboard" });
    return;
  }
  loadBalance();
  loadTakes();
  api
    .get("/voice-profiles")
    .then(({ data }) => {
      voices.value = (data?.data?.voice_profiles ?? []).filter(
        (v) => v.provider === "google" && !v.is_cloned
      );
    })
    .catch(() => {});
});
</script>

<template>
  <div class="ugc-shell">
    <AppSidebar
      :user="authStore.user"
      :active-page="ownedEntry ? 'from-my-footage' : 'ugc-ads'"
      @logout="authStore.logout()"
    />

    <main class="ugc-main">
      <header class="ugc-top">
        <div class="ugc-crumb"><b>{{ pageName }}</b></div>
        <span class="ugc-beta">BETA · INTERNAL</span>
        <div v-if="balance !== null" class="ugc-credits">
          {{ balance.toLocaleString() }} credits
        </div>
      </header>

      <div v-if="errorMessage" class="ugc-error">{{ errorMessage }}</div>

      <div :class="['ugc-body', `ugc-at-${step}`]">
        <!-- build column -->
        <section class="ugc-build">
        <!-- Step rail. Horizontal and scrollable on a phone, where it is the
             only thing telling you how far through you are. -->
        <nav class="ugc-steps" aria-label="Setup steps">
          <button
            v-for="(st, i) in STEPS"
            :key="st.key"
            type="button"
            :class="['ugc-step', i === step ? 'is-current' : '', i < furthestStep && i !== step ? 'is-done' : '', !canGoStep(i) ? 'is-locked' : '']"
            :aria-current="i === step ? 'step' : undefined"
            :disabled="!canGoStep(i)"
            @click="goStep(i)"
          >
            <span class="ugc-step-n">{{ i < furthestStep && i !== step ? '✓' : String(i + 1).padStart(2, '0') }}</span>
            <span class="ugc-step-t"><b>{{ st.label }}</b></span>
          </button>
        </nav>

          <div v-show="step === 0" class="ugc-step-body ugc-brief-layout">
          <div class="ugc-brief-main">
          <div class="ugc-card ugc-fields">
            <h2 class="ugc-card-t">What are we making?</h2>
            <p class="ugc-hint" style="margin:0 0 10px">Start however is easiest. We'll ask for anything else we need once we see it.</p>
            <div class="ugc-seg-group ugc-brief-tabs">
              <button type="button" :class="['ugc-seg-btn', briefMode === 'idea' ? 'on' : '']" @click="briefMode = 'idea'">Describe an idea</button>
              <button type="button" :class="['ugc-seg-btn', briefMode === 'script' ? 'on' : '']" @click="briefMode = 'script'">Paste an exact script</button>
              <button type="button" :class="['ugc-seg-btn', briefMode === 'link' ? 'on' : '']" @click="briefMode = 'link'">Start from a product link</button>
            </div>

            <template v-if="briefMode === 'idea'">
              <label>
                <span class="ugc-label-row">
                  Tell us what you want, in your own words
                  <button class="ugc-suggest" type="button" :disabled="suggesting === 'context'" @click="suggest('context')">
                    {{ suggesting === "context" ? "thinking…" : "✨ Write with AI" }}
                  </button>
                </span>
                <textarea
                  v-model="context"
                  maxlength="1500"
                  placeholder="A real customer explaining why she switched — warm, a little funny, no medical claims."
                />
              </label>
              <p class="ugc-hint">We'll propose a script and shot plan from this. Nothing is generated yet.</p>
            </template>

            <template v-else-if="briefMode === 'script'">
              <label>
                <span class="ugc-label-row">
                  Paste your script
                  <span class="ugc-card-c">{{ scriptLength }} / {{ MAX_SCRIPT }}</span>
                </span>
                <textarea
                  v-model="script"
                  class="ugc-script"
                  :maxlength="MAX_SCRIPT"
                  placeholder="Hi, I'm Mia. Three months ago I was averaging four hours of sleep a night..."
                ></textarea>
              </label>
              <p class="ugc-hint">Exact wording is preserved — every word, in order, unchanged.</p>
            </template>

            <template v-else>
              <label>Product page or store link
                <input v-model="linkUrl" type="url" maxlength="2000" placeholder="https://fernwell.com/products/sleep-plus" />
              </label>
              <div class="ugc-row">
                <button class="ugc-btn" type="button" :disabled="!linkUrl.trim() || readingLink" @click="readLink">
                  {{ readingLink ? 'Reading the page…' : 'Read the page' }}
                </button>
              </div>
              <p v-if="!linkFound" class="ugc-hint">We'll read the page and show you what we found before assuming any of it is right.</p>
              <div v-else class="ugc-shape">
                <div class="ugc-shape-h">Here's what we read — correct anything that's off</div>
                <p class="ugc-hint" style="margin:6px 0 0">The product and idea fields below were filled from the page and stay yours to edit.</p>
              </div>
            </template>
          </div>

<div class="ugc-card ugc-fields">
            <h2 class="ugc-card-t">{{ ownedEntry ? 'Your material' : 'Starting point' }}</h2>
            <p class="ugc-hint" style="margin:0 0 10px">
              {{ ownedEntry
                ? 'Add the clips you already have. Point at an ad you like and we arrange yours in its shape — nothing is generated, and your real product beats anything we would invent of it.'
                : 'This decides what we are allowed to do with the source.' }}
            </p>
            <div class="ugc-starts">
              <button
                v-for="sp in START_POINTS"
                :key="sp.key"
                type="button"
                :class="['ugc-start', startPoint === sp.key ? 'is-on' : '']"
                @click="startPoint = sp.key"
              >
                <b>{{ sp.label }}</b>
                <span>{{ sp.hint }}</span>
              </button>
            </div>

            <!-- An ad they admire. We read its shape; its footage and words stay theirs. -->
            <template v-if="startPoint === 'found'">
              <label class="ugc-label" style="margin-top:14px">Reference ad</label>
              <div class="ugc-row">
                <img v-if="referenceAsset?.thumbnail_url" :src="referenceAsset.thumbnail_url" alt="" class="ugc-product-thumb" />
                <button class="ugc-btn" type="button" @click="referencePicker = true">
                  {{ referenceAsset ? 'Choose another' : 'Choose a video' }}
                </button>
                <span v-if="readingReference" class="ugc-hint">Reading its structure…</span>
              </div>
              <p v-if="referenceError" class="ugc-error-inline">{{ referenceError }}</p>

              <!-- What we understood, so they can see it before it shapes the plan. -->
              <div v-if="referenceShape" class="ugc-shape">
                <div class="ugc-shape-h">{{ referenceShape.shape || 'Structure read' }}</div>
                <ol class="ugc-beats">
                  <li v-for="(b, i) in referenceShape.beats" :key="i">
                    <b>{{ b.role }}</b>
                    <span v-if="b.end > b.start" class="ugc-beat-secs">{{ Math.round(b.end - b.start) }}s</span>
                    <span>{{ b.does }}</span>
                  </li>
                </ol>
                <p class="ugc-hint">We use this shape only. None of its wording, footage or claims are reused.</p>
              </div>
            </template>

            <!-- Footage they own. Assigned to shots by the director. -->
            <template v-if="startPoint !== 'scratch'">
              <label class="ugc-label" style="margin-top:14px">
                Your footage <span class="ugc-opt">({{ startPoint === 'owned' ? 'the ad is built from these' : 'optional' }})</span>
              </label>
              <div class="ugc-footage">
                <div v-for="a in footageAssets" :key="a.id" class="ugc-footage-item">
                  <img v-if="a.thumbnail_url" :src="a.thumbnail_url" alt="" />
                  <span>{{ a.title }}</span>
                  <button type="button" aria-label="Remove" @click="removeFootage(a.id)">×</button>
                </div>
                <button class="ugc-btn" type="button" @click="footagePicker = true">+ Add a clip</button>
              </div>
              <p class="ugc-hint">
                Your own product beats anything we would generate of it, and using it costs nothing.
              </p>
            </template>
          </div>

          <details class="ugc-card ugc-fields ugc-optional">
            <summary class="ugc-card-t">More direction <span class="ugc-hint">· optional</span></summary>
            <label>
              <span class="ugc-label-row">
                Product / app
                <button class="ugc-suggest" type="button" :disabled="suggesting === 'product'" @click="suggest('product')">
                  {{ suggesting === "product" ? "thinking…" : "✨ Write with AI" }}
                </button>
              </span>
              <input v-model="product" maxlength="200" placeholder="What are we showing?" />
            </label>
            <label v-if="briefMode !== 'idea'">
              <span class="ugc-label-row">
                Audience, idea and desired reaction
                <button class="ugc-suggest" type="button" :disabled="suggesting === 'context'" @click="suggest('context')">
                  {{ suggesting === "context" ? "thinking…" : "✨ Write with AI" }}
                </button>
              </span>
              <textarea v-model="context" maxlength="1500" placeholder="Who is this for, and what should they feel?" />
            </label>
            <div class="ugc-two">
              <label>Must include
                <input v-model="mustInclude" maxlength="200" placeholder="14-day money-back guarantee" />
              </label>
              <label>Avoid
                <input v-model="avoid" maxlength="200" placeholder="Medical or clinical claims" />
              </label>
            </div>
            <label
              >Format<UiSelect
                v-model="format"
                :options="formatOptions.map(([value, label]) => ({ value, label }))"
                aria-label="Format"
                drop="down"
            /></label>
            <label>
              <span class="ugc-label-row">
                Available footage (one description per line)
                <button class="ugc-suggest" type="button" :disabled="suggesting === 'available_footage'" @click="suggest('available_footage')">
                  {{ suggesting === "available_footage" ? "thinking…" : "✨ Write with AI" }}
                </button>
              </span>
              <textarea v-model="footageLabels" :placeholder="'My app screen recording\nProduct close-up'" />
            </label>
            <label>
              <span class="ugc-label-row">Product photo (optional)</span>
              <span class="ugc-product">
                <span v-for="a in productAssets" :key="a.id" class="ugc-product-item">
                  <img :src="a.thumbnail_url" alt="" class="ugc-product-thumb" />
                  <button type="button" aria-label="Remove" @click="removeProduct(a.id)">×</button>
                </span>
                <button v-if="productAssets.length < 5" class="ugc-btn" type="button" @click="productPicker = true">
                  {{ productAssets.length ? '+ Add angle' : 'Choose a photo' }}
                </button>
              </span>
              <span v-if="productAssets.length" class="ugc-hint">
                {{ productAssets.length }}/5 — front, back and a held-in-hand shot teach the model its shape and size.
              </span>
              <span class="ugc-hint">Composited onto the actor so they hold or wear your actual product.</span>
            </label>
          </details>

          <div class="ugc-card ugc-fields">
            <h2 class="ugc-card-t">Delivery</h2>
            <div class="ugc-delivery-grid">
            <label>Target length
              <span class="ugc-seg-group">
                <button
                  v-for="secs in (format === 'reaction' ? [5, 10] : [15, 30, 60])"
                  :key="secs"
                  type="button"
                  :class="['ugc-seg-btn', duration === secs ? 'on' : '']"
                  @click="duration = secs"
                >{{ secs }}s</button>
              </span>
            </label>
            <label>Aspect ratio
              <span class="ugc-seg-group">
                <button
                  v-for="r in ['9:16', '1:1', '16:9']"
                  :key="r"
                  :class="['ugc-seg-btn', aspectRatio === r ? 'on' : '']"
                  type="button"
                  @click="aspectRatio = r"
                >{{ r }}</button>
              </span>
            </label>
            <label>Language<input v-model="language" maxlength="12" placeholder="en" title="Language code for the video, for example en, fr or es." /></label>
            </div>
            <details v-if="format !== 'reaction'" class="ugc-custom-length">
              <summary>Custom length · {{ duration }} seconds</summary>
              <label>Custom seconds
                <input v-model.number="duration" type="number" min="5" :max="format === 'direct_camera' ? 60 : 180" />
              </label>
              <span class="ugc-hint">Approximate — not a hard cutoff yet.</span>
            </details>
          </div>

          </div>
          <aside class="ugc-brief-side" aria-label="Live brief">
            <div class="ugc-card ugc-live-brief">
              <h2><span class="ugc-live-dot" aria-hidden="true"></span>Live brief</h2>
              <p>A <strong>{{ duration }}s</strong>, <strong>{{ aspectRatio }}</strong> UGC ad
                built <strong>{{ startPoint === 'found' ? 'from a reference ad’s structure' : startPoint === 'owned' ? 'from your own footage' : 'from scratch' }}</strong>,
                {{ briefMode === 'script' ? 'using your exact script' : briefMode === 'link' ? 'starting from a product link' : 'from a described idea' }}.</p>
              <p class="ugc-hint">Nothing is generated yet — this only plans the video.</p>
              <dl>
                <div><dt>Language</dt><dd>{{ language || 'Not set' }}</dd></div>
                <div><dt>Credit estimate</dt><dd>After planning</dd></div>
                <div><dt>Next steps</dt><dd>Plan → Approve</dd></div>
              </dl>
            </div>
            <button class="ugc-btn ugc-btn-primary ugc-plan-cta" type="button" :disabled="!stepReady[0] || planning" @click="advanceFromBrief">
              {{ planning ? 'Planning…' : 'See the proposed plan →' }}
            </button>
            <p class="ugc-hint ugc-plan-note">Review the plan and production cost before you approve generation.</p>
          </aside>
          </div>

          <div v-show="step === 1" class="ugc-step-body">
          <div v-if="plan" class="ugc-plan-head">
            <h2 class="ugc-card-t">Here's the plan</h2>
            <div class="ugc-plan-pills">
              <span class="ugc-kind on">{{ plan.format }}</span>
              <span class="ugc-kind roll">~{{ (plan.segments ?? []).reduce((t, x) => t + Number(x.seconds || 0), 0) }}s</span>
              <span class="ugc-kind roll">{{ aspectRatio }}</span>
              <span v-if="oneShotEligible" class="ugc-kind on">One fluid take — beats become dialogue, cuts land between them</span>
            </div>
            <p v-if="oneShotEligible" class="ugc-hint" style="margin:6px 0 0">
              This generates as a single video: the presenter speaks these lines in one take.
              Each passage below is a beat of that take, not a separate scene.
            </p>
          </div>
          <div class="ugc-plan-layout">
          <aside class="ugc-plan-list">
            <h3>Passages</h3>
            <p class="ugc-hint">Select a passage to review its words, visuals and delivery.</p>
            <button v-for="(seg, i) in plan?.segments || []" :key="i" type="button"
              :class="['ugc-passage', { on: selectedPassage === i }]" :aria-pressed="selectedPassage === i" @click="selectedPassage = i">
              <span><b>{{ String(i + 1).padStart(2, '0') }} · {{ seg.kind === 'on_camera' ? 'On camera' : seg.kind === 'reaction' ? 'Reaction' : 'Cut-away' }}</b><small>{{ seg.seconds }}s</small></span>
              <span class="ugc-passage-copy">{{ seg.script_text || seg.headline || seg.visual_brief || 'Add direction' }}</span>
              <small v-if="seg.stale" class="ugc-seg-stale">Needs re-directing</small>
            </button>
          <!-- Cast &amp; voice belongs beside the plan it presents. -->
          <div v-if="!noCast" class="ugc-card">
            <div class="ugc-card-h">
              <span class="ugc-card-t">Cast &amp; voice</span>
              <span class="ugc-card-c">Pick how the presenter is made</span>
            </div>

            <!-- Model first: the engine decides whether a real character is
                 available to cast. -->
            <div class="ugc-cast-style">
              <label :class="['ugc-style-pill', { on: castEngine === 'veo' }]">
                <input v-model="castEngine" type="radio" value="veo" />
                <b>Use one of your characters</b>
                <span>Their real face, dropped into any scene the ad needs — Google's best renderer · 32 cr/s</span>
              </label>
              <label :class="['ugc-style-pill', { on: castEngine === 'seedance' }]">
                <input v-model="castEngine" type="radio" value="seedance" />
                <b>Let us cast a presenter</b>
                <span>We create a fitting presenter for the ad — no character needed · 18 cr/s</span>
              </label>
            </div>

            <div v-for="c in selected" :key="c.id" v-show="castEngine === 'veo'" class="ugc-ch">
              <div class="ugc-ch-av">
                <img v-if="c.reference_asset?.thumbnail_url" :src="c.reference_asset.thumbnail_url" alt="" />
                <span v-else>☺</span>
              </div>
              <div class="ugc-ch-m">
                <div class="ugc-ch-n">{{ c.name }}</div>
                <div class="ugc-ch-s">{{ (c.situations || []).join(" · ") || c.age_group || "—" }}</div>
                <div v-if="plan?.format !== 'reaction'" class="ugc-ch-voice">
                  <UiSelect
                    :model-value="voiceByCharacter[c.id] || ''"
                    :options="[{ value: '', label: 'Voice — automatic' }].concat(voices.map((v) => ({ value: v.provider_voice_key, label: v.name })))"
                    :aria-label="`Voice for ${c.name}`"
                    drop="down"
                    @update:model-value="(val) => setVoice(c.id, val)"
                  />
                  <button
                    v-if="voiceByCharacter[c.id]"
                    class="ugc-btn ugc-btn-sm"
                    type="button"
                    :disabled="loadingVoice === c.id"
                    @click="previewVoice(c)"
                  >
                    {{ loadingVoice === c.id ? "…" : "▶ Hear sample" }}
                  </button>
                  <audio v-if="voicePreviewUrl[c.id]" class="ugc-audio" :src="voicePreviewUrl[c.id]" controls />
                </div>
              </div>
              <button class="ugc-ch-x" type="button" @click="toggleCharacter(c)">✕</button>
            </div>
            <div v-if="castEngine === 'veo'" class="ugc-card-f">
              <button class="ugc-btn" @click="openPicker">{{ selected.length ? "＋ Swap character" : "＋ Pick a character" }}</button>
              <span v-if="!selected.length" class="ugc-hint">Choose a character to front this ad — their face carries into the scene.</span>
            </div>
          </div>
          <p v-else-if="plan" class="ugc-hint">
            No presenter in this format — the words on screen carry the ad, so there is nobody to cast.
          </p>

          <!-- Seedance castless: the director's presenter choice, stated and
               editable, instead of an invisible default. -->
          <div v-if="plan && !noCast && castEngine === 'seedance' && plan.presenter !== undefined" class="ugc-card ugc-fields">
            <label>
              <span class="ugc-label-row">Who fronts this ad <span class="ugc-opt">(the director's pick — edit freely)</span></span>
              <textarea v-model="plan.presenter" maxlength="300" rows="2"></textarea>
            </label>
          </div>

          </aside>
          <div class="ugc-plan-detail">
          <!-- the plan, as something to adjust rather than accept -->
          <div v-if="plan" class="ugc-card">
            <div class="ugc-card-h">
              <span class="ugc-card-t">Shot plan</span>
            </div>
            <div v-if="plan.reasoning" class="ugc-reason">
              {{ plan.reasoning }}
            </div>
            <p class="ugc-reason">
              {{ plan.format }} · Edit the directions, then validate the
              estimate.
            </p>

            <!-- A shot still pointed at a line the script no longer contains.
                 Detected server-side on every re-price; repairable in one tap. -->
            <div v-if="staleCount" class="ugc-stale-bar">
              <span>
                {{ staleCount }} shot{{ staleCount === 1 ? '' : 's' }}
                {{ staleCount === 1 ? 'is' : 'are' }} still directed at
                {{ staleCount === 1 ? 'a line' : 'lines' }} the script no longer contains.
              </span>
              <button class="ugc-btn" type="button" :disabled="redirecting" @click="redirectStale">
                {{ redirecting ? 'Re-directing…' : 'Re-direct them' }}
              </button>
            </div>

            <ol class="ugc-segs">
              <li v-for="(seg, i) in plan.segments" v-show="selectedPassage === i" :key="i" class="ugc-seg">
                <h3>Passage {{ i + 1 }} <span class="ugc-hint">of {{ plan.segments.length }}</span></h3>
                <span
                  :class="[
                    'ugc-kind',
                    seg.kind === 'on_camera' ? 'on' : 'roll',
                  ]"
                >
                  {{
                    seg.kind === "on_camera"
                      ? "On camera"
                      : seg.kind === "reaction"
                      ? "Silent reaction"
                      : "Cut-away"
                  }}
                  · {{ seg.seconds }}s
                </span>
                <span v-if="seg.stale" class="ugc-seg-stale" title="Its visual answers a line that is no longer spoken">
                  answers a line that is gone
                </span>
                <span v-else-if="seg.source === 'upload' && seg.asset_id" class="ugc-seg-yours">
                  your footage
                </span>
                <div class="ugc-fields ugc-shot-fields">
                  <label v-if="seg.kind !== 'reaction'">
                    <span class="ugc-label-row">
                      Spoken words
                      <button
                        class="ugc-suggest"
                        type="button"
                        :disabled="suggesting === suggestToken('script', seg)"
                        @click="suggest('script', seg, 'script_text')"
                      >
                        {{
                          suggesting === suggestToken("script", seg)
                            ? "thinking…"
                            : "✨ Write with AI"
                        }}
                      </button>
                    </span>
                    <textarea v-model="seg.script_text" placeholder="Write the words spoken in this passage." title="These words are spoken aloud. Editing them changes the plan and requires a new estimate." maxlength="1500" />
                  </label>
                  <label>
                    <span class="ugc-label-row">
                      Shot direction
                      <button
                        class="ugc-suggest"
                        type="button"
                        :disabled="
                          suggesting === suggestToken('visual_brief', seg)
                        "
                        @click="suggest('visual_brief', seg, 'visual_brief')"
                      >
                        {{
                          suggesting === suggestToken("visual_brief", seg)
                            ? "thinking…"
                            : "✨ Write with AI"
                        }}
                      </button>
                    </span>
                    <textarea v-model="seg.visual_brief" placeholder="e.g. Close-up of the bottle on a desk, soft daylight." title="Describe the subject, setting, framing and lighting for this passage." maxlength="1000" />
                  </label>
                  <label v-if="seg.kind === 'reaction'">
                    <span class="ugc-label-row">
                      Physical action
                      <button
                        class="ugc-suggest"
                        type="button"
                        :disabled="
                          suggesting === suggestToken('motion_prompt', seg)
                        "
                        @click="suggest('motion_prompt', seg, 'motion_prompt')"
                      >
                        {{
                          suggesting === suggestToken("motion_prompt", seg)
                            ? "thinking…"
                            : "✨ Write with AI"
                        }}
                      </button>
                    </span>
                    <textarea v-model="seg.motion_prompt" placeholder="e.g. Look surprised, pause, then smile." title="Describe physical movement for this silent reaction." maxlength="1000" />
                  </label>
                  <label v-else>
                    <span class="ugc-label-row">
                      Voice delivery
                      <button
                        class="ugc-suggest"
                        type="button"
                        :disabled="
                          suggesting === suggestToken('voice_direction', seg)
                        "
                        @click="
                          suggest('voice_direction', seg, 'voice_direction')
                        "
                      >
                        {{
                          suggesting === suggestToken("voice_direction", seg)
                            ? "thinking…"
                            : "✨ Write with AI"
                        }}
                      </button>
                    </span>
                    <textarea v-model="seg.voice_direction" placeholder="e.g. Warm and confident, with a pause before the benefit." title="Describe delivery and emotion. Choose the actual voice in Cast & voice." maxlength="500" />
                  </label>
                  <!-- Pace belongs with delivery, not with the script: it is
                       how the line is said, the same as the direction above. -->
                  <label class="ugc-speed">
                    <span class="ugc-label-row">
                      Pace
                      <span class="ugc-speed-value">{{ Number(seg.speed ?? 1).toFixed(2) }}×</span>
                    </span>
                    <input
                      v-model.number="seg.speed"
                      type="range"
                      min="0.5"
                      max="2"
                      step="0.05"
                    />
                    <span class="ugc-speed-scale">
                      <span>slower</span><span>natural</span><span>faster</span>
                    </span>
                  </label>
                  <label>
                    <span class="ugc-label-row">
                      Headline (not spoken, stays for this shot)
                      <button
                        class="ugc-suggest"
                        type="button"
                        :disabled="suggesting === suggestToken('headline', seg)"
                        @click="suggest('headline', seg, 'headline')"
                      >
                        {{
                          suggesting === suggestToken("headline", seg)
                            ? "thinking…"
                            : "✨ Write with AI"
                        }}
                      </button>
                    </span>
                    <textarea v-model="seg.headline" placeholder="e.g. Built for busy mornings" title="Short text on screen; this is not spoken aloud." maxlength="180" />
                  </label>
                  <label v-if="seg.kind === 'reaction'"
                    >Seconds<UiSelect
                      v-model.number="seg.seconds"
                      :options="[
                        { value: 5, label: '5' },
                        { value: 10, label: '10' },
                      ]"
                      aria-label="Reaction seconds"
                      drop="down"
                  /></label>
                  <label v-else
                    >Seconds<input
                      v-model.number="seg.seconds"
                      type="number"
                      min="1"
                      max="60"
                  /></label>
                  <template v-if="seg.kind === 'b_roll'">
                    <label
                      >Visual source<UiSelect
                        v-model="seg.source"
                        :options="[
                          { value: 'upload', label: 'My footage' },
                          { value: 'stock', label: 'Imported stock footage' },
                          {
                            value: 'generate',
                            label: 'Generate illustrative still',
                          },
                        ]"
                        @update:modelValue="seg.asset_id = null"
                        drop="down"
                    /></label>
                    <button
                      v-if="seg.source !== 'generate'"
                      class="ugc-btn"
                      @click="footageShot = i"
                    >
                      {{
                        seg.asset_id
                          ? `Change selected asset #${seg.asset_id}`
                          : "Select / upload required footage"
                      }}
                    </button>
                    <span v-else class="ugc-hint"
                      >An illustrative still, not a real product
                      demonstration.</span
                    >
                  </template>
                </div>
              </li>
            </ol>

            <!-- One idea, several openings. The rest of the ad is untouched;
                 each opening is one more take per character. -->
            <div class="ugc-variants">
              <div class="ugc-variants-h">
                <b>Try other openings</b>
                <UiSelect v-model="variantCount" :options="[2, 3, 4].map(n => ({ value: n, label: `${n} openings` }))" aria-label="How many openings" />
                <button class="ugc-btn" type="button" :disabled="writingVariants" @click="writeVariants">
                  {{ writingVariants ? 'Writing…' : (variants.length ? 'Rewrite' : 'Write them') }}
                </button>
              </div>

              <ul v-if="variants.length" class="ugc-variant-list">
                <li
                  v-for="v in variants"
                  :key="v.label"
                  :class="['ugc-variant', chosenVariants.includes(v.label) ? 'is-on' : '']"
                  @click="toggleVariant(v.label)"
                >
                  <span class="ugc-variant-label">{{ v.label }}</span>
                  <span class="ugc-variant-line">{{ v.segments[0].script_text }}</span>
                  <span class="ugc-variant-cr">{{ v.credits_per_character }} cr</span>
                </li>
              </ul>
              <p v-if="variants.length" class="ugc-hint" style="margin-top:8px">
                {{ takeCount }} take{{ takeCount === 1 ? '' : 's' }} in this run —
                {{ 1 + selectedVariants.length }} opening{{ selectedVariants.length ? 's' : '' }}
                across {{ Math.max(selected.length, 1) }}
                character{{ Math.max(selected.length, 1) === 1 ? '' : 's' }}.
              </p>
            </div>
            <div class="ugc-card-f">
              <button
                class="ugc-btn"
                :disabled="quoting || generating"
                @click="reprice"
              >
                {{
                  quoting ? "Checking…" : "Validate & update estimate"
                }}</button
              ><span v-if="!quoteCurrent" class="ugc-hint"
                >Plan changed. Update estimate.</span
              >
            </div>
            <p
              v-for="warning in plan.warnings"
              :key="warning"
              class="ugc-reason"
            >
              {{ warning }}
            </p>
          </div>

          </div>
          </div>
          <div class="ugc-card-f ugc-brief-cta">
            <button class="ugc-btn ugc-btn-primary" type="button" :disabled="!stepReady[1] || quoting" @click="advanceFromPlan">
              {{ quoting ? 'Checking…' : 'Approve plan → see cost' }}
            </button>
          </div>
          </div>

          <div v-show="step === 2" class="ugc-step-body">
          <div class="ugc-card">
            <div class="ugc-card-h"><span class="ugc-card-t">Ready to generate</span></div>
            <p class="ugc-hint" style="margin:0 0 10px">This is what you're approving. Nothing outside this scope will be generated without a new cost decision.</p>
            <div v-if="plan" class="ugc-summary">
              <div class="ugc-summary-row"><span>Format</span><b>{{ plan.format }}</b></div>
              <div class="ugc-summary-row"><span>Length</span><b>~{{ (plan.segments ?? []).reduce((t, x) => t + Number(x.seconds || 0), 0) }} seconds</b></div>
              <div class="ugc-summary-row"><span>Presenter</span>
                <b>{{ noCast ? 'None — text carries the ad' : (castEngine === 'veo' ? (selected.map((c) => c.name).join(', ') || '—') : 'We\'ll cast a fitting presenter') }}</b></div>
              <div class="ugc-summary-row"><span>Assets used</span>
                <b>{{ [productAsset ? 'product photo' : null, footageAssets.length ? `${footageAssets.length} clip${footageAssets.length === 1 ? '' : 's'} of your footage` : null].filter(Boolean).join(', ') || 'none' }}</b></div>
              <div class="ugc-summary-row"><span>Output</span><b>{{ aspectRatio }} · {{ language }}</b></div>
              <div v-if="mustInclude.trim() || avoid.trim()" class="ugc-plan-pills" style="margin-top:8px">
                <span v-if="mustInclude.trim()" class="ugc-kind on">Must include: {{ mustInclude }}</span>
                <span v-if="avoid.trim()" class="ugc-kind roll">Avoid: {{ avoid }}</span>
              </div>
            </div>
          </div>

          <div class="ugc-card">
            <div class="ugc-card-h"><span class="ugc-card-t">Estimated cost</span></div>
            <div v-if="plan && oneShotEligible" class="ugc-summary">
              <div class="ugc-summary-row"><span>One fluid video · {{ oneShotSeconds }}s · presenter speaks natively</span><b>{{ oneShotRate }} cr/s</b></div>
              <div class="ugc-summary-row ugc-summary-total"><span>1 take — total</span><b>{{ oneShotCredits }} credits</b></div>
            </div>
            <div v-else-if="plan" class="ugc-summary">
              <div class="ugc-summary-row"><span>Base take{{ noCast ? '' : ' (per presenter)' }}</span><b>{{ perCharacter }} credits</b></div>
              <div v-if="selectedVariants.length" class="ugc-summary-row">
                <span>{{ selectedVariants.length }} alternative opening{{ selectedVariants.length === 1 ? '' : 's' }}</span>
                <b>+ {{ variantCreditsPerCast }} credits</b>
              </div>
              <div v-if="!noCast && selected.length > 1" class="ugc-summary-row"><span>× {{ selected.length }} presenters</span><b></b></div>
              <div class="ugc-summary-row ugc-summary-total"><span>{{ takeCount }} take{{ takeCount === 1 ? '' : 's' }} — total</span><b>{{ totalCredits }} credits</b></div>
            </div>
            <p class="ugc-hint">
              The exact total, not a range — charged only for what succeeds. A failed step is retried free.
              Revision choices you make after review are their own cost decision.
            </p>
            <span v-if="!quoteCurrent" class="ugc-hint">Plan changed since this estimate. <button class="ugc-suggest" type="button" :disabled="quoting" @click="reprice">Re-check it</button></span>
          </div>

          <div class="ugc-card">
            <div class="ugc-card-h"><span class="ugc-card-t">Before we start</span></div>
            <div class="ugc-fields">
              <label class="ugc-check"
                ><input v-model="reviewed" type="checkbox" :disabled="!quoteCurrent" />
                I reviewed the plan and the estimate.</label
              >
              <label class="ugc-check"
                ><input v-model="consentLikeness" type="checkbox" />
                I confirm I have the right to use {{ noCast ? 'the footage in this video' : (selected.length === 1 ? `${selected[0]?.name}'s likeness and voice` : "these presenters' likenesses and voices") }} in this video.</label
              >
              <label class="ugc-check"
                ><input v-model="consentFacts" type="checkbox" />
                I confirm the product facts I supplied are accurate.</label
              >
              <span v-if="missingFootage" class="ugc-hint">Select the required footage on the plan before generating.</span>
            </div>
            <div class="ugc-gen-row">
              <button class="ugc-btn ugc-btn-primary" :disabled="!canGenerate" @click="generate">
                {{ generating ? "Starting…" : "Generate video" }}
              </button>
              <span v-if="!canGenerate && plan" class="ugc-hint">Confirm the checks above to continue.</span>
            </div>
          </div>
          </div>

          <!-- Each stage owns its primary action; this footer only goes back. -->
          <div v-if="step > 0" class="ugc-nav">
            <button v-if="step > 0" class="ugc-nav-back" type="button" @click="prevStep">← Back</button>
            <span class="ugc-nav-where">Step {{ step + 1 }} of {{ STEPS.length }}</span>

          </div>
        </section>

        <!-- takes -->
      </div>

      <!-- character picker -->
      <MediaPickerModal
        mode="visual"
        :visible="footageShot !== null"
        @close="footageShot = null"
        @select="selectFootage"
      />
      <MediaPickerModal
        mode="visual"
        :visible="referencePicker"
        @close="referencePicker = false"
        @select="selectReference"
      />
      <MediaPickerModal
        mode="visual"
        :visible="footagePicker"
        @close="footagePicker = false"
        @select="selectFootageAsset"
      />
      <MediaPickerModal
        mode="visual"
        :visible="productPicker"
        @close="productPicker = false"
        @select="selectProduct"
      />
      <div v-if="pickerOpen" class="ugc-scrim" @click.self="pickerOpen = false">
        <div class="ugc-modal">
          <aside class="ugc-m-rail">
            <div class="ugc-m-t">Add characters</div>
            <p class="ugc-hint" style="margin: 6px 0 2px">
              Depending on the engine you choose, your character may appear as a close
              look-alike rather than an exact match — you'll see which before generating.
            </p>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Source</div>
              <div class="ugc-tags">
                <button
                  v-for="s in ['stock', 'mine']"
                  :key="s"
                  :class="['ugc-tag', filters.source === s ? 'on' : '']"
                  type="button"
                  @click="
                    filters.source = s;
                    loadCharacters();
                  "
                >
                  {{ s === "stock" ? "Stock" : "Mine" }}
                </button>
              </div>
            </div>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Gender</div>
              <div class="ugc-tags">
                <button
                  v-for="g in ['female', 'male']"
                  :key="g"
                  :class="['ugc-tag', filters.gender === g ? 'on' : '']"
                  type="button"
                  @click="setFilter('gender', g)"
                >
                  {{ g === "female" ? "Female" : "Male" }}
                </button>
              </div>
            </div>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Age</div>
              <div class="ugc-tags">
                <button
                  v-for="[key, label] in AGES"
                  :key="key"
                  :class="['ugc-tag', filters.age_group === key ? 'on' : '']"
                  type="button"
                  @click="setFilter('age_group', key)"
                >
                  {{ label }}
                </button>
              </div>
            </div>

            <div class="ugc-facet">
              <div class="ugc-facet-h">Setting</div>
              <div class="ugc-tags">
                <button
                  v-for="s in SITUATIONS"
                  :key="s"
                  :class="['ugc-tag', filters.situation === s ? 'on' : '']"
                  type="button"
                  @click="setFilter('situation', s)"
                >
                  {{ s }}
                </button>
              </div>
            </div>
          </aside>

          <div class="ugc-m-body">
            <div class="ugc-m-search">
              <input
                v-model="filters.q"
                class="ugc-m-in"
                placeholder="Search characters…"
                @keyup.enter="loadCharacters"
              />
              <button class="ugc-btn" @click="loadCharacters">Search</button>
            </div>

            <div class="ugc-m-grid">
              <div v-if="charsLoading" class="ugc-hint">Loading…</div>
              <div v-else-if="!characters.length" class="ugc-hint">
                No characters match. Stock actors are still being added — switch
                Source to “Mine” to use your own.
              </div>
              <button
                v-for="c in characters"
                :key="c.id"
                :class="['ugc-pc', isSelected(c) ? 'on' : '']"
                type="button"
                @click="toggleCharacter(c)"
              >
                <div class="ugc-pc-f">
                  <img
                    v-if="c.reference_asset?.thumbnail_url"
                    :src="c.reference_asset.thumbnail_url"
                    alt=""
                  />
                  <span v-else>☺</span>
                  <span v-if="isSelected(c)" class="ugc-pc-ck">✓</span>
                </div>
                <div class="ugc-pc-n">
                  {{ c.name }}
                  <i :class="['ugc-mini', c.is_stock ? 'stock' : '']">{{
                    c.is_stock ? "STOCK" : "MINE"
                  }}</i>
                </div>
                <div class="ugc-pc-s">
                  {{ (c.situations || []).join(" · ") || "—" }}
                </div>
              </button>
            </div>

            <div class="ugc-m-foot">
              <span class="ugc-hint">
                {{ selected.length }} selected · max {{ MAX_CHARACTERS }}
              </span>
              <button
                class="ugc-btn ugc-btn-primary"
                @click="pickerOpen = false"
              >
                Done
              </button>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
.ugc-fields {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.ugc-fields label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: 12px;
  color: var(--color-text-secondary);
}
.ugc-fields input,
.ugc-fields textarea,
.ugc-fields select {
  box-sizing: border-box;
  width: 100%;
  padding: 8px;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  background: var(--color-bg-base);
  color: var(--color-text-primary);
  font: inherit;
}
.ugc-fields textarea {
  min-height: 64px;
  resize: vertical;
}
.ugc-fields audio {
  width: 100%;
}
.ugc-fields .ugc-check {
  flex-direction: row;
  align-items: flex-start;
  line-height: 1.5;
}
.ugc-check input {
  width: auto;
}
.ugc-shot-fields {
  padding: 10px 0 12px;
  width: 100%;
  gap: 12px;
}
/* The sidebar is fixed-position, so the main column must be offset by its
   width or it renders underneath. --sidebar-width tracks the collapsed state. */
.ugc-shell {
  display: flex;
  min-height: 100vh;
  background: var(--color-bg-base);
}
.ugc-main {
  margin-left: var(--sidebar-width, 220px);
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.ugc-top {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 22px;
  border-bottom: 1px solid var(--color-border);
}
.ugc-crumb {
  font-size: 13px;
  color: var(--color-text-muted);
}
.ugc-crumb b {
  color: var(--color-text-primary);
  font-weight: 500;
}
.ugc-beta {
  padding: 2px 7px;
  border-radius: 5px;
  background: rgba(255, 107, 53, 0.13);
  color: var(--color-accent);
  font: 10px var(--font-mono);
  letter-spacing: 0.4px;
}
.ugc-credits {
  margin-left: auto;
  font: 12px var(--font-mono);
  color: var(--color-text-secondary);
  padding: 5px 11px;
  border: 1px solid var(--color-border);
  border-radius: 999px;
}

.ugc-error {
  margin: 14px 22px 0;
  padding: 10px 13px;
  border-radius: 9px;
  background: rgba(240, 112, 112, 0.1);
  border: 1px solid rgba(240, 112, 112, 0.3);
  color: #f0a0a0;
  font-size: 12.5px;
}

.ugc-body {
  display: flex;
  align-items: stretch;
  gap: 0;
  flex: 1;
  min-height: 0;
}
.ugc-build {
  width: 480px;
  flex-shrink: 0;
  padding: 18px 20px;
  border-right: 1px solid var(--color-border);
  overflow-y: auto;
}
.ugc-stage {
  flex: 1;
  padding: 18px 22px;
  min-width: 0;
  overflow-y: auto;
}

.ugc-card {
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  margin-bottom: 14px;
}
.ugc-card-h {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 14px 0;
}
.ugc-card-h .ugc-suggest {
  margin-left: 0;
}
.ugc-card-h .ugc-card-c {
  margin-left: auto;
}
.ugc-card-t {
  font-size: 12.5px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.ugc-card-c {
  margin-left: auto;
  font: 10.5px var(--font-mono);
  color: var(--color-text-muted);
}
.ugc-cast-style { display: flex; flex-wrap: wrap; gap: 10px; padding: 0 18px 16px; }
.ugc-style-pill {
  flex: 1 1 220px; display: flex; flex-direction: column; gap: 3px; cursor: pointer;
  border: 1px solid var(--color-border); border-radius: 11px; padding: 10px 14px; font-size: 12.5px;
}
.ugc-style-pill input { position: absolute; opacity: 0; pointer-events: none; }
.ugc-style-pill.on { border-color: var(--color-primary); background: color-mix(in srgb, var(--color-primary) 6%, transparent); }
.ugc-style-pill b { font-size: 13px; }
.ugc-style-pill span { color: var(--color-text-muted); }

.ugc-card-f {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 14px 13px;
}

/* Was borderless and flush to the card edges, so it read as a hole in the
   panel rather than a field, and sat tight under its own header. Inset and
   bordered to match every other input. */
.ugc-script {
  display: block;
  width: calc(100% - 28px);
  margin: 10px 14px 4px;
  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  color: var(--color-text-primary);
  resize: vertical;
  padding: 10px 12px;
  font-size: 13px;
  line-height: 1.65;
  outline: none;
  min-height: 96px;
  font-family: inherit;
  transition: border-color 0.12s ease, box-shadow 0.12s ease;
}
.ugc-script:hover {
  border-color: var(--color-border-active);
}
.ugc-script:focus-visible {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.16);
}
.ugc-script::placeholder {
  color: var(--color-text-muted);
}

.ugc-reason {
  padding: 0 14px 8px;
  font-size: 11.5px;
  color: var(--color-text-muted);
  font-style: italic;
}
.ugc-segs {
  list-style: none;
  margin: 0;
  padding: 4px 14px 12px;
}
.ugc-seg {
  display: grid;
  grid-template-columns: 74px 1fr;
  gap: 8px;
  padding: 7px 0;
  border-top: 1px solid var(--color-border);
  align-items: start;
}
.ugc-kind {
  font: 9.5px var(--font-mono);
  padding: 3px 6px;
  border-radius: 4px;
  text-align: center;
}
.ugc-kind.on {
  background: rgba(255, 107, 53, 0.13);
  color: var(--color-accent);
}
.ugc-kind.roll {
  background: var(--color-bg-elevated);
  color: var(--color-text-muted);
}
.ugc-seg-text {
  font-size: 12px;
  color: var(--color-text-secondary);
  line-height: 1.5;
}
.ugc-seg-brief {
  grid-column: 2;
  font-size: 11px;
  color: var(--color-text-muted);
}

.ugc-ch {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-top: 1px solid var(--color-border);
}
.ugc-ch-av {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  overflow: hidden;
  background: var(--color-bg-elevated);
  display: grid;
  place-items: center;
  flex-shrink: 0;
}
.ugc-ch-av img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.ugc-ch-n {
  font-size: 12.5px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.ugc-ch-s {
  font-size: 10.5px;
  color: var(--color-text-muted);
  margin-top: 1px;
}
.ugc-ch-x {
  margin-left: auto;
  background: none;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 13px;
}

.ugc-out {
  display: flex;
  gap: 8px;
  padding: 12px 14px 14px;
}
.ugc-seg-group {
  display: flex;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  overflow: hidden;
}
.ugc-seg-btn {
  padding: 6px 11px;
  font-size: 11.5px;
  background: var(--color-bg-elevated);
  color: var(--color-text-secondary);
  border: none;
  cursor: pointer;
}
.ugc-seg-btn.on {
  background: rgba(255, 107, 53, 0.13);
  color: var(--color-accent);
}

.ugc-gen {
  padding: 13px 14px;
  border-top: 1px solid var(--color-border);
}
.ugc-math {
  display: block;
  font: 11px var(--font-mono);
  color: var(--color-text-secondary);
  margin-bottom: 10px;
}
.ugc-math b {
  color: #e8b54a;
}
.ugc-gate {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 11px;
  border-radius: 9px;
  background: rgba(232, 181, 74, 0.1);
  border: 1px solid rgba(232, 181, 74, 0.3);
  margin-bottom: 11px;
}
.ugc-gate-i {
  color: #e8b54a;
  font-size: 13px;
}
.ugc-gate-t {
  font-size: 11.5px;
  color: #f0d6a2;
  line-height: 1.5;
}
.ugc-gate-t b {
  color: #e8b54a;
}
.ugc-gate.ok {
  background: rgba(62, 207, 142, 0.1);
  border-color: rgba(62, 207, 142, 0.3);
}
.ugc-gate.ok .ugc-gate-i {
  color: #3ecf8e;
}
.ugc-gate.ok .ugc-gate-t {
  color: #a8e6c9;
}
.ugc-gate.ok .ugc-gate-t b {
  color: #3ecf8e;
}
.ugc-listen {
  margin-left: auto;
  flex-shrink: 0;
  padding: 6px 11px;
  border-radius: 7px;
  border: 1px solid rgba(232, 181, 74, 0.4);
  background: rgba(232, 181, 74, 0.1);
  color: #e8b54a;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
}
.ugc-gen-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ugc-btn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 7px 12px;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: var(--color-bg-elevated);
  color: var(--color-text-primary);
  font-size: 12px;
  cursor: pointer;
  font-family: inherit;
}
.ugc-btn:hover:not(:disabled) {
  border-color: var(--color-border-active);
}
.ugc-btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #1a0a04;
  font-weight: 700;
}
.ugc-btn-primary:hover:not(:disabled) {
  background: var(--color-accent-hover);
}
.ugc-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.ugc-hint {
  font-size: 11px;
  color: var(--color-text-muted);
}

/* ── Form controls ────────────────────────────────────────────────
   Browser defaults ignore the palette entirely: a native select paints a
   light system chrome and its arrow cannot be styled, and inputs keep the
   platform focus ring. Everything here is reset and rebuilt on the app's
   tokens so a form does not look like a different application embedded in
   the page. */
.ugc-build
  :where(
    input[type="text"],
    input[type="number"],
    input:not([type]),
    textarea
  ) {
  -webkit-appearance: none;
  appearance: none;

  background: var(--color-bg-elevated);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 8px 11px;
  color: var(--color-text-primary);
  font: inherit;
  font-size: 12.5px;
  line-height: 1.5;
  outline: none;
  transition: border-color 0.12s ease, box-shadow 0.12s ease;
}
.ugc-build :where(input, textarea):hover:not(:disabled) {
  border-color: var(--color-border-active);
}
.ugc-build :where(input, textarea):focus-visible {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.16);
}
.ugc-build :where(input, textarea)::placeholder {
  color: var(--color-text-muted);
}
.ugc-build :where(input, textarea):disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.ugc-build textarea {
  resize: vertical;
  min-height: 62px;
}

/* Number spinners are unstyleable and read as clutter next to the rest. */
.ugc-build input[type="number"] {
  -moz-appearance: textfield;
}
.ugc-build input[type="number"]::-webkit-outer-spin-button,
.ugc-build input[type="number"]::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

/* Labels wrapping a control, as used through the build column. */
.ugc-build label {
  display: flex;
  flex-direction: column;
  gap: 5px;
  font-size: 11px;
  color: var(--color-text-muted);
}

.ugc-ch-voice {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 7px;
  flex-wrap: wrap;
}
.ugc-ch-voice > :first-child {
  max-width: 200px;
}
.ugc-btn-sm {
  padding: 5px 9px;
  font-size: 11px;
}
.ugc-audio {
  height: 30px;
  max-width: 100%;
  margin-top: 4px;
}
.ugc-audio::-webkit-media-controls-panel {
  background: var(--color-bg-elevated);
}

.ugc-speed { gap: 6px; }
.ugc-speed input[type="range"] {
  width: 100%; accent-color: var(--color-accent, #ff6b35);
  cursor: pointer; margin: 2px 0 0;
}
.ugc-speed-value {
  font-family: "Space Mono", ui-monospace, monospace; font-size: 11.5px;
  color: var(--color-accent, #ff6b35); font-weight: 600;
}
/* The scale is what makes a bare slider legible — 1.35× means nothing on its
   own, and nobody wants to drag it to find out. */
.ugc-speed-scale {
  display: flex; justify-content: space-between;
  font-size: 10px; color: var(--color-text-muted, #6a6a7c);
}

.ugc-label-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.ugc-suggest {
  margin-left: auto;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--color-accent);
  font: inherit;
  font-size: 10.5px;
  padding: 2px 4px;
  border-radius: 5px;
  opacity: 0.85;
}
.ugc-suggest:hover:not(:disabled) {
  opacity: 1;
  background: rgba(255, 107, 53, 0.1);
}
.ugc-suggest:disabled {
  color: var(--color-text-muted);
  cursor: default;
}

.ugc-product { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ugc-product-thumb { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; border: 1px solid var(--color-border); }

.ugc-stage-h {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}
.ugc-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  height: 280px;
  text-align: center;
  color: var(--color-text-muted);
}
.ugc-empty-i {
  width: 44px;
  height: 44px;
  border-radius: 11px;
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  display: grid;
  place-items: center;
  font-size: 18px;
}
.ugc-empty p {
  font-size: 12.5px;
  line-height: 1.6;
  max-width: 260px;
}
.ugc-takes {
  display: flex;
  flex-direction: column;
  gap: 11px;
}
.ugc-take {
  padding: 12px;
  border-radius: 11px;
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
}
.ugc-take-m {
  font: 10.5px var(--font-mono);
  color: var(--color-text-muted);
  margin-top: 3px;
}
.ugc-take-a {
  margin-top: 10px;
}

.ugc-scrim {
  position: fixed;
  inset: 0;
  background: rgba(5, 5, 9, 0.74);
  display: grid;
  place-items: center;
  padding: 32px;
  z-index: 60;
}
.ugc-modal {
  width: 100%;
  max-width: 820px;
  height: 100%;
  max-height: 560px;
  background: var(--color-bg-panel);
  border: 1px solid var(--color-border);
  border-radius: 14px;
  display: flex;
  overflow: hidden;
}
.ugc-m-rail {
  width: 194px;
  flex-shrink: 0;
  border-right: 1px solid var(--color-border);
  padding: 15px 13px;
  overflow-y: auto;
}
.ugc-m-t {
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 13px;
  color: var(--color-text-primary);
}
.ugc-facet {
  margin-bottom: 15px;
}
.ugc-facet-h {
  font: 10px var(--font-mono);
  color: var(--color-text-muted);
  letter-spacing: 0.6px;
  text-transform: uppercase;
  margin-bottom: 7px;
}
.ugc-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}
.ugc-tag {
  padding: 4px 8px;
  border-radius: 999px;
  border: 1px solid var(--color-border);
  background: var(--color-bg-card);
  color: var(--color-text-secondary);
  font-size: 11px;
  cursor: pointer;
  font-family: inherit;
}
.ugc-tag.on {
  background: rgba(255, 107, 53, 0.13);
  border-color: rgba(255, 107, 53, 0.42);
  color: var(--color-accent);
}
.ugc-m-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.ugc-m-search {
  padding: 13px 15px;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  gap: 9px;
}
.ugc-m-in {
  flex: 1;
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: 7px 10px;
  color: var(--color-text-primary);
  font-size: 12.5px;
  outline: none;
  font-family: inherit;
}
.ugc-m-grid {
  flex: 1;
  overflow-y: auto;
  padding: 15px;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
  gap: 12px;
  align-content: start;
}
.ugc-pc {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  text-align: left;
  font-family: inherit;
}
.ugc-pc-f {
  aspect-ratio: 9/16;
  border-radius: 9px;
  border: 1px solid var(--color-border);
  position: relative;
  background: var(--color-bg-elevated);
  display: grid;
  place-items: center;
  font-size: 28px;
  overflow: hidden;
}
.ugc-pc-f img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.ugc-pc.on .ugc-pc-f {
  border-color: var(--color-accent);
  box-shadow: 0 0 0 2px rgba(255, 107, 53, 0.42);
}
.ugc-pc-ck {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: var(--color-accent);
  color: #1a0a04;
  font-size: 10px;
  font-weight: 700;
  display: grid;
  place-items: center;
}
.ugc-pc-n {
  display: flex;
  align-items: center;
  gap: 5px;
  margin-top: 6px;
  font-size: 11.5px;
  font-weight: 500;
  color: var(--color-text-primary);
}
.ugc-pc-s {
  font-size: 10px;
  color: var(--color-text-muted);
}
.ugc-mini {
  padding: 1px 5px;
  border-radius: 4px;
  font: 8.5px var(--font-mono);
  background: var(--color-bg-elevated);
  color: var(--color-text-muted);
  font-style: normal;
}
.ugc-mini.stock {
  background: rgba(62, 207, 142, 0.12);
  color: #3ecf8e;
}
.ugc-m-foot {
  padding: 11px 15px;
  border-top: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  gap: 9px;
}
.ugc-m-foot .ugc-btn-primary {
  margin-left: auto;
}

/* ── Wizard ─────────────────────────────────────────────────────────────
   The rail is the only thing on a phone telling you how far through you are,
   so it scrolls horizontally rather than wrapping into a block that pushes
   the fields off screen. */
.ugc-steps {
  display: flex;
  align-items: center;
  flex: 0 0 auto;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 16px;
}

.ugc-step {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 11px;
  border: 1px solid var(--color-border);
  border-radius: 11px;
  background: var(--color-bg-card);
  color: var(--color-text-secondary);
  font: inherit;
  cursor: pointer;
  text-align: left;
  transition: 0.15s;
}
.ugc-step:hover:not(:disabled) { border-color: var(--color-border-active); }
.ugc-step.is-current {
  border-color: var(--color-accent);
  background: rgba(255, 107, 53, 0.08);
  color: var(--color-text-primary);
}
.ugc-step.is-done .ugc-step-n { color: #34d399; border-color: rgba(52, 211, 153, 0.4); }
.ugc-step.is-locked { opacity: 0.45; cursor: not-allowed; }

.ugc-step-n {
  flex: 0 0 auto;
  width: 24px;
  height: 24px;
  display: grid;
  place-items: center;
  border: 1px solid var(--color-border);
  border-radius: 50%;
  font-family: "Space Mono", monospace;
  font-size: 10.5px;
  font-weight: 700;
}
.ugc-step.is-current .ugc-step-n { border-color: var(--color-accent); color: var(--color-accent); }
.ugc-step-t b { font-size: 12.5px; font-weight: 600; white-space: nowrap; }

.ugc-step-body { display: flex; flex-direction: column; gap: 14px; }

.ugc-nav {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--color-border);
}
.ugc-nav-where {
  font-family: "Space Mono", monospace;
  font-size: 11px;
  color: var(--color-text-muted);
  margin-right: auto;
}
.ugc-nav-back,
.ugc-nav-next {
  border-radius: 9px;
  padding: 10px 16px;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid var(--color-border);
  background: transparent;
  color: var(--color-text-primary);
}
.ugc-nav-next {
  border-color: transparent;
  background: var(--color-accent);
  color: #fff;
}
/* Disabled says what is still needed, so it is a label rather than a wall. */
.ugc-nav-next:disabled {
  background: var(--color-bg-elevated);
  color: var(--color-text-muted);
  cursor: not-allowed;
  font-weight: 400;
}

/* ── Phone ──────────────────────────────────────────────────────────────
   This screen had no media queries at all: a two-column layout and a full
   page of fields at any width. The wizard is what makes one column work —
   one stage per screen instead of five stacked. */
@media (max-width: 860px) {
  .ugc-shell { margin-left: 0; }

  /* .ugc-body is a flex row and .ugc-build a fixed 480px column, so at 430px
     the page was 540px wide and the fixed shell bars stretched with it. */
  .ugc-body {
    flex-direction: column;
    align-items: stretch;
    gap: 16px;
  }
  .ugc-build,
  .ugc-stage {
    width: auto;
    min-width: 0;
  }

  /* The shell bar already names the screen and shows the balance; the page
     header repeated both, and the beta badge is not worth a row of its own. */
  .ugc-top { display: none; }

  .ugc-step { padding: 8px 10px; }

  .ugc-nav { flex-wrap: wrap; }
  .ugc-nav-where { order: -1; flex: 1 0 100%; margin: 0; }
  .ugc-nav-back, .ugc-nav-next { flex: 1; min-height: 44px; }
}

/* ── Starting point ─────────────────────────────────────────────────── */
.ugc-starts { display: flex; flex-direction: column; gap: 8px; }
.ugc-start {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 11px 13px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-bg-card);
  color: var(--color-text-secondary);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: 0.15s;
}
.ugc-start:hover { border-color: var(--color-border-active); }
.ugc-start.is-on {
  border-color: var(--color-accent);
  background: rgba(255, 107, 53, 0.08);
  color: var(--color-text-primary);
}
.ugc-start b { font-size: 13.5px; font-weight: 600; }
.ugc-start span { font-size: 11.5px; color: var(--color-text-muted); }

.ugc-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ugc-error-inline { font-size: 12px; color: #f87171; margin: 8px 0 0; }

/* What we read off the reference, shown before it shapes anything. */
.ugc-shape {
  margin-top: 12px;
  padding: 12px 14px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  background: var(--color-bg-elevated);
}
.ugc-shape-h { font-size: 13px; font-weight: 600; margin-bottom: 8px; }
.ugc-beats { margin: 0 0 8px; padding-left: 18px; display: flex; flex-direction: column; gap: 5px; }
.ugc-beats li { font-size: 12px; color: var(--color-text-secondary); }
.ugc-beats b {
  font-family: "Space Mono", monospace;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-accent);
  margin-right: 6px;
}
.ugc-beat-secs {
  font-family: "Space Mono", monospace;
  font-size: 10.5px;
  color: var(--color-text-muted);
  margin-right: 6px;
}

.ugc-footage { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.ugc-footage-item {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 5px 7px 5px 5px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: var(--color-bg-card);
  font-size: 12px;
  max-width: 220px;
}
.ugc-footage-item img { width: 26px; height: 26px; border-radius: 5px; object-fit: cover; flex: 0 0 auto; }
.ugc-footage-item span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ugc-footage-item button {
  border: 0;
  background: none;
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 15px;
  line-height: 1;
  padding: 0 2px;
}
.ugc-footage-item button:hover { color: #f87171; }

/* ── A shot the script moved out from under ─────────────────────────── */
.ugc-stale-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin: 10px 0 12px;
  padding: 10px 12px;
  border: 1px solid rgba(251, 191, 36, 0.3);
  border-radius: 9px;
  background: rgba(251, 191, 36, 0.08);
  font-size: 12.5px;
  color: var(--color-text-secondary);
}
.ugc-stale-bar span { flex: 1; min-width: 200px; }

.ugc-seg-stale,
.ugc-seg-yours {
  font-family: "Space Mono", monospace;
  font-size: 9.5px;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  padding: 2px 7px;
  border-radius: 5px;
  margin-left: 6px;
}
.ugc-seg-stale { color: #fbbf24; background: rgba(251, 191, 36, 0.12); }
/* Their own clip, which is the cheap and better answer where one exists. */
.ugc-seg-yours { color: #34d399; background: rgba(52, 211, 153, 0.12); }

/* ── Openings ───────────────────────────────────────────────────────── */
.ugc-variants {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--color-border);
}
.ugc-variants-h { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ugc-variants-h b { font-size: 13px; font-weight: 600; flex: 1; }
.ugc-mini-select {
  padding: 6px 8px;
  border-radius: 7px;
  border: 1px solid var(--color-border);
  background: var(--color-bg-elevated);
  color: var(--color-text-primary);
  font: inherit;
  font-size: 12px;
}
.ugc-variant-list { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.ugc-variant {
  display: flex;
  align-items: baseline;
  gap: 10px;
  padding: 9px 11px;
  border: 1px solid var(--color-border);
  border-radius: 9px;
  background: var(--color-bg-card);
  cursor: pointer;
  transition: 0.15s;
}
.ugc-variant:hover { border-color: var(--color-border-active); }
.ugc-variant.is-on { border-color: var(--color-accent); background: rgba(255, 107, 53, 0.08); }
.ugc-variant-label {
  font-family: "Space Mono", monospace;
  font-size: 9.5px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--color-accent);
  flex: 0 0 auto;
}
.ugc-variant-line {
  flex: 1;
  min-width: 0;
  font-size: 12.5px;
  color: var(--color-text-secondary);
}
.ugc-variant-cr {
  font-family: "Space Mono", monospace;
  font-size: 10.5px;
  color: var(--color-text-muted);
  flex: 0 0 auto;
}

/* ── mockup-flow additions ─────────────────────────────────────────────── */
.ugc-product-item { position: relative; display: inline-flex; }
.ugc-product-item button {
  position: absolute; top: -6px; right: -6px; width: 18px; height: 18px;
  border-radius: 50%; border: none; background: var(--color-danger, #b3261e);
  color: #fff; font-size: 11px; line-height: 1; cursor: pointer;
}
.ugc-brief-tabs { margin-bottom: 14px; }
.ugc-two { display: flex; gap: 12px; }
.ugc-two > label { flex: 1; }
.ugc-brief-cta {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 4px;
}
/* The approve cards hold bare content (summary rows, hints, checks) with
   no inner helper class, so they inherited .ugc-card's zero padding and
   everything sat flush against the card edges. Give the card children
   their interior. */
.ugc-card > .ugc-summary { padding: 6px 18px 16px; }
.ugc-card > .ugc-hint { display: block; padding: 6px 18px 0; }
.ugc-card > .ugc-plan-pills { padding: 0 18px 14px; }
.ugc-card > .ugc-gen-row { padding: 4px 18px 16px; }
.ugc-card-h { padding: 16px 18px 2px; }
.ugc-summary-row { padding: 11px 0; }
.ugc-check { padding: 7px 0; line-height: 1.55; }
.ugc-gen-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

.ugc-plan-head {
  padding: 14px 0 6px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.ugc-plan-pills { display: flex; gap: 8px; flex-wrap: wrap; }
.ugc-summary { display: flex; flex-direction: column; }
.ugc-summary-row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
  padding: 9px 0;
  border-bottom: 1px solid var(--color-border);
  font-size: 13px;
}
.ugc-summary-row span { color: var(--color-text-muted); }
.ugc-summary-row:last-child { border-bottom: none; }
.ugc-summary-total {
  background: var(--color-primary-soft, rgba(255, 107, 53, 0.08));
  border-radius: 10px;
  padding: 12px 14px;
  margin-top: 6px;
  border-bottom: none;
  font-size: 14px;
}
@media (max-width: 640px) {
  .ugc-two { flex-direction: column; }
}

/* Reference layout, using the application's existing colors and shell. */
.ugc-body { display: block; overflow: visible; }
.ugc-build { width: 100%; max-width: 1280px; margin: 0 auto; border: 0; overflow: visible; padding: 24px; }
.ugc-at-0 .ugc-step-body, .ugc-at-2 .ugc-step-body { max-width: 840px; margin: 0 auto; }
.ugc-stage { flex: none; margin: 12px 24px 32px; padding: 18px; border: 1px solid var(--color-border); border-radius: 12px; overflow: visible; }
.ugc-stage summary { cursor: pointer; font-weight: 600; }
.ugc-stage-h { display: none; }
.ugc-plan-layout { display: grid; grid-template-columns: minmax(250px, .8fr) minmax(0, 1.6fr); gap: 24px; align-items: start; }
.ugc-plan-list, .ugc-plan-detail { min-width: 0; }
.ugc-plan-list h3 { margin: 0 0 8px; }
.ugc-plan-list > .ugc-card { margin-top: 24px; }
.ugc-passage { display: flex; flex-direction: column; gap: 10px; width: 100%; text-align: left; padding: 15px; margin: 10px 0; border: 1px solid var(--color-border); border-radius: 12px; background: var(--color-bg-card); color: var(--color-text-primary); cursor: pointer; }
.ugc-passage > span:first-child { display: flex; justify-content: space-between; gap: 12px; }
.ugc-passage.on { border-color: var(--color-accent); background: color-mix(in srgb, var(--color-accent) 8%, var(--color-bg-card)); }
.ugc-passage-copy { font-size: 13px; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; color: var(--color-text-muted); }
.ugc-seg { display: block; padding: 18px 0; }
.ugc-seg h3 { margin: 0 0 14px; font-size: 18px; font-weight: 600; }
.ugc-shot-fields { width: 100%; margin-top: 16px; }
.ugc-plan-list .ugc-card-h { flex-wrap: wrap; }
.ugc-plan-list .ugc-card-t { white-space: nowrap; }
.ugc-segs { padding: 0 16px; }
.ugc-plan-list .ugc-ch { flex-wrap: wrap; }
.ugc-plan-list .ugc-ch-m { min-width: 0; }
@media (max-width: 1050px) { .ugc-plan-layout { grid-template-columns: minmax(220px, .85fr) minmax(0, 1.4fr); gap: 16px; } }
@media (max-width: 760px) {
  .ugc-plan-layout { grid-template-columns: minmax(0, 1fr); }
  .ugc-build { padding: 16px; }
  .ugc-stage { margin: 12px 16px 32px; }
}
.ugc-optional summary { cursor: pointer; }
.ugc-optional[open] summary { margin-bottom: 16px; }
/* Chrome slots a details' children into an internal ::details-content box,
   so the flex gap declared on .ugc-fields never reaches the fields — they
   rendered flush against each other. Recreate the rhythm inside. */
.ugc-optional::details-content {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
@supports not selector(::details-content) {
  .ugc-optional[open] > *:not(summary) { margin-top: 10px; }
}

/* Brief reference: compact inputs with a live summary and primary action beside them. */
.ugc-at-0 .ugc-brief-layout { display: grid; grid-template-columns: minmax(0, 1.85fr) minmax(270px, 1fr); max-width: none; gap: 24px; align-items: start; }
.ugc-brief-main { min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.ugc-brief-main > .ugc-card { margin-bottom: 0; padding: 20px; }
.ugc-brief-main .ugc-card-t { font-size: 16px; }
.ugc-brief-main .ugc-starts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.ugc-brief-main .ugc-start { min-width: 0; padding: 12px; }
.ugc-brief-main .ugc-start b { font-size: 12px; }
.ugc-brief-main .ugc-start span { font-size: 11px; line-height: 1.5; }
.ugc-brief-main .ugc-brief-tabs { flex-wrap: wrap; }
.ugc-brief-main textarea { min-height: 90px; }
.ugc-brief-main .ugc-optional:not([open]) { display: block; }
.ugc-brief-side { position: sticky; top: 24px; display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.ugc-brief-side .ugc-card { margin: 0; padding: 20px; }
.ugc-live-brief h2, .ugc-brief-recent h2 { display: flex; align-items: center; gap: 8px; margin: 0 0 16px; font-size: 13px; font-weight: 600; }
.ugc-live-brief h2 { text-transform: uppercase; letter-spacing: .08em; font-family: var(--font-mono); font-size: 11px; color: var(--color-text-secondary); }
.ugc-live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--color-accent); }
.ugc-live-brief > p { font-size: 14px; line-height: 1.7; color: var(--color-text-secondary); margin: 0 0 12px; }
.ugc-live-brief strong { color: var(--color-text-primary); font-weight: 500; }
.ugc-live-brief dl { border-top: 1px solid var(--color-border); padding-top: 12px; margin: 16px 0 0; }
.ugc-live-brief dl > div { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; font-size: 12px; }
.ugc-live-brief dt { color: var(--color-text-muted); }
.ugc-live-brief dd { margin: 0; text-align: right; }
.ugc-plan-cta { width: 100%; padding: 14px; font-size: 13px; }
.ugc-plan-note { text-align: center; margin: -4px 0 4px; line-height: 1.6; }
.ugc-delivery-grid { display: grid; grid-template-columns: 1.2fr 1fr .65fr; gap: 14px; }
.ugc-delivery-grid .ugc-seg-group { flex-wrap: nowrap; }
.ugc-delivery-grid .ugc-seg-btn { padding: 8px 9px; flex: 1; font-size: 12px; }
.ugc-custom-length { font-size: 12px; color: var(--color-text-muted); }
.ugc-custom-length summary, .ugc-more-takes summary { cursor: pointer; }
.ugc-custom-length label { max-width: 180px; margin-top: 12px; }
.ugc-recent-row { display: flex; align-items: center; gap: 12px; border-top: 1px solid var(--color-border); padding: 14px 0; }
.ugc-recent-row > div { flex: 1; min-width: 0; }
.ugc-recent-row b { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12px; font-weight: 500; }
.ugc-recent-row p, .ugc-recent-row span { font-size: 11px; color: var(--color-text-muted); margin: 4px 0 0; line-height: 1.5; }
.ugc-recent-open, .ugc-recent-extra { color: var(--color-accent); border: 0; background: transparent; font: inherit; font-size: 12px; cursor: pointer; padding: 4px 0; white-space: nowrap; }
.ugc-more-takes { font-size: 12px; color: var(--color-text-secondary); }
.ugc-recent-extra { display: block; white-space: normal; text-align: left; margin-top: 12px; }
@media (max-width: 1100px) {
  .ugc-at-0 .ugc-brief-layout { grid-template-columns: minmax(0, 1.5fr) minmax(250px, 1fr); gap: 16px; }
  .ugc-delivery-grid { grid-template-columns: 1fr 1fr; }
  .ugc-brief-main .ugc-starts { grid-template-columns: 1fr; }
}
@media (max-width: 760px) {
  .ugc-at-0 .ugc-brief-layout { grid-template-columns: minmax(0, 1fr); }
  .ugc-brief-side { position: static; }
  .ugc-brief-main > .ugc-card, .ugc-brief-side .ugc-card { padding: 16px; }
}
@media (max-width: 400px) { .ugc-delivery-grid { grid-template-columns: 1fr; } }
</style>
