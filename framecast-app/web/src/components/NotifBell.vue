<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import api from '../services/api'

const open    = ref(false)
const notifs  = ref([])
const unread  = computed(() => notifs.value.filter(n => !n.is_read).length)

async function load() {
  const res = await api.get('/notifications').catch(() => null)
  notifs.value = res?.data?.data?.notifications ?? []
}

async function markAllRead() {
  await api.post('/notifications/read-all').catch(() => null)
  notifs.value = notifs.value.map(n => ({ ...n, is_read: true }))
}

function formatTime(ts) {
  if (!ts) return ''
  const diff = Math.floor((Date.now() - new Date(ts)) / 60000)
  if (diff < 1) return 'just now'
  if (diff < 60) return `${diff}m ago`
  if (diff < 1440) return `${Math.floor(diff / 60)}h ago`
  return new Date(ts).toLocaleDateString('en', { month: 'short', day: 'numeric' })
}

function onKey(e) { if (e.key === 'Escape') open.value = false }
onMounted(() => { load(); document.addEventListener('keydown', onKey) })
onUnmounted(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="nb-wrap">
    <button class="nb-btn" :class="{ active: open }" title="Notifications" @click="open = !open">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <span v-if="unread > 0" class="nb-badge">{{ unread }}</span>
    </button>

    <Teleport to="body">
      <div v-if="open" class="nb-backdrop" @click="open = false"></div>
      <aside v-if="open" class="nb-drawer">
        <div class="nb-head">
          <div class="nb-title">Notifications</div>
          <button class="nb-mark-read" @click="markAllRead">Mark all read</button>
        </div>
        <div v-if="!notifs.length" class="nb-empty">No notifications yet</div>
        <article
          v-for="n in notifs" :key="n.id"
          :class="['nb-item', n.is_read ? '' : 'unread']"
          @click="!n.is_read && markAllRead()"
        >
          <div :class="['nb-icon', n.type === 'success' ? 'success' : n.type === 'error' ? 'error' : 'info']">
            {{ n.type === 'success' ? '✓' : n.type === 'error' ? '✕' : '•' }}
          </div>
          <div class="nb-body">
            <div class="nb-msg">{{ n.title }}</div>
            <div class="nb-detail">{{ n.message }}</div>
            <div class="nb-time">{{ formatTime(n.created_at) }}</div>
          </div>
          <div v-if="!n.is_read" class="nb-dot"></div>
        </article>
      </aside>
    </Teleport>
  </div>
</template>

<style scoped>
.nb-wrap { position: relative; display: flex; align-items: center; }
.nb-btn { position: relative; width: 32px; height: 32px; border-radius: 7px; border: 1px solid var(--color-border); background: transparent; color: var(--color-text-muted); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: .15s; flex-shrink: 0; }
.nb-btn:hover, .nb-btn.active { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.nb-badge { position: absolute; top: -4px; right: -4px; background: var(--color-accent); color: #fff; font-size: 9px; font-weight: 700; border-radius: 999px; min-width: 14px; height: 14px; display: flex; align-items: center; justify-content: center; padding: 0 3px; }
.nb-backdrop { position: fixed; inset: 0; z-index: 150; }
.nb-drawer { position: fixed; top: 0; right: 0; width: 320px; height: 100vh; background: var(--color-bg-card); border-left: 1px solid var(--color-border); z-index: 151; display: flex; flex-direction: column; box-shadow: -8px 0 32px rgba(0,0,0,.3); overflow-y: auto; }
.nb-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--color-border); flex-shrink: 0; position: sticky; top: 0; background: var(--color-bg-card); }
.nb-title { font-size: 14px; font-weight: 600; }
.nb-mark-read { font-size: 11px; color: var(--color-text-muted); background: none; border: none; cursor: pointer; font-family: inherit; transition: .15s; }
.nb-mark-read:hover { color: var(--color-text-primary); }
.nb-empty { padding: 32px 18px; text-align: center; font-size: 13px; color: var(--color-text-muted); }
.nb-item { display: flex; align-items: flex-start; gap: 10px; padding: 12px 18px; cursor: pointer; transition: .15s; border-bottom: 1px solid var(--color-border); position: relative; }
.nb-item:hover { background: var(--color-bg-elevated); }
.nb-item.unread { background: rgba(255,107,53,.03); }
.nb-icon { width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }
.nb-icon.success { background: rgba(52,211,153,.15); color: #34d399; }
.nb-icon.error   { background: rgba(248,113,113,.15); color: #f87171; }
.nb-icon.info    { background: rgba(96,165,250,.15); color: #60a5fa; }
.nb-body { flex: 1; min-width: 0; }
.nb-msg    { font-size: 13px; font-weight: 500; margin-bottom: 2px; }
.nb-detail { font-size: 11px; color: var(--color-text-muted); line-height: 1.4; margin-bottom: 4px; }
.nb-time   { font-size: 10px; color: var(--color-text-muted); opacity: .7; }
.nb-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--color-accent); flex-shrink: 0; margin-top: 6px; }
</style>
