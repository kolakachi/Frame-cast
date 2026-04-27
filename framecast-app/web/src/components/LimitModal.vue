<script setup>
const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Plan limit reached' },
  subtitle: { type: String, default: 'Upgrade to continue.' },
  rows: { type: Array, default: () => [] },
})

const emit = defineEmits(['close', 'upgrade'])
</script>

<template>
  <div v-if="open" class="modal-overlay" @click.self="emit('close')">
    <div class="limit-modal">
      <div class="limit-kicker">Usage and Billing</div>
      <div class="limit-title">{{ title }}</div>
      <div class="limit-copy">{{ subtitle }}</div>

      <div class="limit-rows">
        <div v-for="row in rows" :key="row.label" class="limit-row">
          <div class="limit-row-head">
            <span>{{ row.label }}</span>
            <span :style="{ color: row.color || '#ff6b35' }">{{ row.used }} / {{ row.limit }}</span>
          </div>
          <div class="limit-bar">
            <div class="limit-bar-fill" :style="{ width: `${row.pct}%`, background: row.color || '#ff6b35' }"></div>
          </div>
        </div>
      </div>

      <div class="limit-actions">
        <button class="btn btn-ghost" type="button" @click="emit('close')">Close</button>
        <button class="btn btn-primary" type="button" @click="emit('upgrade')">View Plans</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.62);
  backdrop-filter: blur(4px);
  z-index: 220;
}

.limit-modal {
  width: min(560px, calc(100vw - 32px));
  max-height: 82vh;
  overflow-y: auto;
  padding: 24px;
  border-radius: 16px;
  background: #111118;
  border: 1px solid #2a2a36;
  box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5);
}

.limit-kicker {
  font-size: 11px;
  color: #6a6a7c;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}

.limit-title {
  margin-top: 10px;
  font-size: 22px;
  font-weight: 700;
  color: #ececf3;
}

.limit-copy {
  margin-top: 8px;
  color: #a1a1b5;
  font-size: 13px;
  line-height: 1.6;
}

.limit-rows {
  display: grid;
  gap: 14px;
  margin-top: 20px;
}

.limit-row-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: 12px;
  color: #ececf3;
  margin-bottom: 6px;
}

.limit-bar {
  height: 6px;
  border-radius: 999px;
  background: #1d1d28;
  overflow: hidden;
}

.limit-bar-fill {
  height: 100%;
  border-radius: 999px;
}

.limit-actions {
  margin-top: 22px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid #2a2a36;
  padding: 8px 14px;
  cursor: pointer;
  background: transparent;
  color: #ececf3;
  font-size: 13px;
  font: inherit;
  transition: 0.15s;
}

.btn:hover { background: rgba(255,255,255,0.04); }
.btn-primary { background: #ff6b35; border-color: #ff6b35; color: #fff; }
.btn-primary:hover { background: #ff875a; }
.btn-ghost { background: transparent; }
</style>
