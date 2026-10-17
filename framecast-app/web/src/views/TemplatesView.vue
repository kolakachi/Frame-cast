<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import AppSidebar from '../components/AppSidebar.vue'
import NotifBell from '../components/NotifBell.vue'
import UiSelect from '../components/UiSelect.vue'
// Templates (owner, 2026-10-16; mockup docs/product/create-ui/templates-mockup/v2/index.html): finished videos to start
// from, one library with Create's examples. Staff-only until it is made public; the API decides who sees it.
const router = useRouter(), authStore = useAuthStore()
const me = ref(null), loading = ref(true), error = ref('')
const templates = ref([]), categories = ref({}), isPublic = ref(false), staff = ref(false)
// A free plan's free first ad (FREE-FIRST) starts from a template that has its own code: those carry a badge.
const freeFirstOpen = ref(false)
const category = ref('all'), style = ref(''), shape = ref(''), search = ref('')

const styleOptions = computed(() => [{ value: '', label: 'Any' }, ...[...new Set(templates.value.map(t => t.style).filter(Boolean))].sort().map(s => ({ value: s, label: s }))])
const shapeOptions = [{ value: '', label: 'Any' }, { value: '9:16', label: '9:16' }, { value: '1:1', label: '1:1' }, { value: '16:9', label: '16:9' }]
const tabs = computed(() => [['all', 'All'], ...Object.entries(categories.value).filter(([k]) => templates.value.some(t => t.category === k))])
const shown = computed(() => {
  const q = search.value.trim().toLowerCase()
  return templates.value.filter(t => (category.value === 'all' || t.category === category.value) && (!style.value || t.style === style.value)
    && (!shape.value || t.shape === shape.value) && (!q || `${t.title} ${t.category_label} ${t.style || ''}`.toLowerCase().includes(q)))
})
const duration = s => `0:${String(Math.max(0, Math.round(s))).padStart(2, '0')}`

function play(e) { const v = e.currentTarget.querySelector('video'); if (v) { v.play().catch(() => {}) } }
function stop(e) { const v = e.currentTarget.querySelector('video'); if (v) { v.pause(); v.currentTime = 0 } }
function logout() { authStore.clearSession(); router.push({ name: 'login' }) }

onMounted(async () => {
  try { me.value = (await api.get('/me')).data?.data?.user ?? null } catch {}
  try { const ff = (await api.get('/create/capabilities')).data.data.free_first; freeFirstOpen.value = !!ff && !ff.conversation_id } catch {}
  try {
    const d = (await api.get('/create/templates')).data.data
    templates.value = d.templates; categories.value = d.categories; isPublic.value = d.public; staff.value = d.staff
  } catch (e) {
    // Not open to this account yet: Create is where they start.
    if (e?.response?.status === 404) { router.replace({ name: 'create' }); return }
    error.value = 'Could not load templates. Refresh to try again.'
  } finally { loading.value = false }
})
</script>

<template>
  <div class="tpl-shell">
    <AppSidebar :user="me" active-page="templates" @logout="logout" />
    <main class="main">
      <div class="topbar">
        <div class="topbar-left"><span class="bc-page">Templates</span></div>
        <div class="topbar-right"><NotifBell /></div>
      </div>
      <div class="content">
        <header class="page-head">
          <div>
            <h1>Templates <span v-if="staff && !isPublic" class="staff-badge" title="Customers don't see Templates until it is made public">Staff only</span></h1>
            <p>Start from a finished video. Add your product and the Creative Director makes it yours.</p>
          </div>
          <label class="search">
            <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7" /><path d="M20 20l-3.5-3.5" /></svg>
            <span class="sr-only">Search templates</span>
            <input v-model="search" type="search" placeholder="Search templates" />
          </label>
        </header>

        <div class="filters">
          <div class="tabs" role="group" aria-label="Category">
            <button v-for="[key, label] in tabs" :key="key" type="button" class="tab" :aria-pressed="category === key" @click="category = key">{{ label }}</button>
          </div>
          <div class="selects">
            <UiSelect v-model="style" :options="styleOptions" label="Style" prefix="Style" bare />
            <UiSelect v-model="shape" :options="shapeOptions" label="Shape" prefix="Shape" bare />
          </div>
        </div>

        <p v-if="error" class="banner">{{ error }}</p>
        <div v-if="loading" class="grid"><div v-for="n in 8" :key="n" class="card card--loading"><div class="card-media" /></div></div>
        <div v-else class="grid">
          <router-link v-for="t in shown" :key="t.id" class="card" :to="{ name: 'template', params: { templateId: t.id } }" @mouseenter="play" @mouseleave="stop" @focus="play" @blur="stop">
            <div class="card-media">
              <img v-if="t.poster_url" :src="t.poster_url" alt="" loading="lazy" />
              <video v-if="t.preview_url" :src="t.preview_url" muted loop playsinline preload="none" />
              <span class="duration">{{ duration(t.seconds) }}</span>
              <span v-if="t.locked" class="lock">Not in your plan</span>
              <span v-else-if="freeFirstOpen && t.source === 'version'" class="free-badge">Free first ad</span>
            </div>
            <div class="card-text">
              <span class="card-title">{{ t.title }}</span>
              <span class="card-meta">{{ t.category_label }} · {{ t.shape }}<template v-if="t.style"> · {{ t.style }}</template></span>
            </div>
          </router-link>
        </div>
        <p v-if="!loading && !shown.length" class="card-meta">No templates match. Try another category or clear the search.</p>
      </div>
    </main>
  </div>
</template>

<style scoped>
.tpl-shell{display:flex;min-height:100vh;background:#0a0a0f;color:#ececf3}
.main{margin-left:var(--sidebar-width,220px);flex:1;min-width:0;display:flex;flex-direction:column}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 24px;border-bottom:1px solid rgba(255,255,255,.08)}
.bc-page{color:#e8e8ee;font-weight:600;font-size:13px}
.topbar-right{display:flex;align-items:center;gap:12px}
.content{padding:32px 40px 56px;max-width:1200px;display:flex;flex-direction:column;gap:28px}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.page-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:16px 24px}
.page-head h1{margin:0;font-size:26px;font-weight:700;letter-spacing:-.02em}
.page-head p{margin:6px 0 0;color:#8b8ba0;max-width:52ch;font-size:14px}
.staff-badge{margin-left:10px;vertical-align:middle;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:3px 8px;border-radius:999px;border:1px solid rgba(255,107,53,.45);color:#ff6b35}
.search{display:flex;align-items:center;gap:8px;width:260px;max-width:100%;min-height:40px;padding:0 12px;background:#111118;border-radius:10px;color:#6a6a7c}
.search input{flex:1;min-width:0;background:transparent;border:0;outline:none;color:#ececf3;font:inherit;font-size:14px}
.search:focus-within{outline:2px solid #ff6b35;outline-offset:2px}
.filters{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px 24px;border-bottom:1px solid #1a1a24}
.tabs{display:flex;flex-wrap:wrap;gap:22px}
.tab{background:none;border:0;border-bottom:2px solid transparent;margin-bottom:-1px;min-height:36px;padding:0 0 10px;color:#8b8ba0;cursor:pointer;font:inherit;font-size:14px}
.tab:hover{color:#ececf3}
.tab[aria-pressed="true"]{color:#ececf3;font-weight:600;border-bottom-color:#ff6b35}
.selects{display:flex;gap:4px;padding-bottom:8px}
.banner{color:#fca5a5;font-size:13px;margin:0}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:32px 20px}
.card{display:flex;flex-direction:column;gap:10px;text-decoration:none;color:inherit}
.card-media{position:relative;aspect-ratio:4/5;border-radius:12px;overflow:hidden;background:#111118}
.card-media>img,.card-media>video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:block}
.card-media video{opacity:0;transition:opacity .2s}
.card:hover .card-media video,.card:focus-visible .card-media video{opacity:1}
.card-media::after{content:"";position:absolute;inset:0;border-radius:inherit;box-shadow:inset 0 0 0 1px rgba(255,255,255,.04);pointer-events:none}
.card:hover .card-media::after{box-shadow:inset 0 0 0 1px rgba(255,255,255,.18)}
.card--loading .card-media{animation:pulse 1.4s ease-in-out infinite}
@keyframes pulse{50%{opacity:.6}}
.duration{position:absolute;top:8px;right:8px;z-index:1;font-size:11px;padding:2px 6px;border-radius:4px;background:rgba(10,10,15,.7)}
.lock{position:absolute;left:8px;bottom:8px;z-index:1;font-size:11px;padding:2px 6px;border-radius:4px;background:rgba(10,10,15,.8);color:#a1a1b5}
.card-text{display:flex;flex-direction:column;gap:2px}
.card-title{font-size:14px;font-weight:600}
.free-badge{position:absolute;top:10px;left:10px;font-size:11px;font-weight:700;letter-spacing:.03em;padding:4px 9px;border-radius:999px;background:var(--color-accent);color:#fff}
.card-meta{font-size:12px;color:#8b8ba0}
@media (prefers-reduced-motion:reduce){.card-media video{transition:none}}
@media (max-width:860px){
  .content{padding:20px 16px 40px}
  .search{width:100%}
  .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:24px 12px}
}
</style>
