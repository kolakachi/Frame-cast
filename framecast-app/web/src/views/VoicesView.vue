<script setup>
import { onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";
import api from "../services/api";
import AppSidebar from "../components/AppSidebar.vue";
import GridSkeleton from "../components/skeletons/GridSkeleton.vue";
import NotifBell from "../components/NotifBell.vue";
import VoiceCloneModal from "../components/VoiceCloneModal.vue";

const router = useRouter();
const authStore = useAuthStore();

const mePayload = ref(null);
const loading = ref(true);
const error = ref("");
const voices = ref([]); // cloned voices only

const showClone = ref(false);
function openClone() { showClone.value = true; }
function onCloned() { loadVoices(); }

async function loadVoices() {
  loading.value = true;
  error.value = "";
  try {
    const res = await api.get("/voice-profiles");
    const all = res.data?.data?.voice_profiles ?? [];
    voices.value = all.filter((v) => v.is_cloned);
  } catch (e) {
    error.value = e?.response?.data?.error?.message ?? "Could not load voices.";
  } finally {
    loading.value = false;
  }
}

const deleteTarget = ref(null);
async function confirmDelete() {
  const v = deleteTarget.value;
  if (!v) return;
  try {
    await api.delete(`/voice-profiles/${v.id}`);
    deleteTarget.value = null;
    await loadVoices();
  } catch (e) {
    error.value = e?.response?.data?.error?.message ?? "Could not delete the voice.";
    deleteTarget.value = null;
  }
}

function initial(name) {
  return (name || "?").trim().charAt(0).toUpperCase();
}

function logout() {
  authStore.clearSession();
  router.push({ name: "login" });
}

onMounted(async () => {
  try {
    const me = await api.get("/me");
    mePayload.value = me.data?.data?.user ?? null;
  } catch {}
  await loadVoices();
});
</script>

<template>
  <div class="voices-shell">
    <AppSidebar :user="mePayload" active-page="voices" @logout="logout" />

    <main class="main">
      <div class="topbar">
        <div class="topbar-left">
          <span class="bc-ws">My Workspace</span>
          <span class="bc-sep">/</span>
          <span class="bc-page">Voices</span>
        </div>
        <div class="topbar-right">
          <button class="btn btn-primary btn-sm" type="button" @click="openClone">
            <span style="font-weight:700">＋</span> Clone a voice
          </button>
          <NotifBell />
        </div>
      </div>

      <div class="content">
        <div v-if="error" class="banner error">{{ error }}</div>
        <GridSkeleton v-if="loading" layout="row" :count="6" :min="260" :row-thumb="40" :lines="2" />

        <template v-else>
          <div class="intro">
            <div class="intro-title">Your cloned voices</div>
            <div class="intro-body">
              Upload a clean ~10–20s sample of a voice. We clone it with Chatterbox, and it
              becomes selectable as the voiceover for any scene — alongside the built-in voices.
            </div>
          </div>

          <div v-if="voices.length === 0" class="empty-hero">
            <div class="empty-icon">🎙</div>
            <div class="empty-title">No cloned voices yet</div>
            <div class="empty-body">
              Clone a voice from a short sample, then pick it in the editor's voice panel.
            </div>
            <button class="btn btn-primary" type="button" @click="openClone">＋ Clone your first voice</button>
          </div>

          <div v-else class="voice-grid">
            <article v-for="v in voices" :key="v.id" class="voice-card">
              <div class="voice-avatar">{{ initial(v.name) }}</div>
              <div class="voice-card-body">
                <div class="voice-card-name">{{ v.name }}</div>
                <div class="voice-card-meta"><span class="cloned-badge">Cloned</span> · Chatterbox</div>
              </div>
              <button class="voice-del" type="button" title="Delete" @click="deleteTarget = v">✕</button>
            </article>
          </div>
        </template>
      </div>
    </main>

    <!-- Clone modal (shared component: name + record-with-countdown / upload, preview, retake) -->
    <VoiceCloneModal v-if="showClone" @close="showClone = false" @created="onCloned" />

    <!-- Delete confirm -->
    <Teleport to="body">
      <div v-if="deleteTarget" class="v-backdrop" @click.self="deleteTarget = null">
        <div class="v-modal v-modal-sm">
          <div class="v-title">Delete "{{ deleteTarget.name }}"?</div>
          <div class="v-confirm-body">Scenes already using this voice keep their generated audio, but you won't be able to pick it for new ones.</div>
          <div class="v-foot">
            <button class="btn btn-ghost btn-sm" type="button" @click="deleteTarget = null">Cancel</button>
            <button class="btn btn-danger btn-sm" type="button" @click="confirmDelete">Delete</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.voices-shell { display: flex; min-height: 100vh; background: #0a0a0f; color: #e8e8ee; }
/* The sidebar is position:fixed (220px); offset main like the other views so
   left-aligned content isn't clipped under it. */
.main { margin-left: var(--sidebar-width, 220px); flex: 1; min-width: 0; display: flex; flex-direction: column; }
.topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid rgba(255,255,255,0.08); }
.topbar-left { font-size: 13px; color: #8a8a9a; }
.bc-sep { margin: 0 8px; opacity: 0.5; }
.bc-page { color: #e8e8ee; font-weight: 600; }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.content { padding: 24px; max-width: 1100px; }
.banner.error { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: #fca5a5; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px; }
.page-state { color: #8a8a9a; padding: 40px 0; }

.intro { margin-bottom: 20px; }
.intro-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
.intro-body { font-size: 13px; color: #9a9aab; line-height: 1.5; max-width: 640px; }

.empty-hero { text-align: center; padding: 60px 20px; border: 1px dashed rgba(255,255,255,0.12); border-radius: 14px; }
.empty-icon { font-size: 34px; margin-bottom: 10px; }
.empty-title { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
.empty-body { font-size: 13px; color: #9a9aab; margin-bottom: 16px; line-height: 1.5; }

.voice-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.voice-card { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: #14141c; border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; }
.voice-avatar { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,107,53,0.15); color: #ff8055; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
.voice-card-body { flex: 1; min-width: 0; }
.voice-card-name { font-weight: 600; font-size: 14px; }
.voice-card-meta { font-size: 11.5px; color: #8a8a9a; margin-top: 2px; }
.cloned-badge { background: rgba(255,107,53,0.18); color: #ff8055; border-radius: 5px; padding: 1px 6px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.voice-del { background: transparent; border: none; color: #8a8a9a; cursor: pointer; font-size: 14px; padding: 4px 8px; border-radius: 6px; }
.voice-del:hover { background: rgba(248,113,113,0.12); color: #fca5a5; }

.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.14); background: rgba(255,255,255,0.04); color: #e8e8ee; font: inherit; font-size: 13px; cursor: pointer; }
.btn:hover { background: rgba(255,255,255,0.08); }
.btn-sm { padding: 6px 12px; font-size: 12.5px; }
.btn-primary { background: #ff6b35; border-color: #ff6b35; color: #0a0a0f; font-weight: 600; }
.btn-primary:hover { background: #ff8055; border-color: #ff8055; }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-ghost { background: transparent; border-color: transparent; }
.btn-ghost:hover { background: rgba(255,255,255,0.06); }
.btn-danger { background: #ef4444; border-color: #ef4444; color: #fff; font-weight: 600; }
.btn-danger:hover { background: #f87171; }

.v-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 1000; padding: 20px; }
.v-modal { width: 100%; max-width: 480px; background: #14141c; border: 1px solid rgba(255,255,255,0.1); border-radius: 14px; padding: 20px; }
.v-modal-sm { max-width: 400px; }
.v-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.v-title { font-size: 16px; font-weight: 700; }
.v-close { background: transparent; border: none; color: #8a8a9a; font-size: 22px; cursor: pointer; line-height: 1; }
.v-field { margin-bottom: 14px; }
.v-label { display: block; font-size: 12px; color: #9a9aab; margin-bottom: 6px; }
.v-input { width: 100%; box-sizing: border-box; background: #0e0e15; border: 1px solid rgba(255,255,255,0.12); border-radius: 8px; color: #e8e8ee; font: inherit; font-size: 13px; padding: 9px 11px; }
.v-input:focus { outline: none; border-color: rgba(255,107,53,0.5); }
.v-sample-actions { display: flex; gap: 10px; }
.v-sample-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; min-height: 46px; border: 1px solid rgba(255,255,255,0.14); border-radius: 10px; background: rgba(255,255,255,0.04); color: #e8e8ee; font: inherit; font-size: 13px; cursor: pointer; }
.v-sample-btn:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,107,53,0.4); }
.v-sample-btn.rec { background: rgba(248,113,113,0.14); border-color: rgba(248,113,113,0.5); color: #fca5a5; }
.v-sample-btn.disabled { opacity: 0.5; cursor: not-allowed; }
.v-sample-current { margin-top: 8px; font-size: 12.5px; color: #5ed39a; word-break: break-all; }
.v-rec-live { margin-top: 8px; font-size: 12.5px; color: #fca5a5; }
.v-hint { font-size: 11px; color: #7a7a8a; margin-top: 6px; line-height: 1.4; }
.v-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
.v-confirm-body { font-size: 13px; color: #9a9aab; line-height: 1.5; margin: 10px 0 4px; }
</style>
