<script setup>
import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import ClientHubPanel from "../components/ClientHubPanel.vue";
import UiSelect from "../components/UiSelect.vue";
import ConfirmDialog from "../components/ConfirmDialog.vue";
import { useAuthStore } from "../stores/auth";
import { useWorkspaceStore } from "../stores/workspace";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const workspaceStore = useWorkspaceStore();

const clientId = computed(() => Number(route.params.id));
const initialClient = ref(null);
const detailLoading = ref(true);
const client = computed(
  () => (workspaceStore.clients ?? []).find((c) => Number(c.id) === clientId.value) ?? initialClient.value,
);

const SEATS = [
  { value: "client", label: "View only", hint: "View, request videos and approve work." },
  { value: "client_editor", label: "Edit", hint: "Make and change videos." },
  { value: "client_admin", label: "Admin", hint: "Edit content and manage client brand settings." },
];
const seatLabel = (r) => SEATS.find((s) => s.value === r)?.label ?? "View only";

// One dialog serves both destructive actions; the browser's confirm() box
// cannot be styled, cannot say what it is about, and looks like a warning
// from the browser rather than from the product.
const confirmState = ref(null);
const confirmPending = ref(false);

function ask(config) {
  confirmState.value = config;
}

async function confirmYes() {
  if (!confirmState.value || confirmPending.value) return;
  confirmPending.value = true;
  try {
    await confirmState.value.run();
    confirmState.value = null;
  } finally {
    confirmPending.value = false;
  }
}

// ── Credits ────────────────────────────────────────────────────────────
const fundAmount = ref("");
const fundBusy = ref(false);
const fundError = ref("");

async function move(sign) {
  const n = parseInt(String(fundAmount.value).trim(), 10);
  if (!n || n <= 0 || fundBusy.value) return;
  fundBusy.value = true;
  fundError.value = "";
  try {
    await workspaceStore.fundClient(clientId.value, sign * n);
    fundAmount.value = "";
  } catch (e) {
    fundError.value = e.response?.data?.error?.message ?? "Those credits could not be moved.";
  } finally {
    fundBusy.value = false;
  }
}

function repool() {
  const left = (client.value?.credits ?? 0).toLocaleString();
  ask({
    title: "Back to the shared balance?",
    message: `${left} unspent credits return to your balance, and this client starts drawing on it instead. Nothing it has already made is affected.`,
    confirmLabel: "Move to shared",
    destructive: false,
    run: async () => {
      try {
        await workspaceStore.unfundClient(clientId.value);
      } catch {
        fundError.value = "Could not move that client back to the shared balance.";
      }
    },
  });
}

// ── Cap ────────────────────────────────────────────────────────────────
const capValue = ref("");
const capBusy = ref(false);

async function saveCap() {
  const raw = String(capValue.value).trim();
  capBusy.value = true;
  try {
    if (raw !== "" && (!/^\d+$/.test(raw) || Number(raw)<1)) throw new Error('Enter a positive whole number or leave the cap blank.');
    await workspaceStore.setCap(clientId.value, raw === "" ? null : Number(raw));
    fundError.value = "";
  } catch (e) {
    fundError.value = e.response?.data?.error?.message ?? e.message ?? 'Could not save the cap.';
  } finally {
    capBusy.value = false;
  }
}

// ── Members ────────────────────────────────────────────────────────────
const members = ref([]);
const pagination = ref({ current_page: 1, last_page: 1, total: 0 });
const search = ref("");
const membersBusy = ref(false);
const inviteEmail = ref("");
const inviteRole = ref("client");
const inviteBusy = ref(false);
const memberError = ref("");

async function loadMembers(page = 1) {
  const currentRequest = ++memberRequest;
  membersBusy.value = true;
  try {
    const r = await workspaceStore.loadViewers(clientId.value, { page, q: search.value.trim() });
    if(currentRequest !== memberRequest) return;
    members.value = r.viewers;
    pagination.value = r.pagination;
  } catch {
    memberError.value = "Could not load members. Try again.";
  } finally {
    membersBusy.value = false;
  }
}

let searchTimer = null;
let memberRequest = 0;
watch(search, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadMembers(1), 250);
});

async function resend(member) {
  if (inviteBusy.value) return;
  inviteBusy.value = true; memberError.value = '';
  try { await workspaceStore.inviteViewer(clientId.value, member.email, member.role); await loadMembers(pagination.value.current_page); }
  catch (e) { memberError.value = e.response?.data?.error?.message ?? 'Could not resend the invitation.'; }
  finally { inviteBusy.value = false; }
}
async function invite() {
  const email = inviteEmail.value.trim();
  if (!email || inviteBusy.value) return;
  inviteBusy.value = true;
  memberError.value = "";
  try {
    await workspaceStore.inviteViewer(clientId.value, email, inviteRole.value);
    inviteEmail.value = "";
    await loadMembers(pagination.value.current_page);
  } catch (e) {
    memberError.value = e.response?.data?.error?.message ?? "Could not send that invite.";
  } finally {
    inviteBusy.value = false;
  }
}

async function changeRole(m, role) {
  try {
    await workspaceStore.setViewerRole(clientId.value, m.id, role);
    m.role = role;
  } catch {
    memberError.value = "Could not change that person's access.";
  }
}

function remove(m) {
  ask({
    title: "Remove access?",
    message: `${m.email} will be signed out and will no longer see this workspace. Their work stays where it is, and you can invite them again later.`,
    confirmLabel: "Remove access",
    destructive: true,
    run: async () => {
      try {
        await workspaceStore.removeViewer(clientId.value, m.id);
        await loadMembers(pagination.value.current_page);
      } catch {
        memberError.value = "Could not remove that person.";
      }
    },
  });
}

watch(clientId, async () => {
  detailLoading.value = true;
  initialClient.value = null;
  await workspaceStore.loadClients(true);
  initialClient.value = (workspaceStore.clients ?? []).find(c=>Number(c.id)===clientId.value) ?? null;
  detailLoading.value = false;
  capValue.value = client.value?.monthly_credit_cap ?? "";
  loadMembers(1);
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
  <div class="shell">
    <AppSidebar :user="authStore.user" active-page="clients" />
    <div class="main">
      <div class="topbar">
        <button class="back" @click="router.push({ name: 'clients' })">‹ Clients</button>
        <h1 class="page-title">{{ client?.client_label || client?.name || "Client" }}</h1>
        <span v-if="client" :class="['mode', client.funding_mode === 'funded' ? 'funded' : '']">
          {{ client.funding_mode === "funded" ? "Funded" : "Pooled" }}
        </span>
        <div class="topbar-right">
          <button class="btn" @click="workspaceStore.switchTo(clientId)">Open workspace</button>
        </div>
      </div>

      <div v-if="client" class="body">
        <ClientHubPanel :client-id="clientId" @changed="workspaceStore.loadClients(true)"><template #access>
        <!-- Credits -->
        <p class="sub">Allocation uses top-up credits only. Keep monthly credits in the shared balance and set a cap to limit each client.</p>
        <section class="panel">
          <h2>Credits</h2>
          <p class="sub">
            <template v-if="client.funding_mode === 'funded'">
              This client spends its own balance. When it runs out, work stops here — your
              shared balance is never touched.
            </template>
            <template v-else>
              This client spends your shared balance of
              {{ (workspaceStore.sharedCredits ?? 0).toLocaleString() }}. Fund it to give it
              credits of its own instead.
            </template>
          </p>

          <div class="figures">
            <div class="fig">
              <div class="fig-n">{{ client.funding_mode === "funded" ? (client.credits ?? 0).toLocaleString() : "—" }}</div>
              <div class="fig-l">its own balance</div>
            </div>
            <div class="fig">
              <div class="fig-n">{{ (client.spent_this_month ?? 0).toLocaleString() }}</div>
              <div class="fig-l">spent this month</div>
            </div>
            <div class="fig">
              <div class="fig-n">{{ client.monthly_credit_cap ? client.monthly_credit_cap.toLocaleString() : "—" }}</div>
              <div class="fig-l">monthly cap</div>
            </div>
          </div>

          <div class="row">
            <input v-model="fundAmount" class="input" type="number" min="1" placeholder="e.g. 200" aria-label="Credits to allocate or reclaim" title="Move top-up credits between your agency and this client." />
            <button class="btn btn-primary" :disabled="fundBusy" @click="move(1)">Add</button>
            <button class="btn" :disabled="fundBusy || client.funding_mode !== 'funded'" @click="move(-1)">Take back</button>
            <button v-if="client.funding_mode === 'funded'" class="btn btn-quiet" @click="repool">
              Back to shared balance
            </button>
          </div>
          <p v-if="fundError" class="err">{{ fundError }}</p>
        </section>

        <!-- Cap -->
        <section class="panel">
          <h2>Monthly cap</h2>
          <p class="sub">
            A ceiling on what this client may spend each month. Leave blank for no ceiling.
            Separate from funding — a funded client can still be rate-limited.
          </p>
          <div class="row">
            <input v-model="capValue" class="input" type="number" min="1" placeholder="e.g. 500 — leave blank for no limit" aria-label="Monthly credit cap" title="Maximum credits this client may spend per calendar month. This does not allocate credits." />
            <button class="btn btn-primary" :disabled="capBusy" @click="saveCap">
              {{ capBusy ? "Saving…" : "Save cap" }}
            </button>
          </div>
        </section>

        <!-- Members -->
        <section class="panel">
          <div class="panel-head">
            <h2>People</h2>
            <span class="count">{{ pagination.total }}</span>
          </div>
          <p class="sub">
            Everyone here sees this workspace and nothing else of yours — never your other
            clients, your billing, or your client list.
          </p>

          <div class="row">
            <input v-model="inviteEmail" class="input" type="email" placeholder="teammate@example.com" aria-label="Email to invite" title="Invite someone to this client workspace with the access level selected." @keyup.enter="invite" />
            <UiSelect v-model="inviteRole" :options="SEATS" label="Invitation access level" :disabled="inviteBusy" />
            <button class="btn btn-primary" :disabled="!inviteEmail.trim() || inviteBusy" @click="invite">
              {{ inviteBusy ? "Sending…" : "Invite" }}
            </button>
          </div>
          <p class="hint">{{ SEATS.find((s) => s.value === inviteRole)?.hint }}</p>
          <p v-if="memberError" class="err">{{ memberError }}</p>

          <input v-model="search" class="input search" placeholder="Search by name or email…" aria-label="Search people" />

          <div v-if="membersBusy" class="empty">Loading…</div>
          <div v-else-if="!members.length" class="empty">
            {{ search ? "Nobody matches that." : "Nobody has been invited yet." }}
          </div>
          <table v-else class="members">
            <tbody>
              <tr v-for="m in members" :key="m.id">
                <td class="m-mail">{{ m.email }}</td>
                <td class="m-seen">{{ m.accepted_at ? "accessed workspace" : m.delivery_status === "failed" ? "email failed" : m.invite_expired ? "invite expired" : "invited" }}</td>
                <td class="m-role">
                  <UiSelect :model-value="m.role" :options="SEATS" :label="`Access for ${m.email}`" @update:model-value="changeRole(m, $event)" />
                </td>
                <td class="m-x"><div class="member-actions"><button v-if="!m.accepted_at && !m.last_seen_at" class="x resend" title="Resend invitation" :aria-label="`Resend invitation to ${m.email}`" @click="resend(m)" :disabled="inviteBusy"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 7v5h-5"/><path d="M20 12a8 8 0 1 0-2.3 5.7M20 7l-2.3-2.3"/></svg></button><button class="x" title="Remove access" :aria-label="`Remove access for ${m.email}`" @click="remove(m)">&#10005;</button></div></td>
              </tr>
            </tbody>
          </table>

          <div v-if="pagination.last_page > 1" class="pager">
            <button class="btn" :disabled="pagination.current_page <= 1" @click="loadMembers(pagination.current_page - 1)">Previous</button>
            <span>Page {{ pagination.current_page }} of {{ pagination.last_page }}</span>
            <button class="btn" :disabled="pagination.current_page >= pagination.last_page" @click="loadMembers(pagination.current_page + 1)">Next</button>
          </div>
        </section>
        </template></ClientHubPanel>
      </div>
      <div v-else class="body"><div class="empty">{{workspaceStore.loadFailed ? "Could not load this client." : detailLoading ? "Loading client…" : "Client not found."}} <button class="btn" @click="workspaceStore.loadClients(true)">Retry</button></div></div>
    </div>

    <ConfirmDialog
      :open="!!confirmState"
      :title="confirmState?.title ?? ''"
      :message="confirmState?.message ?? ''"
      :confirm-label="confirmState?.confirmLabel ?? 'Confirm'"
      :destructive="confirmState?.destructive ?? false"
      :pending="confirmPending"
      @close="confirmPending || (confirmState = null)"
      @confirm="confirmYes"
    />
  </div>
</template>

<style scoped>
.shell { display: flex; min-height: 100vh; min-height: 100dvh; }
.main { flex: 1; margin-left: var(--sidebar-width, 220px); min-width: 0; transition: margin-left .18s ease; }
.topbar {
  display: flex; align-items: center; gap: 12px; padding: 0 24px; height: 64px;
  border-bottom: 1px solid var(--color-border, #23232d); background: var(--color-bg-panel, #111117);
}
.topbar-right { margin-left: auto; }
.back { background: none; border: none; color: var(--color-text-muted, #6f7080); cursor: pointer; font: inherit; font-size: 13px; padding: 4px 6px; }
.back:hover { color: var(--color-accent, #ff6b35); }
.page-title { font-size: 18px; font-weight: 650; letter-spacing: -.02em; margin: 0; }
.mode {
  font-size: 9.5px; letter-spacing: .08em; text-transform: uppercase; padding: 2px 7px; border-radius: 4px;
  border: 1px solid var(--color-border, #23232d); color: var(--color-text-muted, #6f7080);
}
.mode.funded { border-color: rgba(106, 208, 157, .32); background: rgba(106, 208, 157, .1); color: #6ad09d; }
.body { padding: 24px; max-width: 840px; display: flex; flex-direction: column; gap: 16px; }
.panel + .panel { margin-top: 20px; }
.member-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; padding-left: 12px; }
.member-actions .x { display: inline-flex; align-items:center; justify-content:center; width:32px; height:32px; flex-shrink:0; }
.resend { font-size:20px; }
.panel { border: 1px solid var(--color-border, #23232d); border-radius: 10px; background: var(--color-bg-card, #17171e); padding: 18px; }
.panel h2 { font-size: 15px; font-weight: 600; margin: 0 0 4px; }
.panel-head { display: flex; align-items: center; gap: 9px; }
.count { font-size: 11px; font-family: var(--font-mono, Menlo, monospace); color: var(--color-text-muted, #6f7080); }
.sub { font-size: 12.5px; color: var(--color-text-muted, #6f7080); margin: 0 0 14px; line-height: 1.55; max-width: 66ch; }
.figures { display: flex; gap: 26px; flex-wrap: wrap; margin-bottom: 16px; }
.fig-n { font-size: 20px; font-weight: 600; font-family: var(--font-mono, Menlo, monospace); font-variant-numeric: tabular-nums; }
.fig-l { font-size: 10.5px; color: var(--color-text-muted, #6f7080); margin-top: 2px; }
.row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.input {
  flex: 1; min-width: 180px; padding: 9px 11px; border-radius: 8px;
  border: 1px solid var(--color-border, #23232d); background: var(--color-bg-sunken, #0d0d12);
  color: inherit; font: inherit; font-size: 13px;
}
.input.narrow { flex: 0 0 auto; min-width: 130px; }
.input.search { margin-top: 14px; flex: none; width: 100%; }
.input.tiny { min-width: 110px; padding: 5px 8px; font-size: 12px; }
.btn {
  padding: 9px 14px; border-radius: 8px; cursor: pointer; font: inherit; font-size: 13px; white-space: nowrap;
  border: 1px solid var(--color-border, #23232d); background: var(--color-bg-elevated, #1d1d26);
  color: var(--color-text-secondary, #a8a9b4);
}
.btn-primary { background: var(--color-accent, #ff6b35); border-color: var(--color-accent, #ff6b35); color: #fff; font-weight: 500; }
.btn-quiet { background: none; }
.btn:disabled { opacity: .45; cursor: not-allowed; }
.hint { font-size: 11.5px; color: var(--color-text-muted, #6f7080); margin: 7px 0 0; }
.err { color: #fca5a5; font-size: 12.5px; margin: 8px 0 0; }
.empty { color: var(--color-text-muted, #6f7080); font-size: 13px; padding: 16px 0; }
.members { width: 100%; border-collapse: collapse; margin-top: 10px; }
.members td { padding: 8px 0; border-bottom: 1px solid var(--color-border, #23232d); font-size: 13px; vertical-align: middle; }
.m-mail { color: var(--color-text-secondary, #a8a9b4); }
.m-seen { font-size: 11px; color: var(--color-text-muted, #6f7080); white-space: nowrap; padding-left: 12px !important; }
.m-role { width: 130px; padding-left: 12px !important; }
.m-x { width: 30px; text-align: right; }
.x { background: none; border: none; cursor: pointer; color: var(--color-text-muted, #6f7080); font-size: 12px; padding: 3px 5px; border-radius: 5px; }
.x:hover { color: #fca5a5; background: rgba(224, 104, 95, .12); }
.pager { display: flex; align-items: center; gap: 12px; margin-top: 14px; font-size: 12px; color: var(--color-text-muted, #6f7080); }
@media (max-width: 860px) {
  .main { margin-left: 0; }
  .body { padding: 16px; }
  .topbar { padding: 0 16px; }
  .m-seen { display: none; }
}
</style>
