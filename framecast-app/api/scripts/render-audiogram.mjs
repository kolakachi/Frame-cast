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

    await page.evaluate((frame) => {
      window.renderFrame(frame);
    }, {
      bars,
      localSeconds,
      captionWords,
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
  const multiplier = size === "small"
    ? 0.76
    : size === "large"
      ? 1.35
      : size === "xlarge"
        ? 1.76
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
        renderCaption(frame.captionWords, state);
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
