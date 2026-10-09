<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../services/api'
import CardModal from './CardModal.vue'
import { tune } from './industries.js'
import { useWorkspaceStore } from '../../stores/workspace'

// The top of the dashboard (2026-10-08 mockup, owner's decisions): a welcome, "Set up your studio" from what the
// workspace already has, and "What do you want to make?" cards that open Create with a starter brief.
const props = defineProps({ user: { type: Object, default: null }, credits: { type: Object, default: null } })
const router = useRouter()

const firstName = computed(() => String(props.user?.name || '').trim().split(/\s+/)[0] || 'there')
// The low-credit nudge (owner, 2026-10-09: a video count overpromised). Production's last two weeks: a Weave build
// used 217 credits at the median and 440 at the 75th percentile, plus about 45 per plan, so a video is 250–500.
// Shown under 1,000 credits; under 300 it says most videos need more. The smallest top-up is 500 credits for $8.
const LOW = 1000, VERY_LOW = 300
const balance = computed(() => (props.credits && props.credits.balance !== null ? Number(props.credits.balance) : null))
const lowCredits = computed(() => balance.value !== null && balance.value > 0 && balance.value < LOW)

const setup = ref(null)
const brand = computed(() => setup.value?.brand_name || '')
const STEPS = computed(() => [
  { key: 'brand', title: 'Add your brand', text: 'Logo, colours and fonts, used in every video.', action: 'Add brand', to: { name: 'settings', query: { section: 'brand' } } },
  { key: 'pronunciation', title: 'Say your name right', text: brand.value ? `Tell us once how “${brand.value}” is said, and every voice says it that way.` : 'Tell us once how your brand name is said, and every voice says it that way.',
    action: 'Add pronunciation', to: { name: 'create', query: { pronunciations: '1', ...(brand.value ? { name: brand.value } : {}) } } },
  { key: 'face_voice', title: 'Your face and voice', text: 'One photo for your character, a short recording for your cloned voice.', action: 'Add a photo', to: { name: 'characters' }, alt: { label: 'or clone your voice', to: { name: 'voices' } }, optional: true },
  { key: 'first_video', title: 'Make your first video', text: 'Describe it, or start from a video you love. You see the plan before anything is made.', action: 'Open Create', to: { name: 'create' } },
])
const nextStep = computed(() => STEPS.value.find(s => setup.value && !setup.value.steps?.[s.key])?.key)
// The studio setup is the agency owner's to do, so a collaborator never sees it. (The agency's own view lives
// under Agency in the menu, not on the dashboard: owner, 2026-10-09.)
const setupDone = computed(() => props.user?.role === 'collaborator' || (!!setup.value && setup.value.done >= setup.value.total))

// "Hide for now" and the one-time "set up" note are remembered per workspace in this browser.
const wsKey = k => `wyv_dash_${k}_${props.user?.workspace_id || 'ws'}`
const remembered = k => { try { return localStorage.getItem(wsKey(k)) === '1' } catch { return false } }
const remember = (k, on) => { try { on ? localStorage.setItem(wsKey(k), '1') : localStorage.removeItem(wsKey(k)) } catch { /* optional */ } }
const setupHidden = ref(false), readySeen = ref(false)
function hideSetup(on) { setupHidden.value = on; remember('setup_hidden', on) }
function dismissReady() { readySeen.value = true; remember('ready_seen', true) }

async function loadSetup() { try { setup.value = (await api.get('/dashboard/setup')).data.data } catch { /* keep what we had */ } }

// This week: the calendar, once the studio is set up. Monday to Sunday of the current week.
const posts = ref([])
const weekStart = computed(() => { const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return d })
const days = computed(() => Array.from({ length: 7 }, (_, i) => {
  const d = new Date(weekStart.value); d.setDate(d.getDate() + i)
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const items = posts.value.filter(p => { const t = new Date(p.scheduled_at || p.published_at || p.created_at); return t >= d && t < new Date(d.getTime() + 864e5) })
  return { date: d, name: d.toLocaleDateString('en', { weekday: 'short' }), n: d.getDate(), items, past: d < today, isToday: d.getTime() === today.getTime() }
}))
const weekCounts = computed(() => {
  const posted = posts.value.filter(p => p.status === 'published').length
  const scheduled = posts.value.filter(p => ['scheduled', 'pending', 'processing'].includes(p.status)).length
  const empty = days.value.filter(d => !d.past && !d.items.length).map(d => d.date.toLocaleDateString('en', { weekday: 'long' }))
  return { posted, scheduled, empty }
})
const PLATFORM = { youtube: 'YouTube', tiktok: 'TikTok', instagram: 'Instagram', facebook: 'Facebook' }
async function loadWeek() {
  const from = weekStart.value, to = new Date(from.getTime() + 7 * 864e5)
  try { posts.value = (await api.get('/scheduled-posts', { params: { from: from.toISOString(), to: to.toISOString(), per_page: 100 } })).data?.data?.posts ?? [] } catch { posts.value = [] }
}
function fillDay(d) {
  const when = d.date.toLocaleDateString('en', { weekday: 'long', day: 'numeric', month: 'long' })
  router.push({ name: 'create', hash: '#brief=' + encodeURIComponent(`A video to post on ${when}: [what it is about], for [who it is for], ending on [your call to action].`) + '&from=card:day' })
}

// Ticks update when the user comes back from adding a brand, a name or a photo in another tab.
function onVisible() { if (document.visibilityState === 'visible') { loadSetup(); if (setupDone.value) loadWeek() } }
onMounted(async () => {
  setupHidden.value = remembered('setup_hidden'); readySeen.value = remembered('ready_seen')
  await loadSetup()
  if (setupDone.value) loadWeek()
  loadIdeas()
  document.addEventListener('visibilitychange', onVisible)
})
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible))

// Each card opens Create with a starter brief whose [brackets] the user fills in (the website-brief handoff, from=card).
const POSTER = 'https://s3.us-east-005.backblazeb2.com/frame-cast/marketing/samples/weave/'
const CARDS = [
  { key: 'offer_ad', tagline: 'A short ad with a clear hook, one benefit and your offer.', title: 'Ads & Promo', text: 'A product ad with your offer, ready to post.', poster: 'b06', colour: '#b8532e',
    brief: 'A 15-second vertical ad for [your product]. Show [what it does] for [who it is for], and end on [your offer, for example 20% off this week].' },
  { key: 'launch_promo', tagline: 'A teaser that builds anticipation for something new.', title: 'Product Launch', text: 'A teaser in bold type for something new.', poster: 'b01', colour: '#4b4fa8',
    brief: 'A 20-second vertical launch teaser for [what is new]. Bold type that builds anticipation, ending on [the launch date or link].' },
  { key: 'testimonial', tagline: 'Someone talks to camera about your product, like a real customer would.', title: 'UGC Testimonial', text: 'A presenter, or you, talking to camera.', poster: 'b05', colour: '#7a3b9a',
    brief: 'A 20-second UGC video: a creator talks to camera about how [your product] helped them with [the problem]. Natural and friendly, ending on [your call to action].' },
  { key: 'explainer', tagline: 'An idea, steps or numbers, made easy to follow.', title: 'Explainer & Data', text: 'Numbers and steps, made easy to follow.', poster: 's100', colour: '#1f6a59',
    brief: 'A 30-second vertical explainer showing [the idea or the numbers] for [who it is for]. Easy to follow, ending on [the takeaway].' },
  { key: 'listicle', tagline: 'Three quick tips your audience saves, in your brand.', title: 'Tips & How-To', text: 'Three quick tips your audience saves.', poster: 'b10', colour: '#8a5a1d',
    brief: 'A 30-second vertical video with 3 quick tips about [your topic] for [who it is for], ending on [your call to action].' },
  { key: 'reference', tagline: 'Show us a video you love, and get yours in that style.', title: 'From a Video You Love', text: 'Paste a TikTok, Reel or YouTube link.', poster: 'l3461c7aa', colour: '#2d5d8c',
    brief: 'Make a video like this one for [your product]: [paste a TikTok, Reel or YouTube link]' },
]
// Ordered and worded for what the workspace sells (D5).
const cards = computed(() => tune(CARDS, setup.value?.industry, setup.value?.goal))
// "Videos for my clients" at onboarding: point them at the Agency features (or at what Agency adds).
const workspaceStore = useWorkspaceStore()
const agencyHint = computed(() => setup.value?.goal === 'agency' && !agencyHintHidden.value)
const agencyHintHidden = ref((() => { try { return localStorage.getItem('wyv_agency_hint') === 'hidden' } catch { return false } })())
function hideAgencyHint() { agencyHintHidden.value = true; try { localStorage.setItem('wyv_agency_hint', 'hidden') } catch { /* this session only */ } }
// A card opens its modal (CardModal): script or Script Writer, options, then Continue starts it in Create.
const openCard = ref(null)
function startFrom(card, prefill = null) { openCard.value = { ...card, image: POSTER + card.poster + '.jpg', prefill } }

// Next videos for you (D8): three ideas from the workspace's own videos, shown once it has made one. "Start this"
// opens that format's card with the idea in Video details; a quick post names the week's first empty day.
const ideas = ref([])
const openDay = computed(() => days.value.find(d => !d.past && !d.items.length) || null)
const ideaLabel = i => i.kind === 'more' ? 'More like your last' : i.kind === 'new_format' ? 'A format to try' : openDay.value ? 'Fill ' + openDay.value.date.toLocaleDateString('en', { weekday: 'long' }) : 'A quick post'
async function loadIdeas() {
  try { ideas.value = (await api.get('/dashboard/ideas')).data?.data?.ideas ?? [] } catch { ideas.value = [] }
  if (ideas.value.length && !setupDone.value) loadWeek()
}
function startIdea(i) {
  const card = cards.value.find(c => c.key === i.format) || cards.value[0]
  const day = i.kind === 'quick' && openDay.value ? `To post on ${openDay.value.date.toLocaleDateString('en', { weekday: 'long', day: 'numeric', month: 'long' })}. ` : ''
  startFrom(card, { details: day + i.brief })
}
</script>

<template>
  <section class="dh">
    <div class="dh-glow" aria-hidden="true" />
    <header class="dh-welcome">
      <h1>Welcome, <em>{{ firstName }}</em></h1>
      <p v-if="balance !== null">{{ balance.toLocaleString() }} credits</p>
      <button v-if="lowCredits" type="button" class="dh-low" @click="router.push({ name: 'settings', query: { section: 'billing' } })">
        <template v-if="balance < VERY_LOW">Running low: most videos need 250–500 credits</template>
        <template v-else>{{ balance.toLocaleString() }} credits left · A video in Weave usually uses 250–500</template>
        · Top up: 500 credits for $8 →
      </button>
    </header>

    <div v-if="setup && !setupDone && setupHidden" class="dh-hd dh-hidden"><span>Set up your studio <b>{{ setup.done }} / {{ setup.total }}</b></span><button type="button" class="dh-link" @click="hideSetup(false)">Show setup</button></div>
    <template v-else-if="setup && !setupDone">
      <div class="dh-hd"><h2>Set up your studio <b>{{ setup.done }} / {{ setup.total }}</b></h2><span class="dh-hd-right"><small>Each step makes every video sound and look more like you</small><button type="button" class="dh-link" @click="hideSetup(true)">Hide for now</button></span></div>
      <div class="dh-setup">
        <div v-for="(s, i) in STEPS" :key="s.key" :class="['dh-step', { done: setup.steps[s.key], now: nextStep === s.key }]">
          <div class="dh-step-top"><span class="dh-ring" :aria-label="setup.steps[s.key] ? 'Done' : 'Not done yet'" /><span class="dh-step-n">STEP {{ i + 1 }}</span></div>
          <h3>{{ s.title }}</h3>
          <p>{{ s.text }}</p>
          <button type="button" class="dh-step-btn" @click="router.push(s.to)">{{ setup.steps[s.key] ? 'Edit' : s.action }}</button>
          <span v-if="s.alt || (s.optional && !setup.steps[s.key])" class="dh-opt"><button v-if="s.alt" type="button" class="dh-link" @click="router.push(s.alt.to)">{{ s.alt.label }}</button><template v-if="s.alt && s.optional && !setup.steps[s.key]"> · </template><template v-if="s.optional && !setup.steps[s.key]">Optional</template></span>
        </div>
      </div>
    </template>

    <template v-if="setupDone">
      <div v-if="!readySeen" class="dh-ready" role="status"><span><b>✓ Your studio is set up.</b> Your brand, name, face and voice are used in every video.</span><span class="dh-ready-links"><button type="button" class="dh-link" @click="router.push({ name: 'settings', query: { section: 'brand' } })">Brand</button> · <button type="button" class="dh-link" @click="router.push({ name: 'voices' })">Voice</button> · <button type="button" class="dh-link" @click="router.push({ name: 'create', query: { pronunciations: '1' } })">Pronunciations</button><button type="button" class="dh-x" aria-label="Dismiss" @click="dismissReady">×</button></span></div>
      <div class="dh-hd"><h2>This week</h2><button type="button" class="dh-link" @click="router.push({ name: 'calendar' })">Open calendar →</button></div>
      <div class="dh-week">
        <div v-for="d in days" :key="d.n" :class="['dh-day', { today: d.isToday, past: d.past }]">
          <b>{{ d.name }} <span>{{ d.n }}</span></b>
          <span v-for="p in d.items.slice(0, 3)" :key="p.id" :class="['dh-pill', p.status === 'published' ? 'posted' : p.status === 'failed' ? 'failed' : 'sched']" :title="p.project_title || 'Post'">{{ p.project_title || 'Post' }} · {{ PLATFORM[p.platform] || p.platform }}</span>
          <span v-if="d.items.length > 3" class="dh-more">+{{ d.items.length - 3 }} more</span>
          <button v-if="!d.past && !d.items.length" type="button" class="dh-fill" @click="fillDay(d)">+ Fill this day</button>
        </div>
      </div>
      <p class="dh-weekline"><b>{{ weekCounts.posted }} posted · {{ weekCounts.scheduled }} scheduled</b><template v-if="weekCounts.empty.length"> · {{ weekCounts.empty.length === 1 ? weekCounts.empty[0] + ' is' : weekCounts.empty.slice(0, -1).join(', ') + ' and ' + weekCounts.empty.at(-1) + ' are' }} empty</template></p>
    </template>

    <template v-if="ideas.length">
      <div class="dh-hd"><h2>Next videos for you</h2><small>From your brand and your videos</small></div>
      <div class="dh-ideas">
        <article v-for="i in ideas" :key="i.kind" class="dh-idea">
          <small>{{ ideaLabel(i) }}</small>
          <b>{{ i.title }}</b>
          <p>{{ i.why }}</p>
          <button type="button" class="dh-idea-go" @click="startIdea(i)">Start this →</button>
        </article>
      </div>
    </template>

    <div v-if="agencyHint" class="dh-agency" role="note">
      <span v-if="workspaceStore.canOwnClients"><b>Making videos for clients?</b> Give each client their own space, approvals and your team's allowances.</span>
      <span v-else><b>Making videos for clients?</b> The Agency plan adds a space per client, client approvals and your team.</span>
      <router-link class="dh-agency__go" :to="workspaceStore.canOwnClients ? { name: 'agency', params: { tab: 'clients' } } : { name: 'plans' }">{{ workspaceStore.canOwnClients ? 'Add your first client' : 'See the Agency plan' }}</router-link>
      <button type="button" class="dh-agency__x" aria-label="Hide this" @click="hideAgencyHint">×</button>
    </div>
    <div class="dh-hd"><h2>What do you want to make?</h2><small v-if="setup?.niche">Tuned for: {{ setup.niche }}</small></div>
    <div class="dh-cards">
      <button v-for="c in cards" :key="c.key" type="button" class="dh-card" :style="{ '--c': c.colour }" @click="startFrom(c)">
        <img :src="POSTER + c.poster + '.jpg'" alt="" loading="lazy" />
        <span class="dh-card-copy"><span class="dh-card-title">{{ c.title }}</span><span class="dh-card-text">{{ c.text }}</span></span>
        <span class="dh-go">Create now ›</span>
      </button>
    </div>
    <CardModal :card="openCard" :cloned-voice="!!setup?.cloned_voice" @close="openCard = null" />
  </section>
</template>

<style scoped>
.dh{position:relative;display:flex;flex-direction:column;gap:14px;isolation:isolate}
.dh-glow{position:absolute;inset:-90px -6% auto -6%;height:520px;z-index:-1;pointer-events:none;
  background:radial-gradient(50% 45% at 45% 30%,rgba(255,107,53,.2),transparent 70%),radial-gradient(40% 40% at 70% 45%,rgba(124,92,255,.14),transparent 70%),radial-gradient(35% 35% at 25% 55%,rgba(63,207,142,.09),transparent 70%)}
.dh-welcome{text-align:center;margin:14px 0 18px;display:flex;flex-direction:column;align-items:center;gap:6px}
.dh-welcome h1{margin:0;font-size:clamp(30px,4.4vw,46px);font-weight:800;letter-spacing:-.03em;color:var(--color-text-primary,#ececf3)}
.dh-welcome h1 em{font-style:normal;background:linear-gradient(90deg,#ff6b35,#ffa47e);-webkit-background-clip:text;background-clip:text;color:transparent}
.dh-welcome p{margin:0;color:var(--color-text-secondary,#a7abb7);font-size:14px}
.dh-low{margin-top:4px;font:inherit;font-size:13px;color:#f2b84b;background:transparent;border:1px solid rgba(242,184,75,.4);border-radius:999px;padding:5px 12px;cursor:pointer}
.dh-hd{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:6px}
.dh-hd h2{margin:0;font-size:16px;font-weight:700;color:var(--color-text-primary,#ececf3);display:inline-flex;gap:8px;align-items:center}
.dh-hd h2 b{font:700 12.5px ui-monospace,Menlo,monospace;color:#ff6b35}
.dh-hd small{color:var(--color-text-secondary,#8f95a1);font-size:13px}
.dh-setup{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;background:rgba(20,23,29,.7);border:1px solid var(--color-border,#262b34);border-radius:20px;padding:14px;backdrop-filter:blur(6px)}
.dh-step{border:1px solid var(--color-border,#262b34);border-radius:14px;padding:14px;background:rgba(15,17,22,.7);display:flex;flex-direction:column;gap:8px;min-height:196px}
.dh-step.now{border:2px solid #ff6b35;box-shadow:0 0 0 4px rgba(255,107,53,.13)}
.dh-step-top{display:flex;justify-content:space-between;align-items:center}
.dh-ring{width:20px;height:20px;border-radius:50%;border:2px solid #2c313b}
.dh-step.now .dh-ring{border-color:#ff6b35}
.dh-step.done .dh-ring{border-color:#3fcf8e;background:#3fcf8e url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M6 12l4 4 8-8' stroke='%230b0d11' stroke-width='3' fill='none'/%3E%3C/svg%3E") center/14px no-repeat}
.dh-step-n{font:700 11px ui-monospace,Menlo,monospace;letter-spacing:.08em;color:var(--color-text-secondary,#8f95a1)}
.dh-step h3{margin:2px 0 0;font-size:15px;color:var(--color-text-primary,#ececf3)}
.dh-step p{margin:0;color:var(--color-text-secondary,#8f95a1);font-size:13px;flex:1;line-height:1.45}
.dh-step-btn{font:inherit;font-weight:700;font-size:13px;border-radius:999px;padding:9px 12px;background:#191d24;border:1px solid #2c313b;color:var(--color-text-primary,#ececf3);cursor:pointer}
.dh-step.now .dh-step-btn{background:#ff6b35;border-color:#ff6b35;color:#1a0d06}
.dh-step.done .dh-step-btn{background:transparent}
.dh-opt{font-size:12px;color:#5d6472}
.dh-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.dh-card{position:relative;border-radius:20px;overflow:hidden;min-height:220px;display:flex;flex-direction:column;justify-content:space-between;align-items:flex-start;padding:22px;isolation:isolate;border:1px solid var(--color-border,#262b34);cursor:pointer;text-align:left;font:inherit;color:#fff;background:var(--c);transition:transform .25s ease}
.dh-card:hover{transform:translateY(-3px)}
.dh-card:focus-visible{outline:2px solid #ff6b35;outline-offset:3px}
.dh-card img{position:absolute;right:0;top:0;height:100%;width:62%;object-fit:cover;z-index:-2;transition:transform .8s ease}
.dh-card:hover img{transform:scale(1.04)}
.dh-card::before{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(90deg,var(--c) 38%,color-mix(in srgb,var(--c) 60%,transparent) 55%,transparent 82%)}
.dh-card-copy{display:flex;flex-direction:column;gap:6px;max-width:60%}
.dh-card-title{font-size:clamp(22px,2.6vw,32px);font-weight:800;letter-spacing:-.03em;line-height:1.05;text-shadow:0 2px 12px rgba(0,0,0,.25)}
.dh-card-text{color:rgba(255,255,255,.85);font-size:13px}
.dh-go{display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.14);backdrop-filter:blur(6px);border-radius:999px;padding:9px 16px;font-weight:700;font-size:13.5px}
button.dh-link{background:none;border:0;padding:0;font:inherit;font-size:13px;color:#ffa47e;cursor:pointer;text-decoration:underline;text-underline-offset:3px}
.dh-hd-right{display:inline-flex;gap:14px;align-items:center;flex-wrap:wrap}
.dh-hidden{font-size:14px;color:var(--color-text-secondary,#a7abb7)}
.dh-hidden b{font:700 12.5px ui-monospace,Menlo,monospace;color:#ff6b35;margin-left:4px}
.dh-ready{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;border:1px solid rgba(63,207,142,.35);background:rgba(63,207,142,.08);border-radius:14px;padding:11px 14px;font-size:13.5px;color:var(--color-text-primary,#ececf3)}
.dh-ready b{color:#3fcf8e}
.dh-ready-links{display:inline-flex;gap:6px;align-items:center;color:var(--color-text-secondary,#8f95a1)}
.dh-x{background:none;border:0;color:var(--color-text-secondary,#8f95a1);font-size:18px;line-height:1;cursor:pointer;margin-left:8px}
.dh-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}
.dh-day{border:1px solid var(--color-border,#262b34);border-radius:12px;padding:9px;background:rgba(15,17,22,.7);min-height:104px;display:flex;flex-direction:column;gap:6px;min-width:0}
.dh-day.today{border-color:#ff6b35}
.dh-day.past{opacity:.7}
.dh-day b{font-size:12px;display:flex;justify-content:space-between;color:var(--color-text-secondary,#b7bcc6)}
.dh-day b span{font-family:ui-monospace,Menlo,monospace;color:#5d6472;font-weight:400}
.dh-pill{border-radius:8px;padding:4px 6px;font-size:11px;line-height:1.3;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dh-pill.posted{background:rgba(63,207,142,.12);color:#3fcf8e}
.dh-pill.sched{background:rgba(255,107,53,.13);color:#ffa47e}
.dh-pill.failed{background:rgba(248,113,113,.12);color:#f87171}
.dh-more{font-size:11px;color:#8f95a1}
.dh-fill{margin-top:auto;font:inherit;font-size:11px;color:var(--color-text-secondary,#8f95a1);background:transparent;border:1px dashed #2c313b;border-radius:8px;padding:6px;cursor:pointer}
.dh-fill:hover{color:#ffa47e;border-color:#ff6b35}
.dh-weekline{margin:0;color:var(--color-text-secondary,#8f95a1);font-size:13px}
.dh-weekline b{color:var(--color-text-primary,#ececf3)}
.dh-ideas{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.dh-idea{border:1px solid #262b34;border-radius:14px;padding:14px;background:rgba(20,23,29,.8);display:flex;flex-direction:column;gap:8px}
.dh-idea small{font:700 10.5px ui-monospace,Menlo,monospace;letter-spacing:.06em;text-transform:uppercase;color:#ff6b35}
.dh-idea b{font-size:14px;line-height:1.35;color:var(--color-text-primary,#ececf3)}
.dh-idea p{margin:0;color:var(--color-text-secondary,#8f95a1);font-size:12.5px;flex:1}
.dh-idea-go{align-self:flex-start;border:1px solid #2c313b;background:transparent;color:var(--color-text-primary,#ececf3);border-radius:999px;padding:6px 12px;font:inherit;font-weight:600;font-size:12.5px;cursor:pointer}
.dh-idea-go:hover{border-color:#ff6b35;color:#ffa47e}
.dh-idea-go:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
@media (max-width:980px){.dh-ideas{grid-template-columns:1fr}}
@media (max-width:900px){.dh-week{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media (max-width:1100px){.dh-setup{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.dh-cards,.dh-setup{grid-template-columns:1fr}.dh-card-copy{max-width:72%}}
@media (prefers-reduced-motion:reduce){.dh-card,.dh-card img{transition:none}.dh-card:hover,.dh-card:hover img{transform:none}}
.dh-agency{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 14px;margin:0 0 14px;border:1px solid var(--accent-border,rgba(255,107,53,.5));border-radius:10px;background:var(--accent-soft,rgba(255,107,53,.08));font-size:13.5px}
.dh-agency > span{flex:1 1 280px;min-width:0}
.dh-agency__go{font-weight:700;color:var(--accent,#ff6b35);text-decoration:none;white-space:nowrap}
.dh-agency__go:hover{text-decoration:underline}
.dh-agency__x{border:0;background:none;color:var(--text-faint,#8f95a1);font-size:18px;cursor:pointer;width:32px;height:32px}
</style>
