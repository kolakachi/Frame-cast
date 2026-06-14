<script setup>
import { computed, onBeforeUnmount, ref } from "vue";
import api from "../services/api";

const emit = defineEmits(["close", "created"]);

const name = ref("");
const file = ref(null); // File to upload (from record or upload)
const fileLabel = ref("");
const state = ref("idle"); // idle | uploading | creating
const error = ref("");
const busy = computed(() => state.value === "uploading" || state.value === "creating");

// Capture state machine: choose | countdown | recording | review
const phase = ref("choose");
const countdown = ref(0);
const recordSeconds = ref(0);
const sampleUrl = ref(""); // object URL for preview playback
const isPlaying = ref(false);

let mediaRecorder = null;
let chunks = [];
let stream = null;
let recTimer = null;
let cdTimer = null;
let previewAudio = null;
const MAX_SECONDS = 60;

function clearTimers() {
  if (recTimer) { window.clearInterval(recTimer); recTimer = null; }
  if (cdTimer) { window.clearInterval(cdTimer); cdTimer = null; }
}
function releaseStream() {
  try { stream?.getTracks().forEach((t) => t.stop()); } catch {}
  stream = null;
}
function stopPreview() {
  try { previewAudio?.pause(); } catch {}
  isPlaying.value = false;
}
function revokeSample() {
  if (sampleUrl.value) { try { URL.revokeObjectURL(sampleUrl.value); } catch {} sampleUrl.value = ""; }
}

// ── Record with a 3-2-1 countdown ──────────────────────────────────
function startCountdown() {
  error.value = "";
  retake(); // clear any prior capture
  phase.value = "countdown";
  countdown.value = 3;
  cdTimer = window.setInterval(() => {
    countdown.value -= 1;
    if (countdown.value <= 0) {
      window.clearInterval(cdTimer);
      cdTimer = null;
      beginRecording();
    }
  }, 1000);
}

async function beginRecording() {
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    chunks = [];
    mediaRecorder = new MediaRecorder(stream);
    mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
    mediaRecorder.onstop = () => {
      const type = mediaRecorder?.mimeType || "audio/webm";
      const blob = new Blob(chunks, { type });
      const ext = type.includes("ogg") ? "ogg" : "webm";
      file.value = new File([blob], `recording.${ext}`, { type });
      fileLabel.value = `Recording · ${recordSeconds.value}s`;
      revokeSample();
      sampleUrl.value = URL.createObjectURL(blob);
      releaseStream();
      phase.value = "review";
    };
    mediaRecorder.start();
    phase.value = "recording";
    recordSeconds.value = 0;
    recTimer = window.setInterval(() => {
      recordSeconds.value += 1;
      if (recordSeconds.value >= MAX_SECONDS) stopRecording();
    }, 1000);
  } catch (e) {
    error.value = "Microphone access was denied or is unavailable.";
    phase.value = "choose";
  }
}

function stopRecording() {
  clearTimers();
  try { mediaRecorder?.stop(); } catch {}
}

function cancelCountdown() {
  clearTimers();
  phase.value = "choose";
}

// ── Upload alternative ─────────────────────────────────────────────
function onFile(e) {
  const f = e.target.files?.[0] ?? null;
  if (!f) return;
  retake();
  file.value = f;
  fileLabel.value = f.name;
  revokeSample();
  sampleUrl.value = URL.createObjectURL(f);
  phase.value = "review";
}

// ── Preview / retake ───────────────────────────────────────────────
function togglePreview() {
  if (!sampleUrl.value) return;
  if (isPlaying.value) { stopPreview(); return; }
  if (!previewAudio) previewAudio = new Audio();
  previewAudio.src = sampleUrl.value;
  previewAudio.onended = () => { isPlaying.value = false; };
  previewAudio.play().then(() => { isPlaying.value = true; }).catch(() => {});
}
function retake() {
  clearTimers();
  stopPreview();
  releaseStream();
  revokeSample();
  file.value = null;
  fileLabel.value = "";
  recordSeconds.value = 0;
  phase.value = "choose";
}

async function submit() {
  if (!name.value.trim()) { error.value = "Give the voice a name."; return; }
  if (!file.value) { error.value = "Record or upload a voice sample first."; return; }
  error.value = "";
  stopPreview();
  try {
    state.value = "uploading";
    const fd = new FormData();
    fd.append("title", `Voice sample — ${name.value.trim()}`);
    fd.append("asset_type", "audio");
    fd.append("asset_file", file.value);
    const up = await api.post("/assets", fd, { headers: { "Content-Type": "multipart/form-data" } });
    const assetId = up.data?.data?.asset?.id;
    if (!assetId) throw new Error("Upload returned no asset id.");

    state.value = "creating";
    const res = await api.post("/voice-profiles/clone", {
      name: name.value.trim(),
      source_asset_id: assetId,
    });
    emit("created", res.data?.data?.voice_profile ?? null);
    close();
  } catch (e) {
    state.value = "idle";
    error.value = e?.response?.data?.error?.message ?? "Could not create the cloned voice.";
  }
}

function close() {
  retake();
  emit("close");
}

onBeforeUnmount(() => { clearTimers(); stopPreview(); releaseStream(); revokeSample(); });
</script>

<template>
  <Teleport to="body">
    <div class="vc-backdrop" @click.self="close">
      <div class="vc-modal">
        <div class="vc-head">
          <div class="vc-title">✦ Clone a voice</div>
          <button class="vc-close" type="button" @click="close">×</button>
        </div>

        <div class="vc-field">
          <label class="vc-label">Voice name</label>
          <input v-model="name" class="vc-input" placeholder="e.g. My narrator" :disabled="busy" />
        </div>

        <div class="vc-field">
          <label class="vc-label">Voice sample</label>

          <!-- Countdown -->
          <div v-if="phase === 'countdown'" class="vc-stage vc-countdown">
            <div class="vc-count-num">{{ countdown }}</div>
            <div class="vc-stage-sub">Get ready…</div>
            <button class="vc-text-btn" type="button" @click="cancelCountdown">Cancel</button>
          </div>

          <!-- Recording -->
          <div v-else-if="phase === 'recording'" class="vc-stage vc-recording">
            <div class="vc-rec-dot"></div>
            <div class="vc-stage-main">Recording · {{ recordSeconds }}s</div>
            <div class="vc-stage-sub">Speak naturally for ~10–20s.</div>
            <button class="btn btn-primary btn-sm" type="button" @click="stopRecording">⏹ Stop</button>
          </div>

          <!-- Review (preview + retake) -->
          <div v-else-if="phase === 'review'" class="vc-stage vc-review">
            <button class="vc-play" type="button" @click="togglePreview">{{ isPlaying ? '⏸' : '▶' }}</button>
            <div class="vc-review-info">
              <div class="vc-review-label">{{ fileLabel }}</div>
              <div class="vc-review-sub">Preview it, then create — or retake.</div>
            </div>
            <button class="vc-text-btn" type="button" @click="retake">↺ Retake</button>
          </div>

          <!-- Choose: record or upload -->
          <div v-else class="vc-sample-actions">
            <button type="button" class="vc-sample-btn" @click="startCountdown">● Record</button>
            <label class="vc-sample-btn">
              <input type="file" accept="audio/*" hidden @change="onFile" />
              ⬆ Upload file
            </label>
          </div>

          <div class="vc-hint">A clean, single-speaker clip of ~10–20s works best — no music or background noise. mp3, wav, m4a or a recording all work.</div>
        </div>

        <div v-if="error" class="banner error" style="margin-top:10px">{{ error }}</div>

        <div class="vc-foot">
          <button class="btn btn-ghost btn-sm" type="button" @click="close">Cancel</button>
          <button class="btn btn-primary btn-sm" type="button" :disabled="busy || !file || phase === 'recording' || phase === 'countdown'" @click="submit">
            {{ state === 'uploading' ? 'Uploading…' : state === 'creating' ? 'Cloning…' : 'Create voice' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.vc-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.62); display: flex; align-items: center; justify-content: center; z-index: 1200; padding: 20px; }
.vc-modal { width: 100%; max-width: 480px; background: #14141c; border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; padding: 20px; color: #e8e8ee; }
.vc-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.vc-title { font-size: 16px; font-weight: 700; }
.vc-close { background: transparent; border: none; color: #8a8a9a; font-size: 22px; line-height: 1; cursor: pointer; }
.vc-field { margin-bottom: 14px; }
.vc-label { display: block; font-size: 12px; color: #9a9aab; margin-bottom: 6px; }
.vc-input { width: 100%; box-sizing: border-box; background: #0e0e15; border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; color: #e8e8ee; font: inherit; font-size: 13px; padding: 9px 11px; }
.vc-input:focus { outline: none; border-color: rgba(255,107,53,0.5); }

.vc-sample-actions { display: flex; gap: 10px; }
.vc-sample-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; min-height: 46px; border: 1px solid rgba(255,255,255,0.14); border-radius: 10px; background: rgba(255,255,255,0.04); color: #e8e8ee; font: inherit; font-size: 13px; cursor: pointer; }
.vc-sample-btn:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,107,53,0.4); }

.vc-stage { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 18px 12px; border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; }
.vc-stage-main { font-size: 14px; font-weight: 600; }
.vc-stage-sub { font-size: 11.5px; color: #8a8a9a; }
.vc-count-num { font-size: 44px; font-weight: 800; color: #ff8055; line-height: 1; }
.vc-recording { border-color: rgba(248,113,113,0.4); background: rgba(248,113,113,0.06); }
.vc-rec-dot { width: 12px; height: 12px; border-radius: 50%; background: #f87171; animation: vc-pulse 1s infinite; }
@keyframes vc-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }
.vc-review { flex-direction: row; align-items: center; gap: 12px; }
.vc-play { width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%; border: 1px solid rgba(255,107,53,0.5); background: rgba(255,107,53,0.12); color: #ff8055; cursor: pointer; font-size: 14px; }
.vc-review-info { flex: 1; min-width: 0; text-align: left; }
.vc-review-label { font-size: 13px; font-weight: 600; word-break: break-all; }
.vc-review-sub { font-size: 11.5px; color: #8a8a9a; margin-top: 1px; }
.vc-text-btn { background: transparent; border: none; color: #ff8055; font: inherit; font-size: 12.5px; cursor: pointer; flex-shrink: 0; }
.vc-text-btn:hover { text-decoration: underline; }

.vc-hint { font-size: 11px; color: #7a7a8a; margin-top: 8px; line-height: 1.4; }
.vc-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }

.banner.error { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: #fca5a5; border-radius: 8px; padding: 10px 14px; font-size: 13px; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.04); color: #e8e8ee; font: inherit; font-size: 13px; cursor: pointer; }
.btn:hover { background: rgba(255,255,255,0.08); }
.btn-sm { padding: 6px 12px; font-size: 12.5px; }
.btn-primary { background: #ff6b35; border-color: #ff6b35; color: #0a0a0f; font-weight: 600; }
.btn-primary:hover { background: #ff8055; border-color: #ff8055; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border-color: transparent; }
.btn-ghost:hover { background: rgba(255,255,255,0.06); }
</style>
