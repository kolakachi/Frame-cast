<script setup>
// Agency › Clients (owner, 2026-10-09: Clients and Team are tabs inside the one Agency menu item).
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import api from "../../services/api";
import { useWorkspaceStore } from "../../stores/workspace";

const router = useRouter();
const workspaceStore = useWorkspaceStore();

const summaries=ref({});
const newName = ref("");
const busy = ref(false);
const error = ref("");

const showArchived = ref(false);
const search = ref('');
const clients = computed(() => (workspaceStore.clients ?? []).filter(c => (showArchived.value || c.status !== 'archived') && (c.client_label || c.name).toLowerCase().includes(search.value.toLowerCase())));
watch(showArchived, () => workspaceStore.loadClients(showArchived.value));
const atLimit = computed(
  () => (workspaceStore.clients ?? []).filter(c=>c.status!=='archived').length >= (workspaceStore.maxClients ?? 50),
);

async function addClient() {
  const name = newName.value.trim();
  if (!name || busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    const created = await workspaceStore.createClient(name);
    newName.value = "";
    if (created?.id) router.push({ name: "client-detail", params: { id: created.id } });
  } catch (e) {
    error.value = e.response?.data?.error?.message ?? "Could not create that workspace.";
  } finally {
    busy.value = false;
  }
}

// Credits shown per row mean different things by funding mode, so the label
// travels with the number rather than sitting in a column header.
function creditLine(c) {
  if (c.funding_mode === "funded") return `${(c.credits ?? 0).toLocaleString()} credits of its own`;
  if (c.monthly_credit_cap) {
    return `${(c.spent_this_month ?? 0).toLocaleString()} / ${c.monthly_credit_cap.toLocaleString()} this month`;
  }
  return "Shared balance, no cap";
}

// Restyled 2026-10-09 in the agency dashboard's look: one toolbar, cards with a coloured initial, activity chips, a
// credit bar against the cap, and Open / Create for <client>.
const COLOURS = ["#b8532e", "#7a4b22", "#3b5d8c", "#1f6a59", "#7a3b9a", "#8a5a1d", "#2d5d8c", "#a3432f"];
const colour = (id) => COLOURS[Number(id) % COLOURS.length];
const adding = ref(false), opening = ref(0);
const nameOf = (c) => c.client_label || c.name;
const capShare = (c) => (c.monthly_credit_cap ? Math.min(100, (100 * (c.spent_this_month ?? 0)) / c.monthly_credit_cap) : 0);
const nearCap = (c) => c.monthly_credit_cap && (c.spent_this_month ?? 0) >= c.monthly_credit_cap * 0.8;
const spent30 = (c) => workspaceStore.clientUsage?.[c.id]?.credits ?? null;
async function createFor(c) {
  if (opening.value) return;
  opening.value = c.id;
  try { await workspaceStore.switchTo(c.id, "/create"); } catch { opening.value = 0; }
}
watch(adding, (v) => { if (v) setTimeout(() => document.getElementById("new-client")?.focus(), 0); });

onMounted(async () => {
  workspaceStore.loadClients();
  workspaceStore.loadClientUsage(30);
  try { const r=await api.get('/agency-overview');summaries.value=Object.fromEntries(r.data.data.map(x=>[x.id,x])); } catch {error.value='Could not load client activity. Refresh to retry.'}
});
</script>

<template>
<div class="body">
        <div class="toolbar">
          <label class="search"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input v-model="search" type="search" aria-label="Find a client" placeholder="Find a client…" /></label>
          <label class="toggle"><input v-model="showArchived" type="checkbox" /><span>Show archived</span></label>
          <span class="count">{{ clients.length }} of {{ workspaceStore.maxClients ?? 50 }}</span>
          <button type="button" class="btn-add" :disabled="atLimit" @click="adding = !adding">{{ adding ? "Close" : "+ Add client" }}</button>
        </div>

        <form v-if="adding" class="new" @submit.prevent="addClient">
          <label for="new-client">New client</label>
          <input id="new-client" v-model="newName" maxlength="120" placeholder="Client name, for example Acme Skincare" :disabled="atLimit" />
          <button type="submit" class="btn-primary" :disabled="!newName.trim() || busy || atLimit">{{ busy ? "Creating…" : "Create workspace" }}</button>
        </form>
        <p v-if="atLimit" class="note warn">You have reached the limit of {{ workspaceStore.maxClients ?? 50 }} client workspaces.</p>
        <p v-if="error || workspaceStore.loadFailed" class="note err" role="alert">{{ error || "Could not load clients." }} <button type="button" class="link" @click="workspaceStore.loadClients(showArchived)">Retry</button></p>

        <div v-if="workspaceStore.clients === null" class="empty">Loading clients…</div>
        <div v-else-if="!clients.length" class="empty">
          <b>{{ search ? "No client matches that." : "No client workspaces yet." }}</b>
          <span v-if="!search">Add one and it starts with your brand. You can invite the client to approve videos, and give your team access.</span>
          <button v-if="!search && !adding" type="button" class="btn-primary" :disabled="atLimit" @click="adding = true">+ Add your first client</button>
        </div>

        <div v-else class="grid">
          <article v-for="c in clients" :key="c.id" :class="['card', { archived: c.status === 'archived' }]">
            <div class="card-top">
              <span class="logo" :style="{ background: colour(c.id) }">{{ nameOf(c)[0]?.toUpperCase() }}</span>
              <span class="who"><b>{{ nameOf(c) }}</b><small>{{ c.status === "active" ? (c.funding_mode === "funded" ? "Funded" : "Shared balance") : c.status === "paused" ? "Paused" : "Archived" }}</small></span>
              <span :class="['mode', c.funding_mode === 'funded' ? 'funded' : '']">{{ c.funding_mode === "funded" ? "Funded" : "Pooled" }}</span>
            </div>
            <div class="chips">
              <span v-if="summaries[c.id]?.pending_reviews" class="ok">{{ summaries[c.id].pending_reviews }} awaiting approval</span>
              <span v-if="summaries[c.id]?.open_requests" class="warn">{{ summaries[c.id].open_requests }} open request{{ summaries[c.id].open_requests === 1 ? "" : "s" }}</span>
              <span>{{ c.projects }} project{{ c.projects === 1 ? "" : "s" }}</span>
              <span>{{ c.members }} {{ c.members === 1 ? "person" : "people" }}</span>
            </div>
            <div class="credits">
              <div class="credits-row"><span>{{ creditLine(c) }}</span><span v-if="spent30(c) !== null" class="muted">{{ spent30(c).toLocaleString() }} in {{ workspaceStore.clientUsageDays }} days</span></div>
              <div v-if="c.monthly_credit_cap" :class="['bar', { hot: nearCap(c) }]" aria-hidden="true"><i :style="{ width: capShare(c) + '%' }" /></div>
              <p v-if="nearCap(c)" class="warn-line">At least 80% of this month's cap is used.</p>
            </div>
            <div class="card-foot">
              <router-link class="open" :to="{ name: 'client-detail', params: { id: c.id } }">Open</router-link>
              <button v-if="c.status === 'active'" type="button" class="create" :disabled="!!opening" @click="createFor(c)">{{ opening === c.id ? "Opening…" : "Create for " + nameOf(c) + " →" }}</button>
            </div>
          </article>
        </div>
      </div>
</template>

<style scoped>
.body{display:flex;flex-direction:column;gap:14px;color:var(--color-text-primary,#ececf3)}
.toolbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.search{flex:1 1 260px;display:flex;align-items:center;gap:8px;border:1px solid #2c313b;border-radius:8px;padding:0 12px;background:#0f1116;color:#8f95a1}
.search input{flex:1;min-width:0;background:none;border:0;color:#ececf3;font:inherit;font-size:14px;padding:10px 0;outline:none}
.search:focus-within{border-color:rgba(255,107,53,.6);box-shadow:0 0 0 3px rgba(255,107,53,.12)}
.toggle{display:inline-flex;align-items:center;gap:7px;font-size:13px;color:#b7bcc6;cursor:pointer}
.count{font:600 12px ui-monospace,Menlo,monospace;color:#5d6472}
.btn-add,.btn-primary{background:#ff6b35;color:#1a0d06;border:0;border-radius:8px;padding:10px 18px;font:inherit;font-weight:700;font-size:13.5px;cursor:pointer}
.btn-add:disabled,.btn-primary:disabled{opacity:.5;cursor:default}
.new{display:flex;align-items:center;gap:10px;flex-wrap:wrap;border:1px solid rgba(255,107,53,.45);border-radius:14px;padding:12px 14px;background:rgba(20,23,29,.85)}
.new label{font-size:12.5px;font-weight:700;color:#b7bcc6}
.new input{flex:1 1 260px;min-width:0;background:#0f1116;border:1px solid #2c313b;border-radius:8px;padding:9px 11px;color:#ececf3;font:inherit;font-size:14px}
.note{margin:0;font-size:13px}
.note.warn{color:#f2b84b}
.note.err{color:#f4bba9;background:#352322;border-radius:10px;padding:10px 14px}
.link{background:none;border:0;color:#ffa47e;font:inherit;cursor:pointer;padding:0 4px}
.empty{border:1px dashed #2c313b;border-radius:16px;padding:34px 20px;display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center;color:#8f95a1}
.empty b{color:#ececf3;font-size:15px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px}
.card{border:1px solid #262b34;border-radius:18px;padding:16px;background:rgba(20,23,29,.85);display:flex;flex-direction:column;gap:12px;transition:border-color .2s,transform .2s}
.card:hover{border-color:#3a404c;transform:translateY(-2px)}
.card.archived{opacity:.6}
.card-top{display:flex;align-items:center;gap:11px}
.logo{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;font-weight:800;font-size:16px;color:#fff;flex:0 0 38px}
.who{flex:1;min-width:0;display:flex;flex-direction:column}
.who b{font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.who small{color:#8f95a1;font-size:12px}
.mode{font:700 10px ui-monospace,Menlo,monospace;letter-spacing:.06em;text-transform:uppercase;border:1px solid #2c313b;border-radius:999px;padding:3px 8px;color:#8f95a1}
.mode.funded{color:#3fcf8e;border-color:rgba(63,207,142,.4)}
.chips{display:flex;flex-wrap:wrap;gap:6px}
.chips span{font-size:11.5px;border-radius:999px;padding:3px 9px;background:#191d24;color:#b7bcc6}
.chips .ok{background:rgba(63,207,142,.12);color:#3fcf8e}
.chips .warn{background:rgba(242,184,75,.12);color:#f2b84b}
.credits{display:flex;flex-direction:column;gap:6px}
.credits-row{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:12.5px;color:#b7bcc6}
.muted{color:#5d6472}
.bar{height:6px;border-radius:999px;background:#191d24;overflow:hidden}
.bar i{display:block;height:100%;background:#ff6b35}
.bar.hot i{background:#f2b84b}
.warn-line{margin:0;font-size:12px;color:#f2b84b}
.card-foot{display:flex;gap:8px;margin-top:auto}
.open{border:1px solid #2c313b;border-radius:8px;padding:7px 14px;font-size:12.5px;font-weight:600;color:#ececf3;text-decoration:none}
.open:hover{border-color:#3a404c;background:#191d24}
.create{flex:1;border:1px solid rgba(255,107,53,.5);color:#ff6b35;background:transparent;border-radius:8px;padding:7px 12px;font:inherit;font-weight:700;font-size:12.5px;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.create:hover{background:rgba(255,107,53,.1)}
.create:disabled{opacity:.6;cursor:default}
.body :is(button,a,input):focus-visible{outline:2px solid #ff6b35;outline-offset:2px}

@media(prefers-reduced-motion:reduce){.card{transition:none}.card:hover{transform:none}}
</style>
