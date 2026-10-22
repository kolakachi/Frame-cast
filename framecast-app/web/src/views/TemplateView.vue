<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../services/api'
import { peekBrief, clearBrief } from '../services/pendingBrief.js'
import AppSidebar from '../components/AppSidebar.vue'
import TemplateDrawer from '../components/create/TemplateDrawer.vue'
// A template (mockup v2 template.html): watch it, add your product, pick how closely to follow it, "Make mine". Make
// mine starts a Create conversation from the template, attaches the photos, sends the brief and plans; the plan and its
// price come before anything is made.
const route = useRoute(), router = useRouter(), authStore = useAuthStore()
const me = ref(null), t = ref(null), loading = ref(true), error = ref('')
const product = ref(''), photos = ref([]), shape = ref('9:16'), match = ref('exact'), busy = ref(''), editOpen = ref(false)
const player = ref(null), playing = ref(false)
// FREE-FIRST: a free plan's one free ad, from a template with its own code, kept to "Exactly" and its own shape.
const freeFirst = ref(null)
const freeMode = computed(() => !!freeFirst.value && !freeFirst.value.conversation_id && freeFirst.value.open !== false)
// Free first ads ended for accounts that hadn't started one (owner, 2026-10-22): a free plan gets the pass instead.
const freeClosed = computed(() => !!freeFirst.value && !freeFirst.value.conversation_id && freeFirst.value.open === false)
const freeUsed = computed(() => !!freeFirst.value?.conversation_id)
// Free only from a template with its own code and no AI-made video in it (owner, 2026-10-17).
const freeFits = computed(() => t.value?.free_fit === true)
let key = crypto.randomUUID()

const eyebrow = computed(() => t.value ? `${t.value.category_label} · ${t.value.seconds} seconds · ${t.value.shape}` : '')
const facts = computed(() => t.value ? [t.value.style, ...(t.value.parts || [])].filter(Boolean).join(' · ') : '')
const matchHint = computed(() => t.value?.matches?.find(m => m.value === match.value)?.hint || '')
const errMsg = (e, fallback) => e?.response?.data?.message || e?.response?.data?.error?.message || fallback

function pickPhotos(e) {
  const files = [...(e.target.files || [])].filter(f => f.type.startsWith('image/')).slice(0, 6)
  photos.value = files
}
function play() { const v = player.value; if (!v) return; v.play().then(() => { playing.value = true }).catch(() => {}) }

async function makeMine() {
  if (!product.value.trim()) { error.value = 'Tell us about your product: a link, or a few words about it.'; return }
  if (freeMode.value) { match.value = 'exact'; shape.value = t.value.shape }
  error.value = ''; busy.value = 'Starting from the template…'
  try {
    const made = (await api.post(`/create/templates/${t.value.id}/use`, { product: product.value.trim(), match: match.value, aspect_ratio: shape.value, idempotency_key: key }, { timeout: 150000 })).data.data
    let version = made.version
    for (const [i, file] of photos.value.entries()) {
      busy.value = `Adding your photos (${i + 1} of ${photos.value.length})…`
      const form = new FormData(); form.append('asset_file', file); form.append('purpose', 'source'); form.append('idempotency_key', `${key}:photo:${i}`); form.append('expected_version', version)
      version = (await api.post(`/create/conversations/${made.id}/uploads`, form, { headers: { 'Content-Type': 'multipart/form-data' } })).data.data.conversation.version
    }
    busy.value = 'Sending your brief…'
    await api.post(`/create/conversations/${made.id}/messages`, { content: made.brief, expected_version: version, idempotency_key: `${key}:brief` })
    clearBrief()
    // Create picks it up and plans: the plan and its price come before anything is made.
    router.push({ name: 'create', params: { conversationId: made.id }, query: { plan: '1' } })
  } catch (e) {
    error.value = errMsg(e, 'Could not start from this template. Try again.')
    busy.value = ''
  }
}

async function removeTemplate() {
  if (!window.confirm('Remove this template? It disappears for everyone.')) return
  try { await api.delete(`/create/templates/${t.value.id}`); router.push({ name: 'templates' }) } catch (e) { error.value = errMsg(e, 'Could not remove it.') }
}
function logout() { authStore.clearSession(); router.push({ name: 'login' }) }

onMounted(async () => {
  try { me.value = (await api.get('/me')).data?.data?.user ?? null } catch {}
  try { freeFirst.value = (await api.get('/create/capabilities')).data.data.free_first ?? null } catch {}
  // The idea typed on the website's first box rides in: it becomes "Your product" for the free ad.
  if (!product.value) product.value = peekBrief() || ''
  try {
    t.value = (await api.get(`/create/templates/${route.params.templateId}`)).data.data
    shape.value = t.value.shape
  } catch (e) {
    if (e?.response?.status === 404) { router.replace({ name: 'templates' }); return }
    error.value = 'Could not load this template.'
  } finally { loading.value = false }
})
</script>

<template>
  <div class="tpl-shell">
    <AppSidebar :user="me" active-page="template" @logout="logout" />
    <main class="main">
      <div class="detail">
        <router-link class="back" :to="{ name: 'templates' }">
          <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
          Templates
        </router-link>
        <p v-if="loading" class="muted">Loading…</p>
        <div v-else-if="t" class="detail-body">
          <div class="player-col">
            <div :class="['player', { 'is-playing': playing }]" :data-shape="t.shape">
              <video ref="player" :src="t.video_url" :poster="t.poster_url" playsinline preload="metadata" :controls="playing" @pause="playing = false" @ended="playing = false" />
              <button type="button" class="play" aria-label="Play with sound" @click="play">
                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
              </button>
            </div>
            <span class="player-note">{{ `0:${String(t.seconds).padStart(2, '0')}` }} · plays with sound</span>
          </div>

          <div class="info">
            <div class="info-head">
              <span v-if="t.staff" class="staff-row"><span v-if="!t.public" class="staff-badge">Staff</span>
                <button type="button" class="link" @click="editOpen = true">Edit</button>
                <button type="button" class="link" @click="removeTemplate">Remove</button>
                <router-link v-if="t.conversation_id" class="link" :to="{ name: 'create', params: { conversationId: t.conversation_id } }">Open the original</router-link>
              </span>
              <span class="eyebrow">{{ eyebrow }}<span v-if="freeMode && freeFits" class="free-pill">Free first ad</span></span>
              <h1>{{ t.title }}</h1>
              <p v-if="t.description" class="lede">{{ t.description }}</p>
              <span v-if="facts" class="facts">{{ facts }}</span>
            </div>
            <hr class="divider" />

            <p v-if="t.locked" class="locked">This template isn't in your plan. Upgrade to use it, or start from one that is.</p>
            <div v-else-if="freeUsed" class="free-note">
              <b>You've made your free first ad.</b>
              <p>Make more with the $9 Test Pass: your own words and photos, new shots, voices and presenters, and no WyvStudio mark.</p>
              <div class="free-actions"><a class="btn-primary" href="/continue?pass=1">Get the Test Pass, $9</a><router-link :to="{ name: 'create', params: { conversationId: freeFirst.conversation_id } }">Open your free ad</router-link></div>
            </div>
            <div v-else-if="freeClosed" class="free-note">
              <b>Make your ad with the $9 Test Pass.</b>
              <p>Your own words and photos, new shots, voices and presenters, and no WyvStudio mark.</p>
              <div class="free-actions"><a class="btn-primary" href="/continue?pass=1">Get the Test Pass, $9</a></div>
            </div>
            <div v-else-if="freeMode && !freeFits" class="free-note">
              <b>Your free first ad starts from a template marked "Free first ad".</b>
              <p>This one makes new AI video or a fresh build for your product, which comes with the $9 Test Pass or a pack.</p>
              <div class="free-actions"><router-link class="btn-primary" :to="{ name: 'templates' }">See the free ones</router-link><a href="/continue?pass=1">Get the Test Pass, $9</a></div>
            </div>
            <form v-else class="use" @submit.prevent="makeMine">
              <label class="field">
                <span class="field-label">Your product</span>
                <textarea v-model="product" class="textarea" rows="3" maxlength="2000" required :placeholder="t.ask_hint || 'A link, or a few words about it. e.g. yourshop.com/product, launch week 20% off'" />
              </label>

              <div class="use-row">
                <label class="add-photos">
                  <span class="icon-box"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg></span>
                  <span>{{ photos.length ? `${photos.length} ${photos.length === 1 ? 'photo' : 'photos'} added` : 'Add product photos' }}</span>
                  <input class="sr-only" type="file" accept="image/png,image/jpeg,image/webp" multiple @change="pickPhotos" />
                </label>
                <fieldset v-if="!freeMode" class="shape">
                  <legend class="sr-only">Shape</legend>
                  <span aria-hidden="true">Shape</span>
                  <div class="seg">
                    <label v-for="s in ['9:16', '1:1', '16:9']" :key="s"><input v-model="shape" type="radio" name="shape" :value="s" />{{ s }}</label>
                  </div>
                </fieldset>
              </div>

              <fieldset v-if="!freeMode" class="match">
                <legend class="field-label">How closely to follow it</legend>
                <div class="seg seg--wide">
                  <label v-for="m in t.matches" :key="m.value"><input v-model="match" type="radio" name="match" :value="m.value" />{{ m.label }}</label>
                </div>
                <span class="match-hint">{{ matchHint }}</span>
              </fieldset>

              <div class="submit">
                <button type="submit" class="btn-primary" :disabled="!!busy">{{ busy || (freeMode ? 'Make my free ad' : 'Make mine') }}</button>
                <p v-if="freeMode" class="fineprint">Free: your first ad keeps this template's look with your words, colours, photos and a new voiceover, and a small WyvStudio mark.</p>
                <p v-else class="fineprint">You’ll see the plan and the price before anything is made.</p>
                <p v-if="error" class="error" role="alert">{{ error }}</p>
              </div>
            </form>
          </div>
        </div>
        <p v-else-if="error" class="error">{{ error }}</p>
      </div>
    </main>
    <TemplateDrawer v-if="t?.staff" :open="editOpen" :template="t" :poster="t.poster_url" @close="editOpen = false" @saved="s => { t = s; editOpen = false }" />
  </div>
</template>

<style scoped>
.tpl-shell{display:flex;min-height:100vh;background:#0a0a0f;color:#ececf3}
.main{margin-left:var(--sidebar-width,220px);flex:1;min-width:0}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.detail{padding:28px 44px 56px;display:flex;flex-direction:column;gap:28px}
.back{align-self:flex-start;display:inline-flex;align-items:center;gap:6px;min-height:32px;font-size:13px;color:#8b8ba0;text-decoration:none}
.back:hover{color:#ececf3}
.muted{color:#8b8ba0}
.detail-body{display:flex;flex-wrap:wrap;gap:48px;align-items:flex-start;max-width:1100px}
.player-col{flex:0 1 340px;min-width:240px;display:flex;flex-direction:column;gap:8px}
.player{position:relative;aspect-ratio:9/16;max-width:100%;border-radius:14px;overflow:hidden;background:#111118}
.player[data-shape="1:1"]{aspect-ratio:1/1}
.player[data-shape="16:9"]{aspect-ratio:16/9}
.player video{width:100%;height:100%;object-fit:cover;display:block}
.play{position:absolute;inset:0;margin:auto;width:56px;height:56px;border-radius:50%;border:0;background:rgba(10,10,15,.7);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer}
.play:hover{background:rgba(10,10,15,.85)}
.player.is-playing .play{display:none}
.player-note{font-size:12px;color:#6a6a7c}
.info{flex:999 1 400px;min-width:0;max-width:520px;display:flex;flex-direction:column;gap:28px;padding-top:4px}
.info-head{display:flex;flex-direction:column;gap:10px}
.staff-row{display:flex;align-items:center;gap:12px;font-size:13px}
.free-note{display:flex;flex-direction:column;gap:8px;padding:20px 22px;border-radius:16px;border:1px solid var(--color-border);background:var(--color-bg-card,rgba(255,255,255,.03))}
.free-note b{font-size:16px;line-height:1.35;color:var(--color-text-primary)}
.free-note p{margin:0;color:var(--color-text-secondary);font-size:14px;line-height:1.5}
.free-actions{display:flex;flex-wrap:wrap;gap:18px;align-items:center;margin-top:8px}
.free-actions .btn-primary{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 20px;text-decoration:none;white-space:nowrap}
.free-actions a:not(.btn-primary){font-size:14px;font-weight:600;color:var(--color-accent);text-decoration:none}
.free-actions a:not(.btn-primary):hover{text-decoration:underline}
.free-pill{margin-left:10px;font-size:11px;font-weight:700;letter-spacing:.03em;padding:3px 9px;border-radius:999px;background:var(--color-accent,#ff6b35);color:#fff;vertical-align:middle}
.staff-badge{font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;padding:3px 8px;border-radius:999px;border:1px solid rgba(255,107,53,.45);color:#ff6b35}
.link{background:none;border:0;padding:0;color:#a1a1b5;font:inherit;font-size:13px;text-decoration:underline;text-underline-offset:3px;cursor:pointer}
.link:hover{color:#ececf3}
.eyebrow{font-size:13px;color:#8b8ba0}
.info h1{margin:0;font-size:28px;font-weight:700;letter-spacing:-.02em;line-height:1.2}
.lede{margin:0;color:#a1a1b5;font-size:15px;line-height:1.6;max-width:48ch}
.facts{font-size:13px;color:#6a6a7c}
.divider{height:1px;background:#1a1a24;border:0;margin:0}
.locked{margin:0;color:#a1a1b5;font-size:14px}
.use{display:flex;flex-direction:column;gap:20px}
.field{display:flex;flex-direction:column;gap:8px}
.field-label{font-size:13px;font-weight:500;padding:0;margin-bottom:8px}
.field .field-label{margin:0}
.textarea{background:#111118;border:1px solid #1a1a24;border-radius:10px;padding:12px 14px;line-height:1.5;resize:vertical;min-height:88px;color:#ececf3;font:inherit;font-size:14px}
.textarea::placeholder{color:#6a6a7c}
.textarea:focus{border-color:#34344a;outline:none}
.use-row{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center;justify-content:space-between}
.add-photos{display:inline-flex;align-items:center;gap:8px;min-height:36px;cursor:pointer;font-size:14px}
.icon-box{width:28px;height:28px;border-radius:8px;background:#15151e;display:inline-flex;align-items:center;justify-content:center}
.add-photos:hover .icon-box{background:#24242f}
.add-photos:has(input:focus-visible){outline:2px solid #ff6b35;outline-offset:4px;border-radius:8px}
.shape,.match{border:0;margin:0;padding:0}
.shape{display:flex;align-items:center;gap:10px}
.shape>span{font-size:13px;color:#8b8ba0}
.seg{display:inline-flex;gap:2px;padding:3px;background:#111118;border-radius:9px}
.seg label{position:relative;min-height:32px;padding:0 12px;border-radius:7px;font-size:13px;color:#8b8ba0;display:inline-flex;align-items:center;cursor:pointer}
.seg input{position:absolute;opacity:0;pointer-events:none}
.seg label:has(input:checked){background:#24242f;color:#ececf3;font-weight:600}
.seg label:has(input:focus-visible){outline:2px solid #ff6b35;outline-offset:1px}
.match-hint{display:block;margin-top:8px;font-size:12.5px;color:#8b8ba0}
.btn-primary{min-height:46px;border:0;border-radius:10px;background:#ff6b35;color:#1a0a03;font:inherit;font-size:15px;font-weight:600;cursor:pointer}
.btn-primary:hover{filter:brightness(1.08)}
.btn-primary:disabled{opacity:.7;cursor:default}
.fineprint{margin:0;font-size:12px;color:#6a6a7c}
.submit{display:flex;flex-direction:column;gap:10px}
.error{margin:0;color:#ff8f8f;font-size:13px}
@media (max-width:860px){
  .detail{padding:20px 16px 40px}
  .detail-body{gap:28px}
  .player-col{flex-basis:100%;max-width:360px;margin:0 auto}
}
</style>
