<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../services/api'
import { useWorkspaceStore } from '../../stores/workspace'

// The agency's part of the dashboard (phase 3, mockup view 3; owner: "Needs you" comes first). The owner sees every
// client, the month's credits by client and by collaborator; a collaborator sees the clients they were given and
// their own allowance. Opening anything switches into that client's workspace first.
// On the Agency overview the owner's client cards live on the Clients page instead (showClients false).
const props = defineProps({ showClients: { type: Boolean, default: true } })
const emit = defineEmits(['role', 'loaded'])
const router = useRouter()
const workspaceStore = useWorkspaceStore()
const view = ref(null), opening = ref('')

const KIND = {
  changes: ['Changes asked', 'warn'], request: ['Client wrote', 'warn'], schedule: ['Approved · schedule it', 'ok'],
  review: ['Review & send', 'you'], with_client: ['With client', ''],
}
const COLOURS = ['#b8532e', '#7a4b22', '#3b5d8c', '#1f6a59', '#7a3b9a', '#8a5a1d', '#2d5d8c', '#a3432f']
const colour = id => COLOURS[Number(id) % COLOURS.length]
const isOwner = computed(() => view.value?.role === 'owner')
const allowance = computed(() => {
  const a = view.value?.allowance
  return a && a.limit !== null ? { ...a, left: Math.max(0, a.limit - a.spent - a.reserved) } : null
})
const creditMax = computed(() => Math.max(1, ...(view.value?.credits?.by_client || []).map(c => c.credits), ...(view.value?.credits?.by_collaborator || []).map(c => c.credits)))

async function load() {
  try { view.value = (await api.get('/dashboard/agency')).data.data } catch { view.value = null }
  if (view.value) emit('role', view.value.role)
  emit('loaded', view.value)
}
async function open(item) {
  const key = item.kind + item.title + item.at
  if (opening.value) return
  // A client's message opens on the client's page for the owner; everything else inside the client's workspace.
  if (item.kind === 'request' && isOwner.value) { router.push(`/clients/${item.client.id}`); return }
  opening.value = key
  try { await workspaceStore.switchTo(item.open.workspace_id, item.kind === 'request' ? '/client-work' : item.open.path) } catch { opening.value = '' }
}
async function createFor(c) {
  if (opening.value) return
  opening.value = 'c' + c.id
  try { await workspaceStore.switchTo(c.id, '/create') } catch { opening.value = '' }
}
onMounted(load)
</script>

<template>
  <div v-if="view" class="ap">
    <p v-if="allowance" class="ap-allow"><b>{{ allowance.left.toLocaleString() }}</b> of {{ allowance.limit.toLocaleString() }} credits left this month<span v-if="allowance.reserved"> · {{ allowance.reserved.toLocaleString() }} held for work in progress</span></p>

    <template v-if="view.needs.length || isOwner">
      <div class="ap-hd"><h2>Needs you <b v-if="view.needs.length">{{ view.needs.length }}</b></h2><small>{{ isOwner ? 'Across all clients' : 'Across your clients' }}</small></div>
      <div v-if="view.needs.length" class="ap-needs">
        <button v-for="n in view.needs" :key="n.kind + n.title + n.at" type="button" class="ap-need" :disabled="!!opening" @click="open(n)">
          <span class="ap-thumb" :style="{ '--c': colour(n.client.id) }"><img v-if="n.poster_url" :src="n.poster_url" alt="" loading="lazy" /><i v-else aria-hidden="true">{{ n.client.name.charAt(0) }}</i></span>
          <span class="ap-need-copy"><b>{{ n.client.name }} · {{ n.title }}</b><small>{{ n.detail }}</small></span>
          <span :class="['ap-tag', KIND[n.kind]?.[1]]">{{ opening === n.kind + n.title + n.at ? 'Opening…' : KIND[n.kind]?.[0] }}</span>
        </button>
      </div>
      <p v-else class="ap-clear">Nothing is waiting on you. New changes, approvals and finished videos show here.</p>
    </template>

    <template v-if="!isOwner || props.showClients">
    <div class="ap-hd"><h2>{{ isOwner ? 'Clients' : 'Your clients' }}</h2><span class="ap-links"><router-link v-if="isOwner" to="/agency/team">Team →</router-link><router-link v-if="isOwner" to="/agency/clients">All clients →</router-link></span></div>
    <p v-if="!view.clients.length" class="ap-clear">{{ isOwner ? 'No clients yet.' : 'Your agency hasn\'t given you any clients yet. You can still make videos for the agency itself.' }}</p>
    <div v-else class="ap-clients">
      <article v-for="c in view.clients" :key="c.id" class="ap-client">
        <div class="ap-client-top"><span class="ap-logo" :style="{ background: colour(c.id) }">{{ c.name.charAt(0) }}</span><b>{{ c.name }}</b></div>
        <div class="ap-stats">
          <span v-if="c.changes" class="warn">{{ c.changes }} changes asked</span>
          <span v-if="c.to_review" class="you">{{ c.to_review }} to review</span>
          <span v-if="c.to_schedule" class="ok">{{ c.to_schedule }} to schedule</span>
          <span v-if="c.with_client">{{ c.with_client }} with client</span>
          <span v-if="c.scheduled">{{ c.scheduled }} scheduled</span>
          <span v-if="!c.changes && !c.to_review && !c.to_schedule && !c.with_client && !c.scheduled" class="quiet">All clear</span>
        </div>
        <div class="ap-row"><span>Setup {{ c.setup_done }}/{{ c.setup_total }}</span><span>{{ c.credits_this_month.toLocaleString() }}{{ c.monthly_cap ? ' / ' + c.monthly_cap.toLocaleString() : '' }} credits this month</span></div>
        <div class="ap-bar" aria-hidden="true"><i :style="{ width: (100 * c.setup_done / c.setup_total) + '%' }" /></div>
        <button type="button" class="ap-create" :disabled="!!opening" @click="createFor(c)">{{ opening === 'c' + c.id ? 'Opening…' : 'Create for ' + c.name + ' →' }}</button>
      </article>
    </div>
    </template>

    <template v-if="isOwner && view.credits">
      <div class="ap-hd"><h2>Credits this month</h2><small>One shared balance{{ view.credits.own ? ' · the agency itself used ' + view.credits.own.toLocaleString() : '' }}</small></div>
      <div class="ap-usage">
        <div v-for="c in view.credits.by_client" :key="'c' + c.id" class="ap-urow"><span>{{ c.name }}</span><div class="ap-bar"><i :style="{ width: (100 * c.credits / creditMax) + '%' }" /></div><span>{{ c.credits.toLocaleString() }}</span></div>
        <template v-if="view.credits.by_collaborator.length">
          <div class="ap-sub">By person</div>
          <div v-for="p in view.credits.by_collaborator" :key="'p' + p.id" class="ap-urow"><span>{{ p.name }}</span><div class="ap-bar ap-bar--team"><i :style="{ width: (100 * p.credits / creditMax) + '%' }" /></div><span>{{ p.credits.toLocaleString() }}{{ p.allowance !== null ? ' / ' + p.allowance.toLocaleString() : '' }}</span></div>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ap{display:flex;flex-direction:column;gap:12px}
.ap-allow{margin:-4px auto 6px;font-size:13.5px;color:var(--color-text-secondary,#b7bcc6);border:1px solid #2c313b;border-radius:999px;padding:6px 14px}
.ap-allow b{color:var(--color-text-primary,#ececf3)}
.ap-hd{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px}
.ap-hd h2{margin:0;font-size:16px;font-weight:700;color:var(--color-text-primary,#ececf3);display:inline-flex;gap:8px;align-items:center}
.ap-hd h2 b{font:700 12.5px ui-monospace,Menlo,monospace;color:#ff6b35}
.ap-hd small{color:var(--color-text-secondary,#8f95a1);font-size:13px}
.ap-links{display:inline-flex;gap:14px}
.ap-links a{color:var(--color-text-secondary,#b7bcc6);font-size:13px;text-decoration:none}
.ap-links a:hover{color:#ffa47e}
.ap-needs{border:1px solid #262b34;border-radius:16px;background:rgba(20,23,29,.8);overflow:hidden}
.ap-need{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:12px;align-items:center;width:100%;padding:10px 14px;border:0;border-top:1px solid #1f232b;background:transparent;color:inherit;font:inherit;text-align:left;cursor:pointer}
.ap-need:first-child{border-top:0}
.ap-need:hover{background:#14171d}
.ap-need:disabled{cursor:default}
.ap-thumb{width:44px;height:58px;border-radius:7px;overflow:hidden;background:var(--c);display:grid;place-items:center}
.ap-thumb img{width:100%;height:100%;object-fit:cover}
.ap-thumb i{font-style:normal;font-weight:800;color:#fff}
.ap-need-copy{display:flex;flex-direction:column;min-width:0}
.ap-need-copy b{font-size:13.5px;color:var(--color-text-primary,#ececf3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ap-need-copy small{color:var(--color-text-secondary,#8f95a1);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ap-tag{font-size:11.5px;border-radius:999px;padding:4px 9px;white-space:nowrap;background:#191d24;color:#b7bcc6}
.ap-tag.ok,.ap-stats .ok{background:rgba(63,207,142,.12);color:#3fcf8e}
.ap-tag.warn,.ap-stats .warn{background:rgba(242,184,75,.12);color:#f2b84b}
.ap-tag.you,.ap-stats .you{background:rgba(255,107,53,.13);color:#ffa47e}
.ap-clear{margin:0;color:var(--color-text-secondary,#8f95a1);font-size:13px}
.ap-clients{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.ap-client{border:1px solid #262b34;border-radius:16px;padding:14px;background:rgba(20,23,29,.8);display:flex;flex-direction:column;gap:10px}
.ap-client-top{display:flex;align-items:center;gap:10px}
.ap-client-top b{font-size:14px;color:var(--color-text-primary,#ececf3)}
.ap-logo{width:34px;height:34px;border-radius:9px;display:grid;place-items:center;font-weight:800;color:#fff;flex:0 0 34px}
.ap-stats{display:flex;flex-wrap:wrap;gap:6px}
.ap-stats span{font-size:11.5px;border-radius:999px;padding:3px 8px;background:#191d24;color:#b7bcc6}
.ap-stats .quiet{color:#5d6472}
.ap-row{display:flex;justify-content:space-between;gap:8px;font-size:11.5px;color:var(--color-text-secondary,#8f95a1)}
.ap-bar{height:6px;border-radius:999px;background:#191d24;overflow:hidden}
.ap-bar i{display:block;height:100%;background:#ff6b35}
.ap-bar--team i{background:#7c5cff}
.ap-create{border:1px solid rgba(255,107,53,.5);color:#ff6b35;background:transparent;border-radius:8px;padding:7px 12px;font:inherit;font-weight:700;font-size:12.5px;cursor:pointer}
.ap-create:hover{background:rgba(255,107,53,.1)}
.ap-create:disabled{opacity:.6;cursor:default}
.ap-usage{border:1px solid #262b34;border-radius:16px;padding:14px;background:rgba(20,23,29,.8);display:grid;gap:9px}
.ap-urow{display:grid;grid-template-columns:150px minmax(0,1fr) 110px;gap:12px;align-items:center;font-size:12.5px;color:var(--color-text-primary,#ececf3)}
.ap-urow span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ap-urow span:last-child{text-align:right;font-family:ui-monospace,Menlo,monospace;color:#b7bcc6}
.ap-sub{font:700 10.5px ui-monospace,Menlo,monospace;letter-spacing:.08em;text-transform:uppercase;color:#5d6472;margin-top:6px}
.ap button:focus-visible,.ap a:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
@media (max-width:640px){.ap-urow{grid-template-columns:100px minmax(0,1fr) 80px}}
</style>
