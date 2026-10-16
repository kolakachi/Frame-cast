<script setup>
import {computed,onMounted,ref} from 'vue'
import {useRoute} from 'vue-router'
import api from '../services/api'
import FinishedVideoPlayer from '../components/FinishedVideoPlayer.vue'
const route=useRoute(),result=ref(null),error=ref('')
onMounted(async()=>{try{result.value=(await api.get('/public/creations/'+route.params.token)).data.data}catch{error.value='This share link is unavailable.'}})
// "Make a video like this", as on the sample pages: a visitor who came with campaign tags (an email, an ad) keeps them,
// so a sign-up is credited to that campaign; otherwise the shared creation is the source (owner, 2026-10-16).
const TRACKING=['utm_source','utm_medium','utm_campaign','utm_term','utm_content','gclid','gbraid','wbraid']
const ctaUrl=computed(()=>{
  const keep=new URLSearchParams()
  for(const k of TRACKING){const v=route.query[k];if(typeof v==='string'&&v&&v.length<=200)keep.set(k,v)}
  if(![...keep.keys()].length){keep.set('utm_source','creation_share');keep.set('utm_medium','video')}
  return 'https://wyvstudio.com/?'+keep.toString()
})
</script>
<template><main class="share"><a class="brand" href="https://wyvstudio.com/">WyvStudio</a><p v-if="error">{{ error }}</p><template v-else-if="result"><h1>{{ result.title }}</h1><img v-if="result.kind === 'image'" :src="result.url" alt="Shared creation" /><FinishedVideoPlayer v-else :src="result.url" />
  <div class="made">Made with <strong>WyvStudio</strong>, the AI short-video studio</div>
  <a class="cta" :href="ctaUrl" rel="noopener">Make a video like this →</a></template><p v-else>Loading creation…</p></main></template>
<style scoped>.share{min-height:100vh;background:#0a0a0f;color:#ececf3;padding:40px max(24px,calc((100vw - 850px)/2));box-sizing:border-box}.share .brand{color:#ff783e;text-decoration:none;font-weight:700}.share h1{font-size:24px;margin-top:40px}.share p{color:#a69aaf}.share img{max-width:100%;max-height:75vh;object-fit:contain}
.made{margin-top:22px;color:#a69aaf;font-size:14px}.made strong{color:#ececf3}
.cta{display:inline-flex;align-items:center;gap:8px;margin-top:14px;background:#ff6b35;color:#fff;text-decoration:none;font-size:14px;font-weight:700;padding:12px 24px;border-radius:999px}.cta:hover{background:#f97316}</style>
