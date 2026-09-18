<script setup>
import AppSidebar from '../components/AppSidebar.vue'
import ClientHubPanel from '../components/ClientHubPanel.vue'
import { useAuthStore } from '../stores/auth'
const auth=useAuthStore()
</script>
<template><div><AppSidebar :user="auth.user" active-page="client-work"/><main class="client-work"><h1>Client workspace</h1><ClientHubPanel /></main></div></template>
<style scoped>.client-work{margin-left:var(--sidebar-width,220px);padding:28px;max-width:1250px}h1{font-size:24px;margin:0 0 24px}@media(max-width:860px){.client-work{margin-left:0;padding:80px 16px 100px}}</style>
