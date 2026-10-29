<script setup>
// Opens the classic or the new editor for /projects/:id/editor. The answer comes from /me (`editor`), so a stale
// session without it asks once. Phones stay on classic until the new editor's phone layout is built.
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useAuthStore } from "../stores/auth";
import EditorView from "./EditorView.vue";

const EditorNextView = defineAsyncComponent(() => import("./EditorNextView.vue"));

const auth = useAuthStore();
const ready = ref(auth.user?.editor !== undefined);
const phone = ref(window.matchMedia("(max-width: 860px)").matches);
const mq = window.matchMedia("(max-width: 860px)");
const onMq = (e) => { phone.value = e.matches; };

onMounted(async () => {
  mq.addEventListener?.("change", onMq);
  if (!ready.value) {
    await auth.refreshUser();
    ready.value = true;
  }
});
onBeforeUnmount(() => mq.removeEventListener?.("change", onMq));
// A session written by an older payload has no answer; ask /me rather than guess classic.
watch(() => auth.user && auth.user.editor === undefined, (missing) => { if (missing) auth.refreshUser(); });

const which = computed(() => (auth.user?.editor === "new" && !phone.value ? "new" : "classic"));
</script>

<template>
  <EditorNextView v-if="ready && which === 'new'" key="new" />
  <EditorView v-else-if="ready" key="classic" />
</template>
