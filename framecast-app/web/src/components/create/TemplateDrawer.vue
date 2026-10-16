<script setup>
import { computed, ref, watch } from 'vue'
import api from '../../services/api'
import SideDrawer from './SideDrawer.vue'
import UiSelect from '../UiSelect.vue'
// Staff: "Add to templates" on a finished version, or edit a template (Templates, 2026-10-16; mockup v2 staff.html).
// The version's parts (text, colours, pictures, voice, music) are read on the server; staff name and place it.
const props = defineProps({
  open: Boolean,
  conversationId: { type: String, default: '' },
  revision: { type: Object, default: null }, // { id, number } when adding
  template: { type: Object, default: null }, // the template when editing
  poster: { type: String, default: '' },
  video: { type: String, default: '' }, // the version's video, its first frame as the picture when there is no poster
})
const emit = defineEmits(['close', 'saved'])
const CATEGORIES = { offer: 'Offer ad', app: 'App showcase', explainer: 'Explainer', ugc: 'UGC ad', announce: 'Announcement', footage: 'From your footage' }
const categoryOptions = Object.entries(CATEGORIES).map(([value, label]) => ({ value, label }))
const form = ref({ title: '', category: 'offer', description: '', ask_hint: '' })
const draft = ref(null), loading = ref(false), saving = ref(false), error = ref('')
const editing = computed(() => !!props.template)
const parts = computed(() => editing.value ? (props.template.parts || []) : (draft.value?.summary || []))
const style = computed(() => editing.value ? props.template.style : draft.value?.parts?.style)
const meta = computed(() => editing.value ? 'Staff only until Templates is public' : props.revision ? `Version ${props.revision.number} · staff only` : '')

watch(() => props.open, async open => {
  if (!open) return
  error.value = ''
  if (editing.value) {
    const t = props.template
    form.value = { title: t.title, category: t.category, description: t.description || '', ask_hint: t.ask_hint || '' }
    return
  }
  loading.value = true; draft.value = null
  try {
    draft.value = (await api.get(`/create/conversations/${props.conversationId}/revisions/${props.revision.id}/template-draft`)).data.data
    form.value = { title: draft.value.title || '', category: 'offer', description: '', ask_hint: '' }
  } catch (e) { error.value = e?.response?.data?.message || 'Could not read this version.' }
  finally { loading.value = false }
})

async function save() {
  if (!form.value.title.trim()) { error.value = 'Give the template a title.'; return }
  saving.value = true; error.value = ''
  try {
    const body = { title: form.value.title.trim(), category: form.value.category, description: form.value.description.trim() || null, ask_hint: form.value.ask_hint.trim() || null }
    const saved = editing.value
      ? (await api.patch(`/create/templates/${props.template.id}`, body)).data.data
      : (await api.post('/create/templates', { ...body, conversation_id: props.conversationId, revision_id: props.revision.id })).data.data
    emit('saved', saved)
  } catch (e) { error.value = e?.response?.data?.message || 'Could not save the template.' }
  finally { saving.value = false }
}
</script>

<template>
  <SideDrawer :open="open" :title="editing ? 'Edit template' : 'Add to templates'" :meta="meta" @close="emit('close')">
    <p v-if="loading" class="td-muted">Reading this version…</p>
    <template v-else>
      <p v-if="draft?.existing_id" class="td-note">This version is already a template. Adding it again makes a second one.</p>
      <div class="td-version">
        <div class="td-thumb"><img v-if="poster" :src="poster" alt="" /><video v-else-if="video" :src="video" muted playsinline preload="metadata" /></div>
        <div>
          <strong>{{ editing ? template.title : `Version ${revision?.number}` }}</strong>
          <small v-if="style">{{ style }}</small>
          <small v-if="parts.length">{{ parts.join(' · ') }}</small>
          <small>Kept: the video, style, shape and structure. Each user's product and words replace this one's.</small>
        </div>
      </div>
      <label class="td-group"><span>Title</span><input v-model="form.title" class="td-in" maxlength="120" /></label>
      <div class="td-group"><span id="td-cat">Category</span>
        <UiSelect v-model="form.category" :options="categoryOptions" label="Category" align="left" class="td-select" />
      </div>
      <label class="td-group"><span>Description on the template</span>
        <textarea v-model="form.description" class="td-in" rows="4" maxlength="600" placeholder="What the video does, in a sentence or two." /></label>
      <label class="td-group"><span>What the user is asked for</span>
        <textarea v-model="form.ask_hint" class="td-in" rows="2" maxlength="300" placeholder="A link, or a few words about it. e.g. yourshop.com/ridge-2, launch week 20% off" />
        <small class="td-hint">Shown as the placeholder in “Your product” on the template page.</small></label>
      <p v-if="error" class="td-error" role="alert">{{ error }}</p>
    </template>
    <template #footer>
      <div class="td-foot">
        <p class="td-muted">Only staff see Templates until it is made public.</p>
        <div class="td-buttons">
          <button type="button" class="td-btn" @click="emit('close')">Cancel</button>
          <button type="button" class="td-btn td-btn--primary" :disabled="saving || loading" @click="save">{{ saving ? 'Saving…' : editing ? 'Save' : 'Add template' }}</button>
        </div>
      </div>
    </template>
  </SideDrawer>
</template>

<style scoped>
.td-version{display:grid;grid-template-columns:64px 1fr;gap:12px;padding:10px;margin:8px 0 14px;border:1px solid rgba(255,107,53,.45);border-radius:12px;background:var(--bg-3,#1d2129)}
.td-thumb{width:64px;aspect-ratio:9/16;border-radius:7px;overflow:hidden;background:var(--bg-2,#151820)}
.td-thumb img,.td-thumb video{width:100%;height:100%;object-fit:cover;display:block}
.td-version strong{display:block;font-size:13px}
.td-version small{display:block;color:var(--text-3,#8f95a1);font-size:12px;line-height:1.5}
.td-group{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
.td-group>span{font:600 11px/1.4 var(--mono,ui-monospace,monospace);letter-spacing:.08em;text-transform:uppercase;color:var(--text-3,#8f95a1)}
.td-in{width:100%;box-sizing:border-box;border:1px solid var(--line-3,#2c313b);border-radius:8px;background:var(--bg-2,#151820);color:var(--text,#eceef1);padding:8px 9px;font:13px/1.45 inherit;resize:none}
.td-in:focus{outline:none;border-color:rgba(255,107,53,.6)}
.td-select{display:block}
.td-select :deep(.ui-select-trigger){width:100%;min-height:36px;font-size:13px;background:var(--bg-2,#151820);border-color:var(--line-3,#2c313b)}
.td-hint{color:var(--text-3,#8f95a1);font-size:12px}
.td-note{margin:4px 0 10px;padding:8px 10px;border-radius:8px;background:rgba(255,107,53,.08);color:#ff9a6b;font-size:12.5px}
.td-error{color:#ff8f8f;font-size:12.5px;margin:0}
.td-muted{margin:0;color:var(--text-3,#8f95a1);font-size:12px}
.td-foot{display:flex;flex-direction:column;gap:8px;width:100%}
.td-buttons{display:flex;justify-content:flex-end;gap:8px}
.td-btn{min-height:36px;padding:0 14px;border-radius:8px;border:1px solid var(--line-3,#2c313b);background:transparent;color:var(--text,#eceef1);font:inherit;font-size:13px;cursor:pointer}
.td-btn--primary{border:0;background:#ff6b35;color:#1a0a03;font-weight:600}
.td-btn:disabled{opacity:.6;cursor:default}
</style>
