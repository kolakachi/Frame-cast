<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import api from '../services/api'

// "What's new" release notes. Deliberately mounted once in AppSidebar rather
// than per-view like NotifBell (which every view re-declares its own copy of),
// so there is a single instance and a single fetch.
const open    = ref(false)
const entries = ref([])
const unread  = ref(0)

async function load() {
  const res = await api.get('/changelog').catch(() => null)
  entries.value = res?.data?.data?.entries ?? []
  unread.value  = res?.data?.data?.unread_count ?? 0
}

async function toggle() {
  open.value = !open.value
  // Opening IS reading — clear the badge optimistically so the dot doesn't
  // linger while the request is in flight.
  if (open.value && unread.value > 0) {
    unread.value = 0
    await api.post('/changelog/seen').catch(() => null)
  }
}

function formatDate(d) {
  if (!d) return ''
  return new Date(d + 'T00:00:00').toLocaleDateString('en', {
    month: 'short', day: 'numeric', year: 'numeric',
  })
}

function onKey(e) { if (e.key === 'Escape') open.value = false }
onMounted(() => { load(); document.addEventListener('keydown', onKey) })
onUnmounted(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <button class="wn-btn" type="button" title="What's new" @click="toggle">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M12 2l2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z" />
    </svg>
    <span class="wn-label">What's new</span>
    <span v-if="unread > 0" class="wn-dot" :title="`${unread} new`"></span>
  </button>

  <Teleport to="body">
    <div v-if="open" class="wn-backdrop" @click="open = false"></div>
    <aside v-if="open" class="wn-drawer">
      <div class="wn-head">
        <div class="wn-title">What's new</div>
        <button class="wn-close" type="button" @click="open = false">Close</button>
      </div>

      <div v-if="!entries.length" class="wn-empty">Nothing here yet.</div>

      <article v-for="e in entries" :key="e.slug" class="wn-item">
        <div class="wn-meta">
          <span :class="['wn-tag', e.tag]">{{ e.tag }}</span>
          <span class="wn-date">{{ formatDate(e.date) }}</span>
        </div>
        <div class="wn-item-title">{{ e.title }}</div>
        <p class="wn-body">{{ e.body }}</p>
      </article>

      <a class="wn-all" href="https://wyvstudio.com/changelog.html" target="_blank" rel="noopener">
        View full changelog →
      </a>
    </aside>
  </Teleport>
</template>

<style scoped>
.wn-btn { position: relative; display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 10px; border-radius: 7px; border: none; background: transparent; color: var(--color-text-muted); font-family: inherit; font-size: 12px; cursor: pointer; transition: .15s; text-align: left; }
.wn-btn:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.wn-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--color-accent); flex-shrink: 0; margin-left: auto; }

.wn-backdrop { position: fixed; inset: 0; z-index: 150; }
.wn-drawer { position: fixed; top: 0; right: 0; width: 360px; height: 100vh; background: var(--color-bg-card); border-left: 1px solid var(--color-border); z-index: 151; display: flex; flex-direction: column; box-shadow: -8px 0 32px rgba(0,0,0,.3); overflow-y: auto; }
.wn-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--color-border); flex-shrink: 0; position: sticky; top: 0; background: var(--color-bg-card); }
.wn-title { font-size: 14px; font-weight: 600; }
.wn-close { font-size: 11px; color: var(--color-text-muted); background: none; border: none; cursor: pointer; font-family: inherit; transition: .15s; }
.wn-close:hover { color: var(--color-text-primary); }
.wn-empty { padding: 32px 18px; text-align: center; font-size: 13px; color: var(--color-text-muted); }

.wn-item { padding: 14px 18px; border-bottom: 1px solid var(--color-border); }
.wn-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.wn-tag { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 6px; border-radius: 4px; }
.wn-tag.new      { background: rgba(52,211,153,.15); color: #34d399; }
.wn-tag.improved { background: rgba(96,165,250,.15); color: #60a5fa; }
.wn-tag.fixed    { background: rgba(255,107,53,.12); color: var(--color-accent); }
.wn-date { font-size: 10px; color: var(--color-text-muted); opacity: .8; }
.wn-item-title { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
.wn-body { font-size: 12px; color: var(--color-text-muted); line-height: 1.5; margin: 0; }

.wn-all { display: block; padding: 14px 18px; font-size: 12px; color: var(--color-text-muted); text-decoration: none; transition: .15s; }
.wn-all:hover { color: var(--color-text-primary); }

.sidebar.collapsed .wn-label { display: none; }
</style>
