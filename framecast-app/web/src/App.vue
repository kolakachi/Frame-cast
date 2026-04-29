<script setup>
import { useRouter } from 'vue-router'
import { useLimitStore } from './stores/limit'
import { useSidebarStore } from './stores/sidebar'
import LimitModal from './components/LimitModal.vue'

const router = useRouter()
const limitStore = useLimitStore()
const sidebarStore = useSidebarStore()

function handleUpgrade() {
  limitStore.close()
  router.push({ name: 'settings', query: { section: 'usage' } })
}
</script>

<template>
  <div :class="{ 'sb-collapsed': sidebarStore.collapsed }">
    <RouterView />

    <LimitModal
      :open="limitStore.open"
      :title="limitStore.title"
      :subtitle="limitStore.subtitle"
      :rows="limitStore.rows"
      @close="limitStore.close()"
      @upgrade="handleUpgrade"
    />
  </div>
</template>

<style>
.main {
  transition: margin-left 0.2s ease;
}
.sb-collapsed .main {
  margin-left: 56px !important;
}
</style>
