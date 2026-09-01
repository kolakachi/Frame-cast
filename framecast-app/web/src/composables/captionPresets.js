// Caption animation presets. `animation` is a new optional key in
// caption_settings_json — absent means "plain" (today's static captions), so
// every existing project renders unchanged. Applying a preset also writes its
// default font into caption_settings.font (visible in the font picker, user
// can change it afterwards); every other user setting layers on top.
//
// The same registry drives the editor panel row, the View-all modal and the
// live preview overlay. Export-side twins live in RendersExportScenes.php.

export const CAPTION_ANIMATIONS = [
  {
    key: "plain",
    name: "Plain",
    badge: "Classic",
    font: null, // keep whatever the user has
    chunk: 4,
    panel: false,
    desc: "No animation — full line, spoken word changes color.",
  },
  {
    key: "beast",
    name: "Beast",
    badge: "Hot",
    font: "Montserrat",
    chunk: 1,
    panel: false,
    desc: "One giant word at a time with a spring pop-in.",
  },
  {
    key: "comic",
    name: "Comic Pop",
    badge: "",
    font: "Luckiest Guy",
    chunk: 1,
    panel: false,
    desc: "Bubble letters bounce in with a playful tilt.",
  },
  {
    key: "sticker",
    name: "Sticker",
    badge: "",
    font: "Passion One",
    chunk: 3,
    panel: false,
    desc: "Tilted sticker line; words scale in as spoken.",
  },
  {
    key: "karaoke",
    name: "Karaoke",
    badge: "Hot",
    font: "Montserrat",
    chunk: 4,
    panel: false,
    desc: "Line stays readable; each word lights up as it's spoken.",
  },
  {
    key: "box",
    name: "Box",
    badge: "",
    font: "Nunito",
    chunk: 4,
    panel: false,
    desc: "A colored pill jumps word-to-word behind the speech.",
  },
  {
    key: "stream",
    name: "Stream",
    badge: "New",
    font: "Roboto Mono",
    chunk: 8,
    panel: true,
    desc: "Characters type out in sync with the voice, caret and all.",
  },
  {
    key: "blur",
    name: "Blur In",
    badge: "",
    font: "Days One",
    chunk: 3,
    panel: false,
    desc: "Words materialize from a soft blur. Cinematic.",
  },
  {
    key: "glitch",
    name: "Glitch",
    badge: "",
    font: "Bebas Neue",
    chunk: 1,
    panel: false,
    desc: "RGB-split flicker on each word. Gaming / tech energy.",
  },
  {
    key: "slide",
    name: "Slide Up",
    badge: "",
    font: "Montserrat",
    chunk: 4,
    panel: false,
    desc: "Words rise into place as spoken and stay.",
  },
  {
    key: "wave",
    name: "Wave",
    badge: "",
    font: "Fredoka One",
    chunk: 4,
    panel: false,
    desc: "The spoken word hops with a squash-and-stretch bounce.",
  },
  {
    key: "punch",
    name: "Punch",
    badge: "Hot",
    font: "Montserrat",
    chunk: 3,
    panel: false,
    desc: "Heavy italic caps; the spoken word scales up hard.",
  },
  {
    key: "tracking",
    name: "Tracking",
    badge: "",
    font: "Bebas Neue",
    chunk: 4,
    panel: false,
    desc: "Wide-spaced caps expand open; words brighten as spoken.",
  },
  {
    key: "neon",
    name: "Neon",
    badge: "New",
    font: "Orbitron",
    chunk: 3,
    panel: false,
    desc: "Glow in the highlight color; words flicker on like neon.",
  },
  {
    key: "news",
    name: "News Bar",
    badge: "New",
    font: "Nunito",
    chunk: 5,
    panel: true,
    desc: "Lower-third label; words type on with a marker sweep.",
  },
  {
    key: "marker",
    name: "Marker",
    badge: "New",
    font: "Permanent Marker",
    chunk: 3,
    panel: false,
    desc: "Handwritten words stamp in with a little settle.",
  },
];

// Panel row: Plain ALWAYS first, current selection second (when not plain),
// then these, then "View all".
export const CURATED_ANIMATION_KEYS = ["beast", "karaoke", "punch", "box", "stream"];

export function animationByKey(key) {
  return CAPTION_ANIMATIONS.find((p) => p.key === key) || CAPTION_ANIMATIONS[0];
}

// Highlight mode semantics on top of a preset:
//   word_by_word  -> one word on screen, whatever the preset
//   line_by_line  -> full lines even for one-word presets (they show 4-word
//                    lines; the active word still carries the motion)
//   keywords      -> the preset's natural default
export function effectiveChunk(preset, highlightMode) {
  const mode = highlightMode || "keywords";
  if (mode === "word_by_word") return 1;
  if (mode === "line_by_line") return preset.chunk > 1 ? preset.chunk : 4;
  return preset.chunk;
}

export function panelRowAnimations(currentKey) {
  const row = [CAPTION_ANIMATIONS[0]];
  const current = animationByKey(currentKey);
  if (current.key !== "plain") row.push(current);
  for (const key of CURATED_ANIMATION_KEYS) {
    if (key !== current.key && row.length < 7) row.push(animationByKey(key));
  }
  return row;
}

// ---------------------------------------------------------------------------
// Word-state engine. Given timed words + the playhead, returns the currently
// visible line with a state per word ("unspoken" | "active" | "spoken") and,
// for typewriter presets, the fraction of the active word already typed.
// A line hides the moment the next line starts (no double captions).
// ---------------------------------------------------------------------------
export function captionLineAt(timedWords, seconds, chunkSize) {
  const size = Math.max(1, chunkSize || 4);
  if (!Array.isArray(timedWords) || timedWords.length === 0) return null;

  const lines = [];
  for (let i = 0; i < timedWords.length; i += size) {
    lines.push(timedWords.slice(i, i + size));
  }

  for (let li = 0; li < lines.length; li += 1) {
    const line = lines[li];
    const start = line[0].start;
    const end = line[line.length - 1].end;
    const next = lines[li + 1];
    const hideAt = next ? Math.min(end + 0.35, next[0].start) : end + 0.35;
    if (seconds < start - 0.05 || seconds >= hideAt) continue;

    return line.map((word) => {
      let state = "unspoken";
      let frac = 0;
      if (seconds >= word.end) {
        state = "spoken";
        frac = 1;
      } else if (seconds >= word.start) {
        state = "active";
        frac = Math.min(1, (seconds - word.start) / Math.max(0.03, word.end - word.start));
      }
      return { text: word.text, state, frac };
    });
  }
  return null;
}

// Fallback when a scene's audio has no word timestamps: spread the script
// evenly across the scene duration (same fallback the export builder uses).
export function syntheticTimedWords(text, durationSeconds) {
  const words = String(text || "").trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return [];
  const duration = Math.max(1, Number(durationSeconds) || words.length * 0.35);
  const per = duration / words.length;
  return words.map((word, i) => ({ text: word, start: i * per, end: (i + 1) * per }));
}
