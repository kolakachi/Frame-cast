<script setup>
import { ref,onMounted } from 'vue'
import api from '../services/api'
import { useAuthStore } from '../stores/auth'
import { useWorkspaceStore } from '../stores/workspace'
const auth=useAuthStore(), store=useWorkspaceStore(), items=ref([]), error=ref('')
onMounted(async()=>{try{items.value=(await api.get('/workspace-access')).data.data}catch{error.value='Workspace list unavailable'}})
async function change(id){try{await store.switchTo(Number(id))}catch{error.value='Could not switch workspace'}}
</script>
<template><div v-if="items.length || error" class="access-picker"><label v-if="items.length>1">Active workspace<select :value="auth.user?.workspace_id" :disabled="store.switching" @change="change($event.target.value)"><option v-for="w in items" :key="w.id" :value="w.id">{{w.name}}</option></select></label><router-link v-if="items.some(w=>w.id===auth.user?.workspace_id && w.is_client)" to="/client-work">Client brief & requests →</router-link><small v-if="error" role="alert">{{error}}</small></div></template>
<style scoped>.access-picker{padding:12px;font-size:12px}label{display:block;color:var(--color-text-muted,#aaa)}select{width:100%;margin:7px 0;padding:7px;border-radius:6px;background:var(--color-bg-panel,#222);color:inherit;border:1px solid var(--color-border,#333)}a{display:block;color:var(--color-accent,#ff6b35);margin-top:8px}small{display:block;color:#fa978b}</style>
