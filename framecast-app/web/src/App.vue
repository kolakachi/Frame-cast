<script setup>
import { useRouter } from 'vue-router'
import { useLimitStore } from './stores/limit'
import LimitModal from './components/LimitModal.vue'

const router = useRouter()
const limitStore = useLimitStore()

function handleUpgrade() {
  limitStore.close()
  router.push({ name: 'settings', query: { section: 'usage' } })
}
</script>

<template>
  <div>
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
