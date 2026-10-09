<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import api from '../../services/api'
import CreateDialog from './CreateDialog.vue'

// The workspace library (2026-10-09), shared by Create's "Add from your library" and the dashboard's card modal.
// One pick: a tap picks the file (or character) at once. Several: tiles are ticked and "Add" hands them all back.
// Upload (when `accept` is set) offers new files from the computer alongside the library. A video without a poster
// shows its first frame.
const PER_PAGE = 9
const ALL = [['all', 'All'], ['video', 'Videos'], ['image', 'Images'], ['audio', 'Audio'], ['characters', 'Characters']]
const props = defineProps({
  open: Boolean, title: { type: String, default: 'Add from your library' }, filters: { type: Array, default: () => ['all', 'video', 'image', 'audio', 'characters'] },
  multiple: Boolean, max: { type: Number, default: 6 }, chosen: { type: Array, default: () => [] }, disabled: Boolean, accept: { type: String, default: '' },
  error: { type: String, default: '' }, emptyText: { type: String, default: 'No matching media.' }, characterNote: { type: String, default: 'Picking a character adds their name to your brief and attaches their saved photo.' },
})
const emit = defineEmits(['close', 'pick', 'character', 'done', 'files'])
const shown = computed(() => ALL.filter(([f]) => props.filters.includes(f)))
const types = computed(() => ['video', 'image', 'audio'].filter(t => props.filters.includes(t)))
const filter = ref('all'), search = ref(''), page = ref(1), lastPage = ref(1), assets = ref([]), characters = ref([]), problem = ref(''), loading = ref(false)
const picked = ref([]), fileInput = ref(null)
let epoch = 0

// Each pick, whether a file or a character, as one shape the caller can show and send.
const asItem = a => ({ key: 'a' + a.id, kind: 'asset', asset_id: a.id, name: a.title || a.asset_type, asset_type: a.asset_type, thumb: thumb(a) })
const asCharacter = c => ({ key: 'c' + c.id, kind: 'character', asset_id: c.reference_asset?.id, name: c.name, asset_type: 'image', thumb: c.reference_asset?.thumbnail_url || '' })
const thumb = a => a.asset_type === 'image' ? (a.thumbnail_url || a.storage_url || '') : (a.thumbnail_url || '')
const isPicked = item => picked.value.some(p => p.key === item.key)
// Characters come in one list and are paged here, nine at a time like files.
const pagedCharacters = computed(() => characters.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE))
const room = computed(() => props.max - picked.value.length)

watch(() => props.open, open => {
  if (!open) return
  search.value = ''; page.value = 1; filter.value = shown.value[0]?.[0] || 'all'; problem.value = ''
  picked.value = props.multiple ? props.chosen.filter(c => c.kind !== 'file').map(c => ({ ...c })) : []
  load()
}, { immediate: true })
onBeforeUnmount(() => { epoch++ })

function show(f) { if (filter.value === f) return; filter.value = f; page.value = 1; load() }
async function load() {
  const ticket = ++epoch
  loading.value = true; problem.value = ''
  try {
    // Characters are saved people, not files: their saved photo is what is attached.
    if (filter.value === 'characters') {
      const result = await api.get('/characters', { params: { q: search.value || undefined } })
      if (ticket !== epoch) return
      characters.value = (result.data.data.characters ?? []).filter(c => !props.multiple || c.reference_asset?.id)
      lastPage.value = Math.max(1, Math.ceil(characters.value.length / PER_PAGE))
      return
    }
    // The server filters (kinds, live files only), so every page holds PER_PAGE.
    const kinds = filter.value !== 'all' ? [filter.value] : types.value
    const result = await api.get('/assets', { params: { per_page: PER_PAGE, page: page.value, q: search.value || undefined, asset_types: kinds, live: 1 } })
    if (ticket !== epoch) return
    assets.value = result.data.data.assets ?? []
    lastPage.value = result.data.meta?.pagination?.last_page || 1
  } catch (e) { if (ticket === epoch) problem.value = e.response?.data?.error?.message || e.response?.data?.message || 'The library could not load. Try again.' }
  finally { if (ticket === epoch) loading.value = false }
}
function turn(step) { page.value += step; if (filter.value !== 'characters') load() }
function tapAsset(a) { if (props.multiple) toggle(asItem(a)); else emit('pick', a) }
function tapCharacter(c) { if (props.multiple) toggle(asCharacter(c)); else emit('character', c) }
function toggle(item) {
  if (isPicked(item)) picked.value = picked.value.filter(p => p.key !== item.key)
  else if (room.value > 0) picked.value.push(item)
}
function upload(list) { const files = Array.from(list || []); if (fileInput.value) fileInput.value.value = ''; if (files.length) emit('files', files) }
</script>

<template>
  <CreateDialog :open="open" :title="title" @close="emit('close')">
    <div v-if="accept" class="lp-upload">
      <button type="button" class="btn btn--ghost btn--sm" :disabled="disabled" @click="fileInput.click()">Upload from computer</button>
      <small>or pick something you have used before</small>
      <input ref="fileInput" type="file" :multiple="multiple" :accept="accept" hidden @change="upload($event.target.files)" @cancel.stop />
    </div>
    <form class="library-search" @submit.prevent="page = 1; load()"><input v-model="search" class="input" type="search" aria-label="Search library" :placeholder="filter === 'characters' ? 'Find a character…' : 'Find a ' + (types.length === 1 ? types[0] : types.join(', ').replace(/, ([^,]*)$/, ' or $1')) + ' file…'" /><button type="submit" class="btn btn--ghost btn--sm">Search</button></form>
    <div v-if="shown.length > 1" class="library-filters" role="group" aria-label="Show"><button v-for="[f, label] in shown" :key="f" type="button" :aria-pressed="filter === f" @click="show(f)">{{ label }}</button></div>
    <template v-if="filter === 'characters'">
      <div class="library-grid"><button v-for="c in pagedCharacters" :key="c.id" type="button" :class="{ on: multiple && isPicked(asCharacter(c)) }" :aria-pressed="multiple ? isPicked(asCharacter(c)) : undefined" :disabled="disabled || multiple && !isPicked(asCharacter(c)) && room <= 0" @click="tapCharacter(c)"><img v-if="c.reference_asset?.thumbnail_url" :src="c.reference_asset.thumbnail_url" alt="" /><span v-else class="file-symbol">☺</span><strong>{{ c.name }}</strong><small>character</small><i v-if="multiple && isPicked(asCharacter(c))" class="lp-tick" aria-hidden="true">✓</i></button></div>
      <p v-if="!characters.length && !loading" class="muted">No characters yet. Make one on the Characters page, then pick it here.</p>
      <p v-else-if="characterNote" class="muted">{{ characterNote }}</p>
    </template>
    <template v-else>
      <div class="library-grid"><button v-for="a in assets" :key="a.id" type="button" :class="{ on: multiple && isPicked(asItem(a)) }" :aria-pressed="multiple ? isPicked(asItem(a)) : undefined" :disabled="disabled || multiple && !isPicked(asItem(a)) && room <= 0" @click="tapAsset(a)"><img v-if="thumb(a)" :src="thumb(a)" alt="" loading="lazy" /><video v-else-if="a.asset_type === 'video' && a.storage_url" :src="a.storage_url + '#t=0.5'" preload="metadata" muted playsinline aria-hidden="true" /><span v-else class="file-symbol">{{ a.asset_type === 'video' ? '▷' : a.asset_type === 'image' ? '▢' : '♫' }}</span><strong>{{ a.title || a.asset_type }}</strong><small>{{ a.asset_type }}</small><i v-if="multiple && isPicked(asItem(a))" class="lp-tick" aria-hidden="true">✓</i></button></div>
      <p v-if="!assets.length && !loading" class="muted">{{ emptyText }}</p>
    </template>
    <div v-if="lastPage > 1" class="row-actions"><button type="button" class="btn btn--ghost btn--sm" :disabled="page <= 1" @click="turn(-1)">Previous</button><span>{{ page }} / {{ lastPage }}</span><button type="button" class="btn btn--ghost btn--sm" :disabled="page >= lastPage" @click="turn(1)">Next</button></div>
    <p v-if="problem || error" class="create-error" role="alert">{{ problem || error }}</p>
    <div v-if="multiple" class="lp-foot"><small>{{ picked.length ? picked.length + ' chosen' : 'Tap to choose' }} · up to {{ max }}</small><button type="button" class="btn btn--primary btn--sm" @click="emit('done', picked)">{{ picked.length ? 'Add ' + picked.length : 'Done' }}</button></div>
  </CreateDialog>
</template>

<style scoped>
.lp-upload{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.lp-upload small{color:var(--text-3,#8f95a1);font-size:12.5px}
.library-search{display:flex;gap:8px;align-items:center;margin-bottom:15px}
.library-filters{display:flex;flex-wrap:wrap;gap:6px;margin:-4px 0 4px}
.library-filters button{border:1px solid var(--line-3,#2c313b);border-radius:999px;background:transparent;color:var(--text-2,#b7bcc6);font:inherit;font-size:12.5px;font-weight:600;padding:6px 12px;cursor:pointer}
.library-filters button[aria-pressed="true"]{color:var(--accent,#ff6b35);border-color:color-mix(in srgb,var(--accent,#ff6b35) 45%,transparent);background:color-mix(in srgb,var(--accent,#ff6b35) 12%,transparent)}
.library-filters button:focus-visible{outline:2px solid var(--accent,#ff6b35);outline-offset:2px}
.library-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}
.library-grid button{position:relative;text-align:left;min-width:0;padding:10px;border:1px solid var(--line-2,#262b34);border-radius:var(--r-md,12px);background:var(--bg-3,#14171d);color:inherit;cursor:pointer}
.library-grid button.on{border-color:var(--accent,#ff6b35);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent,#ff6b35) 18%,transparent)}
.library-grid button:disabled{cursor:default;opacity:.55}
.library-grid img,.library-grid video,.library-grid .file-symbol{display:grid;place-items:center;height:85px;width:100%;object-fit:contain;background:var(--bg-2,#0f1116);border-radius:6px;margin-bottom:8px}
.library-grid strong{font-size:11px;font-weight:500;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.library-grid small{font-size:10px;color:var(--text-3,#8f95a1)}
.lp-tick{position:absolute;top:6px;right:6px;width:22px;height:22px;border-radius:50%;background:var(--accent,#ff6b35);color:#1a0d06;font:700 12px/22px system-ui;text-align:center;font-style:normal}
.row-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.muted{color:var(--text-3,#8f95a1);font-size:13px}
.create-error{color:#f4bba9;padding:10px 14px;background:#352322;border-radius:10px;font-size:12px;line-height:1.6;margin:0 0 8px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid transparent;font:inherit;font-weight:700;cursor:pointer}
.btn:disabled{opacity:.5;cursor:default}
.btn--primary{background:var(--accent,#ff6b35);color:var(--accent-ink,#0b0d11)}
.btn--ghost{background:transparent;border-color:var(--line-3,#2c313b);color:var(--text-2,#b7bcc6);font-weight:600}.btn--ghost:hover:not(:disabled){color:var(--text,#eceef1);background:var(--bg-4,#191d24)}
.btn--sm{padding:7px 11px;font-size:12px;border-radius:7px}
.input{width:100%;padding:9px 11px;border:1px solid var(--line-3,#2c313b);border-radius:var(--r,8px);background:var(--bg-3,#14171d);color:var(--text,#eceef1);font:inherit;font-size:13px}
.lp-foot{position:sticky;bottom:-22px;display:flex;justify-content:space-between;align-items:center;gap:10px;margin:0 -22px -22px;padding:14px 22px;background:var(--color-bg-secondary,#17171f);border-top:1px solid #ffffff12}
.lp-foot small{color:var(--text-3,#8f95a1);font-size:12.5px}
@media (max-width:560px){.library-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
