<script setup>
// Body skeleton for Settings — mirrors the two-pane shell (left nav menu +
// right content card with a card row and stacked label/control form rows) so a
// slow load reads as the settings page arriving, not a blank pane. Rendered
// inside the real shell (sidebar + topbar stay put).
function delay(i) {
  return ['', 'sk-d1', 'sk-d2', 'sk-d3'][i % 4];
}
</script>

<template>
  <div class="set-sk" aria-busy="true" aria-label="Loading settings">
    <!-- left nav -->
    <div class="set-menu">
      <div class="sk" style="width: 60px; height: 9px; margin-bottom: 18px"></div>
      <div v-for="i in 4" :key="i" class="sk" :class="delay(i)" style="width: 100%; height: 32px; border-radius: 8px; margin-bottom: 8px"></div>
    </div>

    <!-- right pane -->
    <div class="set-pane">
      <div class="sk" style="width: 180px; height: 17px"></div>
      <div class="sk" style="width: 70%; height: 10px; margin-top: 10px"></div>

      <div class="set-cards">
        <div v-for="i in 3" :key="i" class="set-card">
          <div class="set-swatches">
            <div class="sk" :class="delay(i)" style="width: 20px; height: 20px; border-radius: 6px"></div>
            <div class="sk" :class="delay(i)" style="width: 20px; height: 20px; border-radius: 6px"></div>
            <div class="sk" :class="delay(i)" style="width: 20px; height: 20px; border-radius: 6px"></div>
          </div>
          <div class="sk" :class="delay(i)" style="width: 70%; height: 12px; margin-top: 12px"></div>
          <div class="sk" :class="delay(i)" style="width: 90%; height: 8px; margin-top: 8px"></div>
        </div>
      </div>

      <div v-for="i in 5" :key="i" class="set-row">
        <div class="set-row-label">
          <div class="sk" :class="delay(i)" style="width: 120px; height: 11px"></div>
          <div class="sk" :class="delay(i)" style="width: 150px; height: 8px; margin-top: 8px"></div>
        </div>
        <div class="set-row-control">
          <div class="sk" :class="delay(i)" style="width: 100%; height: 38px; border-radius: 8px"></div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.set-sk { display: grid; grid-template-columns: 230px 1fr; gap: 22px; align-items: start; margin-top: 24px; }
.set-menu { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 18px; }
.set-pane { background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 12px; padding: 18px; }
.set-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin: 20px 0 8px; }
.set-card { background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 10px; padding: 14px; }
.set-swatches { display: flex; gap: 6px; }
.set-row { display: flex; gap: 20px; padding: 18px 0; border-top: 1px solid var(--color-border); }
.set-row-label { width: 180px; flex-shrink: 0; }
.set-row-control { flex: 1; }
@media (max-width: 900px) {
  .set-sk { grid-template-columns: 1fr; }
  .set-row { flex-direction: column; gap: 10px; }
  .set-row-label { width: auto; }
}
</style>
