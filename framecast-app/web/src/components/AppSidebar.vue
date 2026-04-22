<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'

const props = defineProps({
  user: { type: Object, default: null },
  activePage: { type: String, default: '' },
  channelCount: { type: Number, default: 0 },
})

const emit = defineEmits(['logout'])

const router = useRouter()
const showUserPopover = ref(false)
const isAdmin = computed(() => ['super_admin', 'platform_admin'].includes(props.user?.role))

function nav(name) {
  showUserPopover.value = false
  router.push({ name })
}
</script>

<template>
  <nav class="sidebar">
    <div class="sidebar-logo">
      <div class="logo-mark">F</div>
      <div class="logo-text">Framecast</div>
    </div>

    <div class="ws-switcher" role="button" tabindex="0" @click="showUserPopover = !showUserPopover">
      <div class="ws-avatar">{{ user?.name?.[0]?.toUpperCase() || 'W' }}</div>
      <div class="ws-info">
        <div class="ws-name">My Workspace</div>
        <div class="ws-plan">Studio Plan</div>
      </div>
      <span class="ws-caret">⌄</span>
    </div>

    <div class="sidebar-nav">
      <div class="nav-section-label">Workspace</div>
      <button :class="['nav-item', activePage === 'dashboard' ? 'active' : '']" type="button" @click="nav('dashboard')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <rect x="3" y="3" width="7" height="7" rx="1"></rect>
          <rect x="14" y="3" width="7" height="7" rx="1"></rect>
          <rect x="3" y="14" width="7" height="7" rx="1"></rect>
          <rect x="14" y="14" width="7" height="7" rx="1"></rect>
        </svg>
        Dashboard
      </button>
      <button :class="['nav-item', activePage === 'channels' ? 'active' : '']" type="button" @click="nav('channels')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9s10.3-3.9 14.2 0c3.9 3.9 3.9 10.3 0 14.2"></path>
          <path d="m7.5 7.5 9 9M7.5 16.5l9-9"></path>
        </svg>
        Channels
        <span v-if="channelCount > 0" class="nav-count">{{ channelCount }}</span>
      </button>
      <button class="nav-item" type="button" disabled>
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M2 6h4v4H2zM2 14h4v4H2zM10 6h12M10 10h12M10 14h12M10 18h12"></path>
        </svg>
        Series
      </button>
      <button :class="['nav-item', activePage === 'videos' ? 'active' : '']" type="button" @click="nav('videos')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <polygon points="5 3 19 12 5 21 5 3"></polygon>
        </svg>
        All Videos
      </button>
      <button :class="['nav-item', activePage === 'jobs' ? 'active' : '']" type="button" @click="nav('jobs')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="9"></circle>
          <polyline points="12 7 12 12 15 15"></polyline>
        </svg>
        Jobs
      </button>

      <div class="nav-section-label">Library</div>
      <button :class="['nav-item', activePage === 'asset-library' ? 'active' : '']" type="button" @click="nav('asset-library')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"></path>
        </svg>
        Assets
      </button>

      <div class="nav-section-label">Account</div>
      <button :class="['nav-item', activePage === 'settings' ? 'active' : '']" type="button" @click="nav('settings')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        Settings
      </button>
      <button v-if="isAdmin" :class="['nav-item', activePage === 'admin' ? 'active' : '']" type="button" @click="nav('admin')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M12 3l7 3v5c0 4.4-2.8 8.4-7 10-4.2-1.6-7-5.6-7-10V6l7-3z"></path>
          <path d="M9 12l2 2 4-5"></path>
        </svg>
        God Mode
      </button>
    </div>

    <div class="sidebar-bottom">
      <div class="user-row" role="button" tabindex="0" @click="showUserPopover = !showUserPopover">
        <div class="user-avatar">{{ user?.name?.[0]?.toUpperCase() || 'U' }}</div>
        <div class="user-info">
          <div class="user-name">{{ user?.name || 'User' }}</div>
          <div class="user-email">{{ user?.email || '—' }}</div>
        </div>
      </div>
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
.sidebar { position: fixed; inset: 0 auto 0 0; width: 220px; background: var(--color-bg-panel); border-right: 1px solid var(--color-border); display: flex; flex-direction: column; z-index: 100; overflow: hidden; }
.sidebar-logo { padding: 18px 16px 12px; border-bottom: 1px solid var(--color-border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.logo-mark { width: 28px; height: 28px; border-radius: 7px; background: var(--color-accent); display: flex; align-items: center; justify-content: center; color: #fff; font-family: "Space Mono", monospace; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.logo-text { font-size: 14px; font-weight: 700; color: var(--color-text-primary); letter-spacing: -0.3px; }
.ws-switcher { margin: 10px 8px; padding: 9px 10px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 10px; display: flex; align-items: center; gap: 9px; cursor: pointer; transition: 0.15s; flex-shrink: 0; }
.ws-switcher:hover { border-color: var(--color-border-active); }
.ws-avatar { width: 26px; height: 26px; border-radius: 6px; background: linear-gradient(135deg, #7c3aed, #db2777); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; font-family: "Space Mono", monospace; flex-shrink: 0; }
.ws-info { flex: 1; min-width: 0; }
.ws-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ws-plan { font-size: 10px; color: var(--color-text-muted); margin-top: 1px; }
.ws-caret { color: var(--color-text-muted); font-size: 11px; flex-shrink: 0; }
.sidebar-nav { flex: 1; padding: 4px 8px; overflow-y: auto; display: flex; flex-direction: column; gap: 1px; }
.nav-section-label { font-family: "Space Mono", monospace; font-size: 9px; letter-spacing: 0.1em; text-transform: uppercase; color: var(--color-text-muted); padding: 10px 8px 4px; }
.nav-icon { flex-shrink: 0; display: block; }
.nav-count { margin-left: auto; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 999px; padding: 1px 7px; font-size: 9px; font-weight: 700; font-family: "Space Mono", monospace; color: var(--color-text-muted); }
.nav-item { width: 100%; display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--color-text-secondary); cursor: pointer; transition: 0.15s; border: 1px solid transparent; text-align: left; background: transparent; appearance: none; }
.nav-item:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.nav-item.active { background: rgba(255, 107, 53, 0.1); color: var(--color-accent); border-color: rgba(255, 107, 53, 0.18); }
.nav-item:disabled { opacity: 0.4; cursor: default; }
.nav-item:disabled:hover { background: transparent; color: var(--color-text-secondary); }
.sidebar-bottom { border-top: 1px solid var(--color-border); padding: 10px 8px; position: relative; flex-shrink: 0; }
.user-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: 0.15s; }
.user-row:hover { background: var(--color-bg-elevated); }
.user-avatar { width: 28px; height: 28px; border-radius: 999px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }
.user-info { flex: 1; min-width: 0; }
.user-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-email { font-size: 10px; color: var(--color-text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-popover { position: absolute; bottom: 56px; left: 8px; right: 8px; background: var(--color-bg-elevated); border: 1px solid var(--color-border-active); border-radius: 10px; padding: 12px; z-index: 200; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4); }
.user-popover-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.user-popover-email { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.user-popover-divider { border-top: 1px solid var(--color-border); margin: 10px 0; }
.user-popover-action { width: 100%; text-align: left; color: #f87171; font-size: 13px; cursor: pointer; background: transparent; border: none; padding: 0; appearance: none; }
</style>
