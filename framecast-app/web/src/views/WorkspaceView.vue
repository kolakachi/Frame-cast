<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'
import AppSidebar from '../components/AppSidebar.vue'

const authStore = useAuthStore()
const workspaceStore = useWorkspaceStore()

const editingName = ref(false)
const nameInput = ref('')
const saving = ref(false)
const saveError = ref(null)

const workspace = computed(() => workspaceStore.workspace)
const usage = computed(() => workspaceStore.usage)

const PLAN_COLORS = {
  free: '#6b7280',
  studio: '#7c3aed',
  scale: '#0ea5e9',
  enterprise: '#f59e0b',
}

const planColor = computed(() => PLAN_COLORS[workspace.value?.plan_tier] ?? '#6b7280')

function meterPct(used, limit) {
  if (!limit) return 0
  return Math.min(100, Math.round((used / limit) * 100))
}

function meterClass(used, limit) {
  if (used > limit) return 'meter-danger'
  const pct = meterPct(used, limit)
  if (pct >= 90) return 'meter-danger'
  if (pct >= 70) return 'meter-warn'
  return 'meter-ok'
}

function remaining(used, limit) {
  const diff = limit - used
  if (diff < 0) return { over: true, count: Math.abs(diff) }
  return { over: false, count: diff }
}

function startEdit() {
  nameInput.value = workspace.value?.name ?? ''
  editingName.value = true
  saveError.value = null
}

function cancelEdit() {
  editingName.value = false
  saveError.value = null
}

async function saveName() {
  const trimmed = nameInput.value.trim()
  if (!trimmed || trimmed === workspace.value?.name) {
    editingName.value = false
    return
  }
  saving.value = true
  saveError.value = null
  try {
    await workspaceStore.updateName(workspace.value.id, trimmed)
    editingName.value = false
  } catch {
    saveError.value = 'Could not save. Please try again.'
  } finally {
    saving.value = false
  }
}

async function logout() {
  await authStore.logout()
  window.location.href = '/login'
}

onMounted(async () => {
  const wsId = authStore.user?.workspace_id
  if (wsId) await workspaceStore.load(wsId)
})
</script>

<template>
  <div class="app-layout">
    <AppSidebar :user="authStore.user" active-page="workspace" @logout="logout" />

    <main class="main-content">
      <div class="ws-page">
        <div class="ws-header">
          <div class="ws-header-left">
            <div class="ws-icon">{{ workspace?.name?.[0]?.toUpperCase() || 'W' }}</div>
            <div class="ws-title-block">
              <div v-if="!editingName" class="ws-name-row">
                <h1 class="ws-title">{{ workspace?.name || 'My Workspace' }}</h1>
                <button class="edit-btn" type="button" @click="startEdit">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                  </svg>
                  Edit
                </button>
              </div>
              <div v-else class="ws-name-edit">
                <input
                  v-model="nameInput"
                  class="name-input"
                  maxlength="80"
                  placeholder="Workspace name"
                  autofocus
                  @keydown.enter="saveName"
                  @keydown.esc="cancelEdit"
                />
                <button class="btn-save" :disabled="saving" type="button" @click="saveName">
                  {{ saving ? 'Saving…' : 'Save' }}
                </button>
                <button class="btn-cancel" type="button" @click="cancelEdit">Cancel</button>
                <span v-if="saveError" class="save-error">{{ saveError }}</span>
              </div>
              <div class="ws-meta">
                <span class="plan-badge" :style="{ background: planColor + '22', color: planColor, borderColor: planColor + '44' }">
                  {{ workspaceStore.planLabel }} Plan
                </span>
                <span class="ws-status" :class="workspace?.status === 'active' ? 'status-active' : 'status-inactive'">
                  {{ workspace?.status || 'active' }}
                </span>
                <span class="ws-since">Member since {{ workspace?.created_at ? new Date(workspace.created_at).toLocaleDateString('en-US', { month: 'short', year: 'numeric' }) : '—' }}</span>
              </div>
            </div>
          </div>
        </div>

        <div v-if="workspaceStore.loading && !workspace" class="loading-state">
          Loading workspace…
        </div>

        <template v-else-if="usage">
          <section class="section">
            <h2 class="section-title">Usage</h2>
            <div class="usage-grid">
              <div class="usage-card" :class="{ 'usage-over': usage.renders_used > usage.render_limit }">
                <div class="usage-label">
                  <span>Exports</span>
                  <span class="usage-count">{{ usage.renders_used }} / {{ usage.render_limit }}</span>
                </div>
                <div class="meter-track">
                  <div class="meter-fill" :class="meterClass(usage.renders_used, usage.render_limit)" :style="{ width: meterPct(usage.renders_used, usage.render_limit) + '%' }"></div>
                </div>
                <div :class="['usage-sub', remaining(usage.renders_used, usage.render_limit).over ? 'sub-over' : '']">
                  <template v-if="remaining(usage.renders_used, usage.render_limit).over">{{ remaining(usage.renders_used, usage.render_limit).count }} over limit</template>
                  <template v-else>{{ remaining(usage.renders_used, usage.render_limit).count }} remaining this month</template>
                </div>
              </div>

              <div class="usage-card" :class="{ 'usage-over': usage.voice_minutes_used > usage.voice_minutes_limit }">
                <div class="usage-label">
                  <span>Voice Minutes</span>
                  <span class="usage-count">{{ usage.voice_minutes_used }} / {{ usage.voice_minutes_limit }}</span>
                </div>
                <div class="meter-track">
                  <div class="meter-fill" :class="meterClass(usage.voice_minutes_used, usage.voice_minutes_limit)" :style="{ width: meterPct(usage.voice_minutes_used, usage.voice_minutes_limit) + '%' }"></div>
                </div>
                <div :class="['usage-sub', remaining(usage.voice_minutes_used, usage.voice_minutes_limit).over ? 'sub-over' : '']">
                  <template v-if="remaining(usage.voice_minutes_used, usage.voice_minutes_limit).over">{{ remaining(usage.voice_minutes_used, usage.voice_minutes_limit).count }} min over limit</template>
                  <template v-else>{{ remaining(usage.voice_minutes_used, usage.voice_minutes_limit).count }} min remaining</template>
                </div>
              </div>

              <div class="usage-card" :class="{ 'usage-over': usage.active_channels > usage.channel_limit }">
                <div class="usage-label">
                  <span>Channels</span>
                  <span class="usage-count">{{ usage.active_channels }} / {{ usage.channel_limit }}</span>
                </div>
                <div class="meter-track">
                  <div class="meter-fill" :class="meterClass(usage.active_channels, usage.channel_limit)" :style="{ width: meterPct(usage.active_channels, usage.channel_limit) + '%' }"></div>
                </div>
                <div :class="['usage-sub', remaining(usage.active_channels, usage.channel_limit).over ? 'sub-over' : '']">
                  <template v-if="remaining(usage.active_channels, usage.channel_limit).over">{{ remaining(usage.active_channels, usage.channel_limit).count }} over limit</template>
                  <template v-else>{{ remaining(usage.active_channels, usage.channel_limit).count }} slots available</template>
                </div>
              </div>

              <div class="usage-card" :class="{ 'usage-over': usage.voice_cloning_used > usage.voice_cloning_limit }">
                <div class="usage-label">
                  <span>Voice Clones</span>
                  <span class="usage-count">{{ usage.voice_cloning_used }} / {{ usage.voice_cloning_limit }}</span>
                </div>
                <div class="meter-track">
                  <div class="meter-fill" :class="meterClass(usage.voice_cloning_used, usage.voice_cloning_limit)" :style="{ width: meterPct(usage.voice_cloning_used, usage.voice_cloning_limit) + '%' }"></div>
                </div>
                <div :class="['usage-sub', remaining(usage.voice_cloning_used, usage.voice_cloning_limit).over ? 'sub-over' : '']">
                  <template v-if="remaining(usage.voice_cloning_used, usage.voice_cloning_limit).over">{{ remaining(usage.voice_cloning_used, usage.voice_cloning_limit).count }} over limit</template>
                  <template v-else>{{ remaining(usage.voice_cloning_used, usage.voice_cloning_limit).count }} remaining</template>
                </div>
              </div>

              <div class="usage-card" :class="{ 'usage-over': usage.dub_languages_used > usage.dub_languages_limit }">
                <div class="usage-label">
                  <span>Dub Languages</span>
                  <span class="usage-count">{{ usage.dub_languages_used }} / {{ usage.dub_languages_limit }}</span>
                </div>
                <div class="meter-track">
                  <div class="meter-fill" :class="meterClass(usage.dub_languages_used, usage.dub_languages_limit)" :style="{ width: meterPct(usage.dub_languages_used, usage.dub_languages_limit) + '%' }"></div>
                </div>
                <div :class="['usage-sub', remaining(usage.dub_languages_used, usage.dub_languages_limit).over ? 'sub-over' : '']">
                  <template v-if="remaining(usage.dub_languages_used, usage.dub_languages_limit).over">{{ remaining(usage.dub_languages_used, usage.dub_languages_limit).count }} over limit</template>
                  <template v-else>{{ remaining(usage.dub_languages_used, usage.dub_languages_limit).count }} languages remaining</template>
                </div>
              </div>
            </div>
          </section>

          <section class="section">
            <h2 class="section-title">Overview</h2>
            <div class="stats-row">
              <div class="stat-card">
                <div class="stat-value">{{ usage.projects }}</div>
                <div class="stat-label">Projects</div>
              </div>
              <div class="stat-card">
                <div class="stat-value">{{ usage.assets }}</div>
                <div class="stat-label">Assets</div>
              </div>
              <div class="stat-card">
                <div class="stat-value">{{ usage.renders_used }}</div>
                <div class="stat-label">Completed Exports</div>
              </div>
              <div class="stat-card">
                <div class="stat-value">{{ usage.brand_kits }}</div>
                <div class="stat-label">Brand Kits</div>
              </div>
            </div>
          </section>
        </template>
      </div>
    </main>
  </div>
</template>

<style scoped>
.app-layout { display: flex; min-height: 100vh; background: var(--color-bg-base); }
.main-content { flex: 1; margin-left: var(--sidebar-width, 220px); padding: 32px 40px; overflow-y: auto; }

.ws-page { max-width: 820px; }

.ws-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 40px; }
.ws-header-left { display: flex; align-items: flex-start; gap: 18px; }

.ws-icon {
  width: 56px; height: 56px; border-radius: 14px; flex-shrink: 0;
  background: linear-gradient(135deg, #7c3aed, #db2777);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; font-weight: 700; color: #fff;
  font-family: "Space Mono", monospace;
}

.ws-title-block { display: flex; flex-direction: column; gap: 6px; }
.ws-name-row { display: flex; align-items: center; gap: 10px; }
.ws-title { font-size: 22px; font-weight: 700; color: var(--color-text-primary); margin: 0; }

.edit-btn {
  display: flex; align-items: center; gap: 5px;
  font-size: 12px; color: var(--color-text-muted); background: transparent;
  border: 1px solid var(--color-border); border-radius: 6px; padding: 3px 9px;
  cursor: pointer; transition: 0.15s;
}
.edit-btn:hover { color: var(--color-text-primary); border-color: var(--color-border-active); }

.ws-name-edit { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.name-input {
  background: var(--color-bg-elevated); border: 1px solid var(--color-border-active);
  border-radius: 8px; padding: 6px 12px; font-size: 15px; font-weight: 600;
  color: var(--color-text-primary); outline: none; width: 280px;
}
.name-input:focus { border-color: var(--color-accent); }
.btn-save {
  background: var(--color-accent); color: #fff; border: none; border-radius: 8px;
  padding: 6px 14px; font-size: 13px; font-weight: 600; cursor: pointer; transition: 0.15s;
}
.btn-save:disabled { opacity: 0.6; cursor: default; }
.btn-cancel {
  background: transparent; border: 1px solid var(--color-border); border-radius: 8px;
  padding: 6px 14px; font-size: 13px; color: var(--color-text-secondary); cursor: pointer;
}
.save-error { font-size: 12px; color: #f87171; }

.ws-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.plan-badge {
  font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px;
  border: 1px solid; font-family: "Space Mono", monospace; letter-spacing: 0.03em;
}
.ws-status { font-size: 11px; font-weight: 600; text-transform: capitalize; color: var(--color-text-muted); }
.status-active { color: #4ade80; }
.status-inactive { color: #f87171; }
.ws-since { font-size: 11px; color: var(--color-text-muted); }

.loading-state { color: var(--color-text-muted); font-size: 14px; padding: 40px 0; }

.section { margin-bottom: 36px; }
.section-title {
  font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;
  color: var(--color-text-muted); font-family: "Space Mono", monospace;
  margin: 0 0 14px;
}

.usage-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

.usage-card {
  background: var(--color-bg-panel); border: 1px solid var(--color-border);
  border-radius: 10px; padding: 14px 16px;
}
.usage-label { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; color: var(--color-text-primary); margin-bottom: 8px; }
.usage-count { font-family: "Space Mono", monospace; font-size: 12px; color: var(--color-text-muted); }
.meter-track { height: 5px; background: var(--color-border); border-radius: 999px; overflow: hidden; margin-bottom: 6px; }
.meter-fill { height: 100%; border-radius: 999px; transition: width 0.4s; }
.meter-ok { background: #4ade80; }
.meter-warn { background: #f59e0b; }
.meter-danger { background: #f87171; }
.usage-sub { font-size: 11px; color: var(--color-text-muted); }
.sub-over { color: #f87171; font-weight: 600; }
.usage-over { border-color: rgba(248, 113, 113, 0.3); background: rgba(248, 113, 113, 0.04); }

.stats-row { display: flex; gap: 12px; flex-wrap: wrap; }
.stat-card {
  flex: 1; min-width: 120px; background: var(--color-bg-panel);
  border: 1px solid var(--color-border); border-radius: 10px; padding: 16px;
  text-align: center;
}
.stat-value { font-size: 26px; font-weight: 700; color: var(--color-text-primary); font-family: "Space Mono", monospace; line-height: 1; margin-bottom: 6px; }
.stat-label { font-size: 11px; color: var(--color-text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
</style>
