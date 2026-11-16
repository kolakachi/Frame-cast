<script setup>
// The new editor's loading state, in its own layout: top bar, rail, Script panel, the frame and the timeline. The
// classic skeleton showed the old layout before the new one appeared (owner, 2026-11-16).
</script>

<template>
  <div class="nxs" role="status" aria-label="Loading the editor">
    <div class="nxs-top">
      <span class="nxs-b" style="width: 18px; height: 18px"></span>
      <span class="nxs-b" style="width: 280px; height: 16px"></span>
      <span class="nxs-grow"></span>
      <span class="nxs-b" style="width: 84px; height: 34px; border-radius: 9px"></span>
      <span class="nxs-b nxs-accent" style="width: 116px; height: 34px; border-radius: 9px"></span>
    </div>
    <div class="nxs-body">
      <div class="nxs-rail">
        <span v-for="n in 8" :key="n" class="nxs-b" style="width: 40px; height: 40px; border-radius: 10px"></span>
      </div>
      <div class="nxs-panel">
        <span class="nxs-b" style="width: 90px; height: 18px"></span>
        <span class="nxs-b" style="width: 130px; height: 12px"></span>
        <div v-for="n in 4" :key="n" class="nxs-card">
          <span class="nxs-b" style="width: 40%; height: 11px"></span>
          <span class="nxs-b" style="width: 100%; height: 12px"></span>
          <span class="nxs-b" style="width: 82%; height: 12px"></span>
        </div>
      </div>
      <div class="nxs-main">
        <div class="nxs-stage">
          <span class="nxs-b" style="width: 340px; height: 40px; border-radius: 12px"></span>
          <span class="nxs-b nxs-frame"></span>
        </div>
        <div class="nxs-tl">
          <div class="nxs-tl-head">
            <span class="nxs-b" style="width: 36px; height: 36px; border-radius: 50%"></span>
            <span class="nxs-b" style="width: 90px; height: 12px"></span>
          </div>
          <div class="nxs-lanes">
            <div class="nxs-lane is-tall"><span v-for="w in [120, 150, 90, 110, 140, 100, 130]" :key="w" class="nxs-b" :style="{ width: `${w}px`, height: '62px', borderRadius: '10px' }"></span></div>
            <div v-for="n in 3" :key="n" class="nxs-lane"><span class="nxs-b" style="width: 100%; height: 26px; border-radius: 7px"></span></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.nxs { height: 100vh; height: 100dvh; display: flex; flex-direction: column; background: #141417; overflow: hidden; }
.nxs-b { display: block; flex-shrink: 0; border-radius: 6px; background: linear-gradient(90deg, #1e1e23 0%, #26262c 50%, #1e1e23 100%); background-size: 200% 100%; animation: nxs-shine 1.4s ease-in-out infinite; }
.nxs-accent { background: rgba(240, 122, 69, 0.35); animation: none; }
.nxs-top { height: 56px; display: flex; align-items: center; gap: 14px; padding: 0 16px; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
.nxs-grow { flex: 1 1 0; }
.nxs-body { flex: 1 1 0; min-height: 0; display: flex; }
.nxs-rail { width: 76px; display: flex; flex-direction: column; align-items: center; gap: 18px; padding: 16px 0; }
.nxs-panel { width: 320px; background: #17171b; border-radius: 16px 0 0 0; padding: 22px 20px; display: flex; flex-direction: column; gap: 12px; }
.nxs-card { display: flex; flex-direction: column; gap: 8px; padding: 12px; border-radius: 12px; background: rgba(255, 255, 255, 0.02); }
.nxs-main { flex: 1 1 0; min-width: 0; display: flex; flex-direction: column; }
.nxs-stage { flex: 1 1 0; min-height: 0; background: #0d0d10; display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 14px 0; }
.nxs-frame { flex: 1 1 0; min-height: 0; aspect-ratio: 9 / 16; border-radius: 12px; }
.nxs-tl { height: 258px; padding: 0 16px 14px 96px; border-top: 1px solid rgba(255, 255, 255, 0.06); display: flex; flex-direction: column; }
.nxs-tl-head { height: 52px; display: flex; align-items: center; gap: 12px; margin-left: -80px; }
.nxs-lanes { display: flex; flex-direction: column; gap: 6px; padding-top: 22px; overflow: hidden; }
.nxs-lane { display: flex; gap: 4px; }
@keyframes nxs-shine { 0% { background-position: 100% 0; } 100% { background-position: -100% 0; } }
@media (prefers-reduced-motion: reduce) { .nxs-b { animation: none; } }
</style>
