<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

// The UGC card's presenter choice (2026-10-09): Auto, or one of the workspace's characters, each shown by its photo.
const props = defineProps({ modelValue: { type: String, default: '' }, characters: { type: Array, default: () => [] } })
const emit = defineEmits(['update:modelValue'])
const open = ref(false), root = ref(null)
const chosen = computed(() => props.characters.find(c => String(c.id) === props.modelValue) || null)
const thumb = c => c?.reference_asset?.thumbnail_url || ''
function pick(value) { emit('update:modelValue', value); open.value = false }
function outside(e) { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('pointerdown', outside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', outside))
</script>

<template>
  <div ref="root" class="cp">
    <button type="button" class="cp-trigger" :aria-expanded="open" aria-haspopup="listbox" @click="open = !open">
      <span class="cp-av"><img v-if="thumb(chosen)" :src="thumb(chosen)" alt="" /><template v-else>☺</template></span>
      <span class="cp-grow"><b>{{ chosen ? chosen.name : 'Auto' }}</b><small>{{ chosen ? 'Your character, in their saved look' : 'A presenter that suits your audience is chosen for you' }}</small></span>
      <span class="cp-btn">{{ open ? 'Close' : chosen ? 'Change' : '+ Choose a character' }}</span>
    </button>
    <div v-if="open" class="cp-menu" role="listbox" aria-label="Characters">
      <button type="button" :class="['cp-card', { on: !modelValue }]" role="option" :aria-selected="!modelValue" @click="pick('')"><span class="cp-pic cp-pic--auto">✦</span><b>Auto</b><small>Chosen for you</small></button>
      <button v-for="c in characters" :key="c.id" type="button" :class="['cp-card', { on: modelValue === String(c.id) }]" role="option" :aria-selected="modelValue === String(c.id)" @click="pick(String(c.id))">
        <span class="cp-pic"><img v-if="thumb(c)" :src="thumb(c)" alt="" loading="lazy" /></span><b>{{ c.name }}</b><small>{{ c.is_stock ? 'Ready-made' : 'Yours' }}</small>
      </button>
      <p v-if="!characters.length" class="cp-empty">No characters yet. Add one on the Characters page, or leave it on Auto.</p>
    </div>
  </div>
</template>

<style scoped>
.cp{position:relative}
.cp-trigger{width:100%;display:flex;align-items:center;gap:12px;border:1px dashed #2c313b;border-radius:14px;padding:12px 14px;background:transparent;color:#eceef1;text-align:left;font:inherit;cursor:pointer}
.cp-trigger:hover{border-color:#3a404c}
.cp-av{width:42px;height:42px;border-radius:50%;background:#191d24;display:grid;place-items:center;color:#8f95a1;flex:0 0 42px;overflow:hidden}
.cp-av img{width:100%;height:100%;object-fit:cover}
.cp-grow{flex:1;min-width:0;display:flex;flex-direction:column}
.cp-grow b{font-size:14px}
.cp-grow small{color:#8f95a1;font-size:12.5px}
.cp-btn{border:1px solid #2c313b;background:#191d24;border-radius:999px;padding:8px 14px;font-weight:600;font-size:13px;white-space:nowrap}
.cp-menu{position:absolute;z-index:5;left:0;right:0;top:calc(100% + 6px);max-height:340px;overflow:auto;background:#14171d;border:1px solid #2c313b;border-radius:14px;padding:10px;box-shadow:0 24px 60px -20px rgba(0,0,0,.85);display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px}
.cp-card{display:flex;flex-direction:column;gap:3px;align-items:flex-start;border:1px solid #262b34;border-radius:12px;padding:6px;background:#0f1116;color:#eceef1;font:inherit;text-align:left;cursor:pointer}
.cp-card:hover{border-color:#3a404c}
.cp-card.on{border-color:#ff6b35;box-shadow:0 0 0 3px rgba(255,107,53,.13)}
.cp-pic{width:100%;aspect-ratio:1;border-radius:8px;overflow:hidden;background:#191d24;display:grid;place-items:center;color:#ff6b35}
.cp-pic img{width:100%;height:100%;object-fit:cover}
.cp-card b{font-size:12.5px;line-height:1.25}
.cp-card small{color:#8f95a1;font-size:11px}
.cp-empty{grid-column:1/-1;margin:4px;color:#8f95a1;font-size:13px}
.cp button:focus-visible{outline:2px solid #ff6b35;outline-offset:2px}
</style>
