<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from '../components/AppSidebar.vue'
import NotifBell from '../components/NotifBell.vue'
import AgencyPanel from '../components/dashboard/AgencyPanel.vue'
import ClientsPanel from '../components/agency/ClientsPanel.vue'
import TeamPanel from '../components/agency/TeamPanel.vue'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'

// Agency (owner, 2026-10-09): one menu item, with Overview (Needs you and the month's credits), Clients and Team as
// tabs. Clients and Team are the agency owner's; a collaborator sees Overview only: their clients and allowance.
const route = useRoute()
const auth = useAuthStore()
const workspaceStore = useWorkspaceStore()
const tab = computed(() => ['clients', 'team'].includes(route.params.tab) && isOwner.value ? route.params.tab : 'overview')
const isOwner = computed(() => workspaceStore.canOwnClients)
const loaded = ref(false), empty = ref(false), role = ref('')
const TABS = [['overview', 'Overview', '/agency'], ['clients', 'Clients', '/agency/clients'], ['team', 'Team', '/agency/team']]
function onLoaded(view) { loaded.value = true; empty.value = !view }
onMounted(() => { if (workspaceStore.clients === null) workspaceStore.loadClients() })
</script>

<template>
  <div>
    <AppSidebar :user="auth.user" active-page="agency" />
    <main class="ag">
      <div class="ag-glow" aria-hidden="true" />
      <header class="ag-head">
        <div>
          <h1>Agency</h1>
          <p v-if="tab === 'overview'">{{ role === 'collaborator' ? 'Your clients, what needs you, and your credit allowance this month.' : 'What needs you across your clients, and who used what this month.' }}</p>
          <p v-else-if="tab === 'clients'">Each client gets its own workspace: projects, characters and brand kept apart. Leave a client on your shared balance, or fund it with credits of its own.</p>
          <p v-else>Collaborators make videos with your credits, in your workspace and the clients you give them, up to the monthly allowance you set. Billing, clients and the team stay with you.</p>
        </div>
        <div class="ag-right">
          <span v-if="isOwner" class="ag-pool"><b>{{ (workspaceStore.sharedCredits ?? 0).toLocaleString() }}</b> shared credits</span>
          <NotifBell />
        </div>
      </header>

      <nav v-if="isOwner" class="ag-tabs" aria-label="Agency">
        <router-link v-for="[key, label, to] in TABS" :key="key" :to="to" :class="['ag-tab', { on: tab === key }]" :aria-current="tab === key ? 'page' : undefined">
          {{ label }}<span v-if="key === 'clients' && (workspaceStore.clients ?? []).length" class="ag-count">{{ (workspaceStore.clients ?? []).filter(c => c.status !== 'archived').length }}</span>
        </router-link>
      </nav>

      <template v-if="tab === 'overview'">
        <AgencyPanel :show-clients="false" @role="r => role = r" @loaded="onLoaded" />
        <p v-if="!loaded" class="ag-quiet">Loading…</p>
        <div v-else-if="empty" class="ag-empty">
          <b>No clients yet</b>
          <span>Add a client and this page shows what needs you across all of them.</span>
          <router-link to="/agency/clients" class="ag-btn">Add a client</router-link>
        </div>
      </template>
      <ClientsPanel v-else-if="tab === 'clients'" />
      <TeamPanel v-else />
    </main>
  </div>
</template>

<style scoped>
.ag{position:relative;isolation:isolate;margin-left:var(--sidebar-width,220px);padding:30px 28px 48px;max-width:1180px;display:flex;flex-direction:column;gap:18px;color:var(--color-text-primary,#ececf3)}
.ag-glow{position:absolute;inset:-120px 0 auto 0;height:400px;z-index:-1;pointer-events:none;background:radial-gradient(45% 45% at 35% 30%,rgba(255,107,53,.14),transparent 70%),radial-gradient(35% 35% at 75% 40%,rgba(124,92,255,.1),transparent 70%)}
.ag-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap}
.ag-head h1{margin:0 0 6px;font-size:28px;font-weight:800;letter-spacing:-.025em}
.ag-head p{margin:0;max-width:66ch;color:var(--color-text-secondary,#a7abb7);font-size:14px;line-height:1.55}
.ag-right{display:flex;align-items:center;gap:14px}
.ag-pool{border:1px solid #2c313b;border-radius:8px;padding:7px 12px;font-size:13px;color:var(--color-text-secondary,#b7bcc6);background:rgba(20,23,29,.7)}
.ag-pool b{font-family:ui-monospace,Menlo,monospace;color:var(--color-text-primary,#ececf3)}
.ag-tabs{display:flex;gap:4px;border-bottom:1px solid #1f232b}
.ag-tab{position:relative;display:inline-flex;align-items:center;gap:7px;padding:10px 14px;color:var(--color-text-secondary,#8f95a1);font-weight:600;font-size:14px;text-decoration:none;border-radius:8px 8px 0 0}
.ag-tab:hover{color:var(--color-text-primary,#ececf3)}
.ag-tab.on{color:var(--color-text-primary,#ececf3)}
.ag-tab.on::after{content:"";position:absolute;left:10px;right:10px;bottom:-1px;height:2px;border-radius:2px;background:#ff6b35}
.ag-count{font:700 11px ui-monospace,Menlo,monospace;color:#8f95a1;background:#191d24;border-radius:6px;padding:1px 6px}
.ag-quiet{margin:0;color:#8f95a1}
.ag-empty{border:1px dashed #2c313b;border-radius:16px;padding:34px 20px;display:flex;flex-direction:column;align-items:center;gap:10px;text-align:center;color:#8f95a1}
.ag-empty b{color:#ececf3;font-size:15px}
.ag-btn{background:#ff6b35;color:#1a0d06;border-radius:8px;padding:10px 18px;font-weight:700;font-size:13.5px;text-decoration:none}
.ag :is(a,button):focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
@media(max-width:860px){.ag{margin-left:0;padding:80px 16px 100px}}
</style>
