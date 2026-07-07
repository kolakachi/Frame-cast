<script setup>
// Full editor skeleton — the "loading" state renders no shell, so this mirrors
// the whole editor (top bar → Scenes column → center preview → right panel) so
// the load reads as the editor arriving, not a blank screen.
function delay(i) {
  return ['', 'sk-d1', 'sk-d2', 'sk-d3'][i % 4];
}
</script>

<template>
  <div class="ed-sk" aria-busy="true" aria-label="Loading editor">
    <!-- top bar -->
    <div class="ed-top">
      <div class="sk" style="width: 78px; height: 22px; border-radius: 6px"></div>
      <div class="sk" style="width: 260px; height: 32px; border-radius: 10px"></div>
      <div class="ed-spacer"></div>
      <div class="sk" style="width: 90px; height: 34px; border-radius: 9px"></div>
      <div class="sk" style="width: 120px; height: 34px; border-radius: 9px"></div>
    </div>

    <div class="ed-body">
      <!-- scenes column -->
      <div class="ed-col scenes">
        <div class="sk" style="width: 90px; height: 20px; border-radius: 6px; margin-bottom: 16px"></div>
        <div v-for="i in 4" :key="i" class="ed-scene">
          <div class="sk" :class="delay(i)" style="width: 60%; height: 10px; border-radius: 5px"></div>
          <div class="sk" :class="delay(i)" style="width: 100%; height: 12px; border-radius: 6px"></div>
          <div class="sk" :class="delay(i)" style="width: 80%; height: 12px; border-radius: 6px"></div>
          <div class="ed-scene-tags">
            <div class="sk" style="width: 54px; height: 20px; border-radius: 999px"></div>
            <div class="sk" style="width: 74px; height: 20px; border-radius: 999px"></div>
          </div>
        </div>
      </div>

      <!-- center preview -->
      <div class="ed-col center">
        <div class="sk ed-phone"></div>
        <div class="sk" style="width: 150px; height: 32px; border-radius: 10px"></div>
        <div class="ed-controls">
          <div class="sk" style="width: 42px; height: 42px; border-radius: 50%"></div>
          <div class="sk" style="width: 56px; height: 56px; border-radius: 50%"></div>
          <div class="sk" style="width: 42px; height: 42px; border-radius: 50%"></div>
        </div>
      </div>

      <!-- right config panel -->
      <div class="ed-col panel">
        <div class="ed-tabs">
          <div class="sk" style="flex: 1; height: 34px; border-radius: 8px"></div>
          <div class="sk" style="flex: 1; height: 34px; border-radius: 8px"></div>
        </div>
        <div v-for="i in 5" :key="i" class="ed-field">
          <div class="sk" :class="delay(i)" style="width: 40%; height: 9px; border-radius: 5px"></div>
          <div class="sk" :class="delay(i)" style="width: 100%; height: 38px; border-radius: 9px"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ed-sk { min-height: 100vh; background: var(--color-bg-deep); display: flex; flex-direction: column; }
.ed-top { height: 64px; border-bottom: 1px solid var(--color-border); display: flex; align-items: center; gap: 20px; padding: 0 26px; flex-shrink: 0; }
.ed-spacer { flex: 1; }
.ed-body { flex: 1; display: flex; min-height: 0; }
.ed-col { padding: 22px 20px; }
.scenes { width: 360px; border-right: 1px solid var(--color-border); display: flex; flex-direction: column; }
.ed-scene { border: 1px solid var(--color-border); border-radius: 13px; padding: 15px 16px; margin-bottom: 13px; display: flex; flex-direction: column; gap: 10px; }
.ed-scene-tags { display: flex; gap: 8px; margin-top: 2px; }
.center { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 22px; min-width: 0; }
.ed-phone { width: 300px; height: 534px; border-radius: 20px; }
.ed-controls { display: flex; align-items: center; gap: 22px; }
.panel { width: 440px; border-left: 1px solid var(--color-border); display: flex; flex-direction: column; gap: 18px; }
.ed-tabs { display: flex; gap: 10px; }
.ed-field { display: flex; flex-direction: column; gap: 8px; }
@media (max-width: 1000px) {
  .panel { display: none; }
}
</style>
