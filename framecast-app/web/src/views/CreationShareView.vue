<script setup>
import {onMounted,ref} from 'vue'
import {useRoute} from 'vue-router'
import api from '../services/api'
import FinishedVideoPlayer from '../components/FinishedVideoPlayer.vue'
const route=useRoute(),result=ref(null),error=ref('')
onMounted(async()=>{try{result.value=(await api.get('/public/creations/'+route.params.token)).data.data}catch{error.value='This share link is unavailable.'}})
</script>
<template><main class="share"><a href="/">WyvStudio</a><p v-if="error">{{ error }}</p><template v-else-if="result"><h1>{{ result.title }}</h1><p>Version {{ result.version }}</p><img v-if="result.kind === 'image'" :src="result.url" alt="Shared creation" /><FinishedVideoPlayer v-else :src="result.url" /></template><p v-else>Loading creation…</p></main></template>
<style scoped>.share{min-height:100vh;background:#0a0a0f;color:#ececf3;padding:40px max(24px,calc((100vw - 850px)/2));box-sizing:border-box}.share a{color:#ff783e}.share h1{font-size:24px;margin-top:40px}.share p{color:#a69aaf}.share img{max-width:100%;max-height:75vh;object-fit:contain}</style>
