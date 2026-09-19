<script setup>
import { onBeforeUnmount, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import AppSidebar from "../components/AppSidebar.vue";
import api from "../services/api";
import { useAuthStore } from "../stores/auth";
import { apiErrorMessage } from "../composables/apiError";

// The takes library: UGC's own first-class listing, now that takes no
// longer appear in All Videos. Cards open straight to review or the run.

const router = useRouter();
const authStore = useAuthStore();

const takes = ref([]);
const loaded = ref(false);
const errorMessage = ref("");
let timer = null;
let disposed = false;

async function load() {
  clearTimeout(timer);
  try {
    const { data } = await api.get("/ugc/takes");
    if (disposed) return;
    takes.value = data?.data?.takes ?? [];
    loaded.value = true;
  } catch (err) {
    if (!loaded.value) errorMessage.value = apiErrorMessage(err, "Could not load your takes.");
  }
  if (!disposed && takes.value.some((t) => t.status === "generating")) timer = setTimeout(load, 10000);
}

function open(take) {
  router.push(
    take.status === "ready_for_review"
      ? { name: "ugc-review", params: { projectId: take.id } }
      : take.run_id
      ? { name: "ugc-run", params: { runId: take.run_id } }
      : { name: "project-editor", params: { projectId: take.id } }
  );
}

const STATUS = {
  ready_for_review: { label: "Ready to review", cls: "ok" },
  generating: { label: "Generating…", cls: "" },
  needs_attention: { label: "Needs a retry", cls: "bad" },
};

async function remove(take) {
  if (!window.confirm(`Delete "${take.character}"? This removes the take and its scenes.`)) return;
  try {
    await api.delete(`/projects/${take.id}`);
    takes.value = takes.value.filter((t) => t.id !== take.id);
  } catch (err) {
    errorMessage.value = apiErrorMessage(err, "Could not delete that take.");
  }
}

function when(iso) {
  if (!iso) return "";
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

onMounted(() => {
  if (!authStore.user?.is_internal) {
    router.replace({ name: "dashboard" });
    return;
  }
  load();
});
onBeforeUnmount(() => {
  disposed = true;
  clearTimeout(timer);
});
</script>

<template>
  <div class="tl-shell">
    <AppSidebar :user="authStore.user" active-page="ugc-ads" @logout="authStore.logout()" />

    <main class="tl-main">
      <header class="tl-top">
        <div class="tl-crumb">
          <b>UGC Ads</b> — your takes
        </div>
        <button class="tl-btn tl-btn-primary" type="button" @click="router.push({ name: 'ugc-new' })">
          + New take
        </button>
      </header>

      <div v-if="errorMessage" class="tl-error">{{ errorMessage }}</div>

      <div v-if="loaded && !takes.length" class="tl-empty">
        No takes yet. Your first one starts from a brief, a script, or a product link —
        <router-link :to="{ name: 'ugc-new' }">make one</router-link>.
      </div>

      <div class="tl-grid">
        <button v-for="t in takes" :key="t.id" type="button" class="tl-card" @click="open(t)">
          <div class="tl-thumb">
            <video
              v-if="t.thumbnail_url && t.thumbnail_type === 'video'"
              :src="t.thumbnail_url"
              muted
              playsinline
              preload="metadata"
            ></video>
            <img v-else-if="t.thumbnail_url" :src="t.thumbnail_url" alt="" loading="lazy" />
            <span v-else class="tl-thumb-empty">{{ t.status === "generating" ? "Generating…" : "▢" }}</span>
            <span :class="['tl-status', STATUS[t.status]?.cls]">{{ STATUS[t.status]?.label ?? t.status }}</span>
            <span class="tl-del" role="button" aria-label="Delete take" @click.stop="remove(t)">✕</span>
          </div>
          <div class="tl-meta">
            <b class="tl-title">{{ t.character }}</b>
            <span class="tl-sub">
              {{ t.scenes === 1 ? "One fluid video" : `${t.scenes} scenes` }} · {{ t.credits }} cr
              <template v-if="t.created_at"> · {{ when(t.created_at) }}</template>
            </span>
          </div>
        </button>
      </div>
    </main>
  </div>
</template>

<style scoped>
.tl-shell { display: flex; min-height: 100vh; background: var(--color-bg); }
.tl-main {
  margin-left: var(--sidebar-width, 220px);
  flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 0 28px 48px;
}
.tl-top {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 18px 0 14px; border-bottom: 1px solid var(--color-border);
}
.tl-crumb { font-size: 13.5px; color: var(--color-text-muted); }
.tl-crumb b { color: var(--color-text); }
.tl-error {
  margin: 14px 0 0; padding: 10px 14px; border-radius: 10px; font-size: 13px;
  background: var(--color-danger-soft, rgba(179, 38, 30, 0.08)); color: var(--color-danger, #b3261e);
}
.tl-empty { padding: 40px 0; font-size: 14px; color: var(--color-text-muted); }
.tl-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 16px; padding-top: 20px;
}
.tl-card {
  text-align: left; border: 1px solid var(--color-border); border-radius: 14px;
  background: var(--color-surface); color: var(--color-text); padding: 0; cursor: pointer; overflow: hidden;
}
.tl-card:hover { border-color: var(--color-primary); }
.tl-thumb {
  position: relative; aspect-ratio: 9 / 16; max-height: 300px; width: 100%;
  background: #141311; display: flex; align-items: center; justify-content: center;
}
.tl-thumb img, .tl-thumb video { width: 100%; height: 100%; object-fit: cover; display: block; }
.tl-thumb-empty { color: #847f70; font-size: 13px; }
.tl-status {
  position: absolute; left: 10px; bottom: 10px; font-size: 11px; font-weight: 700;
  border-radius: 999px; padding: 4px 10px; background: rgba(20, 19, 17, 0.75); color: #e8e4da;
}
.tl-status.ok { color: #7fd6a8; }
.tl-status.bad { color: #ff9b93; }
.tl-del {
  position: absolute; right: 10px; top: 10px; width: 26px; height: 26px;
  display: none; align-items: center; justify-content: center; border-radius: 50%;
  background: rgba(20, 19, 17, 0.8); color: #e8e4da; font-size: 12px; cursor: pointer;
}
.tl-card:hover .tl-del { display: flex; }
.tl-del:hover { color: #ff9b93; }
.tl-meta { display: flex; flex-direction: column; gap: 3px; padding: 10px 12px 12px; }
.tl-title { font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tl-sub { font-size: 12px; color: var(--color-text-muted); }
.tl-btn {
  border: 1px solid var(--color-border); background: var(--color-surface); color: var(--color-text);
  border-radius: 9px; padding: 9px 15px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.tl-btn-primary { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
@media (max-width: 860px) {
  .tl-main { margin-left: 0; padding: 0 14px 90px; }
}
</style>
