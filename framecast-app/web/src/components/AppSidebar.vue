<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useWorkspaceStore } from '../stores/workspace'

const props = defineProps({
  user: { type: Object, default: null },
  activePage: { type: String, default: '' },
  channelCount: { type: Number, default: 0 },
  collapsed: { type: Boolean, default: false },
})

const emit = defineEmits(['logout'])

const router = useRouter()
const workspaceStore = useWorkspaceStore()
const showWsPopover = ref(false)
const showUserPopover = ref(false)
const isAdmin = computed(() => ['super_admin', 'platform_admin'].includes(props.user?.role))

const planColors = { free: '#6b7280', studio: '#7c3aed', scale: '#0ea5e9', enterprise: '#f59e0b' }
const planColor = computed(() => planColors[workspaceStore.planTier] ?? '#6b7280')

const exportsPct = computed(() => {
  const u = workspaceStore.usage
  if (!u || !u.render_limit) return 0
  return Math.min(100, Math.round((u.renders_used / u.render_limit) * 100))
})

function nav(name) {
  showWsPopover.value = false
  showUserPopover.value = false
  router.push({ name })
}

function openWsPopover() {
  showUserPopover.value = false
  showWsPopover.value = !showWsPopover.value
}

function openUserPopover() {
  showWsPopover.value = false
  showUserPopover.value = !showUserPopover.value
}

function goWorkspaceSettings() {
  showWsPopover.value = false
  router.push({ name: 'workspace' })
}

function handleOutsideClick(e) {
  if (!e.target.closest('.ws-switcher') && !e.target.closest('.ws-popover')) {
    showWsPopover.value = false
  }
  if (!e.target.closest('.user-row') && !e.target.closest('.user-popover')) {
    showUserPopover.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleOutsideClick)
  const wsId = props.user?.workspace_id
  if (wsId && !workspaceStore.workspace && !workspaceStore.loading) {
    workspaceStore.load(wsId)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('click', handleOutsideClick)
})
</script>

<template>
  <nav :class="['sidebar', collapsed ? 'collapsed' : '']">
    <div class="sidebar-logo">
      <div class="logo-mark">F</div>
      <div class="logo-text">Framecast</div>
    </div>

    <!-- Workspace switcher -->
    <div :class="['ws-switcher', showWsPopover ? 'open' : '', activePage === 'workspace' ? 'active' : '']" role="button" tabindex="0" @click.stop="openWsPopover">
      <div class="ws-avatar">{{ workspaceStore.workspaceName[0]?.toUpperCase() || 'W' }}</div>
      <div class="ws-info">
        <div class="ws-name">{{ workspaceStore.workspaceName }}</div>
        <div class="ws-plan">{{ workspaceStore.planLabel }} Plan</div>
      </div>
      <svg class="ws-caret" :style="{ transform: showWsPopover ? 'rotate(180deg)' : 'rotate(0deg)' }" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polyline points="6 9 12 15 18 9"></polyline>
      </svg>
    </div>

    <!-- Workspace popup -->
    <div v-if="showWsPopover" class="ws-popover" @click.stop>
      <!-- Current workspace block -->
      <div class="ws-popover-ws" role="button" tabindex="0" @click="goWorkspaceSettings">
        <div class="ws-popover-avatar">{{ workspaceStore.workspaceName[0]?.toUpperCase() || 'W' }}</div>
        <div class="ws-popover-info">
          <div class="ws-popover-name">{{ workspaceStore.workspaceName }}</div>
          <div class="ws-popover-plan" :style="{ color: planColor }">{{ workspaceStore.planLabel }} Plan</div>
        </div>
        <svg class="ws-popover-arrow" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M9 18l6-6-6-6"></path>
        </svg>
      </div>

      <!-- Usage mini-bar -->
      <div v-if="workspaceStore.usage" class="ws-popover-usage">
        <div class="ws-usage-row">
          <span class="ws-usage-label">Exports</span>
          <span class="ws-usage-val">{{ workspaceStore.usage.renders_used }}/{{ workspaceStore.usage.render_limit }}</span>
        </div>
        <div class="ws-usage-track">
          <div class="ws-usage-fill" :style="{ width: exportsPct + '%', background: exportsPct >= 90 ? '#f87171' : exportsPct >= 70 ? '#f59e0b' : '#4ade80' }"></div>
        </div>
      </div>

      <div class="ws-popover-divider"></div>

      <button class="ws-popover-action" type="button" @click="goWorkspaceSettings">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        Workspace Settings
      </button>

      <button class="ws-popover-action ws-popover-disabled" type="button" disabled title="Multi-workspace support coming soon">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M12 5v14M5 12h14"></path>
        </svg>
        Create Workspace
        <span class="ws-soon-badge">Soon</span>
      </button>
    </div>

    <div class="sidebar-nav">
      <div class="nav-section-label">Workspace</div>
      <button :class="['nav-item', activePage === 'dashboard' ? 'active' : '']" data-tooltip="Dashboard" type="button" @click="nav('dashboard')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <rect x="3" y="3" width="7" height="7" rx="1"></rect>
          <rect x="14" y="3" width="7" height="7" rx="1"></rect>
          <rect x="3" y="14" width="7" height="7" rx="1"></rect>
          <rect x="14" y="14" width="7" height="7" rx="1"></rect>
        </svg>
        Dashboard
      </button>
      <button :class="['nav-item', activePage === 'channels' ? 'active' : '']" data-tooltip="Channels" type="button" @click="nav('channels')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M4.9 19.1C1 15.2 1 8.8 4.9 4.9s10.3-3.9 14.2 0c3.9 3.9 3.9 10.3 0 14.2"></path>
          <path d="m7.5 7.5 9 9M7.5 16.5l9-9"></path>
        </svg>
        Channels
        <span v-if="channelCount > 0" class="nav-count">{{ channelCount }}</span>
      </button>
      <button :class="['nav-item', activePage === 'series' ? 'active' : '']" data-tooltip="Series" type="button" @click="nav('series')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M2 6h4v4H2zM2 14h4v4H2zM10 6h12M10 10h12M10 14h12M10 18h12"></path>
        </svg>
        Series
      </button>
      <button :class="['nav-item', activePage === 'videos' ? 'active' : '']" data-tooltip="All Videos" type="button" @click="nav('videos')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <polygon points="5 3 19 12 5 21 5 3"></polygon>
        </svg>
        All Videos
      </button>
      <button :class="['nav-item', activePage === 'jobs' ? 'active' : '']" data-tooltip="Jobs" type="button" @click="nav('jobs')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="9"></circle>
          <polyline points="12 7 12 12 15 15"></polyline>
        </svg>
        Jobs
      </button>

      <div class="nav-section-label">Library</div>
      <button :class="['nav-item', activePage === 'asset-library' ? 'active' : '']" data-tooltip="Assets" type="button" @click="nav('asset-library')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2z"></path>
        </svg>
        Assets
      </button>

      <div class="nav-section-label">Account</div>
      <button :class="['nav-item', activePage === 'settings' ? 'active' : '']" data-tooltip="Settings" type="button" @click="nav('settings')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <circle cx="12" cy="12" r="3"></circle>
          <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
        </svg>
        Settings
      </button>
      <button v-if="isAdmin" :class="['nav-item', activePage === 'admin' ? 'active' : '']" data-tooltip="God Mode" type="button" @click="nav('admin')">
        <svg class="nav-icon" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M12 3l7 3v5c0 4.4-2.8 8.4-7 10-4.2-1.6-7-5.6-7-10V6l7-3z"></path>
          <path d="M9 12l2 2 4-5"></path>
        </svg>
        God Mode
      </button>
    </div>

    <div class="sidebar-bottom">
      <div class="user-row" role="button" tabindex="0" @click.stop="openUserPopover">
        <div class="user-avatar">{{ user?.name?.[0]?.toUpperCase() || 'U' }}</div>
        <div class="user-info">
          <div class="user-name">{{ user?.name || 'User' }}</div>
          <div class="user-email">{{ user?.email || '—' }}</div>
        </div>
      </div>
      <div v-if="showUserPopover" class="user-popover" @click.stop>
        <div class="user-popover-name">{{ user?.name || 'User' }}</div>
        <div class="user-popover-email">{{ user?.email || '—' }}</div>
        <div class="user-popover-divider"></div>
        <button class="user-popover-action" type="button" @click="nav('settings')">Account Settings</button>
        <button class="user-popover-action danger" type="button" @click="emit('logout')">Log out</button>
      </div>
    </div>
  </nav>
</template>

<style scoped>
.sidebar { position: fixed; inset: 0 auto 0 0; width: 220px; background: var(--color-bg-panel); border-right: 1px solid var(--color-border); display: flex; flex-direction: column; z-index: 100; overflow: hidden; }
.sidebar-logo { padding: 18px 16px 12px; border-bottom: 1px solid var(--color-border); display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.logo-mark { width: 28px; height: 28px; border-radius: 7px; background: var(--color-accent); display: flex; align-items: center; justify-content: center; color: #fff; font-family: "Space Mono", monospace; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.logo-text { font-size: 14px; font-weight: 700; color: var(--color-text-primary); letter-spacing: -0.3px; }

.ws-switcher { margin: 10px 8px 0; padding: 9px 10px; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 10px; display: flex; align-items: center; gap: 9px; cursor: pointer; transition: 0.15s; flex-shrink: 0; position: relative; z-index: 102; }
.ws-switcher:hover { border-color: var(--color-border-active); }
.ws-switcher.open, .ws-switcher.active { border-color: rgba(255, 107, 53, 0.4); background: rgba(255, 107, 53, 0.06); }
.ws-avatar { width: 26px; height: 26px; border-radius: 6px; background: linear-gradient(135deg, #7c3aed, #db2777); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: #fff; font-family: "Space Mono", monospace; flex-shrink: 0; }
.ws-info { flex: 1; min-width: 0; }
.ws-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ws-plan { font-size: 10px; color: var(--color-text-muted); margin-top: 1px; }
.ws-caret { color: var(--color-text-muted); flex-shrink: 0; transition: transform 0.2s; }

/* Workspace popup */
.ws-popover { position: absolute; top: 108px; left: 8px; right: 8px; background: var(--color-bg-elevated); border: 1px solid var(--color-border-active); border-radius: 12px; padding: 8px; z-index: 101; box-shadow: 0 12px 32px rgba(0, 0, 0, 0.5); }

.ws-popover-ws { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 8px; cursor: pointer; transition: 0.15s; }
.ws-popover-ws:hover { background: rgba(255,255,255,0.05); }
.ws-popover-avatar { width: 30px; height: 30px; border-radius: 8px; background: linear-gradient(135deg, #7c3aed, #db2777); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; font-family: "Space Mono", monospace; flex-shrink: 0; }
.ws-popover-info { flex: 1; min-width: 0; }
.ws-popover-name { font-size: 12px; font-weight: 600; color: var(--color-text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ws-popover-plan { font-size: 10px; font-weight: 700; margin-top: 1px; font-family: "Space Mono", monospace; }
.ws-popover-arrow { color: var(--color-text-muted); flex-shrink: 0; }

.ws-popover-usage { padding: 6px 10px 8px; }
.ws-usage-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
.ws-usage-label { font-size: 10px; color: var(--color-text-muted); }
.ws-usage-val { font-size: 10px; color: var(--color-text-muted); font-family: "Space Mono", monospace; }
.ws-usage-track { height: 3px; background: var(--color-border); border-radius: 999px; overflow: hidden; }
.ws-usage-fill { height: 100%; border-radius: 999px; transition: width 0.4s; }

.ws-popover-divider { border-top: 1px solid var(--color-border); margin: 6px 0; }

.ws-popover-action { width: 100%; display: flex; align-items: center; gap: 8px; padding: 7px 10px; border-radius: 7px; font-size: 12px; font-weight: 500; color: var(--color-text-secondary); background: transparent; border: none; cursor: pointer; text-align: left; transition: 0.15s; }
.ws-popover-action:hover:not(:disabled) { background: rgba(255,255,255,0.05); color: var(--color-text-primary); }
.ws-popover-disabled { opacity: 0.45; cursor: default; }
.ws-soon-badge { margin-left: auto; font-size: 9px; font-weight: 700; font-family: "Space Mono", monospace; background: rgba(255,107,53,0.15); color: var(--color-accent); border-radius: 4px; padding: 1px 5px; letter-spacing: 0.05em; }

.sidebar-nav { flex: 1; padding: 4px 8px; overflow-y: auto; display: flex; flex-direction: column; gap: 1px; margin-top: 8px; }
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
.user-popover { position: fixed; bottom: 16px; left: 8px; width: 204px; background: var(--color-bg-elevated); border: 1px solid var(--color-border-active); border-radius: 10px; padding: 12px; z-index: 300; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4); }
.user-popover-name { font-size: 13px; font-weight: 600; color: var(--color-text-primary); }
.user-popover-email { font-size: 11px; color: var(--color-text-muted); margin-top: 2px; }
.user-popover-divider { border-top: 1px solid var(--color-border); margin: 10px 0; }
.user-popover-action { width: 100%; display: block; text-align: left; font-size: 13px; cursor: pointer; background: transparent; border: none; padding: 4px 0; color: var(--color-text-secondary); appearance: none; }
.user-popover-action:hover { color: var(--color-text-primary); }
.user-popover-action.danger { color: #f87171; }
.user-popover-action.danger:hover { color: #fca5a5; }

/* ── Collapsed (icon-only) mode ── */
.sidebar { transition: width 0.2s ease; }
.sidebar.collapsed { width: 56px; }
.sidebar.collapsed .logo-text { display: none; }
.sidebar.collapsed .sidebar-logo { justify-content: center; padding: 14px 0; }
.sidebar.collapsed .ws-switcher { padding: 7px; justify-content: center; }
.sidebar.collapsed .ws-info,
.sidebar.collapsed .ws-caret { display: none; }
.sidebar.collapsed .nav-section-label { display: none; }
.sidebar.collapsed .nav-item { padding: 10px; justify-content: center; font-size: 0; }
.sidebar.collapsed .nav-count { display: none; }
.sidebar.collapsed .user-info { display: none; }
.sidebar.collapsed .user-row { justify-content: center; padding: 8px; }

/* Tooltips on collapsed nav items */
.sidebar.collapsed { overflow: visible; }
.sidebar.collapsed .sidebar-nav { overflow: visible; }
.sidebar.collapsed .nav-item { position: relative; }
.sidebar.collapsed .nav-item::after {
  content: attr(data-tooltip);
  position: absolute;
  left: calc(100% + 10px);
  top: 50%;
  transform: translateY(-50%);
  background: #1e1e28;
  color: #e5e5e7;
  font-size: 12px;
  font-weight: 500;
  padding: 5px 10px;
  border-radius: 6px;
  border: 1px solid #2a2a38;
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.15s ease;
  z-index: 200;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
}
.sidebar.collapsed .nav-item:hover::after { opacity: 1; }
</style>
