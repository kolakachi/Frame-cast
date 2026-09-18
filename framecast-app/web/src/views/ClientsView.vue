<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import api from "../services/api";
import NotifBell from "../components/NotifBell.vue";
import { useAuthStore } from "../stores/auth";
import { useWorkspaceStore } from "../stores/workspace";

const router = useRouter();
const authStore = useAuthStore();
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

onMounted(async () => {
  workspaceStore.loadClients();
  workspaceStore.loadClientUsage(30);
  try { const r=await api.get('/agency-overview');summaries.value=Object.fromEntries(r.data.data.map(x=>[x.id,x])); } catch {error.value='Could not load client activity. Refresh to retry.'}
});
</script>

<template>
  <div class="shell">
    <AppSidebar :user="authStore.user" active-page="clients" />
    <div class="main">
      <div class="topbar">
        <div class="topbar-left">
          <h1 class="page-title">Clients</h1>
          <span class="page-sub">
            {{ clients.length }} of {{ workspaceStore.maxClients ?? 50 }}
          </span>
        </div>
        <div class="topbar-right">
          <span class="pool">
            <strong>{{ (workspaceStore.sharedCredits ?? 0).toLocaleString() }}</strong> shared credits
          </span>
          <NotifBell />
        </div>
      </div>

      <div class="body">
        <p class="lede">
          Each client gets its own workspace — projects, characters and brand kept apart.
          Leave a client on your shared balance, or fund it with credits of its own so it
          can only ever spend what you gave it.
        </p>

        <div class="add"><input class="input" v-model="search" aria-label="Search clients" placeholder="Find a client…" /><label><input v-model="showArchived" type="checkbox" /> Show archived</label></div>
        <div class="add">
          <input
            v-model="newName"
            class="input"
            maxlength="120"
            :disabled="atLimit"
            placeholder="Client name — e.g. Acme Skincare"
            @keyup.enter="addClient"
          />
          <button class="btn btn-primary" :disabled="!newName.trim() || busy || atLimit" @click="addClient">
            {{ busy ? "Creating…" : "Add client" }}
          </button>
        </div>
        <p v-if="atLimit" class="hint warn">
          You have reached the limit of {{ workspaceStore.maxClients ?? 50 }} client workspaces.
        </p>
        <p v-if="error || workspaceStore.loadFailed" class="err">{{ error || "Could not load clients." }} <button class="btn" @click="workspaceStore.loadClients(showArchived)">Retry</button></p>

        <div v-if="workspaceStore.clients === null" class="empty">Loading…</div>
        <div v-else-if="!clients.length" class="empty">
          No client workspaces yet. Add one above and it inherits your brand.
        </div>

        <div v-else class="grid">
          <router-link
            v-for="c in clients"
            :key="c.id"
            class="card"
            :to="{ name: 'client-detail', params: { id: c.id } }"
          >
            <div class="card-head">
              <span class="dot">{{ (c.client_label || c.name)[0]?.toUpperCase() }}</span>
              <span class="nm">{{ c.client_label || c.name }}</span>
              <span :class="['mode', c.funding_mode === 'funded' ? 'funded' : '']">
                {{ c.funding_mode === "funded" ? "Funded" : "Pooled" }}
              </span>
            </div>
            <div class="card-row">{{c.status}} · {{ creditLine(c) }}</div><p v-if="c.monthly_credit_cap && c.spent_this_month >= c.monthly_credit_cap * .8" class="warn">At least 80% of monthly cap used</p>
            <div v-if="summaries[c.id]" class="card-row">{{summaries[c.id].open_requests}} open requests · {{summaries[c.id].pending_reviews}} awaiting approval</div>
            <div class="card-foot">
              <span>{{ c.projects }} project{{ c.projects === 1 ? "" : "s" }}</span>
              <span>{{ c.members }} member{{ c.members === 1 ? "" : "s" }}</span>
            </div>
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; min-height: 100dvh; }
.main { flex: 1; margin-left: var(--sidebar-width, 220px); min-width: 0; transition: margin-left .18s ease; }
.topbar {
  display: flex; align-items: center; gap: 14px; padding: 0 24px; height: 64px;
  border-bottom: 1px solid var(--color-border, #23232d); background: var(--color-bg-panel, #111117);
}
.topbar-left { display: flex; align-items: baseline; gap: 10px; }
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 14px; }
.page-title { font-size: 18px; font-weight: 650; letter-spacing: -.02em; margin: 0; }
.page-sub { font-size: 12px; color: var(--color-text-muted, #6f7080); }
.pool { font-size: 12.5px; color: var(--color-text-secondary, #a8a9b4); }
.pool strong { font-family: var(--font-mono, ui-monospace, Menlo, monospace); color: var(--color-text-primary, #e9e9ef); }
.body { padding: 24px; max-width: 1100px; }
.lede { font-size: 13.5px; color: var(--color-text-secondary, #a8a9b4); max-width: 70ch; margin: 0 0 18px; line-height: 1.6; }
.add { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
.input {
  flex: 1; min-width: 240px; padding: 10px 12px; border-radius: 8px;
  border: 1px solid var(--color-border, #23232d); background: var(--color-bg-sunken, #0d0d12);
  color: inherit; font: inherit;
}
.btn {
  padding: 10px 16px; border-radius: 8px; cursor: pointer; font: inherit; font-size: 13.5px;
  border: 1px solid var(--color-border, #23232d); background: var(--color-bg-card, #17171e);
  color: var(--color-text-secondary, #a8a9b4);
}
.btn-primary { background: var(--color-accent, #ff6b35); border-color: var(--color-accent, #ff6b35); color: #fff; font-weight: 500; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.hint { font-size: 12px; color: var(--color-text-muted, #6f7080); margin: 4px 0 0; }
.hint.warn { color: #e0b04a; }
.err { color: #fca5a5; font-size: 12.5px; margin: 6px 0 0; }
.empty { margin-top: 26px; color: var(--color-text-muted, #6f7080); font-size: 13.5px; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin-top: 20px; }
.card {
  display: block; text-decoration: none; color: inherit; padding: 14px;
  border: 1px solid var(--color-border, #23232d); border-radius: 10px;
  background: var(--color-bg-card, #17171e); transition: border-color .15s ease;
}
.card:hover { border-color: var(--color-border-active, #34343f); }
.card-head { display: flex; align-items: center; gap: 9px; }
.dot {
  width: 30px; height: 30px; border-radius: 8px; flex: none; display: grid; place-items: center;
  background: var(--color-bg-elevated, #1d1d26); font-size: 12.5px; font-weight: 600;
}
.nm { font-size: 14px; font-weight: 600; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mode {
  font-size: 9.5px; letter-spacing: .08em; text-transform: uppercase; padding: 2px 7px; border-radius: 4px;
  border: 1px solid var(--color-border, #23232d); color: var(--color-text-muted, #6f7080); white-space: nowrap;
}
.mode.funded { border-color: rgba(106, 208, 157, .32); background: rgba(106, 208, 157, .1); color: #6ad09d; }
.card-row { margin-top: 11px; font-size: 12.5px; color: var(--color-text-secondary, #a8a9b4); }
.card-foot {
  margin-top: 10px; padding-top: 9px; border-top: 1px solid var(--color-border, #23232d);
  display: flex; gap: 14px; font-size: 11.5px; color: var(--color-text-muted, #6f7080);
}
@media (max-width: 860px) {
  .main { margin-left: 0; }
  .body { padding: 16px; }
  .topbar { padding: 0 16px; }
}
</style>
