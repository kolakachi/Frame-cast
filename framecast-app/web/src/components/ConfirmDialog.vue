<script setup>
defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: 'Confirm action' },
  message: { type: String, default: '' },
  confirmLabel: { type: String, default: 'Confirm' },
  cancelLabel: { type: String, default: 'Cancel' },
  pending: { type: Boolean, default: false },
  destructive: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'confirm'])
</script>

<template>
  <div v-if="open" class="modal-overlay" @click.self="emit('close')">
    <div class="confirm-modal">
      <div class="confirm-title">{{ title }}</div>
      <div class="confirm-copy">{{ message }}</div>
      <div class="confirm-actions">
        <button class="btn btn-ghost" type="button" :disabled="pending" @click="emit('close')">{{ cancelLabel }}</button>
        <button
          :class="['btn', destructive ? 'btn-danger-solid' : 'btn-primary']"
          type="button"
          :disabled="pending"
          @click="emit('confirm')"
        >
          {{ pending ? 'Working…' : confirmLabel }}
        </button>
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

.confirm-modal {
  width: min(420px, calc(100vw - 32px));
  background: linear-gradient(180deg, rgba(255,255,255,0.018), transparent 100%), #17171f;
  border: 1px solid rgba(248,113,113,0.12);
  border-radius: 16px;
  padding: 22px;
  box-shadow: 0 24px 48px rgba(0, 0, 0, 0.42);
}

.confirm-title {
  font-size: 18px;
  font-weight: 600;
  color: #ececf3;
}

.confirm-copy {
  margin-top: 10px;
  font-size: 13px;
  line-height: 1.6;
  color: #a1a1b5;
}

.confirm-actions {
  margin-top: 22px;
  display: flex;
  justify-content: flex-end;
  gap: 8px;
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
.btn-danger-solid { background: #7f1d1d; border-color: #b91c1c; color: #fff; }
.btn-danger-solid:hover { background: #991b1b; }
</style>
