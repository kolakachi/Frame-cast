<script setup>
import { computed, onMounted, ref } from 'vue'
import AppSidebar from '../components/AppSidebar.vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'

// A client's home (phase 3, A4; mockup view 4): videos waiting for their approval (watch, Approve, or Ask for changes
// with a note), this week's schedule, and a message to the agency. The brief and requests stay one link away.
const auth = useAuthStore()
const home = ref(null), posts = ref([]), problem = ref(''), busy = ref(0)
const asking = ref(0), note = ref(''), message = ref(''), sending = ref(false), sent = ref(false)
const firstName = computed(() => (auth.user?.name || '').trim().split(/\s+/)[0] || 'there')
const agency = computed(() => home.value?.agency_name || 'your agency')
const err = e => e.response?.data?.error?.message || e.response?.data?.message || 'Something went wrong. Try again.'

const weekStart = (() => { const d = new Date(); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return d })()
const days = computed(() => Array.from({ length: 7 }, (_, i) => {
  const d = new Date(weekStart); d.setDate(d.getDate() + i)
  const items = posts.value.filter(p => { const t = new Date(p.scheduled_at || p.published_at || p.created_at); return t >= d && t < new Date(d.getTime() + 864e5) })
  return { key: i, name: d.toLocaleDateString('en', { weekday: 'short' }), n: d.getDate(), items }
}))
const PLATFORM = { youtube: 'YouTube', tiktok: 'TikTok', instagram: 'Instagram', facebook: 'Facebook' }

async function load() {
  try { home.value = (await api.get('/client-home')).data.data } catch (e) { problem.value = err(e) }
  const to = new Date(weekStart.getTime() + 7 * 864e5)
  try { posts.value = (await api.get('/scheduled-posts', { params: { from: weekStart.toISOString(), to: to.toISOString(), per_page: 100 } })).data?.data?.posts ?? [] } catch { posts.value = [] }
}
async function decide(a, decision) {
  if (busy.value) return
  if (decision === 'rejected' && !note.value.trim()) { problem.value = 'Say what you would like changed, so ' + agency.value + ' knows what to do.'; return }
  busy.value = a.id; problem.value = ''
  try {
    await api.post(`/approvals/${a.id}/decide`, { decision, comment: decision === 'rejected' ? note.value.trim() : null })
    asking.value = 0; note.value = ''
    await load()
  } catch (e) { problem.value = err(e) }
  finally { busy.value = 0 }
}
async function send() {
  if (sending.value || !message.value.trim()) return
  sending.value = true; problem.value = ''
  try {
    await api.post('/client-work/requests', { title: 'Message', brief: message.value.trim() })
    message.value = ''; sent.value = true
  } catch (e) { problem.value = err(e) }
  finally { sending.value = false }
}
onMounted(load)
</script>

<template>
  <div>
    <AppSidebar :user="auth.user" active-page="client-home" />
    <main class="ch">
      <div class="ch-glow" aria-hidden="true" />
      <header class="ch-welcome">
        <h1>Welcome, <em>{{ firstName }}</em></h1>
        <p v-if="home">{{ home.client_name }} · videos made by {{ agency }}</p>
      </header>
      <p v-if="problem" class="ch-problem" role="alert">{{ problem }}</p>

      <section aria-labelledby="wait-title">
        <div class="ch-hd"><h2 id="wait-title">Waiting for your approval <b v-if="home?.waiting.length">{{ home.waiting.length }}</b></h2><small>Approve, or say what to change</small></div>
        <p v-if="home && !home.waiting.length" class="ch-quiet">Nothing is waiting for you. New videos from {{ agency }} show up here.</p>
        <div v-else class="ch-approve">
          <article v-for="a in home?.waiting || []" :key="a.id" class="ch-ap">
            <video v-if="a.video_url" :src="a.video_url" :poster="a.poster_url || undefined" controls playsinline preload="none" />
            <div v-else class="ch-novideo">The video file is being prepared.</div>
            <div class="ch-ap-body">
              <b>{{ a.title }}</b>
              <small>Sent {{ new Date(a.sent_at).toLocaleDateString('en', { day: 'numeric', month: 'short' }) }}</small>
              <template v-if="asking === a.id">
                <label class="ch-note">What should change?<textarea v-model="note" rows="3" maxlength="2000" placeholder="For example: make the price bigger in the last scene." /></label>
                <div class="ch-btns"><button type="button" class="ch-send" :disabled="!!busy" @click="decide(a, 'rejected')">{{ busy === a.id ? 'Sending…' : 'Send to ' + agency }}</button><button type="button" class="ch-link" @click="asking = 0; note = ''">Cancel</button></div>
              </template>
              <div v-else class="ch-btns"><button type="button" class="ch-yes" :disabled="!!busy" @click="decide(a, 'approved')">{{ busy === a.id ? 'Approving…' : 'Approve' }}</button><button type="button" class="ch-no" :disabled="!!busy" @click="asking = a.id">Ask for changes</button></div>
            </div>
          </article>
        </div>
      </section>

      <section aria-labelledby="week-title">
        <div class="ch-hd"><h2 id="week-title">This week</h2></div>
        <div class="ch-week">
          <div v-for="d in days" :key="d.key" class="ch-day"><b>{{ d.name }} <span>{{ d.n }}</span></b>
            <span v-for="p in d.items.slice(0, 3)" :key="p.id" :class="['ch-pill', p.status === 'published' ? 'posted' : 'sched']">{{ p.project_title || 'Post' }} · {{ PLATFORM[p.platform] || p.platform }}</span>
          </div>
        </div>
      </section>

      <section class="ch-msg" aria-labelledby="msg-title">
        <div class="ch-hd"><h2 id="msg-title">Message {{ agency }}</h2><router-link to="/client-work">Brief &amp; requests →</router-link></div>
        <form @submit.prevent="send"><label for="msg" class="ch-sr">Your message</label><textarea id="msg" v-model="message" rows="3" maxlength="10000" :placeholder="'Ask for a new video, share an idea, or anything for ' + agency + '.'" @input="sent = false" />
          <div class="ch-btns"><small v-if="sent" role="status">Sent. {{ agency }} will see it on their dashboard.</small><span v-else /><button type="submit" class="ch-send" :disabled="sending || !message.trim()">{{ sending ? 'Sending…' : 'Send' }}</button></div>
        </form>
      </section>

      <section v-if="home?.recent.length" aria-labelledby="recent-title">
        <div class="ch-hd"><h2 id="recent-title">Recently decided</h2></div>
        <ul class="ch-recent"><li v-for="r in home.recent" :key="r.id"><b>{{ r.title }}</b><span :class="r.decision === 'approved' ? 'ok' : 'warn'">{{ r.decision === 'approved' ? 'Approved' : 'Changes asked' }}</span><small v-if="r.comment">“{{ r.comment }}”</small></li></ul>
      </section>
    </main>
  </div>
</template>

<style scoped>
.ch{position:relative;isolation:isolate;margin-left:var(--sidebar-width,220px);padding:28px;max-width:1100px;display:flex;flex-direction:column;gap:22px;color:var(--color-text-primary,#ececf3)}
.ch-glow{position:absolute;inset:-90px -6% auto -6%;height:420px;z-index:-1;pointer-events:none;background:radial-gradient(50% 45% at 45% 30%,rgba(255,107,53,.18),transparent 70%),radial-gradient(40% 40% at 70% 45%,rgba(124,92,255,.12),transparent 70%)}
.ch-welcome{text-align:center;margin:10px 0 4px}
.ch-welcome h1{margin:0;font-size:clamp(28px,4vw,42px);font-weight:800;letter-spacing:-.03em}
.ch-welcome h1 em{font-style:normal;background:linear-gradient(90deg,#ff6b35,#ffa47e);-webkit-background-clip:text;background-clip:text;color:transparent}
.ch-welcome p{margin:6px 0 0;color:var(--color-text-secondary,#a7abb7)}
.ch-hd{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
.ch-hd h2{margin:0;font-size:16px;font-weight:700;display:inline-flex;gap:8px;align-items:center}
.ch-hd h2 b{font:700 12.5px ui-monospace,Menlo,monospace;color:#ff6b35}
.ch-hd small,.ch-hd a{color:var(--color-text-secondary,#8f95a1);font-size:13px;text-decoration:none}
.ch-hd a:hover{color:#ffa47e}
.ch-approve{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
.ch-ap{border:1px solid #262b34;border-radius:16px;overflow:hidden;background:#14171d;display:flex;flex-direction:column}
.ch-ap video{width:100%;aspect-ratio:9/12;object-fit:contain;background:#000;display:block}
.ch-novideo{aspect-ratio:9/12;display:grid;place-items:center;color:#8f95a1;font-size:13px;padding:16px;text-align:center}
.ch-ap-body{padding:10px 12px 12px;display:flex;flex-direction:column;gap:8px}
.ch-ap-body b{font-size:13.5px}
.ch-ap-body small{color:#8f95a1}
.ch-btns{display:flex;gap:6px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.ch-yes,.ch-no,.ch-send{flex:1;text-align:center;border-radius:8px;padding:8px 10px;font:inherit;font-weight:700;font-size:12.5px;cursor:pointer;border:1px solid #2c313b;background:transparent;color:#ececf3}
.ch-yes{background:#3fcf8e;border-color:#3fcf8e;color:#06150e}
.ch-send{background:#ff6b35;border-color:#ff6b35;color:#1a0d06;flex:0 0 auto;padding:8px 16px}
.ch-yes:disabled,.ch-no:disabled,.ch-send:disabled{opacity:.55;cursor:default}
.ch-link{background:none;border:0;color:#b7bcc6;font:inherit;font-size:13px;cursor:pointer}
.ch-note{display:flex;flex-direction:column;gap:6px;font-size:12.5px;font-weight:600;color:#b7bcc6}
.ch-note textarea,.ch-msg textarea{background:#0f1116;border:1px solid #2c313b;border-radius:8px;padding:9px 11px;color:#ececf3;font:inherit;font-size:14px;resize:vertical;width:100%;box-sizing:border-box}
.ch-msg form{display:flex;flex-direction:column;gap:10px}
.ch-msg small{color:#3fcf8e;font-size:12.5px}
.ch-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px}
.ch-day{border:1px solid #262b34;border-radius:12px;padding:9px;background:rgba(15,17,22,.7);min-height:84px;display:flex;flex-direction:column;gap:6px;font-size:12px}
.ch-day b{font-size:12px;display:flex;justify-content:space-between;color:#b7bcc6}
.ch-day b span{font-family:ui-monospace,Menlo,monospace;color:#5d6472;font-weight:400}
.ch-pill{border-radius:8px;padding:4px 6px;font-size:11px;line-height:1.3;overflow:hidden;text-overflow:ellipsis}
.ch-pill.posted{background:rgba(63,207,142,.12);color:#3fcf8e}
.ch-pill.sched{background:rgba(255,107,53,.13);color:#ffa47e}
.ch-recent{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
.ch-recent li{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;font-size:13px}
.ch-recent span{font-size:11.5px;border-radius:999px;padding:2px 8px}
.ch-recent .ok{background:rgba(63,207,142,.12);color:#3fcf8e}
.ch-recent .warn{background:rgba(242,184,75,.12);color:#f2b84b}
.ch-recent small{color:#8f95a1}
.ch-quiet{margin:0;color:#8f95a1}
.ch-problem{margin:0;color:#f4bba9;background:#352322;border-radius:10px;padding:10px 14px;font-size:13px}
.ch-sr{position:absolute;left:-9999px}
.ch button:focus-visible,.ch textarea:focus-visible,.ch a:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
@media(max-width:860px){.ch{margin-left:0;padding:80px 16px 100px}.ch-week{grid-template-columns:repeat(4,minmax(0,1fr))}}
</style>
