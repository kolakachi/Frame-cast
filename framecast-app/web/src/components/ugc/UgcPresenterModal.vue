<script setup>
// Every presenter in one window (owner, 2026-10-11): the prompt box shows a few faces and a More card that opens this.
// Browse and filter the actors and your characters, look at one up close, choose where they are filmed, add or remove.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import UgcBackgroundPicker from './UgcBackgroundPicker.vue'

const props = defineProps({
  stock: { type: Array, default: () => [] },
  mine: { type: Array, default: () => [] },
  presenters: { type: Array, default: () => [] },
  settings: { type: Array, default: () => [] },
  // The character opened first, when the window is opened from a face.
  focus: { type: Object, default: null },
  // The page's latest error (a plan limit, five presenters at most), shown here so it is not hidden behind the window.
  error: { type: String, default: '' },
})
const emit = defineEmits(['close', 'add', 'remove', 'set-setting', 'pick-for-me', 'upload'])

const AGE = { young_adult: 'Young adult', adult: 'Adult', senior: 'Senior' }
const imageOf = c => c?.reference_asset?.thumbnail_url || ''
const search = ref('')
const gender = ref('')
const age = ref('')
const source = ref('actors')
const viewing = ref(props.focus)
// The place picked for someone not added yet; it goes with them when they are added.
const pending = ref({})

const rows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (source.value === 'mine' ? props.mine : props.stock).filter(c =>
    (!gender.value || c.gender === gender.value) && (!age.value || c.age_group === age.value)
    && (!q || `${c.name} ${c.description || ''} ${(c.situations || []).join(' ')}`.toLowerCase().includes(q)))
})
const chosen = id => props.presenters.find(p => p.character_id === id) || null
const tags = c => [c.is_stock ? 'Actor' : 'Your character', AGE[c.age_group], c.gender ? c.gender[0].toUpperCase() + c.gender.slice(1) : '', ...(c.situations || []).slice(0, 3)].filter(Boolean)
const place = computed({
  get: () => viewing.value ? (chosen(viewing.value.id)?.setting ?? pending.value[viewing.value.id] ?? null) : null,
  set: key => {
    const c = viewing.value
    if (!c) return
    if (chosen(c.id)) emit('set-setting', { character_id: c.id, setting: key })
    else pending.value = { ...pending.value, [c.id]: key }
  },
})
const count = computed(() => props.presenters.length)

function open(c) { viewing.value = c }
function toggle(c) {
  if (chosen(c.id)) emit('remove', c.id)
  else emit('add', { character: c, setting: pending.value[c.id] ?? null })
}
function onKey(e) { if (e.key === 'Escape') { if (viewing.value && window.matchMedia('(max-width: 860px)').matches) viewing.value = null; else emit('close') } }
watch(source, () => { viewing.value = null })
onMounted(() => document.addEventListener('keydown', onKey))
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div class="upm-overlay" @mousedown.self="emit('close')">
      <div class="upm" role="dialog" aria-modal="true" aria-label="Presenters">
        <header class="upm-head">
          <div class="upm-title">
            <h2>Presenters</h2>
            <button type="button" class="upm-x" aria-label="Close" @click="emit('close')">×</button>
          </div>
          <div class="upm-tools">
            <input v-model="search" class="upm-search" placeholder="Search by name, look or place" aria-label="Search presenters" />
            <div class="upm-chips" role="group" aria-label="Show">
              <button v-if="mine.length" type="button" :class="['upm-chip', { on: source === 'actors' }]" @click="source = 'actors'">Actors</button>
              <button v-if="mine.length" type="button" :class="['upm-chip', { on: source === 'mine' }]" @click="source = 'mine'">Your characters</button>
              <span v-if="mine.length" class="upm-sep" aria-hidden="true"></span>
              <button v-for="[k, l] in [['', 'Everyone'], ['female', 'Women'], ['male', 'Men']]" :key="'g' + k" type="button" :class="['upm-chip', { on: gender === k }]" @click="gender = k">{{ l }}</button>
              <span class="upm-sep" aria-hidden="true"></span>
              <button v-for="[k, l] in [['', 'Any age'], ['young_adult', 'Young adult'], ['adult', 'Adult'], ['senior', 'Senior']]" :key="'a' + k" type="button" :class="['upm-chip', { on: age === k }]" @click="age = k">{{ l }}</button>
            </div>
          </div>
        </header>

        <div class="upm-body">
          <div class="upm-grid">
            <button type="button" class="upm-card upm-card--action" @click="emit('pick-for-me')">
              <span class="upm-action-i">✦</span><b>Let us pick</b><small>Who fits your product</small>
            </button>
            <button type="button" class="upm-card upm-card--action" @click="emit('upload')">
              <span class="upm-action-i">⇪</span><b>Upload a photo</b><small>You, or someone you have the rights to</small>
            </button>
            <button v-for="c in rows" :key="c.id" type="button" :class="['upm-card', { on: !!chosen(c.id), viewing: viewing?.id === c.id }]" :aria-pressed="!!chosen(c.id)" @click="open(c)">
              <img v-if="imageOf(c)" :src="imageOf(c)" :alt="c.name" loading="lazy" />
              <span v-else class="upm-none">{{ c.name.slice(0, 1) }}</span>
              <span class="upm-name">{{ c.name }}</span>
              <span v-if="chosen(c.id)" class="upm-tick" aria-label="Added">✓</span>
            </button>
            <p v-if="!rows.length" class="upm-empty">No one matches. Try another filter.</p>
          </div>

          <aside v-if="viewing" class="upm-detail" aria-label="Presenter details">
            <button type="button" class="upm-back" @click="viewing = null">‹ All presenters</button>
            <img v-if="imageOf(viewing)" :src="imageOf(viewing)" :alt="viewing.name" class="upm-photo" />
            <div class="upm-detail-t">
              <b>{{ viewing.name }}</b>
              <p v-if="viewing.description">{{ viewing.description }}</p>
              <span class="upm-tags"><span v-for="t in tags(viewing)" :key="t">{{ t }}</span></span>
            </div>
            <div v-if="settings.length" class="upm-place">
              <span class="upm-label">Background</span>
              <small class="upm-muted">The kind of place. We choose the exact spot and light.</small>
              <UgcBackgroundPicker v-model="place" :settings="settings" />
            </div>
            <button type="button" :class="['upm-add', { on: !!chosen(viewing.id) }]" @click="toggle(viewing)">
              {{ chosen(viewing.id) ? `Remove ${viewing.name}` : `Add ${viewing.name}` }}
            </button>
          </aside>
          <aside v-else class="upm-detail upm-detail--hint" aria-hidden="true">
            <p>Pick someone to see them up close and choose where they are filmed.</p>
          </aside>
        </div>

        <footer class="upm-foot">
          <span class="upm-faces">
            <img v-for="p in presenters.filter(x => x.image)" :key="p.key" :src="p.image" :alt="p.name" />
          </span>
          <span v-if="error" class="upm-error" role="alert">{{ error }}</span>
          <span v-else class="upm-muted">{{ count ? (count > 1 ? `${count} presenters · one video each` : '1 presenter · one video') : 'None chosen: we pick who fits' }}</span>
          <button type="button" class="upm-done" @click="emit('close')">Done</button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.upm-overlay { position: fixed; inset: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; padding: 24px; background: rgba(0, 0, 0, .72); }
.upm { width: 100%; max-width: 1040px; height: min(760px, 92vh); display: flex; flex-direction: column; overflow: hidden; border-radius: 14px; border: 1px solid var(--color-border-active); background: var(--color-bg-panel); box-shadow: 0 32px 80px rgba(0, 0, 0, .6); }
.upm-head { padding: 16px 20px 12px; border-bottom: 1px solid var(--color-border); display: grid; gap: 12px; }
.upm-title { display: flex; align-items: center; justify-content: space-between; }
.upm-title h2 { margin: 0; font-size: 17px; }
.upm-x { width: 32px; height: 32px; border-radius: 8px; border: 0; background: transparent; color: var(--color-text-secondary); font-size: 22px; cursor: pointer; }
.upm-x:hover { background: var(--color-bg-elevated); color: var(--color-text-primary); }
.upm-tools { display: grid; gap: 10px; }
.upm-search { font: inherit; font-size: 13px; padding: 8px 11px; border-radius: 8px; border: 1px solid var(--color-border-active); background: var(--color-bg-soft); color: var(--color-text-primary); }
.upm-chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.upm-chip { font: inherit; font-size: 12px; padding: 5px 10px; border-radius: 999px; border: 1px solid var(--color-border-active); background: transparent; color: var(--color-text-secondary); cursor: pointer; }
.upm-chip.on { border-color: var(--color-accent); color: var(--color-accent); background: rgba(255, 107, 53, .1); }
.upm-sep { width: 1px; height: 18px; background: var(--color-border-active); margin: 0 2px; }
.upm-body { flex: 1; min-height: 0; display: grid; grid-template-columns: 1fr 320px; }
/* Rows as tall as their cards: a clipped card with a fixed shape has no minimum height, so the grid squeezed the rows
   to the window and the cards overlapped (owner's screenshot, 2026-10-11). */
.upm-grid { overflow-y: auto; padding: 16px 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); grid-auto-rows: max-content; gap: 10px; align-content: start; }
.upm-card { position: relative; padding: 0; border-radius: 10px; border: 1px solid var(--color-border-active); background: var(--color-bg-soft); color: var(--color-text-primary); cursor: pointer; overflow: hidden; aspect-ratio: 9 / 14; font: inherit; }
.upm-card img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
.upm-card:hover, .upm-card.viewing { border-color: var(--color-accent); }
.upm-card.on { box-shadow: 0 0 0 2px var(--color-accent); border-color: var(--color-accent); }
.upm-name { position: absolute; left: 0; right: 0; bottom: 0; padding: 18px 8px 6px; font-size: 12.5px; font-weight: 600; text-align: left; color: #fff; background: linear-gradient(transparent, rgba(0, 0, 0, .78)); }
.upm-tick { position: absolute; top: 6px; right: 6px; width: 22px; height: 22px; border-radius: 50%; background: var(--color-accent); color: #fff; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; }
.upm-none { display: flex; align-items: center; justify-content: center; height: 100%; font-size: 30px; color: var(--color-text-muted); }
.upm-card--action { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 10px; border-style: dashed; text-align: center; }
.upm-card--action b { font-size: 13px; }
.upm-card--action small { font-size: 11px; color: var(--color-text-secondary); line-height: 1.3; }
.upm-action-i { font-size: 20px; color: var(--color-accent); }
.upm-empty { grid-column: 1 / -1; color: var(--color-text-secondary); font-size: 13px; }
.upm-detail { border-left: 1px solid var(--color-border); overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; }
.upm-detail > * { flex-shrink: 0; }
.upm-detail--hint { align-items: center; justify-content: center; text-align: center; color: var(--color-text-secondary); font-size: 13px; padding: 24px; }
.upm-back { display: none; }
.upm-photo { width: 100%; aspect-ratio: 3 / 4; object-fit: cover; border-radius: 10px; }
.upm-detail-t { display: grid; gap: 6px; }
.upm-detail-t b { font-size: 16px; }
.upm-detail-t p { margin: 0; font-size: 12.5px; line-height: 1.45; color: var(--color-text-secondary); }
.upm-detail-t p::first-letter { text-transform: uppercase; }
.upm-tags { display: flex; flex-wrap: wrap; gap: 4px; }
.upm-tags span { font-size: 11px; padding: 2px 7px; border-radius: 999px; border: 1px solid var(--color-border-active); color: var(--color-text-secondary); text-transform: capitalize; }
.upm-place { display: grid; gap: 6px; }
.upm-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--color-text-secondary); }
.upm-muted { font-size: 12px; color: var(--color-text-secondary); }
.upm-add { font: inherit; font-size: 14px; font-weight: 600; padding: 10px 14px; border-radius: 9px; border: 1px solid var(--color-accent); background: var(--color-accent); color: #fff; cursor: pointer; }
.upm-add.on { background: transparent; color: var(--color-text-primary); border-color: var(--color-border-active); }
.upm-foot { display: flex; align-items: center; gap: 12px; padding: 12px 20px; border-top: 1px solid var(--color-border); }
.upm-faces { display: flex; }
.upm-faces img { width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 2px solid var(--color-bg-panel); margin-left: -8px; }
.upm-faces img:first-child { margin-left: 0; }
.upm-error { font-size: 12.5px; color: #ff8a65; }
.upm-done { margin-left: auto; font: inherit; font-size: 13.5px; font-weight: 600; padding: 8px 18px; border-radius: 9px; border: 0; background: var(--color-accent); color: #fff; cursor: pointer; }

@media (max-width: 860px) {
  .upm-overlay { padding: 0; }
  .upm { height: 100dvh; max-width: none; border: 0; border-radius: 0; }
  .upm-head { padding: calc(12px + env(safe-area-inset-top)) 16px 10px; }
  .upm-chips { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; }
  .upm-chip { flex: 0 0 auto; }
  .upm-body { grid-template-columns: 1fr; position: relative; }
  .upm-grid { padding: 12px 16px; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); }
  .upm-detail--hint { display: none; }
  .upm-detail { position: absolute; inset: 0; border-left: 0; background: var(--color-bg-panel); }
  .upm-back { display: inline-flex; align-self: flex-start; font: inherit; font-size: 13px; padding: 4px 0; border: 0; background: transparent; color: var(--color-accent); cursor: pointer; }
  .upm-photo { max-height: 46dvh; object-fit: cover; }
  .upm-foot { padding: 10px 16px calc(10px + env(safe-area-inset-bottom)); }
}
</style>
