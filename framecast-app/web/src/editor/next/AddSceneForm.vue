<script setup>
// Add a scene (owner, 2026-11-16): classic's form, in its own modal so it has room: what the scene is and says on the
// left, where its picture comes from on the right. `after` is the scene it goes after; empty adds it at the end, as
// classic's "+ Add" does.
import { ref } from "vue";
import UiSelect from "../../components/UiSelect.vue";
import { cr, useCore, usePrices } from "./core";

const props = defineProps({ after: { type: [Number, String], default: null }, label: { type: String, default: "" } });
const core = useCore();
const {
  sceneTypeOptions, selectedSceneType, newSceneScript, addSceneVisualMode, selectAddSceneVisualMode, addSceneStockSubType,
  ADD_SCENE_STOCK_OPTIONS, addSceneVisualQuery, characters, addSceneCharacterId, selectAddSceneCharacter,
  addSceneVisualStyle, stylePickerRows, addSceneCustomVisualStyle, addScenePickedAsset, pickAssetForNewScene,
  closeAddScene, generateNewSceneDraft, addSceneGeneratePending, addSceneGenerateError, createScene, createCharacterOpen,
} = core;
const { imageCost } = usePrices(core);

const MODES = [["stock_video", "Stock video", "Footage found for the scene"], ["stock_image", "Stock image", "A photo found for the scene"],
  ["ai_image", "AI picture", "Drawn for the scene"], ["ai_video", "AI video", "A clip made for the scene"],
  ["audiogram", "Audiogram", "Sound waves over a colour"], ["assets", "Your library", "An upload or past media"]];
// AI video is made after the scene exists (Media › AI video: model, storyboard or straight, the price shown first); the
// scene is added without a picture and that panel opens on it with the shot described here.
const mode = ref(addSceneVisualMode.value || "");
const shot = ref("");
// Audiogram: chosen here, saved onto the new scene once it exists (the core's own controls save to the selected scene).
const { AUDIOGRAM_STYLES, AUDIOGRAM_COLORS, AUDIOGRAM_BACKGROUNDS } = core;
const ag = ref({ style: "bars", color: "#ff6b35", bg: "dark" });
const agBg = () => ({ background: (AUDIOGRAM_BACKGROUNDS.find((b) => b.key === ag.value.bg) || AUDIOGRAM_BACKGROUNDS[0]).css });
function pickMode(m) {
  mode.value = m;
  selectAddSceneVisualMode(m === "ai_video" ? "" : m);
}
const adding = ref(false);
const thumb = (c) => c?.reference_asset?.thumbnail_url || c?.reference_asset?.storage_url || null;

async function add() {
  if (adding.value) return;
  adding.value = true;
  // Set before the scene is made: adding closes this form; the editor opens AI video once the new scene is selected.
  if (mode.value === "ai_video") core.mediaIntent.value = { kind: "ai_video", shot: shot.value.trim() };
  if (mode.value === "audiogram") core.mediaIntent.value = { kind: "audiogram", ...ag.value };
  try { await createScene(props.after || null); } finally { adding.value = false; }
}
</script>

<template>
  <div class="nx-modal nx-add-modal nx-add" role="dialog" aria-modal="true" aria-labelledby="nx-add-title" @keydown.esc="closeAddScene">
    <div class="nx-row">
      <div>
        <h2 id="nx-add-title">Add a new scene</h2>
        <p v-if="label">{{ label }}</p>
      </div>
      <button class="nx-ib" type="button" aria-label="Close" @click="closeAddScene"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
    </div>

    <div class="nx-add-body">
      <!-- What the scene is and says -->
      <section class="nx-add-col">
        <span class="nx-label">Scene type</span>
        <div class="nx-chips">
          <button v-for="t in sceneTypeOptions" :key="t" type="button" :class="['nx-chip', selectedSceneType === t ? 'on' : '']" @click="selectedSceneType = t">{{ t }}</button>
        </div>
        <label class="nx-label" for="nx-add-script">What it says</label>
        <textarea id="nx-add-script" v-model="newSceneScript" class="nx-input" rows="7" placeholder="Write the scene's script, or describe the idea and click Draft with AI…"></textarea>
        <button class="nx-btn nx-full" type="button" :disabled="addSceneGeneratePending" @click="generateNewSceneDraft">{{ addSceneGeneratePending ? "Drafting…" : "Draft with AI" }}</button>
        <p class="nx-hint">Drafting reads the scenes around it. No credits.</p>
      </section>

      <!-- Where its picture comes from -->
      <section class="nx-add-col">
        <span class="nx-label">Picture</span>
        <div class="nx-add-modes" role="radiogroup" aria-label="Where the picture comes from">
          <button v-for="[m, l, d] in MODES" :key="m" type="button" role="radio" :aria-checked="mode === m" :class="['nx-opt', mode === m ? 'on' : '']" @click="pickMode(m)">
            <b>{{ l }}</b><span>{{ d }}</span>
          </button>
        </div>
        <p v-if="!mode" class="nx-hint">None picked: the scene is added without a picture, and you choose it after.</p>

        <template v-if="mode === 'ai_video'">
          <label class="nx-label" for="nx-add-shot">Describe the shot</label>
          <textarea id="nx-add-shot" v-model="shot" class="nx-input" rows="4" maxlength="1000" placeholder="How it moves: slow push-in on the product, steam rising, she looks up and smiles"></textarea>
          <p class="nx-hint">After you add the scene, AI video opens on it with this shot: choose the model and length there, see the price, and nothing is made until you say so.</p>
        </template>

        <template v-else-if="mode === 'audiogram'">
          <p class="nx-hint">Moving sound waves over a colour, following the scene's voice.</p>
          <span class="nx-label">Design</span>
          <div class="nx-ag-grid">
            <button v-for="st in AUDIOGRAM_STYLES" :key="st.key" type="button" :class="['nx-ag', ag.style === st.key ? 'on' : '']" @click="ag.style = st.key">
              <span class="nx-ag-mini" :style="agBg()">
                <svg v-if="st.key === 'circle'" viewBox="0 0 40 40" aria-hidden="true"><g transform="translate(20,20)"><line v-for="(h, i) in [0.5,0.8,0.6,0.9,0.55,0.75,0.65,0.85]" :key="i" :transform="`rotate(${i * 45})`" x1="0" y1="7" x2="0" :y2="7 + h * 10" :stroke="ag.color" stroke-width="2.5" /></g></svg>
                <template v-else><i v-for="(h, i) in [0.4,0.7,0.55,0.9,0.6,0.8,0.45]" :key="i" :class="st.key === 'mirror' ? 'is-mirror' : st.key === 'minimal' ? 'is-thin' : ''" :style="{ height: `${h * 100}%`, background: ag.color }"></i></template>
              </span>
              <span>{{ st.label }}</span>
            </button>
          </div>
          <span class="nx-label">Colour</span>
          <div class="nx-swatches">
            <button v-for="c in AUDIOGRAM_COLORS" :key="c" type="button" :class="['nx-swatch', ag.color === c ? 'on' : '']" :style="{ background: c }" :aria-label="`Colour ${c}`" @click="ag.color = c"></button>
            <label class="nx-swatch nx-swatch-custom" aria-label="Your own colour"><input type="color" :value="ag.color" @input="(e) => (ag.color = e.target.value)" />+</label>
          </div>
          <span class="nx-label">Background</span>
          <div class="nx-chips">
            <button v-for="bg in AUDIOGRAM_BACKGROUNDS" :key="bg.key" type="button" :class="['nx-chip', ag.bg === bg.key ? 'on' : '']" @click="ag.bg = bg.key">{{ bg.label }}</button>
          </div>
          <p class="nx-hint">Free. You can change it later in Media › Audiogram.</p>
        </template>

        <template v-if="mode === 'stock_video'">
          <div class="nx-row"><span>Type</span><UiSelect v-model="addSceneStockSubType" :options="ADD_SCENE_STOCK_OPTIONS" label="Stock video type" /></div>
          <textarea v-model="addSceneVisualQuery" class="nx-input" rows="2" aria-label="Search for" placeholder="Search for: abandoned mansion at night. Leave blank to use the script."></textarea>
          <p class="nx-hint">Stock footage is free.</p>
        </template>

        <template v-else-if="mode === 'stock_image'">
          <textarea v-model="addSceneVisualQuery" class="nx-input" rows="2" aria-label="Search for" placeholder="Search for: dark forest with fog. Leave blank to use the script."></textarea>
          <p class="nx-hint">Stock photos are free.</p>
        </template>

        <template v-else-if="mode === 'ai_image'">
          <span class="nx-label">Character</span>
          <div class="nx-add-chars">
            <button type="button" :class="['nx-add-char', !addSceneCharacterId ? 'on' : '']" @click="selectAddSceneCharacter(null)"><span class="nx-pick-thumb">–</span><span>None</span></button>
            <button v-for="c in characters" :key="c.id" type="button" :class="['nx-add-char', addSceneCharacterId === c.id ? 'on' : '']" @click="selectAddSceneCharacter(c.id)">
              <span class="nx-pick-thumb"><img v-if="thumb(c)" :src="thumb(c)" alt="" /><template v-else>{{ c.name.charAt(0).toUpperCase() }}</template></span><span>{{ c.name }}</span>
            </button>
            <button type="button" class="nx-add-char" @click="createCharacterOpen = true"><span class="nx-pick-thumb">+</span><span>New</span></button>
          </div>
          <span class="nx-label">Style</span>
          <div class="nx-add-styles">
            <button v-for="st in stylePickerRows" :key="st.key" type="button" :class="['nx-add-style', addSceneVisualStyle === st.key ? 'on' : '']" :title="st.description || st.label" @click="addSceneVisualStyle = st.key">
              <img v-if="st.sample_url" :src="st.sample_url" :alt="''" loading="lazy" /><span v-else class="nx-add-style-ph">✦</span>
              <span>{{ st.label }}</span>
            </button>
          </div>
          <textarea v-if="addSceneVisualStyle === 'custom'" v-model="addSceneCustomVisualStyle" class="nx-input" rows="2" maxlength="500" aria-label="Your own style" placeholder="Your own style: moody film stills, neon alleys, 35mm grain. Blank uses the video's style."></textarea>
          <textarea v-model="addSceneVisualQuery" class="nx-input" rows="2" maxlength="1000" aria-label="Prompt (optional)" placeholder="Prompt (optional). Leave blank to draw from the script."></textarea>
          <p class="nx-hint">The picture is drawn when you add the scene{{ imageCost != null ? `: ${imageCost} credits` : "" }}.</p>
        </template>

        <template v-else-if="mode === 'assets'">
          <div v-if="addScenePickedAsset" class="nx-list-row nx-pick">
            <span class="nx-pick-thumb"><img v-if="addScenePickedAsset.thumbnail_url || (addScenePickedAsset.asset_type === 'image' && addScenePickedAsset.storage_url)" :src="addScenePickedAsset.thumbnail_url || addScenePickedAsset.storage_url" alt="" /></span>
            <span class="nx-list-main"><b>{{ addScenePickedAsset.title || "Selected" }}</b><span>{{ addScenePickedAsset.asset_type || "asset" }}</span></span>
            <button class="nx-mini" type="button" @click="pickAssetForNewScene">Replace</button>
          </div>
          <button v-else class="nx-btn nx-full" type="button" @click="pickAssetForNewScene">Pick from your library</button>
        </template>
      </section>
    </div>

    <p v-if="addSceneGenerateError" class="nx-err">{{ addSceneGenerateError }}</p>
    <div class="nx-row nx-end nx-add-actions">
      <button class="nx-btn" type="button" @click="closeAddScene">Cancel</button>
      <button class="nx-btn nx-primary" type="button" :disabled="adding" @click="add">{{ adding ? "Adding…" : `Add scene${mode === "ai_image" ? cr(imageCost) : ""}` }}</button>
    </div>
  </div>
</template>
