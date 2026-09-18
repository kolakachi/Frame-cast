<script setup>
import { ref } from 'vue'
import api from '../services/api'
const props=defineProps({ endpoint:String, field:String, current:{type:String,default:''}, context:{type:String,default:''}, disabled:Boolean })
const emit=defineEmits(['apply'])
const open=ref(false), notes=ref(''), draft=ref(''), busy=ref(false), error=ref('')
async function generate(){
  if(busy.value || notes.value.trim().length<3)return
  busy.value=true;error.value='';draft.value=''
  try{draft.value=(await api.post(`${props.endpoint}/draft-text`,{field:props.field,instruction:notes.value,current:props.current,context:props.context})).data.data.text}
  catch(e){error.value=e.response?.data?.message || e.response?.data?.error?.message || 'Could not write a draft. Please try again.'}
  finally{busy.value=false}
}
function apply(){emit('apply',draft.value);open.value=false;draft.value=''}
</script>
<template>
  <div class="ai-draft">
    <button type="button" class="ai-trigger" :disabled="disabled" @click="open=!open" :aria-expanded="open">✦ Write with AI</button>
    <div v-if="open" class="ai-panel">
      <label>What should AI include?<textarea v-model="notes" maxlength="3000" rows="2" placeholder="Add a few facts or rough notes, and tell us the tone you want." :disabled="busy" /></label>
      <p>Uses your client brief and these notes. Review the draft before using it; nothing is saved automatically.</p>
      <button type="button" :disabled="busy || disabled || notes.trim().length<3" @click="generate">{{busy?'Writing…':draft?'Try again':'Generate draft'}}</button>
      <p v-if="error" class="ai-error" role="alert">{{error}}</p>
      <template v-if="draft"><label>Review draft<textarea v-model="draft" rows="4" /></label><button type="button" :disabled="disabled || !draft.trim()" @click="apply">Use this draft</button></template>
      <button type="button" class="ai-cancel" @click="open=false">Close</button>
    </div>
  </div>
</template>
<style scoped>
.ai-draft{margin:-8px 0 20px}.ai-trigger{color:var(--color-accent,#ff6b35);border:0;background:transparent;padding:4px 0;font-size:12px;cursor:pointer}.ai-panel{padding:14px;margin-top:8px;border:1px solid var(--color-border,#41414e);border-radius:10px;background:var(--color-bg-panel,#252531)}label{display:block;font-size:12px;margin-bottom:10px}textarea{display:block;box-sizing:border-box;width:100%;margin-top:8px;padding:10px;background:var(--color-bg-sunken,#101017);color:inherit;border:1px solid var(--color-border,#41414e);border-radius:7px;font:inherit;resize:vertical}p{font-size:12px;line-height:1.5;color:var(--color-text-muted,#aaa)}button:not(.ai-trigger){padding:8px 12px;border:1px solid var(--color-border,#41414e);border-radius:7px;background:transparent;color:inherit;cursor:pointer;margin:0 8px 10px 0}button:disabled{opacity:.5;cursor:not-allowed}.ai-error{color:#fca5a5}
</style>
