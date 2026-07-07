<script setup>
// Body skeleton for the dashboard — mirrors the real layout (credits banner →
// "Continue" video strip → a list section) so a slow load reads as loading, not
// empty. Rendered inside the real shell (sidebar + topbar stay put).
function delay(i) {
  return ['', 'sk-d1', 'sk-d2', 'sk-d3'][i % 4];
}
</script>

<template>
  <div class="dash-sk" aria-busy="true" aria-label="Loading dashboard">
    <div class="sk banner"></div>

    <section class="sk-section">
      <div class="sk-head">
        <div class="sk line lg" style="width: 190px"></div>
        <div class="sk line sm" style="width: 60px"></div>
      </div>
      <div class="sk-strip">
        <div v-for="i in 5" :key="i" class="sk-card">
          <div class="sk thumb" :class="delay(i)"></div>
          <div class="sk-card-body">
            <div class="sk line" :class="delay(i)" style="width: 85%"></div>
            <div class="sk line sm" :class="delay(i)" style="width: 55%"></div>
          </div>
        </div>
      </div>
    </section>

    <section class="sk-section">
      <div class="sk-head">
        <div class="sk line lg" style="width: 140px"></div>
      </div>
      <div class="sk-rows">
        <div v-for="i in 3" :key="i" class="sk row-item" :class="delay(i)"></div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dash-sk { padding: 24px 28px; display: flex; flex-direction: column; gap: 28px; }
.banner { height: 50px; border-radius: 14px; }
.sk-section { display: flex; flex-direction: column; gap: 16px; }
.sk-head { display: flex; align-items: center; justify-content: space-between; }
.line { height: 12px; border-radius: 6px; }
.line.lg { height: 18px; }
.line.sm { height: 9px; }
.sk-strip { display: flex; gap: 14px; overflow: hidden; }
.sk-card { flex: 0 0 190px; background: var(--color-bg-card); border: 1px solid var(--color-border); border-radius: 14px; overflow: hidden; }
.sk-card .thumb { width: 100%; height: 300px; border-radius: 0; }
.sk-card-body { padding: 12px 13px 16px; display: flex; flex-direction: column; gap: 9px; }
.sk-rows { display: flex; flex-direction: column; gap: 12px; }
.row-item { height: 62px; border-radius: 12px; }
</style>
