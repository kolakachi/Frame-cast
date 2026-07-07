<script setup>
// Reusable row skeleton for table/list pages (jobs render queue, and any other
// stacked-row view). Renders inside the real shell so the topbar + filters stay
// put; each row mirrors thumb → id/title → status pill → trailing cell.
defineProps({
  count: { type: Number, default: 6 }, // number of row placeholders
  thumb: { type: Boolean, default: true }, // leading square thumb
});
function delay(i) {
  return ['', 'sk-d1', 'sk-d2', 'sk-d3'][i % 4];
}
</script>

<template>
  <div class="list-sk" aria-busy="true" aria-label="Loading">
    <div v-for="i in count" :key="i" class="ls-row">
      <div v-if="thumb" class="sk ls-thumb" :class="delay(i)"></div>
      <div class="ls-main">
        <div class="sk" :class="delay(i)" style="width: 42%; height: 12px; border-radius: 6px"></div>
        <div class="sk" :class="delay(i)" style="width: 24%; height: 9px; border-radius: 5px"></div>
      </div>
      <div class="sk" :class="delay(i)" style="width: 92px; height: 22px; border-radius: 999px"></div>
      <div class="sk ls-tail" :class="delay(i)"></div>
    </div>
  </div>
</template>

<style scoped>
.list-sk { display: flex; flex-direction: column; }
.ls-row { display: flex; align-items: center; gap: 14px; padding: 15px 8px; border-bottom: 1px solid var(--color-border); }
.ls-thumb { width: 52px; height: 52px; border-radius: 10px; flex: 0 0 auto; }
.ls-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 9px; }
.ls-tail { width: 60px; height: 10px; border-radius: 5px; flex: 0 0 auto; }
@media (max-width: 640px) { .ls-tail { display: none; } }
</style>
