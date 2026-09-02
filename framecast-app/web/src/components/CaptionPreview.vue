<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { animationByKey, captionLineAt, effectiveChunk } from "../composables/captionPresets";

const props = defineProps({
  // timed words: [{ text, start, end }] — real Whisper timings or synthetic
  words: { type: Array, default: () => [] },
  seconds: { type: Number, default: 0 },
  settings: { type: Object, default: () => ({}) },
  // demo tiles (modal grid): self-clocked 2-word loop, ignores words/seconds
  demo: { type: Boolean, default: false },
});

const DEMO_WORDS = [
  { text: "Brand", start: 0.15, end: 0.85 },
  { text: "videos", start: 1.0, end: 1.7 },
];
const DEMO_LOOP = 2.4;

const demoClock = ref(0);
let rafId = null;
onMounted(() => {
  if (!props.demo) return;
  const step = (t) => {
    demoClock.value = (t / 1000) % DEMO_LOOP;
    rafId = requestAnimationFrame(step);
  };
  rafId = requestAnimationFrame(step);
});
onBeforeUnmount(() => {
  if (rafId) cancelAnimationFrame(rafId);
});

const preset = computed(() => animationByKey(props.settings.animation || "plain"));
const isPanelPreset = computed(() => preset.value.panel);
const isTypewriter = computed(() => preset.value.key === "stream" || preset.value.key === "news");

const chunkSize = computed(() => {
  if (props.demo) return DEMO_WORDS.length;
  return effectiveChunk(preset.value, props.settings.highlight_mode);
});

const line = computed(() => {
  const words = props.demo ? DEMO_WORDS : props.words;
  const seconds = props.demo ? demoClock.value : props.seconds;
  return captionLineAt(words, seconds, chunkSize.value);
});

const highlightStyle = computed(() => props.settings.highlight_style || "color");
const highlightColor = computed(() =>
  highlightStyle.value === "plain"
    ? props.settings.color || "#ffffff"
    : props.settings.highlight_color || "#ff6b35"
);

// Backdrop semantics (one switch everywhere): panel presets carry a panel by
// design — backdrop=false strips it; on other presets backdrop=true adds one.
const backdropOn = computed(() =>
  props.settings.backdrop === undefined ? isPanelPreset.value : props.settings.backdrop !== false
);

const rootClass = computed(() => [
  "cp",
  `cp-${preset.value.key}`,
  {
    "cp-multi": (line.value?.length || 0) > 1,
    "cp-underline": highlightStyle.value === "underline",
    "cp-backdrop": backdropOn.value && !isPanelPreset.value,
    "cp-nopanel": isPanelPreset.value && !backdropOn.value,
  },
]);

const rootStyle = computed(() => {
  const style = { "--cp-hl": highlightColor.value };
  const color = props.settings.color || "#ffffff";
  const defaultWhite = ["#fff", "#ffffff"].includes(color.toLowerCase());
  // News Bar defaults to dark text on its light bar; only override it when
  // the user picked a non-default text color (or removed the bar).
  if (!(preset.value.key === "news" && backdropOn.value && defaultWhite)) {
    style.color = color;
  }
  if (props.settings.panel_color) style["--cp-bg"] = props.settings.panel_color;
  // Demo tiles have no user font — show the preset in its own typeface, like
  // the CapCut picker. The main preview inherits the font from the wrapper.
  if (props.demo && preset.value.font) {
    style.fontFamily = `"${preset.value.font}", sans-serif`;
  }
  return style;
});

function chars(word) {
  return Array.from(word.text);
}

function charOn(word, index) {
  if (word.state === "spoken") return true;
  if (word.state !== "active") return false;
  return index < Math.round(word.frac * word.text.length);
}
</script>

<template>
  <div v-if="line" :class="rootClass" :style="rootStyle">
    <span
      v-for="(word, index) in line"
      :key="index"
      :class="`cp-w cp-${word.state}`"
    >
      <template v-if="isTypewriter">
        <!-- Real space, inside the word span so it appears and hides with the
             word — a margin can't match the font's space advance (0.6em in
             Roboto Mono), which made typed words look jammed together. -->
        <span v-if="index > 0" class="cp-ch cp-on">&nbsp;</span>
        <span
          v-for="(ch, ci) in chars(word)"
          :key="ci"
          :class="['cp-ch', charOn(word, ci) ? 'cp-on' : '']"
        >{{ ch }}</span>
      </template>
      <template v-else>{{ word.text }}</template>
    </span>
    <span v-if="isTypewriter" class="cp-caret"></span>
  </div>
</template>

<style scoped>
.cp { line-height: 1.18; display: inline-block; max-width: 100%; }
.cp .cp-w { display: inline-block; margin: 0 0.13em; }

/* plain — today's captions exactly: no motion, static highlight */
.cp-plain .cp-active { color: var(--cp-hl); }

/* beast — one giant word, spring pop */
.cp-beast { font-weight: 900; font-size: 2.5em; text-transform: uppercase;
  -webkit-text-stroke: 0.035em #000; paint-order: stroke fill; text-shadow: 0 0.08em 0 rgba(0, 0, 0, 0.55); }
.cp-beast .cp-w { display: none; }
.cp-beast .cp-active { display: inline-block; animation: cp-pop 0.18s cubic-bezier(0.2, 1.6, 0.4, 1) both; }
@keyframes cp-pop { from { transform: scale(0.35); opacity: 0; } to { transform: scale(1); opacity: 1; } }

/* comic — bubble bounce with tilt */
.cp-comic { font-size: 2.1em; text-transform: uppercase; letter-spacing: 0.02em;
  -webkit-text-stroke: 0.045em #000; paint-order: stroke fill; text-shadow: 0.06em 0.09em 0 #000; }
.cp-comic .cp-w { display: none; }
.cp-comic .cp-active { display: inline-block; animation: cp-comic-in 0.22s cubic-bezier(0.2, 2.2, 0.5, 1) both; }
.cp-comic .cp-w:nth-child(odd).cp-active { rotate: -3deg; }
.cp-comic .cp-w:nth-child(even).cp-active { rotate: 2.5deg; }
@keyframes cp-comic-in { 0% { transform: scale(0.2) rotate(-14deg); opacity: 0; } 70% { transform: scale(1.18); } 100% { transform: scale(1); opacity: 1; } }

/* sticker — tilted line, words scale in, active wiggles */
.cp-sticker { font-size: 1.7em; text-transform: uppercase; transform: rotate(-2deg); letter-spacing: 0.015em;
  -webkit-text-stroke: 0.07em #000; paint-order: stroke fill; text-shadow: 0.05em 0.07em 0 rgba(0, 0, 0, 0.85); }
.cp-sticker .cp-unspoken { opacity: 0; transform: scale(0.4); }
.cp-sticker .cp-spoken, .cp-sticker .cp-active { opacity: 1; transform: scale(1);
  transition: transform 0.16s cubic-bezier(0.2, 1.8, 0.4, 1), opacity 0.1s; }
.cp-sticker .cp-active { animation: cp-wiggle 0.3s ease both; color: var(--cp-hl); }
@keyframes cp-wiggle { 0% { rotate: 0deg; } 30% { rotate: 4deg; } 60% { rotate: -3deg; } 100% { rotate: 0deg; } }

/* karaoke — dim line, spoken words light up */
.cp-karaoke { font-weight: 800; font-style: italic; font-size: 1.5em; text-transform: uppercase;
  -webkit-text-stroke: 0.03em #000; paint-order: stroke fill; text-shadow: 0 0.1em 0.18em rgba(0, 0, 0, 0.7); }
.cp-karaoke .cp-w { opacity: 0.38; transition: opacity 0.08s; }
.cp-karaoke .cp-spoken { opacity: 1; }
.cp-karaoke .cp-active { opacity: 1; color: var(--cp-hl); transform: scale(1.08); transition: transform 0.1s, opacity 0.08s; }

/* box — pill behind the active word */
.cp-box { font-weight: 800; font-size: 1.45em; text-shadow: 0 0.09em 0.15em rgba(0, 0, 0, 0.75); }
.cp-box .cp-w { padding: 0.04em 0.18em; border-radius: 0.28em; transition: background 0.09s, color 0.09s, transform 0.12s; }
.cp-box .cp-active { background: var(--cp-hl); color: #111; transform: scale(1.07); text-shadow: none; }

/* stream — typewriter console; panel grows, caret rides last typed char */
.cp-stream { font-size: 1.15em; background: var(--cp-bg, rgba(0, 0, 0, 0.62));
  padding: 0.5em 0.7em; border-radius: 0.5em; text-align: center; }
.cp-stream .cp-w { display: none; margin: 0; }
.cp-stream .cp-active, .cp-stream .cp-spoken { display: inline-block; }
.cp-stream .cp-ch { display: none; }
.cp-stream .cp-ch.cp-on { display: inline; }
.cp-caret { display: inline-block; width: 0.5em; height: 1.05em; background: var(--cp-hl);
  vertical-align: text-bottom; animation: cp-blink 1s steps(1) infinite; margin-left: 0.08em; }
@keyframes cp-blink { 50% { opacity: 0; } }

/* blur — words de-blur as spoken */
.cp-blur { font-size: 1.6em; text-transform: uppercase; letter-spacing: 0.03em; text-shadow: 0 0.1em 0.2em rgba(0, 0, 0, 0.7); }
.cp-blur .cp-unspoken { opacity: 0; filter: blur(0.22em); transform: scale(1.15); }
.cp-blur .cp-spoken, .cp-blur .cp-active { opacity: 1; filter: blur(0); transform: scale(1);
  transition: filter 0.28s ease, opacity 0.22s, transform 0.28s; }
.cp-blur .cp-active { color: var(--cp-hl); }

/* glitch — RGB-split flicker per word */
.cp-glitch { font-size: 2.1em; letter-spacing: 0.04em; text-shadow: 0 0.07em 0 rgba(0, 0, 0, 0.6); }
.cp-glitch .cp-w { display: none; }
.cp-glitch .cp-active { display: inline-block; transform-origin: center center; animation: cp-glitch 0.3s steps(2) both; }
@keyframes cp-glitch {
  0% { opacity: 0.4; transform: translate(-0.08em, 0.04em) skewX(12deg); text-shadow: -0.06em 0 0 #0ff, 0.06em 0 0 #f0f; }
  35% { opacity: 1; transform: translate(0.05em, -0.03em) skewX(-8deg); text-shadow: 0.05em 0 0 #0ff, -0.05em 0 0 #f0f; }
  70% { transform: translate(-0.02em, 0.01em); text-shadow: -0.02em 0 0 #0ff, 0.02em 0 0 #f0f; }
  100% { transform: none; text-shadow: 0 0.07em 0 rgba(0, 0, 0, 0.6); }
}

/* slide — words rise into place and stay */
.cp-slide { font-weight: 700; font-size: 1.4em; text-shadow: 0 0.1em 0.2em rgba(0, 0, 0, 0.8); }
.cp-slide .cp-unspoken { opacity: 0; transform: translateY(0.9em); }
.cp-slide .cp-spoken, .cp-slide .cp-active { opacity: 1; transform: translateY(0);
  transition: transform 0.22s cubic-bezier(0.2, 1, 0.3, 1), opacity 0.18s; }
.cp-slide .cp-active { color: var(--cp-hl); }

/* wave — spoken word hops */
.cp-wave { font-size: 1.5em; -webkit-text-stroke: 0.04em #000; paint-order: stroke fill; text-shadow: 0 0.08em 0 rgba(0, 0, 0, 0.6); }
.cp-wave .cp-w { transition: color 0.1s; }
.cp-wave .cp-active { color: var(--cp-hl); animation: cp-bounce 0.32s ease both; }
@keyframes cp-bounce { 0% { transform: translateY(0); } 40% { transform: translateY(-0.32em) scale(1.12); } 100% { transform: translateY(0) scale(1); } }

/* punch — heavy italic, active word scales hard */
.cp-punch { font-weight: 900; font-style: italic; font-size: 1.55em; text-transform: uppercase;
  text-shadow: 0.1em 0.12em 0 #000; letter-spacing: 0.01em; }
.cp-punch .cp-w { opacity: 0.3; transform: skewX(-4deg); transition: all 0.09s; }
.cp-punch .cp-spoken { opacity: 1; }
.cp-punch .cp-active { opacity: 1; color: var(--cp-hl); transform: skewX(-4deg) scale(1.22); }

/* tracking — spaced caps expand open per line */
.cp-tracking { font-size: 1.35em; letter-spacing: 0.35em; text-shadow: 0 0.08em 0.18em rgba(0, 0, 0, 0.8); animation: cp-track-in 0.5s ease both; }
.cp-tracking .cp-w { opacity: 0.45; transition: opacity 0.09s; }
.cp-tracking .cp-spoken, .cp-tracking .cp-active { opacity: 1; }
.cp-tracking .cp-active { color: var(--cp-hl); }
@keyframes cp-track-in { from { letter-spacing: 0.05em; opacity: 0; } to { letter-spacing: 0.35em; opacity: 1; } }

/* neon — glow in highlight color, tube flicker */
.cp-neon { font-weight: 700; font-size: 1.3em; text-transform: uppercase; letter-spacing: 0.06em;
  text-shadow: 0 0 0.22em var(--cp-hl), 0 0 0.55em var(--cp-hl), 0 0 1em var(--cp-hl); }
.cp-neon .cp-w { opacity: 0.3; transition: opacity 0.12s; }
.cp-neon .cp-spoken { opacity: 0.85; }
.cp-neon .cp-active { opacity: 1; animation: cp-neon-flick 0.26s steps(3) both; }
@keyframes cp-neon-flick { 0% { opacity: 0.15; } 40% { opacity: 1; } 60% { opacity: 0.45; } 100% { opacity: 1; } }

/* news — lower-third bar grows word by word */
.cp-news { font-weight: 800; font-size: 0.95em; background: var(--cp-bg, #fff); color: #111;
  padding: 0.5em 0.75em; border-radius: 0.22em; box-shadow: 0 0.35em 1.2em rgba(0, 0, 0, 0.45); text-align: left; }
.cp-news .cp-w { display: none; margin: 0; }
.cp-news .cp-spoken, .cp-news .cp-active { display: inline-block; }
.cp-news .cp-active { background: var(--cp-hl); border-radius: 0.15em; padding: 0 0.12em; }
.cp-news .cp-caret { background: currentColor; width: 0.45em; height: 0.95em; }

/* marker — handwritten stamp-in */
.cp-marker { font-size: 1.45em; rotate: -2deg; text-shadow: 0.04em 0.06em 0 rgba(0, 0, 0, 0.6); }
.cp-marker .cp-unspoken { opacity: 0; transform: rotate(4deg) scale(1.35); }
.cp-marker .cp-spoken, .cp-marker .cp-active { opacity: 1; transform: none; transition: all 0.18s ease-out; }
.cp-marker .cp-active { color: var(--cp-hl); }

/* one-word presets in line-by-line mode: show the whole line dimmed, the
   active word still pops with its motion */
.cp-beast.cp-multi .cp-w, .cp-comic.cp-multi .cp-w, .cp-glitch.cp-multi .cp-w {
  display: inline-block;
  opacity: 0.35;
}
.cp-beast.cp-multi .cp-spoken, .cp-comic.cp-multi .cp-spoken, .cp-glitch.cp-multi .cp-spoken {
  opacity: 1;
}
.cp-beast.cp-multi .cp-active, .cp-comic.cp-multi .cp-active, .cp-glitch.cp-multi .cp-active {
  opacity: 1;
  color: var(--cp-hl);
}
.cp-beast.cp-multi, .cp-comic.cp-multi, .cp-glitch.cp-multi { font-size: 1.4em; }

/* user overrides */
.cp-underline .cp-active { text-decoration: underline; text-decoration-thickness: 0.07em; text-underline-offset: 0.14em; }
.cp-backdrop { background: var(--cp-bg, rgba(0, 0, 0, 0.58)); padding: 0.35em 0.55em; border-radius: 0.45em; }
.cp-nopanel { background: transparent !important; box-shadow: none !important; padding: 0 !important; }
.cp-news.cp-nopanel { color: #fff; text-shadow: 0 0.08em 0.15em rgba(0, 0, 0, 0.75); }
</style>
