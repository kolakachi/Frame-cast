<script setup>
// The plan's 3D mascot, drawn live in the browser with the same builder the video uses, turning slowly so it can be
// checked from every angle before the plan is approved. The 3D bundle loads only when a plan has a mascot.
import { ref, onMounted, onBeforeUnmount } from 'vue'

const props = defineProps({ spec: { type: Object, required: true } })
const canvas = ref(null)
const failed = ref(false)
let raf = 0, renderer = null, alive = true

function loadBundle() {
  if (window.W3) return Promise.resolve()
  if (!window.__w3Loading) {
    window.__w3Loading = new Promise((resolve, reject) => {
      const s = document.createElement('script')
      s.src = '/vendor/three-wyv.js'
      s.onload = () => resolve()
      s.onerror = () => { window.__w3Loading = null; reject(new Error('3D preview unavailable')) }
      document.head.appendChild(s)
    })
  }
  return window.__w3Loading
}

function start() {
  const W3 = window.W3, T = W3.THREE, c = canvas.value
  renderer = new T.WebGLRenderer({ canvas: c, alpha: true, antialias: false })
  renderer.setPixelRatio(1); renderer.setSize(c.width, c.height, false); renderer.setClearColor(0x000000, 0)
  const scene = new T.Scene(), cam = new T.PerspectiveCamera(30, c.width / c.height, 0.1, 100)
  const m = W3.buildMascot(props.spec, { cell: 2 }); scene.add(m.root)
  // Head radius r pixels, its centre a little above the middle; the bust runs off the bottom.
  const r = 60, cy = 112
  cam.position.set(0, 0, (c.height / 2) / r / Math.tan(15 * Math.PI / 180))
  const blinks = W3.autoBlinks(3600, 0.8), started = performance.now()
  const frame = () => {
    if (!alive) return
    const t = (performance.now() - started) / 1000, yaw = Math.sin(t * 0.55) * 0.6
    W3.applyRig(m, { x: 0, y: -(cy - c.height / 2) / r - 0.3, yaw, bodyYaw: yaw * 0.45, tilt: Math.sin(t * 0.9) * 0.04 }, W3.faceAt(t, { blinks }))
    renderer.render(scene, cam)
    raf = requestAnimationFrame(frame)
  }
  frame()
}

onMounted(async () => {
  try { await loadBundle(); if (alive) start() } catch { failed.value = true }
})
onBeforeUnmount(() => { alive = false; cancelAnimationFrame(raf); renderer?.dispose() })
</script>

<template>
  <div class="mascot-preview">
    <canvas v-show="!failed" ref="canvas" width="260" height="280" aria-label="Your 3D mascot, turning" />
    <p v-if="failed" class="notice">The 3D preview could not load in this browser, so you have not seen the character yet. Tick "Storyboard only" in this plan to see it in still frames before the video is made.</p>
  </div>
</template>

<style scoped>
.mascot-preview { display: flex; justify-content: center; margin: 8px 0 4px; }
.mascot-preview canvas { width: 260px; height: 280px; border-radius: 14px; background: #fff; }
</style>
