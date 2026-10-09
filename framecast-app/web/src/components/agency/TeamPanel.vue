<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../services/api'

// An agency's team (phase 3, 2026-10-09; owner: collaborators are agency team members). Invite a collaborator, give
// them a monthly credit allowance and the clients they may work in, then change, pause or remove them.
const team = ref([]), clients = ref([]), max = ref(25), loading = ref(true), problem = ref(''), notice = ref('')
const form = ref({ email: '', name: '', allowance: '', clientIds: [] }), inviting = ref(false)
const editing = ref(null), saving = ref(false)

const clientName = id => clients.value.find(c => c.id === id)?.name || 'Client'
const left = p => p.allowance === null ? null : Math.max(0, p.allowance - p.spent_this_month - p.reserved)
const full = computed(() => team.value.length >= max.value)
const message = e => e.response?.data?.error?.message || e.response?.data?.message || 'Something went wrong. Try again.'

async function load() {
  loading.value = true; problem.value = ''
  try { const d = (await api.get('/team')).data.data; team.value = d.collaborators; clients.value = d.clients; max.value = d.max }
  catch (e) { problem.value = message(e) }
  finally { loading.value = false }
}
async function invite() {
  if (inviting.value) return
  inviting.value = true; problem.value = ''; notice.value = ''
  const f = form.value
  try {
    const d = (await api.post('/team', { email: f.email.trim(), name: f.name.trim() || null, allowance: f.allowance === '' ? null : Number(f.allowance), client_ids: f.clientIds })).data.data
    notice.value = d.invite_sent ? `Invitation sent to ${d.collaborator.email}.` : `${d.collaborator.email} was added, but the email failed. Use Resend.`
    form.value = { email: '', name: '', allowance: '', clientIds: [] }
    await load()
  } catch (e) { problem.value = message(e) }
  finally { inviting.value = false }
}
function edit(p) { editing.value = { id: p.id, name: p.name, allowance: p.allowance === null ? '' : String(p.allowance), clientIds: [...p.client_ids], status: p.status } }
async function save() {
  if (saving.value || !editing.value) return
  saving.value = true; problem.value = ''
  const e = editing.value
  try {
    await api.patch(`/team/${e.id}`, { name: e.name, allowance: e.allowance === '' ? null : Number(e.allowance), client_ids: e.clientIds, status: e.status })
    editing.value = null; await load()
  } catch (err) { problem.value = message(err) }
  finally { saving.value = false }
}
async function resend(p) { try { await api.post(`/team/${p.id}/invite`); notice.value = `Invitation sent again to ${p.email}.` } catch (e) { problem.value = message(e) } }
const confirmRemove = ref(null)
async function remove(p) {
  try { await api.delete(`/team/${p.id}`); confirmRemove.value = null; editing.value = null; await load() } catch (e) { problem.value = message(e) }
}
function toggle(list, id) { const i = list.indexOf(id); if (i >= 0) list.splice(i, 1); else list.push(id) }
onMounted(load)
</script>

<template>
  <div class="tm">
      <p v-if="problem" class="tm-problem" role="alert">{{ problem }}</p>
      <p v-if="notice" class="tm-notice" role="status">{{ notice }}</p>

      <section class="tm-card" aria-labelledby="invite-title">
        <h2 id="invite-title">Invite a collaborator</h2>
        <form class="tm-form" @submit.prevent="invite">
          <label>Email<input v-model="form.email" type="email" required placeholder="ada@studio.com" :disabled="full" /></label>
          <label>Name <small>optional</small><input v-model="form.name" type="text" placeholder="Ada" :disabled="full" /></label>
          <label>Monthly allowance <small>credits · empty for no limit</small><input v-model="form.allowance" type="number" min="0" step="10" placeholder="500" :disabled="full" /></label>
          <fieldset v-if="clients.length" class="tm-clients"><legend>Clients they can work in</legend>
            <label v-for="c in clients" :key="c.id" class="tm-check"><input type="checkbox" :checked="form.clientIds.includes(c.id)" :disabled="full" @change="toggle(form.clientIds, c.id)" />{{ c.name }}</label>
          </fieldset>
          <div class="tm-actions"><small>{{ full ? `Your team is full (${max}).` : 'They get an email with a sign-in link, good for 7 days.' }}</small><button type="submit" class="tm-btn" :disabled="inviting || full || !form.email">{{ inviting ? 'Sending…' : 'Send invitation' }}</button></div>
        </form>
      </section>

      <section aria-labelledby="team-title">
        <h2 id="team-title" class="tm-h2">Collaborators <span>{{ team.length }} / {{ max }}</span></h2>
        <p v-if="loading" class="tm-quiet">Loading…</p>
        <p v-else-if="!team.length" class="tm-quiet">No collaborators yet.</p>
        <div v-else class="tm-list">
          <article v-for="p in team" :key="p.id" class="tm-person">
            <div class="tm-who"><span class="tm-avatar" aria-hidden="true">{{ (p.name || p.email).charAt(0).toUpperCase() }}</span><span><b>{{ p.name || p.email }}</b><small>{{ p.email }} · {{ p.status === 'paused' ? 'Paused' : p.invite === 'accepted' ? 'Joined' : p.invite === 'failed' ? 'Email failed' : 'Invited' }}</small></span></div>
            <div class="tm-spend">
              <template v-if="p.allowance !== null"><b>{{ left(p).toLocaleString() }}</b> of {{ p.allowance.toLocaleString() }} left this month<div class="tm-bar" aria-hidden="true"><i :style="{ width: Math.min(100, 100 * (p.spent_this_month + p.reserved) / Math.max(1, p.allowance)) + '%' }" /></div></template>
              <template v-else>{{ p.spent_this_month.toLocaleString() }} credits this month · no limit</template>
            </div>
            <div class="tm-in">{{ p.client_ids.length ? p.client_ids.map(clientName).join(', ') : 'Your workspace only' }}</div>
            <div class="tm-row-actions">
              <button v-if="p.invite !== 'accepted'" type="button" class="tm-link" @click="resend(p)">Resend</button>
              <button type="button" class="tm-link" @click="edit(p)">Edit</button>
            </div>
          </article>
        </div>
      </section>

      <div v-if="editing" class="tm-scrim" @click.self="editing = null">
        <form class="tm-card tm-dialog" role="dialog" aria-label="Edit collaborator" @submit.prevent="save" @keydown.esc="editing = null">
          <h2>Edit {{ editing.name }}</h2>
          <label>Name<input v-model="editing.name" type="text" /></label>
          <label>Monthly allowance <small>credits · empty for no limit</small><input v-model="editing.allowance" type="number" min="0" step="10" /></label>
          <fieldset v-if="clients.length" class="tm-clients"><legend>Clients they can work in</legend>
            <label v-for="c in clients" :key="c.id" class="tm-check"><input type="checkbox" :checked="editing.clientIds.includes(c.id)" @change="toggle(editing.clientIds, c.id)" />{{ c.name }}</label>
          </fieldset>
          <label class="tm-check"><input type="checkbox" :checked="editing.status === 'paused'" @change="editing.status = $event.target.checked ? 'paused' : 'active'" />Paused: they can't sign in or spend</label>
          <div class="tm-actions">
            <button v-if="confirmRemove !== editing.id" type="button" class="tm-link tm-danger" @click="confirmRemove = editing.id">Remove from team</button>
            <span v-else class="tm-confirm">Remove them? Their videos stay. <button type="button" class="tm-link tm-danger" @click="remove(editing)">Remove</button> <button type="button" class="tm-link" @click="confirmRemove = null">Keep</button></span>
            <span class="tm-spacer" />
            <button type="button" class="tm-link" @click="editing = null">Cancel</button>
            <button type="submit" class="tm-btn" :disabled="saving">{{ saving ? 'Saving…' : 'Save' }}</button>
          </div>
        </form>
      </div>
  </div>
</template>

<style scoped>
.tm{display:flex;flex-direction:column;gap:20px;color:var(--color-text-primary,#ececf3)}
.tm-card{border:1px solid #262b34;border-radius:16px;background:rgba(20,23,29,.85);padding:18px}
.tm-card h2,.tm-h2{margin:0 0 12px;font-size:16px;font-weight:700}
.tm-h2 span{font:600 12px ui-monospace,Menlo,monospace;color:#8f95a1;margin-left:6px}
.tm-form,.tm-dialog{display:grid;gap:12px}
.tm-form{grid-template-columns:repeat(3,minmax(0,1fr))}
.tm-form label,.tm-dialog label{display:flex;flex-direction:column;gap:6px;font-size:12.5px;font-weight:600;color:#b7bcc6}
.tm-form small,.tm-dialog small{font-weight:400;color:#5d6472}
.tm-form input,.tm-dialog input[type=text],.tm-dialog input[type=number]{background:#0f1116;border:1px solid #2c313b;border-radius:8px;padding:9px 11px;color:#ececf3;font:inherit;font-size:14px}
.tm-clients{grid-column:1/-1;border:1px solid #262b34;border-radius:12px;padding:10px 12px;display:flex;flex-wrap:wrap;gap:8px 18px;margin:0}
.tm-clients legend{font-size:12.5px;font-weight:600;color:#b7bcc6;padding:0 4px}
label.tm-check{flex-direction:row;align-items:center;gap:8px;font-weight:500;color:#ececf3}
.tm-actions{grid-column:1/-1;display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between}
.tm-actions small{color:#8f95a1;font-size:12.5px}
.tm-spacer{flex:1}
.tm-btn{background:#ff6b35;color:#1a0d06;border:0;border-radius:8px;padding:10px 18px;font:inherit;font-weight:700;cursor:pointer}
.tm-btn:disabled{opacity:.5;cursor:default}
.tm-link{background:none;border:0;color:#b7bcc6;font:inherit;font-size:13px;cursor:pointer;padding:4px}
.tm-link:hover{color:#ffa47e}
.tm-danger{color:#f87171}
.tm-confirm{font-size:13px;color:#b7bcc6}
.tm-list{display:flex;flex-direction:column;border:1px solid #262b34;border-radius:16px;overflow:hidden}
.tm-person{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) minmax(0,1fr) auto;gap:14px;align-items:center;padding:12px 16px;border-top:1px solid #1f232b;background:rgba(20,23,29,.6)}
.tm-person:first-child{border-top:0}
.tm-who{display:flex;gap:10px;align-items:center;min-width:0}
.tm-who span:last-child{display:flex;flex-direction:column;min-width:0}
.tm-who b{font-size:14px}
.tm-who small,.tm-in{color:#8f95a1;font-size:12px;overflow:hidden;text-overflow:ellipsis}
.tm-avatar{width:32px;height:32px;border-radius:50%;background:#7c5cff;color:#fff;display:grid;place-items:center;font-weight:800;flex:0 0 32px}
.tm-spend{font-size:12.5px;color:#b7bcc6;display:flex;flex-direction:column;gap:5px}
.tm-spend b{color:#ececf3}
.tm-bar{height:5px;border-radius:999px;background:#191d24;overflow:hidden}
.tm-bar i{display:block;height:100%;background:#7c5cff}
.tm-row-actions{display:flex;gap:6px}
.tm-quiet{color:#8f95a1;margin:0}
.tm-problem{margin:0;color:#f4bba9;background:#352322;border-radius:10px;padding:10px 14px;font-size:13px}
.tm-notice{margin:0;color:#3fcf8e;font-size:13px}
.tm-scrim{position:fixed;inset:0;background:rgba(5,6,9,.6);display:grid;place-items:center;z-index:50;padding:16px}
.tm-dialog{width:min(520px,100%)}
.tm button:focus-visible,.tm input:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
@media(max-width:860px){.tm-form{grid-template-columns:1fr}.tm-person{grid-template-columns:1fr}}
</style>
