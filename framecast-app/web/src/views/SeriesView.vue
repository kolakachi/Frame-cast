<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'

const router = useRouter()
const authStore = useAuthStore()

const seriesList = ref([])
const loading = ref(true)

async function loadSeries() {
  loading.value = true
  try {
    const res = await api.get('/series')
    seriesList.value = res.data.data.series || []
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

function logout() {
  authStore.logout()
  router.push({ name: 'login' })
}

onMounted(loadSeries)
</script>

<template>
  <div class="shell">
    <AppSidebar :user="authStore.user" active-page="series" @logout="logout" />

    <main class="main">
      <div class="page-header">
        <div>
          <h1 class="page-title">Series</h1>
          <p class="page-sub">Context-aware content series with episode memory and character consistency.</p>
        </div>
        <button class="btn-primary" type="button" @click="router.push({ name: 'series-create' })">+ New Series</button>
      </div>

      <div v-if="loading" class="empty-state">
        <div class="spinner"></div>
        <p>Loading series…</p>
      </div>

      <div v-else-if="seriesList.length === 0" class="empty-state">
        <div class="empty-icon">
          <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
            <path d="M2 6h4v4H2zM2 14h4v4H2zM10 6h12M10 10h12M10 14h12M10 18h12"></path>
          </svg>
        </div>
        <p class="empty-title">No series yet</p>
        <p class="empty-sub">Create a series to start generating context-aware episodes.</p>
        <button class="btn-primary" type="button" @click="router.push({ name: 'series-create' })">Create your first series</button>
      </div>

      <div v-else class="series-grid">
        <div
          v-for="(s, i) in seriesList"
          :key="s.id"
          class="series-card"
          role="button"
          tabindex="0"
          @click="router.push({ name: 'series-detail', params: { seriesId: s.id } })"
          @keydown.enter="router.push({ name: 'series-detail', params: { seriesId: s.id } })"
        >
          <div class="series-num">{{ String(i + 1).padStart(2, '0') }}</div>
          <div class="series-info">
            <div class="series-name">{{ s.name }}</div>
            <div class="series-meta">
              <span class="series-pill">{{ s.episodes_count || 0 }} ep{{ s.episodes_count !== 1 ? 's' : '' }}</span>
              <span v-if="s.tone" class="series-pill">{{ s.tone }}</span>
              <span v-if="s.memory_window > 0" class="series-pill">{{ s.memory_window }}-ep memory</span>
              <span v-if="s.characters?.length" class="series-pill">{{ s.characters.length }} char{{ s.characters.length !== 1 ? 's' : '' }}</span>
            </div>
            <p v-if="s.description" class="series-desc">{{ s.description }}</p>
          </div>
          <div class="series-actions">
            <span class="series-eps">ep {{ (s.episodes_count || 0) + 1 }} due</span>
            <button
              class="btn-primary btn-sm"
              type="button"
              @click.stop="router.push({ name: 'series-detail', params: { seriesId: s.id } })"
            >+ Episode</button>
          </div>
        </div>
      </div>

    </main>
  </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; background: var(--color-bg-base); }
.main { margin-left: 220px; flex: 1; padding: 32px 36px; min-width: 0; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 28px; }
.page-title { font-size: 22px; font-weight: 700; color: var(--color-text-primary); margin: 0 0 4px; }
.page-sub { font-size: 13px; color: var(--color-text-muted); margin: 0; }

.btn-primary { background: var(--color-accent); color: #fff; border: none; border-radius: 8px; padding: 9px 18px; font-size: 13px; font-weight: 600; cursor: pointer; transition: 0.15s; }
.btn-primary:hover:not(:disabled) { opacity: 0.88; }
.btn-primary:disabled { opacity: 0.5; cursor: default; }
.btn-ghost { background: transparent; color: var(--color-text-secondary); border: 1px solid var(--color-border); border-radius: 8px; padding: 9px 18px; font-size: 13px; cursor: pointer; }
.btn-ghost:hover { background: var(--color-bg-elevated); }

.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 80px 0; color: var(--color-text-muted); text-align: center; }
.empty-icon { color: var(--color-text-muted); opacity: 0.4; }
.empty-title { font-size: 15px; font-weight: 600; color: var(--color-text-primary); margin: 0; }
.empty-sub { font-size: 13px; margin: 0; }
.spinner { width: 28px; height: 28px; border: 2px solid var(--color-border); border-top-color: var(--color-accent); border-radius: 50%; animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.series-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
.series-card { background: var(--color-bg-panel); border: 1px solid var(--color-border); border-radius: 12px; padding: 16px 18px; cursor: pointer; transition: 0.15s; display: flex; gap: 14px; align-items: flex-start; }
.series-card:hover { border-color: var(--color-border-active); transform: translateY(-1px); }
.series-num { font-family: 'Space Mono', monospace; font-size: 22px; font-weight: 700; color: var(--color-border-active); flex-shrink: 0; width: 36px; text-align: center; padding-top: 2px; }
.series-info { flex: 1; min-width: 0; }
.series-name { font-size: 14px; font-weight: 700; color: var(--color-text-primary); margin-bottom: 6px; }
.series-meta { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 6px; }
.series-pill { font-size: 10px; font-family: 'Space Mono', monospace; background: var(--color-bg-elevated); border: 1px solid var(--color-border); border-radius: 4px; padding: 2px 7px; color: var(--color-text-muted); }
.series-desc { font-size: 12px; color: var(--color-text-muted); margin: 0; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.series-actions { display: flex; flex-direction: column; gap: 6px; align-items: flex-end; flex-shrink: 0; }
.series-eps { font-size: 11px; color: var(--color-text-muted); font-family: 'Space Mono', monospace; white-space: nowrap; }
.btn-sm { padding: 5px 12px; font-size: 11px; }
</style>

