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
const barCount = payload.style === "minimal" ? 20 : payload.style === "mirror" ? 18 : 16;
const sampleRate = Math.max(8000, Number(payload.sampleRate || 16000));
const pcm = payload.pcmPath ? await readPcmFloat32(payload.pcmPath) : new Float32Array(0);
const totalFrames = Math.max(1, Math.ceil(duration * fps));
const bandFrames = buildBandFrames({
  pcm,
  duration,
  fps,
  barCount,
  sampleRate,
  style: payload.style || "bars",
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
      timerText: formatClock((payload.elapsedSeconds || 0) + localSeconds),
      captionWords,
      musicWaveHeights: buildMusicWaveHeights(localSeconds),
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

function buildBandFrames({ pcm, duration, fps, barCount, sampleRate, style }) {
  const totalFrames = Math.max(1, Math.ceil(duration * fps));
  const ranges = createBandRanges(barCount, sampleRate);
  const windowSize = 1024;
  const rawFrames = [];

  for (let frameIndex = 0; frameIndex < totalFrames; frameIndex += 1) {
    const currentSeconds = Math.min(duration, frameIndex / fps);
    const bars = pcm.length > 0
      ? analyzeBarsAtTime(pcm, sampleRate, currentSeconds, ranges, windowSize)
      : simulatedBars(currentSeconds, barCount, style);
    rawFrames.push(bars);
  }

  const flattened = rawFrames.flat().filter((value) => Number.isFinite(value) && value > 0);
  const reference = percentile(flattened, 0.985) || 1;
  const smoothedFrames = [];
  let previous = new Array(barCount).fill(0.04);

  for (const rawBars of rawFrames) {
    const normalized = rawBars.map((value, index) => {
      const pos = index / Math.max(1, barCount - 1);
      const scaled = Math.sqrt(Math.min(1, value / reference));
      const voicedBias = 0.88 + Math.max(0, 1 - Math.abs(pos - 0.34) * 1.6) * 0.22;
      return clamp(scaled * voicedBias, 0.04, 1);
    });

    previous = previous.map((current, index) =>
      clamp(current + (normalized[index] - current) * 0.28, 0.04, 1),
    );
    smoothedFrames.push([...previous]);
  }

  return smoothedFrames;
}

function analyzeBarsAtTime(pcm, sampleRate, currentSeconds, ranges, windowSize) {
  const centerSample = Math.floor(currentSeconds * sampleRate);
  const start = Math.max(0, centerSample - Math.floor(windowSize / 2));
  const window = new Float32Array(windowSize);

  for (let index = 0; index < windowSize; index += 1) {
    const sourceIndex = Math.min(pcm.length - 1, Math.max(0, start + index));
    const hann = 0.5 * (1 - Math.cos((2 * Math.PI * index) / Math.max(1, windowSize - 1)));
    window[index] = (pcm[sourceIndex] || 0) * hann;
  }

  return ranges.map(([minFreq, maxFreq]) => {
    const probes = geometricProbeFrequencies(minFreq, maxFreq, 4);
    const total = probes.reduce(
      (sum, frequency) => sum + goertzelPower(window, sampleRate, frequency),
      0,
    );

    return total / Math.max(1, probes.length);
  });
}

function createBandRanges(barCount, sampleRate) {
  const minFreq = 70;
  const maxFreq = Math.min(sampleRate * 0.42, 3800);
  const ranges = [];

  for (let index = 0; index < barCount; index += 1) {
    const start = minFreq * Math.pow(maxFreq / minFreq, index / barCount);
    const end = minFreq * Math.pow(maxFreq / minFreq, (index + 1) / barCount);
    ranges.push([start, end]);
  }

  return ranges;
}

function geometricProbeFrequencies(minFreq, maxFreq, count) {
  if (count <= 1 || minFreq <= 0 || maxFreq <= minFreq) {
    return [Math.max(minFreq, maxFreq)];
  }

  return Array.from({ length: count }, (_, index) => {
    const t = (index + 0.5) / count;
    return minFreq * Math.pow(maxFreq / minFreq, t);
  });
}

function goertzelPower(samples, sampleRate, targetFrequency) {
  if (!Number.isFinite(targetFrequency) || targetFrequency <= 0) {
    return 0;
  }

  const omega = (2 * Math.PI * targetFrequency) / sampleRate;
  const coeff = 2 * Math.cos(omega);
  let s0 = 0;
  let s1 = 0;
  let s2 = 0;

  for (let index = 0; index < samples.length; index += 1) {
    s0 = samples[index] + coeff * s1 - s2;
    s2 = s1;
    s1 = s0;
  }

  return Math.max(0, s1 * s1 + s2 * s2 - coeff * s1 * s2);
}

function simulatedBars(currentSeconds, barCount, style) {
  return Array.from({ length: barCount }, (_, index) => {
    const pos = index / Math.max(1, barCount - 1);
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

function buildMusicWaveHeights(currentSeconds) {
  return [0, 1, 2, 3].map((index) =>
    clamp(0.35 + 0.65 * (0.5 + 0.5 * Math.sin(currentSeconds * 8 + index * 0.8)), 0.2, 1),
  );
}

function buildInitialState(payload) {
  return {
    width: Number(payload.width || 1080),
    height: Number(payload.height || 1920),
    style: payload.style || "bars",
    color: payload.color || "#ff6b35",
    backgroundCss: payload.backgroundCss || "linear-gradient(180deg,#0d0d1a 0%,#0a0a14 100%)",
    captionEnabled: payload.captionEnabled !== false,
    captionClass: captionClassName(payload.captionStyle || "impact", payload.captionEnabled !== false),
    captionPosition: payload.captionPosition || "bottom_third",
    captionFontFamily: fontFamilyValue(payload.captionFont || "Bebas Neue"),
    captionFontSize: captionFontSize(payload.captionSize || "medium", Number(payload.height || 1920)),
    captionColor: payload.captionColor || "#ffffff",
    captionHighlightColor: payload.captionHighlightColor || "#ff6b35",
    musicTitle: String(payload.musicTitle || ""),
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

function formatClock(seconds) {
  const whole = Math.max(0, Math.round(Number(seconds || 0)));
  const mins = Math.floor(whole / 60);
  const secs = whole % 60;
  return `${String(mins).padStart(2, "0")}:${String(secs).padStart(2, "0")}`;
}

function percentile(values, ratio) {
  if (!Array.isArray(values) || values.length === 0) {
    return 0;
  }

  const sorted = [...values].sort((a, b) => a - b);
  const index = Math.min(sorted.length - 1, Math.max(0, Math.floor(sorted.length * ratio)));
  return sorted[index];
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
        padding: calc(28px * var(--scale)) calc(20px * var(--scale));
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
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
        min-height: calc(28px * var(--scale));
        border-radius: calc(8px * var(--scale)) calc(8px * var(--scale)) 0 0;
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
        width: calc(200px * var(--scale));
        height: calc(200px * var(--scale));
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .ag-circle-wrap svg {
        width: 100%;
        height: 100%;
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
        min-height: calc(16px * var(--scale));
        border-radius: calc(3px * var(--scale)) calc(3px * var(--scale)) 0 0;
      }

      .preview-watermark {
        position: absolute;
        top: calc(16px * var(--scale));
        left: calc(16px * var(--scale));
        font-family: "Space Mono", monospace;
        font-size: calc(10px * var(--scale));
        color: rgba(255, 255, 255, 0.3);
        letter-spacing: 0.02em;
      }

      .preview-timer {
        position: absolute;
        top: calc(16px * var(--scale));
        right: calc(16px * var(--scale));
        font-family: "Space Mono", monospace;
        font-size: calc(11px * var(--scale));
        color: rgba(255, 255, 255, 0.5);
        background: rgba(0, 0, 0, 0.4);
        padding: calc(3px * var(--scale)) calc(8px * var(--scale));
        border-radius: calc(4px * var(--scale));
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

      .preview-music-indicator {
        position: absolute;
        bottom: calc(16px * var(--scale));
        left: calc(16px * var(--scale));
        right: calc(16px * var(--scale));
        display: flex;
        align-items: center;
        gap: calc(6px * var(--scale));
        padding: calc(6px * var(--scale)) calc(10px * var(--scale));
        border-radius: calc(6px * var(--scale));
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(calc(4px * var(--scale)));
      }

      .preview-music-indicator.hidden {
        display: none;
      }

      .preview-music-waves {
        display: flex;
        align-items: center;
        gap: calc(2px * var(--scale));
      }

      .music-wave {
        width: calc(2px * var(--scale));
        border-radius: 999px;
        background: var(--accent);
      }

      .preview-music-name {
        font-size: calc(10px * var(--scale));
        color: rgba(255,255,255,0.7);
        font-family: "Space Mono", monospace;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
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
              <svg viewBox="0 0 200 200" aria-hidden="true">
                <g id="circle-bars" transform="translate(100,100)"></g>
                <circle id="circle-core-a" cx="100" cy="100" r="30"></circle>
                <circle id="circle-core-b" cx="100" cy="100" r="20"></circle>
              </svg>
            </div>
            <div class="ag-minimal" id="minimal-layer" hidden></div>
          </div>
        </div>
        <div class="preview-watermark">FRAMECAST</div>
        <div class="preview-timer" id="preview-timer">00:00</div>
        <div class="preview-caption" id="preview-caption"></div>
        <div class="preview-music-indicator hidden" id="preview-music-indicator">
          <div class="preview-music-waves">
            <div class="music-wave"></div>
            <div class="music-wave"></div>
            <div class="music-wave"></div>
            <div class="music-wave"></div>
          </div>
          <span class="preview-music-name" id="preview-music-name"></span>
        </div>
      </div>
    </div>
    <script>
      window.initializeRenderer = function initializeRenderer(initialState) {
        const scale = initialState.height / 480;
        document.documentElement.style.setProperty("--scale", String(scale));
        document.documentElement.style.setProperty("--accent", initialState.color);
        document.getElementById("preview-video-bg").style.background = initialState.backgroundCss;
        document.getElementById("preview-caption").style.fontFamily = initialState.captionFontFamily;
        document.getElementById("preview-caption").style.fontSize = initialState.captionFontSize;
        document.getElementById("preview-caption").className = "preview-caption " + initialState.captionClass + " " + captionPositionClass(initialState.captionPosition);
        document.getElementById("preview-music-name").textContent = initialState.musicTitle || "";
        document.getElementById("preview-music-indicator").classList.toggle("hidden", !initialState.musicTitle);

        buildBars(document.getElementById("bars-layer"), 16, "ag-bar");
        buildBars(document.getElementById("mirror-layer"), 18, "ag-mirror-bar");
        buildBars(document.getElementById("minimal-layer"), 40, "ag-minimal-bar");
        buildCircleBars(document.getElementById("circle-bars"), 16);
        document.getElementById("circle-core-a").setAttribute("fill", initialState.color);
        document.getElementById("circle-core-a").setAttribute("opacity", "0.15");
        document.getElementById("circle-core-b").setAttribute("fill", initialState.color);
        document.getElementById("circle-core-b").setAttribute("opacity", "0.25");
        setActiveStyle(initialState.style);
        window.__rendererState = initialState;
      };

      window.renderFrame = function renderFrame(frame) {
        const state = window.__rendererState;
        document.getElementById("preview-timer").textContent = frame.timerText;
        renderCaption(frame.captionWords, state);
        renderMusicWaves(frame.musicWaveHeights, state);
        renderWaveform(frame.bars, state);
      };

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
        document.getElementById("bars-layer").style.display = style === "bars" ? "flex" : "none";
        document.getElementById("mirror-layer").style.display = style === "mirror" ? "flex" : "none";
        document.getElementById("circle-layer").style.display = style === "circle" ? "flex" : "none";
        document.getElementById("minimal-layer").style.display = style === "minimal" ? "flex" : "none";
      }

      function renderWaveform(bars, state) {
        if (state.style === "circle") {
          const lines = [...document.querySelectorAll("#circle-bars line")];
          lines.forEach((line, index) => {
            const bar = bars[index] || 0.04;
            line.setAttribute("stroke", state.color);
            line.setAttribute("y2", String(38 + bar * 52));
            line.setAttribute("opacity", String(0.6 + bar * 0.4));
          });
          return;
        }

        if (state.style === "mirror") {
          const barEls = [...document.querySelectorAll("#mirror-layer .ag-mirror-bar")];
          barEls.forEach((el, index) => {
            const bar = bars[index] || 0.04;
            el.style.height = Math.round(bar * 100) + "%";
            el.style.background = state.color;
            el.style.boxShadow = "0 0 calc(10px * var(--scale)) " + state.color + "55";
          });
          return;
        }

        if (state.style === "minimal") {
          const mirrored = [...bars, ...bars.slice().reverse()];
          const barEls = [...document.querySelectorAll("#minimal-layer .ag-minimal-bar")];
          barEls.forEach((el, index) => {
            const bar = mirrored[index] || 0.04;
            el.style.height = Math.round(bar * 80 + 8) + "%";
            el.style.background = state.color;
            el.style.opacity = String(0.5 + bar * 0.5);
          });
          return;
        }

        const barEls = [...document.querySelectorAll("#bars-layer .ag-bar")];
        barEls.forEach((el, index) => {
          const bar = bars[index] || 0.04;
          el.style.height = Math.round(bar * 100) + "%";
          el.style.background = "linear-gradient(to top, " + state.color + "99, " + state.color + ")";
          el.style.boxShadow = "0 0 calc(12px * var(--scale)) " + state.color + "44";
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

      function renderMusicWaves(heights, state) {
        const container = document.getElementById("preview-music-indicator");
        if (!state.musicTitle) {
          container.classList.add("hidden");
          return;
        }

        const waveEls = [...container.querySelectorAll(".music-wave")];
        waveEls.forEach((wave, index) => {
          const height = heights[index] || 0.4;
          wave.style.height = "calc(" + (4 + height * 8) + "px * var(--scale))";
          wave.style.opacity = String(0.45 + height * 0.55);
        });
      }
    </script>
  </body>
</html>`;
}
