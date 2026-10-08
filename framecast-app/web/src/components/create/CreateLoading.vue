<script setup>
// While Create opens (the first load, or a conversation being fetched): the outline of a conversation, so the page
// never flashes the empty "What are we making?" screen before the real one arrives.
defineProps({ label: { type: String, default: 'Opening your creation…' } })
</script>
<template>
  <div class="cl" role="status" aria-live="polite" :aria-label="label">
    <div class="cl-msg cl-user"><i class="cl-line" style="width: 70%" /><i class="cl-line" style="width: 45%" /></div>
    <div class="cl-msg cl-agent"><i class="cl-line" style="width: 85%" /><i class="cl-line" style="width: 60%" /></div>
    <div class="cl-card">
      <div class="cl-row"><i class="cl-pill" /><i class="cl-pill" /><i class="cl-pill" /></div>
      <i class="cl-line" style="width: 90%" /><i class="cl-line" style="width: 75%" />
      <div class="cl-row cl-end"><i class="cl-btn" /></div>
    </div>
    <div class="cl-composer"><i class="cl-line" style="width: 40%" /></div>
    <span class="cl-sr">{{ label }}</span>
  </div>
</template>
<style scoped>
.cl{display:flex;flex-direction:column;gap:14px;max-width:760px;width:100%;margin:28px auto 0;padding:0 16px}
.cl-msg{display:flex;flex-direction:column;gap:8px;padding:12px 14px;border-radius:var(--r-lg,12px);max-width:72%}
.cl-user{align-self:flex-end;background:var(--bg-4,#191d24);width:52%}
.cl-agent{align-self:flex-start;background:transparent;border:1px solid var(--line-2,#262b34);width:64%}
.cl-card{display:flex;flex-direction:column;gap:10px;padding:14px;border:1px solid var(--line-2,#262b34);border-radius:var(--r-lg,12px);background:var(--bg-3,#14171d)}
.cl-row{display:flex;gap:8px}
.cl-end{justify-content:flex-end}
.cl-composer{margin-top:10px;padding:14px;border:1px solid var(--line-3,#2c313b);border-radius:var(--r-lg,12px);background:var(--bg-2,#0f1116)}
.cl-line,.cl-pill,.cl-btn{display:block;border-radius:6px;background:linear-gradient(90deg,var(--bg-4,#191d24) 0%,var(--line-3,#2c313b) 50%,var(--bg-4,#191d24) 100%);background-size:200% 100%;animation:cl-shine 1.4s ease-in-out infinite}
.cl-line{height:10px}
.cl-pill{height:20px;width:64px;border-radius:999px}
.cl-btn{height:30px;width:120px;border-radius:8px}
.cl-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
@keyframes cl-shine{0%{background-position:100% 0}100%{background-position:-100% 0}}
@media (prefers-reduced-motion: reduce){.cl-line,.cl-pill,.cl-btn{animation:none}}
@media (max-width:640px){.cl-msg{max-width:90%}.cl-user{width:80%}.cl-agent{width:88%}}
</style>
