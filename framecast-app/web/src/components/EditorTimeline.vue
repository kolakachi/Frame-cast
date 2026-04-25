<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  scenes:        { type: Array,   default: () => [] },
  activeSceneId: { type: Number,  default: null },
  playProgress:  { type: Number,  default: 0 },    // 0-100 within current previewMode
  totalDuration: { type: Number,  default: 0 },    // seconds
  isPlaying:     { type: Boolean, default: false },
  musicTrack:    { type: Object,  default: null },
  previewMode:   { type: String,  default: 'scene' }, // 'scene' | 'full'
})

const emit = defineEmits(['scene-select', 'seek', 'reorder', 'close'])

// ── Zoom ─────────────────────────────────────────────────────────────────────
const zoom = ref(1) // 1 = fit, 2 = 2×

// ── Scene geometry ────────────────────────────────────────────────────────────
function sceneDur(scene) {
  const a = Number(scene?.audio_asset?.duration_seconds || 0)
  if (a > 0) return Math.max(0.1, a)
  return Math.max(0.1, Number(scene?.duration_seconds || 12))
}

const total = computed(() => {
  if (props.totalDuration > 0) return props.totalDuration
  return props.scenes.reduce((s, sc) => s + sceneDur(sc), 0) || 1
})

const sceneBlocks = computed(() => {
  let cum = 0
  return props.scenes.map((sc, i) => {
    const dur = sceneDur(sc)
    const block = {
      id: sc.id,
      index: i,
      startPct: (cum / total.value) * 100,
      widthPct: (dur / total.value) * 100,
      dur,
      label: `S${String(i + 1).padStart(2, '0')}`,
      durLabel: dur >= 1 ? `${dur.toFixed(1)}s` : `${Math.round(dur * 1000)}ms`,
      scriptSnippet: (sc.script_text || sc.label || '').slice(0, 40),
      active: sc.id === props.activeSceneId,
    }
    cum += dur
    return block
  })
})

// ── Playhead position in full-video space (0-100) ────────────────────────────
const playheadPct = computed(() => {
  if (props.previewMode === 'full') return props.playProgress

  // Scene mode: map scene-local progress to full-video space
  const block = sceneBlocks.value.find(b => b.id === props.activeSceneId)
  if (!block) return 0
  return block.startPct + (props.playProgress / 100) * block.widthPct
})

// ── Ruler ticks ──────────────────────────────────────────────────────────────
const rulerTicks = computed(() => {
  const dur = total.value
  // pick a sensible interval
  let interval = 5
  if (dur <= 15) interval = 2
  if (dur <= 5)  interval = 1
  if (dur > 60)  interval = 10
  if (dur > 180) interval = 30

  const ticks = []
  for (let t = 0; t <= dur; t += interval) {
    ticks.push({ secs: t, pct: (t / dur) * 100, label: `${t}s` })
  }
  return ticks
})

// ── Waveform paths (deterministic per scene) ──────────────────────────────────
function waveformPath(sceneId, line) {
  const seed = (sceneId * 137 + line * 31) % 997
  const pts = []
  const steps = 32
  for (let i = 0; i <= steps; i++) {
    const x = (i / steps) * 600
    const rand = Math.sin(seed + i * 2.4 + line * 5.1) * 0.5 + 0.5
    const amp = line === 0 ? 4 - rand * 3.5 : 12 + rand * 3.5
    pts.push(`${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${amp.toFixed(1)}`)
  }
  return pts.join(' ')
}

// ── Drag-to-reorder ───────────────────────────────────────────────────────────
const tracksRef = ref(null)
const drag = ref({ active: false, sceneId: null, fromIndex: -1, overIndex: -1, startX: 0, dx: 0 })

function onSceneMousedown(e, block) {
  e.preventDefault()
  drag.value = { active: true, sceneId: block.id, fromIndex: block.index, overIndex: block.index, startX: e.clientX, dx: 0 }
  window.addEventListener('mousemove', onDragMove)
  window.addEventListener('mouseup', onDragEnd)
}

function onDragMove(e) {
  if (!drag.value.active) return
  drag.value.dx = e.clientX - drag.value.startX

  // Determine which block the cursor is over based on x position
  const trackEl = tracksRef.value
  if (!trackEl) return
  const rect = trackEl.getBoundingClientRect()
  const relX = e.clientX - rect.left
  const pct = (relX / rect.width) * 100

  let over = props.scenes.length - 1
  for (let i = 0; i < sceneBlocks.value.length; i++) {
    const b = sceneBlocks.value[i]
    if (pct < b.startPct + b.widthPct / 2) { over = i; break }
  }
  drag.value.overIndex = over
}

function onDragEnd() {
  window.removeEventListener('mousemove', onDragMove)
  window.removeEventListener('mouseup', onDragEnd)

  if (drag.value.active && drag.value.overIndex !== drag.value.fromIndex) {
    const reordered = [...props.scenes]
    const [moved] = reordered.splice(drag.value.fromIndex, 1)
    reordered.splice(drag.value.overIndex, 0, moved)
    emit('reorder', reordered.map(s => s.id))
  }
  drag.value = { active: false, sceneId: null, fromIndex: -1, overIndex: -1, startX: 0, dx: 0 }
}

// ── Playhead drag + ruler click ───────────────────────────────────────────────
const seekRef = ref(null)
const seekingPlayhead = ref(false)

function onRulerClick(e) {
  const rect = seekRef.value?.getBoundingClientRect()
  if (!rect) return
  const pct = Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100))
  emit('seek', pct)
}

function onPlayheadMousedown(e) {
  e.stopPropagation()
  seekingPlayhead.value = true
  window.addEventListener('mousemove', onPlayheadMove)
  window.addEventListener('mouseup', onPlayheadUp)
}

function onPlayheadMove(e) {
  const rect = seekRef.value?.getBoundingClientRect()
  if (!rect) return
  const pct = Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100))
  emit('seek', pct)
}

function onPlayheadUp() {
  seekingPlayhead.value = false
  window.removeEventListener('mousemove', onPlayheadMove)
  window.removeEventListener('mouseup', onPlayheadUp)
}

onBeforeUnmount(() => {
  window.removeEventListener('mousemove', onDragMove)
  window.removeEventListener('mouseup', onDragEnd)
  window.removeEventListener('mousemove', onPlayheadMove)
  window.removeEventListener('mouseup', onPlayheadUp)
})

// ── Computed track width for zoom ─────────────────────────────────────────────
const trackStyle = computed(() => ({
  minWidth: zoom.value === 1 ? '100%' : `${zoom.value * 100}%`,
}))
</script>

<template>
  <div class="tl-root">

    <!-- Header -->
    <div class="tl-header">
      <span class="tl-header-label">Timeline</span>
      <span class="tl-timecode">
        {{ ((playheadPct / 100) * total).toFixed(1) }}s &nbsp;/&nbsp; {{ total.toFixed(1) }}s
      </span>
      <div class="tl-zoom">
        <button :class="['tl-zoom-btn', zoom === 1 ? 'active' : '']" @click="zoom = 1">Fit</button>
        <button :class="['tl-zoom-btn', zoom === 2 ? 'active' : '']" @click="zoom = 2">2×</button>
        <button :class="['tl-zoom-btn', zoom === 3 ? 'active' : '']" @click="zoom = 3">3×</button>
      </div>
      <button class="tl-close" title="Close timeline" @click="emit('close')">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- Body -->
    <div class="tl-body">

      <!-- Track labels -->
      <div class="tl-labels">
        <div class="tl-label-spacer"></div><!-- ruler height -->
        <div class="tl-label scenes"><span class="tl-track-icon">▦</span> Scenes</div>
        <div class="tl-label voice"><span class="tl-track-icon">♪</span> Voice</div>
        <div class="tl-label sounds"><span class="tl-track-icon">◎</span> Sounds</div>
        <div class="tl-label music"><span class="tl-track-icon">♫</span> Music</div>
        <div class="tl-label captions"><span class="tl-track-icon">T</span> Captions</div>
      </div>

      <!-- Scrollable track area -->
      <div class="tl-scroll">
        <div class="tl-tracks" :style="trackStyle" ref="seekRef">

          <!-- Ruler -->
          <div class="tl-ruler" @click="onRulerClick">
            <span
              v-for="tick in rulerTicks" :key="tick.secs"
              class="tl-tick"
              :style="{ left: tick.pct + '%' }"
            >{{ tick.label }}</span>
          </div>

          <!-- Relative container for all tracks + playhead -->
          <div class="tl-track-area" ref="tracksRef">

            <!-- Scene track -->
            <div class="tl-track scene-track">
              <div
                v-for="block in sceneBlocks" :key="block.id"
                :class="['tl-scene-block', block.active ? 'active' : '', drag.active && drag.sceneId === block.id ? 'dragging' : '']"
                :style="{ left: block.startPct + '%', width: 'calc(' + block.widthPct + '% - 2px)' }"
                @mousedown="onSceneMousedown($event, block)"
                @click="emit('scene-select', block.id)"
              >
                <span class="block-label">{{ block.label }}</span>
                <span class="block-dur">{{ block.durLabel }}</span>
              </div>
              <!-- Drop indicator -->
              <div
                v-if="drag.active && drag.overIndex !== drag.fromIndex"
                class="drop-indicator"
                :style="{ left: (sceneBlocks[drag.overIndex]?.startPct ?? 0) + '%' }"
              ></div>
            </div>

            <!-- Voice track -->
            <div class="tl-track voice-track">
              <svg
                v-for="block in sceneBlocks" :key="block.id"
                class="voice-wave"
                :style="{ left: block.startPct + '%', width: 'calc(' + block.widthPct + '% - 2px)' }"
                viewBox="0 0 600 18"
                preserveAspectRatio="none"
              >
                <path :d="waveformPath(block.id, 0)" stroke="#4A7FCA" stroke-width="1.2" fill="none" opacity="0.65"/>
                <path :d="waveformPath(block.id, 1)" stroke="#4A7FCA" stroke-width="1.2" fill="none" opacity="0.65"/>
              </svg>
            </div>

            <!-- Sounds track -->
            <div class="tl-track sounds-track">
              <div
                v-for="block in sceneBlocks" :key="block.id"
                class="tl-sound-slot"
                :style="{ left: block.startPct + '%', width: 'calc(' + block.widthPct + '% - 2px)' }"
                title="Add sound effect"
              >
                <span class="sound-add">+</span>
              </div>
            </div>

            <!-- Music track -->
            <div class="tl-track music-track">
              <div v-if="musicTrack" class="tl-music-bar" :title="musicTrack.title">
                <span class="music-bar-label">♫ {{ musicTrack.title }}</span>
              </div>
              <div v-else class="tl-music-empty">No music selected</div>
            </div>

            <!-- Captions track -->
            <div class="tl-track captions-track">
              <div
                v-for="block in sceneBlocks" :key="block.id"
                class="tl-caption-block"
                :style="{ left: block.startPct + '%', width: 'calc(' + block.widthPct + '% - 2px)' }"
              >{{ block.scriptSnippet }}</div>
            </div>

            <!-- Playhead -->
            <div
              class="tl-playhead"
              :style="{ left: playheadPct + '%' }"
            >
              <div class="tl-playhead-head" @mousedown.prevent="onPlayheadMousedown"></div>
            </div>

          </div><!-- tl-track-area -->
        </div><!-- tl-tracks -->
      </div><!-- tl-scroll -->
    </div><!-- tl-body -->
  </div>
</template>

<style scoped>
.tl-root {
  display: flex;
  flex-direction: column;
  background: #0b0b10;
  border-top: 1px solid #1e1e28;
  overflow: hidden;
  user-select: none;
  flex-shrink: 0;
}

/* ── Header ── */
.tl-header {
  height: 36px;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 14px;
  border-bottom: 1px solid #1e1e28;
  flex-shrink: 0;
}
.tl-header-label {
  font-size: 10px;
  font-weight: 600;
  color: #4a4a58;
  text-transform: uppercase;
  letter-spacing: .08em;
}
.tl-timecode {
  font-family: 'Space Mono', monospace;
  font-size: 10px;
  color: #6b6b73;
}
.tl-zoom {
  margin-left: auto;
  display: flex;
  gap: 2px;
}
.tl-zoom-btn {
  font-size: 10px;
  padding: 2px 8px;
  border-radius: 4px;
  border: 1px solid #1e1e28;
  background: transparent;
  color: #4a4a58;
  cursor: pointer;
  font-family: inherit;
  transition: .12s;
}
.tl-zoom-btn.active { background: #17171c; color: #9a9aab; border-color: #2a2a38; }
.tl-close {
  width: 22px;
  height: 22px;
  border-radius: 4px;
  border: 1px solid #1e1e28;
  background: transparent;
  color: #4a4a58;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: .12s;
}
.tl-close:hover { color: #e5e5e7; border-color: #2a2a38; background: #17171c; }

/* ── Body ── */
.tl-body {
  flex: 1;
  display: flex;
  overflow: hidden;
  min-height: 0;
}

/* Track labels */
.tl-labels {
  width: 76px;
  flex-shrink: 0;
  border-right: 1px solid #1e1e28;
  display: flex;
  flex-direction: column;
  padding-bottom: 4px;
}
.tl-label-spacer { height: 22px; flex-shrink: 0; }
.tl-label {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 0 10px;
  font-size: 10px;
  color: #4a4a58;
  flex-shrink: 0;
}
.tl-label.scenes  { height: 38px; }
.tl-label.voice   { height: 32px; }
.tl-label.sounds  { height: 26px; }
.tl-label.music   { height: 26px; }
.tl-label.captions{ height: 22px; }
.tl-track-icon {
  width: 14px;
  height: 14px;
  border-radius: 3px;
  background: #17171c;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 8px;
  color: #6b6b73;
  flex-shrink: 0;
}

/* Scroll container */
.tl-scroll {
  flex: 1;
  overflow-x: auto;
  overflow-y: hidden;
  padding: 0 8px 4px;
}
.tl-scroll::-webkit-scrollbar { height: 4px; }
.tl-scroll::-webkit-scrollbar-track { background: transparent; }
.tl-scroll::-webkit-scrollbar-thumb { background: #1e1e28; border-radius: 2px; }

/* Track column */
.tl-tracks {
  display: flex;
  flex-direction: column;
  gap: 4px;
  height: 100%;
}

/* Ruler */
.tl-ruler {
  height: 22px;
  position: relative;
  cursor: crosshair;
  flex-shrink: 0;
}
.tl-tick {
  position: absolute;
  top: 6px;
  transform: translateX(-50%);
  font-family: 'Space Mono', monospace;
  font-size: 9px;
  color: #2a2a38;
  white-space: nowrap;
}
.tl-tick::before {
  content: '';
  position: absolute;
  top: -4px;
  left: 50%;
  width: 1px;
  height: 4px;
  background: #1e1e28;
}

/* Track area (relative, all tracks + playhead) */
.tl-track-area {
  position: relative;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* Generic track */
.tl-track {
  position: relative;
  border-radius: 5px;
  overflow: visible;
  flex-shrink: 0;
}

/* Scene track */
.scene-track { height: 38px; background: #0d0d11; }
.tl-scene-block {
  position: absolute;
  top: 2px;
  height: calc(100% - 4px);
  background: #1f1a17;
  border-left: 2px solid #FF6B35;
  border-radius: 3px;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 7px;
  font-size: 10px;
  color: #c4a590;
  overflow: hidden;
  white-space: nowrap;
  cursor: grab;
  transition: filter .1s;
  box-sizing: border-box;
}
.tl-scene-block:hover { filter: brightness(1.2); }
.tl-scene-block.active {
  background: #2a1f16;
  border: 1.5px solid #FF6B35;
  color: #ff9d6b;
}
.tl-scene-block.dragging {
  opacity: 0.5;
  cursor: grabbing;
}
.block-label { font-weight: 700; font-size: 9px; flex-shrink: 0; }
.block-dur { font-size: 9px; color: #7a6a5a; }
.drop-indicator {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 2px;
  background: #FF6B35;
  border-radius: 2px;
  transform: translateX(-1px);
  pointer-events: none;
  box-shadow: 0 0 6px rgba(255,107,53,.5);
}

/* Voice track */
.voice-track { height: 32px; background: #0d0d11; }
.voice-wave {
  position: absolute;
  top: 7px;
  height: 18px;
  display: block;
  box-sizing: border-box;
}

/* Sounds track */
.sounds-track { height: 26px; background: #0d0d11; }
.tl-sound-slot {
  position: absolute;
  top: 3px;
  height: calc(100% - 6px);
  border: 1px dashed #1e1e28;
  border-radius: 3px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: .12s;
  box-sizing: border-box;
}
.tl-sound-slot:hover { border-color: rgba(255,107,53,.4); background: rgba(255,107,53,.04); }
.sound-add { font-size: 11px; color: #2a2a38; }
.tl-sound-slot:hover .sound-add { color: #FF6B35; }

/* Music track */
.music-track { height: 26px; background: #0d0d11; padding: 5px 6px; }
.tl-music-bar {
  width: 100%;
  height: 100%;
  background: linear-gradient(to right, #2a2438, #3d3352 50%, #2a2438);
  border-radius: 3px;
  opacity: 0.75;
  display: flex;
  align-items: center;
  padding: 0 8px;
  overflow: hidden;
}
.music-bar-label {
  font-size: 9px;
  color: #9a8ab0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tl-music-empty {
  width: 100%;
  height: 100%;
  border: 1px dashed #1e1e28;
  border-radius: 3px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  color: #2a2a38;
}

/* Captions track */
.captions-track { height: 22px; }
.tl-caption-block {
  position: absolute;
  top: 0;
  height: 100%;
  background: #0d0d11;
  border-radius: 3px;
  display: flex;
  align-items: center;
  padding: 0 6px;
  font-size: 9px;
  color: #3a3a48;
  overflow: hidden;
  white-space: nowrap;
  box-sizing: border-box;
}

/* Playhead */
.tl-playhead {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 0;
  pointer-events: none;
  z-index: 10;
}
.tl-playhead::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 1.5px;
  height: 100%;
  background: #FF6B35;
  transform: translateX(-50%);
  box-shadow: 0 0 4px rgba(255,107,53,.4);
}
.tl-playhead-head {
  position: absolute;
  top: -3px;
  left: 50%;
  transform: translateX(-50%);
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #FF6B35;
  cursor: ew-resize;
  pointer-events: all;
  box-shadow: 0 0 0 2px rgba(255,107,53,.25);
}
</style>
