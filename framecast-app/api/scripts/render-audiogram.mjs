import fs from "node:fs/promises";
import path from "node:path";
import puppeteer from "puppeteer-core";

const [, , payloadPath, framesDir] = process.argv;

if (!payloadPath || !framesDir) {
  console.error("Usage: node scripts/render-audiogram.mjs <payload.json> <frames-dir>");
  process.exit(1);
}

const payload = JSON.parse(await fs.readFile(payloadPath, "utf8"));
await fs.mkdir(framesDir, { recursive: true });

const fps = Math.max(12, Number(payload.fps || 20));
const duration = Math.max(0.1, Number(payload.duration || 3));
const width = Math.max(270, Number(payload.width || 1080));
const height = Math.max(480, Number(payload.height || 1920));
const style = normalizeStyle(payload.style || "bars");
// Always 14 bars — matches editor's waveformLive ref length exactly
const BAR_COUNT = 14;
// AnalyserNode settings — must match EditorView.vue exactly
const FFT_SIZE = 128;
const FFT_SMOOTHING = 0.82;
const MIN_DB = -100;
const MAX_DB = -30;
const sampleRate = Math.max(8000, Number(payload.sampleRate || 16000));
const analysisOffsetSeconds = Math.max(0, Number(payload.analysisOffsetSeconds || 0));
const pcm = payload.pcmPath ? await readPcmFloat32(payload.pcmPath) : new Float32Array(0);
const totalFrames = Math.max(1, Math.ceil(duration * fps));
const bandFrames = buildBandFrames({
  pcm,
  duration,
  fps,
  sampleRate,
  style,
  analysisOffsetSeconds,
});

const browser = await puppeteer.launch({
  executablePath: process.env.PUPPETEER_EXECUTABLE_PATH || "/usr/bin/chromium",
  headless: true,
  args: [
    "--no-sandbox",
    "--disable-setuid-sandbox",
    "--disable-dev-shm-usage",
    "--mute-audio",
  ],
});

try {
  const page = await browser.newPage();
  await page.setViewport({
    width,
    height,
    deviceScaleFactor: 1,
  });

  await page.setContent(buildHtml(), { waitUntil: "load" });
  await page.evaluate((initialPayload) => {
    window.initializeRenderer(initialPayload);
  }, buildInitialState(payload));
  await page.evaluate(async () => {
    if (document.fonts?.ready) {
      await document.fonts.ready;
    }
  });

  for (let frameIndex = 0; frameIndex < totalFrames; frameIndex += 1) {
    const localSeconds = Math.min(duration, frameIndex / fps);
    const bars = bandFrames[Math.min(frameIndex, bandFrames.length - 1)];
    const captionWords = buildCaptionWords(
      payload.captionText || "",
      payload.captionHighlightMode || "keywords",
      localSeconds,
      duration,
      payload.timedWords || [],
    );
    const captionAnimWords = (payload.captionAnimation || "plain") !== "plain"
      ? buildAnimatedCaptionLine(payload, localSeconds, duration)
      : null;

    await page.evaluate((frame) => {
      window.renderFrame(frame);
    }, {
      bars,
      localSeconds,
      captionWords,
      captionAnimWords,
    });

    await page.screenshot({
      path: path.join(framesDir, `frame-${String(frameIndex).padStart(6, "0")}.png`),
      type: "png",
      omitBackground: false,
    });
  }
} finally {
  await browser.close();
}

async function readPcmFloat32(pcmPath) {
  const buffer = await fs.readFile(pcmPath);
  const bytes = buffer.buffer.slice(
    buffer.byteOffset,
    buffer.byteOffset + buffer.byteLength,
  );

  return new Float32Array(bytes);
}

// ─── Frequency analysis matching editor's Web Audio API exactly ───────────────
// Editor: AnalyserNode fftSize=128, smoothingTimeConstant=0.82,
//         minDecibels=-100, maxDecibels=-30
//         usableBins = Math.max(14, Math.floor(data.length * 0.8))
//         each bar averages its small frequency range and maps avg / 180
// We replicate that with a 128-pt FFT + identical smoothing + the same bar mapping.

function buildBandFrames({ pcm, duration, fps, sampleRate, style, analysisOffsetSeconds = 0 }) {
  const totalFrames = Math.max(1, Math.ceil(duration * fps));
  // Persistent smoothed FFT magnitude across frames (like AnalyserNode)
  let smoothedMag = new Float32Array(FFT_SIZE / 2);
  // Per-frame lerp state (matches editor's 28% per rAF)
  let previous = new Array(BAR_COUNT).fill(0.04);
  const smoothedFrames = [];

  for (let frameIndex = 0; frameIndex < totalFrames; frameIndex += 1) {
    const currentSeconds = Math.min(duration, frameIndex / fps);
    let bars;

    if (pcm.length > 0) {
      const analysisSeconds = currentSeconds + analysisOffsetSeconds;
      const rawMag = fftMagnitude(pcm, sampleRate, analysisSeconds, FFT_SIZE);
      // Apply AnalyserNode smoothing
      for (let i = 0; i < smoothedMag.length; i += 1) {
        smoothedMag[i] = FFT_SMOOTHING * smoothedMag[i] + (1 - FFT_SMOOTHING) * rawMag[i];
      }
      // Convert to byte data and map bins — exact editor formula
      const byteData = magnitudeToByteData(smoothedMag, FFT_SIZE);
      const usableBins = Math.max(BAR_COUNT, Math.floor(byteData.length * 0.8));
      const binsPerBar = Math.max(1, Math.floor(usableBins / BAR_COUNT));
      bars = Array.from({ length: BAR_COUNT }, (_, i) => {
        const start = i * binsPerBar;
        const end = i === BAR_COUNT - 1 ? usableBins : Math.min(usableBins, start + binsPerBar);
        let total = 0;

        for (let bin = start; bin < end; bin += 1) {
          total += byteData[bin] ?? 0;
        }

        const avg = total / Math.max(1, end - start);
        return clamp(avg / 180, 0.04, 1);
      });
    } else {
      bars = simulatedBars(currentSeconds, BAR_COUNT, style);
    }

    // 28% lerp — matches editor's tickWaveform
    previous = previous.map((cur, i) => clamp(cur + (bars[i] - cur) * 0.28, 0.04, 1));
    smoothedFrames.push([...previous]);
  }

  return smoothedFrames;
}

function normalizeStyle(rawStyle) {
  const style = String(rawStyle || "bars").trim().toLowerCase();

  if (style === "radial") {
    return "circle";
  }

  if (["bars", "mirror", "circle", "minimal"].includes(style)) {
    return style;
  }

  return "bars";
}

// 64-point FFT with Blackman window (same window the Web Audio API uses)
function fftMagnitude(pcm, sampleRate, currentSeconds, fftSize) {
  const center = Math.floor(currentSeconds * sampleRate);
  const start = Math.max(0, center - Math.floor(fftSize / 2));
  const real = new Array(fftSize).fill(0);
  const imag = new Array(fftSize).fill(0);

  for (let i = 0; i < fftSize; i += 1) {
    const idx = clamp(start + i, 0, pcm.length - 1);
    const t = i / (fftSize - 1);
    // Blackman window — same as Web Audio API AnalyserNode
    const win = 0.42 - 0.5 * Math.cos(2 * Math.PI * t) + 0.08 * Math.cos(4 * Math.PI * t);
    real[i] = (pcm[idx] || 0) * win;
  }

  // Bit-reversal permutation
  for (let i = 1, j = 0; i < fftSize; i += 1) {
    let bit = fftSize >> 1;
    for (; j & bit; bit >>= 1) j ^= bit;
    j ^= bit;
    if (i < j) {
      [real[i], real[j]] = [real[j], real[i]];
    }
  }

  // Cooley-Tukey butterfly
  for (let len = 2; len <= fftSize; len <<= 1) {
    const angle = -2 * Math.PI / len;
    const wR = Math.cos(angle);
    const wI = Math.sin(angle);
    for (let i = 0; i < fftSize; i += len) {
      let uR = 1; let uI = 0;
      const half = len >> 1;
      for (let k = 0; k < half; k += 1) {
        const vR = real[i + k + half] * uR - imag[i + k + half] * uI;
        const vI = real[i + k + half] * uI + imag[i + k + half] * uR;
        real[i + k + half] = real[i + k] - vR;
        imag[i + k + half] = imag[i + k] - vI;
        real[i + k] += vR;
        imag[i + k] += vI;
        const t = uR * wR - uI * wI;
        uI = uR * wI + uI * wR;
        uR = t;
      }
    }
  }

  const mag = new Float32Array(fftSize / 2);
  for (let i = 0; i < fftSize / 2; i += 1) {
    mag[i] = Math.sqrt(real[i] * real[i] + imag[i] * imag[i]);
  }
  return mag;
}

// Convert FFT magnitude to AnalyserNode.getByteFrequencyData() byte values
function magnitudeToByteData(mag, fftSize) {
  const out = new Uint8Array(mag.length);
  for (let i = 0; i < mag.length; i += 1) {
    const db = mag[i] > 0 ? 20 * Math.log10(mag[i] / fftSize) : -Infinity;
    const normalized = (db - MIN_DB) / (MAX_DB - MIN_DB);
    out[i] = Math.max(0, Math.min(255, Math.round(normalized * 255)));
  }
  return out;
}

function simulatedBars(currentSeconds, count, style) {
  return Array.from({ length: count }, (_, index) => {
    const pos = index / Math.max(1, count - 1);
    const envelope = Math.max(0.15, 1 - Math.abs(pos - 0.3) * 1.3);
    const wobble = 0.55 + 0.45 * Math.sin(currentSeconds * 7.2 + index * 0.9);
    const micro = 0.65 + 0.35 * Math.sin(currentSeconds * 13.5 + index * 1.8);
    const styleBias = style === "minimal" ? 0.8 : style === "mirror" ? 0.92 : 1;
    return envelope * wobble * micro * styleBias;
  });
}

// ─── Animated caption presets (twin of CaptionPreview.vue) ────────────────
// Frames are screenshotted out of real time, so nothing may depend on wall
// clocks. Every word carries tRel (seconds since activation); most presets
// compute their easing directly, while Glitch pauses the editor's exact CSS
// animation and seeks it to tRel with a negative delay.
const ANIM_CHUNK = { beast: 1, comic: 1, glitch: 1, sticker: 3, blur: 3, punch: 3, neon: 3, marker: 3, stream: 8, news: 5 };
const ANIM_UPPER = ["beast", "comic", "sticker", "karaoke", "blur", "punch", "tracking", "neon"];

function buildAnimatedCaptionLine(payload, seconds, duration) {
  const animation = payload.captionAnimation || "plain";
  let words = (Array.isArray(payload.timedWords) ? payload.timedWords : [])
    .map((w) => ({
      text: String(w?.text || w?.word || "").trim(),
      start: Number(w?.start),
      end: Number(w?.end),
    }))
    .filter((w) => w.text && Number.isFinite(w.start) && Number.isFinite(w.end) && w.end > w.start)
    .sort((a, b) => a.start - b.start);

  if (words.length === 0) {
    const plain = String(payload.captionText || "").trim().split(/\s+/).filter(Boolean);
    const per = plain.length ? duration / plain.length : 0;
    words = plain.map((text, i) => ({ text, start: i * per, end: (i + 1) * per }));
  }
  if (ANIM_UPPER.includes(animation)) {
    words = words.map((w) => ({ ...w, text: w.text.toUpperCase() }));
  }

  // word_by_word = one word for every preset; line_by_line = full lines even
  // for one-word presets; keywords = the preset's natural default.
  let chunk = ANIM_CHUNK[animation] || 4;
  const mode = payload.captionHighlightMode || "keywords";
  if (mode === "word_by_word") chunk = 1;
  else if (mode === "line_by_line" && chunk === 1) chunk = 4;

  const lines = [];
  for (let i = 0; i < words.length; i += chunk) lines.push(words.slice(i, i + chunk));

  for (let li = 0; li < lines.length; li += 1) {
    const line = lines[li];
    const start = line[0].start;
    const end = line[line.length - 1].end;
    const next = lines[li + 1];
    const hideAt = next ? Math.min(end + 0.35, next[0].start) : end + 0.35;
    if (seconds < start - 0.05 || seconds >= hideAt) continue;

    return line.map((w) => {
      let state = "unspoken";
      let frac = 0;
      let tRel = 0;
      if (seconds >= w.end) {
        state = "spoken";
        frac = 1;
        tRel = seconds - w.start;
      } else if (seconds >= w.start) {
        state = "active";
        tRel = seconds - w.start;
        frac = Math.min(1, tRel / Math.max(0.03, w.end - w.start));
      }
      return { text: w.text, state, frac, tRel };
    });
  }

  return [];
}

function buildCaptionWords(text, highlightMode, currentSeconds, duration, timedWords) {
  const mode = highlightMode || "keywords";
  const pct = clamp(duration > 0 ? currentSeconds / duration : 0, 0, 1);

  if (mode === "none") {
    return [];
  }

  if (
    Array.isArray(timedWords) &&
    timedWords.length > 0 &&
    (mode === "word_by_word" || mode === "line_by_line")
  ) {
    return previewTimedWords(timedWords, mode, currentSeconds);
  }

  const words = String(text || "")
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (words.length === 0) {
    return [];
  }

  if (mode === "word_by_word") {
    const index = Math.min(Math.floor(pct * words.length), words.length - 1);
    return [{ text: words[index], highlighted: true }];
  }

  if (mode === "line_by_line") {
    const wordsPerLine = 4;
    const lines = [];
    for (let index = 0; index < words.length; index += wordsPerLine) {
      lines.push(words.slice(index, index + wordsPerLine));
    }

    const wordIndex = Math.min(Math.floor(pct * words.length), words.length - 1);
    const lineIndex = Math.min(Math.floor(wordIndex / wordsPerLine), lines.length - 1);
    const lineWords = lines[lineIndex];
    const highlightStart = Math.min(1, lineWords.length - 1);
    const highlightEnd = Math.min(lineWords.length, highlightStart + 2);

    return lineWords.map((word, index) => ({
      text: index === lineWords.length - 1 ? word : `${word} `,
      highlighted: index >= highlightStart && index < highlightEnd,
    }));
  }

  const highlightStart = Math.min(1, words.length - 1);
  const highlightEnd = Math.min(words.length, highlightStart + 2);
  return words.map((word, index) => ({
    text: `${word}${index === words.length - 1 ? "" : " "}`,
    highlighted: index >= highlightStart && index < highlightEnd,
  }));
}

function previewTimedWords(timedWords, mode, currentSeconds) {
  const activeWordIndex = timedWords.findIndex(
    (word) => currentSeconds >= Number(word.start) && currentSeconds < Number(word.end),
  );
  const fallbackIndex = timedWords.reduce((found, word, index) => {
    if (Number(word.start) <= currentSeconds) {
      return index;
    }
    return found;
  }, 0);
  const activeIndex = activeWordIndex >= 0 ? activeWordIndex : fallbackIndex;

  if (mode === "word_by_word") {
    return [{ text: timedWords[activeIndex]?.text || "", highlighted: true }];
  }

  const wordsPerLine = 4;
  const lineStart = Math.floor(activeIndex / wordsPerLine) * wordsPerLine;
  return timedWords.slice(lineStart, lineStart + wordsPerLine).map((word, index, lineWords) => ({
    text: `${word.text}${index === lineWords.length - 1 ? "" : " "}`,
    highlighted: lineStart + index === activeIndex,
  }));
}

function buildInitialState(payload) {
  return {
    width: Number(payload.width || 1080),
    height: Number(payload.height || 1920),
    style: normalizeStyle(payload.style || "bars"),
    color: payload.color || "#ff6b35",
    backgroundCss: payload.backgroundCss || "linear-gradient(180deg,#0d0d1a 0%,#0a0a14 100%)",
    captionEnabled: payload.captionEnabled !== false,
    captionClass: captionClassName(payload.captionStyle || "impact", payload.captionEnabled !== false),
    captionPosition: payload.captionPosition || "bottom_third",
    captionFontFamily: fontFamilyValue(payload.captionFont || "Bebas Neue"),
    captionFontSize: captionFontSize(payload.captionSize || "medium", Number(payload.height || 1920)),
    captionColor: payload.captionColor || "#ffffff",
    captionHighlightColor: payload.captionHighlightColor || "#ff6b35",
    captionAnimation: payload.captionAnimation || "plain",
    captionHighlightStyle: payload.captionHighlightStyle || "color",
    captionPanelColor: payload.captionPanelColor || null,
    captionBackdrop: payload.captionBackdrop === undefined ? null : payload.captionBackdrop,
  };
}

function captionClassName(style, enabled) {
  if (!enabled) {
    return "caption-hidden";
  }

  if (style === "editorial") {
    return "caption-style-editorial";
  }

  if (style === "hacker") {
    return "caption-style-hacker";
  }

  return "caption-style-impact";
}

function captionFontSize(size, height) {
  const base = (17 * height) / 480;
  // Ratios of the editor's CAPTION_SIZE_MAP (13/17/23/30 px) — the same
  // scale buildASSCaption uses, so all three renderers agree.
  const multiplier = size === "small"
    ? 13 / 17
    : size === "large"
      ? 23 / 17
      : size === "xlarge"
        ? 30 / 17
        : 1;
  return `${Math.round(base * multiplier)}px`;
}

function fontFamilyValue(font) {
  return `"${font}", sans-serif`;
}

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function buildHtml() {
  return `<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <style>
      :root {
        --scale: 1;
        --accent: #ff6b35;
        --yellow: #fbbf24;
      }

      * {
        box-sizing: border-box;
      }

      html, body {
        margin: 0;
        width: 100%;
        height: 100%;
        overflow: hidden;
        background: #000;
      }

      body {
        font-family: "DM Sans", sans-serif;
      }

      .preview-container,
      .preview-video-bg,
      .preview-fallback-waveform {
        width: 100%;
        height: 100%;
      }

      .preview-container {
        position: relative;
        overflow: hidden;
      }

      .preview-video-bg {
        position: relative;
        overflow: hidden;
      }

      /* display:none must win over any display:flex rules on the same element */
      [hidden] { display: none !important; }

      .preview-fallback-waveform {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: calc(32px * var(--scale));
      }

      .waveform-shell {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: calc(28px * var(--scale)) calc(20px * var(--scale));
        gap: calc(24px * var(--scale));
      }

      .ag-bars,
      .ag-mirror,
      .ag-minimal {
        width: 100%;
        display: flex;
        justify-content: center;
      }

      .ag-bars {
        height: calc(200px * var(--scale));
        align-items: flex-end;
        gap: calc(6px * var(--scale));
      }

      .ag-bar {
        flex: 1;
        max-width: calc(18px * var(--scale));
        min-height: 14%;
        border-radius: calc(4px * var(--scale)) calc(4px * var(--scale)) 0 0;
      }

      .ag-mirror {
        height: calc(200px * var(--scale));
        align-items: center;
        gap: calc(6px * var(--scale));
      }

      .ag-mirror-bar {
        flex: 1;
        max-width: calc(16px * var(--scale));
        border-radius: 999px;
      }

      .ag-circle-wrap {
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .ag-circle-wrap svg {
        width: calc(200px * var(--scale));
        height: calc(200px * var(--scale));
        overflow: visible;
      }

      .ag-minimal {
        height: calc(120px * var(--scale));
        align-items: flex-end;
        gap: calc(3px * var(--scale));
      }

      .ag-minimal-bar {
        flex: 1;
        max-width: calc(8px * var(--scale));
        min-height: 8%;
        border-radius: calc(2px * var(--scale)) calc(2px * var(--scale)) 0 0;
      }

      .preview-caption {
        position: absolute;
        bottom: calc(100px * var(--scale));
        left: calc(16px * var(--scale));
        right: calc(16px * var(--scale));
        text-align: center;
      }

      .preview-caption.position-center {
        top: 50%;
        bottom: auto;
        transform: translateY(-50%);
      }

      .preview-caption.position-top {
        top: calc(80px * var(--scale));
        bottom: auto;
      }

      .caption-word {
        display: inline;
        line-height: 1.4;
        font-size: inherit;
        font-weight: 700;
        color: #fff;
      }

      .caption-word.highlight {
        color: var(--accent);
      }

      .caption-hidden {
        display: none !important;
      }

      /* Exact twin of CaptionPreview.vue. Audiogram frames are rendered out
         of real time, so wordSpanFor pauses this animation and seeks it using
         the active word's elapsed time. */
      @keyframes caption-glitch {
        0% { opacity: 0.4; transform: translate(-0.08em, 0.04em) skewX(12deg); text-shadow: -0.06em 0 0 #0ff, 0.06em 0 0 #f0f; }
        35% { opacity: 1; transform: translate(0.05em, -0.03em) skewX(-8deg); text-shadow: 0.05em 0 0 #0ff, -0.05em 0 0 #f0f; }
        70% { transform: translate(-0.02em, 0.01em); text-shadow: -0.02em 0 0 #0ff, 0.02em 0 0 #f0f; }
        100% { transform: none; text-shadow: 0 0.07em 0 rgba(0, 0, 0, 0.6); }
      }

      .caption-style-editorial .caption-word {
        font-style: italic;
        font-weight: 400;
      }

      .caption-style-editorial .caption-word.highlight {
        color: #fff;
        text-decoration: underline;
        text-underline-offset: calc(3px * var(--scale));
      }

      .caption-style-editorial .caption-word.normal {
        color: rgba(255, 255, 255, 0.75);
      }

      .caption-style-hacker .caption-word {
        font-size: calc(16px * var(--scale));
        font-weight: 400;
      }

      .caption-style-hacker .caption-word.highlight {
        color: var(--yellow);
      }

      .caption-style-hacker .caption-word.normal {
        color: rgba(255, 255, 255, 0.9);
      }

    </style>
  </head>
  <body>
    <div class="preview-container">
      <div class="preview-video-bg" id="preview-video-bg">
        <div class="preview-fallback-waveform" id="preview-fallback-waveform">
          <div class="waveform-shell">
            <div class="ag-bars" id="bars-layer"></div>
            <div class="ag-mirror" id="mirror-layer" hidden></div>
            <div class="ag-circle-wrap" id="circle-layer" hidden>
              <svg viewBox="0 0 200 200" width="200" height="200" aria-hidden="true">
                <g id="circle-bars" transform="translate(100,100)"></g>
                <circle id="circle-core-a" cx="100" cy="100" r="30"></circle>
                <circle id="circle-core-b" cx="100" cy="100" r="20"></circle>
              </svg>
            </div>
            <div class="ag-minimal" id="minimal-layer" hidden></div>
          </div>
        </div>
        <div class="preview-caption" id="preview-caption"></div>
      </div>
    </div>
    <script>
      function normalizeStyle(rawStyle) {
        const style = String(rawStyle || "bars").trim().toLowerCase();

        if (style === "radial") return "circle";
        if (style === "bars" || style === "mirror" || style === "circle" || style === "minimal") {
          return style;
        }

        return "bars";
      }

      window.initializeRenderer = function initializeRenderer(initialState) {
        const scale = initialState.height / 480;
        initialState.style = normalizeStyle(initialState.style);
        document.documentElement.style.setProperty("--scale", String(scale));
        document.documentElement.style.setProperty("--accent", initialState.color);
        document.getElementById("preview-video-bg").style.background = initialState.backgroundCss;
        document.getElementById("preview-caption").style.fontFamily = initialState.captionFontFamily;
        document.getElementById("preview-caption").style.fontSize = initialState.captionFontSize;
        document.getElementById("preview-caption").className = "preview-caption " + initialState.captionClass + " " + captionPositionClass(initialState.captionPosition);

        buildBars(document.getElementById("bars-layer"), 14, "ag-bar");
        buildBars(document.getElementById("mirror-layer"), 14, "ag-mirror-bar");
        buildBars(document.getElementById("minimal-layer"), 28, "ag-minimal-bar");
        buildCircleBars(document.getElementById("circle-bars"), 14);
        document.getElementById("circle-core-a").setAttribute("fill", initialState.color);
        document.getElementById("circle-core-a").setAttribute("opacity", "0.15");
        document.getElementById("circle-core-b").setAttribute("fill", initialState.color);
        document.getElementById("circle-core-b").setAttribute("opacity", "0.25");
        setActiveStyle(initialState.style);
        window.__rendererState = initialState;
      };

      window.renderFrame = function renderFrame(frame) {
        const state = window.__rendererState;
        if (state.captionAnimation && state.captionAnimation !== "plain") {
          renderAnimatedCaption(frame.captionAnimWords || [], state);
        } else {
          renderCaption(frame.captionWords, state);
        }
        renderWaveform(frame.bars, frame.localSeconds || 0, state);
      };

      // Mirrors the editor's ag-bounce: scaleY 0.55→1.0, 1.5s ease-in-out infinite alternate
      function computeBounceScale(t) {
        const period = 1.5;
        const phase = (t % period) / period;
        const cycleIndex = Math.floor(t / period);
        // alternate: even cycles go 0→1, odd cycles go 1→0
        const normalized = cycleIndex % 2 === 0 ? phase : 1 - phase;
        // smoothstep ease-in-out (matches CSS ease-in-out closely)
        const eased = normalized * normalized * (3 - 2 * normalized);
        return 0.55 + eased * 0.45;
      }

      function captionPositionClass(position) {
        if (position === "center") return "position-center";
        if (position === "top_third") return "position-top";
        return "position-bottom";
      }

      function buildBars(container, count, className) {
        container.innerHTML = "";
        for (let index = 0; index < count; index += 1) {
          const bar = document.createElement("span");
          bar.className = className;
          container.appendChild(bar);
        }
      }

      function buildCircleBars(container, count) {
        container.innerHTML = "";
        for (let index = 0; index < count; index += 1) {
          const line = document.createElementNS("http://www.w3.org/2000/svg", "line");
          line.setAttribute("transform", "rotate(" + (index * (360 / count)) + ")");
          line.setAttribute("x1", "0");
          line.setAttribute("y1", "38");
          line.setAttribute("x2", "0");
          line.setAttribute("y2", "70");
          line.setAttribute("stroke-width", "6");
          line.setAttribute("stroke-linecap", "round");
          container.appendChild(line);
        }
      }

      function setActiveStyle(style) {
        const normalizedStyle = normalizeStyle(style);
        const toggleLayer = (id, isActive) => {
          const el = document.getElementById(id);
          el.hidden = !isActive;
          el.style.display = isActive ? "flex" : "none";
        };

        toggleLayer("bars-layer", normalizedStyle === "bars");
        toggleLayer("mirror-layer", normalizedStyle === "mirror");
        toggleLayer("circle-layer", normalizedStyle === "circle");
        toggleLayer("minimal-layer", normalizedStyle === "minimal");
      }

      function renderWaveform(bars, localSeconds, state) {
        const bounceScale = computeBounceScale(localSeconds);
        const activeStyle = normalizeStyle(state.style);

        if (activeStyle === "circle") {
          const lines = [...document.querySelectorAll("#circle-bars line")];
          lines.forEach((line, index) => {
            const bar = bars[index] || 0.04;
            line.setAttribute("stroke", state.color);
            line.setAttribute("y2", String(38 + bar * 52));
            line.setAttribute("opacity", String(0.6 + bar * 0.4));
          });
          return;
        }

        if (activeStyle === "mirror") {
          const barEls = [...document.querySelectorAll("#mirror-layer .ag-mirror-bar")];
          barEls.forEach((el, index) => {
            const bar = bars[index] || 0.04;
            el.style.height = Math.round(bar * 100) + "%";
            el.style.background = state.color;
            el.style.boxShadow = "0 0 calc(10px * var(--scale)) " + state.color + "55";
            el.style.transform = "scaleY(" + bounceScale + ")";
          });
          return;
        }

        if (activeStyle === "minimal") {
          const mirrored = [...bars, ...bars.slice().reverse()];
          const barEls = [...document.querySelectorAll("#minimal-layer .ag-minimal-bar")];
          barEls.forEach((el, index) => {
            const bar = mirrored[index] || 0.04;
            el.style.height = Math.round(bar * 80 + 8) + "%";
            el.style.background = state.color;
            el.style.opacity = String(0.5 + bar * 0.5);
            el.style.transform = "scaleY(" + bounceScale + ")";
          });
          return;
        }

        const barEls = [...document.querySelectorAll("#bars-layer .ag-bar")];
        barEls.forEach((el, index) => {
          const bar = bars[index] || 0.04;
          el.style.height = Math.round(bar * 100) + "%";
          el.style.background = "linear-gradient(to top, " + state.color + "99, " + state.color + ")";
          el.style.boxShadow = "0 0 calc(12px * var(--scale)) " + state.color + "44";
          el.style.transform = "scaleY(" + bounceScale + ")";
        });
      }

      // ── Animated caption presets — deterministic per-frame styling ──────
      // Frames render out of real time, so every transform is a pure
      // function of the word's tRel/frac (no CSS animations or transitions).
      // Must equal ANIM_FONT_SCALE in BuildsAnimatedCaptions.php and the
      // font-size em values in CaptionPreview.vue, or audiogram scenes
      // export captions at a different size than the video scenes beside
      // them in the same project.
      var ANIM_FONT_SCALE = { beast: 2.5, comic: 2.1, glitch: 2.1, sticker: 1.7, blur: 1.6,
        punch: 1.55, karaoke: 1.5, wave: 1.5, box: 1.45, marker: 1.45, slide: 1.4,
        tracking: 1.35, neon: 1.3, stream: 1.15, news: 0.95 };

      function clamp01(x) { return Math.min(1, Math.max(0, x)); }
      function easeOutBack(x) {
        var c1 = 2.6, c3 = c1 + 1;
        return 1 + c3 * Math.pow(x - 1, 3) + c1 * Math.pow(x - 1, 2);
      }

      function applyGlitchFrame(span, elapsed) {
        span.style.transformOrigin = "center center";
        span.style.animation = "caption-glitch 0.3s steps(2) both paused";
        span.style.animationDelay = "-" + Math.max(0, elapsed) + "s";
      }

      function renderAnimatedCaption(words, state) {
        var container = document.getElementById("preview-caption");
        if (!state.captionEnabled || !Array.isArray(words) || words.length === 0) {
          container.classList.add("caption-hidden");
          container.innerHTML = "";
          return;
        }
        container.classList.remove("caption-hidden");
        container.innerHTML = "";

        var anim = state.captionAnimation;
        var hl = state.captionHighlightStyle === "plain" ? state.captionColor : state.captionHighlightColor;
        var isPanel = anim === "stream" || anim === "news";
        var backdropOn = state.captionBackdrop === null || state.captionBackdrop === undefined
          ? isPanel
          : state.captionBackdrop !== false;

        var baseColor = state.captionColor;
        if (anim === "news" && backdropOn && ["#fff", "#ffffff"].indexOf(String(baseColor).toLowerCase()) !== -1) {
          baseColor = "#111111";
        }

        var wrap = document.createElement("span");
        wrap.style.display = "inline-block";
        wrap.style.maxWidth = "100%";
        var multiPop = words.length > 1 && (anim === "beast" || anim === "comic" || anim === "glitch");
        wrap.style.fontSize = (multiPop ? 1.4 : (ANIM_FONT_SCALE[anim] || 1)) + "em";
        wrap.style.lineHeight = "1.18";
        wrap.style.color = baseColor;
        if (anim === "sticker" || anim === "marker") wrap.style.transform = "rotate(-2deg)";
        if (anim === "tracking") wrap.style.letterSpacing = "0.35em";
        if (isPanel && backdropOn) {
          wrap.style.background = state.captionPanelColor || (anim === "news" ? "#ffffff" : "rgba(0,0,0,0.62)");
          wrap.style.padding = "0.5em 0.7em";
          wrap.style.borderRadius = anim === "news" ? "0.22em" : "0.5em";
          wrap.style.textAlign = "left";
          if (anim === "news") wrap.style.boxShadow = "0 0.35em 1.2em rgba(0,0,0,0.45)";
        } else if (!isPanel && backdropOn) {
          wrap.style.background = state.captionPanelColor || "rgba(0,0,0,0.58)";
          wrap.style.padding = "0.35em 0.55em";
          wrap.style.borderRadius = "0.45em";
        }

        if (isPanel) {
          buildTypewriterCaption(wrap, words, anim, hl, baseColor);
        } else {
          var oneWordMode = words.length === 1;
          words.forEach(function (word, index) {
            var span = wordSpanFor(anim, word, index, hl, baseColor, oneWordMode);
            if (span) {
              if (state.captionHighlightStyle === "underline" && word.state === "active") {
                span.style.textDecoration = "underline";
              }
              wrap.appendChild(span);
              wrap.appendChild(document.createTextNode(" "));
            }
          });
        }

        container.appendChild(wrap);
      }

      function wordSpanFor(anim, word, index, hl, baseColor, oneWordMode) {
        var popPreset = anim === "beast" || anim === "comic" || anim === "glitch";
        if (popPreset && oneWordMode && word.state !== "active") return null;

        var span = document.createElement("span");
        span.textContent = word.text;
        span.style.display = "inline-block";
        span.style.margin = "0 0.1em";
        span.style.fontWeight = "700";
        span.style.textShadow = "0 0.08em 0.18em rgba(0,0,0,0.7)";
        if (anim === "glitch") {
          span.style.fontWeight = "400";
          span.style.textShadow = "0 0.07em 0 rgba(0,0,0,0.6)";
        }
        var t = word.tRel || 0;
        var active = word.state === "active";
        var unspoken = word.state === "unspoken";

        if (popPreset && !oneWordMode) {
          // one-word preset forced into line mode: dim line, active word keeps
          // the preset's character (comic tilt, glitch skew) — spans transform
          // in place in DOM, unlike ASS \frz.
          span.style.opacity = unspoken ? "0.35" : "1";
          if (anim === "beast") {
            span.style.fontWeight = "900";
            span.style.webkitTextStroke = "0.035em #000";
          } else if (anim === "comic") {
            span.style.webkitTextStroke = "0.045em #000";
          }
          if (active) {
            if (anim === "glitch") {
              // Unlike Beast/Comic, the Vue Glitch preset has no scale-in. Its
              // translate/skew and steps(2) timing are applied verbatim.
              applyGlitchFrame(span, t);
            } else {
              var ep = easeOutBack(clamp01(t / 0.14));
              var tx = "scale(" + (0.55 + 0.45 * ep) + ")";
              var tiltL = (index % 2 === 0 ? -6 : 6) * clamp01(t / 0.22);
              if (anim === "comic") tx += " rotate(" + tiltL + "deg)";
              span.style.transform = tx;
            }
            span.style.color = hl;
          }
          return span;
        }

        if (anim === "beast") {
          span.style.fontWeight = "900";
          var e = easeOutBack(clamp01(t / 0.18));
          span.style.transform = "scale(" + (0.35 + 0.65 * e) + ")";
          span.style.webkitTextStroke = "0.035em #000";
        } else if (anim === "comic") {
          var ec = easeOutBack(clamp01(t / 0.22));
          var tilt = index % 2 === 0 ? -3 : 2.5;
          span.style.transform = "scale(" + (0.2 + 0.8 * ec) + ") rotate(" + (tilt * clamp01(t / 0.22)) + "deg)";
          span.style.webkitTextStroke = "0.045em #000";
        } else if (anim === "glitch") {
          // One-word Glitch keeps the base caption colour in Vue; the RGB
          // split, vertical direction, duration and stepped timing all come
          // from the same keyframes used in the editor.
          applyGlitchFrame(span, t);
        } else if (anim === "karaoke") {
          span.style.fontWeight = "800";
          span.style.fontStyle = "italic";
          span.style.opacity = unspoken ? "0.38" : "1";
          if (active) { span.style.color = hl; span.style.transform = "scale(1.08)"; }
        } else if (anim === "box") {
          span.style.fontWeight = "800";
          span.style.padding = "0.04em 0.18em";
          span.style.borderRadius = "0.28em";
          if (active) { span.style.background = hl; span.style.color = "#111"; span.style.transform = "scale(1.07)"; span.style.textShadow = "none"; }
        } else if (anim === "sticker") {
          span.style.webkitTextStroke = "0.07em #000";
          if (unspoken) { span.style.opacity = "0"; }
          else if (active) {
            var es = easeOutBack(clamp01(t / 0.16));
            span.style.transform = "scale(" + (0.4 + 0.6 * es) + ")";
            span.style.color = hl;
          }
        } else if (anim === "blur") {
          if (unspoken) { span.style.opacity = "0"; }
          else {
            var eb = clamp01(t / 0.28);
            span.style.filter = "blur(" + (10 * (1 - eb)) + "px)";
            span.style.transform = "scale(" + (1.15 - 0.15 * eb) + ")";
            if (active) span.style.color = hl;
          }
        } else if (anim === "slide") {
          if (unspoken) { span.style.opacity = "0"; }
          else {
            var el = clamp01(t / 0.22);
            span.style.opacity = String(Math.min(1, el * 2));
            span.style.transform = "translateY(" + (0.9 * (1 - easeOutBack(el))) + "em)";
            if (active) span.style.color = hl;
          }
        } else if (anim === "wave") {
          span.style.webkitTextStroke = "0.04em #000";
          if (active) {
            var ew = clamp01(t / 0.32);
            span.style.transform = "translateY(" + (-0.32 * Math.sin(Math.PI * ew)) + "em)";
            span.style.color = hl;
          }
        } else if (anim === "punch") {
          span.style.fontWeight = "900";
          span.style.fontStyle = "italic";
          span.style.textShadow = "0.1em 0.12em 0 #000";
          span.style.opacity = unspoken ? "0.3" : "1";
          span.style.transform = active ? "skewX(-4deg) scale(1.22)" : "skewX(-4deg)";
          if (active) span.style.color = hl;
        } else if (anim === "tracking") {
          span.style.opacity = unspoken ? "0.45" : "1";
          if (active) span.style.color = hl;
        } else if (anim === "neon") {
          span.style.textShadow = "0 0 0.22em " + hl + ", 0 0 0.55em " + hl + ", 0 0 1em " + hl;
          if (unspoken) span.style.opacity = "0.3";
          else if (active) {
            var flick = t < 0.13 ? [0.15, 1, 0.45][Math.floor(t / 0.045) % 3] : 1;
            span.style.opacity = String(flick);
          } else span.style.opacity = "0.85";
        } else if (anim === "marker") {
          if (unspoken) { span.style.opacity = "0"; }
          else if (active) {
            var em = clamp01(t / 0.18);
            span.style.transform = "rotate(" + (4 * (1 - em)) + "deg) scale(" + (1.35 - 0.35 * em) + ")";
            span.style.color = hl;
          }
        } else if (active) {
          span.style.color = hl;
        }

        return span;
      }

      function buildTypewriterCaption(wrap, words, anim, hl, baseColor) {
        words.forEach(function (word) {
          if (word.state === "unspoken") return;
          var shown = word.text;
          if (anim === "stream" && word.state === "active") {
            var chars = Array.from(word.text);
            shown = chars.slice(0, Math.round(word.frac * chars.length)).join("");
            if (shown === "") return;
          }
          var span = document.createElement("span");
          span.textContent = shown;
          span.style.display = "inline-block";
          span.style.margin = "0 0.12em 0 0";
          span.style.fontWeight = anim === "news" ? "800" : "500";
          if (anim === "news" && word.state === "active") {
            span.style.background = hl;
            span.style.borderRadius = "0.15em";
            span.style.padding = "0 0.12em";
          }
          wrap.appendChild(span);
        });
        var caret = document.createElement("span");
        caret.style.display = "inline-block";
        caret.style.width = anim === "news" ? "0.45em" : "0.5em";
        caret.style.height = anim === "news" ? "0.95em" : "1.05em";
        caret.style.background = anim === "news" ? baseColor : hl;
        caret.style.verticalAlign = "text-bottom";
        wrap.appendChild(caret);
      }

      function renderCaption(words, state) {
        const container = document.getElementById("preview-caption");
        if (!state.captionEnabled || !Array.isArray(words) || words.length === 0) {
          container.classList.add("caption-hidden");
          container.innerHTML = "";
          return;
        }

        container.classList.remove("caption-hidden");
        container.innerHTML = "";
        words.forEach((word) => {
          const span = document.createElement("span");
          span.className = "caption-word " + (word.highlighted ? "highlight" : "normal");
          span.textContent = word.text;
          span.style.color = word.highlighted ? state.captionHighlightColor : state.captionColor;
          container.appendChild(span);
        });
      }

    </script>
  </body>
</html>`;
}
