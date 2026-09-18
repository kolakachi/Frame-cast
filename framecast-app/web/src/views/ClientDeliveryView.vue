<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
const route=useRoute(), data=ref(null), error=ref(''), loading=ref(true)
async function load(){loading.value=true;error.value='';try{data.value=(await axios.get(`${import.meta.env.VITE_API_URL || ''}/api/v1/delivery/${route.params.token}`)).data.data}catch{error.value='This delivery is unavailable. It may have expired or been revoked.'}finally{loading.value=false}}
onMounted(load)
</script>
<template><main><small>WYVSTUDIO · CLIENT DELIVERY</small><p v-if="loading">Loading your delivery…</p><p v-else-if="error" role="alert">{{error}}</p><template v-else-if="data"><h1>{{data.title}}</h1><p class="note">{{data.message}}</p><p>Available until {{new Date(data.expires_at).toLocaleDateString()}}</p><article v-for="f in data.files" :key="f.id"><div><h2>{{f.name}}</h2><span>{{f.format}}</span></div><a :href="f.url" target="_blank" rel="noopener">Open / download ↗</a></article><p>File links last ten minutes. <button @click="load">Refresh links</button></p></template></main></template>
<style scoped>main{max-width:850px;margin:60px auto;padding:24px;color:var(--color-text-primary,#eee)}small{letter-spacing:.15em;color:#ff824f}h1{font-size:32px}h2{font-size:16px}p{line-height:1.7;color:var(--color-text-secondary,#bbb)}.note{white-space:pre-wrap}article{display:flex;justify-content:space-between;align-items:center;gap:24px;border:1px solid var(--color-border,#333);border-radius:14px;padding:20px;margin:16px 0}a,button{color:#ff824f}button{background:none;border:0;cursor:pointer}@media(max-width:600px){article{align-items:flex-start;flex-direction:column}}</style>
