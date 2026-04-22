<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import AppSidebar from '../components/AppSidebar.vue'

const router = useRouter()
const authStore = useAuthStore()

const mePayload = ref(null)
const overview = ref(null)
const loading = ref(true)
const errorMessage = ref('')
const savingWorkspaceId = ref(null)

const summary = computed(() => overview.value?.summary ?? {})
const plans = computed(() => overview.value?.plans ?? {})
const workspaces = computed(() => overview.value?.workspaces ?? [])
const users = computed(() => overview.value?.users ?? [])
const recentUsage = computed(() => overview.value?.recent_usage ?? [])
const projectCosts = computed(() => overview.value?.project_costs ?? [])

const planOptions = computed(() =>
  Object.entries(plans.value).map(([key, plan]) => ({
    key,
    name: plan.name ?? key,
  })),
)

function money(value) {
  return `$${Number(value || 0).toFixed(4)}`
}

function compactMoney(value) {
  return `$${Number(value || 0).toFixed(2)}`
}

function number(value) {
  return new Intl.NumberFormat().format(Number(value || 0))
}

function pct(used, limit) {
  const safeLimit = Number(limit || 0)
  if (safeLimit <= 0) return 0
  return Math.min(100, Math.round((Number(used || 0) / safeLimit) * 100))
}

function formatDate(value) {
  if (!value) return '-'
  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value))
}

function limitRows(workspace) {
  const usage = workspace.usage ?? {}

  return [
    ['API budget', workspace.api_spend_month_usd, workspace.api_budget_usd, money(workspace.api_budget_remaining_usd)],
    ['Renders', usage.renders_used, usage.render_limit, number(workspace.remaining?.renders)],
    ['Voice mins', usage.voice_minutes_used, usage.voice_minutes_limit, number(workspace.remaining?.voice_minutes)],
    ['Channels', usage.active_channels, usage.channel_limit, number(workspace.remaining?.channels)],
  ]
}

async function loadAdmin() {
  loading.value = true
  errorMessage.value = ''

  try {
    const [meRes, adminRes] = await Promise.all([
      api.get('/me'),
      api.get('/admin/overview'),
    ])

    mePayload.value = meRes.data.data.user
    overview.value = adminRes.data.data
  } catch (err) {
    errorMessage.value = err?.response?.data?.error?.message || 'Could not load god mode.'
  } finally {
    loading.value = false
  }
}

async function updateWorkspacePlan(workspace) {
  savingWorkspaceId.value = workspace.id
  errorMessage.value = ''

  try {
    await api.patch(`/admin/workspaces/${workspace.id}/plan`, {
      plan_tier: workspace.plan_tier,
      status: workspace.status,
    })
    await loadAdmin()
  } catch (err) {
    errorMessage.value = err?.response?.data?.error?.message || 'Could not update workspace plan.'
  } finally {
    savingWorkspaceId.value = null
  }
}

async function logout() {
  await authStore.logout()
  router.push({ name: 'login' })
}

onMounted(loadAdmin)
</script>

<template>
  <div class="admin-shell">
    <AppSidebar :user="mePayload" active-page="admin" @logout="logout" />

    <main class="admin-main">
      <div class="admin-topbar">
        <div>
          <p class="eyebrow">God Mode</p>
          <h1>Framecast Control</h1>
        </div>
        <button class="refresh-btn" type="button" @click="loadAdmin">Refresh</button>
      </div>

      <div v-if="errorMessage" class="banner error">{{ errorMessage }}</div>
      <div v-if="loading" class="page-state">Loading god mode...</div>

      <template v-else>
        <section class="stats-grid">
          <div class="stat-card">
            <span>Users</span>
            <strong>{{ number(summary.users) }}</strong>
          </div>
          <div class="stat-card">
            <span>Workspaces</span>
            <strong>{{ number(summary.workspaces) }}</strong>
          </div>
          <div class="stat-card">
            <span>Projects</span>
            <strong>{{ number(summary.projects) }}</strong>
          </div>
          <div class="stat-card">
            <span>This month</span>
            <strong>{{ compactMoney(summary.api_spend_month_usd) }}</strong>
          </div>
          <div class="stat-card">
            <span>Total tracked</span>
            <strong>{{ compactMoney(summary.api_spend_total_usd) }}</strong>
          </div>
          <div class="stat-card warn">
            <span>Failed calls</span>
            <strong>{{ number(summary.failed_api_calls_month) }}</strong>
          </div>
        </section>

        <section class="notice-band">
          <strong>Provider spend is estimated from calls Framecast makes.</strong>
          <span>OpenAI account balance still lives in OpenAI billing, but this page shows our workspace budgets, remaining limits, failures, and recent requests.</span>
        </section>

        <section class="section-block">
          <div class="section-heading">
            <div>
              <p class="eyebrow">Limits</p>
              <h2>Workspaces</h2>
            </div>
          </div>

          <div class="workspace-list">
            <article v-for="workspace in workspaces" :key="workspace.id" class="workspace-card">
              <div class="workspace-head">
                <div>
                  <h3>{{ workspace.name }}</h3>
                  <p>{{ workspace.users_count }} users · {{ workspace.projects_count }} projects · {{ workspace.status }}</p>
                </div>

                <div class="workspace-controls">
                  <select v-model="workspace.plan_tier" class="admin-select" @change="updateWorkspacePlan(workspace)">
                    <option v-for="plan in planOptions" :key="plan.key" :value="plan.key">{{ plan.name }}</option>
                  </select>
                  <select v-model="workspace.status" class="admin-select" @change="updateWorkspacePlan(workspace)">
                    <option value="active">Active</option>
                    <option value="paused">Paused</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>
              </div>

              <div v-if="savingWorkspaceId === workspace.id" class="saving">Saving workspace...</div>

              <div class="limit-grid">
                <div v-for="row in limitRows(workspace)" :key="row[0]" class="limit-row">
                  <div class="limit-copy">
                    <span>{{ row[0] }}</span>
                    <b>{{ row[0] === 'API budget' ? money(row[1]) : number(row[1]) }} / {{ row[0] === 'API budget' ? money(row[2]) : number(row[2]) }}</b>
                  </div>
                  <div class="meter"><i :style="{ width: `${pct(row[1], row[2])}%` }"></i></div>
                  <small>{{ row[3] }} remaining</small>
                </div>
              </div>
            </article>
          </div>
        </section>

        <section class="section-block">
          <div class="section-heading">
            <div>
              <p class="eyebrow">Spend</p>
              <h2>Cost per Video</h2>
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Project</th>
                  <th>Status</th>
                  <th>Script</th>
                  <th>Image</th>
                  <th>Voice</th>
                  <th>Total</th>
                  <th>Calls</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in projectCosts" :key="row.project_id">
                  <td>
                    <strong>{{ row.project_title }}</strong>
                    <span>{{ row.source_type }} · ws {{ row.workspace_id }}</span>
                  </td>
                  <td>{{ row.status }}</td>
                  <td>{{ money(row.script_cost) }}</td>
                  <td>{{ money(row.image_cost) }}</td>
                  <td>{{ money(row.tts_cost) }}</td>
                  <td><strong>{{ money(row.total_cost) }}</strong></td>
                  <td>{{ row.call_count }}</td>
                </tr>
                <tr v-if="projectCosts.length === 0">
                  <td colspan="7">No project cost data yet.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="section-grid">
          <div class="section-block">
            <div class="section-heading">
              <div>
                <p class="eyebrow">Accounts</p>
                <h2>Users</h2>
              </div>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>User</th>
                    <th>Workspace</th>
                    <th>Role</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="user in users" :key="user.id">
                    <td>
                      <strong>{{ user.name || user.email }}</strong>
                      <span>{{ user.email }}</span>
                    </td>
                    <td>{{ user.workspace_name || '-' }}</td>
                    <td>{{ user.role }}</td>
                    <td>{{ user.status }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div class="section-block">
            <div class="section-heading">
              <div>
                <p class="eyebrow">Providers</p>
                <h2>Recent Calls</h2>
              </div>
            </div>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>When</th>
                    <th>Service</th>
                    <th>Status</th>
                    <th>Cost</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="event in recentUsage" :key="event.id">
                    <td>{{ formatDate(event.occurred_at) }}</td>
                    <td>
                      <strong>{{ event.service }}</strong>
                      <span>{{ event.model || event.operation }}</span>
                    </td>
                    <td :class="['status-cell', event.status]">{{ event.status }}</td>
                    <td>{{ money(event.estimated_cost_usd) }}</td>
                  </tr>
                  <tr v-if="recentUsage.length === 0">
                    <td colspan="4">No provider calls tracked yet.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </template>
    </main>
  </div>
</template>

<style scoped>
.admin-shell {
  min-height: 100vh;
  background: var(--color-bg-deep);
}

.admin-main {
  margin-left: 220px;
  padding: 30px;
}

.admin-topbar,
.section-heading,
.workspace-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.admin-topbar {
  margin-bottom: 22px;
}

.eyebrow {
  color: var(--color-accent);
  font-family: var(--font-mono);
  font-size: 11px;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

h1,
h2,
h3 {
  color: var(--color-text-primary);
  font-weight: 700;
}

h1 {
  margin-top: 6px;
  font-size: 30px;
}

h2 {
  font-size: 20px;
}

h3 {
  font-size: 17px;
}

.refresh-btn,
.admin-select {
  height: 38px;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: var(--color-bg-elevated);
  color: var(--color-text-primary);
  padding: 0 12px;
}

.refresh-btn {
  cursor: pointer;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 12px;
}

.stat-card,
.workspace-card,
.section-block,
.notice-band {
  border: 1px solid var(--color-border);
  background: var(--color-bg-panel);
  border-radius: 8px;
}

.stat-card {
  padding: 16px;
}

.stat-card span,
.workspace-head p,
.limit-row small,
td span,
.notice-band span {
  color: var(--color-text-muted);
}

.stat-card strong {
  display: block;
  margin-top: 8px;
  font-size: 24px;
}

.stat-card.warn strong {
  color: #f87171;
}

.notice-band {
  display: grid;
  gap: 6px;
  margin-top: 14px;
  padding: 16px;
  line-height: 1.45;
}

.section-block {
  margin-top: 18px;
  padding: 18px;
}

.workspace-list {
  display: grid;
  gap: 12px;
  margin-top: 14px;
}

.workspace-card {
  padding: 16px;
}

.workspace-controls {
  display: flex;
  gap: 8px;
}

.saving {
  color: var(--color-accent);
  font-size: 13px;
  margin-top: 10px;
}

.limit-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-top: 16px;
}

.limit-row {
  min-width: 0;
}

.limit-copy {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  font-size: 13px;
}

.limit-copy b {
  color: var(--color-text-secondary);
  white-space: nowrap;
}

.meter {
  height: 7px;
  margin: 8px 0;
  border-radius: 999px;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.08);
}

.meter i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--color-accent);
}

.section-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
}

.table-wrap {
  margin-top: 14px;
  overflow: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 520px;
}

th,
td {
  padding: 12px 10px;
  border-bottom: 1px solid var(--color-border);
  text-align: left;
  font-size: 13px;
}

th {
  color: var(--color-text-muted);
  font-family: var(--font-mono);
  font-size: 11px;
  text-transform: uppercase;
}

td strong,
td span {
  display: block;
}

.status-cell.succeeded {
  color: #34d399;
}

.status-cell.failed {
  color: #f87171;
}

.banner,
.page-state {
  border-radius: 8px;
  padding: 14px 16px;
  border: 1px solid var(--color-border);
  background: var(--color-bg-panel);
}

.banner.error {
  margin-bottom: 16px;
  border-color: rgba(248, 113, 113, 0.45);
  color: #fca5a5;
}

@media (max-width: 1180px) {
  .stats-grid,
  .limit-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .section-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 760px) {
  .admin-main {
    margin-left: 0;
    padding: 22px 14px 86px;
  }

  .stats-grid,
  .limit-grid {
    grid-template-columns: 1fr;
  }

  .admin-topbar,
  .workspace-head {
    align-items: flex-start;
    flex-direction: column;
  }

  .workspace-controls {
    width: 100%;
    flex-direction: column;
  }
}
</style>
