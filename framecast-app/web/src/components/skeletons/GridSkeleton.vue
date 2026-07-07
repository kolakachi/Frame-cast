<script setup>
// Reusable body skeleton for the workspace list pages (channels, videos,
// characters, series, voices, assets…). Renders inside the real shell, so the
// sidebar + topbar stay put and only the body reads as loading. Props tune it
// to each page's card shape rather than shipping a bespoke file per view.
defineProps({
  count: { type: Number, default: 8 },      // number of card placeholders
  min: { type: Number, default: 220 },      // grid card min width (px)
  ratio: { type: String, default: '' },     // thumb aspect-ratio, e.g. '1 / 1' (wins over thumbH)
  thumbH: { type: Number, default: 150 },    // fixed thumb height (px); 0 = no thumb (stack layout)
  layout: { type: String, default: 'stack' }, // 'stack' (thumb on top) | 'row' (thumb left)
  rowThumb: { type: Number, default: 64 },   // square thumb side for row layout (px)
  lines: { type: Number, default: 2 },       // text lines in the card body
  stats: { type: Number, default: 0 },       // number of stat tiles above the grid (0 = none)
  header: { type: Boolean, default: false }, // show a section header (eyebrow + title + button)
});
function delay(i) {
  return ['', 'sk-d1', 'sk-d2', 'sk-d3'][i % 4];
}
</script>

<template>
  <div class="grid-sk" aria-busy="true" aria-label="Loading">
    <div v-if="stats" class="gs-stats">
      <div v-for="i in stats" :key="'s' + i" class="gs-stat">
        <div class="sk" :class="delay(i)" style="width: 60%; height: 9px"></div>
        <div class="sk" :class="delay(i)" style="width: 42%; height: 22px; margin-top: 12px"></div>
        <div class="sk" :class="delay(i)" style="width: 78%; height: 8px; margin-top: 12px"></div>
      </div>
    </div>

    <div v-if="header" class="gs-header">
      <div class="gs-header-titles">
        <div class="sk" style="width: 74px; height: 8px"></div>
        <div class="sk" style="width: 140px; height: 16px; margin-top: 9px"></div>
      </div>
      <div class="sk" style="width: 120px; height: 30px; border-radius: 8px"></div>
    </div>

    <div class="gs-grid" :style="{ '--gs-min': min + 'px' }">
      <div v-for="i in count" :key="i" :class="['gs-card', layout === 'row' ? 'row' : 'stack']">
        <div
          v-if="layout === 'row'"
          class="sk gs-rowthumb"
          :class="delay(i)"
          :style="{ width: rowThumb + 'px', height: rowThumb + 'px' }"
        ></div>
        <div
          v-else-if="ratio || thumbH"
          class="sk gs-thumb"
          :class="delay(i)"
          :style="ratio ? { aspectRatio: ratio } : { height: thumbH + 'px' }"
        ></div>

        <div class="gs-body">
          <div class="sk" :class="delay(i)" style="width: 82%; height: 13px"></div>
          <div
            v-for="n in Math.max(0, lines - 1)"
            :key="n"
            class="sk"
            :class="delay(i)"
            :style="{ width: 62 - n * 8 + '%', height: '9px' }"
          ></div>
          <div v-if="layout !== 'row'" class="gs-foot">
            <div class="sk" style="width: 54px; height: 18px; border-radius: 999px"></div>
            <div class="sk" style="width: 40px; height: 18px; border-radius: 999px"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.grid-sk { display: flex; flex-direction: column; gap: 22px; }
.gs-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
.gs-stat { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 16px 18px; }
.gs-header { display: flex; align-items: center; justify-content: space-between; }
.gs-header-titles { display: flex; flex-direction: column; }
.gs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(var(--gs-min, 220px), 1fr)); gap: 16px; }
.gs-card.stack { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; overflow: hidden; }
.gs-card.row { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 14px 16px; display: flex; gap: 12px; align-items: center; }
.gs-thumb { width: 100%; border-radius: 0; }
.gs-rowthumb { border-radius: 10px; flex: 0 0 auto; }
.gs-body { padding: 14px 15px 16px; display: flex; flex-direction: column; gap: 9px; flex: 1; min-width: 0; }
.gs-card.row .gs-body { padding: 0; }
.gs-foot { display: flex; gap: 8px; margin-top: 5px; }
</style>
