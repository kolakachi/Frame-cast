<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { peekBrief, saveBrief } from '../services/pendingBrief'

// The Weave onboarding (2026-10-09): three questions, each skippable, then Create opens with a ready first brief.
// What they make most picks the first video and the examples they see; their website becomes the brand kit; how they
// heard of us is for marketing only. Existing users never see it (they are already onboarded).
const router = useRouter()
const auth = useAuthStore()

// Each goal shows its own examples; no example appears under two goals.
const GOALS = [
  { id: 'ads', icon: 'M4 6h16v12H4zM8 10h8M8 14h5', label: 'Product ads', detail: 'Ads for what you sell, ready for Reels and TikTok', samples: ['b03'] },
  { id: 'ugc', icon: 'M12 12a4 4 0 100-8 4 4 0 000 8zM5 20c1-4 4-6 7-6s6 2 7 6', label: 'UGC-style ads', detail: 'A creator talking about your product', samples: ['b05'] },
  { id: 'explain', icon: 'M5 19V9M10 19V5M15 19v-7M20 19v-4', label: 'Explainers', detail: 'Teach, explain or show how something works', samples: ['s100'] },
  { id: 'launch', icon: 'M12 3l2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5z', label: 'Launches and promos', detail: 'A new product, an offer or an event', samples: ['b01'] },
  { id: 'agency', icon: 'M4 5h7v7H4zM13 5h7v7h-7zM4 14h7v5H4zM13 14h7v5h-7z', label: 'Videos for my clients', detail: 'An agency or freelancer making videos for brands', samples: ['l30703d4a', 'le55486c6', 'l8623176a'] },
  { id: 'explore', icon: 'M12 3a9 9 0 100 18 9 9 0 000-18zM15 9l-2 4-4 2 2-4z', label: 'Just exploring', detail: 'Show me what it can do', samples: ['l1317b615'] },
]
const SOURCES = [['tiktok', 'TikTok'], ['instagram', 'Instagram'], ['youtube', 'YouTube'], ['x', 'X'], ['facebook', 'Facebook'], ['linkedin', 'LinkedIn'],
  ['google', 'Google'], ['appsumo', 'AppSumo'], ['friend', 'A friend'], ['assistant', 'ChatGPT or Claude'], ['other', 'Other']]
const STEPS = ['goal', 'brand', 'heard']

const step = ref('goal'), goal = ref(''), hover = ref(''), heard = ref(''), busy = ref(false), error = ref('')
const site = ref(''), typed = ref(''), useName = ref(false), brand = ref(null), reading = ref(false), industry = ref('')
const samples = ref({}), cycle = ref(0)
const idx = computed(() => STEPS.indexOf(step.value))
const shown = computed(() => {
  const g = GOALS.find(x => x.id === (hover.value || goal.value)) || GOALS[0]
  const id = g.samples[cycle.value % g.samples.length]
  return samples.value[id] || null
})
let timer = null
onMounted(async () => {
  // Our own example videos play beside the question: the one for the kind of video being chosen.
  try { samples.value = Object.fromEntries(((await api.get('/create/samples')).data.data || []).map(s => [s.id, s])) } catch { /* the panel stays plain */ }
  timer = setInterval(() => { cycle.value++ }, 2600)
})
onBeforeUnmount(() => clearInterval(timer))
watch([hover, goal], () => { cycle.value = 0 })

async function readSite() {
  if (!site.value.trim() || reading.value) return
  reading.value = true; error.value = ''
  try { brand.value = (await api.post('/onboarding/brand', { url: site.value.trim() }, { timeout: 120000 })).data.data; industry.value = brand.value.industry || '' }
  catch (e) { error.value = e.response?.data?.message || 'That website could not be read. Type your brand name instead.' }
  finally { reading.value = false }
}
async function saveName() {
  if (!typed.value.trim()) return
  reading.value = true; error.value = ''
  try { brand.value = (await api.post('/onboarding/brand', { name: typed.value.trim() })).data.data }
  catch (e) { error.value = e.response?.data?.message || 'That could not be saved.' }
  finally { reading.value = false }
}
function next() {
  if (step.value === 'goal' && !goal.value) return
  if (idx.value < STEPS.length - 1) { step.value = STEPS[idx.value + 1]; error.value = ''; return }
  finish(false)
}
function back() { if (idx.value > 0) step.value = STEPS[idx.value - 1] }
async function finish(skipped) {
  if (busy.value) return
  busy.value = true; error.value = ''
  try {
    const r = (await api.post('/onboarding', { skipped, ...(skipped ? {} : { goal: goal.value || null, heard: heard.value || null, industry: industry.value || null }) })).data.data
    // A brief written on wyvstudio.com before signing up wins: it is what they came to make.
    if (r.brief && !peekBrief()) saveBrief({ text: r.brief, from: 'onboarding' })
    try { localStorage.setItem('wyv_onboarding', JSON.stringify({ style: r.style ? 'pack:' + r.style : '', sample_filter: r.sample_filter || 'all', at: Date.now() })) } catch { /* defaults only */ }
    auth.markOnboarded?.()
    await router.replace({ name: 'create' })
  } catch (e) { error.value = e.response?.data?.message || 'Something went wrong. Try again.' }
  finally { busy.value = false }
}
const footer = computed(() => ['We use this to set up your videos and suggest what to make next.', 'Your logo, colours and products go into your brand kit. Change them anytime in Settings.', 'This is the last question.'][idx.value] || '')
</script>

<template>
  <div class="welcome">
    <aside class="welcome__show" aria-hidden="true">
      <figure>
        <video v-if="shown?.preview_url" :key="shown.id" :src="shown.preview_url" :poster="shown.poster_url" autoplay muted loop playsinline />
        <figcaption v-if="shown"><i /><span><b>{{ shown.title }}</b><small>Made in WyvStudio · {{ shown.seconds }} s</small></span></figcaption>
      </figure>
    </aside>
    <main class="welcome__main">
      <header class="welcome__bar">
        <button type="button" class="welcome__back" :style="{ visibility: idx > 0 ? 'visible' : 'hidden' }" aria-label="Back" @click="back"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg></button>
        <span class="welcome__dots" role="progressbar" aria-valuemin="1" aria-valuemax="3" :aria-valuenow="idx + 1" :aria-label="`Step ${idx + 1} of 3`"><i v-for="(s, i) in STEPS" :key="s" :class="{ on: i <= idx, now: i === idx }" /></span>
        <button type="button" class="welcome__skip" :disabled="busy" @click="finish(true)">Skip for now</button>
      </header>

      <section v-if="step === 'goal'" class="welcome__step">
        <h1>What will you make most?<span>We'll set up your first video for it.</span></h1>
        <div class="welcome__grid">
          <button v-for="g in GOALS" :key="g.id" type="button" :class="['welcome__tile', { on: goal === g.id }]" :aria-pressed="goal === g.id" @click="goal = g.id" @mouseenter="hover = g.id" @mouseleave="hover = ''" @focus="hover = g.id" @blur="hover = ''">
            <span class="welcome__tile-top"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="g.icon" /></svg><i class="welcome__radio" /></span>
            <b>{{ g.label }}</b><span>{{ g.detail }}</span>
          </button>
        </div>
        <button type="button" class="welcome__go" :disabled="!goal" @click="next">Continue</button>
      </section>

      <section v-else-if="step === 'brand'" class="welcome__step">
        <h1>Your brand<span>Paste your website. We'll read the rest.</span></h1>
        <form v-if="!useName" class="welcome__brand" @submit.prevent="readSite">
          <label for="welcome-site">Your website</label>
          <div class="welcome__row"><input id="welcome-site" v-model="site" inputmode="url" autocomplete="url" placeholder="yourbrand.com" :disabled="reading" /><button type="submit" class="welcome__read" :disabled="reading || !site.trim()">{{ reading ? 'Reading…' : brand ? 'Read again' : 'Read my site' }}</button></div>
          <button type="button" class="welcome__link" @click="useName = true; error = ''">No website? Type your brand name instead</button>
        </form>
        <form v-else class="welcome__brand" @submit.prevent="saveName">
          <label for="welcome-name">Your brand name</label>
          <div class="welcome__row"><input id="welcome-name" v-model="typed" maxlength="80" placeholder="Your brand" :disabled="reading" /><button type="submit" class="welcome__read" :disabled="reading || !typed.trim()">Save</button></div>
          <button type="button" class="welcome__link" @click="useName = false; error = ''">I have a website</button>
        </form>
        <div v-if="brand" class="welcome__found">
          <div class="welcome__who"><img v-if="brand.logo_url" :src="brand.logo_url" alt="" /><span v-else class="welcome__mono">{{ (brand.name || '?').slice(0, 1) }}</span><span><b>{{ brand.name }}</b><small v-if="brand.products.length">{{ brand.products.slice(0, 3).join(', ') }}</small><small v-else-if="brand.summary">{{ brand.summary }}</small></span></div>
          <div v-if="brand.palette.length || brand.url" class="welcome__meta">
            <template v-if="brand.palette.length"><span class="welcome__k">Colours</span><i v-for="c in brand.palette" :key="c" class="welcome__swatch" :style="{ background: c }" /></template>
            <span class="welcome__fill" />
            <template v-if="brand.url"><label for="welcome-industry" class="welcome__k">Industry</label><select id="welcome-industry" v-model="industry"><option value="">Not sure</option><option v-for="(name, id) in brand.industries" :key="id" :value="id">{{ name }}</option></select></template>
          </div>
          <small class="welcome__note">Saved to your brand kit. Change anything later in Settings.</small>
        </div>
        <p v-if="error" class="welcome__error" role="alert">{{ error }}</p>
        <button type="button" class="welcome__go" :disabled="reading" @click="next">{{ brand ? 'Continue' : 'Continue without it' }}</button>
      </section>

      <section v-else class="welcome__step">
        <h1>How did you hear about us?<span>Optional. It helps us reach people like you.</span></h1>
        <div class="welcome__chips"><button v-for="[id, label] in SOURCES" :key="id" type="button" :class="['welcome__chip', { on: heard === id }]" :aria-pressed="heard === id" @click="heard = heard === id ? '' : id">{{ label }}</button></div>
        <p v-if="error" class="welcome__error" role="alert">{{ error }}</p>
        <button type="button" class="welcome__go" :disabled="busy" @click="next">{{ busy ? 'Setting up…' : 'Make my first video' }}</button>
      </section>

      <p class="welcome__foot">{{ footer }}</p>
    </main>
  </div>
</template>

<style scoped>
.welcome{min-height:100vh;display:flex;flex-wrap:wrap;background:var(--bg,#0b0d11);color:var(--text,#eceef1);font-size:14px}
.welcome__show{flex:1 1 360px;max-width:460px;box-sizing:border-box;padding:16px;display:flex}
.welcome__show figure{margin:0;flex:1;position:relative;border-radius:16px;overflow:hidden;background:var(--surface,#14171d);min-height:520px}
.welcome__show video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.welcome__show figcaption{position:absolute;left:14px;right:14px;bottom:14px;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:rgba(11,13,17,.78);color:#eceef1;font-size:13px}
.welcome__show figcaption i{width:8px;height:8px;border-radius:50%;background:#ff6b35;flex:0 0 auto}
.welcome__show figcaption span{display:flex;flex-direction:column;min-width:0}
.welcome__show figcaption b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.welcome__show figcaption small{color:#b7bcc6}
.welcome__main{flex:999 1 520px;min-width:0;box-sizing:border-box;padding:24px clamp(16px,4vw,40px) 28px;display:flex;flex-direction:column}
.welcome__bar{display:flex;align-items:center;justify-content:space-between;gap:12px}
.welcome__back{width:40px;height:40px;border-radius:50%;border:1px solid var(--border,#2c313b);background:transparent;color:var(--text-dim,#b7bcc6);display:grid;place-items:center;cursor:pointer}
.welcome__dots{display:flex;gap:6px}
.welcome__dots i{display:block;height:4px;width:16px;border-radius:2px;background:var(--border,#2c313b)}
.welcome__dots i.on{background:var(--accent,#ff6b35)}
.welcome__dots i.now{width:28px}
.welcome__skip{font:inherit;font-size:13px;color:var(--text-dim,#b7bcc6);background:transparent;border:0;padding:10px 6px;cursor:pointer}
.welcome__skip:hover{color:var(--text,#eceef1)}
.welcome__step{flex:1;display:flex;flex-direction:column;justify-content:center;align-items:center;gap:26px;padding:24px 0}
h1{margin:0;text-align:center;font-size:clamp(26px,3.4vw,34px);line-height:1.15;font-weight:700;letter-spacing:-.01em;text-wrap:balance}
h1 span{display:block;color:var(--text-faint,#8f95a1);font-weight:500}
.welcome__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;width:100%;max-width:560px}
@media (max-width:520px){.welcome__grid{grid-template-columns:minmax(0,1fr)}.welcome__show{display:none}}
.welcome__tile{display:flex;flex-direction:column;align-items:flex-start;gap:6px;text-align:left;padding:14px 16px;min-height:108px;border-radius:12px;cursor:pointer;font:inherit;color:inherit;border:1px solid var(--border,#262b34);background:var(--surface-2,#14171d)}
.welcome__tile:hover{border-color:var(--border-strong,#3a3f49)}
.welcome__tile.on{border-color:var(--accent-border,rgba(255,107,53,.7));background:var(--accent-soft,rgba(255,107,53,.08))}
.welcome__tile:focus-visible,.welcome__chip:focus-visible,.welcome__go:focus-visible{outline:2px solid var(--accent,#ff6b35);outline-offset:2px}
.welcome__tile-top{display:flex;justify-content:space-between;align-items:center;width:100%;color:var(--text-faint,#8f95a1)}
.welcome__tile.on .welcome__tile-top{color:var(--accent,#ff6b35)}
.welcome__radio{width:16px;height:16px;border-radius:50%;box-sizing:border-box;border:1.5px solid var(--text-faint,#5d6472)}
.welcome__tile.on .welcome__radio{border:5px solid var(--accent,#ff6b35)}
.welcome__tile b{font-size:15px}
.welcome__tile > span:last-child{font-size:12.5px;color:var(--text-faint,#8f95a1);line-height:1.4}
.welcome__go{font:700 14px inherit;font-family:inherit;border:0;border-radius:8px;padding:12px 28px;min-height:44px;cursor:pointer;background:var(--accent,#ff6b35);color:#1a0d06}
.welcome__go:disabled{cursor:not-allowed;background:var(--surface-2,#262a31);color:var(--text-faint,#5d6472)}
.welcome__brand{width:100%;max-width:520px;display:flex;flex-direction:column;gap:10px}
.welcome__brand label{font-size:12.5px;color:var(--text-dim,#b7bcc6)}
.welcome__row{display:flex;gap:8px;flex-wrap:wrap}
.welcome__row input{flex:1 1 220px;min-width:0;font:15px inherit;font-family:inherit;color:var(--text,#eceef1);background:var(--surface-2,#14171d);border:1px solid var(--border,#2c313b);border-radius:8px;padding:12px 14px}
.welcome__read{font:600 14px inherit;font-family:inherit;color:var(--text,#eceef1);background:var(--surface,#191d24);border:1px solid var(--border,#2c313b);border-radius:8px;padding:0 16px;min-height:44px;cursor:pointer}
.welcome__link{align-self:flex-start;font:inherit;font-size:13px;color:var(--text-dim,#b7bcc6);background:none;border:0;padding:4px 0;cursor:pointer;text-decoration:underline}
.welcome__found{width:100%;max-width:520px;box-sizing:border-box;border:1px solid var(--border,#262b34);border-radius:12px;background:var(--surface-2,#14171d);padding:14px 16px;display:flex;flex-direction:column;gap:12px}
.welcome__who{display:flex;align-items:center;gap:12px}
.welcome__who img,.welcome__mono{width:44px;height:44px;border-radius:10px;object-fit:contain;background:#fff;flex:0 0 auto}
.welcome__mono{display:grid;place-items:center;font-weight:700;font-size:20px;color:#1a0d06;background:var(--accent,#ff6b35)}
.welcome__who > span{display:flex;flex-direction:column;gap:2px;min-width:0}
.welcome__who small,.welcome__k,.welcome__note{color:var(--text-faint,#8f95a1);font-size:12px}
.welcome__meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.welcome__swatch{width:22px;height:22px;border-radius:6px;border:1px solid var(--border,#3a3f49)}
.welcome__fill{flex:1}
.welcome__meta select{font:13px inherit;font-family:inherit;color:var(--text,#eceef1);background:var(--surface,#191d24);border:1px solid var(--border,#2c313b);border-radius:8px;padding:6px 8px}
.welcome__chips{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;max-width:600px}
.welcome__chip{font:500 13.5px inherit;font-family:inherit;padding:10px 14px;min-height:40px;border-radius:8px;cursor:pointer;color:var(--text,#eceef1);border:1px solid var(--border,#2c313b);background:var(--surface-2,#14171d)}
.welcome__chip.on{color:var(--accent,#ff6b35);border-color:var(--accent-border,rgba(255,107,53,.7));background:var(--accent-soft,rgba(255,107,53,.08))}
.welcome__error{margin:0;color:#f0b4ac;font-size:13px;text-align:center;max-width:520px}
.welcome__foot{margin:0;text-align:center;font-size:12.5px;color:var(--text-faint,#5d6472)}
</style>
