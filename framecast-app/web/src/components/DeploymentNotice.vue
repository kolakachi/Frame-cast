<script setup>
import { deploymentNotice, deploymentRecovery } from '../services/deploymentRecovery.js'
</script>

<template>
  <aside v-if="deploymentNotice.visible" class="deployment-notice" role="alert" aria-label="Page recovery">
    <strong>Part of this page could not load.</strong>
    <p>There may be an update or a connection problem. Save any edits before reloading.</p>
    <p v-if="deploymentNotice.reason">{{ deploymentNotice.reason }}</p>
    <button type="button" @click="deploymentRecovery.refresh()">Reload page</button>
  </aside>
</template>

<style scoped>
.deployment-notice{position:fixed;bottom:20px;right:20px;z-index:20000;width:min(380px,calc(100vw - 40px));box-sizing:border-box;padding:18px;border:1px solid #586174;border-radius:12px;background:#171c26;color:#f5f6f8;box-shadow:0 8px 32px #0005;font-size:14px;line-height:1.5}
p{margin:8px 0}
button{margin-top:4px;background:#f5f6f8;color:#171c26;border:0;border-radius:6px;padding:8px 14px;font:inherit;cursor:pointer}
button:focus-visible{outline:3px solid #ff863c;outline-offset:3px}
</style>
