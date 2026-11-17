<script setup>
// The transition into the next scene (owner, 2026-11-17): twenty to pick from, none by default. Each tile shows the
// move from this scene's last frame to the next one's first; hovering plays it, and the large preview loops the pick
// at its length. A pick saves at once; "Use on all scenes" sets every cut in one step (one undo).
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import api from "../../services/api";
import { useCore } from "./core";
import { DEFAULT_SECONDS, TRANSITIONS, TRANSITION_SECONDS, drawTransition, parseRule, sceneFrame } from "./transitions";

const props = defineProps({ index: { type: Number, required: true } });
const emit = defineEmits(["close"]);
const core = useCore();
const { scenes, project, replaceSceneInCollection } = core;

const from = computed(() => scenes.value[props.index] ?? null);
const to = computed(() => scenes.value[props.index + 1] ?? null);
const current = computed(() => parseRule(from.value?.transition_rule));
const type = ref(current.value?.type ?? "none");
const seconds = ref(current.value?.seconds ?? DEFAULT_SECONDS);
const saving = ref(false);
const error = ref("");
const allDone = ref(false);

// Frame size in the project's shape, small enough to draw twenty times.
const shape = computed(() => {
  const [w, h] = String(project.value?.aspect_ratio || "9:16").split(":").map(Number);
  return w && h ? w / h : 9 / 16;
});
const dims = (long) => (shape.value >= 1 ? [long, Math.round(long / shape.value)] : [Math.round(long * shape.value), long]);

const frames = ref(null);
const tileRefs = ref({});
const big = ref(null);
const webgl = ref(true);

function drawTile(key, t) {
  const c = tileRefs.value[key];
  if (c && frames.value && !drawTransition(c, frames.value.a, frames.value.b, key, t)) webgl.value = false;
}
let hoverRaf = 0;
let hoverKey = null;
function hover(key) {
  hoverKey = key;
  cancelAnimationFrame(hoverRaf);
  const start = performance.now();
  const tick = (now) => {
    if (hoverKey !== key) return;
    // Hold, move, hold: 0.3 s on the outgoing scene, 1 s of transition, 0.3 s on the next.
    const t = (((now - start) / 1000) % 1.6 - 0.3) / 1;
    drawTile(key, t);
    hoverRaf = requestAnimationFrame(tick);
  };
  hoverRaf = requestAnimationFrame(tick);
}
// The still on each tile: the middle of the move, except Zoom, whose middle is the closest (one colour).
const restT = (key) => (key === "zoomin" ? 0.2 : 0.5);
function unhover(key) {
  if (hoverKey === key) hoverKey = null;
  drawTile(key, restT(key));
}
let bigRaf = 0;
function loopBig() {
  cancelAnimationFrame(bigRaf);
  const start = performance.now();
  const tick = (now) => {
    const c = big.value;
    if (!c || !frames.value) return;
    const hold = 0.6;
    const d = Number(seconds.value) || DEFAULT_SECONDS;
    const e = ((now - start) / 1000) % (hold * 2 + d);
    const t = type.value === "none" ? (e < hold + d / 2 ? 0 : 1) : (e - hold) / d;
    drawTransition(c, frames.value.a, frames.value.b, type.value === "none" ? "fade" : type.value, t);
    bigRaf = requestAnimationFrame(tick);
  };
  bigRaf = requestAnimationFrame(tick);
}

onMounted(async () => {
  const [w, h] = dims(240);
  const [a, b] = await Promise.all([
    sceneFrame(from.value, props.index + 1, w, h, "end"),
    sceneFrame(to.value, props.index + 2, w, h, "start"),
  ]);
  frames.value = { a, b };
  await nextTick();
  for (const t of TRANSITIONS) drawTile(t.key, restT(t.key));
  loopBig();
});
// Escape closes it wherever the focus is (the tiles are canvases, so focus often stays on the page).
const onKey = (e) => { if (e.key === "Escape") emit("close"); };
window.addEventListener("keydown", onKey);
onBeforeUnmount(() => { cancelAnimationFrame(bigRaf); cancelAnimationFrame(hoverRaf); hoverKey = null; window.removeEventListener("keydown", onKey); });
watch([type, seconds], () => { if (frames.value) loopBig(); });

async function save(all = false) {
  if (!from.value) return;
  saving.value = true;
  error.value = "";
  try {
    const r = await api.post(`/scenes/${from.value.id}/transition${all ? "/all" : ""}`, { type: type.value, seconds: Number(seconds.value) });
    for (const row of r.data?.data?.scenes ?? []) {
      const s = scenes.value.find((x) => x.id === row.id);
      if (s && s.transition_rule !== row.transition_rule) replaceSceneInCollection({ ...s, transition_rule: row.transition_rule });
    }
    if (all) allDone.value = true;
  } catch (err) {
    error.value = err.response?.data?.error?.message ?? "Couldn't save the transition.";
  } finally {
    saving.value = false;
  }
}
function pick(key) {
  if (type.value === key) return;
  type.value = key;
  allDone.value = false;
  save();
}
function pickSeconds(s) {
  if (seconds.value === s) return;
  seconds.value = s;
  allDone.value = false;
  if (type.value !== "none") save();
}
const tileStyle = computed(() => ({ aspectRatio: String(shape.value) }));
</script>

<template>
  <div class="nx-modal-bg" @click.self="emit('close')">
    <div class="nx-modal nx-modal-wide nx-tr" role="dialog" aria-modal="true" aria-labelledby="nx-tr-title">
      <div class="nx-row">
        <div>
          <h2 id="nx-tr-title">Transition</h2>
          <p>From scene {{ index + 1 }} into scene {{ index + 2 }}. The voices aren't moved and the video's length stays the same.</p>
        </div>
        <button class="nx-ib" type="button" aria-label="Close" @click="emit('close')"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
      </div>

      <div class="nx-tr-body">
        <div class="nx-tr-side">
          <canvas ref="big" class="nx-tr-big" :width="dims(240)[0]" :height="dims(240)[1]" :style="tileStyle" aria-label="Preview of the transition"></canvas>
          <span class="nx-label">Length</span>
          <div class="nx-seg nx-seg-full" role="group" aria-label="Length">
            <button v-for="s in TRANSITION_SECONDS" :key="s" type="button" :class="seconds === s ? 'on' : ''" :disabled="type === 'none'" @click="pickSeconds(s)">{{ s }} s</button>
          </div>
          <button class="nx-btn nx-full" type="button" :disabled="saving || scenes.length < 3" @click="save(true)">{{ allDone ? "Used on all scenes" : "Use on all scenes" }}</button>
          <p v-if="error" class="nx-err">{{ error }}</p>
          <p v-else-if="!webgl" class="nx-hint">This browser can't play the previews, but the transition is still applied when you export.</p>
        </div>

        <div class="nx-tr-grid" role="radiogroup" aria-label="Transition">
          <button type="button" role="radio" :aria-checked="type === 'none'" :class="['nx-tr-tile', type === 'none' ? 'on' : '']" @click="pick('none')">
            <span class="nx-tr-none" :style="tileStyle">Cut</span>
            <span>None</span>
          </button>
          <button v-for="t in TRANSITIONS" :key="t.key" type="button" role="radio" :aria-checked="type === t.key" :class="['nx-tr-tile', type === t.key ? 'on' : '']"
            @click="pick(t.key)" @mouseenter="hover(t.key)" @mouseleave="unhover(t.key)" @focus="hover(t.key)" @blur="unhover(t.key)">
            <canvas :ref="(el) => { if (el) tileRefs[t.key] = el; }" :width="dims(120)[0]" :height="dims(120)[1]" :style="tileStyle" aria-hidden="true"></canvas>
            <span>{{ t.label }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.nx-tr { max-width: 860px; }
.nx-tr-body { display: grid; grid-template-columns: 220px 1fr; gap: 18px; min-height: 0; }
.nx-tr-side { display: flex; flex-direction: column; gap: 10px; }
.nx-tr-big { width: 100%; max-height: 340px; object-fit: contain; border-radius: 10px; background: #000; }
.nx-tr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 10px; max-height: calc(100dvh - 220px); overflow-y: auto; padding-right: 4px; align-content: start; }
.nx-tr-tile { display: flex; flex-direction: column; gap: 6px; padding: 6px; border: 1px solid transparent; border-radius: 10px; background: var(--field); color: var(--text-2); font-size: 12px; cursor: pointer; text-align: center; }
.nx-tr-tile canvas, .nx-tr-none { width: 100%; max-height: 120px; object-fit: contain; border-radius: 7px; background: #000; display: block; }
.nx-tr-none { display: flex; align-items: center; justify-content: center; color: var(--muted); font-weight: 600; }
.nx-tr-tile:hover { color: var(--text); }
.nx-tr-tile.on { border-color: var(--accent); color: var(--text); }
</style>
