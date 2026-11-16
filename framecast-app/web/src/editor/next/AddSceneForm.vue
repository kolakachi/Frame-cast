<script setup>
// Add a scene, as in the classic editor (owner, 2026-11-16: "maintain the same add new scene as in classic"): type,
// script (or draft it with AI), and where its picture comes from (stock video, stock image, AI with a character and
// a style, or the library). `after` is the scene it goes after; empty adds it at the end, as classic's "+ Add" does.
import { ref } from "vue";
import UiSelect from "../../components/UiSelect.vue";
import { cr, useCore, usePrices } from "./core";

const props = defineProps({ after: { type: [Number, String], default: null }, label: { type: String, default: "" } });
const core = useCore();
const {
  sceneTypeOptions, selectedSceneType, newSceneScript, addSceneVisualMode, selectAddSceneVisualMode, addSceneStockSubType,
  ADD_SCENE_STOCK_OPTIONS, addSceneVisualQuery, characters, addSceneCharacter, addSceneCharacterId, selectAddSceneCharacter,
  addSceneVisualStyle, stylePickerRows, imageStylesByKey, addSceneCustomVisualStyle, addScenePickedAsset, pickAssetForNewScene,
  closeAddScene, generateNewSceneDraft, addSceneGeneratePending, addSceneGenerateError, createScene, createCharacterOpen,
} = core;

const { imageCost } = usePrices(core);
const MODES = [["stock_video", "Video"], ["stock_image", "Image"], ["ai_image", "AI"], ["assets", "Assets"]];
const charOpen = ref(false);
const styleOpen = ref(false);
const adding = ref(false);
const thumb = (c) => c?.reference_asset?.thumbnail_url || c?.reference_asset?.storage_url || null;

async function add() {
  if (adding.value) return;
  adding.value = true;
  try { await createScene(props.after || null); } finally { adding.value = false; }
}
</script>

<template>
  <div class="nx-add" @click.stop>
    <div class="nx-row">
      <b class="nx-add-title">Add a new scene</b>
      <button class="nx-ib" type="button" aria-label="Close" @click="closeAddScene"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
    </div>
    <p v-if="label" class="nx-hint">{{ label }}</p>

    <span class="nx-label">Scene type</span>
    <div class="nx-chips">
      <button v-for="t in sceneTypeOptions" :key="t" type="button" :class="['nx-chip', selectedSceneType === t ? 'on' : '']" @click="selectedSceneType = t">{{ t }}</button>
    </div>
    <textarea v-model="newSceneScript" class="nx-input" rows="3" aria-label="Scene script" placeholder="Write the scene's script, or click Draft with AI…"></textarea>

    <span class="nx-label">Picture</span>
    <div class="nx-seg nx-seg-full" role="tablist" aria-label="Where the picture comes from">
      <button v-for="[m, l] in MODES" :key="m" type="button" role="tab" :aria-selected="addSceneVisualMode === m" :class="addSceneVisualMode === m ? 'on' : ''" @click="selectAddSceneVisualMode(m)">{{ l }}</button>
    </div>
    <p v-if="!addSceneVisualMode" class="nx-hint">No picture picked: the scene is added without one, and you choose it after.</p>

    <template v-if="addSceneVisualMode === 'stock_video'">
      <div class="nx-row"><span>Type</span><UiSelect v-model="addSceneStockSubType" :options="ADD_SCENE_STOCK_OPTIONS" label="Stock video type" /></div>
      <textarea v-model="addSceneVisualQuery" class="nx-input" rows="2" aria-label="Search for" placeholder="Search for: abandoned mansion at night. Leave blank to use the script."></textarea>
    </template>

    <template v-else-if="addSceneVisualMode === 'stock_image'">
      <textarea v-model="addSceneVisualQuery" class="nx-input" rows="2" aria-label="Search for" placeholder="Search for: dark forest with fog. Leave blank to use the script."></textarea>
    </template>

    <template v-else-if="addSceneVisualMode === 'ai_image'">
      <div class="nx-pop-wrap">
        <button type="button" class="nx-list-row nx-pick" :aria-expanded="charOpen" @click="charOpen = !charOpen; styleOpen = false">
          <span class="nx-pick-thumb"><img v-if="thumb(addSceneCharacter)" :src="thumb(addSceneCharacter)" alt="" /><template v-else>{{ addSceneCharacter ? addSceneCharacter.name.charAt(0).toUpperCase() : "◐" }}</template></span>
          <span class="nx-list-main"><b>{{ addSceneCharacter?.name || "No character" }}</b><span>{{ addSceneCharacter ? "Kept the same in this scene" : "Pick one to keep a person the same" }}</span></span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <div v-if="charOpen" class="nx-pop nx-pop-scroll">
          <p v-if="!characters.length" class="nx-hint">No characters yet.</p>
          <button v-for="c in characters" :key="c.id" type="button" :class="['nx-list-row', addSceneCharacterId === c.id ? 'on' : '']" @click="selectAddSceneCharacter(c.id); charOpen = false">
            <span class="nx-pick-thumb"><img v-if="thumb(c)" :src="thumb(c)" alt="" /><template v-else>{{ c.name.charAt(0).toUpperCase() }}</template></span>
            <span class="nx-list-main"><b>{{ c.name }}</b><span>{{ c.scenes_count > 0 ? `${c.scenes_count} scene${c.scenes_count === 1 ? "" : "s"}` : "new" }}</span></span>
          </button>
          <div class="nx-row">
            <button v-if="addSceneCharacterId" class="nx-link" type="button" @click="selectAddSceneCharacter(null); charOpen = false">No character</button><span v-else></span>
            <button class="nx-mini" type="button" @click="charOpen = false; createCharacterOpen = true">+ New character</button>
          </div>
        </div>
      </div>

      <span class="nx-label">Style</span>
      <div class="nx-pop-wrap">
        <button type="button" class="nx-list-row nx-pick" :aria-expanded="styleOpen" @click="styleOpen = !styleOpen; charOpen = false">
          <span class="nx-pick-thumb"><img v-if="imageStylesByKey.get(addSceneVisualStyle)?.sample_url" :src="imageStylesByKey.get(addSceneVisualStyle).sample_url" alt="" /><template v-else>✦</template></span>
          <span class="nx-list-main"><b>{{ imageStylesByKey.get(addSceneVisualStyle)?.label ?? "Pick a style" }}</b></span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </button>
        <div v-if="styleOpen" class="nx-pop nx-pop-scroll">
          <button v-for="st in stylePickerRows" :key="st.key" type="button" :class="['nx-list-row', addSceneVisualStyle === st.key ? 'on' : '']" @click="addSceneVisualStyle = st.key; styleOpen = false">
            <span class="nx-pick-thumb"><img v-if="st.sample_url" :src="st.sample_url" :alt="st.label" /><template v-else>✦</template></span>
            <span class="nx-list-main"><b>{{ st.label }}</b><span v-if="st.description">{{ st.description }}</span></span>
          </button>
        </div>
      </div>
      <template v-if="addSceneVisualStyle === 'custom'">
        <textarea v-model="addSceneCustomVisualStyle" class="nx-input" rows="2" maxlength="500" aria-label="Custom style" placeholder="Your own style: moody film stills, neon alleys, 35mm grain"></textarea>
        <p class="nx-hint">Leave blank to use the video's style.</p>
      </template>
      <textarea v-model="addSceneVisualQuery" class="nx-input" rows="2" maxlength="1000" aria-label="Prompt (optional)" placeholder="Prompt (optional). Leave blank to draw from the script."></textarea>
      <p class="nx-hint">The picture is drawn when you add the scene{{ imageCost != null ? `: ${imageCost} credits` : "" }}.</p>
    </template>

    <template v-else-if="addSceneVisualMode === 'assets'">
      <div v-if="addScenePickedAsset" class="nx-list-row nx-pick">
        <span class="nx-pick-thumb"><img v-if="addScenePickedAsset.thumbnail_url || (addScenePickedAsset.asset_type === 'image' && addScenePickedAsset.storage_url)" :src="addScenePickedAsset.thumbnail_url || addScenePickedAsset.storage_url" alt="" /></span>
        <span class="nx-list-main"><b>{{ addScenePickedAsset.title || "Selected" }}</b><span>{{ addScenePickedAsset.asset_type || "asset" }}</span></span>
        <button class="nx-mini" type="button" @click="pickAssetForNewScene">Replace</button>
      </div>
      <button v-else class="nx-btn nx-full" type="button" @click="pickAssetForNewScene">Pick from your library</button>
    </template>

    <p v-if="addSceneGenerateError" class="nx-err">{{ addSceneGenerateError }}</p>
    <div class="nx-row nx-end nx-add-actions">
      <button class="nx-btn" type="button" @click="closeAddScene">Cancel</button>
      <button class="nx-btn" type="button" :disabled="addSceneGeneratePending" @click="generateNewSceneDraft">{{ addSceneGeneratePending ? "Drafting…" : "Draft with AI" }}</button>
      <button class="nx-btn nx-primary" type="button" :disabled="adding" @click="add">{{ adding ? "Adding…" : `Add scene${addSceneVisualMode === "ai_image" ? cr(imageCost) : ""}` }}</button>
    </div>
  </div>
</template>
