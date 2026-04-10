<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

defineProps({
  user: { type: Object, default: null },
  activePage: { type: String, default: '' },
})

const emit = defineEmits(['logout'])

const router = useRouter()
const showUserPopover = ref(false)

function nav(name) {
  showUserPopover.value = false
  router.push({ name })
}
</script>

<template>
  <nav class="sidebar">
    <div class="sidebar-logo">F</div>

    <div class="sidebar-nav">
      <button :class="['nav-item', activePage === 'dashboard' ? 'active' : '']" type="button" @click="nav('dashboard')">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <rect x="3" y="3" width="7" height="7" rx="1" />
          <rect x="14" y="3" width="7" height="7" rx="1" />
          <rect x="3" y="14" width="7" height="7" rx="1" />
          <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
        <span class="tooltip">Dashboard</span>
      </button>

      <button :class="['nav-item', activePage === 'asset-library' ? 'active' : '']" type="button" @click="nav('asset-library')">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z" />
        </svg>
        <span class="tooltip">Asset Library</span>
      </button>

      <button :class="['nav-item', activePage === 'settings' ? 'active' : '']" type="button" @click="nav('settings')">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="3" />
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
        <span class="tooltip">Settings</span>
      </button>
    </div>

    <div class="sidebar-bottom">
      <button class="avatar" type="button" @click="showUserPopover = !showUserPopover">
        {{ user?.name?.[0] || 'U' }}
      </button>

      <div v-if="showUserPopover" class="user-popover">
        <div class="user-popover-name">{{ user?.name || 'User' }}</div>
        <div class="user-popover-email">{{ user?.email || '—' }}</div>
        <div class="user-popover-divider"></div>
        <button class="user-popover-action" type="button" @click="emit('logout')">Log out</button>
      </div>
    </div>
  </nav>
</template>

<style scoped>
.sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: 72px;
  background: rgba(17, 17, 24, 0.96);
  border-right: 1px solid var(--color-border, #2a2a36);
  backdrop-filter: blur(12px);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 16px 0;
  z-index: 100;
}

.sidebar-logo {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--color-accent, #ff6b35), #ff9b72);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-family: "Space Mono", monospace;
  font-weight: 700;
  margin-bottom: 28px;
}

.sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
}

.nav-item {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  color: var(--color-text-muted, #6a6a7c);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  position: relative;
  transition: 0.2s ease;
  background: none;
  border: none;
  padding: 0;
  appearance: none;
}

.nav-item:hover {
  color: var(--color-text-secondary, #a1a1b5);
  background: var(--color-bg-card, #17171f);
}

.nav-item.active {
  color: var(--color-accent, #ff6b35);
  background: rgba(255, 107, 53, 0.14);
  box-shadow: inset 0 0 0 1px rgba(255, 107, 53, 0.18);
}

.nav-item svg {
  display: block;
}

.tooltip {
  position: absolute;
  left: 58px;
  top: 50%;
  transform: translateY(-50%);
  opacity: 0;
  pointer-events: none;
  background: var(--color-bg-elevated, #1d1d28);
  color: var(--color-text-primary, #ececf3);
  font-size: 12px;
  padding: 5px 10px;
  border-radius: 6px;
  border: 1px solid var(--color-border, #2a2a36);
  white-space: nowrap;
  transition: opacity 0.15s ease;
}

.nav-item:hover .tooltip {
  opacity: 1;
}

.sidebar-bottom {
  position: relative;
}

.avatar {
  width: 34px;
  height: 34px;
  padding: 0;
  border-radius: 50%;
  background: linear-gradient(135deg, #2a3a70, #7d3cff);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  border: none;
  line-height: 1;
  appearance: none;
  flex-shrink: 0;
}

.user-popover {
  position: absolute;
  bottom: 52px;
  left: 12px;
  width: 200px;
  background: var(--color-bg-elevated, #1d1d28);
  border: 1px solid var(--color-border-active, #3b3b4f);
  border-radius: 10px;
  padding: 12px;
  z-index: 200;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}

.user-popover-name {
  font-weight: 600;
  font-size: 13px;
  color: var(--color-text-primary, #ececf3);
}

.user-popover-email {
  margin-top: 2px;
  color: var(--color-text-muted, #6a6a7c);
  font-size: 11px;
}

.user-popover-divider {
  height: 1px;
  margin: 10px 0;
  background: var(--color-border, #2a2a36);
}

.user-popover-action {
  width: 100%;
  text-align: left;
  background: transparent;
  color: #f87171;
  border: none;
  cursor: pointer;
  padding: 0;
  font-size: 13px;
}
</style>
